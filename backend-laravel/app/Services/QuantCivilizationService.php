<?php

namespace App\Services;

use App\Models\AgentReputation;
use App\Models\BlindSpot;
use App\Models\CivilizationAgent;
use App\Models\CivilizationCreditEvent;
use App\Models\CivilizationGoal;
use App\Models\CivilizationMemory;
use App\Models\CouncilDecision;
use App\Models\CouncilVote;
use App\Models\FutureDiscovery;
use App\Models\GenomeDiscovery;
use App\Models\InstitutionalKnowledge;
use App\Models\KnowledgeClaim;
use App\Models\MarketDiscovery;
use App\Models\MetaAuditRun;
use App\Models\StrategyScore;
use App\Models\UnknownZone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class QuantCivilizationService
{
    public function synchronize(): ?CouncilDecision
    {
        if (! Schema::hasTable('civilization_agents')) {
            return null;
        }

        $agents = $this->ensureAgents();
        $this->assignCredits($agents);
        $this->preserveInstitutionalKnowledge();
        $this->writeCollectiveMemory();
        $this->syncCivilizationGoals($agents);

        return $this->deliberateCouncil($agents);
    }

    private function ensureAgents(): Collection
    {
        $roleAgents = collect([
            [
                'agent_key' => 'role:research',
                'display_name' => 'Research Agent',
                'role_key' => 'research',
                'role_label' => 'Research Agent',
                'domain' => 'research',
                'capabilities' => ['hypothesis_generation', 'research_queue', 'experiment_design'],
                'objectives' => ['increase_information_gain', 'discover_new_edges'],
            ],
            [
                'agent_key' => 'role:risk',
                'display_name' => 'Risk Agent',
                'role_key' => 'risk',
                'role_label' => 'Risk Agent',
                'domain' => 'risk',
                'capabilities' => ['drawdown_control', 'ruin_risk_review', 'veto_high_risk'],
                'objectives' => ['protect_capital', 'reduce_tail_risk'],
            ],
            [
                'agent_key' => 'role:market',
                'display_name' => 'Market Agent',
                'role_key' => 'market',
                'role_label' => 'Market Agent',
                'domain' => 'market_intelligence',
                'capabilities' => ['species_library', 'market_memory', 'similarity_scanning'],
                'objectives' => ['expand_market_coverage', 'detect_unknown_territory'],
            ],
            [
                'agent_key' => 'role:evolution',
                'display_name' => 'Evolution Agent',
                'role_key' => 'evolution',
                'role_label' => 'Evolution Agent',
                'domain' => 'strategy_evolution',
                'capabilities' => ['mutation_tracking', 'lineage_review', 'survival_selection'],
                'objectives' => ['increase_adaptability', 'avoid_strategy_stagnation'],
            ],
            [
                'agent_key' => 'role:knowledge',
                'display_name' => 'Knowledge Agent',
                'role_key' => 'knowledge',
                'role_label' => 'Knowledge Agent',
                'domain' => 'knowledge_graph',
                'capabilities' => ['claim_preservation', 'evidence_linking', 'institutional_memory'],
                'objectives' => ['preserve_discoveries', 'improve_evidence_quality'],
            ],
            [
                'agent_key' => 'role:prediction',
                'display_name' => 'Prediction Agent',
                'role_key' => 'prediction',
                'role_label' => 'Prediction Agent',
                'domain' => 'future_simulation',
                'capabilities' => ['scenario_planning', 'survival_forecast', 'future_stress_tests'],
                'objectives' => ['improve_forecast_reliability', 'reduce_planning_error'],
            ],
            [
                'agent_key' => 'role:meta',
                'display_name' => 'Meta Agent',
                'role_key' => 'meta',
                'role_label' => 'Meta Agent',
                'domain' => 'audit',
                'capabilities' => ['knowledge_audit', 'contradiction_detection', 'blind_spot_detection'],
                'objectives' => ['reduce_false_confidence', 'surface_unknowns'],
            ],
        ])->map(fn (array $definition): CivilizationAgent => $this->upsertAgent($definition));

        $strategyAgents = collect();
        if (Schema::hasTable('agent_reputations')) {
            $strategyAgents = AgentReputation::query()
                ->orderByDesc('reputation_score')
                ->take(8)
                ->get()
                ->map(fn (AgentReputation $reputation): CivilizationAgent => $this->upsertAgent([
                    'agent_key' => 'strategy:'.$reputation->strategy,
                    'display_name' => strtoupper($reputation->strategy),
                    'role_key' => 'strategy_member',
                    'role_label' => 'Strategy Member',
                    'domain' => 'strategy',
                    'reputation_score' => (float) $reputation->reputation_score,
                    'trust_score' => (float) $reputation->trust_score,
                    'contribution_score' => (float) $reputation->survival_score,
                    'capabilities' => ['trade_decision', 'strategy_evidence'],
                    'objectives' => ['survive_future_scenarios', 'contribute_evidence'],
                    'metadata' => [
                        'source' => 'agent_reputation',
                        'strategy' => $reputation->strategy,
                        'sessions_count' => $reputation->sessions_count,
                        'calibration_score' => $reputation->calibration_score,
                    ],
                ]));
        }

        return $roleAgents->merge($strategyAgents)->values();
    }

    private function upsertAgent(array $definition): CivilizationAgent
    {
        $agent = CivilizationAgent::updateOrCreate(
            ['agent_key' => $definition['agent_key']],
            [
                'display_name' => $definition['display_name'],
                'role_key' => $definition['role_key'],
                'role_label' => $definition['role_label'],
                'domain' => $definition['domain'],
                'status' => 'active',
                'reputation_score' => round($this->clamp((float) ($definition['reputation_score'] ?? $this->roleReputation($definition['role_key']))), 2),
                'contribution_score' => round($this->clamp((float) ($definition['contribution_score'] ?? $this->roleContribution($definition['role_key']))), 2),
                'trust_score' => round($this->clamp((float) ($definition['trust_score'] ?? $this->roleTrust($definition['role_key']))), 2),
                'vote_weight' => round($this->voteWeight((float) ($definition['reputation_score'] ?? $this->roleReputation($definition['role_key']))), 2),
                'capabilities' => $definition['capabilities'] ?? [],
                'objectives' => $definition['objectives'] ?? [],
                'metadata' => $definition['metadata'] ?? [],
                'last_active_at' => now(),
            ],
        );

        return $agent;
    }

    private function assignCredits(Collection $agents): void
    {
        $creditPlan = [
            'role:knowledge' => [
                'amount' => $this->boundedCount(KnowledgeClaim::class, 'knowledge_claims', 10, 1600),
                'reason' => 'Credits for preserving Knowledge Graph claims.',
                'source_type' => KnowledgeClaim::class,
                'source_id' => null,
            ],
            'role:market' => [
                'amount' => $this->boundedCount(MarketDiscovery::class, 'market_discoveries', 35, 1400),
                'reason' => 'Credits for market species and discovery coverage.',
                'source_type' => MarketDiscovery::class,
                'source_id' => null,
            ],
            'role:evolution' => [
                'amount' => $this->boundedCount(GenomeDiscovery::class, 'genome_discoveries', 45, 1200),
                'reason' => 'Credits for genome discoveries and evolution evidence.',
                'source_type' => GenomeDiscovery::class,
                'source_id' => null,
            ],
            'role:prediction' => [
                'amount' => $this->boundedCount(FutureDiscovery::class, 'future_discoveries', 55, 1300),
                'reason' => 'Credits for future discoveries and scenario planning.',
                'source_type' => FutureDiscovery::class,
                'source_id' => null,
            ],
            'role:meta' => [
                'amount' => $this->boundedCount(MetaAuditRun::class, 'meta_audit_runs', 75, 1500),
                'reason' => 'Credits for self-audit, blind-spot and contradiction monitoring.',
                'source_type' => MetaAuditRun::class,
                'source_id' => null,
            ],
            'role:risk' => [
                'amount' => $this->riskCredits(),
                'reason' => 'Credits for reducing unknown zones and forcing defensive review.',
                'source_type' => MetaAuditRun::class,
                'source_id' => null,
            ],
            'role:research' => [
                'amount' => $this->researchCredits(),
                'reason' => 'Credits for turning gaps into research priorities.',
                'source_type' => BlindSpot::class,
                'source_id' => null,
            ],
        ];

        foreach ($agents as $agent) {
            $plan = $creditPlan[$agent->agent_key] ?? null;
            if (! $plan && $agent->role_key === 'strategy_member') {
                $plan = [
                    'amount' => round(((float) $agent->reputation_score * 7) + ((float) $agent->contribution_score * 4), 2),
                    'reason' => 'Credits for strategy reputation and survival contribution.',
                    'source_type' => AgentReputation::class,
                    'source_id' => null,
                ];
            }

            if (! $plan) {
                continue;
            }

            CivilizationCreditEvent::updateOrCreate(
                [
                    'civilization_agent_id' => $agent->id,
                    'event_type' => 'sync_allocation',
                    'source_type' => $plan['source_type'],
                    'source_id' => $plan['source_id'],
                ],
                [
                    'amount' => round((float) $plan['amount'], 2),
                    'reason' => $plan['reason'],
                    'evidence' => [
                        'non_transferable' => true,
                        'sync_at' => now()->toDateTimeString(),
                    ],
                ],
            );

            $agent->update([
                'credits_balance' => round((float) $agent->creditEvents()->sum('amount'), 2),
            ]);
        }
    }

    private function preserveInstitutionalKnowledge(): void
    {
        if (Schema::hasTable('knowledge_claims')) {
            KnowledgeClaim::query()
                ->orderByDesc('confidence_score')
                ->orderByDesc('evidence_count')
                ->take(30)
                ->get()
                ->each(fn (KnowledgeClaim $claim): InstitutionalKnowledge => InstitutionalKnowledge::updateOrCreate(
                    ['knowledge_key' => 'claim:'.$claim->id],
                    [
                        'title' => $claim->title,
                        'knowledge_type' => $claim->claim_type,
                        'summary' => $claim->claim,
                        'confidence_score' => (float) $claim->confidence_score,
                        'evidence_count' => (int) $claim->evidence_count,
                        'preservation_status' => 'preserved',
                        'status' => $claim->status === 'deprecated' ? 'archived' : 'active',
                        'source_type' => KnowledgeClaim::class,
                        'source_id' => $claim->id,
                        'scope' => $claim->scope ?? [],
                        'metadata' => ['origin' => 'knowledge_graph'],
                    ],
                ));
        }

        $this->preserveDiscovery(FutureDiscovery::class, 'future_discoveries', 'future');
        $this->preserveDiscovery(MarketDiscovery::class, 'market_discoveries', 'market');
        $this->preserveDiscovery(GenomeDiscovery::class, 'genome_discoveries', 'genome');
    }

    private function preserveDiscovery(string $model, string $table, string $type): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        $model::query()
            ->orderByDesc('confidence_score')
            ->take(15)
            ->get()
            ->each(function ($discovery) use ($model, $type): void {
                InstitutionalKnowledge::updateOrCreate(
                    ['knowledge_key' => $type.':discovery:'.$discovery->id],
                    [
                        'title' => $discovery->title,
                        'knowledge_type' => $type.'_discovery',
                        'summary' => $discovery->discovery,
                        'confidence_score' => (float) $discovery->confidence_score,
                        'evidence_count' => (int) $discovery->evidence_count,
                        'preservation_status' => 'preserved',
                        'status' => $discovery->status ?? 'active',
                        'source_type' => $model,
                        'source_id' => $discovery->id,
                        'scope' => $discovery->scope ?? [],
                        'metadata' => $discovery->metadata ?? [],
                    ],
                );
            });
    }

    private function writeCollectiveMemory(): void
    {
        $latestMeta = Schema::hasTable('meta_audit_runs')
            ? MetaAuditRun::query()->latest()->first()
            : null;

        if ($latestMeta) {
            CivilizationMemory::updateOrCreate(
                ['memory_key' => 'meta_audit:'.$latestMeta->id],
                [
                    'memory_type' => 'self_correction',
                    'title' => 'Meta audit #'.$latestMeta->id,
                    'summary' => $latestMeta->summary ?: 'Meta Intelligence audit completed.',
                    'impact_score' => (float) $latestMeta->knowledge_health_score,
                    'source_type' => MetaAuditRun::class,
                    'source_id' => $latestMeta->id,
                    'tags' => ['meta', 'audit', 'knowledge_health'],
                    'evidence' => [
                        'audited_claims' => $latestMeta->audited_claims,
                        'contradictions' => $latestMeta->contradictions_found,
                        'unknown_zones' => $latestMeta->unknown_zones_found,
                    ],
                    'status' => 'active',
                ],
            );
        }

        CouncilDecision::query()
            ->latest()
            ->take(5)
            ->get()
            ->each(fn (CouncilDecision $decision): CivilizationMemory => CivilizationMemory::updateOrCreate(
                ['memory_key' => 'council:'.$decision->id],
                [
                    'memory_type' => 'council_decision',
                    'title' => $decision->title,
                    'summary' => $decision->rationale ?: 'Council decision recorded.',
                    'impact_score' => (float) $decision->consensus_score,
                    'source_type' => CouncilDecision::class,
                    'source_id' => $decision->id,
                    'tags' => ['council', $decision->final_decision, $decision->proposal_type],
                    'evidence' => $decision->metadata ?? [],
                    'status' => 'active',
                ],
            ));
    }

    private function syncCivilizationGoals(Collection $agents): void
    {
        $owners = $agents->keyBy('agent_key');
        $latestMeta = Schema::hasTable('meta_audit_runs') ? MetaAuditRun::query()->latest()->first() : null;
        $avgReputation = CivilizationAgent::query()->avg('reputation_score') ?: 50;
        $institutionalKnowledge = InstitutionalKnowledge::count();
        $unknownZones = Schema::hasTable('unknown_zones') ? UnknownZone::where('status', 'open')->count() : 0;
        $blindSpots = Schema::hasTable('blind_spots') ? BlindSpot::where('status', 'open')->count() : 0;

        $goals = [
            [
                'goal_key' => 'increase_adaptability',
                'owner' => 'role:evolution',
                'title' => 'Increase adaptability',
                'priority' => 82,
                'progress' => $avgReputation,
                'metrics' => ['avg_agent_reputation' => round((float) $avgReputation, 2)],
            ],
            [
                'goal_key' => 'reduce_unknown_zones',
                'owner' => 'role:meta',
                'title' => 'Reduce unknown zones',
                'priority' => $this->clamp(70 + ($unknownZones * 4)),
                'progress' => $this->clamp(100 - ($unknownZones * 12)),
                'metrics' => ['open_unknown_zones' => $unknownZones],
            ],
            [
                'goal_key' => 'improve_prediction_reliability',
                'owner' => 'role:prediction',
                'title' => 'Improve prediction reliability',
                'priority' => 78,
                'progress' => (float) ($latestMeta?->knowledge_health_score ?? 50),
                'metrics' => ['knowledge_health' => $latestMeta?->knowledge_health_score],
            ],
            [
                'goal_key' => 'expand_knowledge_coverage',
                'owner' => 'role:knowledge',
                'title' => 'Expand knowledge coverage',
                'priority' => $this->clamp(68 + ($blindSpots * 3)),
                'progress' => $this->clamp(min(100, $institutionalKnowledge * 2.5)),
                'metrics' => ['institutional_knowledge' => $institutionalKnowledge, 'blind_spots' => $blindSpots],
            ],
            [
                'goal_key' => 'protect_capital',
                'owner' => 'role:risk',
                'title' => 'Protect capital',
                'priority' => 90,
                'progress' => $this->clamp(100 - ($unknownZones * 6) - ($blindSpots * 4)),
                'metrics' => ['unknown_zones' => $unknownZones, 'blind_spots' => $blindSpots],
            ],
        ];

        foreach ($goals as $goal) {
            CivilizationGoal::updateOrCreate(
                ['goal_key' => $goal['goal_key']],
                [
                    'owner_agent_id' => $owners->get($goal['owner'])?->id,
                    'title' => $goal['title'],
                    'description' => 'Civilization-level objective tracked beyond direct profit.',
                    'priority_score' => round($this->clamp((float) $goal['priority']), 2),
                    'progress_score' => round($this->clamp((float) $goal['progress']), 2),
                    'status' => 'active',
                    'metrics' => $goal['metrics'],
                    'metadata' => ['source' => 'civilization_sync'],
                ],
            );
        }
    }

    private function deliberateCouncil(Collection $agents): CouncilDecision
    {
        $latestMeta = Schema::hasTable('meta_audit_runs') ? MetaAuditRun::query()->latest()->first() : null;
        $unknownZones = Schema::hasTable('unknown_zones') ? UnknownZone::where('status', 'open')->count() : 0;
        $blindSpots = Schema::hasTable('blind_spots') ? BlindSpot::where('status', 'open')->count() : 0;
        $riskScore = $this->clamp(($unknownZones * 10) + ($blindSpots * 6) + max(0, 70 - (float) ($latestMeta?->knowledge_health_score ?? 70)));
        $knowledgeGap = $this->clamp(($unknownZones * 12) + ($blindSpots * 10));
        $expectedValue = $this->clamp(55 + ($knowledgeGap * 0.35) + max(0, 80 - $riskScore) * 0.15);
        $proposalKey = 'research_allocation:'.now()->toDateString().':'.($latestMeta?->id ?? 0);

        $proposer = $agents->firstWhere('agent_key', 'role:research');
        $decision = CouncilDecision::updateOrCreate(
            ['proposal_key' => $proposalKey],
            [
                'proposed_by_agent_id' => $proposer?->id,
                'title' => 'Allocate research credits to highest uncertainty zones',
                'proposal_type' => 'research_allocation',
                'status' => 'decided',
                'expected_value_score' => round($expectedValue, 2),
                'risk_score' => round($riskScore, 2),
                'knowledge_gap_score' => round($knowledgeGap, 2),
                'metadata' => [
                    'meta_audit_id' => $latestMeta?->id,
                    'unknown_zones' => $unknownZones,
                    'blind_spots' => $blindSpots,
                    'knowledge_health' => $latestMeta?->knowledge_health_score,
                ],
            ],
        );

        foreach ($agents->whereIn('agent_key', ['role:research', 'role:knowledge', 'role:risk', 'role:prediction', 'role:meta', 'role:market']) as $agent) {
            $vote = $this->agentVote($agent, $expectedValue, $riskScore, $knowledgeGap, (float) ($latestMeta?->knowledge_health_score ?? 70));
            CouncilVote::updateOrCreate(
                [
                    'council_decision_id' => $decision->id,
                    'civilization_agent_id' => $agent->id,
                ],
                [
                    'vote' => $vote['vote'],
                    'weight' => $agent->vote_weight,
                    'confidence_score' => $vote['confidence'],
                    'reason' => $vote['reason'],
                    'evidence' => [
                        'expected_value' => round($expectedValue, 2),
                        'risk_score' => round($riskScore, 2),
                        'knowledge_gap' => round($knowledgeGap, 2),
                    ],
                ],
            );
        }

        $decision->load('votes');
        $yes = (float) $decision->votes->where('vote', 'yes')->sum('weight');
        $no = (float) $decision->votes->where('vote', 'no')->sum('weight');
        $veto = $decision->votes->contains('vote', 'veto');
        $total = max(1, (float) $decision->votes->sum('weight'));
        $quorum = round($this->clamp(($total / max(1, $agents->whereIn('agent_key', ['role:research', 'role:knowledge', 'role:risk', 'role:prediction', 'role:meta', 'role:market'])->sum('vote_weight'))) * 100), 2);
        $consensus = round($this->clamp(max($yes, $no) / $total * 100), 2);
        $final = $veto ? 'vetoed' : ($yes > $no && $quorum >= 60 ? 'approved' : 'rejected');

        $decision->update([
            'final_decision' => $final,
            'quorum_score' => $quorum,
            'consensus_score' => $consensus,
            'rationale' => $this->decisionRationale($final, $yes, $no, $veto, $riskScore, $knowledgeGap),
        ]);

        return $decision->fresh('votes.agent');
    }

    private function agentVote(CivilizationAgent $agent, float $expectedValue, float $riskScore, float $knowledgeGap, float $health): array
    {
        if ($agent->agent_key === 'role:risk' && $riskScore >= 72) {
            return ['vote' => 'no', 'confidence' => 86, 'reason' => 'Ruin and unknown exposure are too high for broad approval.'];
        }

        if ($agent->agent_key === 'role:meta' && $health < 55) {
            return ['vote' => 'veto', 'confidence' => 90, 'reason' => 'Knowledge health is too weak; proposal needs audit repair first.'];
        }

        if (in_array($agent->agent_key, ['role:research', 'role:knowledge', 'role:market'], true) && $knowledgeGap >= 25) {
            return ['vote' => 'yes', 'confidence' => 78, 'reason' => 'Knowledge gap is material and should be converted into research.'];
        }

        if ($agent->agent_key === 'role:prediction' && $expectedValue >= 60 && $riskScore < 75) {
            return ['vote' => 'yes', 'confidence' => 72, 'reason' => 'Scenario planning benefits from reducing uncertainty.'];
        }

        return $expectedValue > $riskScore
            ? ['vote' => 'yes', 'confidence' => 64, 'reason' => 'Expected information gain is higher than current risk.']
            : ['vote' => 'no', 'confidence' => 66, 'reason' => 'Risk pressure exceeds expected information gain.'];
    }

    private function decisionRationale(string $final, float $yes, float $no, bool $veto, float $riskScore, float $knowledgeGap): string
    {
        if ($veto) {
            return 'Meta veto applied because knowledge health is below institutional safety threshold.';
        }

        return "Council {$final}: yes weight {$yes}, no weight {$no}, risk {$riskScore}%, knowledge gap {$knowledgeGap}%.";
    }

    private function roleReputation(string $role): float
    {
        return match ($role) {
            'knowledge' => $this->clamp(55 + $this->countTable('knowledge_claims') * 0.8),
            'market' => $this->clamp(55 + $this->countTable('market_discoveries') * 3),
            'evolution' => $this->clamp(55 + $this->countTable('genome_discoveries') * 4),
            'prediction' => $this->clamp(55 + $this->countTable('future_discoveries') * 5),
            'meta' => (float) (MetaAuditRun::query()->latest()->value('knowledge_health_score') ?? 55),
            'risk' => $this->clamp(80 - ($this->countTable('unknown_zones') * 3)),
            'research' => $this->clamp(60 + $this->countTable('blind_spots') * 2),
            default => 55,
        };
    }

    private function roleContribution(string $role): float
    {
        return match ($role) {
            'knowledge' => $this->clamp($this->countTable('knowledge_claims') * 2),
            'market' => $this->clamp(50 + $this->countTable('market_discoveries') * 4),
            'evolution' => $this->clamp(50 + $this->countTable('genome_discoveries') * 5),
            'prediction' => $this->clamp(50 + $this->countTable('future_discoveries') * 6),
            'meta' => $this->clamp(45 + $this->countTable('meta_audit_runs') * 12),
            'risk' => $this->clamp(70 - $this->countTable('unknown_zones') * 4),
            'research' => $this->clamp(55 + $this->countTable('blind_spots') * 5),
            default => 50,
        };
    }

    private function roleTrust(string $role): float
    {
        $health = (float) (MetaAuditRun::query()->latest()->value('knowledge_health_score') ?? 65);

        return match ($role) {
            'meta' => $this->clamp($health),
            'risk' => $this->clamp(65 + max(0, 75 - $this->countTable('unknown_zones') * 8) * 0.2),
            default => $this->clamp(50 + $health * 0.35),
        };
    }

    private function voteWeight(float $reputation): float
    {
        return round($this->clamp(0.75 + ($reputation / 100), 0.75, 1.75), 2);
    }

    private function boundedCount(string $model, string $table, float $multiplier, float $max): float
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }

        return round(min($max, $model::query()->count() * $multiplier), 2);
    }

    private function riskCredits(): float
    {
        $unknowns = $this->countTable('unknown_zones');
        $stressTests = $this->countTable('future_stress_tests');

        return round($this->clamp(($stressTests * 45) + max(0, 12 - $unknowns) * 50, 0, 1500), 2);
    }

    private function researchCredits(): float
    {
        $blindSpots = $this->countTable('blind_spots');
        $claims = $this->countTable('knowledge_claims');

        return round($this->clamp(($blindSpots * 85) + ($claims * 4), 0, 1400), 2);
    }

    private function countTable(string $table): int
    {
        return Schema::hasTable($table) ? (int) \DB::table($table)->count() : 0;
    }

    private function clamp(float $value, float $min = 0, float $max = 100): float
    {
        return max($min, min($max, $value));
    }
}
