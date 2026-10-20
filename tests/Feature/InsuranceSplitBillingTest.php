<?php

namespace Tests\Feature;

use App\Enums\InvoicePayer;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\WorkOrderItemType;
use App\Enums\WorkOrderStatus;
use App\Enums\WorkOrderType;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\WorkOrders\Pages\ListWorkOrders;
use App\Filament\Widgets\GarageStatsOverviewWidget;
use App\Filament\Widgets\InsuranceReceivableWidget;
use App\Models\Invoice;
use App\Models\Part;
use App\Models\Service;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\WorkOrderPrintService;
use App\Services\WorkOrderService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class InsuranceSplitBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_insurance_order_splits_deductible_and_out_of_scope_items_to_customer(): void
    {
        $this->seed();

        $order = $this->insuranceOrder();

        $this->assertSame('6292000.00', $order->final_amount);
        $this->assertSame([
            'customer' => 1292000.0,
            'insurance' => 5000000.0,
            'deductible' => 500000.0,
            'outside_scope' => 792000.0,
        ], $order->paymentSplit());

        // Khấu trừ lớn hơn tổng tiền: khách trả hết, bảo hiểm không trả gì.
        $order->update(['insurance_deductible' => 9000000]);
        $this->assertSame(['customer' => 6292000.0, 'insurance' => 0.0], array_slice($order->paymentSplit(), 0, 2));

        $standard = WorkOrder::query()->where('code', 'RO-20261019-001')->firstOrFail();
        $this->assertSame((float) $standard->final_amount, $standard->paymentSplit()['customer']);
        $this->assertSame(0.0, $standard->paymentSplit()['insurance']);
    }

    public function test_outside_insurance_toggle_only_shows_on_insurance_orders(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $order = $this->insuranceOrder(approve: false);
        $standard = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();

        $this->actingAs($admin);

        $this->get('/admin/work-orders/'.$order->id.'/edit')
            ->assertOk()
            ->assertSee('Ngoài phạm vi bảo hiểm (khách tự trả)');

        $this->get('/admin/work-orders/'.$standard->id.'/edit')
            ->assertOk()
            ->assertDontSee('Ngoài phạm vi bảo hiểm (khách tự trả)');
    }

    public function test_two_invoices_are_issued_for_customer_and_insurer(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $order = $this->insuranceOrder();

        $this->actingAs($admin);

        Livewire::test(ListWorkOrders::class)
            ->assertTableActionVisible('createInvoice', $order)
            ->assertTableActionHidden('printInsuranceInvoice', $order)
            ->callTableAction('createInvoice', $order, [
                'payer' => InvoicePayer::Customer->value,
                'payment_method' => PaymentMethod::Cash->value,
                'paid_amount' => '1.292.000',
            ])
            ->assertHasNoTableActionErrors();

        Livewire::test(ListWorkOrders::class)
            ->callTableAction('createInvoice', $order, [
                'payer' => InvoicePayer::Insurance->value,
                'paid_amount' => 0,
                'notes' => 'Hồ sơ bồi thường BV-2026-0012',
            ])
            ->assertHasNoTableActionErrors();

        $customerInvoice = $order->invoiceFor(InvoicePayer::Customer);
        $insuranceInvoice = $order->invoiceFor(InvoicePayer::Insurance);

        $this->assertInstanceOf(Invoice::class, $customerInvoice);
        $this->assertSame('1292000.00', $customerInvoice->total_amount);
        $this->assertSame('1292000.00', $customerInvoice->paid_amount);
        $this->assertSame(PaymentMethod::Cash, $customerInvoice->payment_method);
        $this->assertSame(PaymentStatus::Paid, $customerInvoice->payment_status);
        $this->assertNull($customerInvoice->payer_name);

        $this->assertInstanceOf(Invoice::class, $insuranceInvoice);
        $this->assertSame('5000000.00', $insuranceInvoice->total_amount);
        $this->assertSame('0.00', $insuranceInvoice->paid_amount);
        $this->assertSame(PaymentMethod::Insurance, $insuranceInvoice->payment_method);
        $this->assertSame(PaymentStatus::Unpaid, $insuranceInvoice->payment_status);
        $this->assertSame('Bảo Việt', $insuranceInvoice->payer_name);
        $this->assertNotSame($customerInvoice->invoice_number, $insuranceInvoice->invoice_number);
        $this->assertSame(
            (float) $order->refresh()->final_amount,
            (float) $customerInvoice->total_amount + (float) $insuranceInvoice->total_amount,
        );
        $this->assertSame(WorkOrderStatus::Completed, $order->status);

        Livewire::test(ListWorkOrders::class)
            ->assertTableActionHidden('createInvoice', $order)
            ->assertTableActionVisible('printInvoice', $order)
            ->assertTableActionVisible('printInsuranceInvoice', $order);

        $service = app(WorkOrderService::class);

        try {
            $service->issueInvoice($order, PaymentMethod::Insurance, 0, null, InvoicePayer::Insurance);
            $this->fail('Không được lập hóa đơn bảo hiểm lần hai.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('đã có hóa đơn', $exception->getMessage());
        }

        $standard = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $standard->update(['status' => WorkOrderStatus::CustomerApproved]);

        try {
            $service->issueInvoice($standard, PaymentMethod::Cash, 0, null, InvoicePayer::Insurance);
            $this->fail('Lệnh thường không có phần bảo hiểm.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('Chỉ lệnh bảo hiểm', $exception->getMessage());
        }

        $this->assertSame(2, Invoice::query()->where('work_order_id', $order->id)->count());
    }

    public function test_invoice_pdfs_print_per_payer_with_qr_only_for_customer_share(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $order = $this->insuranceOrder();
        $service = app(WorkOrderService::class);
        $customerInvoice = $service->issueInvoice($order, PaymentMethod::BankTransfer, 0);
        $insuranceInvoice = $service->issueInvoice($order, PaymentMethod::Insurance, 0, null, InvoicePayer::Insurance);
        $printer = app(WorkOrderPrintService::class);

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        Http::fake([
            'img.vietqr.io/*' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);

        $customerHtml = view('pdf.invoice', $printer->invoiceData($order, $customerInvoice))->render();

        $this->assertStringContainsString('Hóa đơn — phần khách hàng trả', $customerHtml);
        $this->assertStringContainsString($customerInvoice->invoice_number, $customerHtml);
        $this->assertStringContainsString('1.292.000 đ', $customerHtml);
        $this->assertStringContainsString('5.000.000 đ', $customerHtml);
        $this->assertStringContainsString('ngoài phạm vi BH — khách trả', $customerHtml);
        $this->assertStringContainsString('Một triệu hai trăm chín mươi hai nghìn đồng', $customerHtml);
        $this->assertStringContainsString('alt="VietQR"', $customerHtml);
        $this->assertStringContainsString('data:image/png;base64,', $customerHtml);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'amount=1292000'));
        Http::assertSentCount(1);

        $insuranceHtml = view('pdf.invoice', $printer->invoiceData($order, $insuranceInvoice))->render();

        $this->assertStringContainsString('Hóa đơn — phần bảo hiểm chi trả', $insuranceHtml);
        $this->assertStringContainsString($insuranceInvoice->invoice_number, $insuranceHtml);
        $this->assertStringContainsString('Bảo Việt', $insuranceHtml);
        $this->assertStringContainsString('Đại diện công ty bảo hiểm', $insuranceHtml);
        $this->assertStringContainsString('Năm triệu đồng', $insuranceHtml);
        $this->assertStringNotContainsString('alt="VietQR"', $insuranceHtml);
        $this->assertStringNotContainsString('data:image/png;base64,', $insuranceHtml);
        Http::assertSentCount(1);

        // Đường dẫn cũ theo lệnh in hóa đơn của khách.
        $this->assertSame($customerInvoice->id, $printer->invoiceData($order)['invoice']->id);

        $this->actingAs($admin);

        foreach ([$customerInvoice, $insuranceInvoice] as $invoice) {
            $pdf = $this->get(route('invoices.pdf', $invoice))
                ->assertOk()
                ->assertHeader('content-type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', $pdf->getContent());
        }

        auth()->logout();
        $this->get(route('invoices.pdf', $insuranceInvoice))->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_insurance_receivables_are_tracked_separately_until_collected(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $order = $this->insuranceOrder();
        $service = app(WorkOrderService::class);
        $customerInvoice = $service->issueInvoice($order, PaymentMethod::Cash, 1000000);
        $insuranceInvoice = $service->issueInvoice($order, PaymentMethod::Insurance, 0, null, InvoicePayer::Insurance);
        $seededInvoices = Invoice::query()->where('work_order_id', '!=', $order->id)->get();

        $this->actingAs($admin);

        Livewire::test(InsuranceReceivableWidget::class)
            ->assertSee('Công nợ bảo hiểm')
            ->assertSee('5.000.000 đ')
            ->assertSee('1 hóa đơn chưa thu đủ')
            ->assertSee('Bảo Việt');

        // Công nợ khách lẻ chỉ còn 292.000 đ, không lẫn tiền bảo hiểm.
        Livewire::test(GarageStatsOverviewWidget::class)
            ->assertSee('292.000 đ')
            ->assertDontSee('5.292.000 đ');

        $this->get('/admin/invoices')
            ->assertOk()
            ->assertSee('Hóa đơn & công nợ')
            ->assertSee('Bảo hiểm còn nợ')
            ->assertSee($insuranceInvoice->invoice_number);

        Livewire::test(ListInvoices::class)
            ->filterTable('payer_type', InvoicePayer::Insurance->value)
            ->assertCanSeeTableRecords([$insuranceInvoice])
            ->assertCanNotSeeTableRecords([$customerInvoice, ...$seededInvoices]);

        Livewire::test(ListInvoices::class)
            ->filterTable('outstanding', true)
            ->assertCanSeeTableRecords([$insuranceInvoice, $customerInvoice])
            ->assertCanNotSeeTableRecords($seededInvoices);

        Livewire::test(ListInvoices::class)
            ->filterTable('payer_name', 'Bảo Việt')
            ->assertCanSeeTableRecords([$insuranceInvoice])
            ->assertCanNotSeeTableRecords([$customerInvoice]);

        Livewire::test(ListWorkOrders::class)
            ->filterTable('insurance_receivable', true)
            ->assertCanSeeTableRecords([$order])
            ->assertCanNotSeeTableRecords(WorkOrder::query()->whereKeyNot($order->id)->get());

        Livewire::test(ListInvoices::class)
            ->callTableAction('recordPayment', $insuranceInvoice, [
                'amount' => '3.000.000',
                'note' => 'Bảo Việt chuyển khoản đợt 1',
            ])
            ->assertHasNoTableActionErrors();

        $insuranceInvoice->refresh();
        $this->assertSame('3000000.00', $insuranceInvoice->paid_amount);
        $this->assertSame(PaymentStatus::PartiallyPaid, $insuranceInvoice->payment_status);
        $this->assertSame(2000000.0, $insuranceInvoice->outstandingAmount());
        $this->assertStringContainsString('Bảo Việt chuyển khoản đợt 1', (string) $insuranceInvoice->notes);

        Livewire::test(InsuranceReceivableWidget::class)->assertSee('2.000.000 đ');

        try {
            $service->recordPayment($insuranceInvoice, 2500000);
            $this->fail('Không được thu vượt công nợ.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('vượt quá công nợ', $exception->getMessage());
        }

        $service->recordPayment($insuranceInvoice, 2000000);

        $this->assertSame(PaymentStatus::Paid, $insuranceInvoice->refresh()->payment_status);

        Livewire::test(ListInvoices::class)
            ->assertTableActionHidden('recordPayment', $insuranceInvoice)
            ->assertTableActionVisible('recordPayment', $customerInvoice);

        Livewire::test(ListWorkOrders::class)
            ->filterTable('insurance_receivable', true)
            ->assertCanNotSeeTableRecords([$order]);

        Livewire::test(InsuranceReceivableWidget::class)
            ->assertSee('0 hóa đơn chưa thu đủ')
            ->assertSee('Không còn công nợ');
    }

    /**
     * Xe CX-5 vào xưởng đồng sơn theo hồ sơ Bảo Việt, khấu trừ 500.000 đ, VAT 10%:
     * công thay má phanh 2.000.000 + má phanh 3.000.000 (bảo hiểm duyệt),
     * 4 lít nhớt 720.000 khách làm thêm (ngoài phạm vi bảo hiểm).
     */
    private function insuranceOrder(bool $approve = true): WorkOrder
    {
        $advisor = User::query()->where('email', 'sa@gara.vn')->firstOrFail();
        $vehicle = Vehicle::query()->where('license_plate', '30H-987.65')->firstOrFail();
        $service = app(WorkOrderService::class);

        $order = WorkOrder::query()->create([
            'code' => 'RO-20261020-0901',
            'vehicle_id' => $vehicle->id,
            'order_type' => WorkOrderType::Insurance,
            'insurance_company' => 'Bảo Việt',
            'insurance_deductible' => 500000,
            'service_advisor_id' => $advisor->id,
            'reception_odo' => $vehicle->current_odo,
            'customer_request' => 'Va quẹt cản trước, kiểm tra phanh',
            'status' => WorkOrderStatus::Reception,
            'discount_amount' => 0,
            'vat_rate' => 10,
        ]);

        WorkOrderItem::query()->create([
            'work_order_id' => $order->id,
            'item_type' => WorkOrderItemType::Service,
            'item_id' => Service::query()->where('code', 'TH-PHANH')->value('id'),
            'quantity' => 1,
            'unit_price' => 2000000,
        ]);
        WorkOrderItem::query()->create([
            'work_order_id' => $order->id,
            'item_type' => WorkOrderItemType::Part,
            'item_id' => Part::query()->where('sku', 'RANGER-BRAKE-F')->value('id'),
            'quantity' => 1,
            'unit_price' => 3000000,
        ]);
        WorkOrderItem::query()->create([
            'work_order_id' => $order->id,
            'item_type' => WorkOrderItemType::Part,
            'item_id' => Part::query()->where('sku', 'CAST-5W30')->value('id'),
            'quantity' => 4,
            'unit_price' => 180000,
            'is_outside_insurance' => true,
        ]);

        $service->recalculateTotals($order);

        if ($approve) {
            $service->approveWorkOrder($order, $advisor->id);
        }

        return $order->refresh();
    }
}
