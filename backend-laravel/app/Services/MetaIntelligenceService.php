<?php

namespace App\Services;

use App\Models\AgentBelief;
use App\Models\BeliefDecayEvent;
use App\Models\BlindSpot;
use App\Models\KnowledgeAudit;
use App\Models\KnowledgeClaim;
use App\Models\KnowledgeContradiction;
use App\Models\KnowledgeHealthScore;
use App\Models\MarketStateSnapshot;
use App\Models\MetaAuditRun;
use App\Models\SelfCritique;
use App\Models\UnknownZone;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class MetaIntelligenceService
{
    public function runAudit(): ?MetaAuditRun
    {
        if (! Schema::hasTable('meta_audit_runs')) {
            return null;
        }

        $run = MetaAuditRun::create([
            'status' => 'running',
            'started_at' => now(),
        ]);

        $knowledgeAudits = $this->auditKnowledgeClaims($run);
        $beliefDecayEvents = $this->recordBeliefDecay($run);
        $contradictions = $this->detectContradictions($run);
        $unknownZones = $this->detectUnknownZones($run);
        $blindSpots = $this->findBlindSpots($run);
        $health = $this->scoreKnowledgeHealth($run, $knowledgeAudits, $beliefDecayEvents, $contradictions, $unknownZones, $blindSpots);
        $critique = $this->writeSelfCritique($run, $knowledgeAudits, $contradictions, $unknownZones, $blindSpots, $health);

        $run->update([
            'status' => 'success',
            'finished_at' => now(),
            'knowledge_health_score' => $health->overall_score,
            'audited_claims' => $knowledgeAudits->count(),
            'decayed_beliefs' => $beliefDecayEvents->count(),
            'contradictions_found' => $contradictions->count(),
            'unknown_zones_found' => $unknownZones->count(),
            'blind_spots_found' => $blindSpots->count(),
            'summary' => $critique->critique,
            'metrics' => [
                'health_components' => $health->components,
                'critique_id' => $critique->id,
                'non_destructive' => true,
            ],
        ]);

        return $run->fresh(['healthScore', 'selfCritiques']);
    }

    private function auditKnowledgeClaims(MetaAuditRun $run): Collection
    {
        if (! Schema::hasTable('knowledge_claims')) {
            return collect();
        }

        return KnowledgeClaim::query()
            ->latest('last_seen_at')
            ->take(250)
            ->get()
            ->map(function (KnowledgeClaim $claim) use ($run): KnowledgeAudit {
                $ageDays = $this->ageDays($claim->last_seen_at ?? $claim->updated_at ?? $claim->created_at);
                $evidenceCount = (int) $claim->evidence_count;
                $original = (float) $claim->confidence_score;
                $evidencePenalty = $evidenceCount < 5 ? (5 - $evidenceCount) * 4 : 0;
                $agePenalty = min(36, $ageDays / 30 * 5);
                $statusPenalty = $claim->status === 'provisional' ? 5 : 0;
                $decay = round($this->clamp($agePenalty + $evidencePenalty + $statusPenalty, 0, 55), 2);
                $audited = round($this->clamp($original - $decay), 2);
                $verdict = $this->auditVerdict($audited, $decay, $ageDays, $evidenceCount);

                return KnowledgeAudit::create([
                    'meta_audit_run_id' => $run->id,
                    'knowledge_claim_id' => $claim->id,
                    'audit_type' => 'knowledge_audit',
                    'original_confidence' => $original,
                    'audited_confidence' => $audited,
                    'decay_amount' => $decay,
                    'verdict' => $verdict,
                    'recommended_action' => $this->auditAction($verdict),
                    'reason' => "Claim '{$claim->title}' was audited for age, evidence size and provisional status.",
                    'evidence' => [
                        'age_days' => $ageDays,
                        'evidence_count' => $evidenceCount,
                        'status' => $claim->status,
                        'scope' => $claim->scope,
                    ],
                ]);
            });
    }

    private function recordBeliefDecay(MetaAuditRun $run): Collection
    {
        if (! Schema::hasTable('agent_beliefs')) {
            return collect();
        }

        return AgentBelief::query()
            ->latest('last_evidence_at')
            ->take(250)
            ->get()
            ->map(function (AgentBelief $belief) use ($run): BeliefDecayEvent {
                $ageDays = $this->ageDays($belief->last_evidence_at ?? $belief->updated_at ?? $belief->created_at);
                $original = (float) $belief->score;
                $sampleSize = max(1, (int) $belief->sample_size);
                $failureRate = (int) $belief->failed_count / max(1, (int) $belief->confirmed_count + (int) $belief->failed_count);
                $ageDecay = min(32, $ageDays / 30 * 4);
                $failureDecay = $failureRate >= 0.35 ? min(18, $failureRate * 28) : 0;
                $lowSampleDecay = $sampleSize < 10 ? (10 - $sampleSize) * 1.6 : 0;
                $decay = round($this->clamp($ageDecay + $failureDecay + $lowSampleDecay, 0, 55), 2);
                $decayedScore = round($this->clamp($original - $decay), 2);
                $reasonCode = $ageDays > 90 ? 'aging' : ($failureDecay > 0 ? 'recent_failures' : 'low_evidence');

                return BeliefDecayEvent::create([
                    'meta_audit_run_id' => $run->id,
                    'agent_belief_id' => $belief->id,
                    'strategy' => $belief->strategy,
                    'belief_key' => $belief->belief_key,
                    'original_score' => $original,
                    'decayed_score' => $decayedScore,
                    'decay_amount' => $decay,
                    'reason_code' => $reasonCode,
                    'reason' => 'Belief was re-scored for freshness, failure pressure and evidence size without overwriting the original belief.',
                    'evidence' => [
                        'age_days' => $ageDays,
                        'sample_size' => $sampleSize,
                        'confirmed_count' => (int) $belief->confirmed_count,
                        'failed_count' => (int) $belief->failed_count,
                        'failure_rate' => round($failureRate, 4),
                    ],
                ]);
            });
    }

    private function detectContradictions(MetaAuditRun $run): Collection
    {
        if (! Schema::hasTable('knowledge_claims')) {
            return collect();
        }

        $claims = KnowledgeClaim::query()
            ->where('confidence_score', '>=', 45)
            ->latest('last_seen_at')
            ->take(300)
            ->get();

        $created = collect();

        $claims->groupBy(fn (KnowledgeClaim $claim): string => $this->scopeKey($claim))
            ->each(function (EloquentCollection|Collection $group) use ($run, $created): void {
                $items = $group->values();

                for ($i = 0; $i < $items->count(); $i++) {
                    for ($j = $i + 1; $j < $items->count(); $j++) {
                        $claimA = $items[$i];
                        $claimB = $items[$j];
                        $directionA = $this->claimDirection($claimA);
                        $directionB = $this->claimDirection($claimB);

                        if (! $directionA || ! $directionB || $directionA === $directionB) {
                            continue;
                        }

                        $severity = round($this->clamp(((float) $claimA->confidence_score + (float) $claimB->confidence_score) / 2), 2);
                        $created->push(KnowledgeContradiction::create([
                            'meta_audit_run_id' => $run->id,
                            'claim_a_id' => $claimA->id,
                            'claim_b_id' => $claimB->id,
                            'contradiction_type' => 'directional_conflict',
                            'severity_score' => $severity,
                            'status' => 'open',
                            'summary' => "Conflict: '{$claimA->title}' vs '{$claimB->title}' in the same knowledge scope.",
                            'evidence' => [
                                'scope_key' => $this->scopeKey($claimA),
                                'claim_a_direction' => $directionA,
                                'claim_b_direction' => $directionB,
                                'claim_a_confidence' => (float) $claimA->confidence_score,
                                'claim_b_confidence' => (float) $claimB->confidence_score,
                            ],
                        ]));

                        return;
                    }
                }
            });

        return $created;
    }

    private function detectUnknownZones(MetaAuditRun $run): Collection
    {
        if (! Schema::hasTable('market_state_snapshots')) {
            return collect();
        }

        return MarketStateSnapshot::query()
            ->with(['marketSpecies', 'genome.similarityMatches'])
            ->latest('time')
            ->take(30)
            ->get()
            ->filter(function (MarketStateSnapshot $snapshot): bool {
                $maxSimilarity = (float) ($snapshot->genome?->similarityMatches?->max('similarity_score') ?? 0);
                $claimCount = $this->claimCountForSnapshot($snapshot);

                return $maxSimilarity < 35 || $claimCount < 2;
            })
            ->map(function (MarketStateSnapshot $snapshot) use ($run): UnknownZone {
                $maxSimilarity = (float) ($snapshot->genome?->similarityMatches?->max('similarity_score') ?? 0);
                $claimCount = $this->claimCountForSnapshot($snapshot);
                $uncertainty = round($this->clamp((100 - $maxSimilarity) + max(0, 2 - $claimCount) * 8), 2);

                return UnknownZone::create([
                    'meta_audit_run_id' => $run->id,
                    'symbol' => $snapshot->symbol,
                    'timeframe' => $snapshot->timeframe,
                    'market_state' => $snapshot->market_state,
                    'market_species' => $snapshot->marketSpecies?->name,
                    'similarity_score' => round($maxSimilarity, 2),
                    'uncertainty_score' => $uncertainty,
                    'status' => 'open',
                    'reason' => 'Historical similarity or Knowledge Graph evidence is insufficient for this market reality.',
                    'evidence' => [
                        'snapshot_id' => $snapshot->id,
                        'market_species_id' => $snapshot->market_species_id,
                        'claim_count' => $claimCount,
                        'liquidity_state' => $snapshot->liquidity_state,
                        'momentum_state' => $snapshot->momentum_state,
                        'structure_state' => $snapshot->structure_state,
                    ],
                ]);
            })
            ->values();
    }

    private function findBlindSpots(MetaAuditRun $run): Collection
    {
        if (! Schema::hasTable('market_state_snapshots')) {
            return collect();
        }

        $requiredContexts = [
            ['key' => 'low_range_high_volatility', 'label' => 'Low liquidity range under high volatility', 'liquidity' => 'low_proxy', 'market' => 'range', 'momentum' => 'strong'],
            ['key' => 'low_panic_breakout', 'label' => 'Low liquidity panic breakout', 'liquidity' => 'low_proxy', 'market' => 'panic', 'structure' => 'breakout'],
            ['key' => 'compression_trap', 'label' => 'Compression trap transition', 'market' => 'compression', 'structure' => 'trap'],
            ['key' => 'calm_breakout', 'label' => 'Calm breakout follow-through', 'liquidity' => 'normal_proxy', 'structure' => 'breakout'],
            ['key' => 'trend_range_shift', 'label' => 'Trend to range regime shift', 'market' => 'range', 'momentum' => 'weak'],
        ];

        return collect($requiredContexts)
            ->map(function (array $context) use ($run): ?BlindSpot {
                $query = MarketStateSnapshot::query();

                if (isset($context['liquidity'])) {
                    $query->where('liquidity_state', $context['liquidity']);
                }
                if (isset($context['market'])) {
                    $query->where('market_state', $context['market']);
                }
                if (isset($context['momentum'])) {
                    $query->where('momentum_state', $context['momentum']);
                }
                if (isset($context['structure'])) {
                    $query->where('structure_state', $context['structure']);
                }

                $samples = $query->count();

                if ($samples >= 3) {
                    return null;
                }

                $priority = round($this->clamp(92 - ($samples * 18)), 2);

                return BlindSpot::create([
                    'meta_audit_run_id' => $run->id,
                    'spot_key' => $context['key'],
                    'label' => $context['label'],
                    'priority_score' => $priority,
                    'status' => 'open',
                    'reason' => 'Meta Engine found insufficient coverage for this market condition combination.',
                    'coverage' => [
                        'samples_found' => $samples,
                        'minimum_required' => 3,
                        'context' => $context,
                    ],
                    'suggested_research' => [
                        'action' => 'queue_research_ticket',
                        'target' => $context['key'],
                        'goal' => 'Collect or replay more evidence before trusting conclusions in this zone.',
                    ],
                ]);
            })
            ->filter()
            ->values();
    }

    private function scoreKnowledgeHealth(
        MetaAuditRun $run,
        Collection $knowledgeAudits,
        Collection $beliefDecayEvents,
        Collection $contradictions,
        Collection $unknownZones,
        Collection $blindSpots,
    ): KnowledgeHealthScore {
        $auditedCount = max(1, $knowledgeAudits->count());
        $stableRatio = $knowledgeAudits->where('verdict', 'stable')->count() / $auditedCount;
        $agingRatio = $knowledgeAudits->whereIn('verdict', ['aging', 'stale'])->count() / $auditedCount;
        $beliefDecayPressure = min(30, (float) $beliefDecayEvents->avg('decay_amount'));
        $contradictionPressure = min(35, $contradictions->count() * 9);
        $unknownPressure = min(28, $unknownZones->count() * 6);
        $blindSpotPressure = min(24, $blindSpots->count() * 5);
        $freshScore = round($this->clamp(50 + ($stableRatio * 45) - ($agingRatio * 25)), 2);
        $overall = round($this->clamp(100 - $beliefDecayPressure - $contradictionPressure - $unknownPressure - $blindSpotPressure + ($stableRatio * 12)), 2);

        return KnowledgeHealthScore::create([
            'meta_audit_run_id' => $run->id,
            'overall_score' => $overall,
            'fresh_discoveries_score' => $freshScore,
            'aging_discoveries_score' => round($this->clamp($agingRatio * 100), 2),
            'contradiction_score' => round($this->clamp($contradictionPressure), 2),
            'unknown_zone_score' => round($this->clamp($unknownPressure), 2),
            'blind_spot_score' => round($this->clamp($blindSpotPressure), 2),
            'components' => [
                'audited_claims' => $knowledgeAudits->count(),
                'stable_ratio' => round($stableRatio, 4),
                'aging_ratio' => round($agingRatio, 4),
                'belief_decay_pressure' => round($beliefDecayPressure, 2),
                'contradictions' => $contradictions->count(),
                'unknown_zones' => $unknownZones->count(),
                'blind_spots' => $blindSpots->count(),
            ],
        ]);
    }

    private function writeSelfCritique(
        MetaAuditRun $run,
        Collection $knowledgeAudits,
        Collection $contradictions,
        Collection $unknownZones,
        Collection $blindSpots,
        KnowledgeHealthScore $health,
    ): SelfCritique {
        $title = 'Knowledge base needs routine calibration';
        $critique = 'Meta Engine found no dominant failure mode; keep auditing knowledge freshness and coverage.';
        $action = 'Keep weekly audit active and avoid raising confidence without new evidence.';
        $severity = 35.0;

        if ($contradictions->isNotEmpty()) {
            $title = 'Knowledge Graph contains conflicting claims';
            $critique = 'The system is holding opposite conclusions inside the same scope, so affected claims should be reviewed before they influence future planning.';
            $action = 'Open research tickets for high-severity contradictions and lower confidence for dependent forecasts.';
            $severity = max($severity, (float) $contradictions->max('severity_score'));
        } elseif ($unknownZones->isNotEmpty()) {
            $title = 'Current market contains unknown territory';
            $critique = 'Some recent market realities have weak historical similarity or too little graph evidence, so trade sizing should stay conservative.';
            $action = 'Collect more examples for unknown zones and require stronger confirmation before scaling exposure.';
            $severity = max($severity, (float) $unknownZones->max('uncertainty_score'));
        } elseif ($blindSpots->isNotEmpty()) {
            $title = 'Research coverage has blind spots';
            $critique = 'Several important regime combinations are under-sampled, which can make conclusions overconfident outside familiar conditions.';
            $action = 'Prioritize replay or training sessions for the highest-priority blind spots.';
            $severity = max($severity, (float) $blindSpots->max('priority_score'));
        } elseif ($knowledgeAudits->whereIn('verdict', ['aging', 'stale', 'challenged'])->isNotEmpty()) {
            $title = 'Knowledge is aging';
            $critique = 'Some claims lost audited confidence because the evidence is old, thin, or still provisional.';
            $action = 'Refresh aging claims with new sessions before treating them as validated knowledge.';
            $severity = max($severity, (float) $knowledgeAudits->max('decay_amount') + 35);
        }

        return SelfCritique::create([
            'meta_audit_run_id' => $run->id,
            'title' => $title,
            'critique' => $critique,
            'evidence_summary' => "Health {$health->overall_score}%, contradictions {$contradictions->count()}, unknown zones {$unknownZones->count()}, blind spots {$blindSpots->count()}.",
            'recommended_action' => $action,
            'severity_score' => round($this->clamp($severity), 2),
            'status' => $health->overall_score < 55 ? 'urgent' : 'open',
            'metadata' => [
                'health_score_id' => $health->id,
                'audited_claims' => $knowledgeAudits->count(),
            ],
        ]);
    }

    private function auditVerdict(float $auditedConfidence, float $decay, int $ageDays, int $evidenceCount): string
    {
        if ($auditedConfidence < 45) {
            return 'challenged';
        }
        if ($ageDays > 120) {
            return 'stale';
        }
        if ($decay >= 18) {
            return 'aging';
        }
        if ($evidenceCount < 5) {
            return 'weak_evidence';
        }

        return 'stable';
    }

    private function auditAction(string $verdict): string
    {
        return match ($verdict) {
            'challenged' => 'quarantine_review',
            'stale', 'aging' => 'refresh_evidence',
            'weak_evidence' => 'collect_more_samples',
            default => 'monitor',
        };
    }

    private function scopeKey(KnowledgeClaim $claim): string
    {
        $scope = $claim->scope ?? [];
        $parts = [
            $claim->claim_type,
            $scope['strategy'] ?? null,
            $scope['market_species'] ?? null,
            $scope['market_state'] ?? null,
            $scope['belief_key'] ?? null,
            $scope['reason_code'] ?? null,
        ];

        return implode('|', array_map(fn ($part): string => strtolower((string) ($part ?: '*')), $parts));
    }

    private function claimDirection(KnowledgeClaim $claim): ?string
    {
        $text = strtolower($claim->title.' '.$claim->claim.' '.json_encode($claim->metadata ?? []));
        $positive = ['performs better', 'superior', 'validated', 'profitable', 'strong', 'works', 'survived'];
        $negative = ['struggles', 'failure', 'death', 'poor', 'weak', 'collapse', 'failed', 'dangerous'];

        foreach ($positive as $needle) {
            if (str_contains($text, $needle)) {
                return 'positive';
            }
        }

        foreach ($negative as $needle) {
            if (str_contains($text, $needle)) {
                return 'negative';
            }
        }

        return null;
    }

    private function claimCountForSnapshot(MarketStateSnapshot $snapshot): int
    {
        if (! Schema::hasTable('knowledge_claims')) {
            return 0;
        }

        return KnowledgeClaim::query()
            ->where(function ($query) use ($snapshot): void {
                $query->where('claim', 'like', '%'.$snapshot->market_state.'%');

                if ($snapshot->marketSpecies?->name) {
                    $query->orWhere('claim', 'like', '%'.$snapshot->marketSpecies->name.'%');
                }
            })
            ->count();
    }

    private function ageDays($date): int
    {
        if (! $date) {
            return 365;
        }

        return max(0, (int) $date->diffInDays(now()));
    }

    private function clamp(float $value, float $min = 0, float $max = 100): float
    {
        return max($min, min($max, $value));
    }
}
