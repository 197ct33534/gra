<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\PurchaseOrder;
use Filament\Resources\Pages\EditRecord;

class EditPurchaseOrder extends EditRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PurchaseOrderResource::markOrderedAction(),
            PurchaseOrderResource::cancelAction(),
        ];
    }

    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();

        /** @var PurchaseOrder $record */
        $record = $this->getRecord();

        abort_unless($record->status === PurchaseOrderStatus::Draft, 403, 'Chỉ sửa được đơn nháp.');
    }

    protected function afterSave(): void
    {
        /** @var PurchaseOrder $record */
        $record = $this->record;
        $record->recalculateSubtotal();
    }
}
