<?php

namespace App\Policies;

use App\Enums\WorkOrderStatus;
use App\Models\User;
use App\Models\WorkOrder;
use App\Policies\Concerns\ChecksGarageRoles;

class WorkOrderPolicy
{
    use ChecksGarageRoles;

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, WorkOrder $workOrder): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $this->isStaffOffice($user);
    }

    public function update(User $user, WorkOrder $workOrder): bool
    {
        return $this->isStaffOffice($user);
    }

    /**
     * Cố vấn chỉ xóa lệnh còn ở bước báo giá và chưa có hóa đơn; lệnh đã duyệt chỉ chủ gara xóa.
     */
    public function delete(User $user, WorkOrder $workOrder): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->isServiceAdvisor()
            && in_array($workOrder->status, [WorkOrderStatus::Reception, WorkOrderStatus::Diagnosing, WorkOrderStatus::Quoted], true)
            && ! $workOrder->invoices()->exists();
    }

    public function deleteAny(User $user): bool
    {
        return $user->isAdmin();
    }
}
