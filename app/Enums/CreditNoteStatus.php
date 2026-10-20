<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CreditNoteStatus: string implements HasColor, HasLabel
{
    case Issued = 'issued';
    case Void = 'void';

    public function getLabel(): string
    {
        return match ($this) {
            self::Issued => 'Đã lập',
            self::Void => 'Đã hủy',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Issued => 'success',
            self::Void => 'gray',
        };
    }
}
