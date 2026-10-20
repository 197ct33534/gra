<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Filament\Resources\Appointments\AppointmentResource;
use App\Models\User;
use App\Services\AppointmentService;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateAppointment extends CreateRecord
{
    protected static string $resource = AppointmentResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $user = User::current();
        abort_if($user === null, 403);

        try {
            return app(AppointmentService::class)->create($data, $user->id);
        } catch (DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
            $this->halt();
        }
    }
}
