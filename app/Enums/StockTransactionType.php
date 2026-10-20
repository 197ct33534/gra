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

    public function getLabel(): string
    {
        return match ($this) {
            self::Import => 'Nhập kho',
            self::ExportWorkOrder => 'Xuất theo lệnh',
            self::Return => 'Hoàn kho',
            self::StocktakeAdjustment => 'Điều chỉnh kiểm kê',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Import => 'success',
            self::ExportWorkOrder => 'warning',
            self::Return => 'purple',
            self::StocktakeAdjustment => 'gray',
        };
    }
}
