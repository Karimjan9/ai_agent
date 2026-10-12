<?php

namespace App\Services;

use App\Models\AgentDiagnosis;
use App\Models\AgentFailureCase;
use App\Models\AgentKnowledgeCard;
use App\Models\AgentLearningCausalExperiment;
use App\Models\AgentLearningEpisode;
use App\Models\AgentLearningMutationIntent;
use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\Candle;
use App\Models\EliteAgentPortfolio;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Models\MutationMemory;
use App\Models\Symbol;
use App\Services\MarketData\HistoricalDataQualityService;
use App\Services\MarketData\MarketDataContinuityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** Internal method group composed exclusively into LabPopulationService. */
trait LabPopulationMutationTrait
{
    private function differentialSingleGene(
        array $base,
        int $slot,
        ?string $scope,
        ?string $objective = null,
        ?string $variant = null,
        ?array $niche = null,
        array $blockedKeys = [],
        array $blockedDirections = [],
    ): array {
        $blockedKeys = array_values(array_unique($blockedKeys));
        $role = (string) data_get($niche, 'specialist_role', data_get($niche, 'role', ''));
        $target = in_array($scope, ['trend_up', 'range', 'trend_down'], true) ? $scope : ['trend_down', 'range', 'trend_up'][$slot % 3];
        $parameters = [...$this->schemas->defaults('differential_router'), ...$base,
            'differential_target_regime' => $target, 'differential_replay_mode' => 'paired_isolated'];
        $schema = $this->schemas->schema('differential_router');
        $declaredGene = (string) data_get($niche, 'declared_gene', '');
        if ($declaredGene !== '' && array_key_exists($declaredGene, $schema)) {
            // Controlled-rescue seats carry an explicit causal gene. The
            // differential compiler must not let regime priors or slot order
            // substitute a different field underneath that declaration.
            return $this->declaredSingleGeneMutation(
                $parameters,
                $schema,
                $declaredGene,
                $slot,
                $blockedKeys,
                $blockedDirections,
            );
        }
        // Monthly survival is a state-recurrence experiment.  If immutable
        // evidence identifies a transition/cooldown/spread veto, direct the
        // child to that existing control gene.  The state cluster is never a
        // calendar feature and the helper returns at most one changed gene.
        if ($objective === 'monthly_survival') {
            $stateMutation = $this->stateClusterMonthlyMutation(
                $parameters,
                $schema,
                data_get($niche, 'state_cluster'),
                $blockedKeys,
            );
            if ($stateMutation !== null) {
                return $stateMutation;
            }
        }
        if ($objective === 'opportunity_recall') {
            if ($role === 'trend_down_specialist') {
                return $this->trendDownCouncilRecallMutation($parameters, $schema, $blockedDirections);
            }
            // Recall is a controlled entry-funnel experiment, but a global
            // confidence relaxation lets a trend-up child borrow false
            // positives from every branch.  That is exactly how G113's
            // recall child reached 26 trades while PF collapsed to 0.22.
            // Keep the non-target router frozen and mutate the owner regime's
            // entry gene first; the unchanged PF, monthly and abstention
            // gates decide whether the added opportunities were useful.
            $ownerKeys = match ($target) {
                'trend_up' => ['differential_target_min_signal_confidence', 'trend_up_strength_min', 'trend_up_roc_threshold', 'trend_up_pullback_atr_fraction', 'trend_up_ema_period'],
                'trend_down' => ['differential_target_min_signal_confidence', 'trend_down_strength_min', 'trend_down_roc_threshold', 'trend_down_pullback_atr_fraction', 'trend_down_ema_period'],
                'range' => ['range_deviation', 'range_low_volatility_only', 'range_reentry_required', 'range_adx_max'],
                default => [],
            };
            // G120/G121 showed that loss_cooldown was the dominant rejection
            // source. Test the existing state-conditioned policy as a single
            // boolean ablation: trend/normal waits are shorter, while
            // range/high-risk waits are longer. It is never a global
            // confidence relaxation and still faces every unchanged gate.
            // When the parent already enables it, the child is the explicit
            // fixed-cooldown control for the same causal comparison.
            if ($variant === 'state_conditioned_cooldown'
                && array_key_exists('dynamic_cooldown_enabled', $parameters)
                && isset($schema['dynamic_cooldown_enabled'])) {
                $parameters['dynamic_cooldown_enabled'] = ! (bool) $parameters['dynamic_cooldown_enabled'];

                return $parameters;
            }
            // A cooldown-dominant owner gets a small, explicit shortening
            // probe.  The parent policy remains frozen; this child tests only
            // whether the rejected opportunities are worth the added risk.
            if ($variant === 'loss_cooldown_shortening'
                && array_key_exists('loss_cooldown_candles', $parameters)
                && isset($schema['loss_cooldown_candles'])) {
                [$type, $min, $max] = array_pad($schema['loss_cooldown_candles'], 3, null);
                $current = (int) $parameters['loss_cooldown_candles'];
                $step = max(1, min(4, (int) ceil(((float) $max - (float) $min) * .08)));
                $parameters['loss_cooldown_candles'] = max((int) $min, $current - $step);

                return $parameters;
            }
            // G128 exposed a different recall bottleneck from the earlier
            // cooldown cohort: regime_transition_wait rejected 111 of 284
            // opportunities.  Shorten only the transition wait by one candle
            // so the child tests whether a transition can be re-entered
            // sooner.  Firewall semantics, entry logic, costs and every
            // unchanged passport gate remain frozen.
            if ($variant === 'transition_wait_shortening'
                && array_key_exists('transition_wait_candles', $parameters)
                && isset($schema['transition_wait_candles'])) {
                [$type, $min, $max] = array_pad($schema['transition_wait_candles'], 3, null);
                $current = (int) $parameters['transition_wait_candles'];
                $parameters['transition_wait_candles'] = max((int) $min, $current - 1);

                return $parameters;
            }
            // G122's cooldown child exposed the next real bottleneck: the
            // negative-EV lower-bound veto rejected 172 of 257 opportunities.
            // Test only the existing guard as a shadow ablation. This is not
            // a promotion relaxation: recall, abstention precision, monthly
            // survival, stress, adversarial and every elite gate remain
            // unchanged and decide whether the extra opportunities are safe.
            if ($variant === 'negative_ev_lower_bound_ablation'
                && array_key_exists('confidence_ev_lower_bound_enabled', $parameters)
                && isset($schema['confidence_ev_lower_bound_enabled'])) {
                $parameters['confidence_ev_lower_bound_enabled'] = ! (bool) $parameters['confidence_ev_lower_bound_enabled'];

                return $parameters;
            }
            // A spread-dominant range owner gets a minimal recall probe.  It
            // is deliberately tiny and remains exposed to stress, drawdown,
            // monthly and worst-regime gates; this is not a spread-gate
            // relaxation for promotion.
            if ($variant === 'spread_atr_recall_probe'
                && array_key_exists('max_spread_atr_ratio', $parameters)
                && isset($schema['max_spread_atr_ratio'])) {
                [, , $max] = array_pad($schema['max_spread_atr_ratio'], 3, null);
                $parameters['max_spread_atr_ratio'] = round(min((float) $max, (float) $parameters['max_spread_atr_ratio'] + .005), 4);

                return $parameters;
            }
            foreach ($ownerKeys as $key) {
                if (! array_key_exists($key, $parameters) || ! isset($schema[$key])) {
                    continue;
                }
                [$type, $min, $max] = array_pad($schema[$key], 3, null);
                if ($type === 'boolean') {
                    $parameters[$key] = ! (bool) ($parameters[$key] ?? false);

                    return $parameters;
                }
                if (! is_numeric($min) || ! is_numeric($max)) {
                    continue;
                }
                $current = (float) $parameters[$key];
                $step = match (true) {
                    str_ends_with($key, '_strength_min') => 2.0,
                    str_ends_with($key, '_roc_threshold') => .05,
                    str_ends_with($key, '_pullback_atr_fraction') => .15,
                    str_ends_with($key, '_ema_period') => 5.0,
                    'range_deviation' => .2,
                    'range_adx_max' => 2.0,
                    default => max(.0001, ((float) $max - (float) $min) * .05),
                };
                // A recall lane must actually expand the target opportunity
                // funnel.  Alternating the target-only confidence threshold
                // upward on even seats silently creates a precision-control
                // arm instead of a recall arm; keep that gene directional.
                // Other owner genes still alternate bounded expansion and
                // contraction so the lane retains causal contrast.
                $direction = $key === 'differential_target_min_signal_confidence'
                    ? -1
                    : (($slot % 2 === 0) ? 1 : -1);
                $value = max((float) $min, min((float) $max, $current + ($direction * $step)));
                $parameters[$key] = $type === 'integer' ? (int) round($value) : round($value, 4);

                return $parameters;
            }
            // Legacy families without a regime-local field retain the old
            // bounded fallback; it is still subject to the same gates.
            foreach (['minimum_confidence', 'minimum_signal_confidence', 'loss_cooldown_candles', 'weak_regime_wait_candles', 'dynamic_cooldown_enabled'] as $key) {
                if (! array_key_exists($key, $parameters)) {
                    continue;
                }
                if ($key === 'dynamic_cooldown_enabled') {
                    $parameters[$key] = ! (bool) $parameters[$key];

                    return $parameters;
                } elseif ($key === 'minimum_confidence') {
                    $current = (float) $parameters[$key];
                    if ($current > .1) {
                        $parameters[$key] = round(max(.1, $current - .1), 4);

                        return $parameters;
                    }
                } elseif ($key === 'minimum_signal_confidence') {
                    $current = (float) $parameters[$key];
                    if ($current > 0) {
                        $parameters[$key] = round(max(0.0, $current - .05), 4);

                        return $parameters;
                    }
                } else {
                    $current = (int) $parameters[$key];
                    if ($current > 1) {
                        $parameters[$key] = max(1, $current - 1);

                        return $parameters;
                    }
                }
            }

            return $parameters;
        }
        if ($objective === 'transition_firewall') {
            if ($role === 'trend_up_specialist') {
                return $this->transitionCouncilMutation($parameters, $schema, $blockedDirections);
            }
            // April's portfolio loss was shared by otherwise different
            // members, which is a classic transition-boundary hypothesis.
            // Test the firewall as one isolated control gene; do not hide it
            // inside a calendar/month mutation bundle.
            $parameters['transition_firewall_enabled'] = ! (bool) ($parameters['transition_firewall_enabled'] ?? false);

            return $parameters;
        }
        if ($objective === 'stress_cost') {
            // Stress is an execution-topology problem, not a reason to
            // rewrite the target regime's entry identity.  Test one bounded
            // exit gene per child and let the unchanged non-target and stress
            // gates decide whether the experiment earned its place.
            $key = ['atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles'][$slot % 4];
            [$type, $min, $max] = array_pad($this->schemas->schema('differential_router')[$key] ?? ['numeric', 0.5, 4.0], 3, null);
            $current = (float) ($parameters[$key] ?? (($min + $max) / 2));
            $step = match ($key) {
                'atr_stop_multiplier' => .15,
                'atr_target_multiplier' => .25,
                'trailing_atr_multiplier' => .2,
                default => 8,
            };
            $direction = ($slot % 2 === 0) ? 1 : -1;
            $value = max((float) $min, min((float) $max, $current + ($direction * $step)));
            $parameters[$key] = $type === 'integer' ? (int) round($value) : round($value, 4);

            return $parameters;
        }
        $contextV2 = in_array($objective, ['monthly_survival', 'temporal_stability', 'calendar_context_rescue'], true);
        if ($contextV2) {
            $parameters['differential_router_version'] = 'v2';
        }
        if ($target === 'range') {
            // Three range children test three different mechanisms. Each
            // child changes one gene only, so a later improvement can receive
            // causal credit instead of being attributed to a hidden bundle.
            $variant = $slot % 3;
            if ($variant === 1) {
                $parameters['range_signal_mode'] = 'inverse_extreme';
            } elseif ($variant === 2) {
                $parameters['range_low_volatility_only'] = ! (bool) ($parameters['range_low_volatility_only'] ?? false);
            } else {
                $parameters['range_deviation'] = (float) ($parameters['range_deviation'] ?? 2.0) + (($slot % 2) ? -.2 : .2);
            }
        } elseif ($target === 'trend_up') {
            if ($contextV2) {
                // v2 reuses the parent hybrid momentum branch.  Each council
                // objective changes one meaningful target-lane gene, keeping
                // the opportunity count comparable to the frozen parent.
                if ($objective === 'monthly_survival') {
                    $parameters['trend_up_roc_threshold'] = round(max(.01, (float) ($parameters['trend_up_roc_threshold'] ?? .2) - .05), 4);
                } elseif ($objective === 'temporal_stability') {
                    $parameters['trend_up_roc_period'] = max(4, min(60, (int) ($parameters['trend_up_roc_period'] ?? 12) + 2));
                } else {
                    $parameters['trend_up_ema_period'] = max(10, min(300, (int) ($parameters['trend_up_ema_period'] ?? 50) + 10));
                }

                return $parameters;
            }
            // The parent deficit is not lack of global PF; it is a thin,
            // negative trend-up|normal lane.  The old +/-2 pair produced
            // 18/22 from the default 20 and collapsed the child to 3/1
            // target trades.  Explore the direction that can restore
            // opportunity coverage, one gene at a time, while leaving every
            // other specialist and execution parameter frozen.
            $variant = $slot % 3;
            $key = 'trend_up_strength_min';
            [$strengthType, $strengthMin, $strengthMax] = array_pad($schema[$key] ?? ['numeric', 10, 50], 3, null);
            $baseStrength = max((float) $strengthMin, min((float) $strengthMax, (float) ($parameters[$key] ?? 20.0)));
            if ($objective === 'calendar_context_rescue') {
                // A context rescue must not repeat the two screen-only
                // strength variants. Pullback depth is the only changed
                // target-lane gene; all non-target specialist branches stay
                // frozen by the differential replay contract.
                $basePullback = (float) ($parameters['trend_up_pullback_atr_fraction'] ?? 0.75);
                $parameters['trend_up_pullback_atr_fraction'] = round(min(2.0, $basePullback + 0.15), 2);
            } elseif ($variant === 1) {
                $parameters[$key] = max((float) $strengthMin, min((float) $strengthMax, $baseStrength - 6.0));
            } elseif ($variant === 2) {
                $parameters[$key] = max((float) $strengthMin, min((float) $strengthMax, $baseStrength - 4.0));
            } else {
                $basePullback = (float) ($parameters['trend_up_pullback_atr_fraction'] ?? 0.75);
                $parameters['trend_up_pullback_atr_fraction'] = round(max(0.25, $basePullback - 0.20), 2);
            }
        } else {
            if ($contextV2) {
                if ($objective === 'monthly_survival') {
                    $parameters['trend_down_roc_threshold'] = round(max(.01, (float) ($parameters['trend_down_roc_threshold'] ?? .2) - .05), 4);
                } elseif ($objective === 'temporal_stability') {
                    $parameters['trend_down_roc_period'] = max(4, min(60, (int) ($parameters['trend_down_roc_period'] ?? 12) + 2));
                } else {
                    $parameters['trend_down_ema_period'] = max(10, min(300, (int) ($parameters['trend_down_ema_period'] ?? 50) + 10));
                }

                return $parameters;
            }
            $key = $target.'_strength_min';
            $delta = (($slot % 2) ? -2 : 2);
            [$strengthType, $strengthMin, $strengthMax] = array_pad($this->schemas->schema('differential_router')[$key] ?? ['numeric', 10, 50], 3, null);
            $current = max((float) $strengthMin, min((float) $strengthMax, (float) ($parameters[$key] ?? 20.0)));
            $value = max((float) $strengthMin, min((float) $strengthMax, $current + $delta));
            $parameters[$key] = $strengthType === 'integer' ? (int) round($value) : round($value, 4);
        }

        return $parameters;
    }

    /**
     * Enforce the draft-time mutation invariant before a model row exists.
     *
     * A compiler may legitimately propose a bundle for an ordinary research
     * lane, but repair/causal lanes must be attributable to one gene.  When a
     * compiler returns multiple changes we retain the first legal changed
     * field and restore every other field to the frozen base.  If a safety
     * firewall blocks every legal field, returning null makes the caller skip
     * the slot instead of persisting a zero-diff or silently resurrected child.
     */
    private function enforceConstructorMutationInvariant(
        string $family,
        array $base,
        array $parameters,
        int $slot,
        string $target,
        array $blockedKeys = [],
        bool $singleGene = false,
    ): ?array {
        $diff = $this->diff($base, $parameters);
        if ($diff === []) {
            return $this->forceSingleGeneNudge($family, $base, $slot, $target, $blockedKeys);
        }
        if (! $singleGene || count($diff) === 1) {
            return $parameters;
        }

        foreach (array_keys($diff) as $key) {
            if (in_array($key, $blockedKeys, true)) {
                continue;
            }
            $candidate = $base;
            $candidate[$key] = $parameters[$key] ?? $base[$key] ?? null;
            $candidate = $this->schemas->normalizeForGeneration($family, $candidate);
            $candidate = $this->schemas->validate($family, $candidate);
            if (count($this->diff($base, $candidate)) === 1) {
                return $candidate;
            }
        }

        return $this->forceSingleGeneNudge($family, $base, $slot, $target, $blockedKeys);
    }

    /**
     * Compile the exact gene declared by a five-by-four rescue seat.
     *
     * This helper is deliberately small and schema-bounded. It changes one
     * value only, observes the learned direction firewall, and returns the
     * frozen input when that seat has no legal direction left.
     */
    private function declaredSingleGeneMutation(
        array $parameters,
        array $schema,
        string $key,
        int $slot,
        array $blockedKeys = [],
        array $blockedDirections = [],
        ?string $forcedDirection = null,
    ): array {
        if (in_array($key, $blockedKeys, true) || ! array_key_exists($key, $parameters) || ! isset($schema[$key])) {
            return $parameters;
        }
        [$type, $min, $max] = array_pad($schema[$key], 3, null);
        $current = $parameters[$key];
        $candidates = [];
        if ($type === 'boolean') {
            $candidates[] = match ($forcedDirection) {
                'enable' => true,
                'disable' => false,
                default => ! (bool) $current,
            };
        } elseif ($type === 'string') {
            $choices = array_values(array_filter((array) $min, fn (mixed $choice): bool => $choice !== $current));
            if ($forcedDirection === 'decrease') {
                $choices = array_reverse($choices);
            }
            foreach ($choices as $choice) {
                $candidates[] = $choice;
            }
        } elseif (is_numeric($min) && is_numeric($max)) {
            $span = (float) $max - (float) $min;
            $step = $type === 'integer' ? max(1, (int) round($span * .05)) : max(.0001, $span * .05);
            $directions = $forcedDirection === 'increase'
                ? [1, 2, 3, -1, -2, -3]
                : ($forcedDirection === 'decrease' ? [-1, -2, -3, 1, 2, 3] : [1, -1, 2, -2, 3, -3]);
            foreach ($directions as $direction) {
                $value = max((float) $min, min((float) $max, (float) $current + ($direction * $step)));
                $candidates[] = $type === 'integer' ? (int) round($value) : round($value, 4);
            }
            // The dynamic cooldown policy caps trend/normal waits at two
            // candles. A normal five-percent decrement from the historical
            // value 4 therefore produces 2, which is still behaviourally
            // identical to the parent. For this declared gene, jump to the
            // first executable value when the cap is active; it remains one
            // gene and stays inside the schema bounds.
            if ($key === 'loss_cooldown_candles'
                && (bool) ($parameters['dynamic_cooldown_enabled'] ?? false)
                && $forcedDirection === 'decrease'
                && (float) $current > 2.0) {
                array_unshift($candidates, $type === 'integer' ? (int) $min : (float) $min);
            }
        }
        foreach (array_values(array_unique($candidates, SORT_REGULAR)) as $candidate) {
            // 0, 0.0 and a numeric string can represent the same schema
            // value after a JSON/database round-trip. A type-only change is
            // not a causal mutation and must be skipped.
            if (! $this->valuesDiffer($current, $candidate)
                || $this->mutationDirectionBlocked($key, $candidate, $blockedDirections)) {
                continue;
            }
            $child = $parameters;
            $child[$key] = $candidate;
            if (count($this->diff($parameters, $child)) === 1) {
                return $child;
            }
        }

        return $parameters;
    }

    /** Deterministic last-resort for a G98 lane whose proposed gene is already set. */
    private function forceSingleGeneNudge(string $family, array $parameters, int $slot, string $target, array $blockedKeys = []): ?array
    {
        $preferred = match ($target) {
            'monthly_survival' => ['transition_firewall_enabled', 'session_filter_enabled', 'minimum_signal_confidence', 'lookback'],
            'regime_coverage' => ['trend_strength_min', 'minimum_signal_confidence', 'lookback'],
            'volatility_session_stability' => ['high_volatility_risk_multiplier', 'session_start', 'minimum_signal_confidence'],
            'exit_topology' => ['atr_stop_multiplier', 'atr_target_multiplier', 'time_stop_candles'],
            'transition_firewall' => ['transition_firewall_enabled', 'transition_wait_candles'],
            'portfolio_router' => ['differential_target_regime', 'minimum_signal_confidence', 'lookback'],
            'opportunity_recall' => ['differential_target_min_signal_confidence', 'minimum_confidence', 'minimum_signal_confidence', 'loss_cooldown_candles', 'weak_regime_wait_candles', 'transition_wait_candles', 'dynamic_cooldown_enabled', 'confidence_ev_lower_bound_enabled'],
            'unknown_state_curiosity' => ['minimum_signal_confidence', 'minimum_confidence', 'transition_firewall_enabled', 'transition_wait_candles', 'high_volatility_risk_multiplier', 'avoid_high_volatility'],
            default => [],
        };
        $schema = $this->schemas->schema($family);
        $keys = [...array_values(array_intersect($preferred, array_keys($schema))), ...array_keys($schema)];
        foreach (array_unique($keys) as $key) {
            if (in_array($key, $blockedKeys, true)) {
                continue;
            }
            [$type, $min, $max] = array_pad($schema[$key] ?? [], 3, null);
            if ($type === 'boolean') {
                $parameters[$key] = ! (bool) ($parameters[$key] ?? false);

                return $parameters;
            }
            if ($type === 'string') {
                continue;
            }
            if (! is_numeric($min) || ! is_numeric($max)) {
                continue;
            }
            $current = (float) ($parameters[$key] ?? (((float) $min + (float) $max) / 2));
            $step = max($type === 'integer' ? 1 : .0001, (((float) $max - (float) $min) * .05));
            $next = $current + (($slot % 2 === 0) ? $step : -$step);
            if ($next < (float) $min || $next > (float) $max) {
                $next = $current - (($slot % 2 === 0) ? $step : -$step);
            }
            $next = $type === 'integer' ? (int) round($next) : round($next, 4);
            if (! $this->valuesDiffer($parameters[$key] ?? null, $next)) {
                continue;
            }
            $parameters[$key] = $next;

            return $parameters;
        }

        // If every legal gene is blocked by independently confirmed harmful
        // evidence, skip the slot. A no-change/retest child is not an agent:
        // it cannot teach the lab which mutation caused an outcome.
        return null;
    }

    /** Hybrid range rescue: preserve trend/breakout and change one range gene. */
    private function rangeCouncilSingleGene(
        array $base,
        int $slot,
        ?string $objective = null,
        ?string $variant = null,
        ?array $niche = null,
        array $blockedKeys = [],
        array $blockedDirections = [],
    ): array {
        $blockedKeys = array_values(array_unique($blockedKeys));
        $parameters = [...$this->schemas->defaults('hybrid'), ...$base];
        $role = (string) data_get($niche, 'specialist_role', data_get($niche, 'role', ''));
        if ($role === 'range_specialist') {
            return $this->rangeCouncilRoleMutation($parameters, $blockedDirections);
        }
        if ($role === 'transition_risk_router' && $objective === 'transition_firewall') {
            return $this->transitionCouncilMutation($parameters, $this->schemas->schema('hybrid'), $blockedDirections);
        }
        if ($objective === 'transition_firewall') {
            // Same experiment for a range specialist: preserve its entry
            // topology and test only transition protection.
            return [...$parameters, 'transition_firewall_enabled' => ! (bool) ($parameters['transition_firewall_enabled'] ?? false)];
        }
        if ($objective === 'opportunity_recall') {
            if ($variant === 'state_conditioned_cooldown' && array_key_exists('dynamic_cooldown_enabled', $parameters)) {
                return [...$parameters, 'dynamic_cooldown_enabled' => ! (bool) $parameters['dynamic_cooldown_enabled']];
            }
            if ($variant === 'loss_cooldown_shortening' && array_key_exists('loss_cooldown_candles', $parameters)) {
                $parameters['loss_cooldown_candles'] = max(1, (int) $parameters['loss_cooldown_candles'] - 1);

                return $parameters;
            }
            if ($variant === 'transition_wait_shortening' && array_key_exists('transition_wait_candles', $parameters)) {
                $parameters['transition_wait_candles'] = max(1, (int) $parameters['transition_wait_candles'] - 1);

                return $parameters;
            }
            if ($variant === 'negative_ev_lower_bound_ablation' && array_key_exists('confidence_ev_lower_bound_enabled', $parameters)) {
                return [...$parameters, 'confidence_ev_lower_bound_enabled' => ! (bool) $parameters['confidence_ev_lower_bound_enabled']];
            }
            if ($variant === 'spread_atr_recall_probe' && array_key_exists('max_spread_atr_ratio', $parameters)) {
                return [...$parameters, 'max_spread_atr_ratio' => round(min(.5, (float) $parameters['max_spread_atr_ratio'] + .005), 4)];
            }
            $current = (float) ($parameters['minimum_confidence'] ?? 1.0);

            return [...$parameters, 'minimum_confidence' => round(max(.1, $current - .1), 4)];
        }
        if ($objective === 'stress_cost') {
            return $this->stressExitSingleGene('hybrid', $parameters, $slot);
        }
        if ($objective === 'monthly_survival') {
            $stateMutation = $this->stateClusterMonthlyMutation(
                $parameters,
                $this->schemas->schema('hybrid'),
                data_get($niche, 'state_cluster'),
                $blockedKeys,
            );
            if ($stateMutation !== null) {
                return $stateMutation;
            }

            // inverse_extreme was a screen winner in G80/G81 but collapsed
            // under sealed full replay. Use a different entry topology for
            // the next monthly experiment instead of reproducing that
            // known-overfit fingerprint.
            return [...$parameters, 'range_signal_mode' => 'mean_reversion'];
        }
        if ($objective === 'temporal_stability') {
            return [...$parameters, 'range_deviation' => round(max(0.5, (float) ($parameters['range_deviation'] ?? 2.0) - .2), 4)];
        }
        if ($objective === 'calendar_context_rescue') {
            // This tests re-entry hysteresis in the declared range envelope;
            // it does not alter trend or breakout behavior.
            return [...$parameters, 'range_reentry_required' => false];
        }

        return match ($slot % 3) {
            1 => [...$parameters, 'range_signal_mode' => 'inverse_extreme'],
            2 => [...$parameters, 'range_low_volatility_only' => ! (bool) ($parameters['range_low_volatility_only'] ?? false)],
            default => [...$parameters, 'range_deviation' => round((float) ($parameters['range_deviation'] ?? 2.0) + .2, 4)],
        };
    }

    /**
     * Compile a state-cluster monthly hypothesis into one bounded gene.
     *
     * A month can reveal recurrence, but it is not an executable feature:
     * this method deliberately reads only regime/volatility/transition,
     * liquidity and veto labels.  The returned child still runs through the
     * unchanged PF, monthly, stress, adversarial, recall and passport gates.
     */
    private function stateClusterMonthlyMutation(
        array $parameters,
        array $schema,
        mixed $stateCluster,
        array $blockedKeys = [],
    ): ?array {
        if (! is_array($stateCluster)
            || (string) data_get($stateCluster, 'protocol') !== 'state_cluster_v1'
            || (string) data_get($stateCluster, 'status') !== 'assessed') {
            return null;
        }

        $vetoReason = strtolower(trim((string) data_get($stateCluster, 'veto_reason', '')));
        $transitionState = strtolower(trim((string) data_get($stateCluster, 'transition_state', '')));
        $liquidityState = strtolower(trim((string) data_get($stateCluster, 'spread_liquidity_state', '')));

        // Transition vetoes are tested with the existing firewall.  This is a
        // paired ablation/activation, not a permanent permission to trade in
        // transitions.
        if (($vetoReason === 'regime_transition_wait' || $transitionState === 'transition_wait')
            && array_key_exists('transition_firewall_enabled', $parameters)
            && isset($schema['transition_firewall_enabled'])
            && ! in_array('transition_firewall_enabled', $blockedKeys, true)) {
            $parameters['transition_firewall_enabled'] = ! (bool) $parameters['transition_firewall_enabled'];

            return $parameters;
        }

        // Cooldown evidence gets the state-conditioned policy toggle.  A
        // later child can test the candle length in the dedicated recall lane;
        // the monthly lane first asks whether the policy itself is useful.
        if (in_array($vetoReason, ['loss_cooldown', 'cooldown', 'loss_streak_wait'], true)
            && array_key_exists('dynamic_cooldown_enabled', $parameters)
            && isset($schema['dynamic_cooldown_enabled'])
            && ! in_array('dynamic_cooldown_enabled', $blockedKeys, true)) {
            $parameters['dynamic_cooldown_enabled'] = ! (bool) $parameters['dynamic_cooldown_enabled'];

            return $parameters;
        }

        // Spread vetoes receive a deliberately tiny, bounded envelope probe.
        // In a low-liquidity cluster the direction is conservative (tighter
        // admission); otherwise the child tests whether a small widening
        // recovers genuinely good opportunities.  Either direction remains
        // exposed to cost/stress and recall gates.
        if ($vetoReason === 'spread_to_atr'
            || str_contains($liquidityState, 'spread')) {
            if (array_key_exists('max_spread_atr_ratio', $parameters)
                && isset($schema['max_spread_atr_ratio'])
                && ! in_array('max_spread_atr_ratio', $blockedKeys, true)) {
                [, $min, $max] = array_pad($schema['max_spread_atr_ratio'], 3, null);
                $current = (float) $parameters['max_spread_atr_ratio'];
                $direction = str_contains($liquidityState, 'low') || str_contains($liquidityState, 'illiquid') ? -1 : 1;
                $parameters['max_spread_atr_ratio'] = round(max(
                    (float) $min,
                    min((float) $max, $current + ($direction * .005)),
                ), 4);

                return $parameters;
            }
        }

        // Negative-EV lower-bound vetoes are tested as a shadow ablation;
        // promotion still requires the original abstention-precision and
        // opportunity-recall thresholds.
        if (in_array($vetoReason, ['negative_ev_lower_bound', 'negative_ev', 'ev_lower_bound'], true)
            && array_key_exists('confidence_ev_lower_bound_enabled', $parameters)
            && isset($schema['confidence_ev_lower_bound_enabled'])
            && ! in_array('confidence_ev_lower_bound_enabled', $blockedKeys, true)) {
            $parameters['confidence_ev_lower_bound_enabled'] = ! (bool) $parameters['confidence_ev_lower_bound_enabled'];

            return $parameters;
        }

        return null;
    }

    /** Change one schema-bounded execution gene for a cost-resilience probe. */
    private function stressExitSingleGene(string $family, array $parameters, int $slot): array
    {
        $key = ['atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles'][$slot % 4];
        [$type, $min, $max] = array_pad($this->schemas->schema($family)[$key] ?? ['numeric', 0.5, 4.0], 3, null);
        $current = (float) ($parameters[$key] ?? (($min + $max) / 2));
        $step = match ($key) {
            'atr_stop_multiplier' => .15,
            'atr_target_multiplier' => .25,
            'trailing_atr_multiplier' => .2,
            default => 8,
        };
        $direction = ($slot % 2 === 0) ? 1 : -1;
        $value = max((float) $min, min((float) $max, $current + ($direction * $step)));
        $parameters[$key] = $type === 'integer' ? (int) round($value) : round($value, 4);

        return $parameters;
    }

    /**
     * Compile one deterministic, schema-valid shadow probe for a declared
     * role owner.  Shadow seats are not allowed to inherit a multi-gene
     * crossover accidentally; the returned vector is the frozen base plus
     * exactly the requested gene.  This is search-space hygiene only and
     * carries no mutation credit or promotion authority.
     */
    private function shadowSingleGeneProbe(
        string $family,
        array $base,
        array $parameters,
        string $gene,
        int $index,
    ): array {
        $schema = $this->schemas->schema($family);
        if (! array_key_exists($gene, $base) || ! isset($schema[$gene])) {
            return $base;
        }

        [$type, $min, $max] = array_pad($schema[$gene], 3, null);
        $current = $base[$gene];
        $candidate = $current;
        if ($type === 'string') {
            $choices = array_values((array) $min);
            if ($choices === []) {
                return $base;
            }
            $position = array_search($current, $choices, true);
            $position = $position === false ? 0 : (int) $position;
            $candidate = $choices[($position + $index + 1) % count($choices)];
        } elseif ($type === 'boolean') {
            $candidate = ! (bool) $current;
        } elseif (in_array($type, ['integer', 'numeric'], true) && is_numeric($min) && is_numeric($max)) {
            $span = (float) $max - (float) $min;
            $step = $type === 'integer' ? max(1.0, min(8.0, $span / 12.0)) : max(.0001, $span * .05);
            $direction = $index % 2 === 0 ? 1.0 : -1.0;
            $candidate = (float) $current + ($direction * $step * (1 + intdiv($index, 2)));
            $candidate = max((float) $min, min((float) $max, $candidate));
            $candidate = $type === 'integer' ? (int) round($candidate) : round($candidate, 4);
            if ($this->valuesDiffer($current, $candidate) === false) {
                $candidate = $direction > 0 ? (float) $current - $step : (float) $current + $step;
                $candidate = max((float) $min, min((float) $max, $candidate));
                $candidate = $type === 'integer' ? (int) round($candidate) : round($candidate, 4);
            }
        }

        if (! $this->valuesDiffer($current, $candidate)) {
            return $base;
        }
        $probe = $base;
        $probe[$gene] = $candidate;

        return $probe;
    }

    private function ensureNovelParameters(LabGeneration $generation, string $family, array $parameters, int $seed, bool $preserveDirectedMutation = false, ?string $singleGeneKey = null): array
    {
        $existing = $generation->agents()->with('modelVersion')->get()
            ->filter(fn ($agent) => $agent->strategy_family === $family)
            ->map(fn ($agent) => $agent->modelVersion?->parameters ?? [])
            ->values();

        for ($attempt = 0; $attempt < 12; $attempt++) {
            $duplicate = $existing->contains(fn (array $other) => $this->parameterFingerprint($family, $other) === $this->parameterFingerprint($family, $parameters)
                || (! $preserveDirectedMutation && $this->parameterDistance($family, $other, $parameters) < 0.08)
            );
            if (! $duplicate) {
                return $parameters;
            }
            if ($singleGeneKey && isset($this->schemas->schema($family)[$singleGeneKey])) {
                [$type, $min, $max] = array_pad($this->schemas->schema($family)[$singleGeneKey], 3, null);
                if ($type === 'boolean') {
                    $parameters[$singleGeneKey] = ! (bool) ($parameters[$singleGeneKey] ?? false);
                } elseif ($type === 'string') {
                    $choices = (array) $min;
                    $current = (string) ($parameters[$singleGeneKey] ?? ($choices[0] ?? ''));
                    $position = array_search($current, $choices, true);
                    $parameters[$singleGeneKey] = $choices[(($position === false ? 0 : $position) + $attempt + 1) % max(1, count($choices))];
                } else {
                    $current = (float) ($parameters[$singleGeneKey] ?? (($min + $max) / 2));
                    $direction = (($seed + $attempt) % 2 === 0) ? 1 : -1;
                    $value = max($min, min($max, $current + $direction * (($max - $min) * .07)));
                    $parameters[$singleGeneKey] = $type === 'integer' ? (int) round($value) : round($value, 4);
                }
            } else {
                $parameters = $this->randomParameters($family, $seed + (($attempt + 1) * 37));
            }
        }

        // The final fallback remains schema-valid and deterministic.  Its
        // fingerprint records that the family search space was exhausted.
        return $parameters;
    }

    /**
     * Keep evolution from replaying an exact historical topology forever.
     *
     * This is a search-space hygiene rule, not a quality shortcut: a novel
     * child must still pass every unchanged screening/full/forward gate.  For
     * a declared council/differential lane we mutate one contextual gene only;
     * an unrelated specialist branch is never changed to manufacture novelty.
     */
    private function ensureHistoricalNovelParameters(
        string $symbol,
        string $timeframe,
        string $family,
        array $parameters,
        int $seed,
        string $target,
        ?array $niche,
        ?string $isolatedKey = null,
        ?int $generationId = null,
        ?bool &$exhausted = null,
        mixed $forbiddenValue = null,
        ?string $forcedDirection = null,
    ): array {
        $cacheKeyBase = strtoupper($symbol).'|'.strtoupper($timeframe).'|'.$family;
        // The scheduler can keep one service container alive across multiple
        // generation builds. A market/family-only cache then freezes the
        // historical blacklist before the just-created cohort is visible,
        // allowing the next generation to replay the same failed topology.
        // Scope the cache and query to the current generation's predecessor
        // stream so every new cohort sees the complete prior history while
        // agents within one cohort still share one stable snapshot.
        $cacheKey = $generationId !== null
            ? $cacheKeyBase.'|generation:'.$generationId
            : $cacheKeyBase;
        if ($exhausted !== null) {
            $exhausted = false;
        }
        if (! array_key_exists($cacheKey, $this->historicalParameterFingerprints)) {
            // Historical novelty is a safety boundary for evolution, not a
            // recent-memory heuristic.  The old 160-agent window allowed a
            // failed portfolio member from an older generation to re-enter
            // the next council with the same topology, which then consumed a
            // full replay only to rediscover the same failure.  Load the
            // complete market/family history (the result is cached for this
            // generation build) so a new child is genuinely new before it
            // reaches screening.  This never converts a failure to a pass;
            // it only prevents redundant experiments.
            $this->historicalParameterFingerprints[$cacheKey] = $this->historicalParameterFingerprintSnapshot(
                $symbol,
                $timeframe,
                $family,
                $generationId,
            );
        }

        $historical = $this->historicalParameterFingerprints[$cacheKey];
        if (! $this->historicalFingerprintExists($historical, $this->parameterFingerprint($family, $parameters))) {
            return $parameters;
        }

        // G98/causal-isolation children have a sealed one-gene contract.  A
        // historical collision may require a bounded nudge, but it must never
        // add an unrelated novelty gene after the isolated mutation was
        // created.  If the declared gene is exhausted, preserve the original
        // child and report exhaustion to the constructor instead of silently
        // turning it into an uninterpretable multi-gene experiment.
        $keys = $isolatedKey !== null && isset($this->schemas->schema($family)[$isolatedKey])
            ? [$isolatedKey]
            : $this->historicalNoveltyKeys($family, $parameters, $target, $niche);
        foreach (range(0, 24) as $attempt) {
            $key = $keys[$attempt % max(1, count($keys))] ?? null;
            if (! $key || ! isset($this->schemas->schema($family)[$key])) {
                continue;
            }
            $candidate = $this->nudgeNoveltyParameter(
                $family,
                $parameters,
                $key,
                $attempt,
                $seed,
                $isolatedKey !== null,
            );
            if ($forbiddenValue !== null
                && array_key_exists($key, $candidate)
                && ! $this->valuesDiffer($forbiddenValue, $candidate[$key])) {
                // Do not let a historical nudge turn a one-gene child back
                // into its frozen parent/default. Continue to the next ring.
                continue;
            }
            if ($forcedDirection !== null
                && $key === $isolatedKey
                && array_key_exists($key, $candidate)
                && ! $this->candidateMatchesDirection($forbiddenValue, $candidate[$key], $forcedDirection)) {
                // Historical novelty may search a neighboring ring, but it
                // may not reverse an explicitly approved repair direction.
                // Reversing an abstention increase into a cooldown decrease
                // would invalidate the hypothesis while still claiming a
                // one-gene causal experiment.
                continue;
            }
            if (! $this->historicalFingerprintExists($historical, $this->parameterFingerprint($family, $candidate))) {
                return $candidate;
            }
        }

        // A bounded schema can genuinely exhaust one lane. Keep the original
        // candidate in that rare case and let the caller record an explicit
        // no-change control; no historical failure is converted into a pass.
        if ($exhausted !== null) {
            $exhausted = true;
        }

        return $parameters;
    }

    /**
     * Build the complete novelty blacklist without hydrating the very large
     * ModelVersion metadata documents.  The production archive currently has
     * only a few MB of executable parameters but hundreds of MB of provenance
     * metadata; loading full Eloquent models made the first seat of a family
     * monopolise the Windows constructor worker for tens of minutes.
     *
     * Both identities remain protected: the fingerprint recomputed from the
     * executable parameter vector and the legacy fingerprint recorded in
     * metadata.  A keyed set also makes each bounded novelty probe O(1).
     *
     * @return array<string, true>
     */
    private function historicalParameterFingerprintSnapshot(
        string $symbol,
        string $timeframe,
        string $family,
        ?int $generationId,
    ): array {
        $driver = DB::connection()->getDriverName();
        $recordedFingerprintSql = match ($driver) {
            'mysql', 'mariadb' => "JSON_UNQUOTE(JSON_EXTRACT(model_versions.metadata, '$.parameter_fingerprint'))",
            'pgsql' => "model_versions.metadata->>'parameter_fingerprint'",
            'sqlite' => "json_extract(model_versions.metadata, '$.parameter_fingerprint')",
            'sqlsrv' => "JSON_VALUE(model_versions.metadata, '$.parameter_fingerprint')",
            default => 'NULL',
        };
        $query = DB::table('lab_agents')
            ->join('model_versions', 'model_versions.id', '=', 'lab_agents.model_version_id')
            ->select('lab_agents.id as agent_id', 'model_versions.parameters')
            ->selectRaw("{$recordedFingerprintSql} as recorded_fingerprint")
            ->where('lab_agents.symbol', strtoupper($symbol))
            ->where('lab_agents.timeframe', strtoupper($timeframe))
            ->where('lab_agents.strategy_family', $family)
            ->when($generationId !== null, fn ($builder) => $builder->where('lab_agents.lab_generation_id', '<', $generationId))
            ->orderBy('lab_agents.id');

        $fingerprints = [];
        foreach ($query->cursor() as $row) {
            $parameters = is_array($row->parameters)
                ? $row->parameters
                : json_decode((string) $row->parameters, true);
            if (is_array($parameters) && $parameters !== []) {
                $fingerprints[$this->parameterFingerprint($family, $parameters)] = true;
            }
            $recorded = is_string($row->recorded_fingerprint) ? trim($row->recorded_fingerprint) : '';
            if ($recorded !== '') {
                $fingerprints[$recorded] = true;
            }
        }

        return $fingerprints;
    }

    /** @param array<int|string, mixed> $historical */
    private function historicalFingerprintExists(array $historical, string $fingerprint): bool
    {
        // Reflection-level regression fixtures written before the compact set
        // used a value list. Keep that representation readable while runtime
        // snapshots use hash keys for constant-time membership checks.
        return array_is_list($historical)
            ? in_array($fingerprint, $historical, true)
            : isset($historical[$fingerprint]);
    }

    private function candidateMatchesDirection(mixed $base, mixed $candidate, string $direction): bool
    {
        if (in_array($direction, ['enable', 'increase'], true)) {
            if (is_bool($base) || is_bool($candidate)) {
                return (bool) $base === false && (bool) $candidate === true;
            }

            return is_numeric($base) && is_numeric($candidate) && (float) $candidate > (float) $base;
        }
        if (in_array($direction, ['disable', 'decrease'], true)) {
            if (is_bool($base) || is_bool($candidate)) {
                return (bool) $base === true && (bool) $candidate === false;
            }

            return is_numeric($base) && is_numeric($candidate) && (float) $candidate < (float) $base;
        }

        return true;
    }

    private function historicalNoveltyKeys(string $family, array $parameters, string $target, ?array $niche): array
    {
        $objective = (string) data_get($niche, 'objective', '');
        $regime = (string) data_get($niche, 'regime', '');
        // Recall variants are deliberately single-gene experiments. If a
        // historical fingerprint collision occurs, only the declared gene
        // may be nudged; adding a second gene would invalidate the causal
        // contract and make the child impossible to interpret.
        if ($objective === 'opportunity_recall') {
            $variant = (string) data_get($niche, 'recall_variant', '');
            if ($variant === 'state_conditioned_cooldown') {
                return ['dynamic_cooldown_enabled'];
            }
            if ($variant === 'loss_cooldown_shortening') {
                return ['loss_cooldown_candles'];
            }
            if ($variant === 'transition_wait_shortening') {
                return ['transition_wait_candles'];
            }
            if ($variant === 'negative_ev_lower_bound_ablation') {
                return ['confidence_ev_lower_bound_enabled'];
            }
            if ($variant === 'spread_atr_recall_probe') {
                return ['max_spread_atr_ratio'];
            }
        }
        if ($family === 'hybrid' && $regime === 'range') {
            return match ($objective) {
                'monthly_survival' => ['range_signal_mode', 'range_deviation'],
                'temporal_stability' => ['range_deviation', 'range_lookback'],
                'calendar_context_rescue' => ['range_reentry_required', 'range_adx_max'],
                'stress_cost' => ['atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles'],
                'opportunity_recall' => ['dynamic_cooldown_enabled', 'confidence_ev_lower_bound_enabled', 'minimum_confidence', 'transition_wait_candles'],
                default => ['range_signal_mode', 'range_deviation', 'range_reentry_required'],
            };
        }
        if ($family === 'differential_router') {
            $prefix = in_array($regime, ['trend_up', 'trend_down', 'range'], true) ? $regime : 'trend_down';
            if ($prefix === 'range') {
                return ['range_signal_mode', 'range_deviation', 'range_reentry_required'];
            }

            return match ($objective) {
                'monthly_survival' => ["{$prefix}_roc_threshold", "{$prefix}_strength_min"],
                'temporal_stability' => ["{$prefix}_roc_period", "{$prefix}_pullback_atr_fraction"],
                'calendar_context_rescue' => ["{$prefix}_ema_period", "{$prefix}_pullback_atr_fraction"],
                'stress_cost' => ['atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles'],
                'opportunity_recall' => ['dynamic_cooldown_enabled', 'confidence_ev_lower_bound_enabled', 'minimum_confidence', 'minimum_signal_confidence', 'loss_cooldown_candles', 'weak_regime_wait_candles', 'transition_wait_candles'],
                default => ["{$prefix}_strength_min", "{$prefix}_pullback_atr_fraction", "{$prefix}_risk_multiplier"],
            };
        }

        $preferred = match ($target) {
            'trade_frequency' => ['lookback', 'confirmation_candles', 'minimum_signal_confidence'],
            'profit_factor', 'risk_exit' => ['atr_target_multiplier', 'atr_stop_multiplier', 'time_stop_candles'],
            'temporal_stability', 'monthly_survival', 'rolling_regime' => ['lookback', 'session_start', 'session_end', 'minimum_signal_confidence'],
            default => [],
        };
        $available = array_keys($this->schemas->schema($family));

        return array_values(array_filter([...$preferred, ...$available], fn (string $key): bool => array_key_exists($key, $parameters)));
    }

    private function nudgeNoveltyParameter(
        string $family,
        array $parameters,
        string $key,
        int $attempt,
        int $seed,
        bool $boundedIsolated = false,
    ): array {
        $schema = $this->schemas->schema($family);
        [$type, $min, $max] = array_pad($schema[$key], 3, null);
        $candidate = $parameters;
        if ($type === 'boolean') {
            $candidate[$key] = ! (bool) ($parameters[$key] ?? false);

            return $candidate;
        }
        if ($type === 'string') {
            $choices = array_values((array) $min);
            if ($choices === []) {
                return $candidate;
            }
            $current = (string) ($parameters[$key] ?? $choices[0]);
            $position = array_search($current, $choices, true);
            $offset = (($position === false ? 0 : $position) + $attempt + $seed + 1) % count($choices);
            $candidate[$key] = $choices[$offset];

            return $candidate;
        }
        $current = (float) ($parameters[$key] ?? (($min + $max) / 2));
        $span = max(0.0001, (float) $max - (float) $min);
        if ($type === 'integer' && $boundedIsolated) {
            // Integer genes have a discrete, auditable search space.  The
            // old percentage ring rounded small steps back to the same value
            // and could loop through an already-failed run of values (for
            // example 12..17) without ever reaching the next unseen integer.
            // Enumerate concentric integer rings instead: +1, -1, +2, -2,
            // ... with schema bounds applied and no wrap-around.
            $currentInteger = (int) round($current);
            $radius = intdiv($attempt, 2) + 1;
            $direction = (($attempt + $seed) % 2 === 0) ? 1 : -1;
            $candidate[$key] = max((int) $min, min((int) $max, $currentInteger + ($direction * $radius)));

            return $candidate;
        }
        $direction = (($attempt + $seed) % 2 === 0) ? 1 : -1;
        // Isolated council genes already have a declared experiment step.
        // Historical novelty searches concentric bounded rings: it starts
        // nearby, then expands only when the local ring is exhausted. This
        // keeps a probe interpretable while preventing an exhausted +/-1%
        // ring from silently resurrecting a known historical topology.
        $stepRate = $boundedIsolated
            ? (.01 + (.005 * intdiv($attempt, 4)))
            : (.035 + (.01 * intdiv($attempt, 4)));
        $step = $span * $stepRate;
        $value = $current + ($direction * $step);
        if ($boundedIsolated) {
            // An isolated child already has a declared bounded mutation. A
            // historical fingerprint collision may search locally, but it
            // must never wrap from the lower bound to the opposite edge of
            // the schema (for example ROC .10 -> 4.77). If the local lane is
            // exhausted, the caller preserves the original child and the
            // normal evidence gates decide its fate.
            $value = max((float) $min, min((float) $max, $value));
        } else {
            if ($value > (float) $max) {
                $value = (float) $min + $step;
            }
            if ($value < (float) $min) {
                $value = (float) $max - $step;
            }
        }
        $candidate[$key] = $type === 'integer' ? (int) round($value) : round($value, 4);

        return $candidate;
    }

    private function selectArchitecture(string $symbol, string $timeframe, string $family, string $origin, int $seed, ?string $scope, ?ModelVersion $parent): string
    {
        $architectures = self::ARCHITECTURES[$family] ?? [$family];
        $memory = MutationMemory::query()->where(compact('symbol', 'timeframe'))->where('strategy_family', $family)
            ->where('parameter_key', '__architecture')
            ->whereJsonContains('behavioral_effect->causal_credit->status', 'independently_confirmed')
            ->where('confidence', '>=', 60)
            ->when($scope, fn ($query) => $query->where('market_regime', $scope));
        $harmful = (clone $memory)->where('outcome', 'harmful')->where('confidence', '>=', 70)->where('forward_delta', '<', 0)
            ->pluck('new_value')->map(fn ($value) => data_get($value, 'value'))->filter()->unique()->all();
        $paused = $this->pausedArchitectures($symbol, $timeframe, $family);
        $available = array_values(array_diff($architectures, $harmful, $paused));
        if ($available === []) {
            $available = $architectures;
        } // search space is exhausted; re-test rather than silently stop learning.
        $parentArchitecture = data_get($parent?->metadata, 'strategy_architecture');
        if ($origin === 'elite' && in_array($parentArchitecture, $available, true)) {
            return $parentArchitecture;
        }
        $beneficial = (clone $memory)->where('outcome', 'beneficial')->where('forward_delta', '>', 0)->orderByDesc('confidence')->first();
        $preferred = data_get($beneficial?->new_value, 'value');
        // Mutation exploits an evidenced topology on two thirds of slots and
        // keeps one third exploratory so a formerly weak architecture can
        // recover under a different regime rather than becoming permanently extinct.
        if ($origin === 'mutation' && in_array($preferred, $available, true) && $seed % 3 !== 0) {
            return $preferred;
        }

        return $available[$seed % count($available)];
    }

    private function architectureBaseStrategy(string $architecture): string
    {
        return match ($architecture) {
            'trend_pullback' => 'trend_v1', 'trend_breakout_retest' => 'trend_retest_v1',
            'breakout_retest' => 'breakout_v1', 'breakout_continuation' => 'breakout_continuation_v1',
            'volatility_compression_expansion' => 'volatility_v1', 'volatility_breakout' => 'volatility_breakout_v1',
            'range_mean_reversion' => 'mean_reversion_v1', 'range_rsi_reversion' => 'range_rsi_reversion_v1',
            'session_breakout' => 'session_v1', 'session_mean_reversion' => 'session_mean_reversion_v1',
            'momentum_continuation' => 'momentum_v1', 'momentum_pullback' => 'momentum_pullback_v1',
            'regime_router' => 'hybrid_v1', 'regime_consensus' => 'regime_consensus_v1',
            'frozen_regime_specialist_ensemble' => 'regime_ensemble_v1',
            'frozen_parent_differential_router' => 'differential_router_v1',
            default => $architecture.'_v1',
        };
    }

    private function parameterFingerprint(string $family, array $parameters): string
    {
        $parameters = $this->canonicalFingerprintParameters($family, $parameters);

        return hash('sha256', $family.'|'.json_encode($parameters, JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * Keep parameter identity stable across JSON/database numeric round-trips.
     *
     * A generated child is fingerprinted before persistence and again after
     * its JSON cast is hydrated.  PHP can represent the same schema value as
     * an int or float at those two boundaries (for example 0 vs 0.0).  Those
     * type-only changes are not new experiments and must not let a historical
     * failed topology pass the novelty blacklist.
     */
    private function canonicalFingerprintParameters(string $family, array $parameters): array
    {
        return $this->schemas->canonicalizeForIdentity($family, $parameters);
    }

    private function parameterDistance(string $family, array $left, array $right): float
    {
        $schema = $this->schemas->schema($family);
        $distance = 0.0;
        $count = 0;
        foreach ($schema as $key => $definition) {
            [$type, $min, $max] = array_pad($definition, 3, null);
            if (! array_key_exists($key, $left) || ! array_key_exists($key, $right)) {
                continue;
            }
            $count++;
            if ($type === 'boolean') {
                $distance += (bool) $left[$key] === (bool) $right[$key] ? 0.0 : 1.0;

                continue;
            }
            if ($type === 'string') {
                $distance += (string) $left[$key] === (string) $right[$key] ? 0.0 : 1.0;

                continue;
            }
            $distance += abs((float) $left[$key] - (float) $right[$key]) / max(0.000001, (float) $max - (float) $min);
        }

        return $count ? $distance / $count : 1.0;
    }

    private function mutate(string $symbol, string $timeframe, string $family, array $base, int $seed, ?string $scope, string $target = 'profit_factor', bool $isolated = false, array $historyKeys = [], array $decisionPacket = []): array
    {
        $schema = $this->schemas->schema($family);
        $signatureBound = in_array($target, ['monthly_survival', 'regime_coverage', 'volatility_session_stability', 'exit_topology', 'transition_firewall', 'portfolio_router', 'opportunity_recall', 'unknown_state_curiosity'], true);
        $historicalPrior = $this->historicalLearning->confirmedMutationPrior($symbol, $timeframe, $family, $scope);
        $beneficial = MutationMemory::where(compact('symbol', 'timeframe'))->where('strategy_family', $family)
            ->when($scope, fn ($query) => $query->where('market_regime', $scope))
            ->when($signatureBound && $scope, fn ($query) => $query->whereJsonContains('behavioral_effect->failure_signature->regime', $scope))
            ->whereJsonContains('behavioral_effect->causal_credit->status', 'independently_confirmed')
            ->where('outcome', 'beneficial')->where('confidence', '>=', 60)->where('forward_delta', '>', 0)
            ->whereNotNull('execution_contract_hash')->where('independent_confirmation_count', '>=', 2)
            ->where('non_target_regression_status', 'passed')
            ->orderByDesc('confidence')->first();
        $harmful = MutationMemory::where(compact('symbol', 'timeframe'))->where('strategy_family', $family)
            ->when($scope, fn ($query) => $query->where('market_regime', $scope))
            ->when($signatureBound && $scope, fn ($query) => $query->whereJsonContains('behavioral_effect->failure_signature->regime', $scope))
            ->whereJsonContains('behavioral_effect->causal_credit->status', 'independently_confirmed')
            ->where('outcome', 'harmful')->where('confidence', '>=', 60)->where('forward_delta', '<', 0)
            ->orderByDesc('confidence')->first();
        $keys = array_keys($schema);
        $targetKeys = match ($target) {
            // Trade deficit: relax only the filter proven to be preventing
            // entries. Do not solve starvation by silently increasing risk.
            'trade_frequency' => ['lookback', 'confirmation_candles', 'minimum_signal_confidence', 'trend_strength_min', 'roc_threshold', 'deviation', 'atr_threshold', 'compression_ratio'],
            // Shadow regret pointed to this exact guard. Keep the rescue
            // bounded to the guard and its online evidence threshold.
            'shadow_veto_loss_cooldown' => ['loss_cooldown_candles', 'cooldown_shadow_edge_pf', 'cooldown_shadow_min_samples'],
            'shadow_veto_confidence' => ['minimum_signal_confidence'],
            'shadow_veto_volatility' => ['high_volatility_risk_multiplier', 'avoid_high_volatility'],
            // PF is an exit-topology experiment: stop/target/trailing/time
            // exits and partials are changed as a coherent family.
            'profit_factor', 'risk_exit', 'stress_cost' => ['atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles', 'partial_take_profit_fraction', 'partial_target_atr_multiplier'],
            'drawdown_risk' => ['high_volatility_risk_multiplier', 'max_loss_streak_before_wait', 'loss_cooldown_candles', 'atr_stop_multiplier', 'avoid_high_volatility'],
            // These are deliberately separate targets. A temporal collapse,
            // a bad regime, and a poor month are different failures and must
            // not all mutate the same undifferentiated \"rolling\" bundle.
            'temporal_stability' => ['lookback', 'session_start', 'session_end', 'minimum_signal_confidence'],
            'monthly_survival' => [
                'session_filter_enabled', 'session_start', 'session_end',
                'transition_firewall_enabled', 'transition_wait_candles',
                'trend_roc_period', 'trend_roc_threshold', 'trend_ema_period',
                'breakout_lookback', 'breakout_atr_threshold',
                'range_lookback', 'range_deviation', 'range_adx_max',
            ],
            'regime_coverage' => ['trend_strength_min', 'high_volatility_risk_multiplier', 'minimum_signal_confidence', 'lookback'],
            // G98 council lanes are isolated.  Their lists describe a
            // possible single causal gene, never a bundle to optimise PF.
            'volatility_session_stability' => ['session_filter_enabled', 'session_start', 'session_end', 'high_volatility_risk_multiplier', 'avoid_high_volatility'],
            'exit_topology' => ['atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles', 'partial_take_profit_fraction', 'partial_target_atr_multiplier'],
            'portfolio_router' => ['differential_target_regime', 'differential_target_volatility', 'differential_target_direction', 'minimum_signal_confidence'],
            'opportunity_recall' => ['differential_target_min_signal_confidence', 'minimum_confidence', 'minimum_signal_confidence', 'loss_cooldown_candles', 'weak_regime_wait_candles', 'transition_wait_candles', 'dynamic_cooldown_enabled', 'confidence_ev_lower_bound_enabled'],
            // Curiosity may inspect uncertainty and risk boundaries only. It
            // never receives the broad PF/exit bundle and its evidence is
            // permanently marked research-only by the child contract.
            'unknown_state_curiosity' => ['minimum_signal_confidence', 'minimum_confidence', 'transition_firewall_enabled', 'transition_wait_candles', 'high_volatility_risk_multiplier', 'avoid_high_volatility'],
            'rolling_regime', 'architecture' => ['lookback', 'session_start', 'session_end', 'trend_strength_min', 'minimum_signal_confidence', 'high_volatility_risk_multiplier'],
            default => $keys,
        };
        // Hybrid calendar/temporal failures must mutate the real specialist
        // adapters. A generic lookback mutation used to change nothing in the
        // hybrid runtime because its trend/breakout/range lookbacks were
        // hard-coded constants.
        if ($family === 'hybrid') {
            $targetKeys = match ($target) {
                'temporal_stability', 'monthly_survival' => [
                    'session_filter_enabled', 'session_start', 'session_end',
                    'transition_firewall_enabled', 'transition_wait_candles',
                    'trend_roc_period', 'trend_roc_threshold', 'trend_ema_period',
                    'breakout_lookback', 'breakout_atr_threshold',
                    'range_lookback', 'range_deviation', 'range_adx_max',
                ],
                'regime_coverage' => [
                    'trend_roc_period', 'trend_roc_threshold', 'trend_ema_period',
                    'range_lookback', 'range_deviation', 'range_adx_max',
                    'range_low_volatility_only', 'high_volatility_wait',
                    'trend_up_strength_min', 'trend_down_strength_min',
                ],
                'volatility_session_stability' => [
                    'session_filter_enabled', 'session_start', 'session_end',
                    'high_volatility_wait', 'high_volatility_risk_multiplier',
                ],
                'exit_topology' => [
                    'trend_atr_stop_multiplier', 'trend_atr_target_multiplier',
                    'breakout_atr_stop_multiplier', 'breakout_atr_target_multiplier',
                    'range_atr_stop_multiplier', 'range_atr_target_multiplier',
                ],
                'trade_frequency' => [
                    'trend_weight', 'breakout_weight', 'mean_reversion_weight',
                    'minimum_confidence', 'session_filter_enabled',
                ],
                default => $targetKeys,
            };
        }
        if ($family === 'differential_router' && in_array($target, ['temporal_stability', 'monthly_survival', 'volatility_session_stability'], true)) {
            $targetKeys = [
                'session_filter_enabled', 'session_start', 'session_end',
                'transition_firewall_enabled', 'transition_wait_candles',
                'trend_roc_period', 'trend_roc_threshold', 'trend_ema_period',
                'breakout_lookback', 'breakout_atr_threshold',
                'range_lookback', 'range_deviation', 'range_adx_max',
            ];
        }
        // Immutable failure profiles narrow the experiment to genes that can
        // affect the diagnosed failure. This is a search prior, never a gate
        // shortcut; a snapshot-only insight is still marked non-causal.
        $historyTargetKeys = array_values(array_intersect($targetKeys, $historyKeys));
        if ($historyTargetKeys !== []) {
            $targetKeys = $historyTargetKeys;
        }
        $targetKeys = array_values(array_intersect($keys, $targetKeys));
        if ($targetKeys !== []) {
            $keys = $targetKeys;
        }
        $latestDiagnosis = AgentDiagnosis::query()->whereHas('modelMarketPerformance', fn ($query) => $query
            ->where('symbol', $symbol)->where('timeframe', $timeframe)->where('strategy_family', $family))->latest()->first();
        $latestDeficits = (array) data_get($latestDiagnosis?->evidence, 'deficits', []);
        // A high-confidence harmful mutation is evidence, not a tie-breaker.
        // Keep it out of the next search unless every available gene has been
        // falsified; otherwise a single historic beneficial result could keep
        // resurrecting a known-bad direction.
        $harmfulKeys = MutationMemory::where(compact('symbol', 'timeframe'))->where('strategy_family', $family)
            ->when($scope, fn ($query) => $query->where('market_regime', $scope))
            ->whereJsonContains('behavioral_effect->causal_credit->status', 'independently_confirmed')
            ->where('outcome', 'harmful')->where('confidence', '>=', 70)->where('forward_delta', '<', 0)
            ->pluck('parameter_key')->unique()->all();
        $safeKeys = array_values(array_diff($keys, $harmfulKeys));
        $knowledgeBlockedKeys = $this->knowledge->blockedMutationKeys($symbol, $timeframe, $family, $scope);
        $safeKeys = array_values(array_diff($safeKeys, $knowledgeBlockedKeys));
        $memoryContext = $this->learningContextFromMutationScope($scope);
        $memoryBlockedKeys = $this->learningMemory->blockedMutationKeys($symbol, $timeframe, $family, $safeKeys, $memoryContext);
        $safeKeys = array_values(array_diff($safeKeys, $memoryBlockedKeys));
        if ($safeKeys !== []) {
            $keys = $safeKeys;
        }
        $budget = app(AgentProfessionalExamService::class)->mutationBudget($symbol, $timeframe, $family);
        $budgetKeys = app(AgentProfessionalExamService::class)->allowedMutationKeys($keys, $budget);
        if ($budgetKeys !== []) {
            $keys = $budgetKeys;
        }
        // A fully exhausted lane is an explicit WAIT/retest outcome. It must
        // not resurrect a confirmed harmful gene merely to fill a slot.
        if ($keys === []) {
            return $base;
        }
        // Three repeated changes with no observable behavioural movement are
        // not treated as an optimisation signal. Temporarily park the gene so
        // the generation budget can test a materially different mechanism.
        $ineffectiveKeys = MutationMemory::where(compact('symbol', 'timeframe'))->where('strategy_family', $family)
            ->whereIn('parameter_key', $keys)->get()
            ->filter(fn (MutationMemory $memory) => data_get($memory->behavioral_effect, 'causal_experiment.parameter_effective') === false)
            ->pluck('parameter_key')->unique()->all();
        $effectiveKeys = array_values(array_diff($keys, $ineffectiveKeys));
        if ($effectiveKeys !== []) {
            $keys = $effectiveKeys;
        }
        // Confirmed memory is not a hint applied after a random mutation.
        // It gets the first right to build the exact one-gene intervention
        // from the packet retrieved before compilation. If its context,
        // baseline value, schema, or causal cartridge does not match, memory
        // abstains and the ordinary exploration stack remains explicit.
        $memoryIntervention = app(CausalLearningMutationIntentService::class)->selectIntervention(
            $decisionPacket,
            $base,
            $schema,
            $keys,
        );
        if (data_get($memoryIntervention, 'status') === 'selected') {
            return (array) data_get($memoryIntervention, 'parameters', $base);
        }
        $diagnosedKey = AgentDiagnosis::whereHas('modelMarketPerformance', fn ($q) => $q->where('symbol', $symbol)->where('timeframe', $timeframe)->where('strategy_family', $family))
            ->latest()->get()->flatMap(fn ($item) => $item->recommended_mutations ?? [])->first(fn ($key) => isset($schema[$key]) && in_array($key, $keys, true));
        $decisionAdvice = $this->decisionLearning->advice($symbol, $timeframe, $family, $scope);
        $decisionKey = collect($decisionAdvice['prioritize'])->first(fn ($key) => isset($schema[$key]) && in_array($key, $keys, true));
        $memoryRecommendation = $this->learningMemory->recommend($symbol, $timeframe, $family, $keys, $target, null, $memoryContext);
        $memoryKey = data_get($memoryRecommendation, 'parameter_key');
        if (! $beneficial && $harmful && count($keys) > 1) {
            $keys = array_values(array_diff($keys, [$harmful->parameter_key]));
        }
        $bayesian = $this->bayesianMutations->recommend($symbol, $timeframe, $family, $keys, $latestDeficits, $scope);
        $bayesianKey = data_get($bayesian, 'evidence_status') === 'confirmed_prior'
            && isset($schema[data_get($bayesian, 'parameter_key')])
            && in_array(data_get($bayesian, 'parameter_key'), $keys, true)
            ? data_get($bayesian, 'parameter_key') : null;
        $banditContext = [
            'regime' => $scope ?: 'unknown',
            'session' => data_get($memoryContext, 'session_utc_hour', data_get($memoryContext, 'session', 'unknown')),
            'volume_state' => data_get($memoryContext, 'volume_state', 'unknown'),
            'temporal_window' => data_get($memoryContext, 'temporal_window', 'unknown'),
            'side' => data_get($memoryContext, 'side', 'both'),
        ];
        $bandit = app(ContextualMutationBanditService::class)->recommend($symbol, $timeframe, $family, $keys, $target, $banditContext);
        $banditKey = data_get($bandit, 'parameter_key');
        $banditKey = $banditKey && isset($schema[$banditKey]) && in_array($banditKey, $keys, true) ? $banditKey : null;
        $historicalKey = $historicalPrior && isset($schema[$historicalPrior['parameter_key']])
            && in_array($historicalPrior['parameter_key'], $keys, true) ? $historicalPrior['parameter_key'] : null;
        $key = $beneficial?->parameter_key && isset($schema[$beneficial->parameter_key]) && in_array($beneficial->parameter_key, $keys, true)
            ? $beneficial->parameter_key : ($memoryKey && isset($schema[$memoryKey]) && in_array($memoryKey, $keys, true)
                ? $memoryKey : ($banditKey ?: ($historicalKey ?: ($bayesianKey ?: ($decisionKey ?: ($diagnosedKey ?: $keys[$seed % count($keys)]))))));
        // The first hybrid monthly-survival experiment must actually test the
        // missing calendar mechanism. Selecting an execution gene here would
        // reproduce G71's PF rescue and leave the month failure untouched.
        if (in_array($family, ['hybrid', 'differential_router'], true)
            && $target === 'monthly_survival'
            && isset($schema['transition_firewall_enabled'])) {
            $key = 'transition_firewall_enabled';
        }
        [$type, $min, $max] = array_pad($schema[$key], 3, null);
        $learnedDirection = static fn (MutationMemory $memory): int => data_get($memory->new_value, 'value') >= data_get($memory->old_value, 'value') ? 1 : -1;
        $direction = $beneficial && is_numeric(data_get($beneficial->new_value, 'value')) && is_numeric(data_get($beneficial->old_value, 'value'))
            ? $learnedDirection($beneficial)
            : ($harmful && is_numeric(data_get($harmful->new_value, 'value')) && is_numeric(data_get($harmful->old_value, 'value'))
                ? -$learnedDirection($harmful)
                : ($historicalPrior && is_numeric($historicalPrior['direction'] ?? null)
                    ? (int) $historicalPrior['direction'] * ($historicalPrior['outcome'] === 'harmful' ? -1 : 1)
                    : ($seed % 2 === 0 ? 1 : -1)));
        if ($target === 'trade_frequency' && in_array($key, ['lookback', 'confirmation_candles', 'minimum_signal_confidence', 'trend_strength_min', 'roc_threshold', 'deviation', 'atr_threshold'], true)) {
            $direction = -1;
        }
        if ($target === 'shadow_veto_loss_cooldown' && in_array($key, ['loss_cooldown_candles', 'cooldown_shadow_edge_pf', 'cooldown_shadow_min_samples'], true)) {
            $direction = -1;
        }
        if ($target === 'shadow_veto_confidence') {
            $direction = -1;
        }
        if ($target === 'shadow_veto_volatility' && $key === 'high_volatility_risk_multiplier') {
            $direction = 1;
        }
        if ($target === 'opportunity_recall' && in_array($key, ['minimum_confidence', 'minimum_signal_confidence', 'loss_cooldown_candles', 'weak_regime_wait_candles'], true)) {
            $direction = -1;
        }
        if ($target === 'drawdown_risk' && in_array($key, ['high_volatility_risk_multiplier'], true)) {
            $direction = -1;
        }
        if ($target === 'drawdown_risk' && in_array($key, ['max_loss_streak_before_wait', 'loss_cooldown_candles', 'atr_stop_multiplier'], true)) {
            $direction = 1;
        }
        if ($type === 'boolean') {
            $base[$key] = ! (bool) ($base[$key] ?? false);

            return $isolated ? $base : $this->applyBoundedBundle($schema, $base, $key, $target, $direction);
        }
        if ($type === 'string') {
            $allowed = array_values(array_filter((array) $min, fn (mixed $value): bool => is_string($value)));
            $alternatives = array_values(array_diff($allowed, [(string) ($base[$key] ?? '')]));
            if ($alternatives === []) {
                return $base;
            }
            $base[$key] = $alternatives[$seed % count($alternatives)];

            // Enum/string genes are complete topology interventions. They are
            // never followed by a numeric bundle, even in a broad discovery
            // lane, because that would destroy single-gene attribution.
            return $base;
        }
        if (! in_array($type, ['integer', 'numeric'], true) || ! is_numeric($min) || ! is_numeric($max)) {
            return $base;
        }
        $current = (float) ($base[$key] ?? (($min + $max) / 2));
        $step = ($beneficial || $harmful) ? 0.05 : 0.1;
        $value = max($min, min($max, $current + $direction * (($max - $min) * $step)));
        $base[$key] = $type === 'integer' ? (int) round($value) : round($value, 4);

        return $isolated ? $base : $this->applyBoundedBundle($schema, $base, $key, $target, $direction);
    }

    /**
     * Scale only the already-selected numeric gene for a risk-bounded mode.
     * The method is intentionally conservative: a missing/ambiguous diff is
     * left untouched so causal attribution and the constructor invariant stay
     * intact.
     */
    private function applyRiskBoundedMutationStep(
        string $family,
        array $base,
        array $parameters,
        string $mode,
        float $configuredMultiplier,
    ): array {
        $multiplier = match ($mode) {
            'bold_explorer' => max($configuredMultiplier, (float) config('services.lab_selection.bold_mutation_step_multiplier', 2.0)),
            'proven_gene_refinement', 'independent_pass' => max($configuredMultiplier, (float) config('services.lab_selection.proven_gene_step_multiplier', 1.5)),
            'screen_pass' => max($configuredMultiplier, (float) config('services.lab_selection.screen_pass_step_multiplier', 1.2)),
            'uncertainty' => min($configuredMultiplier, (float) config('services.lab_selection.uncertainty_step_multiplier', .75)),
            default => 1.0,
        };
        if ($multiplier === 1.0) {
            return $parameters;
        }

        $diff = $this->diff($base, $parameters);
        if (count($diff) !== 1) {
            return $parameters;
        }
        $key = (string) array_key_first($diff);
        $schema = $this->schemas->schema($family);
        if (! isset($schema[$key])) {
            return $parameters;
        }
        [$type, $min, $max] = array_pad($schema[$key], 3, null);
        if (! in_array($type, ['integer', 'number', 'float', 'numeric'], true)
            || ! is_numeric($min) || ! is_numeric($max)
            || ! is_numeric($base[$key] ?? null) || ! is_numeric($parameters[$key] ?? null)) {
            return $parameters;
        }
        $old = (float) $base[$key];
        $current = (float) $parameters[$key];
        $delta = $current - $old;
        if ($delta == 0.0) {
            return $parameters;
        }
        $value = max((float) $min, min((float) $max, $old + ($delta * $multiplier)));
        $parameters[$key] = $type === 'integer' ? (int) round($value) : round($value, 4);

        return $parameters;
    }

    /** @return array<string, string>|null */
    private function learningContextFromMutationScope(?string $scope): ?array
    {
        if ($scope === null || trim($scope) === '') {
            return null;
        }
        [$prefix, $value] = array_pad(explode(':', trim($scope), 2), 2, null);
        if (! is_string($value) || trim($value) === '') {
            return ['regime' => trim($scope)];
        }

        return match (strtolower(trim((string) $prefix))) {
            'market', 'regime' => ['regime' => trim($value)],
            'volatility' => ['volatility' => trim($value)],
            'session' => ['session' => trim($value)],
            default => ['regime' => trim($scope)],
        };
    }

    /** A mutation is a small coherent experiment, never an unbounded re-fit. */
    private function applyBoundedBundle(array $schema, array $parameters, string $primary, string $target, int $direction): array
    {
        $bundle = match ($target) {
            'trade_frequency' => ['lookback', 'confirmation_candles', 'minimum_signal_confidence', 'trend_strength_min'],
            'shadow_veto_loss_cooldown' => ['loss_cooldown_candles', 'cooldown_shadow_edge_pf', 'cooldown_shadow_min_samples'],
            'shadow_veto_confidence' => ['minimum_signal_confidence'],
            'shadow_veto_volatility' => ['high_volatility_risk_multiplier', 'avoid_high_volatility'],
            'profit_factor', 'risk_exit' => ['atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles', 'partial_take_profit_fraction'],
            'drawdown_risk' => ['high_volatility_risk_multiplier', 'max_loss_streak_before_wait', 'loss_cooldown_candles', 'avoid_high_volatility'],
            'temporal_stability' => ['lookback', 'session_start', 'session_end', 'minimum_signal_confidence'],
            'monthly_survival' => [
                'session_filter_enabled', 'session_start', 'session_end',
                'transition_firewall_enabled', 'transition_wait_candles',
                'trend_roc_period', 'trend_roc_threshold', 'trend_ema_period',
                'breakout_lookback', 'breakout_atr_threshold',
                'range_lookback', 'range_deviation', 'range_adx_max',
            ],
            'regime_coverage' => ['trend_strength_min', 'high_volatility_risk_multiplier', 'minimum_signal_confidence'],
            'opportunity_recall' => ['minimum_confidence', 'minimum_signal_confidence', 'loss_cooldown_candles', 'weak_regime_wait_candles', 'transition_wait_candles', 'dynamic_cooldown_enabled', 'confidence_ev_lower_bound_enabled'],
            'rolling_regime', 'architecture' => ['lookback', 'session_start', 'session_end', 'minimum_signal_confidence'],
            default => [],
        };
        // Primary plus two related controls is enough to test topology while
        // retaining a causal attribution path for each bundle.
        foreach (array_slice(array_values(array_diff($bundle, [$primary])), 0, 2) as $key) {
            if (! isset($schema[$key])) {
                continue;
            }
            [$type, $min, $max] = array_pad($schema[$key], 3, null);
            if ($type === 'boolean') {
                if ($target === 'drawdown_risk') {
                    $parameters[$key] = true;
                }

                continue;
            }
            $current = (float) ($parameters[$key] ?? (($min + $max) / 2));
            $secondaryDirection = $target === 'trade_frequency' ? -1 : $direction;
            if ($target === 'drawdown_risk' && $key === 'high_volatility_risk_multiplier') {
                $secondaryDirection = -1;
            }
            $value = max($min, min($max, $current + $secondaryDirection * (($max - $min) * .03)));
            $parameters[$key] = $type === 'integer' ? (int) round($value) : round($value, 4);
        }

        return $parameters;
    }
}
