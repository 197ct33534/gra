<?php

namespace App\Filament\Resources\StockTransactions;

use App\Enums\StockTransactionType;
use App\Filament\Resources\StockTransactions\Pages\ListStockTransactions;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\Part;
use App\Models\StockTransaction;
use App\Support\VietnameseMoney;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class StockTransactionResource extends Resource
{
    protected static ?string $model = StockTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'Kho & dịch vụ';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Thẻ kho';

    protected static ?string $modelLabel = 'thẻ kho';

    protected static ?string $pluralModelLabel = 'thẻ kho';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label('Mã phiếu')
                    ->state(fn (StockTransaction $record): string => self::referenceLabel($record))
                    ->url(fn (StockTransaction $record): ?string => self::workOrderUrl($record))
                    ->color(fn (StockTransaction $record): ?string => self::workOrderUrl($record) !== null ? 'primary' : null),
                TextColumn::make('created_at')
                    ->label('Ngày giao dịch')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('part.name')
                    ->label('Phụ tùng')
                    ->description(fn (StockTransaction $record): string => $record->part?->sku ?? '')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('part', function (Builder $query) use ($search): void {
                            $query->where('name', 'like', "%{$search}%")
                                ->orWhere('sku', 'like', "%{$search}%");
                        });
                    }),
                TextColumn::make('transaction_type')
                    ->label('Loại giao dịch')
                    ->badge()
                    ->formatStateUsing(fn (StockTransactionType $state): string => $state->getLabel())
                    ->color(fn (StockTransactionType $state): string|array|null => $state->getColor()),
                TextColumn::make('quantity')
                    ->label('Số lượng')
                    ->formatStateUsing(fn (int $state): string => ($state > 0 ? '+' : '').$state)
                    ->color(fn (int $state): string => $state < 0 ? 'danger' : 'success')
                    ->alignEnd(),
                TextColumn::make('unit_price')
                    ->label('Đơn giá')
                    ->formatStateUsing(fn (mixed $state): string => VietnameseMoney::format($state)),
                TextColumn::make('note')
                    ->label('Ghi chú')
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('creator.name')
                    ->label('Người thực hiện'),
            ])
            ->filters([
                SelectFilter::make('transaction_type')
                    ->label('Loại giao dịch')
                    ->options(StockTransactionType::class),
                SelectFilter::make('part_id')
                    ->label('Phụ tùng')
                    ->relationship('part', 'name')
                    ->getOptionLabelFromRecordUsing(fn (Part $record): string => $record->name.' ('.$record->sku.')')
                    ->searchable()
                    ->preload(),
                Filter::make('created_at')
                    ->label('Ngày giao dịch')
                    ->schema([
                        DatePicker::make('from')->label('Từ ngày'),
                        DatePicker::make('until')->label('Đến ngày'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->recordActions([])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * @return array<class-string>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListStockTransactions::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['part', 'creator', 'workOrder']);
    }

    public static function referenceLabel(StockTransaction $record): string
    {
        if (self::linksToWorkOrder($record) && $record->workOrder !== null) {
            return $record->workOrder->code;
        }

        return $record->reference_id !== null ? '#'.$record->reference_id : '—';
    }

    public static function workOrderUrl(StockTransaction $record): ?string
    {
        if (! self::linksToWorkOrder($record) || $record->workOrder === null) {
            return null;
        }

        return WorkOrderResource::getUrl('view', ['record' => $record->workOrder]);
    }

    private static function linksToWorkOrder(StockTransaction $record): bool
    {
        return in_array($record->transaction_type, [
            StockTransactionType::ExportWorkOrder,
            StockTransactionType::Return,
        ], true);
    }
}
