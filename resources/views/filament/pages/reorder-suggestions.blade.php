<x-filament-panels::page>
    @php($rows = $this->getSuggestions())

    <style>
        .ro-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .ro-table th, .ro-table td { padding: 8px 10px; border-bottom: 1px solid rgba(127,127,127,.15); text-align: left; }
        .ro-table .num { text-align: right; white-space: nowrap; }
        .ro-toolbar { display: flex; gap: 12px; align-items: end; flex-wrap: wrap; margin-bottom: 12px; }
        .ro-muted { opacity: .65; font-size: 12px; }
        .ro-danger { color: #dc2626; font-weight: 700; }
    </style>

    <div class="ro-toolbar">
        <div>
            <label style="font-weight:600;display:block;margin-bottom:4px">Nhà cung cấp</label>
            <x-filament::input.wrapper style="min-width:240px">
                <x-filament::input.select wire:model="supplier_id">
                    <option value="">— Chọn NCC —</option>
                    @foreach (\App\Models\Supplier::query()->active()->orderBy('name')->get() as $supplier)
                        <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>
        <x-filament::button wire:click="createDraftPo" wire:confirm="Tạo PO nháp với các dòng đã chọn?">
            Tạo PO nháp
        </x-filament::button>
        <span class="ro-muted">SL đề xuất ≈ max(ngưỡng×2 − tồn khả dụng, ngưỡng).</span>
    </div>

    <x-filament::section>
        <div style="overflow-x:auto">
            <table class="ro-table">
                <thead>
                <tr>
                    <th></th>
                    <th>SKU</th>
                    <th>Tên</th>
                    <th>Nhóm</th>
                    <th class="num">Khả dụng</th>
                    <th class="num">Ngưỡng</th>
                    <th class="num">Đề xuất nhập</th>
                    <th class="num">Giá vốn</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($rows as $row)
                    <tr>
                        <td>
                            <input type="checkbox" value="{{ $row['part']->id }}" wire:model="selected">
                        </td>
                        <td>{{ $row['part']->sku }}</td>
                        <td>{{ $row['part']->name }}</td>
                        <td>{{ $row['part']->category?->name }}</td>
                        <td class="num {{ $row['available'] <= 0 ? 'ro-danger' : '' }}">{{ $row['available'] }}</td>
                        <td class="num">{{ $row['part']->min_stock_alert }}</td>
                        <td class="num">{{ $row['suggest_qty'] }}</td>
                        <td class="num">{{ \App\Filament\Pages\ReorderSuggestions::money((float) $row['part']->cost_price) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="ro-muted">Không có mã dưới ngưỡng tồn.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
