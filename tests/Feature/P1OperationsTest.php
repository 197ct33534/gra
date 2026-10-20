<?php

namespace Tests\Feature;

use App\Enums\CustomerType;
use App\Enums\PaymentStatus;
use App\Enums\WorkOrderStatus;
use App\Models\Customer;
use App\Models\FleetContract;
use App\Models\Invoice;
use App\Models\PartCategory;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\CreditNoteService;
use App\Services\FleetService;
use App\Services\QuoteApprovalService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class P1OperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_part_categories_page(): void
    {
        $this->seed();
        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $this->actingAs($admin);
        $this->get('/admin/part-categories')->assertOk()->assertSee('Dầu nhớt');
        $this->assertGreaterThan(0, PartCategory::query()->count());
    }

    public function test_fleet_credit_limit_blocks_unpaid_invoice(): void
    {
        $this->seed();

        $company = Customer::query()->where('phone', '0989991122')->firstOrFail();
        $company->update(['credit_limit' => 100000, 'customer_type' => CustomerType::Company]);

        FleetContract::query()->create([
            'customer_id' => $company->id,
            'code' => 'FL-TEST-001',
            'name' => 'HĐ vận tải ABC',
            'starts_at' => now()->subMonth(),
            'ends_at' => now()->addYear(),
            'discount_percent' => 5,
            'credit_limit' => 100000,
            'payment_terms_days' => 30,
            'is_active' => true,
        ]);

        $this->expectException(DomainException::class);
        app(FleetService::class)->assertWithinCreditLimit($company->fresh(), 500000);
    }

    public function test_remote_quote_approval_flow(): void
    {
        $this->seed();

        $order = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $order->update(['status' => WorkOrderStatus::Quoted]);

        $quotes = app(QuoteApprovalService::class);
        $url = $quotes->publicUrl($order);
        $token = $order->fresh()->quote_approval_token;

        $this->assertStringContainsString('/quote/', $url);
        $this->get(route('public.quote.show', $token))->assertOk()->assertSee($order->code);

        $this->post(route('public.quote.approve', $token))->assertRedirect();
        $order->refresh();
        $this->assertSame(WorkOrderStatus::CustomerApproved, $order->status);
        $this->assertSame('remote_link', $order->quote_approved_via);
        $this->assertNotNull($order->quote_approved_at);
    }

    public function test_remote_quote_reject(): void
    {
        $this->seed();

        $order = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $order->update(['status' => WorkOrderStatus::Quoted]);
        $token = app(QuoteApprovalService::class)->ensureToken($order);

        $this->post(route('public.quote.reject', $token), ['reason' => 'Đắt quá'])
            ->assertRedirect();

        $order->refresh();
        $this->assertSame(WorkOrderStatus::Diagnosing, $order->status);
        $this->assertSame('Đắt quá', $order->quote_reject_reason);
    }

    public function test_credit_note_reduces_paid_amount(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $invoice = Invoice::query()->where('payment_status', PaymentStatus::Paid)->firstOrFail();
        $beforePaid = (float) $invoice->paid_amount;

        $note = app(CreditNoteService::class)->issue(
            $invoice,
            min(50000, $beforePaid),
            'Khách khiếu nại',
            $admin->id,
            false,
        );

        $invoice->refresh();
        $this->assertStringStartsWith('CN-', $note->code);
        $this->assertEqualsWithDelta($beforePaid - (float) $note->amount, (float) $invoice->paid_amount, 0.01);
    }

    public function test_fleet_and_credit_note_pages(): void
    {
        $this->seed();
        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $this->actingAs($admin);

        $this->get('/admin/fleet-contracts')->assertOk();
        $this->get('/admin/credit-notes')->assertOk();
    }
}
