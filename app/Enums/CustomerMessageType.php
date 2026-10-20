<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CustomerMessageType: string implements HasLabel
{
    case MaintenanceReminder = 'maintenance_reminder';
    case PaymentReceipt = 'payment_receipt';

    public function getLabel(): string
    {
        return match ($this) {
            self::MaintenanceReminder => 'Nhắc bảo dưỡng',
            self::PaymentReceipt => 'Báo đã nhận tiền',
        };
    }
}
