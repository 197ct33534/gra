<x-filament-panels::page>
    @php($report = $this->getReport())

    <style>
        .pr-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .pr-table th { text-align: left; font-weight: 600; padding: 8px 10px; border-bottom: 1px solid rgba(127,127,127,.3); white-space: nowrap; }
        .pr-table td { padding: 8px 10px; border-bottom: 1px solid rgba(127,127,127,.15); vertical-align: top; }
        .pr-table .num { text-align: right; white-space: nowrap; }
        .pr-table tfoot td { font-weight: 700; border-top: 2px solid rgba(127,127,127,.4); }
        .pr-wrap { overflow-x: auto; }
        .pr-muted { opacity: .65; font-size: 12px; }
        .pr-payout { font-weight: 700; color: #16a34a; }
        .pr-detail summary { cursor: pointer; font-size: 13px; opacity: .8; margin-top: 4px; }
        .pr-detail table { margin-top: 6px; font-size: 13px; }
        .pr-toolbar { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
    </style>

    <div class="pr-toolbar">
        <label for="pr-month" style="font-weight: 600;">Kỳ lương</label>
        <x-filament::input.wrapper style="min-width: 200px;">
            <x-filament::input.select id="pr-month" wire:model.live="month">
                @foreach (\App\Filament\Pages\TechnicianPayroll::monthOptions() as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </x-filament::input.select>
        </x-filament::input.wrapper>
        <span class="pr-muted">Tính trên xe đã bàn giao trong tháng. Mặc định thợ hưởng {{ \App\Services\PayrollService::defaultRate() }}% tiền công; dịch vụ có mức khoán cố định thì tính theo mức đó.</span>
    </div>

    <x-filament::section icon="heroicon-o-banknotes">
        <x-slot name="heading">Lương khoán thợ — tổng {{ \App\Filament\Pages\TechnicianPayroll::money($report['total']) }}</x-slot>
        <div class="pr-wrap">
            <table class="pr-table">
                <thead>
                    <tr>
                        <th>Thợ</th>
                        <th class="num">Hạng mục</th>
                        <th class="num">Số xe</th>
                        <th class="num">Tiền công làm ra</th>
                        <th class="num">Giờ chuẩn</th>
                        <th class="num">Làm lại (BH)</th>
                        <th class="num">Tiền khoán</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['technicians'] as $row)
                        <tr>
                            <td>
                                <div style="font-weight: 600;">{{ $row['technician']->name }}</div>
                                <div class="pr-muted">Hưởng {{ rtrim(rtrim(number_format($row['rate'], 2, ',', '.'), '0'), ',') }}% tiền công</div>
                                @if (! empty($report['details'][$row['technician']->id]))
                                    <details class="pr-detail">
                                        <summary>Xem {{ count($report['details'][$row['technician']->id]) }} hạng mục</summary>
                                        <table class="pr-table">
                                            @foreach ($report['details'][$row['technician']->id] as $line)
                                                <tr>
                                                    <td>{{ $line['date'] }}</td>
                                                    <td>{{ $line['code'] }}<div class="pr-muted">{{ $line['plate'] }}</div></td>
                                                    <td>{{ $line['job'] }}</td>
                                                    <td class="num">{{ $line['labor'] }}</td>
                                                    <td class="num">{{ $line['payout'] }} <span class="pr-muted">{{ $line['estimated'] }}</span></td>
                                                </tr>
                                            @endforeach
                                        </table>
                                    </details>
                                @endif
                            </td>
                            <td class="num">{{ $row['jobs'] }}</td>
                            <td class="num">{{ $row['cars'] }}</td>
                            <td class="num">{{ \App\Filament\Pages\TechnicianPayroll::money($row['labor']) }}</td>
                            <td class="num">{{ number_format($row['minutes'] / 60, 1, ',', '.') }} giờ</td>
                            <td class="num" style="{{ $row['redo'] > 0 ? 'color:#dc2626;font-weight:700' : '' }}">{{ $row['redo'] }}</td>
                            <td class="num pr-payout">{{ \App\Filament\Pages\TechnicianPayroll::money($row['payout']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="pr-muted">Chưa có kỹ thuật viên.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section icon="heroicon-o-wrench-screwdriver">
        <x-slot name="heading">Năng suất cầu nâng</x-slot>
        <div class="pr-wrap">
            <table class="pr-table">
                <thead>
                    <tr>
                        <th>Cầu</th>
                        <th class="num">Xe đã giao</th>
                        <th class="num">Tiền công</th>
                        <th class="num">Tiền phụ tùng</th>
                        <th class="num">Xe nằm xưởng TB</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['lifts'] as $row)
                        <tr>
                            <td style="font-weight: 600;">{{ $row['lift'] }}</td>
                            <td class="num">{{ $row['cars'] }}</td>
                            <td class="num">{{ \App\Filament\Pages\TechnicianPayroll::money($row['labor']) }}</td>
                            <td class="num">{{ \App\Filament\Pages\TechnicianPayroll::money($row['parts']) }}</td>
                            <td class="num">{{ number_format($row['avg_hours'], 1, ',', '.') }} giờ</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="pr-muted">Chưa có xe bàn giao trong tháng.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
