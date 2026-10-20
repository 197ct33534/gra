<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerWalletTransaction;
use App\Models\Invoice;
use App\Support\VietnameseMoney;
use DomainException;
use Illuminate\Support\Facades\DB;

class WalletService
{
    public function deposit(Customer $customer, int|float|string $amount, string $reason, ?int $actorId = null): CustomerWalletTransaction
    {
        $value = round((float) $amount, 2);
        if ($value <= 0) {
            throw new DomainException('Số nạp ví phải lớn hơn 0.');
        }

        return $this->adjust($customer, $value, $reason, $actorId);
    }

    public function applyToInvoice(Invoice $invoice, int|float|string $amount, ?int $actorId = null): CustomerWalletTransaction
    {
        $value = round((float) $amount, 2);
        if ($value <= 0) {
            throw new DomainException('Số trừ ví phải lớn hơn 0.');
        }

        $invoice->loadMissing('workOrder.vehicle.customer');
        $customer = $invoice->workOrder?->vehicle?->customer;
        if (! $customer instanceof Customer) {
            throw new DomainException('Hóa đơn không gắn khách hàng.');
        }

        if ($invoice->isInsurancePayer()) {
            throw new DomainException('Không dùng ví trên hóa đơn bảo hiểm.');
        }

        $outstanding = $invoice->outstandingAmount();
        if ($value - $outstanding > 0.001) {
            throw new DomainException('Số trừ ví vượt công nợ còn lại ('.VietnameseMoney::format($outstanding).').');
        }

        return DB::transaction(function () use ($invoice, $customer, $value, $actorId): CustomerWalletTransaction {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            $tx = $this->adjust(
                $customer->fresh(),
                -$value,
                'Trừ ví thanh toán HĐ '.$locked->invoice_number,
                $actorId,
                $locked->id,
            );

            $paid = round((float) $locked->paid_amount + $value, 2);
            $locked->paid_amount = $paid;
            $locked->syncPaymentStatus();
            $locked->notes = trim(($locked->notes ? $locked->notes."\n" : '')
                .'Ví KH: -'.VietnameseMoney::format($value));
            $locked->save();

            return $tx;
        });
    }

    private function adjust(
        Customer $customer,
        float $amount,
        string $reason,
        ?int $actorId = null,
        ?int $invoiceId = null,
    ): CustomerWalletTransaction {
        if (! filled($reason)) {
            throw new DomainException('Cần lý do giao dịch ví.');
        }

        return DB::transaction(function () use ($customer, $amount, $reason, $actorId, $invoiceId): CustomerWalletTransaction {
            /** @var Customer $locked */
            $locked = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $balance = round((float) $locked->wallet_balance + $amount, 2);

            if ($balance < -0.001) {
                throw new DomainException('Số dư ví không đủ (đang có '.VietnameseMoney::format($locked->wallet_balance).').');
            }

            $locked->wallet_balance = max(0, $balance);
            $locked->save();

            return CustomerWalletTransaction::query()->create([
                'customer_id' => $locked->id,
                'invoice_id' => $invoiceId,
                'amount' => $amount,
                'balance_after' => (float) $locked->wallet_balance,
                'reason' => $reason,
                'created_by' => $actorId,
            ]);
        });
    }
}
