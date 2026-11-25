<x-filament-panels::page>
    @php
        $board = $this->getBoard();
        $columnLabels = \App\Services\WorkshopBoardService::columns();
        $timezone = (string) config('garage.timezone');
        $viewer = \App\Models\User::current();
        $showMoney = \App\Models\User::currentCanSeeMoney();
        // Điện thoại chỉ hiện 1 cột: mở trang là thấy ngay cột đầu tiên có xe.
        $defaultColumn = collect($board)->filter(fn (array $column): bool => $column['orders']->isNotEmpty())->keys()->first()
            ?? array_key_first($board);
    @endphp

    <style>
        .wb-board { display: grid; grid-template-columns: repeat(5, minmax(240px, 1fr)); gap: 12px; overflow-x: auto; padding-bottom: 8px; }
        .wb-col { background: #f3f4f6; border-radius: 12px; padding: 10px; min-height: 60vh; display: flex; flex-direction: column; gap: 10px; transition: background .15s, outline-color .15s; outline: 2px dashed transparent; }
        .wb-col.wb-over { background: #fef3c7; outline-color: #f59e0b; }
        .wb-col-head { display: flex; justify-content: space-between; align-items: center; font-weight: 700; font-size: 14px; color: #374151; padding: 2px 4px; }
        .wb-count { background: #fff; border-radius: 999px; padding: 0 9px; font-size: 12px; color: #4b5563; border: 1px solid #e5e7eb; }
        .wb-card { background: #fff; border: 1px solid #e5e7eb; border-left: 5px solid #f59e0b; border-radius: 10px; padding: 10px 10px 8px; box-shadow: 0 1px 2px rgba(0,0,0,.05); cursor: grab; user-select: none; }
        .wb-card.wb-late { border-left-color: #dc2626; }
        .wb-card.wb-wait { border-left-color: #ea580c; }
        .wb-card:active { cursor: grabbing; }
        .wb-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 6px; }
        .wb-plate { display: inline-block; background: #111827; color: #fde68a; font-weight: 800; font-size: 20px; letter-spacing: 1px; padding: 2px 10px; border-radius: 6px; line-height: 1.3; }
        .wb-model { font-weight: 600; font-size: 14px; margin-top: 4px; color: #111827; }
        .wb-code { font-size: 11px; color: #6b7280; }
        .wb-body { margin-top: 6px; font-size: 13px; color: #374151; display: grid; gap: 2px; }
        .wb-body b { color: #111827; }
        .wb-tags { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 6px; }
        .wb-foot { display: flex; justify-content: space-between; align-items: center; margin-top: 8px; padding-top: 6px; border-top: 1px dashed #e5e7eb; font-size: 13px; }
        .wb-total { font-weight: 700; color: #111827; }
        .wb-move { width: 100%; margin-top: 6px; font-size: 12px; border: 1px solid #d1d5db; border-radius: 6px; padding: 4px 6px; background: #fff; color: #374151; }
        .wb-empty { color: #9ca3af; font-size: 13px; text-align: center; padding: 24px 0; }
        .wb-hint { font-size: 13px; color: #6b7280; margin-bottom: 8px; }
        .dark .wb-col { background: rgba(255,255,255,.04); }
        .dark .wb-col.wb-over { background: rgba(245,158,11,.15); }
        .dark .wb-col-head { color: #e5e7eb; }
        .dark .wb-count { background: rgba(255,255,255,.08); border-color: transparent; color: #e5e7eb; }
        .dark .wb-card { background: #18181b; border-color: #3f3f46; }
        .dark .wb-model, .dark .wb-total, .dark .wb-body b { color: #f4f4f5; }
        .dark .wb-body { color: #d4d4d8; }
        .dark .wb-foot { border-top-color: #3f3f46; }
        .dark .wb-move { background: #27272a; border-color: #3f3f46; color: #e4e4e7; }

        /* Thanh chọn cột: chỉ dùng trên điện thoại. */
        .wb-tabs { display: none; gap: 6px; overflow-x: auto; padding: 2px 0 10px; margin-bottom: 4px; scrollbar-width: none; }
        .wb-tabs::-webkit-scrollbar { display: none; }
        .wb-tab { flex: 0 0 auto; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d1d5db; background: #fff; color: #374151; border-radius: 999px; padding: 8px 12px; font-size: 14px; font-weight: 600; white-space: nowrap; min-height: 40px; }
        .wb-tab .wb-count { border: 0; background: #f3f4f6; font-weight: 700; }
        .wb-tab.wb-tab-on { background: #f59e0b; border-color: #f59e0b; color: #111827; }
        .wb-tab.wb-tab-on .wb-count { background: rgba(255,255,255,.7); color: #111827; }
        .dark .wb-tab { background: #18181b; border-color: #3f3f46; color: #e4e4e7; }
        .dark .wb-tab .wb-count { background: rgba(255,255,255,.08); color: #e4e4e7; }
        .dark .wb-tab.wb-tab-on { background: #f59e0b; border-color: #f59e0b; color: #111827; }
        .wb-hint-phone { display: none; }

        /* Máy tính bảng: cuộn ngang, mỗi lần vuốt dừng đúng mép một cột. */
        @media (max-width: 1279px) {
            .wb-board { scroll-snap-type: x mandatory; }
            .wb-col { scroll-snap-align: start; }
        }

        /* Điện thoại: một cột chiếm hết bề ngang, đổi cột bằng thanh chọn; chuyển xe bằng ô 'Chuyển sang…'. */
        @media (max-width: 767px) {
            .wb-tabs { display: flex; }
            .wb-board { display: block; overflow: visible; padding-bottom: 0; }
            .wb-col { display: none; min-height: 0; padding: 8px; }
            .wb-col.wb-active { display: flex; }
            .wb-col-head { display: none; }
            .wb-card { cursor: default; padding: 12px; }
            .wb-plate { font-size: 22px; }
            .wb-body, .wb-foot { font-size: 14px; }
            .wb-move { font-size: 15px; min-height: 44px; padding: 8px 10px; margin-top: 10px; }
            .wb-empty { padding: 32px 0; }
            .wb-hint-desktop { display: none; }
            .wb-hint-phone { display: block; }
        }
    </style>

    <div class="wb-hint wb-hint-desktop">Kéo thả thẻ xe sang cột khác (hoặc chọn ở ô "Chuyển sang…" trên máy tính bảng). Bấm đúp hoặc biểu tượng con mắt để xem nhanh lệnh. Bảng tự cập nhật ngay khi có xe đổi trạng thái (dự phòng: làm mới mỗi 30 giây).</div>
    <div class="wb-hint wb-hint-phone">Chạm tên cột để xem xe trong cột đó. Chuyển xe: chọn ở ô "Chuyển sang…" dưới mỗi thẻ. Chạm biểu tượng con mắt để xem nhanh lệnh.</div>

    @include('filament.partials.workshop-realtime')

    <div wire:poll.30s x-data="{ over: null, col: '{{ $defaultColumn }}' }">
    <nav class="wb-tabs" role="tablist" aria-label="Chọn cột">
        @foreach ($board as $key => $column)
            <button
                type="button"
                role="tab"
                class="wb-tab"
                data-wb-tab="{{ $key }}"
                x-bind:class="{ 'wb-tab-on': col === '{{ $key }}' }"
                x-bind:aria-selected="col === '{{ $key }}'"
                x-on:click="col = '{{ $key }}'; window.scrollTo({ top: 0, behavior: 'smooth' })"
            >
                {{ $column['label'] }}
                <span class="wb-count">{{ $column['orders']->count() }}</span>
            </button>
        @endforeach
    </nav>

    <div class="wb-board">
        @foreach ($board as $key => $column)
            <section
                wire:key="col-{{ $key }}"
                class="wb-col"
                x-bind:class="{ 'wb-over': over === '{{ $key }}', 'wb-active': col === '{{ $key }}' }"
                x-on:dragover.prevent="over = '{{ $key }}'"
                x-on:dragleave="if (! $el.contains($event.relatedTarget)) over = null"
                x-on:drop.prevent="over = null; $wire.moveOrder(parseInt($event.dataTransfer.getData('text/plain')), '{{ $key }}')"
                data-column="{{ $key }}"
            >
                <div class="wb-col-head">
                    <span>{{ $column['label'] }}</span>
                    <span class="wb-count">{{ $column['orders']->count() }}</span>
                </div>

                @forelse ($column['orders'] as $order)
                    @php
                        $isLate = $order->promised_at !== null && $order->promised_at->isPast();
                        $vehicle = $order->vehicle;
                    @endphp
                    <article
                        wire:key="order-{{ $order->id }}"
                        class="wb-card {{ $isLate ? 'wb-late' : ($order->needs_restock ? 'wb-wait' : '') }}"
                        draggable="true"
                        x-on:dragstart="$event.dataTransfer.setData('text/plain', '{{ $order->id }}'); $event.dataTransfer.effectAllowed = 'move'"
                        x-on:dblclick="$wire.mountAction('viewOrder', { order: {{ $order->id }} })"
                        data-order="{{ $order->code }}"
                    >
                        <div class="wb-top">
                            <div>
                                <span class="wb-plate">{{ $vehicle?->license_plate ?? '—' }}</span>
                                <div class="wb-model">{{ trim(($vehicle?->brand ?? '').' '.($vehicle?->model ?? '')) }}</div>
                                <div class="wb-code">{{ $order->code }}</div>
                            </div>
                            <x-filament::icon-button
                                icon="heroicon-o-eye"
                                label="Xem chi tiết"
                                color="gray"
                                wire:click="mountAction('viewOrder', { order: {{ $order->id }} })"
                            />
                        </div>

                        <div class="wb-body">
                            <div>Khách: <b>{{ $vehicle?->customer?->name ?? '—' }}</b> · {{ $vehicle?->customer?->phone ?? '' }}</div>
                            <div>Cố vấn: {{ $order->serviceAdvisor?->name ?? '—' }}</div>
                        </div>

                        <div class="wb-tags">
                            <x-filament::badge color="gray" icon="heroicon-m-wrench">{{ $order->lift_bay ?: 'Chưa xếp cầu' }}</x-filament::badge>
                            @if ($order->promised_at)
                                <x-filament::badge :color="$isLate ? 'danger' : 'info'" icon="heroicon-m-clock">
                                    {{ $isLate ? 'Trễ hẹn · ' : 'Hẹn ' }}{{ $order->promised_at->timezone($timezone)->format('H:i d/m') }}
                                </x-filament::badge>
                            @endif
                            @if ($order->needs_restock)
                                <x-filament::badge color="warning" icon="heroicon-m-exclamation-triangle">Chờ đồ</x-filament::badge>
                            @endif
                            @if ($order->isInsuranceClaim())
                                <x-filament::badge color="purple">Bảo hiểm</x-filament::badge>
                            @endif
                        </div>

                        <div class="wb-foot">
                            <span class="wb-total">{{ $showMoney ? \App\Filament\Pages\WorkshopBoard::money($order->final_amount) : $order->code }}</span>
                            <span>{{ $order->approved_items_count }} hạng mục</span>
                        </div>

                        <select
                            class="wb-move"
                            aria-label="Chuyển sang cột khác"
                            x-on:change="if ($event.target.value) { $wire.moveOrder({{ $order->id }}, $event.target.value); $event.target.value = '' }"
                        >
                            <option value="">Chuyển sang…</option>
                            @foreach ($columnLabels as $targetKey => $targetLabel)
                                @continue($targetKey === $key || ! \App\Services\WorkshopBoardService::canMoveTo($viewer, $order, $targetKey))
                                <option value="{{ $targetKey }}">{{ $targetLabel }}</option>
                            @endforeach
                        </select>
                    </article>
                @empty
                    <div class="wb-empty">Không có xe</div>
                @endforelse
            </section>
        @endforeach
    </div>
    </div>
</x-filament-panels::page>
