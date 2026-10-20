<?php

namespace App\Filament\Resources\Suppliers;

use App\Filament\Resources\Suppliers\Pages\CreateSupplier;
use App\Filament\Resources\Suppliers\Pages\EditSupplier;
use App\Filament\Resources\Suppliers\Pages\ListSuppliers;
use App\Models\Supplier;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Mua hàng';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Nhà cung cấp';

    protected static ?string $modelLabel = 'nhà cung cấp';

    protected static ?string $pluralModelLabel = 'nhà cung cấp';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label('Mã NCC')
                ->required()
                ->maxLength(30)
                ->unique(ignoreRecord: true)
                ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? mb_strtoupper(trim($state)) : $state),
            TextInput::make('name')
                ->label('Tên nhà cung cấp')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),
            TextInput::make('phone')
                ->label('Số điện thoại')
                ->tel()
                ->maxLength(20),
            TextInput::make('email')
                ->label('Email')
                ->email()
                ->maxLength(255),
            TextInput::make('tax_code')
                ->label('Mã số thuế')
                ->maxLength(20),
            TextInput::make('payment_terms_days')
                ->label('Công nợ (ngày)')
                ->numeric()
                ->minValue(0)
                ->default(0)
                ->helperText('0 = trả ngay khi nhập.'),
            Textarea::make('address')
                ->label('Địa chỉ')
                ->rows(2)
                ->columnSpanFull(),
            TextInput::make('bank_name')
                ->label('Ngân hàng')
                ->maxLength(255),
            TextInput::make('bank_account_no')
                ->label('Số tài khoản')
                ->maxLength(50),
            TextInput::make('bank_account_name')
                ->label('Chủ tài khoản')
                ->maxLength(255),
            Textarea::make('note')
                ->label('Ghi chú')
                ->rows(2)
                ->columnSpanFull(),
            Toggle::make('is_active')
                ->label('Đang hợp tác')
                ->default(true)
                ->inline(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Mã')
                    ->badge()
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Tên NCC')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('phone')
                    ->label('SĐT')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('tax_code')
                    ->label('MST')
                    ->toggleable()
                    ->placeholder('—'),
                TextColumn::make('payment_terms_days')
                    ->label('Công nợ')
                    ->suffix(' ngày')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('HT')
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Đang hợp tác')
                    ->trueLabel('Chỉ đang hợp tác')
                    ->falseLabel('Ngừng hợp tác')
                    ->placeholder('Tất cả'),
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
            'index' => ListSuppliers::route('/'),
            'create' => CreateSupplier::route('/create'),
            'edit' => EditSupplier::route('/{record}/edit'),
        ];
    }
}
