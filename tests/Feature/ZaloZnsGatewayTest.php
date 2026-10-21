<?php

namespace Tests\Feature;

use App\Enums\CustomerMessageStatus;
use App\Enums\CustomerMessageType;
use App\Messaging\ZaloZnsGateway;
use App\Models\Customer;
use App\Models\CustomerMessage;
use App\Services\MessagingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZaloZnsGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://business.openapi.zalo.me/message/template';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        config([
            'garage.messaging.driver' => 'zalo_zns',
            'services.zalo.oa_id' => 'oa-1',
            'services.zalo.access_token' => 'token-1',
            'services.zalo.default_template_id' => 'tpl-1',
        ]);
    }

    private function message(string $phone = '0977123456'): CustomerMessage
    {
        return CustomerMessage::query()->create([
            'customer_id' => Customer::query()->value('id'),
            'type' => CustomerMessageType::MaintenanceReminder,
            'channel' => 'zalo',
            'phone' => $phone,
            'body' => 'Xe đến hạn bảo dưỡng',
            'status' => CustomerMessageStatus::Pending,
            'scheduled_at' => now()->subMinute(),
        ]);
    }

    public function test_sends_with_international_phone_and_returns_zalo_message_id(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['error' => 0, 'message' => 'Success', 'data' => ['msg_id' => 'zns-123']])]);
        $message = $this->message();

        $this->assertTrue(app(MessagingService::class)->deliver($message));

        $this->assertSame('zns-123', $message->refresh()->provider_message_id);
        $this->assertSame(CustomerMessageStatus::Sent, $message->status);
        Http::assertSent(fn (Request $request): bool => $request['phone'] === '84977123456'
            && $request['template_id'] === 'tpl-1'
            && $request->hasHeader('Authorization', 'Bearer token-1'));
    }

    public function test_zalo_error_code_inside_http_200_is_a_failure(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['error' => -124, 'message' => 'Access token invalid'])]);
        $message = $this->message();

        $this->assertFalse(app(MessagingService::class)->deliver($message));

        $message->refresh();
        $this->assertNotSame(CustomerMessageStatus::Sent, $message->status);
        $this->assertStringContainsString('-124', $message->error);
        $this->assertStringContainsString('Access token invalid', $message->error);
    }

    public function test_network_error_on_one_message_does_not_stop_the_batch(): void
    {
        $first = $this->message('0977123456');
        $second = $this->message('0909888777');
        Http::fake(function (Request $request) {
            if ($request['phone'] === '84977123456') {
                throw new ConnectionException('Connection timed out');
            }

            return Http::response(['error' => 0, 'data' => ['msg_id' => 'zns-ok']]);
        });

        $result = app(MessagingService::class)->sendPending();

        $this->assertSame(['sent' => 1, 'failed' => 1], $result);
        $this->assertStringContainsString('Không kết nối được Zalo ZNS', $first->refresh()->error);
        $this->assertSame(CustomerMessageStatus::Sent, $second->refresh()->status);
    }

    public function test_phone_format_for_zns(): void
    {
        $this->assertSame('84977123456', ZaloZnsGateway::internationalPhone('0977 123 456'));
        $this->assertSame('84977123456', ZaloZnsGateway::internationalPhone('84977123456'));
    }
}
