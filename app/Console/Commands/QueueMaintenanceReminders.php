<?php

namespace App\Console\Commands;

use App\Services\MessagingService;
use DomainException;
use Illuminate\Console\Command;

class QueueMaintenanceReminders extends Command
{
    protected $signature = 'garage:queue-maintenance-reminders';

    protected $description = 'Tạo tin nhắc bảo dưỡng cho các xe đã đến hạn hoặc sắp đến hạn';

    public function handle(MessagingService $messaging): int
    {
        $queued = 0;
        $skipped = 0;

        foreach ($messaging->dueVehicles() as $row) {
            try {
                $messaging->queueMaintenanceReminder($row['vehicle']);
                $queued++;
            } catch (DomainException $exception) {
                $skipped++;
                $this->line('Bỏ qua: '.$exception->getMessage());
            }
        }

        $this->info("Đã tạo {$queued} tin nhắc, bỏ qua {$skipped} xe.");

        return self::SUCCESS;
    }
}
