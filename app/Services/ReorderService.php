<?php

namespace App\Services;

use App\Enums\PurchaseOrderStatus;
use App\Models\Part;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReorderService
{
    /**
     * @return Collection<int, array{part: Part, available: int, suggest_qty: int, gap: int}>
     */
    public function suggestions(): Collection
    {
        return Part::query()
            ->with('category')
            ->orderBy('name')
            ->get()
            ->map(function (Part $part): ?array {
                $available = $part->availableQuantity();
                $min = (int) $part->min_stock_alert;
                if (! $part->isLowStock()) {
                    return null;
                }

                $gap = max(0, $min - $available);
                $suggest = max($min * 2 - $available, $min, 1);

                return [
                    'part' => $part,
                    'available' => $available,
                    'suggest_qty' => $suggest,
                    'gap' => $gap,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @param  list<array{part_id: int, quantity: int|float}>  $lines
     */
    public function createDraftPurchaseOrder(Supplier $supplier, array $lines, int $userId, ?string $notes = null): PurchaseOrder
    {
        if ($lines === []) {
            throw new DomainException('Chọn ít nhất một phụ tùng để tạo PO.');
        }

        return DB::transaction(function () use ($supplier, $lines, $userId, $notes): PurchaseOrder {
            $order = PurchaseOrder::query()->create([
                'code' => app(PurchasingService::class)->nextPurchaseOrderCode(),
                'supplier_id' => $supplier->id,
                'status' => PurchaseOrderStatus::Draft,
                'notes' => $notes ?? 'Tạo từ đề xuất nhập hàng (tồn thấp)',
                'created_by' => $userId,
                'subtotal' => 0,
            ]);

            $subtotal = 0.0;
            foreach ($lines as $line) {
                $part = Part::query()->whereKey((int) $line['part_id'])->first();
                if (! $part instanceof Part) {
                    continue;
                }
                $qty = max(1, (float) $line['quantity']);
                $unitCost = (float) $part->cost_price;
                PurchaseOrderItem::query()->create([
                    'purchase_order_id' => $order->id,
                    'part_id' => $part->id,
                    'quantity_ordered' => $qty,
                    'quantity_received' => 0,
                    'unit_cost' => $unitCost,
                ]);
                $subtotal += round($qty * $unitCost, 2);
            }

            if ($subtotal <= 0) {
                throw new DomainException('Không tạo được dòng PO hợp lệ.');
            }

            $order->subtotal = round($subtotal, 2);
            $order->save();

            return $order->fresh(['items', 'supplier']) ?? $order;
        });
    }
}
