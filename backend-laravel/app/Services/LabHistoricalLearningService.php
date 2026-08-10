<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LabCandleDecisionEvent;
use App\Models\LabEvaluationRun;
use App\Models\LabGateDecisionEvent;
use App\Models\LabGeneration;
use App\Models\LabLearningConsumptionEvent;
use App\Models\LabLearningInsight;
use App\Models\LabMutationCreditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;

/**
 * Converts the immutable evidence plane into bounded evolution advice.
 *
 * This service has two hard safety rules:
 *  - snapshot/legacy history may choose a failure target, but cannot award a
 *    beneficial or harmful causal prior;
 *  - an insight is append-only and a generation records which insight it
 *    consumed, so evolution is auditable rather than a hidden heuristic.
 */
class LabHistoricalLearningService
{
    public const PROTOCOL = 'lab_historical_learning_v1';

    private const TARGET_KEYS = [
        'monthly_survival' => [
            'session_filter_enabled', 'session_start', 'session_end',
            'transition_firewall_enabled', 'transition_wait_candles',
            'trend_roc_period', 'trend_roc_threshold', 'trend_ema_period',
            'breakout_lookback', 'breakout_atr_threshold',
            'range_lookback', 'range_deviation', 'range_adx_max',
        ],
        'temporal_stability' => ['lookback', 'session_start', 'session_end', 'minimum_signal_confidence'],
        'regime_coverage' => ['trend_strength_min', 'high_volatility_risk_multiplier', 'minimum_signal_confidence', 'lookback'],
        'volatility_session_stability' => ['session_filter_enabled', 'session_start', 'session_end', 'high_volatility_risk_multiplier', 'avoid_high_volatility'],
        'exit_topology' => ['atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles', 'partial_take_profit_fraction'],
        'portfolio_router' => ['differential_target_regime', 'differential_target_volatility', 'differential_target_direction', 'minimum_signal_confidence'],
        'opportunity_recall' => ['minimum_confidence', 'minimum_signal_confidence', 'loss_cooldown_candles', 'weak_regime_wait_candles'],
        'profit_factor' => ['atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles', 'partial_take_profit_fraction'],
        'stress_cost' => ['atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles'],
        'trade_frequency' => ['lookback', 'confirmation_candles', 'minimum_signal_confidence', 'trend_strength_min', 'roc_threshold', 'deviation', 'atr_threshold'],
        'drawdown_risk' => ['high_volatility_risk_multiplier', 'max_loss_streak_before_wait', 'loss_cooldown_candles', 'atr_stop_multiplier', 'avoid_high_volatility'],
        'shadow_veto_loss_cooldown' => ['loss_cooldown_candles', 'cooldown_shadow_edge_pf', 'cooldown_shadow_min_samples'],
        'shadow_veto_confidence' => ['minimum_signal_confidence'],
        'shadow_veto_volatility' => ['high_volatility_risk_multiplier', 'avoid_high_volatility'],
        'architecture' => ['lookback', 'session_start', 'session_end', 'trend_strength_min', 'minimum_signal_confidence'],
    ];

    private array $mutationPriorCache = [];
    /** @var array<string, array{summary: array, aggregates: array}> */
    private array $candleEvidenceCache = [];

    public function refreshForLab(string $symbol, string $timeframe = 'H1'): array
    {
        $symbol = strtoupper($symbol);
        $timeframe = strtoupper($timeframe);
        $families = LabAgent::query()->where('symbol', $symbol)->where('timeframe', $timeframe)
            ->distinct()->pluck('strategy_family')->filter()->values();
        // Candle decision evidence is immutable and shared by every family in
        // one lab refresh. Aggregate the 1M+ row event plane once, then split
        // the compact result by strategy family. Re-running the same grouped
        // scan once per family made generation creation grow linearly with
        // historical population size and caused a valid audit-triggered build
        // to time out without creating a generation.
        $candleEvidence = $families->isEmpty() ? ['summary' => [], 'aggregates' => []]
            : $this->candleEvidenceForLab($symbol, $timeframe);

        return $families->map(fn (string $family): ?LabLearningInsight => $this->refreshFamily($symbol, $timeframe, $family, $candleEvidence))
            ->filter()->values()->all();
    }

    public function latestForFamily(string $symbol, string $timeframe, string $family): ?LabLearningInsight
    {
        return LabLearningInsight::query()->where([
            'symbol' => strtoupper($symbol), 'timeframe' => strtoupper($timeframe), 'strategy_family' => $family,
        ])->latest('generated_at')->first();
    }

    /** Record exactly how a new generation consumed historical advice. */
    public function recordGenerationConsumption(LabGeneration $generation, array $plan): int
    {
        $generation->loadMissing('laboratory');
        $count = 0;
        foreach ($plan as $index => $spec) {
            $family = (string) ($spec['family'] ?? '');
            if ($family === '') continue;
            $insight = $this->latestForFamily(
                (string) $generation->laboratory?->symbol,
                (string) $generation->laboratory?->timeframe,
                $family,
            );
            $recommended = (array) ($insight?->recommended_mutations ?? []);
            $target = (string) ($spec['target'] ?? '');
            $primaryTarget = (string) data_get($recommended, 'primary_target', '');
            $secondaryTargets = (array) data_get($recommended, 'secondary_targets', []);
            $historyApplied = $insight !== null && ($target === $primaryTarget || in_array($target, $secondaryTargets, true));
            LabLearningConsumptionEvent::create([
                'event_id' => (string) Str::uuid(),
                'lab_learning_insight_id' => $insight?->id,
                'lab_generation_id' => $generation->id,
                'symbol' => (string) $generation->laboratory?->symbol,
                'timeframe' => (string) $generation->laboratory?->timeframe,
                'strategy_family' => $family,
                'role' => (string) ($spec['origin'] ?? 'generation_plan'),
                'target' => $target,
                'evidence_quality' => $insight?->evidence_quality,
                'causal_prior_allowed' => (bool) ($insight?->causal_prior_allowed ?? false),
                'selected_keys' => (array) data_get($recommended, 'keys', []),
                'payload' => [
                    'protocol' => self::PROTOCOL,
                    'plan_index' => $index,
                    'history_applied_to_target' => $historyApplied,
                    'history_target' => $primaryTarget ?: null,
                    'insight_id' => $insight?->insight_id,
                    'failure_signature' => $insight?->failure_signature,
                    'causal_rule' => 'Causal direction is allowed only from independently confirmed exact replay credits.',
                ],
                'recorded_at' => now(),
            ]);
            $count++;
        }
        return $count;
    }

    /**
     * Return a direction prior only when immutable exact replay evidence has
     * independently confirmed the same mutation at least twice.
     */
    public function confirmedMutationPrior(string $symbol, string $timeframe, string $family, ?string $scope = null): ?array
    {
        $cacheKey = implode('|', [strtoupper($symbol), strtoupper($timeframe), $family, $scope ?: 'global']);
        if (array_key_exists($cacheKey, $this->mutationPriorCache)) return $this->mutationPriorCache[$cacheKey];
        $agents = LabAgent::query()->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))
            ->where('strategy_family', $family)->get(['id']);
        if ($agents->isEmpty()) return $this->mutationPriorCache[$cacheKey] = null;
        $agentIds = $agents->pluck('id')->all();
        $credits = LabMutationCreditEvent::query()->whereIn('lab_agent_id', $agentIds)->get();
        $runIds = $credits->flatMap(fn (LabMutationCreditEvent $event): array => (array) $event->evidence_run_ids)->filter()->unique()->values();
        $exactRuns = LabEvaluationRun::query()->whereIn('run_id', $runIds->all())->where('status', '!=', 'legacy_snapshot')->pluck('run_id')->all();
        if ($exactRuns === []) return $this->mutationPriorCache[$cacheKey] = null;
        $rows = $credits->filter(function (LabMutationCreditEvent $event) use ($exactRuns, $scope): bool {
            $status = (string) data_get($event->payload, 'behavioral_effect.causal_credit.status', data_get($event->payload, 'causal_credit.status', ''));
            $hasExact = collect((array) $event->evidence_run_ids)->intersect($exactRuns)->isNotEmpty();
            $scopeMatches = $scope === null || (string) data_get($event->payload, 'market_regime', data_get($event->agent?->modelVersion?->metadata, 'mutation_scope', '')) === $scope;
            return $hasExact && $status === 'independently_confirmed' && $scopeMatches
                && in_array($event->outcome, ['beneficial', 'harmful'], true);
        });
        $winner = $rows->groupBy(fn (LabMutationCreditEvent $event): string => $event->parameter_key.'|'.$event->outcome)
            ->map(fn ($items) => $items->sortByDesc('recorded_at')->first())
            ->filter(fn (LabMutationCreditEvent $event): bool => $rows->where('parameter_key', $event->parameter_key)->where('outcome', $event->outcome)->count() >= 2)
            ->sortByDesc('confidence')->first();
        if (! $winner) return $this->mutationPriorCache[$cacheKey] = null;
        $old = data_get($winner->payload, 'old_value.value', null);
        $new = data_get($winner->payload, 'new_value.value', null);
        $direction = is_numeric($old) && is_numeric($new) ? ((float) $new >= (float) $old ? 1 : -1) : null;
        return $this->mutationPriorCache[$cacheKey] = [
            'parameter_key' => $winner->parameter_key,
            'outcome' => $winner->outcome,
            'direction' => $direction,
            'confidence' => (float) $winner->confidence,
            'confirmation_count' => $rows->where('parameter_key', $winner->parameter_key)->where('outcome', $winner->outcome)->count(),
            'evidence_run_ids' => collect((array) $winner->evidence_run_ids)->intersect($exactRuns)->values()->all(),
            'protocol' => self::PROTOCOL,
        ];
    }

    private function refreshFamily(string $symbol, string $timeframe, string $family, array $candleEvidence = []): ?LabLearningInsight
    {
        $agents = LabAgent::query()->where('symbol', $symbol)->where('timeframe', $timeframe)
            ->where('strategy_family', $family)->get(['id', 'lab_generation_id']);
        if ($agents->isEmpty()) return null;
        $agentIds = $agents->pluck('id')->all();
        $gateEvents = LabGateDecisionEvent::query()->whereIn('lab_agent_id', $agentIds)
            ->whereIn('stage', ['screening', 'full_validation', 'statistical_forward_gate', 'paper_admission'])
            ->latest('recorded_at')->get();
        $latestGates = $gateEvents->groupBy(fn (LabGateDecisionEvent $event): string => $event->lab_agent_id.'|'.$event->stage)
            ->map(fn ($items) => $items->sortByDesc('revision')->first())->values();
        $reasonCounts = [];
        $targetScores = [];
        $sourceEventIds = [];
        foreach ($latestGates as $event) {
            $sourceEventIds[] = $event->id;
            foreach (array_unique((array) $event->reason_codes) as $reason) {
                $reason = strtoupper((string) $reason);
                if (! preg_match('/^(FAILED_|INSUFFICIENT_|DOMINATED_|OVERFIT|REJECTED)/', $reason)) continue;
                $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
                // Older forward events used FAILED_REGIME_COVERAGE for a
                // seasonal monthly failure. Prefer the immutable metric
                // payload when available so legacy evidence still routes to
                // the monthly lane without rewriting that evidence.
                $target = $this->targetForFailure($reason, (array) $event->metrics);
                if ($target) $targetScores[$target] = ($targetScores[$target] ?? 0) + 3;
            }
        }
        arsort($reasonCounts);

        $runs = LabEvaluationRun::query()->whereIn('lab_agent_id', $agentIds)->get();
        $exactRuns = $runs->filter(fn (LabEvaluationRun $run): bool => $run->status !== 'legacy_snapshot' && ! (bool) data_get($run->metadata, 'historical', false));
        $legacyRuns = $runs->where('status', 'legacy_snapshot');
        $exactRunIds = $exactRuns->pluck('run_id')->values()->all();

        if ($candleEvidence === []) $candleEvidence = $this->candleEvidenceForAgents($agentIds, $family);
        $candleSummary = (object) ($candleEvidence['summary'][$family] ?? [
            'total' => 0, 'accepted' => 0, 'rejected' => 0,
        ]);
        $candleAggregates = array_slice((array) ($candleEvidence['aggregates'][$family] ?? []), 0, 64);

        foreach ($candleAggregates as $row) {
            $target = $this->targetForRejection((string) $row['rejection_code']);
            if ($target) $targetScores[$target] = ($targetScores[$target] ?? 0) + min(30, (int) $row['occurrences']);
        }
        arsort($targetScores);
        $primaryTarget = array_key_first($targetScores);
        $secondaryTargets = array_slice(array_keys($targetScores), 1, 3);
        $recommendedKeys = self::TARGET_KEYS[$primaryTarget] ?? [];

        $credits = LabMutationCreditEvent::query()->whereIn('lab_agent_id', $agentIds)->get();
        $confirmedCredits = $credits->filter(function (LabMutationCreditEvent $event) use ($exactRunIds): bool {
            $status = (string) data_get($event->payload, 'behavioral_effect.causal_credit.status', data_get($event->payload, 'causal_credit.status', ''));
            return $status === 'independently_confirmed'
                && collect((array) $event->evidence_run_ids)->intersect($exactRunIds)->isNotEmpty();
        });
        $blocked = $confirmedCredits->where('outcome', 'harmful')->where('forward_delta', '<', 0)
            ->groupBy('parameter_key')->map(fn ($items) => [
                'parameter_key' => $items->first()->parameter_key,
                'confirmations' => $items->count(),
                'reason' => 'independently_confirmed_harmful',
            ])->values()->all();
        $causalPriorAllowed = $exactRuns->count() >= 2 && $confirmedCredits->count() >= 2;

        $evidenceQuality = $exactRuns->isNotEmpty() ? 'exact' : ($legacyRuns->isNotEmpty() || $latestGates->isNotEmpty() ? 'snapshot_only' : 'no_evidence');
        $confidence = min(95, 15 + min(30, $latestGates->count() * 2) + min(25, $exactRuns->count() * 2) + min(20, count($candleAggregates)));
        $dominantCandle = $candleAggregates[0] ?? null;
        $scopeKey = data_get($dominantCandle, 'market_regime') ? 'market:'.data_get($dominantCandle, 'market_regime') : 'global';
        $metrics = [
            'agent_count' => count($agentIds),
            'latest_gate_count' => $latestGates->count(),
            'exact_run_count' => $exactRuns->count(),
            'legacy_snapshot_run_count' => $legacyRuns->count(),
            'technical_error_count' => $runs->where('status', 'technical_error')->count(),
            'candle_event_count' => (int) ($candleSummary->total ?? 0),
            'accepted_candle_events' => (int) ($candleSummary->accepted ?? 0),
            'rejected_candle_events' => (int) ($candleSummary->rejected ?? 0),
            'target_scores' => $targetScores,
            'confirmed_credit_count' => $confirmedCredits->count(),
        ];
        $failureSignature = [
            'dominant_gate_reasons' => array_slice($reasonCounts, 0, 8, true),
            'dominant_rejections' => array_slice($candleAggregates, 0, 12),
            'dominant_regime' => data_get($dominantCandle, 'market_regime'),
            'dominant_volatility' => data_get($dominantCandle, 'volatility_regime'),
            'calendar_is_diagnostic_only' => true,
        ];
        $recommended = [
            'primary_target' => $primaryTarget,
            'secondary_targets' => $secondaryTargets,
            'keys' => $recommendedKeys,
            'policy' => $primaryTarget ? 'test_the_dominant_failure_signature_before_exploring_new_genes' : 'exploration_only',
        ];
        $sourceGenerationIds = $agents->pluck('lab_generation_id')->filter()->unique()->values()->all();
        sort($sourceEventIds);
        $sourceRunIds = $runs->pluck('run_id')->filter()->sort()->values()->all();
        $sourceHash = $this->hash([
            'protocol' => self::PROTOCOL, 'symbol' => $symbol, 'timeframe' => $timeframe, 'family' => $family,
            'gate_event_ids' => $sourceEventIds, 'run_ids' => $sourceRunIds,
            'failure_signature' => $failureSignature, 'metrics' => $metrics, 'recommended' => $recommended,
        ]);
        $existing = LabLearningInsight::query()->where('source_hash', $sourceHash)->first();
        if ($existing) return $existing;

        try {
            return LabLearningInsight::create([
            'insight_id' => (string) Str::uuid(), 'symbol' => $symbol, 'timeframe' => $timeframe,
            'strategy_family' => $family, 'scope_key' => $scopeKey, 'insight_type' => 'failure_profile',
            'evidence_quality' => $evidenceQuality, 'causal_prior_allowed' => $causalPriorAllowed,
            'confidence' => $confidence, 'source_hash' => $sourceHash,
            'source_generation_ids' => $sourceGenerationIds,
            'source_agent_ids' => array_slice($agentIds, 0, 500),
            'source_run_ids' => array_slice($sourceRunIds, 0, 500),
            'source_event_ids' => array_slice($sourceEventIds, 0, 500),
            'failure_signature' => $failureSignature, 'metrics' => $metrics,
            'recommended_mutations' => $recommended, 'blocked_mutations' => $blocked,
            'conclusion' => $primaryTarget
                ? "{$family}: {$primaryTarget} is the dominant historical failure target; preserve all other lanes and test only the diagnosed envelope."
                : "{$family}: no stable failure target is available; keep this family exploratory and do not infer causal mutation credit.",
            'generated_at' => now(),
            ]);
        } catch (QueryException $exception) {
            // Two scheduler paths may observe the same immutable source hash
            // at once. The unique source hash is the idempotency fence; the
            // already-created insight is the correct result for both callers.
            if (str_contains(strtolower($exception->getMessage()), 'unique')) {
                return LabLearningInsight::query()->where('source_hash', $sourceHash)->first();
            }
            throw $exception;
        }
    }

    /** Aggregate the immutable candle event plane once per lab refresh. */
    private function candleEvidenceForLab(string $symbol, string $timeframe): array
    {
        $cacheKey = strtoupper($symbol).'|'.strtoupper($timeframe);
        if (isset($this->candleEvidenceCache[$cacheKey])) return $this->candleEvidenceCache[$cacheKey];

        $base = DB::table('lab_candle_decision_events as e')
            ->join('lab_agents as a', 'a.id', '=', 'e.lab_agent_id')
            ->where('a.symbol', strtoupper($symbol))
            ->where('a.timeframe', strtoupper($timeframe));
        $summary = (clone $base)
            ->select('a.strategy_family')
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN e.accepted = 1 THEN 1 ELSE 0 END) as accepted, SUM(CASE WHEN e.accepted = 0 THEN 1 ELSE 0 END) as rejected')
            ->groupBy('a.strategy_family')
            ->get()
            ->mapWithKeys(fn ($row): array => [(string) $row->strategy_family => [
                'total' => (int) $row->total,
                'accepted' => (int) $row->accepted,
                'rejected' => (int) $row->rejected,
            ]])->all();
        $aggregates = (clone $base)
            ->whereNotNull('e.rejection_code')
            ->select('a.strategy_family', 'e.rejection_code', 'e.market_regime', 'e.volatility_regime')
            ->selectRaw('COUNT(*) as occurrences')
            ->groupBy('a.strategy_family', 'e.rejection_code', 'e.market_regime', 'e.volatility_regime')
            ->get()
            ->groupBy('strategy_family')
            ->map(fn ($rows): array => $rows->sortByDesc('occurrences')->take(64)->map(fn ($row): array => [
                'rejection_code' => $row->rejection_code,
                'market_regime' => $row->market_regime,
                'volatility_regime' => $row->volatility_regime,
                'occurrences' => (int) $row->occurrences,
            ])->values()->all())->all();

        return $this->candleEvidenceCache[$cacheKey] = ['summary' => $summary, 'aggregates' => $aggregates];
    }

    /** Fallback for a direct family refresh outside refreshForLab(). */
    private function candleEvidenceForAgents(array $agentIds, string $family = '__fallback'): array
    {
        $base = DB::table('lab_candle_decision_events as e')->whereIn('e.lab_agent_id', $agentIds);
        $summary = (clone $base)->selectRaw(
            'COUNT(*) as total, SUM(CASE WHEN e.accepted = 1 THEN 1 ELSE 0 END) as accepted, SUM(CASE WHEN e.accepted = 0 THEN 1 ELSE 0 END) as rejected'
        )->first();
        $aggregates = (clone $base)->whereNotNull('e.rejection_code')
            ->select('e.rejection_code', 'e.market_regime', 'e.volatility_regime')
            ->selectRaw('COUNT(*) as occurrences')
            ->groupBy('e.rejection_code', 'e.market_regime', 'e.volatility_regime')
            ->orderByDesc('occurrences')->limit(64)->get()->map(fn ($row): array => [
                'rejection_code' => $row->rejection_code,
                'market_regime' => $row->market_regime,
                'volatility_regime' => $row->volatility_regime,
                'occurrences' => (int) $row->occurrences,
            ])->all();

        return [
            'summary' => [$family => [
                'total' => (int) ($summary->total ?? 0),
                'accepted' => (int) ($summary->accepted ?? 0),
                'rejected' => (int) ($summary->rejected ?? 0),
            ]],
            'aggregates' => [$family => $aggregates],
        ];
    }

    private function targetForFailure(string $reason, array $metrics = []): ?string
    {
        if ($reason === 'FAILED_REGIME_COVERAGE'
            && data_get($metrics, 'monthly_passport.status') === 'seasonal_or_luck') {
            return 'monthly_survival';
        }

        return match (true) {
            str_contains($reason, 'CALENDAR') || str_contains($reason, 'MONTH') => 'monthly_survival',
            str_contains($reason, 'TEMPORAL') || str_contains($reason, 'WINDOW') => 'temporal_stability',
            str_contains($reason, 'OPPORTUNITY_RECALL') => 'opportunity_recall',
            str_contains($reason, 'NON_TARGET') || str_contains($reason, 'PORTFOLIO') => 'portfolio_router',
            str_contains($reason, 'REGIME') => 'regime_coverage',
            str_contains($reason, 'STRESS') || str_contains($reason, 'COST') => 'stress_cost',
            str_contains($reason, 'TRADE') || str_contains($reason, 'SAMPLE') => 'trade_frequency',
            str_contains($reason, 'OVERFIT') || str_contains($reason, 'PASSPORT') => 'architecture',
            str_contains($reason, 'DRAWDOWN') || str_contains($reason, 'RUIN') => 'drawdown_risk',
            str_contains($reason, 'PROFIT') || str_contains($reason, 'EDGE') => 'profit_factor',
            default => null,
        };
    }

    private function targetForRejection(string $reason): ?string
    {
        return match (strtolower($reason)) {
            'minimum_confidence', 'weak_confidence' => 'trade_frequency',
            'outside_session' => 'volatility_session_stability',
            'loss_cooldown', 'loss_streak_wait' => 'shadow_veto_loss_cooldown',
            'weak_regime_history' => 'regime_coverage',
            'regime_transition_wait' => 'monthly_survival',
            'high_volatility', 'volatility_veto' => 'volatility_session_stability',
            default => null,
        };
    }

    private function hash(mixed $value): string
    {
        return hash('sha256', json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
