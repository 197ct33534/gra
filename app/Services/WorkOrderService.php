<?php

namespace App\Services;

use App\Enums\InvoicePayer;
use App\Enums\PaymentMethod;
use App\Enums\StockTransactionType;
use App\Enums\WorkOrderItemType;
use App\Enums\WorkOrderStatus;
use App\Models\Invoice;
use App\Models\Part;
use App\Models\Service;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleRecommendation;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Support\VietnameseMoney;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class WorkOrderService
{
    public function nextInvoiceNumber(): string
    {
        return DB::transaction(fn (): string => $this->reserveInvoiceNumber());
    }

    /**
     * Lập hóa đơn cho một bên trả tiền.
     *
     * Lệnh bảo hiểm được lập tối đa hai hóa đơn: phần khách trả (khấu trừ + hạng mục ngoài phạm vi)
     * và phần công ty bảo hiểm trả. Lệnh thường chỉ có hóa đơn khách hàng cho toàn bộ số tiền.
     */
    public function issueInvoice(
        WorkOrder $workOrder,
        PaymentMethod $method,
        int|float|string $paidAmount,
        ?string $notes = null,
        InvoicePayer $payer = InvoicePayer::Customer,
    ): Invoice {
        $invoice = DB::transaction(function () use ($workOrder, $method, $paidAmount, $notes, $payer): Invoice {
            $locked = WorkOrder::query()->whereKey($workOrder->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [
                WorkOrderStatus::CustomerApproved,
                WorkOrderStatus::InProgress,
                WorkOrderStatus::Completed,
            ], true)) {
                throw new DomainException('Chỉ lập hóa đơn khi lệnh đã được khách duyệt, đang sửa hoặc đã hoàn thành.');
            }

            $this->assertNoPendingAddendum($locked);

            $paid = round((float) $paidAmount, 2);

            if ($paid < 0) {
                throw new DomainException('Số tiền thanh toán không hợp lệ.');
            }

            $payerName = null;

            if ($locked->isInsuranceClaim()) {
                $alreadyIssued = Invoice::query()
                    ->where('work_order_id', $locked->id)
                    ->where('payer_type', $payer)
                    ->lockForUpdate()
                    ->exists();

                if ($alreadyIssued) {
                    throw new DomainException('Lệnh bảo hiểm đã có hóa đơn cho '.mb_strtolower($payer->getLabel()).'.');
                }
            }

            if ($payer === InvoicePayer::Insurance) {
                if (! $locked->isInsuranceClaim()) {
                    throw new DomainException('Chỉ lệnh bảo hiểm mới lập hóa đơn cho công ty bảo hiểm.');
                }

                if (blank($locked->insurance_company)) {
                    throw new DomainException('Lệnh bảo hiểm chưa ghi tên công ty bảo hiểm.');
                }

                $method = PaymentMethod::Insurance;
                $payerName = trim((string) $locked->insurance_company);
            }

            $total = $locked->amountDueFrom($payer);

            if ($locked->isInsuranceClaim() && $total <= 0) {
                throw new DomainException('Không có số tiền '.mb_strtolower($payer->getLabel()).' phải trả trên lệnh này.');
            }

            if ($paid > $total + 0.001) {
                throw new DomainException('Số tiền đã thu vượt quá số tiền phải trả ('.VietnameseMoney::format($total).').');
            }

            $vatRate = (int) $locked->vat_rate;
            $amountBeforeVat = $vatRate > 0 ? round($total / (1 + $vatRate / 100), 2) : $total;
            $vatAmount = round($total - $amountBeforeVat, 2);

            if ($method === PaymentMethod::Cash && $paid > 0) {
                $this->assertCashSessionReady($paid);
            }

            if ($payer === InvoicePayer::Customer && $paid + 0.001 < $total) {
                $locked->loadMissing('vehicle.customer');
                $customer = $locked->vehicle?->customer;
                if ($customer instanceof \App\Models\Customer) {
                    app(FleetService::class)->assertWithinCreditLimit($customer, round($total - $paid, 2));
                }
            }

            $invoice = Invoice::query()->create([
                'invoice_number' => $this->reserveInvoiceNumber(),
                'work_order_id' => $locked->id,
                'payer_type' => $payer,
                'payer_name' => $payerName,
                'total_amount' => $total,
                'paid_amount' => $paid,
                'payment_method' => $method,
                'payment_status' => Invoice::statusFor($total, $paid),
                'notes' => filled($notes) ? $notes : null,
                'vat_rate' => $vatRate,
                'amount_before_vat' => $amountBeforeVat,
                'vat_amount' => $vatAmount,
            ]);

            if ($method === PaymentMethod::Cash && $paid > 0) {
                $this->postCashPayment($invoice, $paid);
            }

            $locked->status = WorkOrderStatus::Completed;
            $locked->save();

            return $invoice;
        });

        $workOrder->refresh();

        return $invoice;
    }

    /**
     * Ghi nhận thêm một lần thu tiền trên hóa đơn còn nợ (VD: công ty bảo hiểm chuyển khoản sau giám định).
     */
    public function recordPayment(Invoice $invoice, int|float|string $amount, ?string $note = null): Invoice
    {
        $updated = DB::transaction(function () use ($invoice, $amount, $note): Invoice {
            $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();
            $received = round((float) $amount, 2);
            $outstanding = $locked->outstandingAmount();

            if ($received <= 0) {
                throw new DomainException('Số tiền thu phải lớn hơn 0.');
            }

            if ($outstanding <= 0) {
                throw new DomainException('Hóa đơn đã thanh toán đủ.');
            }

            if ($received > $outstanding + 0.001) {
                throw new DomainException('Số tiền thu vượt quá công nợ còn lại ('.VietnameseMoney::format($outstanding).').');
            }

            if ($locked->payment_method === PaymentMethod::Cash) {
                $this->assertCashSessionReady($received);
            }

            $paid = round((float) $locked->paid_amount + $received, 2);
            $locked->paid_amount = $paid;
            $locked->payment_status = Invoice::statusFor((float) $locked->total_amount, $paid);

            if (filled($note)) {
                $line = now()->timezone((string) config('garage.timezone'))->format('d/m/Y').' — thu '.VietnameseMoney::format($received).': '.trim((string) $note);
                $locked->notes = filled($locked->notes) ? $locked->notes."\n".$line : $line;
            }

            $locked->save();

            if ($locked->payment_method === PaymentMethod::Cash) {
                $this->postCashPayment($locked, $received);
            }

            return $locked;
        });

        $invoice->refresh();

        return $updated;
    }

    private function assertCashSessionReady(float $amount): void
    {
        if (! config('garage.cash.require_open_session_for_cash', true)) {
            return;
        }

        // Chỉ kiểm tra ca mở; ghi quỹ sau khi invoice được lưu.
        app(CashService::class)->requireOpenSession();
        unset($amount);
    }

    private function postCashPayment(Invoice $invoice, float $amount): void
    {
        if (! config('garage.cash.require_open_session_for_cash', true)) {
            return;
        }

        $userId = User::current()?->id;

        if ($userId === null) {
            // Seeder / console: bỏ qua ghi quỹ nếu không có user đăng nhập.
            return;
        }

        app(CashService::class)->recordInvoiceCashPayment($invoice, $amount, $userId);
    }

    public function nextCode(): string
    {
        return DB::transaction(function (): string {
            $prefix = 'RO-'.now()->format('Ymd').'-';

            $lastCode = WorkOrder::query()
                ->where('code', 'like', $prefix.'%')
                ->lockForUpdate()
                ->orderByDesc('code')
                ->value('code');

            $sequence = 1;

            if (is_string($lastCode) && preg_match('/^'.preg_quote($prefix, '/').'(\d{4})$/', $lastCode, $matches) === 1) {
                $sequence = ((int) $matches[1]) + 1;
            }

            if ($sequence > 9999) {
                throw new DomainException('Đã hết mã lệnh sửa chữa trong ngày.');
            }

            return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
        });
    }

    public function recalculateTotals(WorkOrder $workOrder): void
    {
        $workOrder->load('items');

        $partsAmount = 0.0;
        $laborAmount = 0.0;

        foreach ($workOrder->items as $item) {
            $item->syncSubtotal();
            $item->save();

            if (! $item->is_approved) {
                continue;
            }

            $subtotal = (float) $item->subtotal;

            if ($item->item_type === WorkOrderItemType::Part) {
                $partsAmount += $subtotal;
            } elseif ($item->item_type === WorkOrderItemType::Service) {
                $laborAmount += $subtotal;
            }
        }

        $partsAmount = round($partsAmount, 2);
        $laborAmount = round($laborAmount, 2);
        $discount = round((float) $workOrder->discount_amount, 2);
        $vatRate = (int) $workOrder->vat_rate;

        if ($vatRate < 0) {
            throw new DomainException('Thuế VAT không được âm.');
        }

        $net = round($partsAmount + $laborAmount - $discount, 2);
        $final = round($net * (1 + ($vatRate / 100)), 2);

        $workOrder->total_parts_amount = $partsAmount;
        $workOrder->total_labor_amount = $laborAmount;
        $workOrder->final_amount = $final;
        $workOrder->save();
    }

    /**
     * Duyệt báo giá: chỉ giữ chỗ phụ tùng kho, không trừ tồn thực.
     *
     * Thiếu hàng không chặn duyệt: lệnh được gắn cờ cần nhập thêm và trả về danh sách cảnh báo.
     *
     * @return list<string>
     */
    public function approveWorkOrder(WorkOrder $workOrder, int $userId): array
    {
        $warnings = DB::transaction(function () use ($workOrder, $userId): array {
            $this->assertActor($userId);

            $locked = WorkOrder::query()
                ->whereKey($workOrder->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($locked->status, [WorkOrderStatus::Reception, WorkOrderStatus::Diagnosing, WorkOrderStatus::Quoted], true)) {
                throw new DomainException('Chỉ duyệt lệnh đang tiếp nhận, chẩn đoán hoặc đã báo giá.');
            }

            $warnings = $this->commitItems($locked, $this->approvedItems($locked), $userId);

            $this->recalculateTotals($locked);

            $locked->status = WorkOrderStatus::CustomerApproved;
            $locked->needs_restock = $warnings !== [];
            $locked->approved_revision = $locked->maxRevision();
            $locked->save();

            return $warnings;
        });

        $workOrder->refresh();

        return $warnings;
    }

    /**
     * Chốt đợt phát sinh: khách đã đồng ý các hạng mục phát hiện thêm khi tháo xe.
     *
     * Chỉ giữ kho cho hạng mục phát sinh khách đồng ý; hạng mục bị từ chối được giữ lại làm khuyến cáo.
     *
     * @return list<string>
     */
    public function approveAddendum(WorkOrder $workOrder, int $userId): array
    {
        $warnings = DB::transaction(function () use ($workOrder, $userId): array {
            $this->assertActor($userId);

            $locked = WorkOrder::query()
                ->whereKey($workOrder->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->stockIsCommitted() || $locked->status === WorkOrderStatus::Delivered) {
                throw new DomainException('Chỉ chốt phát sinh cho lệnh đã duyệt và chưa bàn giao.');
            }

            if ($locked->stock_exported_at !== null) {
                throw new DomainException('Lệnh này đã xuất kho theo quy trình cũ, hãy lập lệnh mới cho phần phát sinh.');
            }

            $maxRevision = $locked->maxRevision();

            if ($maxRevision <= max(1, (int) $locked->approved_revision)) {
                throw new DomainException('Không có hạng mục phát sinh nào chờ khách duyệt.');
            }

            $pending = $this->approvedItems($locked)
                ->filter(fn (WorkOrderItem $item): bool => $item->revision_no > max(1, (int) $locked->approved_revision))
                ->values();
            $warnings = $this->commitItems($locked, $pending, $userId);

            $this->recalculateTotals($locked);

            $locked->approved_revision = $maxRevision;
            $locked->needs_restock = $locked->needs_restock || $warnings !== [];
            $locked->save();

            return $warnings;
        });

        $workOrder->refresh();

        return $warnings;
    }

    /**
     * Giữ chỗ hàng kho, ghi nhập - xuất thẳng hàng mua ngoài, chốt giá vốn cho các hạng mục vừa được khách đồng ý.
     *
     * @param  Collection<int, WorkOrderItem>  $items
     * @return list<string>
     */
    private function commitItems(WorkOrder $locked, Collection $items, int $userId): array
    {
        $warnings = [];

        foreach ($items as $item) {
            $item->applyCustomerSuppliedRules();

            if ($item->isStockPart()) {
                $part = Part::query()->whereKey($item->item_id)->lockForUpdate()->firstOrFail();
                $needed = $this->wholeQuantity($item, $part->name);
                $available = $part->availableQuantity();

                if ($available < $needed) {
                    $missing = $needed - max(0, $available);
                    $warnings[] = "Thiếu {$part->name}: khả dụng {$available}, cần {$needed} — cần nhập thêm {$missing} {$part->unit}.";
                }

                if ((float) $item->cost_price <= 0) {
                    $item->cost_price = $part->cost_price;
                }

                $part->reserved_quantity += $needed;
                $part->save();
            }

            if ($item->isDirectPurchasePart()) {
                $part = Part::query()->whereKey($item->item_id)->lockForUpdate()->firstOrFail();
                $quantity = $this->wholeQuantity($item, $part->name);
                $note = filled($item->supplier_info) ? 'Mua ngoài: '.$item->supplier_info : 'Mua ngoài';

                StockTransaction::query()->create([
                    'part_id' => $part->id,
                    'transaction_type' => StockTransactionType::Import,
                    'quantity' => $quantity,
                    'unit_price' => $item->cost_price,
                    'reference_id' => $locked->id,
                    'note' => $note,
                    'created_by' => $userId,
                ]);
                StockTransaction::query()->create([
                    'part_id' => $part->id,
                    'transaction_type' => StockTransactionType::ExportWorkOrder,
                    'quantity' => -$quantity,
                    'unit_price' => $item->unit_price,
                    'reference_id' => $locked->id,
                    'note' => $note,
                    'created_by' => $userId,
                ]);
            }

            $item->save();
        }

        return $warnings;
    }

    /**
     * Hủy duyệt: nhả phần giữ chỗ; hàng mua ngoài đã về gara thì nhập lại kho.
     */
    public function cancelApproval(WorkOrder $workOrder, int $userId): void
    {
        DB::transaction(function () use ($workOrder, $userId): void {
            $this->assertActor($userId);

            $locked = WorkOrder::query()
                ->whereKey($workOrder->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->stockIsCommitted() || $locked->status === WorkOrderStatus::Delivered) {
                throw new DomainException('Chỉ hủy duyệt lệnh đã duyệt và chưa bàn giao.');
            }

            foreach ($this->committedItems($locked) as $item) {
                if (! $item->isStockPart() && ! $item->isDirectPurchasePart()) {
                    continue;
                }

                $part = Part::query()->whereKey($item->item_id)->lockForUpdate()->firstOrFail();
                $quantity = $this->wholeQuantity($item, $part->name);

                if ($item->isStockPart() && $locked->stock_exported_at === null) {
                    $part->reserved_quantity = max(0, $part->reserved_quantity - $quantity);
                    $part->save();

                    continue;
                }

                $part->stock_quantity += $quantity;
                $part->save();

                StockTransaction::query()->create([
                    'part_id' => $part->id,
                    'transaction_type' => StockTransactionType::Return,
                    'quantity' => $quantity,
                    'unit_price' => $item->unit_price,
                    'reference_id' => $locked->id,
                    'note' => $item->isDirectPurchasePart() ? 'Hủy duyệt: hàng mua ngoài nhập lại kho' : null,
                    'created_by' => $userId,
                ]);
            }

            $locked->status = WorkOrderStatus::Reception;
            $locked->needs_restock = false;
            $locked->approved_revision = 0;
            $locked->stock_exported_at = null;
            $locked->save();
        });

        $workOrder->refresh();
    }

    /**
     * Bàn giao xe: xuất kho chính thức phần đã giữ chỗ và chốt lãi gộp của lệnh.
     *
     * Tồn không đủ vẫn cho bàn giao (hàng đã lắp lên xe), kho bị âm và trả về cảnh báo để bù phiếu nhập.
     *
     * @return list<string>
     */
    public function completeDelivery(WorkOrder $workOrder, int $userId): array
    {
        $warnings = DB::transaction(function () use ($workOrder, $userId): array {
            $this->assertActor($userId);

            $locked = WorkOrder::query()
                ->whereKey($workOrder->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === WorkOrderStatus::Delivered) {
                throw new DomainException('Lệnh đã được bàn giao.');
            }

            if (! $locked->status->stockIsCommitted()) {
                throw new DomainException('Chỉ bàn giao lệnh đã được khách duyệt.');
            }

            $this->assertNoPendingAddendum($locked);

            $warnings = [];
            $totalCost = 0.0;

            foreach ($this->committedItems($locked) as $item) {
                if ($item->isStockPart() && $locked->stock_exported_at === null) {
                    $part = Part::query()->whereKey($item->item_id)->lockForUpdate()->firstOrFail();
                    $quantity = $this->wholeQuantity($item, $part->name);

                    if ($part->stock_quantity < $quantity) {
                        $after = $part->stock_quantity - $quantity;
                        $warnings[] = "Tồn {$part->name} còn {$after} sau khi xuất — cần lập phiếu nhập bù.";
                    }

                    if ((float) $item->cost_price <= 0) {
                        $item->cost_price = $part->cost_price;
                        $item->save();
                    }

                    $part->stock_quantity -= $quantity;
                    $part->reserved_quantity = max(0, $part->reserved_quantity - $quantity);
                    $part->save();

                    StockTransaction::query()->create([
                        'part_id' => $part->id,
                        'transaction_type' => StockTransactionType::ExportWorkOrder,
                        'quantity' => -$quantity,
                        'unit_price' => $item->unit_price,
                        'reference_id' => $locked->id,
                        'created_by' => $userId,
                    ]);
                }

                $totalCost += $item->totalCost();
            }

            $vehicle = Vehicle::query()
                ->whereKey($locked->vehicle_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->reception_odo > $vehicle->current_odo) {
                $vehicle->current_odo = $locked->reception_odo;
                $vehicle->save();
            }

            $this->syncRecommendations($locked);
            app(PayrollService::class)->snapshot($locked);

            $locked->gross_profit = round($this->revenueBeforeVat($locked) - $totalCost, 2);
            $locked->stock_exported_at ??= now();
            $locked->needs_restock = false;
            $locked->status = WorkOrderStatus::Delivered;
            $locked->delivered_at = now();
            $locked->save();

            $delivered = $locked->fresh(['items', 'vehicle.customer']) ?? $locked;
            app(WarrantyService::class)->issueCardsForDelivery($delivered);
            app(CustomerPortalService::class)->ensureFeedbackToken($delivered);
            app(CustomerPortalService::class)->ensureStatusToken($delivered);
            app(LoyaltyService::class)->earnFromDelivery($delivered, $userId);

            return $warnings;
        });

        $workOrder->refresh();

        return $warnings;
    }

    /**
     * Doanh thu của gara không gồm VAT (VAT thu hộ nhà nước).
     */
    private function revenueBeforeVat(WorkOrder $workOrder): float
    {
        return round(
            (float) $workOrder->total_parts_amount + (float) $workOrder->total_labor_amount - (float) $workOrder->discount_amount,
            2,
        );
    }

    /**
     * @return Collection<int, WorkOrderItem>
     */
    private function approvedItems(WorkOrder $workOrder): Collection
    {
        return $workOrder->items()
            ->where('is_approved', true)
            ->orderBy('item_id')
            ->orderBy('id')
            ->get();
    }

    /**
     * Hạng mục khách đã đồng ý và đã chốt giữ kho (không gồm phát sinh còn chờ khách duyệt).
     *
     * @return Collection<int, WorkOrderItem>
     */
    private function committedItems(WorkOrder $workOrder): Collection
    {
        return $workOrder->items()
            ->where('is_approved', true)
            ->where('revision_no', '<=', max(1, (int) $workOrder->approved_revision))
            ->orderBy('item_id')
            ->orderBy('id')
            ->get();
    }

    private function assertNoPendingAddendum(WorkOrder $workOrder): void
    {
        $revision = $workOrder->pendingAddendumRevision();

        if ($revision !== null) {
            throw new DomainException('Còn hạng mục phát sinh đợt '.($revision - 1).' chưa chốt với khách. Bấm "Chốt phát sinh" trước.');
        }
    }

    /**
     * Ghi hạng mục khách từ chối vào bệnh án xe; khuyến cáo cũ được đánh dấu đã làm nếu lần này khách làm.
     */
    private function syncRecommendations(WorkOrder $workOrder): void
    {
        $workOrder->loadMissing('vehicle');
        $items = $workOrder->items()->get();
        $names = $this->itemNames($items);

        foreach ($items->filter(fn (WorkOrderItem $item): bool => $item->is_approved === false) as $item) {
            VehicleRecommendation::query()->firstOrCreate(
                ['work_order_item_id' => $item->id],
                [
                    'vehicle_id' => $workOrder->vehicle_id,
                    'work_order_id' => $workOrder->id,
                    'item_type' => $item->item_type,
                    'item_id' => $item->item_id,
                    'item_name' => $names[$item->id],
                    'revision_no' => $item->revision_no,
                    'decline_reason' => $item->decline_reason,
                    'odo' => $workOrder->reception_odo,
                    'declined_at' => $item->declined_at ?? now(),
                ],
            );
        }

        foreach ($items->filter(fn (WorkOrderItem $item): bool => $item->is_approved !== false) as $item) {
            VehicleRecommendation::query()
                ->open()
                ->where('vehicle_id', $workOrder->vehicle_id)
                ->where('work_order_id', '!=', $workOrder->id)
                ->where('item_type', $item->item_type)
                ->where('item_id', $item->item_id)
                ->update(['resolved_at' => now(), 'resolved_work_order_id' => $workOrder->id]);
        }
    }

    /**
     * @param  Collection<int, WorkOrderItem>  $items
     * @return array<int, string>
     */
    private function itemNames(Collection $items): array
    {
        $parts = Part::query()
            ->whereIn('id', $items->filter(fn (WorkOrderItem $item): bool => $item->item_type === WorkOrderItemType::Part)->pluck('item_id'))
            ->pluck('name', 'id');
        $services = Service::query()
            ->whereIn('id', $items->filter(fn (WorkOrderItem $item): bool => $item->item_type === WorkOrderItemType::Service)->pluck('item_id'))
            ->pluck('name', 'id');

        return $items->mapWithKeys(fn (WorkOrderItem $item): array => [
            $item->id => $item->item_type === WorkOrderItemType::Part
                ? (string) ($parts[$item->item_id] ?? 'Phụ tùng #'.$item->item_id)
                : (string) ($services[$item->item_id] ?? 'Dịch vụ #'.$item->item_id),
        ])->all();
    }

    private function reserveInvoiceNumber(): string
    {
        $prefix = 'INV-'.now()->format('Ymd').'-';

        $lastNumber = Invoice::query()
            ->where('invoice_number', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('invoice_number')
            ->value('invoice_number');

        $sequence = 1;

        if (is_string($lastNumber) && preg_match('/^'.preg_quote($prefix, '/').'(\d{4})$/', $lastNumber, $matches) === 1) {
            $sequence = ((int) $matches[1]) + 1;
        }

        if ($sequence > 9999) {
            throw new DomainException('Đã hết mã hóa đơn trong ngày.');
        }

        return $prefix.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    private function assertActor(int $userId): void
    {
        if ($userId < 1 || ! User::query()->whereKey($userId)->exists()) {
            throw new DomainException('Người thực hiện không hợp lệ.');
        }
    }

    private function wholeQuantity(WorkOrderItem $item, string $partName): int
    {
        $quantity = (float) $item->quantity;

        if ($quantity <= 0 || abs($quantity - round($quantity)) > 0.00001) {
            throw new DomainException("Số lượng phụ tùng {$partName} phải là số nguyên dương.");
        }

        return (int) round($quantity);
    }
}
