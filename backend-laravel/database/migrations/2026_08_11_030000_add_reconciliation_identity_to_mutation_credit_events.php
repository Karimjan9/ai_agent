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
            if (! Schema::hasColumn('lab_mutation_credit_events', 'temporal_window_key')) {
                $table->string('temporal_window_key', 128)->nullable()->after('evidence_run_ids');
            }
            if (! Schema::hasColumn('lab_mutation_credit_events', 'reconciliation_key')) {
                $table->string('reconciliation_key', 64)->nullable()->after('temporal_window_key');
            }
        });

        DB::table('lab_mutation_credit_events')
            ->orderBy('id')
            ->get(['id', 'lab_generation_id', 'lab_agent_id', 'mutation_memory_id', 'parameter_key', 'outcome', 'evidence_run_ids', 'payload'])
            ->each(function (object $row): void {
                $payload = is_string($row->payload) ? (json_decode($row->payload, true) ?: []) : ((array) $row->payload);
                $runs = is_string($row->evidence_run_ids)
                    ? (json_decode($row->evidence_run_ids, true) ?: [])
                    : ((array) $row->evidence_run_ids);
                $primary = (string) data_get($payload, 'primary_evidence_run_id', $runs[0] ?? '');
                $temporal = (string) data_get($payload, 'temporal_window_key', 'legacy:'.$row->id);
                $reconciliation = hash('sha256', json_encode([
                    'protocol' => 'mutation_credit_reconciliation_v1',
                    'generation_id' => $row->lab_generation_id,
                    'agent_id' => $row->lab_agent_id,
                    'mutation_memory_id' => $row->mutation_memory_id,
                    'parameter_key' => $row->parameter_key,
                    'outcome' => $row->outcome,
                    'primary_evidence_run_id' => $primary,
                    'temporal_window_key' => $temporal,
                ], JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));

                DB::table('lab_mutation_credit_events')->where('id', $row->id)->update([
                    'temporal_window_key' => $temporal,
                    'reconciliation_key' => $reconciliation,
                    'updated_at' => now(),
                ]);
            });

        Schema::table('lab_mutation_credit_events', function (Blueprint $table): void {
            $table->unique('reconciliation_key', 'lab_mutation_credit_reconciliation_unique');
            $table->index(['lab_agent_id', 'temporal_window_key'], 'lab_mutation_agent_temporal_window_idx');
        });
    }

    public function down(): void
    {
        Schema::table('lab_mutation_credit_events', function (Blueprint $table): void {
            $table->dropUnique('lab_mutation_credit_reconciliation_unique');
            $table->dropIndex('lab_mutation_agent_temporal_window_idx');
            $table->dropColumn(['temporal_window_key', 'reconciliation_key']);
        });
    }
};
