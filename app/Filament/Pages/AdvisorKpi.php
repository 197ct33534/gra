<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\AdvisorKpiService;
use App\Services\PayrollService;
use App\Support\VietnameseMoney;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use UnitEnum;

class AdvisorKpi extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static string|UnitEnum|null $navigationGroup = 'Quản trị';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'KPI cố vấn';

    protected static ?string $title = 'KPI cố vấn dịch vụ';

    protected static ?string $slug = 'advisor-kpi';

    protected string $view = 'filament.pages.advisor-kpi';

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
     * @return list<array<string, mixed>>
     */
    public function getRows(): array
    {
        return app(AdvisorKpiService::class)->monthly($this->month);
    }

    public static function money(float $amount): string
    {
        return VietnameseMoney::format($amount);
    }
}
