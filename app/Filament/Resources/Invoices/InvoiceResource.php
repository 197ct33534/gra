<?php

namespace App\Filament\Resources\Invoices;

use App\Enums\EInvoiceStatus;
use App\Enums\InvoicePayer;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\Invoice;
use App\Models\User;
use App\Services\CreditNoteService;
use App\Services\EInvoiceService;
use App\Services\LoyaltyService;
use App\Services\WorkOrderService;
use App\Support\VietnameseMoney;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Thu ngân';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Hóa đơn & công nợ';

    protected static ?string $modelLabel = 'hóa đơn';

    protected static ?string $pluralModelLabel = 'hóa đơn';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('invoice_number')
                    ->label('Số hóa đơn')
                    ->badge()
                    ->color('gray')
                    ->copyable()
                    ->searchable(),
                TextColumn::make('workOrder.code')
                    ->label('Lệnh sửa chữa')
                    ->description(fn (Invoice $record): string => $record->workOrder?->vehicle?->license_plate ?? '')
                    ->url(fn (Invoice $record): ?string => $record->workOrder !== null
                        ? WorkOrderResource::getUrl('view', ['record' => $record->workOrder])
                        : null)
                    ->color('primary')
                    ->searchable(),
                TextColumn::make('payer_type')
                    ->label('Bên trả')
                    ->badge()
                    ->formatStateUsing(fn (InvoicePayer $state): string => $state->getLabel())
                    ->color(fn (InvoicePayer $state): string => $state->getColor()),
                TextColumn::make('payer_display')
                    ->label('Đơn vị / khách hàng')
                    ->state(fn (Invoice $record): string => self::payerDisplay($record))
                    ->wrap(),
                TextColumn::make('total_amount')
                    ->label('Phải thu')
                    ->formatStateUsing(fn (mixed $state): string => VietnameseMoney::format($state))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('paid_amount')
                    ->label('Đã thu')
                    ->formatStateUsing(fn (mixed $state): string => VietnameseMoney::format($state))
                    ->alignEnd(),
                TextColumn::make('outstanding')
                    ->label('Còn nợ')
                    ->state(fn (Invoice $record): string => VietnameseMoney::format($record->outstandingAmount()))
                    ->color(fn (Invoice $record): string => $record->outstandingAmount() > 0 ? 'danger' : 'success')
                    ->alignEnd(),
                TextColumn::make('payment_status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn (PaymentStatus $state): string => $state->getLabel())
                    ->color(fn (PaymentStatus $state): string => match ($state) {
                        PaymentStatus::Paid => 'success',
                        PaymentStatus::PartiallyPaid => 'warning',
                        PaymentStatus::Unpaid => 'danger',
                    }),
                TextColumn::make('created_at')
                    ->label('Ngày lập')
                    ->dateTime('d/m/Y')
                    ->description(fn (Invoice $record): ?string => $record->outstandingAmount() > 0 && $record->created_at !== null
                        ? 'Nợ '.(int) $record->created_at->startOfDay()->diffInDays(now()->startOfDay()).' ngày'
                        : null)
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('payer_type')
                    ->label('Bên trả')
                    ->options(InvoicePayer::class),
                SelectFilter::make('payment_status')
                    ->label('Trạng thái thanh toán')
                    ->options(PaymentStatus::class),
                Filter::make('outstanding')
                    ->label('Còn công nợ')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->outstanding()),
                SelectFilter::make('payer_name')
                    ->label('Công ty bảo hiểm')
                    ->options(fn (): array => Invoice::query()
                        ->where('payer_type', InvoicePayer::Insurance)
                        ->whereNotNull('payer_name')
                        ->distinct()
                        ->orderBy('payer_name')
                        ->pluck('payer_name', 'payer_name')
                        ->all()),
                Filter::make('created_at')
                    ->label('Ngày lập')
                    ->schema([
                        DatePicker::make('from')->label('Từ ngày'),
                        DatePicker::make('until')->label('Đến ngày'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->recordActions([
                self::recordPaymentAction(),
                self::redeemLoyaltyAction(),
                self::issueEInvoiceAction(),
                self::cancelEInvoiceAction(),
                self::sendEInvoiceLinkAction(),
                self::creditNoteAction(),
                Action::make('printInvoice')
                    ->label('In hóa đơn')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->color('success')
                    ->url(fn (Invoice $record): string => route('invoices.pdf', $record))
                    ->openUrlInNewTab(),
                Action::make('openEInvoiceLookup')
                    ->label('Tra cứu HĐĐT')
                    ->icon(Heroicon::OutlinedLink)
                    ->url(fn (Invoice $record): ?string => $record->einvoice_lookup_url)
                    ->openUrlInNewTab()
                    ->visible(fn (Invoice $record): bool => filled($record->einvoice_lookup_url)),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function issueEInvoiceAction(): Action
    {
        return Action::make('issueEInvoice')
            ->label('Xuất HĐĐT')
            ->icon(Heroicon::OutlinedDocumentCheck)
            ->color('primary')
            ->visible(fn (Invoice $record): bool => ! in_array($record->einvoice_status, [EInvoiceStatus::Issued], true))
            ->form([
                Toggle::make('admin_override')
                    ->label('Admin: xuất dù chưa thu đủ')
                    ->visible(fn (): bool => User::current()?->isAdmin() ?? false),
            ])
            ->action(function (Invoice $record, array $data): void {
                try {
                    $invoice = app(EInvoiceService::class)->issue(
                        $record,
                        (bool) ($data['admin_override'] ?? false) && (User::current()?->isAdmin() ?? false),
                    );
                    Notification::make()
                        ->title('Đã xuất HĐĐT')
                        ->body($invoice->einvoice_series.$invoice->einvoice_number)
                        ->success()
                        ->send();
                } catch (DomainException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }

    public static function cancelEInvoiceAction(): Action
    {
        return Action::make('cancelEInvoice')
            ->label('Hủy HĐĐT')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (Invoice $record): bool => $record->einvoice_status === EInvoiceStatus::Issued
                && (User::current()?->isAdmin() ?? false))
            ->form([
                Textarea::make('reason')->label('Lý do hủy')->required()->rows(2),
            ])
            ->action(function (Invoice $record, array $data): void {
                $user = User::current();
                if ($user === null) {
                    return;
                }

                try {
                    app(EInvoiceService::class)->cancel($record, (string) $data['reason'], $user->id);
                    Notification::make()->title('Đã hủy HĐĐT.')->success()->send();
                } catch (DomainException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }

    public static function sendEInvoiceLinkAction(): Action
    {
        return Action::make('sendEInvoiceLink')
            ->label('Gửi link HĐĐT')
            ->icon(Heroicon::OutlinedChatBubbleLeft)
            ->visible(fn (Invoice $record): bool => $record->einvoice_status === EInvoiceStatus::Issued)
            ->action(function (Invoice $record): void {
                $message = app(EInvoiceService::class)->queueLookupLink($record);
                if ($message === null) {
                    Notification::make()->title('Không gửi được (thiếu SĐT / HĐ BH).')->warning()->send();

                    return;
                }
                Notification::make()->title('Đã xếp hàng gửi link HĐĐT.')->success()->send();
            });
    }

    public static function creditNoteAction(): Action
    {
        return Action::make('creditNote')
            ->label('Credit note / hoàn tiền')
            ->icon(Heroicon::OutlinedReceiptRefund)
            ->color('danger')
            ->visible(fn (Invoice $record): bool => (float) $record->paid_amount > 0 && (User::current()?->isAdmin() ?? false))
            ->form([
                MoneyInput::make('amount')
                    ->label('Số tiền hoàn')
                    ->required()
                    ->default(fn (Invoice $record): int => (int) round((float) $record->paid_amount)),
                Textarea::make('reason')->label('Lý do')->required()->rows(2),
                Toggle::make('restock')->label('Hoàn kho phụ tùng đã xuất')->inline(false),
                Textarea::make('notes')->label('Ghi chú thêm')->rows(2),
            ])
            ->requiresConfirmation()
            ->action(function (Invoice $record, array $data): void {
                $user = User::current();
                if ($user === null) {
                    return;
                }

                try {
                    $note = app(CreditNoteService::class)->issue(
                        $record,
                        $data['amount'],
                        (string) $data['reason'],
                        $user->id,
                        (bool) ($data['restock'] ?? false),
                        $data['notes'] ?? null,
                    );
                    Notification::make()
                        ->title('Đã lập '.$note->code)
                        ->body('Hoàn '.VietnameseMoney::format($note->amount))
                        ->success()
                        ->send();
                } catch (DomainException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }

    public static function redeemLoyaltyAction(): Action
    {
        return Action::make('redeemLoyalty')
            ->label('Đổi điểm loyalty')
            ->icon(Heroicon::OutlinedGift)
            ->color('purple')
            ->visible(function (Invoice $record): bool {
                if ($record->outstandingAmount() <= 0 || $record->isInsurancePayer()) {
                    return false;
                }

                $points = (int) ($record->workOrder?->vehicle?->customer?->loyalty_points ?? 0);

                return $points > 0 && (User::current()?->canSeeMoney() ?? false);
            })
            ->modalHeading(fn (Invoice $record): string => 'Đổi điểm — HĐ '.$record->invoice_number)
            ->modalDescription(function (Invoice $record): string {
                $customer = $record->workOrder?->vehicle?->customer;
                $rate = app(LoyaltyService::class)->redeemVndPerPoint();

                return 'Khách có '.(int) ($customer?->loyalty_points ?? 0).' điểm · 1 điểm = '
                    .VietnameseMoney::format($rate).' · còn nợ '
                    .VietnameseMoney::format($record->outstandingAmount());
            })
            ->form([
                TextInput::make('points')
                    ->label('Số điểm đổi')
                    ->numeric()
                    ->required()
                    ->minValue(1)
                    ->default(function (Invoice $record): int {
                        $customerPoints = (int) ($record->workOrder?->vehicle?->customer?->loyalty_points ?? 0);
                        $rate = app(LoyaltyService::class)->redeemVndPerPoint();
                        $maxByDebt = (int) floor($record->outstandingAmount() / $rate);

                        return max(1, min($customerPoints, $maxByDebt));
                    }),
            ])
            ->action(function (Invoice $record, array $data): void {
                try {
                    $tx = app(LoyaltyService::class)->redeemOnInvoice(
                        $record,
                        (int) $data['points'],
                        User::current()?->id,
                    );
                    Notification::make()
                        ->title('Đã đổi '.abs($tx->points).' điểm')
                        ->body('Còn nợ: '.VietnameseMoney::format($record->fresh()->outstandingAmount()))
                        ->success()
                        ->send();
                } catch (DomainException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }

    public static function recordPaymentAction(): Action
    {
        return Action::make('recordPayment')
            ->label('Ghi nhận thu tiền')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('warning')
            ->visible(fn (Invoice $record): bool => $record->outstandingAmount() > 0)
            ->modalHeading(fn (Invoice $record): string => 'Thu tiền hóa đơn '.$record->invoice_number)
            ->modalDescription(fn (Invoice $record): string => self::payerDisplay($record).' — còn nợ '.VietnameseMoney::format($record->outstandingAmount()).'.')
            ->schema([
                MoneyInput::make('amount')
                    ->label('Số tiền thu lần này')
                    ->required()
                    ->default(fn (Invoice $record): int => (int) round($record->outstandingAmount())),
                Textarea::make('note')
                    ->label('Ghi chú')
                    ->placeholder('VD: Bảo Việt chuyển khoản theo hồ sơ bồi thường số ...')
                    ->rows(2),
            ])
            ->modalSubmitActionLabel('Ghi nhận')
            ->action(function (Invoice $record, array $data): void {
                try {
                    $invoice = app(WorkOrderService::class)->recordPayment(
                        $record,
                        VietnameseMoney::parse($data['amount']) ?? 0,
                        isset($data['note']) ? (string) $data['note'] : null,
                    );

                    Notification::make()
                        ->title('Đã ghi nhận thu tiền')
                        ->body('Còn nợ: '.VietnameseMoney::format($invoice->outstandingAmount()))
                        ->success()
                        ->send();
                } catch (DomainException $exception) {
                    Notification::make()
                        ->title('Không thể ghi nhận thu tiền')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public static function payerDisplay(Invoice $record): string
    {
        if ($record->isInsurancePayer()) {
            return $record->payer_name ?: ($record->workOrder?->insurance_company ?? PaymentMethod::Insurance->getLabel());
        }

        return $record->workOrder?->vehicle?->customer?->name ?? 'Khách lẻ';
    }

    /**
     * @return array<class-string>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['workOrder.vehicle.customer', 'workOrder.branch']);
    }
}
