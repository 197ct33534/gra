<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupGarageDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_sqlite_backup_writes_database_and_media_zip(): void
    {
        Storage::fake('local');

        // RefreshDatabase dùng :memory: — tạo file sqlite tạm để lệnh backup có gì mà copy.
        $sqlitePath = database_path('backup-test.sqlite');
        if (! is_file($sqlitePath)) {
            touch($sqlitePath);
        }

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite.database' => $sqlitePath]);

        $public = storage_path('app/public');
        if (! is_dir($public)) {
            mkdir($public, 0777, true);
        }
        file_put_contents($public.'/probe.txt', 'gara');

        $exit = Artisan::call('garage:backup', ['--keep' => 30]);

        $this->assertSame(0, $exit);

        $dirs = Storage::disk('local')->directories('backups');
        $this->assertNotEmpty($dirs);

        $dir = $dirs[0];
        Storage::disk('local')->assertExists($dir.'/database.sqlite');
        Storage::disk('local')->assertExists($dir.'/media.zip');

        @unlink($public.'/probe.txt');
        @unlink($sqlitePath);
    }

    public function test_backup_uses_the_disk_from_config_so_config_cache_does_not_break_it(): void
    {
        Storage::fake('local');
        Storage::fake('offsite');
        config(['garage.backup.disk' => 'offsite']);

        $sqlitePath = database_path('backup-test.sqlite');
        touch($sqlitePath);
        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite.database' => $sqlitePath]);

        $this->assertSame(0, Artisan::call('garage:backup'));

        $this->assertNotEmpty(Storage::disk('offsite')->directories('backups'));
        $this->assertSame([], Storage::disk('local')->directories('backups'));

        @unlink($sqlitePath);
    }
}
