<?php

namespace App\Services;

use App\Models\AgentBelief;
use App\Models\AgentHypothesis;
use App\Models\ExtinctionEvent;
use App\Models\GenomeDiscovery;
use App\Models\KnowledgeClaim;
use App\Models\KnowledgeEvidence;
use App\Models\KnowledgeFact;
use App\Models\KnowledgeGraphEdge;
use App\Models\KnowledgeGraphNode;
use App\Models\KnowledgeMiningRun;
use App\Models\KnowledgeQuery;
use App\Models\MarketDiscovery;
use App\Models\MarketSpecies;
use App\Models\StrategyGenome;
use App\Models\StrategyScore;
use App\Models\StrategySpeciesPerformance;
use App\Models\TrainingSession;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class UniversalKnowledgeGraphService
{
    public function recordTrainingSession(TrainingSession $session): void
    {
        if (! Schema::hasTable('knowledge_graph_nodes')) {
            return;
        }

        $session->loadMissing(['strategyScores.dnaProfile']);

        $sessionNode = $this->node(
            'training_session',
            'session:'.$session->id,
            'Session #'.$session->id,
            "Training session for {$session->symbol} {$session->timeframe}.",
            TrainingSession::class,
            $session->id,
            (float) ($session->average_stability_score ?: 55),
            max(1, (int) $session->agents_count),
            [
                'symbol' => $session->symbol,
                'timeframe' => $session->timeframe,
                'best_strategy' => $session->best_strategy,
                'worst_strategy' => $session->worst_strategy,
            ],
        );

        $symbolNode = $this->node('symbol', 'symbol:'.$session->symbol, $session->symbol, 'Traded market symbol.', null, null, 70);
        $timeframeNode = $this->node('timeframe', 'timeframe:'.$session->timeframe, $session->timeframe, 'Training timeframe.', null, null, 70);
        $this->edge($sessionNode, $symbolNode, 'RUN_ON_SYMBOL', 1, 80, 1, 'positive', ['source' => 'training_session']);
        $this->edge($sessionNode, $timeframeNode, 'RUN_ON_TIMEFRAME', 1, 80, 1, 'positive', ['source' => 'training_session']);

        foreach ($session->strategyScores as $score) {
            $this->recordStrategyScore($sessionNode, $score);
        }

        $this->linkSessionHypotheses($session);
        $this->linkScientistFacts();
        $this->mine();
    }

    public function mine(): KnowledgeMiningRun
    {
        $run = KnowledgeMiningRun::create([
            'status' => 'running',
            'started_at' => now(),
        ]);

        $before = [
            'nodes' => KnowledgeGraphNode::count(),
            'edges' => KnowledgeGraphEdge::count(),
            'claims' => KnowledgeClaim::count(),
        ];

        $this->mineStrategySpeciesPatterns();
        $this->mineFailureIntelligence();
        $this->mineGenomeDiscoveries();
        $this->mineMarketDiscoveries();
        $this->mineBeliefClaims();

        $after = [
            'nodes' => KnowledgeGraphNode::count(),
            'edges' => KnowledgeGraphEdge::count(),
            'claims' => KnowledgeClaim::count(),
        ];

        $run->update([
            'status' => 'success',
            'finished_at' => now(),
            'nodes_created' => max(0, $after['nodes'] - $before['nodes']),
            'edges_created' => max(0, $after['edges'] - $before['edges']),
            'claims_created' => max(0, $after['claims'] - $before['claims']),
            'summary' => 'Knowledge Miner connected strategies, market species, genomes, hypotheses, beliefs, discoveries and failure causes.',
            'metrics' => [
                'before' => $before,
                'after' => $after,
            ],
        ]);

        return $run;
    }

    public function answer(string $question): KnowledgeQuery
    {
        if (! Schema::hasTable('knowledge_queries')) {
            throw new \RuntimeException('Knowledge Graph tables are not migrated.');
        }

        $normalized = str($question)->lower()->toString();
        $matchedNodes = collect();
        $matchedEdges = collect();
        $reasoning = [];
        $confidence = 55.0;

        $strategy = $this->strategyFromQuestion($normalized);
        if ($strategy) {
            $strategyNode = KnowledgeGraphNode::query()
                ->where('node_type', 'strategy')
                ->where('node_key', 'strategy:'.$strategy)
                ->first();

            if ($strategyNode) {
                $matchedNodes->push($strategyNode);
                $edges = $strategyNode->outgoingEdges()
                    ->with('targetNode')
                    ->orderByDesc('confidence_score')
                    ->take(12)
                    ->get();
                $matchedEdges = $matchedEdges->merge($edges);
                $reasoning = $edges->map(fn (KnowledgeGraphEdge $edge): string => "{$edge->relation_type}: {$edge->targetNode?->label} ({$edge->confidence_score}%)")->values()->all();
                $confidence = max($confidence, (float) $edges->avg('confidence_score'));
            }
        }

        if (str_contains($normalized, 'fail') || str_contains($normalized, 'death') || str_contains($normalized, 'failing') || str_contains($normalized, 'zaif')) {
            $claims = KnowledgeClaim::query()
                ->whereIn('claim_type', ['failure_cause', 'strategy_species_performance'])
                ->orderByDesc('confidence_score')
                ->take(6)
                ->get();
            $reasoning = array_merge($reasoning, $claims->map(fn (KnowledgeClaim $claim): string => "{$claim->title}: {$claim->confidence_score}%")->all());
            $confidence = max($confidence, (float) $claims->avg('confidence_score'));
        } elseif (! $strategy) {
            $claims = KnowledgeClaim::query()
                ->orderByDesc('confidence_score')
                ->take(6)
                ->get();
            $reasoning = $claims->map(fn (KnowledgeClaim $claim): string => "{$claim->title}: {$claim->confidence_score}%")->all();
            $confidence = max($confidence, (float) $claims->avg('confidence_score'));
        }

        $answer = $this->composeAnswer($question, $reasoning, $strategy);

        return KnowledgeQuery::create([
            'question' => $question,
            'answer' => $answer,
            'matched_node_ids' => $matchedNodes->pluck('id')->unique()->values()->all(),
            'matched_edge_ids' => $matchedEdges->pluck('id')->unique()->values()->all(),
            'confidence_score' => round($confidence, 2),
            'reasoning' => $reasoning,
            'metadata' => [
                'strategy' => $strategy,
                'mode' => str_contains($normalized, 'fail') || str_contains($normalized, 'death') ? 'failure_intelligence' : 'research_assistant',
            ],
        ]);
    }

    private function recordStrategyScore(KnowledgeGraphNode $sessionNode, StrategyScore $score): void
    {
        $strategyNode = $this->node(
            'strategy',
            'strategy:'.$score->strategy,
            strtoupper($score->strategy),
            'Strategy or agent version observed in training history.',
            StrategyScore::class,
            $score->id,
            (float) $score->score,
            max(1, (int) $score->total_trades),
            [
                'parameters' => $score->parameters,
                'score' => $score->score,
                'profit_factor' => $score->profit_factor,
                'winrate' => $score->winrate,
            ],
        );

        $this->edge($sessionNode, $strategyNode, 'OBSERVED_STRATEGY', 1, (float) $score->score, max(1, (int) $score->total_trades));
        $this->recordMetricEdges($strategyNode, $score);
        $this->recordParameterEdges($strategyNode, $score);
        $this->recordGenomeEdges($strategyNode, $score);
        $this->recordSpeciesEdges($strategyNode, $score);
        $this->recordFailureEdges($strategyNode, $score);
    }

    private function recordMetricEdges(KnowledgeGraphNode $strategyNode, StrategyScore $score): void
    {
        $metrics = [
            'profit_factor' => (float) $score->profit_factor,
            'winrate' => (float) $score->winrate,
            'robustness_score' => (float) ($score->robustness_score ?? 0),
            'stability_score' => (float) ($score->stability_score ?? 0),
        ];

        foreach ($metrics as $metric => $value) {
            $bucket = $this->metricBucket($metric, $value);
            $metricNode = $this->node(
                'metric',
                "metric:{$metric}:{$bucket}",
                "{$metric} {$bucket}",
                'Observed strategy performance metric bucket.',
                StrategyScore::class,
                $score->id,
                $this->metricConfidence($metric, $value),
                max(1, (int) $score->total_trades),
                ['metric' => $metric, 'value' => $value, 'bucket' => $bucket],
            );

            $this->edge($strategyNode, $metricNode, 'ACHIEVED_METRIC', max(0.1, $value), $this->metricConfidence($metric, $value), max(1, (int) $score->total_trades));
        }
    }

    private function recordParameterEdges(KnowledgeGraphNode $strategyNode, StrategyScore $score): void
    {
        foreach (($score->parameters ?? []) as $key => $value) {
            if (is_array($value) || is_object($value)) {
                continue;
            }

            $bucket = is_numeric($value) ? $this->numericBucket((float) $value) : (string) $value;
            $parameterNode = $this->node(
                'parameter',
                "parameter:{$key}:{$bucket}",
                "{$key} {$bucket}",
                'Strategy parameter bucket.',
                StrategyScore::class,
                $score->id,
                (float) $score->score,
                max(1, (int) $score->total_trades),
                ['parameter' => $key, 'value' => $value, 'bucket' => $bucket],
            );

            $this->edge($strategyNode, $parameterNode, 'USES_PARAMETER', 1, (float) $score->score, max(1, (int) $score->total_trades));
        }
    }

    private function recordGenomeEdges(KnowledgeGraphNode $strategyNode, StrategyScore $score): void
    {
        $genome = StrategyGenome::query()
            ->where('strategy_score_id', $score->id)
            ->orWhere('strategy', $score->strategy)
            ->orderByDesc('fitness_score')
            ->latest()
            ->first();

        if (! $genome) {
            return;
        }

        $genomeNode = $this->node(
            'strategy_genome',
            'strategy_genome:'.$genome->genome_hash,
            "{$genome->strategy} genome {$genome->version}",
            'Immutable strategy genome snapshot.',
            StrategyGenome::class,
            $genome->id,
            (float) $genome->fitness_score,
            1,
            [
                'family' => $genome->family,
                'generation' => $genome->generation,
                'fitness_score' => $genome->fitness_score,
            ],
        );

        $this->edge($strategyNode, $genomeNode, 'HAS_GENOME', 1, (float) $genome->fitness_score, 1);
    }

    private function recordSpeciesEdges(KnowledgeGraphNode $strategyNode, StrategyScore $score): void
    {
        $performances = StrategySpeciesPerformance::query()
            ->with('marketSpecies')
            ->where('strategy_score_id', $score->id)
            ->get();

        foreach ($performances as $performance) {
            $species = $performance->marketSpecies;
            if (! $species) {
                continue;
            }

            $speciesNode = $this->speciesNode($species);
            $confidence = $this->clamp((float) $performance->confidence_score + ((float) $performance->profit_percent > 0 ? 8 : -10));
            $polarity = (float) $performance->profit_percent >= 0 ? 'positive' : 'negative';

            $edge = $this->edge($strategyNode, $speciesNode, 'PERFORMS_IN_MARKET_SPECIES', (float) $performance->profit_percent, $confidence, max(1, (int) $performance->trades), $polarity, [
                'winrate' => $performance->winrate,
                'profit_percent' => $performance->profit_percent,
                'species_name' => $species->name,
            ]);

            $this->evidenceForEdge($edge, StrategySpeciesPerformance::class, $performance->id, 'strategy_species_performance', "{$strategyNode->label} produced {$performance->profit_percent}% in {$species->name}.");
        }
    }

    private function recordFailureEdges(KnowledgeGraphNode $strategyNode, StrategyScore $score): void
    {
        $causes = [];

        if ($score->is_overfit) {
            $causes[] = ['overfitting', 'Walk Forward detected overfit or forward collapse.'];
        }
        if ((float) ($score->mc_risk_of_ruin_percent ?? 0) > 20) {
            $causes[] = ['risk_of_ruin', 'Monte Carlo risk of ruin is high.'];
        }
        if ((float) $score->profit_factor < 1) {
            $causes[] = ['weak_profit_factor', 'Profit factor is below survival threshold.'];
        }
        if ((float) $score->max_drawdown_percent > 15) {
            $causes[] = ['drawdown_pressure', 'Drawdown pressure is high.'];
        }
        if ((float) data_get($score->dnaProfile, 'trend_dependency', 0) > 75 && (float) $score->net_profit_percent <= 0) {
            $causes[] = ['excessive_trend_dependency', 'DNA suggests high trend dependency while profit is weak.'];
        }

        foreach ($causes as [$key, $summary]) {
            $causeNode = $this->node('failure_cause', 'failure_cause:'.$key, str($key)->replace('_', ' ')->title()->toString(), $summary, StrategyScore::class, $score->id, 75);
            $this->edge($strategyNode, $causeNode, 'HAS_FAILURE_CAUSE', 1, 75, 1, 'negative', ['score_id' => $score->id, 'summary' => $summary]);
        }
    }

    private function linkSessionHypotheses(TrainingSession $session): void
    {
        AgentHypothesis::query()
            ->where('training_session_id', $session->id)
            ->get()
            ->each(function (AgentHypothesis $hypothesis): void {
                $strategyNode = $this->node('strategy', 'strategy:'.$hypothesis->strategy, strtoupper($hypothesis->strategy), 'Strategy or agent version observed in hypothesis history.', AgentHypothesis::class, $hypothesis->id, (float) $hypothesis->confidence);
                $hypothesisNode = $this->node('hypothesis', 'hypothesis:'.$hypothesis->id, "Hypothesis #{$hypothesis->id}", $hypothesis->hypothesis, AgentHypothesis::class, $hypothesis->id, (float) $hypothesis->confidence, 1, ['status' => $hypothesis->status]);
                $this->edge($strategyNode, $hypothesisNode, 'PRODUCED_HYPOTHESIS', 1, (float) $hypothesis->confidence, 1, $hypothesis->status === 'failed' ? 'negative' : 'positive');
            });
    }

    private function linkScientistFacts(): void
    {
        KnowledgeFact::query()
            ->latest('last_seen_at')
            ->take(50)
            ->get()
            ->each(function (KnowledgeFact $fact): void {
                $claimNode = $this->node('knowledge_fact', 'knowledge_fact:'.$fact->id, $fact->title, $fact->fact, KnowledgeFact::class, $fact->id, (float) $fact->confidence_score, (int) $fact->evidence_count, ['scope' => $fact->scope]);
                $this->claim(
                    $fact->title,
                    $fact->fact,
                    'knowledge_fact',
                    (float) $fact->confidence_score,
                    (int) $fact->evidence_count,
                    $fact->status,
                    $fact->scope ?? [],
                    ['source_type' => $fact->source_type, 'source_id' => $fact->source_id],
                    $claimNode,
                );
            });
    }

    private function mineStrategySpeciesPatterns(): void
    {
        StrategySpeciesPerformance::query()
            ->with('marketSpecies')
            ->get()
            ->groupBy(fn (StrategySpeciesPerformance $item): string => $item->strategy.'|'.$item->market_species_id)
            ->each(function (Collection $items): void {
                $first = $items->first();
                if (! $first || ! $first->marketSpecies) {
                    return;
                }

                $strategyNode = $this->node('strategy', 'strategy:'.$first->strategy, strtoupper($first->strategy), 'Strategy or agent version.', StrategySpeciesPerformance::class, $first->id, (float) $items->avg('confidence_score'), (int) $items->sum('trades'));
                $speciesNode = $this->speciesNode($first->marketSpecies);
                $avgProfit = round((float) $items->avg('profit_percent'), 2);
                $avgWinrate = round((float) $items->avg('winrate'), 2);
                $evidenceCount = max(1, (int) $items->sum('trades'));
                $confidence = $this->clamp(50 + min(30, $items->count() * 6) + ($avgProfit > 0 ? 10 : -10) + min(10, $evidenceCount / 20));
                $direction = $avgProfit >= 0 ? 'performs better' : 'struggles';
                $title = "{$first->strategy} {$direction} in {$first->marketSpecies->name}";

                $this->edge($strategyNode, $speciesNode, 'HAS_CONTEXTUAL_PERFORMANCE', $avgProfit, $confidence, $evidenceCount, $avgProfit >= 0 ? 'positive' : 'negative', [
                    'avg_profit' => $avgProfit,
                    'avg_winrate' => $avgWinrate,
                    'samples' => $items->count(),
                ]);

                $this->claim(
                    $title,
                    strtoupper($first->strategy)." {$direction} in {$first->marketSpecies->name}: avg profit {$avgProfit}%, avg winrate {$avgWinrate}%.",
                    'strategy_species_performance',
                    $confidence,
                    $evidenceCount,
                    $confidence >= 85 ? 'validated' : 'provisional',
                    ['strategy' => $first->strategy, 'market_species' => $first->marketSpecies->name],
                    ['samples' => $items->pluck('id')->values()->all()],
                    $strategyNode,
                );
            });
    }

    private function mineFailureIntelligence(): void
    {
        ExtinctionEvent::query()
            ->get()
            ->groupBy('reason_code')
            ->each(function (Collection $events, string $reasonCode): void {
                $causeNode = $this->node('failure_cause', 'failure_cause:'.$reasonCode, str($reasonCode)->replace('_', ' ')->title()->toString(), 'Observed strategy death/failure cause.', ExtinctionEvent::class, $events->first()?->id, 70 + min(20, $events->count() * 4), $events->count());

                $this->claim(
                    'Strategy death cause: '.$causeNode->label,
                    "{$causeNode->label} appeared in {$events->count()} extinction/failure events.",
                    'failure_cause',
                    $this->clamp(58 + $events->count() * 8),
                    $events->count(),
                    $events->count() >= 4 ? 'validated' : 'provisional',
                    ['reason_code' => $reasonCode],
                    ['event_ids' => $events->pluck('id')->values()->all()],
                    $causeNode,
                );
            });

        StrategyScore::query()
            ->where(function ($query): void {
                $query->where('is_overfit', true)
                    ->orWhere('profit_factor', '<', 1)
                    ->orWhere('max_drawdown_percent', '>', 15);
            })
            ->get()
            ->each(function (StrategyScore $score): void {
                $strategyNode = $this->node('strategy', 'strategy:'.$score->strategy, strtoupper($score->strategy), 'Strategy or agent version.', StrategyScore::class, $score->id, (float) $score->score);
                $this->recordFailureEdges($strategyNode, $score);
            });
    }

    private function mineGenomeDiscoveries(): void
    {
        GenomeDiscovery::query()->get()->each(function (GenomeDiscovery $discovery): void {
            $node = $this->node('genome_discovery', 'genome_discovery:'.$discovery->id, $discovery->title, $discovery->discovery, GenomeDiscovery::class, $discovery->id, (float) $discovery->confidence_score, (int) $discovery->evidence_count, ['scope' => $discovery->scope]);

            $this->claim(
                $discovery->title,
                $discovery->discovery,
                'genome_pattern',
                (float) $discovery->confidence_score,
                (int) $discovery->evidence_count,
                $discovery->status,
                $discovery->scope ?? [],
                $discovery->metadata ?? [],
                $node,
            );
        });
    }

    private function mineMarketDiscoveries(): void
    {
        MarketDiscovery::query()->with('marketSpecies')->get()->each(function (MarketDiscovery $discovery): void {
            $node = $this->node('market_discovery', 'market_discovery:'.$discovery->id, $discovery->title, $discovery->discovery, MarketDiscovery::class, $discovery->id, (float) $discovery->confidence_score, (int) $discovery->evidence_count, ['market_state' => $discovery->market_state]);

            if ($discovery->marketSpecies) {
                $speciesNode = $this->speciesNode($discovery->marketSpecies);
                $this->edge($speciesNode, $node, 'HAS_MARKET_DISCOVERY', 1, (float) $discovery->confidence_score, (int) $discovery->evidence_count);
            }

            $this->claim(
                $discovery->title,
                $discovery->discovery,
                'market_pattern',
                (float) $discovery->confidence_score,
                (int) $discovery->evidence_count,
                $discovery->status,
                ['market_state' => $discovery->market_state],
                $discovery->metadata ?? [],
                $node,
            );
        });
    }

    private function mineBeliefClaims(): void
    {
        AgentBelief::query()
            ->where('sample_size', '>', 0)
            ->get()
            ->each(function (AgentBelief $belief): void {
                $strategyNode = $this->node('strategy', 'strategy:'.$belief->strategy, strtoupper($belief->strategy), 'Strategy or agent version.', AgentBelief::class, $belief->id, (float) $belief->score, (int) $belief->sample_size);
                $beliefNode = $this->node('agent_belief', "agent_belief:{$belief->strategy}:{$belief->belief_key}", $belief->belief_label, 'Agent belief calibrated from scientific evidence.', AgentBelief::class, $belief->id, (float) $belief->score, (int) $belief->sample_size);
                $this->edge($strategyNode, $beliefNode, 'HAS_BELIEF', 1, (float) $belief->score, (int) $belief->sample_size, $belief->score >= 50 ? 'positive' : 'negative');

                $this->claim(
                    "{$belief->strategy} belief {$belief->belief_key}",
                    strtoupper($belief->strategy)." has {$belief->belief_label} belief score {$belief->score}% over {$belief->sample_size} samples.",
                    'agent_belief',
                    (float) $belief->score,
                    (int) $belief->sample_size,
                    $belief->score >= 80 ? 'validated' : 'provisional',
                    ['strategy' => $belief->strategy, 'belief_key' => $belief->belief_key],
                    ['confirmed' => $belief->confirmed_count, 'failed' => $belief->failed_count],
                    $strategyNode,
                );
            });
    }

    private function node(
        string $type,
        string $key,
        string $label,
        ?string $summary = null,
        ?string $sourceType = null,
        ?int $sourceId = null,
        float $confidence = 50,
        int $evidenceCount = 1,
        array $metadata = [],
    ): KnowledgeGraphNode {
        $node = KnowledgeGraphNode::firstOrNew(['node_key' => $this->shortKey($key)]);
        $existingEvidence = (int) ($node->evidence_count ?? 0);
        $node->fill([
            'node_type' => $type,
            'label' => $label,
            'summary' => $summary,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'confidence_score' => $this->weightedAverage((float) ($node->confidence_score ?? 0), $existingEvidence, $confidence, $evidenceCount),
            'evidence_count' => max($existingEvidence, $existingEvidence + $evidenceCount),
            'metadata' => array_replace($node->metadata ?? [], $metadata),
            'last_seen_at' => now(),
        ]);
        $node->save();

        return $node;
    }

    private function edge(
        KnowledgeGraphNode $source,
        KnowledgeGraphNode $target,
        string $relation,
        float $weight = 1,
        float $confidence = 50,
        int $evidenceCount = 1,
        string $polarity = 'positive',
        array $metadata = [],
    ): KnowledgeGraphEdge {
        $edge = KnowledgeGraphEdge::firstOrNew([
            'source_node_id' => $source->id,
            'target_node_id' => $target->id,
            'relation_type' => $relation,
        ]);
        $existingEvidence = (int) ($edge->evidence_count ?? 0);
        $edge->fill([
            'weight' => $this->weightedAverage((float) ($edge->weight ?? 0), $existingEvidence, $weight, $evidenceCount),
            'confidence_score' => $this->weightedAverage((float) ($edge->confidence_score ?? 0), $existingEvidence, $confidence, $evidenceCount),
            'evidence_count' => max($existingEvidence, $existingEvidence + $evidenceCount),
            'polarity' => $polarity,
            'status' => 'active',
            'metadata' => array_replace($edge->metadata ?? [], $metadata),
            'last_seen_at' => now(),
        ]);
        $edge->save();

        return $edge;
    }

    private function claim(
        string $title,
        string $claim,
        string $type,
        float $confidence,
        int $evidenceCount,
        string $status,
        array $scope,
        array $metadata,
        ?KnowledgeGraphNode $primaryNode = null,
    ): KnowledgeClaim {
        $knowledgeClaim = KnowledgeClaim::updateOrCreate(
            ['title' => $title],
            [
                'primary_node_id' => $primaryNode?->id,
                'claim' => $claim,
                'claim_type' => $type,
                'confidence_score' => round($this->clamp($confidence), 2),
                'evidence_count' => $evidenceCount,
                'status' => $status,
                'scope' => $scope,
                'metadata' => $metadata,
                'last_seen_at' => now(),
            ],
        );

        if ($primaryNode) {
            KnowledgeEvidence::firstOrCreate([
                'knowledge_claim_id' => $knowledgeClaim->id,
                'knowledge_graph_node_id' => $primaryNode->id,
                'source_type' => $primaryNode->source_type ?? KnowledgeGraphNode::class,
                'source_id' => $primaryNode->source_id ?? $primaryNode->id,
                'evidence_type' => $type,
            ], [
                'summary' => $claim,
                // knowledge_evidence.weight is DECIMAL(8,4); raw historical
                // sample counts can exceed its maximum (9,999.9999). Keep the
                // stored weight bounded while preserving the exact count in
                // the surrounding node/claim evidence metadata.
                'weight' => min(9999.9999, max(1, $evidenceCount)),
                'observed_at' => now(),
                'metadata' => $metadata,
            ]);
        }

        return $knowledgeClaim;
    }

    private function evidenceForEdge(KnowledgeGraphEdge $edge, string $sourceType, ?int $sourceId, string $type, string $summary): void
    {
        KnowledgeEvidence::firstOrCreate([
            'knowledge_graph_edge_id' => $edge->id,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'evidence_type' => $type,
        ], [
            'summary' => $summary,
            'weight' => $edge->weight,
            'observed_at' => now(),
            'metadata' => ['confidence_score' => $edge->confidence_score],
        ]);
    }

    private function speciesNode(MarketSpecies $species): KnowledgeGraphNode
    {
        return $this->node(
            'market_species',
            'market_species:'.$species->code,
            $species->name,
            $species->description,
            MarketSpecies::class,
            $species->id,
            (float) max($species->danger_score, $species->opportunity_score),
            (int) $species->snapshots()->count(),
            [
                'code' => $species->code,
                'dominant_state' => $species->dominant_state,
                'danger_score' => $species->danger_score,
                'opportunity_score' => $species->opportunity_score,
            ],
        );
    }

    private function composeAnswer(string $question, array $reasoning, ?string $strategy): string
    {
        if (empty($reasoning)) {
            return "Knowledge Graph hozircha bu savolga yetarli evidence topmadi. Ko'proq session, species performance yoki discovery kerak.";
        }

        $subject = $strategy ? strtoupper($strategy) : 'Graph';
        $lines = array_slice($reasoning, 0, 5);

        return $subject." bo'yicha graph evidence: ".implode(' | ', $lines);
    }

    private function strategyFromQuestion(string $question): ?string
    {
        $strategies = KnowledgeGraphNode::query()
            ->where('node_type', 'strategy')
            ->pluck('node_key')
            ->map(fn (string $key): string => str_replace('strategy:', '', $key));

        foreach ($strategies as $strategy) {
            if (str_contains($question, strtolower($strategy)) || str_contains($question, strtolower(str_replace('_', ' ', $strategy)))) {
                return $strategy;
            }
        }

        if (preg_match('/([a-z]+(?:_[a-z]+)*_v\d+)/', $question, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function metricBucket(string $metric, float $value): string
    {
        return match ($metric) {
            'profit_factor' => $value >= 2 ? '>2' : ($value >= 1.3 ? '1.3-2' : '<1.3'),
            'winrate' => $value >= 70 ? '>70' : ($value >= 55 ? '55-70' : '<55'),
            default => $this->numericBucket($value),
        };
    }

    private function numericBucket(float $value): string
    {
        $floor = (int) (floor($value / 10) * 10);

        return $floor.'-'.($floor + 10);
    }

    private function metricConfidence(string $metric, float $value): float
    {
        return match ($metric) {
            'profit_factor' => $this->clamp(40 + min(55, $value * 25)),
            'winrate' => $this->clamp($value),
            default => $this->clamp($value),
        };
    }

    private function weightedAverage(float $old, int $oldCount, float $new, int $newCount): float
    {
        if ($oldCount <= 0) {
            return round($this->clamp($new), 2);
        }

        return round($this->clamp((($old * $oldCount) + ($new * $newCount)) / max(1, $oldCount + $newCount)), 2);
    }

    private function shortKey(string $key): string
    {
        if (strlen($key) <= 240) {
            return $key;
        }

        return substr($key, 0, 180).':'.substr(hash('sha256', $key), 0, 24);
    }

    private function clamp(float $value, float $min = 0, float $max = 100): float
    {
        return max($min, min($max, $value));
    }
}
