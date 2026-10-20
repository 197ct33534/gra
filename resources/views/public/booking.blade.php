<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Đặt lịch — {{ $garage['name'] }}</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; background: #f6f7f9; color: #111; }
        .wrap { max-width: 560px; margin: 0 auto; padding: 16px; }
        .card { background: #fff; border-radius: 12px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,.08); margin-bottom: 12px; }
        h1 { font-size: 1.25rem; margin: 0 0 4px; }
        .muted { color: #666; font-size: .9rem; }
        label { display: block; font-size: .9rem; margin: 10px 0 4px; }
        input, textarea { width: 100%; border: 1px solid #ddd; border-radius: 8px; padding: 10px; box-sizing: border-box; }
        button { border: 0; border-radius: 8px; padding: 12px 16px; background: #d97706; color: #fff; font-weight: 600; cursor: pointer; margin-top: 12px; width: 100%; }
        .flash { padding: 10px 12px; border-radius: 8px; margin-bottom: 12px; }
        .flash.ok { background: #dcfce7; color: #166534; }
        .flash.err { background: #fee2e2; color: #991b1b; }
        .err { color: #b91c1c; font-size: .85rem; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>{{ $garage['name'] }}</h1>
        <p class="muted">{{ $garage['address'] }} · {{ $garage['hotline'] }}</p>
        <p><strong>Đặt lịch sửa xe online</strong></p>
    </div>

    @if(session('success'))
        <div class="flash ok">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="flash err">{{ session('error') }}</div>
    @endif

    <div class="card">
        <form method="post" action="{{ route('public.booking.store') }}">
            @csrf
            <label>Họ tên *</label>
            <input name="name" value="{{ old('name') }}" required maxlength="255">
            @error('name') <div class="err">{{ $message }}</div> @enderror

            <label>Số điện thoại *</label>
            <input name="phone" value="{{ old('phone') }}" required placeholder="0901234567">
            @error('phone') <div class="err">{{ $message }}</div> @enderror

            <label>Biển số xe *</label>
            <input name="license_plate" value="{{ old('license_plate') }}" required placeholder="51K-123.45">
            @error('license_plate') <div class="err">{{ $message }}</div> @enderror

            <label>Thời gian mong muốn *</label>
            <input type="datetime-local" name="scheduled_start" value="{{ old('scheduled_start') }}" required>
            @error('scheduled_start') <div class="err">{{ $message }}</div> @enderror

            <label>Nhu cầu / triệu chứng</label>
            <textarea name="customer_request" rows="3" placeholder="VD: Bảo dưỡng 10.000 km, máy kêu...">{{ old('customer_request') }}</textarea>

            <button type="submit">Gửi yêu cầu đặt lịch</button>
            <p class="muted" style="margin-top:10px">Gara sẽ gọi xác nhận và xếp cầu. Không chọn được cầu trực tiếp trên form này.</p>
        </form>
    </div>
</div>
</body>
</html>
