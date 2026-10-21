<?php

namespace App\Http\Controllers;

use App\Helpers\LicensePlateHelper;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\AppointmentService;
use App\Services\MessagingService;
use App\Services\QuickReceptionService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class PublicBookingController extends Controller
{
    public function create(): View
    {
        return view('public.booking', [
            'garage' => config('garage'),
        ]);
    }

    public function store(Request $request, AppointmentService $appointments): RedirectResponse
    {
        // Khách hay gõ "0933 222 111" / "0933.222.111": bỏ dấu trước khi kiểm tra định dạng.
        $request->merge(['phone' => preg_replace('/[\s.\-]/', '', (string) $request->input('phone')) ?? $request->input('phone')]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20', 'regex:/^(0|\+84)(3|5|7|8|9)\d{8}$/'],
            'license_plate' => ['required', 'string', 'max:20'],
            'scheduled_start' => ['required', 'date', 'after:now'],
            'customer_request' => ['nullable', 'string', 'max:1000'],
        ], [
            'phone.regex' => 'Số điện thoại phải là di động Việt Nam.',
            'scheduled_start.after' => 'Giờ hẹn phải ở tương lai.',
        ]);

        // Khách gõ "+84..." hoặc có dấu cách; DB lưu "0...". Biển số trong DB có dấu chấm/gạch.
        $phone = MessagingService::normalizePhone($data['phone']) ?? (preg_replace('/[\s.\-]/', '', $data['phone']) ?? $data['phone']);
        $plate = LicensePlateHelper::clean($data['license_plate']);

        $customer = Customer::query()->where('phone', $phone)->first();
        $vehicle = QuickReceptionService::findVehicle($data['license_plate']);

        if ($vehicle instanceof Vehicle && $customer instanceof Customer && (int) $vehicle->customer_id !== (int) $customer->id) {
            // Biển số thuộc khách khác — vẫn nhận walk-in, để cố vấn xử lý.
            $vehicle = null;
        }

        $actorId = (int) (User::query()->where('role', 'admin')->value('id') ?: User::query()->value('id'));
        if ($actorId < 1) {
            return back()->withInput()->with('error', 'Hệ thống chưa sẵn sàng nhận lịch. Vui lòng gọi hotline.');
        }

        try {
            $start = Carbon::parse($data['scheduled_start'], (string) config('garage.timezone'))->utc();

            $appt = $appointments->create([
                'customer_id' => $customer?->id,
                'vehicle_id' => $vehicle?->id,
                'walk_in_name' => $customer ? null : trim($data['name']),
                'walk_in_phone' => $customer ? null : $phone,
                'walk_in_plate' => $vehicle ? null : ($plate !== '' ? $plate : null),
                'scheduled_start' => $start,
                'lift_bay' => null,
                'customer_request' => $data['customer_request'] ?? null,
                'service_advisor_id' => $actorId,
            ], $actorId);
        } catch (DomainException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()
            ->route('public.booking.create')
            ->with('success', 'Đã gửi lịch hẹn '.$appt->code.'. Gara sẽ gọi xác nhận sớm.');
    }
}
