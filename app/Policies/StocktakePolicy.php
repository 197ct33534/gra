<?php

namespace App\Policies;

use App\Models\Stocktake;
use App\Models\User;
use App\Policies\Concerns\ChecksGarageRoles;

class StocktakePolicy
{
    use ChecksGarageRoles;

    public function viewAny(User $user): bool
    {
        return $this->isStaffOffice($user);
    }

    public function view(User $user, Stocktake $stocktake): bool
    {
        return $this->isStaffOffice($user);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Stocktake $stocktake): bool
    {
        return $user->isAdmin() && $stocktake->status->canEditCounts();
    }

    public function delete(User $user, Stocktake $stocktake): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
