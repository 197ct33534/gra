<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Đánh giá {{ $order->code }} — {{ $garage['name'] }}</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; background: #f6f7f9; color: #111; }
        .wrap { max-width: 560px; margin: 0 auto; padding: 16px; }
        .card { background: #fff; border-radius: 12px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.08); margin-bottom: 12px; }
        h1 { font-size: 1.25rem; margin: 0 0 4px; }
        .muted { color: #666; font-size: .9rem; }
        .scores { display: flex; gap: 8px; flex-wrap: wrap; margin: 12px 0; }
        .scores label { border: 1px solid #ddd; border-radius: 8px; padding: 10px 14px; cursor: pointer; }
        .scores input { margin-right: 4px; }
        textarea { width: 100%; min-height: 90px; border: 1px solid #ddd; border-radius: 8px; padding: 8px; }
        button { border: 0; border-radius: 8px; padding: 12px 16px; background: #16a34a; color: #fff; font-weight: 600; cursor: pointer; }
        .flash { padding: 10px 12px; border-radius: 8px; margin-bottom: 12px; }
        .flash.ok { background: #dcfce7; color: #166534; }
        .flash.err { background: #fee2e2; color: #991b1b; }
        .stars { font-size: 1.4rem; color: #f59e0b; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>{{ $garage['name'] }}</h1>
        <p class="muted">Đánh giá lần sửa {{ $order->code }} · xe {{ $order->vehicle?->license_plate }}</p>
    </div>

    @if(session('success'))
        <div class="flash ok">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="flash err">{{ session('error') }}</div>
    @endif

    <div class="card">
        @if($order->feedback_at)
            <p>Bạn đã đánh giá <span class="stars">{{ str_repeat('★', (int) $order->feedback_score) }}{{ str_repeat('☆', 5 - (int) $order->feedback_score) }}</span></p>
            @if($order->feedback_comment)
                <p class="muted">“{{ $order->feedback_comment }}”</p>
            @endif
            <p class="muted">Cảm ơn quý khách!</p>
        @else
            <form method="post" action="{{ route('public.feedback.store', $token) }}">
                @csrf
                <p style="font-weight:600;margin-top:0">Mức hài lòng (1–5)</p>
                <div class="scores">
                    @for($i = 1; $i <= 5; $i++)
                        <label>
                            <input type="radio" name="score" value="{{ $i }}" @checked(old('score') == $i || $i === 5) required>
                            {{ $i }}
                        </label>
                    @endfor
                </div>
                @error('score') <p class="flash err">{{ $message }}</p> @enderror
                <label class="muted">Góp ý thêm (không bắt buộc)</label>
                <textarea name="comment" placeholder="Thợ nhiệt tình, trả xe đúng hẹn…">{{ old('comment') }}</textarea>
                <p style="margin-top:12px"><button type="submit">Gửi đánh giá</button></p>
            </form>
        @endif
    </div>
</div>
</body>
</html>
