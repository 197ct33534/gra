<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum SupplierPaymentMethod: string implements HasLabel
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cash => 'Tiền mặt',
            self::BankTransfer => 'Chuyển khoản',
        };
    }
}
