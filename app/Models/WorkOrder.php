<?php

namespace App\Models;

use App\Enums\InsuranceClaimStatus;
use App\Enums\InvoicePayer;
use App\Enums\WorkOrderItemType;
use App\Enums\WorkOrderStatus;
use App\Enums\WorkOrderType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class WorkOrder extends Model implements HasMedia
{
    use InteractsWithMedia;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'vehicle_id',
        'order_type',
        'warranty_card_id',
        'insurance_company',
        'insurance_deductible',
        'insurance_claim_no',
        'insurance_adjuster',
        'insurance_expected_pay_at',
        'insurance_claim_status',
        'service_advisor_id',
        'reception_odo',
        'fuel_level',
        'customer_request',
        'technical_diagnosis',
        'checkin_checklist',
        'promised_at',
        'lift_bay',
        'status',
        'needs_restock',
        'approved_revision',
        'quote_approval_token',
        'quote_approved_at',
        'quote_approved_via',
        'quote_rejected_at',
        'quote_reject_reason',
        'stock_exported_at',
        'delivered_at',
        'total_parts_amount',
        'total_labor_amount',
        'discount_amount',
        'vat_rate',
        'final_amount',
        'gross_profit',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_type' => WorkOrderType::class,
            'insurance_deductible' => 'decimal:2',
            'insurance_expected_pay_at' => 'date',
            'insurance_claim_status' => InsuranceClaimStatus::class,
            'reception_odo' => 'integer',
            'checkin_checklist' => 'array',
            'promised_at' => 'datetime',
            'status' => WorkOrderStatus::class,
            'needs_restock' => 'boolean',
            'approved_revision' => 'integer',
            'quote_approved_at' => 'datetime',
            'quote_rejected_at' => 'datetime',
            'stock_exported_at' => 'datetime',
            'delivered_at' => 'datetime',
            'total_parts_amount' => 'decimal:2',
            'total_labor_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'vat_rate' => 'integer',
            'final_amount' => 'decimal:2',
            'gross_profit' => 'decimal:2',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('reception_photos')
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);

        $this->addMediaCollection('reception_signature')
            ->singleFile()
            ->acceptsMimeTypes(['image/png']);

        $this->addMediaCollection('insurance_docs')
            ->acceptsMimeTypes([
                'image/jpeg', 'image/png', 'image/webp', 'application/pdf',
            ]);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function warrantyCard(): BelongsTo
    {
        return $this->belongsTo(WarrantyCard::class);
    }

    public function serviceAdvisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'service_advisor_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(WorkOrderItem::class);
    }

    public function workOrderItems(): HasMany
    {
        return $this->items();
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function latestInvoice(): HasOne
    {
        return $this->hasOne(Invoice::class)->latestOfMany();
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(VehicleRecommendation::class);
    }

    public function maxRevision(): int
    {
        return max(1, (int) $this->items()->max('revision_no'));
    }

    /**
     * Đợt phát sinh để gắn hạng mục mới: dùng lại đợt đang chờ khách chốt, nếu không thì mở đợt kế tiếp.
     */
    public function nextAddendumRevision(): int
    {
        $max = $this->maxRevision();
        $approved = max(1, (int) $this->approved_revision);

        return $max > $approved ? $max : $approved + 1;
    }

    /**
     * Đợt phát sinh còn hạng mục khách đồng ý nhưng chưa chốt giữ kho (null nếu không có).
     */
    public function pendingAddendumRevision(): ?int
    {
        if (! $this->status->stockIsCommitted()) {
            return null;
        }

        $revision = $this->items()
            ->where('is_approved', true)
            ->where('revision_no', '>', max(1, (int) $this->approved_revision))
            ->min('revision_no');

        return $revision === null ? null : (int) $revision;
    }

    public function isInsuranceClaim(): bool
    {
        return $this->order_type === WorkOrderType::Insurance;
    }

    /**
     * Chia tiền lệnh bảo hiểm: khách trả khấu trừ cộng hạng mục ngoài phạm vi bảo hiểm (đã gồm VAT),
     * công ty bảo hiểm trả phần còn lại của tổng thanh toán. Lệnh thường: khách trả toàn bộ.
     *
     * @return array{customer: float, insurance: float, deductible: float, outside_scope: float}
     */
    public function paymentSplit(): array
    {
        $final = round((float) $this->final_amount, 2);

        if (! $this->isInsuranceClaim()) {
            return ['customer' => $final, 'insurance' => 0.0, 'deductible' => 0.0, 'outside_scope' => 0.0];
        }

        $outsideNet = (float) $this->items()
            ->where('is_approved', true)
            ->where('is_outside_insurance', true)
            ->sum('subtotal');
        $outside = round($outsideNet * (1 + ((int) $this->vat_rate / 100)), 2);
        $deductible = max(0.0, round((float) $this->insurance_deductible, 2));
        $customer = min($final, round($deductible + $outside, 2));

        return [
            'customer' => $customer,
            'insurance' => round($final - $customer, 2),
            'deductible' => $deductible,
            'outside_scope' => $outside,
        ];
    }

    public function amountDueFrom(InvoicePayer $payer): float
    {
        return $this->paymentSplit()[$payer->value];
    }

    public function invoiceFor(InvoicePayer $payer): ?Invoice
    {
        return $this->invoices()->where('payer_type', $payer)->latest('id')->first();
    }

    public function countsAsOilService(): bool
    {
        $this->loadMissing('items');
        $catalog = Vehicle::oilChangeCatalogIds();

        foreach ($this->items as $item) {
            $itemId = (int) $item->item_id;

            if ($item->item_type === WorkOrderItemType::Service && in_array($itemId, $catalog['services'], true)) {
                return true;
            }

            if ($item->item_type === WorkOrderItemType::Part && in_array($itemId, $catalog['parts'], true)) {
                return true;
            }
        }

        return false;
    }

    public function leadTechnicianName(): string
    {
        $this->loadMissing('items.technician');

        $technician = $this->items->first(fn (WorkOrderItem $item): bool => $item->technician_id !== null)?->technician;

        return $technician?->name ?? 'Chưa phân công';
    }

    /**
     * @return list<string>
     */
    public function jobLines(): array
    {
        $this->loadMissing('items.technician');

        $serviceIds = $this->items
            ->filter(fn (WorkOrderItem $item): bool => $item->item_type === WorkOrderItemType::Service)
            ->pluck('item_id');
        $partIds = $this->items
            ->filter(fn (WorkOrderItem $item): bool => $item->item_type === WorkOrderItemType::Part)
            ->pluck('item_id');
        $services = Service::query()->whereIn('id', $serviceIds)->get()->keyBy('id');
        $parts = Part::query()->whereIn('id', $partIds)->get()->keyBy('id');
        $lines = [];

        foreach ($this->items->sortBy([['revision_no', 'asc'], ['id', 'asc']]) as $item) {
            $technician = $item->technician?->name ?? 'Chưa phân công';
            $prefix = $item->isAddendum() ? '['.$item->revisionLabel().'] ' : '';
            $suffix = $item->is_approved === false ? ' (khách từ chối)' : '';

            if ($item->item_type === WorkOrderItemType::Service) {
                $name = $services->get($item->item_id)?->name ?? 'Dịch vụ #'.$item->item_id;
                $lines[] = "{$prefix}Công: {$name} — {$technician}{$suffix}";
            }

            if ($item->item_type === WorkOrderItemType::Part) {
                $name = $parts->get($item->item_id)?->name ?? 'Phụ tùng #'.$item->item_id;
                $quantity = $this->quantityLabel($item->quantity);
                $lines[] = "{$prefix}Phụ tùng: {$name} x{$quantity} — {$technician}{$suffix}";
            }
        }

        return $lines === [] ? ['Chưa có hạng mục'] : $lines;
    }

    private function quantityLabel(int|float|string|null $quantity): string
    {
        $value = (float) $quantity;

        if (abs($value - round($value)) < 0.001) {
            return number_format($value, 0, ',', '.');
        }

        return rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',');
    }
}
