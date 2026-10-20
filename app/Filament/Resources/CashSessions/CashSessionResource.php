<?php

namespace App\Filament\Resources\CashSessions;

use App\Enums\CashMovementCategory;
use App\Enums\CashMovementDirection;
use App\Enums\CashSessionStatus;
use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\CashSessions\Pages\ListCashSessions;
use App\Filament\Resources\CashSessions\Pages\ViewCashSession;
use App\Models\CashSession;
use App\Models\User;
use App\Services\CashService;
use App\Support\VietnameseMoney;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
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
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class CashSessionResource extends Resource
{
    protected static ?string $model = CashSession::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static string|UnitEnum|null $navigationGroup = 'Thu ngân';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Sổ quỹ tiền mặt';

    protected static ?string $modelLabel = 'ca quỹ';

    protected static ?string $pluralModelLabel = 'ca quỹ';

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
            TextEntry::make('status')->label('Trạng thái')->badge(),
            TextEntry::make('opened_at')->label('Mở lúc')->dateTime('d/m/Y H:i'),
            TextEntry::make('opener.name')->label('Người mở'),
            TextEntry::make('opening_balance')
                ->label('Dư đầu')
                ->formatStateUsing(fn (mixed $s): string => VietnameseMoney::format($s)),
            TextEntry::make('expected')
                ->label('Dự kiến trong két')
                ->state(fn (CashSession $r): string => VietnameseMoney::format(
                    $r->status === CashSessionStatus::Closed
                        ? (float) $r->expected_balance
                        : $r->computedExpectedBalance()
                )),
            TextEntry::make('closing_counted')
                ->label('Đếm thực tế')
                ->formatStateUsing(fn (mixed $s): string => $s === null ? '—' : VietnameseMoney::format($s)),
            TextEntry::make('difference')
                ->label('Lệch')
                ->formatStateUsing(fn (mixed $s): string => $s === null ? '—' : VietnameseMoney::format($s)),
            TextEntry::make('closed_at')->label('Đóng lúc')->dateTime('d/m/Y H:i')->placeholder('—'),
            RepeatableEntry::make('movements')
                ->label('Biến động')
                ->schema([
                    TextEntry::make('occurred_at')->label('Lúc')->dateTime('d/m H:i'),
                    TextEntry::make('direction')->label('Chiều')->badge(),
                    TextEntry::make('category')->label('Loại')->badge(),
                    TextEntry::make('amount')->label('Số tiền')->formatStateUsing(fn (mixed $s): string => VietnameseMoney::format($s)),
                    TextEntry::make('note')->label('Ghi chú')->placeholder('—'),
                ])
                ->columns(5)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('opened_at')->label('Mở')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('opener.name')->label('Người mở'),
                TextColumn::make('status')->label('TT')->badge(),
                TextColumn::make('opening_balance')
                    ->label('Dư đầu')
                    ->formatStateUsing(fn (mixed $s): string => VietnameseMoney::format($s)),
                TextColumn::make('expected_balance')
                    ->label('Dự kiến')
                    ->state(fn (CashSession $r): string => VietnameseMoney::format(
                        $r->status === CashSessionStatus::Closed
                            ? (float) ($r->expected_balance ?? 0)
                            : $r->computedExpectedBalance()
                    )),
                TextColumn::make('difference')
                    ->label('Lệch')
                    ->formatStateUsing(fn (mixed $s): string => $s === null ? '—' : VietnameseMoney::format($s))
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->options(CashSessionStatus::class),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['opener', 'closer', 'movements']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCashSessions::route('/'),
            'view' => ViewCashSession::route('/{record}'),
        ];
    }

    public static function openSessionAction(): Action
    {
        return Action::make('openSession')
            ->label('Mở ca quỹ')
            ->icon(Heroicon::OutlinedLockOpen)
            ->color('success')
            ->visible(fn (): bool => (User::current()?->isAdmin() ?? false)
                && app(CashService::class)->currentOpenSession() === null)
            ->form([
                MoneyInput::make('opening_balance')->label('Tiền trong két lúc mở')->required()->default(0),
                Textarea::make('note')->label('Ghi chú')->rows(2),
            ])
            ->action(function (array $data): void {
                $user = User::current();
                if ($user === null) {
                    return;
                }

                try {
                    app(CashService::class)->openSession($user->id, $data['opening_balance'], $data['note'] ?? null);
                    Notification::make()->title('Đã mở ca quỹ.')->success()->send();
                } catch (DomainException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }

    public static function closeSessionAction(): Action
    {
        return Action::make('closeSession')
            ->label('Đóng ca')
            ->icon(Heroicon::OutlinedLockClosed)
            ->color('danger')
            ->visible(fn (): bool => (User::current()?->isAdmin() ?? false)
                && app(CashService::class)->currentOpenSession() !== null)
            ->form([
                MoneyInput::make('closing_counted')->label('Đếm tiền thực tế trong két')->required(),
                Textarea::make('note')->label('Ghi chú đóng ca')->rows(2),
            ])
            ->requiresConfirmation()
            ->action(function (array $data): void {
                $user = User::current();
                $session = app(CashService::class)->currentOpenSession();
                if ($user === null || $session === null) {
                    return;
                }

                try {
                    $closed = app(CashService::class)->closeSession(
                        $session,
                        $user->id,
                        $data['closing_counted'],
                        $data['note'] ?? null,
                    );
                    Notification::make()
                        ->title('Đã đóng ca')
                        ->body('Lệch: '.VietnameseMoney::format($closed->difference))
                        ->success()
                        ->send();
                } catch (DomainException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }

    public static function pettyExpenseAction(): Action
    {
        return Action::make('pettyExpense')
            ->label('Chi phí nhỏ')
            ->icon(Heroicon::OutlinedBanknotes)
            ->visible(fn (): bool => (User::current()?->isAdmin() ?? false)
                && app(CashService::class)->currentOpenSession() !== null)
            ->form([
                MoneyInput::make('amount')->label('Số tiền chi')->required(),
                Textarea::make('note')->label('Nội dung chi')->rows(2)->required(),
            ])
            ->action(function (array $data): void {
                $user = User::current();
                $session = app(CashService::class)->currentOpenSession();
                if ($user === null || $session === null) {
                    return;
                }

                try {
                    app(CashService::class)->recordMovement(
                        $session,
                        CashMovementDirection::Out,
                        $data['amount'],
                        CashMovementCategory::PettyExpense,
                        $user->id,
                        null,
                        $data['note'] ?? null,
                    );
                    Notification::make()->title('Đã ghi chi quỹ.')->success()->send();
                } catch (DomainException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }
}
