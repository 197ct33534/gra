<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use App\Policies\Concerns\ChecksGarageRoles;

class InvoicePolicy
{
    use ChecksGarageRoles;

    public function viewAny(User $user): bool
    {
        return $this->isStaffOffice($user);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $this->isStaffOffice($user);
    }

    public function create(User $user): bool
    {
        return $this->isStaffOffice($user);
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $this->isStaffOffice($user);
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return $user->isAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }
}
