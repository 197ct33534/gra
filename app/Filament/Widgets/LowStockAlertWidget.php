<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Parts\PartResource;
use App\Models\Part;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class LowStockAlertWidget extends TableWidget
{
    protected static ?int $sort = 4;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 1;

    public function table(Table $table): Table
    {
        return $table
            ->heading('Cảnh báo phụ tùng chạm ngưỡng tồn kho')
            ->query(
                Part::query()
                    ->whereRaw('stock_quantity - reserved_quantity <= min_stock_alert')
                    ->orderByRaw('stock_quantity - reserved_quantity'),
            )
            ->columns([
                TextColumn::make('sku')
                    ->label('SKU')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('name')
                    ->label('Tên phụ tùng'),
                TextColumn::make('shelf_location')
                    ->label('Vị trí kệ')
                    ->placeholder('Chưa xếp kệ'),
                TextColumn::make('stock_quantity')
                    ->label('Tồn hiện tại')
                    ->badge()
                    ->color('danger'),
                TextColumn::make('reserved_quantity')
                    ->label('Đang giữ cho xe')
                    ->badge()
                    ->color('warning'),
                TextColumn::make('min_stock_alert')
                    ->label('Ngưỡng tối thiểu'),
            ])
            ->recordUrl(fn (Part $record): string => PartResource::getUrl('edit', ['record' => $record]))
            ->emptyStateHeading('Không có phụ tùng dưới ngưỡng');
    }

    public static function canView(): bool
    {
        return User::current()?->canSeeMoney() ?? false;
    }
}
