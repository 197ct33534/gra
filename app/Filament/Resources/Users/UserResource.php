<?php

namespace App\Filament\Resources\Users;

use App\Enums\UserRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use App\Services\PayrollService;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Quản trị';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Nhân viên';

    protected static ?string $modelLabel = 'nhân viên';

    protected static ?string $pluralModelLabel = 'nhân viên';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Họ tên')
                ->required()
                ->maxLength(255),
            TextInput::make('email')
                ->label('Email đăng nhập')
                ->email()
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255),
            Select::make('role')
                ->label('Vai trò')
                ->options(UserRole::class)
                ->required()
                ->native(false)
                ->live()
                ->helperText('Quản trị: xem giá vốn, lãi gộp, nhật ký. Cố vấn: tiếp nhận, báo giá, thu tiền. Kỹ thuật viên: xem lệnh và bảng điều phối, không thấy tiền.'),
            TextInput::make('commission_rate')
                ->label('% tiền công được hưởng (lương khoán)')
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->suffix('%')
                ->placeholder(fn (): string => 'Mặc định '.PayrollService::defaultRate().'%')
                ->helperText('Áp dụng cho hạng mục công không có mức khoán cố định.')
                ->visible(fn (Get $get): bool => in_array($get('role'), [UserRole::Technician, UserRole::Technician->value], true)),
            TextInput::make('password')
                ->label('Mật khẩu')
                ->password()
                ->revealable()
                ->minLength(8)
                ->required(fn (mixed $livewire): bool => $livewire instanceof CreateRecord)
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->helperText('Để trống khi sửa nếu không đổi mật khẩu.'),
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
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),
                TextColumn::make('role')
                    ->label('Vai trò')
                    ->badge()
                    ->color(fn (?UserRole $state): string => match ($state) {
                        UserRole::Admin => 'danger',
                        UserRole::ServiceAdvisor => 'info',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->label('Vai trò')
                    ->options(UserRole::class),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
