@if ($order)
    @php($timezone = (string) config('garage.timezone'))
    <div style="display: grid; gap: 14px; font-size: 14px;">
        <div>
            <div style="font-size: 26px; font-weight: 800; letter-spacing: 1px;">{{ $order->vehicle?->license_plate }}</div>
            <div style="font-weight: 600;">{{ trim(($order->vehicle?->brand ?? '').' '.($order->vehicle?->model ?? '').' '.($order->vehicle?->year ?? '')) }}</div>
            <div style="opacity: .7;">ODO nhận xe: {{ number_format((int) $order->reception_odo, 0, ',', '.') }} km</div>
        </div>

        <div style="display: flex; flex-wrap: wrap; gap: 6px;">
            <x-filament::badge :color="$order->status->getColor()">{{ $order->status->getLabel() }}</x-filament::badge>
            <x-filament::badge color="gray">{{ $order->lift_bay ?: 'Chưa xếp cầu' }}</x-filament::badge>
            @if ($order->promised_at)
                <x-filament::badge :color="$order->promised_at->isPast() ? 'danger' : 'info'">
                    Hẹn giao {{ $order->promised_at->timezone($timezone)->format('H:i d/m/Y') }}
                </x-filament::badge>
            @endif
            @if ($order->needs_restock)
                <x-filament::badge color="warning">Chờ đồ</x-filament::badge>
            @endif
        </div>

        <div>
            <div style="font-weight: 700;">Khách hàng</div>
            <div>{{ $order->vehicle?->customer?->name }} · {{ $order->vehicle?->customer?->phone }}</div>
            <div>Cố vấn: {{ $order->serviceAdvisor?->name ?? '—' }}</div>
        </div>

        <div>
            <div style="font-weight: 700;">Khách phản ánh</div>
            <div style="white-space: pre-line;">{{ $order->customer_request ?: '—' }}</div>
        </div>

        @if (filled($order->technical_diagnosis))
            <div>
                <div style="font-weight: 700;">Chẩn đoán</div>
                <div style="white-space: pre-line;">{{ $order->technical_diagnosis }}</div>
            </div>
        @endif

        <div>
            <div style="font-weight: 700;">Hạng mục</div>
            <ul style="margin: 4px 0 0 18px; list-style: disc;">
                @foreach ($lines as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>
        </div>

        @if (\App\Models\User::currentCanSeeMoney())
            <div style="font-size: 16px; font-weight: 700;">Tổng tiền hiện tại: {{ \App\Support\VietnameseMoney::format($order->final_amount) }}</div>
        @endif
    </div>
@else
    <div>Không tìm thấy lệnh sửa chữa.</div>
@endif
