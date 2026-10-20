<?php

namespace App\Policies;

use App\Models\Branch;
use App\Models\User;

class BranchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Branch $branch): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Branch $branch): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Branch $branch): bool
    {
        return $user->isAdmin() && ! $branch->is_default;
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }
}
