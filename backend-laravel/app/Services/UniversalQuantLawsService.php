<?php

namespace App\Services;

use App\Models\InstitutionalKnowledge;
use App\Models\KnowledgeClaim;
use App\Models\QuantLaw;
use App\Models\QuantLawCandidate;
use App\Models\QuantLawConflict;
use App\Models\QuantLawDiscoveryRun;
use App\Models\QuantLawEvidence;
use App\Models\QuantLawEvolutionEvent;
use App\Models\QuantLawGraphEdge;
use App\Models\StrategyDnaProfile;
use App\Models\StrategyScore;
use App\Models\UniversalDriverRanking;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class UniversalQuantLawsService
{
    public function discover(): ?QuantLawDiscoveryRun
    {
        if (! Schema::hasTable('quant_law_discovery_runs')) {
            return null;
        }

        $run = QuantLawDiscoveryRun::create([
            'status' => 'running',
            'started_at' => now(),
        ]);

        $candidates = $this->generateCandidates($run);
        $laws = $this->promoteCandidates($candidates);
        $this->recordLawGraph($laws);
        $conflicts = $this->detectConflicts($laws);
        $drivers = $this->rankUniversalDrivers($run);

        $run->update([
            'status' => 'success',
            'finished_at' => now(),
            'candidates_created' => $candidates->count(),
            'laws_promoted' => $laws->count(),
            'conflicts_found' => $conflicts->count(),
            'summary' => "Quant Laws discovered {$candidates->count()} candidates, promoted {$laws->count()} laws and ranked {$drivers->count()} universal drivers.",
            'metrics' => [
                'law_ids' => $laws->pluck('id')->values()->all(),
                'driver_keys' => $drivers->pluck('driver_key')->values()->all(),
                'non_causal_warning' => 'Quant laws are provisional invariants, not causal proof.',
            ],
        ]);

        return $run->fresh(['candidates', 'driverRankings']);
    }

    private function generateCandidates(QuantLawDiscoveryRun $run): Collection
    {
        return collect([
            $this->candidateFromTrendDependency($run),
            $this->candidateFromLowVolatilityBreakouts($run),
            $this->candidateFromConfirmationTradeoff($run),
        ])
            ->filter()
            ->merge($this->candidatesFromKnowledgeClaims($run))
            ->values();
    }

    private function candidateFromTrendDependency(QuantLawDiscoveryRun $run): ?QuantLawCandidate
    {
        if (! Schema::hasTable('strategy_dna_profiles')) {
            return null;
        }

        $profiles = StrategyDnaProfile::query()
            ->with('strategyScore.trainingSession')
            ->where('trend_dependency', '>=', 70)
            ->get();

        if ($profiles->isEmpty()) {
            return null;
        }

        $avgTrend = (float) $profiles->avg('trend_dependency');
        $avgAdaptability = (float) $profiles->avg('adaptability_score');
        $avgSurvival = (float) $profiles->avg('survival_score');
        $effect = $this->clamp($avgTrend - (($avgAdaptability + $avgSurvival) / 2), -100, 100);
        $strategies = $profiles->pluck('strategyScore.strategy')->filter()->unique();
        $sessions = $profiles->pluck('strategyScore.training_session_id')->filter()->unique();
        $tradeCount = (int) $profiles->sum(fn (StrategyDnaProfile $profile): int => (int) ($profile->strategyScore?->total_trades ?? 0));
        $confidence = $this->clamp(52 + ($profiles->count() * 5) + max(0, $effect) * 0.42);
        $universality = $this->universality($strategies->count(), 0, $sessions->count(), $tradeCount);

        $candidate = $this->upsertCandidate($run, [
            'candidate_key' => 'law:trend_dependency:adaptability_decay',
            'title' => 'High trend dependency reduces long-term adaptability',
            'observation' => 'Strategies with high trend dependency tend to show weaker adaptability and survival scores.',
            'law_type' => 'adaptability_law',
            'confidence_score' => $confidence,
            'universality_score' => $universality,
            'effect_size' => round($effect, 3),
            'evidence_count' => $profiles->count(),
            'strategy_count' => $strategies->count(),
            'species_count' => 0,
            'session_count' => $sessions->count(),
            'trade_count' => $tradeCount,
            'scope' => [
                'driver' => 'trend_dependency',
                'target' => 'adaptability',
                'direction' => 'negative',
            ],
            'metadata' => [
                'avg_trend_dependency' => round($avgTrend, 2),
                'avg_adaptability' => round($avgAdaptability, 2),
                'avg_survival' => round($avgSurvival, 2),
            ],
        ]);

        $profiles->take(30)->each(function (StrategyDnaProfile $profile) use ($candidate): void {
            $score = $profile->strategyScore;
            $this->evidence($candidate, null, [
                'source_type' => StrategyDnaProfile::class,
                'source_id' => $profile->id,
                'strategy' => $score?->strategy,
                'evidence_type' => 'strategy_dna',
                'effect_direction' => 'negative',
                'effect_size' => round((float) $profile->trend_dependency - (float) $profile->adaptability_score, 3),
                'confidence_score' => $this->clamp((float) $profile->trend_dependency),
                'sample_size' => max(1, (int) ($score?->total_trades ?? 1)),
                'summary' => "Trend dependency {$profile->trend_dependency}% vs adaptability {$profile->adaptability_score}% for {$score?->strategy}.",
                'metadata' => [
                    'survival_score' => $profile->survival_score,
                    'training_session_id' => $score?->training_session_id,
                ],
            ]);
        });

        return $candidate;
    }

    private function candidateFromLowVolatilityBreakouts(QuantLawDiscoveryRun $run): ?QuantLawCandidate
    {
        if (! Schema::hasTable('strategy_scores')) {
            return null;
        }

        $scores = StrategyScore::query()
            ->where('strategy', 'like', '%breakout%')
            ->get()
            ->filter(fn (StrategyScore $score): bool => $this->lowVolatilityProfit($score) !== null);

        if ($scores->isEmpty()) {
            return null;
        }

        $avgLowVolProfit = (float) $scores->avg(fn (StrategyScore $score): float => (float) $this->lowVolatilityProfit($score));
        $avgWinrate = (float) $scores->avg('winrate');
        $effect = $this->clamp(abs(min(0, $avgLowVolProfit)) * 18 + max(0, 55 - $avgWinrate) * 0.5);
        $strategies = $scores->pluck('strategy')->unique();
        $sessions = $scores->pluck('training_session_id')->filter()->unique();
        $tradeCount = (int) $scores->sum('total_trades');
        $confidence = $this->clamp(50 + ($scores->count() * 6) + $effect * 0.6);
        $universality = $this->universality($strategies->count(), 0, $sessions->count(), $tradeCount);

        $candidate = $this->upsertCandidate($run, [
            'candidate_key' => 'law:low_volatility:breakout_failure',
            'title' => 'Low volatility increases breakout failure',
            'observation' => 'Breakout strategies lose follow-through when low volatility suppresses expansion.',
            'law_type' => 'market_structure_law',
            'confidence_score' => $confidence,
            'universality_score' => $universality,
            'effect_size' => round($effect, 3),
            'evidence_count' => $scores->count(),
            'strategy_count' => $strategies->count(),
            'species_count' => 0,
            'session_count' => $sessions->count(),
            'trade_count' => $tradeCount,
            'scope' => [
                'driver' => 'low_volatility',
                'target' => 'breakout_failure',
                'direction' => 'positive',
            ],
            'metadata' => [
                'avg_low_volatility_profit' => round($avgLowVolProfit, 2),
                'avg_winrate' => round($avgWinrate, 2),
            ],
        ]);

        $scores->take(30)->each(function (StrategyScore $score) use ($candidate): void {
            $this->evidence($candidate, null, [
                'source_type' => StrategyScore::class,
                'source_id' => $score->id,
                'strategy' => $score->strategy,
                'evidence_type' => 'low_volatility_breakout',
                'effect_direction' => 'positive',
                'effect_size' => round(abs((float) $this->lowVolatilityProfit($score)), 3),
                'confidence_score' => $this->clamp(60 + abs((float) $this->lowVolatilityProfit($score)) * 6),
                'sample_size' => max(1, (int) $score->total_trades),
                'summary' => "{$score->strategy} low-volatility profit {$this->lowVolatilityProfit($score)}%.",
                'metadata' => [
                    'winrate' => $score->winrate,
                    'profit_factor' => $score->profit_factor,
                ],
            ]);
        });

        return $candidate;
    }

    private function candidateFromConfirmationTradeoff(QuantLawDiscoveryRun $run): ?QuantLawCandidate
    {
        if (! Schema::hasTable('strategy_scores')) {
            return null;
        }

        $scores = StrategyScore::query()
            ->with('dnaProfile')
            ->whereNotNull('parameters')
            ->get()
            ->filter(fn (StrategyScore $score): bool => count($score->parameters ?? []) >= 3 && $score->dnaProfile !== null);

        if ($scores->isEmpty()) {
            return null;
        }

        $avgParameters = (float) $scores->avg(fn (StrategyScore $score): int => count($score->parameters ?? []));
        $avgAdaptability = (float) $scores->avg(fn (StrategyScore $score): float => (float) $score->dnaProfile?->adaptability_score);
        $avgWinrate = (float) $scores->avg('winrate');
        $effect = $this->clamp(($avgParameters * 7) + max(0, 70 - $avgAdaptability) - max(0, $avgWinrate - 55) * 0.2);
        $strategies = $scores->pluck('strategy')->unique();
        $sessions = $scores->pluck('training_session_id')->filter()->unique();
        $tradeCount = (int) $scores->sum('total_trades');
        $confidence = $this->clamp(48 + ($scores->count() * 4) + $effect * 0.45);
        $universality = $this->universality($strategies->count(), 0, $sessions->count(), $tradeCount);

        $candidate = $this->upsertCandidate($run, [
            'candidate_key' => 'law:confirmation:adaptability_tradeoff',
            'title' => 'Increasing confirmation lowers adaptability',
            'observation' => 'More confirmation rules can reduce false entries but often lowers adaptation speed across regimes.',
            'law_type' => 'tradeoff_law',
            'confidence_score' => $confidence,
            'universality_score' => $universality,
            'effect_size' => round($effect, 3),
            'evidence_count' => $scores->count(),
            'strategy_count' => $strategies->count(),
            'species_count' => 0,
            'session_count' => $sessions->count(),
            'trade_count' => $tradeCount,
            'scope' => [
                'driver' => 'confirmation_density',
                'target' => 'adaptability',
                'direction' => 'negative',
            ],
            'metadata' => [
                'avg_parameter_count' => round($avgParameters, 2),
                'avg_adaptability' => round($avgAdaptability, 2),
                'avg_winrate' => round($avgWinrate, 2),
            ],
        ]);

        $scores->take(30)->each(function (StrategyScore $score) use ($candidate): void {
            $this->evidence($candidate, null, [
                'source_type' => StrategyScore::class,
                'source_id' => $score->id,
                'strategy' => $score->strategy,
                'evidence_type' => 'confirmation_tradeoff',
                'effect_direction' => 'negative',
                'effect_size' => count($score->parameters ?? []),
                'confidence_score' => $this->clamp(45 + count($score->parameters ?? []) * 8),
                'sample_size' => max(1, (int) $score->total_trades),
                'summary' => "{$score->strategy} uses ".count($score->parameters ?? [])." confirmation parameters with adaptability {$score->dnaProfile?->adaptability_score}%.",
                'metadata' => [
                    'parameters' => array_keys($score->parameters ?? []),
                    'adaptability_score' => $score->dnaProfile?->adaptability_score,
                ],
            ]);
        });

        return $candidate;
    }

    private function candidatesFromKnowledgeClaims(QuantLawDiscoveryRun $run): Collection
    {
        if (! Schema::hasTable('knowledge_claims')) {
            return collect();
        }

        return KnowledgeClaim::query()
            ->where('confidence_score', '>=', 70)
            ->where(function ($query): void {
                $query->where('claim', 'like', '%trend dependency%')
                    ->orWhere('title', 'like', '%trend dependency%')
                    ->orWhere('claim', 'like', '%adaptability%')
                    ->orWhere('title', 'like', '%adaptability%');
            })
            ->take(20)
            ->get()
            ->map(function (KnowledgeClaim $claim) use ($run): QuantLawCandidate {
                $direction = $this->textDirection($claim->title.' '.$claim->claim);
                $confidence = (float) $claim->confidence_score;
                $evidenceCount = max(1, (int) $claim->evidence_count);
                $scope = array_replace($claim->scope ?? [], [
                    'driver' => 'trend_dependency',
                    'target' => 'adaptability',
                    'direction' => $direction,
                    'source_claim_id' => $claim->id,
                ]);

                $candidate = $this->upsertCandidate($run, [
                    'candidate_key' => 'claim-law:'.$claim->id,
                    'title' => 'Claim-derived law: '.$claim->title,
                    'observation' => $claim->claim,
                    'law_type' => 'claim_derived_law',
                    'confidence_score' => $confidence,
                    'universality_score' => $this->clamp(30 + min(35, $evidenceCount * 2)),
                    'effect_size' => $direction === 'positive' ? 18 : -18,
                    'evidence_count' => $evidenceCount,
                    'strategy_count' => isset(($claim->scope ?? [])['strategy']) ? 1 : 0,
                    'species_count' => isset(($claim->scope ?? [])['market_species']) ? 1 : 0,
                    'session_count' => 0,
                    'trade_count' => $evidenceCount,
                    'scope' => $scope,
                    'metadata' => [
                        'source' => 'knowledge_claim',
                        'claim_status' => $claim->status,
                    ],
                ]);

                $this->evidence($candidate, null, [
                    'source_type' => KnowledgeClaim::class,
                    'source_id' => $claim->id,
                    'strategy' => data_get($claim->scope, 'strategy'),
                    'market_species' => data_get($claim->scope, 'market_species'),
                    'evidence_type' => 'knowledge_claim',
                    'effect_direction' => $direction,
                    'effect_size' => $direction === 'positive' ? 18 : -18,
                    'confidence_score' => $confidence,
                    'sample_size' => $evidenceCount,
                    'summary' => $claim->claim,
                    'metadata' => [
                        'title' => $claim->title,
                        'scope' => $claim->scope,
                    ],
                ]);

                return $candidate;
            });
    }

    private function promoteCandidates(Collection $candidates): Collection
    {
        return $candidates
            ->filter(fn (QuantLawCandidate $candidate): bool => (float) $candidate->confidence_score >= 68 || (float) $candidate->universality_score >= 45)
            ->map(function (QuantLawCandidate $candidate): QuantLaw {
                $status = (float) $candidate->confidence_score >= 85 && (float) $candidate->universality_score >= 55 ? 'active' : 'emerging';
                $previous = QuantLaw::query()->where('law_key', $candidate->candidate_key)->first();
                $previousConfidence = $previous ? (float) $previous->confidence_score : null;

                $law = QuantLaw::updateOrCreate(
                    ['law_key' => $candidate->candidate_key],
                    [
                        'quant_law_candidate_id' => $candidate->id,
                        'title' => $candidate->title,
                        'statement' => $this->statementFor($candidate),
                        'law_type' => $candidate->law_type,
                        'status' => $status,
                        'confidence_score' => $candidate->confidence_score,
                        'universality_score' => $candidate->universality_score,
                        'effect_size' => $candidate->effect_size,
                        'evidence_count' => $candidate->evidence_count,
                        'strategy_count' => $candidate->strategy_count,
                        'species_count' => $candidate->species_count,
                        'session_count' => $candidate->session_count,
                        'trade_count' => $candidate->trade_count,
                        'first_seen_at' => $previous?->first_seen_at ?? now(),
                        'last_validated_at' => now(),
                        'scope' => $candidate->scope,
                        'metadata' => $candidate->metadata,
                    ],
                );

                QuantLawEvidence::query()
                    ->where('quant_law_candidate_id', $candidate->id)
                    ->update(['quant_law_id' => $law->id]);

                QuantLawEvolutionEvent::create([
                    'quant_law_id' => $law->id,
                    'event_type' => $previous ? 'revalidated' : 'promoted',
                    'previous_confidence' => $previousConfidence,
                    'new_confidence' => (float) $law->confidence_score,
                    'delta' => round((float) $law->confidence_score - (float) ($previousConfidence ?? 0), 3),
                    'reason' => 'Candidate passed confidence/universality threshold.',
                    'evidence' => [
                        'candidate_id' => $candidate->id,
                        'universality_score' => $candidate->universality_score,
                        'evidence_count' => $candidate->evidence_count,
                    ],
                ]);

                $candidate->update(['status' => $status === 'active' ? 'promoted' : 'emerging']);

                return $law;
            })
            ->values();
    }

    private function recordLawGraph(Collection $laws): void
    {
        foreach ($laws as $law) {
            $driver = data_get($law->scope, 'driver', 'unknown_driver');
            $target = data_get($law->scope, 'target', 'unknown_target');
            $direction = data_get($law->scope, 'direction', 'negative');

            QuantLawGraphEdge::updateOrCreate(
                [
                    'quant_law_id' => $law->id,
                    'source_label' => (string) $driver,
                    'target_label' => (string) $target,
                ],
                [
                    'relation_type' => $direction === 'positive' ? 'increases' : 'reduces',
                    'polarity' => $direction,
                    'confidence_score' => (float) $law->confidence_score,
                    'evidence_count' => (int) $law->evidence_count,
                    'metadata' => [
                        'law_key' => $law->law_key,
                        'effect_size' => $law->effect_size,
                        'universality_score' => $law->universality_score,
                    ],
                ],
            );
        }
    }

    private function detectConflicts(Collection $newLaws): Collection
    {
        $laws = QuantLaw::query()->whereIn('status', ['active', 'emerging'])->get();
        $created = collect();

        $laws->groupBy(fn (QuantLaw $law): string => data_get($law->scope, 'driver', '*').'|'.data_get($law->scope, 'target', '*'))
            ->each(function (Collection $group) use ($created): void {
                $items = $group->values();

                for ($i = 0; $i < $items->count(); $i++) {
                    for ($j = $i + 1; $j < $items->count(); $j++) {
                        $lawA = $items[$i];
                        $lawB = $items[$j];
                        $directionA = data_get($lawA->scope, 'direction');
                        $directionB = data_get($lawB->scope, 'direction');

                        if (! $directionA || ! $directionB || $directionA === $directionB) {
                            continue;
                        }

                        $created->push(QuantLawConflict::updateOrCreate(
                            [
                                'law_a_id' => min($lawA->id, $lawB->id),
                                'law_b_id' => max($lawA->id, $lawB->id),
                            ],
                            [
                                'conflict_type' => 'opposite_direction',
                                'severity_score' => round($this->clamp(((float) $lawA->confidence_score + (float) $lawB->confidence_score) / 2), 2),
                                'status' => 'open',
                                'summary' => "Law conflict: '{$lawA->title}' contradicts '{$lawB->title}'.",
                                'evidence' => [
                                    'driver' => data_get($lawA->scope, 'driver'),
                                    'target' => data_get($lawA->scope, 'target'),
                                    'law_a_direction' => $directionA,
                                    'law_b_direction' => $directionB,
                                ],
                            ],
                        ));
                    }
                }
            });

        return $created->unique('id')->values();
    }

    private function rankUniversalDrivers(QuantLawDiscoveryRun $run): Collection
    {
        $rankings = QuantLaw::query()
            ->whereIn('status', ['active', 'emerging'])
            ->get()
            ->groupBy(fn (QuantLaw $law): string => (string) data_get($law->scope, 'driver', 'unknown_driver'))
            ->map(function (Collection $laws, string $driver): array {
                return [
                    'driver_key' => $driver,
                    'driver_label' => str($driver)->replace('_', ' ')->title()->toString(),
                    'impact_score' => $this->clamp((float) $laws->avg('effect_size') + (float) $laws->avg('universality_score') * 0.45 + $laws->count() * 5),
                    'confidence_score' => $this->clamp((float) $laws->avg('confidence_score')),
                    'evidence_count' => (int) $laws->sum('evidence_count'),
                    'law_ids' => $laws->pluck('id')->values()->all(),
                ];
            })
            ->sortByDesc('impact_score')
            ->values()
            ->map(function (array $driver, int $index) use ($run): UniversalDriverRanking {
                return UniversalDriverRanking::updateOrCreate(
                    [
                        'quant_law_discovery_run_id' => $run->id,
                        'driver_key' => $driver['driver_key'],
                    ],
                    [
                        'driver_label' => $driver['driver_label'],
                        'rank' => $index + 1,
                        'impact_score' => round($driver['impact_score'], 2),
                        'confidence_score' => round($driver['confidence_score'], 2),
                        'evidence_count' => $driver['evidence_count'],
                        'metadata' => [
                            'law_ids' => $driver['law_ids'],
                            'note' => 'Universal Driver Analysis, not a guaranteed holy grail.',
                        ],
                    ],
                );
            });

        return $rankings;
    }

    private function upsertCandidate(QuantLawDiscoveryRun $run, array $data): QuantLawCandidate
    {
        $status = (float) $data['confidence_score'] >= 85 && (float) $data['universality_score'] >= 55 ? 'ready_for_law' : 'emerging';

        return QuantLawCandidate::updateOrCreate(
            ['candidate_key' => $data['candidate_key']],
            array_replace($data, [
                'quant_law_discovery_run_id' => $run->id,
                'status' => $status,
                'confidence_score' => round($this->clamp((float) $data['confidence_score']), 2),
                'universality_score' => round($this->clamp((float) $data['universality_score']), 2),
                'last_seen_at' => now(),
            ]),
        );
    }

    private function evidence(?QuantLawCandidate $candidate, ?QuantLaw $law, array $data): QuantLawEvidence
    {
        return QuantLawEvidence::updateOrCreate(
            [
                'quant_law_candidate_id' => $candidate?->id,
                'quant_law_id' => $law?->id,
                'source_type' => $data['source_type'] ?? null,
                'source_id' => $data['source_id'] ?? null,
                'evidence_type' => $data['evidence_type'],
            ],
            $data + [
                'quant_law_candidate_id' => $candidate?->id,
                'quant_law_id' => $law?->id,
            ],
        );
    }

    private function lowVolatilityProfit(StrategyScore $score): ?float
    {
        $performance = $score->volatility_performance ?? [];

        return data_get($performance, 'low_volatility.net_profit_percent')
            ?? data_get($performance, 'low.net_profit_percent')
            ?? data_get($performance, 'low_volatility.profit')
            ?? null;
    }

    private function universality(int $strategyCount, int $speciesCount, int $sessionCount, int $tradeCount): float
    {
        return $this->clamp(
            min(32, $strategyCount * 10)
            + min(24, $speciesCount * 5)
            + min(24, $sessionCount * 3)
            + min(20, $tradeCount / 50)
        );
    }

    private function statementFor(QuantLawCandidate $candidate): string
    {
        $driver = str((string) data_get($candidate->scope, 'driver', 'unknown driver'))->replace('_', ' ')->toString();
        $target = str((string) data_get($candidate->scope, 'target', 'unknown target'))->replace('_', ' ')->toString();
        $direction = data_get($candidate->scope, 'direction') === 'positive' ? 'increases' : 'reduces';

        return "{$driver} {$direction} {$target}; confidence {$candidate->confidence_score}%, universality {$candidate->universality_score}%.";
    }

    private function textDirection(string $text): string
    {
        $normalized = strtolower($text);
        $positive = ['improves', 'increase', 'increases', 'raises', 'strengthens', 'boosts', 'better'];
        $negative = ['reduces', 'lowers', 'decreases', 'loses', 'weakens', 'failure', 'collapses', 'worse'];

        foreach ($positive as $needle) {
            if (str_contains($normalized, $needle)) {
                return 'positive';
            }
        }

        foreach ($negative as $needle) {
            if (str_contains($normalized, $needle)) {
                return 'negative';
            }
        }

        return 'negative';
    }

    private function clamp(float $value, float $min = 0, float $max = 100): float
    {
        return max($min, min($max, $value));
    }
}
