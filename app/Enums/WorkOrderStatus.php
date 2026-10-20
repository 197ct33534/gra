<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum WorkOrderStatus: string implements HasLabel
{
    case Reception = 'reception';
    case Diagnosing = 'diagnosing';
    case Quoted = 'quoted';
    case CustomerApproved = 'customer_approved';
    case InProgress = 'in_progress';
    case Qc = 'qc';
    case Completed = 'completed';
    case Delivered = 'delivered';

    public function getLabel(): string
    {
        return match ($this) {
            self::Reception => 'Tiếp nhận',
            self::Diagnosing => 'Đang chẩn đoán',
            self::Quoted => 'Đã báo giá',
            self::CustomerApproved => 'Khách đã duyệt',
            self::InProgress => 'Đang sửa',
            self::Qc => 'Kiểm tra chất lượng',
            self::Completed => 'Hoàn thành',
            self::Delivered => 'Đã bàn giao',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Reception, self::Diagnosing => 'gray',
            self::Quoted => 'warning',
            self::CustomerApproved => 'info',
            self::InProgress => 'primary',
            self::Qc => 'info',
            self::Completed, self::Delivered => 'success',
        };
    }

    /**
     * Báo giá đã duyệt: phụ tùng kho đang được giữ chỗ, hoặc đã xuất khi bàn giao.
     */
    public function stockIsCommitted(): bool
    {
        return match ($this) {
            self::CustomerApproved, self::InProgress, self::Qc, self::Completed, self::Delivered => true,
            self::Reception, self::Diagnosing, self::Quoted => false,
        };
    }
}
