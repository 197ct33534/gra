<?php

namespace App\Policies;

use App\Models\CustomerMessage;
use App\Models\User;
use App\Policies\Concerns\ChecksGarageRoles;

class CustomerMessagePolicy
{
    use ChecksGarageRoles;

    public function viewAny(User $user): bool
    {
        return $this->isStaffOffice($user);
    }

    public function view(User $user, CustomerMessage $customerMessage): bool
    {
        return $this->isStaffOffice($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, CustomerMessage $customerMessage): bool
    {
        return $this->isStaffOffice($user);
    }

    public function delete(User $user, CustomerMessage $customerMessage): bool
    {
        return false;
    }
}
