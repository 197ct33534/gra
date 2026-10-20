<?php

namespace App\Filament\Resources\Services;

use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\Services\Pages\CreateService;
use App\Filament\Resources\Services\Pages\EditService;
use App\Filament\Resources\Services\Pages\ListServices;
use App\Models\Service;
use App\Models\User;
use App\Services\PayrollService;
use App\Support\VietnameseMoney;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ServiceResource extends Resource
{
    protected static ?string $model = Service::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = 'Kho & dịch vụ';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Dịch vụ';

    protected static ?string $modelLabel = 'dịch vụ';

    protected static ?string $pluralModelLabel = 'dịch vụ';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->label('Mã dịch vụ')
                    ->required()
                    ->maxLength(50)
                    ->unique(ignoreRecord: true)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $state, TextInput $component): void {
                        if (blank($state)) {
                            return;
                        }

                        $component->state(mb_strtoupper(trim($state)));
                    })
                    ->dehydrateStateUsing(
                        fn (?string $state): ?string => filled($state) ? mb_strtoupper(trim($state)) : $state,
                    ),
                TextInput::make('name')
                    ->label('Tên dịch vụ')
                    ->required()
                    ->maxLength(255),
                MoneyInput::make('labor_cost')
                    ->label('Tiền công')
                    ->required(),
                MoneyInput::make('technician_payout')
                    ->label('Khoán cố định cho thợ / lần làm')
                    ->helperText(fn (): string => 'Để trống: thợ hưởng theo % tiền công (mặc định '.PayrollService::defaultRate().'%).')
                    ->rules(['nullable', 'integer', 'min:0']),
                TextInput::make('standard_time_minutes')
                    ->label('Thời gian chuẩn')
                    ->required()
                    ->numeric()
                    ->minValue(1)
                    ->suffix('phút')
                    ->default(60),
                TextInput::make('warranty_months')
                    ->label('BH công (tháng)')
                    ->numeric()
                    ->minValue(0),
                TextInput::make('warranty_km')
                    ->label('BH công (km)')
                    ->numeric()
                    ->minValue(0),
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
                TextColumn::make('name')
                    ->label('Tên dịch vụ')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('labor_cost')
                    ->label('Tiền công')
                    ->formatStateUsing(fn (mixed $state): string => VietnameseMoney::format($state))
                    ->sortable(),
                TextColumn::make('technician_payout')
                    ->label('Khoán thợ')
                    ->formatStateUsing(fn (mixed $state): string => VietnameseMoney::format($state))
                    ->placeholder(fn (): string => 'Theo % ('.PayrollService::defaultRate().'%)')
                    ->visible(fn (): bool => User::currentCanSeeCosts()),
                TextColumn::make('standard_time_minutes')
                    ->label('Thời gian chuẩn')
                    ->suffix(' phút')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('code');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServices::route('/'),
            'create' => CreateService::route('/create'),
            'edit' => EditService::route('/{record}/edit'),
        ];
    }
}
