<?php

namespace App\Filament\Resources\GoodsReceipts\Pages;

use App\Filament\Resources\GoodsReceipts\GoodsReceiptResource;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchasingService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class CreateGoodsReceipt extends CreateRecord
{
    protected static string $resource = GoodsReceiptResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $user = User::current();
        abort_if($user === null, 403);

        $supplier = Supplier::query()->findOrFail($data['supplier_id']);
        $lines = [];

        foreach ($data['lines'] ?? [] as $line) {
            $lines[] = [
                'part_id' => (int) $line['part_id'],
                'quantity' => $line['quantity'],
                'unit_cost' => $line['unit_cost'],
            ];
        }

        return app(PurchasingService::class)->receiveGoods(
            $supplier,
            $lines,
            $user->id,
            null,
            $data['invoice_no_supplier'] ?? null,
            isset($data['invoice_date']) ? Carbon::parse($data['invoice_date']) : null,
            $data['notes'] ?? null,
            isset($data['vat_rate']) ? (int) $data['vat_rate'] : null,
            isset($data['received_at']) ? Carbon::parse($data['received_at']) : null,
        );
    }
}
