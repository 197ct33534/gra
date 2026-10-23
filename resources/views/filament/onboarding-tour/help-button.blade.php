@php
    use App\Filament\Pages\UserManual;
    use App\Models\User;
    use App\Support\OnboardingTours;
    use Filament\Pages\Dashboard;
    use Filament\Support\Icons\Heroicon;
    use Illuminate\Support\Js;

    $user = User::current();
    $pageTour = OnboardingTours::keyFor(request());
    // Trang không có tour riêng: quay về bảng điều khiển với ?tour=1 để Robot dẫn tổng quan.
    $startTour = $pageTour !== null
        ? 'close(); window.garageTour && window.garageTour.start()'
        : 'window.location.href = '.Js::from(Dashboard::getUrl(['tour' => 1]));
@endphp

@if (OnboardingTours::availableTo($user))
    <x-filament::dropdown placement="bottom-end" teleport>
        <x-slot name="trigger">
            <x-filament::icon-button
                :icon="Heroicon::OutlinedQuestionMarkCircle"
                color="gray"
                size="lg"
                label="Trợ giúp"
                tooltip="Trợ giúp"
                data-tour="help-button"
            />
        </x-slot>

        <x-filament::dropdown.list>
            <x-filament::dropdown.list.item
                :icon="Heroicon::OutlinedSparkles"
                :x-on:click="$startTour"
            >
                {{ $pageTour !== null ? 'Robot hướng dẫn trang này' : 'Robot hướng dẫn tổng quan' }}
            </x-filament::dropdown.list.item>

            <x-filament::dropdown.list.item
                :icon="Heroicon::OutlinedBookOpen"
                tag="a"
                :href="UserManual::getUrl()"
            >
                Cẩm nang sử dụng
            </x-filament::dropdown.list.item>
        </x-filament::dropdown.list>
    </x-filament::dropdown>
@endif
