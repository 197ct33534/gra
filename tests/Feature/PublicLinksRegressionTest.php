<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Models\Appointment;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Services\QuoteApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Lỗi phát hiện khi chạy thử bản local: link khách và đặt lịch online.
 */
class PublicLinksRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_approve_a_new_quote_after_rejecting_the_previous_one(): void
    {
        $this->seed();

        $order = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $quotes = app(QuoteApprovalService::class);
        $token = $quotes->ensureToken($order);

        $this->post(route('public.quote.reject', $token), ['reason' => 'Giá cao quá'])->assertRedirect();
        $this->assertSame(WorkOrderStatus::Diagnosing, $order->refresh()->status);

        // Cố vấn báo giá lại (giảm giá) và gửi lại đúng link cũ.
        $order->update(['status' => WorkOrderStatus::Quoted]);
        $this->assertNull($order->refresh()->quote_rejected_at);

        $this->post(route('public.quote.approve', $token))->assertRedirect(route('public.quote.show', $token));
        $this->assertSame(WorkOrderStatus::CustomerApproved, $order->refresh()->status);
        $this->assertSame('remote_link', $order->quote_approved_via);
    }

    public function test_online_booking_recognises_existing_car_and_customer_typed_any_way(): void
    {
        $this->seed();

        $vios = Vehicle::query()->where('license_plate', '51K-123.45')->with('customer')->firstOrFail();
        $phone = $vios->customer->phone;

        $this->post(route('public.booking.store'), [
            'name' => 'Khách cũ',
            'phone' => '+84'.substr($phone, 1),
            'license_plate' => '51k 123.45',
            'scheduled_start' => now('Asia/Ho_Chi_Minh')->addDays(2)->setTime(9, 0)->format('Y-m-d H:i'),
            'customer_request' => 'Bảo dưỡng',
        ])->assertRedirect(route('public.booking.create'))->assertSessionHasNoErrors();

        $appointment = Appointment::query()->latest('id')->firstOrFail();
        $this->assertSame($vios->customer_id, $appointment->customer_id);
        $this->assertSame($vios->id, $appointment->vehicle_id);
        $this->assertNull($appointment->walk_in_phone);
        $this->assertNull($appointment->walk_in_plate);

        // Khách mới đặt lịch: biển số lưu dạng gõ liền; tìm trong trang Lịch hẹn gõ kiểu gì cũng ra.
        $this->post(route('public.booking.store'), [
            'name' => 'Khách mới',
            'phone' => '0933 222 111',
            'license_plate' => '30A-111.22',
            'scheduled_start' => now('Asia/Ho_Chi_Minh')->addDays(3)->setTime(10, 0)->format('Y-m-d H:i'),
        ])->assertSessionHasNoErrors();
        $walkIn = Appointment::query()->latest('id')->firstOrFail();

        Livewire::actingAs(User::query()->where('email', 'sa@gara.vn')->firstOrFail())
            ->test(ListAppointments::class)
            ->searchTable('30a 111.22')
            ->assertCanSeeTableRecords([$walkIn])
            ->assertCanNotSeeTableRecords([$appointment])
            ->searchTable('51k12345')
            ->assertCanSeeTableRecords([$appointment])
            ->assertCanNotSeeTableRecords([$walkIn]);
    }
}
