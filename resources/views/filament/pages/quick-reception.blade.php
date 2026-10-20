<x-filament-panels::page>
    @if ($last = $this->getLastOrder())
        <x-filament::section compact icon="heroicon-o-check-circle" icon-color="success">
            <x-slot name="heading">Vừa tiếp nhận {{ $last->vehicle?->license_plate }} — {{ $last->code }}</x-slot>
            <div style="display: flex; flex-wrap: wrap; gap: 8px;">
                <x-filament::button tag="a" size="sm" :href="\App\Filament\Resources\WorkOrders\WorkOrderResource::getUrl('edit', ['record' => $last])" icon="heroicon-o-pencil-square">
                    Mở lệnh để báo giá
                </x-filament::button>
                <x-filament::button tag="a" size="sm" color="warning" :href="route('work-orders.job-card', $last)" target="_blank" icon="heroicon-o-printer">
                    In Phiếu Giao Việc
                </x-filament::button>
            </div>
        </x-filament::section>
    @endif

    <form wire:submit="receive" style="display: grid; gap: 16px;">
        {{ $this->form }}

        <x-filament::button type="submit" size="xl" icon="heroicon-o-check" style="width: 100%;">
            Tiếp nhận xe
        </x-filament::button>
    </form>
</x-filament-panels::page>
