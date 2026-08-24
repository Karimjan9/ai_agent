<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;

/**
 * Reprojects historical immutable screening artifacts into a new response-map
 * projection. It never edits a run/artifact, dispatches a replay, or fakes a
 * missing hash. The caller must explicitly invoke it after operator review.
 */
class CausalObservationReconciliationService
{
    public const PROTOCOL = 'causal_observation_append_only_reconciliation_v1';

    public function __construct(
        private LabImmutableEvidenceService $evidence,
        private MutationResponseMapService $responseMaps,
        private LearningLaneService $learningLane,
        private MicroReplayService $microReplay,
    ) {}

    /** @return array<string, mixed> */
    public function previewGeneration(LabGeneration $generation): array
    {
        $agents = $generation->agents()->with('modelVersion')->get();
        $rows = $agents->map(fn (LabAgent $agent): array => $this->previewAgent($agent))->values();

        return [
            'protocol' => self::PROTOCOL, 'generation_id' => $generation->id,
            'eligible_candidate_count' => $rows->where('eligible', true)->count(),
            'ineligible_candidate_count' => $rows->where('eligible', false)->count(),
            'agents' => $rows->all(), 'action' => 'operator_review_required_before_append_only_projection',
            'dispatch' => false, 'retry' => false, 'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function previewAgent(LabAgent $agent): array
    {
        $run = $this->screeningRun($agent);
        if (! $run) {
            return $this->ineligible($agent, 'SCREENING_RUN_MISSING');
        }
        try {
            $payload = $this->evidence->latestArtifactPayload($run) ?? [];
        } catch (\Throwable $exception) {
            return $this->ineligible($agent, 'IMMUTABLE_ARTIFACT_UNREADABLE', $exception->getMessage());
        }
        $projection = $this->causalProjection($run, $payload);
        $missing = $this->missingCausalFields($projection);
        $control = (bool) data_get($agent->modelVersion?->metadata, 'control_contract.control_only', false);

        return [
            'agent_id' => $agent->id, 'run_id' => $run->run_id, 'control' => $control,
            'eligible' => $missing === [], 'missing' => $missing,
            'source_response_sha256' => $run->response_hash, 'source_artifact_immutable' => true,
            'promotion_evidence' => false,
        ];
    }

    /**
     * Explicit write-side maintenance action. It is intentionally not wired
     * to a scheduler, generation builder, retry path, or queue worker.
     *
     * @return array<string, mixed>
     */
    public function reconcileAgent(LabAgent $agent): array
    {
        $preview = $this->previewAgent($agent);
        if (! ($preview['eligible'] ?? false)) {
            return [...$preview, 'status' => 'not_reconciled'];
        }
        $run = $this->screeningRun($agent);
        $payload = $run ? ($this->evidence->latestArtifactPayload($run) ?? []) : [];
        $projection = [
            ...($run ? $this->causalProjection($run, $payload) : $payload),
            'evidence_run_id' => $run?->run_id,
            'causal_observation_reconciliation' => [
                'protocol' => self::PROTOCOL, 'source_run_id' => $run?->run_id,
                'source_response_sha256' => $run?->response_hash, 'append_only_projection' => true,
                'dispatch' => false, 'retry' => false, 'promotion_evidence' => false,
            ],
        ];
        $map = $this->responseMaps->recordScreening($agent->fresh(['modelVersion', 'generation']), $projection);
        if (! $map) {
            return [...$preview, 'status' => 'response_projection_failed'];
        }
        $pair = $this->learningLane->pairScreeningObservation($agent->fresh(['modelVersion', 'generation']), $projection, $map);
        $pairModel = filled(data_get($pair, 'id')) ? LabLearningLanePair::find((int) data_get($pair, 'id')) : null;
        $micro = $pairModel ? $this->microReplay->assessPair($pairModel, false) : null;

        return ['protocol' => self::PROTOCOL, 'status' => 'reconciled_append_only', 'agent_id' => $agent->id,
            'response_map_id' => data_get($map, 'id'), 'pair_id' => $pairModel?->id, 'micro_preview' => $micro,
            'dispatch' => false, 'retry' => false, 'promotion_evidence' => false];
    }

    /**
     * Rehydrate only facts already sealed across the immutable artifact and
     * run manifest. A complete trade ledger with N closed trades is the
     * evidence for N accepted exits; no count or hash is invented.
     *
     * @return array<string, mixed>
     */
    private function causalProjection(LabEvaluationRun $run, array $payload): array
    {
        $response = (array) $run->response_meta;
        $tradeLedgerComplete = data_get($response, 'trade_ledger_complete') === true;
        $totalTrades = data_get($payload, 'total_trades');
        $exitFunnel = (array) data_get($payload, 'exit_funnel', []);
        if ($exitFunnel === [] && $tradeLedgerComplete && is_numeric($totalTrades)) {
            $exitFunnel = [
                'accepted_exits' => (int) $totalTrades,
                'source' => 'immutable_complete_trade_ledger',
                'derived_from_trade_ledger_count' => true,
            ];
        }

        return [
            ...$payload,
            'trade_ledger_hash' => data_get($payload, 'trade_ledger_hash', $run->trade_ledger_hash),
            'event_ledger_hash' => data_get($payload, 'event_ledger_hash', data_get($response, 'event_ledger_hash')),
            'signal_decision_hash' => data_get($payload, 'signal_decision_hash', data_get($response, 'decision_trace_hash')),
            'parameter_hash' => data_get($payload, 'parameter_hash', $run->parameter_hash),
            'exit_funnel' => $exitFunnel,
        ];
    }

    private function screeningRun(LabAgent $agent): ?LabEvaluationRun
    {
        return LabEvaluationRun::query()->where('lab_agent_id', $agent->id)
            ->whereIn('phase', ['screening', 'screen'])->where('status', 'completed')->latest('id')->first();
    }

    /** @return array<int, string> */
    private function missingCausalFields(array $payload): array
    {
        $required = ['trade_ledger_hash', 'event_ledger_hash', 'signal_decision_hash', 'parameter_hash'];
        $missing = [];
        foreach ($required as $field) {
            if (! filled(data_get($payload, $field)) && ! filled(data_get($payload, 'mutation_observability.'.$field))) {
                $missing[] = $field;
            }
        }
        if ((array) data_get($payload, 'entry_funnel', []) === []) {
            $missing[] = 'entry_funnel';
        }
        if ((array) data_get($payload, 'exit_funnel', []) === []) {
            $missing[] = 'exit_funnel';
        }
        if (! is_numeric(data_get($payload, 'abstention_count', data_get($payload, 'temporal_survival.abstention_count')))) {
            $missing[] = 'abstention_count';
        }

        return $missing;
    }

    /** @return array<string, mixed> */
    private function ineligible(LabAgent $agent, string $reason, ?string $detail = null): array
    {
        return ['protocol' => self::PROTOCOL, 'agent_id' => $agent->id, 'eligible' => false, 'missing' => [$reason],
            'detail' => $detail, 'source_artifact_immutable' => true, 'promotion_evidence' => false];
    }
}
