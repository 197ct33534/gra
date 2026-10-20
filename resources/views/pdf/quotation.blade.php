<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <title>Báo giá {{ $workOrder->code }}</title>
    <style>
        @include('pdf.partials.styles')
        .part-title { margin-top: 14px; padding: 5px 8px; background: #e6edf5; color: #16324f; font-size: 12px; font-weight: bold; text-transform: uppercase; }
        .part-title.addendum { background: #ede9fe; color: #5b21b6; border-left: 4px solid #7c3aed; }
        .addendum-note { margin-top: 4px; padding: 6px 8px; border: 1px dashed #7c3aed; font-size: 10.5px; }
        .addendum-note ul { margin: 2px 0 0 16px; padding: 0; }
        .part-total { margin-top: 4px; text-align: right; font-size: 11px; }
        .declined { margin-top: 18px; border: 2px solid #b91c1c; padding: 8px; page-break-inside: avoid; }
        .declined h3 { margin: 0 0 4px; font-size: 12px; color: #b91c1c; text-transform: uppercase; }
        .declined .items th { background: #b91c1c; }
        .declined .waiver { margin-top: 6px; font-size: 10px; font-style: italic; }
    </style>
</head>
<body>
    <table class="meta">
        <tr>
            <td>
                <h1>{{ $garage['name'] }}</h1>
                <div>{{ $garage['address'] }}</div>
                <div>Hotline: {{ $garage['hotline'] }}</div>
            </td>
            <td class="right">
                <h2>Báo giá sửa chữa</h2>
                <div>Số báo giá: <strong>{{ $workOrder->code }}</strong></div>
                <div>Ngày lập: {{ $workOrder->created_at?->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y') }}</div>
                <div>Cố vấn dịch vụ: {{ $workOrder->serviceAdvisor?->name ?? '—' }}</div>
            </td>
        </tr>
    </table>
    <div class="rule"></div>

    <div class="section">Xe và khách hàng</div>
    <table class="items">
        <tr>
            <td>Khách hàng</td>
            <td>{{ $workOrder->vehicle?->customer?->name ?? '—' }}</td>
            <td>Số điện thoại</td>
            <td>{{ $workOrder->vehicle?->customer?->phone ?? '—' }}</td>
        </tr>
        <tr>
            <td>Biển số xe</td>
            <td>{{ $workOrder->vehicle?->license_plate ?? '—' }}</td>
            <td>Dòng xe / Hãng</td>
            <td>{{ trim(($workOrder->vehicle?->brand ?? '').' '.($workOrder->vehicle?->model ?? '').' '.($workOrder->vehicle?->year ?? '')) }}</td>
        </tr>
        <tr>
            <td>Số ODO lúc nhận</td>
            <td>{{ number_format((int) $workOrder->reception_odo, 0, ',', '.') }} km</td>
            <td>Yêu cầu ban đầu</td>
            <td>{{ $workOrder->customer_request ?: '—' }}</td>
        </tr>
    </table>

    @foreach ($sections as $section)
        <div class="part-title {{ $section['is_addendum'] ? 'addendum' : '' }}">{{ $section['title'] }}</div>
        @if ($section['is_addendum'])
            <div class="addendum-note">
                <div>Thời gian phát sinh: <strong>{{ $section['raised_at'] ?? '—' }}</strong></div>
                @if ($section['findings'] !== [])
                    <div>Hiện tượng thợ phát hiện khi tháo xe:</div>
                    <ul>
                        @foreach ($section['findings'] as $finding)
                            <li>{{ $finding }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif

        @if ($section['services']->isNotEmpty())
            <div class="section">Tiền công dịch vụ</div>
            <table class="items">
                <thead>
                    <tr>
                        <th>Tên dịch vụ</th>
                        <th>ĐVT</th>
                        <th class="num">Tiền công</th>
                        <th class="num">Giảm trừ</th>
                        <th class="num">Thành tiền</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($section['services'] as $line)
                        <tr>
                            <td>{{ $line['name'] }}</td>
                            <td>{{ $line['unit'] }}</td>
                            <td class="num">{{ $line['unit_price'] }}</td>
                            <td class="num">{{ $line['discount'] }}</td>
                            <td class="num">{{ $line['subtotal'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if ($section['parts']->isNotEmpty())
            <div class="section">Phụ tùng, dầu nhớt thay thế</div>
            <table class="items">
                <thead>
                    <tr>
                        <th>Tên phụ tùng</th>
                        <th>OEM/SKU</th>
                        <th>ĐVT</th>
                        <th class="num">Số lượng</th>
                        <th class="num">Đơn giá</th>
                        <th class="num">Giảm trừ</th>
                        <th class="num">Thành tiền</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($section['parts'] as $line)
                        <tr>
                            <td>{{ $line['name'] }}</td>
                            <td>{{ $line['code'] }}</td>
                            <td>{{ $line['unit'] }}</td>
                            <td class="num">{{ $line['quantity'] }}</td>
                            <td class="num">{{ $line['unit_price'] }}</td>
                            <td class="num">{{ $line['discount'] }}</td>
                            <td class="num">{{ $line['subtotal'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <div class="part-total">Cộng {{ $section['short'] }}: <strong>{{ $section['total'] }}</strong> (chưa VAT)</div>
    @endforeach

    @if ($sections === [])
        <div class="empty" style="margin-top: 10px;">Chưa có hạng mục được khách đồng ý.</div>
    @endif

    <table class="summary">
        <tr>
            <td class="label">Tổng tiền công</td>
            <td class="right">{{ $laborAmount }}</td>
        </tr>
        <tr>
            <td class="label">Tổng tiền phụ tùng</td>
            <td class="right">{{ $partsAmount }}</td>
        </tr>
        <tr>
            <td class="label">Giảm giá chung</td>
            <td class="right">{{ $discountAmount }}</td>
        </tr>
        <tr>
            <td class="label">Thuế VAT ({{ (int) $workOrder->vat_rate }}%)</td>
            <td class="right">{{ $vatAmount }}</td>
        </tr>
        <tr>
            <td class="label grand">Tổng thanh toán</td>
            <td class="right grand">{{ $finalAmount }}</td>
        </tr>
    </table>
    <div class="words">Bằng chữ: {{ $amountInWords }}.</div>

    <table class="signs">
        <tr>
            <td>Cố vấn dịch vụ</td>
            <td>Khách hàng xác nhận duyệt báo giá</td>
        </tr>
        <tr>
            <td class="space"></td>
            <td class="space"></td>
        </tr>
        <tr>
            <td>{{ $workOrder->serviceAdvisor?->name ?? '' }}</td>
            <td>{{ $workOrder->vehicle?->customer?->name ?? '' }}</td>
        </tr>
    </table>

    @if ($declined !== [])
        <div class="declined">
            <h3>Hạng mục khuyến nghị chưa thực hiện (Khách hàng bảo lưu/từ chối)</h3>
            <table class="items">
                <thead>
                    <tr>
                        <th>Hạng mục gara khuyến nghị</th>
                        <th>Đợt báo giá</th>
                        <th class="num">SL</th>
                        <th>Lý do khách chưa làm</th>
                        <th class="num">Giá trị tham khảo</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($declined as $line)
                        <tr>
                            <td>{{ $line['name'] }}</td>
                            <td>{{ $line['revision'] }}</td>
                            <td class="num">{{ $line['quantity'] }}</td>
                            <td>{{ $line['reason'] }}</td>
                            <td class="num">{{ $line['amount'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="waiver">
                Gara đã kiểm tra, thông báo tình trạng và khuyến nghị thực hiện các hạng mục trên. Khách hàng xác nhận đã được tư vấn và tự nguyện
                chưa thực hiện. Gara được miễn trừ trách nhiệm đối với các sự cố phát sinh từ những bộ phận này sau khi xe xuất xưởng.
            </div>
            <table class="signs">
                <tr>
                    <td></td>
                    <td>Khách hàng ký xác nhận từ chối</td>
                </tr>
                <tr>
                    <td class="space"></td>
                    <td class="space"></td>
                </tr>
                <tr>
                    <td></td>
                    <td>{{ $workOrder->vehicle?->customer?->name ?? '' }}</td>
                </tr>
            </table>
        </div>
    @endif
</body>
</html>
