<?php

namespace App\Filament\Resources\WorkOrders\Pages;

use App\Filament\Resources\WorkOrders\WorkOrderResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewWorkOrder extends ViewRecord
{
    protected static string $resource = WorkOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            WorkOrderResource::printJobCardAction(),
            WorkOrderResource::printQuotationAction(),
            WorkOrderResource::createInvoiceAction(),
            WorkOrderResource::printInvoiceAction(),
            WorkOrderResource::approveQuoteAction(),
            WorkOrderResource::approveAddendumAction(),
            WorkOrderResource::cancelApprovalAction(),
            WorkOrderResource::completeDeliveryAction(),
            EditAction::make(),
        ];
    }
}
