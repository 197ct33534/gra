<?php

namespace App\Filament\Resources\FleetContracts\Pages;

use App\Filament\Resources\FleetContracts\FleetContractResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFleetContract extends EditRecord
{
    protected static string $resource = FleetContractResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
