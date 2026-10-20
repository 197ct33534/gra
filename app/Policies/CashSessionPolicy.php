<?php

namespace App\Policies;

use App\Models\CashSession;
use App\Models\User;

class CashSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isServiceAdvisor();
    }

    public function view(User $user, CashSession $cashSession): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, CashSession $cashSession): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, CashSession $cashSession): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
