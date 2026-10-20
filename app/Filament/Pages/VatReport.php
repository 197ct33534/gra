<?php

namespace App\Filament\Pages;

use App\Models\Invoice;
use App\Models\SupplierBill;
use App\Models\User;
use App\Support\VietnameseMoney;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use UnitEnum;

class VatReport extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Thu ngân';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Báo cáo VAT';

    protected static ?string $title = 'Báo cáo VAT đầu ra / đầu vào';

    protected static ?string $slug = 'vat-report';

    protected string $view = 'filament.pages.vat-report';

    /**
     * @var array{from?: string, to?: string}|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return User::current()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        $tz = (string) config('garage.timezone');
        $this->form->fill([
            'from' => now($tz)->startOfMonth()->toDateString(),
            'to' => now($tz)->endOfMonth()->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                DatePicker::make('from')->label('Từ ngày')->required()->native(false),
                DatePicker::make('to')->label('Đến ngày')->required()->native(false),
            ])
            ->statePath('data')
            ->columns(2);
    }

    /**
     * @return Collection<int, Invoice>
     */
    public function outputInvoices(): Collection
    {
        [$from, $to] = $this->range();

        return Invoice::query()
            ->with('workOrder.vehicle')
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, SupplierBill>
     */
    public function inputBills(): Collection
    {
        [$from, $to] = $this->range();

        return SupplierBill::query()
            ->with('supplier')
            ->whereBetween('bill_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('bill_date')
            ->get();
    }

    /**
     * @return array{before: float, vat: float, total: float}
     */
    public function outputTotals(): array
    {
        $rows = $this->outputInvoices();

        return [
            'before' => round((float) $rows->sum(fn (Invoice $i): float => (float) ($i->amount_before_vat ?? 0)), 2),
            'vat' => round((float) $rows->sum(fn (Invoice $i): float => (float) ($i->vat_amount ?? 0)), 2),
            'total' => round((float) $rows->sum('total_amount'), 2),
        ];
    }

    /**
     * @return array{before: float, vat: float, total: float}
     */
    public function inputTotals(): array
    {
        $rows = $this->inputBills();

        return [
            'before' => round((float) $rows->sum('amount_before_vat'), 2),
            'vat' => round((float) $rows->sum('vat_amount'), 2),
            'total' => round((float) $rows->sum('total_amount'), 2),
        ];
    }

    public function money(mixed $amount): string
    {
        return VietnameseMoney::format($amount);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(): array
    {
        $tz = (string) config('garage.timezone');
        $from = Carbon::parse($this->data['from'] ?? now($tz)->startOfMonth()->toDateString(), $tz)->startOfDay()->utc();
        $to = Carbon::parse($this->data['to'] ?? now($tz)->endOfMonth()->toDateString(), $tz)->endOfDay()->utc();

        return [$from, $to];
    }
}
