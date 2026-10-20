# Backlog P5 — Tối ưu kho & vận hành sâu

> Sau P4. Mục tiêu: đề xuất mua hàng, việc của thợ, xuất kế toán, ví khách, chuyển kho CN.

## Tiến độ

| Ticket | Chủ đề | Status |
|--------|--------|--------|
| P5-01 | Đề xuất nhập hàng (tồn thấp → PO nháp) | ✅ |
| P5-02 | Việc của tôi (thợ) | ✅ |
| P5-03 | Xuất CSV sổ kế toán / VAT tháng | ✅ |
| P5-04 | Ví trả trước khách (deposit) | ✅ |
| P5-05 | Điều chuyển kho liên chi nhánh | ✅ |

---

### P5-01 Reorder
Trang `ReorderSuggestions`: `available <= min_stock` → chọn NCC → tạo PO Draft.

### P5-02 My Jobs
Filament page `TechnicianMyJobs`: RO đang mở; thợ chỉ thấy lệnh có hạng mục gán mình.

### P5-03 Accounting export
`AccountingExportService` + nút CSV trên `FinancialReports` (bán hàng / mua hàng theo tháng).

### P5-04 Wallet
`customers.wallet_balance` + `customer_wallet_transactions`; nạp ví trên KH; trừ ví trên HĐ còn nợ.

### P5-05 Stock transfer
SKU unique theo `(branch_id, sku)`; phiếu `stock_transfers` nháp → hoàn tất trừ/cộng tồn + thẻ kho TransferOut/In.
