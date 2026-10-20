<?php

namespace App\Services;

use App\Models\WorkOrder;
use DomainException;
use Illuminate\Support\Str;

class CustomerPortalService
{
    public function ensureStatusToken(WorkOrder $workOrder): string
    {
        if (filled($workOrder->status_token)) {
            return (string) $workOrder->status_token;
        }

        $token = Str::random(48);
        $workOrder->status_token = $token;
        $workOrder->save();

        return $token;
    }

    public function statusUrl(WorkOrder $workOrder): string
    {
        return url('/status/'.$this->ensureStatusToken($workOrder));
    }

    public function findByStatusToken(string $token): WorkOrder
    {
        $order = WorkOrder::query()
            ->with(['vehicle.customer', 'items'])
            ->where('status_token', $token)
            ->first();

        if (! $order instanceof WorkOrder) {
            throw new DomainException('Link theo dõi không hợp lệ.');
        }

        return $order;
    }

    public function ensureFeedbackToken(WorkOrder $workOrder): string
    {
        if (filled($workOrder->feedback_token)) {
            return (string) $workOrder->feedback_token;
        }

        $token = Str::random(48);
        $workOrder->feedback_token = $token;
        $workOrder->save();

        return $token;
    }

    public function feedbackUrl(WorkOrder $workOrder): string
    {
        return url('/feedback/'.$this->ensureFeedbackToken($workOrder));
    }

    public function findByFeedbackToken(string $token): WorkOrder
    {
        $order = WorkOrder::query()
            ->with(['vehicle.customer'])
            ->where('feedback_token', $token)
            ->first();

        if (! $order instanceof WorkOrder) {
            throw new DomainException('Link đánh giá không hợp lệ.');
        }

        return $order;
    }

    public function submitFeedback(string $token, int $score, ?string $comment = null): WorkOrder
    {
        if ($score < 1 || $score > 5) {
            throw new DomainException('Điểm đánh giá phải từ 1 đến 5.');
        }

        $order = $this->findByFeedbackToken($token);

        if ($order->feedback_at !== null) {
            throw new DomainException('Bạn đã gửi đánh giá cho lần sửa này rồi. Cảm ơn!');
        }

        if ($order->delivered_at === null) {
            throw new DomainException('Chỉ đánh giá sau khi xe đã bàn giao.');
        }

        $order->feedback_score = $score;
        $order->feedback_comment = filled($comment) ? trim($comment) : null;
        $order->feedback_at = now();
        $order->save();

        return $order;
    }
}
