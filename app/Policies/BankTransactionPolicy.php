<?php

namespace App\Policies;

use App\Models\BankTransaction;
use App\Models\User;
use App\Policies\Concerns\ChecksGarageRoles;

class BankTransactionPolicy
{
    use ChecksGarageRoles;

    public function viewAny(User $user): bool
    {
        return $this->isStaffOffice($user);
    }

    public function view(User $user, BankTransaction $bankTransaction): bool
    {
        return $this->isStaffOffice($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, BankTransaction $bankTransaction): bool
    {
        return false;
    }

    public function delete(User $user, BankTransaction $bankTransaction): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
