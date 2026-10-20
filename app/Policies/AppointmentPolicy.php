<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;
use App\Policies\Concerns\ChecksGarageRoles;

class AppointmentPolicy
{
    use ChecksGarageRoles;

    public function viewAny(User $user): bool
    {
        return $this->isStaffOffice($user);
    }

    public function view(User $user, Appointment $appointment): bool
    {
        return $this->isStaffOffice($user);
    }

    public function create(User $user): bool
    {
        return $this->isStaffOffice($user);
    }

    public function update(User $user, Appointment $appointment): bool
    {
        return $this->isStaffOffice($user);
    }

    public function delete(User $user, Appointment $appointment): bool
    {
        return $user->isAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }
}
