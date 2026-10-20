<?php

namespace App\Support;

final class BodyInspection
{
    /**
     * @return array<string, string>
     */
    public static function zones(): array
    {
        return [
            'front_bumper' => 'Cản trước',
            'rear_bumper' => 'Cản sau',
            'hood' => 'Nắp capo',
            'roof' => 'Nóc xe',
            'trunk' => 'Cốp / cửa hậu',
            'left_fender' => 'Sườn / vè trái',
            'right_fender' => 'Sườn / vè phải',
            'left_doors' => 'Cửa trái',
            'right_doors' => 'Cửa phải',
            'windshield' => 'Kính chắn gió',
            'wheels' => 'Mâm / lốp',
            'mirrors' => 'Gương chiếu hậu',
            'lights' => 'Đèn trước/sau',
            'interior' => 'Nội thất',
            'undercarriage' => 'Gầm xe',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function conditions(): array
    {
        return [
            'ok' => 'Bình thường',
            'scratch' => 'Xước',
            'dent' => 'Móp / lõm',
            'crack' => 'Nứt / vỡ',
            'rust' => 'Gỉ / ăn mòn',
            'paint' => 'Tróc sơn / lệch màu',
            'missing' => 'Thiếu / mất chi tiết',
            'other' => 'Khác',
        ];
    }

    public static function zoneLabel(string $zone): string
    {
        return self::zones()[$zone] ?? $zone;
    }

    public static function conditionLabel(string $condition): string
    {
        return self::conditions()[$condition] ?? $condition;
    }

    /**
     * @param  list<array{zone?: string, condition?: string, note?: string}>|null  $rows
     * @return list<string>
     */
    public static function summaryLines(?array $rows): array
    {
        if ($rows === null || $rows === []) {
            return [];
        }

        $lines = [];
        foreach ($rows as $row) {
            $zone = self::zoneLabel((string) ($row['zone'] ?? ''));
            $condition = self::conditionLabel((string) ($row['condition'] ?? 'other'));
            $note = trim((string) ($row['note'] ?? ''));
            $lines[] = $zone.': '.$condition.($note !== '' ? ' — '.$note : '');
        }

        return $lines;
    }
}
