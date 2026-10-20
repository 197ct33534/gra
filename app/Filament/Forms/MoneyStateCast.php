<?php

namespace App\Filament\Forms;

use App\Support\VietnameseMoney;
use Filament\Schemas\Components\StateCasts\Contracts\StateCast;

/**
 * Ô tiền hiển thị "1.500.000" nhưng lưu số nguyên 1500000.
 */
class MoneyStateCast implements StateCast
{
    public function get(mixed $state): ?int
    {
        return VietnameseMoney::parse($state);
    }

    public function set(mixed $state): ?int
    {
        return VietnameseMoney::parse($state);
    }
}
