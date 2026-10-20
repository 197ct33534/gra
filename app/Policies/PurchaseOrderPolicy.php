<?php

namespace App\Policies;

use App\Models\PurchaseOrder;
use App\Models\User;
use App\Policies\Concerns\ChecksGarageRoles;

class PurchaseOrderPolicy
{
    use ChecksGarageRoles;

    public function viewAny(User $user): bool
    {
        return $this->isStaffOffice($user);
    }

    public function view(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->isStaffOffice($user);
    }

    public function create(User $user): bool
    {
        return $this->isStaffOffice($user);
    }

    public function update(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $this->isStaffOffice($user);
    }

    public function delete(User $user, PurchaseOrder $purchaseOrder): bool
    {
        return $user->isAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }
}
