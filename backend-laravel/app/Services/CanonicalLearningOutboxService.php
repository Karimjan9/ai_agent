<?php

namespace App\Services;

use App\Models\CanonicalLearningOutbox;
use App\Models\CapabilityCausalAttribution;
use App\Models\LabAgent;
use App\Models\LabLearningLaneDispatch;
use App\Models\LabLearningLanePair;
use App\Models\LabMutationResponseMap;
use App\Models\AgentLearningLesson;
use App\Models\AgentLearningSettlement;
use App\Models\LearningRecoveryEvent;
use Illuminate\Support\Facades\Schema;

/**
 * Durable learning-truth pipeline. A replay is never completed merely because
 * the evaluator returned: it becomes complete only after its canonical
 * episode and settlement have been written successfully.
 */
class CanonicalLearningOutboxService
{
    public const PROTOCOL = 'learning_truth_protocol_v1';

    /** @return array<string,mixed> */
    public function record(LabAgent $agent, LabLearningLanePair $pair, array $result, bool $causalCreditEligible, array $delta): array
    {
        $gate = app(LearningEvidenceGate::class)->allow($pair, $result, 'canonical_pending');
        if (! $gate['allowed']) {
            $this->markDiagnosticOnly($pair, implode(',', $gate['reasons']));

            return ['status' => $gate['status'], 'reason' => implode(',', $gate['reasons']), 'promotion_evidence' => false];
        }
        if (! $this->available()) {
            return $this->fail($pair, null, 'CANONICAL_OUTBOX_UNAVAILABLE');
        }

        $run = (string) data_get($result, 'evidence_run_id', 'none');
        $key = hash('sha256', implode('|', [self::PROTOCOL, 'canonical_episode', $pair->id, $run, $pair->candidate_data_hash, $pair->candidate_execution_hash]));
        $row = CanonicalLearningOutbox::query()->firstOrCreate(['idempotency_key' => $key], [
            'kind' => 'canonical_episode', 'status' => 'pending', 'pair_id' => $pair->id,
            'evidence_run_id' => $run, 'data_hash' => $pair->candidate_data_hash,
            'execution_hash' => $pair->candidate_execution_hash, 'payload' => [
                'protocol' => self::PROTOCOL, 'agent_id' => $agent->id,
                'causal_credit_eligible' => $causalCreditEligible, 'delta' => $delta,
                'result' => $result, 'promotion_evidence' => false,
            ],
        ]);
        $pair->update(['status' => 'canonical_pending', 'metadata' => [...((array) $pair->metadata), 'canonical_outbox_id' => $row->id, 'promotion_evidence' => false]]);

        return $this->process($row);
    }

    /** @return array<string,mixed> */
    public function process(CanonicalLearningOutbox $row): array
    {
        if ((string) $row->status === 'completed') {
            return ['status' => 'completed', 'outbox_id' => $row->id, 'promotion_evidence' => false];
        }
        $pair = $row->pair_id ? LabLearningLanePair::query()->with(['candidateResponseMap', 'controlResponseMap'])->find($row->pair_id) : null;
        $agent = $pair?->candidateAgent?->fresh(['modelVersion']);
        $gate = app(LearningEvidenceGate::class)->allow($pair, $row->evidence_run_id, 'replay_completed');
        if (! $pair || ! $agent || ! $gate['allowed']) {
            return $this->fail($pair, $row, implode(',', $gate['reasons'] ?: ['CONTROL_PAIR_INVALID_AT_SETTLEMENT']));
        }

        try {
            $payload = (array) $row->payload;
            $result = (array) data_get($payload, 'result', []);
            $map = $pair->candidateResponseMap;
            $kernel = app(LearningKernelService::class);
            $episode = $kernel->openEpisode($agent, [
                'decision_key' => 'learning-lane:pair:'.$pair->id.':'.$row->evidence_run_id,
                'symbol' => $pair->symbol, 'timeframe' => $pair->timeframe,
                'strategy_family' => $pair->strategy_family, 'stage' => 'full_replay',
                'decision' => 'MUTATE', 'context' => (array) data_get($pair->failure_signature, 'state', []),
                'data_manifest_hash' => $row->data_hash, 'execution_hash' => $row->execution_hash,
                'parameter_hash' => $map?->response_key,
            ]);
            if (! is_object($episode)) throw new \RuntimeException('CANONICAL_EPISODE_UNAVAILABLE');
            $trades = $this->tradeCount($result);
            $insufficient = $trades === 0 || $this->hasMissingRewardCoverage($result);
            $settled = $kernel->settleOutcome($episode, [
                'source_key' => 'learning-lane:full:'.$pair->id.':'.$row->evidence_run_id,
                'source_type' => LabLearningLanePair::class, 'source_id' => $pair->id,
                'outcome_status' => 'settled', 'failure_class' => data_get($pair->failure_signature, 'failure_type', $pair->target),
                'parameter_key' => $map?->parameter_key, 'independent_window_key' => $pair->independent_window_key,
                'control_present' => true,
                'evidence_state' => $insufficient ? 'insufficient_evidence' : ((bool) data_get($payload, 'causal_credit_eligible') && (bool) data_get($payload, 'delta.improved') ? 'positive' : 'negative'),
                'metrics' => $result,
            ]);
            if (! is_object(data_get($settled, 'settlement'))) throw new \RuntimeException('CANONICAL_SETTLEMENT_UNAVAILABLE');
            /** @var AgentLearningSettlement $settlement */
            $settlement = $settled['settlement'];
            $kernel->consolidate($settlement);
            $settlementGate = app(LearningEvidenceGate::class)->allow($pair, $row->evidence_run_id, 'canonical_settled');
            if (! $settlementGate['allowed']) throw new \RuntimeException(implode(',', $settlementGate['reasons']));
            $this->projectCapability($pair, $result, $insufficient, $trades, $map, $settlement);
            $row->update(['status' => 'completed', 'attempts' => (int) $row->attempts + 1, 'last_error' => null, 'processed_at' => now()]);
            $this->markCanonicalSettled($pair, $row);

            return ['status' => 'completed', 'outbox_id' => $row->id, 'settlement_id' => $settled['settlement']->id, 'promotion_evidence' => false];
        } catch (\Throwable $exception) {
            return $this->fail($pair, $row, 'CANONICAL_SETTLEMENT_FAILED', $exception);
        }
    }

    /**
     * Append/refresh projections from an already settled immutable outbox.
     * This never reruns the market replay, creates a second settlement, or
     * rewrites pair/gate state.
     *
     * @return array<string,mixed>
     */
    public function reproject(CanonicalLearningOutbox $row): array
    {
        if ((string) $row->status !== 'completed') {
            return ['status' => 'not_completed', 'outbox_id' => $row->id, 'promotion_evidence' => false];
        }
        $pair = $row->pair_id
            ? LabLearningLanePair::query()->with(['candidateResponseMap', 'controlResponseMap', 'candidateAgent'])->find($row->pair_id)
            : null;
        $settlement = $pair
            ? AgentLearningSettlement::query()
                ->where('source_type', LabLearningLanePair::class)
                ->where('source_id', $pair->id)
                ->latest('id')
                ->first()
            : null;
        if (! $pair || ! $settlement || ! $pair->isVerifiedControlPair()) {
            return ['status' => 'canonical_authority_missing', 'outbox_id' => $row->id, 'promotion_evidence' => false];
        }
        $result = (array) data_get($row->payload, 'result', []);
        $trades = $this->tradeCount($result);
        $insufficient = $trades === 0 || $this->hasMissingRewardCoverage($result);
        app(LearningConsolidationService::class)->consolidate($settlement);
        $this->projectCapability($pair, $result, $insufficient, $trades, $pair->candidateResponseMap, $settlement);

        return ['status' => 'reprojected', 'outbox_id' => $row->id, 'settlement_id' => $settlement->id, 'promotion_evidence' => false];
    }

    private function projectCapability(
        LabLearningLanePair $pair,
        array $result,
        bool $insufficient,
        int $trades,
        ?LabMutationResponseMap $map,
        AgentLearningSettlement $settlement,
    ): void
    {
        if (! Schema::hasTable('capability_causal_attributions')) return;
        $state = app(MarketStateEstimatorService::class)->estimate($pair->symbol, $pair->timeframe);
        $state['regime'] = $state['state'];
        $state['session'] = data_get($pair->failure_signature, 'state.session', 'unknown');
        $state['posterior'] = [$state['state'] => $state['regime_probability']];
        $cell = app(CapabilityCellService::class)->resolve($pair->symbol, $pair->timeframe, $state, ['strategy_id' => $pair->strategy_family]);
        $attributionKey = 'learning-settlement:'.$pair->id.':'.(string) data_get($result, 'evidence_run_id', 'none');
        CapabilityCausalAttribution::updateOrCreate(['attribution_key' => $attributionKey], [
            'symbol' => $pair->symbol, 'timeframe' => $pair->timeframe,
            'primary_cause' => $insufficient ? 'execution_admission_starvation' : 'strategy',
            'contributions' => ['strategy' => $insufficient ? 0.0 : .25, 'tactic' => $insufficient ? 0.0 : .20, 'execution' => $insufficient ? 1.0 : .20, 'risk' => $insufficient ? 0.0 : .20, 'market_luck' => $insufficient ? 0.0 : .15],
            'evidence' => ['pair_id' => $pair->id, 'capability_cell_id' => $cell['cell']->id, 'trade_count' => $trades, 'insufficient_evidence' => $insufficient, 'promotion_evidence' => false],
            'attributed_at' => now(),
        ]);
        if ($trades === 0) {
            $pair->update(['metadata' => [...((array) $pair->metadata), 'targeted_repair_lane' => [
                'classification' => 'execution_admission_starvation',
                'lanes' => ['opportunity_recall', 'confidence_calibration', 'minimum_signal_confidence', 'transition_wait', 'abstention_precision'],
                'reason' => 'ZERO_TRADES_NOT_STRATEGY_FAILURE', 'promotion_evidence' => false,
            ]]]);
        }
        if (! $insufficient && $trades > 0) {
            $windows = $this->independentWindows($pair);
            $improved = (bool) data_get($pair->target_delta, 'improved');
            app(SkillCompilerService::class)->compile([
                'symbol' => $pair->symbol, 'timeframe' => $pair->timeframe,
                'state_key' => $cell['cell_key'], 'strategy_id' => $pair->strategy_family,
                'exact_control' => ['paired_isolated' => true, 'status' => 'available', 'data_hash' => $pair->candidate_data_hash, 'execution_hash' => $pair->candidate_execution_hash],
                'data_hash' => $pair->candidate_data_hash, 'execution_hash' => $pair->candidate_execution_hash,
                'independent_windows' => ['observed_windows' => $windows, 'positive_windows' => $improved && $windows > 0 ? 1 : 0],
                'independent_confirmation' => false, 'non_target_regression' => (bool) data_get($pair->non_target_regression, 'failed', false),
                'regime' => $state['state'], 'state_posterior' => $state['posterior'], 'promotion_evidence' => false,
            ]);
            app(LearningCompilerService::class)->compileCanonical([
                'source_key' => 'canonical-pair:'.$pair->id.':'.(string) data_get($result, 'evidence_run_id', 'none'),
                'pair_id' => $pair->id, 'settlement_id' => $settlement->id,
                'lab_agent_id' => $pair->candidate_agent_id, 'lab_generation_id' => $pair->candidateAgent?->lab_generation_id,
                'symbol' => $pair->symbol, 'timeframe' => $pair->timeframe, 'parameter_key' => $map?->parameter_key,
                'old_value' => $map?->old_value, 'new_value' => $map?->new_value, 'direction' => $map?->direction,
                'causal_uplift_r' => (float) data_get($pair->target_delta, 'delta', 0),
                'scope' => ['strategy_family' => $pair->strategy_family, 'regime' => $state['state'], 'session' => data_get($pair->failure_signature, 'state.session'), 'volatility' => data_get($pair->failure_signature, 'state.volatility')],
                'source_experiments' => ['pair-'.$pair->id], 'support' => $trades, 'independent_windows' => $windows,
                'positive_windows' => $improved ? min($windows, 2) : 0, 'non_target_regression' => (bool) data_get($pair->non_target_regression, 'failed', false),
                'canonical_authority' => [
                    'pair_verified' => $pair->isVerifiedControlPair(),
                    'settlement_source_type' => $settlement->source_type,
                    'settlement_source_id' => $settlement->source_id,
                    'settlement_evidence_state' => $settlement->evidence_state,
                ],
            ]);
        }
        app(ProgressScoreboardService::class)->measure($pair->symbol, $pair->timeframe);
    }

    private function markCanonicalSettled(LabLearningLanePair $pair, CanonicalLearningOutbox $row): void
    {
        LabLearningLaneDispatch::query()->where('pair_id', $pair->id)->whereIn('status', ['selected', 'queued', 'running'])->update([
            'status' => 'canonical_settled', 'stage' => 'full_replay', 'completed_at' => null,
        ]);
        $pair->update(['status' => 'canonical_episode_settled', 'metadata' => [...((array) $pair->metadata), 'canonical_outbox_id' => $row->id, 'canonical_settled_at' => now()->utc()->toIso8601String(), 'promotion_evidence' => false]]);
    }

    /** Complete only after a canonical settlement plus lesson decision. */
    public function finalizeDispatch(LabLearningLanePair $pair, string $lessonState = 'lesson_compiled'): array
    {
        $gate = app(LearningEvidenceGate::class)->allow($pair->fresh(['controlResponseMap']), null, $lessonState);
        $lesson = AgentLearningLesson::query()->where('symbol', $pair->symbol)->where('timeframe', $pair->timeframe)
            ->where('lab_agent_id', $pair->candidate_agent_id)
            ->when($lessonState === 'skill_confirmed', fn ($query) => $query->where('status', 'confirmed'))
            ->get()->first(fn (AgentLearningLesson $row): bool => (int) data_get($row->evidence, 'pair_id', 0) === (int) $pair->id);
        if (! $lesson) {
            $gate = [...$gate, 'allowed' => false, 'reasons' => [...$gate['reasons'], 'LESSON_ARTIFACT_MISSING']];
        }
        if (! $gate['allowed']) return $this->fail($pair, null, implode(',', $gate['reasons']));
        LabLearningLaneDispatch::query()->where('pair_id', $pair->id)->whereIn('status', ['selected', 'queued', 'running', 'canonical_settled'])->update([
            'status' => 'completed', 'stage' => 'full_replay', 'completed_at' => now(),
        ]);
        $pair->update(['status' => $lessonState, 'metadata' => [...((array) $pair->metadata), 'lesson_state' => $lessonState, 'promotion_evidence' => false]]);
        if ($lessonState === 'skill_confirmed') {
            $candidate = $pair->candidateAgent()->with('modelVersion')->first();
            if ($candidate) {
                app(CompositionLibrarySettlementService::class)->consolidateConfirmed($candidate, [
                    'pair_id' => $pair->id,
                    'lesson_id' => $lesson->id,
                    'quality_score' => (float) data_get($pair->target_delta, 'score', data_get($pair->target_delta, 'relative_improvement', 0)),
                    'paired_control' => $pair->isVerifiedControlPair(),
                    'independent_confirmation' => $this->lessonIsIndependentlyConfirmed($lesson),
                    'confirmation_protocol' => data_get($lesson->evidence, 'confirmation_protocol'),
                ]);
            }
        }
        return ['status' => $lessonState, 'promotion_evidence' => false];
    }

    /** @return array<string,mixed> */
    private function fail(?LabLearningLanePair $pair, ?CanonicalLearningOutbox $row, string $reason, ?\Throwable $exception = null): array
    {
        $message = $exception ? substr($exception->getMessage(), 0, 1500) : $reason;
        $row?->update(['status' => 'retry_ready', 'attempts' => (int) $row->attempts + 1, 'last_error' => $message]);
        if ($pair) {
            $quarantined = app(LearningTechnicalCircuitBreakerService::class)->record($pair->symbol, $pair->timeframe, $message, ['pair_id' => $pair->id, 'outbox_id' => $row?->id]);
            $pair->update(['status' => 'canonical_failed', 'metadata' => [...((array) $pair->metadata), 'canonical_failure' => $reason, 'promotion_evidence' => false]]);
            LabLearningLaneDispatch::query()->where('pair_id', $pair->id)->whereIn('status', ['selected', 'queued', 'running'])->update(['status' => 'canonical_failed', 'completed_at' => null]);
            if (Schema::hasTable('learning_recovery_events')) LearningRecoveryEvent::updateOrCreate(['event_key' => 'canonical:'.$pair->id.':'.($row?->evidence_run_id ?: 'none')], ['source_type' => self::class, 'source_key' => (string) $pair->id, 'symbol' => $pair->symbol, 'timeframe' => $pair->timeframe, 'status' => $quarantined ? 'technical_quarantine' : 'retry_ready', 'action' => $quarantined ? 'open_technical_repair_lane' : 'retry_canonical_settlement', 'reason' => $reason, 'metadata' => ['outbox_id' => $row?->id, 'error' => $message, 'promotion_evidence' => false]]);
        }
        return ['status' => 'canonical_failed', 'reason' => $reason, 'outbox_id' => $row?->id, 'promotion_evidence' => false];
    }

    private function markDiagnosticOnly(LabLearningLanePair $pair, string $reason): void
    {
        $pair->update(['status' => 'diagnostic_only', 'metadata' => [...((array) $pair->metadata), 'diagnostic_reason' => $reason, 'promotion_evidence' => false]]);
        LabLearningLaneDispatch::query()->where('pair_id', $pair->id)->whereIn('status', ['selected', 'queued', 'running', 'retry_ready'])->update(['status' => 'diagnostic_only', 'completed_at' => null]);
    }

    private function available(): bool { return Schema::hasTable('canonical_learning_outbox') && Schema::hasTable('agent_learning_episodes') && Schema::hasTable('agent_learning_settlements'); }
    private function tradeCount(array $result): int { return max(0, (int) data_get($result, 'total_trades', data_get($result, 'metrics.total_trades', data_get($result, 'entry_funnel.executed_trades', 0)))); }
    private function hasMissingRewardCoverage(array $result): bool { return $this->tradeCount($result) === 0 || (bool) data_get($result, 'coverage_failure', false); }
    private function independentWindows(LabLearningLanePair $pair): int
    {
        return LabLearningLanePair::query()
            ->where('candidate_agent_id', $pair->candidate_agent_id)
            ->where('pair_integrity_status', 'verified')
            ->whereNotNull('independent_window_key')
            ->whereIn('status', ['canonical_episode_settled', 'lesson_compiled', 'skill_confirmed'])
            ->distinct('independent_window_key')
            ->count('independent_window_key');
    }

    private function lessonIsIndependentlyConfirmed(AgentLearningLesson $lesson): bool
    {
        $required = max(3, (int) config('services.learning_lane.independent_confirmations_required', 3));
        $protocol = (string) data_get($lesson->evidence, 'confirmation_protocol', '');

        return (string) $lesson->status === 'confirmed'
            && (int) $lesson->independent_window_count >= $required
            && in_array($protocol, [
                'memory_guided_vs_blinded_vs_frozen_control_v1',
                'learning_lane_independent_skill_v1',
            ], true);
    }
}
