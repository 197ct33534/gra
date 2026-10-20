<?php

namespace App\Filament\Resources\WorkOrders\Pages;

use App\Enums\WorkOrderStatus;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Services\WorkOrderService;
use Filament\Resources\Pages\CreateRecord;

class CreateWorkOrder extends CreateRecord
{
    protected static string $resource = WorkOrderResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['code'] = app(WorkOrderService::class)->nextCode();
        $data['status'] = WorkOrderStatus::Reception->value;

        return $data;
    }

    protected function afterCreate(): void
    {
        app(WorkOrderService::class)->recalculateTotals($this->getRecord());
    }
}
