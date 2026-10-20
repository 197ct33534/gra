<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\CustomerMessageType;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\AppointmentService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppointmentFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_appointment_and_block_overlapping_bay(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $vehicle = Vehicle::query()->where('license_plate', '51K-123.45')->firstOrFail();
        $service = app(AppointmentService::class);

        $start = now()->addDay()->setTime(9, 0);
        $first = $service->create([
            'vehicle_id' => $vehicle->id,
            'customer_id' => $vehicle->customer_id,
            'scheduled_start' => $start,
            'lift_bay' => 'Cầu 1',
            'customer_request' => 'Bảo dưỡng định kỳ',
        ], $admin->id);

        $this->assertSame(AppointmentStatus::Scheduled, $first->status);
        $this->assertStringStartsWith('LH-', $first->code);

        $this->expectException(DomainException::class);
        $service->create([
            'walk_in_name' => 'Anh Test',
            'walk_in_phone' => '0912345678',
            'walk_in_plate' => '51A-111.11',
            'scheduled_start' => $start->copy()->addMinutes(30),
            'lift_bay' => 'Cầu 1',
        ], $admin->id);
    }

    public function test_check_in_creates_work_order(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $vehicle = Vehicle::query()->where('license_plate', '30H-987.65')->firstOrFail();
        $service = app(AppointmentService::class);

        // Đóng lệnh đang mở trên CX-5 nếu có (seed showcase).
        $vehicle->workOrders()->where('status', '!=', 'delivered')->update(['status' => 'delivered', 'delivered_at' => now()]);

        $appointment = $service->create([
            'vehicle_id' => $vehicle->id,
            'customer_id' => $vehicle->customer_id,
            'scheduled_start' => now()->addHours(2),
            'lift_bay' => 'Cầu 2',
            'customer_request' => 'Điều hòa yếu',
        ], $admin->id);

        $order = $service->checkIn($appointment, $admin->id, (int) $vehicle->current_odo);
        $appointment->refresh();

        $this->assertSame(AppointmentStatus::CheckedIn, $appointment->status);
        $this->assertSame($order->id, $appointment->work_order_id);
        $this->assertSame('reception', $order->status->value);
    }

    public function test_queue_appointment_reminder(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $vehicle = Vehicle::query()->where('license_plate', '51K-123.45')->firstOrFail();
        $service = app(AppointmentService::class);

        $appointment = $service->create([
            'vehicle_id' => $vehicle->id,
            'customer_id' => $vehicle->customer_id,
            'scheduled_start' => now()->addHours(6),
            'lift_bay' => 'Cầu 3',
        ], $admin->id);

        $message = $service->queueReminder($appointment);
        $appointment->refresh();

        $this->assertSame(CustomerMessageType::AppointmentReminder, $message->type);
        $this->assertNotNull($appointment->reminder_sent_at);
        $this->assertDatabaseHas('customer_messages', [
            'id' => $message->id,
            'type' => CustomerMessageType::AppointmentReminder->value,
        ]);
    }

    public function test_office_can_open_appointments_page(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $this->actingAs($admin);
        $this->get('/admin/appointments')->assertOk();
    }

    public function test_technician_cannot_open_appointments(): void
    {
        $this->seed();

        $tech = User::query()->where('email', 'tech1@gara.vn')->firstOrFail();
        $this->actingAs($tech);
        $this->get('/admin/appointments')->assertForbidden();
    }
}
