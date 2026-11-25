<?php

namespace App\Filament\Resources\Parts\RelationManagers;

use App\Filament\Support\PhoneTable;
use App\Models\GoodsReceiptItem;
use App\Models\User;
use App\Support\VietnameseMoney;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;

class CostHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'goodsReceiptItems';

    protected static ?string $title = 'Lịch sử giá vốn nhập';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return User::currentCanSeeCosts();
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['goodsReceipt.supplier'])
                ->orderByDesc('id'))
            ->columns([
                TextColumn::make('goodsReceipt.received_at')
                    ->label('Ngày nhập')
                    ->dateTime('d/m/Y')
                    ->timezone((string) config('garage.timezone'))
                    ->placeholder(fn (GoodsReceiptItem $record): string => $record->created_at?->format('d/m/Y') ?? '—')
                    ->visibleFrom('md'),
                TextColumn::make('goodsReceipt.code')
                    ->label('Phiếu nhập')
                    // Điện thoại: ngày nhập / NCC / SL bị ẩn nên ghi gọn dưới mã phiếu.
                    ->description(fn (GoodsReceiptItem $record): Htmlable => PhoneTable::summary([
                        ($record->goodsReceipt?->received_at ?? $record->created_at)
                            ?->timezone((string) config('garage.timezone'))
                            ->format('d/m/Y'),
                        $record->goodsReceipt?->supplier?->name,
                        'SL '.Number::format((float) $record->quantity, maxPrecision: 2, locale: 'vi'),
                    ])),
                TextColumn::make('goodsReceipt.supplier.name')->label('NCC')->placeholder('—')->visibleFrom('md'),
                TextColumn::make('quantity')->label('SL')->numeric(decimalPlaces: 2)->visibleFrom('md'),
                TextColumn::make('unit_cost')
                    ->label('Đơn giá vốn')
                    ->formatStateUsing(fn (mixed $state): string => VietnameseMoney::format($state)),
            ])
            ->paginated([10, 25])
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('Chưa có lần nhập nào');
    }
}
