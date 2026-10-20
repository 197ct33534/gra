<?php

namespace App\Services;

use App\Enums\ActivityEvent;
use App\Enums\StocktakeStatus;
use App\Enums\StockTransactionType;
use App\Models\Part;
use App\Models\Stocktake;
use App\Models\StocktakeItem;
use App\Models\StockTransaction;
use App\Support\VietnameseMoney;
use DomainException;
use Illuminate\Support\Facades\DB;

class InventoryService
{
    /**
     * Chỉnh tồn tay (kiểm kê): luôn để lại một dòng thẻ kho và một dòng nhật ký, không sửa số "chui".
     */
    public function adjustStock(Part $part, int $newQuantity, int $userId, ?string $reason = null): ?StockTransaction
    {
        if ($newQuantity < 0) {
            throw new DomainException('Tồn kho không được âm.');
        }

        $transaction = DB::transaction(function () use ($part, $newQuantity, $userId, $reason): ?StockTransaction {
            $locked = Part::query()->whereKey($part->getKey())->lockForUpdate()->firstOrFail();
            $difference = $newQuantity - (int) $locked->stock_quantity;

            if ($difference === 0) {
                return null;
            }

            $note = filled($reason) ? trim((string) $reason) : 'Chỉnh tồn tay trên phiếu phụ tùng';
            $before = (int) $locked->stock_quantity;
            $locked->stock_quantity = $newQuantity;
            $locked->save();

            $transaction = StockTransaction::query()->create([
                'part_id' => $locked->id,
                'transaction_type' => StockTransactionType::StocktakeAdjustment,
                'quantity' => $difference,
                'unit_price' => $locked->cost_price,
                'note' => $note,
                'created_by' => $userId,
            ]);

            ActivityLogger::log(
                ActivityEvent::StockAdjusted,
                $locked,
                "Chỉnh tồn {$locked->name}: {$before} → {$newQuantity} ".$locked->unit.' ('.($difference > 0 ? '+' : '').$difference.'). Lý do: '.$note,
                ['from' => $before, 'to' => $newQuantity, 'difference' => $difference, 'reason' => $note],
            );

            return $transaction;
        });

        $part->refresh();

        return $transaction;
    }

    public function nextStocktakeCode(): string
    {
        return DB::transaction(function (): string {
            $prefix = 'KK-'.now()->format('Ymd').'-';

            $lastCode = Stocktake::query()
                ->where('code', 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByDesc('code')
                ->value('code');

            $sequence = 1;

            if (is_string($lastCode) && preg_match('/^'.preg_quote($prefix, '/').'(\d{4})$/', $lastCode, $matches) === 1) {
                $sequence = ((int) $matches[1]) + 1;
            }

            if ($sequence > 9999) {
                throw new DomainException('Đã hết mã kiểm kê trong ngày.');
            }

            return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Tạo phiếu kiểm kê và snapshot tồn hiện tại của các phụ tùng được chọn (mặc định: tất cả).
     *
     * @param  list<int>|null  $partIds
     */
    public function createStocktake(int $userId, ?string $note = null, ?array $partIds = null): Stocktake
    {
        return DB::transaction(function () use ($userId, $note, $partIds): Stocktake {
            $stocktake = Stocktake::query()->create([
                'code' => $this->nextStocktakeCode(),
                'status' => StocktakeStatus::Draft,
                'note' => filled($note) ? $note : null,
                'created_by' => $userId,
            ]);

            $query = Part::query()->orderBy('sku');

            if ($partIds !== null) {
                $query->whereIn('id', $partIds);
            }

            $parts = $query->lockForUpdate()->get();

            foreach ($parts as $part) {
                StocktakeItem::query()->create([
                    'stocktake_id' => $stocktake->id,
                    'part_id' => $part->id,
                    'system_quantity' => (int) $part->stock_quantity,
                    'counted_quantity' => null,
                    'difference' => null,
                    'unit_cost' => $part->cost_price,
                ]);
            }

            return $stocktake->fresh(['items']) ?? $stocktake;
        });
    }

    public function startCounting(Stocktake $stocktake): Stocktake
    {
        return DB::transaction(function () use ($stocktake): Stocktake {
            /** @var Stocktake $locked */
            $locked = Stocktake::query()->whereKey($stocktake->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== StocktakeStatus::Draft) {
                throw new DomainException('Chỉ phiếu nháp mới bắt đầu đếm được.');
            }

            $locked->status = StocktakeStatus::Counting;
            $locked->counted_at = now();
            $locked->save();

            return $locked;
        });
    }

    public function cancelStocktake(Stocktake $stocktake): Stocktake
    {
        return DB::transaction(function () use ($stocktake): Stocktake {
            /** @var Stocktake $locked */
            $locked = Stocktake::query()->whereKey($stocktake->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === StocktakeStatus::Applied) {
                throw new DomainException('Phiếu đã áp dụng thì không hủy được.');
            }

            if ($locked->status === StocktakeStatus::Canceled) {
                return $locked;
            }

            $locked->status = StocktakeStatus::Canceled;
            $locked->save();

            return $locked;
        });
    }

    /**
     * Áp dụng phiếu kiểm kê: lệch ≠ 0 → thẻ kho stocktake_adjustment.
     */
    public function applyStocktake(Stocktake $stocktake, int $userId): Stocktake
    {
        return DB::transaction(function () use ($stocktake, $userId): Stocktake {
            /** @var Stocktake $locked */
            $locked = Stocktake::query()->whereKey($stocktake->id)->lockForUpdate()->with('items')->firstOrFail();

            if (! $locked->status->canApply()) {
                throw new DomainException('Phiếu ở trạng thái "'.$locked->status->getLabel().'" không áp dụng được.');
            }

            if ($locked->items->isEmpty()) {
                throw new DomainException('Phiếu kiểm kê không có dòng phụ tùng.');
            }

            $uncounted = $locked->items->filter(fn (StocktakeItem $item): bool => $item->counted_quantity === null);

            if ($uncounted->isNotEmpty()) {
                throw new DomainException('Còn '.$uncounted->count().' mã chưa nhập số đếm thực tế.');
            }

            $adjusted = 0;

            foreach ($locked->items as $item) {
                /** @var Part $part */
                $part = Part::query()->whereKey($item->part_id)->lockForUpdate()->firstOrFail();
                $counted = (int) $item->counted_quantity;

                if ($counted < 0) {
                    throw new DomainException('Số đếm không được âm ('.$part->sku.').');
                }

                // Dùng tồn hiện tại lúc apply (có thể lệch snapshot nếu có giao dịch giữa chừng).
                $before = (int) $part->stock_quantity;
                $difference = $counted - $before;

                $item->system_quantity = $before;
                $item->difference = $difference;
                $item->unit_cost = $part->cost_price;
                $item->save();

                if ($difference === 0) {
                    continue;
                }

                $part->stock_quantity = $counted;
                $part->save();

                StockTransaction::query()->create([
                    'part_id' => $part->id,
                    'transaction_type' => StockTransactionType::StocktakeAdjustment,
                    'quantity' => $difference,
                    'unit_price' => $part->cost_price,
                    'reference_id' => $locked->id,
                    'note' => 'Kiểm kê '.$locked->code.($item->note ? ': '.$item->note : ''),
                    'created_by' => $userId,
                ]);

                $adjusted++;
            }

            $locked->status = StocktakeStatus::Applied;
            $locked->applied_by = $userId;
            $locked->applied_at = now();
            $locked->counted_at ??= now();
            $locked->save();

            $locked->load('items');
            $variance = $locked->varianceValue();

            ActivityLogger::log(
                ActivityEvent::StocktakeApplied,
                $locked,
                "Áp dụng kiểm kê {$locked->code}: {$adjusted} mã lệch, giá trị lệch ".VietnameseMoney::format($variance),
                ['adjusted_lines' => $adjusted, 'variance_value' => $variance],
            );

            return $locked->fresh(['items.part']) ?? $locked;
        });
    }
}
