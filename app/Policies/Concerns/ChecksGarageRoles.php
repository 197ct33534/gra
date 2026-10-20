<?php

namespace App\Policies\Concerns;

use App\Models\User;

trait ChecksGarageRoles
{
    protected function isStaffOffice(User $user): bool
    {
        return $user->isAdmin() || $user->isServiceAdvisor();
    }
}
