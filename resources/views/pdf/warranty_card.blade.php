<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <title>Phiếu bảo hành {{ $card->code }}</title>
    @include('pdf.partials.styles')
</head>
<body>
    <h2 style="text-align:center;margin:0 0 8px;">PHIẾU BẢO HÀNH</h2>
    <p style="text-align:center;margin:0 0 16px;"><strong>{{ $garage['name'] }}</strong><br>{{ $garage['address'] }} · Hotline {{ $garage['hotline'] }}</p>

    <table width="100%" cellspacing="0" cellpadding="4">
        <tr><td width="35%">Mã thẻ</td><td><strong>{{ $card->code }}</strong></td></tr>
        <tr><td>Khách hàng</td><td>{{ $card->customer?->name }} — {{ $card->customer?->phone }}</td></tr>
        <tr><td>Xe</td><td>{{ $card->vehicle?->license_plate }} ({{ $card->vehicle?->brand }} {{ $card->vehicle?->model }})</td></tr>
        <tr><td>Hạng mục</td><td>{{ $card->item_name }}</td></tr>
        <tr><td>Lệnh gốc</td><td>{{ $card->workOrder?->code }}</td></tr>
        <tr><td>Bắt đầu</td><td>{{ $card->starts_at?->format('d/m/Y') }} @if($card->start_odo) / {{ number_format($card->start_odo, 0, ',', '.') }} km @endif</td></tr>
        <tr><td>Hết hạn</td><td>
            @if($card->ends_at) {{ $card->ends_at->format('d/m/Y') }} @else — @endif
            @if($card->end_odo) / {{ number_format($card->end_odo, 0, ',', '.') }} km @endif
        </td></tr>
    </table>

    <p style="margin-top:16px;"><strong>Điều khoản:</strong><br>{{ $card->terms ?: $garage['warranty'] }}</p>
    <p style="font-size:11px;color:#555;">Bảo hành theo điều kiện đến trước (ngày hoặc km). Không áp dụng với phụ tùng khách tự mang.</p>
</body>
</html>
