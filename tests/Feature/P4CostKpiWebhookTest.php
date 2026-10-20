<?php

namespace Tests\Feature;

use App\Enums\WorkOrderStatus;
use App\Filament\Pages\AdvisorKpi;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Part;
use App\Models\Supplier;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\AdvisorKpiService;
use App\Services\PartCostHistoryService;
use App\Services\PayrollService;
use App\Services\WebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class P4CostKpiWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_part_cost_history_and_trend(): void
    {
        $this->seed();

        $part = Part::query()->where('sku', 'RANGER-BRAKE-F')->firstOrFail();
        $supplier = Supplier::query()->firstOrFail();
        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();

        $receipt1 = GoodsReceipt::query()->create([
            'code' => 'PN-TEST-001',
            'supplier_id' => $supplier->id,
            'received_at' => now()->subDays(20),
            'vat_rate' => 8,
            'amount_before_vat' => 100000,
            'vat_amount' => 8000,
            'total_amount' => 108000,
            'created_by' => $admin->id,
        ]);
        GoodsReceiptItem::query()->create([
            'goods_receipt_id' => $receipt1->id,
            'part_id' => $part->id,
            'quantity' => 2,
            'unit_cost' => 400000,
            'line_total' => 800000,
        ]);

        $receipt2 = GoodsReceipt::query()->create([
            'code' => 'PN-TEST-002',
            'supplier_id' => $supplier->id,
            'received_at' => now()->subDays(2),
            'vat_rate' => 8,
            'amount_before_vat' => 120000,
            'vat_amount' => 9600,
            'total_amount' => 129600,
            'created_by' => $admin->id,
        ]);
        GoodsReceiptItem::query()->create([
            'goods_receipt_id' => $receipt2->id,
            'part_id' => $part->id,
            'quantity' => 1,
            'unit_cost' => 450000,
            'line_total' => 450000,
        ]);

        $history = app(PartCostHistoryService::class)->history($part);
        $this->assertGreaterThanOrEqual(2, $history->count());
        $this->assertEqualsWithDelta(450000, (float) $history->first()['unit_cost'], 0.01);
        $this->assertEqualsWithDelta(50000, (float) $history->first()['delta'], 0.01);

        $summary = app(PartCostHistoryService::class)->summary($part);
        $this->assertSame('up', $summary['trend']);
        $this->assertGreaterThanOrEqual(2, $summary['samples']);
    }

    public function test_advisor_kpi_page_and_metrics(): void
    {
        $this->seed();

        $month = PayrollService::currentMonth();
        $rows = app(AdvisorKpiService::class)->monthly($month);
        $this->assertNotEmpty($rows);
        $this->assertArrayHasKey('conversion', $rows[0]);
        $this->assertArrayHasKey('revenue', $rows[0]);

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $this->actingAs($admin);
        Livewire::test(AdvisorKpi::class)->assertOk();
        $this->get(AdvisorKpi::getUrl())->assertOk();
    }

    public function test_webhook_dispatched_on_delivery(): void
    {
        $this->seed();

        Http::fake([
            'https://hooks.example.test/*' => Http::response(['ok' => true], 200),
        ]);

        config([
            'garage.webhooks.delivered_url' => 'https://hooks.example.test/garage',
            'garage.webhooks.secret' => 'test-secret',
        ]);

        $order = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        // Đưa về trạng thái có thể bàn giao.
        $order->update(['status' => WorkOrderStatus::CustomerApproved, 'final_amount' => 1000000]);
        $order->items()->update(['is_approved' => true]);

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();

        // Gọi webhook service trực tiếp với RO đã giao (tránh phụ thuộc stock phức tạp).
        $order->update([
            'status' => WorkOrderStatus::Delivered,
            'delivered_at' => now(),
        ]);

        $ok = app(WebhookService::class)->dispatchDelivered($order->fresh(['vehicle.customer', 'branch']));
        $this->assertTrue($ok);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://hooks.example.test/garage'
                && $request->hasHeader('X-Garage-Event', 'work_order.delivered')
                && str_starts_with((string) $request->header('X-Garage-Signature')[0], 'sha256=')
                && str_contains($request->body(), 'work_order.delivered');
        });
    }

    public function test_webhook_skipped_when_url_empty(): void
    {
        $this->seed();
        config(['garage.webhooks.delivered_url' => null]);

        $order = WorkOrder::query()->where('status', WorkOrderStatus::Delivered)->firstOrFail();
        $this->assertFalse(app(WebhookService::class)->dispatchDelivered($order));
    }
}
