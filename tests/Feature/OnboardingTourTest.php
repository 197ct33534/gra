<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Robot "Cờ Lê": mỗi trang chính có tour riêng, tự chạy lần đầu cho Giám đốc / Cố vấn,
 * mở lại được từ menu dấu hỏi; menu đó còn dẫn tới trang Cẩm nang.
 */
class OnboardingTourTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    public function test_owner_dashboard_tour_runs_once_then_only_on_demand(): void
    {
        $admin = $this->user('admin@gara.vn');
        $this->actingAs($admin);

        // Lời Robot nằm trong <script> dạng JSON (tiếng Việt bị mã hóa \u), nên chỉ so các đoạn không dấu.
        $this->get('/admin')
            ->assertOk()
            ->assertSee('data-tour="help-button"', false)
            ->assertSee('data-tour="workshop-count"', false)
            ->assertSee("const TOUR = 'dashboard';", false)
            ->assertSee('const AUTOSTART = true;', false)
            ->assertSee('anh Hai!', false)
            ->assertSee('Doanh thu 7 ng', false)
            ->assertSee('?tour=1', false); // nút "Mở thử hồ sơ xe" mở hồ sơ xe thật kèm tour của trang đó

        $this->postJson('/onboarding-tour/seen', ['tour' => 'dashboard'])->assertNoContent();
        $this->assertTrue($admin->fresh()->hasSeenTour('dashboard'));
        $this->assertFalse($admin->fresh()->hasSeenTour('work-order'));

        $this->get('/admin')->assertOk()->assertSee('const AUTOSTART = false;', false);
        $this->get('/admin?tour=1')->assertOk()->assertSee('const AUTOSTART = true;', false);
    }

    public function test_advisor_gets_own_tours_on_reception_work_order_and_vehicle_pages(): void
    {
        $this->actingAs($this->user('sa@gara.vn'));

        $this->get('/admin')
            ->assertOk()
            ->assertSee('anh em!', false)
            ->assertDontSee('Doanh thu 7 ng', false);

        $this->get('/admin/quick-reception')
            ->assertOk()
            ->assertSee("const TOUR = 'quick-reception';", false)
            ->assertSee('const AUTOSTART = true;', false);

        $order = WorkOrder::query()->firstOrFail();
        $this->get('/admin/work-orders/'.$order->id.'/edit')
            ->assertOk()
            ->assertSee("const TOUR = 'work-order';", false);
        $this->get('/admin/work-orders/'.$order->id)
            ->assertOk()
            ->assertSee("const TOUR = 'work-order';", false);

        $this->get('/admin/vehicles/'.Vehicle::query()->firstOrFail()->id)
            ->assertOk()
            ->assertSee("const TOUR = 'vehicle';", false);
    }

    public function test_help_menu_offers_page_tour_or_overview_and_the_manual(): void
    {
        $this->actingAs($this->user('sa@gara.vn'));

        $this->get('/admin/quick-reception')
            ->assertOk()
            ->assertSee('Robot hướng dẫn trang này')
            ->assertSee('Cẩm nang sử dụng')
            ->assertSee('/admin/cam-nang', false);

        // Trang không có tour riêng: menu đưa về tour tổng quan trên bảng điều khiển.
        $this->get('/admin/customers')
            ->assertOk()
            ->assertSee('Robot hướng dẫn tổng quan')
            ->assertSee('admin?tour=1', false)
            ->assertDontSee('const AUTOSTART', false);

        $this->get('/admin/cam-nang')
            ->assertOk()
            ->assertSee('Bảng tra cứu nhanh 5 tình huống khó')
            ->assertSee('Khách mang')
            ->assertSee('<table>', false);
    }

    public function test_unknown_tour_key_is_rejected(): void
    {
        $this->actingAs($this->user('admin@gara.vn'));

        $this->postJson('/onboarding-tour/seen', ['tour' => 'khong-co'])->assertUnprocessable();
    }

    public function test_technician_has_no_office_tour_or_manual(): void
    {
        $this->actingAs($this->user('tech1@gara.vn'));

        $this->get('/admin')
            ->assertOk()
            ->assertDontSee('data-tour="help-button"', false)
            ->assertDontSee('window.garageTour', false);
        $this->get('/admin/cam-nang')->assertForbidden();
    }

    public function test_guest_cannot_mark_the_tour(): void
    {
        $this->post('/onboarding-tour/seen', ['tour' => 'dashboard'])->assertRedirect();
    }
}
