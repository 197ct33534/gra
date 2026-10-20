<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Models\WorkOrder;
use App\Services\PayrollService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use UnitEnum;

class NpsReport extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFaceSmile;

    protected static string|UnitEnum|null $navigationGroup = 'Tiếp nhận';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Báo cáo NPS';

    protected static ?string $title = 'Đánh giá khách (NPS / điểm TB)';

    protected static ?string $slug = 'nps-report';

    protected string $view = 'filament.pages.nps-report';

    public string $month = '';

    public static function canAccess(): bool
    {
        return User::current()?->canSeeMoney() ?? false;
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
     * @return array{count: int, avg: float, promoters: int, detractors: int, nps: float, distribution: array<int, int>}
     */
    public function getStats(): array
    {
        ['start' => $start, 'end' => $end] = PayrollService::monthRange($this->month);

        $scores = WorkOrder::query()
            ->whereNotNull('feedback_at')
            ->whereBetween('feedback_at', [$start, $end])
            ->pluck('feedback_score')
            ->map(fn ($s): int => (int) $s);

        $count = $scores->count();
        $avg = $count > 0 ? round($scores->avg(), 2) : 0.0;
        $promoters = $scores->filter(fn (int $s): bool => $s >= 5)->count();
        $detractors = $scores->filter(fn (int $s): bool => $s <= 2)->count();
        $nps = $count > 0 ? round((($promoters - $detractors) / $count) * 100, 1) : 0.0;

        $distribution = [];
        for ($i = 1; $i <= 5; $i++) {
            $distribution[$i] = $scores->filter(fn (int $s): bool => $s === $i)->count();
        }

        return compact('count', 'avg', 'promoters', 'detractors', 'nps', 'distribution');
    }
}
