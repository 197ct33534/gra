<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Events\WorkshopBoardChanged;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\WorkshopBoardService;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class WorkshopBoardRealtimeTest extends TestCase
{
    use RefreshDatabase;

    private function order(): WorkOrder
    {
        return WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
    }

    public function test_moving_a_card_signals_every_open_board(): void
    {
        $this->seed();
        Event::fake([WorkshopBoardChanged::class]);

        $order = $this->order();
        $advisor = User::query()->where('email', 'sa@gara.vn')->firstOrFail();
        app(WorkshopBoardService::class)->move($order, WorkshopBoardService::QC, $advisor->id);

        Event::assertDispatched(WorkshopBoardChanged::class, fn (WorkshopBoardChanged $event): bool => $event->orderId === $order->id);
    }

    public function test_saving_a_field_the_board_does_not_show_sends_no_signal(): void
    {
        $this->seed();
        Event::fake([WorkshopBoardChanged::class]);

        $this->order()->update(['technical_diagnosis' => 'Chỉ sửa ghi chú chẩn đoán']);

        Event::assertNotDispatched(WorkshopBoardChanged::class);
    }

    public function test_signal_goes_on_the_private_workshop_channel_without_money(): void
    {
        $this->seed();
        $order = $this->order();

        $event = new WorkshopBoardChanged($order);

        $this->assertEquals([new PrivateChannel('workshop')], $event->broadcastOn());
        $this->assertSame('board.changed', $event->broadcastAs());
        $this->assertSame(['order_id' => $order->id], $event->broadcastWith());
    }

    public function test_board_keeps_working_when_the_realtime_server_is_down(): void
    {
        $this->seed();
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => '1',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 9,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
        ]);

        $order = $this->order();
        $advisor = User::query()->where('email', 'sa@gara.vn')->firstOrFail();
        app(WorkshopBoardService::class)->move($order, WorkshopBoardService::QC, $advisor->id);

        $this->assertSame(WorkOrderStatus::Qc, $order->fresh()->status);
    }

    public function test_board_page_listens_for_the_signal(): void
    {
        $this->seed();
        $this->actingAs(User::query()->where('email', 'tech1@gara.vn')->firstOrFail());

        $this->get('/admin/workshop-board')->assertOk()->assertSee('board.changed', false);
    }

    public function test_my_jobs_page_listens_for_the_signal(): void
    {
        $this->seed();
        $this->actingAs(User::query()->where('email', 'tech1@gara.vn')->firstOrFail());

        $this->get('/admin/my-jobs')->assertOk()->assertSee('board.changed', false);
    }
}
