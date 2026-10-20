<?php

namespace App\Policies;

use App\Models\Part;
use App\Models\User;
use App\Policies\Concerns\ChecksGarageRoles;

class PartPolicy
{
    use ChecksGarageRoles;

    public function viewAny(User $user): bool
    {
        return $this->isStaffOffice($user);
    }

    public function view(User $user, Part $part): bool
    {
        return $this->isStaffOffice($user);
    }

    /**
     * Cố vấn được tạo mã mới (hàng mua ngoài lần đầu); giá vốn và tồn kho chỉ chủ gara chỉnh.
     */
    public function create(User $user): bool
    {
        return $this->isStaffOffice($user);
    }

    public function update(User $user, Part $part): bool
    {
        return $this->isStaffOffice($user);
    }

    public function delete(User $user, Part $part): bool
    {
        return $user->isAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }
}
