<?php

namespace App\Models\Concerns;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToBranch
{
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Lọc theo chi nhánh nhân viên (khi bật enforce). Admin không bị lọc.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCurrentBranch(Builder $query): Builder
    {
        if (! (bool) config('garage.branches.enforce_scope', false)) {
            return $query;
        }

        $user = User::current();
        if ($user === null || $user->isAdmin() || $user->branch_id === null) {
            return $query;
        }

        return $query->where($query->getModel()->getTable().'.branch_id', $user->branch_id);
    }

    public static function defaultBranchIdForCurrentUser(): ?int
    {
        $user = User::current();

        return $user?->branch_id ?? Branch::defaultId();
    }
}
