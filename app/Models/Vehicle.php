<?php

namespace App\Models;

use App\Enums\WorkOrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Vehicle extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const MAINTENANCE_INTERVAL_KM = 5000;

    /**
     * @var list<string>
     */
    public const MAINTENANCE_SERVICE_CODES = ['BD-5K', 'BD-20K'];

    public const OIL_CATEGORY_NAME = 'Dầu nhớt & Phụ gia';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'customer_id',
        'license_plate',
        'vin',
        'brand',
        'model',
        'year',
        'current_odo',
        'registration_expires_at',
        'insurance_expires_at',
        'inspection_expires_at',
        'service_interval_km',
        'service_interval_months',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'current_odo' => 'integer',
            'registration_expires_at' => 'date',
            'insurance_expires_at' => 'date',
            'inspection_expires_at' => 'date',
            'service_interval_km' => 'integer',
            'service_interval_months' => 'integer',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('reception_photos')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(VehicleRecommendation::class);
    }

    public function getNextServiceOdoAttribute(): ?int
    {
        $visit = $this->latestOilChange();

        if (! $visit instanceof WorkOrder) {
            return null;
        }

        return (int) $visit->reception_odo + $this->serviceIntervalKm();
    }

    public function isMaintenanceDue(): bool
    {
        $nextServiceOdo = $this->next_service_odo;

        if ($nextServiceOdo !== null && $this->current_odo >= $nextServiceOdo) {
            return true;
        }

        $latestVisit = $this->latestVisit();

        return $latestVisit instanceof WorkOrder
            && $latestVisit->created_at !== null
            && $latestVisit->created_at->lte(now()->subMonths($this->serviceIntervalMonths()));
    }

    public function serviceIntervalKm(): int
    {
        return $this->service_interval_km ?: (int) config('garage.maintenance.interval_km', self::MAINTENANCE_INTERVAL_KM);
    }

    public function serviceIntervalMonths(): int
    {
        return $this->service_interval_months ?: (int) config('garage.maintenance.interval_months', 3);
    }

    /**
     * Số km xe chạy mỗi ngày, ước từ ODO các lần vào xưởng; thiếu dữ liệu thì dùng mức mặc định.
     */
    public function averageKmPerDay(): float
    {
        $visits = ($this->relationLoaded('workOrders') ? $this->workOrders : $this->workOrders()->get())
            ->filter(fn (WorkOrder $order): bool => $order->created_at !== null)
            ->sortBy(fn (WorkOrder $order): int => $order->created_at->getTimestamp());
        $first = $visits->first();
        $last = $visits->last();

        if ($first instanceof WorkOrder && $last instanceof WorkOrder && $first->isNot($last)) {
            $days = $first->created_at->diffInDays($last->created_at);
            $km = (int) $last->reception_odo - (int) $first->reception_odo;

            if ($days >= 14 && $km > 0) {
                return round($km / $days, 1);
            }
        }

        return (float) config('garage.maintenance.default_km_per_day', 40);
    }

    /**
     * Ngày dự kiến đến hạn: mốc nào tới trước giữa số km (theo tốc độ chạy) và số tháng kể từ lần bảo dưỡng.
     */
    public function predictedServiceDate(): ?Carbon
    {
        $anchor = $this->latestOilChange() ?? $this->latestVisit();

        if (! $anchor instanceof WorkOrder || $anchor->created_at === null) {
            return null;
        }

        $byMonths = $anchor->created_at->copy()->addMonths($this->serviceIntervalMonths());

        // ODO hiện tại là số đọc ở lần vào xưởng gần nhất; từ đó xe chạy tiếp với tốc độ trung bình.
        $lastSeen = $this->latestVisit()?->created_at ?? $anchor->created_at;
        $nextOdo = (int) $anchor->reception_odo + $this->serviceIntervalKm();
        $daysLeft = (int) ceil(max(0, $nextOdo - $this->current_odo) / max(1.0, $this->averageKmPerDay()));
        $byKm = $lastSeen->copy()->addDays($daysLeft);

        return $byMonths->min($byKm);
    }

    /**
     * 'overdue' đã quá hạn, 'soon' đến hạn trong N ngày tới, null nếu chưa cần nhắc.
     */
    public function reminderStatus(int $withinDays = 14): ?string
    {
        if ($this->isMaintenanceDue()) {
            return 'overdue';
        }

        $date = $this->predictedServiceDate();

        return $date !== null && $date->lte(now()->addDays($withinDays)) ? 'soon' : null;
    }

    public function lifetimeSpendAmount(): float
    {
        $orderIds = $this->workOrders()->pluck('id');
        $invoiced = (float) Invoice::query()->whereIn('work_order_id', $orderIds)->sum('total_amount');
        $invoicedOrderIds = Invoice::query()->whereIn('work_order_id', $orderIds)->pluck('work_order_id');
        $uninvoiced = (float) $this->workOrders()
            ->whereIn('status', [WorkOrderStatus::Completed, WorkOrderStatus::Delivered])
            ->whereNotIn('id', $invoicedOrderIds)
            ->sum('final_amount');

        return round($invoiced + $uninvoiced, 2);
    }

    public function latestOilChange(): ?WorkOrder
    {
        $orders = $this->relationLoaded('workOrders')
            ? $this->workOrders
            : $this->workOrders()->with('items')->get();

        return $orders
            ->filter(fn (WorkOrder $order): bool => $order->countsAsOilService())
            ->sortByDesc(fn (WorkOrder $order): int => ($order->created_at?->getTimestamp() ?? 0) + $order->id)
            ->first();
    }

    public function latestVisit(): ?WorkOrder
    {
        $orders = $this->relationLoaded('workOrders')
            ? $this->workOrders
            : $this->workOrders()->get();

        return $orders
            ->sortByDesc(fn (WorkOrder $order): int => ($order->created_at?->getTimestamp() ?? 0) + $order->id)
            ->first();
    }

    /**
     * @param  Builder<Vehicle>  $query
     * @return Builder<Vehicle>
     */
    public function scopeMaintenanceDue(Builder $query): Builder
    {
        $cutoff = now()->subMonths(3)->toDateTimeString();

        return $query->where(function (Builder $query) use ($cutoff): void {
            $query->whereRaw('vehicles.current_odo >= ('.$this->nextServiceOdoSql().')')
                ->orWhereRaw(
                    '(select max(work_orders.created_at) from work_orders where work_orders.vehicle_id = vehicles.id) <= ?',
                    [$cutoff],
                );
        });
    }

    /**
     * @return array{services: list<int>, parts: list<int>}
     */
    public static function oilChangeCatalogIds(): array
    {
        return once(function (): array {
            return [
                'services' => Service::query()
                    ->whereIn('code', self::MAINTENANCE_SERVICE_CODES)
                    ->pluck('id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all(),
                'parts' => Part::query()
                    ->whereHas('category', fn (Builder $query): Builder => $query->where('name', self::OIL_CATEGORY_NAME))
                    ->pluck('id')
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all(),
            ];
        });
    }

    private function nextServiceOdoSql(): string
    {
        $interval = (int) config('garage.maintenance.interval_km', self::MAINTENANCE_INTERVAL_KM);
        $serviceCodes = collect(self::MAINTENANCE_SERVICE_CODES)
            ->map(fn (string $code): string => "'".str_replace("'", "''", $code)."'")
            ->implode(', ');
        $category = str_replace("'", "''", self::OIL_CATEGORY_NAME);

        return <<<SQL
            select wo.reception_odo + coalesce(vehicles.service_interval_km, {$interval})
            from work_orders as wo
            where wo.vehicle_id = vehicles.id
                and exists (
                    select 1
                    from work_order_items as woi
                    where woi.work_order_id = wo.id
                        and (
                            (
                                woi.item_type = 'service'
                                and woi.item_id in (select id from services where code in ({$serviceCodes}))
                            )
                            or (
                                woi.item_type = 'part'
                                and woi.item_id in (
                                    select parts.id
                                    from parts
                                    inner join part_categories on part_categories.id = parts.category_id
                                    where part_categories.name = '{$category}'
                                )
                            )
                        )
                )
            order by wo.created_at desc, wo.id desc
            limit 1
        SQL;
    }
}
