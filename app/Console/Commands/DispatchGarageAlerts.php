<?php

namespace App\Console\Commands;

use App\Services\GarageAlertService;
use Illuminate\Console\Command;

class DispatchGarageAlerts extends Command
{
    protected $signature = 'garage:dispatch-alerts';

    protected $description = 'Gửi thông báo Filament: RO trễ hẹn, lịch hẹn trễ, tồn thấp';

    public function handle(GarageAlertService $alerts): int
    {
        $sent = $alerts->dispatchDatabaseNotifications();

        $this->info(sprintf(
            'Alerts: late RO=%d, late appt=%d, low stock=%d',
            $sent['late_ro'],
            $sent['late_appt'],
            $sent['low_stock'],
        ));

        return self::SUCCESS;
    }
}
