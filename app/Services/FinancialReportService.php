<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\WorkOrderStatus;
use App\Models\Invoice;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FinancialReportService
{
    /**
     * Aging AR theo tuổi nợ (ngày hóa đơn tạo → hôm nay).
     *
     * @return array{
     *     buckets: array<string, array{label: string, count: int, amount: float}>,
     *     total: float,
     *     rows: list<array{invoice: Invoice, days: int, bucket: string, outstanding: float}>
     * }
     */
    public function agingReceivables(?Carbon $asOf = null): array
    {
        $asOf ??= now();

        $buckets = [
            '0_30' => ['label' => '0–30 ngày', 'count' => 0, 'amount' => 0.0],
            '31_60' => ['label' => '31–60 ngày', 'count' => 0, 'amount' => 0.0],
            '61_90' => ['label' => '61–90 ngày', 'count' => 0, 'amount' => 0.0],
            '90_plus' => ['label' => 'Trên 90 ngày', 'count' => 0, 'amount' => 0.0],
        ];

        $invoices = Invoice::query()
            ->outstanding()
            ->with(['workOrder.vehicle.customer'])
            ->orderBy('created_at')
            ->get();

        $rows = [];
        $total = 0.0;

        foreach ($invoices as $invoice) {
            $outstanding = $invoice->outstandingAmount();
            if ($outstanding <= 0) {
                continue;
            }

            $days = (int) $invoice->created_at->diffInDays($asOf);
            $bucket = match (true) {
                $days <= 30 => '0_30',
                $days <= 60 => '31_60',
                $days <= 90 => '61_90',
                default => '90_plus',
            };

            $buckets[$bucket]['count']++;
            $buckets[$bucket]['amount'] = round($buckets[$bucket]['amount'] + $outstanding, 2);
            $total = round($total + $outstanding, 2);

            $rows[] = [
                'invoice' => $invoice,
                'days' => $days,
                'bucket' => $bucket,
                'outstanding' => $outstanding,
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['days'] <=> $a['days']);

        return compact('buckets', 'total', 'rows');
    }

    /**
     * P&L tháng theo xe đã bàn giao (doanh thu RO) + hóa đơn đã thu trong tháng.
     *
     * @return array{
     *     month: string,
     *     delivered_revenue: float,
     *     delivered_parts: float,
     *     delivered_labor: float,
     *     delivered_cogs: float,
     *     delivered_gross_profit: float,
     *     invoice_billed: float,
     *     invoice_collected: float,
     *     invoice_outstanding: float,
     *     cars: int
     * }
     */
    public function monthlyPnL(string $month): array
    {
        ['start' => $start, 'end' => $end] = PayrollService::monthRange($month);

        $orders = WorkOrder::query()
            ->where('status', WorkOrderStatus::Delivered)
            ->whereBetween('delivered_at', [$start, $end])
            ->with('items')
            ->get();

        $cogs = 0.0;
        foreach ($orders as $order) {
            $cogs += (float) $order->items
                ->filter(fn (WorkOrderItem $item): bool => $item->is_approved !== false)
                ->sum(fn (WorkOrderItem $item): float => (float) $item->totalCost());
        }

        $parts = round((float) $orders->sum('total_parts_amount'), 2);
        $labor = round((float) $orders->sum('total_labor_amount'), 2);
        $revenue = round((float) $orders->sum('final_amount'), 2);
        $cogs = round($cogs, 2);
        $gross = round((float) $orders->sum(fn (WorkOrder $o): float => (float) ($o->gross_profit ?? 0)), 2);

        if ($gross == 0.0 && $orders->isNotEmpty()) {
            $gross = round($revenue - $cogs, 2);
        }

        $invoices = Invoice::query()
            ->whereBetween('created_at', [$start, $end])
            ->get();

        $billed = round((float) $invoices->sum('total_amount'), 2);
        $collected = round((float) $invoices->sum('paid_amount'), 2);

        return [
            'month' => $month,
            'delivered_revenue' => $revenue,
            'delivered_parts' => $parts,
            'delivered_labor' => $labor,
            'delivered_cogs' => $cogs,
            'delivered_gross_profit' => $gross,
            'invoice_billed' => $billed,
            'invoice_collected' => $collected,
            'invoice_outstanding' => round($billed - $collected, 2),
            'cars' => $orders->count(),
        ];
    }

    /**
     * @return Collection<int, Invoice>
     */
    public function unpaidInvoices(): Collection
    {
        return Invoice::query()
            ->whereIn('payment_status', [PaymentStatus::Unpaid, PaymentStatus::PartiallyPaid])
            ->with(['workOrder.vehicle.customer'])
            ->orderBy('created_at')
            ->get();
    }
}
