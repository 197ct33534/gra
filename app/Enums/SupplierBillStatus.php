<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SupplierBillStatus: string implements HasColor, HasLabel
{
    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partial';
    case Paid = 'paid';

    public function getLabel(): string
    {
        return match ($this) {
            self::Unpaid => 'Chưa trả',
            self::PartiallyPaid => 'Trả một phần',
            self::Paid => 'Đã trả đủ',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Unpaid => 'danger',
            self::PartiallyPaid => 'warning',
            self::Paid => 'success',
        };
    }

    public static function for(float $total, float $paid): self
    {
        if ($paid + 0.001 >= $total) {
            return self::Paid;
        }

        return $paid > 0 ? self::PartiallyPaid : self::Unpaid;
    }
}
