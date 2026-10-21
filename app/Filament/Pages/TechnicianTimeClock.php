<?php

namespace App\Filament\Pages;

use App\Models\TechnicianTimeLog;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\TimeClockService;
use BackedEnum;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

class TechnicianTimeClock extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Xưởng';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Chấm công';

    protected static ?string $title = 'Chấm công thợ';

    protected static ?string $slug = 'time-clock';

    protected string $view = 'filament.pages.technician-time-clock';

    public static function canAccess(): bool
    {
        $user = User::current();

        return $user !== null && ($user->isTechnician() || $user->isAdmin() || $user->isServiceAdvisor());
    }

    public function getOpenLog(): ?TechnicianTimeLog
    {
        $user = User::current();
        if ($user === null) {
            return null;
        }

        return app(TimeClockService::class)->openLogFor($user);
    }

    /**
     * @return Collection<int, WorkOrder>
     */
    public function getAvailableOrders()
    {
        $user = User::current();
        if ($user === null) {
            return WorkOrder::query()->whereRaw('1=0')->get();
        }

        return app(TimeClockService::class)->availableOrdersFor($user);
    }

    public function startClock(int $workOrderId): void
    {
        $user = User::current();
        if ($user === null) {
            return;
        }

        try {
            $order = WorkOrder::query()->findOrFail($workOrderId);
            $log = app(TimeClockService::class)->start($user, $order);
            Notification::make()
                ->title('Đã bắt đầu chấm công '.$log->workOrder?->code)
                ->success()
                ->send();
        } catch (DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }

    public function stopClock(): void
    {
        $user = User::current();
        if ($user === null) {
            return;
        }

        try {
            $log = app(TimeClockService::class)->stop($user);
            Notification::make()
                ->title('Đã dừng — '.$log->minutes.' phút trên '.$log->workOrder?->code)
                ->success()
                ->send();
        } catch (DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}
