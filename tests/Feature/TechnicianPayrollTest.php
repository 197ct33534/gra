<?php

namespace Tests\Feature;

use App\Enums\WorkOrderItemSource;
use App\Enums\WorkOrderItemType;
use App\Enums\WorkOrderStatus;
use App\Enums\WorkOrderType;
use App\Filament\Pages\TechnicianPayroll;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\PayrollService;
use App\Services\WorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class TechnicianPayrollTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Cố định ngày để các lệnh mẫu (6 và 2 ngày trước) cùng rơi vào tháng 10/2026.
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00:00', 'Asia/Ho_Chi_Minh'));
        $this->seed();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    public function test_payout_follows_fixed_rate_then_personal_then_default_and_is_frozen_at_delivery(): void
    {
        $admin = $this->user('admin@gara.vn');
        $tech2 = $this->user('tech2@gara.vn');
        $tech2->update(['commission_rate' => 40]);
        $service = app(WorkOrderService::class);

        // RO-001: bảo dưỡng 150.000 đ do thợ 1 (mặc định 30%) làm; phụ tùng không tính khoán.
        $ro1 = WorkOrder::query()->where('code', 'RO-20261019-001')->firstOrFail();
        $service->completeDelivery($ro1, $admin->id);
        $this->assertSame('45000.00', $ro1->items()->where('item_type', 'service')->value('technician_payout'));
        $this->assertSame(['0.00', '0.00'], $ro1->items()->where('item_type', 'part')->pluck('technician_payout')->all());

        // RO-002: thay má phanh có khoán cố định 120.000 đ, thắng cả % riêng của thợ 2.
        Service::query()->where('code', 'TH-PHANH')->update(['technician_payout' => 120000]);
        $ro2 = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $ro2->items()->update(['technician_id' => $tech2->id]);
        WorkOrderItem::query()->create([
            'work_order_id' => $ro2->id,
            'item_type' => WorkOrderItemType::Service,
            'item_id' => Service::query()->where('code', 'VS-DANGIA')->value('id'),
            'quantity' => 1,
            'unit_price' => 400000,
            'technician_id' => $tech2->id,
        ]);
        WorkOrderItem::query()->create([
            'work_order_id' => $ro2->id,
            'item_type' => WorkOrderItemType::Service,
            'item_id' => Service::query()->where('code', 'VS-DANGIA')->value('id'),
            'item_source' => WorkOrderItemSource::Subcontract,
            'quantity' => 1,
            'unit_price' => 300000,
            'cost_price' => 150000,
            'technician_id' => $tech2->id,
        ]);
        $service->recalculateTotals($ro2);
        $service->approveWorkOrder($ro2, $admin->id);
        $service->completeDelivery($ro2, $admin->id);

        $payouts = $ro2->items()->where('item_type', 'service')->orderBy('id')->pluck('technician_payout')->all();
        // Khoán cố định 120.000; vệ sinh dàn lạnh 40% × 400.000; gia công ngoài 0.
        $this->assertSame(['120000.00', '160000.00', '0.00'], $payouts);

        // Đổi mức khoán sau khi giao xe không làm đổi lương đã chốt.
        config(['garage.default_commission_percent' => 50]);
        Service::query()->where('code', 'TH-PHANH')->update(['technician_payout' => 999000]);
        $this->assertSame(280000.0, app(PayrollService::class)->monthlyPayoutFor($tech2, '2026-10'));
    }

    public function test_warranty_redo_and_declined_items_pay_nothing(): void
    {
        $admin = $this->user('admin@gara.vn');
        $tech1 = $this->user('tech1@gara.vn');
        $ro2 = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $ro2->update(['order_type' => WorkOrderType::WarrantyRedo]);
        $ro2->items()->update(['technician_id' => $tech1->id]);
        $service = app(WorkOrderService::class);
        $service->approveWorkOrder($ro2, $admin->id);
        $service->completeDelivery($ro2, $admin->id);

        $this->assertSame('0.00', $ro2->items()->where('item_type', 'service')->value('technician_payout'));

        $summary = collect(app(PayrollService::class)->technicianSummary('2026-10'))->firstWhere('technician.id', $tech1->id);
        $this->assertSame(1, $summary['redo']);

        $item = new WorkOrderItem([
            'item_type' => WorkOrderItemType::Service,
            'item_id' => Service::query()->where('code', 'BD-5K')->value('id'),
            'quantity' => 1,
            'unit_price' => 150000,
            'subtotal' => 150000,
            'technician_id' => $tech1->id,
            'is_approved' => false,
        ]);
        $this->assertSame(0.0, app(PayrollService::class)->payoutFor($item, WorkOrder::query()->where('code', 'RO-20261019-001')->firstOrFail()));
    }

    public function test_monthly_summary_counts_delivered_jobs_only(): void
    {
        $admin = $this->user('admin@gara.vn');
        $tech1 = $this->user('tech1@gara.vn');
        app(WorkOrderService::class)->completeDelivery(WorkOrder::query()->where('code', 'RO-20261019-001')->firstOrFail(), $admin->id);

        $summary = collect(app(PayrollService::class)->technicianSummary('2026-10'))->firstWhere('technician.id', $tech1->id);

        // RO-001 bảo dưỡng 150.000 + RO-DEMO-REV-001 dàn lạnh 400.000 + RO-DEMO-REV-003 bảo dưỡng 20K 350.000; RO-DUE-0001 thuộc tháng 9.
        $this->assertSame(3, $summary['jobs']);
        $this->assertSame(3, $summary['cars']);
        $this->assertSame(900000.0, $summary['labor']);
        $this->assertSame(270000.0, $summary['payout']);
        $this->assertSame(45 + 60 + 90, $summary['minutes']);
        $this->assertSame(30.0, $summary['rate']);

        $lifts = collect(app(PayrollService::class)->liftSummary('2026-10'));
        $this->assertSame(1, $lifts->firstWhere('lift', 'Cầu 1')['cars']);
        $this->assertSame(3, $lifts->firstWhere('lift', 'Chưa xếp cầu')['cars']);
    }

    public function test_payroll_page_is_owner_only_and_exports_csv(): void
    {
        $admin = $this->user('admin@gara.vn');

        $this->actingAs($admin)
            ->get(TechnicianPayroll::getUrl())
            ->assertOk()
            ->assertSee('Lương khoán thợ — tổng 225.000 đ')
            ->assertSee('Thợ Máy Trần Văn A')
            ->assertSee('Năng suất cầu nâng')
            ->assertSee('ước tính');

        Livewire::actingAs($admin)
            ->test(TechnicianPayroll::class)
            ->assertSet('month', '2026-10')
            ->call('exportCsv')
            ->assertFileDownloaded('luong-khoan-2026-10.csv');

        Livewire::actingAs($admin)
            ->test(TechnicianPayroll::class)
            ->set('month', '2026-09')
            ->assertSee('Lương khoán thợ — tổng 45.000 đ');

        $this->flushSession();
        $this->actingAs($this->user('sa@gara.vn'))->get(TechnicianPayroll::getUrl())->assertForbidden();
    }

    public function test_technician_sees_only_own_payout_on_dashboard(): void
    {
        $tech1 = $this->user('tech1@gara.vn');
        $admin = $this->user('admin@gara.vn');
        $ro2 = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $ro2->items()->update(['technician_id' => $tech1->id]);
        app(WorkOrderService::class)->approveWorkOrder($ro2, $admin->id);

        $this->actingAs($tech1)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Tiền khoán của tôi')
            ->assertSee('225.000 đ')
            ->assertSee('Sắp nhận (xe chưa giao)')
            // RO-001 bảo dưỡng 45.000 (chưa giao) + RO-002 thay má phanh 60.000.
            ->assertSee('105.000 đ')
            ->assertDontSee('Doanh thu tháng này');

        $this->flushSession();
        $this->actingAs($admin)->get('/admin')->assertOk()->assertDontSee('Tiền khoán của tôi');
        $this->assertSame(WorkOrderStatus::CustomerApproved, WorkOrder::query()->where('code', 'RO-20261019-002')->value('status'));
    }
}
