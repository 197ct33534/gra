<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Enums\WorkOrderStatus;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Collection;

class AdvisorKpiService
{
    /**
     * @return list<WorkOrderStatus>
     */
    private function quotedOrBeyond(): array
    {
        return [
            WorkOrderStatus::Quoted,
            WorkOrderStatus::CustomerApproved,
            WorkOrderStatus::InProgress,
            WorkOrderStatus::Qc,
            WorkOrderStatus::Completed,
            WorkOrderStatus::Delivered,
        ];
    }

    /**
     * @return list<WorkOrderStatus>
     */
    private function approvedOrBeyond(): array
    {
        return [
            WorkOrderStatus::CustomerApproved,
            WorkOrderStatus::InProgress,
            WorkOrderStatus::Qc,
            WorkOrderStatus::Completed,
            WorkOrderStatus::Delivered,
        ];
    }

    /**
     * @return list<array{
     *     advisor: User,
     *     ros: int,
     *     quoted: int,
     *     approved: int,
     *     delivered: int,
     *     conversion: float,
     *     revenue: float,
     *     avg_feedback: ?float,
     *     feedback_count: int
     * }>
     */
    public function monthly(string $month): array
    {
        ['start' => $start, 'end' => $end] = PayrollService::monthRange($month);

        $advisors = User::query()
            ->whereIn('role', [UserRole::ServiceAdvisor, UserRole::Admin])
            ->orderBy('name')
            ->get();

        $orders = WorkOrder::query()
            ->whereBetween('created_at', [$start, $end])
            ->get();

        $delivered = WorkOrder::query()
            ->where('status', WorkOrderStatus::Delivered)
            ->whereBetween('delivered_at', [$start, $end])
            ->get();

        $rows = [];

        foreach ($advisors as $advisor) {
            $own = $orders->where('service_advisor_id', $advisor->id);
            $ownDelivered = $delivered->where('service_advisor_id', $advisor->id);

            $quoted = $own->filter(fn (WorkOrder $o): bool => in_array($o->status, $this->quotedOrBeyond(), true))->count();
            $approved = $own->filter(fn (WorkOrder $o): bool => in_array($o->status, $this->approvedOrBeyond(), true))->count();
            $conversion = $quoted > 0 ? round($approved / $quoted * 100, 1) : 0.0;

            $feedback = $ownDelivered->filter(fn (WorkOrder $o): bool => $o->feedback_score !== null);
            $avgFeedback = $feedback->isNotEmpty()
                ? round((float) $feedback->avg(fn (WorkOrder $o): int => (int) $o->feedback_score), 2)
                : null;

            $rows[] = [
                'advisor' => $advisor,
                'ros' => $own->count(),
                'quoted' => $quoted,
                'approved' => $approved,
                'delivered' => $ownDelivered->count(),
                'conversion' => $conversion,
                'revenue' => round((float) $ownDelivered->sum('final_amount'), 2),
                'avg_feedback' => $avgFeedback,
                'feedback_count' => $feedback->count(),
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['revenue'] <=> $a['revenue']);

        return $rows;
    }

    /**
     * @return Collection<int, WorkOrder>
     */
    public function deliveredFor(User $advisor, string $month): Collection
    {
        ['start' => $start, 'end' => $end] = PayrollService::monthRange($month);

        return WorkOrder::query()
            ->where('service_advisor_id', $advisor->id)
            ->where('status', WorkOrderStatus::Delivered)
            ->whereBetween('delivered_at', [$start, $end])
            ->with('vehicle')
            ->orderByDesc('delivered_at')
            ->get();
    }
}
