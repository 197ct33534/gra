<?php

namespace Tests\Feature;

use App\Enums\EInvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\WarrantyCardStatus;
use App\Enums\WorkOrderStatus;
use App\Enums\WorkOrderType;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WarrantyCard;
use App\Models\WorkOrder;
use App\Services\EInvoiceService;
use App\Services\WarrantyService;
use App\Services\WorkOrderService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarrantyEInvoiceMessagingTest extends TestCase
{
    use RefreshDatabase;

    public function test_delivery_issues_warranty_cards(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $order = WorkOrder::query()->where('code', 'RO-20261019-001')->firstOrFail();

        // Seed order đã approved + có invoice; đưa về trạng thái có thể bàn giao.
        $order->update([
            'status' => WorkOrderStatus::CustomerApproved,
            'stock_exported_at' => null,
            'delivered_at' => null,
        ]);

        app(WorkOrderService::class)->completeDelivery($order, $admin->id);

        $cards = WarrantyCard::query()->where('work_order_id', $order->id)->get();
        $this->assertNotEmpty($cards);
        $this->assertTrue($cards->every(fn (WarrantyCard $c): bool => $c->status === WarrantyCardStatus::Active));
        $this->assertStringStartsWith('BH-', $cards->first()->code);
    }

    public function test_warranty_claim_creates_redo_order(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $vehicle = Vehicle::query()->where('license_plate', '51K-123.45')->firstOrFail();
        // Đóng lệnh mở trên Vios nếu còn.
        $vehicle->workOrders()->where('status', '!=', 'delivered')->update([
            'status' => 'delivered',
            'delivered_at' => now(),
        ]);

        $order = WorkOrder::query()->where('code', 'RO-DEMO-REV-003')->firstOrFail();
        app(WarrantyService::class)->issueCardsForDelivery($order->fresh(['items', 'vehicle.customer']));

        $card = WarrantyCard::query()->where('work_order_id', $order->id)->firstOrFail();
        $claimOrder = app(WarrantyService::class)->openClaim($card, $admin->id, 'Hư lại sau 1 tuần', (int) $vehicle->current_odo);

        $this->assertSame(WorkOrderType::WarrantyRedo, $claimOrder->order_type);
        $this->assertSame($card->id, $claimOrder->warranty_card_id);
        $this->assertSame(0.0, (float) $claimOrder->final_amount);
        $this->assertSame(WarrantyCardStatus::Claimed, $card->fresh()->status);
    }

    public function test_einvoice_issue_and_cancel_with_log_driver(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $this->actingAs($admin);

        $invoice = Invoice::query()->where('payment_status', PaymentStatus::Paid)->firstOrFail();
        $service = app(EInvoiceService::class);

        $issued = $service->issue($invoice);
        $this->assertSame(EInvoiceStatus::Issued, $issued->einvoice_status);
        $this->assertNotNull($issued->einvoice_number);
        $this->assertNotNull($issued->einvoice_provider_id);

        $canceled = $service->cancel($issued, 'Sai MST khách', $admin->id);
        $this->assertSame(EInvoiceStatus::Canceled, $canceled->einvoice_status);

        $message = $service->queueLookupLink($issued->fresh());
        // Đã hủy thì không gửi link.
        $this->assertNull($message);
    }

    public function test_einvoice_requires_paid_unless_override(): void
    {
        $this->seed();

        $invoice = Invoice::query()->create([
            'invoice_number' => 'INV-UNPAID-TEST',
            'work_order_id' => WorkOrder::query()->firstOrFail()->id,
            'total_amount' => 100000,
            'paid_amount' => 0,
            'payment_method' => PaymentMethod::Cash,
            'payment_status' => PaymentStatus::Unpaid,
            'vat_rate' => 8,
            'amount_before_vat' => 92593,
            'vat_amount' => 7407,
        ]);

        $this->expectException(DomainException::class);
        app(EInvoiceService::class)->issue($invoice);
    }

    public function test_warranty_and_messages_pages(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $this->actingAs($admin);

        $this->get('/admin/warranty-cards')->assertOk();
        $this->get('/admin/customer-messages')->assertOk();
    }

    public function test_print_warranty_pdf(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $order = WorkOrder::query()->where('code', 'RO-DEMO-REV-001')->firstOrFail();
        app(WarrantyService::class)->issueCardsForDelivery($order->fresh(['items', 'vehicle.customer']));
        $card = WarrantyCard::query()->where('work_order_id', $order->id)->firstOrFail();

        $this->actingAs($admin);
        $this->get(route('warranty-cards.pdf', $card))->assertOk();
    }
}
