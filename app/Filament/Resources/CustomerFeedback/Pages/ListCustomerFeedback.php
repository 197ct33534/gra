<?php

namespace App\Filament\Resources\CustomerFeedback\Pages;

use App\Filament\Resources\CustomerFeedback\CustomerFeedbackResource;
use Filament\Resources\Pages\ListRecords;

class ListCustomerFeedback extends ListRecords
{
    protected static string $resource = CustomerFeedbackResource::class;
}
