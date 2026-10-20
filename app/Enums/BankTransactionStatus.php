<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum BankTransactionStatus: string implements HasColor, HasLabel
{
    case Matched = 'matched';
    case Unmatched = 'unmatched';
    case Ignored = 'ignored';

    public function getLabel(): string
    {
        return match ($this) {
            self::Matched => 'Đã gán hóa đơn',
            self::Unmatched => 'Chưa gán',
            self::Ignored => 'Bỏ qua',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Matched => 'success',
            self::Unmatched => 'warning',
            self::Ignored => 'gray',
        };
    }
}
