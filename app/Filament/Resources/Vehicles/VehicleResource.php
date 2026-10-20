<?php

namespace App\Filament\Resources\Vehicles;

use App\Filament\Resources\Vehicles\Pages\CreateVehicle;
use App\Filament\Resources\Vehicles\Pages\EditVehicle;
use App\Filament\Resources\Vehicles\Pages\ListVehicles;
use App\Filament\Resources\Vehicles\Pages\ViewVehicle;
use App\Filament\Resources\Vehicles\RelationManagers\WorkOrdersRelationManager;
use App\Helpers\LicensePlateHelper;
use App\Models\Customer;
use App\Models\Vehicle;
use BackedEnum;
use Closure;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class VehicleResource extends Resource
{
    protected static ?string $model = Vehicle::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Tiếp nhận';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Xe';

    protected static ?string $modelLabel = 'xe';

    protected static ?string $pluralModelLabel = 'xe';

    protected static ?string $recordTitleAttribute = 'license_plate';

    public static function form(Schema $schema): Schema
    {
        return $schema->components(self::formComponents(includeCustomer: true));
    }

    /**
     * @return array<int, Component>
     */
    public static function formComponents(bool $includeCustomer): array
    {
        $components = [];

        if ($includeCustomer) {
            $components[] = Select::make('customer_id')
                ->label('Khách hàng')
                ->relationship('customer', 'name')
                ->getOptionLabelFromRecordUsing(
                    fn (Customer $record): string => "{$record->name} — {$record->phone}",
                )
                ->searchable(['name', 'phone'])
                ->preload()
                ->required()
                ->native(false);
        }

        $components[] = self::licensePlateInput();
        $components[] = TextInput::make('brand')
            ->label('Hãng xe')
            ->required()
            ->maxLength(255);
        $components[] = TextInput::make('model')
            ->label('Dòng xe')
            ->required()
            ->maxLength(255);
        $components[] = TextInput::make('year')
            ->label('Năm sản xuất')
            ->numeric()
            ->minValue(1980)
            ->maxValue((int) date('Y') + 1);
        $components[] = TextInput::make('vin')
            ->label('Số khung (VIN)')
            ->maxLength(17)
            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? mb_strtoupper(trim($state)) : null);
        $components[] = TextInput::make('current_odo')
            ->label('ODO hiện tại')
            ->numeric()
            ->required()
            ->minValue(0)
            ->suffix('km')
            ->default(0);
        $components[] = \Filament\Forms\Components\DatePicker::make('registration_expires_at')
            ->label('Hạn đăng ký xe')
            ->native(false);
        $components[] = \Filament\Forms\Components\DatePicker::make('insurance_expires_at')
            ->label('Hạn BH TNDS / vật chất')
            ->native(false);
        $components[] = \Filament\Forms\Components\DatePicker::make('inspection_expires_at')
            ->label('Hạn đăng kiểm')
            ->native(false);
        $components[] = TextInput::make('service_interval_km')
            ->label('Chu kỳ bảo dưỡng (km)')
            ->integer()
            ->minValue(1000)
            ->suffix('km')
            ->placeholder(fn (): string => 'Mặc định '.number_format((int) config('garage.maintenance.interval_km'), 0, ',', '.'))
            ->helperText('VD: nhớt tổng hợp 10.000 km. Để trống dùng mặc định của gara.');
        $components[] = TextInput::make('service_interval_months')
            ->label('Chu kỳ bảo dưỡng (tháng)')
            ->integer()
            ->minValue(1)
            ->maxValue(24)
            ->suffix('tháng')
            ->placeholder(fn (): string => 'Mặc định '.(int) config('garage.maintenance.interval_months'));

        return $components;
    }

    public static function licensePlateInput(): TextInput
    {
        return TextInput::make('license_plate')
            ->label('Biển số')
            ->required()
            ->maxLength(20)
            ->rule(fn (?Vehicle $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                $plate = LicensePlateHelper::clean(is_string($value) ? $value : null);

                if ($plate === '') {
                    return;
                }

                $duplicate = Vehicle::query()
                    ->whereRaw(LicensePlateHelper::cleanedColumnSql('license_plate').' = ?', [$plate])
                    ->when($record instanceof Vehicle, fn (Builder $query): Builder => $query->whereKeyNot($record->getKey()))
                    ->value('license_plate');

                if (is_string($duplicate)) {
                    $fail("Biển số này đã có trong hệ thống ({$duplicate}).");
                }
            })
            ->live(onBlur: true)
            ->afterStateUpdated(function (?string $state, TextInput $component): void {
                if (blank($state)) {
                    return;
                }

                $component->state(self::normalizeLicensePlate($state));
            })
            ->mutateStateForValidationUsing(
                fn (?string $state): ?string => filled($state) ? self::normalizeLicensePlate($state) : $state,
            )
            ->dehydrateStateUsing(
                fn (?string $state): ?string => filled($state) ? self::normalizeLicensePlate($state) : $state,
            );
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Thông tin xe')
                ->schema([
                    TextEntry::make('license_plate')->label('Biển số')->badge()->color('primary'),
                    TextEntry::make('customer.name')->label('Khách hàng'),
                    TextEntry::make('customer.phone')->label('Số điện thoại'),
                    TextEntry::make('brand')->label('Hãng xe'),
                    TextEntry::make('model')->label('Dòng xe'),
                    TextEntry::make('year')->label('Năm sản xuất'),
                    TextEntry::make('current_odo')
                        ->label('ODO hiện tại')
                        ->formatStateUsing(fn (mixed $state): string => number_format((int) $state, 0, ',', '.').' km'),
                    TextEntry::make('maintenance_status')
                        ->label('Bảo dưỡng')
                        ->state(fn (Vehicle $record): string => $record->isMaintenanceDue() ? 'Đến hạn bảo dưỡng' : 'Bình thường')
                        ->badge()
                        ->color(fn (string $state): string => $state === 'Đến hạn bảo dưỡng' ? 'danger' : 'success'),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ...self::tableColumns(includeCustomer: true),
                TextColumn::make('maintenance_status')
                    ->label('Bảo dưỡng')
                    ->badge()
                    ->state(fn (Vehicle $record): string => $record->isMaintenanceDue() ? 'Đến hạn bảo dưỡng' : 'Bình thường')
                    ->color(fn (string $state): string => $state === 'Đến hạn bảo dưỡng' ? 'danger' : 'success'),
            ])
            ->filters([
                Filter::make('maintenance_due')
                    ->label('Xe đến hạn bảo dưỡng')
                    ->query(fn (Builder $query): Builder => $query->maintenanceDue()),
            ])
            ->recordUrl(fn (Vehicle $record): string => static::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('license_plate');
    }

    /**
     * @return array<int, TextColumn>
     */
    public static function tableColumns(bool $includeCustomer): array
    {
        $columns = [
            TextColumn::make('license_plate')
                ->label('Biển số')
                ->badge()
                ->color('primary')
                ->weight(FontWeight::Bold)
                ->searchable(query: fn (Builder $query, string $search): Builder => LicensePlateHelper::applyVehicleSearch($query, $search)),
            TextColumn::make('brand')
                ->label('Hãng / Dòng xe')
                ->formatStateUsing(fn (Vehicle $record): string => "{$record->brand} {$record->model}"),
        ];

        if ($includeCustomer) {
            $columns[] = TextColumn::make('customer.name')
                ->label('Khách hàng')
                ->sortable();
        }

        $columns[] = TextColumn::make('current_odo')
            ->label('ODO hiện tại')
            ->numeric(decimalPlaces: 0, locale: 'vi')
            ->suffix(' km')
            ->sortable();

        return $columns;
    }

    public static function getRelations(): array
    {
        return [
            WorkOrdersRelationManager::class,
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['customer', 'workOrders.items']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVehicles::route('/'),
            'create' => CreateVehicle::route('/create'),
            'view' => ViewVehicle::route('/{record}'),
            'edit' => EditVehicle::route('/{record}/edit'),
        ];
    }

    public static function normalizeLicensePlate(string $licensePlate): string
    {
        return mb_strtoupper(trim($licensePlate));
    }
}
