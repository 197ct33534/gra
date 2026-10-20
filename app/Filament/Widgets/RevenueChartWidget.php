<?php

namespace App\Filament\Widgets;

use App\Models\Invoice;
use App\Models\User;
use Filament\Widgets\ChartWidget;

class RevenueChartWidget extends ChartWidget
{
    protected static ?int $sort = 2;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Doanh thu 7 ngày gần nhất';

    protected ?string $maxHeight = '16rem';

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $start = now()->startOfDay()->subDays(6);
        $grouped = Invoice::query()
            ->where('created_at', '>=', $start)
            ->get(['paid_amount', 'created_at'])
            ->groupBy(fn (Invoice $invoice): string => $invoice->created_at?->toDateString() ?? '')
            ->map(fn ($invoices): float => round((float) $invoices->sum('paid_amount'), 2));

        $labels = [];
        $totals = [];

        for ($offset = 0; $offset < 7; $offset++) {
            $day = $start->copy()->addDays($offset);
            $labels[] = $day->format('d/m');
            $totals[] = $grouped->get($day->toDateString(), 0.0);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Thực thu',
                    'data' => $totals,
                    'backgroundColor' => '#f59e0b',
                    'borderColor' => '#b45309',
                    'borderWidth' => 1,
                    'borderRadius' => 6,
                ],
            ],
            'labels' => $labels,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'display' => false,
                ],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                ],
            ],
        ];
    }

    public static function canView(): bool
    {
        return User::current()?->isAdmin() ?? false;
    }
}
