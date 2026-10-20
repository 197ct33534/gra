<?php

namespace App\Models;

use App\Enums\WorkOrderItemSource;
use App\Enums\WorkOrderItemStatus;
use App\Enums\WorkOrderItemType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderItem extends Model
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'item_source' => 'stock',
        'cost_price' => 0,
        'revision_no' => 1,
        'is_approved' => true,
        'is_outside_insurance' => false,
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'work_order_id',
        'item_type',
        'item_id',
        'item_source',
        'quantity',
        'unit_price',
        'cost_price',
        'supplier_info',
        'warranty_policy',
        'revision_no',
        'finding_note',
        'is_approved',
        'decline_reason',
        'declined_at',
        'is_outside_insurance',
        'discount_amount',
        'subtotal',
        'technician_id',
        'technician_payout',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'item_type' => WorkOrderItemType::class,
            'item_id' => 'integer',
            'item_source' => WorkOrderItemSource::class,
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'revision_no' => 'integer',
            'is_approved' => 'boolean',
            'declined_at' => 'datetime',
            'is_outside_insurance' => 'boolean',
            'discount_amount' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'technician_payout' => 'decimal:2',
            'status' => WorkOrderItemStatus::class,
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class, 'item_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'item_id');
    }

    public static function calculateSubtotal(float|int|string|null $quantity, float|int|string|null $unitPrice, float|int|string|null $discount): string
    {
        $subtotal = round(((float) $quantity * (float) $unitPrice) - (float) $discount, 2);

        return number_format($subtotal, 2, '.', '');
    }

    /**
     * Hạng mục khách từ chối giữ nguyên đơn giá để in khuyến cáo, nhưng thành tiền bằng 0.
     */
    public function syncSubtotal(): void
    {
        $this->subtotal = $this->is_approved === false
            ? '0.00'
            : self::calculateSubtotal($this->quantity, $this->unit_price, $this->discount_amount);
    }

    /**
     * Giá trị hạng mục nếu khách đồng ý làm (dùng cho khối khuyến cáo bị từ chối).
     */
    public function quotedAmount(): float
    {
        return (float) self::calculateSubtotal($this->quantity, $this->unit_price, $this->discount_amount);
    }

    public function catalogName(): string
    {
        return match ($this->item_type) {
            WorkOrderItemType::Part => Part::query()->whereKey($this->item_id)->value('name') ?? 'Phụ tùng #'.$this->item_id,
            default => Service::query()->whereKey($this->item_id)->value('name') ?? 'Dịch vụ #'.$this->item_id,
        };
    }

    /**
     * Giá bảng giá hiện hành (giá bán phụ tùng hoặc tiền công chuẩn).
     */
    public function catalogPrice(): float
    {
        return (float) match ($this->item_type) {
            WorkOrderItemType::Part => Part::query()->whereKey($this->item_id)->value('selling_price'),
            default => Service::query()->whereKey($this->item_id)->value('labor_cost'),
        };
    }

    public function isAddendum(): bool
    {
        return (int) $this->revision_no > 1;
    }

    public function revisionLabel(): string
    {
        return $this->isAddendum() ? 'Phát sinh đợt '.((int) $this->revision_no - 1) : 'Báo giá ban đầu';
    }

    public function source(): WorkOrderItemSource
    {
        return $this->item_source ?? WorkOrderItemSource::Stock;
    }

    public function isStockPart(): bool
    {
        return $this->item_type === WorkOrderItemType::Part && $this->source() === WorkOrderItemSource::Stock;
    }

    public function isDirectPurchasePart(): bool
    {
        return $this->item_type === WorkOrderItemType::Part && $this->source() === WorkOrderItemSource::DirectPurchase;
    }

    /**
     * Đồ khách mang tới: gara chỉ tính công, không tính tiền và không bảo hành phụ tùng.
     */
    public function applyCustomerSuppliedRules(): void
    {
        if ($this->source() !== WorkOrderItemSource::CustomerSupplied) {
            return;
        }

        $this->unit_price = 0;
        $this->cost_price = 0;
        $this->discount_amount = 0;

        if (blank($this->warranty_policy)) {
            $this->warranty_policy = WorkOrderItemSource::CUSTOMER_SUPPLIED_WARRANTY;
        }
    }

    public function totalCost(): float
    {
        return round((float) $this->quantity * (float) $this->cost_price, 2);
    }

    protected static function booted(): void
    {
        static::creating(function (WorkOrderItem $item): void {
            $item->status ??= WorkOrderItemStatus::Pending;
        });

        static::saving(function (WorkOrderItem $item): void {
            $item->applyCustomerSuppliedRules();
            $item->syncSubtotal();

            if ($item->is_approved === false) {
                $item->declined_at ??= now();
            } else {
                $item->declined_at = null;
                $item->decline_reason = null;
            }
        });
    }
}
