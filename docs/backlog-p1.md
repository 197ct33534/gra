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
| P1-06 | Import Excel khách/phụ tùng | ⏳ |
| P1-07 | Chấm công / giờ thợ thực tế | ⏳ |
| P1-08 | Báo cáo aging AR / P&L tháng | ⏳ |
| P1-09 | Thông báo Filament (trễ hẹn, tồn thấp) | ⏳ |

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
