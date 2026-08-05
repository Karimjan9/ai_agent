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
        $generation = $generationQuery->orderByDesc('generation')->firstOrFail();

        // Coverage rescue must follow the evidence stream, not a historical
        // agent ID. The old G102 constants silently ignored the later G104
        // breakout parent that actually carried the eight uncertified cells.
        $coverageCandidates = LabAgent::query()->with('modelVersion.marketPerformances')
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
                && data_get($row['coverage'], 'status') === 'assessed'
                && $row['uncertified'] > 0
                && $row['edge'])
            ->sortByDesc(fn (array $row): array => [$row['uncertified'], (float) data_get($row['result'], 'profit_factor', 0)])
            ->take(4)
            ->values();

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
            'edge_evidence' => ['pf_stress_regime_parents' => $edgeParents->pluck('id')->all(), 'monthly_and_regime' => 'existing full-replay evidence retained; gates unchanged', 'selection' => 'dynamic_valid_full_replay_parent_with_uncertified_cells'],
            'failure' => 'operating_envelope_coverage_sparse', 'certified_cells' => count($certified),
            'uncertified_cells' => array_values($cells), 'coverage_recall' => null,
            'eligible' => $eligible,
            'rule' => 'Rescue is allowed only for sparse certified coverage. PF, entry, exit, non-target lanes and promotion gates are not relaxed.',
        ];
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
