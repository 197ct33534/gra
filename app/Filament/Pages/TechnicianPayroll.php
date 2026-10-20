<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Models\WorkOrderItem;
use App\Services\PayrollService;
use App\Support\VietnameseMoney;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * Bảng lương khoán theo tháng và năng suất thợ / cầu nâng — chỉ chủ gara xem.
 */
class TechnicianPayroll extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static string|UnitEnum|null $navigationGroup = 'Quản trị';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Lương khoán & năng suất';

    protected static ?string $title = 'Lương khoán & năng suất';

    protected static ?string $slug = 'technician-payroll';

    protected string $view = 'filament.pages.technician-payroll';

    public string $month = '';

    public static function canAccess(): bool
    {
        return User::current()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        $this->month = PayrollService::currentMonth();
    }

    public function updatedMonth(string $value): void
    {
        if (! array_key_exists($value, self::monthOptions())) {
            $this->month = PayrollService::currentMonth();
        }
    }

    /**
     * @return array<string, string>
     */
    public static function monthOptions(): array
    {
        $now = Carbon::now((string) config('garage.timezone'))->startOfMonth();
        $options = [];

        for ($i = 0; $i < 12; $i++) {
            $month = $now->copy()->subMonths($i);
            $options[$month->format('Y-m')] = 'Tháng '.$month->format('m/Y');
        }

        return $options;
    }

    /**
     * @return array{technicians: list<array<string, mixed>>, lifts: list<array<string, mixed>>, details: array<int, list<array<string, string>>>, total: float}
     */
    public function getReport(): array
    {
        $payroll = app(PayrollService::class);
        $technicians = $payroll->technicianSummary($this->month);
        $timezone = (string) config('garage.timezone');
        $details = $payroll->deliveredLaborItems($this->month)
            ->groupBy('technician_id')
            ->map(fn ($items): array => $items->map(fn (WorkOrderItem $item): array => [
                'date' => $item->workOrder?->delivered_at?->timezone($timezone)->format('d/m') ?? '',
                'code' => $item->workOrder?->code ?? '',
                'plate' => $item->workOrder?->vehicle?->license_plate ?? '',
                'job' => ($item->service?->name ?? 'Dịch vụ #'.$item->item_id).((float) $item->quantity !== 1.0 ? ' × '.(float) $item->quantity : ''),
                'labor' => VietnameseMoney::format($item->subtotal),
                'payout' => VietnameseMoney::format($payroll->itemPayout($item)),
                'estimated' => $item->technician_payout === null ? 'ước tính' : '',
            ])->values()->all())
            ->all();

        return [
            'technicians' => $technicians,
            'lifts' => $payroll->liftSummary($this->month),
            'details' => $details,
            'total' => round((float) collect($technicians)->sum('payout'), 2),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportCsv')
                ->label('Tải bảng lương (CSV)')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(fn (): StreamedResponse => $this->exportCsv()),
        ];
    }

    public function exportCsv(): StreamedResponse
    {
        $rows = app(PayrollService::class)->technicianSummary($this->month);
        $filename = 'luong-khoan-'.$this->month.'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            // BOM để Excel đọc đúng tiếng Việt.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Thợ', 'Số hạng mục', 'Số xe', 'Tiền công làm ra', 'Tỷ lệ %', 'Giờ chuẩn', 'Lỗi phải làm lại', 'Tiền khoán']);

            foreach ($rows as $row) {
                fputcsv($out, [
                    $row['technician']->name,
                    $row['jobs'],
                    $row['cars'],
                    (int) round($row['labor']),
                    $row['rate'],
                    round($row['minutes'] / 60, 1),
                    $row['redo'],
                    (int) round($row['payout']),
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public static function money(float $amount): string
    {
        return VietnameseMoney::format($amount);
    }
}
