<?php

// Explicit isolated restore exercise. Its account cannot change the source schema.
$root = realpath($argv[1] ?? ''); $manifestFile = realpath($argv[2] ?? ''); $output = $argv[3] ?? null;
if (! $root || ! $manifestFile || ! $output) exit(2);
require $root.'/backend-laravel/vendor/autoload.php';
$app = require $root.'/backend-laravel/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$manifest = json_decode(file_get_contents($manifestFile), true, 512, JSON_THROW_ON_ERROR);
$backup = $manifest['backup_path'] ?? dirname($manifestFile).'/'.$manifest['file'];
if (($manifest['schema'] ?? null) !== 'neurotrader-db-backup/v1' || ! is_file($backup)
    || ! preg_match('/^[a-f0-9]{64}$/D', $manifest['uncompressed_sha256'] ?? '')
    || ! hash_equals($manifest['sha256'], hash_file('sha256', $backup))) throw new RuntimeException('ORIGINAL_BACKUP_HASH_REQUIRED');
$db = Illuminate\Support\Facades\DB::connection(); $configuration = config('database.connections.mysql');
$source = $db->selectOne('SELECT DATABASE() AS name, @@datadir AS directory');
$admin = $db; $restoreConfiguration = $configuration; $restoreDirectory = $source->directory;
// An optional private configuration selects a separate, loopback-only scratch
// server. The source connection remains read-only throughout the exercise.
if (isset($argv[4])) {
    $scratch = json_decode(file_get_contents($argv[4]), true, 512, JSON_THROW_ON_ERROR);
    if (($scratch['host'] ?? null) !== '127.0.0.1' || (int) ($scratch['port'] ?? 0) === (int) $configuration['port']
        || ! preg_match('/^restore-drill-codex-[a-z0-9_-]+$/D', basename(str_replace('\\', '/', $scratch['data_directory'] ?? '')))) {
        throw new RuntimeException('SEPARATE_SCRATCH_SERVER_REQUIRED');
    }
    $restoreConfiguration = [...$configuration, 'host' => $scratch['host'], 'port' => $scratch['port'],
        'username' => $scratch['username'], 'password' => $scratch['password'], 'database' => 'mysql', 'url' => null];
    config(['database.connections.restore_drill_admin' => $restoreConfiguration]);
    $admin = Illuminate\Support\Facades\DB::connection('restore_drill_admin');
    $actual = $admin->selectOne('SELECT @@datadir AS directory');
    $normalize = static fn ($path) => strtolower(rtrim(str_replace('\\', '/', (string) realpath($path)), '/'));
    if (! realpath($actual->directory) || $normalize($actual->directory) !== $normalize($scratch['data_directory'])
        || $normalize($actual->directory) === $normalize($source->directory)) throw new RuntimeException('SCRATCH_SERVER_DATA_DIRECTORY_MISMATCH');
    $restoreDirectory = $actual->directory;
}
$target = 'codex_restore_'.gmdate('Ymd_His').'_'.bin2hex(random_bytes(3)); $user = 'codex_restore_'.bin2hex(random_bytes(5));
$password = bin2hex(random_bytes(32));
if (! preg_match('/^codex_restore_[a-z0-9_]+$/D', $target) || $target === $source->name) throw new RuntimeException('ISOLATED_RESTORE_TARGET_REQUIRED');
if ($admin->selectOne('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?', [$target])) throw new RuntimeException('ISOLATED_RESTORE_TARGET_MUST_BE_NEW');
$facts = static fn ($connection) => $connection->table('lab_evaluation_runs')->where('lab_generation_id', 357)
    ->where('phase', 'screening')->orderBy('id')->get(['id', 'run_id', 'status', 'request_hash', 'response_hash', 'data_hash', 'code_hash'])
    ->map(fn ($row) => (array) $row)->all();
$originalFacts = $facts($db);
$originalTables = array_map(fn ($row) => $row->name, $db->select(
    'SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME', [$source->name]));
sort($originalTables, SORT_STRING);
$receipt = ['protocol' => 'isolated_original_backup_restore_drill_v2', 'started_at' => gmdate('c'),
    'backup_sha256' => $manifest['sha256'], 'isolated_database' => $target, 'source_database_unchanged' => true,
    'completed' => false, 'separate_account_restricted_to_target' => true, 'separate_scratch_server' => $admin !== $db];
$databaseCreated = false; $userCreated = false;
try {
    $receipt['stage'] = 'create_isolated_schema';
    $admin->unprepared("CREATE DATABASE `$target` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"); $databaseCreated = true;
    $receipt['stage'] = 'create_restricted_account';
    $admin->unprepared("CREATE USER '$user'@'%' IDENTIFIED BY '$password'"); $userCreated = true;
    $receipt['stage'] = 'grant_target_scope';
    $admin->unprepared("GRANT ALL PRIVILEGES ON `$target`.* TO '$user'@'%'");
    $receipt['stage'] = 'restore_original_stream';
    $python = getenv('RESTORE_PYTHON_BINARY') ?: 'python';
    $process = new Symfony\Component\Process\Process([$python, '-B', __DIR__.'/restore-backup-stream.py']);
    $process->setInput(json_encode(['mysql_binary' => str_replace('mysqldump', 'mysql', config('database.backup.mysqldump_binary')),
        'host' => $restoreConfiguration['host'], 'port' => $restoreConfiguration['port'], 'user' => $user, 'password' => $password,
        'database' => $target, 'backup_file' => $backup, 'uncompressed_sha256' => $manifest['uncompressed_sha256'],
        'data_directory' => $restoreDirectory, 'error_file' => $output.'.mysql-errors.log',
        'progress_file' => $output.'.progress.json']));
    // The local host may suspend overnight. Disk reserve, restricted schema,
    // process exit and original stream hashes bound this operational exercise.
    $process->setTimeout(null); $process->run();
    $stream = json_decode($process->getOutput(), true);
    if (! $process->isSuccessful()) throw new RuntimeException('ISOLATED_RESTORE_STREAM_FAILED:'.($stream['reason_code'] ?? 'OWNER_PROCESS_FAILED'));
    config(['database.connections.restore_drill' => [...$restoreConfiguration, 'database' => $target, 'username' => $user, 'password' => $password, 'url' => null]]);
    $restored = Illuminate\Support\Facades\DB::connection('restore_drill');
    $tables = $restored->selectOne('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA=?', [$target]);
    $restoredTables = array_map(fn ($row) => $row->name, $restored->select(
        'SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME', [$target]));
    $backupTables = $stream['original_backup_table_names'] ?? [];
    sort($backupTables, SORT_STRING);
    sort($restoredTables, SORT_STRING);
    $receipt += $stream;
    if ($backupTables === [] || $backupTables !== $restoredTables) throw new RuntimeException('RESTORED_NATIVE_BACKUP_TABLE_INVENTORY_MISMATCH');
    $receipt['original_backup_table_count'] = count($backupTables);
    $receipt['original_backup_table_names'] = $backupTables;
    $receipt['original_backup_table_inventory_equal'] = true;
    $missingCurrent = array_values(array_diff($originalTables, $restoredTables));
    $receipt['current_schema_missing_from_snapshot'] = $missingCurrent;
    if ($missingCurrent !== [] && ($argv[5] ?? '') === '--upgrade-current-empty-schema') {
        // This pre-release backup predates exactly two current, still-empty
        // evidence schemas. Upgrade only through their original migrations;
        // a missing populated table or unknown schema cannot be reconstructed.
        $allowed = [
            'scoped_research_certificates' => '2026_10_09_120000_create_scoped_research_certificates.php',
            'research_exposure_capture_records' => '2026_10_09_140000_create_research_exposure_capture_records.php',
        ];
        $paths = []; $migrationProof = [];
        foreach ($allowed as $table => $file) {
            if (! in_array($table, $missingCurrent, true)) continue;
            if ($db->table($table)->count() !== 0) throw new RuntimeException('MISSING_CURRENT_EVIDENCE_TABLE_IS_POPULATED');
            $path = $root.'/backend-laravel/database/migrations/'.$file;
            if (! is_file($path)) throw new RuntimeException('ORIGINAL_SCHEMA_MIGRATION_REQUIRED');
            $paths[] = $path;
            $migrationProof[] = ['table' => $table, 'source_rows' => 0, 'migration' => $file, 'migration_sha256' => hash_file('sha256', $path)];
        }
        if (count($paths) !== count($missingCurrent)) throw new RuntimeException('UNKNOWN_CURRENT_SCHEMA_UPGRADE_FORBIDDEN');
        $migrator = app('migrator');
        $migrator->usingConnection('restore_drill', fn () => $migrator->run($paths, ['pretend' => false, 'step' => false]));
        foreach ($migrationProof as &$proof) {
            $proof['restored_rows'] = $restored->table($proof['table'])->count();
            if ($proof['restored_rows'] !== 0) throw new RuntimeException('EMPTY_EVIDENCE_SCHEMA_RESTORATION_REQUIRED');
        }
        unset($proof);
        $receipt['bounded_empty_schema_upgrade'] = $migrationProof;
        $restoredTables = array_map(fn ($row) => $row->name, $restored->select(
            'SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME', [$target]));
        sort($restoredTables, SORT_STRING);
    }
    $restoredFacts = $facts($restored);
    $receipt += $stream;
    $receipt['restored_table_count'] = count($restoredTables);
    $receipt['source_table_count'] = count($originalTables);
    $receipt['original_table_inventory_equal'] = $originalTables === $restoredTables;
    $receipt['current_source_table_inventory_equal'] = $originalTables === $restoredTables;
    $receipt['g263_original_run_count'] = count($restoredFacts);
    $receipt['g263_original_facts_sha256'] = hash('sha256', json_encode($restoredFacts));
    $receipt['g263_original_facts_equal'] = $originalFacts === $restoredFacts;
    $receipt['source_original_facts_still_equal'] = $originalFacts === $facts($db);
    if (! $receipt['original_table_inventory_equal']) throw new RuntimeException('RESTORED_ORIGINAL_TABLE_INVENTORY_MISMATCH');
    if (count($restoredFacts) !== 20 || ! $receipt['g263_original_facts_equal'] || ! $receipt['source_original_facts_still_equal']) throw new RuntimeException('RESTORED_ORIGINAL_G263_FACTS_MISMATCH');
    $receipt['completed'] = true; $receipt['reason_code'] = 'ORIGINAL_BACKUP_RESTORED_AND_FACTS_VERIFIED';
} catch (Throwable $error) {
    $receipt['error_class'] = get_class($error);
    $receipt['driver_error_code'] = $error instanceof PDOException ? ($error->errorInfo[1] ?? null) : null;
    $receipt['reason_code'] = preg_match('/^[A-Z0-9_]+(?::[A-Z0-9_]+)?$/D', $error->getMessage())
        ? $error->getMessage() : 'ISOLATED_RESTORE_OWNER_FAILED';
} finally {
    Illuminate\Support\Facades\DB::purge('restore_drill');
    // Both identifiers were generated, validated and created by this invocation.
    if ($databaseCreated) $admin->unprepared("DROP DATABASE `$target`");
    if ($userCreated) $admin->unprepared("DROP USER '$user'@'%'");
    Illuminate\Support\Facades\DB::purge('restore_drill_admin');
    $receipt['temporary_database_removed'] = true; $receipt['temporary_account_removed'] = true;
    $receipt['finished_at'] = gmdate('c');
    file_put_contents($output, json_encode($receipt, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
}
echo json_encode($receipt, JSON_UNESCAPED_SLASHES)."\n";
exit($receipt['completed'] ? 0 : 1);
