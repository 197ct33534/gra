<?php

namespace Database\Seeders;

use App\Enums\CustomerType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Enums\WorkOrderItemType;
use App\Enums\WorkOrderStatus;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\Service;
use App\Models\ServicePackage;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\WorkOrderService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->seedUsers();
        $categories = $this->seedPartCategories();
        $this->seedParts($categories);
        $this->seedServices();
        $this->seedServicePackages();
        $this->seedCustomersAndVehicles();
        $this->seedWorkOrders();
        $this->seedShowcase();
    }

    private function seedUsers(): void
    {
        $users = [
            ['name' => 'Admin', 'email' => 'admin@gara.vn', 'role' => UserRole::Admin],
            ['name' => 'Nguyễn Văn Cố Vấn', 'email' => 'sa@gara.vn', 'role' => UserRole::ServiceAdvisor],
            ['name' => 'Thợ Máy Trần Văn A', 'email' => 'tech1@gara.vn', 'role' => UserRole::Technician],
            ['name' => 'Thợ Điện Lê Văn B', 'email' => 'tech2@gara.vn', 'role' => UserRole::Technician],
        ];

        foreach ($users as $user) {
            User::query()->updateOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'password' => 'password',
                    'role' => $user['role'],
                ],
            );
        }
    }

    /**
     * @return array<string, PartCategory>
     */
    private function seedPartCategories(): array
    {
        $categories = [];

        foreach (['Dầu nhớt & Phụ gia', 'Hệ thống lọc', 'Gầm & Phanh'] as $name) {
            $categories[$name] = PartCategory::query()->updateOrCreate(
                ['name' => $name],
                ['parent_id' => null],
            );
        }

        return $categories;
    }

    /**
     * @param  array<string, PartCategory>  $categories
     */
    private function seedParts(array $categories): void
    {
        $parts = [
            [
                'category' => 'Dầu nhớt & Phụ gia',
                'sku' => 'CAST-5W30',
                'oem_code' => null,
                'name' => 'Nhớt động cơ Castrol 5W-30',
                'unit' => 'Lít',
                'cost_price' => 120000,
                'selling_price' => 180000,
                'stock_quantity' => 80,
                'min_stock_alert' => 10,
                'shelf_location' => 'A1-01',
            ],
            [
                'category' => 'Dầu nhớt & Phụ gia',
                'sku' => 'PRESTONE-COOL',
                'oem_code' => null,
                'name' => 'Nước làm mát Prestone',
                'unit' => 'Can',
                'cost_price' => 80000,
                'selling_price' => 130000,
                'stock_quantity' => 15,
                'min_stock_alert' => 5,
                'shelf_location' => 'A1-02',
            ],
            [
                'category' => 'Hệ thống lọc',
                'sku' => 'LOC-NHOT-VIOS',
                'oem_code' => '04152-YZZA6',
                'name' => 'Lọc nhớt Vios/Yaris',
                'unit' => 'Cái',
                'cost_price' => 60000,
                'selling_price' => 120000,
                'stock_quantity' => 12,
                'min_stock_alert' => 5,
                'shelf_location' => 'B2-01',
            ],
            [
                'category' => 'Hệ thống lọc',
                'sku' => 'LOC-GIO-CX5',
                'oem_code' => null,
                'name' => 'Lọc gió điều hòa CX-5',
                'unit' => 'Cái',
                'cost_price' => 90000,
                'selling_price' => 180000,
                'stock_quantity' => 3,
                'min_stock_alert' => 5,
                'shelf_location' => 'B2-04',
            ],
            [
                'category' => 'Gầm & Phanh',
                'sku' => 'RANGER-BRAKE-F',
                'oem_code' => null,
                'name' => 'Bố thắng trước Ford Ranger',
                'unit' => 'Bộ',
                'cost_price' => 450000,
                'selling_price' => 750000,
                'stock_quantity' => 4,
                'min_stock_alert' => 2,
                'shelf_location' => 'C3-02',
            ],
        ];

        foreach ($parts as $part) {
            $category = $categories[$part['category']];
            unset($part['category']);

            $existing = Part::query()->where('sku', $part['sku'])->first();

            if ($existing instanceof Part) {
                $existing->fill([
                    ...Arr::except($part, ['stock_quantity']),
                    'category_id' => $category->id,
                ])->save();

                continue;
            }

            Part::query()->create([
                ...$part,
                'category_id' => $category->id,
            ]);
        }
    }

    private function seedServices(): void
    {
        $services = [
            [
                'code' => 'BD-5K',
                'name' => 'Bảo dưỡng cấp 5.000 km',
                'labor_cost' => 150000,
                'standard_time_minutes' => 45,
            ],
            [
                'code' => 'BD-20K',
                'name' => 'Bảo dưỡng cấp 20.000 km',
                'labor_cost' => 350000,
                'standard_time_minutes' => 90,
            ],
            [
                'code' => 'TH-PHANH',
                'name' => 'Thay má phanh trước/sau',
                'labor_cost' => 200000,
                'standard_time_minutes' => 60,
            ],
            [
                'code' => 'VS-DANGIA',
                'name' => 'Vệ sinh dàn lạnh nội soi',
                'labor_cost' => 400000,
                'standard_time_minutes' => 60,
            ],
        ];

        foreach ($services as $service) {
            Service::query()->updateOrCreate(
                ['code' => $service['code']],
                $service,
            );
        }
    }

    private function seedServicePackages(): void
    {
        $service = fn (string $code): int => (int) Service::query()->where('code', $code)->value('id');
        $part = fn (string $sku): int => (int) Part::query()->where('sku', $sku)->value('id');
        $packages = [
            [
                'code' => 'GOI-BD5K-VIOS',
                'name' => 'Bảo dưỡng 5.000 km Vios/Yaris',
                'vehicle_hint' => 'Toyota Vios, Yaris',
                'sort_order' => 1,
                'items' => [
                    ['item_type' => WorkOrderItemType::Service, 'item_id' => $service('BD-5K'), 'quantity' => 1],
                    ['item_type' => WorkOrderItemType::Part, 'item_id' => $part('CAST-5W30'), 'quantity' => 4],
                    ['item_type' => WorkOrderItemType::Part, 'item_id' => $part('LOC-NHOT-VIOS'), 'quantity' => 1],
                ],
            ],
            [
                'code' => 'GOI-PHANH-RANGER',
                'name' => 'Thay má phanh trước Ranger',
                'vehicle_hint' => 'Ford Ranger',
                'sort_order' => 2,
                'items' => [
                    ['item_type' => WorkOrderItemType::Service, 'item_id' => $service('TH-PHANH'), 'quantity' => 1],
                    ['item_type' => WorkOrderItemType::Part, 'item_id' => $part('RANGER-BRAKE-F'), 'quantity' => 1],
                ],
            ],
        ];

        foreach ($packages as $data) {
            $package = ServicePackage::query()->updateOrCreate(['code' => $data['code']], Arr::except($data, ['items']));
            $package->items()->delete();
            $package->items()->createMany($data['items']);
        }
    }

    private function seedCustomersAndVehicles(): void
    {
        $records = [
            [
                'customer' => [
                    'name' => 'Anh Hoàng',
                    'phone' => '0901234567',
                    'customer_type' => CustomerType::Individual,
                    'address' => '12 Nguyễn Văn Linh, Quận 7, TP.HCM',
                    'tax_code' => null,
                ],
                'vehicle' => [
                    'license_plate' => '51K-123.45',
                    'brand' => 'Toyota',
                    'model' => 'Vios',
                    'year' => 2021,
                    'current_odo' => 35000,
                ],
            ],
            [
                'customer' => [
                    'name' => 'Chị Mai',
                    'phone' => '0918889999',
                    'customer_type' => CustomerType::Individual,
                    'address' => '45 Cầu Giấy, Hà Nội',
                    'tax_code' => null,
                ],
                'vehicle' => [
                    'license_plate' => '30H-987.65',
                    'brand' => 'Mazda',
                    'model' => 'CX-5',
                    'year' => 2022,
                    'current_odo' => 18500,
                ],
            ],
            [
                'customer' => [
                    'name' => 'Công ty TNHH Vận Tải ABC',
                    'phone' => '0989991122',
                    'customer_type' => CustomerType::Company,
                    'address' => 'KCN Sóng Thần, Dĩ An, Bình Dương',
                    'tax_code' => '0312345678',
                ],
                'vehicle' => [
                    'license_plate' => '29C-555.88',
                    'brand' => 'Ford',
                    'model' => 'Ranger',
                    'year' => 2020,
                    'current_odo' => 72000,
                ],
            ],
        ];

        foreach ($records as $record) {
            $customer = Customer::query()->updateOrCreate(
                ['phone' => $record['customer']['phone']],
                $record['customer'],
            );

            $vehicle = Vehicle::query()->where('license_plate', $record['vehicle']['license_plate'])->first();

            if ($vehicle instanceof Vehicle) {
                $vehicle->fill([
                    ...Arr::except($record['vehicle'], ['current_odo', 'license_plate']),
                    'customer_id' => $customer->id,
                ])->save();

                continue;
            }

            $customer->vehicles()->create($record['vehicle']);
        }
    }

    private function seedPaidInvoice(WorkOrderService $service): void
    {
        $order = WorkOrder::query()->where('code', 'RO-20261019-001')->first();

        if ($order === null || $order->invoices()->exists()) {
            return;
        }

        $service->issueInvoice(
            $order,
            PaymentMethod::BankTransfer,
            (string) $order->final_amount,
            'Thanh toán chuyển khoản đủ 100%.',
        );
    }

    private function seedWorkOrders(): void
    {
        $service = app(WorkOrderService::class);
        $advisor = User::query()->where('email', 'sa@gara.vn')->firstOrFail();
        $technician = User::query()->where('email', 'tech1@gara.vn')->firstOrFail();
        $vios = Vehicle::query()->where('license_plate', '51K-123.45')->firstOrFail();
        $ranger = Vehicle::query()->where('license_plate', '29C-555.88')->firstOrFail();

        if (! WorkOrder::query()->where('code', 'RO-20261019-001')->exists()) {
            $maintenance = Service::query()->where('code', 'BD-5K')->firstOrFail();
            $oil = Part::query()->where('sku', 'CAST-5W30')->firstOrFail();
            $filter = Part::query()->where('sku', 'LOC-NHOT-VIOS')->firstOrFail();

            $order = WorkOrder::query()->create([
                'code' => 'RO-20261019-001',
                'vehicle_id' => $vios->id,
                'service_advisor_id' => $advisor->id,
                'reception_odo' => $vios->current_odo,
                'fuel_level' => '1/2',
                'customer_request' => 'Bảo dưỡng cấp 5.000 km, máy hơi ồn khi nổ máy.',
                'technical_diagnosis' => 'Đến hạn thay nhớt động cơ và lọc nhớt.',
                'checkin_checklist' => ['warranty_book', 'spare_tire'],
                'promised_at' => now('Asia/Ho_Chi_Minh')->setTime(11, 30)->utc(),
                'lift_bay' => 'Cầu 1',
                'status' => WorkOrderStatus::Reception,
                'discount_amount' => 0,
                'vat_rate' => 8,
            ]);

            $this->addWorkOrderItem($order, WorkOrderItemType::Service, $maintenance->id, 1, (string) $maintenance->labor_cost, $technician->id);
            $this->addWorkOrderItem($order, WorkOrderItemType::Part, $oil->id, 4, (string) $oil->selling_price, $technician->id);
            $this->addWorkOrderItem($order, WorkOrderItemType::Part, $filter->id, 1, (string) $filter->selling_price, $technician->id);

            $service->recalculateTotals($order);
            $service->approveWorkOrder($order, $advisor->id);
        }

        $this->seedPaidInvoice($service);

        if (! WorkOrder::query()->where('code', 'RO-20261019-002')->exists()) {
            $brakeJob = Service::query()->where('code', 'TH-PHANH')->firstOrFail();
            $pads = Part::query()->where('sku', 'RANGER-BRAKE-F')->firstOrFail();

            $order = WorkOrder::query()->create([
                'code' => 'RO-20261019-002',
                'vehicle_id' => $ranger->id,
                'service_advisor_id' => $advisor->id,
                'reception_odo' => $ranger->current_odo,
                'fuel_level' => '1/4',
                'customer_request' => 'Đạp phanh có tiếng kêu, đề nghị kiểm tra và thay má phanh trước.',
                'technical_diagnosis' => 'Má phanh trước mòn, cần thay bộ bố thắng.',
                'checkin_checklist' => ['tools'],
                'promised_at' => now('Asia/Ho_Chi_Minh')->setTime(17, 0)->utc(),
                'lift_bay' => 'Cầu 3',
                'status' => WorkOrderStatus::Quoted,
                'discount_amount' => 0,
                'vat_rate' => 8,
            ]);

            $this->addWorkOrderItem($order, WorkOrderItemType::Service, $brakeJob->id, 1, (string) $brakeJob->labor_cost, null);
            $this->addWorkOrderItem($order, WorkOrderItemType::Part, $pads->id, 1, (string) $pads->selling_price, null);

            $service->recalculateTotals($order);
        }
    }

    private function seedShowcase(): void
    {
        $service = app(WorkOrderService::class);
        $advisor = User::query()->where('email', 'sa@gara.vn')->firstOrFail();
        $technician = User::query()->where('email', 'tech1@gara.vn')->firstOrFail();
        $cx5 = Vehicle::query()->where('license_plate', '30H-987.65')->firstOrFail();
        $ranger = Vehicle::query()->where('license_plate', '29C-555.88')->firstOrFail();
        $vios = Vehicle::query()->where('license_plate', '51K-123.45')->firstOrFail();

        $this->seedHistoricalVisit(
            $service,
            'RO-DEMO-REV-001',
            'INV-DEMO-001',
            $cx5,
            $advisor->id,
            Service::query()->where('code', 'VS-DANGIA')->firstOrFail(),
            $technician->id,
            now()->subDays(6),
            (int) $cx5->current_odo,
            PaymentMethod::Cash,
        );
        $this->seedHistoricalVisit(
            $service,
            'RO-DEMO-REV-002',
            'INV-DEMO-002',
            $ranger,
            $advisor->id,
            Service::query()->where('code', 'TH-PHANH')->firstOrFail(),
            null,
            now()->subDays(4),
            max(0, (int) $ranger->current_odo - 1000),
            PaymentMethod::Card,
        );
        $this->seedHistoricalVisit(
            $service,
            'RO-DEMO-REV-003',
            'INV-DEMO-003',
            $vios,
            $advisor->id,
            Service::query()->where('code', 'BD-20K')->firstOrFail(),
            $technician->id,
            now()->subDays(2),
            max(0, (int) $vios->current_odo - 500),
            PaymentMethod::BankTransfer,
        );

        $dueCustomer = Customer::query()->updateOrCreate(
            ['phone' => '0977123456'],
            [
                'name' => 'Anh Tuấn',
                'customer_type' => CustomerType::Individual,
                'address' => '88 Lê Văn Lương, Quận 7, TP.HCM',
                'tax_code' => null,
            ],
        );
        $dueVehicle = Vehicle::query()->where('license_plate', '51G-246.80')->first();

        if (! $dueVehicle instanceof Vehicle) {
            $dueVehicle = $dueCustomer->vehicles()->create([
                'license_plate' => '51G-246.80',
                'brand' => 'Honda',
                'model' => 'City',
                'year' => 2019,
                'current_odo' => 28000,
            ]);
        }

        $this->seedHistoricalVisit(
            $service,
            'RO-DUE-0001',
            'INV-DUE-0001',
            $dueVehicle,
            $advisor->id,
            Service::query()->where('code', 'BD-5K')->firstOrFail(),
            $technician->id,
            now()->subDays(40),
            22000,
            PaymentMethod::BankTransfer,
        );

        if (! WorkOrder::query()->where('code', 'RO-SHOP-0001')->exists()) {
            $cabin = Service::query()->where('code', 'VS-DANGIA')->firstOrFail();
            $order = WorkOrder::query()->create([
                'code' => 'RO-SHOP-0001',
                'vehicle_id' => $cx5->id,
                'service_advisor_id' => $advisor->id,
                'reception_odo' => $cx5->current_odo,
                'fuel_level' => '3/4',
                'customer_request' => 'Điều hòa yếu, cần kiểm tra dàn lạnh.',
                'technical_diagnosis' => 'Đang nội soi dàn lạnh.',
                'status' => WorkOrderStatus::Diagnosing,
                'discount_amount' => 0,
                'vat_rate' => 8,
            ]);
            $this->addWorkOrderItem($order, WorkOrderItemType::Service, $cabin->id, 1, (string) $cabin->labor_cost, $technician->id);
            $service->recalculateTotals($order);
            $order->update(['status' => WorkOrderStatus::Diagnosing]);
        }
    }

    private function seedHistoricalVisit(
        WorkOrderService $service,
        string $code,
        string $invoiceNumber,
        Vehicle $vehicle,
        int $advisorId,
        Service $catalogService,
        ?int $technicianId,
        Carbon $visitedAt,
        int $receptionOdo,
        PaymentMethod $method,
    ): void {
        $order = WorkOrder::query()->where('code', $code)->first();

        if (! $order instanceof WorkOrder) {
            $order = WorkOrder::query()->create([
                'code' => $code,
                'vehicle_id' => $vehicle->id,
                'service_advisor_id' => $advisorId,
                'reception_odo' => $receptionOdo,
                'fuel_level' => '1/2',
                'customer_request' => 'Lịch sử sửa chữa mẫu cho dashboard.',
                'status' => WorkOrderStatus::Delivered,
                'discount_amount' => 0,
                'vat_rate' => 8,
            ]);
            $this->addWorkOrderItem($order, WorkOrderItemType::Service, $catalogService->id, 1, (string) $catalogService->labor_cost, $technicianId);
            $service->recalculateTotals($order);
            $order->update(['status' => WorkOrderStatus::Delivered]);
            $order->refresh();
        }

        WorkOrder::query()->whereKey($order->id)->update([
            'created_at' => $visitedAt,
            'updated_at' => $visitedAt,
            'delivered_at' => $visitedAt,
            // Lệnh mẫu chỉ có tiền công (giá vốn 0): lãi gộp bằng doanh thu trước VAT.
            'gross_profit' => round((float) $order->total_parts_amount + (float) $order->total_labor_amount - (float) $order->discount_amount, 2),
        ]);

        $invoice = Invoice::query()->updateOrCreate(
            ['invoice_number' => $invoiceNumber],
            [
                'work_order_id' => $order->id,
                'total_amount' => $order->final_amount,
                'paid_amount' => $order->final_amount,
                'payment_method' => $method,
                'payment_status' => PaymentStatus::Paid,
                'notes' => 'Hóa đơn mẫu cho biểu đồ doanh thu.',
            ],
        );
        Invoice::query()->whereKey($invoice->id)->update([
            'created_at' => $visitedAt,
            'updated_at' => $visitedAt,
        ]);
    }

    private function addWorkOrderItem(
        WorkOrder $workOrder,
        WorkOrderItemType $type,
        int $itemId,
        float|int $quantity,
        string $unitPrice,
        ?int $technicianId,
    ): void {
        WorkOrderItem::query()->create([
            'work_order_id' => $workOrder->id,
            'item_type' => $type,
            'item_id' => $itemId,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'discount_amount' => 0,
            'subtotal' => WorkOrderItem::calculateSubtotal($quantity, $unitPrice, 0),
            'technician_id' => $technicianId,
            'status' => 'pending',
        ]);
    }
}
