<x-filament-panels::page>
    @php
        $due = $this->getDueVehicles();
        $messages = $this->getRecentMessages();
        $timezone = (string) config('garage.timezone');
    @endphp

    <style>
        .cc-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .cc-table th { text-align: left; font-weight: 600; padding: 8px 10px; border-bottom: 1px solid rgba(127,127,127,.3); white-space: nowrap; }
        .cc-table td { padding: 8px 10px; border-bottom: 1px solid rgba(127,127,127,.15); vertical-align: top; }
        .cc-wrap { overflow-x: auto; }
        .cc-muted { opacity: .65; font-size: 12px; }
        .cc-plate { font-weight: 800; letter-spacing: .5px; }
        .cc-body { max-width: 520px; white-space: normal; }
    </style>

    @if (\App\Filament\Pages\CustomerCare::isSimulated())
        <x-filament::section compact icon="heroicon-o-beaker" icon-color="warning">
            <x-slot name="heading">Đang chạy giả lập — tin không gửi thật cho khách</x-slot>
            Tin "đã gửi" chỉ được ghi vào log. Khi có tài khoản Zalo ZNS hoặc SMS brandname, đổi <code>GARAGE_MESSAGING_DRIVER</code> trong <code>.env</code>.
        </x-filament::section>
    @endif

    <x-filament::section icon="heroicon-o-bell-alert">
        <x-slot name="heading">Xe đến hạn / sắp đến hạn bảo dưỡng ({{ $due->count() }})</x-slot>
        <x-slot name="description">Theo chu kỳ riêng từng xe và số km/ngày ước từ các lần vào xưởng. Không gồm xe đang nằm xưởng.</x-slot>
        <div class="cc-wrap">
            <table class="cc-table">
                <thead>
                    <tr>
                        <th>Xe</th>
                        <th>Khách</th>
                        <th>Tình trạng</th>
                        <th>Dự kiến đến hạn</th>
                        <th>Lần nhắc gần nhất</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($due as $row)
                        <tr wire:key="due-{{ $row['vehicle']->id }}">
                            <td>
                                <div class="cc-plate">{{ $row['vehicle']->license_plate }}</div>
                                <div class="cc-muted">{{ trim($row['vehicle']->brand.' '.$row['vehicle']->model) }} · {{ number_format($row['vehicle']->current_odo, 0, ',', '.') }} km</div>
                            </td>
                            <td>
                                {{ $row['vehicle']->customer?->name }}
                                <div class="cc-muted">{{ $row['vehicle']->customer?->phone }}@if ($row['vehicle']->customer?->reminder_opt_out) · không nhận tin @endif</div>
                            </td>
                            <td>
                                <x-filament::badge :color="$row['status'] === 'overdue' ? 'danger' : 'warning'">
                                    {{ $row['status'] === 'overdue' ? 'Quá hạn' : 'Sắp đến hạn' }}
                                </x-filament::badge>
                            </td>
                            <td>{{ $row['due'] ?? '—' }}</td>
                            <td>
                                @if ($row['last_reminder'])
                                    {{ $row['last_reminder']->created_at->timezone($timezone)->format('d/m/Y') }}
                                    <div class="cc-muted">{{ $row['last_reminder']->status->getLabel() }}</div>
                                @else
                                    <span class="cc-muted">Chưa nhắc</span>
                                @endif
                            </td>
                            <td>
                                <x-filament::button size="sm" color="gray" icon="heroicon-o-bell" wire:click="queueReminder({{ $row['vehicle']->id }})">
                                    Tạo tin nhắc
                                </x-filament::button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="cc-muted">Chưa có xe nào đến hạn.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section icon="heroicon-o-chat-bubble-left-right">
        <x-slot name="heading">Tin nhắn gần đây</x-slot>
        <div class="cc-wrap">
            <table class="cc-table">
                <thead>
                    <tr>
                        <th>Thời gian</th>
                        <th>Khách</th>
                        <th>Loại</th>
                        <th>Nội dung</th>
                        <th>Trạng thái</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($messages as $message)
                        <tr wire:key="msg-{{ $message->id }}">
                            <td>{{ $message->created_at->timezone($timezone)->format('H:i d/m') }}</td>
                            <td>
                                {{ $message->customer?->name }}
                                <div class="cc-muted">{{ $message->phone }} · {{ strtoupper($message->channel) }}</div>
                            </td>
                            <td>{{ $message->type->getLabel() }}</td>
                            <td class="cc-body">{{ $message->body }}</td>
                            <td>
                                <x-filament::badge :color="$message->status->getColor()">{{ $message->status->getLabel() }}</x-filament::badge>
                                @if ($message->error)
                                    <div class="cc-muted" style="color:#dc2626">{{ $message->error }}</div>
                                @endif
                                @if ($message->status === \App\Enums\CustomerMessageStatus::Failed)
                                    <x-filament::link tag="button" size="sm" wire:click="retry({{ $message->id }})">Gửi lại</x-filament::link>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="cc-muted">Chưa có tin nhắn.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
