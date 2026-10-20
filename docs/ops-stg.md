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
