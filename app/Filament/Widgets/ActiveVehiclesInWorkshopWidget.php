<?php

namespace App\Filament\Widgets;

use App\Enums\WorkOrderStatus;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\WorkOrder;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class ActiveVehiclesInWorkshopWidget extends TableWidget
{
    protected static ?int $sort = 3;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 1;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Tiến độ xe đang sửa chữa tại xưởng')
            ->query(
                WorkOrder::query()
                    ->with(['vehicle.customer', 'serviceAdvisor', 'items.technician'])
                    ->whereNotIn('status', [WorkOrderStatus::Completed, WorkOrderStatus::Delivered]),
            )
            ->columns([
                TextColumn::make('vehicle.license_plate')
                    ->label('Biển số')
                    ->badge()
                    ->color('primary'),
                TextColumn::make('vehicle.customer.name')
                    ->label('Khách hàng')
                    ->description(fn (WorkOrder $record): string => $record->vehicle?->customer?->phone ?? ''),
                TextColumn::make('serviceAdvisor.name')
                    ->label('Cố vấn phụ trách'),
                TextColumn::make('created_at')
                    ->label('Tiếp nhận')
                    ->dateTime('d/m/Y H:i'),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn (WorkOrderStatus $state): string => $state->getLabel())
                    ->color(fn (WorkOrderStatus $state): string => $state->getColor()),
                TextColumn::make('lead_technician')
                    ->label('Thợ chính')
                    ->state(fn (WorkOrder $record): string => $record->leadTechnicianName()),
            ])
            ->recordUrl(fn (WorkOrder $record): string => WorkOrderResource::getUrl('view', ['record' => $record]))
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Không có xe đang ở xưởng');
    }
}
