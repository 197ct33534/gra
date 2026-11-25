# Vận hành STG / Docker / Backup

## Chạy local bằng Docker

```bash
cp .env.staging.example .env
# Sinh APP_KEY:
docker compose run --rm app php artisan key:generate

docker compose up -d --build
# App: http://localhost:8000/admin
```

Services: `app`, `queue`, `scheduler`, `mysql`, `redis`.

## Realtime Bảng điều phối xe (Laravel Reverb)

Khi một lệnh đổi trạng thái / cầu nâng / giờ hẹn / thợ phụ trách, mọi màn **Bảng điều phối xe** và
**Việc của tôi** đang mở tự cập nhật trong ~1–2 giây (tín hiệu `board.changed` trên kênh riêng
`workshop`, chỉ mang mã lệnh, không mang tiền).

```bash
# .env: BROADCAST_CONNECTION=reverb + REVERB_APP_ID/KEY/SECRET (sinh bằng `php artisan reverb:install`)
php artisan reverb:start            # local; Docker: service `reverb` (cổng 8080)
```

- Không chạy Reverb hoặc `BROADCAST_CONNECTION=log`: thao tác vẫn bình thường, các màn tự làm mới
  theo chu kỳ (Bảng 30 giây, Việc của tôi 60 giây). Reverb tắt giữa chừng chỉ làm mỗi lần lưu chậm tối
  đa ~0,5 giây (`REVERB_CONNECT_TIMEOUT`).
- Cổng 8080 bận (VD máy có MiniTool ShadowMaker): đặt `REVERB_PORT` và `REVERB_SERVER_PORT` cùng giá trị khác, VD 8081.
- Trình duyệt phải tới được `REVERB_HOST:REVERB_PORT`. Qua tunnel ngrok chỉ mở cổng web nên realtime không
  chạy, các màn quay về tự làm mới theo chu kỳ.
- STG/production sau HTTPS: đặt `REVERB_SCHEME=https`, `REVERB_HOST` = tên miền proxy tới cổng Reverb.

## CI

GitHub Actions (`.github/workflows/ci.yml`) chạy trên push/PR vào `main` và `staging`:

- `composer install`
- `php artisan test`
- `pint --test` (cảnh báo, không fail cứng)

## Backup

```bash
php artisan garage:backup
php artisan garage:backup --keep=7
```

- SQLite: copy file DB
- MySQL: `mysqldump` (cần mysql-client)
- Media: zip `storage/app/public`
- Lưu vào disk `BACKUP_DISK` (mặc định `local` → `storage/app/backups/...`)
- Tự chạy 02:00 Asia/Ho_Chi_Minh qua scheduler

### Restore drill (SQLite)

```bash
cp storage/app/backups/YYYYMMDD_HHMMSS/database.sqlite database/database.sqlite
unzip -o storage/app/backups/YYYYMMDD_HHMMSS/media.zip -d storage/app/public
php artisan config:clear
```

### Restore drill (MySQL)

```bash
mysql -h ... -u ... -p gra < storage/app/backups/.../database.sql
```

## Deploy STG (checklist thủ công)

1. Backup trước migrate
2. `git pull` / image mới
3. `php artisan migrate --force`
4. `php artisan config:cache && php artisan route:cache`
5. Restart `queue` + `scheduler`
6. Smoke: login admin, mở RO, nhập kho, kiểm kê
