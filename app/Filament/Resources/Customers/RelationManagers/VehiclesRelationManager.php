<?php

namespace App\Filament\Resources\Customers\RelationManagers;

use App\Filament\Resources\Vehicles\VehicleResource;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class VehiclesRelationManager extends RelationManager
{
    protected static string $relationship = 'vehicles';

    protected static bool $isLazy = false;

    protected static ?string $title = 'Xe';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedTruck;

    public function form(Schema $schema): Schema
    {
        return $schema->components(VehicleResource::formComponents(includeCustomer: false));
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns(VehicleResource::tableColumns(includeCustomer: false))
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
