<?php

namespace App\Console\Commands;

use App\Models\LabTemporalAblationRun;
use App\Services\TemporalAblationRunnerService;
use Illuminate\Console\Command;

class ReconcileTemporalAblationAudit extends Command
{
    protected $signature = 'trading:reconcile-temporal-ablation-audit {--run-key=} {--json}';

    protected $description = 'Synchronize temporal ablation DB decisions with their current system-event projection';

    public function handle(TemporalAblationRunnerService $runner): int
    {
        $query = LabTemporalAblationRun::query()
            ->where(function ($builder): void {
                $builder->whereIn('status', ['blocked', 'failed', 'superseded'])
                    ->orWhereIn('decision', [
                        'failed',
                        'TEMPORAL_ABLATION_NOT_QUALIFIED',
                        'TEMPORAL_ABLATION_EXECUTION_FAILED',
                    ]);
            })
            ->when($this->option('run-key'), fn ($builder) => $builder->where('run_key', (string) $this->option('run-key')))
            ->with(['laboratory', 'generation']);

        $rows = [];
        foreach ($query->get() as $run) {
            $event = $runner->syncAuditEvent($run);
            $rows[] = [
                'run_id' => $run->id,
                'run_key' => $run->run_key,
                'status' => $run->status,
                'reason_codes' => $run->reason_codes,
                'event_id' => $event->id,
                'payload_hash' => data_get($event->payload, 'payload_hash'),
            ];
        }

        $payload = [
            'protocol' => 'temporal_ablation_audit_reconciliation_v1',
            'synchronized' => count($rows),
            'rows' => $rows,
            'promotion_evidence' => false,
        ];
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('Temporal ablation audit synchronized: '.count($rows));
        }

        return self::SUCCESS;
    }
}
