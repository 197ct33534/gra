<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\StockTransactionType;
use App\Enums\WorkOrderItemSource;
use App\Enums\WorkOrderItemType;
use App\Enums\WorkOrderStatus;
use App\Filament\Resources\WorkOrders\Pages\ListWorkOrders;
use App\Models\Invoice;
use App\Models\Part;
use App\Models\Service;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\WorkOrderService;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_approval_reserves_stock_without_deducting_it(): void
    {
        $this->seed();

        $oil = Part::query()->where('sku', 'CAST-5W30')->firstOrFail();
        $filter = Part::query()->where('sku', 'LOC-NHOT-VIOS')->firstOrFail();
        $pads = Part::query()->where('sku', 'RANGER-BRAKE-F')->firstOrFail();
        $approved = WorkOrder::query()->where('code', 'RO-20261019-001')->firstOrFail();
        $quoted = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();

        $this->assertSame(80, $oil->stock_quantity);
        $this->assertSame(4, $oil->reserved_quantity);
        $this->assertSame(76, $oil->availableQuantity());
        $this->assertSame(12, $filter->stock_quantity);
        $this->assertSame(1, $filter->reserved_quantity);
        $this->assertSame(4, $pads->stock_quantity);
        $this->assertSame(0, $pads->reserved_quantity);
        $this->assertSame(WorkOrderStatus::Completed, $approved->status);
        $this->assertFalse($approved->needs_restock);
        $this->assertNull($approved->stock_exported_at);

        $invoice = Invoice::query()->where('work_order_id', $approved->id)->firstOrFail();
        $this->assertSame(PaymentStatus::Paid, $invoice->payment_status);
        $this->assertSame(PaymentMethod::BankTransfer, $invoice->payment_method);
        $this->assertSame('1069200.00', $invoice->paid_amount);
        $this->assertMatchesRegularExpression('/^INV-\d{8}-\d{4}$/', $invoice->invoice_number);
        $this->assertSame(WorkOrderStatus::Quoted, $quoted->status);
        $this->assertSame('1069200.00', $approved->final_amount);
        $this->assertSame('1026000.00', $quoted->final_amount);
        $this->assertSame('840000.00', $approved->total_parts_amount);
        $this->assertSame('150000.00', $approved->total_labor_amount);
        $this->assertSame('120000.00', $approved->items()->where('item_id', $oil->id)->where('item_type', 'part')->value('cost_price'));

        $this->assertSame(0, StockTransaction::query()->where('reference_id', $approved->id)->count());
    }

    public function test_short_stock_still_approves_flags_restock_and_warns(): void
    {
        $this->seed();

        $pads = Part::query()->where('sku', 'RANGER-BRAKE-F')->firstOrFail();
        $pads->update(['stock_quantity' => 0]);
        $quoted = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(ListWorkOrders::class)
            ->callTableAction('approveQuote', $quoted);

        Notification::assertNotified('Đã duyệt — kho đang thiếu hàng, cần nhập thêm');

        $quoted->refresh();
        $pads->refresh();
        $this->assertSame(WorkOrderStatus::CustomerApproved, $quoted->status);
        $this->assertTrue($quoted->needs_restock);
        $this->assertSame(0, $pads->stock_quantity);
        $this->assertSame(1, $pads->reserved_quantity);
        $this->assertSame(-1, $pads->availableQuantity());
        $this->assertSame(0, StockTransaction::query()->where('part_id', $pads->id)->count());
    }

    public function test_service_returns_shortage_warnings(): void
    {
        $this->seed();

        Part::query()->where('sku', 'RANGER-BRAKE-F')->update(['stock_quantity' => 0]);
        $quoted = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();

        $warnings = app(WorkOrderService::class)->approveWorkOrder($quoted, $admin->id);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Bố thắng trước Ford Ranger', $warnings[0]);
        $this->assertStringContainsString('cần nhập thêm 1 Bộ', $warnings[0]);
    }

    public function test_delivery_deducts_reserved_stock_and_records_gross_profit(): void
    {
        $this->seed();

        $advisor = User::query()->where('email', 'sa@gara.vn')->firstOrFail();
        $order = WorkOrder::query()->where('code', 'RO-20261019-001')->firstOrFail();
        $vehicle = Vehicle::query()->where('license_plate', '51K-123.45')->firstOrFail();
        $order->update(['reception_odo' => 34999]);

        $warnings = app(WorkOrderService::class)->completeDelivery($order, $advisor->id);

        $this->assertSame([], $warnings);
        $order->refresh();
        $this->assertSame(WorkOrderStatus::Delivered, $order->status);
        $this->assertNotNull($order->stock_exported_at);
        // Doanh thu trước VAT 990.000 − giá vốn (4 × 120.000 + 60.000) = 450.000.
        $this->assertSame('450000.00', $order->gross_profit);
        $this->assertSame(35000, $vehicle->refresh()->current_odo);

        $oil = Part::query()->where('sku', 'CAST-5W30')->firstOrFail();
        $this->assertSame(76, $oil->stock_quantity);
        $this->assertSame(0, $oil->reserved_quantity);
        $this->assertSame(11, Part::query()->where('sku', 'LOC-NHOT-VIOS')->value('stock_quantity'));
        $this->assertDatabaseHas('stock_transactions', [
            'part_id' => $oil->id,
            'transaction_type' => StockTransactionType::ExportWorkOrder->value,
            'quantity' => -4,
            'reference_id' => $order->id,
        ]);

        $this->expectExceptionMessage('Lệnh đã được bàn giao.');
        app(WorkOrderService::class)->completeDelivery($order, $advisor->id);
    }

    public function test_delivery_with_missing_stock_goes_negative_and_warns(): void
    {
        $this->seed();

        $advisor = User::query()->where('email', 'sa@gara.vn')->firstOrFail();
        $pads = Part::query()->where('sku', 'RANGER-BRAKE-F')->firstOrFail();
        $pads->update(['stock_quantity' => 0]);
        $order = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $service = app(WorkOrderService::class);

        $service->approveWorkOrder($order, $advisor->id);
        $warnings = $service->completeDelivery($order, $advisor->id);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('cần lập phiếu nhập bù', $warnings[0]);
        $this->assertSame(-1, $pads->refresh()->stock_quantity);
        $this->assertSame(0, $pads->reserved_quantity);
        $this->assertFalse($order->refresh()->needs_restock);
    }

    public function test_legacy_orders_already_deducted_are_not_deducted_twice(): void
    {
        $this->seed();

        $advisor = User::query()->where('email', 'sa@gara.vn')->firstOrFail();
        $order = WorkOrder::query()->where('code', 'RO-20261019-001')->firstOrFail();
        // Mô phỏng lệnh duyệt bằng logic cũ: kho đã trừ, không có phần giữ chỗ.
        Part::query()->where('sku', 'CAST-5W30')->update(['stock_quantity' => 76, 'reserved_quantity' => 0]);
        Part::query()->where('sku', 'LOC-NHOT-VIOS')->update(['stock_quantity' => 11, 'reserved_quantity' => 0]);
        $order->update(['stock_exported_at' => now()]);

        app(WorkOrderService::class)->completeDelivery($order, $advisor->id);

        $this->assertSame(76, Part::query()->where('sku', 'CAST-5W30')->value('stock_quantity'));
        $this->assertSame(11, Part::query()->where('sku', 'LOC-NHOT-VIOS')->value('stock_quantity'));
        $this->assertSame('450000.00', $order->refresh()->gross_profit);
    }

    public function test_cancel_approval_releases_reservation(): void
    {
        $this->seed();

        $advisor = User::query()->where('email', 'sa@gara.vn')->firstOrFail();
        $order = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $service = app(WorkOrderService::class);

        $service->approveWorkOrder($order, $advisor->id);
        $this->assertSame(1, Part::query()->where('sku', 'RANGER-BRAKE-F')->value('reserved_quantity'));

        $service->cancelApproval($order, $advisor->id);

        $this->assertSame(WorkOrderStatus::Reception, $order->refresh()->status);
        $this->assertSame(0, Part::query()->where('sku', 'RANGER-BRAKE-F')->value('reserved_quantity'));
        $this->assertSame(4, Part::query()->where('sku', 'RANGER-BRAKE-F')->value('stock_quantity'));
        $this->assertSame(0, StockTransaction::query()->where('reference_id', $order->id)->count());
    }

    public function test_direct_purchase_records_import_and_export_without_touching_stock(): void
    {
        $this->seed();

        $advisor = User::query()->where('email', 'sa@gara.vn')->firstOrFail();
        $order = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $pads = Part::query()->where('sku', 'RANGER-BRAKE-F')->firstOrFail();
        $order->items()->where('item_type', 'part')->update([
            'item_source' => WorkOrderItemSource::DirectPurchase->value,
            'cost_price' => 500000,
            'supplier_info' => 'Chợ Kim Biên',
        ]);
        $service = app(WorkOrderService::class);

        $warnings = $service->approveWorkOrder($order, $advisor->id);

        $this->assertSame([], $warnings);
        $pads->refresh();
        $this->assertSame(4, $pads->stock_quantity);
        $this->assertSame(0, $pads->reserved_quantity);
        $this->assertDatabaseHas('stock_transactions', [
            'part_id' => $pads->id,
            'transaction_type' => StockTransactionType::Import->value,
            'quantity' => 1,
            'unit_price' => '500000.00',
            'reference_id' => $order->id,
            'note' => 'Mua ngoài: Chợ Kim Biên',
        ]);
        $this->assertDatabaseHas('stock_transactions', [
            'part_id' => $pads->id,
            'transaction_type' => StockTransactionType::ExportWorkOrder->value,
            'quantity' => -1,
            'reference_id' => $order->id,
        ]);

        $service->completeDelivery($order, $advisor->id);

        $this->assertSame(4, $pads->refresh()->stock_quantity);
        $this->assertSame(2, StockTransaction::query()->where('reference_id', $order->id)->count());
        // 200.000 công + 750.000 phụ tùng − 500.000 giá mua ngoài.
        $this->assertSame('450000.00', $order->refresh()->gross_profit);
    }

    public function test_customer_supplied_part_is_free_without_warranty_and_skips_stock(): void
    {
        $this->seed();

        $advisor = User::query()->where('email', 'sa@gara.vn')->firstOrFail();
        $order = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $item = $order->items()->where('item_type', 'part')->firstOrFail();
        $item->update(['item_source' => WorkOrderItemSource::CustomerSupplied]);

        $item->refresh();
        $this->assertSame('0.00', $item->unit_price);
        $this->assertSame('0.00', $item->subtotal);
        $this->assertSame(WorkOrderItemSource::CUSTOMER_SUPPLIED_WARRANTY, $item->warranty_policy);

        $service = app(WorkOrderService::class);
        $service->approveWorkOrder($order, $advisor->id);

        $order->refresh();
        $this->assertSame('0.00', $order->total_parts_amount);
        $this->assertSame('216000.00', $order->final_amount);
        $this->assertSame(0, Part::query()->where('sku', 'RANGER-BRAKE-F')->value('reserved_quantity'));

        $service->completeDelivery($order, $advisor->id);

        $this->assertSame(4, Part::query()->where('sku', 'RANGER-BRAKE-F')->value('stock_quantity'));
        $this->assertSame('200000.00', $order->refresh()->gross_profit);
    }

    public function test_subcontract_cost_and_declined_items_shape_totals_and_profit(): void
    {
        $this->seed();

        $advisor = User::query()->where('email', 'sa@gara.vn')->firstOrFail();
        $order = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $brakeJob = Service::query()->where('code', 'TH-PHANH')->firstOrFail();
        $oil = Part::query()->where('sku', 'CAST-5W30')->firstOrFail();

        WorkOrderItem::query()->create([
            'work_order_id' => $order->id,
            'item_type' => WorkOrderItemType::Service,
            'item_id' => $brakeJob->id,
            'item_source' => WorkOrderItemSource::Subcontract,
            'quantity' => 1,
            'unit_price' => 300000,
            'cost_price' => 150000,
            'supplier_info' => 'Tiện láng đĩa anh Hùng',
            'revision_no' => 2,
        ]);
        WorkOrderItem::query()->create([
            'work_order_id' => $order->id,
            'item_type' => WorkOrderItemType::Part,
            'item_id' => $oil->id,
            'quantity' => 4,
            'unit_price' => 180000,
            'revision_no' => 2,
            'is_approved' => false,
        ]);
        $service = app(WorkOrderService::class);
        $service->recalculateTotals($order);

        $this->assertSame('750000.00', $order->refresh()->total_parts_amount);
        $this->assertSame('500000.00', $order->total_labor_amount);

        $service->approveWorkOrder($order, $advisor->id);
        // 4 lít đang giữ là của RO-20261019-001; nhớt khách từ chối không giữ thêm.
        $this->assertSame(4, $oil->refresh()->reserved_quantity);

        $service->completeDelivery($order, $advisor->id);

        // 1.250.000 − (450.000 bố thắng + 150.000 gia công ngoài); nhớt khách từ chối không tính.
        $this->assertSame('650000.00', $order->refresh()->gross_profit);
        $this->assertSame(80, $oil->refresh()->stock_quantity);
        $this->assertSame(4, $oil->reserved_quantity);
    }

    public function test_work_order_pages_render_seeded_orders(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $order = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();

        $this->actingAs($admin);

        $this->get('/admin/work-orders')
            ->assertOk()
            ->assertSee('RO-20261019-001')
            ->assertSee('RO-20261019-002')
            ->assertSee('51K-123.45')
            ->assertSee('1.069.200 đ')
            ->assertSee('Cầu 3')
            ->assertSee('In Phiếu Giao Việc');

        $this->get('/admin/work-orders/'.$order->getKey())
            ->assertOk()
            ->assertSee('Duyệt báo giá')
            ->assertSee('29C-555.88');

        $this->get('/admin/work-orders/create')
            ->assertOk()
            ->assertSee('Thông tin tiếp nhận')
            ->assertSee('Báo giá & Hạng mục sửa chữa')
            ->assertSee('Giờ hẹn giao xe')
            ->assertSee('Cầu nâng');
    }
}
