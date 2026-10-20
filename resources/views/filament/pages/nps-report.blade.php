<x-filament-panels::page>
    @php($stats = $this->getStats())

    <style>
        .nps-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 10px; margin: 12px 0; }
        .nps-stat { border: 1px solid rgba(127,127,127,.2); border-radius: 10px; padding: 12px; }
        .nps-stat .lbl { font-size: 12px; opacity: .7; }
        .nps-stat .val { font-size: 22px; font-weight: 700; margin-top: 4px; }
        .nps-bar { display: flex; gap: 6px; align-items: flex-end; height: 120px; margin-top: 16px; }
        .nps-col { flex: 1; text-align: center; }
        .nps-col .fill { background: #f59e0b; border-radius: 6px 6px 0 0; min-height: 4px; }
        .nps-toolbar { display: flex; gap: 12px; align-items: center; }
    </style>

    <div class="nps-toolbar">
        <label for="nps-month" style="font-weight:600">Tháng</label>
        <x-filament::input.wrapper style="min-width:160px">
            <x-filament::input.select id="nps-month" wire:model.live="month">
                @foreach (\App\Filament\Pages\NpsReport::monthOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
    </div>

    <div class="nps-grid">
        <div class="nps-stat"><div class="lbl">Số đánh giá</div><div class="val">{{ $stats['count'] }}</div></div>
        <div class="nps-stat"><div class="lbl">Điểm TB</div><div class="val">{{ number_format($stats['avg'], 2, ',', '.') }}</div></div>
        <div class="nps-stat"><div class="lbl">NPS*</div><div class="val">{{ $stats['nps'] }}</div></div>
        <div class="nps-stat"><div class="lbl">5★ / ≤2★</div><div class="val">{{ $stats['promoters'] }} / {{ $stats['detractors'] }}</div></div>
    </div>

    @php($max = max(1, max($stats['distribution'])))
    <div class="nps-bar">
        @foreach ($stats['distribution'] as $score => $qty)
            <div class="nps-col">
                <div class="fill" style="height: {{ (int) round(($qty / $max) * 100) }}px"></div>
                <div style="font-size:12px;margin-top:4px">{{ $score }}★</div>
                <div style="font-weight:600">{{ $qty }}</div>
            </div>
        @endforeach
    </div>
    <p style="opacity:.65;font-size:12px;margin-top:12px">* NPS gần đúng thang 1–5: (5★ − ≤2★) / tổng × 100.</p>
</x-filament-panels::page>
