<?php

namespace App\Filament\Resources\Stocktakes\Pages;

use App\Filament\Resources\Stocktakes\StocktakeResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewStocktake extends ViewRecord
{
    protected static string $resource = StocktakeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            StocktakeResource::startCountingAction(),
            StocktakeResource::applyAction(),
            StocktakeResource::cancelAction(),
            EditAction::make()
                ->visible(fn (): bool => $this->getRecord()->status->canEditCounts()),
        ];
    }
}
