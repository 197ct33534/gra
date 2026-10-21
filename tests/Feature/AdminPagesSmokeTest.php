<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Resources\Pages\Page as ResourcePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * Mở mọi trang trong panel admin bằng từng vai trò: không trang nào được lỗi 500.
 * Trang có {record} dùng bản ghi đầu tiên của resource (dữ liệu mẫu từ seeder).
 */
class AdminPagesSmokeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<string>
     */
    private function adminUrls(): array
    {
        $urls = [];

        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            if (! in_array('GET', $route->methods(), true) || ! str_starts_with((string) $route->getName(), 'filament.admin.')) {
                continue;
            }

            if (str_contains((string) $route->getName(), '.auth.')) {
                continue;
            }

            $parameters = $route->parameterNames();

            if ($parameters === []) {
                $urls[] = '/'.ltrim($route->uri(), '/');

                continue;
            }

            if ($parameters !== ['record']) {
                continue;
            }

            $page = $route->getAction('uses');
            $page = is_string($page) ? $page : ($route->getAction('controller') ?? null);

            if (! is_string($page) || ! class_exists($page) || ! is_subclass_of($page, ResourcePage::class)) {
                continue;
            }

            $model = $page::getResource()::getModel();
            $record = $model::query()->orderBy((new $model)->getKeyName())->first();

            if ($record !== null) {
                $urls[] = '/'.str_replace('{record}', (string) $record->getRouteKey(), ltrim($route->uri(), '/'));
            }
        }

        sort($urls);

        return array_values(array_unique($urls));
    }

    public function test_every_admin_page_opens_for_every_role(): void
    {
        $this->seed();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $urls = $this->adminUrls();
        $this->assertGreaterThan(40, count($urls), 'Phải tìm thấy đủ các trang admin.');

        $failures = [];

        foreach (['admin@gara.vn', 'sa@gara.vn', 'tech1@gara.vn'] as $email) {
            $user = User::query()->where('email', $email)->firstOrFail();

            foreach ($urls as $url) {
                $this->flushSession();
                $status = $this->actingAs($user)->get($url)->getStatusCode();

                if (! in_array($status, [200, 302, 403], true)) {
                    $failures[] = "{$email} {$url} → {$status}";
                }
            }
        }

        $this->assertSame([], $failures, "Trang lỗi:\n".implode("\n", $failures));
    }

    public function test_public_customer_pages_open_and_reject_bad_links_cleanly(): void
    {
        $this->seed();

        $this->get(route('public.booking.create'))->assertOk();

        foreach (['public.quote.show', 'public.status.show', 'public.feedback.show'] as $name) {
            $this->get(route($name, ['token' => 'ma-khong-ton-tai']))->assertNotFound();
        }

        foreach (['public.quote.approve', 'public.quote.reject', 'public.feedback.store'] as $name) {
            // Link sai: quay lại trang trước kèm lỗi hoặc 404 — miễn không phải lỗi máy chủ.
            $status = $this->post(route($name, ['token' => 'ma-khong-ton-tai']))->getStatusCode();
            $this->assertLessThan(500, $status, "{$name} → {$status}");
        }
    }
}
