<?php

namespace App\Services;

use App\Enums\CreditNoteStatus;
use App\Enums\PaymentStatus;
use App\Enums\StockTransactionType;
use App\Enums\WorkOrderItemType;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Part;
use App\Models\StockTransaction;
use App\Models\WorkOrder;
use App\Support\VietnameseMoney;
use DomainException;
use Illuminate\Support\Facades\DB;

class CreditNoteService
{
    public function nextCode(): string
    {
        return DB::transaction(function (): string {
            $prefix = 'CN-'.now()->format('Ymd').'-';
            $last = CreditNote::query()
                ->where('code', 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByDesc('code')
                ->value('code');

            $sequence = 1;
            if (is_string($last) && preg_match('/^'.preg_quote($prefix, '/').'(\d{4})$/', $last, $m) === 1) {
                $sequence = ((int) $m[1]) + 1;
            }

            if ($sequence > 9999) {
                throw new DomainException('Đã hết mã credit note trong ngày.');
            }

            return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
        });
    }

    public function issue(
        Invoice $invoice,
        int|float|string $amount,
        string $reason,
        int $userId,
        bool $restock = false,
        ?string $notes = null,
    ): CreditNote {
        $value = round((float) $amount, 2);

        if ($value <= 0) {
            throw new DomainException('Số tiền hoàn phải lớn hơn 0.');
        }

        if (! filled($reason)) {
            throw new DomainException('Cần nhập lý do hoàn tiền.');
        }

        return DB::transaction(function () use ($invoice, $value, $reason, $userId, $restock, $notes): CreditNote {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->with('workOrder.items')->firstOrFail();

            if ((float) $locked->paid_amount <= 0) {
                throw new DomainException('Hóa đơn chưa thu tiền — không lập credit note.');
            }

            if ($value - (float) $locked->paid_amount > 0.001) {
                throw new DomainException('Số hoàn vượt số đã thu ('.VietnameseMoney::format($locked->paid_amount).').');
            }

            $paid = round((float) $locked->paid_amount - $value, 2);
            $locked->paid_amount = $paid;
            $locked->payment_status = Invoice::statusFor($locked->amountDue(), $paid);
            $locked->notes = trim(($locked->notes ? $locked->notes."\n" : '').'Credit note: -'.VietnameseMoney::format($value).' — '.$reason);
            $locked->save();

            if ($restock) {
                $this->restockFromWorkOrder($locked->workOrder, $userId);
            }

            return CreditNote::query()->create([
                'code' => $this->nextCode(),
                'invoice_id' => $locked->id,
                'work_order_id' => $locked->work_order_id,
                'amount' => $value,
                'reason' => $reason,
                'restock' => $restock,
                'status' => CreditNoteStatus::Issued,
                'created_by' => $userId,
                'issued_at' => now(),
                'notes' => filled($notes) ? $notes : null,
            ]);
        });
    }

    private function restockFromWorkOrder(?WorkOrder $order, int $userId): void
    {
        if (! $order instanceof WorkOrder) {
            return;
        }

        foreach ($order->items as $item) {
            if ($item->item_type !== WorkOrderItemType::Part || ! $item->is_approved || ! $item->isStockPart()) {
                continue;
            }

            $part = Part::query()->whereKey($item->item_id)->lockForUpdate()->first();
            if (! $part instanceof Part) {
                continue;
            }

            $qty = (int) round((float) $item->quantity);
            if ($qty < 1) {
                continue;
            }

            $part->stock_quantity += $qty;
            $part->save();

            StockTransaction::query()->create([
                'part_id' => $part->id,
                'transaction_type' => StockTransactionType::Return,
                'quantity' => $qty,
                'unit_price' => $item->unit_price,
                'reference_id' => $order->id,
                'note' => 'Hoàn kho theo credit note',
                'created_by' => $userId,
            ]);
        }
    }
}
