<?php

namespace App\Filament\Resources\PartCategories;

use App\Filament\Resources\PartCategories\Pages\CreatePartCategory;
use App\Filament\Resources\PartCategories\Pages\EditPartCategory;
use App\Filament\Resources\PartCategories\Pages\ListPartCategories;
use App\Models\PartCategory;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class PartCategoryResource extends Resource
{
    protected static ?string $model = PartCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Kho & dịch vụ';

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = 'Nhóm phụ tùng';

    protected static ?string $modelLabel = 'nhóm phụ tùng';

    protected static ?string $pluralModelLabel = 'nhóm phụ tùng';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Tên nhóm')
                ->required()
                ->maxLength(255),
            Select::make('parent_id')
                ->label('Nhóm cha')
                ->relationship('parent', 'name')
                ->searchable()
                ->preload()
                ->native(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Tên')->searchable()->sortable(),
                TextColumn::make('parent.name')->label('Nhóm cha')->placeholder('—'),
                TextColumn::make('parts_count')->label('Số mã')->counts('parts'),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPartCategories::route('/'),
            'create' => CreatePartCategory::route('/create'),
            'edit' => EditPartCategory::route('/{record}/edit'),
        ];
    }
}
