<?php

namespace App\Policies;

use App\Models\CreditNote;
use App\Models\User;

class CreditNotePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isServiceAdvisor();
    }

    public function view(User $user, CreditNote $creditNote): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, CreditNote $creditNote): bool
    {
        return false;
    }

    public function delete(User $user, CreditNote $creditNote): bool
    {
        return false;
    }
}
