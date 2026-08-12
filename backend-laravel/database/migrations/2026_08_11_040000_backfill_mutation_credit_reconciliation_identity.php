<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('lab_mutation_credit_events')
            ->whereNull('reconciliation_key')
            ->orderBy('id')
            ->get([
                'id', 'lab_generation_id', 'lab_agent_id', 'mutation_memory_id',
                'parameter_key', 'outcome', 'evidence_run_ids', 'payload',
            ])
            ->each(function (object $row): void {
                $payload = is_string($row->payload)
                    ? (json_decode($row->payload, true) ?: [])
                    : ((array) $row->payload);
                $runs = is_string($row->evidence_run_ids)
                    ? (json_decode($row->evidence_run_ids, true) ?: [])
                    : ((array) $row->evidence_run_ids);
                $primary = (string) data_get($payload, 'primary_evidence_run_id', $runs[0] ?? '');
                $temporal = (string) data_get($payload, 'temporal_window_key', '');
                if ($temporal === '') {
                    $windowIds = collect([
                        ...((array) data_get($payload, 'temporal_window_ids', [])),
                        ...((array) data_get($payload, 'verified_skill_contract.independent_forward_windows.window_ids', [])),
                        ...((array) data_get($payload, 'paired_experiment.independent_forward_windows.window_ids', [])),
                    ])->filter()->map(fn (mixed $id): string => (string) $id)->unique()->sort()->values()->all();
                    $temporal = $windowIds !== []
                        ? hash('sha256', json_encode(['protocol' => 'temporal_window_set_v1', 'window_ids' => $windowIds], JSON_UNESCAPED_SLASHES))
                        : 'legacy:unscoped:'.$row->id;
                }

                $reconciliation = hash('sha256', json_encode([
                    'protocol' => 'mutation_credit_reconciliation_v1',
                    'generation_id' => $row->lab_generation_id,
                    'agent_id' => $row->lab_agent_id,
                    'mutation_memory_id' => $row->mutation_memory_id,
                    'parameter_key' => $row->parameter_key,
                    'outcome' => $row->outcome,
                    'primary_evidence_run_id' => $primary,
                    'temporal_window_key' => $temporal,
                    'legacy_row_id' => $row->id,
                ], JSON_UNESCAPED_SLASHES));

                DB::table('lab_mutation_credit_events')->where('id', $row->id)->update([
                    'temporal_window_key' => $temporal,
                    'reconciliation_key' => $reconciliation,
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        // Identity backfills are append-only audit repairs; rolling them back
        // would re-open duplicate reconciliation risk for historical rows.
    }
};
