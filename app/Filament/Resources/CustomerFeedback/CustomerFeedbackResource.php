<?php

namespace App\Filament\Resources\CustomerFeedback;

use App\Filament\Resources\CustomerFeedback\Pages\ListCustomerFeedback;
use App\Filament\Support\PhoneTable;
use App\Models\WorkOrder;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use UnitEnum;

class CustomerFeedbackResource extends Resource
{
    protected static ?string $model = WorkOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStar;

    protected static string|UnitEnum|null $navigationGroup = 'Tiếp nhận';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Đánh giá KH';

    protected static ?string $modelLabel = 'đánh giá';

    protected static ?string $pluralModelLabel = 'đánh giá';

    protected static ?string $slug = 'customer-feedback';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('RO')->visibleFrom('md')->searchable(),
                TextColumn::make('vehicle.license_plate')
                    ->label('Biển số')
                    // Điện thoại: các cột RO / khách / nhận xét / lúc bị ẩn nên ghi gọn dưới biển số.
                    ->description(fn (WorkOrder $record): Htmlable => self::phoneSummary($record)),
                TextColumn::make('vehicle.customer.name')->label('Khách')->visibleFrom('md'),
                TextColumn::make('feedback_score')
                    ->label('Điểm')
                    ->formatStateUsing(fn (?int $state): string => $state ? str_repeat('★', $state).str_repeat('☆', 5 - $state) : '—')
                    ->sortable(),
                TextColumn::make('feedback_comment')->label('Nhận xét')->limit(50)->wrap()->visibleFrom('md'),
                TextColumn::make('feedback_at')->label('Lúc')->dateTime('d/m/Y H:i')->visibleFrom('md')->sortable(),
            ])
            ->defaultSort('feedback_at', 'desc');
    }

    /**
     * Dòng phụ dưới biển số, chỉ hiện trên điện thoại: mã RO · khách · lúc đánh giá · nhận xét (rút gọn).
     */
    public static function phoneSummary(WorkOrder $record): Htmlable
    {
        return PhoneTable::summary([
            $record->code,
            $record->vehicle?->customer?->name,
            $record->feedback_at?->timezone((string) config('garage.timezone'))->format('d/m/Y H:i'),
            filled($record->feedback_comment) ? '“'.Str::limit((string) $record->feedback_comment, 50).'”' : null,
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereNotNull('feedback_at')
            ->with(['vehicle.customer']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerFeedback::route('/'),
        ];
    }
}
