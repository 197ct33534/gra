<?php

namespace App\Filament\Resources\Appointments;

use App\Enums\AppointmentStatus;
use App\Filament\Resources\Appointments\Pages\CreateAppointment;
use App\Filament\Resources\Appointments\Pages\EditAppointment;
use App\Filament\Resources\Appointments\Pages\ListAppointments;
use App\Filament\Resources\Appointments\Pages\ViewAppointment;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Helpers\LicensePlateHelper;
use App\Models\Appointment;
use App\Models\User;
use App\Services\AppointmentService;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class AppointmentResource extends Resource
{
    protected static ?string $model = Appointment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Tiếp nhận';

    protected static ?int $navigationSort = 0;

    protected static ?string $navigationLabel = 'Lịch hẹn';

    protected static ?string $modelLabel = 'lịch hẹn';

    protected static ?string $pluralModelLabel = 'lịch hẹn';

    protected static ?string $recordTitleAttribute = 'code';

    public static function form(Schema $schema): Schema
    {
        $bays = collect(config('garage.lift_bays', []))
            ->mapWithKeys(fn (string $bay): array => [$bay => $bay])
            ->all();

        return $schema->components([
            Select::make('customer_id')
                ->label('Khách hàng')
                ->relationship('customer', 'name')
                ->searchable()
                ->preload()
                ->native(false),
            Select::make('vehicle_id')
                ->label('Xe')
                ->relationship('vehicle', 'license_plate')
                ->searchable()
                ->preload()
                ->native(false),
            TextInput::make('walk_in_name')
                ->label('Tên khách (chưa có hồ sơ)')
                ->maxLength(255),
            TextInput::make('walk_in_phone')
                ->label('SĐT (chưa có hồ sơ)')
                ->tel()
                ->maxLength(20),
            TextInput::make('walk_in_plate')
                ->label('Biển số (chưa có hồ sơ)')
                ->maxLength(20),
            DateTimePicker::make('scheduled_start')
                ->label('Giờ hẹn')
                ->required()
                ->seconds(false)
                ->native(false)
                ->default(now()->addHour()->startOfHour()),
            DateTimePicker::make('scheduled_end')
                ->label('Dự kiến xong')
                ->seconds(false)
                ->native(false)
                ->helperText('Để trống = +'.((int) config('garage.appointments.default_duration_minutes', 60)).' phút'),
            Select::make('lift_bay')
                ->label('Cầu nâng')
                ->options($bays)
                ->native(false),
            Select::make('service_advisor_id')
                ->label('Cố vấn')
                ->options(fn (): array => User::query()
                    ->whereIn('role', ['admin', 'service_advisor'])
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->native(false)
                ->default(fn (): ?int => User::current()?->id),
            Textarea::make('customer_request')
                ->label('Yêu cầu khách')
                ->rows(3)
                ->columnSpanFull(),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('code')->label('Mã lịch'),
            TextEntry::make('status')->label('Trạng thái')->badge(),
            TextEntry::make('scheduled_start')->label('Giờ hẹn')->dateTime('d/m/Y H:i'),
            TextEntry::make('scheduled_end')->label('Dự kiến xong')->dateTime('d/m/Y H:i')->placeholder('—'),
            TextEntry::make('lift_bay')->label('Cầu')->placeholder('—'),
            TextEntry::make('customer.name')->label('Khách')->placeholder(fn (Appointment $r): string => $r->walk_in_name ?? '—'),
            TextEntry::make('phone')->label('SĐT')->state(fn (Appointment $r): string => $r->displayPhone() ?? '—'),
            TextEntry::make('plate')->label('Biển số')->state(fn (Appointment $r): string => $r->displayPlate() ?? '—'),
            TextEntry::make('workOrder.code')->label('Lệnh RO')->placeholder('Chưa check-in'),
            TextEntry::make('customer_request')->label('Yêu cầu')->placeholder('—')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('Mã')->searchable()->sortable(),
                TextColumn::make('scheduled_start')->label('Giờ hẹn')->dateTime('d/m H:i')->sortable(),
                TextColumn::make('plate')
                    ->label('Biển số')
                    ->state(fn (Appointment $r): string => $r->displayPlate() ?? '—')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        // Như các ô tìm xe khác: "51k12345", "51K-123.45" đều ra cùng một xe.
                        $plate = LicensePlateHelper::clean($search);

                        return $query->where(function (Builder $q) use ($search, $plate): void {
                            if ($plate !== '') {
                                $q->whereRaw(LicensePlateHelper::cleanedColumnSql('appointments.walk_in_plate').' like ?', ['%'.$plate.'%']);
                            }

                            $q->orWhereHas('vehicle', fn (Builder $v): Builder => LicensePlateHelper::applyVehicleSearch($v, $search));
                        });
                    }),
                TextColumn::make('guest')
                    ->label('Khách')
                    ->state(fn (Appointment $r): string => $r->displayName()),
                TextColumn::make('lift_bay')->label('Cầu')->placeholder('—'),
                TextColumn::make('status')->label('TT')->badge(),
                TextColumn::make('workOrder.code')->label('RO')->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label('Trạng thái')->options(AppointmentStatus::class),
                Filter::make('today')
                    ->label('Hôm nay')
                    ->query(function (Builder $query): Builder {
                        $tz = (string) config('garage.timezone');
                        $start = now($tz)->startOfDay()->utc();
                        $end = now($tz)->endOfDay()->utc();

                        return $query->whereBetween('scheduled_start', [$start, $end]);
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (Appointment $record): bool => $record->status === AppointmentStatus::Scheduled),
            ])
            ->defaultSort('scheduled_start');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['customer', 'vehicle', 'workOrder', 'advisor']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAppointments::route('/'),
            'create' => CreateAppointment::route('/create'),
            'view' => ViewAppointment::route('/{record}'),
            'edit' => EditAppointment::route('/{record}/edit'),
        ];
    }

    public static function checkInAction(): Action
    {
        return Action::make('checkIn')
            ->label('Xe đã tới')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->visible(fn (Appointment $record): bool => $record->status === AppointmentStatus::Scheduled)
            ->form([
                TextInput::make('reception_odo')
                    ->label('ODO tiếp nhận')
                    ->numeric()
                    ->minValue(1)
                    ->required(fn (Appointment $record): bool => ! $record->vehicle_id)
                    ->default(fn (Appointment $record): ?int => $record->vehicle?->current_odo),
            ])
            ->action(function (Appointment $record, array $data): void {
                $user = User::current();
                if ($user === null) {
                    return;
                }

                try {
                    $order = app(AppointmentService::class)->checkIn(
                        $record,
                        $user->id,
                        $data['reception_odo'] ?? null,
                    );
                    Notification::make()
                        ->title('Check-in thành công')
                        ->body('Đã tạo lệnh '.$order->code)
                        ->success()
                        ->send();
                } catch (DomainException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            })
            ->successRedirectUrl(fn (Appointment $record): ?string => $record->fresh()?->work_order_id
                ? WorkOrderResource::getUrl('edit', ['record' => $record->fresh()->work_order_id])
                : null);
    }

    public static function cancelAction(): Action
    {
        return Action::make('cancelAppointment')
            ->label('Hủy lịch')
            ->color('danger')
            ->icon(Heroicon::OutlinedXMark)
            ->requiresConfirmation()
            ->form([
                Textarea::make('canceled_reason')->label('Lý do hủy')->rows(2),
            ])
            ->visible(fn (Appointment $record): bool => $record->status === AppointmentStatus::Scheduled)
            ->action(function (Appointment $record, array $data): void {
                try {
                    app(AppointmentService::class)->cancel($record, $data['canceled_reason'] ?? null);
                    Notification::make()->title('Đã hủy lịch hẹn.')->success()->send();
                } catch (DomainException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }

    public static function noShowAction(): Action
    {
        return Action::make('noShow')
            ->label('Không đến')
            ->color('warning')
            ->icon(Heroicon::OutlinedUserMinus)
            ->requiresConfirmation()
            ->visible(fn (Appointment $record): bool => $record->status === AppointmentStatus::Scheduled)
            ->action(function (Appointment $record): void {
                try {
                    app(AppointmentService::class)->markNoShow($record);
                    Notification::make()->title('Đã đánh dấu không đến.')->success()->send();
                } catch (DomainException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }
}
