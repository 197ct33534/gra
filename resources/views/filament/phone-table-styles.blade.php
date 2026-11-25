{{--
    Bảng trên điện thoại (< 768px), áp cho toàn panel. Đi kèm App\Filament\Support\PhoneTable:
    - .phone-only : dòng phụ dưới cột chính, chỉ hiện trên điện thoại.
    - .act-inline : nút rời trên dòng, ẩn trên điện thoại.
    - .act-more   : nút ⋮ 'Thao tác', chỉ hiện trên điện thoại.
    - .hide-phone : cột (th/td) của bảng HTML tự làm, ẩn trên điện thoại.
--}}
<style>
    .phone-only { display: none; }
    .act-more { display: none !important; }

    @media (max-width: 767px) {
        .phone-only { display: block; font-size: 13px; line-height: 1.45; white-space: normal; min-width: 150px; }
        .act-inline { display: none !important; }
        .act-more { display: inline-flex !important; }
        .hide-phone { display: none !important; }

        /* Cột chính (ô có dòng phụ) được xuống dòng, để tên dài không đẩy bảng rộng quá màn hình. */
        .fi-ta-cell:has(.phone-only) { max-width: 50vw; }
        .fi-ta-cell:has(.phone-only) .fi-ta-text,
        .fi-ta-cell:has(.phone-only) .fi-ta-text-item,
        .fi-ta-cell:has(.phone-only) .fi-ta-text-item-label { white-space: normal; overflow-wrap: anywhere; }

        /* Tiêu đề cột dài (VD 'Giá theo bảng giá') được xuống dòng. */
        .fi-ta-header-cell { white-space: normal; }
        .fi-ta-header-cell .fi-ta-header-cell-label, .fi-ta-header-cell button { white-space: normal; text-align: inherit; }

        /* Ô tích chọn để xoá hàng loạt: ẩn trên điện thoại (dễ bấm nhầm, chiếm chỗ); máy tính vẫn có. */
        .fi-ta-selection-cell { display: none !important; }

        /* Nút chữ đơn lẻ trên dòng (VD 'Sửa'): chỉ còn biểu tượng; chữ vẫn còn cho trình đọc màn hình. */
        .fi-ta-actions .fi-ac-link-action { font-size: 0; gap: 0; }
        .fi-ta-actions .fi-ac-link-action > .fi-icon { width: 1.25rem; height: 1.25rem; }
    }
</style>
