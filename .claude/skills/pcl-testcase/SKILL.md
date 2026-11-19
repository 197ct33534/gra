---
name: pcl-testcase
description: >
  Write a PCL test-case checklist for the gra_o_to garage app (Laravel 12 + Filament v5) — the
  team's 5-column sheet: 機能名 / テスト項目 / 確認項目 / ケース数 / 確認URL・データ — that pastes
  straight into Google Sheets or Excel with in-cell line breaks intact. Use whenever asked for test
  cases, a PCL, a テスト仕様書, a QA checklist, "viết testcase", "viết PCL", "kịch bản kiểm thử" for
  a screen, a Filament resource/page, a workflow (tiếp nhận xe, lệnh sửa chữa, thu ngân, kho...),
  a scheduled command or a queue job — and whenever an existing PCL needs new rows. Covers the
  writing style that keeps 確認項目 readable by garage staff, and the csv → html → xlsx pipeline.
---

# PCL test-case sheets (gra_o_to)

Ported from the g-microzero `pcl-testcase` skill. The format, writing rules and renderers are the
team standard and unchanged; the section **gra_o_to specifics** is what differs.

## The five columns, and what actually goes in them

| # | Header | Content |
|---|--------|---------|
| 1 | `機能名 / Function` | Feature group. A **business** name in Vietnamese, matching the menu label — "Tiếp nhận nhanh", "Lệnh sửa chữa", not `QuickReception` / `WorkOrderResource`. |
| 2 | `テスト項目 / Test case` | One sentence naming the situation under test. |
| 3 | `確認項目 / Confirmation Items` | Precondition → `Kết quả mong đợi:` → outcome → optional `Vì sao quan trọng:` → `Chi tiết:`. See below. |
| 4 | `ケース数 / Count of` | Number of cases the row covers. Usually `1`. |
| 5 | `確認URL・データ / Confirmation data` | **The URL if the feature has one.** Otherwise the SQL / log grep / command that proves it. |

The headers stay Japanese + English (team template). Columns 1–3 are written in **Vietnamese**,
because the people who approve and run the sheet are garage staff and the UI is Vietnamese.

**Column 5 rule.** A screen has a URL — write it (`/admin/work-orders/{id}/edit`, plus the record,
filter or login role that matters). A scheduled command or queued job has none, so write what a
tester actually runs: the `SELECT`, the `php artisan ...` command, the `grep '<log marker>'`. Never
leave the column empty and never invent a URL for something that has no route — check
`php artisan route:list --path=admin`.

## How to write 確認項目 — the part that gets this wrong

The column exists so a **non-engineer (giám đốc xưởng, cố vấn dịch vụ, thu ngân) can approve the
case**. Engineer-language fails that: `WorkOrderStatus::Approved`, `reserveStock() returns false`,
`canSeeMoney()` tell a garage owner nothing about what the business loses.

Write the **business consequence first**, then the mechanics:

```
Lệnh sửa chữa đang 'Đã báo giá', phụ tùng trong báo giá còn đủ tồn kho.
Bấm 'Duyệt báo giá' → xác nhận.
Kết quả mong đợi:
Trang 'Phụ tùng': cột 'Đang giữ cho xe' tăng đúng số lượng trong báo giá, 'Tồn kho' KHÔNG đổi.
Tồn kho chỉ bị trừ khi bấm 'Hoàn thành & Bàn giao'.
Vì sao quan trọng: giữ hàng để xe khác không lấy mất, nhưng chưa trừ tồn khi xe chưa giao.
Chi tiết: WorkOrderService::approveWorkOrder() tăng reserved_quantity; completeDelivery() mới trừ stock_quantity.
```

Rules that come out of that:

- **Lead with the observable outcome.** What a person sees on screen (label, badge, notification),
  in the data, or on the invoice.
- **Quote UI labels exactly as the code renders them** — copy them from the Filament resource /
  page / `lang/` file, with their Vietnamese diacritics. A label that does not exist on screen
  makes the case unexecutable.
- **`Vì sao quan trọng:` only where the consequence is not obvious.** Money, stock, data loss,
  role leakage (thợ thấy giá tiền), silent breakage. Not on every row.
- **`Chi tiết:` last, one line.** Class, enum, log string, config key — for whoever executes it.
- **Never claim behaviour you have not read in the code.** Every `Kết quả mong đợi` must be
  traceable to a real branch, every label must be on screen, every log string must be greppable.
  An invented step is worse than a missing one — QA signs off against this sheet.
- **Mark regression guards.** `REGRESSION GUARD: trước bản sửa <ngày>, ...`.
- **Flag open business questions rather than deciding them.** If code and spec disagree, write
  `CẦN XÁC NHẬN: ... Nghiệp vụ xác nhận giá trị nào là đúng.` and leave it visible.
- **Not everything can be de-jargoned.** Payloads, e-invoice XML, index plans — still state the
  consequence, but say plainly the case needs a developer to execute.

## gra_o_to specifics

- **Where sheets live:** `docs/testcase/PCL_<Feature>_<YYYYMMDD>.csv` (and the rendered `.html` /
  `.xlsx` beside it).
- **Roles:** `Admin` (giám đốc xưởng), `ServiceAdvisor` (cố vấn dịch vụ / SA), `Technician` (thợ) —
  see `app/Enums/UserRole.php`. Many screens and actions are role-gated (money columns, price
  edits, stocktake apply). When a case depends on role, say **which account logs in** in column 3
  and add a separate row for the role that must be **denied**.
- **Test accounts:** use the staff accounts created by `database/seeders/DatabaseSeeder.php`
  (`admin@…`, `sa1@…`, `tech1@…`). Refer to them by email; do not paste passwords into the sheet.
- **URLs:** the Filament panel is at `/admin`. Resource pages are `/admin/<slug>`,
  `/admin/<slug>/create`, `/admin/<slug>/{id}/edit`; custom pages such as `/admin/quick-reception`,
  `/admin/workshop-board`, `/admin/my-jobs`. Get slugs from `php artisan route:list --path=admin`.
- **Data checks:** local DB is SQLite (`database/database.sqlite`). For column 5 prefer a `SELECT`
  a tester can run, or `php artisan tinker --execute="..."` when a model accessor is the truth.
- **Status flows:** the work-order lifecycle (`app/Enums/WorkOrderStatus.php`) drives stock holding,
  technician jobs, invoicing. Order cases along that lifecycle.
- **Scheduled jobs** are in `routes/console.php` (e.g. customer-care reminders) — no URL; use the
  artisan command + `SELECT` on the affected table.
- **Existing automated tests** in `tests/Feature` often encode the exact branch — read them to find
  edge cases, but the PCL is for manual QA on screen, not a copy of the test list.

## Format pipeline

```
sheet.csv   ← the SINGLE source of truth. Edit only this.
   ├─ node csv_to_html.cjs sheet.csv   → sheet.html   ← what people COPY from
   └─ php generate_pcl.php sheet.csv  → sheet.xlsx   ← styled deliverable (needs PhpSpreadsheet)
```

Both scripts are renderers; nothing is duplicated, so the outputs cannot drift from the csv.
The html renderer is `.cjs` (not `.js`) because gra_o_to's `package.json` sets `"type": "module"` —
as `.js` Node would load it as ESM and fail on `require`.

**xlsx note:** `generate_pcl.php` uses `phpoffice/phpspreadsheet`, installed in gra_o_to as a
**dev** dependency (`require-dev`, ^5.11). On a fresh checkout run `composer install` (with dev)
first; on a `--no-dev` install the script exits with a clear message — then deliver the `.html`
and say the xlsx was skipped. Run it with the project's PHP (Laragon `php` on PATH).

### Why the csv is CSV and not TSV

A test step needs **real line breaks inside one cell**. A tab-separated file cannot hold a newline
inside a field, so TSV forces an encoded marker the reader has to decode. Quoted CSV carries the
newline for real, and `fgetcsv()` reads it back exactly.

### Why pasting the csv as text will always break

> The clipboard's **plain-text** path splits only on TAB and NEWLINE. It has **no CSV parser**. So
> every newline inside a quoted field ends the row, quotes show up literally, and one cell explodes
> down column A.

Two working paths, and only two:

1. **Copy from the HTML** — the clipboard's HTML path carries table structure, and `<br>` inside
   `<td>` arrives as a real in-cell line break. This is why `csv_to_html.cjs` exists.
2. **Import the csv, don't paste it** — Google Sheets `File > Import > Upload > Replace current
   sheet`, separator Comma. Excel: double-click the file, or `Data > From Text/CSV`.

### CSV conventions

- **UTF-8 BOM at the start.** Without it Excel on Windows reads the file in the ANSI codepage and
  the Japanese headers and Vietnamese diacritics become mojibake. Never add a BOM to a `.php` file.
- **Every field quoted**, headers included. Headers are two lines: Japanese, newline, English.
- **Use single quotes inside content** (`'Chờ khách duyệt'`). Avoids CSV `""` escaping entirely.
- When writing the csv from a script on Windows, write UTF-8 explicitly (PowerShell
  `Set-Content` defaults to ANSI — use the Write tool or `-Encoding utf8`).

## Workflow

1. **Read the real code first.** Trace the Filament resource/page, its actions, the model/service it
   calls, every guard (role, status, stock), early return and failure notification.
2. Group cases by business function and order them along the real flow (tiếp nhận → báo giá →
   khách duyệt → sửa → xuất kho → hoá đơn → thu tiền → giao xe).
3. Copy `assets/template.csv` to `docs/testcase/PCL_<Feature>_<YYYYMMDD>.csv` and fill it in.
4. Render and **validate** (below).
5. Deliver the `.html` — that is the file people actually use. Mention the import route as the
   alternative.

```bash
node .claude/skills/pcl-testcase/assets/csv_to_html.cjs docs/testcase/PCL_Foo_20261119.csv
php .claude/skills/pcl-testcase/assets/generate_pcl.php docs/testcase/PCL_Foo_20261119.csv
```

## Validate before delivering

A broken quote is invisible once the table is styled — it silently swallows the following rows
into one cell. Both renderers refuse a record that is not exactly 5 columns, so **running a
renderer is itself the structural check**. Add these (Git Bash):

```bash
# field separators must equal (records x 4)
grep -o '","' sheet.csv | wc -l

# quote parity must be even at EOF, or a quote is missing somewhere
awk '{q+=gsub(/"/,"\"")} END {print (q%2==0 ? "balanced" : "ODD -> broken")}' sheet.csv

# BOM present (expect 357 273 277)
head -c 3 sheet.csv | od -c | head -1

# rendered html: <td> must equal cases x 5
grep -o '<td>' sheet.html | wc -l
```

## Traps

- **`ケース数` is not a row counter.** It is how many cases that row represents. Leave it `1` unless
  a row genuinely covers several.
- **Renaming column-1 groups changes readability, not content.** If the team already knows the old
  names, ask before renaming.
- **The html Copy button uses `Range` + `execCommand`, not `navigator.clipboard`** — a page opened
  from `file://` is not a secure context in Chrome. Do not "modernise" it.
- **Do not Ctrl+A the html page** — that drags the instruction text into the paste. Use the
  button, or select the table only.
