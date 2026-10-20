<?php

namespace App\Services;

use App\Enums\CustomerType;
use App\Models\Customer;
use App\Models\Part;
use App\Models\PartCategory;
use App\Support\CsvSpreadsheet;
use App\Support\VietnameseMoney;
use DomainException;
use Illuminate\Support\Facades\DB;

class CatalogImportService
{
    /**
     * @return array{created: int, updated: int, skipped: int, errors: list<string>}
     */
    public function importCustomers(string $path): array
    {
        $rows = CsvSpreadsheet::readAssoc($path);
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $row) {
            $line = $row['_line'] ?? '?';

            try {
                $phone = $this->normalizePhone($row['phone'] ?? '');
                $name = trim((string) ($row['name'] ?? ''));

                if ($phone === '' || $name === '') {
                    throw new DomainException('Thiếu tên hoặc số điện thoại.');
                }

                if (! preg_match('/^(0|\+84)(3|5|7|8|9)\d{8}$/', $phone)) {
                    throw new DomainException('SĐT không hợp lệ: '.$phone);
                }

                $type = $this->parseCustomerType($row['customer_type'] ?? 'individual');
                $payload = [
                    'name' => $name,
                    'email' => filled($row['email'] ?? null) ? trim((string) $row['email']) : null,
                    'address' => filled($row['address'] ?? null) ? trim((string) $row['address']) : null,
                    'customer_type' => $type,
                    'tax_code' => filled($row['tax_code'] ?? null) ? trim((string) $row['tax_code']) : null,
                ];

                if (array_key_exists('credit_limit', $row) && trim((string) $row['credit_limit']) !== '') {
                    $payload['credit_limit'] = VietnameseMoney::parse($row['credit_limit']);
                }

                if (array_key_exists('payment_terms_days', $row) && trim((string) $row['payment_terms_days']) !== '') {
                    $payload['payment_terms_days'] = max(0, (int) $row['payment_terms_days']);
                }

                $existing = Customer::query()->where('phone', $phone)->first();

                if ($existing instanceof Customer) {
                    $existing->fill($payload);
                    $existing->save();
                    $updated++;
                } else {
                    Customer::query()->create([
                        ...$payload,
                        'phone' => $phone,
                        'payment_terms_days' => $payload['payment_terms_days'] ?? 0,
                    ]);
                    $created++;
                }
            } catch (DomainException $e) {
                $skipped++;
                $errors[] = "Dòng {$line}: ".$e->getMessage();
            } catch (\Throwable $e) {
                $skipped++;
                $errors[] = "Dòng {$line}: ".$e->getMessage();
            }
        }

        return compact('created', 'updated', 'skipped', 'errors');
    }

    /**
     * @return array{created: int, updated: int, skipped: int, errors: list<string>}
     */
    public function importParts(string $path): array
    {
        $rows = CsvSpreadsheet::readAssoc($path);
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $row) {
            $line = $row['_line'] ?? '?';

            try {
                DB::transaction(function () use ($row, &$created, &$updated): void {
                    $sku = trim((string) ($row['sku'] ?? ''));
                    $name = trim((string) ($row['name'] ?? ''));
                    $categoryName = trim((string) ($row['category'] ?? ''));

                    if ($sku === '' || $name === '') {
                        throw new DomainException('Thiếu SKU hoặc tên phụ tùng.');
                    }

                    if ($categoryName === '') {
                        throw new DomainException('Thiếu nhóm phụ tùng.');
                    }

                    $category = PartCategory::query()->firstOrCreate(
                        ['name' => $categoryName],
                        ['parent_id' => null],
                    );

                    $cost = VietnameseMoney::parse($row['cost_price'] ?? null);
                    $sell = VietnameseMoney::parse($row['selling_price'] ?? null);

                    if ($cost === null || $sell === null) {
                        throw new DomainException('Thiếu hoặc sai giá vốn/giá bán.');
                    }

                    $payload = [
                        'category_id' => $category->id,
                        'name' => $name,
                        'unit' => filled($row['unit'] ?? null) ? trim((string) $row['unit']) : 'cái',
                        'cost_price' => $cost,
                        'selling_price' => $sell,
                        'oem_code' => filled($row['oem_code'] ?? null) ? trim((string) $row['oem_code']) : null,
                        'shelf_location' => filled($row['shelf_location'] ?? null) ? trim((string) $row['shelf_location']) : null,
                        'min_stock_alert' => max(0, (int) ($row['min_stock_alert'] ?? 5)),
                    ];

                    if (array_key_exists('warranty_months', $row) && trim((string) $row['warranty_months']) !== '') {
                        $payload['warranty_months'] = max(0, (int) $row['warranty_months']);
                    }

                    if (array_key_exists('warranty_km', $row) && trim((string) $row['warranty_km']) !== '') {
                        $payload['warranty_km'] = max(0, (int) $row['warranty_km']);
                    }

                    $existing = Part::query()->where('sku', $sku)->first();

                    if ($existing instanceof Part) {
                        // Không đè tồn kho khi cập nhật — tránh phá số kiểm kê.
                        $existing->fill($payload);
                        $existing->save();
                        $updated++;
                    } else {
                        $stock = max(0, (int) ($row['stock_quantity'] ?? 0));
                        Part::query()->create([
                            ...$payload,
                            'sku' => $sku,
                            'stock_quantity' => $stock,
                            'reserved_quantity' => 0,
                        ]);
                        $created++;
                    }
                });
            } catch (DomainException $e) {
                $skipped++;
                $errors[] = "Dòng {$line}: ".$e->getMessage();
            } catch (\Throwable $e) {
                $skipped++;
                $errors[] = "Dòng {$line}: ".$e->getMessage();
            }
        }

        return compact('created', 'updated', 'skipped', 'errors');
    }

    /**
     * @return list<string>
     */
    public function customerTemplateHeaders(): array
    {
        return ['name', 'phone', 'email', 'address', 'customer_type', 'tax_code', 'credit_limit', 'payment_terms_days'];
    }

    /**
     * @return list<string>
     */
    public function partTemplateHeaders(): array
    {
        return [
            'sku', 'name', 'category', 'unit', 'cost_price', 'selling_price',
            'stock_quantity', 'min_stock_alert', 'oem_code', 'shelf_location',
            'warranty_months', 'warranty_km',
        ];
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[\s.\-]/', '', trim($phone)) ?? '';

        if (str_starts_with($phone, '84') && strlen($phone) === 11) {
            $phone = '0'.substr($phone, 2);
        }

        if (str_starts_with($phone, '+84')) {
            $phone = '0'.substr($phone, 3);
        }

        return $phone;
    }

    private function parseCustomerType(string $raw): CustomerType
    {
        $normalized = str_replace([' ', '-'], '_', mb_strtolower(trim($raw)));

        return match (true) {
            in_array($normalized, ['company', 'doanh_nghiep', 'dn', 'cty', 'cong_ty'], true) => CustomerType::Company,
            in_array($normalized, ['individual', 'ca_nhan', 'cn', 'khach_le', ''], true) => CustomerType::Individual,
            default => throw new DomainException('Loại khách không hợp lệ: '.$raw),
        };
    }
}
