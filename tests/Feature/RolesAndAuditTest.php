<?php

namespace Tests\Feature;

use App\Enums\ActivityEvent;
use App\Enums\StockTransactionType;
use App\Enums\UserRole;
use App\Enums\WorkOrderStatus;
use App\Filament\Pages\WorkshopBoard;
use App\Filament\Resources\Parts\Pages\EditPart;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\WorkOrders\Pages\EditWorkOrder;
use App\Models\ActivityLog;
use App\Models\Part;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\WorkOrderService;
use App\Services\WorkshopBoardService;
use App\Support\VietnameseMoney;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class RolesAndAuditTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    public function test_technician_sees_the_job_but_no_money_and_no_office_pages(): void
    {
        $this->seed();

        $tech = $this->user('tech1@gara.vn');
        $order = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $this->actingAs($tech);

        $this->get('/admin/workshop-board')->assertOk()->assertSee('29C-555.88')->assertDontSee('1.026.000 đ');
        $this->get('/admin/work-orders')->assertOk()->assertSee('RO-20261019-002')->assertDontSee('1.069.200 đ');
        $this->get('/admin/work-orders/'.$order->id)
            ->assertOk()
            ->assertSee('In Phiếu Giao Việc')
            ->assertDontSee('Duyệt báo giá')
            ->assertDontSee('In Báo giá')
            ->assertDontSee('Đơn giá bán');
        $this->get('/admin/vehicles')->assertOk();
        $this->get('/admin')->assertOk()->assertDontSee('Doanh thu tháng này')->assertDontSee('Lãi gộp đã bàn giao');

        foreach (['/admin/work-orders/'.$order->id.'/edit', '/admin/work-orders/create', '/admin/customers', '/admin/parts', '/admin/invoices', '/admin/stock-transactions', '/admin/users', '/admin/activity-logs'] as $url) {
            $this->get($url)->assertForbidden();
        }
    }

    public function test_technician_moves_cards_only_inside_the_workshop(): void
    {
        $this->seed();

        $tech = $this->user('tech1@gara.vn');
        $advisor = $this->user('sa@gara.vn');
        $order = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $page = Livewire::actingAs($tech)->test(WorkshopBoard::class);

        $page->call('moveOrder', $order->id, WorkshopBoardService::IN_PROGRESS);
        Notification::assertNotified('Không chuyển được RO-20261019-002');
        $this->assertSame(WorkOrderStatus::Quoted, $order->refresh()->status);

        app(WorkOrderService::class)->approveWorkOrder($order, $advisor->id);
        $page->call('moveOrder', $order->id, WorkshopBoardService::QC);
        $this->assertSame(WorkOrderStatus::Qc, $order->refresh()->status);

        $page->call('moveOrder', $order->id, WorkshopBoardService::QUOTED);
        $this->assertSame(WorkOrderStatus::Qc, $order->refresh()->status);
    }

    public function test_advisor_cannot_see_costs_or_manage_staff_and_logs(): void
    {
        $this->seed();

        $advisor = $this->user('sa@gara.vn');
        $part = Part::query()->where('sku', 'RANGER-BRAKE-F')->firstOrFail();
        $this->actingAs($advisor);

        $this->get('/admin/parts/'.$part->id.'/edit')
            ->assertOk()
            ->assertSee('Giá bán')
            ->assertDontSee('Giá vốn')
            ->assertDontSee('Lý do điều chỉnh tồn');
        $this->get('/admin/parts/create')->assertOk()->assertSee('Giá vốn');
        $this->get('/admin')->assertOk()->assertSee('Doanh thu tháng này')->assertDontSee('Lãi gộp đã bàn giao');

        foreach (['/admin/users', '/admin/activity-logs', '/admin/services/1/edit'] as $url) {
            $this->get($url)->assertForbidden();
        }

        Livewire::actingAs($advisor)
            ->test(EditPart::class, ['record' => $part->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(4, $part->refresh()->stock_quantity);
        $this->assertSame('450000.00', $part->cost_price);
    }

    public function test_advisor_price_and_discount_limits_and_every_change_is_logged(): void
    {
        $this->seed();

        $advisor = $this->user('sa@gara.vn');
        $order = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $padsItem = $order->items()->where('item_type', 'part')->firstOrFail();
        $key = 'record-'.$padsItem->id;

        // 600.000 thấp hơn bảng giá 750.000 tới 20% > 10%: bị chặn.
        Livewire::actingAs($advisor)
            ->test(EditWorkOrder::class, ['record' => $order->getRouteKey()])
            ->set("data.items.{$key}.unit_price", 600000)
            ->call('save')
            ->assertHasFormErrors(["items.{$key}.unit_price"]);

        Livewire::actingAs($advisor)
            ->test(EditWorkOrder::class, ['record' => $order->getRouteKey()])
            ->set('data.discount_amount', 300000)
            ->call('save')
            ->assertHasFormErrors(['discount_amount']);

        // 700.000 (thấp hơn 6,7%) trong hạn mức: lưu được nhưng để lại dấu vết.
        Livewire::actingAs($advisor)
            ->test(EditWorkOrder::class, ['record' => $order->getRouteKey()])
            ->set("data.items.{$key}.unit_price", 700000)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('700000.00', $padsItem->refresh()->unit_price);
        $changed = ActivityLog::query()->where('event', ActivityEvent::PriceChanged)->sole();
        $this->assertSame($advisor->id, $changed->user_id);
        $this->assertSame('RO-20261019-002', $changed->subject_label);
        $this->assertStringContainsString('750.000 đ → 700.000 đ', $changed->description);
        $below = ActivityLog::query()->where('event', ActivityEvent::BelowCatalog)->sole();
        $this->assertSame(6.7, $below->properties['percent']);

        // Chủ gara không bị giới hạn.
        Livewire::actingAs($this->user('admin@gara.vn'))
            ->test(EditWorkOrder::class, ['record' => $order->getRouteKey()])
            ->set("data.items.{$key}.unit_price", 500000)
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('500000.00', $padsItem->refresh()->unit_price);

        $padsItem->delete();
        $this->assertSame(1, ActivityLog::query()->where('event', ActivityEvent::ItemDeleted)->count());
    }

    public function test_activity_log_page_is_owner_only(): void
    {
        $this->seed();

        $advisor = $this->user('sa@gara.vn');
        $order = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $this->actingAs($advisor);
        $order->update(['discount_amount' => 50000]);

        $this->actingAs($this->user('admin@gara.vn'))
            ->get('/admin/activity-logs')
            ->assertOk()
            ->assertSee('Nhật ký thao tác')
            ->assertSee('Đổi giảm giá')
            ->assertSee('Giảm giá toàn đơn: 0 đ → 50.000 đ')
            ->assertSee('Nguyễn Văn Cố Vấn');
    }

    public function test_manual_stock_edit_goes_through_the_stock_card(): void
    {
        $this->seed();

        $admin = $this->user('admin@gara.vn');
        $part = Part::query()->where('sku', 'RANGER-BRAKE-F')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(EditPart::class, ['record' => $part->getRouteKey()])
            ->set('data.stock_quantity', 2)
            ->set('data.adjustment_reason', 'Kiểm kê cuối tháng thiếu 2 bộ')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(2, $part->refresh()->stock_quantity);
        $this->assertDatabaseHas('stock_transactions', [
            'part_id' => $part->id,
            'transaction_type' => StockTransactionType::StocktakeAdjustment->value,
            'quantity' => -2,
            'note' => 'Kiểm kê cuối tháng thiếu 2 bộ',
            'created_by' => $admin->id,
        ]);
        $log = ActivityLog::query()->where('event', ActivityEvent::StockAdjusted)->sole();
        $this->assertStringContainsString('4 → 2', $log->description);
    }

    public function test_owner_creates_staff_accounts_with_roles(): void
    {
        $this->seed();

        $admin = $this->user('admin@gara.vn');

        Livewire::actingAs($admin)
            ->test(CreateUser::class)
            ->fillForm([
                'name' => 'Thợ Gầm Phạm Văn C',
                'email' => 'tech3@gara.vn',
                'role' => UserRole::Technician->value,
                'password' => 'matkhau-moi-123',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = $this->user('tech3@gara.vn');
        $this->assertSame(UserRole::Technician, $created->role);
        $this->assertTrue(Hash::check('matkhau-moi-123', $created->password));
        $this->assertFalse($admin->can('delete', $admin));
        $this->assertFalse($admin->can('delete', $this->user('sa@gara.vn')));
        $this->assertTrue($admin->can('delete', $created));
    }

    public function test_owner_dashboard_shows_profit_and_leakage_signals(): void
    {
        $this->seed();

        $admin = $this->user('admin@gara.vn');
        $order = WorkOrder::query()->where('code', 'RO-20261019-001')->firstOrFail();
        app(WorkOrderService::class)->completeDelivery($order, $admin->id);

        $this->assertNotNull($order->refresh()->delivered_at);
        $timezone = (string) config('garage.timezone');
        $profit = (float) WorkOrder::query()
            ->where('status', WorkOrderStatus::Delivered)
            ->whereBetween('delivered_at', [now($timezone)->startOfMonth()->utc(), now($timezone)->endOfMonth()->utc()])
            ->sum('gross_profit');

        $this->actingAs($admin)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Lãi gộp &amp; dấu hiệu thất thoát (tháng này)', false)
            ->assertSee('Lãi gộp đã bàn giao')
            ->assertSee(VietnameseMoney::format($profit))
            ->assertSee('Bán dưới bảng giá')
            ->assertSee('Chỉnh tồn kho tay');
    }
}
