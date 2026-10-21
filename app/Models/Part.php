<?php

namespace App\Models;

use App\Enums\WorkOrderItemType;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Part extends Model
{
    use BelongsToBranch;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'branch_id',
        'category_id',
        'sku',
        'oem_code',
        'name',
        'unit',
        'cost_price',
        'selling_price',
        'stock_quantity',
        'reserved_quantity',
        'min_stock_alert',
        'shelf_location',
        'compatibility',
        'warranty_months',
        'warranty_km',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cost_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'stock_quantity' => 'integer',
            'reserved_quantity' => 'integer',
            'min_stock_alert' => 'integer',
            'compatibility' => 'array',
            'warranty_months' => 'integer',
            'warranty_km' => 'integer',
        ];
    }

    /**
     * Tồn có thể hứa cho lệnh mới: tồn thực trừ phần đã giữ cho các lệnh đã duyệt.
     */
    public function availableQuantity(): int
    {
        return (int) $this->stock_quantity - (int) $this->reserved_quantity;
    }

    /**
     * Cần nhập thêm: tồn khả dụng chạm ngưỡng. Ngưỡng 0 = không theo dõi tồn (hàng mua theo xe),
     * chỉ báo khi tồn bị âm (đã xuất mà chưa nhập bù).
     */
    public function isLowStock(): bool
    {
        $available = $this->availableQuantity();
        $min = (int) $this->min_stock_alert;

        return $available <= $min && ($min > 0 || $available < 0);
    }

    /**
     * @param  Builder<Part>  $query
     * @return Builder<Part>
     */
    public function scopeLowStock(Builder $query): Builder
    {
        return $query
            ->whereRaw('stock_quantity - reserved_quantity <= min_stock_alert')
            ->where(fn (Builder $query): Builder => $query
                ->where('min_stock_alert', '>', 0)
                ->orWhereRaw('stock_quantity - reserved_quantity < 0'));
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PartCategory::class, 'category_id');
    }

    public function stockTransactions(): HasMany
    {
        return $this->hasMany(StockTransaction::class);
    }

    public function goodsReceiptItems(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }

    public function workOrderItems(): HasMany
    {
        return $this->hasMany(WorkOrderItem::class, 'item_id')
            ->where('work_order_items.item_type', WorkOrderItemType::Part->value);
    }
}
