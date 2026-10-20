<?php

namespace App\Providers;

use App\EInvoice\EInvoiceGateway;
use App\EInvoice\LogEInvoiceGateway;
use App\EInvoice\UnconfiguredEInvoiceGateway;
use App\Messaging\LogMessageGateway;
use App\Messaging\MessageGateway;
use App\Messaging\UnconfiguredMessageGateway;
use App\Messaging\ZaloZnsGateway;
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
                'zalo_zns' => new ZaloZnsGateway,
                default => new UnconfiguredMessageGateway($driver),
            };
        });

        $this->app->bind(EInvoiceGateway::class, function (): EInvoiceGateway {
            $driver = (string) config('garage.einvoice.driver', 'log');

            return match ($driver) {
                'log' => new LogEInvoiceGateway,
                default => new UnconfiguredEInvoiceGateway($driver),
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
