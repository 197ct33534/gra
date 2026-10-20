<?php

namespace App\Services;

use App\Enums\CustomerType;
use App\Enums\WorkOrderItemType;
use App\Enums\WorkOrderStatus;
use App\Enums\WorkOrderType;
use App\Helpers\LicensePlateHelper;
use App\Models\Customer;
use App\Models\Part;
use App\Models\Service;
use App\Models\ServicePackage;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Tiếp nhận nhanh giờ cao điểm: một lần bấm tạo (hoặc tìm) khách, xe và lệnh sửa chữa.
 * Khách mới chỉ bắt buộc biển số + SĐT; phần còn lại bổ sung sau.
 */
class QuickReceptionService
{
    public const UNKNOWN = 'Chưa rõ';

    public function __construct(private readonly WorkOrderService $workOrders) {}

    public static function findVehicle(?string $plate): ?Vehicle
    {
        $clean = LicensePlateHelper::clean($plate);

        if ($clean === '') {
            return null;
        }

        return Vehicle::query()
            ->with('customer')
            ->whereRaw(LicensePlateHelper::cleanedColumnSql('license_plate').' = ?', [$clean])
            ->first();
    }

    public static function cleanPhone(?string $phone): string
    {
        return preg_replace('/\D/', '', (string) $phone) ?? '';
    }

    public static function openOrderFor(Vehicle $vehicle): ?WorkOrder
    {
        return $vehicle->workOrders()
            ->where('status', '!=', WorkOrderStatus::Delivered)
            ->latest('id')
            ->first();
    }

    /**
     * @param  array{license_plate: string, phone?: ?string, customer_name?: ?string, brand?: ?string, model?: ?string, reception_odo: int|string, fuel_level?: ?string, checkin_checklist?: list<string>, body_damage_zones?: list<string>, customer_request?: ?string, promised_at?: mixed, lift_bay?: ?string, package_ids?: list<int|string>}  $data
     */
    public function receive(array $data, int $userId): WorkOrder
    {
        return DB::transaction(function () use ($data, $userId): WorkOrder {
            $plate = trim((string) $data['license_plate']);
            $odo = (int) $data['reception_odo'];
            $vehicle = self::findVehicle($plate);

            if ($vehicle instanceof Vehicle) {
                $vehicle = Vehicle::query()->whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
                $open = self::openOrderFor($vehicle);

                if ($open instanceof WorkOrder) {
                    throw new DomainException("Xe {$vehicle->license_plate} đang có lệnh {$open->code} chưa bàn giao.");
                }

                if ($odo < $vehicle->current_odo) {
                    throw new DomainException('ODO tiếp nhận ('.number_format($odo, 0, ',', '.').' km) nhỏ hơn ODO lần trước ('.number_format($vehicle->current_odo, 0, ',', '.').' km).');
                }
            } else {
                $vehicle = $this->createVehicle($plate, $odo, $data);
            }

            $bodyInspection = [];
            foreach (array_values($data['body_damage_zones'] ?? []) as $zone) {
                if (! is_string($zone) || $zone === '') {
                    continue;
                }
                $bodyInspection[] = [
                    'zone' => $zone,
                    'condition' => 'other',
                    'note' => 'Ghi nhận lúc tiếp nhận nhanh',
                ];
            }

            $order = WorkOrder::query()->create([
                'code' => $this->workOrders->nextCode(),
                'vehicle_id' => $vehicle->id,
                'order_type' => WorkOrderType::Standard,
                'service_advisor_id' => $userId,
                'reception_odo' => $odo,
                'fuel_level' => $data['fuel_level'] ?? null,
                'customer_request' => filled($data['customer_request'] ?? null) ? trim((string) $data['customer_request']) : null,
                'checkin_checklist' => array_values($data['checkin_checklist'] ?? []),
                'body_inspection' => $bodyInspection !== [] ? $bodyInspection : null,
                'promised_at' => $data['promised_at'] ?? null,
                'lift_bay' => filled($data['lift_bay'] ?? null) ? $data['lift_bay'] : null,
                'status' => WorkOrderStatus::Reception,
                'vat_rate' => 8,
            ]);

            $this->addPackages($order, $data['package_ids'] ?? []);
            $this->workOrders->recalculateTotals($order);

            return $order->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createVehicle(string $plate, int $odo, array $data): Vehicle
    {
        $phone = self::cleanPhone($data['phone'] ?? null);

        if (strlen($phone) < 9) {
            throw new DomainException('Xe mới: cần số điện thoại khách (ít nhất 9 số).');
        }

        $customer = Customer::query()->where('phone', $phone)->first()
            ?? Customer::query()->create([
                'name' => filled($data['customer_name'] ?? null) ? trim((string) $data['customer_name']) : 'Khách '.$phone,
                'phone' => $phone,
                'customer_type' => CustomerType::Individual,
            ]);

        return $customer->vehicles()->create([
            'license_plate' => mb_strtoupper($plate),
            'brand' => filled($data['brand'] ?? null) ? trim((string) $data['brand']) : self::UNKNOWN,
            'model' => filled($data['model'] ?? null) ? trim((string) $data['model']) : self::UNKNOWN,
            'current_odo' => $odo,
        ]);
    }

    /**
     * @param  list<int|string>  $packageIds
     */
    private function addPackages(WorkOrder $order, array $packageIds): void
    {
        $packages = ServicePackage::query()->active()->with('items')->whereIn('id', $packageIds)->get();

        foreach ($packages as $package) {
            foreach ($package->items as $item) {
                $price = $item->item_type === WorkOrderItemType::Part
                    ? Part::query()->whereKey($item->item_id)->value('selling_price')
                    : Service::query()->whereKey($item->item_id)->value('labor_cost');

                if ($price === null) {
                    continue;
                }

                WorkOrderItem::query()->create([
                    'work_order_id' => $order->id,
                    'item_type' => $item->item_type,
                    'item_id' => $item->item_id,
                    'quantity' => $item->quantity,
                    'unit_price' => $price,
                ]);
            }
        }
    }
}
