<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Throwable;
use ZipArchive;

/**
 * Sao lưu DB (sqlite dump file / mysqldump) + thư mục storage/app/public (ảnh tiếp nhận).
 */
class BackupGarageData extends Command
{
    protected $signature = 'garage:backup
                            {--keep= : Số ngày giữ bản sao lưu (mặc định config/BACKUP_KEEP_DAYS)}';

    protected $description = 'Sao lưu database và media gara vào disk backup';

    public function handle(): int
    {
        $keepDays = (int) ($this->option('keep') ?: config('garage.backup.keep_days', 14));
        $diskName = (string) config('garage.backup.disk', 'local');
        $stamp = now()->format('Ymd_His');
        $folder = 'backups/'.$stamp;

        $disk = Storage::disk($diskName);
        $disk->makeDirectory($folder);

        $this->info("Tạo backup → disk [{$diskName}] / {$folder}");

        try {
            $this->backupDatabase($disk, $folder);
            $this->backupMedia($disk, $folder, $stamp);
        } catch (Throwable $e) {
            $this->error('Backup thất bại: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->pruneOldBackups($disk, $keepDays);
        $this->info('Xong.');

        return self::SUCCESS;
    }

    private function backupDatabase(Filesystem $disk, string $folder): void
    {
        $connection = config('database.default');

        if ($connection === 'sqlite') {
            $path = config('database.connections.sqlite.database');
            if (! is_string($path) || ! is_file($path)) {
                throw new \RuntimeException('Không tìm thấy file SQLite.');
            }
            $disk->put($folder.'/database.sqlite', File::get($path));
            $this->line('✓ SQLite');

            return;
        }

        if ($connection === 'mysql') {
            $cfg = config('database.connections.mysql');
            $target = storage_path('app/'.$folder.'-dump.sql');
            File::ensureDirectoryExists(dirname($target));

            $cmd = sprintf(
                'mysqldump --host=%s --port=%s --user=%s --password=%s %s > %s',
                escapeshellarg((string) $cfg['host']),
                escapeshellarg((string) $cfg['port']),
                escapeshellarg((string) $cfg['username']),
                escapeshellarg((string) $cfg['password']),
                escapeshellarg((string) $cfg['database']),
                escapeshellarg($target),
            );

            exec($cmd, $output, $code);

            if ($code !== 0 || ! is_file($target)) {
                throw new \RuntimeException('mysqldump thất bại (exit '.$code.'). Cài mysql-client trên host/container.');
            }

            $disk->put($folder.'/database.sql', File::get($target));
            File::delete($target);
            $this->line('✓ MySQL dump');

            return;
        }

        throw new \RuntimeException("Chưa hỗ trợ backup cho connection [{$connection}].");
    }

    private function backupMedia(Filesystem $disk, string $folder, string $stamp): void
    {
        $publicPath = storage_path('app/public');

        if (! is_dir($publicPath)) {
            $this->warn('Không có storage/app/public — bỏ qua media.');

            return;
        }

        $files = File::allFiles($publicPath);

        // ZipArchive không ghi file khi zip rỗng: gara chưa có ảnh nào thì bỏ qua, không làm hỏng cả bản backup.
        if ($files === []) {
            $this->warn('storage/app/public chưa có file — bỏ qua media.');

            return;
        }

        $zipPath = storage_path('app/'.$stamp.'-media.zip');
        $zip = new ZipArchive;

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Không tạo được file zip media.');
        }

        foreach ($files as $file) {
            $relative = ltrim(str_replace($publicPath, '', $file->getPathname()), DIRECTORY_SEPARATOR);
            $zip->addFile($file->getPathname(), $relative);
        }

        $zip->close();
        $disk->put($folder.'/media.zip', File::get($zipPath));
        File::delete($zipPath);
        $this->line('✓ Media ('.count($files).' file)');
    }

    private function pruneOldBackups(Filesystem $disk, int $keepDays): void
    {
        if ($keepDays < 1) {
            return;
        }

        $cutoff = now()->subDays($keepDays)->getTimestamp();

        foreach ($disk->directories('backups') as $dir) {
            $name = basename($dir);
            // Ymd_His
            if (preg_match('/^(\d{8})_(\d{6})$/', $name, $m) !== 1) {
                continue;
            }

            $ts = strtotime($m[1].' '.$m[2]);

            if ($ts !== false && $ts < $cutoff) {
                $disk->deleteDirectory($dir);
                $this->line("✓ Xóa backup cũ {$dir}");
            }
        }
    }
}
