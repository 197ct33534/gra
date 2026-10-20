<?php

namespace App\Services;

use App\Models\GoodsReceiptItem;
use App\Models\Part;
use Illuminate\Support\Collection;

class PartCostHistoryService
{
    /**
     * Lịch sử giá vốn nhập theo phiếu nhập kho.
     *
     * @return Collection<int, array{date: string, supplier: string, receipt: string, qty: float, unit_cost: float, delta: ?float}>
     */
    public function history(Part $part, int $limit = 50): Collection
    {
        $items = GoodsReceiptItem::query()
            ->with(['goodsReceipt.supplier'])
            ->where('part_id', $part->id)
            ->whereHas('goodsReceipt')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->sortBy(fn (GoodsReceiptItem $item): int => $item->goodsReceipt?->received_at?->timestamp
                ?? $item->created_at?->timestamp
                ?? 0)
            ->values();

        $previous = null;
        $rows = [];

        foreach ($items as $item) {
            $cost = (float) $item->unit_cost;
            $delta = $previous === null ? null : round($cost - $previous, 2);
            $rows[] = [
                'date' => ($item->goodsReceipt?->received_at ?? $item->created_at)?->timezone((string) config('garage.timezone'))->format('d/m/Y') ?? '—',
                'supplier' => $item->goodsReceipt?->supplier?->name ?? '—',
                'receipt' => $item->goodsReceipt?->code ?? '—',
                'qty' => (float) $item->quantity,
                'unit_cost' => $cost,
                'delta' => $delta,
            ];
            $previous = $cost;
        }

        return collect(array_reverse($rows));
    }

    /**
     * @return array{current: float, last: ?float, avg: ?float, min: ?float, max: ?float, samples: int, trend: string}
     */
    public function summary(Part $part): array
    {
        $history = $this->history($part, 20);
        $costs = $history->pluck('unit_cost')->map(fn ($v): float => (float) $v)->values();
        $samples = $costs->count();
        $last = $samples > 0 ? (float) $costs->first() : null;
        $avg = $samples > 0 ? round((float) $costs->avg(), 2) : null;
        $min = $samples > 0 ? (float) $costs->min() : null;
        $max = $samples > 0 ? (float) $costs->max() : null;

        $trend = 'stable';
        if ($samples >= 2) {
            $newest = (float) $costs->first();
            $olderAvg = (float) $costs->skip(1)->take(3)->avg();
            $diff = $newest - $olderAvg;
            if ($diff > 0.001) {
                $trend = 'up';
            } elseif ($diff < -0.001) {
                $trend = 'down';
            }
        }

        return [
            'current' => (float) $part->cost_price,
            'last' => $last,
            'avg' => $avg,
            'min' => $min,
            'max' => $max,
            'samples' => $samples,
            'trend' => $trend,
        ];
    }
}
