<?php

namespace App\Services;

use App\Enums\WorkOrderStatus;
use App\Models\WorkOrder;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class QuoteApprovalService
{
    public function ensureToken(WorkOrder $workOrder): string
    {
        if (filled($workOrder->quote_approval_token)) {
            return (string) $workOrder->quote_approval_token;
        }

        $token = Str::random(48);
        $workOrder->quote_approval_token = $token;
        $workOrder->save();

        return $token;
    }

    public function publicUrl(WorkOrder $workOrder): string
    {
        $token = $this->ensureToken($workOrder);

        return url('/quote/'.$token);
    }

    public function findByToken(string $token): WorkOrder
    {
        $order = WorkOrder::query()
            ->with(['vehicle.customer', 'items'])
            ->where('quote_approval_token', $token)
            ->first();

        if (! $order instanceof WorkOrder) {
            throw new DomainException('Link báo giá không hợp lệ hoặc đã hết hạn.');
        }

        return $order;
    }

    public function approveByCustomer(string $token): WorkOrder
    {
        return DB::transaction(function () use ($token): WorkOrder {
            $order = WorkOrder::query()->where('quote_approval_token', $token)->lockForUpdate()->first();

            if (! $order instanceof WorkOrder) {
                throw new DomainException('Link báo giá không hợp lệ.');
            }

            if ($order->status !== WorkOrderStatus::Quoted) {
                throw new DomainException('Báo giá không còn chờ duyệt (trạng thái: '.$order->status->getLabel().').');
            }

            if ($order->quote_rejected_at !== null) {
                throw new DomainException('Khách đã từ chối báo giá này trước đó.');
            }

            $actorId = (int) ($order->service_advisor_id
                ?: \App\Models\User::query()->where('role', 'admin')->value('id')
                ?: 0);

            $warnings = app(WorkOrderService::class)->approveWorkOrder($order, $actorId);

            $order->refresh();
            $order->quote_approved_at = now();
            $order->quote_approved_via = 'remote_link';
            $order->save();

            unset($warnings);

            return $order;
        });
    }

    public function rejectByCustomer(string $token, ?string $reason = null): WorkOrder
    {
        return DB::transaction(function () use ($token, $reason): WorkOrder {
            $order = WorkOrder::query()->where('quote_approval_token', $token)->lockForUpdate()->first();

            if (! $order instanceof WorkOrder) {
                throw new DomainException('Link báo giá không hợp lệ.');
            }

            if ($order->status !== WorkOrderStatus::Quoted) {
                throw new DomainException('Báo giá không còn chờ duyệt.');
            }

            $order->quote_rejected_at = now();
            $order->quote_reject_reason = filled($reason) ? trim($reason) : null;
            $order->status = WorkOrderStatus::Diagnosing;
            $order->save();

            return $order;
        });
    }
}
