<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\Vehicle;
use App\Models\VehicleRecommendation;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Hạng mục khách từ chối ở các lần trước: cớ để cố vấn dịch vụ tư vấn lại khi xe quay lại xưởng.
 */
class DeclinedRecommendationsWidget extends TableWidget
{
    public ?Vehicle $record = null;

    protected static bool $isDiscovered = false;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Các hạng mục khách đã từ chối ở những lần trước')
            ->description('Khuyến cáo kỹ thuật chưa làm — tư vấn lại cho khách lần này.')
            ->query(
                VehicleRecommendation::query()
                    ->where('vehicle_id', $this->record?->getKey() ?? 0)
                    ->with('workOrder')
                    ->orderByRaw('resolved_at is not null')
                    ->orderByDesc('declined_at'),
            )
            ->columns([
                TextColumn::make('declined_at')
                    ->label('Ngày từ chối')
                    ->dateTime('d/m/Y'),
                TextColumn::make('item_name')
                    ->label('Hạng mục khuyến cáo')
                    ->weight('bold')
                    ->description(fn (VehicleRecommendation $record): ?string => $record->revision_no > 1 ? 'Phát sinh đợt '.($record->revision_no - 1) : null),
                TextColumn::make('decline_reason')
                    ->label('Lý do')
                    ->placeholder('Không ghi lý do')
                    ->wrap(),
                TextColumn::make('odo')
                    ->label('ODO lúc đó')
                    ->numeric(decimalPlaces: 0, locale: 'vi')
                    ->suffix(' km'),
                TextColumn::make('workOrder.code')
                    ->label('Lệnh')
                    ->badge()
                    ->color('gray')
                    ->url(fn (VehicleRecommendation $record): string => WorkOrderResource::getUrl('view', ['record' => $record->work_order_id])),
                TextColumn::make('state')
                    ->label('Tình trạng')
                    ->badge()
                    ->state(fn (VehicleRecommendation $record): string => $record->resolved_at === null ? 'Chưa làm' : 'Đã làm '.$record->resolved_at->timezone((string) config('garage.timezone'))->format('d/m/Y'))
                    ->color(fn (VehicleRecommendation $record): string => $record->resolved_at === null ? 'danger' : 'success')
                    ->icon(fn (VehicleRecommendation $record): Heroicon => $record->resolved_at === null ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedCheckCircle),
            ])
            ->paginated(false)
            ->emptyStateHeading('Khách chưa từ chối khuyến cáo nào')
            ->emptyStateIcon(Heroicon::OutlinedHandThumbUp);
    }
}
