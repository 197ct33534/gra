<?php

namespace App\Filament\Resources\Parts\Pages;

use App\Filament\Resources\Parts\PartResource;
use App\Models\Part;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\PartCostHistoryService;
use App\Support\VietnameseMoney;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditPart extends EditRecord
{
    protected static string $resource = PartResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);

        if (! $this->getRecord() instanceof Part || ! User::currentCanSeeCosts()) {
            return;
        }

        $summary = app(PartCostHistoryService::class)->summary($this->getRecord());
        $trendLabel = match ($summary['trend']) {
            'up' => '↗ tăng',
            'down' => '↘ giảm',
            default => '→ ổn định',
        };

        Notification::make()
            ->title('Xu hướng giá vốn: '.$trendLabel)
            ->body(
                'Hiện tại '.VietnameseMoney::format($summary['current'])
                .($summary['last'] !== null ? ' · nhập gần nhất '.VietnameseMoney::format($summary['last']) : '')
                .($summary['avg'] !== null ? ' · TB '.VietnameseMoney::format($summary['avg']) : '')
                .' ('.$summary['samples'].' lần nhập)'
            )
            ->info()
            ->send();
    }

    /**
     * Tồn kho không được ghi thẳng: đi qua phiếu điều chỉnh kiểm kê để có dấu vết trên thẻ kho.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if ($record instanceof Part && array_key_exists('stock_quantity', $data)) {
            app(InventoryService::class)->adjustStock(
                $record,
                (int) $data['stock_quantity'],
                (int) auth()->id(),
                $this->data['adjustment_reason'] ?? null,
            );
        }

        unset($data['stock_quantity']);

        return parent::handleRecordUpdate($record, $data);
    }
}
