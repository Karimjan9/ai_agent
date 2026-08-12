<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('lab_trial_ledger') || ! Schema::hasColumn('lab_trial_ledger', 'identity_fingerprint')) {
            return;
        }

        // These rows are historical and remain outside the canonical
        // selection/evolution plane. Give each one a deterministic audit
        // locator so a missing hash is never represented by a null identity.
        DB::table('lab_trial_ledger')
            ->where('identity_status', 'legacy_unresolved')
            ->whereNull('identity_fingerprint')
            ->orderBy('id')
            ->chunkById(250, function ($rows): void {
                foreach ($rows as $row) {
                    $fingerprint = hash('sha256', json_encode([
                        'protocol' => 'lab_trial_identity_legacy_backfill_v1',
                        'row_id' => (int) $row->id,
                    ], JSON_UNESCAPED_SLASHES));

                    DB::table('lab_trial_ledger')
                        ->where('id', $row->id)
                        ->whereNull('identity_fingerprint')
                        ->update(['identity_fingerprint' => $fingerprint]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('lab_trial_ledger') || ! Schema::hasColumn('lab_trial_ledger', 'identity_fingerprint')) {
            return;
        }

        DB::table('lab_trial_ledger')
            ->where('identity_status', 'legacy_unresolved')
            ->orderBy('id')
            ->chunkById(250, function ($rows): void {
                foreach ($rows as $row) {
                    $fingerprint = hash('sha256', json_encode([
                        'protocol' => 'lab_trial_identity_legacy_backfill_v1',
                        'row_id' => (int) $row->id,
                    ], JSON_UNESCAPED_SLASHES));

                    DB::table('lab_trial_ledger')
                        ->where('id', $row->id)
                        ->where('identity_fingerprint', $fingerprint)
                        ->update(['identity_fingerprint' => null]);
                }
            });
    }
};
