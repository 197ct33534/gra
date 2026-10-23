<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Robot "Cờ Lê" tự dẫn đường lần đầu đăng nhập cho Giám đốc xưởng / Cố vấn, và mở lại được bằng nút Trợ giúp.
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

    public function test_owner_gets_the_robot_once_then_only_on_demand(): void
    {
        $admin = $this->user('admin@gara.vn');
        $this->actingAs($admin);

        $this->get('/admin')
            ->assertOk()
            ->assertSee('data-tour="help-button"', false)
            ->assertSee('data-tour="workshop-count"', false)
            ->assertSee('const AUTOSTART = true;', false)
            ->assertSee('anh Hai!', false); // lời chào trong script (tiếng Việt bị mã hóa \u, chỉ so phần không dấu)

        $this->post('/onboarding-tour/seen')->assertNoContent();
        $this->assertNotNull($admin->fresh()->onboarding_tour_seen_at);

        $this->get('/admin')->assertOk()->assertSee('const AUTOSTART = false;', false);
        $this->get('/admin?tour=1')->assertOk()->assertSee('const AUTOSTART = true;', false);
    }

    public function test_help_button_on_other_pages_leads_back_to_the_dashboard_tour(): void
    {
        $this->actingAs($this->user('sa@gara.vn'));

        $this->get('/admin/vehicles')
            ->assertOk()
            ->assertSee('data-tour="help-button"', false)
            ->assertSee('admin?tour=1', false)
            ->assertDontSee('const AUTOSTART', false);

        $this->get('/admin')->assertOk()->assertSee('anh em!', false);
    }

    public function test_technician_has_no_office_tour(): void
    {
        $this->actingAs($this->user('tech1@gara.vn'));

        $this->get('/admin')
            ->assertOk()
            ->assertDontSee('data-tour="help-button"', false)
            ->assertDontSee('window.garageTour', false);
    }

    public function test_guest_cannot_mark_the_tour(): void
    {
        $this->post('/onboarding-tour/seen')->assertRedirect();
    }
}
