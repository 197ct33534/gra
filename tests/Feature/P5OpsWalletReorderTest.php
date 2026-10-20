<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\StockTransferStatus;
use App\Enums\StockTransactionType;
use App\Filament\Pages\FinancialReports;
use App\Filament\Pages\ReorderSuggestions;
use App\Filament\Pages\StockTransfers;
use App\Filament\Pages\TechnicianMyJobs;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerWalletTransaction;
use App\Models\Invoice;
use App\Models\Part;
use App\Models\PurchaseOrder;
use App\Models\StockTransaction;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AccountingExportService;
use App\Services\ReorderService;
use App\Services\StockTransferService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class P5OpsWalletReorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_reorder_suggestions_and_draft_po(): void
    {
        $this->seed();

        $part = Part::query()->where('sku', 'LOC-GIO-CX5')->firstOrFail();
        $this->assertLessThanOrEqual((int) $part->min_stock_alert, $part->availableQuantity());

        $suggestions = app(ReorderService::class)->suggestions();
        $this->assertTrue($suggestions->contains(fn (array $row): bool => $row['part']->id === $part->id));

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $supplier = Supplier::query()->firstOrFail();

        $po = app(ReorderService::class)->createDraftPurchaseOrder(
            $supplier,
            [['part_id' => $part->id, 'quantity' => 5]],
            $admin->id,
        );

        $this->assertInstanceOf(PurchaseOrder::class, $po);
        $this->assertSame(1, $po->items()->count());
        $this->assertStringStartsWith('PO-', $po->code);

        $this->actingAs($admin);
        Livewire::test(ReorderSuggestions::class)->assertOk();
    }

    public function test_technician_my_jobs_page(): void
    {
        $this->seed();

        $tech = User::query()->where('email', 'tech1@gara.vn')->firstOrFail();
        $this->actingAs($tech);

        Livewire::test(TechnicianMyJobs::class)
            ->assertOk()
            ->assertSee('RO-');
    }

    public function test_accounting_monthly_csv_export(): void
    {
        $this->seed();

        $month = now((string) config('garage.timezone'))->format('Y-m');
        $response = app(AccountingExportService::class)->monthlySalesCsv($month);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $this->actingAs($admin);
        Livewire::test(FinancialReports::class)
            ->assertOk()
            ->call('exportSalesCsv')
            ->assertFileDownloaded('so-ban-hang-'.$month.'.csv');
    }

    public function test_wallet_deposit_and_apply_to_invoice(): void
    {
        $this->seed();

        $invoice = Invoice::query()->where('payment_status', PaymentStatus::Paid)->firstOrFail();
        $invoice->update([
            'paid_amount' => 0,
            'payment_status' => PaymentStatus::Unpaid,
            'loyalty_discount_amount' => 0,
            'loyalty_points_redeemed' => 0,
        ]);

        $customer = $invoice->workOrder->vehicle->customer;
        $customer->update(['wallet_balance' => 0]);

        $wallet = app(WalletService::class);
        $deposit = $wallet->deposit($customer->fresh(), 500000, 'Nạp test', null);
        $this->assertSame(500000.0, (float) $deposit->balance_after);
        $this->assertEqualsWithDelta(500000, (float) $customer->fresh()->wallet_balance, 0.01);

        $applyAmount = min(200000, $invoice->fresh()->outstandingAmount());
        $tx = $wallet->applyToInvoice($invoice->fresh(['workOrder.vehicle.customer']), $applyAmount);

        $this->assertLessThan(0, (float) $tx->amount);
        $this->assertEqualsWithDelta(500000 - $applyAmount, (float) $customer->fresh()->wallet_balance, 0.01);
        $this->assertEqualsWithDelta($applyAmount, (float) $invoice->fresh()->paid_amount, 0.01);
        $this->assertSame(1, CustomerWalletTransaction::query()->where('invoice_id', $invoice->id)->count());
    }

    public function test_stock_transfer_between_branches(): void
    {
        $this->seed();

        $from = Branch::query()->where('code', 'CN-HCM')->firstOrFail();
        $to = Branch::query()->create([
            'code' => 'CN-DN',
            'name' => 'Đà Nẵng',
            'is_active' => true,
            'is_default' => false,
        ]);

        $part = Part::query()->where('sku', 'LOC-GIO-CX5')->firstOrFail();
        $part->update(['branch_id' => $from->id, 'stock_quantity' => 10, 'reserved_quantity' => 0]);
        $before = (int) $part->stock_quantity;

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $service = app(StockTransferService::class);

        $transfer = $service->createDraft(
            $from,
            $to,
            [['part_id' => $part->id, 'quantity' => 3]],
            $admin->id,
            'Chuyển thử',
        );
        $this->assertSame(StockTransferStatus::Draft, $transfer->status);

        $done = $service->complete($transfer, $admin->id);
        $this->assertSame(StockTransferStatus::Completed, $done->status);
        $this->assertSame($before - 3, (int) $part->fresh()->stock_quantity);

        $dest = Part::query()->where('branch_id', $to->id)->where('sku', 'LOC-GIO-CX5')->first();
        $this->assertNotNull($dest);
        $this->assertSame(3, (int) $dest->stock_quantity);

        $this->assertTrue(
            StockTransaction::query()
                ->where('transaction_type', StockTransactionType::TransferOut)
                ->where('part_id', $part->id)
                ->exists()
        );
        $this->assertTrue(
            StockTransaction::query()
                ->where('transaction_type', StockTransactionType::TransferIn)
                ->where('part_id', $dest->id)
                ->exists()
        );

        $this->actingAs($admin);
        Livewire::test(StockTransfers::class)->assertOk();
    }
}
