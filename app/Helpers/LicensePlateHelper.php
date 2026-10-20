<?php

namespace App\Helpers;

use Illuminate\Database\Eloquent\Builder;

final class LicensePlateHelper
{
    /**
     * "51f-123.45", "51f12345", "51F 123.45" đều về "51F12345".
     */
    public static function clean(?string $plate): string
    {
        return preg_replace('/[^A-Z0-9]/', '', mb_strtoupper((string) $plate)) ?? '';
    }

    /**
     * Biểu thức SQL bỏ dấu chấm, gạch ngang, khoảng trắng của cột biển số để so với chuỗi đã clean().
     */
    public static function cleanedColumnSql(string $column): string
    {
        return "replace(replace(replace(replace(upper({$column}), '-', ''), '.', ''), ' ', ''), '_', '')";
    }

    /**
     * Tìm xe theo biển số (bỏ qua ký tự đặc biệt) hoặc theo số điện thoại/tên chủ xe.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function applyVehicleSearch(Builder $query, string $search, string $plateColumn = 'vehicles.license_plate'): Builder
    {
        $plate = self::clean($search);
        $phone = preg_replace('/\D/', '', $search) ?? '';
        $name = trim($search);

        return $query->where(function (Builder $query) use ($plate, $phone, $name, $plateColumn): void {
            if ($plate !== '') {
                $query->orWhereRaw(self::cleanedColumnSql($plateColumn).' like ?', ['%'.$plate.'%']);
            }

            $query->orWhereHas('customer', function (Builder $query) use ($phone, $name): void {
                $query->where(function (Builder $query) use ($phone, $name): void {
                    if (strlen($phone) >= 3) {
                        $query->orWhere('phone', 'like', '%'.$phone.'%');
                    }

                    if ($name !== '') {
                        $query->orWhere('name', 'like', '%'.$name.'%');
                    }
                });
            });
        });
    }
}
