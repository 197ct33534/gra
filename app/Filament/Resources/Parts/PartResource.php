<?php

namespace App\Filament\Resources\Parts;

use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\Parts\Pages\CreatePart;
use App\Filament\Resources\Parts\Pages\EditPart;
use App\Filament\Resources\Parts\Pages\ListParts;
use App\Models\Part;
use App\Models\User;
use App\Support\VietnameseMoney;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class PartResource extends Resource
{
    protected static ?string $model = Part::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|UnitEnum|null $navigationGroup = 'Kho & dịch vụ';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Phụ tùng';

    protected static ?string $modelLabel = 'phụ tùng';

    protected static ?string $pluralModelLabel = 'phụ tùng';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('sku')
                    ->label('SKU')
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('oem_code')
                    ->label('Mã OEM')
                    ->maxLength(255)
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? $state : null),
                TextInput::make('name')
                    ->label('Tên phụ tùng')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Select::make('category_id')
                    ->label('Nhóm phụ tùng')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->native(false),
                TextInput::make('unit')
                    ->label('Đơn vị tính')
                    ->required()
                    ->maxLength(50),
                MoneyInput::make('cost_price')
                    ->label('Giá vốn')
                    ->required()
                    ->helperText('Chỉ chủ gara xem lại được giá vốn sau khi lưu.')
                    // Cố vấn nhập giá mua khi tạo mã mới, nhưng không xem lại giá vốn hàng cũ.
                    ->visible(fn (mixed $livewire): bool => User::currentCanSeeCosts() || $livewire instanceof CreateRecord),
                MoneyInput::make('selling_price')
                    ->label('Giá bán')
                    ->required(),
                TextInput::make('stock_quantity')
                    ->label('Tồn kho')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->helperText(fn (mixed $livewire): ?string => $livewire instanceof EditRecord ? 'Sửa số này sẽ ghi một dòng "Điều chỉnh kiểm kê" vào thẻ kho và nhật ký.' : null)
                    ->visible(fn (): bool => User::currentCanSeeCosts()),
                TextInput::make('adjustment_reason')
                    ->label('Lý do điều chỉnh tồn')
                    ->placeholder('VD: Kiểm kê cuối tháng thiếu 2 cái')
                    ->maxLength(255)
                    ->dehydrated(false)
                    ->visible(fn (mixed $livewire): bool => User::currentCanSeeCosts() && $livewire instanceof EditRecord),
                TextInput::make('min_stock_alert')
                    ->label('Ngưỡng cảnh báo tồn')
                    ->required()
                    ->numeric()
                    ->minValue(0)
                    ->default(5),
                TextInput::make('shelf_location')
                    ->label('Vị trí kệ kho')
                    ->maxLength(255)
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? $state : null),
                TextInput::make('warranty_months')
                    ->label('BH (tháng)')
                    ->numeric()
                    ->minValue(0)
                    ->helperText('Để trống = dùng mặc định gara.'),
                TextInput::make('warranty_km')
                    ->label('BH (km)')
                    ->numeric()
                    ->minValue(0),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Tên phụ tùng')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category.name')
                    ->label('Nhóm phụ tùng')
                    ->sortable(),
                TextColumn::make('selling_price')
                    ->label('Giá bán')
                    ->formatStateUsing(fn (mixed $state): string => VietnameseMoney::format($state))
                    ->sortable(),
                TextColumn::make('stock_quantity')
                    ->label('Tồn kho')
                    ->badge()
                    ->color(fn (Part $record): string => $record->stock_quantity <= $record->min_stock_alert ? 'danger' : 'success')
                    ->sortable(),
                TextColumn::make('reserved_quantity')
                    ->label('Đang giữ cho xe')
                    ->badge()
                    ->color(fn (Part $record): string => $record->reserved_quantity > $record->stock_quantity ? 'danger' : 'gray')
                    ->tooltip('Đã duyệt báo giá, chờ bàn giao xe mới trừ tồn')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('category_id')
                    ->label('Nhóm phụ tùng')
                    ->relationship('category', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('low_stock')
                    ->label('Sắp hết hàng')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereColumn('stock_quantity', '<=', 'min_stock_alert')),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListParts::route('/'),
            'create' => CreatePart::route('/create'),
            'edit' => EditPart::route('/{record}/edit'),
        ];
    }
}
