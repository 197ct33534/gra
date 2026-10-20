<?php

namespace App\Filament\Pages;

use App\Enums\CustomerMessageStatus;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\CustomerMessage;
use App\Models\Vehicle;
use App\Services\MessagingService;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

/**
 * Chăm sóc khách: xe đến hạn bảo dưỡng và tin nhắn đã gửi.
 */
class CustomerCare extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Tiếp nhận';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Chăm sóc khách';

    protected static ?string $title = 'Chăm sóc khách hàng';

    protected static ?string $slug = 'customer-care';

    protected string $view = 'filament.pages.customer-care';

    public static function canAccess(): bool
    {
        return WorkOrderResource::isOfficeUser();
    }

    public static function isSimulated(): bool
    {
        return config('garage.messaging.driver') === 'log';
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    public function getDueVehicles(): \Illuminate\Support\Collection
    {
        return app(MessagingService::class)->dueVehicles();
    }

    /**
     * @return Collection<int, CustomerMessage>
     */
    public function getRecentMessages(): Collection
    {
        return CustomerMessage::query()->with(['customer', 'vehicle'])->latest('id')->limit(30)->get();
    }

    public function pendingCount(): int
    {
        return CustomerMessage::query()->where('status', CustomerMessageStatus::Pending)->count();
    }

    public function queueReminder(int $vehicleId): void
    {
        $vehicle = Vehicle::query()->find($vehicleId);

        if (! $vehicle instanceof Vehicle) {
            return;
        }

        try {
            app(MessagingService::class)->queueMaintenanceReminder($vehicle, WorkOrderResource::actorId());
            Notification::make()->title('Đã tạo tin nhắc cho xe '.$vehicle->license_plate)->success()->send();
        } catch (DomainException $exception) {
            Notification::make()->title('Không tạo tin nhắc')->body($exception->getMessage())->warning()->send();
        }
    }

    public function retry(int $messageId): void
    {
        $message = CustomerMessage::query()->find($messageId);

        if (! $message instanceof CustomerMessage || $message->status !== CustomerMessageStatus::Failed) {
            return;
        }

        $ok = app(MessagingService::class)->deliver($message);
        Notification::make()
            ->title($ok ? 'Đã gửi lại tin' : 'Gửi lại vẫn lỗi')
            ->body($ok ? null : $message->error)
            ->{$ok ? 'success' : 'danger'}()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('queueAll')
                ->label('Tạo tin cho tất cả xe đến hạn')
                ->icon(Heroicon::OutlinedBellAlert)
                ->requiresConfirmation()
                ->modalDescription('Chỉ tạo tin cho xe chưa được nhắc gần đây và khách không từ chối nhận tin.')
                ->action(function (): void {
                    $queued = 0;
                    $skipped = 0;

                    foreach (app(MessagingService::class)->dueVehicles() as $row) {
                        try {
                            app(MessagingService::class)->queueMaintenanceReminder($row['vehicle'], WorkOrderResource::actorId());
                            $queued++;
                        } catch (DomainException) {
                            $skipped++;
                        }
                    }

                    Notification::make()->title("Đã tạo {$queued} tin nhắc, bỏ qua {$skipped} xe")->success()->send();
                }),
            Action::make('sendPending')
                ->label(fn (): string => 'Gửi tin đang chờ ('.$this->pendingCount().')')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('success')
                ->disabled(fn (): bool => $this->pendingCount() === 0)
                ->action(function (): void {
                    $result = app(MessagingService::class)->sendPending();
                    Notification::make()
                        ->title("Đã gửi {$result['sent']} tin".($result['failed'] > 0 ? ", lỗi {$result['failed']} tin" : ''))
                        ->{$result['failed'] > 0 ? 'warning' : 'success'}()
                        ->send();
                }),
        ];
    }
}
