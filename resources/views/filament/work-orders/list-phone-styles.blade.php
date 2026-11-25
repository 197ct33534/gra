{{--
    Danh sách Lệnh sửa chữa trên điện thoại: chỉ giữ Biển số / Trạng thái / nút ⋮ (các cột khác dùng
    ->visibleFrom('md')); mã lệnh, khách, tiền, giờ hẹn ghi dưới biển số; các nút trên dòng gom vào nút ⋮.
--}}
<style>
    .wo-phone-only { display: none; }
    .wo-act-more { display: none !important; }

    @media (max-width: 767px) {
        .wo-phone-only { display: block; font-size: 13px; line-height: 1.45; white-space: normal; min-width: 150px; }
        .wo-act-inline { display: none !important; }
        .wo-act-more { display: inline-flex !important; }
    }
</style>
