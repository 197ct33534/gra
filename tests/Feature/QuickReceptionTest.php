<?php

namespace Tests\Feature;

use App\Enums\WorkOrderItemType;
use App\Enums\WorkOrderStatus;
use App\Filament\Pages\QuickReception;
use App\Filament\Resources\ServicePackages\Pages\CreateServicePackage;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\Customer;
use App\Models\Service;
use App\Models\ServicePackage;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleRecommendation;
use App\Models\WorkOrder;
use App\Services\QuickReceptionService;
use App\Services\WorkOrderService;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class QuickReceptionTest extends TestCase
{
    use RefreshDatabase;

    private const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    public function test_new_car_is_received_in_one_submit_with_package_photos_and_signature(): void
    {
        $this->seed();
        Storage::fake('public');

        $advisor = $this->user('sa@gara.vn');
        $package = ServicePackage::query()->where('code', 'GOI-BD5K-VIOS')->firstOrFail();
        $customers = Customer::query()->count();

        Livewire::actingAs($advisor)
            ->test(QuickReception::class)
            ->assertSchemaStateSet(['checkin_checklist' => array_keys(WorkOrderResource::CHECKIN_ITEMS), 'fuel_level' => '1/2'])
            ->fillForm([
                'license_plate' => '30k 999.99',
                'phone' => '0912 345 678',
                'reception_odo' => 12000,
                'customer_request' => 'Bảo dưỡng định kỳ, phanh hơi kêu',
                'package_ids' => [$package->id],
                'lift_bay' => 'Cầu 2',
                'reception_photos' => [UploadedFile::fake()->image('xuoc-can-truoc.jpg')],
                'signature' => self::SIGNATURE,
            ])
            ->fillForm(['promise_preset' => 'today17'])
            ->call('receive')
            ->assertHasNoFormErrors()
            ->assertSet('lastOrderId', fn (?int $id): bool => $id !== null);

        Notification::assertNotified('Đã tiếp nhận 30K 999.99 — '.WorkOrder::query()->latest('id')->value('code'));

        $order = WorkOrder::query()->latest('id')->firstOrFail();
        $this->assertSame(WorkOrderStatus::Reception, $order->status);
        $this->assertSame($advisor->id, $order->service_advisor_id);
        $this->assertSame(12000, $order->reception_odo);
        $this->assertSame('Cầu 2', $order->lift_bay);
        $this->assertSame(array_keys(WorkOrderResource::CHECKIN_ITEMS), $order->checkin_checklist);
        $this->assertSame('17:00', $order->promised_at->timezone(config('garage.timezone'))->format('H:i'));
        $this->assertSame(3, $order->items()->count());
        $this->assertSame('1069200.00', $order->final_amount);
        $this->assertCount(1, $order->getMedia('reception_photos'));
        $this->assertCount(1, $order->getMedia('reception_signature'));

        $this->assertSame($customers + 1, Customer::query()->count());
        $customer = Customer::query()->where('phone', '0912345678')->firstOrFail();
        $this->assertSame('Khách 0912345678', $customer->name);
        $vehicle = $order->vehicle;
        $this->assertSame($customer->id, $vehicle->customer_id);
        $this->assertSame(QuickReceptionService::UNKNOWN, $vehicle->brand);
        $this->assertSame(12000, $vehicle->current_odo);
    }

    public function test_existing_car_is_found_by_plate_typed_any_way(): void
    {
        $this->seed();

        $advisor = $this->user('sa@gara.vn');
        $vios = Vehicle::query()->where('license_plate', '51K-123.45')->firstOrFail();
        $service = app(QuickReceptionService::class);

        // Xe còn lệnh RO-20261019-001 chưa bàn giao: không cho tiếp nhận trùng.
        try {
            $service->receive(['license_plate' => '51k12345', 'reception_odo' => 36000], $advisor->id);
            $this->fail('Phải chặn tiếp nhận trùng khi xe còn lệnh mở.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('RO-20261019-001 chưa bàn giao', $exception->getMessage());
        }

        app(WorkOrderService::class)->completeDelivery(WorkOrder::query()->where('code', 'RO-20261019-001')->firstOrFail(), $advisor->id);

        Livewire::actingAs($advisor)
            ->test(QuickReception::class)
            ->fillForm(['license_plate' => '51k 123 45', 'reception_odo' => 1000])
            ->assertSchemaStateSet(['license_plate' => '51K-123.45'])
            ->call('receive');
        Notification::assertNotified('Chưa tiếp nhận được');

        $vehicles = Vehicle::query()->count();
        $order = $service->receive(['license_plate' => '51K 123.45', 'reception_odo' => 41000], $advisor->id);

        $this->assertSame($vios->id, $order->vehicle_id);
        $this->assertSame($vehicles, Vehicle::query()->count());
        $this->assertSame(0, $order->items()->count());
    }

    public function test_plate_lookup_shows_history_due_maintenance_and_declined_advice(): void
    {
        $this->seed();

        $ranger = Vehicle::query()->where('license_plate', '29C-555.88')->firstOrFail();
        $order = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        VehicleRecommendation::query()->create([
            'vehicle_id' => $ranger->id,
            'work_order_id' => $order->id,
            'item_type' => WorkOrderItemType::Service,
            'item_id' => Service::query()->where('code', 'VS-DANGIA')->value('id'),
            'item_name' => 'Vệ sinh dàn lạnh nội soi',
            'decline_reason' => 'Để lần sau',
            'declined_at' => now()->subMonth(),
        ]);

        $html = QuickReception::vehicleSummary('29c55588')->toHtml();
        $this->assertStringContainsString('Ford Ranger', $html);
        $this->assertStringContainsString('Công ty TNHH Vận Tải ABC', $html);
        $this->assertStringContainsString('RO-20261019-002', $html);
        $this->assertStringContainsString('Lần trước khách chưa làm', $html);
        $this->assertStringContainsString('Vệ sinh dàn lạnh nội soi', $html);
        $this->assertStringContainsString('Để lần sau', $html);

        $this->assertStringContainsString('Xe đến hạn bảo dưỡng', QuickReception::vehicleSummary('51G24680')->toHtml());
        $this->assertStringContainsString('Xe mới', QuickReception::vehicleSummary('99A-000.01')->toHtml());
    }

    public function test_new_car_needs_a_phone_number(): void
    {
        $this->seed();

        Livewire::actingAs($this->user('sa@gara.vn'))
            ->test(QuickReception::class)
            ->fillForm(['license_plate' => '99A00001', 'reception_odo' => 500])
            ->call('receive')
            ->assertHasFormErrors(['phone' => 'required']);

        $this->assertNull(QuickReceptionService::findVehicle('99A00001'));
    }

    public function test_access_and_package_management_follow_roles(): void
    {
        $this->seed();

        $this->actingAs($this->user('sa@gara.vn'))
            ->get(QuickReception::getUrl())
            ->assertOk()
            ->assertSee('Tiếp nhận nhanh')
            ->assertSee('Bảo dưỡng 5.000 km Vios/Yaris — 990.000 đ')
            ->assertSee('Khách ký nhận xe');
        $this->get('/admin/service-packages')->assertOk()->assertSee('GOI-BD5K-VIOS');
        $this->get('/admin/service-packages/create')->assertForbidden();
        // Đổi người dùng trong cùng phiên: xóa phiên cũ để AuthenticateSession không đá ra trang đăng nhập.
        $this->flushSession();
        $this->actingAs($this->user('tech1@gara.vn'))->get(QuickReception::getUrl())->assertForbidden();

        $admin = $this->user('admin@gara.vn');
        $brakeJob = Service::query()->where('code', 'TH-PHANH')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(CreateServicePackage::class)
            ->fillForm([
                'code' => 'goi-phanh',
                'name' => 'Thay má phanh',
                'is_active' => true,
                'sort_order' => 3,
                'items' => [
                    ['item_type' => 'service', 'item_id' => $brakeJob->id, 'quantity' => 1],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $package = ServicePackage::query()->where('code', 'GOI-PHANH')->firstOrFail();
        $this->assertSame(200000.0, $package->catalogTotal());
    }
}
