<?php

return [
    'name' => env('GARAGE_NAME', 'Gara Ô tô Đức Phát'),
    'address' => env('GARAGE_ADDRESS', 'Số 123 Đường Xe Hơi, Quận 7, TP. Hồ Chí Minh'),
    'hotline' => env('GARAGE_HOTLINE', '0901234567'),
    'bank_id' => env('GARAGE_BANK_ID', 'MB'),
    'account_no' => env('GARAGE_ACCOUNT_NO', '0901234567'),
    'account_name' => env('GARAGE_ACCOUNT_NAME', 'GARA O TO DUC PHAT'),
    'warranty' => env('GARAGE_WARRANTY', 'Bảo hành phụ tùng 06 tháng hoặc 10.000 km tùy điều kiện nào đến trước.'),
    // Cố vấn dịch vụ được giảm giá / bán dưới bảng giá tối đa bao nhiêu %; vượt mức cần chủ gara.
    'advisor_max_discount_percent' => (float) env('GARAGE_ADVISOR_MAX_DISCOUNT', 10),
    // Thợ hưởng mặc định bao nhiêu % tiền công hạng mục mình làm (đè được theo từng thợ hoặc khoán cố định theo dịch vụ).
    'default_commission_percent' => (float) env('GARAGE_DEFAULT_COMMISSION', 30),
    'maintenance' => [
        'interval_km' => (int) env('GARAGE_SERVICE_INTERVAL_KM', 5000),
        'interval_months' => (int) env('GARAGE_SERVICE_INTERVAL_MONTHS', 3),
        // Dùng khi xe mới có một lần vào xưởng, chưa đủ dữ liệu ước số km/ngày.
        'default_km_per_day' => (float) env('GARAGE_DEFAULT_KM_PER_DAY', 40),
        // Nhắc trước khi đến hạn bao nhiêu ngày, và không nhắc lại một xe trong bao nhiêu ngày.
        'remind_before_days' => (int) env('GARAGE_REMIND_BEFORE_DAYS', 14),
        'remind_again_after_days' => (int) env('GARAGE_REMIND_AGAIN_AFTER_DAYS', 30),
    ],
    'messaging' => [
        // log = giả lập (ghi log, không gửi thật); zalo_zns / sms_brandname khi có tài khoản nhà cung cấp.
        'driver' => env('GARAGE_MESSAGING_DRIVER', 'log'),
        'channel' => env('GARAGE_MESSAGING_CHANNEL', 'zalo'),
    ],
    'bank_webhook' => [
        // Khóa API khai báo trên SePay (header "Authorization: Apikey <khóa>").
        'sepay_api_key' => env('SEPAY_WEBHOOK_API_KEY'),
    ],
    'timezone' => env('GARAGE_TIMEZONE', 'Asia/Ho_Chi_Minh'),
    'lift_bays' => array_map(
        fn (int $bay): string => 'Cầu '.$bay,
        range(1, max(1, (int) env('GARAGE_LIFT_BAYS', 6))),
    ),
    'purchasing' => [
        // weighted_average = bình quân gia quyền; last_purchase = lấy giá lần nhập mới nhất.
        'cost_method' => env('GARAGE_COST_METHOD', 'weighted_average'),
        'default_vat_rate' => (int) env('GARAGE_PURCHASE_VAT_RATE', 8),
    ],
    'appointments' => [
        'default_duration_minutes' => (int) env('GARAGE_APPOINTMENT_MINUTES', 60),
        'bay_buffer_minutes' => (int) env('GARAGE_BAY_BUFFER_MINUTES', 15),
        'remind_before_hours' => (int) env('GARAGE_APPOINTMENT_REMIND_HOURS', 24),
    ],
    'cash' => [
        // true = bắt buộc mở ca trước khi thu tiền mặt; false = bỏ qua ghi quỹ (không khuyến nghị).
        'require_open_session_for_cash' => (bool) env('GARAGE_CASH_REQUIRE_SESSION', true),
    ],
];
