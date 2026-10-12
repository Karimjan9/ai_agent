<?php

namespace Tests\Feature;

use App\Console\Commands\BackupDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DatabaseBackupRetentionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/database-backup-retention-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->root);
    }

    protected function tearDown(): void
    {
        if (isset($this->root) && str_starts_with($this->root, sys_get_temp_dir().'/database-backup-retention-')) {
            File::deleteDirectory($this->root);
        }
        parent::tearDown();
    }

    public function test_verified_new_gzip_replaces_old_sql_and_gzip_with_their_manifests(): void
    {
        $old = $this->oldBackups();
        $new = $this->newBackup();
        (new \ReflectionMethod(BackupDatabase::class, 'pruneVerified'))->invoke(new BackupDatabase, $this->root, $new, 1);
        $this->assertFileExists($new);
        $this->assertFileExists($new.'.manifest.json');
        foreach ($old as $path) {
            $this->assertFileDoesNotExist($path);
            $this->assertFileDoesNotExist($path.'.manifest.json');
        }
        $this->assertCount(2, File::files($this->root));
    }

    public function test_corrupt_new_backup_preserves_every_old_copy(): void
    {
        $old = $this->oldBackups();
        $new = $this->newBackup();
        $bytes = File::get($new);
        File::put($new, str_repeat('x', strlen($bytes)));
        try {
            (new \ReflectionMethod(BackupDatabase::class, 'pruneVerified'))->invoke(new BackupDatabase, $this->root, $new, 1);
            $this->fail('Corrupt new backup must not prune older snapshots.');
        } catch (\RuntimeException $error) {
            $this->assertSame('VERIFIED_NEW_BACKUP_REQUIRED_BEFORE_PRUNE', $error->getMessage());
        }
        foreach ($old as $path) {
            $this->assertSame('original old snapshot', File::get($path));
            $this->assertFileExists($path.'.manifest.json');
        }
    }

    public function test_outside_new_backup_cannot_authorize_deletion_in_another_directory(): void
    {
        $old = $this->oldBackups();
        File::ensureDirectoryExists($this->root.'/separate');
        $new = $this->newBackup($this->root.'/separate');
        $this->expectExceptionMessage('VERIFIED_NEW_BACKUP_REQUIRED_BEFORE_PRUNE');
        try {
            (new \ReflectionMethod(BackupDatabase::class, 'pruneVerified'))->invoke(new BackupDatabase, $this->root, $new, 1);
        } finally {
            foreach ($old as $path) $this->assertFileExists($path);
        }
    }

    public function test_health_accepts_a_hash_verified_compressed_backup(): void
    {
        $new = $this->newBackup();
        config(['database.backup.directory'=>$this->root, 'database.backup.verify_hash_on_health'=>true]);
        $service = (new \ReflectionClass(\App\Services\PhaseTwoFoundationService::class))->newInstanceWithoutConstructor();
        $result = (new \ReflectionMethod($service, 'databaseBackupStatus'))->invoke($service);
        $this->assertSame('ok', $result['status']);
        $this->assertSame(basename($new), $result['metrics']['file']);
        $this->assertTrue($result['metrics']['sha256_rehashed_on_health']);
    }

    public function test_health_refuses_a_changed_compressed_backup(): void
    {
        $new = $this->newBackup();
        $bytes = File::get($new);
        File::put($new, str_repeat('x', strlen($bytes)));
        config(['database.backup.directory'=>$this->root, 'database.backup.verify_hash_on_health'=>true]);
        $service = (new \ReflectionClass(\App\Services\PhaseTwoFoundationService::class))->newInstanceWithoutConstructor();
        $result = (new \ReflectionMethod($service, 'databaseBackupStatus'))->invoke($service);
        $this->assertSame('warning', $result['status']);
        $this->assertStringContainsString('No recent verified database backup', $result['message']);
    }

    private function oldBackups(): array
    {
        $paths = [$this->root.'/neurotrader_20250101_000000.sql', $this->root.'/neurotrader_release_20250102_000000.sql.gz'];
        foreach ($paths as $path) {
            File::put($path, 'original old snapshot');
            File::put($path.'.manifest.json', '{}');
        }
        return $paths;
    }

    private function newBackup(?string $directory = null): string
    {
        $path = ($directory ?? $this->root).'/neurotrader_20261012_000000.sql.gz';
        File::put($path, gzencode('CREATE TABLE original (id int);'));
        File::put($path.'.manifest.json', json_encode(['schema'=>'neurotrader-db-backup/v1', 'file'=>basename($path),
            'bytes'=>filesize($path), 'sha256'=>hash_file('sha256', $path)]));
        return $path;
    }
}
