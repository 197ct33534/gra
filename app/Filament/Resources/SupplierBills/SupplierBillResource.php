<?php

namespace App\Filament\Resources\SupplierBills;

use App\Enums\SupplierBillStatus;
use App\Enums\SupplierPaymentMethod;
use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\SupplierBills\Pages\ListSupplierBills;
use App\Models\SupplierBill;
use App\Models\User;
use App\Services\PurchasingService;
use App\Support\VietnameseMoney;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use UnitEnum;

class SupplierBillResource extends Resource
{
    protected static ?string $model = SupplierBill::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Mua hàng';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Công nợ NCC';

    protected static ?string $modelLabel = 'công nợ NCC';

    protected static ?string $pluralModelLabel = 'công nợ NCC';

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

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('supplier.name')
                    ->label('NCC')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('bill_number')
                    ->label('Số HĐ')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('goodsReceipt.code')
                    ->label('Phiếu nhập')
                    ->placeholder('—'),
                TextColumn::make('bill_date')
                    ->label('Ngày HĐ')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('due_date')
                    ->label('Hạn trả')
                    ->date('d/m/Y')
                    ->placeholder('Trả ngay')
                    ->color(fn (SupplierBill $record): ?string => $record->due_date !== null
                        && $record->status !== SupplierBillStatus::Paid
                        && $record->due_date->isPast()
                        ? 'danger'
                        : null),
                TextColumn::make('total_amount')
                    ->label('Phải trả')
                    ->formatStateUsing(fn (mixed $state): string => VietnameseMoney::format($state)),
                TextColumn::make('outstanding')
                    ->label('Còn nợ')
                    ->state(fn (SupplierBill $record): string => VietnameseMoney::format($record->outstandingAmount())),
                TextColumn::make('status')
                    ->label('TT')
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(SupplierBillStatus::class),
            ])
            ->recordActions([
                Action::make('pay')
                    ->label('Thanh toán')
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->color('success')
                    ->visible(fn (SupplierBill $record): bool => $record->outstandingAmount() > 0 && (User::current()?->isAdmin() ?? false))
                    ->form([
                        MoneyInput::make('amount')
                            ->label('Số tiền')
                            ->required()
                            ->default(fn (SupplierBill $record): int => (int) round($record->outstandingAmount())),
                        Select::make('method')
                            ->label('Hình thức')
                            ->options(SupplierPaymentMethod::class)
                            ->required()
                            ->native(false)
                            ->default(SupplierPaymentMethod::BankTransfer),
                        DateTimePicker::make('paid_at')
                            ->label('Thời điểm')
                            ->default(now())
                            ->required()
                            ->native(false),
                        TextInput::make('reference')
                            ->label('Mã giao dịch / phiếu chi')
                            ->maxLength(255),
                        Textarea::make('notes')
                            ->label('Ghi chú')
                            ->rows(2),
                    ])
                    ->action(function (SupplierBill $record, array $data): void {
                        $user = User::current();
                        if ($user === null) {
                            return;
                        }

                        try {
                            app(PurchasingService::class)->recordSupplierPayment(
                                $record,
                                $data['amount'],
                                SupplierPaymentMethod::from($data['method']),
                                $user->id,
                                $data['reference'] ?? null,
                                $data['notes'] ?? null,
                                isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : null,
                            );
                            Notification::make()->title('Đã ghi nhận thanh toán NCC.')->success()->send();
                        } catch (DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['supplier', 'goodsReceipt']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupplierBills::route('/'),
        ];
    }
}
