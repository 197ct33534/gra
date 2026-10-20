<?php

namespace App\Filament\Resources\Branches\Pages;

use App\Filament\Resources\Branches\BranchResource;
use App\Models\Branch;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditBranch extends EditRecord
{
    protected static string $resource = BranchResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function afterSave(): void
    {
        /** @var Branch $record */
        $record = $this->record;
        if ($record->is_default) {
            Branch::query()->whereKeyNot($record->id)->update(['is_default' => false]);
        }
    }
}
