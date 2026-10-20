<x-filament-panels::page>
    @php($report = $this->getReport())
    @php($aging = $report['aging'])
    @php($pnl = $report['pnl'])

    <style>
        .fr-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .fr-table th, .fr-table td { padding: 8px 10px; border-bottom: 1px solid rgba(127,127,127,.15); text-align: left; }
        .fr-table .num { text-align: right; white-space: nowrap; }
        .fr-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; margin-bottom: 12px; }
        .fr-stat { border: 1px solid rgba(127,127,127,.2); border-radius: 10px; padding: 12px; }
        .fr-stat .lbl { font-size: 12px; opacity: .7; }
        .fr-stat .val { font-size: 18px; font-weight: 700; margin-top: 4px; }
        .fr-toolbar { display: flex; gap: 12px; align-items: center; margin-bottom: 12px; }
    </style>

    <x-filament::section icon="heroicon-o-clock">
        <x-slot name="heading">Aging công nợ phải thu — tổng {{ \App\Filament\Pages\FinancialReports::money($aging['total']) }}</x-slot>
        <div class="fr-grid">
            @foreach ($aging['buckets'] as $bucket)
                <div class="fr-stat">
                    <div class="lbl">{{ $bucket['label'] }} ({{ $bucket['count'] }} HĐ)</div>
                    <div class="val">{{ \App\Filament\Pages\FinancialReports::money($bucket['amount']) }}</div>
                </div>
            @endforeach
        </div>
        <div style="overflow-x:auto">
            <table class="fr-table">
                <thead>
                <tr>
                    <th>Hóa đơn</th>
                    <th>Khách / xe</th>
                    <th class="num">Tuổi nợ</th>
                    <th class="num">Còn phải thu</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($aging['rows'] as $row)
                    <tr>
                        <td>{{ $row['invoice']->invoice_number }}</td>
                        <td>
                            {{ $row['invoice']->payer_name ?: ($row['invoice']->workOrder?->vehicle?->customer?->name ?? '—') }}
                            <div style="opacity:.65;font-size:12px">{{ $row['invoice']->workOrder?->vehicle?->license_plate }}</div>
                        </td>
                        <td class="num">{{ $row['days'] }} ngày</td>
                        <td class="num">{{ \App\Filament\Pages\FinancialReports::money($row['outstanding']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" style="opacity:.65">Không còn công nợ phải thu.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section icon="heroicon-o-chart-bar">
        <x-slot name="heading">P&L tháng (xe đã bàn giao)</x-slot>
        <div class="fr-toolbar">
            <label for="fr-month" style="font-weight:600">Tháng</label>
            <x-filament::input.wrapper style="min-width:160px">
                <x-filament::input.select id="fr-month" wire:model.live="month">
                    @foreach (\App\Filament\Pages\FinancialReports::monthOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
        <div class="fr-grid">
            <div class="fr-stat"><div class="lbl">Số xe giao</div><div class="val">{{ $pnl['cars'] }}</div></div>
            <div class="fr-stat"><div class="lbl">Doanh thu RO</div><div class="val">{{ \App\Filament\Pages\FinancialReports::money($pnl['delivered_revenue']) }}</div></div>
            <div class="fr-stat"><div class="lbl">Tiền công</div><div class="val">{{ \App\Filament\Pages\FinancialReports::money($pnl['delivered_labor']) }}</div></div>
            <div class="fr-stat"><div class="lbl">Tiền PT</div><div class="val">{{ \App\Filament\Pages\FinancialReports::money($pnl['delivered_parts']) }}</div></div>
            <div class="fr-stat"><div class="lbl">Giá vốn (COGS)</div><div class="val">{{ \App\Filament\Pages\FinancialReports::money($pnl['delivered_cogs']) }}</div></div>
            <div class="fr-stat"><div class="lbl">Lãi gộp</div><div class="val">{{ \App\Filament\Pages\FinancialReports::money($pnl['delivered_gross_profit']) }}</div></div>
            <div class="fr-stat"><div class="lbl">HĐ phát hành</div><div class="val">{{ \App\Filament\Pages\FinancialReports::money($pnl['invoice_billed']) }}</div></div>
            <div class="fr-stat"><div class="lbl">Đã thu trong kỳ</div><div class="val">{{ \App\Filament\Pages\FinancialReports::money($pnl['invoice_collected']) }}</div></div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
