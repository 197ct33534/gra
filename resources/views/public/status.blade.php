<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Theo dõi {{ $order->code }} — {{ $garage['name'] }}</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; background: #f6f7f9; color: #111; }
        .wrap { max-width: 640px; margin: 0 auto; padding: 16px; }
        .card { background: #fff; border-radius: 12px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.08); margin-bottom: 12px; }
        h1 { font-size: 1.25rem; margin: 0 0 4px; }
        .muted { color: #666; font-size: .9rem; }
        .steps { display: grid; gap: 8px; }
        .step { display: flex; gap: 10px; align-items: center; padding: 8px; border-radius: 8px; }
        .step.done { background: #ecfdf5; }
        .step.current { background: #fffbeb; font-weight: 700; }
        .dot { width: 12px; height: 12px; border-radius: 999px; background: #d1d5db; flex-shrink: 0; }
        .step.done .dot { background: #16a34a; }
        .step.current .dot { background: #f59e0b; }
        .badge { display: inline-block; padding: 4px 10px; border-radius: 999px; background: #eef2ff; font-size: .85rem; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>{{ $garage['name'] }}</h1>
        <p class="muted">{{ $garage['address'] }} · {{ $garage['hotline'] }}</p>
        <p><strong>Theo dõi lệnh {{ $order->code }}</strong></p>
        <p class="muted">
            Xe {{ $order->vehicle?->license_plate }} —
            {{ $order->vehicle?->customer?->name }}
        </p>
        <p><span class="badge">{{ $order->status->getLabel() }}</span></p>
        @if($order->promised_at)
            <p class="muted">Hẹn trả: {{ $order->promised_at->timezone($garage['timezone'] ?? 'Asia/Ho_Chi_Minh')->format('H:i d/m/Y') }}</p>
        @endif
    </div>

    <div class="card">
        <p style="font-weight:600;margin-top:0">Tiến độ</p>
        <div class="steps">
            @foreach($timeline as $step)
                <div class="step {{ $step['done'] ? 'done' : '' }} {{ $step['current'] ? 'current' : '' }}">
                    <span class="dot"></span>
                    <span>{{ $step['label'] }}</span>
                </div>
            @endforeach
        </div>
    </div>

    @if($showMoney && $order->final_amount !== null)
        <div class="card">
            <p class="muted" style="margin:0">Tổng ước tính (tham khảo)</p>
            <p style="font-size:1.2rem;font-weight:700;margin:4px 0 0">
                {{ number_format((float) $order->final_amount, 0, ',', '.') }} đ
            </p>
        </div>
    @endif

    @if($order->status->value === 'quoted' && $order->quote_approval_token)
        <div class="card">
            <a href="{{ url('/quote/'.$order->quote_approval_token) }}">Mở báo giá để duyệt →</a>
        </div>
    @endif

    @if($order->delivered_at && $order->feedback_token && ! $order->feedback_at)
        <div class="card">
            <a href="{{ url('/feedback/'.$order->feedback_token) }}">Đánh giá trải nghiệm sửa chữa →</a>
        </div>
    @endif
</div>
</body>
</html>
