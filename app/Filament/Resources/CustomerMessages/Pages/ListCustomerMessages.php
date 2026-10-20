<?php

namespace App\Filament\Resources\CustomerMessages\Pages;

use App\Filament\Resources\CustomerMessages\CustomerMessageResource;
use Filament\Resources\Pages\ListRecords;

class ListCustomerMessages extends ListRecords
{
    protected static string $resource = CustomerMessageResource::class;
}
