<?php

namespace App\Services;

use App\Models\CausalEdge;
use App\Models\CausalRootCause;
use App\Models\QuantLaw;
use App\Models\QuantTheory;
use App\Models\TheoryBattle;
use App\Models\TheoryComponent;
use App\Models\TheoryEvolutionEvent;
use App\Models\TheoryGenerationRun;
use App\Models\TheoryPrediction;
use App\Models\UnifiedQuantModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AutonomousTheoryGenerationService
{
    public function generate(): ?TheoryGenerationRun
    {
        if (! Schema::hasTable('theory_generation_runs')) {
            return null;
        }

        $run = TheoryGenerationRun::create([
            'status' => 'running',
            'started_at' => now(),
        ]);

        $theories = $this->buildTheories($run);
        $battles = $this->buildTheoryBattles($theories);
        $predictions = $this->buildTheoryPredictions($theories);
        $models = $this->buildUnifiedModels($theories);

        $run->update([
            'status' => 'success',
            'finished_at' => now(),
            'theories_generated' => $theories->count(),
            'battles_created' => $battles->count(),
            'predictions_created' => $predictions->count(),
            'unified_models_created' => $models->count(),
            'summary' => "Autonomous Theory Generation created {$theories->count()} theories, {$battles->count()} theory battles and {$models->count()} unified models.",
            'metrics' => [
                'theory_ids' => $theories->pluck('id')->values()->all(),
                'dominant_theories' => $theories->where('status', 'dominant')->pluck('theory_key')->values()->all(),
                'warning' => 'Theories are higher-order scientific models. They require future prediction validation before operational sizing changes.',
            ],
        ]);

        return $run;
    }

    private function buildTheories(TheoryGenerationRun $run): Collection
    {
        $evidence = $this->collectEvidence();

        return collect($this->theoryBlueprints())
            ->map(fn (array $blueprint): ?QuantTheory => $this->buildTheory($run, $blueprint, $evidence))
            ->filter()
            ->values();
    }

    private function collectEvidence(): Collection
    {
        $evidence = collect();

        if (Schema::hasTable('quant_laws')) {
            QuantLaw::query()
                ->whereIn('status', ['active', 'emerging'])
                ->orderByDesc('confidence_score')
                ->take(250)
                ->get()
                ->each(function (QuantLaw $law) use ($evidence): void {
                    $driver = $this->normalize((string) data_get($law->scope, 'driver', $law->law_type));
                    $target = $this->normalize((string) data_get($law->scope, 'target', $law->title));

                    $evidence->push([
                        'kind' => 'quant_law',
                        'source_type' => QuantLaw::class,
                        'source_id' => $law->id,
                        'driver' => $driver,
                        'target' => $target,
                        'score' => (float) $law->confidence_score,
                        'effect' => abs((float) $law->effect_size),
                        'evidence_count' => max(1, (int) $law->evidence_count),
                        'summary' => $law->title,
                        'metadata' => [
                            'law_key' => $law->law_key,
                            'universality_score' => $law->universality_score,
                            'strategy_count' => $law->strategy_count,
                            'session_count' => $law->session_count,
                            'trade_count' => $law->trade_count,
                        ],
                    ]);
                });
        }

        if (Schema::hasTable('causal_edges')) {
            CausalEdge::query()
                ->with(['sourceNode', 'targetNode'])
                ->orderByDesc('causality_score')
                ->take(250)
                ->get()
                ->each(function (CausalEdge $edge) use ($evidence): void {
                    $driver = $this->normalize($edge->sourceNode?->label ?? 'unknown_driver');
                    $target = $this->normalize($edge->targetNode?->label ?? 'unknown_target');

                    $evidence->push([
                        'kind' => 'causal_edge',
                        'source_type' => CausalEdge::class,
                        'source_id' => $edge->id,
                        'driver' => $driver,
                        'target' => $target,
                        'score' => (float) $edge->causality_score,
                        'effect' => abs((float) $edge->effect_size),
                        'evidence_count' => max(1, (int) $edge->evidence_count),
                        'summary' => "{$driver} -> {$target}",
                        'metadata' => [
                            'edge_key' => $edge->edge_key,
                            'identification_status' => $edge->identification_status,
                            'direction' => $edge->direction,
                        ],
                    ]);
                });
        }

        if (Schema::hasTable('causal_root_causes')) {
            CausalRootCause::query()
                ->orderBy('rank')
                ->take(100)
                ->get()
                ->each(function (CausalRootCause $cause) use ($evidence): void {
                    $driver = $this->normalize($cause->title);
                    $target = $this->normalize((string) data_get($cause->metadata, 'target', 'future_survival'));

                    $evidence->push([
                        'kind' => 'root_cause',
                        'source_type' => CausalRootCause::class,
                        'source_id' => $cause->id,
                        'driver' => $driver,
                        'target' => $target,
                        'score' => (float) $cause->confidence_score,
                        'effect' => (float) $cause->impact_score,
                        'evidence_count' => 1,
                        'summary' => $cause->summary,
                        'metadata' => [
                            'cause_key' => $cause->cause_key,
                            'rank' => $cause->rank,
                            'impact_score' => $cause->impact_score,
                        ],
                    ]);
                });
        }

        return $evidence;
    }

    private function buildTheory(TheoryGenerationRun $run, array $blueprint, Collection $evidence): ?QuantTheory
    {
        $matches = $this->matchingEvidence($evidence, $blueprint);

        if ($matches->count() < (int) $blueprint['minimum_evidence']) {
            return null;
        }

        $previous = QuantTheory::query()->where('theory_key', $blueprint['key'])->first();
        $confidence = $this->confidenceScore($matches);
        $explanatory = $this->explanatoryPowerScore($matches);
        $predictive = $this->predictivePowerScore($matches);
        $status = $this->theoryStatus($confidence, $explanatory, $predictive);
        $lawCount = $matches->where('kind', 'quant_law')->count();
        $edgeCount = $matches->where('kind', 'causal_edge')->count();
        $rootCount = $matches->where('kind', 'root_cause')->count();

        $theory = QuantTheory::updateOrCreate(
            ['theory_key' => $blueprint['key']],
            [
                'theory_generation_run_id' => $run->id,
                'title' => $blueprint['title'],
                'thesis' => $blueprint['thesis'],
                'theory_type' => $blueprint['type'],
                'status' => $status,
                'confidence_score' => round($confidence, 2),
                'explanatory_power_score' => round($explanatory, 2),
                'predictive_power_score' => round($predictive, 2),
                'evidence_count' => (int) $matches->sum('evidence_count'),
                'law_count' => $lawCount,
                'causal_edge_count' => $edgeCount,
                'root_cause_count' => $rootCount,
                'scope' => [
                    'drivers' => $blueprint['drivers'],
                    'targets' => $blueprint['targets'],
                    'level' => 'pattern_to_law_to_theory',
                ],
                'metadata' => [
                    'evidence_mix' => [
                        'laws' => $lawCount,
                        'causal_edges' => $edgeCount,
                        'root_causes' => $rootCount,
                    ],
                    'generated_from' => 'canonical_25_autonomous_theory_generation_engine',
                ],
            ],
        );

        $this->syncComponents($theory, $matches);
        $this->writeEvolutionEvent($theory, $previous, $status, $confidence, $matches);

        return $theory;
    }

    private function matchingEvidence(Collection $evidence, array $blueprint): Collection
    {
        return $evidence
            ->filter(function (array $item) use ($blueprint): bool {
                $haystack = implode(' ', [
                    $item['driver'],
                    $item['target'],
                    $item['summary'],
                    json_encode($item['metadata']),
                ]);

                foreach ($blueprint['keywords'] as $keyword) {
                    if (Str::contains($haystack, $this->normalize($keyword))) {
                        return true;
                    }
                }

                return false;
            })
            ->sortByDesc(fn (array $item): float => ((float) $item['score'] * 0.7) + ((float) $item['effect'] * 0.3))
            ->values();
    }

    private function syncComponents(QuantTheory $theory, Collection $matches): void
    {
        TheoryComponent::query()->where('quant_theory_id', $theory->id)->delete();

        $matches->take(24)->each(function (array $item) use ($theory): void {
            TheoryComponent::create([
                'quant_theory_id' => $theory->id,
                'component_type' => $item['kind'],
                'source_type' => $item['source_type'],
                'source_id' => $item['source_id'],
                'contribution_score' => round($this->clamp(((float) $item['score'] * 0.65) + ((float) $item['effect'] * 0.35)), 2),
                'polarity' => 'supporting',
                'summary' => $item['summary'],
                'metadata' => $item['metadata'],
            ]);
        });
    }

    private function writeEvolutionEvent(QuantTheory $theory, ?QuantTheory $previous, string $status, float $confidence, Collection $matches): void
    {
        TheoryEvolutionEvent::create([
            'quant_theory_id' => $theory->id,
            'event_type' => $previous ? 'revalidated' : 'generated',
            'previous_status' => $previous?->status,
            'new_status' => $status,
            'previous_confidence' => $previous?->confidence_score,
            'new_confidence' => round($confidence, 2),
            'reason' => $previous
                ? 'Theory re-scored from latest laws, causal edges and root causes.'
                : 'Theory generated by combining repeated law and causal evidence into a higher-order explanation.',
            'evidence' => [
                'component_count' => $matches->count(),
                'top_components' => $matches->take(5)->pluck('summary')->values()->all(),
            ],
        ]);
    }

    private function buildTheoryBattles(Collection $theories): Collection
    {
        $ranked = $theories
            ->sortByDesc(fn (QuantTheory $theory): float => ((float) $theory->confidence_score * 0.45) + ((float) $theory->explanatory_power_score * 0.35) + ((float) $theory->predictive_power_score * 0.2))
            ->values();

        if ($ranked->count() < 2) {
            return collect();
        }

        $created = collect();

        for ($index = 0; $index < min(3, $ranked->count() - 1); $index++) {
            $theoryA = $ranked[$index];
            $theoryB = $ranked[$index + 1];
            $scoreA = $this->battleScore($theoryA);
            $scoreB = $this->battleScore($theoryB);
            $winner = $scoreA >= $scoreB ? $theoryA : $theoryB;
            $gap = abs($scoreA - $scoreB);

            $created->push(TheoryBattle::updateOrCreate(
                ['battle_key' => 'theory-battle:'.$theoryA->theory_key.':'.$theoryB->theory_key],
                [
                    'theory_a_id' => $theoryA->id,
                    'theory_b_id' => $theoryB->id,
                    'status' => $gap >= 12 ? 'decided' : 'contested',
                    'winner_theory_id' => $gap >= 6 ? $winner->id : null,
                    'confidence_gap' => round($gap, 2),
                    'summary' => "{$theoryA->title} competes with {$theoryB->title}; current evidence favors {$winner->title}.",
                    'evidence' => [
                        'theory_a_score' => round($scoreA, 2),
                        'theory_b_score' => round($scoreB, 2),
                        'decision_rule' => 'confidence + explanatory power + predictive power',
                    ],
                ],
            ));
        }

        return $created;
    }

    private function buildTheoryPredictions(Collection $theories): Collection
    {
        return $theories->map(function (QuantTheory $theory): TheoryPrediction {
            $driver = (string) data_get($theory->scope, 'drivers.0', 'adaptability');
            $target = (string) data_get($theory->scope, 'targets.0', 'future_survival');
            $delta = $this->clamp((((float) $theory->predictive_power_score - 45) * 0.22) + ((float) $theory->confidence_score * 0.06), -25, 35);

            return TheoryPrediction::updateOrCreate(
                ['prediction_key' => 'theory-prediction:'.$theory->theory_key.':'.$target],
                [
                    'quant_theory_id' => $theory->id,
                    'target_metric' => $target,
                    'baseline_value' => 50,
                    'intervention_value' => 65,
                    'predicted_delta' => round($delta, 3),
                    'confidence_score' => $theory->confidence_score,
                    'horizon' => 'next_3_research_cycles',
                    'status' => 'untested',
                    'rationale' => "If {$driver} improves by 15 points, {$target} is expected to improve by ".round($delta, 2)." points under {$theory->title}.",
                    'metadata' => [
                        'theory_status' => $theory->status,
                        'explanatory_power_score' => $theory->explanatory_power_score,
                        'predictive_power_score' => $theory->predictive_power_score,
                    ],
                ],
            );
        });
    }

    private function buildUnifiedModels(Collection $theories): Collection
    {
        $strong = $theories
            ->filter(fn (QuantTheory $theory): bool => (float) $theory->confidence_score >= 60)
            ->sortByDesc('confidence_score')
            ->values();

        if ($strong->count() < 2) {
            return collect();
        }

        $confidence = $strong->avg('confidence_score');
        $model = UnifiedQuantModel::updateOrCreate(
            ['model_key' => 'unified:adaptive_resilience_market_survival'],
            [
                'title' => 'Adaptive Resilience Market Survival Model',
                'thesis' => 'Long-term quant survival is best explained by adaptability, recovery speed and regime awareness acting together.',
                'status' => $confidence >= 80 ? 'accepted' : 'emerging',
                'confidence_score' => round($confidence, 2),
                'theory_count' => $strong->count(),
                'law_count' => (int) $strong->sum('law_count'),
                'root_cause_count' => (int) $strong->sum('root_cause_count'),
                'components' => $strong->map(fn (QuantTheory $theory): array => [
                    'theory_key' => $theory->theory_key,
                    'title' => $theory->title,
                    'confidence_score' => $theory->confidence_score,
                ])->values()->all(),
                'metadata' => [
                    'source' => 'canonical_25_autonomous_theory_generation_engine',
                    'requires_prediction_validation' => true,
                ],
            ],
        );

        return collect([$model]);
    }

    private function theoryBlueprints(): array
    {
        return [
            [
                'key' => 'theory:adaptive_dominance',
                'title' => 'Adaptive Dominance Theory',
                'thesis' => 'Long-term strategy survival is primarily driven by adaptability, not by one fixed signal family.',
                'type' => 'survival_theory',
                'drivers' => ['adaptability', 'trend_dependency'],
                'targets' => ['future_survival'],
                'keywords' => ['adaptability', 'trend dependency', 'trend_dependency', 'adaptive'],
                'minimum_evidence' => 1,
            ],
            [
                'key' => 'theory:recovery_resilience',
                'title' => 'Recovery Resilience Theory',
                'thesis' => 'Strategies that recover faster after adverse regimes preserve more future optionality than strategies with only high peak performance.',
                'type' => 'resilience_theory',
                'drivers' => ['recovery_speed', 'drawdown_recovery'],
                'targets' => ['future_survival'],
                'keywords' => ['recovery', 'drawdown', 'resilience'],
                'minimum_evidence' => 1,
            ],
            [
                'key' => 'theory:regime_awareness_compounding',
                'title' => 'Regime Awareness Compounding Theory',
                'thesis' => 'Regime-aware agents compound knowledge better because they avoid applying universal laws outside their valid market species.',
                'type' => 'market_context_theory',
                'drivers' => ['regime_awareness', 'market_species'],
                'targets' => ['knowledge_transfer', 'future_survival'],
                'keywords' => ['regime', 'market species', 'species', 'volatility', 'liquidity'],
                'minimum_evidence' => 1,
            ],
        ];
    }

    private function confidenceScore(Collection $matches): float
    {
        $weightedScore = $matches->sum(fn (array $item): float => (float) $item['score'] * max(1, (int) $item['evidence_count']));
        $weight = max(1, $matches->sum('evidence_count'));
        $base = $weightedScore / $weight;
        $diversityBonus = $matches->pluck('kind')->unique()->count() * 4;
        $evidenceBonus = min(12, log($weight + 1, 2) * 2.4);

        return $this->clamp($base + $diversityBonus + $evidenceBonus);
    }

    private function explanatoryPowerScore(Collection $matches): float
    {
        $componentBreadth = min(35, $matches->count() * 7);
        $averageEffect = $matches->avg('effect') ?: 0;
        $sourceDiversity = $matches->pluck('kind')->unique()->count() * 10;

        return $this->clamp(35 + $componentBreadth + ($averageEffect * 0.25) + $sourceDiversity);
    }

    private function predictivePowerScore(Collection $matches): float
    {
        $causalEvidence = $matches->where('kind', 'causal_edge');
        $rootCauseEvidence = $matches->where('kind', 'root_cause');
        $base = $matches->avg('score') ?: 50;
        $causalBonus = $causalEvidence->count() ? 12 : 0;
        $rootBonus = $rootCauseEvidence->count() ? 8 : 0;

        return $this->clamp(($base * 0.7) + 18 + $causalBonus + $rootBonus);
    }

    private function theoryStatus(float $confidence, float $explanatory, float $predictive): string
    {
        if ($confidence >= 85 && $explanatory >= 75 && $predictive >= 75) {
            return 'dominant';
        }

        if ($confidence >= 70 && $explanatory >= 65) {
            return 'accepted';
        }

        return 'emerging';
    }

    private function battleScore(QuantTheory $theory): float
    {
        return ((float) $theory->confidence_score * 0.45)
            + ((float) $theory->explanatory_power_score * 0.35)
            + ((float) $theory->predictive_power_score * 0.2);
    }

    private function normalize(string $value): string
    {
        return Str::of($value)->replace('_', ' ')->lower()->squish()->toString();
    }

    private function clamp(float $value, float $min = 0, float $max = 100): float
    {
        return max($min, min($max, $value));
    }
}
