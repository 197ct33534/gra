<?php

namespace App\Policies;

use App\Models\StockTransaction;
use App\Models\User;
use App\Policies\Concerns\ChecksGarageRoles;

class StockTransactionPolicy
{
    use ChecksGarageRoles;

    public function viewAny(User $user): bool
    {
        return $this->isStaffOffice($user);
    }

    public function view(User $user, StockTransaction $stockTransaction): bool
    {
        return $this->isStaffOffice($user);
    }

    /**
     * Thẻ kho chỉ sinh ra từ nghiệp vụ, không ai được tạo hoặc sửa tay.
     */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, StockTransaction $stockTransaction): bool
    {
        return false;
    }

    public function delete(User $user, StockTransaction $stockTransaction): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
