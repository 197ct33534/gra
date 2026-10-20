<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum InsuranceClaimStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Assessing = 'assessing';
    case Approved = 'approved';
    case Paid = 'paid';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => 'Nháp hồ sơ',
            self::Submitted => 'Đã gửi BH',
            self::Assessing => 'Đang giám định',
            self::Approved => 'BH duyệt chi',
            self::Paid => 'BH đã trả',
            self::Rejected => 'BH từ chối',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Submitted, self::Assessing => 'info',
            self::Approved => 'warning',
            self::Paid => 'success',
            self::Rejected => 'danger',
        };
    }
}
