<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EInvoiceStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Issued = 'issued';
    case Canceled = 'canceled';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Đang xử lý',
            self::Issued => 'Đã xuất',
            self::Canceled => 'Đã hủy',
            self::Failed => 'Lỗi',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Issued => 'success',
            self::Canceled => 'gray',
            self::Failed => 'danger',
        };
    }
}
