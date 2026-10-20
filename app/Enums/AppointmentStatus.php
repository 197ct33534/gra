<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AppointmentStatus: string implements HasColor, HasLabel
{
    case Scheduled = 'scheduled';
    case CheckedIn = 'checked_in';
    case Canceled = 'canceled';
    case NoShow = 'no_show';

    public function getLabel(): string
    {
        return match ($this) {
            self::Scheduled => 'Đã hẹn',
            self::CheckedIn => 'Đã check-in',
            self::Canceled => 'Đã hủy',
            self::NoShow => 'Không đến',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Scheduled => 'info',
            self::CheckedIn => 'success',
            self::Canceled => 'gray',
            self::NoShow => 'danger',
        };
    }

    public function blocksBay(): bool
    {
        return $this === self::Scheduled;
    }
}
