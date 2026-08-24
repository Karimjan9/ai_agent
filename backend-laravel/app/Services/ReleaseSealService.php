<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/** Fail-closed source, migration, schema, config and test release attestation. */
class ReleaseSealService
{
    public const PROTOCOL = 'neurotrader_release_seal_v1';

    /** @return array<string, mixed> */
    public function build(string $testRunId, bool $testsPassed): array
    {
        $snapshot = $this->snapshot();
        $reasons = [];
        if ($testRunId === '') {
            $reasons[] = 'TEST_RUN_ID_REQUIRED';
        }
        if (! $testsPassed) {
            $reasons[] = 'PASSING_TEST_ATTESTATION_REQUIRED';
        }
        if ($snapshot['dirty_worktree']) {
            $reasons[] = 'RELEASE_SCOPE_DIRTY';
        }
        if ($snapshot['pending_migrations'] !== []) {
            $reasons[] = 'PENDING_MIGRATIONS';
        }
        if ($reasons !== []) {
            return [
                'protocol' => self::PROTOCOL,
                'status' => 'refused',
                'reason_codes' => $reasons,
                'snapshot' => $snapshot,
            ];
        }
        $manifest = [
            'protocol' => self::PROTOCOL,
            'status' => 'sealed',
            'git_sha' => $snapshot['git_sha'],
            'dirty_worktree' => false,
            'release_scope' => $snapshot['release_scope'],
            'source_checksum' => $snapshot['source_checksum'],
            'migration_checksum' => $snapshot['migration_checksum'],
            'schema_checksum' => $snapshot['schema_checksum'],
            'config_checksum' => $snapshot['config_checksum'],
            'test_run_id' => $testRunId,
            'tests_passed' => true,
            'sealed_at' => now()->utc()->toIso8601String(),
        ];
        $manifest['seal_checksum'] = $this->hash($manifest);
        File::ensureDirectoryExists(dirname($this->path()));
        File::put($this->path(), json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return $manifest;
    }

    /** @return array<string, mixed> */
    public function verify(): array
    {
        if (! File::exists($this->path())) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason_codes' => ['RELEASE_MANIFEST_MISSING']];
        }
        $manifest = json_decode((string) File::get($this->path()), true);
        if (! is_array($manifest)) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason_codes' => ['RELEASE_MANIFEST_INVALID']];
        }
        $seal = (string) data_get($manifest, 'seal_checksum');
        $unsigned = $manifest;
        unset($unsigned['seal_checksum']);
        $snapshot = $this->snapshot();
        $reasons = [];
        if ($seal === '' || ! hash_equals($seal, $this->hash($unsigned))) {
            $reasons[] = 'SEAL_CHECKSUM_MISMATCH';
        }
        foreach (['git_sha', 'source_checksum', 'migration_checksum', 'schema_checksum', 'config_checksum'] as $field) {
            if ((string) data_get($manifest, $field) !== (string) data_get($snapshot, $field)) {
                $reasons[] = strtoupper($field).'_MISMATCH';
            }
        }
        if (data_get($manifest, 'dirty_worktree') !== false || $snapshot['dirty_worktree']) {
            $reasons[] = 'RELEASE_SCOPE_DIRTY';
        }
        if (data_get($manifest, 'tests_passed') !== true || ! filled(data_get($manifest, 'test_run_id'))) {
            $reasons[] = 'TEST_ATTESTATION_INVALID';
        }
        if ($snapshot['pending_migrations'] !== []) {
            $reasons[] = 'PENDING_MIGRATIONS';
        }

        return [
            'protocol' => self::PROTOCOL,
            'status' => $reasons === [] ? 'verified' : 'blocked',
            'reason_codes' => array_values(array_unique($reasons)),
            'manifest' => $manifest,
            'snapshot' => $snapshot,
        ];
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $scope = [
            'app', 'bootstrap', 'config', 'database/migrations', 'routes', 'scripts',
            'composer.json', 'composer.lock', 'package.json', 'package-lock.json',
            'ecosystem.config.cjs', 'artisan', '.env.example',
        ];
        $status = $this->git(['status', '--porcelain=v1', '--', ...$scope], false);
        $migrationFiles = array_values(array_filter($this->files([database_path('migrations')]), fn (string $path): bool => str_ends_with($path, '.php')));
        $configFiles = array_values(array_filter($this->files([config_path()]), fn (string $path): bool => str_ends_with($path, '.php')));
        $sourceRoots = collect($scope)->map(fn (string $path): string => base_path($path))->all();
        $sourceFiles = $this->files($sourceRoots);
        $migrationFilesByName = app('migrator')->getMigrationFiles(database_path('migrations'));
        $ran = collect(app('migrator')->getRepository()->getRan())->map('strval');
        $pending = collect(array_keys($migrationFilesByName))->reject(fn (string $migration): bool => $ran->contains($migration))->values()->all();

        return [
            'git_sha' => trim($this->git(['rev-parse', 'HEAD'])),
            'dirty_worktree' => trim($status) !== '',
            'dirty_entries' => array_values(array_filter(preg_split('/\r?\n/', trim($status)) ?: [])),
            'release_scope' => $scope,
            'source_checksum' => $this->fileChecksum($sourceFiles),
            'migration_checksum' => $this->fileChecksum($migrationFiles),
            'schema_checksum' => $this->schemaChecksum(),
            'config_checksum' => $this->fileChecksum($configFiles),
            'pending_migrations' => $pending,
        ];
    }

    private function path(): string
    {
        return (string) config('services.release_seal.manifest_path', storage_path('app/release/release-manifest.json'));
    }

    /** @param list<string> $arguments */
    private function git(array $arguments, bool $throw = true): string
    {
        $process = new Process(['git', ...$arguments], base_path());
        $process->setTimeout(30)->run();
        if ($throw && ! $process->isSuccessful()) {
            throw new \RuntimeException('RELEASE_GIT_INSPECTION_FAILED');
        }

        return $process->getOutput();
    }

    /** @param list<string> $roots @return list<string> */
    private function files(array $roots): array
    {
        $files = [];
        foreach ($roots as $root) {
            if (is_file($root)) {
                $files[] = realpath($root) ?: $root;

                continue;
            }
            if (! is_dir($root)) {
                continue;
            }
            foreach (File::allFiles($root) as $file) {
                $files[] = $file->getRealPath();
            }
        }
        sort($files, SORT_STRING);

        return array_values(array_unique($files));
    }

    /** @param list<string> $files */
    private function fileChecksum(array $files): string
    {
        $rows = [];
        foreach ($files as $path) {
            $relative = str_replace('\\', '/', ltrim(str_replace(base_path(), '', $path), '\\/'));
            $rows[$relative] = hash_file('sha512', $path);
        }
        ksort($rows);

        return $this->hash($rows);
    }

    private function schemaChecksum(): string
    {
        $builder = DB::connection()->getSchemaBuilder();
        $tables = collect($builder->getTables())->map(fn (array $table): string => (string) ($table['name'] ?? $table['table_name'] ?? ''))->filter()->sort()->values();
        $schema = [];
        foreach ($tables as $table) {
            $schema[$table] = collect($builder->getColumns($table))->map(fn (array $column): array => [
                'name' => $column['name'] ?? null,
                'type' => $column['type_name'] ?? $column['type'] ?? null,
                'nullable' => $column['nullable'] ?? null,
                'default' => $column['default'] ?? null,
            ])->sortBy('name')->values()->all();
        }

        return $this->hash($schema);
    }

    private function hash(array $payload): string
    {
        return hash('sha512', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }
}
