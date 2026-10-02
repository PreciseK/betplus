<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupDatabaseToR2Test extends TestCase
{
    use RefreshDatabase;

    public function test_backup_creates_encrypted_snapshot_in_r2(): void
    {
        \Illuminate\Support\Facades\Config::set('services.cloudflare.r2_backup_disk', 'backup_test');
        Storage::fake('backup_test');

        $this->artisan('backup:database-r2', ['--retention' => 5])->assertSuccessful();

        $files = Storage::disk('backup_test')->files('backups');
        $this->assertNotEmpty($files, 'R2 backups directory should not be empty');

        $backupFile = $files[0];
        $this->assertStringEndsWith('.sql.gz.enc', $backupFile);

        $content = Storage::disk('backup_test')->get($backupFile);
        $this->assertNotEmpty($content);
    }

    public function test_backup_can_run_without_encryption(): void
    {
        \Illuminate\Support\Facades\Config::set('services.cloudflare.r2_backup_disk', 'backup_test');
        Storage::fake('backup_test');

        $this->artisan('backup:database-r2 --no-encrypt')->assertSuccessful();

        $files = Storage::disk('backup_test')->files('backups');
        $this->assertNotEmpty($files);

        $backupFile = $files[0];
        $this->assertStringEndsWith('.sql.gz', $backupFile);

        $content = Storage::disk('backup_test')->get($backupFile);
        $decompressed = gzdecode($content);
        $this->assertNotFalse($decompressed);
        $this->assertNotEmpty($decompressed);
    }
}
