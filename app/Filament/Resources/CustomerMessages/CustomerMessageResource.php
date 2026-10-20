<?php

namespace App\Filament\Resources\CustomerMessages;

use App\Enums\CustomerMessageStatus;
use App\Enums\CustomerMessageType;
use App\Filament\Resources\CustomerMessages\Pages\ListCustomerMessages;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
                TextColumn::make('created_at')->label('Tạo')->dateTime('d/m H:i')->sortable(),
                TextColumn::make('customer.name')->label('Khách')->searchable(),
                TextColumn::make('phone')->label('SĐT')->searchable(),
                TextColumn::make('type')->label('Loại')->badge(),
                TextColumn::make('channel')->label('Kênh'),
                TextColumn::make('status')->label('TT')->badge(),
                TextColumn::make('attempts')->label('Thử'),
                TextColumn::make('error')->label('Lỗi')->limit(40)->placeholder('—')->toggleable(),
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
