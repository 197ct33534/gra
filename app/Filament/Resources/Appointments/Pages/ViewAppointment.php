<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Filament\Resources\Appointments\AppointmentResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAppointment extends ViewRecord
{
    protected static string $resource = AppointmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AppointmentResource::checkInAction(),
            AppointmentResource::cancelAction(),
            AppointmentResource::noShowAction(),
            EditAction::make()
                ->visible(fn (): bool => $this->getRecord()->status === \App\Enums\AppointmentStatus::Scheduled),
        ];
    }
}
