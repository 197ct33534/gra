# Backlog P2 — Trải nghiệm khách & giữ chân

> Sau P1. Mục tiêu: khách theo dõi RO, feedback, điểm thành viên, nhắc giấy tờ xe.

## Tiến độ

| Ticket | Chủ đề | Status |
|--------|--------|--------|
| P2-01 | Portal trạng thái RO (link token) | ✅ |
| P2-02 | Hạn giấy tờ xe (ĐKXD / BH / đăng kiểm) | ✅ |
| P2-03 | Feedback / NPS sau bàn giao | ✅ |
| P2-04 | Điểm loyalty cơ bản | ✅ |
| P2-05 | Portal đặt lịch công khai | ✅ |
| P2-06 | Đa chi nhánh (scaffold) | ✅ |

---

### P2-01 Status portal
Token công khai: khách xem tiến độ RO (không hiện tiền nếu chưa duyệt BG).

### P2-02 Vehicle documents
`registration_expires_at`, `insurance_expires_at`, `inspection_expires_at` + widget/alert.

### P2-03 Feedback
Sau bàn giao: link đánh giá 1–5 + nhận xét; danh sách Filament.

### P2-04 Loyalty
Cộng điểm khi bàn giao (theo doanh thu); xem trên khách; trừ điểm thủ công (admin).

### P2-05 Public booking
Form đặt lịch không cần đăng nhập (throttle) → Appointment Scheduled.

### P2-06 Multi-branch
Bảng `branches` + `branch_id` nullable trên RO/kho (scaffold, chưa tách dữ liệu cứng).
