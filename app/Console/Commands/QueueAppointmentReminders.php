<?php

namespace App\Console\Commands;

use App\Services\AppointmentService;
use Illuminate\Console\Command;

class QueueAppointmentReminders extends Command
{
    protected $signature = 'garage:queue-appointment-reminders';

    protected $description = 'Tạo tin nhắc Zalo/SMS cho lịch hẹn sắp tới';

    public function handle(AppointmentService $appointments): int
    {
        $result = $appointments->queueUpcomingReminders();
        $this->info("Đã tạo {$result['queued']} tin nhắc lịch hẹn, bỏ qua {$result['skipped']}.");

        return self::SUCCESS;
    }
}
