<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Filament\Support\PhoneTable;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\GarageAlertService;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Contracts\Support\Htmlable;

class LateJobsAlertWidget extends TableWidget
{
    protected static ?int $sort = 3;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 1;

    public function table(Table $table): Table
    {
        $ids = app(GarageAlertService::class)->lateWorkOrders()->pluck('id');

        return $table
            ->heading('RO trễ giờ hẹn trả xe')
            ->query(
                WorkOrder::query()
                    ->with(['vehicle.customer'])
                    ->whereIn('id', $ids->isEmpty() ? [0] : $ids->all())
                    ->orderBy('promised_at'),
            )
            ->columns([
                TextColumn::make('code')->label('RO')->badge()->visibleFrom('md'),
                TextColumn::make('vehicle.license_plate')->label('Biển số')
                    // Điện thoại: mã RO và trạng thái ghi gọn dưới biển số (CSS ẩn dòng này từ 768px).
                    ->description(fn (WorkOrder $record): Htmlable => PhoneTable::summary([
                        $record->code,
                        $record->status?->getLabel(),
                    ])),
                TextColumn::make('promised_at')
                    ->label('Hẹn trả')
                    ->dateTime('d/m H:i')
                    ->timezone((string) config('garage.timezone'))
                    ->color('danger'),
                TextColumn::make('status')->label('TT')->badge()->visibleFrom('md'),
            ])
            ->recordUrl(fn (WorkOrder $record): string => WorkOrderResource::getUrl('edit', ['record' => $record]))
            ->emptyStateHeading('Không có RO trễ hẹn');
    }

    public static function canView(): bool
    {
        $user = User::current();

        return $user !== null && ($user->isAdmin() || $user->isServiceAdvisor());
    }
}
