<?php

namespace App\Filament\Pages;

use App\Enums\WorkOrderStatus;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\User;
use App\Models\WorkOrder;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

class TechnicianMyJobs extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrench;

    protected static string|UnitEnum|null $navigationGroup = 'Xưởng';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Việc của tôi';

    protected static ?string $title = 'Việc của tôi';

    protected static ?string $slug = 'my-jobs';

    protected string $view = 'filament.pages.technician-my-jobs';

    public static function canAccess(): bool
    {
        $user = User::current();

        return $user !== null && ($user->isTechnician() || $user->isAdmin() || $user->isServiceAdvisor());
    }

    /**
     * @return Collection<int, WorkOrder>
     */
    public function getJobs(): Collection
    {
        $user = User::current();
        if ($user === null) {
            return WorkOrder::query()->whereRaw('1=0')->get();
        }

        $query = WorkOrder::query()
            ->with(['vehicle.customer', 'items'])
            ->whereNotIn('status', [WorkOrderStatus::Delivered])
            ->orderByRaw("case status
                when 'in_progress' then 1
                when 'qc' then 2
                when 'customer_approved' then 3
                when 'quoted' then 4
                else 5 end")
            ->orderBy('promised_at');

        if ($user->isTechnician()) {
            $query->whereHas('items', fn ($q) => $q->where('technician_id', $user->id));
        }

        return $query->limit(80)->get();
    }

    public function orderUrl(WorkOrder $order): string
    {
        return WorkOrderResource::getUrl('edit', ['record' => $order]);
    }
}
