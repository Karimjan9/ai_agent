<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\ModelMarketPerformance;
use App\Models\ParentCandidatePreparation;
use Illuminate\Support\Facades\Schema;

/**
 * Durable projection of the Seed -> Skill Mentor -> Full Parent funnel.
 *
 * This service never grants promotion.  It records the evidence state that
 * the ordinary strict parent frontier may consume, and makes parent supply
 * (or its exact blocker) measurable instead of relying on generation volume.
 */
class ParentFoundryService
{
    public const PROTOCOL = 'parent_foundry_v1';

    /** @return array<string, mixed> */
    public function recordSeed(LabAgent $agent, array $screeningResult = []): array
    {
        return $this->record($agent, null, [
            'stage' => 'screen_validated_seed',
            'parent_eligible' => false,
            'evidence_run_id' => data_get($screeningResult, 'evidence_run_id'),
        ]);
    }

    /** @return array<string, mixed> */
    public function record(
        LabAgent $agent,
        ?ModelMarketPerformance $performance,
        array $mentor,
    ): array {
        if (! Schema::hasTable('lab_parent_candidate_preparations')) {
            return ['protocol' => self::PROTOCOL, 'available' => false, 'promotion_evidence' => false];
        }
        $agent->loadMissing('modelVersion', 'generation.laboratory');
        $model = $agent->modelVersion;
        if (! $model) {
            return ['protocol' => self::PROTOCOL, 'available' => true, 'status' => 'missing_model', 'promotion_evidence' => false];
        }
        $role = (string) (
            data_get($model->metadata, 'council_specialist_contract.role')
            ?: data_get($model->metadata, 'role_complete_council.role')
            ?: data_get($model->metadata, 'portfolio_council_lane.specialist_role')
            ?: data_get($model->metadata, 'semantic_group.role')
        );
        $cohortRole = (string) data_get($model->metadata, 'causal_learning_cohort.role', '');
        $causalExperiment = (array) data_get($model->metadata, 'causal_learning_experiment', []);
        $causalRequired = $cohortRole === 'memory_guided';
        $causalConfirmed = ! $causalRequired
            || data_get($causalExperiment, 'status') === 'confirmed';
        $parentEligible = data_get($mentor, 'stage') === 'full_parent'
            && data_get($mentor, 'parent_eligible') === true
            && $causalConfirmed;
        $status = match (true) {
            $parentEligible => 'eligible_parent',
            data_get($mentor, 'stage') === 'skill_mentor' => 'skill_mentor',
            data_get($mentor, 'stage') === 'full_replay_observed' => 'awaiting_forward_passport',
            default => 'screen_seed',
        };
        $semantic = (array) data_get($model->metadata, 'semantic_group', []);
        $semanticIdentity = [
            'symbol' => strtoupper($agent->symbol),
            'timeframe' => strtoupper($agent->timeframe),
            'strategy_family' => $agent->strategy_family,
            'role' => $role !== '' ? $role : null,
            'semantic_group' => $semantic,
        ];
        $key = hash('sha512', json_encode([
            self::PROTOCOL, $model->id, 'parent_foundry_funnel', $semanticIdentity,
        ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        $payload = [
            'model_version_id' => $model->id,
            'lab_agent_id' => $agent->id,
            'symbol' => strtoupper($agent->symbol),
            'timeframe' => strtoupper($agent->timeframe),
            'strategy_family' => $agent->strategy_family,
            'council_role' => $role !== '' ? $role : null,
            'status' => $status,
            'idea_type' => 'parent_foundry_funnel',
            'idea' => [
                'protocol' => self::PROTOCOL,
                'stage' => data_get($mentor, 'stage', 'screen_validated_seed'),
                'semantic_identity' => $semanticIdentity,
                'semantic_identity_hash' => hash('sha512', json_encode($semanticIdentity, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)),
                'parent_gate_bypass' => false,
                'promotion_evidence' => false,
            ],
            'required_evidence' => [
                'canonical_receipt_integrity' => data_get($model->metadata, 'learning_receipt.integrity.status'),
                'causal_experiment_required' => $causalRequired,
                'causal_experiment_confirmed' => $causalConfirmed,
                'independent_full_replay' => data_get($mentor, 'mentor_contract.status') === 'confirmed_shadow_mentor',
                'forward_passport' => data_get($mentor, 'forward_gate_decision') === 'passed',
                'exact_semantic_parent_frontier' => true,
                'lineage_continuation_required' => true,
            ],
            'source_metrics' => [
                'performance_id' => $performance?->id,
                'sample_count' => (int) ($performance?->sample_count ?? 0),
                'rolling_windows_count' => (int) ($performance?->rolling_windows_count ?? 0),
                'rolling_forward_wins' => (int) ($performance?->rolling_forward_wins ?? 0),
                'profit_factor' => (float) data_get($performance?->metrics, 'profit_factor', 0),
                'parent_eligible' => $parentEligible,
                'promotion_evidence' => false,
            ],
            'promotion_evidence' => false,
            'evaluated_at' => now(),
        ];
        $row = ParentCandidatePreparation::query()->updateOrCreate(
            ['preparation_key' => $key],
            $payload,
        );

        return [
            'protocol' => self::PROTOCOL,
            'available' => true,
            'projection_id' => (int) $row->id,
            'status' => $status,
            'parent_eligible' => $parentEligible,
            'strategy_family' => $agent->strategy_family,
            'council_role' => $role !== '' ? $role : null,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function summary(string $symbol, string $timeframe): array
    {
        if (! Schema::hasTable('lab_parent_candidate_preparations')) {
            return ['protocol' => self::PROTOCOL, 'available' => false, 'promotion_evidence' => false];
        }
        $rows = ParentCandidatePreparation::query()
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->where('idea_type', 'parent_foundry_funnel')
            ->get();
        $eligible = $rows->where('status', 'eligible_parent');

        return [
            'protocol' => self::PROTOCOL,
            'available' => true,
            'symbol' => strtoupper($symbol),
            'timeframe' => strtoupper($timeframe),
            'screen_seeds' => $rows->where('status', 'screen_seed')->count(),
            'skill_mentors' => $rows->where('status', 'skill_mentor')->count(),
            'awaiting_forward_passports' => $rows->where('status', 'awaiting_forward_passport')->count(),
            'eligible_parent_models' => $eligible->pluck('model_version_id')->unique()->values()->all(),
            'eligible_parent_families' => $eligible->pluck('strategy_family')->unique()->values()->all(),
            'eligible_parent_roles' => $eligible->pluck('council_role')->filter()->unique()->values()->all(),
            'promotion_evidence' => false,
        ];
    }
}
