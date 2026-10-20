<?php

namespace App\Policies;

use App\Models\SupplierBill;
use App\Models\User;
use App\Policies\Concerns\ChecksGarageRoles;

class SupplierBillPolicy
{
    use ChecksGarageRoles;

    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isServiceAdvisor();
    }

    public function view(User $user, SupplierBill $supplierBill): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, SupplierBill $supplierBill): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, SupplierBill $supplierBill): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
