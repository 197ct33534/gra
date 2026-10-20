<?php

namespace App\Policies;

use App\Models\FleetContract;
use App\Models\User;
use App\Policies\Concerns\ChecksGarageRoles;

class FleetContractPolicy
{
    use ChecksGarageRoles;

    public function viewAny(User $user): bool
    {
        return $this->isStaffOffice($user);
    }

    public function view(User $user, FleetContract $fleetContract): bool
    {
        return $this->isStaffOffice($user);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, FleetContract $fleetContract): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, FleetContract $fleetContract): bool
    {
        return $user->isAdmin();
    }
}
