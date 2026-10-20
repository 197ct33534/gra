<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <title>Hóa đơn {{ $invoice->invoice_number }}</title>
    <style>
        @include('pdf.partials.styles')
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
                <h2>{{ $documentTitle }}</h2>
                <div>Số hóa đơn: <strong>{{ $invoice->invoice_number }}</strong></div>
                <div>Lệnh sửa chữa: {{ $workOrder->code }}</div>
                <div>Ngày thanh toán: {{ $invoice->created_at?->timezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') }}</div>
                <div>Phương thức: {{ $invoice->payment_method->getLabel() }}</div>
                <div>Bên thanh toán: <strong>{{ $payerLabel }}</strong></div>
                <div>Cố vấn dịch vụ: {{ $workOrder->serviceAdvisor?->name ?? '—' }}</div>
            </td>
        </tr>
    </table>
    <div class="rule"></div>

    <div class="section">Xe và khách hàng</div>
    <table class="items">
        @if ($isInsuranceClaim)
            <tr>
                <td>Công ty bảo hiểm</td>
                <td>{{ $workOrder->insurance_company ?: '—' }}</td>
                <td>Đơn vị thanh toán hóa đơn này</td>
                <td>{{ $payerLabel }}</td>
            </tr>
        @endif
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

    <div class="section">Tiền công dịch vụ và bảo dưỡng</div>
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
            @forelse ($services as $line)
                <tr>
                    <td>{{ $line['name'] }}</td>
                    <td>{{ $line['unit'] }}</td>
                    <td class="num">{{ $line['unit_price'] }}</td>
                    <td class="num">{{ $line['discount'] }}</td>
                    <td class="num">{{ $line['subtotal'] }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">Không có hạng mục tiền công.</td></tr>
            @endforelse
        </tbody>
    </table>

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
            @forelse ($parts as $line)
                <tr>
                    <td>{{ $line['name'] }}</td>
                    <td>{{ $line['code'] }}</td>
                    <td>{{ $line['unit'] }}</td>
                    <td class="num">{{ $line['quantity'] }}</td>
                    <td class="num">{{ $line['unit_price'] }}</td>
                    <td class="num">{{ $line['discount'] }}</td>
                    <td class="num">{{ $line['subtotal'] }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty">Không có phụ tùng thay thế.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="summary">
        <tr>
            <td style="width: 75%;">
                <table>
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
                    @if ($isInsuranceClaim)
                        <tr>
                            <td class="label">Tổng chi phí sửa chữa</td>
                            <td class="right">{{ $finalAmount }}</td>
                        </tr>
                        <tr>
                            <td class="label">Khấu trừ khách tự trả</td>
                            <td class="right">{{ $split['deductible'] }}</td>
                        </tr>
                        <tr>
                            <td class="label">Hạng mục ngoài phạm vi bảo hiểm (gồm VAT)</td>
                            <td class="right">{{ $split['outside_scope'] }}</td>
                        </tr>
                        <tr>
                            <td class="label">Phần khách hàng trả</td>
                            <td class="right">{{ $split['customer'] }}</td>
                        </tr>
                        <tr>
                            <td class="label">Phần bảo hiểm chi trả</td>
                            <td class="right">{{ $split['insurance'] }}</td>
                        </tr>
                    @endif
                    <tr>
                        <td class="label">Đã thu</td>
                        <td class="right">{{ $paidAmount }}</td>
                    </tr>
                    <tr>
                        <td class="label">Còn phải thu</td>
                        <td class="right">{{ $outstandingAmount }}</td>
                    </tr>
                    <tr>
                        <td class="label grand">{{ $isInsuranceClaim ? 'Số tiền '.($isInsurancePayer ? 'bảo hiểm' : 'khách hàng').' thanh toán' : 'Tổng thanh toán' }}</td>
                        <td class="right grand">{{ $amountDue }}</td>
                    </tr>
                </table>
                <div class="words">Bằng chữ: {{ $amountInWords }}.</div>
            </td>
            @if ($showQr)
                <td class="qr">
                    <img src="{{ $qrDataUri }}" alt="VietQR">
                    <div class="caption">Quét mã để chuyển khoản</div>
                    <div class="caption">{{ $qrDescription }}</div>
                    <div class="caption">{{ $garage['account_name'] }}</div>
                </td>
            @else
                <td class="qr">
                    <div class="caption">Thanh toán theo hồ sơ bồi thường</div>
                    <div class="caption">{{ $payerLabel }}</div>
                </td>
            @endif
        </tr>
    </table>

    <div class="box">
        <strong>Điều khoản bảo hành:</strong> {{ $garage['warranty'] }}
        @if (filled($invoice->notes))
            <div>Ghi chú thanh toán: {{ $invoice->notes }}</div>
        @endif
    </div>

    <table class="signs">
        <tr>
            <td>{{ $isInsurancePayer ? 'Đại diện công ty bảo hiểm' : 'Khách hàng nhận xe' }}</td>
            <td>Cố vấn dịch vụ</td>
            <td>Kế toán / Thu ngân</td>
        </tr>
        <tr>
            <td class="space"></td>
            <td class="space"></td>
            <td class="space"></td>
        </tr>
        <tr>
            <td>{{ $isInsurancePayer ? $payerLabel : ($workOrder->vehicle?->customer?->name ?? '') }}</td>
            <td>{{ $workOrder->serviceAdvisor?->name ?? '' }}</td>
            <td></td>
        </tr>
    </table>
</body>
</html>
