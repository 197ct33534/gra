<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum WarrantyClaimStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Done = 'done';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'Mở',
            self::Approved => 'Duyệt',
            self::Rejected => 'Từ chối',
            self::Done => 'Xong',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Open => 'info',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Done => 'gray',
        };
    }
}
