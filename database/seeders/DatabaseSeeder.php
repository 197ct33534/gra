<?php

namespace Database\Seeders;

use App\Enums\CustomerMessageStatus;
use App\Enums\CustomerType;
use App\Enums\InsuranceClaimStatus;
use App\Enums\InvoicePayer;
use App\Enums\PaymentMethod;
use App\Enums\StockTransactionType;
use App\Enums\UserRole;
use App\Enums\WorkOrderItemSource;
use App\Enums\WorkOrderItemType;
use App\Enums\WorkOrderStatus;
use App\Enums\WorkOrderType;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\Service;
use App\Models\ServicePackage;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\CustomerPortalService;
use App\Services\QuoteApprovalService;
use App\Services\WorkOrderService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dữ liệu demo cho buổi chào hàng chủ gara: 6 hồ sơ xe theo kịch bản thực chiến + doanh thu tháng này.
 *
 * Mọi lệnh sửa chữa đi qua đúng các service nghiệp vụ (duyệt → giữ kho → hóa đơn → bàn giao) nên thẻ kho,
 * khuyến cáo bị từ chối, lương khoán, thẻ bảo hành, nhật ký đều nhất quán như gara dùng thật.
 * Lần sửa trong quá khứ được tạo bằng cách "đặt đồng hồ" về đúng ngày đó.
 *
 * Bộ test tự động dùng TestFixtureSeeder, không dùng file này.
 */
class DatabaseSeeder extends Seeder
{
    private const PASSWORD = 'password';

    private const TIMEZONE = 'Asia/Ho_Chi_Minh';

    /**
     * Tồn kho hiển thị sau khi nạp demo (đã trừ các lần xuất cho xe).
     *
     * @var array<string, int>
     */
    private array $targetStock = [];

    /**
     * @var array<string, User>
     */
    private array $users = [];

    private WorkOrderService $workOrders;

    public function run(): void
    {
        $this->workOrders = app(WorkOrderService::class);
        $this->wipe();

        try {
            DB::transaction(function (): void {
                $this->seedUsers();
                $this->seedCatalog();
                $this->seedServicePackages();

                $this->scenarioViosLifetimeHistory();
                $this->scenarioSeltosMaintenanceDue();
                $this->scenarioMonthlyRevenue();
                $this->scenarioRangerInsurance();
                $this->scenarioMercedesRevisions();
                $this->scenarioSantafeWaitingParts();
                $this->scenarioMazdaCustomerPartsAndSubcontract();

                $this->settleOpeningStock();
                $this->settleMessages();
            });
        } finally {
            Carbon::setTestNow();
            Auth::guard()->forgetUser();
        }
    }

    // ------------------------------------------------------------------
    // Dọn dữ liệu cũ
    // ------------------------------------------------------------------

    /**
     * Xóa sạch mọi bảng nghiệp vụ (chạy được cả `db:seed` trên DB đang có dữ liệu).
     * Làm ngoài transaction vì TRUNCATE trên MySQL tự commit và SQLite không đổi PRAGMA trong transaction.
     */
    private function wipe(): void
    {
        $tables = collect(Schema::getTableListing())
            ->map(fn (string $table): string => str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table)
            ->reject(fn (string $table): bool => in_array($table, ['migrations', 'sqlite_sequence'], true))
            ->values();

        Schema::withoutForeignKeyConstraints(function () use ($tables): void {
            foreach ($tables as $table) {
                DB::table($table)->truncate();
            }
        });

        // Chi nhánh mặc định vốn do migration tạo: dựng lại sau khi xóa.
        Branch::query()->create([
            'code' => 'CN-HCM',
            'name' => 'Chi nhánh chính',
            'address' => config('garage.address'),
            'hotline' => config('garage.hotline'),
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    // ------------------------------------------------------------------
    // Nhân sự, danh mục
    // ------------------------------------------------------------------

    private function seedUsers(): void
    {
        $branchId = Branch::defaultId();
        $staff = [
            'admin' => ['Nguyễn Văn Đức - Giám đốc xưởng', 'admin@gara.vn', UserRole::Admin, null],
            'sa1' => ['Lê Hoàng - Cố vấn xe Nhật/Hàn', 'sa1@gara.vn', UserRole::ServiceAdvisor, null],
            'sa2' => ['Trần Minh - Cố vấn xe Đức & Bảo hiểm', 'sa2@gara.vn', UserRole::ServiceAdvisor, null],
            'tech1' => ['Trần Văn Toàn - Thợ máy gầm chính', 'tech1@gara.vn', UserRole::Technician, 35],
            'tech2' => ['Phạm Văn Nam - Thợ điện lạnh', 'tech2@gara.vn', UserRole::Technician, 30],
            'tech3' => ['Lê Văn Hùng - Thợ đồng sơn', 'tech3@gara.vn', UserRole::Technician, 30],
            'tech4' => ['Đỗ Tuấn - Kỹ thuật viên phụ', 'tech4@gara.vn', UserRole::Technician, 20],
        ];

        foreach ($staff as $key => [$name, $email, $role, $commission]) {
            $this->users[$key] = User::query()->create([
                'name' => $name,
                'email' => $email,
                'password' => self::PASSWORD,
                'role' => $role,
                'commission_rate' => $commission,
                'branch_id' => $branchId,
            ]);
        }
    }

    private function seedCatalog(): void
    {
        $categories = [];

        foreach (['Dầu nhớt & Phụ gia', 'Hệ thống lọc', 'Gầm & Phanh', 'Điện & Đánh lửa', 'Lốp & Ắc quy', 'Làm mát & Động cơ', 'Thân vỏ & Phụ kiện'] as $name) {
            $categories[$name] = PartCategory::query()->create(['name' => $name])->id;
        }

        // [sku, tên, đvt, OEM, vốn, bán, tồn hiển thị, ngưỡng, kệ, nhóm, BH tháng, BH km]
        $parts = [
            ['CAST-5W30', 'Nhớt Castrol Magnatec 5W-30', 'Lít', null, 110000, 165000, 60, 12, 'KHO-DAU-01', 'Dầu nhớt & Phụ gia', null, null],
            ['MOTUL-300V', 'Nhớt Motul 300V 5W-40', 'Lít', null, 230000, 320000, 18, 6, 'KHO-DAU-02', 'Dầu nhớt & Phụ gia', null, null],
            ['PRESTONE-4L', 'Nước làm mát Prestone đỏ', 'Can 4L', null, 140000, 220000, 8, 3, 'A1-02', 'Làm mát & Động cơ', null, null],
            ['DAU-PHANH-DOT4', 'Dầu phanh Bosch DOT4 0.5L', 'Chai', null, 75000, 130000, 20, 4, 'A1-03', 'Gầm & Phanh', null, null],
            ['LOC-NHOT-VIOS', 'Lọc nhớt Vios/Yaris', 'Cái', '04152-YZZA6', 45000, 95000, 15, 4, 'L1-01', 'Hệ thống lọc', null, null],
            ['LOC-NHOT-HONDA', 'Lọc nhớt Honda City/Civic', 'Cái', '15400-RTA-003', 55000, 110000, 10, 3, 'L1-02', 'Hệ thống lọc', null, null],
            ['LOC-NHOT-RANGER', 'Lọc nhớt Ford Ranger 2.0/2.2', 'Cái', 'BB3Q-6744-BA', 95000, 185000, 10, 3, 'L1-03', 'Hệ thống lọc', null, null],
            ['LOC-NHOT-HK', 'Lọc nhớt Hyundai/Kia (Accent, Morning, Seltos)', 'Cái', '26300-35505', 50000, 100000, 10, 3, 'L1-04', 'Hệ thống lọc', null, null],
            ['LOC-GIO-VIOS', 'Lọc gió động cơ Vios/Yaris', 'Cái', '17801-0Y040', 85000, 160000, 12, 3, 'L2-02', 'Hệ thống lọc', null, null],
            // Cố ý dưới ngưỡng: kích hoạt cảnh báo đỏ "Phụ tùng sắp hết" trên Dashboard.
            ['LOC-GIO-DH-CX5', 'Lọc gió điều hòa Mazda CX-5', 'Cái', null, 90000, 180000, 2, 5, 'L2-01', 'Hệ thống lọc', null, null],
            ['MA-PHANH-VIOS', 'Bộ má phanh trước Vios', 'Bộ', null, 350000, 620000, 5, 2, 'P1-02', 'Gầm & Phanh', 6, 10000],
            ['MA-PHANH-RANGER', 'Bộ má phanh trước Ranger', 'Bộ', null, 520000, 890000, 4, 2, 'P1-04', 'Gầm & Phanh', 6, 10000],
            ['RO-TUYN-CB-VIOS', 'Cao su rotuyn cân bằng Vios', 'Cái', null, 120000, 210000, 8, 2, 'G1-02', 'Gầm & Phanh', 6, 10000],
            ['CANG-A-VIOS-L', 'Càng A dưới trái Vios', 'Cái', null, 650000, 1150000, 3, 1, 'G1-01', 'Gầm & Phanh', 12, 20000],
            ['BUGI-IK20', 'Bugi Iridium Denso IK20', 'Cái', null, 120000, 220000, 16, 4, 'E1-01', 'Điện & Đánh lửa', 12, 30000],
            ['GAT-MUA-BOSCH', 'Gạt mưa Bosch Aerotwin (bộ 2 thanh)', 'Bộ', null, 180000, 320000, 10, 2, 'E1-03', 'Thân vỏ & Phụ kiện', null, null],
            ['AC-QUY-55D23L', 'Ắc quy GS 55D23L', 'Bình', null, 1550000, 1850000, 6, 2, 'E2-01', 'Lốp & Ắc quy', 12, null],
            ['LOP-185-60R15', 'Lốp Michelin Energy XM2+ 185/60R15', 'Lốp', null, 1350000, 1650000, 8, 2, 'T1-01', 'Lốp & Ắc quy', 12, 40000],
            ['DAI-CAM-C200', 'Đai cam tổng Mercedes C200 (W205)', 'Sợi', null, 450000, 780000, 2, 1, 'G2-05', 'Làm mát & Động cơ', 12, 20000],
            // Hàng không tồn kho, chỉ mua ngoài theo xe.
            ['GUONG-P-RANGER', 'Cụm gương chiếu hậu phải Ranger Wildtrak (gập điện, đèn báo rẽ)', 'Cụm', null, 6200000, 7900000, 0, 0, null, 'Thân vỏ & Phụ kiện', 6, null],
            ['KET-NUOC-C200', 'Két nước làm mát Mercedes C200 (OEM)', 'Cái', null, 4200000, 5800000, 0, 0, null, 'Làm mát & Động cơ', 12, 20000],
            ['VAN-HANG-NHIET-C200', 'Cụm van hằng nhiệt Mercedes C200', 'Cụm', null, 1100000, 1650000, 0, 0, null, 'Làm mát & Động cơ', 12, 20000],
            ['CU-DE-SANTAFE', 'Củ đề SantaFe 2.2 CRDi', 'Cái', null, 2400000, 3250000, 0, 0, null, 'Điện & Đánh lửa', 6, null],
        ];

        $branchId = Branch::defaultId();

        foreach ($parts as [$sku, $name, $unit, $oem, $cost, $price, $stock, $min, $shelf, $category, $warrantyMonths, $warrantyKm]) {
            Part::query()->create([
                'branch_id' => $branchId,
                'category_id' => $categories[$category],
                'sku' => $sku,
                'oem_code' => $oem,
                'name' => $name,
                'unit' => $unit,
                'cost_price' => $cost,
                'selling_price' => $price,
                // Tồn tạm thật lớn để các lệnh mẫu không bị báo thiếu hàng; chốt lại số thật ở settleOpeningStock().
                'stock_quantity' => 999,
                'min_stock_alert' => $min,
                'shelf_location' => $shelf,
                'warranty_months' => $warrantyMonths,
                'warranty_km' => $warrantyKm,
            ]);
            $this->targetStock[$sku] = $stock;
        }

        // [mã, tên, tiền công, phút chuẩn, khoán cố định cho thợ]
        $services = [
            ['BD-5K', 'Công bảo dưỡng cấp 5.000 km', 150000, 30, null],
            ['BD-20K', 'Công bảo dưỡng cấp 20.000 km', 350000, 60, null],
            ['BD-40K', 'Công bảo dưỡng lớn 40.000 km', 850000, 180, null],
            ['TH-PHANH', 'Công thay 1 cặp má phanh', 200000, 45, 60000],
            ['VS-DANGIA', 'Vệ sinh nội soi dàn lạnh', 450000, 60, null],
            ['SON-CAN', 'Sơn gò cản trước/sau', 900000, 240, null],
            ['SCAN-LOI', 'Quét đọc lỗi OBD-II và chẩn đoán', 150000, 30, null],
            ['KT-TIENGKEU', 'Kiểm tra tiếng kêu khoang động cơ', 300000, 60, null],
            ['THAY-ROTUYN', 'Công thay cặp cao su rotuyn cân bằng', 250000, 45, null],
            ['THAY-CANG-A', 'Công thay càng A + cân chỉnh độ chụm', 350000, 60, null],
            ['THAY-KET-NUOC', 'Công thay két nước + van hằng nhiệt', 600000, 120, null],
            ['SON-CUA', 'Gò, sơn sấy 1 cánh cửa', 4200000, 360, null],
            ['LAP-GUONG', 'Công tháo lắp gương chiếu hậu', 300000, 30, null],
            ['DANH-BONG', 'Đánh bóng toàn xe 3 bước', 1200000, 240, null],
            ['THAY-CU-DE', 'Công tháo lắp củ đề', 450000, 90, null],
            ['THAY-NHOT', 'Công thay nhớt (khách mang nhớt)', 100000, 20, null],
            ['LANG-DIA', 'Láng 2 đĩa phanh trước (gia công ngoài)', 450000, 60, null],
            ['THAY-LOP', 'Tháo lắp + cân bằng động 1 lốp', 80000, 15, null],
            ['THAY-AC-QUY', 'Công thay ắc quy + reset hệ thống', 50000, 15, null],
        ];

        foreach ($services as [$code, $name, $labor, $minutes, $payout]) {
            Service::query()->create([
                'code' => $code,
                'name' => $name,
                'labor_cost' => $labor,
                'standard_time_minutes' => $minutes,
                'technician_payout' => $payout,
            ]);
        }
    }

    private function seedServicePackages(): void
    {
        $packages = [
            ['GOI-BD5K-VIOS', 'Bảo dưỡng 5.000 km Vios/Yaris', 'Toyota Vios, Yaris', [['service', 'BD-5K', 1], ['part', 'CAST-5W30', 4], ['part', 'LOC-NHOT-VIOS', 1]]],
            ['GOI-BD20K-RANGER', 'Bảo dưỡng 20.000 km Ranger/Everest', 'Ford Ranger, Everest 2.2', [['service', 'BD-20K', 1], ['part', 'CAST-5W30', 8], ['part', 'LOC-NHOT-RANGER', 1]]],
            ['GOI-PHANH-VIOS', 'Thay má phanh trước Vios', 'Toyota Vios', [['service', 'TH-PHANH', 1], ['part', 'MA-PHANH-VIOS', 1], ['part', 'DAU-PHANH-DOT4', 1]]],
        ];

        foreach ($packages as $index => [$code, $name, $hint, $items]) {
            $package = ServicePackage::query()->create([
                'code' => $code,
                'name' => $name,
                'vehicle_hint' => $hint,
                'is_active' => true,
                'sort_order' => $index + 1,
            ]);

            foreach ($items as [$type, $key, $quantity]) {
                $package->items()->create([
                    'item_type' => $type,
                    'item_id' => $type === 'part' ? $this->partId($key) : $this->serviceId($key),
                    'quantity' => $quantity,
                ]);
            }
        }
    }

    // ------------------------------------------------------------------
    // Kịch bản 1: Vios chạy Grab — bệnh án trọn đời & khuyến cáo bị từ chối
    // ------------------------------------------------------------------

    private function scenarioViosLifetimeHistory(): void
    {
        $vios = $this->vehicle('Anh Hoàng Grab', '0903123456', '51G-823.45', 'Toyota', 'Vios', 2019, 50000);

        $this->deliveredVisit($vios, $this->ago(11, 9), 55000, 'sa1', [
            ['service', 'BD-20K', 1, 'tech1'],
            ['part', 'CAST-5W30', 4, 'tech1'],
            ['part', 'LOC-NHOT-VIOS', 1, 'tech1'],
            ['part', 'LOC-GIO-VIOS', 1, 'tech1'],
        ], 'Bảo dưỡng định kỳ, xe chạy Grab.', PaymentMethod::BankTransfer);

        $this->deliveredVisit($vios, $this->ago(7, 14), 68000, 'sa1', [
            ['service', 'BD-5K', 1, 'tech4'],
            ['part', 'CAST-5W30', 4, 'tech4'],
            ['part', 'LOC-NHOT-VIOS', 1, 'tech4'],
            ['service', 'TH-PHANH', 1, 'tech1'],
            ['part', 'MA-PHANH-VIOS', 1, 'tech1'],
            ['part', 'DAU-PHANH-DOT4', 1, 'tech1'],
        ], 'Phanh kêu ken két khi dừng, bảo dưỡng luôn.', PaymentMethod::Card);

        // Lần 78.000 km: thợ báo cao su rotuyn cân bằng nứt, khách chạy Grab nên hẹn lần sau.
        $this->deliveredVisit($vios, $this->ago(3, 20), 78000, 'sa1', [
            ['service', 'BD-20K', 1, 'tech1'],
            ['part', 'CAST-5W30', 4, 'tech1'],
            ['part', 'LOC-NHOT-VIOS', 1, 'tech1'],
            ['part', 'BUGI-IK20', 4, 'tech2'],
            ['part', 'RO-TUYN-CB-VIOS', 2, 'tech1', ['is_approved' => false, 'decline_reason' => 'Khách chạy Grab, hẹn lần bảo dưỡng sau']],
            ['service', 'THAY-ROTUYN', 1, 'tech1', ['is_approved' => false, 'decline_reason' => 'Khách chạy Grab, hẹn lần bảo dưỡng sau']],
        ], 'Bảo dưỡng 80k sớm, gầm có tiếng lục cục khi qua gờ.', PaymentMethod::BankTransfer);

        // Hôm nay 85.000 km: bảo dưỡng lớn + làm luôn rotuyn đã hoãn. Đã thu đủ, xe chờ khách lấy.
        $today = $this->todayAt(7, 45);
        $order = $this->at($today, fn (): WorkOrder => $this->createOrder($vios, 'sa1', 85000, [
            ['service', 'BD-40K', 1, 'tech1'],
            ['part', 'CAST-5W30', 4, 'tech1'],
            ['part', 'LOC-NHOT-VIOS', 1, 'tech1'],
            ['part', 'LOC-GIO-VIOS', 1, 'tech1'],
            ['part', 'PRESTONE-4L', 1, 'tech4'],
            ['part', 'DAU-PHANH-DOT4', 1, 'tech4'],
            ['part', 'RO-TUYN-CB-VIOS', 2, 'tech1'],
            ['service', 'THAY-ROTUYN', 1, 'tech1'],
        ], [
            'customer_request' => 'Bảo dưỡng mốc 80k, làm luôn cao su rotuyn lần trước hoãn.',
            'technical_diagnosis' => 'Đến hạn bảo dưỡng lớn; cao su rotuyn cân bằng nứt cả 2 bên.',
            'promised_at' => $this->todayAt(16, 0),
        ]));
        $this->at($today->copy()->addMinutes(20), fn (): array => $this->workOrders->approveWorkOrder($order, $this->users['sa1']->id));
        $this->at($this->todayAt(8, 30), fn (): bool => $order->refresh()->update(['status' => WorkOrderStatus::InProgress]));
        $this->at($this->todayAt(11, 15), fn () => $this->workOrders->issueInvoice($order->refresh(), PaymentMethod::BankTransfer, (float) $order->final_amount, 'Khách chuyển khoản đủ, chờ lấy xe chiều.'));
    }

    // ------------------------------------------------------------------
    // Kịch bản 6: Seltos quá hạn bảo dưỡng
    // ------------------------------------------------------------------

    private function scenarioSeltosMaintenanceDue(): void
    {
        $seltos = $this->vehicle('Lê Văn Tuấn', '0908889911', '51H-899.11', 'Kia', 'Seltos', 2021, 20000);

        $this->deliveredVisit($seltos, $this->ago(10, 10), 21000, 'sa1', [
            ['service', 'BD-5K', 1, 'tech4'],
            ['part', 'CAST-5W30', 4, 'tech4'],
            ['part', 'LOC-NHOT-HK', 1, 'tech4'],
        ], 'Bảo dưỡng định kỳ.', PaymentMethod::Card);

        // Lần gần nhất: 5 tháng trước, mốc 30.000 km.
        $this->deliveredVisit($seltos, $this->ago(5, 8), 30000, 'sa1', [
            ['service', 'BD-20K', 1, 'tech1'],
            ['part', 'CAST-5W30', 4, 'tech1'],
            ['part', 'LOC-NHOT-HK', 1, 'tech1'],
            ['part', 'BUGI-IK20', 4, 'tech2'],
        ], 'Bảo dưỡng 30.000 km.', PaymentMethod::BankTransfer);

        // Khách báo ODO qua Zalo khi đặt hỏi giá: đã chạy thêm 6.500 km.
        $seltos->update(['current_odo' => 36500]);
    }

    // ------------------------------------------------------------------
    // Doanh thu tháng này: 13 xe khách lẻ đã giao, rải từ ngày 1 đến 20
    // ------------------------------------------------------------------

    private function scenarioMonthlyRevenue(): void
    {
        $fleet = [
            'city21' => ['Chị Ngọc Anh', '0912340001', '51F-246.81', 'Honda', 'City', 2021, 31000],
            'vios20' => ['Anh Quốc Bảo', '0938220145', '51H-102.37', 'Toyota', 'Vios', 2020, 64000],
            'everest' => ['Công ty TNHH Vận tải Phúc Lộc', '0909456123', '51D-556.12', 'Ford', 'Everest', 2019, 98000],
            'cx5' => ['Anh Minh Khôi', '0976543210', '30K-812.45', 'Mazda', 'CX-5', 2022, 27000],
            'morning' => ['Chị Bích Trâm', '0905112233', '51G-377.90', 'Kia', 'Morning', 2017, 72000],
            'ranger18' => ['Anh Thành Nam', '0918223344', '62C-145.67', 'Ford', 'Ranger XLS', 2018, 118000],
            'accent' => ['Anh Đức Thịnh', '0939886622', '51K-209.14', 'Hyundai', 'Accent', 2021, 42000],
            'yaris' => ['Chị Thảo Vy', '0987001122', '51H-660.18', 'Toyota', 'Yaris', 2019, 56000],
            'city19' => ['Anh Hải Đăng', '0909332211', '51F-888.03', 'Honda', 'City', 2019, 69000],
            'vios18' => ['Anh Công Lý', '0966778899', '59A-321.09', 'Toyota', 'Vios', 2018, 103000],
        ];
        $vehicles = [];

        foreach ($fleet as $key => [$name, $phone, $plate, $brand, $model, $year, $odo]) {
            $vehicles[$key] = $this->vehicle($name, $phone, $plate, $brand, $model, $year, $odo, $key === 'everest');
        }

        // [ngày trong tháng, xe, ODO, cố vấn, hạng mục, yêu cầu, cách trả, điểm đánh giá]
        $visits = [
            [1, 'city21', 31200, 'sa1', [['service', 'BD-5K', 1, 'tech4'], ['part', 'CAST-5W30', 4, 'tech4'], ['part', 'LOC-NHOT-HONDA', 1, 'tech4'], ['part', 'GAT-MUA-BOSCH', 1, 'tech4']], 'Bảo dưỡng 5.000 km, gạt mưa kêu.', PaymentMethod::BankTransfer, 5],
            [2, 'vios20', 64500, 'sa1', [['service', 'BD-20K', 1, 'tech1'], ['part', 'CAST-5W30', 4, 'tech1'], ['part', 'LOC-NHOT-VIOS', 1, 'tech1'], ['part', 'LOC-GIO-VIOS', 1, 'tech1'], ['part', 'BUGI-IK20', 4, 'tech2'], ['part', 'LOP-185-60R15', 2, 'tech4'], ['service', 'THAY-LOP', 2, 'tech4']], 'Bảo dưỡng + thay 2 lốp trước mòn gai.', PaymentMethod::BankTransfer, 5],
            [3, 'everest', 98300, 'sa2', [['service', 'BD-20K', 1, 'tech1'], ['part', 'CAST-5W30', 8, 'tech1'], ['part', 'LOC-NHOT-RANGER', 1, 'tech1'], ['part', 'DAU-PHANH-DOT4', 2, 'tech1']], 'Xe công ty bảo dưỡng định kỳ.', PaymentMethod::BankTransfer, 4],
            [5, 'cx5', 27400, 'sa1', [['service', 'VS-DANGIA', 1, 'tech2'], ['part', 'LOC-GIO-DH-CX5', 1, 'tech2'], ['part', 'PRESTONE-4L', 1, 'tech2'], ['part', 'GAT-MUA-BOSCH', 1, 'tech4']], 'Điều hòa có mùi, gió yếu.', PaymentMethod::Card, 5],
            [6, 'morning', 72300, 'sa1', [['service', 'BD-5K', 1, 'tech4'], ['part', 'CAST-5W30', 4, 'tech4'], ['part', 'LOC-NHOT-HK', 1, 'tech4'], ['part', 'AC-QUY-55D23L', 1, 'tech2'], ['service', 'THAY-AC-QUY', 1, 'tech2']], 'Sáng đề khó nổ, bảo dưỡng luôn.', PaymentMethod::BankTransfer, 4],
            [8, 'ranger18', 118400, 'sa2', [['service', 'TH-PHANH', 1, 'tech1'], ['part', 'MA-PHANH-RANGER', 1, 'tech1'], ['part', 'DAU-PHANH-DOT4', 1, 'tech1'], ['service', 'SCAN-LOI', 1, 'tech2']], 'Đạp phanh rung, đèn check sáng.', PaymentMethod::BankTransfer, 5],
            [9, 'accent', 42300, 'sa1', [['service', 'BD-20K', 1, 'tech1'], ['part', 'CAST-5W30', 4, 'tech1'], ['part', 'LOC-NHOT-HK', 1, 'tech1'], ['part', 'LOP-185-60R15', 2, 'tech4'], ['service', 'THAY-LOP', 2, 'tech4']], 'Bảo dưỡng + 2 lốp sau phồng hông.', PaymentMethod::Card, 3],
            [11, 'yaris', 56200, 'sa1', [['service', 'BD-5K', 1, 'tech4'], ['part', 'CAST-5W30', 4, 'tech4'], ['part', 'LOC-NHOT-VIOS', 1, 'tech4'], ['part', 'PRESTONE-4L', 1, 'tech4']], 'Bảo dưỡng 5.000 km.', PaymentMethod::BankTransfer, 5],
            [13, 'city19', 69300, 'sa1', [['service', 'BD-5K', 1, 'tech4'], ['part', 'CAST-5W30', 4, 'tech4'], ['part', 'LOC-NHOT-HONDA', 1, 'tech4'], ['part', 'AC-QUY-55D23L', 1, 'tech2'], ['service', 'THAY-AC-QUY', 1, 'tech2']], 'Ắc quy yếu, bảo dưỡng.', PaymentMethod::BankTransfer, null],
            [14, 'vios18', 103200, 'sa1', [['service', 'TH-PHANH', 1, 'tech1'], ['part', 'MA-PHANH-VIOS', 1, 'tech1'], ['part', 'CANG-A-VIOS-L', 1, 'tech1'], ['service', 'THAY-CANG-A', 1, 'tech1']], 'Lái bị lệch, phanh kêu.', PaymentMethod::Card, 4],
            [16, 'everest', 99100, 'sa2', [['service', 'VS-DANGIA', 1, 'tech2'], ['part', 'PRESTONE-4L', 2, 'tech2'], ['part', 'GAT-MUA-BOSCH', 1, 'tech4']], 'Điều hòa yếu, nước làm mát hao.', PaymentMethod::BankTransfer, 5],
            [18, 'city21', 31900, 'sa1', [['part', 'LOP-185-60R15', 2, 'tech4'], ['service', 'THAY-LOP', 2, 'tech4'], ['part', 'AC-QUY-55D23L', 1, 'tech2'], ['service', 'THAY-AC-QUY', 1, 'tech2']], 'Thủng lốp, ắc quy hết bảo hành.', PaymentMethod::Card, null],
            [20, 'ranger18', 119100, 'sa2', [['service', 'BD-20K', 1, 'tech1'], ['part', 'CAST-5W30', 8, 'tech1'], ['part', 'LOC-NHOT-RANGER', 1, 'tech1']], 'Bảo dưỡng 20.000 km.', PaymentMethod::BankTransfer, 5],
        ];

        $now = Carbon::now(self::TIMEZONE);
        $month = $now->copy()->startOfMonth();

        foreach ($visits as $index => [$day, $key, $odo, $advisor, $items, $request, $method, $score]) {
            $date = $month->copy()->day($day)->setTime(8 + ($index % 3), 15 + ($index * 7) % 40);

            // Chạy seeder vào đầu tháng: ngày chưa tới thì lùi về tháng trước, không tạo hóa đơn "tương lai".
            if ($date->copy()->setTime(16, 40)->gt($now)) {
                $date->subMonthNoOverflow();
            }
            $order = $this->deliveredVisit($vehicles[$key], $date, $odo, $advisor, $items, $request, $method);

            if ($score !== null) {
                $order->update([
                    'feedback_score' => $score,
                    'feedback_comment' => $score >= 5 ? 'Làm nhanh, báo giá rõ ràng.' : ($score === 3 ? 'Chờ lấy xe hơi lâu so với hẹn.' : null),
                    'feedback_at' => $date->copy()->addDay()->utc(),
                ]);
            }
        }
    }

    // ------------------------------------------------------------------
    // Kịch bản 2: Ranger làm bảo hiểm PVI — tách 2 hóa đơn
    // ------------------------------------------------------------------

    private function scenarioRangerInsurance(): void
    {
        $ranger = $this->vehicle('Nguyễn Văn Tuấn', '0918776655', '60A-912.34', 'Ford', 'Ranger Wildtrak', 2021, 61000);
        $start = Carbon::now(self::TIMEZONE)->subDays(3)->setTime(9, 0);

        $order = $this->at($start, fn (): WorkOrder => $this->createOrder($ranger, 'sa2', 61250, [
            ['service', 'SON-CUA', 1, 'tech3'],
            ['part', 'GUONG-P-RANGER', 1, 'tech3', ['item_source' => WorkOrderItemSource::DirectPurchase, 'supplier_info' => 'Phụ tùng Ford chính hãng Phú Mỹ Hưng']],
            ['service', 'LAP-GUONG', 1, 'tech3'],
            // Khách tự trả: ngoài phạm vi bồi thường của PVI.
            ['service', 'DANH-BONG', 1, 'tech3', ['is_outside_insurance' => true]],
        ], [
            'order_type' => WorkOrderType::Insurance,
            'insurance_company' => 'PVI Sài Gòn',
            'insurance_deductible' => 500000,
            'insurance_claim_no' => 'PVI-HCM-2026-08812',
            'insurance_adjuster' => 'Nguyễn Hữu Phước (GĐV PVI)',
            'insurance_expected_pay_at' => Carbon::now(self::TIMEZONE)->addDays(25)->toDateString(),
            'insurance_claim_status' => InsuranceClaimStatus::Approved,
            'lift_bay' => 'Cầu 4',
            // VAT 0% để hai hóa đơn khớp đúng số chào hàng (PVI 11.900.000 đ, khách 1.700.000 đ).
            'vat_rate' => 0,
            'customer_request' => 'Va quẹt cột bãi xe: móp cửa sau phải, vỡ gương phải. Khách muốn đánh bóng toàn xe luôn.',
            'technical_diagnosis' => 'GĐV PVI đã duyệt: gò sơn cửa sau phải + thay cụm gương. Đánh bóng không thuộc phạm vi bồi thường.',
            'promised_at' => $this->todayAt(17, 30),
        ]));

        $this->at($start->copy()->addHours(5), fn (): array => $this->workOrders->approveWorkOrder($order, $this->users['sa2']->id));
        $this->at($start->copy()->addDay()->setTime(8, 0), fn (): bool => $order->refresh()->update(['status' => WorkOrderStatus::InProgress]));

        $issueAt = $this->todayAt(9, 30);
        $this->at($issueAt, fn () => $this->workOrders->issueInvoice($order->refresh(), PaymentMethod::Insurance, 0, 'Chờ PVI giải ngân theo hồ sơ PVI-HCM-2026-08812.', InvoicePayer::Insurance));
        $this->at($issueAt->copy()->addMinutes(5), fn () => $this->workOrders->issueInvoice($order->refresh(), PaymentMethod::BankTransfer, 0, 'Khấu trừ 500.000 đ + đánh bóng 1.200.000 đ. Khách quét QR khi lấy xe.'));
        app(CustomerPortalService::class)->ensureStatusToken($order->refresh());
    }

    // ------------------------------------------------------------------
    // Kịch bản 3: Mercedes C200 — báo giá phát sinh đợt 2, có hạng mục bị từ chối
    // ------------------------------------------------------------------

    private function scenarioMercedesRevisions(): void
    {
        $c200 = $this->vehicle('Chị Thu Hương', '0988554433', '30E-678.90', 'Mercedes-Benz', 'C200', 2018, 74000);
        $start = Carbon::now(self::TIMEZONE)->subDay()->setTime(14, 0);
        $finding = 'Tháo cản trước thấy két nước rỉ ở đáy, van hằng nhiệt kẹt mở khiến máy lâu nóng; đai cam tổng có vết nứt chân răng.';

        $order = $this->at($start, fn (): WorkOrder => $this->createOrder($c200, 'sa2', 74350, [
            ['service', 'KT-TIENGKEU', 1, 'tech1'],
            ['part', 'MOTUL-300V', 6, 'tech1'],
            ['part', 'KET-NUOC-C200', 1, 'tech1', ['revision_no' => 2, 'finding_note' => $finding, 'item_source' => WorkOrderItemSource::DirectPurchase, 'supplier_info' => 'Phụ tùng Đức Mercedes Quận 10']],
            ['part', 'VAN-HANG-NHIET-C200', 1, 'tech1', ['revision_no' => 2, 'finding_note' => $finding, 'item_source' => WorkOrderItemSource::DirectPurchase, 'supplier_info' => 'Phụ tùng Đức Mercedes Quận 10']],
            ['service', 'THAY-KET-NUOC', 1, 'tech1', ['revision_no' => 2, 'finding_note' => $finding]],
            ['part', 'DAI-CAM-C200', 1, 'tech1', ['revision_no' => 2, 'finding_note' => $finding, 'is_approved' => false, 'decline_reason' => 'Khách để lần bảo dưỡng sau']],
        ], [
            'status' => WorkOrderStatus::Quoted,
            'lift_bay' => 'Cầu 2',
            'customer_request' => 'Khoang máy có tiếng rít khi mới nổ máy, thay nhớt luôn.',
            'technical_diagnosis' => 'Tiếng rít do đai cam tổng khô. Báo giá phát sinh sau khi tháo cản kiểm tra két nước.',
            'promised_at' => Carbon::now(self::TIMEZONE)->addDay()->setTime(17, 0),
        ]));

        app(QuoteApprovalService::class)->ensureToken($order);
        app(CustomerPortalService::class)->ensureStatusToken($order);
    }

    // ------------------------------------------------------------------
    // Kịch bản 4: SantaFe kẹt cầu chờ củ đề mua ngoài
    // ------------------------------------------------------------------

    private function scenarioSantafeWaitingParts(): void
    {
        $santafe = $this->vehicle('Trần Trọng', '0977221199', '51H-345.67', 'Hyundai', 'SantaFe 2.2 dầu', 2020, 88000);
        $start = Carbon::now(self::TIMEZONE)->subDay()->setTime(8, 20);

        $order = $this->at($start, fn (): WorkOrder => $this->createOrder($santafe, 'sa1', 88400, [
            ['service', 'SCAN-LOI', 1, 'tech2'],
            ['part', 'CU-DE-SANTAFE', 1, 'tech2', ['item_source' => WorkOrderItemSource::DirectPurchase, 'supplier_info' => 'Phụ tùng An Dân']],
            ['service', 'THAY-CU-DE', 1, 'tech2'],
        ], [
            'lift_bay' => 'Cầu 3',
            'customer_request' => 'Đề không nổ, nghe tạch tạch.',
            'technical_diagnosis' => 'Chổi than củ đề mòn, bánh răng mẻ. Đặt củ đề mới ở Phụ tùng An Dân, hẹn giao chiều nay.',
            // Hẹn trả từ chiều qua mà chưa có đồ: thẻ trên bảng điều phối hiện "Trễ hẹn".
            'promised_at' => $start->copy()->setTime(17, 0),
        ]));

        $this->at($start->copy()->addHours(2), fn (): array => $this->workOrders->approveWorkOrder($order, $this->users['sa1']->id));
        $this->at($start->copy()->addHours(2)->addMinutes(10), fn (): bool => $order->refresh()->update([
            'status' => WorkOrderStatus::InProgress,
            'needs_restock' => true,
        ]));
    }

    // ------------------------------------------------------------------
    // Kịch bản 5: Mazda 3 — khách tự mang nhớt + láng đĩa gửi xưởng ngoài
    // ------------------------------------------------------------------

    private function scenarioMazdaCustomerPartsAndSubcontract(): void
    {
        $mazda = $this->vehicle('Phan Mỹ Linh', '0933445566', '51K-555.22', 'Mazda', '3', 2022, 35000);
        $start = $this->todayAt(8, 10);

        $order = $this->at($start, fn (): WorkOrder => $this->createOrder($mazda, 'sa1', 35200, [
            // Khách mang can nhớt 4L: không tính tiền, không trừ kho, gara không bảo hành phụ tùng.
            ['part', 'CAST-5W30', 4, 'tech4', ['item_source' => WorkOrderItemSource::CustomerSupplied]],
            ['service', 'THAY-NHOT', 1, 'tech4'],
            ['service', 'LANG-DIA', 1, 'tech1', ['item_source' => WorkOrderItemSource::Subcontract, 'cost_price' => 240000, 'supplier_info' => 'Xưởng cơ khí bác Ba']],
        ], [
            'lift_bay' => 'Cầu 1',
            'customer_request' => 'Thay nhớt (khách mang nhớt theo). Đạp phanh bị rung vô lăng.',
            'technical_diagnosis' => 'Đĩa phanh trước cong vênh nhẹ: tháo gửi xưởng bác Ba láng, hẹn trả 14h.',
            'promised_at' => $this->todayAt(15, 30),
        ]));

        $this->at($start->copy()->addMinutes(25), fn (): array => $this->workOrders->approveWorkOrder($order, $this->users['sa1']->id));
        $this->at($start->copy()->addMinutes(40), fn (): bool => $order->refresh()->update(['status' => WorkOrderStatus::InProgress]));
    }

    // ------------------------------------------------------------------
    // Chốt sổ
    // ------------------------------------------------------------------

    /**
     * Đưa tồn kho về đúng con số demo bằng một phiếu "Tồn đầu kỳ" ghi trước mọi giao dịch,
     * để thẻ kho cộng dồn vẫn khớp với tồn hiện tại.
     */
    private function settleOpeningStock(): void
    {
        $openingAt = Carbon::now(self::TIMEZONE)->subMonths(12)->startOfMonth()->setTime(8, 0)->utc();
        $admin = $this->users['admin'];

        foreach (Part::query()->get() as $part) {
            $target = $this->targetStock[$part->sku] ?? 0;
            $moved = (int) StockTransaction::query()->where('part_id', $part->id)->sum('quantity');
            $opening = $target - $moved;

            if ($opening > 0) {
                $transaction = StockTransaction::query()->create([
                    'part_id' => $part->id,
                    'transaction_type' => StockTransactionType::Import,
                    'quantity' => $opening,
                    'unit_price' => $part->cost_price,
                    'note' => 'Tồn đầu kỳ khi bắt đầu dùng phần mềm',
                    'created_by' => $admin->id,
                ]);
                $transaction->forceFill(['created_at' => $openingAt, 'updated_at' => $openingAt])->saveQuietly();
            }

            $part->forceFill(['stock_quantity' => $target])->saveQuietly();
        }
    }

    /**
     * Tin nhắn sinh ra trong quá khứ coi như đã gửi; tin của hôm nay để chờ gửi cho buổi demo.
     */
    private function settleMessages(): void
    {
        $startOfToday = Carbon::now(self::TIMEZONE)->startOfDay()->utc();

        CustomerMessage::query()
            ->where('status', CustomerMessageStatus::Pending)
            ->where('created_at', '<', $startOfToday)
            ->get()
            ->each(fn (CustomerMessage $message): bool => $message->forceFill([
                'status' => CustomerMessageStatus::Sent,
                'sent_at' => $message->scheduled_at ?? $message->created_at,
                'provider_message_id' => 'demo-'.$message->id,
                'attempts' => 1,
            ])->saveQuietly());
    }

    // ------------------------------------------------------------------
    // Hàm dựng dữ liệu
    // ------------------------------------------------------------------

    private function vehicle(string $owner, string $phone, string $plate, string $brand, string $model, int $year, int $odo, bool $company = false): Vehicle
    {
        $customer = Customer::query()->firstOrCreate(['phone' => $phone], [
            'name' => $owner,
            'customer_type' => $company ? CustomerType::Company : CustomerType::Individual,
            'tax_code' => $company ? '0314567890' : null,
            'address' => $company ? 'KCN Tân Bình, TP. Hồ Chí Minh' : null,
            'messaging_opt_in' => true,
            'messaging_opt_in_at' => Carbon::now()->subYear(),
            'messaging_opt_in_source' => 'Đồng ý tại quầy',
        ]);

        return $customer->vehicles()->create([
            'license_plate' => $plate,
            'brand' => $brand,
            'model' => $model,
            'year' => $year,
            'current_odo' => $odo,
        ]);
    }

    /**
     * Một lần vào xưởng đã xong: tiếp nhận buổi sáng, duyệt, thu tiền và giao xe buổi chiều cùng ngày.
     *
     * @param  list<array<int, mixed>>  $items
     */
    private function deliveredVisit(Vehicle $vehicle, Carbon $at, int $odo, string $advisor, array $items, string $request, PaymentMethod $method): WorkOrder
    {
        $sa = $this->users[$advisor];
        $order = $this->at($at, fn (): WorkOrder => $this->createOrder($vehicle, $advisor, $odo, $items, [
            'customer_request' => $request,
            'promised_at' => $at->copy()->setTime(17, 0),
        ]));

        $this->at($at->copy()->addMinutes(30), fn (): array => $this->workOrders->approveWorkOrder($order, $sa->id));
        $this->at($at->copy()->addHour(), fn (): bool => $order->refresh()->update(['status' => WorkOrderStatus::InProgress]));
        $this->at($at->copy()->setTime(16, 10), fn () => $this->workOrders->issueInvoice($order->refresh(), $method, (float) $order->final_amount));
        $this->at($at->copy()->setTime(16, 40), fn (): array => $this->workOrders->completeDelivery($order->refresh(), $sa->id));

        return $order->refresh();
    }

    /**
     * @param  list<array<int, mixed>>  $items  [loại, mã, số lượng, thợ, (thuộc tính thêm)]
     * @param  array<string, mixed>  $attributes
     */
    private function createOrder(Vehicle $vehicle, string $advisor, int $odo, array $items, array $attributes = []): WorkOrder
    {
        $sa = $this->users[$advisor];
        Auth::guard()->setUser($sa);

        // DB lưu UTC: Eloquent không tự đổi múi giờ khi ghi một Carbon giờ Việt Nam.
        $attributes = array_map(
            fn (mixed $value): mixed => $value instanceof Carbon ? $value->copy()->utc() : $value,
            $attributes,
        );

        $order = WorkOrder::query()->create(array_merge([
            'code' => $this->workOrders->nextCode(),
            'branch_id' => Branch::defaultId(),
            'vehicle_id' => $vehicle->id,
            'order_type' => WorkOrderType::Standard,
            'service_advisor_id' => $sa->id,
            'reception_odo' => $odo,
            'fuel_level' => '1/2',
            'checkin_checklist' => ['warranty_book', 'tools', 'spare_tire'],
            'status' => WorkOrderStatus::Reception,
            'discount_amount' => 0,
            'vat_rate' => 8,
        ], $attributes));

        foreach ($items as $line) {
            [$type, $key, $quantity, $technician] = $line;
            $extra = $line[4] ?? [];
            $isPart = $type === 'part';
            $catalog = $isPart
                ? Part::query()->where('sku', $key)->firstOrFail()
                : Service::query()->where('code', $key)->firstOrFail();

            WorkOrderItem::query()->create(array_merge([
                'work_order_id' => $order->id,
                'item_type' => $isPart ? WorkOrderItemType::Part : WorkOrderItemType::Service,
                'item_id' => $catalog->id,
                'item_source' => WorkOrderItemSource::Stock,
                'quantity' => $quantity,
                'unit_price' => $isPart ? $catalog->selling_price : $catalog->labor_cost,
                'cost_price' => $isPart ? $catalog->cost_price : 0,
                'technician_id' => $this->users[$technician]->id,
                'revision_no' => 1,
                'is_approved' => true,
            ], $extra));
        }

        $this->workOrders->recalculateTotals($order);

        return $order->refresh();
    }

    /**
     * Chạy một bước nghiệp vụ như thể đang ở thời điểm $at (giờ Việt Nam).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function at(Carbon $at, callable $callback): mixed
    {
        Carbon::setTestNow($at->copy()->utc());

        try {
            return $callback();
        } finally {
            Carbon::setTestNow();
        }
    }

    private function ago(int $months, int $day): Carbon
    {
        return Carbon::now(self::TIMEZONE)->subMonthsNoOverflow($months)->day(max(1, $day))->setTime(8, 30);
    }

    private function todayAt(int $hour, int $minute): Carbon
    {
        return Carbon::now(self::TIMEZONE)->setTime($hour, $minute);
    }

    private function partId(string $sku): int
    {
        return (int) Part::query()->where('sku', $sku)->value('id');
    }

    private function serviceId(string $code): int
    {
        return (int) Service::query()->where('code', $code)->value('id');
    }
}
