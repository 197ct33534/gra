<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\FinancialReportService;
use App\Services\PayrollService;
use App\Support\VietnameseMoney;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use UnitEnum;

class FinancialReports extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Thu ngân';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Aging AR & P&L';

    protected static ?string $title = 'Công nợ theo tuổi & P&L tháng';

    protected static ?string $slug = 'financial-reports';

    protected string $view = 'filament.pages.financial-reports';

    public string $month = '';

    public static function canAccess(): bool
    {
        return User::current()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        $this->month = PayrollService::currentMonth();
    }

    /**
     * @return array<string, string>
     */
    public static function monthOptions(): array
    {
        $tz = (string) config('garage.timezone');
        $cursor = Carbon::now($tz)->startOfMonth();
        $options = [];

        for ($i = 0; $i < 12; $i++) {
            $options[$cursor->format('Y-m')] = $cursor->translatedFormat('m/Y');
            $cursor->subMonth();
        }

        return $options;
    }

    /**
     * @return array{aging: array, ap: array, pnl: array}
     */
    public function getReport(): array
    {
        $finance = app(FinancialReportService::class);

        return [
            'aging' => $finance->agingReceivables(),
            'ap' => $finance->agingPayables(),
            'pnl' => $finance->monthlyPnL($this->month),
        ];
    }

    public static function money(float $amount): string
    {
        return VietnameseMoney::format($amount);
    }
}
