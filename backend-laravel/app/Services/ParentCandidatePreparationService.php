<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\ModelMarketPerformance;
use App\Models\ParentCandidatePreparation;
use Illuminate\Support\Facades\Schema;

/** Prepares only council agents that are close to the strict parent passport. */
class ParentCandidatePreparationService
{
    public const PROTOCOL = 'parent_candidate_preparation_v1';

    /** @return array<string, mixed> */
    public function prepare(
        string $symbol,
        string $timeframe,
        int $limit = 20,
        bool $apply = false,
    ): array {
        if (! Schema::hasTable('lab_parent_candidate_preparations')) {
            return ['available' => false, 'candidate_count' => 0, 'ideas' => 0];
        }

        $performances = ModelMarketPerformance::query()
            ->with('modelVersion')
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->where('evidence_status', 'valid')
            ->whereIn('status', ['champion', 'challenger', 'forward_validated', 'paper'])
            ->latest('id')
            ->limit(max(1, min(100, $limit * 4)))
            ->get()
            ->filter(fn (ModelMarketPerformance $performance): bool => $this->isParentCandidate($performance))
            ->take(max(1, $limit))
            ->values();

        $ideas = [];
        foreach ($performances as $performance) {
            $model = $performance->modelVersion;
            $agent = LabAgent::query()->where('model_version_id', $model->id)->latest('id')->first();
            $role = $this->councilRole($model->metadata);
            foreach ($this->ideas($performance, $agent, $role) as $idea) {
                $key = hash('sha256', json_encode([
                    self::PROTOCOL, $model->id, $performance->id, $idea['idea_type'], $idea['gene'],
                ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
                $ideas[] = [
                    'preparation_key' => $key,
                    'model_version_id' => (int) $model->id,
                    'lab_agent_id' => $agent?->id,
                    'symbol' => strtoupper($symbol),
                    'timeframe' => strtoupper($timeframe),
                    'strategy_family' => (string) $performance->strategy_family,
                    'council_role' => $role,
                    'status' => 'planned',
                    'idea_type' => $idea['idea_type'],
                    'idea' => [
                        ...$idea,
                        'protocol' => self::PROTOCOL,
                        'parent_gate_bypass' => false,
                        'promotion_evidence' => false,
                    ],
                    'required_evidence' => [
                        'same_snapshot' => true,
                        'same_execution_contract' => true,
                        'non_target_regression' => false,
                        'independent_forward_evidence' => true,
                        'counterfactual_branches' => ['autonomous', 'mentored', 'ablated'],
                        'parent_passport_recheck' => true,
                    ],
                    'source_metrics' => [
                        'performance_id' => (int) $performance->id,
                        'profit_factor' => (float) data_get($performance->metrics, 'profit_factor', 0),
                        'sample_count' => (int) $performance->sample_count,
                        'rolling_windows_count' => (int) $performance->rolling_windows_count,
                        'rolling_forward_wins' => (int) $performance->rolling_forward_wins,
                        'promotion_evidence' => false,
                    ],
                    'promotion_evidence' => false,
                ];
            }
        }

        if ($apply) {
            foreach ($ideas as $idea) {
                ParentCandidatePreparation::query()->firstOrCreate(
                    ['preparation_key' => $idea['preparation_key']],
                    $idea,
                );
            }
        }

        return [
            'available' => true,
            'protocol' => self::PROTOCOL,
            'symbol' => strtoupper($symbol),
            'timeframe' => strtoupper($timeframe),
            'apply' => $apply,
            'candidate_count' => $performances->count(),
            'ideas' => count($ideas),
            'prepared_model_version_ids' => $performances->pluck('model_version_id')->map(fn ($id): int => (int) $id)->values()->all(),
            'promotion_evidence' => false,
        ];
    }

    private function isParentCandidate(ModelMarketPerformance $performance): bool
    {
        $model = $performance->modelVersion;
        if (! $model || (bool) data_get($model->metadata, 'shadow_research_lane.shadow_only', false)) return false;
        $metadata = (array) $model->metadata;
        $stage = (string) data_get($metadata, 'evolution_stage.stage', '');
        if (in_array($stage, ['screen_validated_seed', 'skill_mentor', 'screen_validated_control', 'repair_anchor', 'repair_anchor_control'], true)) {
            return false;
        }
        $repairAnchor = (array) data_get($metadata, 'repair_anchor', []);
        if ($repairAnchor !== [] && data_get($repairAnchor, 'parent_eligible_after_confirmation') !== true) return false;
        if (data_get($metadata, 'skill_mentor.status') === 'confirmed') return false;
        $role = $this->councilRole($model->metadata);
        if ($role === null) return false;
        $metrics = (array) $performance->metrics;
        $bootstrap = data_get($metrics, 'statistical_evidence.edge_quality.bootstrap_pf', []);
        $bootstrapPass = data_get($bootstrap, 'status') !== 'assessed'
            || (float) data_get($bootstrap, 'pf_5_percentile_lower_bound', 0) >= 1.1;
        $edge = (array) data_get($metrics, 'statistical_evidence.edge_quality', []);
        $regimePass = ! (bool) data_get($edge, 'worst_regime_sampled', false)
            || (float) data_get($edge, 'worst_regime_pf', 0) >= 1.0;

        return (float) data_get($metrics, 'profit_factor', 0) >= 1.3
            && (float) data_get($metrics, 'max_drawdown_percent', data_get($metrics, 'max_drawdown', 100)) <= 15
            && (float) data_get($metrics, 'monte_carlo.risk_of_ruin_percent', 100) <= 10
            && ! (bool) data_get($metrics, 'is_overfit', true)
            && (int) $performance->sample_count >= 30
            && (int) $performance->rolling_windows_count >= 3
            && (int) $performance->rolling_forward_wins >= 3
            && $bootstrapPass && $regimePass
            && data_get($metrics, 'behavioral_diversity.status') !== 'near_duplicate';
    }

    private function councilRole(?array $metadata): ?string
    {
        $role = data_get($metadata, 'role_complete_council.role')
            ?: data_get($metadata, 'specialist_council_membership.member_role')
            ?: data_get($metadata, 'portfolio_council_lane.role');
        $role = trim((string) $role);

        return $role !== '' ? $role : null;
    }

    /** @return list<array<string, mixed>> */
    private function ideas(ModelMarketPerformance $performance, ?LabAgent $agent, string $role): array
    {
        $metadata = (array) $performance->modelVersion?->metadata;
        $diff = (array) ($agent?->parameter_diff ?? []);
        $gene = (string) (
            data_get($metadata, 'skill_mentor.parameter_key')
            ?: data_get($metadata, 'hypothesis_contract.changed_gene')
            ?: array_key_first($diff)
            ?: 'minimum_signal_confidence'
        );
        $target = (string) (
            data_get($metadata, 'generation_target')
            ?: data_get($metadata, 'parent_candidate.target')
            ?: 'parent_reproducibility'
        );

        return [
            [
                'idea_type' => 'parent_counterfactual_reproduction',
                'gene' => $gene,
                'target' => $target,
                'council_role' => $role,
                'branches' => ['autonomous', 'mentored', 'ablated'],
                'rule' => 'Mentored child must beat autonomous and not regress against ablated branch.',
            ],
            [
                'idea_type' => 'parent_successor_single_gene_probe',
                'gene' => $gene,
                'target' => $target,
                'council_role' => $role,
                'max_changed_genes' => 1,
                'rule' => 'Create a new immutable successor candidate; never mutate the parent in place.',
            ],
        ];
    }
}
