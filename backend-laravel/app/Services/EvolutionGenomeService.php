<?php

namespace App\Services;

use App\Models\EvolutionGeneration;
use App\Models\EvolutionProposal;
use App\Models\ExtinctionEvent;
use App\Models\FitnessEvaluation;
use App\Models\GenomeCrossover;
use App\Models\GenomeDiscovery;
use App\Models\GenomeLineage;
use App\Models\GenomeMutation;
use App\Models\KnowledgeFact;
use App\Models\ModelVersion;
use App\Models\SelectionEvent;
use App\Models\StrategyGenome;
use App\Models\StrategyScore;
use App\Models\TrainingSession;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class EvolutionGenomeService
{
    public function recordTrainingSession(TrainingSession $session): void
    {
        if (! Schema::hasTable('strategy_genomes')) {
            return;
        }

        $session->loadMissing(['strategyScores.dnaProfile']);
        $genomes = collect();

        foreach ($session->strategyScores as $score) {
            $genome = $this->recordGenomeFromScore($score);
            $this->recordFitness($genome, $score);
            $this->refreshGeneration($genome->family, (int) $genome->generation);
            $genomes->push($genome);
        }

        $this->runSelection($session);
        $this->proposeCrossovers($session, $genomes);
        $this->extractGenomeDiscoveries();
    }

    public function recordAppliedProposal(EvolutionProposal $proposal, ModelVersion $childVersion): ?StrategyGenome
    {
        if (! Schema::hasTable('strategy_genomes')) {
            return null;
        }

        $parent = $this->findParentGenome($proposal);
        $genes = $this->normalizeGenes($childVersion->parameters ?? $proposal->new_parameters ?? []);
        $child = $this->firstOrCreateGenome([
            'model_version_id' => $childVersion->id,
            'training_session_id' => $proposal->training_session_id,
            'strategy' => $childVersion->strategy,
            'family' => $this->familyName($childVersion->strategy),
            'version' => $childVersion->version ?? $proposal->proposed_version,
            'generation' => (int) $childVersion->generation,
            'genes' => $genes,
            'phenotype' => [
                'source' => 'evolution_proposal',
                'proposal_id' => $proposal->id,
                'main_problem' => $proposal->main_problem,
            ],
            'fitness_score' => 0,
            'status' => 'alive',
        ]);

        if ($parent) {
            GenomeLineage::firstOrCreate([
                'parent_genome_id' => $parent->id,
                'child_genome_id' => $child->id,
                'lineage_type' => 'mutation',
            ], [
                'metadata' => [
                    'proposal_id' => $proposal->id,
                    'reason' => $proposal->reason,
                ],
            ]);

            GenomeMutation::firstOrCreate([
                'parent_genome_id' => $parent->id,
                'child_genome_id' => $child->id,
                'evolution_proposal_id' => $proposal->id,
            ], [
                'mutation_type' => 'parameter_change',
                'mutation_diff' => $this->mutationDiff($proposal->old_parameters ?? [], $proposal->new_parameters ?? []),
                'reason' => $proposal->reason,
            ]);
        }

        $this->refreshGeneration($child->family, (int) $child->generation);

        return $child;
    }

    private function recordGenomeFromScore(StrategyScore $score): StrategyGenome
    {
        $modelVersion = $this->modelVersionForScore($score);
        $genes = $this->normalizeGenes($score->parameters ?? data_get($score->raw_result, 'parameters', []));
        $generation = $modelVersion?->generation ?? $this->generationFromStrategy($score->strategy);
        $version = $modelVersion?->version ?? $this->versionFromStrategy($score->strategy);

        $genome = $this->firstOrCreateGenome([
            'model_version_id' => $modelVersion?->id,
            'strategy_score_id' => $score->id,
            'training_session_id' => $score->training_session_id,
            'strategy' => $score->strategy,
            'family' => $this->familyName($score->strategy),
            'version' => $version,
            'generation' => $generation,
            'genes' => $genes,
            'phenotype' => $this->phenotypeFromScore($score),
            'fitness_score' => $this->fitnessScore($score),
            'evolution_efficiency' => 0,
            'status' => 'alive',
        ]);

        $genome->fill([
            'strategy_score_id' => $score->id,
            'training_session_id' => $score->training_session_id,
            'phenotype' => $this->phenotypeFromScore($score),
            'fitness_score' => $this->fitnessScore($score),
            'evolution_efficiency' => $this->evolutionEfficiency($genome, $score),
            'status' => $genome->status === 'archived' ? 'archived' : 'alive',
        ])->save();

        $this->syncGeneRows($genome);

        return $genome;
    }

    private function firstOrCreateGenome(array $payload): StrategyGenome
    {
        $hash = $this->genomeHash(
            $payload['strategy'],
            $payload['family'],
            $payload['version'],
            (int) $payload['generation'],
            $payload['genes'],
        );

        $genome = StrategyGenome::firstOrNew(['genome_hash' => $hash]);
        $genome->fill($payload + [
            'genome_hash' => $hash,
            'born_at' => now(),
        ]);
        $genome->save();

        $this->syncGeneRows($genome);

        return $genome;
    }

    private function syncGeneRows(StrategyGenome $genome): void
    {
        foreach (($genome->genes ?? []) as $key => $value) {
            $genome->genes()->updateOrCreate(
                ['gene_key' => $key],
                [
                    'gene_value' => ['value' => $value],
                    'value_type' => get_debug_type($value),
                    'observed_fitness' => $genome->fitness_score,
                ],
            );
        }
    }

    private function recordFitness(StrategyGenome $genome, StrategyScore $score): void
    {
        $components = [
            'score' => (float) $score->score,
            'profit_factor' => min(100, (float) $score->profit_factor * 35),
            'robustness' => (float) ($score->robustness_score ?? 50),
            'stability' => (float) ($score->stability_score ?? 50),
            'drawdown_penalty' => (float) $score->max_drawdown_percent,
            'overfit_penalty' => $score->is_overfit ? 20 : 0,
            'risk_of_ruin_penalty' => (float) ($score->mc_risk_of_ruin_percent ?? 0),
        ];

        FitnessEvaluation::updateOrCreate(
            [
                'strategy_genome_id' => $genome->id,
                'strategy_score_id' => $score->id,
            ],
            [
                'training_session_id' => $score->training_session_id,
                'fitness_score' => $this->fitnessScore($score),
                'components' => $components,
                'evaluation_summary' => "{$score->strategy} genome fitness {$genome->fitness_score} from score {$score->score}, robustness {$score->robustness_score}, MC ruin {$score->mc_risk_of_ruin_percent}.",
            ],
        );
    }

    private function runSelection(TrainingSession $session): void
    {
        $alive = StrategyGenome::query()
            ->where('status', 'alive')
            ->orderByDesc('fitness_score')
            ->get();

        if ($alive->count() <= 10) {
            return;
        }

        $survivors = $alive->take(10);
        $archived = $alive->slice(10);

        foreach ($archived as $genome) {
            $reason = $this->extinctionReason($genome);
            $genome->update([
                'status' => 'archived',
                'death_reason' => $reason['reason'],
                'archived_at' => now(),
            ]);

            ExtinctionEvent::firstOrCreate([
                'strategy_genome_id' => $genome->id,
            ], [
                'training_session_id' => $session->id,
                'reason_code' => $reason['code'],
                'reason' => $reason['reason'],
                'evidence' => $genome->phenotype ?? [],
                'extinct_at' => now(),
            ]);
        }

        SelectionEvent::create([
            'training_session_id' => $session->id,
            'selection_type' => 'survival_of_fittest',
            'survivor_genome_ids' => $survivors->pluck('id')->values()->all(),
            'archived_genome_ids' => $archived->pluck('id')->values()->all(),
            'criteria' => [
                'survivors_limit' => 10,
                'sort' => 'fitness_score desc',
                'guardrail' => 'archived genomes keep full evidence and lineage',
            ],
        ]);
    }

    private function proposeCrossovers(TrainingSession $session, Collection $sessionGenomes): void
    {
        $candidates = $sessionGenomes
            ->where('status', 'alive')
            ->sortByDesc('fitness_score')
            ->values();

        if ($candidates->count() < 2) {
            return;
        }

        $parentA = $candidates->first();
        $parentB = $candidates->first(fn (StrategyGenome $genome): bool => $genome->family !== $parentA->family);

        if (! $parentA || ! $parentB) {
            return;
        }

        $childStrategy = strtoupper($parentA->family.'_'.$parentB->family).'_V1';
        $combinedGenes = $this->combinedGenes($parentA, $parentB);

        GenomeCrossover::firstOrCreate([
            'parent_a_genome_id' => $parentA->id,
            'parent_b_genome_id' => $parentB->id,
            'child_strategy' => strtolower($childStrategy),
        ], [
            'combined_genes' => $combinedGenes,
            'rationale' => "{$parentA->strategy} has stronger fitness while {$parentB->strategy} adds complementary gene family. Candidate cross-breed for sandbox validation.",
            'status' => 'proposed',
        ]);
    }

    private function extractGenomeDiscoveries(): void
    {
        $numericGenes = \App\Models\GenomeGene::query()
            ->whereIn('value_type', ['int', 'float', 'double'])
            ->get()
            ->groupBy('gene_key');

        foreach ($numericGenes as $geneKey => $genes) {
            if ($genes->count() < 3) {
                continue;
            }

            $best = $genes->sortByDesc('observed_fitness')->take(max(1, (int) ceil($genes->count() * 0.3)));
            $values = $best->map(fn ($gene): float => (float) data_get($gene->gene_value, 'value'))->values();
            $min = round((float) $values->min(), 4);
            $max = round((float) $values->max(), 4);
            $avgFitness = round((float) $best->avg('observed_fitness'), 2);
            $confidence = min(95, 55 + ($best->count() * 8) + max(0, $avgFitness - 60) * 0.3);

            GenomeDiscovery::updateOrCreate(
                ['title' => "{$geneKey} high-fitness range {$min}-{$max}"],
                [
                    'discovery' => "{$geneKey} values between {$min} and {$max} are overrepresented among high-fitness genomes.",
                    'gene_key' => $geneKey,
                    'scope' => [
                        'range_min' => $min,
                        'range_max' => $max,
                    ],
                    'confidence_score' => round($confidence, 2),
                    'evidence_count' => $genes->count(),
                    'status' => $confidence >= 85 ? 'validated' : 'provisional',
                    'metadata' => [
                        'avg_top_fitness' => $avgFitness,
                        'top_gene_ids' => $best->pluck('id')->values()->all(),
                    ],
                ],
            );

            if (Schema::hasTable('knowledge_facts')) {
                KnowledgeFact::firstOrCreate([
                    'title' => "Genome discovery: {$geneKey} {$min}-{$max}",
                    'source_type' => GenomeDiscovery::class,
                ], [
                    'fact' => "{$geneKey} range {$min}-{$max} appears historically superior in high-fitness genomes.",
                    'scope' => ['gene_key' => $geneKey, 'range_min' => $min, 'range_max' => $max],
                    'confidence_score' => round($confidence, 2),
                    'evidence_count' => $genes->count(),
                    'status' => $confidence >= 85 ? 'validated' : 'provisional',
                    'discovered_at' => now(),
                    'last_seen_at' => now(),
                    'metadata' => ['source' => 'EvolutionGenomeService'],
                ]);
            }
        }
    }

    private function refreshGeneration(string $family, int $generation): void
    {
        $genomes = StrategyGenome::query()
            ->where('family', $family)
            ->where('generation', $generation)
            ->get();

        if ($genomes->isEmpty()) {
            return;
        }

        $best = $genomes->sortByDesc('fitness_score')->first();

        EvolutionGeneration::updateOrCreate(
            ['family' => $family, 'generation' => $generation],
            [
                'genomes_count' => $genomes->count(),
                'best_fitness' => round((float) $genomes->max('fitness_score'), 2),
                'average_fitness' => round((float) $genomes->avg('fitness_score'), 2),
                'best_genome_id' => $best?->id,
            ],
        );
    }

    private function modelVersionForScore(StrategyScore $score): ?ModelVersion
    {
        return ModelVersion::query()
            ->where('strategy', $score->strategy)
            ->latest()
            ->first();
    }

    private function findParentGenome(EvolutionProposal $proposal): ?StrategyGenome
    {
        $strategy = $proposal->strategy;
        $modelVersionId = $proposal->model_version_id;

        return StrategyGenome::query()
            ->when($modelVersionId, fn ($query) => $query->where('model_version_id', $modelVersionId))
            ->orWhere('strategy', $strategy)
            ->orderByDesc('fitness_score')
            ->latest()
            ->first();
    }

    private function normalizeGenes(array $parameters): array
    {
        ksort($parameters);

        return $parameters;
    }

    private function phenotypeFromScore(StrategyScore $score): array
    {
        return [
            'score' => $score->score,
            'winrate' => $score->winrate,
            'profit_factor' => $score->profit_factor,
            'drawdown' => $score->max_drawdown_percent,
            'robustness_score' => $score->robustness_score,
            'is_overfit' => $score->is_overfit,
            'monte_carlo' => [
                'risk_of_ruin_percent' => $score->mc_risk_of_ruin_percent,
                'worst_drawdown_percent' => $score->mc_worst_drawdown_percent,
            ],
            'dna' => $score->dnaProfile?->toArray(),
        ];
    }

    private function fitnessScore(StrategyScore $score): float
    {
        $fitness = ((float) $score->score * 0.35)
            + ((float) ($score->robustness_score ?? $score->score) * 0.20)
            + ((float) ($score->stability_score ?? 50) * 0.15)
            + (min(100, (float) $score->profit_factor * 35) * 0.15)
            + ((float) ($score->dnaProfile?->survival_score ?? 60) * 0.10)
            - ((float) $score->max_drawdown_percent * 0.25)
            - ((float) ($score->mc_risk_of_ruin_percent ?? 0) * 0.35)
            - ($score->is_overfit ? 18 : 0);

        return round(max(0, min(100, $fitness)), 2);
    }

    private function evolutionEfficiency(StrategyGenome $genome, StrategyScore $score): float
    {
        $familyFirst = StrategyGenome::query()
            ->where('family', $genome->family)
            ->orderBy('generation')
            ->orderBy('id')
            ->first();

        if (! $familyFirst || (float) $familyFirst->fitness_score <= 0) {
            return 0;
        }

        return round((((float) $genome->fitness_score - (float) $familyFirst->fitness_score) / max(1, (float) $familyFirst->fitness_score)) * 100, 2);
    }

    private function mutationDiff(array $old, array $new): array
    {
        $keys = collect(array_keys($old + $new))->unique();
        $diff = [];

        foreach ($keys as $key) {
            $oldValue = $old[$key] ?? null;
            $newValue = $new[$key] ?? null;

            if ($oldValue !== $newValue) {
                $diff[$key] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }

        return $diff;
    }

    private function extinctionReason(StrategyGenome $genome): array
    {
        $phenotype = $genome->phenotype ?? [];

        if (data_get($phenotype, 'is_overfit')) {
            return ['code' => 'forward_collapse', 'reason' => 'Forward validation collapsed or model was flagged as overfit.'];
        }

        if ((float) data_get($phenotype, 'monte_carlo.risk_of_ruin_percent', 0) > 30) {
            return ['code' => 'risk_of_ruin', 'reason' => 'Monte Carlo risk of ruin exceeded survival threshold.'];
        }

        return ['code' => 'low_fitness', 'reason' => 'Genome fell outside the top survival set by fitness score.'];
    }

    private function combinedGenes(StrategyGenome $parentA, StrategyGenome $parentB): array
    {
        $genes = [];
        $aGenes = $parentA->genes ?? [];
        $bGenes = $parentB->genes ?? [];

        foreach ($aGenes as $key => $value) {
            $genes[$key] = [
                'value' => $value,
                'source' => $parentA->strategy,
            ];
        }

        foreach ($bGenes as $key => $value) {
            if (! array_key_exists($key, $genes)) {
                $genes[$key] = [
                    'value' => $value,
                    'source' => $parentB->strategy,
                ];
            }
        }

        return $genes;
    }

    private function genomeHash(string $strategy, string $family, string $version, int $generation, array $genes): string
    {
        return hash('sha256', json_encode([
            'strategy' => $strategy,
            'family' => $family,
            'version' => $version,
            'generation' => $generation,
            'genes' => $genes,
        ], JSON_THROW_ON_ERROR));
    }

    private function familyName(string $strategy): string
    {
        return preg_replace('/_v\d+$/', '', $strategy) ?: $strategy;
    }

    private function versionFromStrategy(string $strategy): string
    {
        if (preg_match('/_v(\d+)$/', $strategy, $matches)) {
            return 'v'.$matches[1];
        }

        return 'v1';
    }

    private function generationFromStrategy(string $strategy): int
    {
        if (preg_match('/_v(\d+)$/', $strategy, $matches)) {
            return (int) $matches[1];
        }

        return 1;
    }
}
