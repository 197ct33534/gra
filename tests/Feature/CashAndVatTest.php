<?php

namespace Tests\Feature;

use App\Enums\CashMovementCategory;
use App\Enums\CashMovementDirection;
use App\Enums\CashSessionStatus;
use App\Enums\PaymentMethod;
use App\Enums\WorkOrderStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\CashService;
use App\Services\WorkOrderService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashAndVatTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_close_cash_session_with_petty_expense(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $cash = app(CashService::class);

        $session = $cash->openSession($admin->id, 2000000, 'Đầu ngày');
        $this->assertSame(CashSessionStatus::Open, $session->status);

        $cash->recordMovement(
            $session,
            CashMovementDirection::Out,
            50000,
            CashMovementCategory::PettyExpense,
            $admin->id,
            null,
            'Mua nước',
        );

        $this->assertEqualsWithDelta(1950000.0, $session->fresh()->computedExpectedBalance(), 0.01);

        $closed = $cash->closeSession($session, $admin->id, 1940000, 'Thiếu 10k');
        $this->assertSame(CashSessionStatus::Closed, $closed->status);
        $this->assertEqualsWithDelta(-10000.0, (float) $closed->difference, 0.01);
    }

    public function test_cannot_open_second_session(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $cash = app(CashService::class);
        $cash->openSession($admin->id, 0);

        $this->expectException(DomainException::class);
        $cash->openSession($admin->id, 0);
    }

    public function test_cash_invoice_posts_to_open_session_when_required(): void
    {
        $this->seed();
        config(['garage.cash.require_open_session_for_cash' => true]);

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $this->actingAs($admin);

        app(CashService::class)->openSession($admin->id, 100000);

        $order = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $order->update(['status' => WorkOrderStatus::CustomerApproved]);

        $invoice = app(WorkOrderService::class)->issueInvoice(
            $order,
            PaymentMethod::Cash,
            (float) $order->final_amount,
        );

        $this->assertNotNull($invoice->vat_rate);
        $this->assertNotNull($invoice->amount_before_vat);
        $this->assertNotNull($invoice->vat_amount);
        $this->assertEqualsWithDelta(
            (float) $invoice->total_amount,
            (float) $invoice->amount_before_vat + (float) $invoice->vat_amount,
            0.02,
        );

        $this->assertDatabaseHas('cash_movements', [
            'category' => 'invoice_payment',
            'reference_id' => $invoice->id,
        ]);
    }

    public function test_cash_payment_requires_open_session(): void
    {
        $this->seed();
        config(['garage.cash.require_open_session_for_cash' => true]);

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $this->actingAs($admin);

        $order = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $order->update(['status' => WorkOrderStatus::CustomerApproved]);

        $this->expectException(DomainException::class);
        app(WorkOrderService::class)->issueInvoice($order, PaymentMethod::Cash, 100000);
    }

    public function test_vat_report_page_for_admin(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $this->actingAs($admin);
        $this->get('/admin/vat-report')->assertOk();
    }

    public function test_cash_sessions_page_for_admin(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $this->actingAs($admin);
        $this->get('/admin/cash-sessions')->assertOk();
    }
}
