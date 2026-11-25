<?php

namespace App\Filament\Resources\WarrantyCards;

use App\Enums\WarrantyCardStatus;
use App\Filament\Resources\WarrantyCards\Pages\ListWarrantyCards;
use App\Filament\Resources\WarrantyCards\Pages\ViewWarrantyCard;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Filament\Support\PhoneTable;
use App\Models\User;
use App\Models\WarrantyCard;
use App\Services\WarrantyService;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
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
use Illuminate\Support\Str;
use UnitEnum;

class WarrantyCardResource extends Resource
{
    protected static ?string $model = WarrantyCard::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Tiếp nhận';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Thẻ bảo hành';

    protected static ?string $modelLabel = 'thẻ bảo hành';

    protected static ?string $pluralModelLabel = 'thẻ bảo hành';

    protected static ?string $recordTitleAttribute = 'code';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('code')->label('Mã thẻ'),
            TextEntry::make('status')->label('Trạng thái')->badge(),
            TextEntry::make('item_name')->label('Hạng mục'),
            TextEntry::make('vehicle.license_plate')->label('Biển số'),
            TextEntry::make('customer.name')->label('Khách'),
            TextEntry::make('starts_at')->label('Bắt đầu')->date('d/m/Y'),
            TextEntry::make('ends_at')->label('Hết hạn ngày')->date('d/m/Y')->placeholder('—'),
            TextEntry::make('start_odo')->label('ODO đầu')->placeholder('—'),
            TextEntry::make('end_odo')->label('ODO hết hạn')->placeholder('—'),
            TextEntry::make('workOrder.code')->label('RO gốc'),
            TextEntry::make('terms')->label('Điều khoản')->columnSpanFull()->placeholder('—'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('Mã')->visibleFrom('md')->searchable()->sortable(),
                TextColumn::make('vehicle.license_plate')
                    ->label('Biển số')
                    ->searchable()
                    // Điện thoại: các cột mã / hạng mục / hết hạn / km hết bị ẩn nên ghi gọn dưới biển số.
                    ->description(fn (WarrantyCard $record): Htmlable => self::phoneSummary($record)),
                TextColumn::make('item_name')->label('Hạng mục')->limit(40)->visibleFrom('md'),
                TextColumn::make('ends_at')->label('Hết hạn')->date('d/m/Y')->placeholder('—')->visibleFrom('md')->sortable(),
                TextColumn::make('end_odo')->label('Km hết')->placeholder('—')->visibleFrom('md'),
                TextColumn::make('status')->label('TT')->badge(),
            ])
            ->filters([
                SelectFilter::make('status')->options(WarrantyCardStatus::class),
            ])
            // Máy tính: nút rời; điện thoại: gom vào nút ⋮ (App\Filament\Support\PhoneTable).
            ->recordActions(PhoneTable::actions(fn (): array => [
                ViewAction::make(),
                Action::make('printCard')
                    ->label('In phiếu')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->url(fn (WarrantyCard $record): string => route('warranty-cards.pdf', $record))
                    ->openUrlInNewTab(),
                Action::make('openClaim')
                    ->label('Tiếp nhận claim')
                    ->icon(Heroicon::OutlinedWrenchScrewdriver)
                    ->color('warning')
                    ->visible(fn (WarrantyCard $record): bool => $record->status === WarrantyCardStatus::Active)
                    ->form([
                        Textarea::make('symptom')->label('Triệu chứng / lý do BH')->required()->rows(3),
                        TextInput::make('reception_odo')->label('ODO hiện tại')->numeric()->minValue(0),
                    ])
                    ->action(function (WarrantyCard $record, array $data): void {
                        $user = User::current();
                        if ($user === null) {
                            return;
                        }

                        try {
                            $order = app(WarrantyService::class)->openClaim(
                                $record,
                                $user->id,
                                (string) $data['symptom'],
                                isset($data['reception_odo']) ? (int) $data['reception_odo'] : null,
                            );
                            Notification::make()
                                ->title('Đã tạo lệnh bảo hành '.$order->code)
                                ->success()
                                ->send();
                        } catch (DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    })
                    ->successRedirectUrl(fn (WarrantyCard $record): ?string => $record->fresh()?->claims()->latest('id')->first()?->work_order_id
                        ? WorkOrderResource::getUrl('edit', ['record' => $record->fresh()->claims()->latest('id')->first()->work_order_id])
                        : null),
            ]))
            ->defaultSort('id', 'desc');
    }

    /**
     * Dòng phụ dưới biển số, chỉ hiện trên điện thoại: mã phiếu · hạng mục · hết hạn · km hết.
     */
    public static function phoneSummary(WarrantyCard $record): Htmlable
    {
        return PhoneTable::summary([
            $record->code,
            filled($record->item_name) ? Str::limit((string) $record->item_name, 40) : null,
            $record->ends_at !== null ? 'Hết hạn '.$record->ends_at->format('d/m/Y') : null,
            $record->end_odo !== null ? 'Đến '.number_format($record->end_odo, 0, ',', '.').' km' : null,
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['vehicle', 'customer', 'workOrder']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWarrantyCards::route('/'),
            'view' => ViewWarrantyCard::route('/{record}'),
        ];
    }
}
