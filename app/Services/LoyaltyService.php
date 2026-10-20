<?php

namespace App\Services;

use App\Enums\LoyaltyTier;
use App\Models\Customer;
use App\Models\LoyaltyTransaction;
use App\Models\WorkOrder;
use DomainException;
use Illuminate\Support\Facades\DB;

class LoyaltyService
{
    /**
     * Số điểm = floor(doanh thu trước VAT / VND per point).
     */
    public function pointsPerVnd(): int
    {
        return max(1000, (int) config('garage.loyalty.vnd_per_point', 10000));
    }

    public function earnFromDelivery(WorkOrder $order, ?int $actorId = null): ?LoyaltyTransaction
    {
        if ($order->delivered_at === null) {
            return null;
        }

        $exists = LoyaltyTransaction::query()
            ->where('work_order_id', $order->id)
            ->where('points', '>', 0)
            ->exists();

        if ($exists) {
            return null;
        }

        $order->loadMissing('vehicle.customer');
        $customer = $order->vehicle?->customer;
        if (! $customer instanceof Customer) {
            return null;
        }

        $revenue = max(0.0, (float) $order->final_amount);
        $points = (int) floor($revenue / $this->pointsPerVnd());

        if ($points < 1) {
            return null;
        }

        return $this->adjust(
            $customer,
            $points,
            'Cộng điểm bàn giao '.$order->code,
            $actorId,
            $order->id,
        );
    }

    public function redeemVndPerPoint(): int
    {
        return max(1, (int) config('garage.loyalty.redeem_vnd_per_point', 1000));
    }

    /**
     * Đổi điểm lấy giảm trừ trên hóa đơn còn nợ.
     */
    public function redeemOnInvoice(\App\Models\Invoice $invoice, int $points, ?int $actorId = null): LoyaltyTransaction
    {
        if ($points < 1) {
            throw new DomainException('Số điểm đổi phải lớn hơn 0.');
        }

        $invoice->loadMissing('workOrder.vehicle.customer');
        $customer = $invoice->workOrder?->vehicle?->customer;

        if (! $customer instanceof Customer) {
            throw new DomainException('Hóa đơn không gắn khách hàng.');
        }

        if ($invoice->isInsurancePayer()) {
            throw new DomainException('Không đổi điểm trên hóa đơn bảo hiểm.');
        }

        $credit = round($points * $this->redeemVndPerPoint(), 2);
        $outstanding = $invoice->outstandingAmount();

        if ($credit - $outstanding > 0.001) {
            throw new DomainException(
                'Điểm đổi vượt số còn nợ (tối đa '
                .max(1, (int) floor($outstanding / $this->redeemVndPerPoint()))
                .' điểm).'
            );
        }

        return DB::transaction(function () use ($invoice, $customer, $points, $credit, $actorId): LoyaltyTransaction {
            /** @var \App\Models\Invoice $locked */
            $locked = \App\Models\Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            $tx = $this->adjust(
                $customer->fresh(),
                -$points,
                'Đổi điểm HĐ '.$locked->invoice_number,
                $actorId,
                $locked->work_order_id,
            );

            $locked->loyalty_points_redeemed = (int) $locked->loyalty_points_redeemed + $points;
            $locked->loyalty_discount_amount = round((float) $locked->loyalty_discount_amount + $credit, 2);
            $locked->notes = trim(($locked->notes ? $locked->notes."\n" : '')
                .'Loyalty: -'.$points.' điểm → giảm '.number_format($credit, 0, ',', '.').' đ');
            $locked->syncPaymentStatus();
            $locked->save();

            return $tx;
        });
    }

    public function adjust(
        Customer $customer,
        int $points,
        string $reason,
        ?int $actorId = null,
        ?int $workOrderId = null,
    ): LoyaltyTransaction {
        if ($points === 0) {
            throw new DomainException('Số điểm phải khác 0.');
        }

        if (! filled($reason)) {
            throw new DomainException('Cần lý do điều chỉnh điểm.');
        }

        return DB::transaction(function () use ($customer, $points, $reason, $actorId, $workOrderId): LoyaltyTransaction {
            /** @var Customer $locked */
            $locked = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();

            $balance = max(0, (int) $locked->loyalty_points + $points);
            if ((int) $locked->loyalty_points + $points < 0) {
                throw new DomainException('Không đủ điểm để trừ (đang có '.(int) $locked->loyalty_points.').');
            }

            $locked->loyalty_points = $balance;
            $locked->loyalty_tier = LoyaltyTier::fromPoints($balance)->value;
            $locked->save();

            return LoyaltyTransaction::query()->create([
                'customer_id' => $locked->id,
                'work_order_id' => $workOrderId,
                'points' => $points,
                'balance_after' => $balance,
                'reason' => $reason,
                'created_by' => $actorId,
            ]);
        });
    }
}
