<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Parts\PartResource;
use App\Filament\Support\PhoneTable;
use App\Models\Part;
use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Contracts\Support\Htmlable;

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
                    ->lowStock()
                    ->orderByRaw('stock_quantity - reserved_quantity'),
            )
            ->columns([
                TextColumn::make('sku')
                    ->label('SKU')
                    ->badge()
                    ->color('gray')
                    ->visibleFrom('md'),
                TextColumn::make('name')
                    ->label('Tên phụ tùng')
                    // Điện thoại: SKU, kệ, số đang giữ, ngưỡng ghi gọn dưới tên (CSS ẩn dòng này từ 768px).
                    ->description(fn (Part $record): Htmlable => PhoneTable::summary([
                        $record->sku,
                        'Kệ '.($record->shelf_location ?: 'chưa xếp'),
                        'Giữ '.(int) $record->reserved_quantity,
                        'Ngưỡng '.(int) $record->min_stock_alert,
                    ])),
                TextColumn::make('shelf_location')
                    ->label('Vị trí kệ')
                    ->placeholder('Chưa xếp kệ')
                    ->visibleFrom('md'),
                TextColumn::make('stock_quantity')
                    ->label('Tồn hiện tại')
                    ->badge()
                    ->color('danger'),
                TextColumn::make('reserved_quantity')
                    ->label('Đang giữ cho xe')
                    ->badge()
                    ->color('warning')
                    ->visibleFrom('md'),
                TextColumn::make('min_stock_alert')
                    ->label('Ngưỡng tối thiểu')
                    ->visibleFrom('md'),
            ])
            ->recordUrl(fn (Part $record): string => PartResource::getUrl('edit', ['record' => $record]))
            ->emptyStateHeading('Không có phụ tùng dưới ngưỡng');
    }

    public static function canView(): bool
    {
        return User::current()?->canSeeMoney() ?? false;
    }
}
