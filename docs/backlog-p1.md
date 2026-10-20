# Backlog P1 — Ổn định vận hành

> Sau P0 go-live. Mục tiêu: bảo hiểm chuyên sâu, fleet, duyệt BG từ xa, hoàn tiền, catalog UI.

## Tiến độ

| Ticket | Chủ đề | Status |
|--------|--------|--------|
| P1-01 | PartCategory CRUD Filament | ✅ |
| P1-02 | Hồ sơ bồi thường BH (số HS, giám định, hạn chi, chứng từ) | ✅ |
| P1-03 | Hợp đồng fleet / hạn mức công nợ công ty | ✅ |
| P1-04 | Duyệt báo giá từ xa (link token) | ✅ |
| P1-05 | Credit note / hoàn tiền sau bàn giao | ✅ |
| P1-06 | Import Excel khách/phụ tùng | ✅ |
| P1-07 | Chấm công / giờ thợ thực tế | ✅ |
| P1-08 | Báo cáo aging AR / P&L tháng | ✅ |
| P1-09 | Thông báo Filament (trễ hẹn, tồn thấp) | ✅ |

---

### P1-01 PartCategory
CRUD nhóm phụ tùng (parent/child).

### P1-02 Insurance claim pack
Thêm trên RO bảo hiểm: `insurance_claim_no`, `insurance_adjuster`, `insurance_expected_pay_at`, media `insurance_docs`.

### P1-03 Fleet
Bảng `fleet_contracts` + `customers.credit_limit`; cảnh báo khi công nợ vượt hạn mức.

### P1-04 Remote quote approval
Token công khai: khách mở link → xem BG tóm tắt → Duyệt / Từ chối.

### P1-05 Credit note
Hoàn tiền một phần/toàn bộ hóa đơn đã thu; hoàn kho tùy chọn.

### P1-06 Import CSV khách / phụ tùng
Nút mẫu + import trên danh sách khách & phụ tùng (Excel lưu CSV UTF-8). Upsert theo SĐT / SKU; cập nhật PT không đè tồn.

### P1-07 Chấm công
Trang Chấm công: start/stop theo RO; cộng `actual_minutes` vào hạng mục; hiện trên bảng lương khoán.

### P1-08 Aging AR & P&L
Trang báo cáo: aging 0–30 / 31–60 / 61–90 / 90+ và P&L tháng theo xe đã bàn giao.

### P1-09 Thông báo Filament
Database notifications + widget RO trễ hẹn; lệnh `garage:dispatch-alerts` (mỗi 30 phút).
