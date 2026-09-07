<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A research-only curriculum that turns a complete composition into bounded
 * component experiments.  It deliberately separates hindsight diagnostics
 * from executable policy and never grants live, parent, or promotion credit.
 */
class XauusdEdgeFormationAcademyService
{
    public const PROTOCOL = 'xauusd_edge_formation_academy_v1';
    public const STAGES = [
        'market_cartographer', 'setup_apprentice', 'confirmation_specialist',
        'trigger_entry_specialist', 'execution_specialist', 'management_specialist',
        'full_composition_master',
    ];
    public const BEAM_STAGES = ['setup', 'trigger', 'economic', 'robustness'];

    /** Register/update the frozen composition and expose only the next legal axis. */
    public function passport(string $symbol, string $timeframe, array $identity, array $evidence = []): array
    {
        if (! $this->available()) return $this->blocked('EDGE_ACADEMY_REGISTRY_UNAVAILABLE');
        $symbol = strtoupper($symbol); $timeframe = strtoupper($timeframe);
        $composition = (string) ($identity['composition_key'] ?? $this->hash($identity));
        $context = (string) ($identity['context_key'] ?? $this->hash((array) ($identity['context'] ?? [])));
        $temporal = (string) ($identity['temporal_binding_hash'] ?? $this->hash((array) ($identity['temporal_roles'] ?? [])));
        $stage = $this->normalizeStage((string) ($identity['deepest_stage'] ?? 'market_cartographer'));
        $key = $this->hash([self::PROTOCOL, $symbol, $timeframe, $composition, $context, $temporal]);
        $existing = DB::table('edge_academy_passports')->where('passport_key', $key)->first();
        if ($existing && (int) $existing->stage_depth > $this->depth($stage)) {
            $stage = $this->normalizeStage((string) $existing->deepest_stage);
        }
        $frozen = $this->frozenContract($identity, $stage);
        $curriculum = $this->curriculum($stage, $frozen);
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
        $valid = (bool) ($oracle['diagnostic_only'] ?? false) && ! (bool) ($oracle['runtime_signal'] ?? false)
            && ! (bool) ($oracle['promotion_evidence'] ?? false) && isset($oracle['opportunity_edge_r']);
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
        $oracleEdge = (float) $oracle['opportunity_edge_r'];
        $realizedEdge = array_key_exists('realized_after_cost_r', $realized) ? (float) $realized['realized_after_cost_r'] : $oracleEdge - array_sum($terms);
        $identified = array_filter($terms, fn ($value): bool => $value !== null);
        $dominant = $identified === [] ? null : array_key_first(collect($identified)->sortDesc()->all());
        $status = $oracleEdge <= 0 ? 'oracle_negative_retire_context_cell'
            : ($this->nonNegative($realized['real_entry_after_cost_r'] ?? $realizedEdge) <= 0 ? 'entry_mastery_required'
                : ($realizedEdge <= 0 ? 'management_or_cost_mastery_required' : 'realized_edge_observed'));
        $action = match ($status) {
            'oracle_negative_retire_context_cell' => ['action' => 'retire_strategy_context_cell', 'next_stage' => 'market_cartographer'],
            'entry_mastery_required' => ['action' => 'freeze_upstream_run_trigger_tournament', 'next_stage' => 'trigger_entry_specialist'],
            'management_or_cost_mastery_required' => ['action' => 'freeze_entry_run_management_attribution', 'next_stage' => 'management_specialist'],
            default => ['action' => 'require_independent_replication', 'next_stage' => 'full_composition_master'],
        };
        $data = (string) ($realized['data_hash'] ?? $oracle['data_hash'] ?? 'missing');
        $execution = (string) ($realized['execution_hash'] ?? $oracle['execution_hash'] ?? 'missing');
        $gap = ['oracle_opportunity_edge_r' => $oracleEdge, ...$terms, 'realized_after_cost_r' => $realizedEdge,
            'unexplained_gap_r' => max(0., $oracleEdge - $realizedEdge - array_sum($identified)),
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
            ['role' => 'frozen_control', 'value' => 'structure_plus_reaction'],
            ['role' => 'candidate', 'value' => 'structure_plus_participation'],
            ['role' => 'candidate', 'value' => 'reaction_plus_participation'],
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
            ['role' => 'blinded_control', 'value' => 'state_adaptive'],
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
        return $this->plan($passportId, 'categorical_location_recomposition', $axis, [$axis.'_alternate'], ['minimum_setup_events' => 20, 'minimum_trigger_events' => 12, 'minimum_closed_trades' => 8]);
    }

    /** Context is a bounded specialist experiment, not a hidden global router. */
    public function planRegimeSpecialist(int $passportId, array $context): array
    {
        $required = ['regime', 'volatility', 'session'];
        if (collect($required)->contains(fn (string $key): bool => ! filled($context[$key] ?? null))) {
            return $this->blocked('REGIME_SPECIALIST_CONTEXT_CONTRACT_REQUIRED');
        }
        return $this->plan($passportId, 'regime_specialist_academy', 'context', [
            ['role' => 'frozen_control', 'value' => 'exact_context_replication'],
            ['role' => 'candidate', 'value' => 'same_regime_alternate_session'],
            ['role' => 'blinded_control', 'value' => 'context_blinded_control'],
        ], ['minimum_setup_events' => 20, 'minimum_trigger_events' => 12, 'minimum_closed_trades' => 8, 'max_false_entry_rate' => .45]);
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
        $trial = DB::table('edge_academy_trials')->find($trialId);
        if (! $trial) return $this->blocked('ACADEMY_TRIAL_NOT_FOUND');
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
        DB::table('edge_academy_trials')->where('id', $trialId)->update(['status' => $status,
            'outcome' => json_encode(['density' => $verdict, 'marginal_value' => $marginal, 'outcome' => $outcome, 'promotion_evidence' => false]), 'settled_at' => now(), 'updated_at' => now()]);
        return ['protocol' => self::PROTOCOL, 'status' => $status, 'density' => $verdict, 'marginal_value' => $marginal, 'promotion_evidence' => false];
    }

    /** Convert a settled diagnostic into one bounded next experiment; never dispatch it. */
    public function nextExperiment(int $passportId, array $oracleSettlement): array
    {
        $passport = DB::table('edge_academy_passports')->find($passportId);
        if (! $passport) return $this->blocked('ACADEMY_PASSPORT_NOT_FOUND');
        $status = (string) ($oracleSettlement['status'] ?? '');
        if ($status === 'oracle_negative_retire_context_cell') return ['protocol' => self::PROTOCOL, 'status' => 'retired_context_cell', 'dispatch_allowed' => false, 'promotion_evidence' => false];
        $curriculum = json_decode((string) $passport->curriculum, true) ?: [];
        $axes = (array) ($curriculum['permitted_axes'] ?? []);
        if ($status === 'entry_mastery_required') {
            if (in_array('trigger_topology_policy', $axes, true)) return $this->planTriggerTopologyTournament((int) $passport->id);
            if (in_array('confirmation_family_policy', $axes, true)) return $this->planConfirmationMarginalValue((int) $passport->id);
            return $this->blocked('ENTRY_DIAGNOSIS_AWAITS_UPSTREAM_CURRICULUM');
        }
        if ($status === 'management_or_cost_mastery_required') return ['protocol' => self::PROTOCOL,
            'status' => 'management_attribution_required', 'frozen_entry_required' => true, 'risk_mutation_forbidden' => true,
            'dispatch_allowed' => false, 'promotion_evidence' => false];
        return ['protocol' => self::PROTOCOL, 'status' => 'independent_replication_required', 'dispatch_allowed' => false, 'promotion_evidence' => false];
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
                'terminal' => true, 'promotion_evidence' => false];
        }
        if (! $existing) {
            DB::table('edge_academy_trials')->insert([
                'trial_key' => $key, 'edge_academy_passport_id' => $passportId, 'trial_type' => $type,
                'status' => 'planned', 'frozen_contract' => json_encode($frozen), 'arms' => json_encode($arms),
                'density_contract' => json_encode($density), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return ['protocol' => self::PROTOCOL, 'status' => 'planned', 'trial_type' => $type, 'axis' => $axis, 'arms' => $arms, 'event_density_contract' => $density, 'promotion_evidence' => false];
    }

    /** Planner semantics are explicit, never inferred from arm array order. */
    private function explicitArms(array $definitions, string $axis, array $frozen): array
    {
        $roles = ['frozen_control', 'candidate', 'blinded_control', 'ablation', 'counterfactual'];
        $arms = [];
        foreach ($definitions as $definition) {
            $definition = is_array($definition) ? $definition : ['role' => 'candidate', 'value' => $definition];
            $role = (string) ($definition['role'] ?? '');
            $value = $definition['value'] ?? null;
            if (! in_array($role, $roles, true) || ! is_string($value) || $value === '') return [];
            $arms[] = ['name' => $value, 'role' => $role, 'changed_axis' => $axis,
                'value' => $value, 'frozen_contract_hash' => $this->hash($frozen)];
        }
        return $arms;
    }

    private function curriculum(string $stage, array $frozen): array
    {
        $depth = $this->depth($stage);
        $axes = match (true) {
            $depth <= 0 => ['context'], $depth === 1 => ['setup_topology_policy'],
            $depth === 2 => ['confirmation_family_policy'], $depth === 3 => ['trigger_topology_policy'],
            $depth === 4 => ['entry_mode', 'max_chase_atr', 'minimum_reward_space_r'],
            $depth === 5 => ['atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles'],
            default => ['tactic', 'temporal_binding', 'location'],
        };
        return ['protocol' => self::PROTOCOL, 'deepest_stage' => $stage, 'permitted_axes' => $axes,
            'forbidden_axes' => array_values(array_diff(['context', 'setup_topology_policy', 'confirmation_family_policy', 'trigger_topology_policy', 'entry_mode', 'max_chase_atr', 'minimum_reward_space_r', 'atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles', 'risk'], $axes)),
            'first_three_experiments' => 'exact_replication_only', 'frozen_upstream_hash' => $this->hash($frozen), 'promotion_evidence' => false];
    }
    private function frozenContract(array $identity, string $stage): array { return ['protocol' => self::PROTOCOL, 'deepest_stage' => $stage, 'strategy' => $identity['strategy'] ?? $identity['strategy_family'] ?? null, 'tactic' => $identity['tactic'] ?? null, 'context' => $identity['context'] ?? [], 'temporal_roles' => $identity['temporal_roles'] ?? [], 'risk' => $identity['risk_contract'] ?? [], 'management' => $identity['management_contract'] ?? [], 'frozen_at_stage_depth' => $this->depth($stage)]; }
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
    private function nullableNonNegative(mixed $value): ?float { return is_numeric($value) ? max(0., (float) $value) : null; }
    private function hash(mixed $value): string { return hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)); }
    private function available(): bool { return Schema::hasTable('edge_academy_passports'); }
    private function blocked(string $reason, array $extra = []): array { return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason' => $reason, ...$extra, 'promotion_evidence' => false]; }
}
