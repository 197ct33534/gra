# Backlog P0 — Go-live gara thực tế

> Mục tiêu: đủ pháp lý, kho, liên lạc khách, lịch hẹn và hạ tầng STG trước khi mở vận hành thật.  
> Tech hiện tại: Laravel 12 + Filament v5 + DomPDF + SePay webhook + messaging driver `log`.  
> Quy ước ticket: `P0-{nhóm}-{số}` · Story point ước lượng (1–8) · Owner gợi ý theo role.

---

## Bản đồ nhóm P0

| Nhóm | Chủ đề | Ticket |
|------|--------|--------|
| A | Hóa đơn điện tử (HĐĐT) | P0-A-01 → P0-A-04 |
| B | Zalo ZNS / SMS thật | P0-B-01 → P0-B-04 |
| C | NCC + Đơn mua + Nhập kho | P0-C-01 → P0-C-05 |
| D | Kiểm kê kho | P0-D-01 → P0-D-03 |
| E | Bảo hành theo kỳ hạn | P0-E-01 → P0-E-04 |
| F | Đặt lịch hẹn | P0-F-01 → P0-F-04 |
| G | Sổ quỹ & báo cáo VAT | P0-G-01 → P0-G-04 |
| H | STG / CI / Backup | P0-H-01 → P0-H-04 |

**Thứ tự khuyến nghị:** H (hạ tầng song song) → C → D → A → G → B → E → F.

---

## A. Hóa đơn điện tử (HĐĐT)

### P0-A-01 — Chọn nhà cung cấp HĐĐT & adapter

**User story**  
Là *chủ gara*, tôi muốn hệ thống xuất được hóa đơn điện tử hợp lệ theo quy định VN, để không phải nhập tay trên portal thuế/NCC.

**Phạm vi**
- Interface `EInvoiceGateway` (mirror pattern `MessageGateway`).
- Driver đầu tiên: 1 trong EasyInvoice / Viettel S-Invoice / FPT.eInvoice / MISA meInvoice (chọn theo hợp đồng gara).
- Config: `config/garage.php` → `einvoice.*` + env.

**Acceptance**
- [ ] `GARAGE_EINVOICE_DRIVER=log` chạy giả lập (ghi payload + số HĐ giả).
- [ ] Driver thật gọi API tạo/hủy/tra cứu; lỗi trả message tiếng Việt rõ.
- [ ] Feature test với fake HTTP client.

**DB cần thêm:** không (chỉ config).

**SP:** 5 · **Owner:** Backend

---

### P0-A-02 — Mapping hóa đơn nội bộ → HĐĐT

**User story**  
Là *cố vấn / kế toán*, khi hóa đơn nội bộ đã thu đủ (hoặc theo chính sách xuất), tôi muốn nhấn “Xuất HĐĐT” để hệ thống gửi đúng MST, hàng hóa, VAT.

**Phạm vi**
- Action Filament trên `InvoiceResource` / View WorkOrder.
- Map dòng từ `work_order_items` (part/service) → dòng HĐĐT.
- Khách cá nhân vs công ty (`tax_code`, địa chỉ).
- Payer bảo hiểm: xuất riêng theo `payer_type`.

**Acceptance**
- [ ] Chỉ xuất khi `payment_status` thỏa rule cấu hình (mặc định: đã thanh toán đủ *hoặc* admin override).
- [ ] Không double-issue: 1 invoice nội bộ → tối đa 1 HĐĐT active.
- [ ] Lưu số HĐĐT, ký hiệu, mã CQT, link tra cứu, PDF/XML path.

**DB cần thêm**

```text
invoices
  + einvoice_status          string(20)  nullable  # pending|issued|canceled|failed
  + einvoice_number          string(50)  nullable  # số HĐĐT nhà cung cấp
  + einvoice_series          string(20)  nullable  # ký hiệu (VD: C26T)
  + einvoice_template        string(20)  nullable
  + einvoice_provider        string(30)  nullable
  + einvoice_provider_id     string(100) nullable  # id bên NCC
  + einvoice_tax_authority_code string(50) nullable
  + einvoice_lookup_url      string(500) nullable
  + einvoice_issued_at       timestamp   nullable
  + einvoice_canceled_at     timestamp   nullable
  + einvoice_error           text        nullable
  + einvoice_payload         json        nullable  # request/response tóm tắt

UNIQUE (einvoice_provider, einvoice_provider_id) WHERE not null
INDEX (einvoice_status)
```

**SP:** 8 · **Owner:** Backend + Kế toán nghiệp vụ

---

### P0-A-03 — Hủy / điều chỉnh HĐĐT

**User story**  
Là *admin*, khi xuất sai tôi muốn hủy hoặc lập biên bản điều chỉnh theo quy trình NCC, và hệ thống ghi lại audit.

**Acceptance**
- [ ] Action “Hủy HĐĐT” chỉ admin; bắt buộc lý do.
- [ ] Ghi `ActivityLog` event mới `einvoice_canceled`.
- [ ] Invoice nội bộ không bị xóa; chỉ đổi `einvoice_status`.

**DB:** dùng cột A-02 + enum `ActivityEvent::EInvoiceCanceled` (nếu chưa có).

**SP:** 5 · **Owner:** Backend

---

### P0-A-04 — In/gửi bản thể hiện HĐĐT cho khách

**User story**  
Là *cố vấn*, sau khi xuất HĐĐT tôi muốn in PDF hoặc gửi link tra cứu cho khách qua Zalo/SMS.

**Acceptance**
- [ ] Nút tải PDF/XML từ storage hoặc URL NCC.
- [ ] Queue message type `einvoice_link` (phụ thuộc nhóm B).

**DB**

```text
customer_messages.type  bổ sung giá trị: einvoice_link
# (enum CustomerMessageType — không đổi schema nếu dùng string)
```

**SP:** 3 · **Owner:** Backend

---

## B. Zalo ZNS / SMS thật

### P0-B-01 — Driver Zalo ZNS (hoặc SMS brandname)

**User story**  
Là *chủ gara*, tôi muốn tin nhắc bảo dưỡng và báo thanh toán gửi thật tới SĐT khách, không chỉ ghi log.

**Phạm vi**
- Implement `ZaloZnsGateway` và/hoặc `SmsBrandnameGateway` theo `MessageGateway`.
- Env: OA ID, app id/secret, template IDs, brandname.
- Giữ `log` làm default local/STG nếu chưa có credential.

**Acceptance**
- [ ] `garage:send-messages` gửi pending → cập nhật `provider_message_id` / `error`.
- [ ] Retry có giới hạn (vd. 3 lần) rồi `failed`.
- [ ] Không gửi nếu `reminder_opt_out` (đã có) hoặc SĐT invalid.

**DB cần thêm**

```text
customer_messages
  + attempts          unsignedTinyInteger default 0
  + last_attempt_at   timestamp nullable
  + template_code     string(50) nullable   # mã template ZNS/SMS
  + template_data     json nullable         # biến render template
```

**SP:** 8 · **Owner:** Backend

---

### P0-B-02 — Đăng ký & map template ZNS

**User story**  
Là *admin*, tôi muốn cấu hình mã template cho từng loại tin (nhắc BD, báo CK thành công, link HĐĐT).

**Acceptance**
- [ ] Config/UI settings: map `CustomerMessageType` → `template_code`.
- [ ] Body fallback plain-text khi channel = SMS.

**DB (tuỳ chọn nếu muốn sửa trên UI không deploy)**

```text
message_templates
  id
  type            string(40) unique   # maintenance_reminder, payment_receipt, ...
  channel         string(20)          # zalo|sms
  template_code   string(50)
  body_fallback   text nullable
  is_active       boolean default true
  timestamps
```

**SP:** 3 · **Owner:** Backend

---

### P0-B-03 — Opt-in / nhật ký đồng ý nhận tin

**User story**  
Là *gara*, tôi cần lưu khách đã đồng ý nhận ZNS/SMS để tuân thủ nhà mạng / Zalo.

**Acceptance**
- [ ] Checkbox đồng ý khi tạo/sửa khách + lúc tiếp nhận.
- [ ] Không queue reminder nếu chưa opt-in (nghiêm hơn `reminder_opt_out`).

**DB**

```text
customers
  + messaging_opt_in         boolean default false
  + messaging_opt_in_at      timestamp nullable
  + messaging_opt_in_source  string(40) nullable  # reception|manual|import
```

**SP:** 2 · **Owner:** Backend

---

### P0-B-04 — Dashboard tin gửi thất bại

**User story**  
Là *cố vấn*, tôi muốn thấy danh sách tin `failed` để gọi điện thủ công hoặc gửi lại.

**Acceptance**
- [ ] Page/Resource “Tin nhắn khách” filter theo status.
- [ ] Action “Gửi lại” reset pending.

**DB:** không (dùng `customer_messages`).

**SP:** 3 · **Owner:** Frontend Filament

---

## C. Nhà cung cấp + Đơn mua + Nhập kho

### P0-C-01 — Master Nhà cung cấp (NCC)

**User story**  
Là *thủ kho / admin*, tôi muốn quản lý danh sách NCC (MST, SĐT, công nợ) để mua hàng có truy vết.

**Acceptance**
- [ ] CRUD Filament `SupplierResource`.
- [ ] Policy: admin full; advisor read; technician none.

**DB**

```text
suppliers
  id
  code              string(30) unique
  name              string
  phone             string(20) nullable
  email             string nullable
  tax_code          string(20) nullable index
  address           text nullable
  bank_name         string nullable
  bank_account_no   string(50) nullable
  bank_account_name string nullable
  payment_terms_days unsignedSmallInteger default 0  # 0 = trả ngay
  note              text nullable
  is_active         boolean default true
  timestamps
```

**SP:** 3 · **Owner:** Backend

---

### P0-C-02 — Đơn mua hàng (Purchase Order)

**User story**  
Là *thủ kho*, tôi muốn tạo PO chọn NCC + danh sách phụ tùng/SL/đơn giá dự kiến, để theo dõi hàng đang đặt.

**Acceptance**
- [ ] Status: `draft` → `ordered` → `partially_received` → `received` | `canceled`.
- [ ] Không cho xóa PO đã có phiếu nhập.

**DB**

```text
purchase_orders
  id
  code              string(30) unique     # PO-20261020-001
  supplier_id       FK suppliers restrict
  status            string(30) index
  ordered_at        timestamp nullable
  expected_at       date nullable
  notes             text nullable
  created_by        FK users
  subtotal          decimal(15,2) default 0
  timestamps

purchase_order_items
  id
  purchase_order_id FK cascade
  part_id           FK parts restrict
  quantity_ordered  decimal(12,2)
  quantity_received decimal(12,2) default 0
  unit_cost         decimal(15,2)         # giá nhập dự kiến
  note              string nullable
  timestamps
```

**SP:** 5 · **Owner:** Backend

---

### P0-C-03 — Phiếu nhập kho từ PO / nhập lẻ

**User story**  
Là *thủ kho*, khi hàng về tôi muốn nhập kho theo PO (một phần hoặc đủ) hoặc nhập lẻ không PO, cập nhật tồn + giá vốn.

**Acceptance**
- [ ] Trong `DB::transaction` + `lockForUpdate()` trên `parts` (theo `.cursorrules`).
- [ ] Tạo `stock_transactions` type `import` với `reference_id` = goods_receipt id.
- [ ] Cập nhật `parts.cost_price` theo policy (last purchase **hoặc** weighted average — chọn 1, ghi rõ config).
- [ ] Cập nhật `quantity_received` trên PO items + status PO.

**DB**

```text
goods_receipts
  id
  code                 string(30) unique  # GR-...
  supplier_id          FK suppliers nullOnDelete
  purchase_order_id    FK purchase_orders nullOnDelete
  received_at          timestamp
  invoice_no_supplier  string(50) nullable  # số HĐ NCC
  invoice_date         date nullable
  notes                text nullable
  created_by           FK users
  total_amount         decimal(15,2) default 0
  timestamps

goods_receipt_items
  id
  goods_receipt_id  FK cascade
  part_id           FK parts restrict
  purchase_order_item_id FK nullable
  quantity          decimal(12,2)
  unit_cost         decimal(15,2)
  line_total        decimal(15,2)
  timestamps

stock_transactions
  + note            string nullable          # nếu chưa có
  # reference_id giữ goods_receipt_id khi type=import từ GR
```

**SP:** 8 · **Owner:** Backend

---

### P0-C-04 — Công nợ phải trả NCC (AP)

**User story**  
Là *kế toán*, tôi muốn biết còn nợ NCC bao nhiêu và ghi nhận thanh toán cho NCC.

**Acceptance**
- [ ] Widget/list AP aging (0–30–60–90).
- [ ] Thanh toán NCC không nhầm với thu khách.

**DB**

```text
supplier_bills
  id
  supplier_id       FK
  goods_receipt_id  FK nullable unique   # 1 GR → 1 bill (mvp)
  bill_number       string(50) nullable
  bill_date         date
  due_date          date nullable
  total_amount      decimal(15,2)
  paid_amount       decimal(15,2) default 0
  status            string(20) index     # unpaid|partial|paid
  notes             text nullable
  timestamps

supplier_payments
  id
  supplier_bill_id  FK
  amount            decimal(15,2)
  method            string(20)           # cash|bank_transfer
  paid_at           timestamp
  reference         string nullable
  created_by        FK users
  notes             text nullable
  timestamps
```

**SP:** 5 · **Owner:** Backend

---

### P0-C-05 — Liên kết “mua ngoài” trên RO với NCC

**User story**  
Là *cố vấn*, khi chọn nguồn phụ tùng `direct_purchase`, tôi muốn chọn NCC thay vì chỉ gõ text tự do.

**Acceptance**
- [ ] Field `supplier_id` optional trên `work_order_items`.
- [ ] Giữ `supplier_info` text làm ghi chú bổ sung.

**DB**

```text
work_order_items
  + supplier_id   FK suppliers nullOnDelete  after supplier_info
```

**SP:** 2 · **Owner:** Backend

---

## D. Kiểm kê kho

### P0-D-01 — Phiếu kiểm kê

**User story**  
Là *thủ kho*, định kỳ tôi muốn tạo phiếu kiểm kê, nhập SL đếm thực tế từng mã, hệ thống tính lệch.

**Acceptance**
- [ ] Status: `draft` → `counting` → `applied` | `canceled`.
- [ ] Chỉ `applied` mới đụng tồn.

**DB**

```text
stocktakes
  id
  code           string(30) unique
  status         string(20) index
  counted_at     timestamp nullable
  note           text nullable
  created_by     FK users
  applied_by     FK users nullable
  applied_at     timestamp nullable
  timestamps

stocktake_items
  id
  stocktake_id      FK cascade
  part_id           FK parts restrict
  system_quantity   integer          # snapshot lúc thêm dòng
  counted_quantity  integer nullable
  difference        integer nullable # counted - system (lúc apply)
  unit_cost         decimal(15,2)    # để tính giá trị lệch
  note              string nullable
  unique(stocktake_id, part_id)
```

**SP:** 5 · **Owner:** Backend

---

### P0-D-02 — Apply kiểm kê → stock ledger

**User story**  
Là *admin*, sau khi duyệt số liệu kiểm kê tôi muốn hệ thống điều chỉnh tồn qua `stocktake_adjustment`.

**Acceptance**
- [ ] `InventoryService::applyStocktake()` transaction + lock parts.
- [ ] Mỗi lệch ≠ 0 → 1 `stock_transactions` (`quantity` có dấu hoặc quy ước + note).
- [ ] Không apply 2 lần.

**DB:** dùng D-01 + `stock_transactions.reference_id` = stocktake id.

**SP:** 5 · **Owner:** Backend

---

### P0-D-03 — Báo cáo lệch kiểm kê

**User story**  
Là *chủ gara*, tôi muốn xem giá trị lệch kho (thất thoát) theo kỳ.

**Acceptance**
- [ ] Filter theo ngày apply; export CSV/Excel đơn giản.

**DB:** không.

**SP:** 2 · **Owner:** Frontend

---

## E. Bảo hành theo kỳ hạn

### P0-E-01 — Sinh thẻ bảo hành khi bàn giao

**User story**  
Là *gara*, khi bàn giao RO đã thanh toán, hệ thống tự tạo thẻ bảo hành cho từng phụ tùng/dịch vụ đủ điều kiện, để lần sau nhận claim nhanh.

**Acceptance**
- [ ] Hook trong `WorkOrderService::completeDelivery` (hoặc sau invoice paid — chốt 1 rule).
- [ ] Kỳ hạn mặc định từ config + override theo `work_order_items.warranty_policy` / catalog.
- [ ] Không tạo thẻ cho `customer_supplied`.

**DB**

```text
parts / services (catalog, optional defaults)
  + warranty_months   unsignedSmallInteger nullable
  + warranty_km       unsignedInteger nullable

warranty_cards
  id
  code                  string(30) unique
  vehicle_id            FK
  customer_id           FK
  work_order_id         FK
  work_order_item_id    FK
  item_type             string(20)   # part|service
  item_id               unsignedBigInteger
  item_name             string
  starts_at             date
  ends_at               date nullable
  start_odo             unsignedInteger nullable
  end_odo               unsignedInteger nullable  # start + warranty_km
  status                string(20) index  # active|claimed|expired|void
  terms                 text nullable
  timestamps
  INDEX (vehicle_id, status)
```

**SP:** 5 · **Owner:** Backend

---

### P0-E-02 — Tiếp nhận claim bảo hành → RO `warranty_redo`

**User story**  
Là *cố vấn*, khi khách mang xe vào bảo hành, tôi chọn thẻ BH còn hiệu lực để tạo RO loại `warranty_redo` liên kết thẻ đó.

**Acceptance**
- [ ] Validate còn hạn theo ngày **và** km (nếu có).
- [ ] RO bảo hành: tiền công/phụ tùng claim = 0 hoặc tách dòng chịu phí (policy).
- [ ] `PayrollService` đã bỏ qua `WarrantyRedo` — giữ hành vi này.

**DB**

```text
work_orders
  + warranty_card_id  FK warranty_cards nullOnDelete

warranty_claims
  id
  warranty_card_id   FK
  work_order_id      FK
  symptom            text
  resolution         text nullable
  status             string(20)  # open|approved|rejected|done
  decided_by         FK users nullable
  decided_at         timestamp nullable
  timestamps
```

**SP:** 5 · **Owner:** Backend

---

### P0-E-03 — Widget thẻ BH sắp hết hạn / hết km

**User story**  
Là *chăm sóc KH*, tôi muốn danh sách xe sắp hết BH để upsell / nhắc.

**DB:** không.

**SP:** 2 · **Owner:** Frontend

---

### P0-E-04 — In phiếu bảo hành PDF

**User story**  
Là *cố vấn*, tôi muốn in phiếu BH kèm điều khoản giao khách khi bàn giao.

**DB:** không ( DomPDF view mới ).

**SP:** 3 · **Owner:** Backend

---

## F. Đặt lịch hẹn

### P0-F-01 — Lịch hẹn tiếp nhận

**User story**  
Là *cố vấn*, tôi muốn ghi lịch hẹn (xe, giờ, dịch vụ dự kiến, cầu ưu tiên) trước khi xe tới, để điều phối xưởng.

**Acceptance**
- [ ] CRUD + calendar/list theo ngày.
- [ ] Status: `scheduled` → `checked_in` → `canceled` | `no_show`.
- [ ] Check-in tạo / gắn Quick Reception → WorkOrder.

**DB**

```text
appointments
  id
  code                string(30) unique
  customer_id         FK nullable     # có thể khách mới chưa tạo
  vehicle_id          FK nullable
  walk_in_name        string nullable
  walk_in_phone       string(20) nullable
  walk_in_plate       string(20) nullable
  scheduled_start     timestamp index
  scheduled_end       timestamp nullable
  lift_bay            string(30) nullable
  service_advisor_id  FK users nullable
  estimated_services  json nullable   # [{service_id, note}]
  customer_request    text nullable
  status              string(20) index
  work_order_id       FK nullable
  canceled_reason     string nullable
  created_by          FK users
  timestamps
```

**SP:** 5 · **Owner:** Backend + Filament Page

---

### P0-F-02 — Chống trùng cầu nâng theo khung giờ

**User story**  
Là *điều phối*, hệ thống phải cảnh báo / chặn khi 2 lịch cùng `lift_bay` overlap thời gian.

**Acceptance**
- [ ] Validation overlap (trừ status canceled/no_show).
- [ ] Config buffer phút giữa 2 slot.

**DB:** không (query trên `appointments` + optionally work_orders đang mở cùng bay).

**SP:** 3 · **Owner:** Backend

---

### P0-F-03 — Nhắc lịch hẹn (Zalo/SMS) trước X giờ

**User story**  
Là *khách*, tôi nhận tin nhắc trước giờ hẹn để giảm no-show.

**Acceptance**
- [ ] Command schedule (vd. mỗi giờ): queue message type `appointment_reminder`.
- [ ] Phụ thuộc P0-B.

**DB**

```text
appointments
  + reminder_sent_at  timestamp nullable

customer_messages.type += appointment_reminder
```

**SP:** 3 · **Owner:** Backend

---

### P0-F-04 — Check-in từ lịch → Quick Reception

**User story**  
Là *cố vấn*, từ appointment tôi bấm “Xe đã tới” để prefill biển số/khách/odo request vào Quick Reception.

**Acceptance**
- [ ] Tạo WO gắn `appointments.work_order_id`, status appointment = `checked_in`.

**DB:** dùng F-01.

**SP:** 3 · **Owner:** Backend

---

## G. Sổ quỹ tiền mặt & báo cáo VAT

### P0-G-01 — Ca quỹ / sổ quỹ ngày

**User story**  
Là *thu ngân / admin*, tôi muốn mở/đóng ca quỹ tiền mặt, đối chiếu tiền trong két với tổng thu chi trong ca.

**Acceptance**
- [ ] Mở ca: nhập số dư đầu.
- [ ] Đóng ca: nhập đếm thực tế; lệch = counted - expected.
- [ ] Chỉ 1 ca `open` tại một thời điểm (mvp 1 két).

**DB**

```text
cash_sessions
  id
  opened_at        timestamp
  closed_at        timestamp nullable
  opened_by        FK users
  closed_by        FK users nullable
  opening_balance  decimal(15,2)
  closing_counted  decimal(15,2) nullable
  expected_balance decimal(15,2) nullable  # tính lúc đóng
  difference       decimal(15,2) nullable
  status           string(20) index  # open|closed
  note             text nullable
  timestamps

cash_movements
  id
  cash_session_id  FK
  direction        string(10)   # in|out
  amount           decimal(15,2)
  category         string(40)   # invoice_payment|petty_expense|supplier_payment|adjustment
  reference_type   string nullable  # morph
  reference_id     unsignedBigInteger nullable
  occurred_at      timestamp
  created_by       FK users
  note             string nullable
  timestamps
  INDEX (reference_type, reference_id)
```

**SP:** 5 · **Owner:** Backend

---

### P0-G-02 — Ghi quỹ tự động khi thu tiền mặt

**User story**  
Là *hệ thống*, mỗi lần `recordPayment` method = `cash` phải tạo `cash_movements` thuộc ca đang mở.

**Acceptance**
- [ ] Không cho thu cash nếu chưa mở ca (hoặc auto-open theo config).
- [ ] Tương thích SePay (bank) — không ghi vào két mặt.

**DB:** dùng G-01.

**SP:** 3 · **Owner:** Backend

---

### P0-G-03 — Báo cáo VAT đầu ra theo kỳ

**User story**  
Là *kế toán*, tôi muốn tổng hợp doanh thu trước thuế / VAT / sau thuế theo tháng để đối chiếu HĐĐT & tờ khai.

**Acceptance**
- [ ] Page filter từ ngày → đến ngày.
- [ ] Tách theo `payer_type`, theo `vat_rate` trên WO.
- [ ] Cột “đã xuất HĐĐT” vs “chưa xuất”.

**DB (denormalize nhẹ để query nhanh — khuyến nghị)**

```text
invoices
  + vat_rate           unsignedTinyInteger nullable  # copy từ WO lúc issue
  + amount_before_vat  decimal(15,2) nullable
  + vat_amount         decimal(15,2) nullable
```

Backfill từ `work_orders.vat_rate` + `final_amount`.

**SP:** 5 · **Owner:** Backend

---

### P0-G-04 — Báo cáo VAT đầu vào từ NCC

**User story**  
Là *kế toán*, tôi muốn liệt kê HĐ mua vào từ `goods_receipts` / `supplier_bills` theo kỳ.

**Phụ thuộc:** nhóm C.

**DB**

```text
goods_receipts / supplier_bills
  + vat_rate      unsignedTinyInteger default 8
  + vat_amount    decimal(15,2) default 0
  + amount_before_vat decimal(15,2) default 0
```

**SP:** 3 · **Owner:** Backend

---

## H. STG / CI / Backup

### P0-H-01 — Docker Compose local/STG

**User story**  
Là *dev/ops*, tôi muốn `docker compose up` chạy app + MySQL + Redis + queue worker giống STG.

**Acceptance**
- [ ] `Dockerfile` PHP 8.3-fpm/nginx hoặc frankenphp.
- [ ] `.env.staging.example` đầy đủ `GARAGE_*`, SePay, messaging, einvoice.
- [ ] Volume riêng storage/media.

**DB:** MySQL (không dùng SQLite trên STG).

**SP:** 5 · **Owner:** DevOps

---

### P0-H-02 — GitHub Actions CI

**User story**  
Là *team*, mỗi PR/push `main` phải chạy `composer test` + pint (optional) trước khi merge.

**Acceptance**
- [ ] Workflow `.github/workflows/ci.yml`.
- [ ] Cache composer; sqlite in-memory/file cho test như hiện tại.

**DB:** không.

**SP:** 3 · **Owner:** DevOps

---

### P0-H-03 — Pipeline deploy STG + migrate

**User story**  
Là *ops*, push tag/branch `staging` tự deploy lên server STG, chạy `migrate --force`, `config:cache`, restart queue.

**Acceptance**
- [ ] Zero/minimal downtime script.
- [ ] Rollback ghi trong README ops.
- [ ] Secret không commit.

**DB:** migrate trên STG có backup trước (H-04).

**SP:** 5 · **Owner:** DevOps

---

### P0-H-04 — Backup DB & storage hằng ngày

**User story**  
Là *chủ gara*, dữ liệu RO/hóa đơn/ảnh xe được backup mỗi ngày và giữ N ngày.

**Acceptance**
- [ ] Cron: `mysqldump` (hoặc `php artisan backup`) → S3/R2/local encrypted.
- [ ] Restore drill documented 1 lần trên STG.
- [ ] Backup storage `reception_photos`.

**DB:** không schema app; chỉ ops.

**SP:** 3 · **Owner:** DevOps

---

## Tổng hợp schema mới (checklist migration)

| Bảng / cột mới | Ticket |
|----------------|--------|
| `invoices.einvoice_*`, `vat_*` | A-02, G-03 |
| `customer_messages.attempts`, `template_*` | B-01 |
| `message_templates` (optional) | B-02 |
| `customers.messaging_opt_in*` | B-03 |
| `suppliers` | C-01 |
| `purchase_orders`, `purchase_order_items` | C-02 |
| `goods_receipts`, `goods_receipt_items` | C-03 |
| `supplier_bills`, `supplier_payments` | C-04 |
| `work_order_items.supplier_id` | C-05 |
| `stocktakes`, `stocktake_items` | D-01 |
| `parts/services.warranty_*`, `warranty_cards` | E-01 |
| `warranty_claims`, `work_orders.warranty_card_id` | E-02 |
| `appointments` | F-01, F-03 |
| `cash_sessions`, `cash_movements` | G-01 |
| VAT trên GR/bills | G-04 |

---

## Definition of Done (áp dụng mọi ticket P0)

1. Migration + model + enum/policy (nếu có) + Filament UI tối thiểu.  
2. Logic kho/tiền nằm trong `DB::transaction()`; `parts` dùng `lockForUpdate()`.  
3. Feature test覆盖 happy path + 1 case lỗi nghiệp vụ.  
4. Copy tiếng Việt trên UI; không để English placeholder.  
5. Ghi chú cấu hình env trong `.env.example`.  
6. PR mô tả: user story, cách test tay trên STG.

---

## Ước lượng tổng

| Nhóm | SP (approx.) |
|------|----------------|
| A HĐĐT | 21 |
| B Messaging | 16 |
| C Mua hàng/NCC | 23 |
| D Kiểm kê | 12 |
| E Bảo hành | 15 |
| F Lịch hẹn | 14 |
| G Quỹ & VAT | 16 |
| H Hạ tầng | 16 |
| **Tổng** | **~133 SP** |

> Với 1 full-stack: khoảng **6–9 tuần** tùy độ phức tạp HĐĐT/Zalo và sẵn credential nhà cung cấp.

---

## Ticket “spike” nên làm tuần 0 (trước coding lớn)

| ID | Việc | Kết quả chốt |
|----|------|--------------|
| P0-S-01 | Chọn NCC HĐĐT + lấy sandbox API | Driver A-01 |
| P0-S-02 | Chọn Zalo ZNS vs SMS + duyệt template | Driver B-01 |
| P0-S-03 | Thuê/chuẩn bị VPS STG + domain | H-01/H-03 |
| P0-S-04 | Chốt công thức giá vốn (last vs weighted avg) | C-03 |

---

*Tài liệu này là backlog triển khai; chưa bao gồm P1/P2 (fleet, portal khách, đa chi nhánh, …).*
