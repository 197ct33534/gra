<x-filament-panels::page>
    <form wire:submit.prevent="$refresh" class="space-y-6">
        {{ $this->form }}

        <x-filament::button type="submit" color="primary">
            Xem báo cáo
        </x-filament::button>
    </form>

    @php
        $out = $this->outputInvoices();
        $outTot = $this->outputTotals();
        $in = $this->inputBills();
        $inTot = $this->inputTotals();
    @endphp

    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-4 space-y-3">
        <h3 class="text-base font-semibold">VAT đầu ra (hóa đơn bán)</h3>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Trước thuế: <strong>{{ $this->money($outTot['before']) }}</strong>
            · VAT: <strong>{{ $this->money($outTot['vat']) }}</strong>
            · Tổng: <strong>{{ $this->money($outTot['total']) }}</strong>
        </p>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left border-b">
                        <th class="py-2 pr-3">Số HĐ</th>
                        <th class="py-2 pr-3">Ngày</th>
                        <th class="py-2 pr-3">Bên trả</th>
                        <th class="py-2 pr-3">VAT%</th>
                        <th class="py-2 pr-3">Trước thuế</th>
                        <th class="py-2 pr-3">VAT</th>
                        <th class="py-2">Tổng</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($out as $invoice)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-3">{{ $invoice->invoice_number }}</td>
                            <td class="py-2 pr-3">{{ $invoice->created_at?->timezone(config('garage.timezone'))->format('d/m/Y') }}</td>
                            <td class="py-2 pr-3">{{ $invoice->payer()->getLabel() }}</td>
                            <td class="py-2 pr-3">{{ $invoice->vat_rate ?? '—' }}</td>
                            <td class="py-2 pr-3">{{ $this->money($invoice->amount_before_vat) }}</td>
                            <td class="py-2 pr-3">{{ $this->money($invoice->vat_amount) }}</td>
                            <td class="py-2">{{ $this->money($invoice->total_amount) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-4 text-gray-500">Không có hóa đơn trong kỳ.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-4 space-y-3 mt-6">
        <h3 class="text-base font-semibold">VAT đầu vào (hóa đơn NCC)</h3>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            Trước thuế: <strong>{{ $this->money($inTot['before']) }}</strong>
            · VAT: <strong>{{ $this->money($inTot['vat']) }}</strong>
            · Tổng: <strong>{{ $this->money($inTot['total']) }}</strong>
        </p>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left border-b">
                        <th class="py-2 pr-3">NCC</th>
                        <th class="py-2 pr-3">Số HĐ</th>
                        <th class="py-2 pr-3">Ngày</th>
                        <th class="py-2 pr-3">VAT%</th>
                        <th class="py-2 pr-3">Trước thuế</th>
                        <th class="py-2 pr-3">VAT</th>
                        <th class="py-2">Tổng</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($in as $bill)
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="py-2 pr-3">{{ $bill->supplier?->name }}</td>
                            <td class="py-2 pr-3">{{ $bill->bill_number ?? '—' }}</td>
                            <td class="py-2 pr-3">{{ $bill->bill_date?->format('d/m/Y') }}</td>
                            <td class="py-2 pr-3">{{ $bill->vat_rate }}</td>
                            <td class="py-2 pr-3">{{ $this->money($bill->amount_before_vat) }}</td>
                            <td class="py-2 pr-3">{{ $this->money($bill->vat_amount) }}</td>
                            <td class="py-2">{{ $this->money($bill->total_amount) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-4 text-gray-500">Không có hóa đơn NCC trong kỳ.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-filament-panels::page>
