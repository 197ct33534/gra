<?php

namespace App\Filament\Widgets;

use App\Enums\WorkOrderStatus;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Filament\Support\PhoneTable;
use App\Models\WorkOrder;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Contracts\Support\Htmlable;

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
                    ->color('primary')
                    // Điện thoại: các cột phụ bị ẩn nên ghi gọn dưới biển số (CSS ẩn dòng này từ 768px).
                    ->description(fn (WorkOrder $record): Htmlable => PhoneTable::summary([
                        $record->vehicle?->customer?->name ?? '—',
                        $record->vehicle?->customer?->phone,
                        'Thợ: '.$record->leadTechnicianName(),
                        $record->created_at !== null
                            ? 'Nhận '.$record->created_at->timezone((string) config('garage.timezone'))->format('H:i d/m')
                            : null,
                    ])),
                TextColumn::make('vehicle.customer.name')
                    ->label('Khách hàng')
                    ->visibleFrom('md')
                    ->description(fn (WorkOrder $record): string => $record->vehicle?->customer?->phone ?? ''),
                TextColumn::make('serviceAdvisor.name')
                    ->label('Cố vấn phụ trách')
                    ->visibleFrom('md'),
                TextColumn::make('created_at')
                    ->label('Tiếp nhận')
                    ->visibleFrom('md')
                    ->dateTime('d/m/Y H:i'),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn (WorkOrderStatus $state): string => $state->getLabel())
                    ->color(fn (WorkOrderStatus $state): string => $state->getColor()),
                TextColumn::make('lead_technician')
                    ->label('Thợ chính')
                    ->visibleFrom('md')
                    ->state(fn (WorkOrder $record): string => $record->leadTechnicianName()),
            ])
            ->recordUrl(fn (WorkOrder $record): string => WorkOrderResource::getUrl('view', ['record' => $record]))
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Không có xe đang ở xưởng');
    }
}
