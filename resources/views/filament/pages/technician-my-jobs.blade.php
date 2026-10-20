<x-filament-panels::page>
    @php
        $jobs = $this->getJobs();
        $tz = config('garage.timezone');
    @endphp

    <style>
        .mj-card { border: 1px solid rgba(127,127,127,.25); border-radius: 12px; padding: 14px 16px; margin-bottom: 10px; }
        .mj-row { display: flex; justify-content: space-between; gap: 12px; align-items: center; flex-wrap: wrap; }
        .mj-code { font-weight: 700; font-size: 1.05rem; }
        .mj-muted { opacity: .7; font-size: 13px; }
        .mj-late { color: #dc2626; font-weight: 700; }
        .mj-bay { display: inline-block; border: 1px solid rgba(127,127,127,.4); border-radius: 6px; padding: 2px 8px; font-size: 12px; }
    </style>

    @forelse ($jobs as $order)
        @php($late = $order->promised_at && $order->promised_at->isPast())
        <div class="mj-card">
            <div class="mj-row">
                <div>
                    <div class="mj-code">{{ $order->code }} — {{ $order->vehicle?->license_plate }}</div>
                    <div class="mj-muted">
                        {{ $order->status->getLabel() }}
                        @if($order->lift_bay)
                            · <span class="mj-bay">{{ $order->lift_bay }}</span>
                        @endif
                        @if($order->promised_at)
                            · hẹn
                            <span class="{{ $late ? 'mj-late' : '' }}">
                                {{ $order->promised_at->timezone($tz)->format('H:i d/m') }}
                            </span>
                        @endif
                    </div>
                    <div class="mj-muted">{{ $order->vehicle?->customer?->name }} · {{ \Illuminate\Support\Str::limit($order->customer_request, 80) }}</div>
                </div>
                <x-filament::button tag="a" :href="$this->orderUrl($order)" size="sm">
                    Mở lệnh
                </x-filament::button>
            </div>
        </div>
    @empty
        <x-filament::section>
            <p class="mj-muted">Không có việc đang mở.</p>
        </x-filament::section>
    @endforelse
</x-filament-panels::page>
