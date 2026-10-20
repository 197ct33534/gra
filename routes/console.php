<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Giữ khách: 8h sáng tạo tin nhắc bảo dưỡng, gửi tin chờ mỗi 5 phút (cần cron `php artisan schedule:run`).
Schedule::command('garage:queue-maintenance-reminders')->dailyAt('08:00')->timezone('Asia/Ho_Chi_Minh');
Schedule::command('garage:send-messages')->everyFiveMinutes()->withoutOverlapping();
