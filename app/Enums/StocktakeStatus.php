<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StocktakeStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Counting = 'counting';
    case Applied = 'applied';
    case Canceled = 'canceled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Nháp',
            self::Counting => 'Đang đếm',
            self::Applied => 'Đã áp dụng',
            self::Canceled => 'Đã hủy',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Counting => 'warning',
            self::Applied => 'success',
            self::Canceled => 'danger',
        };
    }

    public function canEditCounts(): bool
    {
        return match ($this) {
            self::Draft, self::Counting => true,
            self::Applied, self::Canceled => false,
        };
    }

    public function canApply(): bool
    {
        return $this === self::Counting || $this === self::Draft;
    }
}
