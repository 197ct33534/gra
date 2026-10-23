@php
    $user = \App\Models\User::current();
    $canTour = $user?->isAdmin() || $user?->role === \App\Enums\UserRole::ServiceAdvisor;
    // Ngoài bảng điều khiển: quay về bảng điều khiển với ?tour=1 để Robot chạy lại.
    $onClick = 'window.garageTour ? window.garageTour.start() : (window.location.href = '.\Illuminate\Support\Js::from(\Filament\Pages\Dashboard::getUrl(['tour' => 1])).')';
@endphp

@if ($canTour)
    <x-filament::icon-button
        :icon="\Filament\Support\Icons\Heroicon::OutlinedQuestionMarkCircle"
        color="gray"
        size="lg"
        label="Trợ giúp"
        tooltip="Trợ giúp: Robot dẫn đường"
        data-tour="help-button"
        :x-on:click="$onClick"
    />
@endif
