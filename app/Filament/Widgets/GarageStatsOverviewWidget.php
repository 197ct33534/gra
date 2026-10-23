<?php

namespace App\Filament\Widgets;

use App\Enums\InvoicePayer;
use App\Enums\WorkOrderStatus;
use App\Models\Invoice;
use App\Models\Part;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\VietnameseMoney;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class GarageStatsOverviewWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected int|array|null $columns = [
        'default' => 1,
        'md' => 2,
        'xl' => 4,
    ];

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $monthRevenue = $this->monthRevenue();
        $workshopCount = WorkOrder::query()
            ->whereIn('status', [
                WorkOrderStatus::Diagnosing,
                WorkOrderStatus::CustomerApproved,
                WorkOrderStatus::InProgress,
                WorkOrderStatus::Qc,
            ])
            ->count();
        $lowStockCount = Part::query()->lowStock()->count();
        // Công nợ bảo hiểm theo dõi riêng ở InsuranceReceivableWidget.
        $debt = (float) Invoice::query()
            ->forPayer(InvoicePayer::Customer)
            ->outstanding()
            ->sum(DB::raw('total_amount - paid_amount'));

        return [
            Stat::make('Doanh thu tháng này', $this->money($monthRevenue))
                ->description('Đã thu trong tháng')
                ->descriptionIcon(Heroicon::OutlinedBanknotes)
                ->chart($this->monthSparkline())
                ->chartColor('success')
                ->color('success'),
            Stat::make('Xe đang tại xưởng', (string) $workshopCount)
                ->description('Đang chẩn đoán, sửa hoặc kiểm tra')
                ->descriptionIcon(Heroicon::OutlinedTruck)
                ->color('warning')
                ->extraAttributes(['data-tour' => 'workshop-count']),
            Stat::make('Phụ tùng sắp hết', (string) $lowStockCount)
                ->description($lowStockCount > 0 ? 'Cần nhập thêm hàng' : 'Tồn kho ổn định')
                ->descriptionIcon(Heroicon::OutlinedCube)
                ->color($lowStockCount > 0 ? 'danger' : 'success'),
            Stat::make('Công nợ khách chưa trả', $this->money($debt))
                ->description($debt > 0 ? 'Còn phải thu trên hóa đơn' : 'Không còn công nợ')
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color($debt > 0 ? 'warning' : 'gray'),
        ];
    }

    private function monthRevenue(): float
    {
        return round((float) Invoice::query()
            ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('paid_amount'), 2);
    }

    /**
     * @return list<float>
     */
    private function monthSparkline(): array
    {
        $start = now()->startOfMonth()->startOfDay();
        $end = now()->startOfDay();
        $grouped = $this->revenueByDay($start, $end);
        $points = [];

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $points[] = $grouped[$day->toDateString()] ?? 0.0;
        }

        return $points;
    }

    /**
     * @return array<string, float>
     */
    private function revenueByDay(Carbon $start, Carbon $end): array
    {
        return Invoice::query()
            ->whereBetween('created_at', [$start, $end->copy()->endOfDay()])
            ->get(['paid_amount', 'created_at'])
            ->groupBy(fn (Invoice $invoice): string => $invoice->created_at?->toDateString() ?? '')
            ->map(fn ($invoices): float => round((float) $invoices->sum('paid_amount'), 2))
            ->all();
    }

    private function money(float $amount): string
    {
        return VietnameseMoney::format($amount);
    }

    public static function canView(): bool
    {
        return User::current()?->canSeeMoney() ?? false;
    }
}
