<?php

namespace Tests\Feature;

use App\Enums\CustomerMessageType;
use App\Enums\WorkOrderStatus;
use App\Filament\Pages\FinancialReports;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\FinancialReportService;
use App\Services\MessagingService;
use App\Services\WorkOrderPrintService;
use App\Support\BodyInspection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class P3BodyInspectionAndP4Test extends TestCase
{
    use RefreshDatabase;

    public function test_body_inspection_summary_and_status_page(): void
    {
        $this->seed();

        $order = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $order->update([
            'body_inspection' => [
                ['zone' => 'front_bumper', 'condition' => 'scratch', 'note' => 'Xước góc phải'],
                ['zone' => 'left_doors', 'condition' => 'dent', 'note' => ''],
            ],
            'status_token' => 'status-body-test-token',
        ]);

        $lines = BodyInspection::summaryLines($order->body_inspection);
        $this->assertCount(2, $lines);
        $this->assertStringContainsString('Cản trước', $lines[0]);

        $data = app(WorkOrderPrintService::class)->jobCardData($order->fresh());
        $this->assertNotEmpty($data['bodyInspection']);

        $this->get(route('public.status.show', 'status-body-test-token'))
            ->assertOk()
            ->assertSee('Cản trước')
            ->assertSee('Xước');
    }

    public function test_feedback_request_queued_via_messaging_service(): void
    {
        $this->seed();

        $order = WorkOrder::query()->where('status', WorkOrderStatus::Delivered)->firstOrFail();
        $order->vehicle->customer->update([
            'messaging_opt_in' => true,
            'messaging_opt_in_at' => now(),
        ]);
        $order->update([
            'feedback_at' => null,
            'feedback_score' => null,
            'feedback_comment' => null,
        ]);

        $msg = app(MessagingService::class)->queueFeedbackRequest($order->fresh(['vehicle.customer']));
        $this->assertNotNull($msg);
        $this->assertSame(CustomerMessageType::FeedbackRequest, $msg->type);
        $this->assertStringContainsString('/feedback/', $msg->body);

        $this->assertNull(app(MessagingService::class)->queueFeedbackRequest($order->fresh(['vehicle.customer'])));
    }

    public function test_aging_payables_structure(): void
    {
        $this->seed();

        $ap = app(FinancialReportService::class)->agingPayables();
        $this->assertArrayHasKey('buckets', $ap);
        $this->assertArrayHasKey('0_30', $ap['buckets']);
        $this->assertArrayHasKey('total', $ap);

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $this->actingAs($admin);
        Livewire::test(FinancialReports::class)->assertOk();
    }

    public function test_body_zones_catalog_complete(): void
    {
        $this->assertGreaterThanOrEqual(10, count(BodyInspection::zones()));
        $this->assertArrayHasKey('scratch', BodyInspection::conditions());
    }
}
