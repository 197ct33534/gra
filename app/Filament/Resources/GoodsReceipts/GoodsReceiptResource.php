<?php

namespace App\Filament\Resources\GoodsReceipts;

use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\GoodsReceipts\Pages\CreateGoodsReceipt;
use App\Filament\Resources\GoodsReceipts\Pages\ListGoodsReceipts;
use App\Filament\Resources\GoodsReceipts\Pages\ViewGoodsReceipt;
use App\Models\GoodsReceipt;
use App\Models\Part;
use App\Models\User;
use App\Support\VietnameseMoney;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class GoodsReceiptResource extends Resource
{
    protected static ?string $model = GoodsReceipt::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static string|UnitEnum|null $navigationGroup = 'Mua hàng';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Phiếu nhập kho';

    protected static ?string $modelLabel = 'phiếu nhập kho';

    protected static ?string $pluralModelLabel = 'phiếu nhập kho';

    protected static ?string $recordTitleAttribute = 'code';

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('supplier_id')
                ->label('Nhà cung cấp')
                ->relationship('supplier', 'name', fn (Builder $query): Builder => $query->active()->orderBy('name'))
                ->getOptionLabelFromRecordUsing(fn ($record): string => $record->displayLabel())
                ->searchable()
                ->preload()
                ->required()
                ->native(false),
            DateTimePicker::make('received_at')
                ->label('Thời điểm nhập')
                ->default(now())
                ->required()
                ->native(false),
            TextInput::make('invoice_no_supplier')
                ->label('Số HĐ NCC')
                ->maxLength(50),
            DatePicker::make('invoice_date')
                ->label('Ngày HĐ NCC')
                ->native(false)
                ->default(now()),
            TextInput::make('vat_rate')
                ->label('VAT %')
                ->numeric()
                ->minValue(0)
                ->maxValue(20)
                ->default((int) config('garage.purchasing.default_vat_rate', 8))
                ->required(),
            Textarea::make('notes')
                ->label('Ghi chú')
                ->rows(2)
                ->columnSpanFull(),
            Repeater::make('lines')
                ->label('Dòng nhập')
                ->schema([
                    Select::make('part_id')
                        ->label('Phụ tùng')
                        ->options(fn (): array => Part::query()->orderBy('name')->get()
                            ->mapWithKeys(fn (Part $part): array => [$part->id => $part->sku.' — '.$part->name])
                            ->all())
                        ->searchable()
                        ->required()
                        ->native(false)
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set): void {
                            $part = Part::query()->find($state);
                            if ($part instanceof Part) {
                                $set('unit_cost', (int) round((float) $part->cost_price));
                            }
                        }),
                    TextInput::make('quantity')
                        ->label('Số lượng')
                        ->numeric()
                        ->minValue(1)
                        ->default(1)
                        ->required(),
                    MoneyInput::make('unit_cost')
                        ->label('Giá nhập')
                        ->required(),
                ])
                ->columns(3)
                ->columnSpanFull()
                ->minItems(1)
                ->defaultItems(1)
                ->addActionLabel('Thêm dòng')
                ->dehydrated(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('code')->label('Mã phiếu'),
            TextEntry::make('supplier.name')->label('NCC'),
            TextEntry::make('purchaseOrder.code')->label('Đơn mua')->placeholder('Nhập lẻ'),
            TextEntry::make('received_at')->label('Nhập lúc')->dateTime('d/m/Y H:i'),
            TextEntry::make('invoice_no_supplier')->label('Số HĐ NCC')->placeholder('—'),
            TextEntry::make('total_amount')
                ->label('Tổng có VAT')
                ->formatStateUsing(fn (mixed $state): string => VietnameseMoney::format($state))
                ->visible(fn (): bool => User::currentCanSeeCosts()),
            TextEntry::make('notes')->label('Ghi chú')->placeholder('—')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Mã')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('supplier.name')
                    ->label('NCC')
                    ->searchable(),
                TextColumn::make('purchaseOrder.code')
                    ->label('PO')
                    ->placeholder('Nhập lẻ')
                    ->toggleable(),
                TextColumn::make('received_at')
                    ->label('Ngày nhập')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('total_amount')
                    ->label('Tổng')
                    ->formatStateUsing(fn (mixed $state): string => VietnameseMoney::format($state))
                    ->visible(fn (): bool => User::currentCanSeeCosts()),
                TextColumn::make('creator.name')
                    ->label('Người nhập')
                    ->toggleable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['supplier', 'purchaseOrder', 'creator', 'items.part']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGoodsReceipts::route('/'),
            'create' => CreateGoodsReceipt::route('/create'),
            'view' => ViewGoodsReceipt::route('/{record}'),
        ];
    }
}
