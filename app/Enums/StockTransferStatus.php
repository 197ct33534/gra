<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StockTransferStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Completed = 'completed';
    case Canceled = 'canceled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Nháp',
            self::Completed => 'Hoàn tất',
            self::Canceled => 'Hủy',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Completed => 'success',
            self::Canceled => 'danger',
        };
    }
}
