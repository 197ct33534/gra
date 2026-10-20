<?php

namespace App\Filament\Pages;

use App\Enums\StockTransferStatus;
use App\Models\Branch;
use App\Models\Part;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\StockTransferService;
use BackedEnum;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

class StockTransfers extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Kho & dịch vụ';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Chuyển kho CN';

    protected static ?string $title = 'Điều chuyển kho liên chi nhánh';

    protected static ?string $slug = 'stock-transfers';

    protected string $view = 'filament.pages.stock-transfers';

    public ?int $from_branch_id = null;

    public ?int $to_branch_id = null;

    public ?string $notes = null;

    /**
     * @var list<array{part_id: ?int, quantity: int|string|null}>
     */
    public array $lines = [];

    public static function canAccess(): bool
    {
        return User::current()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        $this->from_branch_id = Branch::defaultId();
        $this->lines = [['part_id' => null, 'quantity' => 1]];
    }

    /**
     * @return Collection<int, StockTransfer>
     */
    public function getTransfers(): Collection
    {
        return StockTransfer::query()
            ->with(['fromBranch', 'toBranch', 'items'])
            ->orderByDesc('id')
            ->limit(40)
            ->get();
    }

    /**
     * @return array<int, string>
     */
    public function branchOptions(): array
    {
        return Branch::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * @return array<int, string>
     */
    public function partOptions(): array
    {
        if ($this->from_branch_id === null) {
            return [];
        }

        return Part::query()
            ->where('branch_id', $this->from_branch_id)
            ->where('stock_quantity', '>', 0)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Part $part): array => [
                $part->id => $part->sku.' — '.$part->name.' (khả dụng '.$part->availableQuantity().')',
            ])
            ->all();
    }

    public function addLine(): void
    {
        $this->lines[] = ['part_id' => null, 'quantity' => 1];
    }

    public function createDraft(): void
    {
        $user = User::current();
        if ($user === null) {
            return;
        }

        $lines = collect($this->lines)
            ->filter(fn (array $row): bool => filled($row['part_id'] ?? null) && (int) ($row['quantity'] ?? 0) > 0)
            ->map(fn (array $row): array => [
                'part_id' => (int) $row['part_id'],
                'quantity' => (int) $row['quantity'],
            ])
            ->values()
            ->all();

        try {
            $from = Branch::query()->findOrFail((int) $this->from_branch_id);
            $to = Branch::query()->findOrFail((int) $this->to_branch_id);
            $transfer = app(StockTransferService::class)->createDraft($from, $to, $lines, $user->id, $this->notes);
            Notification::make()
                ->title('Đã tạo phiếu nháp '.$transfer->code)
                ->body('Bấm «Hoàn tất» để trừ/ cộng tồn hai chi nhánh.')
                ->success()
                ->send();
            $this->lines = [['part_id' => null, 'quantity' => 1]];
            $this->notes = null;
        } catch (DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function completeTransfer(int $id): void
    {
        $user = User::current();
        if ($user === null) {
            return;
        }

        try {
            $transfer = StockTransfer::query()->findOrFail($id);
            $done = app(StockTransferService::class)->complete($transfer, $user->id);
            Notification::make()
                ->title('Đã hoàn tất '.$done->code)
                ->success()
                ->send();
        } catch (DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public static function statusLabel(StockTransferStatus $status): string
    {
        return $status->getLabel();
    }
}
