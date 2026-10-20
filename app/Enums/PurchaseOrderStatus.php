<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PurchaseOrderStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Ordered = 'ordered';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case Canceled = 'canceled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Nháp',
            self::Ordered => 'Đã đặt',
            self::PartiallyReceived => 'Nhập một phần',
            self::Received => 'Đã nhập đủ',
            self::Canceled => 'Đã hủy',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Ordered => 'info',
            self::PartiallyReceived => 'warning',
            self::Received => 'success',
            self::Canceled => 'danger',
        };
    }

    public function canReceive(): bool
    {
        return match ($this) {
            self::Ordered, self::PartiallyReceived => true,
            self::Draft, self::Received, self::Canceled => false,
        };
    }

    public function canEditItems(): bool
    {
        return $this === self::Draft;
    }
}
