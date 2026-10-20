<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, User $model): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, User $model): bool
    {
        return $user->isAdmin();
    }

    /**
     * Không tự xóa tài khoản đang dùng; nhân viên đã có lịch sử (lệnh, thẻ kho) giữ lại để tra cứu.
     */
    public function delete(User $user, User $model): bool
    {
        return $user->isAdmin()
            && ! $user->is($model)
            && ! $model->advisedWorkOrders()->exists()
            && ! $model->stockTransactions()->exists();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
