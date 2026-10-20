<?php

namespace App\Filament\Resources\BankTransactions;

use App\Enums\BankTransactionStatus;
use App\Enums\InvoicePayer;
use App\Filament\Resources\BankTransactions\Pages\ListBankTransactions;
use App\Models\BankTransaction;
use App\Models\Invoice;
use App\Services\BankReconciliationService;
use App\Support\VietnameseMoney;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class BankTransactionResource extends Resource
{
    protected static ?string $model = BankTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Thu ngân';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Tiền chuyển khoản';

    protected static ?string $modelLabel = 'giao dịch';

    protected static ?string $pluralModelLabel = 'tiền chuyển khoản';

    public static function getNavigationBadge(): ?string
    {
        $count = BankTransaction::query()->where('status', BankTransactionStatus::Unmatched)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('transacted_at')
                    ->label('Thời gian')
                    ->dateTime('H:i d/m/Y')
                    ->sortable(),
                TextColumn::make('amount')
                    ->label('Số tiền')
                    ->formatStateUsing(fn (mixed $state): string => VietnameseMoney::format($state))
                    ->weight('bold'),
                TextColumn::make('content')
                    ->label('Nội dung chuyển khoản')
                    ->description(fn (BankTransaction $record): string => trim(($record->bank ?? '').' '.($record->reference_code ?? '')))
                    ->wrap()
                    ->searchable(),
                TextColumn::make('status')
                    ->label('Đối soát')
                    ->badge(),
                TextColumn::make('invoice.invoice_number')
                    ->label('Hóa đơn')
                    ->placeholder('—')
                    ->description(fn (BankTransaction $record): ?string => $record->status === BankTransactionStatus::Matched ? 'Ghi nhận '.VietnameseMoney::format($record->matched_amount) : null),
                TextColumn::make('note')
                    ->label('Ghi chú')
                    ->wrap()
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Đối soát')
                    ->options(BankTransactionStatus::class),
            ])
            ->recordActions([
                Action::make('match')
                    ->label('Gán vào hóa đơn')
                    ->icon(Heroicon::OutlinedLink)
                    ->color('success')
                    ->visible(fn (BankTransaction $record): bool => $record->status === BankTransactionStatus::Unmatched)
                    ->schema([
                        Select::make('invoice_id')
                            ->label('Hóa đơn khách còn nợ')
                            ->options(fn (): array => Invoice::query()
                                ->outstanding()
                                ->forPayer(InvoicePayer::Customer)
                                ->with('workOrder.vehicle')
                                ->latest('id')
                                ->get()
                                ->mapWithKeys(fn (Invoice $invoice): array => [
                                    $invoice->id => $invoice->invoice_number.' · '.($invoice->workOrder?->vehicle?->license_plate ?? '').' · còn '.VietnameseMoney::format($invoice->outstandingAmount()),
                                ])
                                ->all())
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (BankTransaction $record, array $data): void {
                        try {
                            app(BankReconciliationService::class)->matchToInvoice($record, Invoice::query()->findOrFail($data['invoice_id']), (int) auth()->id());
                            Notification::make()->title('Đã ghi nhận tiền vào hóa đơn')->success()->send();
                        } catch (DomainException $exception) {
                            Notification::make()->title('Không gán được')->body($exception->getMessage())->danger()->send();
                        }
                    }),
                Action::make('ignore')
                    ->label('Bỏ qua')
                    ->icon(Heroicon::OutlinedEyeSlash)
                    ->color('gray')
                    ->visible(fn (BankTransaction $record): bool => $record->status === BankTransactionStatus::Unmatched)
                    ->schema([
                        TextInput::make('reason')
                            ->label('Lý do')
                            ->placeholder('VD: Tiền nhà cung cấp hoàn lại'),
                    ])
                    ->action(fn (BankTransaction $record, array $data): mixed => app(BankReconciliationService::class)->ignore($record, $data['reason'] ?? null)),
            ])
            ->defaultSort('transacted_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('invoice');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBankTransactions::route('/'),
        ];
    }
}
