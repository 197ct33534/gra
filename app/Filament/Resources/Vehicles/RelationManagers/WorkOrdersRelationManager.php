<?php

namespace App\Filament\Resources\Vehicles\RelationManagers;

use App\Enums\WorkOrderStatus;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Filament\Support\PhoneTable;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\VietnameseMoney;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class WorkOrdersRelationManager extends RelationManager
{
    protected static string $relationship = 'workOrders';

    protected static bool $isLazy = false;

    protected static ?string $title = 'Bệnh án sửa chữa';

    protected static string|BackedEnum|null $icon = Heroicon::OutlinedClipboardDocumentList;

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['serviceAdvisor', 'items.technician', 'invoices']))
            ->columns([
                TextColumn::make('code')
                    ->label('Mã RO')
                    ->badge()
                    ->color('primary')
                    // Điện thoại: các cột ngày / ODO / tiền / cố vấn bị ẩn nên ghi gọn dưới mã RO.
                    ->description(fn (WorkOrder $record): Htmlable => self::phoneSummary($record)),
                TextColumn::make('created_at')
                    ->label('Ngày tiếp nhận')
                    ->dateTime('d/m/Y H:i')
                    ->visibleFrom('md')
                    ->sortable(),
                TextColumn::make('reception_odo')
                    ->label('ODO lúc vào')
                    ->numeric(decimalPlaces: 0, locale: 'vi')
                    ->suffix(' km')
                    ->visibleFrom('md'),
                TextColumn::make('final_amount')
                    ->label('Tổng tiền')
                    ->visible(fn (): bool => User::currentCanSeeMoney())
                    ->visibleFrom('md')
                    ->formatStateUsing(fn (mixed $state): string => VietnameseMoney::format($state)),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn (WorkOrderStatus $state): string => $state->getLabel())
                    ->color(fn (WorkOrderStatus $state): string => $state->getColor()),
                TextColumn::make('serviceAdvisor.name')
                    ->label('Cố vấn phụ trách')
                    ->visibleFrom('md'),
                TextColumn::make('job_lines')
                    ->label('Hạng mục đã làm')
                    ->visibleFrom('md')
                    ->state(fn (WorkOrder $record): array => $record->jobLines())
                    ->listWithLineBreaks()
                    ->limitList(2)
                    ->expandableLimitedList(),
            ])
            // Máy tính: nút rời; điện thoại: gom vào nút ⋮ (App\Filament\Support\PhoneTable).
            ->recordActions(PhoneTable::actions(fn (): array => [
                Action::make('viewOrder')
                    ->label('Xem phiếu sửa chữa')
                    ->icon(Heroicon::OutlinedEye)
                    ->url(fn (WorkOrder $record): string => WorkOrderResource::getUrl('view', ['record' => $record])),
                Action::make('printInvoice')
                    ->authorize(fn (): bool => User::currentCanSeeMoney())
                    ->label('In lại Hóa đơn')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->color('success')
                    ->visible(fn (WorkOrder $record): bool => $record->invoices->isNotEmpty())
                    ->url(fn (WorkOrder $record): string => route('work-orders.invoice', $record))
                    ->openUrlInNewTab(),
            ]))
            ->defaultSort('created_at', 'desc');
    }

    /**
     * Dòng phụ dưới mã RO, chỉ hiện trên điện thoại: ngày tiếp nhận · ODO · tổng tiền (chỉ người được xem tiền) · cố vấn.
     */
    public static function phoneSummary(WorkOrder $record): Htmlable
    {
        return PhoneTable::summary([
            $record->created_at?->timezone((string) config('garage.timezone'))->format('d/m/Y H:i'),
            $record->reception_odo !== null ? number_format((int) $record->reception_odo, 0, ',', '.').' km' : null,
            User::currentCanSeeMoney() ? new HtmlString('<b>'.e(VietnameseMoney::format($record->final_amount)).'</b>') : null,
            $record->serviceAdvisor?->name,
        ]);
    }
}
