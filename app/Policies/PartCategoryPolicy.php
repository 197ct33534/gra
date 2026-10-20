<?php

namespace App\Policies;

use App\Models\PartCategory;
use App\Models\User;
use App\Policies\Concerns\ChecksGarageRoles;

class PartCategoryPolicy
{
    use ChecksGarageRoles;

    public function viewAny(User $user): bool
    {
        return $this->isStaffOffice($user);
    }

    public function view(User $user, PartCategory $partCategory): bool
    {
        return $this->isStaffOffice($user);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, PartCategory $partCategory): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, PartCategory $partCategory): bool
    {
        return $user->isAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }
}
