<?php

namespace App\Services;

use App\Enums\ActivityEvent;
use App\Enums\StockTransactionType;
use App\Models\Part;
use App\Models\StockTransaction;
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
}
