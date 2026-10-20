<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Enums\AppointmentStatus;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Models\Appointment;
use App\Services\AppointmentService;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class EditAppointment extends EditRecord
{
    protected static string $resource = AppointmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AppointmentResource::checkInAction(),
            AppointmentResource::cancelAction(),
            AppointmentResource::noShowAction(),
        ];
    }

    protected function authorizeAccess(): void
    {
        parent::authorizeAccess();
        /** @var Appointment $record */
        $record = $this->getRecord();
        abort_unless($record->status === AppointmentStatus::Scheduled, 403);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Appointment $record */
        $start = Carbon::parse($data['scheduled_start']);
        $end = isset($data['scheduled_end']) && filled($data['scheduled_end'])
            ? Carbon::parse($data['scheduled_end'])
            : $start->copy()->addMinutes((int) config('garage.appointments.default_duration_minutes', 60));

        try {
            app(AppointmentService::class)->assertBayAvailable(
                $data['lift_bay'] ?? null,
                $start,
                $end,
                $record->id,
            );
        } catch (DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            $this->halt();
        }

        $data['scheduled_end'] = $end;

        return parent::handleRecordUpdate($record, $data);
    }
}
