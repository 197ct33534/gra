<?php

namespace App\Http\Controllers;

use App\Enums\WorkOrderStatus;
use App\Services\CustomerPortalService;
use DomainException;
use Illuminate\View\View;

class PublicStatusController extends Controller
{
    public function show(string $token, CustomerPortalService $portal): View
    {
        try {
            $order = $portal->findByStatusToken($token);
        } catch (DomainException $e) {
            abort(404, $e->getMessage());
        }

        $showMoney = in_array($order->status, [
            WorkOrderStatus::Quoted,
            WorkOrderStatus::CustomerApproved,
            WorkOrderStatus::InProgress,
            WorkOrderStatus::Qc,
            WorkOrderStatus::Completed,
            WorkOrderStatus::Delivered,
        ], true);

        return view('public.status', [
            'order' => $order,
            'token' => $token,
            'garage' => config('garage'),
            'showMoney' => $showMoney,
            'timeline' => $this->timeline($order->status),
        ]);
    }

    /**
     * @return list<array{key: string, label: string, done: bool, current: bool}>
     */
    private function timeline(WorkOrderStatus $status): array
    {
        $steps = [
            WorkOrderStatus::Reception->value => 'Tiếp nhận',
            WorkOrderStatus::Diagnosing->value => 'Chẩn đoán',
            WorkOrderStatus::Quoted->value => 'Báo giá',
            WorkOrderStatus::CustomerApproved->value => 'Đã duyệt',
            WorkOrderStatus::InProgress->value => 'Đang sửa',
            WorkOrderStatus::Qc->value => 'Kiểm tra',
            WorkOrderStatus::Completed->value => 'Hoàn thành',
            WorkOrderStatus::Delivered->value => 'Bàn giao',
        ];

        $order = array_keys($steps);
        $currentIdx = array_search($status->value, $order, true);
        if ($currentIdx === false) {
            $currentIdx = 0;
        }

        $out = [];
        foreach ($steps as $key => $label) {
            $idx = array_search($key, $order, true);
            $out[] = [
                'key' => $key,
                'label' => $label,
                'done' => $idx !== false && $idx < $currentIdx,
                'current' => $key === $status->value,
            ];
        }

        return $out;
    }
}
