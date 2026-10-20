<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum LoyaltyTier: string implements HasColor, HasLabel
{
    case Member = 'member';
    case Silver = 'silver';
    case Gold = 'gold';
    case Platinum = 'platinum';

    public function getLabel(): string
    {
        return match ($this) {
            self::Member => 'Thành viên',
            self::Silver => 'Bạc',
            self::Gold => 'Vàng',
            self::Platinum => 'Bạch kim',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Member => 'gray',
            self::Silver => 'gray',
            self::Gold => 'warning',
            self::Platinum => 'info',
        };
    }

    public static function fromPoints(int $points): self
    {
        return match (true) {
            $points >= 5000 => self::Platinum,
            $points >= 2000 => self::Gold,
            $points >= 500 => self::Silver,
            default => self::Member,
        };
    }
}
