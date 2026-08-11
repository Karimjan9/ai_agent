<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lab_mutation_credit_events', function (Blueprint $table): void {
            $table->string('evidence_fingerprint', 64)->nullable()->after('payload');
        });

        // Existing rows are historical facts. Give each one a stable legacy
        // identity so the new unique key can be introduced without merging
        // or deleting old evidence.
        DB::table('lab_mutation_credit_events')->orderBy('id')->chunkById(250, function ($events): void {
            foreach ($events as $event) {
                $fingerprint = hash('sha256', json_encode([
                    'protocol' => 'legacy_lab_mutation_credit_event_v1',
                    'legacy_event_id' => (int) $event->id,
                ], JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));
                DB::table('lab_mutation_credit_events')->where('id', $event->id)->update([
                    'evidence_fingerprint' => $fingerprint,
                ]);
            }
        });

        Schema::table('lab_mutation_credit_events', function (Blueprint $table): void {
            $table->unique('evidence_fingerprint', 'lab_mutation_credit_event_fingerprint_unique');
        });
    }

    public function down(): void
    {
        Schema::table('lab_mutation_credit_events', function (Blueprint $table): void {
            $table->dropUnique('lab_mutation_credit_event_fingerprint_unique');
            $table->dropColumn('evidence_fingerprint');
        });
    }
};
