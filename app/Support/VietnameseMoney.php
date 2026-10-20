<?php

namespace App\Support;

final class VietnameseMoney
{
    /**
     * 1500000 → "1.500.000 đ".
     */
    public static function format(int|float|string|null $amount): string
    {
        return number_format((float) $amount, 0, ',', '.').' đ';
    }

    /**
     * Đọc số tiền từ ô nhập: nhận cả "150000.00" (giá trị DB) lẫn "1.500.000" (đã định dạng).
     */
    public static function parse(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (int) round($value);
        }

        $value = trim((string) $value);

        if (preg_match('/^-?\d+(\.\d{1,2})?$/', $value) === 1) {
            return (int) round((float) $value);
        }

        $digits = preg_replace('/\D/', '', $value) ?? '';

        if ($digits === '') {
            return null;
        }

        return str_starts_with($value, '-') ? -(int) $digits : (int) $digits;
    }

    public static function spell(int|float|string|null $amount): string
    {
        $number = (int) round((float) $amount);

        if ($number === 0) {
            return 'Không đồng';
        }

        if ($number < 0) {
            return 'Âm '.self::capitalize(self::read(abs($number))).' đồng';
        }

        return self::capitalize(self::read($number)).' đồng';
    }

    private static function read(int $number): string
    {
        $scales = ['', 'nghìn', 'triệu', 'tỷ', 'nghìn tỷ', 'triệu tỷ'];
        $groups = [];

        while ($number > 0) {
            $groups[] = $number % 1000;
            $number = intdiv($number, 1000);
        }

        $parts = [];
        $count = count($groups);

        for ($index = $count - 1; $index >= 0; $index--) {
            if ($groups[$index] === 0) {
                continue;
            }

            $words = self::readGroup($groups[$index], $index < $count - 1);
            $scale = $scales[$index] ?? '';
            $parts[] = trim($words.' '.$scale);
        }

        return implode(' ', $parts);
    }

    private static function readGroup(int $number, bool $forceZeroHundred): string
    {
        $digits = ['không', 'một', 'hai', 'ba', 'bốn', 'năm', 'sáu', 'bảy', 'tám', 'chín'];
        $hundreds = intdiv($number, 100);
        $tens = intdiv($number % 100, 10);
        $ones = $number % 10;
        $words = [];

        if ($hundreds > 0) {
            $words[] = $digits[$hundreds].' trăm';
        } elseif ($forceZeroHundred && ($tens > 0 || $ones > 0)) {
            $words[] = 'không trăm';
        }

        if ($tens > 1) {
            $words[] = $digits[$tens].' mươi';

            if ($ones === 1) {
                $words[] = 'mốt';
            } elseif ($ones === 4) {
                $words[] = 'tư';
            } elseif ($ones === 5) {
                $words[] = 'lăm';
            } elseif ($ones > 0) {
                $words[] = $digits[$ones];
            }
        } elseif ($tens === 1) {
            $words[] = 'mười';

            if ($ones === 5) {
                $words[] = 'lăm';
            } elseif ($ones > 0) {
                $words[] = $digits[$ones];
            }
        } elseif ($ones > 0) {
            if ($hundreds > 0 || $forceZeroHundred) {
                $words[] = 'lẻ';
            }

            $words[] = $digits[$ones];
        }

        return implode(' ', $words);
    }

    private static function capitalize(string $words): string
    {
        $first = mb_strtoupper(mb_substr($words, 0, 1));

        return $first.mb_substr($words, 1);
    }
}
