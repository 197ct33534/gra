<?php

namespace App\Filament\Resources\ActivityLogs;

use App\Enums\ActivityEvent;
use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\WorkOrder;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ActivityLogResource extends Resource
{
    protected static ?string $model = ActivityLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|UnitEnum|null $navigationGroup = 'Quản trị';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Nhật ký thao tác';

    protected static ?string $modelLabel = 'nhật ký';

    protected static ?string $pluralModelLabel = 'nhật ký thao tác';

    public static function canViewAny(): bool
    {
        return User::current()?->isAdmin() ?? false;
    }

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

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Thời gian')
                    ->dateTime('H:i d/m/Y')
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Người thực hiện')
                    ->placeholder('Hệ thống'),
                TextColumn::make('event')
                    ->label('Thao tác')
                    ->badge(),
                TextColumn::make('description')
                    ->label('Chi tiết')
                    ->wrap()
                    ->searchable(),
                TextColumn::make('subject_label')
                    ->label('Chứng từ')
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->url(fn (ActivityLog $record): ?string => $record->subject_type === (new WorkOrder)->getMorphClass() && $record->event !== ActivityEvent::OrderDeleted
                        ? WorkOrderResource::getUrl('view', ['record' => $record->subject_id])
                        : null)
                    ->searchable(),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->label('Thao tác')
                    ->options(ActivityEvent::class)
                    ->multiple(),
                SelectFilter::make('user_id')
                    ->label('Người thực hiện')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('created_at')
                    ->label('Khoảng ngày')
                    ->schema([
                        DatePicker::make('from')->label('Từ ngày'),
                        DatePicker::make('until')->label('Đến ngày'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('created_at', '<=', $date))),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('user');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivityLogs::route('/'),
        ];
    }
}
