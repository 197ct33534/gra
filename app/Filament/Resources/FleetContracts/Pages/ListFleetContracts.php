<?php

namespace App\Filament\Resources\FleetContracts\Pages;

use App\Filament\Resources\FleetContracts\FleetContractResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFleetContracts extends ListRecords
{
    protected static string $resource = FleetContractResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
