<?php

namespace App\Filament\Resources\Customers;

use App\Enums\CustomerType;
use App\Filament\Forms\MoneyInput;
use App\Filament\Resources\Customers\Pages\CreateCustomer;
use App\Filament\Resources\Customers\Pages\EditCustomer;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Customers\Pages\ViewCustomer;
use App\Filament\Resources\Customers\RelationManagers\VehiclesRelationManager;
use App\Models\Customer;
use App\Models\User;
use App\Services\WalletService;
use App\Support\VietnameseMoney;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Tiếp nhận';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Khách hàng';

    protected static ?string $modelLabel = 'khách hàng';

    protected static ?string $pluralModelLabel = 'khách hàng';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Họ tên')
                    ->required()
                    ->maxLength(255),
                TextInput::make('phone')
                    ->label('Số điện thoại')
                    ->tel()
                    ->required()
                    ->maxLength(20)
                    ->unique(ignoreRecord: true)
                    ->regex('/^(0|\+84)(3|5|7|8|9)\d{8}$/')
                    ->validationMessages([
                        'regex' => 'Số điện thoại phải là số di động Việt Nam, ví dụ 0901234567.',
                    ]),
                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->maxLength(255)
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? $state : null),
                Select::make('customer_type')
                    ->label('Loại khách')
                    ->options(CustomerType::class)
                    ->required()
                    ->native(false),
                Textarea::make('address')
                    ->label('Địa chỉ')
                    ->rows(3)
                    ->columnSpanFull()
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? $state : null),
                TextInput::make('tax_code')
                    ->label('Mã số thuế')
                    ->maxLength(20)
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? $state : null),
                MoneyInput::make('credit_limit')
                    ->label('Hạn mức công nợ')
                    ->visible(fn (Get $get): bool => $get('customer_type') === CustomerType::Company->value
                        || $get('customer_type') === CustomerType::Company),
                TextInput::make('payment_terms_days')
                    ->label('Kỳ hạn TT (ngày)')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->visible(fn (Get $get): bool => $get('customer_type') === CustomerType::Company->value
                        || $get('customer_type') === CustomerType::Company),
                TextInput::make('loyalty_points')
                    ->label('Điểm loyalty')
                    ->numeric()
                    ->disabled()
                    ->dehydrated(false)
                    ->visibleOn('edit'),
                TextInput::make('loyalty_tier')
                    ->label('Hạng thành viên')
                    ->disabled()
                    ->dehydrated(false)
                    ->formatStateUsing(fn ($state): string => $state instanceof \App\Enums\LoyaltyTier
                        ? $state->getLabel()
                        : (string) ($state ?? 'Thành viên'))
                    ->visibleOn('edit'),
                TextInput::make('wallet_balance')
                    ->label('Số dư ví trả trước')
                    ->disabled()
                    ->dehydrated(false)
                    ->formatStateUsing(fn ($state): string => VietnameseMoney::format((float) ($state ?? 0)))
                    ->visibleOn(['edit', 'view']),
                Toggle::make('messaging_opt_in')
                    ->label('Đồng ý nhận tin Zalo/SMS')
                    ->helperText('Bắt buộc để gửi nhắc bảo dưỡng / lịch hẹn.')
                    ->inline(false)
                    ->live()
                    ->afterStateUpdated(function ($state, callable $set): void {
                        if ($state) {
                            $set('messaging_opt_in_at', now());
                            $set('messaging_opt_in_source', 'manual');
                        }
                    }),
                Toggle::make('reminder_opt_out')
                    ->label('Không nhận tin nhắc bảo dưỡng')
                    ->helperText('Khách vẫn nhận tin báo đã nhận tiền.')
                    ->inline(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Họ tên')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('phone')
                    ->label('Số điện thoại')
                    ->searchable(),
                TextColumn::make('customer_type')
                    ->label('Loại khách')
                    ->badge()
                    ->formatStateUsing(fn (CustomerType $state): string => $state->getLabel())
                    ->color(fn (CustomerType $state): string => match ($state) {
                        CustomerType::Individual => 'info',
                        CustomerType::Company => 'warning',
                    }),
                TextColumn::make('vehicles_count')
                    ->label('Số xe')
                    ->counts('vehicles')
                    ->sortable(),
                TextColumn::make('loyalty_points')
                    ->label('Điểm')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('loyalty_tier')
                    ->label('Hạng')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('wallet_balance')
                    ->label('Ví')
                    ->money('VND')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('customer_type')
                    ->label('Loại khách')
                    ->options(CustomerType::class),
            ])
            ->recordUrl(fn (Customer $record): string => static::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                self::depositWalletAction(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('name');
    }

    public static function getRelations(): array
    {
        return [
            VehiclesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomers::route('/'),
            'create' => CreateCustomer::route('/create'),
            'view' => ViewCustomer::route('/{record}'),
            'edit' => EditCustomer::route('/{record}/edit'),
        ];
    }

    public static function depositWalletAction(): Action
    {
        return Action::make('depositWallet')
            ->label('Nạp ví')
            ->icon(Heroicon::OutlinedWallet)
            ->color('success')
            ->visible(fn (): bool => User::current()?->canSeeMoney() ?? false)
            ->form([
                MoneyInput::make('amount')
                    ->label('Số tiền nạp')
                    ->required(),
                Textarea::make('reason')
                    ->label('Lý do / ghi chú')
                    ->required()
                    ->rows(2)
                    ->default('Nạp tiền trả trước'),
            ])
            ->action(function (Customer $record, array $data): void {
                try {
                    $tx = app(WalletService::class)->deposit(
                        $record,
                        VietnameseMoney::parse($data['amount']) ?? 0,
                        (string) $data['reason'],
                        User::current()?->id,
                    );
                    Notification::make()
                        ->title('Đã nạp ví '.VietnameseMoney::format((float) $tx->amount))
                        ->body('Số dư: '.VietnameseMoney::format((float) $tx->balance_after))
                        ->success()
                        ->send();
                } catch (DomainException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }
}
