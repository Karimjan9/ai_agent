<?php

namespace App\Console\Commands;

use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Services\LabGenerationContextService;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabQueueJobInspector;
use App\Services\OperatorApprovalService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Closes a normal cohort whose causal contract is invalid without treating
 * any of its observations as strategy evidence.  This command never repairs
 * or relaxes the contract; it only preserves the history and stops the
 * invalid cohort from remaining an active pipeline owner.
 */
class QuarantineInvalidNormalCohort extends Command
{
    protected $signature = 'trading:quarantine-invalid-normal-cohort
        {symbol : Laboratory symbol}
        {--timeframe=H1 : Laboratory timeframe}
        {--generation= : Exact generation number}
        {--apply : Persist technical quarantine}
        {--approved-by=}
        {--approval-reason=}';

    protected $description = 'Fail-closed quarantine for an invalid normal causal cohort; preserves all evidence';

    public function handle(
        LabImmutableEvidenceService $evidence,
        LabQueueJobInspector $queue,
        LabGenerationContextService $generationContext,
        OperatorApprovalService $approvals,
    ): int {
        $symbol = strtoupper(trim((string) $this->argument('symbol')));
        $timeframe = strtoupper(trim((string) $this->option('timeframe')));
        $generationNumber = (int) $this->option('generation');
        if ($generationNumber <= 0) {
            $this->error('--generation exact qiymat bilan majburiy.');
            return self::FAILURE;
        }

        $generation = LabGeneration::query()
            ->with('laboratory')
            ->where('generation', $generationNumber)
            ->whereHas('laboratory', fn ($query) => $query
                ->where('symbol', $symbol)
                ->where('timeframe', $timeframe))
            ->first();
        if (! $generation) {
            $this->error("{$symbol} {$timeframe} G{$generationNumber} topilmadi.");
            return self::FAILURE;
        }

        $contract = $this->contractIssues($generation);
        $agentIds = $generation->agents()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $queueState = $queue->generationQueueBacklog($agentIds);
        $activeStatuses = ['queued', 'screening', 'full_queued', 'full_validation', 'training'];
        $activeAgents = $generation->agents()->whereIn('lifecycle_status', $activeStatuses)->count();
        $activeRuns = \App\Models\LabEvaluationRun::query()
            ->where('lab_generation_id', $generation->id)
            ->whereIn('status', ['queued', 'started', 'running', 'processing'])
            ->count();

        $result = [
            'protocol' => 'normal_causal_contract_quarantine_v1',
            'generation_id' => (int) $generation->id,
            'generation' => $generationNumber,
            'mode' => data_get($generation->trigger_context, 'control_pairing_contract.mode'),
            'contract_issues' => $contract['issues'],
            'contract_metrics' => $contract['metrics'],
            'queue_total' => $queueState['total'] ?? null,
            'active_agents' => $activeAgents,
            'active_runs' => $activeRuns,
            'promotion_evidence' => false,
        ];

        if ($contract['issues'] === []) {
            $this->error('Normal causal contract invalid emas; quarantine qilinmadi.');
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            return self::FAILURE;
        }
        if (($queueState['available'] ?? true) === false || $queueState['total'] === null) {
            $this->error('Queue state unknown; fail-closed.');
            return self::FAILURE;
        }
        if ((int) $queueState['total'] > 0 || $activeAgents > 0 || $activeRuns > 0) {
            $this->error('Active agent/run/queue bor; avval lifecycle recovery tugashi kerak.');
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            return self::FAILURE;
        }

        if (! (bool) $this->option('apply')) {
            $result['status'] = 'would_quarantine';
            $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            return self::SUCCESS;
        }

        try {
            $approvals->requireForApply('quarantine-invalid-normal-cohort', $this->option('approved-by'), $this->option('approval-reason'), [
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'generation' => $generationNumber,
                'issues' => $contract['issues'],
            ]);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        $quarantined = 0;
        foreach ($generation->fresh(['agents.modelVersion'])->agents as $agent) {
            if ((string) $agent->lifecycle_status === 'technical_quarantine') continue;
            $fromStatus = (string) $agent->lifecycle_status;
            $agent->update([
                'lifecycle_status' => 'technical_quarantine',
                'decision_reason' => 'Normal causal contract incomplete; cohort quarantined without strategy verdict or promotion evidence.',
            ]);
            $evidence->recordLifecycle($agent->fresh(), 'normal_causal_contract_quarantine', [
                'protocol' => 'normal_causal_contract_quarantine_v1',
                'reason_code' => 'NORMAL_CAUSAL_CONTRACT_INCOMPLETE',
                'issues' => $contract['issues'],
                'metrics' => $contract['metrics'],
                'evidence_preserved' => true,
                'quality_verdict' => 'withheld',
                'promotion_evidence' => false,
            ], 'screening', null, null, self::class, null, $fromStatus, 'technical_quarantine');
            $quarantined++;
        }

        $generationContext->updateWithAttributes($generation, [
            'status' => 'technical_quarantine',
            'completed_at' => now(),
        ], function (array $context) use ($contract): array {
            data_set($context, 'integrity_repair.normal_causal_contract', [
                'protocol' => 'normal_causal_contract_quarantine_v1',
                'issues' => $contract['issues'],
                'metrics' => $contract['metrics'],
                'quarantined_at' => now()->utc()->toIso8601String(),
                'evidence_preserved' => true,
                'quality_verdict' => 'withheld',
                'promotion_evidence' => false,
            ]);
            return $context;
        });

        $result['status'] = 'applied';
        $result['quarantined_agents'] = $quarantined;
        $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        return self::SUCCESS;
    }

    /** @return array{issues: array<int, string>, metrics: array<string, mixed>} */
    private function contractIssues(LabGeneration $generation): array
    {
        $context = (array) ($generation->trigger_context ?? []);
        $mode = (string) data_get($context, 'research_allocation_budget.mode', data_get($context, 'control_pairing_contract.mode', ''));
        if ($mode !== 'normal_research') return ['issues' => [], 'metrics' => []];

        $pairing = (array) data_get($context, 'control_pairing_contract', []);
        $structural = (array) data_get($context, 'structural_research_contract', []);
        $issues = [];
        if ((string) data_get($pairing, 'protocol', '') !== 'frozen_control_pair_v1') $issues[] = 'NORMAL_CONTROL_PAIR_PROTOCOL_MISSING';
        if (! (bool) data_get($pairing, 'allowed', false)) $issues[] = 'NORMAL_CONTROL_CANDIDATE_PAIR_INCOMPLETE';
        if ((array) data_get($pairing, 'missing_execution_lanes', []) !== []) $issues[] = 'NORMAL_CONTROL_LANE_MISSING';
        if ((array) data_get($pairing, 'missing_candidate_pairs', []) !== []) $issues[] = 'NORMAL_CANDIDATE_PAIR_MISSING';
        $structuralExpected = (bool) data_get($context, 'normal_structural_research_expected', true);
        if ($structuralExpected && (string) data_get($structural, 'protocol', '') !== 'normal_structural_research_v1') $issues[] = 'NORMAL_STRUCTURAL_CONTRACT_MISSING';
        if ($structuralExpected && (int) data_get($structural, 'structural_candidate_count', 0) < 1) $issues[] = 'NORMAL_STRUCTURAL_CANDIDATE_MISSING';

        return [
            'issues' => array_values(array_unique($issues)),
            'metrics' => [
                'generation_id' => (int) $generation->id,
                'generation' => (int) $generation->generation,
                'pairing_allowed' => (bool) data_get($pairing, 'allowed', false),
                'missing_execution_lanes' => (array) data_get($pairing, 'missing_execution_lanes', []),
                'missing_candidate_pairs' => (array) data_get($pairing, 'missing_candidate_pairs', []),
                'structural_expected' => $structuralExpected,
                'structural_candidate_count' => (int) data_get($structural, 'structural_candidate_count', 0),
                'promotion_evidence' => false,
            ],
        ];
    }
}
