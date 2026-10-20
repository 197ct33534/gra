<?php

namespace Tests\Feature;

use App\Enums\StocktakeStatus;
use App\Enums\StockTransactionType;
use App\Models\Part;
use App\Models\Stocktake;
use App\Models\StocktakeItem;
use App\Models\StockTransaction;
use App\Models\User;
use App\Services\InventoryService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StocktakeTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_stocktake_snapshots_all_parts(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $partsCount = Part::query()->count();

        $stocktake = app(InventoryService::class)->createStocktake($admin->id, 'Kiểm kê cuối tháng');

        $this->assertSame(StocktakeStatus::Draft, $stocktake->status);
        $this->assertSame($partsCount, $stocktake->items()->count());
        $this->assertStringStartsWith('KK-', $stocktake->code);
    }

    public function test_apply_stocktake_adjusts_stock_and_ledger(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $service = app(InventoryService::class);
        $oil = Part::query()->where('sku', 'CAST-5W30')->firstOrFail();
        $before = (int) $oil->stock_quantity;

        $stocktake = $service->createStocktake($admin->id);
        $service->startCounting($stocktake);

        foreach ($stocktake->items as $item) {
            $counted = (int) $item->system_quantity;
            if ((int) $item->part_id === (int) $oil->id) {
                $counted = $before - 3;
            }
            $item->update(['counted_quantity' => $counted]);
        }

        $applied = $service->applyStocktake($stocktake->fresh(['items']), $admin->id);
        $oil->refresh();

        $this->assertSame(StocktakeStatus::Applied, $applied->status);
        $this->assertSame($before - 3, (int) $oil->stock_quantity);

        $tx = StockTransaction::query()
            ->where('part_id', $oil->id)
            ->where('transaction_type', StockTransactionType::StocktakeAdjustment)
            ->where('reference_id', $applied->id)
            ->first();

        $this->assertNotNull($tx);
        $this->assertSame(-3, (int) $tx->quantity);
        $this->assertEqualsWithDelta(-3 * (float) $oil->cost_price, $applied->varianceValue(), 0.01);
    }

    public function test_cannot_apply_with_uncounted_lines(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $service = app(InventoryService::class);
        $stocktake = $service->createStocktake($admin->id);

        $this->expectException(DomainException::class);
        $service->applyStocktake($stocktake, $admin->id);
    }

    public function test_cannot_apply_twice(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $service = app(InventoryService::class);
        $stocktake = $service->createStocktake($admin->id);

        foreach ($stocktake->items as $item) {
            $item->update(['counted_quantity' => $item->system_quantity]);
        }

        $service->applyStocktake($stocktake->fresh(['items']), $admin->id);

        $this->expectException(DomainException::class);
        $service->applyStocktake($stocktake->fresh(), $admin->id);
    }

    public function test_stocktake_pages_are_admin_create_only(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $advisor = User::query()->where('email', 'sa@gara.vn')->firstOrFail();
        $tech = User::query()->where('email', 'tech1@gara.vn')->firstOrFail();

        $this->assertTrue($admin->can('viewAny', Stocktake::class));
        $this->assertTrue($admin->can('create', Stocktake::class));
        $this->assertTrue($advisor->can('viewAny', Stocktake::class));
        $this->assertFalse($advisor->can('create', Stocktake::class));
        $this->assertFalse($tech->can('viewAny', Stocktake::class));

        $this->actingAs($admin);
        $this->get('/admin/stocktakes')->assertOk();
        $this->get('/admin/stocktakes/create')->assertOk();
    }

    public function test_advisor_can_list_stocktakes_but_not_create(): void
    {
        $this->seed();

        $advisor = User::query()->where('email', 'sa@gara.vn')->firstOrFail();
        $this->actingAs($advisor);
        $this->get('/admin/stocktakes')->assertOk();
        $this->get('/admin/stocktakes/create')->assertForbidden();
    }

    public function test_technician_cannot_open_stocktakes(): void
    {
        $this->seed();

        $tech = User::query()->where('email', 'tech1@gara.vn')->firstOrFail();
        $this->actingAs($tech);
        $this->get('/admin/stocktakes')->assertForbidden();
    }
}
