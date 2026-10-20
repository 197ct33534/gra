<?php

namespace App\Policies;

use App\Models\GoodsReceipt;
use App\Models\User;
use App\Policies\Concerns\ChecksGarageRoles;

class GoodsReceiptPolicy
{
    use ChecksGarageRoles;

    public function viewAny(User $user): bool
    {
        return $this->isStaffOffice($user);
    }

    public function view(User $user, GoodsReceipt $goodsReceipt): bool
    {
        return $this->isStaffOffice($user);
    }

    public function create(User $user): bool
    {
        return $this->isStaffOffice($user);
    }

    public function update(User $user, GoodsReceipt $goodsReceipt): bool
    {
        return false;
    }

    public function delete(User $user, GoodsReceipt $goodsReceipt): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
