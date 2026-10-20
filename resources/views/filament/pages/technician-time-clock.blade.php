<x-filament-panels::page>
    @php
        $open = $this->getOpenLog();
        $orders = $this->getAvailableOrders();
        $tz = config('garage.timezone');
    @endphp

    <style>
        .tc-card { border: 1px solid rgba(127,127,127,.25); border-radius: 12px; padding: 14px 16px; margin-bottom: 10px; }
        .tc-open { background: rgba(22,163,74,.08); border-color: rgba(22,163,74,.35); }
        .tc-row { display: flex; justify-content: space-between; gap: 12px; align-items: center; flex-wrap: wrap; }
        .tc-muted { opacity: .7; font-size: 13px; }
        .tc-code { font-weight: 700; }
    </style>

    @if ($open)
        <div class="tc-card tc-open">
            <div class="tc-row">
                <div>
                    <div class="tc-code">Đang làm: {{ $open->workOrder?->code }}</div>
                    <div class="tc-muted">
                        Xe {{ $open->workOrder?->vehicle?->license_plate }} —
                        bắt đầu {{ $open->started_at?->timezone($tz)->format('H:i d/m') }}
                    </div>
                </div>
                <x-filament::button color="danger" wire:click="stopClock" wire:confirm="Dừng chấm công lệnh này?">
                    Dừng chấm công
                </x-filament::button>
            </div>
        </div>
    @else
        <x-filament::section>
            <x-slot name="heading">Chưa có phiên đang chạy</x-slot>
            <p class="tc-muted">Chọn lệnh bên dưới để bắt đầu đếm giờ thực tế.</p>
        </x-filament::section>
    @endif

    <x-filament::section>
        <x-slot name="heading">Lệnh có thể chấm công</x-slot>
        @forelse ($orders as $order)
            <div class="tc-card">
                <div class="tc-row">
                    <div>
                        <div class="tc-code">{{ $order->code }} — {{ $order->vehicle?->license_plate }}</div>
                        <div class="tc-muted">
                            {{ $order->status->getLabel() }}
                            @if ($order->promised_at)
                                · hẹn {{ $order->promised_at->timezone($tz)->format('H:i d/m') }}
                            @endif
                            · {{ $order->vehicle?->customer?->name }}
                        </div>
                    </div>
                    @if (! $open)
                        <x-filament::button wire:click="startClock({{ $order->id }})">
                            Bắt đầu
                        </x-filament::button>
                    @endif
                </div>
            </div>
        @empty
            <p class="tc-muted">Không có lệnh đang sửa / đã duyệt để chấm công.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
