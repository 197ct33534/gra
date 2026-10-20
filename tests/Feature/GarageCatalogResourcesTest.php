<?php

namespace Tests\Feature;

use App\Enums\CustomerType;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Parts\Pages\ListParts;
use App\Filament\Resources\Vehicles\Pages\ListVehicles;
use App\Models\Customer;
use App\Models\Part;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GarageCatalogResourcesTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_resources_render_seeded_garage_data(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $customer = Customer::query()->where('phone', '0901234567')->firstOrFail();
        $cabinFilter = Part::query()->where('sku', 'LOC-GIO-CX5')->firstOrFail();
        $oilFilter = Part::query()->where('sku', 'LOC-NHOT-VIOS')->firstOrFail();

        $this->actingAs($admin);

        $this->get('/admin/customers')
            ->assertOk()
            ->assertSee('Anh Hoàng')
            ->assertSee('0901234567')
            ->assertSee('Cá nhân');

        $this->get('/admin/customers/'.$customer->getKey())
            ->assertOk()
            ->assertSee('51K-123.45')
            ->assertSee('Toyota Vios');

        $this->get('/admin/vehicles')
            ->assertOk()
            ->assertSee('51K-123.45')
            ->assertSee('30H-987.65')
            ->assertSee('Anh Hoàng');

        $this->get('/admin/parts')
            ->assertOk()
            ->assertSee('LOC-GIO-CX5')
            ->assertSee('Lọc gió điều hòa CX-5')
            ->assertSee('180.000');

        $this->get('/admin/parts/'.$oilFilter->getKey().'/edit')
            ->assertOk()
            ->assertSee('04152-YZZA6');

        $this->get('/admin/parts/'.$cabinFilter->getKey().'/edit')
            ->assertOk()
            ->assertSee('B2-04');

        $this->get('/admin/services')
            ->assertOk()
            ->assertSee('BD-5K')
            ->assertSee('Bảo dưỡng cấp 5.000 km')
            ->assertSee('150.000');
    }

    public function test_table_search_and_filters_match_garage_rules(): void
    {
        $this->seed();

        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $hoang = Customer::query()->where('phone', '0901234567')->firstOrFail();
        $company = Customer::query()->where('phone', '0989991122')->firstOrFail();
        $vios = Vehicle::query()->where('license_plate', '51K-123.45')->firstOrFail();
        $cx5 = Vehicle::query()->where('license_plate', '30H-987.65')->firstOrFail();
        $cabinFilter = Part::query()->where('sku', 'LOC-GIO-CX5')->firstOrFail();
        $castrol = Part::query()->where('sku', 'CAST-5W30')->firstOrFail();

        $this->actingAs($admin);

        Livewire::test(ListCustomers::class)
            ->filterTable('customer_type', CustomerType::Company)
            ->assertCanSeeTableRecords([$company])
            ->assertCanNotSeeTableRecords([$hoang]);

        Livewire::test(ListVehicles::class)
            ->searchTable('0918889999')
            ->assertCanSeeTableRecords([$cx5])
            ->assertCanNotSeeTableRecords([$vios])
            ->searchTable('51K-123.45')
            ->assertCanSeeTableRecords([$vios])
            ->assertCanNotSeeTableRecords([$cx5]);

        Livewire::test(ListParts::class)
            ->filterTable('low_stock', true)
            ->assertCanSeeTableRecords([$cabinFilter])
            ->assertCanNotSeeTableRecords([$castrol]);
    }
}
