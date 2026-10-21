<?php

namespace App\Filament\Pages;

use App\Models\Part;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ReorderService;
use App\Support\VietnameseMoney;
use BackedEnum;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class ReorderSuggestions extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|UnitEnum|null $navigationGroup = 'Kho & dịch vụ';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Đề xuất nhập hàng';

    protected static ?string $title = 'Đề xuất nhập hàng (tồn thấp)';

    protected static ?string $slug = 'reorder-suggestions';

    protected string $view = 'filament.pages.reorder-suggestions';

    /**
     * @var list<string|int>
     */
    public array $selected = [];

    public ?int $supplier_id = null;

    public static function canAccess(): bool
    {
        return User::current()?->canSeeMoney() ?? false;
    }

    /**
     * @return list<array{part: Part, available: int, suggest_qty: int, gap: int}>
     */
    public function getSuggestions(): array
    {
        return app(ReorderService::class)->suggestions()->all();
    }

    public function createDraftPo(): void
    {
        $user = User::current();
        if ($user === null) {
            return;
        }

        $suggestions = collect($this->getSuggestions())->keyBy(fn (array $row): int => $row['part']->id);
        $lines = [];
        foreach ($this->selected as $partId) {
            $row = $suggestions->get((int) $partId);
            if ($row === null) {
                continue;
            }
            $lines[] = [
                'part_id' => (int) $partId,
                'quantity' => $row['suggest_qty'],
            ];
        }

        try {
            $supplier = Supplier::query()->findOrFail((int) $this->supplier_id);
            $po = app(ReorderService::class)->createDraftPurchaseOrder($supplier, $lines, $user->id);
            Notification::make()
                ->title('Đã tạo PO nháp '.$po->code)
                ->body('Tạm tính '.VietnameseMoney::format($po->subtotal).' — mở đơn mua để chỉnh/đặt hàng.')
                ->success()
                ->send();
            $this->selected = [];
        } catch (DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public static function money(float $amount): string
    {
        return VietnameseMoney::format($amount);
    }
}
