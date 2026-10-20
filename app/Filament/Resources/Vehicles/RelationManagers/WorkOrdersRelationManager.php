<?php

namespace App\Filament\Resources\Vehicles\RelationManagers;

use App\Enums\WorkOrderStatus;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\VietnameseMoney;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WorkOrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'workOrders';

    protected static bool $isLazy = false;

    protected static ?string $title = 'Bệnh án sửa chữa';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedClipboardDocumentList;

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['serviceAdvisor', 'items.technician', 'invoices']))
            ->columns([
                TextColumn::make('code')
                    ->label('Mã RO')
                    ->badge()
                    ->color('primary'),
                TextColumn::make('created_at')
                    ->label('Ngày tiếp nhận')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('reception_odo')
                    ->label('ODO lúc vào')
                    ->numeric(decimalPlaces: 0, locale: 'vi')
                    ->suffix(' km'),
                TextColumn::make('final_amount')
                    ->label('Tổng tiền')
                    ->visible(fn (): bool => User::currentCanSeeMoney())
                    ->formatStateUsing(fn (mixed $state): string => VietnameseMoney::format($state)),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn (WorkOrderStatus $state): string => $state->getLabel())
                    ->color(fn (WorkOrderStatus $state): string => $state->getColor()),
                TextColumn::make('serviceAdvisor.name')
                    ->label('Cố vấn phụ trách'),
                TextColumn::make('job_lines')
                    ->label('Hạng mục đã làm')
                    ->state(fn (WorkOrder $record): array => $record->jobLines())
                    ->listWithLineBreaks()
                    ->limitList(2)
                    ->expandableLimitedList(),
            ])
            ->recordActions([
                Action::make('viewOrder')
                    ->label('Xem phiếu sửa chữa')
                    ->icon(Heroicon::OutlinedEye)
                    ->url(fn (WorkOrder $record): string => WorkOrderResource::getUrl('view', ['record' => $record])),
                Action::make('printInvoice')
                    ->authorize(fn (): bool => User::currentCanSeeMoney())
                    ->label('In lại Hóa đơn')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->color('success')
                    ->visible(fn (WorkOrder $record): bool => $record->invoices->isNotEmpty())
                    ->url(fn (WorkOrder $record): string => route('work-orders.invoice', $record))
                    ->openUrlInNewTab(),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
