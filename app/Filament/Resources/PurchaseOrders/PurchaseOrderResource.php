<?php

namespace App\Filament\Resources\PurchaseOrders;

use App\Enums\PurchaseOrderStatus;
use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\PurchaseOrders\Pages\CreatePurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\EditPurchaseOrder;
use App\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Resources\PurchaseOrders\Pages\ViewPurchaseOrder;
use App\Models\Part;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\PurchasingService;
use App\Support\VietnameseMoney;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use UnitEnum;

class PurchaseOrderResource extends Resource
{
    protected static ?string $model = PurchaseOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Mua hàng';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Đơn mua hàng';

    protected static ?string $modelLabel = 'đơn mua hàng';

    protected static ?string $pluralModelLabel = 'đơn mua hàng';

    protected static ?string $recordTitleAttribute = 'code';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label('Mã đơn')
                ->disabled()
                ->dehydrated(false)
                ->visibleOn('edit'),
            Select::make('supplier_id')
                ->label('Nhà cung cấp')
                ->relationship('supplier', 'name', fn (Builder $query): Builder => $query->active()->orderBy('name'))
                ->getOptionLabelFromRecordUsing(fn ($record): string => $record->displayLabel())
                ->searchable()
                ->preload()
                ->required()
                ->native(false)
                ->disabled(fn (?PurchaseOrder $record): bool => $record instanceof PurchaseOrder && ! $record->status->canEditItems()),
            DatePicker::make('expected_at')
                ->label('Ngày nhận dự kiến')
                ->native(false),
            Textarea::make('notes')
                ->label('Ghi chú')
                ->rows(2)
                ->columnSpanFull(),
            Repeater::make('items')
                ->label('Dòng phụ tùng')
                ->relationship()
                ->schema([
                    Select::make('part_id')
                        ->label('Phụ tùng')
                        ->options(fn (): array => Part::query()->orderBy('name')->get()
                            ->mapWithKeys(fn (Part $part): array => [$part->id => $part->sku.' — '.$part->name])
                            ->all())
                        ->searchable()
                        ->required()
                        ->native(false)
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set): void {
                            $part = Part::query()->find($state);
                            if ($part instanceof Part) {
                                $set('unit_cost', (int) round((float) $part->cost_price));
                            }
                        }),
                    TextInput::make('quantity_ordered')
                        ->label('SL đặt')
                        ->numeric()
                        ->minValue(1)
                        ->default(1)
                        ->required(),
                    MoneyInput::make('unit_cost')
                        ->label('Giá nhập')
                        ->required(),
                    TextInput::make('note')
                        ->label('Ghi chú dòng')
                        ->maxLength(255),
                ])
                ->columns(4)
                ->columnSpanFull()
                ->minItems(1)
                ->addActionLabel('Thêm phụ tùng')
                ->disabled(fn (?PurchaseOrder $record): bool => $record instanceof PurchaseOrder && ! $record->status->canEditItems()),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('code')->label('Mã đơn'),
            TextEntry::make('supplier.name')->label('NCC'),
            TextEntry::make('status')->label('Trạng thái')->badge(),
            TextEntry::make('expected_at')->label('Dự kiến nhận')->date('d/m/Y')->placeholder('—'),
            TextEntry::make('ordered_at')->label('Ngày đặt')->dateTime('d/m/Y H:i')->placeholder('—'),
            TextEntry::make('subtotal')
                ->label('Tạm tính')
                ->formatStateUsing(fn (mixed $state): string => VietnameseMoney::format($state))
                ->visible(fn (): bool => User::currentCanSeeCosts()),
            TextEntry::make('notes')->label('Ghi chú')->placeholder('—')->columnSpanFull(),
            RepeatableEntry::make('items')
                ->label('Dòng phụ tùng')
                ->schema([
                    TextEntry::make('part.name')->label('Phụ tùng'),
                    TextEntry::make('quantity_ordered')->label('SL đặt'),
                    TextEntry::make('quantity_received')->label('SL đã nhập'),
                    TextEntry::make('unit_cost')
                        ->label('Giá nhập')
                        ->formatStateUsing(fn (mixed $state): string => VietnameseMoney::format($state))
                        ->visible(fn (): bool => User::currentCanSeeCosts()),
                ])
                ->columns(4)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Mã')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('supplier.name')
                    ->label('NCC')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge(),
                TextColumn::make('subtotal')
                    ->label('Tạm tính')
                    ->formatStateUsing(fn (mixed $state): string => VietnameseMoney::format($state))
                    ->visible(fn (): bool => User::currentCanSeeCosts()),
                TextColumn::make('expected_at')
                    ->label('Dự kiến nhận')
                    ->date('d/m/Y')
                    ->placeholder('—'),
                TextColumn::make('ordered_at')
                    ->label('Ngày đặt')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(PurchaseOrderStatus::class),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (PurchaseOrder $record): bool => $record->status->canEditItems()),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['supplier', 'items']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseOrders::route('/'),
            'create' => CreatePurchaseOrder::route('/create'),
            'view' => ViewPurchaseOrder::route('/{record}'),
            'edit' => EditPurchaseOrder::route('/{record}/edit'),
        ];
    }

    public static function markOrderedAction(): Action
    {
        return Action::make('markOrdered')
            ->label('Xác nhận đặt hàng')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading('Xác nhận đã đặt hàng NCC?')
            ->visible(fn (PurchaseOrder $record): bool => $record->status === PurchaseOrderStatus::Draft)
            ->action(function (PurchaseOrder $record): void {
                app(PurchasingService::class)->markOrdered($record);
                Notification::make()->title('Đã chuyển đơn sang trạng thái Đã đặt.')->success()->send();
            });
    }

    public static function cancelAction(): Action
    {
        return Action::make('cancelOrder')
            ->label('Hủy đơn')
            ->icon(Heroicon::OutlinedXMark)
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (PurchaseOrder $record): bool => in_array($record->status, [
                PurchaseOrderStatus::Draft,
                PurchaseOrderStatus::Ordered,
            ], true))
            ->action(function (PurchaseOrder $record): void {
                app(PurchasingService::class)->cancelPurchaseOrder($record);
                Notification::make()->title('Đã hủy đơn mua.')->success()->send();
            });
    }

    public static function receiveAction(): Action
    {
        return Action::make('receiveGoods')
            ->label('Nhập kho theo đơn')
            ->icon(Heroicon::OutlinedArchiveBoxArrowDown)
            ->color('success')
            ->visible(fn (PurchaseOrder $record): bool => $record->status->canReceive())
            ->form([
                TextInput::make('invoice_no_supplier')
                    ->label('Số HĐ NCC')
                    ->maxLength(50),
                DatePicker::make('invoice_date')
                    ->label('Ngày HĐ NCC')
                    ->native(false)
                    ->default(now()),
                TextInput::make('vat_rate')
                    ->label('VAT %')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(20)
                    ->default((int) config('garage.purchasing.default_vat_rate', 8))
                    ->required(),
                Textarea::make('notes')
                    ->label('Ghi chú phiếu nhập')
                    ->rows(2),
            ])
            ->action(function (PurchaseOrder $record, array $data): void {
                $user = User::current();
                if ($user === null) {
                    return;
                }

                $receipt = app(PurchasingService::class)->receiveFromPurchaseOrder(
                    $record,
                    $user->id,
                    [],
                    $data['invoice_no_supplier'] ?? null,
                    isset($data['invoice_date']) ? Carbon::parse($data['invoice_date']) : null,
                    $data['notes'] ?? null,
                    isset($data['vat_rate']) ? (int) $data['vat_rate'] : null,
                );

                Notification::make()
                    ->title('Đã nhập kho '.$receipt->code)
                    ->body('Tổng '.VietnameseMoney::format($receipt->total_amount).' (đã gồm VAT).')
                    ->success()
                    ->send();
            });
    }
}
