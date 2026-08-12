<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lab_trial_ledger')) return;

        // The old composite key contains nullable hashes.  On MySQL that
        // permits repeated NULL identities, so it cannot be the recovery
        // idempotency boundary.
        try {
            Schema::table('lab_trial_ledger', fn (Blueprint $table): mixed => $table->dropUnique('lab_trial_recovery_identity'));
        } catch (Throwable) {
            // A partially deployed database may already have removed it.
        }

        Schema::table('lab_trial_ledger', function (Blueprint $table): void {
            if (! Schema::hasColumn('lab_trial_ledger', 'identity_fingerprint')) {
                $table->char('identity_fingerprint', 64)->nullable()->after('execution_hash');
            }
            if (! Schema::hasColumn('lab_trial_ledger', 'identity_status')) {
                $table->string('identity_status', 32)->default('canonical')->after('identity_fingerprint');
            }
        });

        $seen = [];
        DB::table('lab_trial_ledger')->orderBy('id')->chunkById(250, function ($rows) use (&$seen): void {
            foreach ($rows as $row) {
                $run = $row->run_id !== null
                    ? DB::table('lab_evaluation_runs')->where('run_id', $row->run_id)->first(['data_hash', 'request_meta'])
                    : null;
                $dataHash = $this->validHash((string) $row->data_manifest_hash)
                    ? strtolower((string) $row->data_manifest_hash)
                    : $this->runDataHash($run);
                $executionHash = $this->validHash((string) $row->execution_hash)
                    ? strtolower((string) $row->execution_hash)
                    : null;

                if ($dataHash !== null) {
                    DB::table('lab_trial_ledger')->where('id', $row->id)->update([
                        'data_manifest_hash' => $dataHash,
                    ]);
                }

                $canonical = $dataHash !== null;
                $base = [
                    'protocol' => 'lab_trial_identity_v2',
                    'symbol' => strtoupper((string) $row->symbol),
                    'timeframe' => strtoupper((string) $row->timeframe),
                    'stage' => strtolower((string) $row->stage),
                    'parameter_hash' => (string) $row->parameter_hash,
                    'data_manifest_hash' => $dataHash,
                    'execution_hash' => $executionHash,
                ];
                $fingerprint = hash('sha256', json_encode($base, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));
                $status = 'canonical';
                if (! $canonical) {
                    $status = 'legacy_unresolved';
                    $fingerprint = hash('sha256', json_encode([
                        'protocol' => 'lab_trial_identity_legacy_v1', 'row_id' => (int) $row->id,
                    ], JSON_UNESCAPED_SLASHES));
                } elseif (isset($seen[$fingerprint])) {
                    // Preserve the old row for audit, but make it explicit
                    // that it is not an independent trial for DSR/selection.
                    $status = 'historical_duplicate';
                    $fingerprint = hash('sha256', json_encode([
                        'protocol' => 'lab_trial_identity_historical_duplicate_v1',
                        'canonical' => $fingerprint, 'row_id' => (int) $row->id,
                    ], JSON_UNESCAPED_SLASHES));
                }
                $seen[$canonical ? hash('sha256', json_encode($base, JSON_UNESCAPED_SLASHES)) : $fingerprint] = true;

                DB::table('lab_trial_ledger')->where('id', $row->id)->update([
                    'identity_fingerprint' => $fingerprint,
                    'identity_status' => $status,
                ]);
            }
        });

        Schema::table('lab_trial_ledger', function (Blueprint $table): void {
            $table->unique('identity_fingerprint', 'lab_trial_identity_fingerprint_unique');
            $table->index('identity_status', 'lab_trial_identity_status_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('lab_trial_ledger')) return;

        Schema::table('lab_trial_ledger', function (Blueprint $table): void {
            $table->dropUnique('lab_trial_identity_fingerprint_unique');
            $table->dropIndex('lab_trial_identity_status_idx');
            $table->dropColumn(['identity_fingerprint', 'identity_status']);
        });
    }

    private function runDataHash(?object $run): ?string
    {
        if (! $run) return null;
        $candidates = [
            $run->data_hash ?? null,
            data_get(json_decode((string) ($run->request_meta ?? ''), true), 'dataset_manifest.snapshot_sha256'),
            data_get(json_decode((string) ($run->request_meta ?? ''), true), 'dataset_manifest.data_hash'),
        ];
        foreach ($candidates as $candidate) {
            if ($this->validHash((string) $candidate)) return strtolower((string) $candidate);
        }

        return null;
    }

    private function validHash(string $value): bool
    {
        return (bool) preg_match('/^[a-f0-9]{64}$/i', trim($value));
    }
};
