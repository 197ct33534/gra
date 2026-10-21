<?php

namespace App\Services;

use App\Enums\StockTransactionType;
use App\Enums\StockTransferStatus;
use App\Models\Branch;
use App\Models\Part;
use App\Models\StockTransaction;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use DomainException;
use Illuminate\Support\Facades\DB;

class StockTransferService
{
    public function nextCode(): string
    {
        return DB::transaction(function (): string {
            $prefix = 'CK-'.now()->format('Ymd').'-';
            $last = StockTransfer::query()
                ->where('code', 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByDesc('code')
                ->value('code');

            $sequence = 1;
            if (is_string($last) && preg_match('/^'.preg_quote($prefix, '/').'(\d{4})$/', $last, $m) === 1) {
                $sequence = ((int) $m[1]) + 1;
            }

            return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
        });
    }

    /**
     * @param  list<array{part_id: int, quantity: int}>  $lines
     */
    public function createDraft(Branch $from, Branch $to, array $lines, int $userId, ?string $notes = null): StockTransfer
    {
        if ($from->id === $to->id) {
            throw new DomainException('Chi nhánh nguồn và đích phải khác nhau.');
        }

        if ($lines === []) {
            throw new DomainException('Chọn ít nhất một phụ tùng để chuyển.');
        }

        return DB::transaction(function () use ($from, $to, $lines, $userId, $notes): StockTransfer {
            $transfer = StockTransfer::query()->create([
                'code' => $this->nextCode(),
                'from_branch_id' => $from->id,
                'to_branch_id' => $to->id,
                'status' => StockTransferStatus::Draft,
                'notes' => $notes,
                'created_by' => $userId,
            ]);

            foreach ($lines as $line) {
                $qty = max(1, (int) $line['quantity']);
                $part = Part::query()->whereKey((int) $line['part_id'])->first();
                if (! $part instanceof Part) {
                    continue;
                }
                if ((int) $part->branch_id !== (int) $from->id) {
                    throw new DomainException('PT '.$part->sku.' không thuộc chi nhánh nguồn.');
                }
                if ($part->availableQuantity() < $qty) {
                    throw new DomainException('PT '.$part->sku.' không đủ tồn khả dụng (còn '.$part->availableQuantity().').');
                }

                StockTransferItem::query()->create([
                    'stock_transfer_id' => $transfer->id,
                    'part_id' => $part->id,
                    'quantity' => $qty,
                ]);
            }

            if ($transfer->items()->count() === 0) {
                throw new DomainException('Không có dòng chuyển hợp lệ.');
            }

            return $transfer->fresh(['items', 'fromBranch', 'toBranch']) ?? $transfer;
        });
    }

    public function complete(StockTransfer $transfer, int $userId): StockTransfer
    {
        return DB::transaction(function () use ($transfer, $userId): StockTransfer {
            /** @var StockTransfer $locked */
            $locked = StockTransfer::query()->whereKey($transfer->id)->lockForUpdate()->with(['items.part', 'fromBranch', 'toBranch'])->firstOrFail();

            if ($locked->status !== StockTransferStatus::Draft) {
                throw new DomainException('Chỉ phiếu nháp mới được hoàn tất chuyển kho.');
            }

            foreach ($locked->items as $item) {
                /** @var Part $source */
                $source = Part::query()->whereKey($item->part_id)->lockForUpdate()->firstOrFail();
                $qty = (int) $item->quantity;

                if ((int) $source->branch_id !== (int) $locked->from_branch_id) {
                    throw new DomainException('PT '.$source->sku.' không còn thuộc CN nguồn.');
                }
                if ($source->availableQuantity() < $qty) {
                    throw new DomainException('PT '.$source->sku.' không đủ tồn khả dụng.');
                }

                $source->stock_quantity = (int) $source->stock_quantity - $qty;
                $source->save();

                StockTransaction::query()->create([
                    'part_id' => $source->id,
                    'transaction_type' => StockTransactionType::TransferOut,
                    'quantity' => -$qty,
                    'unit_price' => $source->cost_price,
                    'reference_id' => $locked->id,
                    'note' => 'Chuyển kho '.$locked->code.' → '.$locked->toBranch?->code,
                    'created_by' => $userId,
                ]);

                $dest = Part::query()
                    ->where('branch_id', $locked->to_branch_id)
                    ->where('sku', $source->sku)
                    ->lockForUpdate()
                    ->first();

                if (! $dest instanceof Part) {
                    $dest = Part::query()->create([
                        'branch_id' => $locked->to_branch_id,
                        'category_id' => $source->category_id,
                        'sku' => $source->sku,
                        'oem_code' => $source->oem_code,
                        'name' => $source->name,
                        'unit' => $source->unit,
                        'cost_price' => $source->cost_price,
                        'selling_price' => $source->selling_price,
                        'stock_quantity' => 0,
                        'reserved_quantity' => 0,
                        'min_stock_alert' => $source->min_stock_alert,
                        'shelf_location' => $source->shelf_location,
                        'compatibility' => $source->compatibility,
                        'warranty_months' => $source->warranty_months,
                        'warranty_km' => $source->warranty_km,
                    ]);
                    $dest = Part::query()->whereKey($dest->id)->lockForUpdate()->firstOrFail();
                }

                $dest->stock_quantity = (int) $dest->stock_quantity + $qty;
                $dest->save();

                StockTransaction::query()->create([
                    'part_id' => $dest->id,
                    'transaction_type' => StockTransactionType::TransferIn,
                    'quantity' => $qty,
                    'unit_price' => $dest->cost_price,
                    'reference_id' => $locked->id,
                    'note' => 'Nhận chuyển kho '.$locked->code.' từ '.$locked->fromBranch?->code,
                    'created_by' => $userId,
                ]);
            }

            $locked->status = StockTransferStatus::Completed;
            $locked->completed_by = $userId;
            $locked->completed_at = now();
            $locked->save();

            return $locked->fresh(['items.part', 'fromBranch', 'toBranch']) ?? $locked;
        });
    }
}
