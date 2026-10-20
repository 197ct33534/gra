<?php

namespace Tests\Feature;

use App\Enums\ActivityEvent;
use App\Enums\BankTransactionStatus;
use App\Enums\CustomerMessageStatus;
use App\Enums\CustomerMessageType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Filament\Pages\CustomerCare;
use App\Filament\Resources\BankTransactions\Pages\ListBankTransactions;
use App\Models\ActivityLog;
use App\Models\BankTransaction;
use App\Models\CustomerMessage;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Services\BankReconciliationService;
use App\Services\MessagingService;
use App\Services\WorkOrderService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class RetentionAndBankTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00:00', 'Asia/Ho_Chi_Minh'));
        $this->seed();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function user(string $email): User
    {
        return User::query()->where('email', $email)->firstOrFail();
    }

    /**
     * RO-20261019-002 (1.026.000 đ) được duyệt và lập hóa đơn chưa thu đồng nào.
     */
    private function unpaidInvoice(): Invoice
    {
        $admin = $this->user('admin@gara.vn');
        $order = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $service = app(WorkOrderService::class);
        $service->approveWorkOrder($order, $admin->id);

        return $service->issueInvoice($order, PaymentMethod::BankTransfer, 0);
    }

    public function test_each_vehicle_has_its_own_maintenance_cycle(): void
    {
        $due = Vehicle::query()->where('license_plate', '51G-246.80')->firstOrFail();

        $this->assertSame('overdue', $due->reminderStatus());

        // Nhớt tổng hợp 10.000 km: chưa tới mốc km, mới 40 ngày nên chưa cần nhắc.
        $due->update(['service_interval_km' => 10000]);
        $due->refresh();
        $this->assertSame(32000, $due->next_service_odo);
        $this->assertNull($due->reminderStatus());
        $this->assertSame('2026-12-10', $due->predictedServiceDate()->timezone('Asia/Ho_Chi_Minh')->format('Y-m-d'));

        // Chu kỳ 1 tháng thì 40 ngày là quá hạn.
        $due->update(['service_interval_months' => 1]);
        $this->assertSame('overdue', $due->refresh()->reminderStatus());
    }

    public function test_due_list_skips_cars_in_the_workshop_and_reminders_are_deduplicated(): void
    {
        $messaging = app(MessagingService::class);
        $due = $messaging->dueVehicles();

        // Vios, Ranger, CX-5 đang có lệnh mở nên không nhắc.
        $this->assertSame(['51G-246.80'], $due->map(fn (array $row): string => $row['vehicle']->license_plate)->all());

        $vehicle = $due->first()['vehicle'];
        $message = $messaging->queueMaintenanceReminder($vehicle);

        $this->assertSame(CustomerMessageType::MaintenanceReminder, $message->type);
        $this->assertSame('0977123456', $message->phone);
        $this->assertStringContainsString('51G-246.80', $message->body);
        $this->assertStringContainsString('đã đến hạn bảo dưỡng', $message->body);
        $this->assertStringContainsString('mốc 27.000 km', $message->body);
        $this->assertStringContainsString(config('garage.hotline'), $message->body);

        try {
            $messaging->queueMaintenanceReminder($vehicle);
            $this->fail('Không được nhắc trùng trong 30 ngày.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('đã được nhắc trong 30 ngày', $exception->getMessage());
        }

        $vehicle->customer->update(['reminder_opt_out' => true]);
        CustomerMessage::query()->delete();
        $this->expectExceptionMessage('đã từ chối nhận tin nhắc');
        $messaging->queueMaintenanceReminder($vehicle->refresh());
    }

    public function test_scheduled_commands_queue_and_send_through_the_configured_driver(): void
    {
        $this->artisan('garage:queue-maintenance-reminders')
            ->expectsOutput('Đã tạo 1 tin nhắc, bỏ qua 0 xe.')
            ->assertSuccessful();

        // Driver Zalo thiếu token: báo lỗi rõ, hết số lần thử → failed.
        config([
            'garage.messaging.driver' => 'zalo_zns',
            'garage.messaging_retry.max_attempts' => 1,
        ]);
        $this->app->forgetInstance(\App\Messaging\MessageGateway::class);
        $this->artisan('garage:send-messages')->expectsOutput('Đã gửi 0 tin, lỗi 1 tin.')->assertSuccessful();
        $message = CustomerMessage::query()->sole();
        $this->assertSame(CustomerMessageStatus::Failed, $message->status);
        $this->assertStringContainsString('ZALO_OA', $message->error);

        config(['garage.messaging.driver' => 'log']);
        $this->app->forgetInstance(\App\Messaging\MessageGateway::class);
        $this->app->forgetInstance(MessagingService::class);
        $this->assertTrue(app(MessagingService::class)->deliver($message));
        $message->refresh();
        $this->assertSame(CustomerMessageStatus::Sent, $message->status);
        $this->assertStringStartsWith('log-', $message->provider_message_id);
        $this->assertNull($message->error);
    }

    public function test_webhook_requires_the_api_key(): void
    {
        $payload = BankReconciliationService::fakeSepayPayload(100000, 'TT RO-20261019-002');

        config(['garage.bank_webhook.sepay_api_key' => null]);
        $this->postJson(route('webhooks.sepay'), $payload)->assertStatus(503);

        config(['garage.bank_webhook.sepay_api_key' => 'khoa-bi-mat']);
        $this->postJson(route('webhooks.sepay'), $payload, ['Authorization' => 'Apikey sai'])->assertStatus(401);
        $this->assertSame(0, BankTransaction::query()->count());
    }

    public function test_transfer_with_order_code_is_matched_paid_and_receipted_once(): void
    {
        config(['garage.bank_webhook.sepay_api_key' => 'khoa-bi-mat']);
        $invoice = $this->unpaidInvoice();
        $headers = ['Authorization' => 'Apikey khoa-bi-mat'];

        // Ngân hàng bỏ dấu gạch trong nội dung: vẫn khớp mã lệnh.
        $first = BankReconciliationService::fakeSepayPayload(500000, 'NGUYEN VAN A TT RO20261019002 FT26293');
        $this->postJson(route('webhooks.sepay'), $first, $headers)
            ->assertOk()
            ->assertJson(['success' => true, 'status' => 'matched']);
        // SePay gửi lại cùng giao dịch: không thu hai lần.
        $this->postJson(route('webhooks.sepay'), $first, $headers)->assertOk();

        $invoice->refresh();
        $this->assertSame('500000.00', $invoice->paid_amount);
        $this->assertSame(PaymentStatus::PartiallyPaid, $invoice->payment_status);
        $this->assertSame(1, BankTransaction::query()->count());
        $receipt = CustomerMessage::query()->where('type', CustomerMessageType::PaymentReceipt)->sole();
        $this->assertStringContainsString('đã nhận 500.000 đ', $receipt->body);
        $this->assertStringContainsString('Còn lại: 526.000 đ', $receipt->body);

        // Chuyển thừa: thu đủ phần còn nợ, ghi chú phần dư.
        $second = BankReconciliationService::fakeSepayPayload(600000, 'TT RO-20261019-002 lan 2');
        $this->postJson(route('webhooks.sepay'), $second, $headers)->assertOk();

        $this->assertSame(PaymentStatus::Paid, $invoice->refresh()->payment_status);
        $over = BankTransaction::query()->where('provider_id', $second['id'])->sole();
        $this->assertSame('526000.00', $over->matched_amount);
        $this->assertStringContainsString('chuyển thừa 74.000 đ', $over->note);
        $this->assertSame(2, ActivityLog::query()->where('event', ActivityEvent::PaymentRecorded)->count());
    }

    public function test_unclear_transfers_wait_for_manual_matching(): void
    {
        $invoice = $this->unpaidInvoice();
        $reconciliation = app(BankReconciliationService::class);

        $unclear = $reconciliation->ingestSepay(BankReconciliationService::fakeSepayPayload(1026000, 'chuyen tien sua xe'));
        $this->assertSame(BankTransactionStatus::Unmatched, $unclear->status);
        $this->assertStringContainsString('Không thấy mã lệnh', $unclear->note);

        $outgoing = $reconciliation->ingestSepay(['transferType' => 'out'] + BankReconciliationService::fakeSepayPayload(300000, 'Tra tien phu tung'));
        $this->assertSame(BankTransactionStatus::Ignored, $outgoing->status);

        Livewire::actingAs($this->user('sa@gara.vn'))
            ->test(ListBankTransactions::class)
            ->assertCanSeeTableRecords([$unclear, $outgoing])
            ->callTableAction('match', $unclear, data: ['invoice_id' => $invoice->id])
            ->assertHasNoTableActionErrors();

        $this->assertSame(BankTransactionStatus::Matched, $unclear->refresh()->status);
        $this->assertSame(PaymentStatus::Paid, $invoice->refresh()->payment_status);

        $this->artisan('garage:simulate-transfer', ['amount' => 50000, 'content' => 'tien khac'])
            ->expectsOutputToContain('Chưa gán')
            ->assertSuccessful();
    }

    public function test_customer_care_page_for_office_staff(): void
    {
        $advisor = $this->user('sa@gara.vn');
        $vehicle = Vehicle::query()->where('license_plate', '51G-246.80')->firstOrFail();

        $this->actingAs($advisor)
            ->get(CustomerCare::getUrl())
            ->assertOk()
            ->assertSee('Đang chạy giả lập')
            ->assertSee('51G-246.80')
            ->assertSee('Quá hạn')
            ->assertSee('Chưa nhắc');

        Livewire::actingAs($advisor)
            ->test(CustomerCare::class)
            ->call('queueReminder', $vehicle->id)
            ->callAction('sendPending');

        $message = CustomerMessage::query()->sole();
        $this->assertSame($advisor->id, $message->created_by);
        $this->assertSame(CustomerMessageStatus::Sent, $message->status);

        $this->flushSession();
        $this->actingAs($this->user('tech1@gara.vn'))->get(CustomerCare::getUrl())->assertForbidden();
    }
}
