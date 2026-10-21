<?php

namespace App\Filament\Resources\FleetContracts;

use App\Enums\CustomerType;
use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\FleetContracts\Pages\CreateFleetContract;
use App\Filament\Resources\FleetContracts\Pages\EditFleetContract;
use App\Filament\Resources\FleetContracts\Pages\ListFleetContracts;
use App\Models\FleetContract;
use App\Support\VietnameseMoney;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class FleetContractResource extends Resource
{
    protected static ?string $model = FleetContract::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Tiếp nhận';

    protected static ?int $navigationSort = 7;

    protected static ?string $navigationLabel = 'Hợp đồng fleet';

    protected static ?string $modelLabel = 'hợp đồng fleet';

    protected static ?string $pluralModelLabel = 'hợp đồng fleet';

    protected static ?string $recordTitleAttribute = 'code';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('customer_id')
                ->label('Khách công ty')
                ->relationship(
                    'customer',
                    'name',
                    // Filament truyền tham số theo tên: phải là $query.
                    fn (Builder $query): Builder => $query->where('customer_type', CustomerType::Company)->orderBy('name'),
                )
                ->searchable()
                ->preload()
                ->required()
                ->native(false),
            TextInput::make('code')
                ->label('Mã HĐ')
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(30),
            TextInput::make('name')
                ->label('Tên hợp đồng')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),
            DatePicker::make('starts_at')->label('Hiệu lực từ')->required()->native(false),
            DatePicker::make('ends_at')->label('Đến ngày')->native(false),
            TextInput::make('discount_percent')
                ->label('Chiết khấu %')
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->default(0),
            MoneyInput::make('credit_limit')->label('Hạn mức công nợ (HĐ)'),
            TextInput::make('payment_terms_days')
                ->label('Kỳ hạn thanh toán (ngày)')
                ->numeric()
                ->minValue(0)
                ->default(30),
            Toggle::make('is_active')->label('Đang hiệu lực')->default(true)->inline(false),
            Textarea::make('notes')->label('Ghi chú')->rows(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('Mã')->searchable(),
                TextColumn::make('customer.name')->label('Công ty')->searchable(),
                TextColumn::make('name')->label('Hợp đồng')->limit(30),
                TextColumn::make('discount_percent')->label('CK %'),
                TextColumn::make('credit_limit')
                    ->label('Hạn mức')
                    ->formatStateUsing(fn (mixed $s): string => $s === null ? '—' : VietnameseMoney::format($s)),
                TextColumn::make('ends_at')->label('Hết hạn')->date('d/m/Y')->placeholder('Không hạn'),
                IconColumn::make('is_active')->label('HT')->boolean(),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFleetContracts::route('/'),
            'create' => CreateFleetContract::route('/create'),
            'edit' => EditFleetContract::route('/{record}/edit'),
        ];
    }
}
