<?php

namespace App\Services;

use App\Models\Customer;
use App\Support\VietnameseMoney;
use DomainException;

class FleetService
{
    /**
     * Kiểm tra hạn mức công nợ trước khi lập hóa đơn / duyệt bán chịu.
     */
    public function assertWithinCreditLimit(Customer $customer, float $additionalAmount = 0): void
    {
        $limit = $customer->effectiveCreditLimit();

        if ($limit === null) {
            return;
        }

        $outstanding = $customer->outstandingReceivable();
        $projected = round($outstanding + $additionalAmount, 2);

        if ($projected - $limit > 0.001) {
            throw new DomainException(
                "Vượt hạn mức công nợ của {$customer->name}: đang nợ "
                .VietnameseMoney::format($outstanding)
                .' + phát sinh '.VietnameseMoney::format($additionalAmount)
                .' > hạn mức '.VietnameseMoney::format($limit).'.'
            );
        }
    }
}
