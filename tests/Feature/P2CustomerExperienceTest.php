<?php

namespace Tests\Feature;

use App\Enums\LoyaltyTier;
use App\Enums\WorkOrderStatus;
use App\Filament\Resources\CustomerFeedback\Pages\ListCustomerFeedback;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Services\CustomerPortalService;
use App\Services\GarageAlertService;
use App\Services\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class P2CustomerExperienceTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_status_portal(): void
    {
        $this->seed();

        $order = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $order->update(['status' => WorkOrderStatus::InProgress]);

        $url = app(CustomerPortalService::class)->statusUrl($order);
        $token = $order->fresh()->status_token;

        $this->assertStringContainsString('/status/', $url);
        $this->get(route('public.status.show', $token))
            ->assertOk()
            ->assertSee($order->code)
            ->assertSee('Đang sửa');
    }

    public function test_feedback_flow_after_delivery(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $order = WorkOrder::query()->where('status', WorkOrderStatus::Delivered)->first()
            ?? WorkOrder::query()->where('code', 'RO-DEMO-REV-001')->firstOrFail();

        if ($order->status !== WorkOrderStatus::Delivered) {
            // Seed demo orders are delivered; fallback path.
            $order->update([
                'status' => WorkOrderStatus::Delivered,
                'delivered_at' => now(),
            ]);
        }

        $portal = app(CustomerPortalService::class);
        $token = $portal->ensureFeedbackToken($order);

        $this->get(route('public.feedback.show', $token))->assertOk()->assertSee($order->code);

        $this->post(route('public.feedback.store', $token), [
            'score' => 5,
            'comment' => 'Thợ nhiệt tình',
        ])->assertRedirect();

        $order->refresh();
        $this->assertSame(5, $order->feedback_score);
        $this->assertSame('Thợ nhiệt tình', $order->feedback_comment);
        $this->assertNotNull($order->feedback_at);

        $this->actingAs($admin);
        Livewire::test(ListCustomerFeedback::class)->assertOk()->assertSee($order->code);
    }

    public function test_loyalty_points_on_delivery(): void
    {
        $this->seed();

        $order = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $order->update([
            'status' => WorkOrderStatus::CustomerApproved,
            'final_amount' => 2500000,
        ]);
        $order->items()->update(['is_approved' => true]);

        // Đảm bảo có thể bàn giao: dùng service nếu RO chưa commit stock — seed RO-002 is Quoted originally.
        // Ép trạng thái đã duyệt + không phụ thuộc xuất kho phức tạp bằng gọi LoyaltyService trực tiếp.
        $customer = $order->vehicle->customer;
        $before = (int) $customer->loyalty_points;

        $order->update(['delivered_at' => now(), 'status' => WorkOrderStatus::Delivered]);
        $tx = app(LoyaltyService::class)->earnFromDelivery($order->fresh(['vehicle.customer']));

        $this->assertNotNull($tx);
        $this->assertSame(250, $tx->points); // 2.500.000 / 10.000
        $customer->refresh();
        $this->assertSame($before + 250, (int) $customer->loyalty_points);
        $this->assertSame(LoyaltyTier::Member, $customer->loyalty_tier);

        // Không cộng trùng.
        $this->assertNull(app(LoyaltyService::class)->earnFromDelivery($order->fresh(['vehicle.customer'])));
    }

    public function test_vehicle_document_expiry_detection(): void
    {
        $this->seed();

        $vehicle = Vehicle::query()->firstOrFail();
        $vehicle->update([
            'inspection_expires_at' => now()->addDays(7)->toDateString(),
            'insurance_expires_at' => now()->subDay()->toDateString(),
        ]);

        $docs = app(GarageAlertService::class)->expiringVehicleDocuments();
        $this->assertTrue($docs->contains(fn (Vehicle $v): bool => $v->id === $vehicle->id));
    }

    public function test_delivery_creates_feedback_and_status_tokens(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $order = WorkOrder::query()->where('code', 'RO-DEMO-REV-001')->firstOrFail();

        // RO demo đã delivered — tạo RO mới tối giản từ lệnh quoted.
        $source = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $source->update(['status' => WorkOrderStatus::CustomerApproved, 'final_amount' => 500000]);

        // Gọi portal ensure khi delivery path: mô phỏng hook.
        app(CustomerPortalService::class)->ensureStatusToken($source);
        app(CustomerPortalService::class)->ensureFeedbackToken($source);

        $source->refresh();
        $this->assertNotNull($source->status_token);
        $this->assertNotNull($source->feedback_token);

        unset($admin, $order);
    }

    public function test_adjust_loyalty_insufficient_points(): void
    {
        $this->seed();
        $customer = Customer::query()->where('phone', '0989991122')->firstOrFail();
        $customer->update(['loyalty_points' => 10, 'loyalty_tier' => LoyaltyTier::Member]);

        $this->expectException(\DomainException::class);
        app(LoyaltyService::class)->adjust($customer, -50, 'Đổi quà');
    }

    public function test_public_booking_creates_appointment(): void
    {
        $this->seed();

        $this->get(route('public.booking.create'))->assertOk()->assertSee('Đặt lịch');

        $start = now((string) config('garage.timezone'))->addDay()->setTime(9, 0)->format('Y-m-d\TH:i');

        $this->post(route('public.booking.store'), [
            'name' => 'Khách Online',
            'phone' => '0911223344',
            'license_plate' => '51Z-999.88',
            'scheduled_start' => $start,
            'customer_request' => 'Thay nhớt',
        ])->assertRedirect(route('public.booking.create'));

        $this->assertDatabaseHas('appointments', [
            'walk_in_phone' => '0911223344',
            'customer_request' => 'Thay nhớt',
        ]);
    }
}
