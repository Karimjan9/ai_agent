<?php

namespace App\Console\Commands;

use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\LabGeneration;
use App\Services\FailureWoundSetService;
use App\Services\OperatorApprovalService;
use Illuminate\Console\Command;
use RuntimeException;

/** Backfills sealed G44 wound cases without changing any gate decision. */
class MaterializeFailureWoundSet extends Command
{
    protected $signature = 'trading:materialize-failure-wound-set {symbol=XAUUSD} {--timeframe=H1} {--generation=} {--apply} {--approved-by=} {--approval-reason=} {--json}';

    protected $description = 'Materialize sealed regression wounds from existing screening failures';

    public function handle(FailureWoundSetService $wounds, OperatorApprovalService $approvals): int
    {
        $symbol = strtoupper((string) $this->argument('symbol'));
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $lab = AiLaboratory::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->first();
        if (! $lab) return $this->report(['action' => 'blocked', 'message' => 'Laboratory not found.'], self::FAILURE);

        $generationQuery = $lab->generations();
        if ($this->option('generation') !== null) {
            $generationQuery->where('generation', (int) $this->option('generation'));
        }
        $generation = $generationQuery->latest('generation')->first();
        if (! $generation) return $this->report(['action' => 'blocked', 'message' => 'Generation not found.'], self::FAILURE);

        $decisions = CandidateGateDecision::query()
            ->with('labAgent.modelVersion')
            ->where('stage', 'screening')
            ->where('decision', 'failed')
            ->whereHas('labAgent', fn ($query) => $query->where('lab_generation_id', $generation->id))
            ->get();
        $scope = [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'generation_id' => (int) $generation->id,
            'generation' => (int) $generation->generation,
            'decision_count' => $decisions->count(),
            'promotion_evidence' => false,
        ];

        $preview = $decisions->map(function (CandidateGateDecision $decision): array {
            return [
                'agent_id' => (int) $decision->lab_agent_id,
                'reason_codes' => (array) $decision->reason_codes,
                'wound_targets' => array_values(array_filter([
                    in_array('FAILED_TEMPORAL_CHUNK_SURVIVAL', (array) $decision->reason_codes, true) ? 'temporal_chunk' : null,
                    in_array('FAILED_CALENDAR_MONTH_SURVIVAL', (array) $decision->reason_codes, true)
                        || in_array('FAILED_MONTHLY_SURVIVAL', (array) $decision->reason_codes, true) ? 'calendar_month' : null,
                    in_array('FAILED_TRAIN_FORWARD_GAP', (array) $decision->reason_codes, true) ? 'train_forward_gap' : null,
                    in_array('FAILED_STRESS_COST', (array) $decision->reason_codes, true)
                        || in_array('FAILED_EXECUTION_STRESS_GATE', (array) $decision->reason_codes, true) ? 'cost_exit_stress' : null,
                ])),
            ];
        })->values()->all();

        if (! (bool) $this->option('apply')) {
            return $this->report($scope + ['action' => 'dry_run', 'preview' => $preview]);
        }

        try {
            $approval = $approvals->requireForApply(
                'materialize-failure-wound-set',
                $this->option('approved-by'),
                $this->option('approval-reason'),
                $scope,
            );
        } catch (RuntimeException $exception) {
            return $this->report($scope + ['action' => 'blocked', 'message' => $exception->getMessage()], self::FAILURE);
        }

        $sealed = [];
        foreach ($decisions as $decision) {
            if (! $decision->labAgent) continue;
            $sealed = [...$sealed, ...$wounds->sealFromScreening(
                $decision->labAgent,
                (array) $decision->metrics,
                (array) $decision->reason_codes,
            )];
        }

        return $this->report($scope + [
            'action' => 'applied',
            'sealed_count' => count($sealed),
            'sealed' => $sealed,
            'operator_approval' => $approval,
        ]);
    }

    private function report(array $payload, int $exit = self::SUCCESS): int
    {
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ((bool) $this->option('json')) $this->line((string) $encoded);
        elseif (($payload['action'] ?? '') === 'blocked') $this->error((string) ($payload['message'] ?? 'Blocked.'));
        else $this->info('Failure wound set '.$payload['action'].'.');

        return $exit;
    }
}
