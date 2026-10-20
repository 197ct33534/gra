<?php

namespace App\Filament\Resources\CustomerFeedback;

use App\Filament\Resources\CustomerFeedback\Pages\ListCustomerFeedback;
use App\Models\WorkOrder;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
                TextColumn::make('code')->label('RO')->searchable(),
                TextColumn::make('vehicle.license_plate')->label('Biển số'),
                TextColumn::make('vehicle.customer.name')->label('Khách'),
                TextColumn::make('feedback_score')
                    ->label('Điểm')
                    ->formatStateUsing(fn (?int $state): string => $state ? str_repeat('★', $state).str_repeat('☆', 5 - $state) : '—')
                    ->sortable(),
                TextColumn::make('feedback_comment')->label('Nhận xét')->limit(50)->wrap(),
                TextColumn::make('feedback_at')->label('Lúc')->dateTime('d/m/Y H:i')->sortable(),
            ])
            ->defaultSort('feedback_at', 'desc');
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
