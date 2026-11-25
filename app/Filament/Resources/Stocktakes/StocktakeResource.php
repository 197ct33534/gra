<?php

namespace App\Filament\Resources\Stocktakes;

use App\Enums\StocktakeStatus;
use App\Filament\Resources\Stocktakes\Pages\CreateStocktake;
use App\Filament\Resources\Stocktakes\Pages\EditStocktake;
use App\Filament\Resources\Stocktakes\Pages\ListStocktakes;
use App\Filament\Resources\Stocktakes\Pages\ViewStocktake;
use App\Filament\Support\PhoneTable;
use App\Models\Part;
use App\Models\Stocktake;
use App\Models\User;
use App\Services\InventoryService;
use App\Support\VietnameseMoney;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
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
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class StocktakeResource extends Resource
{
    protected static ?string $model = Stocktake::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Kho & dịch vụ';

    protected static ?int $navigationSort = 4;

    protected static ?string $navigationLabel = 'Kiểm kê kho';

    protected static ?string $modelLabel = 'phiếu kiểm kê';

    protected static ?string $pluralModelLabel = 'phiếu kiểm kê';

    protected static ?string $recordTitleAttribute = 'code';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('note')
                ->label('Ghi chú')
                ->rows(2)
                ->columnSpanFull()
                ->visibleOn('create'),
            TextInput::make('code')
                ->label('Mã phiếu')
                ->disabled()
                ->visibleOn(['edit', 'view']),
            Repeater::make('items')
                ->label('Dòng kiểm kê')
                ->relationship()
                ->schema([
                    TextInput::make('part_id')
                        ->label('Phụ tùng')
                        ->formatStateUsing(function (mixed $state, $record): string {
                            $part = $record?->part;
                            if ($part === null && filled($state)) {
                                $part = Part::query()->find($state);
                            }

                            return $part ? $part->sku.' — '.$part->name : (string) $state;
                        })
                        ->disabled()
                        ->dehydrated(),
                    TextInput::make('system_quantity')
                        ->label('Tồn HT')
                        ->disabled()
                        ->dehydrated(),
                    TextInput::make('counted_quantity')
                        ->label('Số đếm thực tế')
                        ->numeric()
                        ->minValue(0)
                        ->required(),
                    TextInput::make('note')
                        ->label('Ghi chú dòng')
                        ->maxLength(255),
                ])
                ->columns(4)
                ->columnSpanFull()
                ->addable(false)
                ->deletable(false)
                ->reorderable(false)
                ->visibleOn('edit'),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('code')->label('Mã phiếu'),
            TextEntry::make('status')->label('Trạng thái')->badge(),
            TextEntry::make('creator.name')->label('Người tạo'),
            TextEntry::make('counted_at')->label('Bắt đầu đếm')->dateTime('d/m/Y H:i')->placeholder('—'),
            TextEntry::make('applied_at')->label('Áp dụng lúc')->dateTime('d/m/Y H:i')->placeholder('—'),
            TextEntry::make('appliedByUser.name')->label('Người áp dụng')->placeholder('—'),
            TextEntry::make('variance')
                ->label('Giá trị lệch')
                ->state(fn (Stocktake $record): string => VietnameseMoney::format($record->varianceValue()))
                ->visible(fn (): bool => User::currentCanSeeCosts()),
            TextEntry::make('note')->label('Ghi chú')->placeholder('—')->columnSpanFull(),
            RepeatableEntry::make('items')
                ->label('Chi tiết')
                ->schema([
                    TextEntry::make('part.sku')->label('SKU'),
                    TextEntry::make('part.name')->label('Tên'),
                    TextEntry::make('system_quantity')->label('HT'),
                    TextEntry::make('counted_quantity')->label('Đếm')->placeholder('—'),
                    TextEntry::make('difference')->label('Lệch')->placeholder('—'),
                    TextEntry::make('line_value')
                        ->label('Giá trị lệch')
                        ->state(fn ($record): string => VietnameseMoney::format($record->varianceValue()))
                        ->visible(fn (): bool => User::currentCanSeeCosts()),
                ])
                ->columns(6)
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
                    ->sortable()
                    // Điện thoại: số mã / giá trị lệch / người tạo / ngày áp dụng bị ẩn nên ghi gọn dưới mã.
                    ->description(fn (Stocktake $record): Htmlable => PhoneTable::summary([
                        $record->items_count.' mã',
                        User::currentCanSeeCosts() ? 'Lệch '.VietnameseMoney::format($record->varianceValue()) : null,
                        $record->creator?->name,
                        $record->applied_at !== null
                            ? 'Áp dụng '.$record->applied_at->timezone((string) config('garage.timezone'))->format('d/m/Y H:i')
                            : null,
                    ])),
                TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge(),
                TextColumn::make('items_count')
                    ->label('Số mã')
                    ->visibleFrom('md')
                    ->counts('items'),
                TextColumn::make('variance')
                    ->label('Giá trị lệch')
                    ->state(fn (Stocktake $record): string => VietnameseMoney::format($record->varianceValue()))
                    ->visible(fn (): bool => User::currentCanSeeCosts())
                    ->visibleFrom('md'),
                TextColumn::make('creator.name')
                    ->label('Người tạo')
                    ->visibleFrom('md'),
                TextColumn::make('applied_at')
                    ->label('Áp dụng')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->visibleFrom('md')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Trạng thái')
                    ->options(StocktakeStatus::class),
            ])
            // Máy tính: nút rời; điện thoại: gom vào nút ⋮ (App\Filament\Support\PhoneTable).
            ->recordActions(PhoneTable::actions(fn (): array => [
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (Stocktake $record): bool => $record->status->canEditCounts() && (User::current()?->isAdmin() ?? false)),
            ]))
            ->defaultSort('id', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['creator', 'items.part', 'appliedByUser']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStocktakes::route('/'),
            'create' => CreateStocktake::route('/create'),
            'view' => ViewStocktake::route('/{record}'),
            'edit' => EditStocktake::route('/{record}/edit'),
        ];
    }

    public static function startCountingAction(): Action
    {
        return Action::make('startCounting')
            ->label('Bắt đầu đếm')
            ->icon(Heroicon::OutlinedPlay)
            ->color('warning')
            ->visible(fn (Stocktake $record): bool => $record->status === StocktakeStatus::Draft && (User::current()?->isAdmin() ?? false))
            ->action(function (Stocktake $record): void {
                app(InventoryService::class)->startCounting($record);
                Notification::make()->title('Đã chuyển sang Đang đếm.')->success()->send();
            });
    }

    public static function applyAction(): Action
    {
        return Action::make('applyStocktake')
            ->label('Áp dụng lệch tồn')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Áp dụng phiếu kiểm kê?')
            ->modalDescription('Tồn kho sẽ được chỉnh theo số đếm; mỗi mã lệch ghi một dòng thẻ kho.')
            ->visible(fn (Stocktake $record): bool => $record->status->canApply() && (User::current()?->isAdmin() ?? false))
            ->action(function (Stocktake $record): void {
                $user = User::current();
                if ($user === null) {
                    return;
                }

                try {
                    $applied = app(InventoryService::class)->applyStocktake($record, $user->id);
                    Notification::make()
                        ->title('Đã áp dụng '.$applied->code)
                        ->body('Giá trị lệch: '.VietnameseMoney::format($applied->varianceValue()))
                        ->success()
                        ->send();
                } catch (DomainException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }

    public static function cancelAction(): Action
    {
        return Action::make('cancelStocktake')
            ->label('Hủy phiếu')
            ->icon(Heroicon::OutlinedXMark)
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (Stocktake $record): bool => in_array($record->status, [
                StocktakeStatus::Draft,
                StocktakeStatus::Counting,
            ], true) && (User::current()?->isAdmin() ?? false))
            ->action(function (Stocktake $record): void {
                try {
                    app(InventoryService::class)->cancelStocktake($record);
                    Notification::make()->title('Đã hủy phiếu kiểm kê.')->success()->send();
                } catch (DomainException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }
}
