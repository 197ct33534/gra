<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\WorkOrderStatus;
use App\Filament\Resources\StockTransactions\Pages\ListStockTransactions;
use App\Filament\Resources\WorkOrders\Pages\ListWorkOrders;
use App\Models\Invoice;
use App\Models\Part;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\VietQrService;
use App\Services\WorkOrderPrintService;
use App\Services\WorkOrderService;
use App\Support\VietnameseMoney;
use DomainException;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class InvoiceAndStockLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_vietnamese_money_and_vietqr_url_are_ready_for_the_invoice(): void
    {
        config([
            'garage.bank_id' => 'MB',
            'garage.account_no' => '0901234567',
            'garage.account_name' => 'GARA O TO DUC PHAT',
        ]);

        $this->assertSame(
            'Một triệu không trăm sáu mươi chín nghìn hai trăm đồng',
            VietnameseMoney::spell(1069200),
        );
        $this->assertSame('Mười lăm đồng', VietnameseMoney::spell(15));
        $this->assertSame('Hai mươi mốt đồng', VietnameseMoney::spell(21));
        $this->assertSame('Không đồng', VietnameseMoney::spell(0));

        $url = app(VietQrService::class)->generateQrUrl(1069200, 'TT RO-20261019-001');

        $this->assertStringStartsWith('https://img.vietqr.io/image/MB-0901234567-compact2.png?', $url);
        $this->assertStringContainsString('amount=1069200', $url);
        $this->assertStringContainsString('addInfo=TT%20RO-20261019-001', $url);
        $this->assertStringContainsString('accountName=GARA%20O%20TO%20DUC%20PHAT', $url);
    }

    public function test_qr_image_falls_back_to_a_local_code_when_vietqr_is_down(): void
    {
        Http::fake([
            'img.vietqr.io/*' => Http::response('unavailable', 503),
        ]);

        $uri = app(VietQrService::class)->generateBase64Qr(150000, 'TT RO-1');

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $uri);
    }

    public function test_invoice_payment_sets_status_and_streams_pdfs(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $approved = WorkOrder::query()->where('code', 'RO-20261019-001')->firstOrFail();
        $quoted = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();

        $this->actingAs($admin);

        Livewire::test(ListWorkOrders::class)
            ->assertTableActionVisible('printQuotation', $approved)
            ->assertTableActionVisible('printInvoice', $approved)
            ->assertTableActionVisible('createInvoice', $approved)
            ->assertTableActionHidden('printInvoice', $quoted)
            ->assertTableActionHidden('createInvoice', $quoted);

        $quoted->update(['status' => WorkOrderStatus::CustomerApproved]);

        Livewire::test(ListWorkOrders::class)
            ->callTableAction('createInvoice', $quoted, [
                'payment_method' => PaymentMethod::Cash->value,
                'paid_amount' => 500000,
                'notes' => 'Thu một phần',
            ]);

        Notification::assertNotified('Đã tạo hóa đơn '.Invoice::query()->where('work_order_id', $quoted->id)->value('invoice_number'));

        $partial = Invoice::query()->where('work_order_id', $quoted->id)->firstOrFail();
        $this->assertSame(PaymentStatus::PartiallyPaid, $partial->payment_status);
        $this->assertSame('500000.00', $partial->paid_amount);
        $this->assertSame(WorkOrderStatus::Completed, $quoted->refresh()->status);
        $this->assertMatchesRegularExpression('/^INV-\d{8}-\d{4}$/', $partial->invoice_number);

        $reception = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $reception->update(['status' => WorkOrderStatus::Reception]);

        try {
            app(WorkOrderService::class)->issueInvoice($reception, PaymentMethod::Cash, 1000);
            $this->fail('Lệnh đang tiếp nhận không được lập hóa đơn.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('đã được khách duyệt', $exception->getMessage());
        }

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        Http::fake([
            'img.vietqr.io/*' => Http::response($png, 200, ['Content-Type' => 'image/png']),
        ]);

        $html = view('pdf.invoice', app(WorkOrderPrintService::class)->invoiceData($approved))->render();
        $this->assertStringContainsString($approved->latestInvoice->invoice_number, $html);
        $this->assertStringContainsString('Một triệu không trăm sáu mươi chín nghìn hai trăm đồng', $html);
        $this->assertStringContainsString('Nhớt động cơ Castrol 5W-30', $html);
        $this->assertStringContainsString('Bảo hành phụ tùng 06 tháng', $html);
        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('TT '.$approved->code, $html);

        $quotation = view('pdf.quotation', app(WorkOrderPrintService::class)->quotationData($approved))->render();
        $this->assertStringContainsString('Báo giá sửa chữa', $quotation);
        $this->assertStringContainsString('@page { size: A4; margin: 15mm; }', $quotation);

        $this->get(route('work-orders.quotation', $approved))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $invoicePdf = $this->get(route('work-orders.invoice', $approved))->assertOk();
        $this->assertStringStartsWith('%PDF', $invoicePdf->getContent());

        auth()->logout();
        $this->get(route('work-orders.invoice', $approved))->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_stock_ledger_is_read_only_and_filters_movements(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $oil = Part::query()->where('sku', 'CAST-5W30')->firstOrFail();
        // Kho chỉ xuất khi bàn giao xe.
        app(WorkOrderService::class)->completeDelivery(
            WorkOrder::query()->where('code', 'RO-20261019-001')->firstOrFail(),
            $admin->id,
        );
        $exports = StockTransaction::query()->where('transaction_type', 'export_work_order')->get();
        $oilExports = $exports->where('part_id', $oil->id)->values();

        $this->actingAs($admin)
            ->get('/admin/stock-transactions')
            ->assertOk()
            ->assertSee('Thẻ kho')
            ->assertSee('RO-20261019-001')
            ->assertSee('Xuất theo lệnh')
            ->assertSee('Nhớt động cơ Castrol 5W-30')
            ->assertDontSee('New thẻ kho');

        Livewire::actingAs($admin)
            ->test(ListStockTransactions::class)
            ->assertCanSeeTableRecords($exports)
            ->filterTable('transaction_type', 'import')
            ->assertCanNotSeeTableRecords($exports);

        Livewire::actingAs($admin)
            ->test(ListStockTransactions::class)
            ->filterTable('part_id', $oil->id)
            ->assertCanSeeTableRecords($oilExports)
            ->assertCanNotSeeTableRecords($exports->where('part_id', '!=', $oil->id)->values());

        Livewire::actingAs($admin)
            ->test(ListStockTransactions::class)
            ->filterTable('created_at', [
                'from' => now()->addDay()->toDateString(),
            ])
            ->assertCanNotSeeTableRecords($exports);
    }
}
