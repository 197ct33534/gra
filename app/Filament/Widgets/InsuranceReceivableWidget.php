<?php

namespace App\Filament\Widgets;

use App\Enums\InvoicePayer;
use App\Models\Invoice;
use App\Models\User;
use App\Support\VietnameseMoney;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Collection;

/**
 * Công nợ bảo hiểm theo dõi riêng với công nợ khách lẻ: tiền bảo hiểm thường về sau 1–3 tháng.
 */
class InsuranceReceivableWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Công nợ bảo hiểm';

    protected int|array|null $columns = [
        'default' => 1,
        'md' => 3,
    ];

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $open = $this->openInvoices();
        $total = round((float) $open->sum(fn (Invoice $invoice): float => $invoice->outstandingAmount()), 2);
        $oldest = $open->min('created_at');
        $oldestDays = $oldest !== null ? (int) $oldest->copy()->startOfDay()->diffInDays(now()->startOfDay()) : 0;
        $topCompany = $open
            ->groupBy(fn (Invoice $invoice): string => $invoice->payer_name ?: 'Chưa ghi tên')
            ->map(fn (Collection $invoices): float => round((float) $invoices->sum(fn (Invoice $invoice): float => $invoice->outstandingAmount()), 2))
            ->sortDesc();

        return [
            Stat::make('Bảo hiểm còn nợ', VietnameseMoney::format($total))
                ->description($open->count().' hóa đơn chưa thu đủ')
                ->descriptionIcon(Heroicon::OutlinedShieldCheck)
                ->color($total > 0 ? 'warning' : 'gray'),
            Stat::make('Nợ lâu nhất', $open->isEmpty() ? '—' : $oldestDays.' ngày')
                ->description($oldestDays > 60 ? 'Cần đôn đốc hồ sơ bồi thường' : 'Tính từ ngày lập hóa đơn')
                ->descriptionIcon(Heroicon::OutlinedClock)
                ->color($oldestDays > 60 ? 'danger' : 'gray'),
            Stat::make('Nợ nhiều nhất', $topCompany->isEmpty() ? '—' : (string) $topCompany->keys()->first())
                ->description($topCompany->isEmpty() ? 'Không còn công nợ' : VietnameseMoney::format($topCompany->first()))
                ->descriptionIcon(Heroicon::OutlinedBuildingOffice2)
                ->color('info'),
        ];
    }

    /**
     * @return Collection<int, Invoice>
     */
    private function openInvoices(): Collection
    {
        return Invoice::query()
            ->forPayer(InvoicePayer::Insurance)
            ->outstanding()
            ->get(['id', 'payer_name', 'total_amount', 'paid_amount', 'created_at']);
    }

    public static function canView(): bool
    {
        return User::current()?->canSeeMoney() ?? false;
    }
}
