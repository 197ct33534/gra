<?php

namespace App\Filament\Resources\Stocktakes\Pages;

use App\Filament\Resources\Stocktakes\StocktakeResource;
use App\Models\Stocktake;
use Filament\Resources\Pages\EditRecord;

class EditStocktake extends EditRecord
{
    protected static string $resource = StocktakeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            StocktakeResource::startCountingAction(),
            StocktakeResource::applyAction(),
            StocktakeResource::cancelAction(),
        ];
    }

    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();

        /** @var Stocktake $record */
        $record = $this->getRecord();
        abort_unless($record->status->canEditCounts(), 403, 'Phiếu này không còn sửa được.');
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->record->loadMissing('items.part');

        return $data;
    }
}
