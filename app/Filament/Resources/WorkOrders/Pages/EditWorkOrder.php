<?php

namespace App\Filament\Resources\WorkOrders\Pages;

use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Services\WorkOrderService;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditWorkOrder extends EditRecord
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
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        app(WorkOrderService::class)->recalculateTotals($this->getRecord());
    }
}
