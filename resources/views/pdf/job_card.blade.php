<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <title>Phiếu giao việc {{ $workOrder->code }}</title>
    {{-- Phiếu cho thợ xưởng: TUYỆT ĐỐI không in đơn giá, tiền công hay bất kỳ số tiền nào. --}}
    <style>
        @page { size: A4; margin: 10mm 12mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; line-height: 1.35; }
        table { width: 100%; border-collapse: collapse; }
        .head td { vertical-align: top; padding: 0; }
        .garage { font-size: 14px; font-weight: bold; }
        .title { font-size: 20px; font-weight: bold; letter-spacing: 1px; margin-top: 2px; }
        .head .right { text-align: right; }
        .code { font-size: 18px; font-weight: bold; }
        .tag { display: inline-block; border: 2px solid #111; padding: 2px 8px; font-size: 18px; font-weight: bold; margin-top: 4px; }
        .promise { margin-top: 8px; border: 3px solid #111; padding: 6px 10px; font-size: 20px; font-weight: bold; text-align: center; }
        .plate { margin-top: 10px; border: 4px solid #111; padding: 6px 4px; text-align: center; font-size: 64px; font-weight: bold; letter-spacing: 3px; line-height: 1.1; }
        .vehicle { text-align: center; font-size: 30px; font-weight: bold; margin-top: 4px; }
        .odo { text-align: center; font-size: 16px; margin-top: 2px; }
        .label { font-size: 13px; font-weight: bold; text-transform: uppercase; margin-top: 12px; }
        .complaint { border: 2px dashed #111; padding: 8px 10px; font-size: 16px; min-height: 48px; white-space: pre-line; }
        .diagnosis { border: 1px solid #555; padding: 6px 10px; font-size: 13px; white-space: pre-line; }
        .jobs { margin-top: 4px; }
        .jobs th { border: 2px solid #111; background: #e5e5e5; padding: 6px 4px; font-size: 12px; text-align: center; }
        .jobs td { border: 1px solid #111; padding: 8px 6px; font-size: 14px; vertical-align: middle; }
        .jobs .no { width: 30px; text-align: center; font-weight: bold; }
        .jobs .src { width: 92px; text-align: center; font-size: 12px; }
        .jobs .tech { width: 110px; font-size: 12px; }
        .jobs .tick { width: 46px; text-align: center; font-size: 26px; }
        .jobs .kcs { width: 90px; }
        .sub { font-size: 10px; color: #333; }
        .extra { font-size: 10px; font-weight: bold; border: 1px solid #111; padding: 0 3px; }
        .signs { margin-top: 10px; page-break-inside: avoid; }
        .signs td { width: 33%; text-align: center; vertical-align: top; font-weight: bold; font-size: 12px; }
        .space { height: 44px; }
        .note { margin-top: 8px; font-size: 10px; color: #333; }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            <td>
                <div class="garage">{{ $garage['name'] }}</div>
                <div class="title">PHIẾU GIAO VIỆC</div>
                <div>Cố vấn dịch vụ: {{ $workOrder->serviceAdvisor?->name ?? '—' }}</div>
            </td>
            <td class="right">
                <div class="code">{{ $workOrder->code }}</div>
                <div class="tag">{{ $workOrder->lift_bay ?: 'CẦU: ……' }}</div>
            </td>
        </tr>
    </table>

    <div class="promise">HẸN GIAO XE: {{ $promisedAt ?? '………… giờ ………… ngày …………' }}</div>

    <div class="plate">{{ $licensePlate }}</div>
    <div class="vehicle">{{ $vehicleName !== '' ? $vehicleName : '—' }}</div>
    <div class="odo">ODO nhận xe: <strong>{{ $odo }}</strong></div>

    <div class="label">Khách phản ánh (nguyên văn)</div>
    <div class="complaint">{{ $workOrder->customer_request ?: '(Khách không ghi chú)' }}</div>

    @if (filled($workOrder->technical_diagnosis))
        <div class="label">Chẩn đoán của gara</div>
        <div class="diagnosis">{{ $workOrder->technical_diagnosis }}</div>
    @endif

    @if (! empty($bodyInspection))
        <div class="label">Hiện trạng thân vỏ khi nhận</div>
        <div class="diagnosis">
            @foreach ($bodyInspection as $line)
                • {{ $line }}@if (! $loop->last)<br>@endif
            @endforeach
        </div>
    @endif

    <div class="label">Công việc cần làm</div>
    <table class="jobs">
        <thead>
            <tr>
                <th>STT</th>
                <th>Hạng mục công việc / phụ tùng thay thế</th>
                <th>Nguồn gốc</th>
                <th>Thợ phụ trách</th>
                <th>Xong</th>
                <th>KCS ký</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lines as $line)
                <tr>
                    <td class="no">{{ $line['no'] }}</td>
                    <td>
                        {{ $line['name'] }}
                        @if ($line['is_additional'])
                            <span class="extra">PHÁT SINH</span>
                        @endif
                        @if ($line['shelf'])
                            <div class="sub">Lấy ở kệ: {{ $line['shelf'] }}</div>
                        @endif
                        @if ($line['supplier'])
                            <div class="sub">Nơi mua / gia công: {{ $line['supplier'] }}</div>
                        @endif
                    </td>
                    <td class="src">{{ $line['source'] }}</td>
                    <td class="tech">{{ $line['technician'] !== '' ? $line['technician'] : '…………………' }}</td>
                    <td class="tick">☐</td>
                    <td class="kcs"></td>
                </tr>
            @empty
                <tr><td colspan="6" style="text-align:center">Chưa có hạng mục được duyệt.</td></tr>
            @endforelse
            @for ($i = 0; $i < max(1, 4 - count($lines)); $i++)
                <tr>
                    <td class="no"></td>
                    <td>&nbsp;</td>
                    <td class="src"></td>
                    <td class="tech"></td>
                    <td class="tick">☐</td>
                    <td class="kcs"></td>
                </tr>
            @endfor
        </tbody>
    </table>
    <div class="note">Phát hiện hư hỏng thêm: ghi vào dòng trống và báo cố vấn dịch vụ lập báo giá phát sinh. KHÔNG tự ý thay khi khách chưa duyệt.</div>

    <table class="signs">
        <tr>
            <td>Thợ chính</td>
            <td>Tổ trưởng / KCS</td>
            <td>Cố vấn dịch vụ</td>
        </tr>
        <tr>
            <td class="space"></td>
            <td class="space"></td>
            <td class="space"></td>
        </tr>
    </table>
</body>
</html>
