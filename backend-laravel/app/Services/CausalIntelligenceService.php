<?php

namespace App\Services;

use App\Models\CausalCounterfactual;
use App\Models\CausalDiscoveryRun;
use App\Models\CausalEdge;
use App\Models\CausalEffectEstimate;
use App\Models\CausalExperiment;
use App\Models\CausalIntervention;
use App\Models\CausalNode;
use App\Models\CausalRootCause;
use App\Models\DiscoveryQualityScore;
use App\Models\QuantLaw;
use App\Models\StrategyDnaProfile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class CausalIntelligenceService
{
    public function discover(): ?CausalDiscoveryRun
    {
        if (! Schema::hasTable('causal_discovery_runs')) {
            return null;
        }

        $run = CausalDiscoveryRun::create([
            'status' => 'running',
            'started_at' => now(),
        ]);

        $edges = $this->buildCausalGraph($run);
        $effects = $this->estimateEffects($edges);
        $counterfactuals = $this->buildCounterfactuals($edges);
        $interventions = $this->proposeInterventions($edges);
        $experiments = $this->planExperiments($edges);
        $rootCauses = $this->rankRootCauses($edges);
        $this->scoreDiscoveryQuality($edges);

        $run->update([
            'status' => 'success',
            'finished_at' => now(),
            'edges_created' => $edges->count(),
            'effects_estimated' => $effects->count(),
            'interventions_created' => $interventions->count(),
            'experiments_created' => $experiments->count(),
            'summary' => "Causal Intelligence built {$edges->count()} causal edges, {$counterfactuals->count()} counterfactuals and {$rootCauses->count()} root causes.",
            'metrics' => [
                'edge_ids' => $edges->pluck('id')->values()->all(),
                'root_cause_ids' => $rootCauses->pluck('id')->values()->all(),
                'warning' => 'Causal scores are identifiability-aware estimates, not proof without experiments.',
            ],
        ]);

        return $run;
    }

    private function buildCausalGraph(CausalDiscoveryRun $run): Collection
    {
        if (! Schema::hasTable('quant_laws')) {
            return collect();
        }

        return QuantLaw::query()
            ->whereIn('status', ['active', 'emerging'])
            ->orderByDesc('confidence_score')
            ->take(100)
            ->get()
            ->map(function (QuantLaw $law) use ($run): CausalEdge {
                $driver = (string) data_get($law->scope, 'driver', 'unknown_driver');
                $target = (string) data_get($law->scope, 'target', 'unknown_target');
                $direction = (string) data_get($law->scope, 'direction', 'negative');
                $source = $this->node($driver, 'driver', (float) $law->confidence_score);
                $targetNode = $this->node($target, 'outcome', (float) $law->confidence_score);
                $causality = $this->causalityScore($law);
                $invariance = $this->invariantEvidence($law);
                $status = $causality >= 75 ? 'provisionally_identified' : ($causality >= 45 ? 'partially_identified' : 'associational');

                return CausalEdge::updateOrCreate(
                    ['edge_key' => "causal:{$driver}:{$target}:{$law->id}"],
                    [
                        'causal_discovery_run_id' => $run->id,
                        'source_node_id' => $source->id,
                        'target_node_id' => $targetNode->id,
                        'quant_law_id' => $law->id,
                        'direction' => $direction,
                        'identification_status' => $status,
                        'causality_score' => round($causality, 2),
                        'correlation_score' => (float) $law->confidence_score,
                        'effect_size' => (float) $law->effect_size,
                        'evidence_count' => (int) $law->evidence_count,
                        'rationale' => "{$law->title} is evaluated as a causal candidate, not automatically accepted as causal truth.",
                        'assumptions' => [
                            'no_unmeasured_confounding' => $causality >= 75 ? 'plausible' : 'unproven',
                            'temporal_order' => 'driver_precedes_target_by_design_or_proxy',
                            'positivity' => (int) $law->strategy_count >= 2 ? 'reasonable' : 'weak',
                        ],
                        'metadata' => [
                            'law_key' => $law->law_key,
                            'law_status' => $law->status,
                            'universality_score' => $law->universality_score,
                            'invariant_causal_evidence' => $invariance,
                        ],
                    ],
                );
            });
    }

    private function estimateEffects(Collection $edges): Collection
    {
        return $edges->map(function (CausalEdge $edge): CausalEffectEstimate {
            $effect = $this->signedEffect($edge);
            $uncertainty = max(8, 45 - ((float) $edge->causality_score * 0.35));

            return CausalEffectEstimate::create([
                'causal_edge_id' => $edge->id,
                'estimand' => 'average_treatment_effect',
                'effect_estimate' => round($effect, 3),
                'confidence_score' => $edge->causality_score,
                'lower_bound' => round($effect - $uncertainty, 3),
                'upper_bound' => round($effect + $uncertainty, 3),
                'method' => 'law_adjusted_backtest_proxy',
                'adjustment_set' => ['strategy_family', 'market_regime', 'volatility_proxy'],
                'metadata' => [
                    'effect_source' => 'quant_law_effect_size',
                    'identification_status' => $edge->identification_status,
                ],
            ]);
        });
    }

    private function buildCounterfactuals(Collection $edges): Collection
    {
        return $edges->map(function (CausalEdge $edge): CausalCounterfactual {
            $source = $edge->sourceNode?->label ?? 'driver';
            $target = $edge->targetNode?->label ?? 'outcome';
            $baseline = $source === 'trend dependency' || $source === 'trend_dependency' ? 90 : 80;
            $intervention = $source === 'trend dependency' || $source === 'trend_dependency' ? 50 : 55;
            $delta = abs($baseline - $intervention) / 40 * abs($this->signedEffect($edge)) * 0.35;

            if ($edge->direction === 'negative') {
                $delta = abs($delta);
            }

            return CausalCounterfactual::create([
                'causal_edge_id' => $edge->id,
                'question' => "If {$source} moved from {$baseline} to {$intervention}, what happens to {$target}?",
                'baseline_value' => $baseline,
                'intervention_value' => $intervention,
                'estimated_delta' => round($delta, 3),
                'confidence_score' => $edge->causality_score,
                'result_summary' => "{$target} estimated change: +".round($delta, 2)."% under intervention.",
                'metadata' => ['direction' => $edge->direction],
            ]);
        });
    }

    private function proposeInterventions(Collection $edges): Collection
    {
        return $edges->filter(fn (CausalEdge $edge): bool => (float) $edge->causality_score >= 45)
            ->map(function (CausalEdge $edge): CausalIntervention {
                $source = $edge->sourceNode?->label ?? 'driver';
                $target = $edge->targetNode?->label ?? 'outcome';
                $reduce = $edge->direction === 'negative';

                return CausalIntervention::create([
                    'causal_edge_id' => $edge->id,
                    'title' => ($reduce ? 'Reduce ' : 'Increase ').$source,
                    'intervention_type' => 'strategy_parameter_policy',
                    'recommendation' => ($reduce ? 'Reduce excessive ' : 'Increase controlled ').$source." to improve {$target}; apply only through experiment-gated evolution.",
                    'expected_impact_score' => round($this->clamp(abs($edge->effect_size) * 0.45 + $edge->causality_score * 0.55), 2),
                    'cost_score' => $reduce ? 42 : 55,
                    'risk_score' => round($this->clamp(100 - (float) $edge->causality_score + abs((float) $edge->effect_size) * 0.15), 2),
                    'status' => 'proposed',
                    'parameters' => [
                        'driver' => $source,
                        'target' => $target,
                        'action' => $reduce ? 'decrease' : 'increase',
                    ],
                    'metadata' => ['identification_status' => $edge->identification_status],
                ]);
            })->values();
    }

    private function planExperiments(Collection $edges): Collection
    {
        return $edges->map(function (CausalEdge $edge): CausalExperiment {
            $source = $edge->sourceNode?->label ?? 'driver';
            $target = $edge->targetNode?->label ?? 'outcome';

            return CausalExperiment::updateOrCreate(
                ['experiment_key' => 'causal-exp:'.$edge->edge_key],
                [
                    'causal_edge_id' => $edge->id,
                    'title' => "Test causal effect of {$source} on {$target}",
                    'hypothesis' => "{$source} causally affects {$target}, beyond correlation found in Quant Laws.",
                    'status' => 'planned',
                    'control_group' => "Existing strategies with current {$source}.",
                    'experimental_group' => "Matched strategies with intervened {$source}.",
                    'expected_information_gain' => round($this->clamp(35 + (float) $edge->causality_score * 0.55), 2),
                    'success_criteria' => [
                        'minimum_samples' => 30,
                        'target_metric' => $target,
                        'holdout_required' => true,
                        'required_invariance_checks' => ['residual_invariance', 'cross_market_stability', 'placebo_intervention', 'negative_control', 'effect_sign_consistency', 'unseen_period_validation'],
                    ],
                    'metadata' => ['edge_id' => $edge->id],
                ],
            );
        });
    }

    private function rankRootCauses(Collection $edges): Collection
    {
        return $edges->sortByDesc(fn (CausalEdge $edge): float => ((float) $edge->causality_score * 0.65) + abs((float) $edge->effect_size) * 0.35)
            ->values()
            ->take(10)
            ->map(function (CausalEdge $edge, int $index): CausalRootCause {
                $source = $edge->sourceNode?->label ?? 'driver';
                $target = $edge->targetNode?->label ?? 'outcome';
                $impact = $this->clamp((float) $edge->causality_score * 0.65 + abs((float) $edge->effect_size) * 0.35);

                return CausalRootCause::updateOrCreate(
                    ['cause_key' => 'root:'.$edge->edge_key],
                    [
                        'causal_edge_id' => $edge->id,
                        'title' => str($source)->replace('_', ' ')->title()->toString(),
                        'summary' => "{$source} is ranked as a root-cause candidate for {$target}.",
                        'impact_score' => round($impact, 2),
                        'confidence_score' => $edge->causality_score,
                        'rank' => $index + 1,
                        'status' => 'active',
                        'metadata' => [
                            'target' => $target,
                            'identification_status' => $edge->identification_status,
                        ],
                    ],
                );
            });
    }

    private function scoreDiscoveryQuality(Collection $edges): void
    {
        foreach ($edges as $edge) {
            if (! $edge->quant_law_id) {
                continue;
            }

            $quality = $this->clamp(((float) $edge->correlation_score * 0.35) + ((float) $edge->causality_score * 0.65));
            DiscoveryQualityScore::updateOrCreate(
                ['source_type' => QuantLaw::class, 'source_id' => $edge->quant_law_id],
                [
                    'title' => $edge->quantLaw?->title ?? $edge->edge_key,
                    'correlation_score' => $edge->correlation_score,
                    'causality_score' => $edge->causality_score,
                    'quality_score' => round($quality, 2),
                    'verdict' => $edge->causality_score >= 75 ? 'causal_candidate' : ($edge->causality_score >= 45 ? 'needs_experiment' : 'correlational'),
                    'metadata' => [
                        'causal_edge_id' => $edge->id,
                        'identification_status' => $edge->identification_status,
                    ],
                ],
            );
        }
    }

    private function node(string $key, string $type, float $confidence): CausalNode
    {
        return CausalNode::updateOrCreate(
            ['node_key' => 'causal_node:'.$key],
            [
                'label' => str($key)->replace('_', ' ')->toString(),
                'node_type' => $type,
                'description' => "Causal {$type} variable from Quant Laws.",
                'confidence_score' => round($this->clamp($confidence), 2),
                'metadata' => ['raw_key' => $key],
            ],
        );
    }

    private function causalityScore(QuantLaw $law): float
    {
        $base = ((float) $law->confidence_score * 0.28)
            + ((float) $law->universality_score * 0.32)
            + min(20, (int) $law->strategy_count * 5)
            + min(12, (int) $law->session_count * 2)
            + min(8, (int) $law->trade_count / 80);

        $penalty = $law->evidence_count < 3 ? 12 : 0;

        return $this->clamp($base - $penalty);
    }

    /** Explicitly distinguishes an invariant causal result from a correlation candidate. */
    private function invariantEvidence(QuantLaw $law): array
    {
        $metadata = $law->metadata ?? [];
        $checks = [
            'residual_invariance' => (bool) data_get($metadata, 'invariance.residual_invariance', false),
            'cross_market_stability' => (int) $law->species_count >= 3 && (bool) data_get($metadata, 'invariance.cross_market_stability', false),
            'placebo_intervention' => (bool) data_get($metadata, 'invariance.placebo_intervention', false),
            'negative_control' => (bool) data_get($metadata, 'invariance.negative_control', false),
            'parameter_intervention_replay' => (bool) data_get($metadata, 'invariance.parameter_intervention_replay', false),
            'effect_sign_consistency' => (bool) data_get($metadata, 'invariance.effect_sign_consistency', false),
            'unseen_period_validation' => (bool) data_get($metadata, 'invariance.unseen_period_validation', false),
        ];
        return ['protocol' => 'invariant_causal_evidence_v1',
            'status' => collect($checks)->every(fn ($pass) => $pass) ? 'invariantly_supported' : 'candidate_requires_invariance_tests',
            'checks' => $checks,
            'rule' => 'A causality score alone never upgrades an edge to causal proof.'];
    }

    private function signedEffect(CausalEdge $edge): float
    {
        $effect = (float) $edge->effect_size;

        return $edge->direction === 'negative' ? -abs($effect) : abs($effect);
    }

    private function clamp(float $value, float $min = 0, float $max = 100): float
    {
        return max($min, min($max, $value));
    }
}
