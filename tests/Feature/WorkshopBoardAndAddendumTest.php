<?php

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\WorkOrderItemType;
use App\Enums\WorkOrderStatus;
use App\Filament\Pages\WorkshopBoard;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\Part;
use App\Models\Service;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleRecommendation;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\WorkOrderPrintService;
use App\Services\WorkOrderService;
use App\Services\WorkshopBoardService;
use DomainException;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkshopBoardAndAddendumTest extends TestCase
{
    use RefreshDatabase;

    public function test_board_groups_seeded_orders_into_workshop_columns(): void
    {
        $this->seed();

        $board = app(WorkshopBoardService::class)->board();

        $this->assertSame(['RO-SHOP-0001'], $board[WorkshopBoardService::DIAGNOSING]->pluck('code')->all());
        $this->assertSame(['RO-20261019-002'], $board[WorkshopBoardService::QUOTED]->pluck('code')->all());
        $this->assertSame(['RO-20261019-001'], $board[WorkshopBoardService::QC]->pluck('code')->all());
        $this->assertTrue($board[WorkshopBoardService::IN_PROGRESS]->isEmpty());
        $this->assertNotContains('RO-DEMO-REV-001', collect($board)->flatten()->pluck('code')->all());
    }

    public function test_dragging_cards_updates_status_and_stock_in_the_database(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $order = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $pads = Part::query()->where('sku', 'RANGER-BRAKE-F')->firstOrFail();

        $page = Livewire::actingAs($admin)->test(WorkshopBoard::class);

        // Chờ khách duyệt → Đang sửa chữa: khách đồng ý, giữ kho.
        $page->call('moveOrder', $order->id, WorkshopBoardService::IN_PROGRESS);
        $order->refresh();
        $this->assertSame(WorkOrderStatus::InProgress, $order->status);
        $this->assertFalse($order->needs_restock);
        $this->assertSame(1, $pads->refresh()->reserved_quantity);
        $this->assertDatabaseHas('work_orders', ['id' => $order->id, 'status' => 'in_progress', 'approved_revision' => 1]);

        $page->call('moveOrder', $order->id, WorkshopBoardService::WAITING_PARTS);
        $this->assertTrue($order->refresh()->needs_restock);
        $this->assertSame(WorkshopBoardService::WAITING_PARTS, WorkshopBoardService::columnFor($order));

        $page->call('moveOrder', $order->id, WorkshopBoardService::QC);
        $order->refresh();
        $this->assertSame(WorkOrderStatus::Qc, $order->status);
        $this->assertFalse($order->needs_restock);

        // Kéo ngược về báo giá: hủy duyệt và nhả kho.
        $page->call('moveOrder', $order->id, WorkshopBoardService::QUOTED);
        $this->assertSame(WorkOrderStatus::Quoted, $order->refresh()->status);
        $this->assertSame(0, $pads->refresh()->reserved_quantity);
        $this->assertSame(0, $order->approved_revision);

        Notification::assertNotified('RO-20261019-002 → Chờ khách duyệt');
    }

    public function test_board_refuses_to_reopen_invoiced_orders_and_flags_short_stock(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $invoiced = WorkOrder::query()->where('code', 'RO-20261019-001')->firstOrFail();
        $quoted = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        Part::query()->where('sku', 'RANGER-BRAKE-F')->update(['stock_quantity' => 0]);

        $page = Livewire::actingAs($admin)->test(WorkshopBoard::class);

        $page->call('moveOrder', $invoiced->id, WorkshopBoardService::QUOTED);
        Notification::assertNotified('Không chuyển được RO-20261019-001');
        $this->assertSame(WorkOrderStatus::Completed, $invoiced->refresh()->status);

        $page->call('moveOrder', $quoted->id, WorkshopBoardService::IN_PROGRESS);
        Notification::assertNotified('RO-20261019-002: đã duyệt nhưng kho thiếu hàng');
        $this->assertTrue($quoted->refresh()->needs_restock);
        $this->assertSame(WorkshopBoardService::WAITING_PARTS, WorkshopBoardService::columnFor($quoted));
    }

    public function test_board_page_renders_cards_for_the_workshop(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        WorkOrder::query()->where('code', 'RO-20261019-002')->update([
            'promised_at' => now()->subHour(),
            'status' => WorkOrderStatus::InProgress,
            'needs_restock' => true,
        ]);

        $this->actingAs($admin)
            ->get(WorkshopBoard::getUrl())
            ->assertOk()
            ->assertSee('Bảng điều phối xe')
            ->assertSee('Chờ chẩn đoán')
            ->assertSee('Chờ khách duyệt')
            ->assertSee('Đang sửa chữa')
            ->assertSee('Chờ phụ tùng / Cần mua ngoài')
            ->assertSee('KCS &amp; Chờ giao xe', false)
            ->assertSee('29C-555.88')
            ->assertSee('Cầu 3')
            ->assertSee('Trễ hẹn')
            ->assertSee('Chờ đồ')
            ->assertSee('1.026.000 đ')
            ->assertSee('2 hạng mục');

        $order = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(WorkshopBoard::class)
            ->mountAction('viewOrder', ['order' => $order->id])
            ->assertActionMounted('viewOrder');

        $detail = view('filament.pages.partials.workshop-order-detail', [
            'order' => $order->load(['vehicle.customer', 'serviceAdvisor']),
            'lines' => $order->jobLines(),
        ])->render();
        $this->assertStringContainsString('Đạp phanh có tiếng kêu', $detail);
        $this->assertStringContainsString('Công: Thay má phanh trước/sau', $detail);
    }

    public function test_declined_items_cost_nothing_and_reserve_nothing(): void
    {
        $this->seed();

        $advisor = User::query()->where('email', 'sa@gara.vn')->firstOrFail();
        $order = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $pads = Part::query()->where('sku', 'RANGER-BRAKE-F')->firstOrFail();
        $item = $order->items()->where('item_type', 'part')->firstOrFail();
        $item->update(['is_approved' => false, 'decline_reason' => 'Để tháng sau']);
        $service = app(WorkOrderService::class);

        $service->recalculateTotals($order);

        $item->refresh();
        $this->assertSame('0.00', $item->subtotal);
        $this->assertSame('750000.00', $item->unit_price);
        $this->assertNotNull($item->declined_at);
        $this->assertSame('0.00', $order->refresh()->total_parts_amount);
        $this->assertSame('216000.00', $order->final_amount);

        $service->approveWorkOrder($order, $advisor->id);

        $this->assertSame(0, $pads->refresh()->reserved_quantity);
        $this->assertStringNotContainsString('Bố thắng', view('pdf.job_card', app(WorkOrderPrintService::class)->jobCardData($order->refresh()))->render());
    }

    public function test_addendum_flow_reserves_on_confirmation_and_records_declined_advice(): void
    {
        $this->seed();

        $advisor = User::query()->where('email', 'sa@gara.vn')->firstOrFail();
        $order = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $oil = Part::query()->where('sku', 'CAST-5W30')->firstOrFail();
        $cabin = Service::query()->where('code', 'VS-DANGIA')->firstOrFail();
        $service = app(WorkOrderService::class);

        $service->approveWorkOrder($order, $advisor->id);
        $this->assertSame(2, $order->refresh()->nextAddendumRevision());

        WorkOrderItem::query()->create([
            'work_order_id' => $order->id,
            'item_type' => WorkOrderItemType::Part,
            'item_id' => $oil->id,
            'quantity' => 4,
            'unit_price' => 180000,
            'revision_no' => 2,
            'finding_note' => 'Tháo máy thấy nhớt đen đặc, có mạt kim loại',
        ]);
        WorkOrderItem::query()->create([
            'work_order_id' => $order->id,
            'item_type' => WorkOrderItemType::Service,
            'item_id' => $cabin->id,
            'quantity' => 1,
            'unit_price' => 400000,
            'revision_no' => 2,
            'finding_note' => 'Dàn lạnh bám bẩn nặng',
            'is_approved' => false,
            'decline_reason' => 'Khách hẹn lần sau',
        ]);
        $service->recalculateTotals($order);

        // 200.000 công + 750.000 bố thắng + 720.000 nhớt phát sinh; vệ sinh dàn lạnh bị từ chối không tính.
        $this->assertSame('1803600.00', $order->refresh()->final_amount);
        $this->assertSame(2, $order->pendingAddendumRevision());
        $this->assertSame(2, $order->nextAddendumRevision());
        $this->assertSame(4, $oil->refresh()->reserved_quantity);

        try {
            $service->issueInvoice($order, PaymentMethod::Cash, 0);
            $this->fail('Còn phát sinh chưa chốt thì không được lập hóa đơn.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('phát sinh đợt 1 chưa chốt', $exception->getMessage());
        }

        $service->approveAddendum($order, $advisor->id);

        $order->refresh();
        $this->assertSame(2, $order->approved_revision);
        $this->assertNull($order->pendingAddendumRevision());
        $this->assertSame(3, $order->nextAddendumRevision());
        $this->assertSame(8, $oil->refresh()->reserved_quantity);

        $service->completeDelivery($order, $advisor->id);

        $this->assertSame(76, $oil->refresh()->stock_quantity);
        $this->assertSame(4, $oil->reserved_quantity);
        $recommendation = VehicleRecommendation::query()->sole();
        $this->assertSame('Vệ sinh dàn lạnh nội soi', $recommendation->item_name);
        $this->assertSame('Khách hẹn lần sau', $recommendation->decline_reason);
        $this->assertSame(2, $recommendation->revision_no);
        $this->assertNull($recommendation->resolved_at);

        $vehicle = Vehicle::query()->where('license_plate', '29C-555.88')->firstOrFail();
        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();

        $this->actingAs($admin)
            ->get('/admin/vehicles/'.$vehicle->getKey())
            ->assertOk()
            ->assertSee('Các hạng mục khách đã từ chối ở những lần trước')
            ->assertSee('Vệ sinh dàn lạnh')
            ->assertSee('Khách hẹn lần sau')
            ->assertSee('Chưa làm')
            ->assertSee('[Phát sinh đợt 1] Phụ tùng: Nhớt động cơ Castrol 5W-30 x4', false)
            ->assertSee('(khách từ chối)');
    }

    public function test_recommendation_is_resolved_when_customer_does_it_next_visit(): void
    {
        $this->seed();

        $advisor = User::query()->where('email', 'sa@gara.vn')->firstOrFail();
        $order = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $order->items()->where('item_type', 'service')->update(['is_approved' => false]);
        $service = app(WorkOrderService::class);
        $service->approveWorkOrder($order, $advisor->id);
        $service->completeDelivery($order, $advisor->id);
        $this->assertSame(1, VehicleRecommendation::query()->open()->count());

        $next = WorkOrder::query()->create([
            'code' => 'RO-NEXT-0001',
            'vehicle_id' => $order->vehicle_id,
            'service_advisor_id' => $advisor->id,
            'reception_odo' => $order->reception_odo + 3000,
            'status' => WorkOrderStatus::Quoted,
            'vat_rate' => 8,
        ]);
        $brakeJob = Service::query()->where('code', 'TH-PHANH')->firstOrFail();
        WorkOrderItem::query()->create([
            'work_order_id' => $next->id,
            'item_type' => WorkOrderItemType::Service,
            'item_id' => $brakeJob->id,
            'quantity' => 1,
            'unit_price' => 200000,
        ]);
        $service->recalculateTotals($next);
        $service->approveWorkOrder($next, $advisor->id);
        $service->completeDelivery($next, $advisor->id);

        $recommendation = VehicleRecommendation::query()->sole();
        $this->assertNotNull($recommendation->resolved_at);
        $this->assertSame($next->id, $recommendation->resolved_work_order_id);
    }

    public function test_quotation_groups_revisions_and_prints_declined_waiver(): void
    {
        $this->seed();

        $advisor = User::query()->where('email', 'sa@gara.vn')->firstOrFail();
        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $order = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $oil = Part::query()->where('sku', 'CAST-5W30')->firstOrFail();
        $cabin = Service::query()->where('code', 'VS-DANGIA')->firstOrFail();
        app(WorkOrderService::class)->approveWorkOrder($order, $advisor->id);
        WorkOrderItem::query()->create([
            'work_order_id' => $order->id,
            'item_type' => WorkOrderItemType::Part,
            'item_id' => $oil->id,
            'quantity' => 4,
            'unit_price' => 180000,
            'revision_no' => 2,
            'finding_note' => 'Tháo máy thấy nhớt đen đặc',
        ]);
        WorkOrderItem::query()->create([
            'work_order_id' => $order->id,
            'item_type' => WorkOrderItemType::Service,
            'item_id' => $cabin->id,
            'quantity' => 1,
            'unit_price' => 400000,
            'revision_no' => 2,
            'is_approved' => false,
        ]);

        $data = app(WorkOrderPrintService::class)->quotationData($order->refresh());

        $this->assertCount(2, $data['sections']);
        $this->assertSame('Phần 1: Báo giá ban đầu', $data['sections'][0]['title']);
        $this->assertSame('Phần 2: Báo giá phát sinh đợt 1', $data['sections'][1]['title']);
        $this->assertSame(['Tháo máy thấy nhớt đen đặc'], $data['sections'][1]['findings']);
        $this->assertSame('720.000 đ', $data['sections'][1]['total']);
        $this->assertCount(1, $data['declined']);
        $this->assertSame('400.000 đ', $data['declined'][0]['amount']);
        $this->assertSame('Khách bảo lưu, chưa làm', $data['declined'][0]['reason']);

        $html = view('pdf.quotation', $data)->render();
        $this->assertStringContainsString('Thời gian phát sinh', $html);
        $this->assertStringContainsString('Hạng mục khuyến nghị chưa thực hiện (Khách hàng bảo lưu/từ chối)', $html);
        $this->assertStringContainsString('Khách hàng ký xác nhận từ chối', $html);
        $this->assertStringContainsString('miễn trừ trách nhiệm', $html);

        $this->actingAs($admin)
            ->get(route('work-orders.quotation', $order))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_quote_form_marks_addendum_and_declined_lines(): void
    {
        $this->seed();

        $advisor = User::query()->where('email', 'sa@gara.vn')->firstOrFail();
        $order = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $padsItem = $order->items()->where('item_type', 'part')->firstOrFail();

        $addendum = WorkOrderResource::itemLabel([
            'revision_no' => 2,
            'item_type' => 'part',
            'item_id' => $padsItem->item_id,
            'is_approved' => true,
            'subtotal' => 750000,
        ])->toHtml();
        $declined = WorkOrderResource::itemLabel([
            'revision_no' => 3,
            'item_type' => 'part',
            'item_id' => $padsItem->item_id,
            'is_approved' => false,
            'subtotal' => 0,
        ])->toHtml();

        $this->assertStringContainsString('[Phát sinh đợt 1]', $addendum);
        $this->assertStringContainsString('#7c3aed', $addendum);
        $this->assertStringContainsString('750.000 đ', $addendum);
        $this->assertStringContainsString('[Phát sinh đợt 2]', $declined);
        $this->assertStringContainsString('<s', $declined);
        $this->assertStringContainsString('KHÁCH TỪ CHỐI', $declined);

        $this->assertFalse(WorkOrderResource::isLockedLine($padsItem));
        app(WorkOrderService::class)->approveWorkOrder($order, $advisor->id);
        $this->assertTrue(WorkOrderResource::isLockedLine($padsItem->refresh()));

        $pending = WorkOrderItem::query()->create([
            'work_order_id' => $order->id,
            'item_type' => WorkOrderItemType::Part,
            'item_id' => $padsItem->item_id,
            'quantity' => 1,
            'unit_price' => 750000,
            'revision_no' => 2,
        ]);
        $this->assertFalse(WorkOrderResource::isLockedLine($pending));

        $this->actingAs(User::query()->where('email', 'admin@gara.vn')->firstOrFail())
            ->get(WorkOrderResource::getUrl('edit', ['record' => $order]))
            ->assertOk()
            ->assertSee('Thêm hạng mục phát sinh')
            ->assertSee('Chốt phát sinh đợt 1')
            ->assertSee('[Phát sinh đợt 1]');
    }
}
