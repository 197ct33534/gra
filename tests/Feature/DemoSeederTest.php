<?php

namespace Tests\Feature;

use App\Enums\InvoicePayer;
use App\Enums\PaymentStatus;
use App\Enums\WorkOrderItemSource;
use App\Enums\WorkOrderStatus;
use App\Models\Invoice;
use App\Models\Part;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleRecommendation;
use App\Models\WorkOrder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Bộ dữ liệu demo cho chủ gara phải luôn nạp được và đúng kịch bản chào hàng.
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedDemoAt('2026-10-21 18:00:00');
    }

    /**
     * Mỗi test nạp demo đúng một lần: SQLite không tắt được khóa ngoại trong transaction của RefreshDatabase,
     * nên không nạp lại lần hai trong cùng test (lệnh `db:seed` thật thì nạp lại được).
     */
    private function seedDemoAt(string $localTime): void
    {
        if ($this->name() === 'test_seeding_early_in_the_month_never_creates_future_orders') {
            $localTime = '2026-11-04 07:00:00';
        }

        Carbon::setTestNow(Carbon::parse($localTime, 'Asia/Ho_Chi_Minh'));
        $this->seed(DatabaseSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function vehicle(string $plate): Vehicle
    {
        return Vehicle::query()->where('license_plate', $plate)->firstOrFail();
    }

    public function test_staff_catalog_and_stock_match_the_demo_script(): void
    {
        $this->assertSame(
            ['admin@gara.vn', 'sa1@gara.vn', 'sa2@gara.vn', 'tech1@gara.vn', 'tech2@gara.vn', 'tech3@gara.vn', 'tech4@gara.vn'],
            User::query()->orderBy('id')->pluck('email')->all(),
        );
        $this->assertGreaterThanOrEqual(15, Part::query()->count());
        $this->assertSame(60, Part::query()->where('sku', 'CAST-5W30')->value('stock_quantity'));
        $this->assertSame(['LOC-GIO-DH-CX5'], Part::query()->lowStock()->pluck('sku')->all());

        foreach (Part::query()->get() as $part) {
            $this->assertSame($part->stock_quantity, (int) StockTransaction::query()->where('part_id', $part->id)->sum('quantity'), "Thẻ kho lệch: {$part->sku}");
        }
    }

    public function test_six_vehicle_scenarios(): void
    {
        $vios = $this->vehicle('51G-823.45');
        $visits = $vios->workOrders()->orderBy('created_at')->get();
        $this->assertSame([55000, 68000, 78000, 85000], $visits->pluck('reception_odo')->all());
        $this->assertSame(WorkOrderStatus::Completed, $visits->last()->status);
        $this->assertSame(PaymentStatus::Paid, $visits->last()->invoices()->sole()->payment_status);
        $this->assertTrue(VehicleRecommendation::query()->where('vehicle_id', $vios->id)->where('item_name', 'Cao su rotuyn cân bằng Vios')->exists());

        $ranger = $this->vehicle('60A-912.34')->workOrders()->sole();
        $this->assertSame('Cầu 4', $ranger->lift_bay);
        $this->assertSame('11900000.00', $ranger->invoices()->where('payer_type', InvoicePayer::Insurance)->sole()->total_amount);
        $this->assertSame('1700000.00', $ranger->invoices()->where('payer_type', InvoicePayer::Customer)->sole()->total_amount);

        $c200 = $this->vehicle('30E-678.90')->workOrders()->sole();
        $this->assertSame(WorkOrderStatus::Quoted, $c200->status);
        $this->assertSame(2, $c200->maxRevision());
        $this->assertSame(1, $c200->items()->where('is_approved', false)->count());

        $santafe = $this->vehicle('51H-345.67')->workOrders()->sole();
        $this->assertSame(WorkOrderStatus::InProgress, $santafe->status);
        $this->assertTrue($santafe->needs_restock);
        $this->assertTrue($santafe->items()->where('item_source', WorkOrderItemSource::DirectPurchase)->where('supplier_info', 'Phụ tùng An Dân')->exists());

        $mazda = $this->vehicle('51K-555.22')->workOrders()->sole();
        $this->assertSame('0.00', $mazda->items()->where('item_source', WorkOrderItemSource::CustomerSupplied)->value('unit_price'));
        $this->assertSame('240000.00', $mazda->items()->where('item_source', WorkOrderItemSource::Subcontract)->value('cost_price'));

        $seltos = $this->vehicle('51H-899.11');
        $this->assertSame(36500, $seltos->current_odo);
        $this->assertTrue($seltos->isMaintenanceDue());
    }

    public function test_this_month_revenue_and_gross_margin_look_like_a_real_garage(): void
    {
        $start = Carbon::now('Asia/Ho_Chi_Minh')->startOfMonth()->utc();
        $paid = Invoice::query()->where('created_at', '>=', $start)->where('payment_status', PaymentStatus::Paid)->get();
        $this->assertGreaterThanOrEqual(12, $paid->count());
        $this->assertLessThanOrEqual(15, $paid->count());
        $this->assertGreaterThanOrEqual(800000, (float) $paid->min('total_amount'));
        $this->assertLessThanOrEqual(7500000, (float) $paid->max('total_amount'));

        $delivered = WorkOrder::query()->where('status', WorkOrderStatus::Delivered)->where('delivered_at', '>=', $start)->get();
        $revenue = $delivered->sum(fn (WorkOrder $order): float => (float) $order->total_parts_amount + (float) $order->total_labor_amount - (float) $order->discount_amount);
        $margin = (float) $delivered->sum('gross_profit') / $revenue * 100;
        $this->assertGreaterThanOrEqual(35, $margin);
        $this->assertLessThanOrEqual(42, $margin);

        $this->assertSame(0, WorkOrder::query()->where('created_at', '>', now())->count(), 'Không được có lệnh "tương lai".');
    }

    public function test_seeding_early_in_the_month_never_creates_future_orders(): void
    {
        $this->assertSame(0, WorkOrder::query()->where('created_at', '>', now())->count());
        $this->assertSame(0, Invoice::query()->where('created_at', '>', now()->addDay())->count());
        $this->assertGreaterThanOrEqual(12, Invoice::query()->where('payment_status', PaymentStatus::Paid)->where('created_at', '>=', now()->subDays(40))->count());
    }

    public function test_key_demo_pages_open_for_the_owner(): void
    {
        $this->actingAs(User::query()->where('email', 'admin@gara.vn')->firstOrFail());

        foreach (['/admin', '/admin/workshop-board', '/admin/work-orders', '/admin/vehicles', '/admin/customer-care', '/admin/technician-payroll', '/admin/invoices'] as $url) {
            $this->get($url)->assertOk();
        }

        $this->get('/admin/vehicles/'.$this->vehicle('51G-823.45')->id)
            ->assertOk()
            ->assertSee('Cao su rotuyn cân bằng Vios');
    }
}
