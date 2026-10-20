<?php

namespace App\Filament\Widgets;

use App\Enums\WorkOrderItemType;
use App\Enums\WorkOrderStatus;
use App\Models\User;
use App\Models\WorkOrderItem;
use App\Services\PayrollService;
use App\Support\VietnameseMoney;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * Thợ tự thấy tiền khoán của mình: có tên trên lệnh là có tiền, nên tự giác cập nhật.
 * Chỉ hiện tiền của chính thợ đó, không lộ giá bán hay doanh thu.
 */
class MyPayoutWidget extends StatsOverviewWidget
{
    protected static ?int $sort = -1;

    protected static bool $isLazy = false;

    protected ?string $heading = 'Tiền khoán của tôi';

    public static function canView(): bool
    {
        return User::current()?->isTechnician() ?? false;
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $user = User::current();

        if (! $user instanceof User) {
            return [];
        }

        $payroll = app(PayrollService::class);
        $month = PayrollService::currentMonth();
        $delivered = $payroll->deliveredLaborItems($month, $user->id);
        $pending = WorkOrderItem::query()
            ->where('technician_id', $user->id)
            ->where('item_type', WorkOrderItemType::Service)
            ->where('is_approved', true)
            ->whereHas('workOrder', fn (Builder $query): Builder => $query->whereIn('status', [
                WorkOrderStatus::CustomerApproved,
                WorkOrderStatus::InProgress,
                WorkOrderStatus::Qc,
                WorkOrderStatus::Completed,
            ]))
            ->with(['workOrder', 'service', 'technician'])
            ->get();
        $pendingAmount = round((float) $pending->sum(fn (WorkOrderItem $item): float => $payroll->payoutFor($item, $item->workOrder, $item->service, $item->technician)), 2);

        return [
            Stat::make('Đã chốt tháng này', VietnameseMoney::format($payroll->monthlyPayoutFor($user, $month)))
                ->description($delivered->count().' hạng mục trên '.$delivered->pluck('work_order_id')->unique()->count().' xe đã giao')
                ->descriptionIcon(Heroicon::OutlinedBanknotes)
                ->color('success'),
            Stat::make('Sắp nhận (xe chưa giao)', VietnameseMoney::format($pendingAmount))
                ->description($pending->count().' hạng mục đang làm — giao xe là được chốt')
                ->descriptionIcon(Heroicon::OutlinedWrenchScrewdriver)
                ->color('warning'),
        ];
    }
}
