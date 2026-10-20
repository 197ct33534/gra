<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CashMovementDirection: string implements HasLabel
{
    case In = 'in';
    case Out = 'out';

    public function getLabel(): string
    {
        return match ($this) {
            self::In => 'Thu vào',
            self::Out => 'Chi ra',
        };
    }
}
