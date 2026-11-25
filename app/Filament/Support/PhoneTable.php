<?php

namespace App\Filament\Support;

use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * Bảng danh sách trên điện thoại (< 768px), dùng chung cho mọi bảng Filament:
 *
 * - Cột phụ: gọi ->visibleFrom('md') để ẩn trên điện thoại.
 * - Thông tin của các cột bị ẩn: ghi gọn dưới cột chính bằng PhoneTable::summary() (đặt vào ->description()).
 * - Nút trên dòng: PhoneTable::actions(fn () => [...]) — máy tính thấy nút rời như cũ, điện thoại
 *   thấy một nút ⋮ 'Thao tác' chứa đúng các nút đó.
 *
 * CSS (.phone-only / .act-inline / .act-more) nạp cho toàn panel: resources/views/filament/phone-table-styles.blade.php.
 */
class PhoneTable
{
    /**
     * Dòng phụ chỉ hiện trên điện thoại. Phần tử chuỗi được escape; HtmlString giữ nguyên (VD chữ đỏ 'Trễ hẹn').
     * Phần tử rỗng/null bị bỏ qua.
     *
     * @param  array<int, string|Htmlable|null>  $parts
     */
    public static function summary(array $parts): Htmlable
    {
        $html = collect($parts)
            ->filter(fn (mixed $part): bool => $part instanceof Htmlable ? $part->toHtml() !== '' : filled($part))
            ->map(fn (string|Htmlable $part): string => $part instanceof Htmlable ? $part->toHtml() : e($part))
            ->implode(' · ');

        return new HtmlString($html === '' ? '' : '<span class="phone-only">'.$html.'</span>');
    }

    /**
     * Nút trên dòng: bản rời (class 'act-inline', ẩn trên điện thoại) + nhóm ⋮ (class 'act-more', chỉ hiện trên
     * điện thoại). $make được gọi hai lần để có hai bộ action riêng; bản trong nhóm đổi tên thêm hậu tố 'Phone'
     * để hai bộ dùng chung một bảng không đè nhau.
     *
     * @param  Closure(): list<Action>  $make
     * @return list<Action|ActionGroup>
     */
    public static function actions(Closure $make): array
    {
        $inline = array_map(
            fn (Action $action): Action => $action->extraAttributes(['class' => 'act-inline'], merge: true),
            $make(),
        );

        $grouped = array_map(
            fn (Action $action): Action => $action->name($action->getName().'Phone'),
            $make(),
        );

        return [
            ...$inline,
            ActionGroup::make($grouped)
                ->label('Thao tác')
                ->tooltip('Thao tác')
                ->icon(Heroicon::EllipsisVertical)
                ->iconButton()
                ->color('gray')
                ->extraAttributes(['class' => 'act-more']),
        ];
    }
}
