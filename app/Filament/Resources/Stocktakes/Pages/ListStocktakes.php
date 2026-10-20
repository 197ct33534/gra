<?php

namespace App\Filament\Resources\Stocktakes\Pages;

use App\Filament\Resources\Stocktakes\StocktakeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStocktakes extends ListRecords
{
    protected static string $resource = StocktakeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Tạo phiếu kiểm kê'),
        ];
    }
}
