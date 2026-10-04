<?php

namespace App\Services;

use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\LabAgent;
use App\Models\ModelVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A research-only curriculum that turns a complete composition into bounded
 * component experiments.  It deliberately separates hindsight diagnostics
 * from executable policy and never grants live, parent, or promotion credit.
 */
class XauusdEdgeFormationAcademyService
{
    public const PROTOCOL = 'xauusd_edge_formation_academy_v2';
    public const STAGES = [
        'market_cartographer', 'setup_apprentice', 'confirmation_specialist',
        'trigger_entry_specialist', 'execution_specialist', 'management_specialist',
        'full_composition_master',
    ];
    public const BEAM_STAGES = ['setup', 'trigger', 'economic', 'robustness'];
    public const LEARNING_PROGRESS_PROTOCOL = 'academy_learning_progress_v1';

    /** Register/update the frozen composition and expose only the next legal axis. */
    public function passport(string $symbol, string $timeframe, array $identity, array $evidence = []): array
    {
        if (! $this->available()) return $this->blocked('EDGE_ACADEMY_REGISTRY_UNAVAILABLE');
        $symbol = strtoupper($symbol); $timeframe = strtoupper($timeframe);
        $composition = (string) ($identity['composition_key'] ?? $this->hash($identity));
        $context = (string) ($identity['context_key'] ?? $this->hash((array) ($identity['context'] ?? [])));
        $temporal = (string) ($identity['temporal_binding_hash'] ?? $this->hash((array) ($identity['temporal_roles'] ?? [])));
        $stage = $this->normalizeStage((string) ($identity['deepest_stage'] ?? 'market_cartographer'));
        $prospective = [
            'baseline_model_version_id' => $identity['baseline_model_version_id'] ?? null,
            'baseline_parameter_hash' => $identity['baseline_parameter_hash'] ?? null,
            'prospective_source_identity' => $identity['prospective_source_identity'] ?? [],
        ];
        // The table also uniquely owns composition/context/temporal identity.
        // A prospective source revision must not collide with or overwrite
        // that older composition merely because its visible label matches.
        $identity['declared_composition_key'] = $composition;
        if ($prospective['baseline_model_version_id'] !== null || $prospective['baseline_parameter_hash'] !== null
            || $prospective['prospective_source_identity'] !== []) {
            $composition = $this->hash(['prospective_composition_identity_v1', self::PROTOCOL, $composition, $prospective]);
        } else {
            // Observational v2 indexing must also coexist with archived v1
            // cells under the secondary database unique constraint.
            $composition = $this->hash(['observational_composition_identity_v1', self::PROTOCOL, $composition]);
        }
        $key = $this->hash([self::PROTOCOL, $symbol, $timeframe, $composition, $context, $temporal, $prospective]);
        $existing = DB::table('edge_academy_passports')->where('passport_key', $key)->first();
        if ($existing && (int) $existing->stage_depth > $this->depth($stage)) {
            $stage = $this->normalizeStage((string) $existing->deepest_stage);
        }
        $frozen = $this->frozenContract($identity, $stage);
        $curriculum = $this->curriculum($stage, $frozen);
        // A passport refresh cannot erase an original pre-execution task seal.
        $curriculum['learning_progress_tasks'] = $existing
            ? (array) data_get(json_decode((string) $existing->curriculum, true), 'learning_progress_tasks', []) : [];
        DB::table('edge_academy_passports')->updateOrInsert(['passport_key' => $key], [
            'symbol' => $symbol, 'timeframe' => $timeframe, 'composition_key' => $composition,
            'strategy_key' => (string) ($identity['strategy_key'] ?? $identity['strategy_family'] ?? 'unknown'),
            'context_key' => $context, 'temporal_binding_hash' => $temporal, 'deepest_stage' => $stage,
            'stage_depth' => $this->depth($stage), 'status' => $this->depth($stage) >= 6 ? 'composition_candidate' : 'apprentice',
            'frozen_upstream_contract' => json_encode($frozen), 'curriculum' => json_encode($curriculum),
            'evidence' => json_encode(['protocol' => self::PROTOCOL, 'input' => $evidence, 'promotion_evidence' => false]),
            'assessed_at' => now(), 'updated_at' => now(), 'created_at' => now(),
        ]);
        $row = DB::table('edge_academy_passports')->where('passport_key', $key)->first();
        return $this->passportPayload($row);
    }

    /**
     * Post-replay opportunity accounting.  oracle must already be a bounded
     * replay diagnostic; refusing runtime-shaped input prevents look-ahead
     * information from leaking back into a trading signal.
     */
    public function assessOracleGap(int $passportId, array $oracle, array $realized): array
    {
        if (! $this->available()) return $this->blocked('EDGE_ACADEMY_REGISTRY_UNAVAILABLE');
        $passport = DB::table('edge_academy_passports')->find($passportId);
        if (! $passport) return $this->blocked('ACADEMY_PASSPORT_NOT_FOUND');
        // These are evidence-boundary booleans, not truthy configuration.
        // Missing flags and strings such as "false" must never opt into a
        // diagnostic-only lane or silently opt out of runtime/promotion.
        $valid = ($oracle['diagnostic_only'] ?? null) === true
            && ($oracle['runtime_signal'] ?? null) === false
            && ($oracle['promotion_evidence'] ?? null) === false
            && ($oracle['full_oracle_gap_available'] ?? null) === true
            && ($oracle['protocol'] ?? null) === 'edge_formation_academy_diagnostic_v2'
            && ($oracle['status'] ?? null) === 'full_oracle_gap_observed'
            && $this->finiteNumber($oracle['oracle_opportunity_edge_r'] ?? null) !== null;
        if (! $valid) return $this->blocked('ORACLE_DIAGNOSTIC_CONTRACT_REQUIRED');
        $terms = [
            'location_selection_loss_r' => $this->nullableNonNegative($realized['location_selection_loss_r'] ?? null),
            'confirmation_missed_opportunity_r' => $this->nullableNonNegative($realized['confirmation_missed_opportunity_r'] ?? null),
            'false_entry_cost_r' => $this->nullableNonNegative($realized['false_entry_cost_r'] ?? null),
            'entry_timing_leakage_r' => $this->nullableNonNegative($realized['entry_timing_leakage_r'] ?? null),
            'spread_slippage_cost_r' => $this->nullableNonNegative($realized['spread_slippage_cost_r'] ?? null),
            'stop_leakage_r' => $this->nullableNonNegative($realized['stop_leakage_r'] ?? null),
            'management_capture_loss_r' => $this->nullableNonNegative($realized['management_capture_loss_r'] ?? null),
        ];
        $oracleEdge = (float) $oracle['oracle_opportunity_edge_r'];
        // An optimistic setup envelope is not an observed trade outcome.
        // No trade / missing loss terms must not manufacture positive edge.
        $realizedEdge = $this->finiteNumber($realized['realized_after_cost_r'] ?? null);
        $entryEdge = $this->finiteNumber($realized['real_entry_after_cost_r'] ?? null);
        foreach (['realized_after_cost_r', 'real_entry_after_cost_r'] as $field) {
            if (array_key_exists($field, $realized) && $realized[$field] !== null
                && $this->finiteNumber($realized[$field]) === null) {
                return $this->blocked('ORACLE_REALIZED_EVIDENCE_MALFORMED');
            }
        }
        $identified = array_filter($terms, fn ($value): bool => $value !== null);
        $dominant = $identified === [] ? null : array_key_first(collect($identified)->sortDesc()->all());
        $status = $oracleEdge <= 0 ? 'oracle_negative_retire_context_cell'
            : (($entryEdge ?? $realizedEdge ?? 0.) <= 0 ? 'entry_mastery_required'
                : ($realizedEdge === null || $realizedEdge <= 0 ? 'management_or_cost_mastery_required' : 'realized_edge_observed'));
        $action = match ($status) {
            'oracle_negative_retire_context_cell' => ['action' => 'retire_strategy_context_cell', 'next_stage' => 'market_cartographer'],
            'entry_mastery_required' => ['action' => 'freeze_upstream_run_trigger_tournament', 'next_stage' => 'trigger_entry_specialist'],
            'management_or_cost_mastery_required' => ['action' => 'freeze_entry_run_management_attribution', 'next_stage' => 'management_specialist'],
            default => ['action' => 'require_independent_replication', 'next_stage' => 'full_composition_master'],
        };
        $data = (string) ($realized['data_hash'] ?? $oracle['data_hash'] ?? 'missing');
        $execution = (string) ($realized['execution_hash'] ?? $oracle['execution_hash'] ?? 'missing');
        $gap = ['oracle_opportunity_edge_r' => $oracleEdge, ...$terms, 'realized_after_cost_r' => $realizedEdge,
            'unexplained_gap_r' => $realizedEdge === null ? null : max(0., $oracleEdge - $realizedEdge - array_sum($identified)),
            'realized_edge_observed' => $realizedEdge !== null,
            'identified_loss_complete' => count($identified) === count($terms), 'dominant_leak' => $dominant];
        $key = $this->hash([self::PROTOCOL, $passportId, $data, $execution, $oracle, $realized]);
        DB::table('edge_academy_oracle_gaps')->updateOrInsert(['diagnostic_key' => $key], [
            'edge_academy_passport_id' => $passportId, 'data_hash' => $data, 'execution_hash' => $execution,
            'status' => $status, 'dominant_leak' => $dominant, 'edge_gap' => json_encode($gap),
            'oracle_contract' => json_encode([...$oracle, 'diagnostic_only' => true, 'runtime_signal' => false, 'promotion_evidence' => false]),
            'action' => json_encode([...$action, 'promotion_evidence' => false]), 'assessed_at' => now(), 'updated_at' => now(), 'created_at' => now(),
        ]);
        return ['protocol' => self::PROTOCOL, 'status' => $status, 'edge_gap' => $gap, 'action' => $action,
            'oracle_is_runtime_signal' => false, 'promotion_evidence' => false];
    }

    /** Keep three high-quality alternatives per causal stage, never a global winner-only archive. */
    public function archiveBeam(int $passportId, string $stage, array $candidate): array
    {
        if (! $this->available()) return $this->blocked('EDGE_ACADEMY_REGISTRY_UNAVAILABLE');
        $passport = DB::table('edge_academy_passports')->find($passportId);
        if (! $passport) return $this->blocked('ACADEMY_PASSPORT_NOT_FOUND');
        $stage = $this->beamStage($stage);
        $composition = (string) ($candidate['composition_key'] ?? $passport->composition_key);
        $quality = (string) ($candidate['quality_key'] ?? $this->hash([
            $candidate['strategy'] ?? null, $candidate['tactic'] ?? null, $candidate['context'] ?? null,
            $candidate['temporal_binding'] ?? null, $stage,
        ]));
        $key = $this->hash([self::PROTOCOL, $passportId, $stage, $composition, $quality]);
        DB::table('edge_academy_beams')->updateOrInsert(['beam_key' => $key], [
            'edge_academy_passport_id' => $passportId, 'symbol' => $passport->symbol, 'timeframe' => $passport->timeframe,
            'beam_stage' => $stage, 'composition_key' => $composition, 'quality_key' => $quality,
            'score' => (float) ($candidate['score'] ?? 0), 'status' => 'candidate',
            'evidence' => json_encode(['protocol' => self::PROTOCOL, 'candidate' => $candidate, 'promotion_evidence' => false]), 'updated_at' => now(), 'created_at' => now(),
        ]);
        $rows = DB::table('edge_academy_beams')->where('edge_academy_passport_id', $passportId)->where('beam_stage', $stage)->orderByDesc('score')->orderBy('id')->get();
        foreach ($rows as $index => $row) DB::table('edge_academy_beams')->where('id', $row->id)->update(['rank' => $index < 3 ? $index + 1 : null, 'status' => $index < 3 ? 'beam_retained' : 'beam_pruned', 'updated_at' => now()]);
        return ['protocol' => self::PROTOCOL, 'status' => 'beam_archived', 'stage' => $stage, 'retained' => $rows->take(3)->pluck('id')->all(), 'promotion_evidence' => false];
    }

    /** Exact five-arm confirmation ablation; all non-confirmation components remain frozen. */
    public function planConfirmationMarginalValue(int $passportId): array
    {
        return $this->plan($passportId, 'confirmation_marginal_value', 'confirmation_family_policy', [
            ['role' => 'frozen_control', 'value' => 'frozen_current'],
            ['role' => 'candidate', 'value' => 'structure_plus_participation'],
            ['role' => 'candidate', 'value' => 'sequential_three'],
            ['role' => 'candidate', 'value' => 'all_three_simultaneous'],
            ['role' => 'blinded_control', 'value' => 'confirmation_blinded_control'],
        ], ['minimum_setup_events' => 20, 'minimum_trigger_events' => 12, 'minimum_closed_trades' => 8, 'max_false_entry_rate' => .45, 'max_opportunity_flood_ratio' => 1.5]);
    }

    /** Exact five-arm trigger topology tournament; only topology changes. */
    public function planTriggerTopologyTournament(int $passportId): array
    {
        return $this->plan($passportId, 'trigger_topology_tournament', 'trigger_topology_policy', [
            ['role' => 'frozen_control', 'value' => 'frozen_current'],
            ['role' => 'candidate', 'value' => 'aggressive_structure_close'],
            ['role' => 'candidate', 'value' => 'balanced_retest_reaction'],
            ['role' => 'candidate', 'value' => 'conservative_continuation'],
            ['role' => 'blinded_control', 'value' => 'trigger_blinded_control'],
        ], ['minimum_setup_events' => 20, 'minimum_trigger_events' => 12, 'minimum_closed_trades' => 8, 'max_false_entry_rate' => .45, 'max_opportunity_flood_ratio' => 1.5]);
    }

    /** Powered/no-power/topology failure is a different answer from economic failure. */
    public function eventDensityVerdict(array $counts, array $contract): array
    {
        $setup = (int) ($counts['setup'] ?? 0); $trigger = (int) ($counts['trigger'] ?? 0); $trades = (int) ($counts['closed_trade'] ?? $counts['closed_trades'] ?? 0);
        $falseRate = (float) ($counts['false_entry_rate'] ?? 0); $flood = (float) ($counts['opportunity_flood_ratio'] ?? 0);
        $status = $setup < (int) ($contract['minimum_setup_events'] ?? 1) || $trigger < (int) ($contract['minimum_trigger_events'] ?? 1)
            ? 'topology_failure_insufficient_events'
            : ($trades < (int) ($contract['minimum_closed_trades'] ?? 1) ? 'path_activated_no_power'
                : ($falseRate > (float) ($contract['max_false_entry_rate'] ?? 1) || $flood > (float) ($contract['max_opportunity_flood_ratio'] ?? INF)
                    ? 'opportunity_flood_or_false_entry_failure' : 'powered_for_economic_settlement'));
        return ['protocol' => self::PROTOCOL, 'status' => $status, 'counts' => compact('setup', 'trigger', 'trades', 'falseRate', 'flood'), 'promotion_evidence' => false];
    }

    /** Recomposition is legal only after powered negative entry evidence and changes one categorical upstream component. */
    public function planLocationRecomposition(int $passportId, string $axis, array $outcome): array
    {
        if (! in_array($axis, ['tactic', 'temporal_binding', 'location'], true)) return $this->blocked('CATEGORICAL_RECOMPOSITION_AXIS_REQUIRED');
        if (($outcome['density_status'] ?? null) !== 'powered_for_economic_settlement' || (float) ($outcome['after_cost_expectancy_r'] ?? 0) >= 0) return $this->blocked('POWERED_NEGATIVE_ENTRY_EVIDENCE_REQUIRED');
        return $this->blocked('ACADEMY_CATEGORICAL_RUNTIME_ADAPTER_REQUIRED', ['axis' => $axis,
            'executable' => false, 'dispatch_allowed' => false]);
    }

    /** Context is a bounded specialist experiment, not a hidden global router. */
    public function planRegimeSpecialist(int $passportId, array $context): array
    {
        $required = ['regime', 'volatility', 'session'];
        if (collect($required)->contains(fn (string $key): bool => ! filled($context[$key] ?? null))) {
            return $this->blocked('REGIME_SPECIALIST_CONTEXT_CONTRACT_REQUIRED');
        }
        return $this->blocked('ACADEMY_PROSPECTIVE_CONTEXT_ADAPTER_REQUIRED', ['axis' => 'context',
            'declared_context' => $context, 'executable' => false, 'dispatch_allowed' => false]);
    }

    /** Independent roles disagree by design; they cannot create authority. */
    public function roleBrief(string $role, array $artifact): array
    {
        $briefs = [
            'edge_architect' => 'Locate the deepest unproven funnel transition and propose one frozen-axis experiment.',
            'skill_distiller' => 'Extract only reproducible component behavior, scope, contraindications, and exact provenance.',
            'adversarial_falsifier' => 'Try to disconfirm the causal claim through controls, counterfactuals, and hostile contexts.',
        ];
        if (! isset($briefs[$role])) return $this->blocked('UNKNOWN_ACADEMY_ROLE');
        return ['protocol' => self::PROTOCOL, 'role' => $role, 'brief' => $briefs[$role], 'artifact_hash' => $this->hash($artifact),
            'can_dispatch_runtime' => false, 'can_grant_authority' => false, 'promotion_evidence' => false];
    }

    /** Research priority rewards knowledge yield, never raw P&L or generation count. */
    public function researchReward(array $evidence): array
    {
        $parts = [
            'causal_depth_gain' => max(0., (float) ($evidence['causal_depth_gain'] ?? 0)),
            'oracle_gap_reduction' => max(0., (float) ($evidence['oracle_gap_reduction'] ?? 0)),
            'decisive_information' => max(0., (float) ($evidence['decisive_information'] ?? 0)),
            'reusable_component' => max(0., (float) ($evidence['reusable_component'] ?? 0)),
            'independent_replication' => max(0., (float) ($evidence['independent_replication'] ?? 0)),
            'descendant_value' => max(0., (float) ($evidence['descendant_value'] ?? 0)),
        ];
        $score = round(($parts['causal_depth_gain'] * 2) + ($parts['oracle_gap_reduction'] * 1.5) + ($parts['decisive_information'] * 2)
            + ($parts['reusable_component'] * 1.5) + ($parts['independent_replication'] * 2) + ($parts['descendant_value'] * 2), 6);
        return ['protocol' => self::PROTOCOL, 'score' => $score, 'components' => $parts,
            'excludes_raw_profit_as_reward' => true, 'promotion_evidence' => false];
    }

    /** Persist a trial outcome only after its density verdict; no plan is evidence by itself. */
    public function settleTrial(int $trialId, array $counts, array $outcome): array
    {
        if (! $this->available()) return $this->blocked('EDGE_ACADEMY_REGISTRY_UNAVAILABLE');
        return DB::transaction(function () use ($trialId, $counts, $outcome): array {
        $trial = DB::table('edge_academy_trials')->lockForUpdate()->find($trialId);
        if (! $trial) return $this->blocked('ACADEMY_TRIAL_NOT_FOUND');
        if ($trial->settled_at !== null) {
            $prior = json_decode((string) $trial->outcome, true) ?: [];
            return ['protocol' => self::PROTOCOL, 'status' => $trial->status,
                'density' => $prior['density'] ?? [], 'marginal_value' => $prior['marginal_value'] ?? null,
                'stage_progress' => $prior['stage_progress'] ?? [], 'terminal' => true, 'promotion_evidence' => false];
        }
        $density = json_decode((string) $trial->density_contract, true) ?: [];
        $verdict = $this->eventDensityVerdict($counts, $density);
        $marginal = null;
        if ($trial->trial_type === 'confirmation_marginal_value') {
            $avoided = $this->nullableNonNegative($outcome['avoided_loss_r'] ?? null);
            $missed = $this->nullableNonNegative($outcome['missed_opportunity_r'] ?? null);
            $late = $this->nullableNonNegative($outcome['late_entry_cost_r'] ?? null);
            $marginal = $avoided !== null && $missed !== null && $late !== null
                ? ['observed' => true, 'passed' => $avoided > ($missed + $late), 'avoided_loss_r' => $avoided, 'missed_opportunity_r' => $missed, 'late_entry_cost_r' => $late]
                : ['observed' => false, 'passed' => false, 'reason' => 'MARGINAL_VALUE_COMPONENTS_MISSING'];
        }
        $status = $verdict['status'] === 'powered_for_economic_settlement'
            ? (($marginal !== null && ! $marginal['observed']) ? 'settled_powered_marginal_value_incomplete' : 'settled_powered')
            : 'settled_without_economic_claim';
        $progress = $this->prospectiveProgress($trial, $outcome);
        if (isset($progress['reason']) && ($progress['evidence_assessable'] ?? null) === false
            && ($progress['prospective_seal_required'] ?? null) === true) {
            $status = 'settled_unassessable_stage_evidence';
            // Transient pair arithmetic cannot outrank the owner's original
            // source/roster check in the persisted behavioral summary.
            $outcome['behavioral_proof'] = [
                'status' => 'unassessable', 'evidence_assessable' => false,
                'reason' => $progress['reason'], 'economic_authority' => false,
            ];
        }
        $outcome['stage_comparisons'] = collect($outcome['stage_comparisons'] ?? [])->map(function (array $comparison): array {
            unset($comparison['control_metrics'], $comparison['candidate_metrics']);
            return $comparison;
        })->all();
        DB::table('edge_academy_trials')->where('id', $trialId)->update(['status' => $status,
            'outcome' => json_encode(['density' => $verdict, 'marginal_value' => $marginal, 'stage_progress' => $progress,
                'outcome' => $outcome, 'promotion_evidence' => false]), 'settled_at' => now(), 'updated_at' => now()]);
        // The prerequisite must be settled before its successor task is sealed.
        // Both writes are still inside this transaction; a reader sees neither
        // a half-settlement nor an unearned continuation.
        if (($progress['status'] ?? null) === 'observed_stage_controllability') {
            $progress['next_trial'] = $this->planNextCurriculumTrial((int) $progress['successor_passport_id']);
            DB::table('edge_academy_trials')->where('id', $trialId)->update([
                'outcome' => json_encode(['density' => $verdict, 'marginal_value' => $marginal, 'stage_progress' => $progress,
                    'outcome' => $outcome, 'promotion_evidence' => false]),
            ]);
        }
        return ['protocol' => self::PROTOCOL, 'status' => $status, 'density' => $verdict, 'marginal_value' => $marginal,
            'stage_progress' => $progress, 'promotion_evidence' => false];
        });
    }

    /** A candidate may become a new research scaffold; historical baselines never gain its depth. */
    private function prospectiveProgress(object $trial, array $outcome): array
    {
        $base = ['protocol' => 'academy_prospective_curriculum_progress_v1', 'status' => 'no_proven_stage_progress',
            'tested_axis' => null, 'runtime_observed_depth' => 0, 'controllable_depth' => 0,
            'evidence_assessable' => false,
            'prospective_seal_required' => false,
            'independent_causal_skill' => false, 'economic_mastery' => false,
            'credit_authority' => false, 'paper_authority' => false, 'parent_authority' => false, 'promotion_evidence' => false];
        if (($outcome['primary_arm_evidence_complete'] ?? null) !== true
            || ! is_int($outcome['expected_arm_count'] ?? null) || $outcome['expected_arm_count'] < 2
            || ($outcome['complete_arm_count'] ?? null) !== $outcome['expected_arm_count']) return $base;
        $passport = DB::table('edge_academy_passports')->find($trial->edge_academy_passport_id);
        if (! $passport) return $base;
        $frozen = json_decode((string) $trial->frozen_contract, true) ?: [];
        $base['prospective_seal_required'] = filled(data_get($frozen, 'prospective_source_identity.source_evaluator_hash'))
            || filled(data_get($frozen, 'prospective_source_identity.python_source_hash'));
        $plannedArms = json_decode((string) $trial->arms, true) ?: [];
        if (count($plannedArms) !== $outcome['expected_arm_count']) {
            return [...$base, 'reason' => 'ACADEMY_ORIGINAL_ARM_ROSTER_REQUIRED'];
        }
        $curriculum = json_decode((string) $passport->curriculum, true) ?: [];
        $director = app(CausalStageMasteryDirectorService::class);
        $evidence = app(LabImmutableEvidenceService::class);
        $compiler = app(AcademyExperimentContractCompilerService::class);
        foreach ((array) ($outcome['stage_comparisons'] ?? []) as $comparison) {
            if (! is_array($comparison) || ($comparison['role'] ?? null) !== 'candidate') continue;
            $gene = (string) ($comparison['gene'] ?? '');
            $base['tested_axis'] = $gene;
            if (! in_array($gene, (array) ($curriculum['permitted_axes'] ?? []), true)) continue;
            $control = LabEvaluationRun::query()->find((int) ($comparison['control_run_database_id'] ?? 0));
            $candidate = LabEvaluationRun::query()->find((int) ($comparison['candidate_run_database_id'] ?? 0));
            if (! $control || ! $candidate || $control->status !== 'completed' || $candidate->status !== 'completed'
                || $control->lab_generation_id !== $candidate->lab_generation_id) continue;
            $candidateSourceReceipt = $evidence->latestArtifactPayload($candidate);
            $base['prospective_seal_required'] = $base['prospective_seal_required']
                || data_get($candidateSourceReceipt, 'data_quality.decision_identity_receipt.protocol') === 'replay_decision_identity_v2';
            if (! $this->primaryRosterComplete($trial, $frozen, (int) $control->lab_generation_id)) {
                $base['reason'] = 'ACADEMY_ORIGINAL_IMMUTABLE_SOURCE_ROSTER_REQUIRED';
                continue;
            }
            $models = ['control' => ModelVersion::query()->find($control->model_version_id),
                'candidate' => ModelVersion::query()->find($candidate->model_version_id)];
            $valid = true;
            $metrics = [];
            foreach (['control' => $control, 'candidate' => $candidate] as $role => $run) {
                $model = $models[$role];
                $agent = LabAgent::query()->with('modelVersion')->find($run->lab_agent_id);
                $artifact = $evidence->latestArtifactPayload($run);
                $artifactRecord = LabEvidenceArtifact::query()->where('run_id', $run->run_id)
                    ->where('artifact_type', 'evaluation_response')->latest('id')->first();
                if (! $model || ! $agent || $agent->model_version_id !== $run->model_version_id
                    || ! hash_equals((string) $run->parameter_hash, $evidence->parameterHash($agent))
                    || ! is_array($artifact) || $run->run_id !== ($comparison[$role.'_run_id'] ?? null)
                    || $run->model_version_id !== ($comparison[$role.'_model_version_id'] ?? null)
                    || $run->lab_agent_id !== ($comparison[$role.'_agent_id'] ?? null)
                    || ! hash_equals((string) $run->response_hash, (string) ($comparison[$role.'_response_hash'] ?? ''))
                    || ! $artifactRecord || ! hash_equals((string) $run->response_hash, (string) $artifactRecord->sha256)
                    // Compressed reads already verify sealed original bytes.
                    // Re-encoding parsed numeric-key objects changes shape;
                    // legacy inline payloads still need their explicit seal.
                    || (! $artifactRecord->storage_path && ! hash_equals((string) $artifactRecord->sha256, $evidence->hash($artifact)))
                    || data_get($model->metadata, 'academy_experiment.academy_trial_id') !== (int) $trial->id
                    || data_get($model->metadata, 'academy_experiment.arm_role') !== ($role === 'control' ? 'frozen_control' : 'candidate')
                    || ! hash_equals((string) ($comparison[$role.'_parameter_hash'] ?? ''), $compiler->parameterHash((array) $model->parameters))) {
                    $valid = false; break;
                }
                if (! $this->prospectiveSourceMatches($frozen, $artifact, $run)) {
                    $valid = false; break;
                }
                $metrics[$role] = [...$artifact, 'value' => $model->parameters[$gene] ?? null];
            }
            if (! $valid) continue;
            $controlParameters = (array) $models['control']->parameters;
            $candidateParameters = (array) $models['candidate']->parameters;
            if (! hash_equals((string) ($frozen['baseline_parameter_hash'] ?? ''), $compiler->parameterHash($controlParameters))) continue;
            $axis = $director->inferAxis($controlParameters, $candidateParameters);
            if (($axis['status'] ?? null) !== 'single_axis' || ($axis['gene'] ?? null) !== $gene) continue;
            $assessment = $director->assess($gene, $metrics['control'], $metrics['candidate']);
            $target = (string) ($assessment['target_stage'] ?? '');
            $stage = match ($target) {
                'location' => 'setup_apprentice', 'setup' => 'confirmation_specialist',
                'confirmation' => 'trigger_entry_specialist', 'trigger' => 'execution_specialist',
                'entry' => 'management_specialist',
                'closed_trade' => 'management_specialist', default => 'market_cartographer',
            };
            $observedCounts = (array) data_get($metrics['candidate'], 'data_quality.decision_identity_receipt.stage_identities', []);
            foreach (['location' => 1, 'setup' => 2, 'confirmation' => 3, 'trigger' => 4, 'entry' => 5, 'closed_trade' => 5] as $observed => $depth) {
                if (data_get($observedCounts, "{$observed}.status") === 'observed'
                    && (int) data_get($observedCounts, "{$observed}.event_count", 0) > 0) {
                    $base['runtime_observed_depth'] = max($base['runtime_observed_depth'], $depth);
                }
            }
            $controlCount = (int) data_get($metrics['control'], "data_quality.decision_identity_receipt.stage_identities.{$target}.event_count", 0);
            $candidateCount = (int) data_get($metrics['candidate'], "data_quality.decision_identity_receipt.stage_identities.{$target}.event_count", 0);
            $controlEconomics = $this->finiteNumber($metrics['control']['after_cost_expectancy_r'] ?? null);
            $candidateEconomics = $this->finiteNumber($metrics['candidate']['after_cost_expectancy_r'] ?? null);
            $sameCountBetterEconomics = $candidateCount === $controlCount && $candidateEconomics !== null
                && $controlEconomics !== null && $candidateEconomics > $controlEconomics;
            $economicRegression = (int) ($metrics['control']['total_trades'] ?? 0) > 0
                && (int) ($metrics['candidate']['total_trades'] ?? 0) > 0
                && $controlEconomics !== null && $candidateEconomics !== null && $candidateEconomics < $controlEconomics;
            if (($assessment['status'] ?? null) !== 'controllable' || $candidateCount <= 0
                || ! ($candidateCount > $controlCount || $sameCountBetterEconomics) || $economicRegression
                || ($metrics['candidate']['forbidden_risk_bypass'] ?? false) !== false
                || $this->depth($stage) <= (int) $passport->stage_depth) continue;
            $bindings = (array) data_get($metrics['candidate'], 'data_quality.decision_identity_receipt.bindings', []);
            $source = [...(array) ($frozen['prospective_source_identity'] ?? []),
                'source_academy_trial_id' => (int) $trial->id, 'source_candidate_run_id' => $candidate->run_id,
                'source_control_run_id' => $control->run_id, 'source_response_hash' => $candidate->response_hash,
                'consumed_input_bindings' => $bindings,
                'source_evaluator_hash' => data_get($frozen, 'prospective_source_identity.source_evaluator_hash'),
                'python_source_hash' => data_get($frozen, 'prospective_source_identity.python_source_hash'),
                'decision_dependency_identity' => (array) data_get($metrics['candidate'], 'data_quality.decision_identity_receipt.dependency_identity', []),
            ];
            $successor = $this->passport($passport->symbol, $passport->timeframe, [
                'composition_key' => $this->hash(['prospective_stage_scaffold', $passport->composition_key, $trial->id, $candidate->model_version_id, $source]),
                'strategy_family' => $passport->strategy_key, 'deepest_stage' => $stage,
                'context' => $frozen['context'] ?? [], 'temporal_roles' => $frozen['temporal_roles'] ?? [],
                'risk_contract' => $frozen['risk'] ?? [], 'management_contract' => $frozen['management'] ?? [],
                'baseline_model_version_id' => $candidate->model_version_id, 'baseline_parameters' => $candidateParameters,
                'baseline_parameter_hash' => $compiler->parameterHash($candidateParameters), 'prospective_source_identity' => $source,
            ], ['source_trial_id' => (int) $trial->id, 'source_passport_id' => (int) $passport->id,
                'control_run_id' => $control->run_id, 'candidate_run_id' => $candidate->run_id,
                'exact_delta' => ['gene' => $gene, 'old' => $controlParameters[$gene], 'new' => $candidateParameters[$gene]],
                'stage_assessment' => $assessment, 'independent_causal_skill' => false]);
            return [...$base, 'status' => 'observed_stage_controllability', 'evidence_assessable' => true, 'controllable_depth' => $this->depth($stage),
                'successor_passport_id' => $successor['passport_id'], 'source_passport_id' => (int) $passport->id,
                'source_control_run_id' => $control->run_id, 'source_candidate_run_id' => $candidate->run_id,
                'candidate_parameter_hash' => $compiler->parameterHash($candidateParameters),
                'exact_delta' => ['gene' => $gene, 'old' => $controlParameters[$gene], 'new' => $candidateParameters[$gene]],
                'assessment' => $assessment];
        }
        return $base;
    }

    /** Pair equality is insufficient: both arms must consume the original prospective source seal. */
    private function prospectiveSourceMatches(array $frozen, array $metrics, LabEvaluationRun $run): bool
    {
        $source = (array) ($frozen['prospective_source_identity'] ?? []);
        $receipt = (array) data_get($metrics, 'data_quality.decision_identity_receipt', []);
        if (($frozen['protocol'] ?? null) !== self::PROTOCOL || ($source['pre_2026_only'] ?? null) !== true
            || ($source['source_identity_protocol'] ?? null) !== 'dual_runtime_source_identity_v1'
            || ($receipt['protocol'] ?? null) !== 'replay_decision_identity_v2' || ($receipt['status'] ?? null) !== 'complete'
            || data_get($receipt, 'bindings.source_identity_protocol') !== 'dual_runtime_source_identity_v1') return false;
        $dataset = (string) ($source['mtf_bundle_hash'] ?? '');
        $execution = (string) ($source['execution_hash'] ?? '');
        $evaluator = (string) ($source['source_evaluator_hash'] ?? '');
        $python = (string) ($source['python_source_hash'] ?? '');
        if ($dataset === '' || $execution === '' || $evaluator === '' || $python === ''
            || ! hash_equals($dataset, (string) data_get($receipt, 'bindings.dataset_identity', ''))
            || ! hash_equals($execution, (string) data_get($receipt, 'bindings.execution_hash', ''))
            || ! hash_equals($evaluator, (string) data_get($receipt, 'bindings.full_runtime_source_hash', ''))
            || ! hash_equals($python, (string) data_get($receipt, 'bindings.python_source_hash', ''))
            || ! hash_equals($python, (string) data_get($receipt, 'bindings.source_evaluator_hash', ''))
            || ! hash_equals($evaluator, (string) $run->code_hash)) return false;
        // source.data_hash identifies the foundation archive, not the MTF
        // runtime bundle or normalized evaluated primary-candle hash.
        foreach (['M5', 'M15', 'H1', 'H4'] as $stream) {
            $sealed = (string) data_get($source, "mtf_bundle_manifest.streams.{$stream}.sha256", '');
            $actual = (string) data_get($receipt, "dependency_identity.streams.{$stream}.actual_source_sha256", '');
            if ($sealed === '' || $actual === '' || ! hash_equals($sealed, $actual)) return false;
        }
        return true;
    }

    /** Counts supplied by a caller cannot substitute for each planned arm's immutable completion. */
    private function primaryRosterComplete(object $trial, array $frozen, int $generationId): bool
    {
        $arms = json_decode((string) $trial->arms, true) ?: [];
        $axis = (string) ($arms[0]['changed_axis'] ?? '');
        $compiler = app(AcademyExperimentContractCompilerService::class);
        $compiled = $compiler->compile(['axis' => $axis, 'arms' => $arms], (array) ($frozen['baseline_parameters'] ?? []),
            ['symbol' => 'XAUUSD', 'laboratory_timeframe' => 'H1', 'execution_timeframe' => 'M5']);
        if (($compiled['status'] ?? null) !== 'compiled') return false;
        $agents = LabAgent::query()->with('modelVersion')->where('lab_generation_id', $generationId)->get()
            ->filter(fn (LabAgent $agent): bool => data_get($agent->modelVersion?->metadata, 'academy_experiment.academy_trial_id') === (int) $trial->id);
        if ($agents->count() !== count($compiled['arms'])) return false;
        $evidence = app(LabImmutableEvidenceService::class);
        foreach ($compiled['arms'] as $index => $arm) {
            $matches = $agents->filter(fn (LabAgent $agent): bool => data_get($agent->modelVersion?->metadata, 'academy_experiment.arm_index') === $index
                && data_get($agent->modelVersion?->metadata, 'academy_experiment.arm_role') === $arm['role']);
            if ($matches->count() !== 1) return false;
            $agent = $matches->first();
            if (! $evidence->equivalentJsonValue($arm['runtime_parameters'], (array) $agent->modelVersion?->parameters)) return false;
            $run = LabEvaluationRun::query()->where('lab_generation_id', $generationId)->where('lab_agent_id', $agent->id)
                ->where('model_version_id', $agent->model_version_id)->where('phase', 'screening')->where('status', 'completed')->latest('id')->first();
            if (! $run || ! hash_equals((string) $run->parameter_hash, $evidence->parameterHash($agent))) return false;
            $artifact = LabEvidenceArtifact::query()->where('run_id', $run->run_id)->where('artifact_type', 'evaluation_response')->latest('id')->first();
            $metrics = $evidence->latestArtifactPayload($run);
            if (! $artifact || ! is_array($metrics) || ! hash_equals((string) $run->response_hash, (string) $artifact->sha256)
                || (! $artifact->storage_path && ! hash_equals((string) $artifact->sha256, $evidence->hash($metrics)))
                || ! $this->prospectiveSourceMatches($frozen, $metrics, $run)) return false;
        }
        return true;
    }

    /** Earned scheduling priority requires the original proof, never a claimed passport depth. */
    public function curriculumContinuationEvidence(int $trialId): array
    {
        $base = ['protocol' => 'academy_curriculum_continuation_evidence_v1', 'eligible' => false,
            'credit_authority' => false, 'paper_authority' => false, 'parent_authority' => false, 'promotion_evidence' => false];
        if (! $this->available()) return [...$base, 'reason' => 'EDGE_ACADEMY_REGISTRY_UNAVAILABLE'];
        $trial = DB::table('edge_academy_trials')->find($trialId);
        $passport = $trial ? DB::table('edge_academy_passports')->find($trial->edge_academy_passport_id) : null;
        if (! $trial || ! $passport || $trial->settled_at !== null || $trial->status !== 'planned') {
            return [...$base, 'reason' => 'ACADEMY_PLANNED_CONTINUATION_REQUIRED'];
        }
        $frozen = json_decode((string) $trial->frozen_contract, true) ?: [];
        $sourceId = (int) data_get($frozen, 'prospective_source_identity.source_academy_trial_id', 0);
        $sourceTrial = $sourceId > 0 ? DB::table('edge_academy_trials')->find($sourceId) : null;
        $sourcePassport = $sourceTrial ? DB::table('edge_academy_passports')->find($sourceTrial->edge_academy_passport_id) : null;
        $sourceOutcome = $sourceTrial ? (json_decode((string) $sourceTrial->outcome, true) ?: []) : [];
        $proof = (array) ($sourceOutcome['stage_progress'] ?? []);
        if (($frozen['protocol'] ?? null) !== self::PROTOCOL || ! $sourceTrial || ! $sourcePassport || $sourceTrial->settled_at === null
            || ($proof['status'] ?? null) !== 'observed_stage_controllability'
            || ($proof['source_passport_id'] ?? null) !== (int) $sourcePassport->id
            || ($proof['successor_passport_id'] ?? null) !== (int) $passport->id
            || ($proof['controllable_depth'] ?? null) !== (int) $passport->stage_depth
            || (int) $passport->stage_depth <= 0
            || data_get($proof, 'next_trial.trial_id') !== (int) $trial->id) {
            return [...$base, 'reason' => 'ACADEMY_ORIGINAL_STAGE_PROGRESS_REQUIRED'];
        }
        $sourceFrozen = json_decode((string) $sourceTrial->frozen_contract, true) ?: [];
        $control = LabEvaluationRun::query()->where('run_id', (string) ($proof['source_control_run_id'] ?? ''))->first();
        $candidate = LabEvaluationRun::query()->where('run_id', (string) ($proof['source_candidate_run_id'] ?? ''))->first();
        if (! $control || ! $candidate || $control->status !== 'completed' || $candidate->status !== 'completed'
            || $control->lab_generation_id !== $candidate->lab_generation_id
            || $candidate->model_version_id !== ($frozen['baseline_model_version_id'] ?? null)
            || $candidate->run_id !== data_get($frozen, 'prospective_source_identity.source_candidate_run_id')
            || $control->run_id !== data_get($frozen, 'prospective_source_identity.source_control_run_id')
            || ! hash_equals((string) $candidate->response_hash, (string) data_get($frozen, 'prospective_source_identity.source_response_hash', ''))) {
            return [...$base, 'reason' => 'ACADEMY_ORIGINAL_RUN_IDENTITIES_REQUIRED'];
        }
        $compiler = app(AcademyExperimentContractCompilerService::class);
        $evidence = app(LabImmutableEvidenceService::class);
        $plannedArms = json_decode((string) $trial->arms, true) ?: [];
        $plannedAxis = (string) ($plannedArms[0]['changed_axis'] ?? '');
        $curriculum = json_decode((string) $passport->curriculum, true) ?: [];
        $plannedContract = $compiler->compile(['axis' => $plannedAxis, 'arms' => $plannedArms],
            (array) ($frozen['baseline_parameters'] ?? []),
            ['symbol' => 'XAUUSD', 'laboratory_timeframe' => 'H1', 'execution_timeframe' => 'M5']);
        if (($plannedContract['status'] ?? null) !== 'compiled'
            || ! in_array($plannedAxis, (array) ($curriculum['permitted_axes'] ?? []), true)
            || $trial->trial_type !== data_get($proof, 'next_trial.trial_type')
            || ! $evidence->equivalentJsonValue($plannedArms, data_get($proof, 'next_trial.arms', []))
            || ! $evidence->equivalentJsonValue(json_decode((string) $trial->density_contract, true) ?: [], data_get($proof, 'next_trial.event_density_contract', []))
            || ! $evidence->equivalentJsonValue($frozen, json_decode((string) $passport->frozen_upstream_contract, true) ?: [])) {
            return [...$base, 'reason' => 'ACADEMY_ORIGINAL_CONTINUATION_PLAN_REQUIRED'];
        }
        $models = ['control' => ModelVersion::query()->find($control->model_version_id),
            'candidate' => ModelVersion::query()->find($candidate->model_version_id)];
        if (! $models['control'] || ! $models['candidate']
            || data_get($models['control']->metadata, 'academy_experiment.academy_trial_id') !== (int) $sourceTrial->id
            || data_get($models['candidate']->metadata, 'academy_experiment.academy_trial_id') !== (int) $sourceTrial->id
            || data_get($models['control']->metadata, 'academy_experiment.arm_role') !== 'frozen_control'
            || data_get($models['candidate']->metadata, 'academy_experiment.arm_role') !== 'candidate'
            || ! hash_equals((string) ($frozen['baseline_parameter_hash'] ?? ''), $compiler->parameterHash((array) $models['candidate']->parameters))
            || ! hash_equals((string) ($proof['candidate_parameter_hash'] ?? ''), (string) ($frozen['baseline_parameter_hash'] ?? ''))
            || ! hash_equals((string) ($sourceFrozen['baseline_parameter_hash'] ?? ''), $compiler->parameterHash((array) $models['control']->parameters))) {
            return [...$base, 'reason' => 'ACADEMY_ORIGINAL_BASELINE_PARAMETERS_REQUIRED'];
        }
        try {
            if (! $this->primaryRosterComplete($sourceTrial, $sourceFrozen, (int) $control->lab_generation_id)) {
                return [...$base, 'reason' => 'ACADEMY_ORIGINAL_IMMUTABLE_ROSTER_REQUIRED'];
            }
            $metrics = ['control' => $evidence->latestArtifactPayload($control), 'candidate' => $evidence->latestArtifactPayload($candidate)];
            foreach (['control' => $control, 'candidate' => $candidate] as $role => $run) {
                $agent = LabAgent::query()->with('modelVersion')->find($run->lab_agent_id);
                $artifact = LabEvidenceArtifact::query()->where('run_id', $run->run_id)->where('artifact_type', 'evaluation_response')->latest('id')->first();
                if (! $agent || $agent->model_version_id !== $run->model_version_id
                    || ! hash_equals((string) $run->parameter_hash, $evidence->parameterHash($agent))
                    || ! $artifact || ! is_array($metrics[$role])
                    || ! hash_equals((string) $run->response_hash, (string) $artifact->sha256)
                    || (! $artifact->storage_path && ! hash_equals((string) $artifact->sha256, $evidence->hash($metrics[$role])))
                    || ! $this->prospectiveSourceMatches($sourceFrozen, $metrics[$role], $run)) {
                    return [...$base, 'reason' => 'ACADEMY_ORIGINAL_SELECTED_ARMS_REQUIRED'];
                }
            }
        } catch (\Throwable) {
            return [...$base, 'reason' => 'ACADEMY_ORIGINAL_ARTIFACT_INVALID'];
        }
        $director = app(CausalStageMasteryDirectorService::class);
        $axis = $director->inferAxis((array) $models['control']->parameters, (array) $models['candidate']->parameters);
        if (($axis['status'] ?? null) !== 'single_axis') return [...$base, 'reason' => 'ACADEMY_ORIGINAL_SINGLE_AXIS_REQUIRED'];
        $gene = (string) $axis['gene'];
        $assessment = $director->assess($gene,
            [...$metrics['control'], 'value' => $models['control']->parameters[$gene]],
            [...$metrics['candidate'], 'value' => $models['candidate']->parameters[$gene]]);
        $target = (string) ($assessment['target_stage'] ?? '');
        $expectedDepth = match ($target) {
            'location' => 1, 'setup' => 2, 'confirmation' => 3, 'trigger' => 4, 'entry', 'closed_trade' => 5, default => 0,
        };
        $controlCount = (int) data_get($assessment, 'control_counts.'.$target, 0);
        $candidateCount = (int) data_get($assessment, 'candidate_counts.'.$target, 0);
        $oldEconomics = $this->finiteNumber($metrics['control']['after_cost_expectancy_r'] ?? null);
        $newEconomics = $this->finiteNumber($metrics['candidate']['after_cost_expectancy_r'] ?? null);
        $sameCountEconomicGain = $candidateCount === $controlCount && $newEconomics !== null && $oldEconomics !== null && $newEconomics > $oldEconomics;
        $economicRegression = (int) ($metrics['control']['total_trades'] ?? 0) > 0 && (int) ($metrics['candidate']['total_trades'] ?? 0) > 0
            && $newEconomics !== null && $oldEconomics !== null && $newEconomics < $oldEconomics;
        $delta = ['gene' => $gene, 'old' => $models['control']->parameters[$gene], 'new' => $models['candidate']->parameters[$gene]];
        if (($assessment['status'] ?? null) !== 'controllable' || $candidateCount <= 0
            || (int) $passport->stage_depth !== $expectedDepth || $expectedDepth <= (int) $sourcePassport->stage_depth
            || ! ($candidateCount > $controlCount || $sameCountEconomicGain) || $economicRegression
            || ($metrics['candidate']['forbidden_risk_bypass'] ?? false) !== false
            || ! $evidence->equivalentJsonValue($delta, $proof['exact_delta'] ?? [])
            || ! $evidence->equivalentJsonValue($assessment, $proof['assessment'] ?? [])) {
            return [...$base, 'reason' => 'ACADEMY_ORIGINAL_POSITIVE_STAGE_PROOF_REQUIRED'];
        }
        return [...$base, 'eligible' => true, 'reason' => 'ACADEMY_ATTESTED_CURRICULUM_CONTINUATION',
            'trial_id' => $trialId, 'source_trial_id' => $sourceId, 'passport_id' => (int) $passport->id,
            'source_control_run_id' => $control->run_id, 'source_candidate_run_id' => $candidate->run_id,
            'source_response_hash' => $candidate->response_hash, 'baseline_parameter_hash' => $frozen['baseline_parameter_hash'],
            'exact_delta' => $delta, 'stage_depth' => (int) $passport->stage_depth];
    }

    /** Convert a settled diagnostic into one bounded next experiment; never dispatch it. */
    public function nextExperiment(int $passportId, array $oracleSettlement): array
    {
        if (! $this->available()) return $this->blocked('EDGE_ACADEMY_REGISTRY_UNAVAILABLE');
        $passport = DB::table('edge_academy_passports')->find($passportId);
        if (! $passport) return $this->blocked('ACADEMY_PASSPORT_NOT_FOUND');
        $status = (string) ($oracleSettlement['status'] ?? '');
        if (! in_array($status, ['oracle_negative_retire_context_cell', 'entry_mastery_required',
            'management_or_cost_mastery_required', 'realized_edge_observed'], true)) {
            return $this->blocked((string) ($oracleSettlement['reason'] ?? 'ORACLE_ENVELOPE_NOT_READY'), [
                'oracle_status' => $status, 'dispatch_allowed' => false,
            ]);
        }
        if ($status === 'oracle_negative_retire_context_cell') return ['protocol' => self::PROTOCOL, 'status' => 'retired_context_cell', 'dispatch_allowed' => false, 'promotion_evidence' => false];
        $progress = $this->learningProgress($passportId);
        if (in_array(data_get($progress, 'recommendation.action'), ['hold_budget', 'repair_prerequisite'], true)) {
            return $this->blocked('ACADEMY_LEARNING_PROGRESS_HOLDS_NEW_WORK', ['learning_progress' => $progress, 'dispatch_allowed' => false]);
        }
        if (data_get($progress, 'recommendation.action') === 'adjacent_legal_challenge') {
            return $this->planAdjacentChallenge($passport, (string) $progress['axis']);
        }
        $curriculum = json_decode((string) $passport->curriculum, true) ?: [];
        $axes = (array) ($curriculum['permitted_axes'] ?? []);
        if ($status === 'entry_mastery_required') {
            if ((int) $passport->stage_depth === 0) {
                $location = $this->planContextLocationProbe((int) $passport->id);
                return ($location['reason'] ?? null) === 'ACADEMY_AXIS_INACTIVE_IN_RUNTIME_MODEL'
                    ? $this->planSetupTopologyProbe((int) $passport->id) : $location;
            }
            if (in_array('setup_topology_policy', $axes, true)) return $this->planSetupTopologyProbe((int) $passport->id);
            if (in_array('trigger_topology_policy', $axes, true)) return $this->planTriggerTopologyTournament((int) $passport->id);
            if (in_array('confirmation_family_policy', $axes, true)) return $this->planConfirmationMarginalValue((int) $passport->id);
            if (in_array('max_chase_atr', $axes, true)) return $this->planEntryGeometryProbe((int) $passport->id);
            return $this->blocked('ENTRY_DIAGNOSIS_AWAITS_UPSTREAM_CURRICULUM');
        }
        if ($status === 'management_or_cost_mastery_required') {
            if (in_array('time_stop_candles', $axes, true)) return $this->planManagementCaptureProbe((int) $passport->id);
            return ['protocol' => self::PROTOCOL, 'status' => 'management_attribution_required',
                'frozen_entry_required' => true, 'risk_mutation_forbidden' => true,
                'dispatch_allowed' => false, 'promotion_evidence' => false];
        }
        return ['protocol' => self::PROTOCOL, 'status' => 'independent_replication_required', 'dispatch_allowed' => false, 'promotion_evidence' => false];
    }

    /** Cold start changes a real context-to-location adapter, never a made-up context gene. */
    public function planContextLocationProbe(int $passportId): array
    {
        if (! $this->available()) return $this->blocked('EDGE_ACADEMY_REGISTRY_UNAVAILABLE');
        $passport = DB::table('edge_academy_passports')->find($passportId);
        if (! $passport) return $this->blocked('ACADEMY_PASSPORT_NOT_FOUND');
        $frozen = json_decode((string) $passport->frozen_upstream_contract, true) ?: [];
        $definition = $this->contextLocationProbeDefinition((array) ($frozen['baseline_parameters'] ?? []));
        if (($definition['status'] ?? null) === 'blocked') return $definition;
        return $this->plan($passportId, $definition['trial_type'], $definition['axis'], $definition['arms'], $definition['event_density_contract']);
    }

    /** Pure planning: archived parameters are a hypothesis, not transferred proof. */
    public function previewColdStartExperiment(array $baselineParameters): array
    {
        $definition = $this->contextLocationProbeDefinition($baselineParameters);
        if (($definition['reason'] ?? null) === 'ACADEMY_AXIS_INACTIVE_IN_RUNTIME_MODEL') {
            $definition = $this->setupTopologyProbeDefinition();
        }
        if (($definition['status'] ?? null) === 'blocked') return $definition;
        $compiler = app(AcademyExperimentContractCompilerService::class);
        $baselineHash = $compiler->parameterHash($baselineParameters);
        $arms = $this->explicitArms($definition['arms'], $definition['axis'], [
            'protocol' => self::PROTOCOL, 'baseline_parameters' => $baselineParameters,
            'baseline_parameter_hash' => $baselineHash, 'frozen_at_stage_depth' => 0,
        ]);
        $compiled = $compiler->compile(['axis' => $definition['axis'], 'arms' => $arms], $baselineParameters,
            ['symbol' => 'XAUUSD', 'laboratory_timeframe' => 'H1', 'execution_timeframe' => 'M5']);
        if (($compiled['status'] ?? null) !== 'compiled') return $compiled;
        return [...$definition, 'protocol' => 'academy_cold_start_preview_v1', 'status' => 'previewed',
            'arms' => $arms, 'compiled_contract' => $compiled, 'compiled_contract_hash' => $this->hash($compiled),
            'baseline_parameter_hash' => $baselineHash, 'stage_depth' => 0, 'source_is_hypothesis_only' => true,
            'dispatch_allowed' => false, 'credit_authority' => false, 'paper_authority' => false,
            'parent_authority' => false, 'promotion_evidence' => false];
    }

    /** Only the existing arbiter/materializer may publish this planned work. */
    public function planColdStartExperiment(int $passportId): array
    {
        if (! $this->available()) return $this->blocked('EDGE_ACADEMY_REGISTRY_UNAVAILABLE');
        $passport = DB::table('edge_academy_passports')->find($passportId);
        if (! $passport) return $this->blocked('ACADEMY_PASSPORT_NOT_FOUND');
        $frozen = json_decode((string) $passport->frozen_upstream_contract, true) ?: [];
        if ((int) $passport->stage_depth !== 0 || ($frozen['protocol'] ?? null) !== self::PROTOCOL) {
            return $this->blocked('ACADEMY_PROSPECTIVE_DEPTH_ZERO_SOURCE_REQUIRED');
        }
        $preview = $this->previewColdStartExperiment((array) ($frozen['baseline_parameters'] ?? []));
        if (($preview['status'] ?? null) !== 'previewed') return $preview;
        return $this->plan($passportId, $preview['trial_type'], $preview['axis'], $preview['arms'], $preview['event_density_contract']);
    }

    private function contextLocationProbeDefinition(array $baselineParameters): array
    {
        $models = ['breakout_and_retest' => 'breakout_retest', 'pullback_rejection' => 'trend_continuation',
            'liquidity_sweep_reclaim' => 'false_break_reversal', 'range_reentry' => 'range_sweep', 'compression_expansion' => 'htf_reversal'];
        $model = $models[$baselineParameters['setup_topology_policy'] ?? '']
            ?? ($baselineParameters['entry_model'] ?? 'trend_continuation');
        if (! in_array($model, ['false_break_reversal', 'range_sweep', 'htf_reversal'], true)) {
            return $this->blocked('ACADEMY_AXIS_INACTIVE_IN_RUNTIME_MODEL', ['effective_entry_model' => $model]);
        }
        $baseline = $baselineParameters['location_tolerance_atr'] ?? null;
        $baseline = $this->finiteNumber($baseline);
        if ($baseline === null || $baseline < .05 || $baseline > 2.) return $this->blocked('ACADEMY_RUNTIME_LOCATION_BASELINE_REQUIRED');
        $candidate = round($baseline <= 1.9 ? $baseline + .1 : $baseline - .1, 6);
        $blinded = round($baseline < .15 ? $baseline + .2 : ($baseline > 1.9 ? $baseline - .2 : $baseline - .1), 6);
        return ['trial_type' => 'context_location_probe', 'axis' => 'location_tolerance_atr', 'arms' => [
            ['role' => 'frozen_control', 'value' => 'frozen_current'],
            ['role' => 'candidate', 'value' => $candidate],
            ['role' => 'blinded_control', 'value' => $blinded],
        ], 'event_density_contract' => ['minimum_setup_events' => 20, 'minimum_trigger_events' => 12, 'minimum_closed_trades' => 8]];
    }

    public function planSetupTopologyProbe(int $passportId): array
    {
        $definition = $this->setupTopologyProbeDefinition();
        return $this->plan($passportId, $definition['trial_type'], $definition['axis'], $definition['arms'], $definition['event_density_contract']);
    }

    private function setupTopologyProbeDefinition(): array
    {
        return ['trial_type' => 'setup_topology_probe', 'axis' => 'setup_topology_policy', 'arms' => [
            ['role' => 'frozen_control', 'value' => 'frozen_current'],
            ['role' => 'candidate', 'value' => 'breakout_and_retest'],
            ['role' => 'candidate', 'value' => 'liquidity_sweep_reclaim'],
            ['role' => 'blinded_control', 'value' => 'pullback_rejection'],
        ], 'event_density_contract' => ['minimum_setup_events' => 20, 'minimum_trigger_events' => 12, 'minimum_closed_trades' => 8]];
    }

    public function planEntryGeometryProbe(int $passportId): array
    {
        return $this->planNumericProbe($passportId, 'entry_geometry_probe', 'max_chase_atr');
    }

    public function planManagementCaptureProbe(int $passportId): array
    {
        // ATR target is overridden by this strategy's structural target;
        // time-stop is consumed by the actual position lifecycle instead.
        return $this->planNumericProbe($passportId, 'management_capture_probe', 'time_stop_candles');
    }

    private function planNextCurriculumTrial(int $passportId): array
    {
        $progress = $this->learningProgress($passportId);
        if (in_array(data_get($progress, 'recommendation.action'), ['hold_budget', 'repair_prerequisite'], true)) {
            return $this->blocked('ACADEMY_LEARNING_PROGRESS_HOLDS_NEW_WORK', ['learning_progress' => $progress, 'dispatch_allowed' => false]);
        }
        $depth = (int) DB::table('edge_academy_passports')->where('id', $passportId)->value('stage_depth');
        return match ($depth) {
            1 => $this->planSetupTopologyProbe($passportId),
            2 => $this->planConfirmationMarginalValue($passportId),
            3 => $this->planTriggerTopologyTournament($passportId),
            4 => $this->planEntryGeometryProbe($passportId),
            5 => $this->planManagementCaptureProbe($passportId),
            default => $this->blocked('INDEPENDENT_CURRICULUM_PROOF_REQUIRED'),
        };
    }

    private function planNumericProbe(int $passportId, string $type, string $axis): array
    {
        if (! $this->available()) return $this->blocked('EDGE_ACADEMY_REGISTRY_UNAVAILABLE');
        $passport = DB::table('edge_academy_passports')->find($passportId);
        if (! $passport) return $this->blocked('ACADEMY_PASSPORT_NOT_FOUND');
        $frozen = json_decode((string) $passport->frozen_upstream_contract, true) ?: [];
        $baseline = data_get($frozen, "baseline_parameters.{$axis}");
        $schema = app(StrategyParameterSchemaService::class)->schema('confirmation_entry_mtf');
        [$kind, $minimum, $maximum] = array_pad($schema[$axis] ?? [], 3, null);
        if (! in_array($kind, ['numeric', 'integer'], true) || $this->finiteNumber($baseline) === null
            || ($kind === 'integer' && ! is_int($baseline)) || $baseline < $minimum || $baseline > $maximum) {
            return $this->blocked('ACADEMY_RUNTIME_NUMERIC_BASELINE_REQUIRED', ['axis' => $axis]);
        }
        $step = $kind === 'integer' ? 1 : .1;
        $candidate = $baseline + $step <= $maximum ? $baseline + $step : $baseline - $step;
        $blinded = $baseline - $step >= $minimum ? $baseline - $step : $baseline + (2 * $step);
        if ($blinded === $candidate) $blinded = $baseline - (2 * $step);
        if ($blinded < $minimum || $blinded > $maximum) return $this->blocked('ACADEMY_TWO_LEGAL_NUMERIC_INTERVENTIONS_REQUIRED');
        if ($kind === 'integer') { $candidate = (int) $candidate; $blinded = (int) $blinded; }
        else { $candidate = round($candidate, 6); $blinded = round($blinded, 6); }
        return $this->plan($passportId, $type, $axis, [
            ['role' => 'frozen_control', 'value' => 'frozen_current'],
            ['role' => 'candidate', 'value' => $candidate], ['role' => 'blinded_control', 'value' => $blinded],
        ], ['minimum_setup_events' => 20, 'minimum_trigger_events' => 12, 'minimum_closed_trades' => 8]);
    }

    public function kpis(string $symbol, string $timeframe): array
    {
        if (! $this->available()) return $this->blocked('EDGE_ACADEMY_REGISTRY_UNAVAILABLE');
        $scope = DB::table('edge_academy_passports')->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe));
        return ['protocol' => self::PROTOCOL, 'passports' => (clone $scope)->count(), 'deepest_stage' => (clone $scope)->max('stage_depth') ?? 0,
            'retired_oracle_cells' => DB::table('edge_academy_oracle_gaps')->where('status', 'oracle_negative_retire_context_cell')->count(),
            'retained_beams' => DB::table('edge_academy_beams')->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->where('status', 'beam_retained')->count(),
            'planned_trials' => DB::table('edge_academy_trials')->whereIn('edge_academy_passport_id', (clone $scope)->pluck('id'))->where('status', 'planned')->count(), 'promotion_evidence' => false];
    }

    private function plan(int $passportId, string $type, string $axis, array $values, array $density): array
    {
        if (! $this->available()) return $this->blocked('EDGE_ACADEMY_REGISTRY_UNAVAILABLE');
        $passport = DB::table('edge_academy_passports')->find($passportId);
        if (! $passport) return $this->blocked('ACADEMY_PASSPORT_NOT_FOUND');
        $curriculum = json_decode((string) $passport->curriculum, true) ?: [];
        if (! in_array($axis, (array) ($curriculum['permitted_axes'] ?? []), true)) return $this->blocked('CURRICULUM_FORBIDS_MUTATION_AXIS', ['permitted_axes' => $curriculum['permitted_axes'] ?? []]);
        $frozen = json_decode((string) $passport->frozen_upstream_contract, true) ?: [];
        $arms = $this->explicitArms($values, $axis, $frozen);
        if ($arms === []) return $this->blocked('EXPLICIT_ACADEMY_ARM_ROLES_REQUIRED');
        $key = $this->hash([self::PROTOCOL, $passportId, $type, $axis, $arms, $frozen]);
        $existing = DB::table('edge_academy_trials')->where('trial_key', $key)->first();
        if ($existing && $existing->settled_at !== null) {
            // Evidence is immutable.  Replanning returns the original plan
            // but never turns a settled trial back into planned work.
            return ['protocol' => self::PROTOCOL, 'status' => (string) $existing->status,
                'trial_type' => $type, 'axis' => $axis,
                'arms' => json_decode((string) $existing->arms, true) ?: [],
                'event_density_contract' => json_decode((string) $existing->density_contract, true) ?: [],
                'learning_progress' => $this->learningProgress($passportId, $axis), 'terminal' => true, 'promotion_evidence' => false];
        }
        if (! $existing) {
            $progress = $this->learningProgress($passportId, $axis);
            if (data_get($progress, 'registered_comparable_trials', 0) >= 6) {
                return $this->blocked('ACADEMY_PREREGISTERED_TASK_TRIAL_CAP_REACHED', ['learning_progress' => $progress]);
            }
            $created = now();
            DB::table('edge_academy_trials')->insert([
                'trial_key' => $key, 'edge_academy_passport_id' => $passportId, 'trial_type' => $type,
                'status' => 'planned', 'frozen_contract' => json_encode($frozen), 'arms' => json_encode($arms),
                'density_contract' => json_encode($density), 'created_at' => $created, 'updated_at' => $created,
            ]);
            $newTrial = DB::table('edge_academy_trials')->where('trial_key', $key)->first();
            $curriculum['learning_progress_tasks'][$key] = $this->learningTask($passport, $newTrial);
            DB::table('edge_academy_passports')->where('id', $passportId)->update(['curriculum' => json_encode($curriculum), 'updated_at' => now()]);
        }
        $trialId = (int) ($existing?->id ?? DB::table('edge_academy_trials')->where('trial_key', $key)->value('id'));
        return ['protocol' => self::PROTOCOL, 'status' => $existing?->status ?? 'planned', 'trial_id' => $trialId,
            'trial_type' => $type, 'axis' => $axis, 'arms' => $arms, 'event_density_contract' => $density,
            'learning_progress' => $this->learningProgress($passportId, $axis), 'promotion_evidence' => false];
    }

    /** Local replay learning, not forecast calibration or independent market skill. */
    public function learningProgress(int $passportId, ?string $axis = null): array
    {
        $base = ['protocol' => self::LEARNING_PROGRESS_PROTOCOL, 'status' => 'unmeasured',
            'metric' => 'unreached_target_stage_fraction', 'metric_is_correctness_or_economic_loss' => false,
            'verified_comparable_trials' => 0, 'error_reduction' => null, 'uncertainty_reduction' => null,
            'independent_validation_proven' => false, 'economic_authority' => false,
            'credit_authority' => false, 'paper_authority' => false, 'parent_authority' => false, 'promotion_evidence' => false];
        if (! $this->available()) return [...$base, 'reason' => 'EDGE_ACADEMY_REGISTRY_UNAVAILABLE'];
        $passport = DB::table('edge_academy_passports')->find($passportId);
        if (! $passport) return [...$base, 'reason' => 'ACADEMY_PASSPORT_NOT_FOUND'];
        $curriculum = json_decode((string) $passport->curriculum, true) ?: [];
        $frozen = json_decode((string) $passport->frozen_upstream_contract, true) ?: [];
        if ($axis === null && (int) $passport->stage_depth === 0) {
            $location = $this->contextLocationProbeDefinition((array) ($frozen['baseline_parameters'] ?? []));
            $axis = ($location['reason'] ?? null) === 'ACADEMY_AXIS_INACTIVE_IN_RUNTIME_MODEL'
                ? 'setup_topology_policy' : 'location_tolerance_atr';
        }
        $axis ??= match ((int) $passport->stage_depth) {
            0 => 'location_tolerance_atr', 1 => 'setup_topology_policy', 2 => 'confirmation_family_policy',
            3 => 'trigger_topology_policy', 4 => 'max_chase_atr', 5 => 'time_stop_candles', default => '',
        };
        if (! in_array($axis, (array) ($curriculum['permitted_axes'] ?? []), true)) return [...$base, 'reason' => 'CURRICULUM_FORBIDS_MUTATION_AXIS'];
        $scope = $this->learningScope($passport, $axis);
        $prerequisite = $this->prerequisiteEvidence($passport);
        $resources = ['maximum_comparable_trials' => 6, 'rolling_trial_window' => 4,
            'maximum_primary_arms_per_trial' => 5, 'new_scope_does_not_reset_cold_start_budget' => true,
            'replay_cpu_measured' => false, 'canonical_dispatcher_budget_unchanged' => true];
        $result = [...$base, 'passport_id' => $passportId, 'axis' => $axis, 'task_scope_key' => $scope['scope_key'],
            'resources' => $resources, 'prerequisite' => $prerequisite,
            'recommendation' => ['action' => 'continue_bounded_probe', 'axis' => $axis, 'dispatch_allowed' => false]];
        if (! $prerequisite['eligible'] && (int) $passport->stage_depth > 0
            && filled(data_get($frozen, 'prospective_source_identity.source_evaluator_hash'))) {
            return [...$result, 'status' => 'blocked_prerequisite', 'reason' => $prerequisite['reason'],
                'recommendation' => ['action' => 'repair_prerequisite', 'required_stage' => $scope['upstream_stage'], 'dispatch_allowed' => false]];
        }
        $observations = []; $excluded = []; $seen = []; $registered = 0;
        // Scope bounded: only passports for this market/timeframe and newest 64
        // settled tasks are examined. Caller-provided outcome labels are unused.
        $trials = DB::table('edge_academy_trials as t')->join('edge_academy_passports as p', 'p.id', '=', 't.edge_academy_passport_id')
            ->where('p.symbol', $passport->symbol)->where('p.timeframe', $passport->timeframe)
            ->where('p.curriculum', 'like', '%'.$scope['scope_key'].'%')
            ->orderByDesc('t.id')->limit(64)->select('t.*')->get()->reverse();
        foreach ($trials as $trial) {
            $owner = DB::table('edge_academy_passports')->find($trial->edge_academy_passport_id);
            $task = $this->verifiedLearningTask($owner, $trial);
            if (! $task || $task['scope_key'] !== $scope['scope_key']) continue;
            $registered++;
            if ($trial->settled_at === null) continue;
            if ((int) $owner->stage_depth > 0 && ! $this->prerequisiteEvidence($owner)['eligible']) {
                $excluded[] = ['trial_id' => (int) $trial->id, 'reason' => 'ORIGINAL_TRIAL_PREREQUISITES_REQUIRED']; continue;
            }
            $pairs = $this->verifiedTrialComparisons($trial);
            if ($pairs === []) { $excluded[] = ['trial_id' => (int) $trial->id, 'reason' => 'ORIGINAL_COMPARABLE_ARM_EVIDENCE_REQUIRED']; continue; }
            if (! collect($pairs)->contains(fn (array $pair): bool => ! $pair['null_intervention'])) {
                $excluded[] = ['trial_id' => (int) $trial->id, 'reason' => 'NON_NULL_PREREGISTERED_COMPARISON_REQUIRED']; continue;
            }
            $eligible = array_values(array_filter($pairs, fn (array $pair): bool => $pair['upstream_count'] >= $task['minimum_upstream_events']));
            if (count($eligible) !== count($pairs)) { $excluded[] = ['trial_id' => (int) $trial->id, 'reason' => 'UNDERPOWERED_TARGET_TRANSITION']; continue; }
            // Every candidate in the preregistered roster contributes; the best
            // arm is never selected retrospectively as the progress statistic.
            $witnesses = array_column($eligible, 'witness_key'); sort($witnesses);
            $identity = $this->canonicalHash($witnesses);
            if (isset($seen[$identity])) continue;
            $seen[$identity] = true;
            $observations[] = ['trial_id' => (int) $trial->id, 'settled_at' => $trial->settled_at,
                'error' => array_sum(array_column($eligible, 'target_deficit')) / count($eligible),
                'candidate_count' => count($eligible), 'witness_keys' => $witnesses,
                'minimum_upstream_count' => min(array_column($eligible, 'upstream_count'))];
        }
        usort($observations, fn (array $left, array $right): int =>
            [$left['settled_at'], $left['trial_id']] <=> [$right['settled_at'], $right['trial_id']]);
        $count = count($observations); $window = array_slice($observations, -4);
        $result = [...$result, 'verified_comparable_trials' => $count, 'registered_comparable_trials' => $registered,
            'observations' => $window, 'excluded_trials' => $excluded,
            'evidence_scope' => 'same_frozen_replay_task_not_independent_market_replication',
            'uncertainty_metric' => 'between_trial_deficit_range_not_statistical_confidence'];
        if (count($window) < 4) return [...$result, 'status' => $count === 0 ? 'unmeasured' : 'underpowered',
            'reason' => 'FOUR_PREREGISTERED_COMPLETED_COMPARABLE_TRIALS_REQUIRED',
            'recommendation' => ['action' => $registered >= 6 ? 'hold_budget' : 'continue_bounded_probe', 'axis' => $axis,
                'reason' => $registered >= 6 ? 'PREREGISTERED_TASK_TRIAL_CAP_REACHED' : 'OBSERVATIONS_NOT_YET_POWERED', 'dispatch_allowed' => false]];
        $early = array_column(array_slice($window, 0, 2), 'error'); $recent = array_column(array_slice($window, 2), 'error');
        $before = array_sum($early) / 2; $after = array_sum($recent) / 2;
        $oldRange = max($early) - min($early); $newRange = max($recent) - min($recent);
        $gain = $before - $after;
        $status = $after <= .05 && $newRange <= .05 ? 'mastered_local'
            : ($newRange > .25 ? 'noisy_plateau' : ($gain >= .05 && $newRange <= max(.1, $oldRange) + 1e-9 ? 'learning_progress' : 'plateau'));
        $hold = in_array($status, ['plateau', 'noisy_plateau'], true) || $registered >= 6;
        $action = $hold ? 'hold_budget' : ($status === 'mastered_local' ? 'adjacent_legal_challenge' : 'continue_bounded_probe');
        $next = $this->adjacentChallenge($passport, $axis, $prerequisite);
        return [...$result, 'status' => $status, 'error_before' => $before, 'error_after' => $after,
            'error_reduction' => $gain, 'uncertainty_before' => $oldRange, 'uncertainty_after' => $newRange,
            'uncertainty_reduction' => $oldRange - $newRange,
            'recommendation' => ['action' => $action, 'axis' => $axis, 'adjacent_challenge' => $next,
                'reason' => $registered >= 6 ? 'PREREGISTERED_TASK_TRIAL_CAP_REACHED' : 'MEASURED_PROGRESS_NOT_SURPRISE', 'dispatch_allowed' => false]];
    }

    /** An edge is observed enabling work, not proof that a skill is necessary. */
    public function skillDependencyGraph(int $passportId): array
    {
        $base = ['protocol' => 'academy_observed_skill_dependency_graph_v1', 'status' => 'unmeasured', 'edges' => [],
            'observed_research_enabling_value' => 0, 'dependency_necessity_proven' => false,
            'causal_enabling_value_proven' => false, 'economic_authority' => false,
            'credit_authority' => false, 'paper_authority' => false, 'parent_authority' => false, 'promotion_evidence' => false];
        if (! $this->available()) return [...$base, 'reason' => 'EDGE_ACADEMY_REGISTRY_UNAVAILABLE'];
        $passport = DB::table('edge_academy_passports')->find($passportId);
        if (! $passport) return [...$base, 'reason' => 'ACADEMY_PASSPORT_NOT_FOUND'];
        $prerequisite = $this->prerequisiteEvidence($passport);
        if (! $prerequisite['eligible']) return [...$base, 'status' => 'blocked_prerequisite', 'reason' => $prerequisite['reason']];
        $edges = []; $value = 0;
        foreach ($prerequisite['lineage'] as $link) {
            $owner = DB::table('edge_academy_passports')->find($link['successor_passport_id']);
            foreach (DB::table('edge_academy_trials')->where('edge_academy_passport_id', $owner->id)->orderBy('id')->limit(6)->get() as $trial) {
                $task = $this->verifiedLearningTask($owner, $trial);
                if (! $task || data_get($task, 'prerequisite.trial_id') !== $link['source_trial_id']) continue;
                $pairs = $trial->settled_at === null ? [] : $this->verifiedTrialComparisons($trial);
                $powered = array_values(array_filter($pairs, fn (array $pair): bool => $pair['upstream_count'] >= $task['minimum_upstream_events']));
                $answered = $powered !== [] && collect($powered)->contains(fn (array $pair): bool => ! $pair['null_intervention']);
                $positive = collect($powered)->contains(fn (array $pair): bool => $pair['assessment']['status'] === 'controllable' && $pair['target_deficit'] < $pair['control_deficit']);
                $value += (int) $answered;
                $edges[] = [...$link, 'downstream_trial_id' => (int) $trial->id, 'downstream_task_scope_key' => $task['scope_key'],
                    'status' => $answered ? 'completed_answered_downstream_work' : ($trial->settled_at === null ? 'unlocked_preregistered_work' : 'underpowered_or_invalid_downstream_work'),
                    'completed_answered' => $answered, 'positive_stage_effect' => $positive,
                    'downstream_witness_keys' => array_column($powered, 'witness_key'),
                    'confounding_guard' => 'exact_source_baseline_single_axis_preserved_upstream', 'economic_value' => null];
            }
        }
        return [...$base, 'status' => $edges === [] ? 'verified_prerequisite_without_downstream_work' : 'observed_dependency_work',
            'edges' => $edges, 'observed_research_enabling_value' => $value];
    }

    private function learningScope(object $passport, string $axis): array
    {
        $frozen = json_decode((string) $passport->frozen_upstream_contract, true) ?: [];
        $parameters = (array) ($frozen['baseline_parameters'] ?? []); unset($parameters[$axis]);
        $target = match ($axis) { 'location_tolerance_atr' => 'location', 'setup_topology_policy' => 'setup',
            'confirmation_family_policy' => 'confirmation', 'trigger_topology_policy' => 'trigger',
            'max_chase_atr', 'minimum_reward_space_r' => 'entry', default => 'closed_trade' };
        $upstream = match ($target) { 'location', 'setup' => 'opportunity', 'confirmation' => 'setup',
            'trigger' => 'confirmation', 'entry' => 'trigger', default => 'entry' };
        $source = (array) ($frozen['prospective_source_identity'] ?? []);
        $baseline = ModelVersion::query()->find((int) ($frozen['baseline_model_version_id'] ?? 0));
        $scope = ['symbol' => $passport->symbol, 'timeframe' => $passport->timeframe, 'strategy' => $frozen['strategy'] ?? null,
            'axis' => $axis, 'target_stage' => $target, 'upstream_stage' => $upstream,
            'context' => $frozen['context'] ?? [], 'temporal_roles' => $frozen['temporal_roles'] ?? [],
            'risk' => $frozen['risk'] ?? [], 'management' => $frozen['management'] ?? [], 'frozen_non_axis_parameters' => $parameters,
            'frozen_runtime_basis' => $baseline ? app(LabImmutableEvidenceService::class)->modelRuntimeBasis($baseline) : null,
            'source' => array_intersect_key($source, array_flip(['mtf_bundle_hash', 'mtf_bundle_manifest', 'execution_hash',
                'source_evaluator_hash', 'python_source_hash', 'source_identity_protocol']))];
        return [...$scope, 'scope_key' => $this->canonicalHash($scope)];
    }

    private function learningTask(object $passport, object $trial): array
    {
        $arms = json_decode((string) $trial->arms, true) ?: [];
        $frozen = json_decode((string) $trial->frozen_contract, true) ?: [];
        $source = (array) ($frozen['prospective_source_identity'] ?? []);
        $task = [...$this->learningScope($passport, (string) ($arms[0]['changed_axis'] ?? '')),
            'protocol' => self::LEARNING_PROGRESS_PROTOCOL, 'trial_key' => $trial->trial_key,
            'trial_created_at' => $trial->created_at, 'trial_type' => $trial->trial_type,
            'baseline_parameter_hash' => $frozen['baseline_parameter_hash'] ?? null,
            'frozen_contract_hash' => $this->canonicalHash($frozen), 'arm_roster_hash' => $this->canonicalHash($arms),
            'density_contract_hash' => $this->canonicalHash(json_decode((string) $trial->density_contract, true) ?: []),
            'metric' => 'unreached_target_stage_fraction', 'minimum_completed_comparable_trials' => 4,
            'minimum_upstream_events' => 20, 'rolling_trial_window' => 4, 'maximum_comparable_trials' => 6,
            'minimum_error_reduction' => .05, 'maximum_recent_deficit_range' => .25,
            'maximum_primary_arms' => 5, 'prerequisite' => [
                'trial_id' => (int) ($source['source_academy_trial_id'] ?? 0),
                'control_run_id' => $source['source_control_run_id'] ?? null, 'candidate_run_id' => $source['source_candidate_run_id'] ?? null,
                'candidate_response_hash' => $source['source_response_hash'] ?? null],
            'research_only' => true, 'economic_authority' => false, 'promotion_evidence' => false];
        return [...$task, 'task_seal' => $this->canonicalHash($task)];
    }

    private function verifiedLearningTask(?object $passport, object $trial): ?array
    {
        if (! $passport) return null;
        $task = data_get(json_decode((string) $passport->curriculum, true), 'learning_progress_tasks.'.$trial->trial_key);
        if (! is_array($task) || $task !== $this->learningTask($passport, $trial)) return null;
        if ($trial->settled_at !== null && $task['trial_created_at'] > $trial->settled_at) return null;
        return $task;
    }

    /** Original complete roster, actual parameters and immutable semantic receipts. */
    private function verifiedTrialComparisons(object $trial): array
    {
        $frozen = json_decode((string) $trial->frozen_contract, true) ?: [];
        $evidence = app(LabImmutableEvidenceService::class); $director = app(CausalStageMasteryDirectorService::class);
        $compiler = app(AcademyExperimentContractCompilerService::class);
        $agents = LabAgent::query()->with('modelVersion')->whereHas('modelVersion', fn ($query) =>
            $query->where('metadata->academy_experiment->academy_trial_id', (int) $trial->id))->get();
        if ($agents->isEmpty() || $agents->pluck('lab_generation_id')->unique()->count() !== 1) return [];
        try {
            if (! $this->primaryRosterComplete($trial, $frozen, (int) $agents->first()->lab_generation_id)) return [];
            $control = $agents->first(fn (LabAgent $agent): bool => data_get($agent->modelVersion?->metadata, 'academy_experiment.arm_role') === 'frozen_control');
            if (! $control) return [];
            $arms = json_decode((string) $trial->arms, true) ?: []; $axis = (string) ($arms[0]['changed_axis'] ?? '');
            $scope = $this->learningScope(DB::table('edge_academy_passports')->find($trial->edge_academy_passport_id), $axis);
            $metrics = []; $runs = [];
            foreach ($agents as $agent) {
                $run = LabEvaluationRun::query()->where('lab_agent_id', $agent->id)->where('model_version_id', $agent->model_version_id)
                    ->where('phase', 'screening')->where('status', 'completed')->latest('id')->first();
                $identity = $run ? $evidence->verifiedModelRuntimeIdentity($run) : null;
                if (! $run || ! $identity || ! $run->started_at || ! $run->finished_at
                    || $run->started_at->lt($trial->created_at)) return [];
                $requestArtifact = LabEvidenceArtifact::query()->where('run_id', $run->run_id)->where('artifact_type', 'evaluation_request')
                    ->where('sha256', $identity['request_artifact_hash'])->oldest('id')->first();
                $request = $requestArtifact ? $evidence->readArtifactPayload($requestArtifact) : null;
                $actualParameters = is_array($request) ? ($request['parameters'] ?? null) : null;
                if ($actualParameters === null && is_array($request)) {
                    $actualParameters = collect($request['agents'] ?? [])->first(fn ($item): bool =>
                        is_array($item) && ($item['lab_agent_id'] ?? null) === (int) $agent->id)['parameters'] ?? null;
                }
                if (! is_array($actualParameters) || ! $evidence->equivalentJsonValue($actualParameters, (array) $agent->modelVersion->parameters)) return [];
                $payload = $evidence->latestArtifactPayload($run);
                if (! is_array($payload) || ! $director->decisionIdentityValid((array) data_get($payload, 'data_quality.decision_identity_receipt', []))) return [];
                $runs[$agent->id] = $run; $metrics[$agent->id] = $payload;
            }
            $pairs = [];
            foreach ($agents as $candidate) {
                if (data_get($candidate->modelVersion?->metadata, 'academy_experiment.arm_role') !== 'candidate') continue;
                if ($this->canonicalHash($evidence->modelRuntimeBasis($control->modelVersion))
                    !== $this->canonicalHash($evidence->modelRuntimeBasis($candidate->modelVersion))) return [];
                $axisProof = $director->inferAxis((array) $control->modelVersion->parameters, (array) $candidate->modelVersion->parameters);
                // A categorical tournament may deliberately include its
                // baseline policy as a null candidate. Keep it in the roster
                // statistic; never mislabel it as an intervention/effect.
                $null = ($axisProof['reason'] ?? null) === 'NO_PARAMETER_CHANGE';
                if (! $null && (($axisProof['status'] ?? null) !== 'single_axis' || ($axisProof['gene'] ?? null) !== $axis)) return [];
                $assessment = $director->assess($axis, [...$metrics[$control->id], 'value' => $control->modelVersion->parameters[$axis]],
                    [...$metrics[$candidate->id], 'value' => $candidate->modelVersion->parameters[$axis]]);
                if (($assessment['evidence_assessable'] ?? false) !== true) return [];
                $upstream = (int) data_get($metrics[$control->id], 'data_quality.decision_identity_receipt.stage_identities.'.$scope['upstream_stage'].'.event_count', 0);
                $old = (int) data_get($metrics[$control->id], 'data_quality.decision_identity_receipt.stage_identities.'.$scope['target_stage'].'.event_count', 0);
                $new = (int) data_get($metrics[$candidate->id], 'data_quality.decision_identity_receipt.stage_identities.'.$scope['target_stage'].'.event_count', 0);
                if ($upstream <= 0 || max($old, $new) > $upstream) return [];
                $pairs[] = ['control_run_id' => $runs[$control->id]->run_id, 'candidate_run_id' => $runs[$candidate->id]->run_id,
                    'control_response_hash' => $runs[$control->id]->response_hash, 'candidate_response_hash' => $runs[$candidate->id]->response_hash,
                    'candidate_model_version_id' => $candidate->model_version_id,
                    'candidate_parameter_hash' => $compiler->parameterHash((array) $candidate->modelVersion->parameters),
                    'upstream_count' => $upstream, 'provided_stage_event_count' => $new,
                    'control_deficit' => 1 - ($old / $upstream), 'target_deficit' => 1 - ($new / $upstream),
                    'null_intervention' => $null, 'assessment' => $assessment,
                    // Full response hashes are still reverified above. They
                    // include transport/checkpoint metadata and are not new
                    // scientific observations when the consumed events agree.
                    'witness_key' => $this->canonicalHash([$scope['scope_key'],
                        $compiler->parameterHash((array) $control->modelVersion->parameters),
                        $compiler->parameterHash((array) $candidate->modelVersion->parameters),
                        data_get($metrics[$control->id], 'data_quality.decision_identity_receipt.candle_domain'),
                        data_get($metrics[$candidate->id], 'data_quality.decision_identity_receipt.candle_domain'),
                        data_get($metrics[$control->id], 'data_quality.decision_identity_receipt.stage_identities'),
                        data_get($metrics[$candidate->id], 'data_quality.decision_identity_receipt.stage_identities')])];
            }
            return $pairs;
        } catch (\Throwable) { return []; }
    }

    private function prerequisiteEvidence(object $passport): array
    {
        $lineage = []; $seen = []; $current = $passport;
        for ($depth = 0; $depth < 16; $depth++) {
            if (isset($seen[$current->id])) return ['eligible' => false, 'reason' => 'ACADEMY_PREREQUISITE_CYCLE', 'lineage' => []];
            $seen[$current->id] = true;
            $frozen = json_decode((string) $current->frozen_upstream_contract, true) ?: [];
            $source = (array) ($frozen['prospective_source_identity'] ?? []);
            $sourceId = (int) ($source['source_academy_trial_id'] ?? 0);
            if ($sourceId === 0) return ['eligible' => (int) $current->stage_depth === 0,
                'reason' => (int) $current->stage_depth === 0 ? 'ACADEMY_ROOT_TASK_NO_PREREQUISITE' : 'ACADEMY_RECEIPT_BOUND_PREREQUISITE_REQUIRED', 'lineage' => $lineage];
            $trial = DB::table('edge_academy_trials')->find($sourceId);
            $owner = $trial ? DB::table('edge_academy_passports')->find($trial->edge_academy_passport_id) : null;
            if ($owner && isset($seen[$owner->id])) return ['eligible' => false, 'reason' => 'ACADEMY_PREREQUISITE_CYCLE', 'lineage' => []];
            if (! $trial || ! $owner || $trial->settled_at === null || ! $this->verifiedLearningTask($owner, $trial)
                || (int) $owner->stage_depth >= (int) $current->stage_depth
                || $owner->symbol !== $current->symbol || $owner->timeframe !== $current->timeframe) {
                return ['eligible' => false, 'reason' => 'ACADEMY_ORIGINAL_PREREQUISITE_TRIAL_REQUIRED', 'lineage' => []];
            }
            $proof = data_get(json_decode((string) $trial->outcome, true), 'stage_progress', []);
            $original = json_decode((string) $trial->frozen_contract, true) ?: [];
            foreach (['context', 'temporal_roles', 'risk', 'management'] as $binding) {
                if ($this->canonicalHash((array) ($original[$binding] ?? [])) !== $this->canonicalHash((array) ($frozen[$binding] ?? []))) {
                    return ['eligible' => false, 'reason' => 'ACADEMY_PREREQUISITE_SCOPE_CONFOUNDED', 'lineage' => []];
                }
            }
            $pairs = $this->verifiedTrialComparisons($trial);
            $witness = collect($pairs)->first(fn (array $pair): bool => $pair['control_run_id'] === ($source['source_control_run_id'] ?? null)
                && $pair['candidate_run_id'] === ($source['source_candidate_run_id'] ?? null)
                && $pair['candidate_response_hash'] === ($source['source_response_hash'] ?? null)
                && $pair['candidate_model_version_id'] === ($frozen['baseline_model_version_id'] ?? null)
                && $pair['candidate_parameter_hash'] === ($frozen['baseline_parameter_hash'] ?? null)
                && $pair['assessment']['status'] === 'controllable' && $pair['target_deficit'] < $pair['control_deficit']
                && $pair['upstream_count'] >= 20 && $pair['provided_stage_event_count'] >= 20);
            $expectedDepth = $witness ? match ($witness['assessment']['target_stage']) {
                'location' => 1, 'setup' => 2, 'confirmation' => 3, 'trigger' => 4, 'entry', 'closed_trade' => 5, default => 0,
            } : 0;
            if (! $witness || ($proof['status'] ?? null) !== 'observed_stage_controllability'
                || ($proof['successor_passport_id'] ?? null) !== (int) $current->id
                || $expectedDepth !== (int) $current->stage_depth
                || $this->canonicalHash((array) ($proof['assessment'] ?? [])) !== $this->canonicalHash($witness['assessment'])) {
                return ['eligible' => false, 'reason' => 'ACADEMY_IMMUTABLE_POSITIVE_PREREQUISITE_REQUIRED', 'lineage' => []];
            }
            $lineage[] = ['source_trial_id' => $sourceId, 'source_passport_id' => (int) $owner->id,
                'successor_passport_id' => (int) $current->id, 'source_candidate_run_id' => $witness['candidate_run_id'],
                'source_control_run_id' => $witness['control_run_id'], 'source_response_hash' => $witness['candidate_response_hash'],
                'provided_stage' => $witness['assessment']['target_stage'], 'provided_stage_event_count' => $witness['provided_stage_event_count'],
                'baseline_parameter_hash' => $witness['candidate_parameter_hash']];
            $current = $owner;
        }
        return ['eligible' => false, 'reason' => 'ACADEMY_PREREQUISITE_DEPTH_CAP_REACHED', 'lineage' => []];
    }

    private function adjacentChallenge(object $passport, string $axis, array $prerequisite): array
    {
        $legal = (array) data_get(json_decode((string) $passport->curriculum, true), 'permitted_axes', []);
        return ['axis' => $axis, 'permitted_axes' => $legal,
            'kind' => 'next_schema_bounded_single_axis_variant', 'requires_fresh_trial_preregistration' => true,
            'new_context_requires_new_source_and_prerequisites' => true, 'prerequisite_verified' => $prerequisite['eligible'],
            'changes_risk_or_frozen_upstream' => false, 'dispatch_allowed' => false];
    }

    /** A harder legal threshold, not a new context, risk axis or arbitrary gene. */
    private function planAdjacentChallenge(object $passport, string $axis): array
    {
        $frozen = json_decode((string) $passport->frozen_upstream_contract, true) ?: [];
        $baseline = data_get($frozen, 'baseline_parameters.'.$axis);
        $schema = app(StrategyParameterSchemaService::class)->schema('confirmation_entry_mtf');
        [$kind, $minimum, $maximum] = array_pad($schema[$axis] ?? [], 3, null);
        if (! in_array($kind, ['numeric', 'integer'], true) || $this->finiteNumber($baseline) === null) {
            return $this->blocked('ACADEMY_ADJACENT_NUMERIC_CHALLENGE_REQUIRED', ['axis' => $axis]);
        }
        $step = $kind === 'integer' ? 2 : .2;
        // Tightening a location/chase threshold is a bounded adjacent stress
        // challenge. It is not evidence of transfer outside this frozen task.
        $candidate = $baseline - $step; $blind = $baseline + $step;
        if ($candidate < $minimum || $blind > $maximum) return $this->blocked('ACADEMY_ADJACENT_LEGAL_VALUES_EXHAUSTED');
        if ($kind === 'integer') { $candidate = (int) $candidate; $blind = (int) $blind; }
        else { $candidate = round($candidate, 6); $blind = round($blind, 6); }
        return $this->plan((int) $passport->id, 'adjacent_'.$axis.'_challenge', $axis, [
            ['role' => 'frozen_control', 'value' => 'frozen_current'], ['role' => 'candidate', 'value' => $candidate],
            ['role' => 'blinded_control', 'value' => $blind],
        ], ['minimum_setup_events' => 20, 'minimum_trigger_events' => 12, 'minimum_closed_trades' => 8]);
    }

    private function canonicalHash(array $value): string { return app(ExecutionContractService::class)->hashParameters($value); }

    /** Planner semantics are explicit, never inferred from arm array order. */
    private function explicitArms(array $definitions, string $axis, array $frozen): array
    {
        $roles = ['frozen_control', 'candidate', 'blinded_control', 'ablation', 'counterfactual'];
        $arms = [];
        foreach ($definitions as $definition) {
            $definition = is_array($definition) ? $definition : ['role' => 'candidate', 'value' => $definition];
            $role = (string) ($definition['role'] ?? '');
            $value = $definition['value'] ?? null;
            if (! in_array($role, $roles, true) || (! is_scalar($value)) || $value === '') return [];
            $arms[] = ['name' => (string) $value, 'role' => $role, 'changed_axis' => $axis,
                'value' => $value, 'frozen_contract_hash' => $this->hash($frozen)];
        }
        return $arms;
    }

    private function curriculum(string $stage, array $frozen): array
    {
        $depth = $this->depth($stage);
        $axes = match (true) {
            $depth <= 0 => ['context', 'location_tolerance_atr', 'setup_topology_policy'], $depth === 1 => ['setup_topology_policy'],
            $depth === 2 => ['confirmation_family_policy'], $depth === 3 => ['trigger_topology_policy'],
            $depth === 4 => ['entry_mode', 'max_chase_atr', 'minimum_reward_space_r'],
            $depth === 5 => ['atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles'],
            default => ['tactic', 'temporal_binding', 'location'],
        };
        return ['protocol' => self::PROTOCOL, 'deepest_stage' => $stage, 'permitted_axes' => $axes,
            'forbidden_axes' => array_values(array_diff(['context', 'setup_topology_policy', 'confirmation_family_policy', 'trigger_topology_policy', 'entry_mode', 'max_chase_atr', 'minimum_reward_space_r', 'atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles', 'risk'], $axes)),
            'initial_experiments' => 'bounded_single_axis_research_with_exact_control', 'frozen_upstream_hash' => $this->hash($frozen), 'promotion_evidence' => false];
    }
    private function frozenContract(array $identity, string $stage): array { return ['protocol' => self::PROTOCOL, 'deepest_stage' => $stage, 'strategy' => $identity['strategy'] ?? $identity['strategy_family'] ?? null, 'tactic' => $identity['tactic'] ?? null, 'context' => $identity['context'] ?? [], 'temporal_roles' => $identity['temporal_roles'] ?? [], 'risk' => $identity['risk_contract'] ?? [], 'management' => $identity['management_contract'] ?? [], 'frozen_at_stage_depth' => $this->depth($stage),
        'declared_composition_key' => $identity['declared_composition_key'] ?? null,
        'baseline_model_version_id' => $identity['baseline_model_version_id'] ?? null,
        'baseline_parameters' => $identity['baseline_parameters'] ?? [],
        'baseline_parameter_hash' => $identity['baseline_parameter_hash'] ?? null,
        'prospective_source_identity' => $identity['prospective_source_identity'] ?? [],
    ]; }
    private function passportPayload(?object $row): array { if (! $row) return $this->blocked('ACADEMY_PASSPORT_WRITE_FAILED'); return ['protocol' => self::PROTOCOL, 'status' => $row->status, 'passport_id' => $row->id, 'passport_key' => $row->passport_key, 'deepest_stage' => $row->deepest_stage, 'curriculum' => json_decode((string) $row->curriculum, true), 'promotion_evidence' => false]; }
    private function normalizeStage(string $stage): string { return in_array($stage, self::STAGES, true) ? $stage : 'market_cartographer'; }
    private function beamStage(string $stage): string
    {
        if (in_array($stage, self::BEAM_STAGES, true)) return $stage;
        return match ($this->normalizeStage($stage)) {
            'market_cartographer', 'setup_apprentice' => 'setup',
            'confirmation_specialist', 'trigger_entry_specialist' => 'trigger',
            'execution_specialist', 'management_specialist' => 'economic',
            default => 'robustness',
        };
    }
    private function depth(string $stage): int { $index = array_search($stage, self::STAGES, true); return $index === false ? 0 : $index; }
    private function nonNegative(mixed $value): float { return max(0., is_numeric($value) ? (float) $value : 0.); }
    private function finiteNumber(mixed $value): ?float { return (is_int($value) || is_float($value)) && is_finite((float) $value) ? (float) $value : null; }
    private function nullableNonNegative(mixed $value): ?float { $number = $this->finiteNumber($value); return $number === null ? null : max(0., $number); }
    private function hash(mixed $value): string { return hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)); }
    private function available(): bool { return Schema::hasTable('edge_academy_passports'); }
    private function blocked(string $reason, array $extra = []): array { return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason' => $reason, ...$extra, 'promotion_evidence' => false]; }
}
