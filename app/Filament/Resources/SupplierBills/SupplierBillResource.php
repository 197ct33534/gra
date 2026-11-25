<?php

namespace App\Filament\Resources\SupplierBills;

use App\Enums\SupplierBillStatus;
use App\Enums\SupplierPaymentMethod;
use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\SupplierBills\Pages\ListSupplierBills;
use App\Filament\Support\PhoneTable;
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
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
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
                    ->sortable()
                    // Điện thoại: số HĐ / phiếu nhập / ngày / hạn trả / phải trả / trạng thái bị ẩn nên ghi gọn dưới NCC.
                    ->description(fn (SupplierBill $record): Htmlable => self::phoneSummary($record)),
                TextColumn::make('bill_number')
                    ->label('Số HĐ')
                    ->placeholder('—')
                    ->visibleFrom('md')
                    ->searchable(),
                TextColumn::make('goodsReceipt.code')
                    ->label('Phiếu nhập')
                    ->placeholder('—')
                    ->visibleFrom('md'),
                TextColumn::make('bill_date')
                    ->label('Ngày HĐ')
                    ->date('d/m/Y')
                    ->visibleFrom('md')
                    ->sortable(),
                TextColumn::make('due_date')
                    ->label('Hạn trả')
                    ->date('d/m/Y')
                    ->placeholder('Trả ngay')
                    ->color(fn (SupplierBill $record): ?string => $record->due_date !== null
                        && $record->status !== SupplierBillStatus::Paid
                        && $record->due_date->isPast()
                        ? 'danger'
                        : null)
                    ->visibleFrom('md'),
                TextColumn::make('total_amount')
                    ->label('Phải trả')
                    ->formatStateUsing(fn (mixed $state): string => VietnameseMoney::format($state))
                    ->visibleFrom('md'),
                TextColumn::make('outstanding')
                    ->label('Còn nợ')
                    ->state(fn (SupplierBill $record): string => VietnameseMoney::format($record->outstandingAmount())),
                TextColumn::make('status')
                    ->label('TT')
                    ->badge()
                    ->visibleFrom('md'),
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

    /**
     * Dòng phụ dưới tên NCC, chỉ hiện trên điện thoại (thay các cột bị ẩn):
     * số HĐ · phiếu nhập · ngày HĐ · hạn trả (đỏ nếu quá hạn) · phải trả · trạng thái.
     */
    public static function phoneSummary(SupplierBill $record): Htmlable
    {
        $due = 'Trả ngay';

        if ($record->due_date !== null) {
            $late = $record->status !== SupplierBillStatus::Paid && $record->due_date->isPast();
            $text = e('Hạn '.$record->due_date->format('d/m/Y'));
            $due = new HtmlString($late ? '<span style="color:#dc2626;font-weight:600">'.$text.'</span>' : $text);
        }

        return PhoneTable::summary([
            filled($record->bill_number) ? 'HĐ '.$record->bill_number : null,
            $record->goodsReceipt?->code,
            $record->bill_date?->format('d/m/Y'),
            $due,
            'Phải trả '.VietnameseMoney::format($record->total_amount),
            $record->status?->getLabel(),
        ]);
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
