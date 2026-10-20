<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Support\VietnameseMoney;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class VehicleServiceSummaryWidget extends StatsOverviewWidget
{
    public ?Vehicle $record = null;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $vehicle = $this->record;

        if (! $vehicle instanceof Vehicle) {
            return [];
        }

        $vehicle->loadMissing('workOrders.items');
        $lastService = $vehicle->latestOilChange();
        $lastLabel = $lastService instanceof WorkOrder
            ? $lastService->created_at?->timezone(config('app.timezone'))->format('d/m/Y').' · '.number_format((int) $lastService->reception_odo, 0, ',', '.').' km'
            : 'Chưa có lần bảo dưỡng';
        $nextOdo = $vehicle->next_service_odo;

        return array_values(array_filter([
            Stat::make('Tổng số lần vào gara', (string) $vehicle->workOrders->count())
                ->description('Toàn bộ lệnh sửa chữa')
                ->descriptionIcon(Heroicon::OutlinedClipboardDocumentList)
                ->color('info'),
            User::currentCanSeeMoney()
                ? Stat::make('Chi tiêu trọn đời', VietnameseMoney::format($vehicle->lifetimeSpendAmount()))
                    ->description('Lifetime value của xe')
                    ->descriptionIcon(Heroicon::OutlinedBanknotes)
                    ->color('success')
                : null,
            Stat::make('Lần bảo dưỡng gần nhất', $lastLabel)
                ->description($nextOdo === null ? 'Chưa có mốc thay nhớt' : 'Mốc kế tiếp '.number_format($nextOdo, 0, ',', '.').' km')
                ->descriptionIcon(Heroicon::OutlinedWrenchScrewdriver)
                ->color($vehicle->isMaintenanceDue() ? 'danger' : 'success'),
        ]));
    }
}
