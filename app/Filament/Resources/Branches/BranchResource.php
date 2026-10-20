<?php

namespace App\Filament\Resources\Branches;

use App\Filament\Resources\Branches\Pages\CreateBranch;
use App\Filament\Resources\Branches\Pages\EditBranch;
use App\Filament\Resources\Branches\Pages\ListBranches;
use App\Models\Branch;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class BranchResource extends Resource
{
    protected static ?string $model = Branch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = 'Quản trị';

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = 'Chi nhánh';

    protected static ?string $modelLabel = 'chi nhánh';

    protected static ?string $pluralModelLabel = 'chi nhánh';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label('Mã')
                ->required()
                ->maxLength(20)
                ->unique(ignoreRecord: true),
            TextInput::make('name')
                ->label('Tên chi nhánh')
                ->required()
                ->maxLength(255),
            TextInput::make('address')
                ->label('Địa chỉ')
                ->maxLength(255),
            TextInput::make('hotline')
                ->label('Hotline')
                ->tel()
                ->maxLength(20),
            Toggle::make('is_active')
                ->label('Đang hoạt động')
                ->default(true)
                ->inline(false),
            Toggle::make('is_default')
                ->label('Chi nhánh mặc định')
                ->helperText('Dùng khi nhân viên chưa gán chi nhánh.')
                ->inline(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('Mã')->badge(),
                TextColumn::make('name')->label('Tên')->searchable(),
                TextColumn::make('hotline')->label('Hotline')->placeholder('—'),
                IconColumn::make('is_active')->label('Active')->boolean(),
                IconColumn::make('is_default')->label('Mặc định')->boolean(),
                TextColumn::make('users_count')->label('NV')->counts('users'),
            ])
            ->recordActions([EditAction::make()])
            ->defaultSort('id');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBranches::route('/'),
            'create' => CreateBranch::route('/create'),
            'edit' => EditBranch::route('/{record}/edit'),
        ];
    }
}
