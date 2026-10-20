<?php

namespace App\Services;

use App\Enums\WarrantyCardStatus;
use App\Enums\WarrantyClaimStatus;
use App\Enums\WorkOrderItemSource;
use App\Enums\WorkOrderItemType;
use App\Enums\WorkOrderStatus;
use App\Enums\WorkOrderType;
use App\Models\Customer;
use App\Models\Part;
use App\Models\Service;
use App\Models\Vehicle;
use App\Models\WarrantyCard;
use App\Models\WarrantyClaim;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WarrantyService
{
    public function nextCardCode(): string
    {
        return DB::transaction(function (): string {
            $prefix = 'BH-'.now()->format('Ymd').'-';
            $last = WarrantyCard::query()
                ->where('code', 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByDesc('code')
                ->value('code');

            $sequence = 1;
            if (is_string($last) && preg_match('/^'.preg_quote($prefix, '/').'(\d{4})$/', $last, $m) === 1) {
                $sequence = ((int) $m[1]) + 1;
            }

            if ($sequence > 9999) {
                throw new DomainException('Đã hết mã thẻ bảo hành trong ngày.');
            }

            return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Sinh thẻ BH cho các hạng mục đủ điều kiện khi bàn giao.
     *
     * @return Collection<int, WarrantyCard>
     */
    public function issueCardsForDelivery(WorkOrder $workOrder): Collection
    {
        $workOrder->loadMissing(['items', 'vehicle.customer']);

        if ($workOrder->order_type === WorkOrderType::WarrantyRedo) {
            return collect();
        }

        $vehicle = $workOrder->vehicle;
        $customer = $vehicle?->customer;

        if (! $vehicle instanceof Vehicle || ! $customer instanceof Customer) {
            return collect();
        }

        $cards = collect();
        $defaultMonths = (int) config('garage.warranty_defaults.default_months', 6);
        $defaultKm = (int) config('garage.warranty_defaults.default_km', 10000);

        foreach ($workOrder->items as $item) {
            if (! $item->is_approved) {
                continue;
            }

            if ($item->source() === WorkOrderItemSource::CustomerSupplied) {
                continue;
            }

            [$months, $km] = $this->resolveWarrantyPeriod($item, $defaultMonths, $defaultKm);

            if ($months <= 0 && $km <= 0) {
                continue;
            }

            $exists = WarrantyCard::query()
                ->where('work_order_item_id', $item->id)
                ->exists();

            if ($exists) {
                continue;
            }

            $starts = ($workOrder->delivered_at ?? now())->toDateString();
            $startOdo = $workOrder->reception_odo;

            $cards->push(WarrantyCard::query()->create([
                'code' => $this->nextCardCode(),
                'vehicle_id' => $vehicle->id,
                'customer_id' => $customer->id,
                'work_order_id' => $workOrder->id,
                'work_order_item_id' => $item->id,
                'item_type' => $item->item_type,
                'item_id' => $item->item_id,
                'item_name' => $this->itemName($item),
                'starts_at' => $starts,
                'ends_at' => $months > 0 ? Carbon::parse($starts)->addMonths($months)->toDateString() : null,
                'start_odo' => $startOdo,
                'end_odo' => $km > 0 && $startOdo !== null ? $startOdo + $km : null,
                'status' => WarrantyCardStatus::Active,
                'terms' => filled($item->warranty_policy)
                    ? $item->warranty_policy
                    : (string) config('garage.warranty_terms', config('garage.warranty')),
            ]));
        }

        return $cards;
    }

    /**
     * Tạo RO bảo hành làm lại từ thẻ còn hiệu lực.
     */
    public function openClaim(
        WarrantyCard $card,
        int $userId,
        string $symptom,
        ?int $receptionOdo = null,
    ): WorkOrder {
        return DB::transaction(function () use ($card, $userId, $symptom, $receptionOdo): WorkOrder {
            /** @var WarrantyCard $locked */
            $locked = WarrantyCard::query()->whereKey($card->id)->lockForUpdate()->with(['vehicle.customer'])->firstOrFail();

            $odo = $receptionOdo ?? $locked->vehicle?->current_odo;

            if (! $locked->isValidAt(now(), $odo !== null ? (int) $odo : null)) {
                throw new DomainException('Thẻ bảo hành '.$locked->code.' không còn hiệu lực (hết hạn ngày/km hoặc đã claim).');
            }

            $vehicle = $locked->vehicle;
            if (! $vehicle instanceof Vehicle) {
                throw new DomainException('Thẻ BH thiếu xe.');
            }

            $open = $vehicle->workOrders()->where('status', '!=', WorkOrderStatus::Delivered)->first();
            if ($open instanceof WorkOrder) {
                throw new DomainException("Xe đang có lệnh {$open->code} chưa bàn giao.");
            }

            $workOrders = app(WorkOrderService::class);
            $order = WorkOrder::query()->create([
                'code' => $workOrders->nextCode(),
                'vehicle_id' => $vehicle->id,
                'order_type' => WorkOrderType::WarrantyRedo,
                'warranty_card_id' => $locked->id,
                'service_advisor_id' => $userId,
                'reception_odo' => (int) ($odo ?? $vehicle->current_odo),
                'customer_request' => 'Bảo hành: '.$symptom,
                'technical_diagnosis' => 'Claim thẻ '.$locked->code.' — '.$locked->item_name,
                'status' => WorkOrderStatus::Reception,
                'vat_rate' => 8,
                'discount_amount' => 0,
            ]);

            WorkOrderItem::query()->create([
                'work_order_id' => $order->id,
                'item_type' => $locked->item_type,
                'item_id' => $locked->item_id,
                'item_source' => WorkOrderItemSource::Stock,
                'quantity' => 1,
                'unit_price' => 0,
                'cost_price' => 0,
                'discount_amount' => 0,
                'subtotal' => 0,
                'is_approved' => true,
                'warranty_policy' => 'Bảo hành làm lại — không tính tiền khách',
                'status' => 'pending',
            ]);

            $workOrders->recalculateTotals($order);

            WarrantyClaim::query()->create([
                'warranty_card_id' => $locked->id,
                'work_order_id' => $order->id,
                'symptom' => $symptom,
                'status' => WarrantyClaimStatus::Open,
            ]);

            $locked->status = WarrantyCardStatus::Claimed;
            $locked->save();

            return $order->fresh(['items']) ?? $order;
        });
    }

    /**
     * @return array{0: int, 1: int} months, km
     */
    private function resolveWarrantyPeriod(WorkOrderItem $item, int $defaultMonths, int $defaultKm): array
    {
        if ($item->item_type === WorkOrderItemType::Part) {
            $part = Part::query()->find($item->item_id);
            $months = (int) ($part?->warranty_months ?? $defaultMonths);
            $km = (int) ($part?->warranty_km ?? $defaultKm);

            return [$months, $km];
        }

        if ($item->item_type === WorkOrderItemType::Service) {
            $service = Service::query()->find($item->item_id);
            $months = (int) ($service?->warranty_months ?? max(1, (int) ($defaultMonths / 2)));
            $km = (int) ($service?->warranty_km ?? 0);

            return [$months, $km];
        }

        return [0, 0];
    }

    private function itemName(WorkOrderItem $item): string
    {
        if ($item->item_type === WorkOrderItemType::Part) {
            return (string) (Part::query()->whereKey($item->item_id)->value('name') ?? 'Phụ tùng #'.$item->item_id);
        }

        return (string) (Service::query()->whereKey($item->item_id)->value('name') ?? 'Dịch vụ #'.$item->item_id);
    }
}
