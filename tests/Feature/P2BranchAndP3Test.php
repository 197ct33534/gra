<?php

namespace Tests\Feature;

use App\Enums\CustomerMessageType;
use App\Enums\PaymentStatus;
use App\Enums\WorkOrderStatus;
use App\Filament\Pages\NpsReport;
use App\Filament\Resources\Branches\Pages\ListBranches;
use App\Models\Branch;
use App\Models\CustomerMessage;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\LoyaltyService;
use App\Services\MessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class P2BranchAndP3Test extends TestCase
{
    use RefreshDatabase;

    public function test_default_branch_seeded_and_assigned(): void
    {
        $this->seed();

        $branch = Branch::query()->where('is_default', true)->first();
        $this->assertNotNull($branch);
        $this->assertSame('CN-HCM', $branch->code);

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $this->assertSame($branch->id, $admin->branch_id);

        $order = WorkOrder::query()->firstOrFail();
        $this->assertSame($branch->id, $order->branch_id);
    }

    public function test_branch_pages_admin_only(): void
    {
        $this->seed();
        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $this->actingAs($admin);

        Livewire::test(ListBranches::class)->assertOk();
        $this->get('/admin/branches')->assertOk()->assertSee('Chi nhánh chính');
    }

    public function test_branch_scope_filters_when_enforced(): void
    {
        $this->seed();

        $main = Branch::query()->where('code', 'CN-HCM')->firstOrFail();
        $other = Branch::query()->create([
            'code' => 'CN-HN',
            'name' => 'Hà Nội',
            'is_active' => true,
            'is_default' => false,
        ]);

        $order = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $order->update(['branch_id' => $other->id]);

        $advisor = User::query()->where('email', 'sa@gara.vn')->firstOrFail();
        $advisor->update(['branch_id' => $main->id]);

        config(['garage.branches.enforce_scope' => true]);
        $this->actingAs($advisor);

        $visible = WorkOrder::query()->forCurrentBranch()->pluck('code');
        $this->assertFalse($visible->contains('RO-20261019-002'));
        $this->assertTrue($visible->isNotEmpty());
    }

    public function test_loyalty_redeem_on_invoice(): void
    {
        $this->seed();

        $invoice = Invoice::query()->where('payment_status', PaymentStatus::Paid)->firstOrFail();
        // Làm HĐ còn nợ và khách có điểm.
        $invoice->update([
            'paid_amount' => 0,
            'payment_status' => PaymentStatus::Unpaid,
            'loyalty_discount_amount' => 0,
            'loyalty_points_redeemed' => 0,
        ]);
        $customer = $invoice->workOrder->vehicle->customer;
        $customer->update(['loyalty_points' => 100, 'loyalty_tier' => 'member']);

        $tx = app(LoyaltyService::class)->redeemOnInvoice($invoice->fresh(['workOrder.vehicle.customer']), 50);

        $this->assertSame(-50, $tx->points);
        $invoice->refresh();
        $this->assertSame(50, (int) $invoice->loyalty_points_redeemed);
        $this->assertEqualsWithDelta(50000, (float) $invoice->loyalty_discount_amount, 0.01);
        $this->assertSame(50, (int) $customer->fresh()->loyalty_points);
        $this->assertLessThan((float) $invoice->total_amount, $invoice->outstandingAmount() + 0.01);
    }

    public function test_status_update_message_queued_when_opted_in(): void
    {
        $this->seed();

        $order = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $customer = $order->vehicle->customer;
        $customer->update([
            'messaging_opt_in' => true,
            'messaging_opt_in_at' => now(),
            'messaging_opt_in_source' => 'test',
        ]);

        $order->update(['status' => WorkOrderStatus::InProgress]);

        $msg = CustomerMessage::query()
            ->where('work_order_id', $order->id)
            ->where('type', CustomerMessageType::StatusUpdate)
            ->first();

        $this->assertNotNull($msg);
        $this->assertStringContainsString('Đang sửa', $msg->body);
        $this->assertStringContainsString('/status/', $msg->body);
    }

    public function test_nps_report_page(): void
    {
        $this->seed();
        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();

        WorkOrder::query()->where('code', 'RO-DEMO-REV-001')->update([
            'feedback_score' => 5,
            'feedback_comment' => 'Tốt',
            'feedback_at' => now(),
            'feedback_token' => 'test-fb-token',
        ]);

        $this->actingAs($admin);
        Livewire::test(NpsReport::class)->assertOk();
        $this->get(NpsReport::getUrl())->assertOk();
    }

    public function test_manual_status_notify_service(): void
    {
        $this->seed();
        $order = WorkOrder::query()->where('status', WorkOrderStatus::Delivered)->firstOrFail();
        $order->vehicle->customer->update(['messaging_opt_in' => true, 'messaging_opt_in_at' => now()]);

        $msg = app(MessagingService::class)->queueStatusUpdate($order->fresh(['vehicle.customer']));
        $this->assertNotNull($msg);
        $this->assertSame(CustomerMessageType::StatusUpdate, $msg->type);
    }
}
