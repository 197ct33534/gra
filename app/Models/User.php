<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'commission_rate',
        'branch_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'commission_rate' => 'decimal:2',
            'onboarding_tour_seen_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function scopeTechnicians(Builder $query): Builder
    {
        return $query->where('role', UserRole::Technician);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin';
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isServiceAdvisor(): bool
    {
        return $this->role === UserRole::ServiceAdvisor;
    }

    public function isTechnician(): bool
    {
        return $this->role === UserRole::Technician;
    }

    /**
     * Giá vốn, lãi gộp, điều chỉnh tồn kho: chỉ chủ gara.
     */
    public function canSeeCosts(): bool
    {
        return $this->isAdmin();
    }

    /**
     * Đơn giá, tổng tiền, doanh thu: không hiển thị cho thợ.
     */
    public function canSeeMoney(): bool
    {
        return ! $this->isTechnician();
    }

    /**
     * Người đang đăng nhập; null khi chạy console/queue.
     */
    public static function current(): ?self
    {
        $user = auth()->user();

        return $user instanceof self ? $user : null;
    }

    /**
     * Quyền theo người đang đăng nhập; console/seeder (không đăng nhập) được coi như chủ gara.
     */
    public static function currentCanSeeCosts(): bool
    {
        return self::current()?->canSeeCosts() ?? true;
    }

    public static function currentCanSeeMoney(): bool
    {
        return self::current()?->canSeeMoney() ?? true;
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function advisedWorkOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'service_advisor_id');
    }

    public function technicianWorkOrderItems(): HasMany
    {
        return $this->hasMany(WorkOrderItem::class, 'technician_id');
    }

    public function stockTransactions(): HasMany
    {
        return $this->hasMany(StockTransaction::class, 'created_by');
    }
}
