<?php

namespace App\Filament\Forms;

use App\Support\VietnameseMoney;
use Filament\Forms\Components\TextInput;
use Filament\Support\RawJs;

final class MoneyInput
{
    /**
     * Ô nhập tiền Việt: gõ 1500000 thấy ngay "1.500.000 đ".
     */
    public static function make(string $name): TextInput
    {
        return TextInput::make($name)
            ->mask(RawJs::make('$money($input, \',\', \'.\', 0)'))
            ->stateCast(new MoneyStateCast)
            ->mutateStateForValidationUsing(fn (mixed $state): ?int => VietnameseMoney::parse($state))
            ->rules(['nullable', 'integer', 'min:0'])
            ->inputMode('numeric')
            ->suffix('đ');
    }
}
