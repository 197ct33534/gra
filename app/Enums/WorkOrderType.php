<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum WorkOrderType: string implements HasLabel
{
    case Standard = 'standard';
    case Insurance = 'insurance';
    case WarrantyRedo = 'warranty_redo';

    public function getLabel(): string
    {
        return match ($this) {
            self::Standard => 'Sửa chữa thường',
            self::Insurance => 'Bảo hiểm',
            self::WarrantyRedo => 'Bảo hành làm lại',
        };
    }
}
