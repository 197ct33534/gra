<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Filament\Pages\FinancialReports;
use App\Filament\Pages\TechnicianTimeClock;
use App\Models\Invoice;
use App\Models\Part;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\FinancialReportService;
use App\Services\GarageAlertService;
use App\Services\PayrollService;
use App\Services\TimeClockService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\TestCase;

class P1TimeReportsAlertsTest extends TestCase
{
    use RefreshDatabase;

    public function test_technician_can_start_and_stop_time_clock(): void
    {
        $this->seed();

        $tech = User::query()->where('email', 'tech1@gara.vn')->firstOrFail();
        $order = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $order->update(['status' => WorkOrderStatus::InProgress]);
        $order->items()->update(['technician_id' => $tech->id]);

        $clock = app(TimeClockService::class);
        $log = $clock->start($tech, $order);
        $this->assertTrue($log->isOpen());

        $this->expectException(DomainException::class);
        $clock->start($tech, $order);
    }

    public function test_stop_time_clock_accumulates_actual_minutes(): void
    {
        $this->seed();

        $tech = User::query()->where('email', 'tech1@gara.vn')->firstOrFail();
        $order = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $order->update(['status' => WorkOrderStatus::InProgress]);
        $item = $order->items()->where('item_type', 'service')->firstOrFail();
        $item->update(['technician_id' => $tech->id]);

        $clock = app(TimeClockService::class);
        $clock->start($tech, $order, $item->id);

        // Giả lập đã chạy 12 phút.
        $open = $clock->openLogFor($tech);
        $open->started_at = now()->subMinutes(12);
        $open->save();

        $stopped = $clock->stop($tech);
        $this->assertFalse($stopped->isOpen());
        $this->assertGreaterThanOrEqual(12, (int) $stopped->minutes);

        $item->refresh();
        $this->assertGreaterThanOrEqual(12, (int) $item->actual_minutes);
    }

    public function test_aging_and_pnl_reports(): void
    {
        $this->seed();

        $aging = app(FinancialReportService::class)->agingReceivables();
        $this->assertArrayHasKey('buckets', $aging);
        $this->assertArrayHasKey('0_30', $aging['buckets']);

        // Tạo HĐ chưa thu để aging > 0 (created_at không nằm trong fillable).
        $invoice = Invoice::query()->firstOrFail();
        $invoice->forceFill([
            'paid_amount' => 0,
            'payment_status' => 'unpaid',
            'created_at' => now()->subDays(45),
        ])->save();

        $aging = app(FinancialReportService::class)->agingReceivables();
        $this->assertGreaterThan(0, $aging['total']);
        $this->assertTrue(
            $aging['buckets']['31_60']['count'] + $aging['buckets']['61_90']['count'] + $aging['buckets']['90_plus']['count'] > 0
            || $aging['buckets']['0_30']['count'] > 0
        );

        $month = PayrollService::currentMonth();
        $pnl = app(FinancialReportService::class)->monthlyPnL($month);
        $this->assertArrayHasKey('delivered_revenue', $pnl);
        $this->assertArrayHasKey('cars', $pnl);
    }

    public function test_financial_reports_page_for_admin(): void
    {
        $this->seed();
        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $this->actingAs($admin);

        Livewire::test(FinancialReports::class)->assertOk();
        $this->get(FinancialReports::getUrl())->assertOk();
    }

    public function test_time_clock_page_for_technician(): void
    {
        $this->seed();
        $tech = User::query()->where('email', 'tech1@gara.vn')->firstOrFail();
        $this->actingAs($tech);

        Livewire::test(TechnicianTimeClock::class)->assertOk();
        $this->get(TechnicianTimeClock::getUrl())->assertOk();
    }

    public function test_dispatch_alerts_notifies_late_ro_and_low_stock(): void
    {
        $this->seed();

        $order = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $order->update([
            'status' => WorkOrderStatus::InProgress,
            'promised_at' => now()->subHours(2),
        ]);

        $part = Part::query()->firstOrFail();
        $part->update([
            'stock_quantity' => 0,
            'reserved_quantity' => 0,
            'min_stock_alert' => 5,
        ]);

        cache()->flush();

        $this->artisan('garage:dispatch-alerts')->assertSuccessful();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $this->assertGreaterThan(0, $admin->notifications()->count());

        // Lần 2 trong cửa sổ 12h không spam thêm cùng key.
        $before = $admin->notifications()->count();
        Artisan::call('garage:dispatch-alerts');
        $this->assertSame($before, $admin->fresh()->notifications()->count());
    }

    public function test_garage_alert_service_lists_late_orders(): void
    {
        $this->seed();

        WorkOrder::query()->where('code', 'RO-20261019-002')->update([
            'status' => WorkOrderStatus::Qc,
            'promised_at' => now()->subDay(),
        ]);

        $late = app(GarageAlertService::class)->lateWorkOrders();
        $this->assertTrue($late->contains(fn ($o) => $o->code === 'RO-20261019-002'));
    }
}
