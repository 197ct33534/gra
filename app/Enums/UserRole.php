<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum UserRole: string implements HasLabel
{
    case Admin = 'admin';
    case ServiceAdvisor = 'service_advisor';
    case Technician = 'technician';

    public function getLabel(): string
    {
        return match ($this) {
            self::Admin => 'Quản trị',
            self::ServiceAdvisor => 'Cố vấn dịch vụ',
            self::Technician => 'Kỹ thuật viên',
        };
    }
}
