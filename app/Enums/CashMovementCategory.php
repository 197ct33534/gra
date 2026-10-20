<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CashMovementCategory: string implements HasLabel
{
    case InvoicePayment = 'invoice_payment';
    case PettyExpense = 'petty_expense';
    case SupplierPayment = 'supplier_payment';
    case Adjustment = 'adjustment';

    public function getLabel(): string
    {
        return match ($this) {
            self::InvoicePayment => 'Thu hóa đơn',
            self::PettyExpense => 'Chi phí nhỏ',
            self::SupplierPayment => 'Trả NCC',
            self::Adjustment => 'Điều chỉnh',
        };
    }
}
