<?php

namespace App\Providers;

use App\Messaging\LogMessageGateway;
use App\Messaging\MessageGateway;
use App\Messaging\UnconfiguredMessageGateway;
use App\Models\Invoice;
use App\Models\Part;
use App\Models\Service;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Observers\CatalogPriceObserver;
use App\Observers\InvoiceObserver;
use App\Observers\WorkOrderItemObserver;
use App\Observers\WorkOrderObserver;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(MessageGateway::class, function (): MessageGateway {
            $driver = (string) config('garage.messaging.driver', 'log');

            return match ($driver) {
                'log' => new LogMessageGateway,
                default => new UnconfiguredMessageGateway($driver),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // DB lưu UTC; màn hình và ô chọn giờ (giờ hẹn giao xe) hiển thị theo giờ Việt Nam.
        FilamentTimezone::set((string) config('garage.timezone'));

        // Nhật ký thao tác nhạy cảm cho chủ gara.
        WorkOrder::observe(WorkOrderObserver::class);
        WorkOrderItem::observe(WorkOrderItemObserver::class);
        Invoice::observe(InvoiceObserver::class);
        Part::observe(CatalogPriceObserver::class);
        Service::observe(CatalogPriceObserver::class);
    }
}
