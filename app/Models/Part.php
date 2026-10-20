<?php

namespace App\Models;

use App\Enums\WorkOrderItemType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Part extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
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

    public function category(): BelongsTo
    {
        return $this->belongsTo(PartCategory::class, 'category_id');
    }

    public function stockTransactions(): HasMany
    {
        return $this->hasMany(StockTransaction::class);
    }

    public function workOrderItems(): HasMany
    {
        return $this->hasMany(WorkOrderItem::class, 'item_id')
            ->where('work_order_items.item_type', WorkOrderItemType::Part->value);
    }
}
