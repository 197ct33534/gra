<x-filament-panels::page>
    @php($transfers = $this->getTransfers())

    <style>
        .st-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .st-table th, .st-table td { padding: 8px 10px; border-bottom: 1px solid rgba(127,127,127,.15); text-align: left; }
        .st-form { display: grid; gap: 12px; margin-bottom: 16px; }
        .st-row { display: flex; gap: 12px; flex-wrap: wrap; align-items: end; }
        .st-muted { opacity: .65; font-size: 12px; }
        .st-lines { display: grid; gap: 8px; }
    </style>

    <x-filament::section>
        <x-slot name="heading">Tạo phiếu chuyển (nháp)</x-slot>
        <div class="st-form">
            <div class="st-row">
                <div>
                    <label style="font-weight:600;display:block;margin-bottom:4px">Từ chi nhánh</label>
                    <x-filament::input.wrapper style="min-width:200px">
                        <x-filament::input.select wire:model.live="from_branch_id">
                            @foreach ($this->branchOptions() as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
                <div>
                    <label style="font-weight:600;display:block;margin-bottom:4px">Đến chi nhánh</label>
                    <x-filament::input.wrapper style="min-width:200px">
                        <x-filament::input.select wire:model="to_branch_id">
                            <option value="">— Chọn —</option>
                            @foreach ($this->branchOptions() as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </div>
            </div>

            <div class="st-lines">
                <div style="font-weight:600">Dòng phụ tùng</div>
                @foreach ($lines as $index => $line)
                    <div class="st-row">
                        <x-filament::input.wrapper style="min-width:320px;flex:1">
                            <x-filament::input.select wire:model="lines.{{ $index }}.part_id">
                                <option value="">— PT —</option>
                                @foreach ($this->partOptions() as $id => $label)
                                    <option value="{{ $id }}">{{ $label }}</option>
                                @endforeach
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                        <x-filament::input.wrapper style="width:100px">
                            <x-filament::input type="number" min="1" wire:model="lines.{{ $index }}.quantity" />
                        </x-filament::input.wrapper>
                    </div>
                @endforeach
                <x-filament::button type="button" color="gray" size="sm" wire:click="addLine">
                    Thêm dòng
                </x-filament::button>
            </div>

            <div>
                <label style="font-weight:600;display:block;margin-bottom:4px">Ghi chú</label>
                <x-filament::input.wrapper>
                    <x-filament::input type="text" wire:model="notes" />
                </x-filament::input.wrapper>
            </div>

            <div>
                <x-filament::button wire:click="createDraft" wire:confirm="Tạo phiếu chuyển nháp?">
                    Tạo phiếu nháp
                </x-filament::button>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Phiếu gần đây</x-slot>
        <div style="overflow-x:auto">
            <table class="st-table">
                <thead>
                <tr>
                    <th>Mã</th>
                    <th>Từ → Đến</th>
                    <th>Dòng</th>
                    <th>TT</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($transfers as $transfer)
                    <tr>
                        <td>{{ $transfer->code }}</td>
                        <td>{{ $transfer->fromBranch?->code }} → {{ $transfer->toBranch?->code }}</td>
                        <td>{{ $transfer->items->count() }}</td>
                        <td>{{ \App\Filament\Pages\StockTransfers::statusLabel($transfer->status) }}</td>
                        <td>
                            @if ($transfer->status === \App\Enums\StockTransferStatus::Draft)
                                <x-filament::button size="sm" color="success"
                                    wire:click="completeTransfer({{ $transfer->id }})"
                                    wire:confirm="Hoàn tất chuyển kho {{ $transfer->code }}?">
                                    Hoàn tất
                                </x-filament::button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="st-muted">Chưa có phiếu chuyển.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
