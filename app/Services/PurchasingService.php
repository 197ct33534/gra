<?php

namespace App\Services;

use App\Enums\ActivityEvent;
use App\Enums\PurchaseOrderStatus;
use App\Enums\StockTransactionType;
use App\Enums\SupplierBillStatus;
use App\Enums\SupplierPaymentMethod;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Part;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockTransaction;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierPayment;
use App\Support\VietnameseMoney;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Chuỗi mua hàng: PO → phiếu nhập → cập nhật tồn/giá vốn → công nợ NCC.
 */
class PurchasingService
{
    public function nextPurchaseOrderCode(): string
    {
        return $this->nextDailyCode(PurchaseOrder::class, 'PO-');
    }

    public function nextGoodsReceiptCode(): string
    {
        return $this->nextDailyCode(GoodsReceipt::class, 'GR-');
    }

    public function markOrdered(PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($order): PurchaseOrder {
            /** @var PurchaseOrder $locked */
            $locked = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->with('items')->firstOrFail();

            if ($locked->status !== PurchaseOrderStatus::Draft) {
                throw new DomainException('Chỉ đơn nháp mới được chuyển sang Đã đặt.');
            }

            if ($locked->items->isEmpty()) {
                throw new DomainException('Đơn mua phải có ít nhất một dòng phụ tùng.');
            }

            $locked->status = PurchaseOrderStatus::Ordered;
            $locked->ordered_at = now();
            $locked->subtotal = round((float) $locked->items->sum(fn (PurchaseOrderItem $item): float => $item->lineTotal()), 2);
            $locked->save();

            return $locked->fresh(['items', 'supplier']) ?? $locked;
        });
    }

    public function cancelPurchaseOrder(PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($order): PurchaseOrder {
            /** @var PurchaseOrder $locked */
            $locked = PurchaseOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [PurchaseOrderStatus::Received, PurchaseOrderStatus::PartiallyReceived], true)) {
                throw new DomainException('Đơn đã có nhập kho thì không hủy được. Hãy nhập điều chỉnh nếu cần.');
            }

            if ($locked->status === PurchaseOrderStatus::Canceled) {
                return $locked;
            }

            $locked->status = PurchaseOrderStatus::Canceled;
            $locked->save();

            return $locked;
        });
    }

    /**
     * @param  list<array{part_id:int, quantity:float|int|string, unit_cost:float|int|string, purchase_order_item_id?:int|null}>  $lines
     */
    public function receiveGoods(
        Supplier $supplier,
        array $lines,
        int $userId,
        ?PurchaseOrder $purchaseOrder = null,
        ?string $invoiceNo = null,
        ?Carbon $invoiceDate = null,
        ?string $notes = null,
        ?int $vatRate = null,
        ?Carbon $receivedAt = null,
    ): GoodsReceipt {
        if ($lines === []) {
            throw new DomainException('Phiếu nhập phải có ít nhất một dòng.');
        }

        $vatRate ??= 8;
        $receivedAt ??= now();

        return DB::transaction(function () use (
            $supplier,
            $lines,
            $userId,
            $purchaseOrder,
            $invoiceNo,
            $invoiceDate,
            $notes,
            $vatRate,
            $receivedAt,
        ): GoodsReceipt {
            $po = null;

            if ($purchaseOrder instanceof PurchaseOrder) {
                /** @var PurchaseOrder $po */
                $po = PurchaseOrder::query()->whereKey($purchaseOrder->id)->lockForUpdate()->with('items')->firstOrFail();

                if (! $po->status->canReceive()) {
                    throw new DomainException('Đơn mua ở trạng thái "'.$po->status->getLabel().'" không nhận hàng được.');
                }

                if ((int) $po->supplier_id !== (int) $supplier->id) {
                    throw new DomainException('NCC trên phiếu nhập phải trùng NCC của đơn mua.');
                }
            }

            $receipt = GoodsReceipt::query()->create([
                'code' => $this->reserveDailyCode(GoodsReceipt::class, 'GR-'),
                'supplier_id' => $supplier->id,
                'purchase_order_id' => $po?->id,
                'received_at' => $receivedAt,
                'invoice_no_supplier' => filled($invoiceNo) ? $invoiceNo : null,
                'invoice_date' => $invoiceDate,
                'vat_rate' => $vatRate,
                'notes' => filled($notes) ? $notes : null,
                'created_by' => $userId,
            ]);

            $amountBeforeVat = 0.0;

            foreach ($lines as $line) {
                $partId = (int) $line['part_id'];
                $quantity = round((float) $line['quantity'], 2);
                $unitCost = round((float) $line['unit_cost'], 2);
                $poItemId = isset($line['purchase_order_item_id']) ? (int) $line['purchase_order_item_id'] : null;

                if ($quantity <= 0) {
                    throw new DomainException('Số lượng nhập phải lớn hơn 0.');
                }

                if ($unitCost < 0) {
                    throw new DomainException('Giá nhập không được âm.');
                }

                $poItem = null;

                if ($poItemId) {
                    if (! $po instanceof PurchaseOrder) {
                        throw new DomainException('Dòng nhập gắn PO item nhưng không có đơn mua.');
                    }

                    $poItem = $po->items->firstWhere('id', $poItemId);

                    if (! $poItem instanceof PurchaseOrderItem) {
                        throw new DomainException('Dòng đơn mua #'.$poItemId.' không thuộc đơn này.');
                    }

                    if ((int) $poItem->part_id !== $partId) {
                        throw new DomainException('Phụ tùng nhập không khớp dòng đơn mua.');
                    }

                    $remaining = $poItem->remainingQuantity();

                    if ($quantity - $remaining > 0.001) {
                        throw new DomainException(
                            'Nhập vượt số còn lại trên PO (còn '.$remaining.').'
                        );
                    }
                }

                $lineTotal = round($quantity * $unitCost, 2);
                $amountBeforeVat += $lineTotal;

                $receiptItem = GoodsReceiptItem::query()->create([
                    'goods_receipt_id' => $receipt->id,
                    'part_id' => $partId,
                    'purchase_order_item_id' => $poItem?->id,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'line_total' => $lineTotal,
                ]);

                $this->applyStockImport($partId, $quantity, $unitCost, $receipt->id, $userId);

                if ($poItem instanceof PurchaseOrderItem) {
                    $poItem->quantity_received = round((float) $poItem->quantity_received + $quantity, 2);
                    $poItem->save();
                }

                unset($receiptItem);
            }

            $vatAmount = round($amountBeforeVat * $vatRate / 100, 2);
            $total = round($amountBeforeVat + $vatAmount, 2);

            $receipt->update([
                'amount_before_vat' => $amountBeforeVat,
                'vat_amount' => $vatAmount,
                'total_amount' => $total,
            ]);

            if ($po instanceof PurchaseOrder) {
                $po->refreshReceiveStatus();
            }

            $this->createBillFromReceipt($receipt->fresh(['supplier']) ?? $receipt);

            ActivityLogger::log(
                ActivityEvent::GoodsReceived,
                $receipt,
                "Nhập kho {$receipt->code}: ".VietnameseMoney::format($total).' (NCC '.$supplier->name.')',
                [
                    'goods_receipt_id' => $receipt->id,
                    'supplier_id' => $supplier->id,
                    'purchase_order_id' => $po?->id,
                    'total_amount' => $total,
                ],
            );

            return $receipt->fresh(['items', 'supplier', 'bill']) ?? $receipt;
        });
    }

    /**
     * Nhận hàng theo đúng phần còn lại của PO (mặc định đủ số còn lại, giá theo PO).
     *
     * @param  array<int, array{quantity?:float|int|string, unit_cost?:float|int|string}>  $overrides  key = purchase_order_item_id
     */
    public function receiveFromPurchaseOrder(
        PurchaseOrder $order,
        int $userId,
        array $overrides = [],
        ?string $invoiceNo = null,
        ?Carbon $invoiceDate = null,
        ?string $notes = null,
        ?int $vatRate = null,
    ): GoodsReceipt {
        $order->loadMissing(['items', 'supplier']);

        if (! $order->supplier instanceof Supplier) {
            throw new DomainException('Đơn mua chưa có nhà cung cấp.');
        }

        $lines = [];

        foreach ($order->items as $item) {
            $remaining = $item->remainingQuantity();
            $override = $overrides[$item->id] ?? [];
            $qty = array_key_exists('quantity', $override)
                ? round((float) $override['quantity'], 2)
                : $remaining;

            if ($qty <= 0) {
                continue;
            }

            $lines[] = [
                'part_id' => (int) $item->part_id,
                'quantity' => $qty,
                'unit_cost' => array_key_exists('unit_cost', $override)
                    ? round((float) $override['unit_cost'], 2)
                    : (float) $item->unit_cost,
                'purchase_order_item_id' => $item->id,
            ];
        }

        return $this->receiveGoods(
            $order->supplier,
            $lines,
            $userId,
            $order,
            $invoiceNo,
            $invoiceDate,
            $notes,
            $vatRate,
        );
    }

    public function recordSupplierPayment(
        SupplierBill $bill,
        int|float|string $amount,
        SupplierPaymentMethod $method,
        int $userId,
        ?string $reference = null,
        ?string $notes = null,
        ?Carbon $paidAt = null,
    ): SupplierPayment {
        $pay = round((float) $amount, 2);

        if ($pay <= 0) {
            throw new DomainException('Số tiền thanh toán phải lớn hơn 0.');
        }

        return DB::transaction(function () use ($bill, $pay, $method, $userId, $reference, $notes, $paidAt): SupplierPayment {
            /** @var SupplierBill $locked */
            $locked = SupplierBill::query()->whereKey($bill->id)->lockForUpdate()->firstOrFail();
            $outstanding = $locked->outstandingAmount();

            if ($pay - $outstanding > 0.001) {
                throw new DomainException('Số tiền vượt công nợ còn lại ('.VietnameseMoney::format($outstanding).').');
            }

            $payment = SupplierPayment::query()->create([
                'supplier_bill_id' => $locked->id,
                'amount' => $pay,
                'method' => $method,
                'paid_at' => $paidAt ?? now(),
                'reference' => filled($reference) ? $reference : null,
                'created_by' => $userId,
                'notes' => filled($notes) ? $notes : null,
            ]);

            $locked->paid_amount = round((float) $locked->paid_amount + $pay, 2);
            $locked->status = SupplierBillStatus::for((float) $locked->total_amount, (float) $locked->paid_amount);
            $locked->save();

            return $payment;
        });
    }

    private function createBillFromReceipt(GoodsReceipt $receipt): SupplierBill
    {
        $supplier = $receipt->supplier;

        if (! $supplier instanceof Supplier) {
            throw new DomainException('Phiếu nhập thiếu nhà cung cấp.');
        }

        $dueDate = null;
        $terms = (int) $supplier->payment_terms_days;
        $billDate = $receipt->invoice_date?->toDateString()
            ?? $receipt->received_at?->toDateString()
            ?? now()->toDateString();

        if ($terms > 0) {
            $dueDate = Carbon::parse($billDate)->addDays($terms)->toDateString();
        }

        return SupplierBill::query()->create([
            'supplier_id' => $supplier->id,
            'goods_receipt_id' => $receipt->id,
            'bill_number' => $receipt->invoice_no_supplier,
            'bill_date' => $billDate,
            'due_date' => $dueDate,
            'vat_rate' => $receipt->vat_rate,
            'amount_before_vat' => $receipt->amount_before_vat,
            'vat_amount' => $receipt->vat_amount,
            'total_amount' => $receipt->total_amount,
            'paid_amount' => 0,
            'status' => SupplierBillStatus::Unpaid,
            'notes' => 'Tự tạo từ phiếu nhập '.$receipt->code,
        ]);
    }

    private function applyStockImport(int $partId, float $quantity, float $unitCost, int $receiptId, int $userId): void
    {
        /** @var Part $part */
        $part = Part::query()->whereKey($partId)->lockForUpdate()->firstOrFail();

        // Tồn `parts.stock_quantity` đang là integer (đồng bộ với xuất kho RO).
        $qtyInt = (int) round($quantity);

        if ($qtyInt < 1) {
            throw new DomainException('Số lượng nhập quy đổi ra tồn kho phải ≥ 1 (làm tròn).');
        }

        $beforeQty = (int) $part->stock_quantity;
        $beforeCost = (float) $part->cost_price;
        $method = (string) config('garage.purchasing.cost_method', 'weighted_average');

        if ($method === 'last_purchase') {
            $part->cost_price = $unitCost;
        } else {
            $newStock = $beforeQty + $qtyInt;
            if ($newStock > 0) {
                $part->cost_price = round((($beforeQty * $beforeCost) + ($qtyInt * $unitCost)) / $newStock, 2);
            } else {
                $part->cost_price = $unitCost;
            }
        }

        $part->stock_quantity = $beforeQty + $qtyInt;
        $part->save();

        StockTransaction::query()->create([
            'part_id' => $part->id,
            'transaction_type' => StockTransactionType::Import,
            'quantity' => $qtyInt,
            'unit_price' => $unitCost,
            'reference_id' => $receiptId,
            'note' => 'Nhập từ phiếu GR#'.$receiptId,
            'created_by' => $userId,
        ]);
    }

    /**
     * @param  class-string<PurchaseOrder|GoodsReceipt>  $modelClass
     */
    private function nextDailyCode(string $modelClass, string $prefixStem): string
    {
        return DB::transaction(fn (): string => $this->reserveDailyCode($modelClass, $prefixStem));
    }

    /**
     * @param  class-string<PurchaseOrder|GoodsReceipt>  $modelClass
     */
    private function reserveDailyCode(string $modelClass, string $prefixStem): string
    {
        $prefix = $prefixStem.now()->format('Ymd').'-';

        $lastCode = $modelClass::query()
            ->where('code', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('code')
            ->value('code');

        $sequence = 1;

        if (is_string($lastCode) && preg_match('/^'.preg_quote($prefix, '/').'(\d{4})$/', $lastCode, $matches) === 1) {
            $sequence = ((int) $matches[1]) + 1;
        }

        if ($sequence > 9999) {
            throw new DomainException('Đã hết mã '.$prefixStem.' trong ngày.');
        }

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
