<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;
use App\Policies\Concerns\ChecksGarageRoles;

class CustomerPolicy
{
    use ChecksGarageRoles;

    public function viewAny(User $user): bool
    {
        return $this->isStaffOffice($user);
    }

    public function view(User $user, Customer $customer): bool
    {
        return $this->isStaffOffice($user);
    }

    public function create(User $user): bool
    {
        return $this->isStaffOffice($user);
    }

    public function update(User $user, Customer $customer): bool
    {
        return $this->isStaffOffice($user);
    }

    public function delete(User $user, Customer $customer): bool
    {
        return $user->isAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }
}
