<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LabGeneration;

/** Formal, evidence-only admission decision for a coverage rescue fork. */
class CoverageRescueAuditService
{
    public const PROTOCOL = 'g102_coverage_rescue_audit_v1';

    public function __construct(private LabImmutableEvidenceService $evidence) {}

    public function audit(string $symbol = 'XAUUSD', ?int $generationNumber = null): array
    {
        $generationQuery = LabGeneration::query()->with('laboratory')
            ->whereHas('laboratory', fn ($q) => $q->where('symbol', strtoupper($symbol))->where('timeframe', 'H1'));
        if ($generationNumber !== null) {
            $generationQuery->where('generation', $generationNumber);
        } else {
            $generationQuery->where('status', 'completed');
        }
        $generations = $generationQuery->orderByDesc('generation')->get();
        if ($generations->isEmpty()) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException)->setModel(LabGeneration::class);
        }

        // Coverage rescue must follow the evidence stream, not a historical
        // agent ID. A completed generation can be a routine screening
        // generation with no coverage passport at all, while the strongest
        // still-valid challenger may live in an older completed generation.
        // Search newest-to-oldest and stop at the newest generation that has
        // an eligible, cost-aware sparse-coverage parent. This keeps the
        // rescue dynamic without making the latest empty generation a false
        // blocker.
        $generation = null;
        $coverageCandidates = collect();
        $searchedGenerations = [];
        foreach ($generations as $candidateGeneration) {
            $searchedGenerations[] = [
                'generation_id' => $candidateGeneration->id,
                'generation' => $candidateGeneration->generation,
                'status' => $candidateGeneration->status,
            ];
            $candidates = $this->coverageCandidatesForGeneration($candidateGeneration);
            if ($generationNumber !== null || $candidates->isNotEmpty()) {
                $generation = $candidateGeneration;
                $coverageCandidates = $candidates;
                break;
            }
        }
        $generation ??= $generations->first();

        $parents = $coverageCandidates->pluck('agent');
        $cells = [];
        $certified = [];
        foreach ($coverageCandidates as $candidate) {
            $parent = $candidate['agent'];
            $result = $candidate['result'];
            foreach ((array) data_get($result, 'certified_coverage_passport.cells', []) as $key => $cell) {
                if (in_array(data_get($cell, 'trade_permission'), ['CERTIFIED', 'TRADE'], true)
                    || in_array(data_get($cell, 'abstain_permission'), ['CERTIFIED', 'ABSTAIN'], true)) {
                    $certified[$key] = true;
                    continue;
                }
                $cells[$key] = [
                    'key' => $key, 'regime' => data_get($cell, 'regime'), 'volatility' => data_get($cell, 'volatility'),
                    'session_utc_hour' => (string) data_get($cell, 'session_utc_hour'), 'direction' => data_get($cell, 'direction'),
                    'trade_count' => (int) data_get($cell, 'trade_count', 0),
                    'missed_profitable_opportunities' => (int) data_get($cell, 'missed_profitable_opportunities', 0),
                    'parent_agent_id' => $parent->id, 'parent_model_version_id' => $parent->model_version_id,
                ];
            }
        }
        $edgeParents = $parents;
        $eligible = $parents->isNotEmpty() && $edgeParents->isNotEmpty() && $cells !== [];
        return [
            'protocol' => self::PROTOCOL, 'generation_id' => $generation->id, 'generation' => $generation->generation,
            'symbol' => $generation->laboratory->symbol, 'timeframe' => $generation->laboratory->timeframe,
            'parent_agent_ids' => $parents->pluck('id')->all(), 'parent_model_version_ids' => $parents->pluck('model_version_id')->all(),
            'searched_generations' => $searchedGenerations,
            'edge_evidence' => ['pf_stress_regime_parents' => $edgeParents->pluck('id')->all(), 'monthly_and_regime' => 'existing full-replay evidence retained; gates unchanged', 'selection' => $generationNumber !== null
                ? 'explicit_generation_valid_full_replay_parent_with_uncertified_cells'
                : 'newest_generation_with_valid_full_replay_parent_with_uncertified_cells'],
            'failure' => 'operating_envelope_coverage_sparse', 'certified_cells' => count($certified),
            'uncertified_cells' => array_values($cells), 'coverage_recall' => null,
            'eligible' => $eligible,
            'rule' => 'Rescue is allowed only for sparse certified coverage. PF, entry, exit, non-target lanes and promotion gates are not relaxed.',
        ];
    }

    /** @return \Illuminate\Support\Collection<int, array<string, mixed>> */
    private function coverageCandidatesForGeneration(LabGeneration $generation)
    {
        return LabAgent::query()->with('modelVersion.marketPerformances')
            ->where('lab_generation_id', $generation->id)
            ->get()
            ->map(function (LabAgent $agent): array {
                $performance = $agent->modelVersion?->marketPerformances
                    ?->where('symbol', $agent->symbol)
                    ?->where('timeframe', $agent->timeframe)
                    ?->sortByDesc('id')->first();
                $result = (array) ($performance?->metrics ?? data_get($agent->modelVersion?->metadata, 'last_result', []));
                $coverage = (array) data_get($result, 'certified_coverage_passport', []);
                return [
                    'agent' => $agent,
                    'performance' => $performance,
                    'result' => $result,
                    'coverage' => $coverage,
                    'uncertified' => (int) data_get($coverage, 'uncertified_cells', 0),
                    'edge' => (float) data_get($result, 'profit_factor', 0) >= 1.3
                        && (int) data_get($result, 'total_trades', data_get($result, 'sample_count', 0)) >= 30
                        && (float) data_get($result, 'pf_attribution.stress_cost.profit_factor', 0) >= 1.1
                        && count((array) data_get($result, 'regime_performance', [])) >= 3,
                ];
            })
            ->filter(fn (array $row): bool => $row['performance'] !== null
                && $row['performance']->evidence_status === 'valid'
                && in_array((string) $row['performance']->status, ['champion', 'challenger', 'forward_validated', 'paper'], true)
                && ! (bool) data_get($row['result'], 'is_overfit', true)
                && data_get($row['coverage'], 'status') === 'assessed'
                && $row['uncertified'] > 0
                && $row['edge'])
            ->sortByDesc(fn (array $row): array => [$row['uncertified'], (float) data_get($row['result'], 'profit_factor', 0)])
            ->take(4)
            ->values();
    }

    public function seal(array $audit): void
    {
        $generation = LabGeneration::findOrFail((int) $audit['generation_id']);
        $agent = LabAgent::find((int) ($audit['parent_agent_ids'][0] ?? 0));
        $this->evidence->recordArtifact(null, 'coverage_rescue_audit', $audit, ['protocol' => self::PROTOCOL, 'eligible' => $audit['eligible']], $agent);
        $context = (array) $generation->trigger_context;
        $context['coverage_rescue_audit'] = $audit;
        $generation->update(['trigger_context' => $context]);
    }
}
