<?php

namespace App\Filament\Resources\CreditNotes;

use App\Filament\Resources\CreditNotes\Pages\ListCreditNotes;
use App\Models\CreditNote;
use App\Support\VietnameseMoney;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class CreditNoteResource extends Resource
{
    protected static ?string $model = CreditNote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    protected static string|UnitEnum|null $navigationGroup = 'Thu ngân';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Credit note';

    protected static ?string $modelLabel = 'credit note';

    protected static ?string $pluralModelLabel = 'credit note';

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
                TextColumn::make('code')->label('Mã')->searchable(),
                TextColumn::make('invoice.invoice_number')->label('Hóa đơn'),
                TextColumn::make('workOrder.code')->label('RO'),
                TextColumn::make('amount')
                    ->label('Số hoàn')
                    ->formatStateUsing(fn (mixed $s): string => VietnameseMoney::format($s)),
                TextColumn::make('reason')->label('Lý do')->limit(40),
                IconColumn::make('restock')->label('Hoàn kho')->boolean(),
                TextColumn::make('status')->label('TT')->badge(),
                TextColumn::make('issued_at')->label('Ngày')->dateTime('d/m/Y H:i'),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['invoice', 'workOrder', 'creator']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCreditNotes::route('/'),
        ];
    }
}
