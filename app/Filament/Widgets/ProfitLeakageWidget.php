<?php

namespace App\Filament\Widgets;

use App\Enums\ActivityEvent;
use App\Enums\WorkOrderStatus;
use App\Filament\Resources\ActivityLogs\ActivityLogResource;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Support\VietnameseMoney;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;

/**
 * Góc nhìn chủ gara: tháng này lãi bao nhiêu và tiền có đang rò rỉ ở đâu không.
 */
class ProfitLeakageWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    protected static bool $isLazy = false;

    protected ?string $heading = 'Lãi gộp & dấu hiệu thất thoát (tháng này)';

    public static function canView(): bool
    {
        return User::current()?->canSeeCosts() ?? false;
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $timezone = (string) config('garage.timezone');
        $start = Carbon::now($timezone)->startOfMonth()->utc();
        $end = Carbon::now($timezone)->endOfMonth()->utc();

        $orders = WorkOrder::query()
            ->where('status', WorkOrderStatus::Delivered)
            ->whereBetween('delivered_at', [$start, $end])
            ->get(['id', 'total_parts_amount', 'total_labor_amount', 'discount_amount', 'gross_profit']);
        $netRevenue = fn (WorkOrder $order): float => (float) $order->total_parts_amount + (float) $order->total_labor_amount - (float) $order->discount_amount;
        $revenue = $orders->sum($netRevenue);
        // Lệnh bàn giao trước khi có tính năng lãi gộp không có số liệu: không kéo biên lãi về 0.
        $withProfit = $orders->filter(fn (WorkOrder $order): bool => $order->gross_profit !== null);
        $missing = $orders->count() - $withProfit->count();
        $profit = (float) $withProfit->sum('gross_profit');
        $profitRevenue = $withProfit->sum($netRevenue);
        $margin = $profitRevenue > 0 ? round($profit / $profitRevenue * 100, 1) : 0.0;

        $lineDiscounts = (float) WorkOrderItem::query()
            ->whereIn('work_order_id', $orders->pluck('id'))
            ->where('is_approved', true)
            ->sum('discount_amount');
        $discounts = round((float) $orders->sum('discount_amount') + $lineDiscounts, 2);

        $logs = ActivityLog::query()->whereBetween('created_at', [$start, $end]);
        $belowCatalog = (clone $logs)->where('event', ActivityEvent::BelowCatalog)->count();
        $edits = (clone $logs)->whereIn('event', ActivityEvent::leakageSignals())->count();
        $stockAdjustments = (clone $logs)->where('event', ActivityEvent::StockAdjusted)->count();
        $logUrl = ActivityLogResource::getUrl('index');

        return [
            Stat::make('Lãi gộp đã bàn giao', VietnameseMoney::format($profit))
                ->description(($withProfit->isEmpty() ? 'Chưa có xe bàn giao có số liệu lãi' : "Biên {$margin}% trên doanh thu ".VietnameseMoney::format($profitRevenue).' (trước VAT) · '.$withProfit->count().' xe')
                    .($missing > 0 ? " · {$missing} xe cũ chưa có số liệu lãi" : ''))
                ->descriptionIcon(Heroicon::OutlinedArrowTrendingUp)
                ->color($withProfit->isEmpty() || $margin >= 30 ? 'success' : 'warning'),
            Stat::make('Giảm giá đã cho khách', VietnameseMoney::format($discounts))
                ->description($revenue > 0 ? round($discounts / ($revenue + $discounts) * 100, 1).'% giá trị báo giá · '.$orders->count().' xe bàn giao' : 'Chưa có xe bàn giao')
                ->descriptionIcon(Heroicon::OutlinedReceiptPercent)
                ->color('warning'),
            Stat::make('Bán dưới bảng giá', (string) $belowCatalog)
                ->description('Lần nhập giá thấp hơn bảng giá')
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->color($belowCatalog > 0 ? 'danger' : 'success')
                ->url($logUrl),
            Stat::make('Sửa giá / xóa chứng từ', (string) $edits)
                ->description('Sửa đơn giá, xóa hạng mục, xóa lệnh, xóa hóa đơn')
                ->descriptionIcon(Heroicon::OutlinedPencilSquare)
                ->color($edits > 0 ? 'warning' : 'success')
                ->url($logUrl),
            Stat::make('Chỉnh tồn kho tay', (string) $stockAdjustments)
                ->description('Lần sửa số tồn ngoài nhập/xuất')
                ->descriptionIcon(Heroicon::OutlinedArchiveBox)
                ->color($stockAdjustments > 0 ? 'danger' : 'success')
                ->url($logUrl),
        ];
    }
}
