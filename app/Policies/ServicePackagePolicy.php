<?php

namespace App\Policies;

use App\Models\ServicePackage;
use App\Models\User;
use App\Policies\Concerns\ChecksGarageRoles;

class ServicePackagePolicy
{
    use ChecksGarageRoles;

    public function viewAny(User $user): bool
    {
        return $this->isStaffOffice($user);
    }

    public function view(User $user, ServicePackage $servicePackage): bool
    {
        return $this->isStaffOffice($user);
    }

    /**
     * Gói giá là chính sách của chủ gara, giống bảng giá công.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, ServicePackage $servicePackage): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, ServicePackage $servicePackage): bool
    {
        return $user->isAdmin();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }
}
