<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Enums\PurchaseOrderStatus;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Models\User;
use App\Services\PurchasingService;
use Filament\Resources\Pages\CreateRecord;

class CreatePurchaseOrder extends CreateRecord
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = User::current();

        $data['code'] = app(PurchasingService::class)->nextPurchaseOrderCode();
        $data['status'] = PurchaseOrderStatus::Draft;
        $data['created_by'] = $user?->id;
        $data['subtotal'] = 0;

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var \App\Models\PurchaseOrder $record */
        $record = $this->record;
        $record->recalculateSubtotal();
    }
}
