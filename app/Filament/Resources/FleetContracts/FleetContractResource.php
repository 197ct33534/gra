<?php

namespace App\Filament\Resources\FleetContracts;

use App\Enums\CustomerType;
use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\FleetContracts\Pages\CreateFleetContract;
use App\Filament\Resources\FleetContracts\Pages\EditFleetContract;
use App\Filament\Resources\FleetContracts\Pages\ListFleetContracts;
use App\Filament\Support\PhoneTable;
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
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
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
                TextColumn::make('code')->label('Mã')->visibleFrom('md')->searchable(),
                TextColumn::make('customer.name')
                    ->label('Công ty')
                    ->searchable()
                    // Điện thoại: các cột mã / hợp đồng / CK / hạn mức / hết hạn bị ẩn nên ghi gọn dưới tên công ty.
                    ->description(fn (FleetContract $record): Htmlable => self::phoneSummary($record)),
                TextColumn::make('name')->label('Hợp đồng')->limit(30)->visibleFrom('md'),
                TextColumn::make('discount_percent')->label('CK %')->visibleFrom('md'),
                TextColumn::make('credit_limit')
                    ->label('Hạn mức')
                    ->visibleFrom('md')
                    ->formatStateUsing(fn (mixed $s): string => $s === null ? '—' : VietnameseMoney::format($s)),
                TextColumn::make('ends_at')->label('Hết hạn')->date('d/m/Y')->placeholder('Không hạn')->visibleFrom('md'),
                IconColumn::make('is_active')->label('HT')->boolean(),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('id', 'desc');
    }

    /**
     * Dòng phụ dưới tên công ty, chỉ hiện trên điện thoại: mã · hợp đồng · CK % · hạn mức · hết hạn.
     */
    public static function phoneSummary(FleetContract $record): Htmlable
    {
        return PhoneTable::summary([
            $record->code,
            filled($record->name) ? Str::limit((string) $record->name, 30) : null,
            $record->discount_percent !== null ? 'CK '.(float) $record->discount_percent.'%' : null,
            $record->credit_limit !== null ? 'Hạn mức '.VietnameseMoney::format($record->credit_limit) : null,
            $record->ends_at !== null ? 'Hết hạn '.$record->ends_at->format('d/m/Y') : 'Không hạn',
        ]);
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
