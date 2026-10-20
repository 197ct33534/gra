<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus;
use App\Enums\StockTransactionType;
use App\Enums\SupplierBillStatus;
use App\Enums\SupplierPaymentMethod;
use App\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Models\Part;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\User;
use App\Models\WorkOrderItem;
use App\Services\PurchasingService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PurchasingFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_catalog_lists_seeded_vendors(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $this->actingAs($admin);

        $this->get('/admin/suppliers')
            ->assertOk()
            ->assertSee('NCC-CASTROL')
            ->assertSee('Đại lý nhớt Castrol miền Nam');

        Livewire::test(ListSuppliers::class)
            ->assertCanSeeTableRecords([
                Supplier::query()->where('code', 'NCC-CASTROL')->firstOrFail(),
            ]);
    }

    public function test_purchase_order_receive_updates_stock_cost_and_ap_bill(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'NCC-CASTROL')->firstOrFail();
        $oil = Part::query()->where('sku', 'CAST-5W30')->firstOrFail();
        $beforeQty = (int) $oil->stock_quantity;
        $beforeCost = (float) $oil->cost_price;

        $service = app(PurchasingService::class);

        $order = PurchaseOrder::query()->create([
            'code' => $service->nextPurchaseOrderCode(),
            'supplier_id' => $supplier->id,
            'status' => PurchaseOrderStatus::Draft,
            'created_by' => $admin->id,
            'subtotal' => 0,
        ]);

        $item = PurchaseOrderItem::query()->create([
            'purchase_order_id' => $order->id,
            'part_id' => $oil->id,
            'quantity_ordered' => 10,
            'quantity_received' => 0,
            'unit_cost' => 100000,
        ]);

        $order->recalculateSubtotal();
        $service->markOrdered($order->fresh());

        $receipt = $service->receiveFromPurchaseOrder($order->fresh(['items', 'supplier']), $admin->id);

        $oil->refresh();
        $order->refresh();
        $item->refresh();

        $this->assertSame(PurchaseOrderStatus::Received, $order->status);
        $this->assertSame(10.0, (float) $item->quantity_received);
        $this->assertSame($beforeQty + 10, (int) $oil->stock_quantity);

        $expectedCost = round((($beforeQty * $beforeCost) + (10 * 100000)) / ($beforeQty + 10), 2);
        $this->assertEqualsWithDelta($expectedCost, (float) $oil->cost_price, 0.01);

        $this->assertDatabaseHas('stock_transactions', [
            'part_id' => $oil->id,
            'transaction_type' => StockTransactionType::Import->value,
            'quantity' => 10,
            'reference_id' => $receipt->id,
        ]);

        $bill = SupplierBill::query()->where('goods_receipt_id', $receipt->id)->firstOrFail();
        $this->assertSame(SupplierBillStatus::Unpaid, $bill->status);
        $this->assertEqualsWithDelta(1080000.0, (float) $bill->total_amount, 0.01); // 1.000.000 + 8% VAT
        $this->assertNotNull($bill->due_date);

        $service->recordSupplierPayment($bill, 1080000, SupplierPaymentMethod::BankTransfer, $admin->id, 'CK-TEST');
        $bill->refresh();
        $this->assertSame(SupplierBillStatus::Paid, $bill->status);
        $this->assertSame(0.0, $bill->outstandingAmount());
    }

    public function test_cannot_over_receive_purchase_order(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'NCC-KIMBIEN')->firstOrFail();
        $filter = Part::query()->where('sku', 'LOC-NHOT-VIOS')->firstOrFail();
        $service = app(PurchasingService::class);

        $order = PurchaseOrder::query()->create([
            'code' => $service->nextPurchaseOrderCode(),
            'supplier_id' => $supplier->id,
            'status' => PurchaseOrderStatus::Ordered,
            'ordered_at' => now(),
            'created_by' => $admin->id,
            'subtotal' => 120000,
        ]);

        $item = PurchaseOrderItem::query()->create([
            'purchase_order_id' => $order->id,
            'part_id' => $filter->id,
            'quantity_ordered' => 2,
            'quantity_received' => 0,
            'unit_cost' => 60000,
        ]);

        $this->expectException(DomainException::class);

        $service->receiveGoods($supplier, [[
            'part_id' => $filter->id,
            'quantity' => 3,
            'unit_cost' => 60000,
            'purchase_order_item_id' => $item->id,
        ]], $admin->id, $order);
    }

    public function test_walk_in_goods_receipt_without_po(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $supplier = Supplier::query()->where('code', 'NCC-KIMBIEN')->firstOrFail();
        $pads = Part::query()->where('sku', 'RANGER-BRAKE-F')->firstOrFail();
        $before = (int) $pads->stock_quantity;

        $receipt = app(PurchasingService::class)->receiveGoods($supplier, [[
            'part_id' => $pads->id,
            'quantity' => 2,
            'unit_cost' => 400000,
        ]], $admin->id);

        $pads->refresh();
        $this->assertSame($before + 2, (int) $pads->stock_quantity);
        $this->assertNull($receipt->purchase_order_id);
        $this->assertTrue($receipt->bill()->exists());
    }

    public function test_work_order_item_can_link_supplier(): void
    {
        $this->seed();

        $supplier = Supplier::query()->where('code', 'NCC-KIMBIEN')->firstOrFail();
        $item = WorkOrderItem::query()->firstOrFail();
        $item->update(['supplier_id' => $supplier->id]);

        $this->assertTrue($item->fresh()->supplier->is($supplier));
    }

    public function test_technician_cannot_open_supplier_pages(): void
    {
        $this->seed();

        $tech = User::query()->where('email', 'tech1@gara.vn')->firstOrFail();
        $this->actingAs($tech);

        $this->get('/admin/suppliers')->assertForbidden();
        $this->get('/admin/purchase-orders')->assertForbidden();
        $this->get('/admin/goods-receipts')->assertForbidden();
        $this->get('/admin/supplier-bills')->assertForbidden();
    }
}
