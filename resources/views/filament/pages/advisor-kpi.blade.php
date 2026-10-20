<x-filament-panels::page>
    @php($rows = $this->getRows())

    <style>
        .ak-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .ak-table th, .ak-table td { padding: 8px 10px; border-bottom: 1px solid rgba(127,127,127,.15); text-align: left; }
        .ak-table .num { text-align: right; white-space: nowrap; }
        .ak-toolbar { display: flex; gap: 12px; align-items: center; margin-bottom: 12px; }
        .ak-muted { opacity: .65; font-size: 12px; }
    </style>

    <div class="ak-toolbar">
        <label for="ak-month" style="font-weight:600">Tháng</label>
        <x-filament::input.wrapper style="min-width:160px">
            <x-filament::input.select id="ak-month" wire:model.live="month">
                @foreach (\App\Filament\Pages\AdvisorKpi::monthOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
        <span class="ak-muted">Conversion = RO đã duyệt / RO đã báo giá trong tháng tạo lệnh. Doanh thu theo xe bàn giao trong tháng.</span>
    </div>

    <x-filament::section>
        <div style="overflow-x:auto">
            <table class="ak-table">
                <thead>
                <tr>
                    <th>Cố vấn</th>
                    <th class="num">RO tạo</th>
                    <th class="num">Đã BG</th>
                    <th class="num">Đã duyệt</th>
                    <th class="num">Conversion</th>
                    <th class="num">Xe giao</th>
                    <th class="num">Doanh thu</th>
                    <th class="num">Điểm TB</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td>
                            <div style="font-weight:600">{{ $row['advisor']->name }}</div>
                            <div class="ak-muted">{{ $row['advisor']->email }}</div>
                        </td>
                        <td class="num">{{ $row['ros'] }}</td>
                        <td class="num">{{ $row['quoted'] }}</td>
                        <td class="num">{{ $row['approved'] }}</td>
                        <td class="num">{{ number_format($row['conversion'], 1, ',', '.') }}%</td>
                        <td class="num">{{ $row['delivered'] }}</td>
                        <td class="num">{{ \App\Filament\Pages\AdvisorKpi::money($row['revenue']) }}</td>
                        <td class="num">
                            @if ($row['avg_feedback'] !== null)
                                {{ number_format($row['avg_feedback'], 2, ',', '.') }}
                                <span class="ak-muted">({{ $row['feedback_count'] }})</span>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="ak-muted">Chưa có cố vấn.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
