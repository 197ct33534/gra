<?php

namespace App\Support;

use DomainException;
use Illuminate\Support\Str;

final class CsvSpreadsheet
{
    /**
     * @return list<array<string, string>>
     */
    public static function readAssoc(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new DomainException('Không đọc được file import.');
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new DomainException('Không mở được file import.');
        }

        try {
            $firstLine = fgets($handle);
            if ($firstLine === false) {
                return [];
            }

            // Bỏ BOM UTF-8 nếu Excel/Google Sheets xuất kèm.
            if (str_starts_with($firstLine, "\xEF\xBB\xBF")) {
                $firstLine = substr($firstLine, 3);
            }

            $delimiter = self::detectDelimiter($firstLine);
            $headers = str_getcsv($firstLine, $delimiter);
            $headers = array_map(fn ($h): string => self::normalizeHeader((string) $h), $headers);

            if ($headers === [] || $headers[0] === '') {
                throw new DomainException('File thiếu dòng tiêu đề cột.');
            }

            $rows = [];
            $lineNo = 1;

            while (($cols = fgetcsv($handle, 0, $delimiter)) !== false) {
                $lineNo++;

                if (self::rowEmpty($cols)) {
                    continue;
                }

                $assoc = [];
                foreach ($headers as $i => $header) {
                    if ($header === '') {
                        continue;
                    }
                    $assoc[$header] = isset($cols[$i]) ? trim((string) $cols[$i]) : '';
                }

                $assoc['_line'] = (string) $lineNo;
                $rows[] = $assoc;
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string|int|float|null>>  $rows
     */
    public static function streamDownload(string $filename, array $headers, array $rows = []): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public static function normalizeHeader(string $header): string
    {
        $header = trim($header);
        $header = Str::lower($header);
        $header = str_replace([' ', '-', '.'], '_', $header);

        $aliases = [
            'ho_ten' => 'name',
            'ten' => 'name',
            'ten_khach' => 'name',
            'so_dien_thoai' => 'phone',
            'sdt' => 'phone',
            'dien_thoai' => 'phone',
            'dia_chi' => 'address',
            'loai_khach' => 'customer_type',
            'loai' => 'customer_type',
            'ma_so_thue' => 'tax_code',
            'mst' => 'tax_code',
            'han_muc' => 'credit_limit',
            'han_muc_cong_no' => 'credit_limit',
            'ky_han' => 'payment_terms_days',
            'ky_han_ngay' => 'payment_terms_days',
            'ma_sku' => 'sku',
            'ma' => 'sku',
            'ten_phu_tung' => 'name',
            'nhom' => 'category',
            'nhom_phu_tung' => 'category',
            'don_vi' => 'unit',
            'dvt' => 'unit',
            'gia_von' => 'cost_price',
            'gia_ban' => 'selling_price',
            'ton' => 'stock_quantity',
            'ton_kho' => 'stock_quantity',
            'nguong_canh_bao' => 'min_stock_alert',
            'canh_bao_ton' => 'min_stock_alert',
            'ma_oem' => 'oem_code',
            'oem' => 'oem_code',
            'vi_tri' => 'shelf_location',
            'ke' => 'shelf_location',
            'bh_thang' => 'warranty_months',
            'bao_hanh_thang' => 'warranty_months',
            'bh_km' => 'warranty_km',
            'bao_hanh_km' => 'warranty_km',
        ];

        return $aliases[$header] ?? $header;
    }

    private static function detectDelimiter(string $line): string
    {
        $comma = substr_count($line, ',');
        $semi = substr_count($line, ';');
        $tab = substr_count($line, "\t");

        if ($semi > $comma && $semi >= $tab) {
            return ';';
        }
        if ($tab > $comma && $tab >= $semi) {
            return "\t";
        }

        return ',';
    }

    /**
     * @param  list<string|null>|false  $cols
     */
    private static function rowEmpty(array|false $cols): bool
    {
        if ($cols === false || $cols === []) {
            return true;
        }

        foreach ($cols as $col) {
            if (trim((string) $col) !== '') {
                return false;
            }
        }

        return true;
    }
}
