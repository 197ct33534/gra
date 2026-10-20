<?php

namespace Tests\Feature;

use App\Enums\CustomerType;
use App\Filament\Resources\Customers\Pages\ListCustomers;
use App\Filament\Resources\Parts\Pages\ListParts;
use App\Models\Customer;
use App\Models\Part;
use App\Models\User;
use App\Services\CatalogImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CatalogImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_customers_upserts_by_phone(): void
    {
        $this->seed();

        $path = $this->tempCsv([
            ['name', 'phone', 'email', 'address', 'customer_type', 'tax_code', 'credit_limit', 'payment_terms_days'],
            ['Khách Import Mới', '0911002200', 'moi@test.vn', 'Q1', 'individual', '', '', '0'],
            ['Công ty ABC cập nhật', '0989991122', '', 'Q7', 'company', '0312345678', '5.000.000', '30'],
        ]);

        $result = app(CatalogImportService::class)->importCustomers($path);

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['skipped']);

        $this->assertDatabaseHas('customers', [
            'phone' => '0911002200',
            'name' => 'Khách Import Mới',
        ]);

        $company = Customer::query()->where('phone', '0989991122')->firstOrFail();
        $this->assertSame(CustomerType::Company, $company->customer_type);
        $this->assertSame('Công ty ABC cập nhật', $company->name);
        $this->assertEqualsWithDelta(5000000, (float) $company->credit_limit, 0.01);
        $this->assertSame(30, $company->payment_terms_days);
    }

    public function test_import_parts_creates_and_preserves_stock_on_update(): void
    {
        $this->seed();

        $existing = Part::query()->where('sku', 'RANGER-BRAKE-F')->firstOrFail();
        $beforeStock = (int) $existing->stock_quantity;

        $path = $this->tempCsv([
            ['sku', 'name', 'category', 'unit', 'cost_price', 'selling_price', 'stock_quantity', 'min_stock_alert'],
            ['RANGER-BRAKE-F', 'Má phanh Ranger (import)', 'Gầm & Phanh', 'bộ', '400000', '650000', '999', '3'],
            ['IMPORT-FILTER-X', 'Lọc gió import X', 'Hệ thống lọc', 'cái', '50000', '90000', '12', '4'],
        ]);

        $result = app(CatalogImportService::class)->importParts($path);

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['updated']);

        $existing->refresh();
        $this->assertSame('Má phanh Ranger (import)', $existing->name);
        $this->assertSame($beforeStock, (int) $existing->stock_quantity);
        $this->assertEqualsWithDelta(650000, (float) $existing->selling_price, 0.01);

        $created = Part::query()->where('sku', 'IMPORT-FILTER-X')->firstOrFail();
        $this->assertSame(12, (int) $created->stock_quantity);
        $this->assertSame('Hệ thống lọc', $created->category?->name);
    }

    public function test_import_skips_invalid_customer_rows(): void
    {
        $path = $this->tempCsv([
            ['name', 'phone'],
            ['Thiếu SĐT', ''],
            ['Sai SĐT', '123'],
        ]);

        $result = app(CatalogImportService::class)->importCustomers($path);

        $this->assertSame(0, $result['created']);
        $this->assertSame(2, $result['skipped']);
        $this->assertCount(2, $result['errors']);
    }

    public function test_list_pages_expose_import_actions(): void
    {
        $this->seed();
        $admin = User::query()->where('email', 'admin@gara.vn')->firstOrFail();
        $this->actingAs($admin);

        Livewire::test(ListCustomers::class)
            ->assertActionExists('importCustomers')
            ->assertActionExists('downloadCustomerTemplate');

        Livewire::test(ListParts::class)
            ->assertActionExists('importParts')
            ->assertActionExists('downloadPartTemplate');
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function tempCsv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'garagex-import-');
        $this->assertNotFalse($path);
        $out = fopen($path, 'w');
        fwrite($out, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);

        return $path;
    }
}
