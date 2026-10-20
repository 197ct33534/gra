<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Báo giá {{ $order->code }} — {{ $garage['name'] }}</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; background: #f6f7f9; color: #111; }
        .wrap { max-width: 640px; margin: 0 auto; padding: 16px; }
        .card { background: #fff; border-radius: 12px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.08); margin-bottom: 12px; }
        h1 { font-size: 1.25rem; margin: 0 0 4px; }
        .muted { color: #666; font-size: .9rem; }
        table { width: 100%; border-collapse: collapse; font-size: .95rem; }
        td, th { padding: 8px 4px; border-bottom: 1px solid #eee; text-align: left; }
        .total { font-size: 1.1rem; font-weight: 700; }
        .actions { display: flex; gap: 8px; flex-wrap: wrap; }
        button, .btn { border: 0; border-radius: 8px; padding: 12px 16px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-block; }
        .ok { background: #16a34a; color: #fff; }
        .no { background: #dc2626; color: #fff; }
        .flash { padding: 10px 12px; border-radius: 8px; margin-bottom: 12px; }
        .flash.ok { background: #dcfce7; color: #166534; }
        .flash.err { background: #fee2e2; color: #991b1b; }
        textarea { width: 100%; min-height: 70px; border: 1px solid #ddd; border-radius: 8px; padding: 8px; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>{{ $garage['name'] }}</h1>
        <p class="muted">{{ $garage['address'] }} · {{ $garage['hotline'] }}</p>
        <p><strong>Báo giá {{ $order->code }}</strong></p>
        <p class="muted">
            Xe {{ $order->vehicle?->license_plate }} —
            {{ $order->vehicle?->customer?->name }}
        </p>
    </div>

    @if(session('success'))
        <div class="flash ok">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="flash err">{{ session('error') }}</div>
    @endif

    <div class="card">
        <table>
            <thead>
            <tr><th>Hạng mục</th><th>SL</th><th>Thành tiền</th></tr>
            </thead>
            <tbody>
            @foreach($order->items->where('is_approved', true) as $item)
                <tr>
                    <td>{{ $item->catalogName() }}</td>
                    <td>{{ $item->quantity }}</td>
                    <td>{{ $money($item->subtotal) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
        <p class="total">Tổng (gồm VAT {{ $order->vat_rate }}%): {{ $money($order->final_amount) }}</p>
        <p class="muted">Trạng thái: {{ $order->status->getLabel() }}</p>
    </div>

    @if($order->status->value === 'quoted' && !$order->quote_rejected_at)
        <div class="card">
            <form method="post" action="{{ route('public.quote.approve', $token) }}" class="actions" style="margin-bottom:12px">
                @csrf
                <button class="ok" type="submit">Đồng ý sửa chữa</button>
            </form>
            <form method="post" action="{{ route('public.quote.reject', $token) }}">
                @csrf
                <label class="muted">Hoặc từ chối / yêu cầu chỉnh sửa:</label>
                <textarea name="reason" placeholder="Lý do (không bắt buộc)"></textarea>
                <p style="margin-top:8px"><button class="no" type="submit">Từ chối báo giá</button></p>
            </form>
        </div>
    @endif
</div>
</body>
</html>
