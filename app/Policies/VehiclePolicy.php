<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Vehicle;
use App\Policies\Concerns\ChecksGarageRoles;

class VehiclePolicy
{
    use ChecksGarageRoles;

    /**
     * Thợ được tra hồ sơ xe (lịch sử sửa chữa) nhưng không sửa.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Vehicle $vehicle): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->isStaffOffice($user);
    }

    public function update(User $user, Vehicle $vehicle): bool
    {
        return $this->isStaffOffice($user);
    }

    public function delete(User $user, Vehicle $vehicle): bool
    {
        return $user->isAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }
}
