<?php

namespace Tests\Feature;

use App\Enums\WorkOrderItemSource;
use App\Filament\Resources\Parts\Pages\CreatePart;
use App\Filament\Resources\Parts\Pages\EditPart;
use App\Filament\Resources\Vehicles\Pages\CreateVehicle;
use App\Filament\Resources\Vehicles\Pages\ListVehicles;
use App\Filament\Resources\WorkOrders\Pages\ListWorkOrders;
use App\Helpers\LicensePlateHelper;
use App\Models\Customer;
use App\Models\Part;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Services\WorkOrderPrintService;
use App\Support\VietnameseMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SprintP0WorkshopUxTest extends TestCase
{
    use RefreshDatabase;

    public function test_license_plate_clean_strips_separators_and_uppercases(): void
    {
        $this->assertSame('51F12345', LicensePlateHelper::clean('51f-123.45'));
        $this->assertSame('51F12345', LicensePlateHelper::clean('51f12345'));
        $this->assertSame('51F12345', LicensePlateHelper::clean('51F 123.45'));
        $this->assertSame('', LicensePlateHelper::clean(null));
    }

    public function test_vehicle_and_work_order_search_ignore_plate_separators(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $vios = Vehicle::query()->where('license_plate', '51K-123.45')->firstOrFail();
        $ranger = Vehicle::query()->where('license_plate', '29C-555.88')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(ListVehicles::class)
            ->searchTable('51k12345')
            ->assertCanSeeTableRecords([$vios])
            ->assertCanNotSeeTableRecords([$ranger])
            ->searchTable('51K 123')
            ->assertCanSeeTableRecords([$vios])
            ->assertCanNotSeeTableRecords([$ranger]);

        Livewire::actingAs($admin)
            ->test(ListVehicles::class)
            ->searchTable($vios->customer->phone)
            ->assertCanSeeTableRecords([$vios]);

        $viosOrder = WorkOrder::query()->where('code', 'RO-20261019-001')->firstOrFail();
        $rangerOrder = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(ListWorkOrders::class)
            ->searchTable('29c55588')
            ->assertCanSeeTableRecords([$rangerOrder])
            ->assertCanNotSeeTableRecords([$viosOrder]);

        $results = LicensePlateHelper::applyVehicleSearch(Vehicle::query(), '51k-12345')->pluck('license_plate')->all();
        $this->assertSame(['51K-123.45'], $results);
    }

    public function test_duplicate_plate_with_different_separators_is_rejected(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $customer = Customer::query()->firstOrFail();

        Livewire::actingAs($admin)
            ->test(CreateVehicle::class)
            ->fillForm([
                'customer_id' => $customer->id,
                'license_plate' => '51k 12345',
                'brand' => 'Toyota',
                'model' => 'Vios',
                'current_odo' => 1000,
            ])
            ->call('create')
            ->assertHasFormErrors(['license_plate']);

        $this->assertSame(1, Vehicle::query()->whereRaw(LicensePlateHelper::cleanedColumnSql('license_plate').' = ?', ['51K12345'])->count());
    }

    public function test_job_card_shows_workshop_info_and_never_money(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $order = WorkOrder::query()->where('code', 'RO-20261019-002')->firstOrFail();
        $order->items()->where('item_type', 'part')->update([
            'item_source' => WorkOrderItemSource::DirectPurchase->value,
            'supplier_info' => 'Chợ Kim Biên',
            'cost_price' => 500000,
        ]);

        $data = app(WorkOrderPrintService::class)->jobCardData($order->refresh());
        $html = view('pdf.job_card', $data)->render();

        $this->assertStringContainsString('PHIẾU GIAO VIỆC', $html);
        $this->assertStringContainsString('RO-20261019-002', $html);
        $this->assertStringContainsString('Cầu 3', $html);
        $this->assertStringContainsString('29C-555.88', $html);
        $this->assertStringContainsString('Ford Ranger', $html);
        $this->assertStringContainsString('Đạp phanh có tiếng kêu, đề nghị kiểm tra và thay má phanh trước.', $html);
        $this->assertStringContainsString('HẸN GIAO XE: 17:00', $html);
        $this->assertStringContainsString('Mua ngoài', $html);
        $this->assertStringContainsString('Chợ Kim Biên', $html);
        $this->assertStringContainsString('Gara làm', $html);

        // Không đơn giá, không tiền công, không ký hiệu tiền, không số dạng 750.000.
        $this->assertDoesNotMatchRegularExpression('/\d\s*(đ|₫|VND|vnđ)/iu', strip_tags($html));
        $this->assertStringNotContainsString('Đơn giá', $html);
        $this->assertStringNotContainsString('Thành tiền', $html);
        $this->assertDoesNotMatchRegularExpression('/\d{1,3}(\.\d{3})+(?! km)/u', str_replace($data['odo'], '', strip_tags($html)));

        $this->actingAs($admin)
            ->get(route('work-orders.job-card', $order))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_money_inputs_accept_thousand_separators_and_show_them_back(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $category = Part::query()->firstOrFail()->category_id;

        Livewire::actingAs($admin)
            ->test(CreatePart::class)
            ->fillForm([
                'sku' => 'MA-PHANH-TEST',
                'name' => 'Má phanh thử',
                'category_id' => $category,
                'unit' => 'Bộ',
                'cost_price' => '1.250.000',
                'selling_price' => '1.500.000',
                'stock_quantity' => 2,
                'min_stock_alert' => 1,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $part = Part::query()->where('sku', 'MA-PHANH-TEST')->firstOrFail();
        $this->assertSame('1250000.00', $part->cost_price);
        $this->assertSame('1500000.00', $part->selling_price);

        Livewire::actingAs($admin)
            ->test(EditPart::class, ['record' => $part->getRouteKey()])
            ->assertSchemaStateSet(['selling_price' => 1500000])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('1500000.00', $part->refresh()->selling_price);
        $this->assertSame('1.500.000 đ', VietnameseMoney::format(1500000));
        $this->assertSame(1500000, VietnameseMoney::parse('1.500.000'));
        $this->assertSame(150000, VietnameseMoney::parse('150000.00'));
    }

    public function test_admin_ui_is_vietnamese(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();

        $this->assertSame('vi', app()->getLocale());

        $this->actingAs($admin)
            ->get('/admin/parts')
            ->assertOk()
            ->assertSee('Thêm mới')
            ->assertSee('Tìm kiếm...')
            ->assertSee('Đang giữ cho xe')
            ->assertSee('180.000 đ')
            ->assertDontSee('₫');

        $this->actingAs($admin)
            ->get('/admin/parts/create')
            ->assertOk()
            ->assertSee('Thêm mới')
            ->assertDontSeeText('Create');
    }
}
