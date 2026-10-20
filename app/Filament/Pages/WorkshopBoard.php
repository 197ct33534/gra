<?php

namespace App\Filament\Pages;

use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\WorkshopBoardService;
use App\Support\VietnameseMoney;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

class WorkshopBoard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedViewColumns;

    protected static string|UnitEnum|null $navigationGroup = 'Tiếp nhận';

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = 'Bảng điều phối xe';

    protected static ?string $title = 'Bảng điều phối xe';

    protected static ?string $slug = 'workshop-board';

    protected string $view = 'filament.pages.workshop-board';

    protected Width|string|null $maxContentWidth = Width::Full;

    /**
     * @return array<string, array{label: string, orders: Collection<int, WorkOrder>}>
     */
    public function getBoard(): array
    {
        $board = app(WorkshopBoardService::class)->board();
        $columns = [];

        foreach (WorkshopBoardService::columns() as $key => $label) {
            $columns[$key] = ['label' => $label, 'orders' => $board[$key]];
        }

        return $columns;
    }

    public function moveOrder(int $orderId, string $column): void
    {
        $order = WorkOrder::query()->find($orderId);

        if (! $order instanceof WorkOrder) {
            return;
        }

        try {
            $warnings = app(WorkshopBoardService::class)->move($order, $column, WorkOrderResource::actorId());
        } catch (DomainException $exception) {
            Notification::make()
                ->title('Không chuyển được '.$order->code)
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        if ($warnings !== []) {
            Notification::make()
                ->title($order->code.': đã duyệt nhưng kho thiếu hàng')
                ->body(implode("\n", $warnings))
                ->warning()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->title($order->code.' → '.(WorkshopBoardService::columns()[$column] ?? $column))
            ->success()
            ->send();
    }

    public function viewOrderAction(): Action
    {
        return Action::make('viewOrder')
            ->label('Chi tiết lệnh')
            ->slideOver()
            ->modalWidth(Width::Large)
            ->modalHeading(fn (array $arguments): string => 'Lệnh '.($this->findOrder($arguments)?->code ?? ''))
            ->modalContent(fn (array $arguments): View => view('filament.pages.partials.workshop-order-detail', [
                'order' => $this->findOrder($arguments)?->load(['vehicle.customer', 'serviceAdvisor', 'items.technician']),
                'lines' => $this->findOrder($arguments)?->jobLines() ?? [],
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Đóng')
            ->extraModalFooterActions(fn (array $arguments): array => [
                Action::make('openOrder')
                    ->label('Mở lệnh sửa chữa')
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->url(WorkOrderResource::getUrl(User::current()?->isTechnician() ? 'view' : 'edit', ['record' => $arguments['order'] ?? 0])),
                Action::make('printJobCard')
                    ->label('In Phiếu Giao Việc')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->color('warning')
                    ->url(route('work-orders.job-card', $arguments['order'] ?? 0))
                    ->openUrlInNewTab(),
            ]);
    }

    public static function money(mixed $amount): string
    {
        return VietnameseMoney::format($amount);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function findOrder(array $arguments): ?WorkOrder
    {
        return WorkOrder::query()->find($arguments['order'] ?? null);
    }
}
