<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Filament\Resources\Vehicles\Pages\ListVehicles;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardAndVehicleHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_and_vehicle_history_use_seeded_demo_data(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $vios = Vehicle::query()->where('license_plate', '51K-123.45')->firstOrFail();
        $due = Vehicle::query()->where('license_plate', '51G-246.80')->firstOrFail();
        $monthRevenue = (float) Invoice::query()
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('paid_amount');

        $this->assertTrue($due->isMaintenanceDue());
        $this->assertSame(27000, $due->next_service_odo);
        $this->assertFalse($vios->isMaintenanceDue());
        $this->assertSame(40000, $vios->next_service_odo);
        $this->assertGreaterThan(1_000_000, $monthRevenue);
        $this->assertSame(3, Invoice::query()->where('invoice_number', 'like', 'INV-DEMO-%')->count());
        $this->assertTrue(
            Invoice::query()
                ->where('invoice_number', 'like', 'INV-DEMO-%')
                ->where('payment_status', PaymentStatus::Paid)
                ->where('created_at', '>=', now()->subDays(7))
                ->count() === 3,
        );

        $this->actingAs($admin);

        $this->get('/admin')
            ->assertOk()
            ->assertSee('Doanh thu tháng này')
            ->assertSee(number_format($monthRevenue, 0, ',', '.').' đ')
            ->assertSee('Xe đang tại xưởng')
            ->assertSee('Phụ tùng sắp hết')
            ->assertSee('Cần nhập thêm hàng')
            ->assertSee('Công nợ khách chưa trả')
            ->assertSee('Doanh thu 7 ngày gần nhất')
            ->assertSee('432000')
            ->assertSee('1069200')
            ->assertSee('Tiến độ xe đang sửa chữa tại xưởng')
            ->assertSee('30H-987.65')
            ->assertSee('29C-555.88')
            ->assertSee('Cảnh báo phụ tùng chạm ngưỡng tồn kho')
            ->assertSee('LOC-GIO-CX5');

        $this->get('/admin/vehicles/'.$vios->getKey())
            ->assertOk()
            ->assertSee('Tổng số lần vào gara')
            ->assertSee('Chi tiêu trọn đời')
            ->assertSee('Lần bảo dưỡng gần nhất')
            ->assertSee('Bệnh án sửa chữa')
            ->assertSee('RO-20261019-001')
            ->assertSee('Công: Bảo dưỡng cấp 5.000 km')
            ->assertSee('In lại Hóa đơn')
            ->assertSee('Xem phiếu sửa chữa');

        $this->get('/admin/vehicles')
            ->assertOk()
            ->assertSee('Đến hạn bảo dưỡng')
            ->assertSee('Bình thường')
            ->assertSee('51G-246.80');

        Livewire::actingAs($admin)
            ->test(ListVehicles::class)
            ->filterTable('maintenance_due', true)
            ->assertCanSeeTableRecords([$due])
            ->assertCanNotSeeTableRecords([$vios]);

        $this->assertSame(
            1,
            WorkOrder::query()->where('code', 'RO-SHOP-0001')->count(),
        );
    }
}
