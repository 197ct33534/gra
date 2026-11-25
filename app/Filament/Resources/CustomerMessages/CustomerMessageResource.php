<?php

namespace App\Filament\Resources\CustomerMessages;

use App\Enums\CustomerMessageStatus;
use App\Enums\CustomerMessageType;
use App\Filament\Resources\CustomerMessages\Pages\ListCustomerMessages;
use App\Filament\Support\PhoneTable;
use App\Models\CustomerMessage;
use App\Services\MessagingService;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
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
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use UnitEnum;

class CustomerMessageResource extends Resource
{
    protected static ?string $model = CustomerMessage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    protected static string|UnitEnum|null $navigationGroup = 'Tiếp nhận';

    protected static ?int $navigationSort = 6;

    protected static ?string $navigationLabel = 'Tin nhắn khách';

    protected static ?string $modelLabel = 'tin nhắn';

    protected static ?string $pluralModelLabel = 'tin nhắn khách';

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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Tạo')->dateTime('d/m H:i')->visibleFrom('md')->sortable(),
                TextColumn::make('customer.name')
                    ->label('Khách')
                    ->searchable()
                    // Điện thoại: các cột SĐT / loại / kênh / giờ tạo / lỗi bị ẩn nên ghi gọn dưới tên khách.
                    ->description(fn (CustomerMessage $record): Htmlable => self::phoneSummary($record)),
                TextColumn::make('phone')->label('SĐT')->visibleFrom('md')->searchable(),
                TextColumn::make('type')->label('Loại')->badge()->visibleFrom('md'),
                TextColumn::make('channel')->label('Kênh')->visibleFrom('md'),
                TextColumn::make('status')->label('TT')->badge(),
                TextColumn::make('attempts')->label('Thử')->visibleFrom('md'),
                TextColumn::make('error')->label('Lỗi')->limit(40)->placeholder('—')->visibleFrom('md')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(CustomerMessageStatus::class),
                SelectFilter::make('type')->options(CustomerMessageType::class),
            ])
            ->recordActions([
                Action::make('retry')
                    ->label('Gửi lại')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (CustomerMessage $record): bool => in_array($record->status, [
                        CustomerMessageStatus::Failed,
                        CustomerMessageStatus::Pending,
                    ], true))
                    ->action(function (CustomerMessage $record): void {
                        try {
                            $updated = app(MessagingService::class)->retry($record);
                            Notification::make()
                                ->title($updated->status === CustomerMessageStatus::Sent ? 'Đã gửi.' : 'Vẫn lỗi / đang chờ.')
                                ->body($updated->error)
                                ->color($updated->status === CustomerMessageStatus::Sent ? 'success' : 'warning')
                                ->send();
                        } catch (DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
            ])
            ->defaultSort('id', 'desc');
    }

    /**
     * Dòng phụ dưới tên khách, chỉ hiện trên điện thoại: SĐT · loại tin · kênh · giờ tạo · lỗi (rút gọn, chữ đỏ).
     */
    public static function phoneSummary(CustomerMessage $record): Htmlable
    {
        return PhoneTable::summary([
            $record->phone,
            $record->type?->getLabel(),
            $record->channel,
            $record->created_at?->timezone((string) config('garage.timezone'))->format('d/m H:i'),
            filled($record->error)
                ? new HtmlString('<span style="color:#dc2626">'.e(Str::limit((string) $record->error, 40)).'</span>')
                : null,
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('customer');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomerMessages::route('/'),
        ];
    }
}
