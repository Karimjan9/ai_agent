<?php

namespace App\Console\Commands;

use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Services\LabImmutableEvidenceService;
use App\Services\MutationObservabilityService;
use Illuminate\Console\Command;

/**
 * Rebuilds the current screening projection after a contract deployment.
 *
 * The original gate/evidence revision is retained in lab_gate_decision_events;
 * this command only tightens the mutable selector when an already screened
 * shadow child has no causal behaviour delta. It never grants credit or
 * changes a promotion gate to passed.
 */
class ReconcileMutationContracts extends Command
{
    protected $signature = 'trading:reconcile-mutation-contracts
        {symbol?}
        {--timeframe=H1}
        {--generation=}
        {--limit=100 : Maximum agents to process in one bounded batch}
        {--after-id=0 : Continue after this LabAgent id}
        {--apply}
        {--json}';

    protected $description = 'Reconcile screening projections with the executable mutation/causal control contract';

    public function handle(MutationObservabilityService $observability, LabImmutableEvidenceService $evidence): int
    {
        $symbol = $this->argument('symbol') ? strtoupper((string) $this->argument('symbol')) : null;
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $limit = max(1, min(250, (int) $this->option('limit')));
        $afterId = max(0, (int) $this->option('after-id'));
        $query = LabAgent::query()
            // The observability assessor compares against the exact
            // same-generation control. Eager-load the cohort once so a
            // historical reconciliation cannot issue one generation-agent
            // query per row.
            ->with(['modelVersion', 'generation.agents.modelVersion'])
            ->whereIn('lifecycle_status', ['screened', 'challenger', 'rejected', 'technical_quarantine'])
            ->where('timeframe', $timeframe)
            ->when($symbol, fn ($builder) => $builder->where('symbol', $symbol))
            ->when($this->option('generation') !== null, function ($builder): void {
                $builder->whereHas('generation', fn ($generation) => $generation->where('generation', (int) $this->option('generation')));
            })
            ->when($afterId > 0, fn ($builder) => $builder->where('id', '>', $afterId));

        $rows = [];
        $agents = $query->orderBy('id')->limit($limit)->get();
        $decisions = CandidateGateDecision::query()
            ->whereIn('lab_agent_id', $agents->pluck('id'))
            ->where('stage', 'screening')
            ->get()
            ->keyBy('lab_agent_id');
        foreach ($agents as $agent) {
            $result = (array) data_get($agent->modelVersion?->metadata, 'last_screen_result', []);
            $decision = $decisions->get($agent->id);
            if ($result === [] || ! $decision) {
                $rows[] = [
                    'agent_id' => (int) $agent->id,
                    'status' => 'skipped_missing_screen_projection',
                    'promotion_evidence' => false,
                ];
                continue;
            }

            $assessment = $observability->assess($agent, [
                ...$result,
                'reason_codes' => (array) $decision->reason_codes,
            ]);
            $contract = (array) data_get($assessment, 'mutation_contract', []);
            $required = (bool) data_get($contract, 'required', false);
            $passed = data_get($contract, 'status') === 'passed';
            $strictReason = null;
            if ($required && ! $passed) {
                $strictReason = data_get($contract, 'status') === 'failed_evidence_incomplete'
                    ? 'FAILED_BEHAVIORAL_MUTATION_EVIDENCE'
                    : 'FAILED_BEHAVIORAL_MUTATION_CONTRACT';
            }

            $row = [
                'agent_id' => (int) $agent->id,
                'decision_before' => (string) $decision->decision,
                'contract_required' => $required,
                'contract_status' => data_get($contract, 'status'),
                'classification' => data_get($assessment, 'classification'),
                'control_pair_status' => data_get($contract, 'control_pair_status'),
                'strict_reason' => $strictReason,
                'applied' => false,
                'promotion_evidence' => false,
            ];

            if ((bool) $this->option('apply')) {
                $observability->record($agent, $assessment);
                $reasons = array_values(array_unique(array_filter([
                    ...((array) $decision->reason_codes),
                    $strictReason,
                ])));
                // Reconciliation is strictly monotone with respect to the
                // screening gate.  It may append a failure reason, but it
                // must never promote an existing failed/insufficient or
                // otherwise non-passed decision merely because this audit
                // found no new reason to add.
                $decisionBefore = (string) $decision->decision;
                $decisionValue = $strictReason !== null
                    ? 'failed'
                    : $decisionBefore;
                $decision->update([
                    'decision' => $decisionValue,
                    'reason_codes' => $reasons,
                    'metrics' => app(LabImmutableEvidenceService::class)->projectionPayload([
                        ...(array) $decision->metrics,
                        'mutation_observability' => $assessment,
                        'promotion_evidence' => false,
                    ]),
                    'evaluated_at' => now(),
                ]);
                $evidence->recordGateDecision($decision->fresh(), [
                    'projection' => 'candidate_gate_decisions',
                    'reconciliation' => self::class,
                    'contract_revision' => MutationObservabilityService::PROTOCOL,
                    'gate_tightening_only' => true,
                    'promotion_evidence' => false,
                ]);
                $row['decision_after'] = $decisionValue;
                $row['applied'] = true;
            }
            $rows[] = $row;
        }

        $payload = [
            'protocol' => MutationObservabilityService::PROTOCOL,
            'apply' => (bool) $this->option('apply'),
            'limit' => $limit,
            'after_id' => $afterId,
            'inspected' => $agents->count(),
            'has_more' => $agents->count() === $limit,
            'next_after_id' => $agents->last()?->id,
            'rows' => $rows,
            'promotion_evidence' => false,
        ];
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf(
                'Mutation contract reconciliation %s: %d agent(s).',
                (bool) $this->option('apply') ? 'applied' : 'dry-run',
                count($rows),
            ));
        }

        return self::SUCCESS;
    }
}
