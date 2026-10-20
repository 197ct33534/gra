<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WarrantyCard;
use App\Policies\Concerns\ChecksGarageRoles;

class WarrantyCardPolicy
{
    use ChecksGarageRoles;

    public function viewAny(User $user): bool
    {
        return $this->isStaffOffice($user);
    }

    public function view(User $user, WarrantyCard $warrantyCard): bool
    {
        return $this->isStaffOffice($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, WarrantyCard $warrantyCard): bool
    {
        return $this->isStaffOffice($user);
    }

    public function delete(User $user, WarrantyCard $warrantyCard): bool
    {
        return false;
    }
}
