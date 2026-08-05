<?php

namespace App\Console\Commands;

use App\Models\AiLaboratory;
use App\Services\LabPopulationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecordLabDataEdgeAudit extends Command
{
    protected $signature = 'trading:lab-data-edge-audit {symbol} {--timeframe=H1} {--generation= : Generation that triggered the audit} {--finding= : Auditable finding and disposition}';

    protected $description = 'Record the required data/edge audit before allowing another targeted lab generation';

    public function handle(): int
    {
        $finding = trim((string) $this->option('finding'));
        if ($finding === '') {
            $this->error('--finding is required; record what was checked and what was changed/falsified.');
            return self::INVALID;
        }

        $symbol = strtoupper((string) $this->argument('symbol'));
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $lab = AiLaboratory::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->firstOrFail();
        $generation = $lab->generations()->latest('generation')->first();
        if ($this->option('generation') !== null && (int) $this->option('generation') !== (int) $generation?->generation) {
            $this->error('Audit generation latest laboratory generation bilan mos emas; eski evidence ustiga unlock qilinmadi.');
            return self::FAILURE;
        }
        if (! $generation) {
            $this->error('Laboratory generation topilmadi.');
            return self::FAILURE;
        }
        // `screened` is a completed evidence boundary.  It remains an
        // active-generation lock for ordinary population creation, but a
        // data/edge audit is exactly the explicit handoff that is allowed to
        // consume that completed failure report.  Never apply this exception
        // while an agent or immutable replay run is still active.
        $screenedAuditBoundary = (string) $generation->status === 'screened'
            && ! $generation->agents()
                ->whereIn('lifecycle_status', ['draft', 'queued', 'training', 'screening', 'full_queued', 'full_validation'])
                ->exists()
            && ! DB::table('lab_evaluation_runs')
                ->where('lab_generation_id', $generation->id)
                ->whereNull('finished_at')
                ->exists();
        if (in_array((string) $generation->status, LabPopulationService::ACTIVE_GENERATION_STATUSES, true)
            && ! $screenedAuditBoundary) {
            $this->warn("G{$generation->generation} hali {$generation->status}; audit unlock generation tugagandan keyin bajariladi.");
            return self::SUCCESS;
        }

        $context = (array) $generation->trigger_context;
        $context['data_edge_audit'] = [
            'protocol' => 'data_edge_audit_v1',
            'recorded_at' => now()->utc()->toIso8601String(),
            'finding' => $finding,
            'generation' => $generation->generation,
            'promotion_evidence' => false,
            'rule' => 'Audit unlocks research creation only; all screening/full/forward/paper gates remain unchanged.',
        ];
        $report = (array) data_get($context, 'latest_generation_report', []);
        $report['next_action'] = 'data_edge_audit_completed';
        $report['data_edge_audit'] = $context['data_edge_audit'];
        $context['latest_generation_report'] = $report;
        $generation->update(['trigger_context' => $context]);

        $this->info("{$symbol} G{$generation->generation}: data/edge audit recorded; next generation creation unlocked for an explicit data_edge_audit trigger.");
        return self::SUCCESS;
    }
}
