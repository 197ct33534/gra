<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum WorkOrderItemSource: string implements HasColor, HasLabel
{
    case Stock = 'stock';
    case DirectPurchase = 'direct_purchase';
    case Subcontract = 'subcontract';
    case CustomerSupplied = 'customer_supplied';

    public const CUSTOMER_SUPPLIED_WARRANTY = 'Khách tự mang đồ - Gara không bảo hành phụ tùng';

    public function getLabel(): string
    {
        return match ($this) {
            self::Stock => 'Kho',
            self::DirectPurchase => 'Mua ngoài',
            self::Subcontract => 'Gia công ngoài',
            self::CustomerSupplied => 'Khách mang',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Stock => 'gray',
            self::DirectPurchase => 'warning',
            self::Subcontract => 'info',
            self::CustomerSupplied => 'purple',
        };
    }
}
