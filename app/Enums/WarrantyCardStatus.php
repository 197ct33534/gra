<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum WarrantyCardStatus: string implements HasColor, HasLabel
{
    case Active = 'active';
    case Claimed = 'claimed';
    case Expired = 'expired';
    case Void = 'void';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Còn hiệu lực',
            self::Claimed => 'Đã claim',
            self::Expired => 'Hết hạn',
            self::Void => 'Hủy',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Active => 'success',
            self::Claimed => 'warning',
            self::Expired, self::Void => 'gray',
        };
    }
}
