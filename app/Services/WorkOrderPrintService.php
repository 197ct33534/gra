<?php

namespace App\Services;

use App\Enums\InvoicePayer;
use App\Enums\WorkOrderItemSource;
use App\Enums\WorkOrderItemType;
use App\Models\Invoice;
use App\Models\Part;
use App\Models\Service;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Support\BodyInspection;
use App\Support\VietnameseMoney;
use Illuminate\Support\Collection;

class WorkOrderPrintService
{
    /**
     * @return array<string, mixed>
     */
    public function quotationData(WorkOrder $workOrder): array
    {
        $data = $this->baseData($workOrder);
        $timezone = (string) config('garage.timezone');
        $approved = $workOrder->items->filter(fn (WorkOrderItem $item): bool => $item->is_approved !== false);
        $sections = [];

        foreach ($approved->groupBy(fn (WorkOrderItem $item): int => max(1, (int) $item->revision_no))->sortKeys() as $revision => $items) {
            $lines = $this->lines($workOrder, $items);
            $isAddendum = $revision > 1;
            $raisedAt = $items->min('created_at');

            $sections[] = [
                'title' => $isAddendum ? 'Phần 2: Báo giá phát sinh đợt '.($revision - 1) : 'Phần 1: Báo giá ban đầu',
                'short' => $isAddendum ? 'phát sinh đợt '.($revision - 1) : 'báo giá ban đầu',
                'is_addendum' => $isAddendum,
                'raised_at' => $isAddendum && $raisedAt !== null ? $raisedAt->timezone($timezone)->format('H:i d/m/Y') : null,
                'findings' => $items->pluck('finding_note')->filter(fn (?string $note): bool => filled($note))->unique()->values()->all(),
                'services' => $lines['services'],
                'parts' => $lines['parts'],
                'total' => $this->money($items->sum(fn (WorkOrderItem $item): float => (float) $item->subtotal)),
            ];
        }

        $declinedItems = $workOrder->items->filter(fn (WorkOrderItem $item): bool => $item->is_approved === false)->values();
        $names = $this->itemNames($declinedItems);

        $data['sections'] = $sections;
        $data['declined'] = $declinedItems->map(fn (WorkOrderItem $item): array => [
            'name' => $names[$item->id],
            'quantity' => $this->quantity($item->quantity),
            'revision' => $item->revisionLabel(),
            'reason' => filled($item->decline_reason) ? $item->decline_reason : 'Khách bảo lưu, chưa làm',
            'amount' => $this->money($item->quotedAmount()),
        ])->all();

        return $data;
    }

    /**
     * Phiếu giao việc cho thợ: cố ý không truyền bất kỳ số tiền nào sang view.
     *
     * @return array<string, mixed>
     */
    public function jobCardData(WorkOrder $workOrder): array
    {
        $workOrder->loadMissing(['vehicle.customer', 'serviceAdvisor', 'items.technician']);

        $items = $workOrder->items
            ->filter(fn (WorkOrderItem $item): bool => $item->is_approved)
            ->sortBy([['revision_no', 'asc'], ['item_type', 'desc'], ['id', 'asc']])
            ->values();
        $parts = Part::query()
            ->whereIn('id', $items->filter(fn (WorkOrderItem $item): bool => $item->item_type === WorkOrderItemType::Part)->pluck('item_id'))
            ->get()
            ->keyBy('id');
        $services = Service::query()
            ->whereIn('id', $items->filter(fn (WorkOrderItem $item): bool => $item->item_type === WorkOrderItemType::Service)->pluck('item_id'))
            ->get()
            ->keyBy('id');

        $lines = $items->map(function (WorkOrderItem $item, int $index) use ($parts, $services): array {
            if ($item->item_type === WorkOrderItemType::Part) {
                $part = $parts->get($item->item_id);
                $name = 'Thay '.($part instanceof Part ? $part->name : 'phụ tùng #'.$item->item_id);
                $name .= ' × '.$this->quantity($item->quantity).' '.($part instanceof Part ? $part->unit : '');
                $location = $item->isStockPart() && $part instanceof Part ? $part->shelf_location : null;
            } else {
                $service = $services->get($item->item_id);
                $name = $service instanceof Service ? $service->name : 'Công việc #'.$item->item_id;
                $location = null;
            }

            return [
                'no' => $index + 1,
                'name' => trim($name),
                'source' => $this->sourceLabel($item),
                'shelf' => $location,
                'supplier' => filled($item->supplier_info) ? $item->supplier_info : null,
                'technician' => $item->technician?->name ?? '',
                'is_additional' => $item->revision_no > 1,
            ];
        });

        $vehicle = $workOrder->vehicle;

        return [
            'garage' => ['name' => config('garage.name'), 'hotline' => config('garage.hotline')],
            'workOrder' => $workOrder,
            'licensePlate' => $vehicle?->license_plate ?? '—',
            'vehicleName' => trim(($vehicle?->brand ?? '').' '.($vehicle?->model ?? '').' '.($vehicle?->year ?? '')),
            'odo' => number_format((int) $workOrder->reception_odo, 0, ',', '.').' km',
            'promisedAt' => $workOrder->promised_at?->timezone(config('garage.timezone'))->format('H:i — d/m/Y'),
            'lines' => $lines,
            'bodyInspection' => BodyInspection::summaryLines(
                is_array($workOrder->body_inspection) ? $workOrder->body_inspection : null
            ),
        ];
    }

    /**
     * Dữ liệu in hóa đơn cho một bên trả tiền.
     *
     * Không chỉ định hóa đơn: in hóa đơn khách hàng mới nhất (lệnh thường chỉ có loại này).
     * VietQR chỉ có trên hóa đơn khách hàng, đúng số tiền phần khách phải trả.
     *
     * @return array<string, mixed>
     */
    public function invoiceData(WorkOrder $workOrder, ?Invoice $invoice = null): array
    {
        $invoice ??= $workOrder->invoiceFor(InvoicePayer::Customer)
            ?? $workOrder->invoices()->latest('id')->first();

        if (! $invoice instanceof Invoice || (int) $invoice->work_order_id !== (int) $workOrder->getKey()) {
            abort(404);
        }

        $data = $this->baseData($workOrder);
        $total = round((float) $invoice->total_amount, 2);
        $isInsurancePayer = $invoice->isInsurancePayer();
        $description = 'TT '.$workOrder->code;
        $split = $workOrder->paymentSplit();

        $data['invoice'] = $invoice;
        $data['isInsuranceClaim'] = $workOrder->isInsuranceClaim();
        $data['isInsurancePayer'] = $isInsurancePayer;
        $data['documentTitle'] = match (true) {
            $isInsurancePayer => 'Hóa đơn — phần bảo hiểm chi trả',
            $workOrder->isInsuranceClaim() => 'Hóa đơn — phần khách hàng trả',
            default => 'Hóa đơn xuất xưởng',
        };
        $data['payerLabel'] = $isInsurancePayer
            ? ($invoice->payer_name ?: (string) $workOrder->insurance_company)
            : ($workOrder->vehicle?->customer?->name ?? 'Khách hàng');
        $data['split'] = [
            'deductible' => $this->money($split['deductible']),
            'outside_scope' => $this->money($split['outside_scope']),
            'customer' => $this->money($split['customer']),
            'insurance' => $this->money($split['insurance']),
        ];
        $data['amountDue'] = $this->money($total);
        $data['paidAmount'] = $this->money($invoice->paid_amount);
        $data['outstandingAmount'] = $this->money($invoice->outstandingAmount());
        $data['amountInWords'] = VietnameseMoney::spell($total);
        $data['showQr'] = ! $isInsurancePayer && $total > 0;
        $data['qrDescription'] = $description;
        $data['qrDataUri'] = $data['showQr']
            ? app(VietQrService::class)->generateBase64Qr($total, $description)
            : null;

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function baseData(WorkOrder $workOrder): array
    {
        $workOrder->loadMissing(['vehicle.customer', 'serviceAdvisor', 'items']);

        $lines = $this->lines($workOrder);
        $partsAmount = round((float) $workOrder->total_parts_amount, 2);
        $laborAmount = round((float) $workOrder->total_labor_amount, 2);
        $discount = round((float) $workOrder->discount_amount, 2);
        $net = round($partsAmount + $laborAmount - $discount, 2);
        $final = round((float) $workOrder->final_amount, 2);

        return [
            'garage' => config('garage'),
            'workOrder' => $workOrder,
            'services' => $lines['services'],
            'parts' => $lines['parts'],
            'laborAmount' => $this->money($laborAmount),
            'partsAmount' => $this->money($partsAmount),
            'discountAmount' => $this->money($discount),
            'vatAmount' => $this->money(round($final - $net, 2)),
            'finalAmount' => $this->money($final),
            'amountInWords' => VietnameseMoney::spell($final),
        ];
    }

    /**
     * @return array{services: Collection<int, array<string, string>>, parts: Collection<int, array<string, string>>}
     */
    private function lines(WorkOrder $workOrder, ?Collection $items = null): array
    {
        $items ??= $workOrder->items;
        $partIds = $items
            ->filter(fn (WorkOrderItem $item): bool => $item->item_type === WorkOrderItemType::Part)
            ->pluck('item_id');
        $serviceIds = $items
            ->filter(fn (WorkOrderItem $item): bool => $item->item_type === WorkOrderItemType::Service)
            ->pluck('item_id');

        $parts = Part::query()->whereIn('id', $partIds)->get()->keyBy('id');
        $services = Service::query()->whereIn('id', $serviceIds)->get()->keyBy('id');
        $serviceLines = collect();
        $partLines = collect();
        $outsideTag = $workOrder->isInsuranceClaim() ? ' (ngoài phạm vi BH — khách trả)' : '';

        foreach ($items as $item) {
            if (! $item->is_approved) {
                continue;
            }

            if ($item->item_type === WorkOrderItemType::Service) {
                $service = $services->get($item->item_id);
                $serviceLines->push([
                    'name' => ($service instanceof Service ? $service->name : 'Dịch vụ #'.$item->item_id).($item->is_outside_insurance ? $outsideTag : ''),
                    'unit' => 'Công',
                    'quantity' => $this->quantity($item->quantity),
                    'unit_price' => $this->money($item->unit_price),
                    'discount' => $this->money($item->discount_amount),
                    'subtotal' => $this->money($item->subtotal),
                ]);
            }

            if ($item->item_type === WorkOrderItemType::Part) {
                $part = $parts->get($item->item_id);
                $code = $part instanceof Part
                    ? collect([$part->oem_code, $part->sku])->filter(fn (?string $value): bool => filled($value))->implode(' / ')
                    : '';
                $partLines->push([
                    'name' => ($part instanceof Part ? $part->name : 'Phụ tùng #'.$item->item_id).($item->is_outside_insurance ? $outsideTag : ''),
                    'code' => $code !== '' ? $code : '—',
                    'unit' => $part instanceof Part ? $part->unit : 'Cái',
                    'quantity' => $this->quantity($item->quantity),
                    'unit_price' => $this->money($item->unit_price),
                    'discount' => $this->money($item->discount_amount),
                    'subtotal' => $this->money($item->subtotal),
                ]);
            }
        }

        return ['services' => $serviceLines, 'parts' => $partLines];
    }

    /**
     * @param  Collection<int, WorkOrderItem>  $items
     * @return array<int, string>
     */
    private function itemNames(Collection $items): array
    {
        $parts = Part::query()
            ->whereIn('id', $items->filter(fn (WorkOrderItem $item): bool => $item->item_type === WorkOrderItemType::Part)->pluck('item_id'))
            ->get()
            ->keyBy('id');
        $services = Service::query()
            ->whereIn('id', $items->filter(fn (WorkOrderItem $item): bool => $item->item_type === WorkOrderItemType::Service)->pluck('item_id'))
            ->pluck('name', 'id');

        return $items->mapWithKeys(function (WorkOrderItem $item) use ($parts, $services): array {
            if ($item->item_type === WorkOrderItemType::Part) {
                $part = $parts->get($item->item_id);

                return [$item->id => $part instanceof Part ? 'Thay '.$part->name.' ('.$part->unit.')' : 'Phụ tùng #'.$item->item_id];
            }

            return [$item->id => (string) ($services[$item->item_id] ?? 'Dịch vụ #'.$item->item_id)];
        })->all();
    }

    private function sourceLabel(WorkOrderItem $item): string
    {
        if ($item->item_type === WorkOrderItemType::Service && $item->source() === WorkOrderItemSource::Stock) {
            return 'Gara làm';
        }

        return $item->source()->getLabel();
    }

    private function money(int|float|string|null $amount): string
    {
        return VietnameseMoney::format($amount);
    }

    private function quantity(int|float|string|null $quantity): string
    {
        $value = (float) $quantity;

        if (abs($value - round($value)) < 0.001) {
            return number_format($value, 0, ',', '.');
        }

        return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
    }
}
