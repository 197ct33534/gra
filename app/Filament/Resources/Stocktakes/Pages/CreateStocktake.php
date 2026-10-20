<?php

namespace App\Filament\Resources\Stocktakes\Pages;

use App\Filament\Resources\Stocktakes\StocktakeResource;
use App\Models\User;
use App\Services\InventoryService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateStocktake extends CreateRecord
{
    protected static string $resource = StocktakeResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $user = User::current();
        abort_if($user === null || ! $user->isAdmin(), 403);

        return app(InventoryService::class)->createStocktake(
            $user->id,
            $data['note'] ?? null,
        );
    }

    protected function getRedirectUrl(): string
    {
        return StocktakeResource::getUrl('edit', ['record' => $this->record]);
    }
}
