<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum InvoicePayer: string implements HasColor, HasLabel
{
    case Customer = 'customer';
    case Insurance = 'insurance';

    public function getLabel(): string
    {
        return match ($this) {
            self::Customer => 'Khách hàng',
            self::Insurance => 'Công ty bảo hiểm',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Customer => 'gray',
            self::Insurance => 'info',
        };
    }
}
