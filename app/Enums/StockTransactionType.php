<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StockTransactionType: string implements HasColor, HasLabel
{
    case Import = 'import';
    case ExportWorkOrder = 'export_work_order';
    case Return = 'return';
    case StocktakeAdjustment = 'stocktake_adjustment';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';

    public function getLabel(): string
    {
        return match ($this) {
            self::Import => 'Nhập kho',
            self::ExportWorkOrder => 'Xuất theo lệnh',
            self::Return => 'Hoàn kho',
            self::StocktakeAdjustment => 'Điều chỉnh kiểm kê',
            self::TransferOut => 'Chuyển kho (xuất)',
            self::TransferIn => 'Chuyển kho (nhập)',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Import, self::TransferIn => 'success',
            self::ExportWorkOrder, self::TransferOut => 'warning',
            self::Return => 'purple',
            self::StocktakeAdjustment => 'gray',
        };
    }
}
