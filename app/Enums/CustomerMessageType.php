<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CustomerMessageType: string implements HasLabel
{
    case MaintenanceReminder = 'maintenance_reminder';
    case PaymentReceipt = 'payment_receipt';
    case AppointmentReminder = 'appointment_reminder';
    case EInvoiceLink = 'einvoice_link';

    public function getLabel(): string
    {
        return match ($this) {
            self::MaintenanceReminder => 'Nhắc bảo dưỡng',
            self::PaymentReceipt => 'Báo đã nhận tiền',
            self::AppointmentReminder => 'Nhắc lịch hẹn',
            self::EInvoiceLink => 'Link HĐĐT',
        };
    }
}
