<?php

namespace App\Filament\Resources\Branches\Pages;

use App\Filament\Resources\Branches\BranchResource;
use App\Models\Branch;
use Filament\Resources\Pages\CreateRecord;

class CreateBranch extends CreateRecord
{
    protected static string $resource = BranchResource::class;

    protected function afterCreate(): void
    {
        /** @var Branch $record */
        $record = $this->record;
        if ($record->is_default) {
            Branch::query()->whereKeyNot($record->id)->update(['is_default' => false]);
        }
    }
}
