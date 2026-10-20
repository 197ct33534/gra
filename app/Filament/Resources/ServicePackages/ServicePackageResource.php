<?php

namespace App\Filament\Resources\ServicePackages;

use App\Enums\WorkOrderItemType;
use App\Filament\Resources\ServicePackages\Pages\CreateServicePackage;
use App\Filament\Resources\ServicePackages\Pages\EditServicePackage;
use App\Filament\Resources\ServicePackages\Pages\ListServicePackages;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\ServicePackage;
use App\Models\User;
use App\Support\VietnameseMoney;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ServicePackageResource extends Resource
{
    protected static ?string $model = ServicePackage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Kho & dịch vụ';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Gói dịch vụ';

    protected static ?string $modelLabel = 'gói dịch vụ';

    protected static ?string $pluralModelLabel = 'gói dịch vụ';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label('Mã gói')
                ->required()
                ->maxLength(50)
                ->unique(ignoreRecord: true)
                ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? mb_strtoupper(trim($state)) : $state),
            TextInput::make('name')
                ->label('Tên gói')
                ->placeholder('VD: Bảo dưỡng 10.000 km Vios')
                ->required()
                ->maxLength(255),
            TextInput::make('vehicle_hint')
                ->label('Dòng xe phù hợp')
                ->placeholder('VD: Toyota Vios, Yaris')
                ->maxLength(255),
            TextInput::make('sort_order')
                ->label('Thứ tự hiển thị')
                ->integer()
                ->default(0),
            Toggle::make('is_active')
                ->label('Đang dùng')
                ->default(true)
                ->inline(false),
            Repeater::make('items')
                ->label('Hạng mục trong gói')
                ->relationship()
                ->schema([
                    Select::make('item_type')
                        ->label('Loại')
                        ->options([
                            WorkOrderItemType::Service->value => 'Dịch vụ',
                            WorkOrderItemType::Part->value => 'Phụ tùng',
                        ])
                        ->required()
                        ->native(false)
                        ->live()
                        ->afterStateUpdated(fn (Set $set): mixed => $set('item_id', null)),
                    Select::make('item_id')
                        ->label('Hạng mục')
                        ->options(fn (Get $get): array => WorkOrderResource::itemOptions($get('item_type')))
                        ->searchable()
                        ->required()
                        ->native(false),
                    TextInput::make('quantity')
                        ->label('Số lượng')
                        ->numeric()
                        ->minValue(0.01)
                        ->default(1)
                        ->required(),
                ])
                ->columns(3)
                ->columnSpanFull()
                ->minItems(1)
                ->addActionLabel('Thêm hạng mục'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Mã gói')
                    ->badge()
                    ->color('gray')
                    ->searchable(),
                TextColumn::make('name')
                    ->label('Tên gói')
                    ->description(fn (ServicePackage $record): ?string => $record->vehicle_hint)
                    ->searchable(),
                TextColumn::make('items_count')
                    ->label('Số hạng mục')
                    ->counts('items'),
                TextColumn::make('catalog_total')
                    ->label('Giá theo bảng giá')
                    ->state(fn (ServicePackage $record): string => VietnameseMoney::format($record->catalogTotal())),
                ToggleColumn::make('is_active')
                    ->label('Đang dùng')
                    ->disabled(fn (): bool => ! (User::current()?->isAdmin() ?? false)),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('sort_order');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('items');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServicePackages::route('/'),
            'create' => CreateServicePackage::route('/create'),
            'edit' => EditServicePackage::route('/{record}/edit'),
        ];
    }
}
