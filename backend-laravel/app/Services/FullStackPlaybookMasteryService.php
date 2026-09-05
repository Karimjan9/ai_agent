<?php

namespace App\Services;

use App\Models\ExecutionTacticPosterior;
use App\Models\FullStackPlaybookPassport;
use App\Models\LabAgent;
use App\Models\ModelVersion;
use App\Models\PlaybookMasteryLedgerEntry;
use App\Models\StrategyMasterPassport;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps "can execute the professional playbook" separate from "has an
 * economic edge".  A library prior is only a frozen procedure until a replay
 * provides the complete decision-to-outcome receipt.
 */
class FullStackPlaybookMasteryService
{
    public const PROTOCOL = 'full_stack_playbook_mastery_v1';
    public const ARMS = [
        'professional_reference',
        'confirmation_floor_one',
        // Kept readable for immutable cohorts registered before the
        // confirmation-breadth experiment replaced the confounded tactic arm.
        'confirmation_tactic_change',
        'temporal_role_change',
        'memory_blinded_autonomous',
        'confirmation_floor_control',
        'internal_structure_trigger',
        'aggressive_trigger',
        'extended_retest_trigger',
        'latent_edge_control',
        'partial_harvest',
        'trailing_harvest',
        'time_stop_harvest',
        'target_harvest',
        'unfiltered_context_control',
        'regime_compatibility_gate',
        'session_liquidity_gate',
        'regime_session_gate',
        'strict_context_gate',
        'regime_entry_control',
        'retest_entry_gate',
        'independent_confirmation_gate',
        'reward_space_gate',
        'chase_quality_gate',
        'failure_cell_control',
        'buy_direction_gate',
        'high_volatility_gate',
        'buy_high_volatility_interaction',
        'sell_direction_negative_control',
        'specialist_interaction_control',
        'trend_continuation_topology',
        'false_break_reversal_topology',
        'extended_retest_window',
        'lower_displacement_gate',
        'h1_breakout_control',
        'm15_setup_breakout',
        'short_structure_horizon',
        'long_structure_horizon',
        'balanced_retest_confirmation',
        'm15_aggressive_control',
        'm15_balanced_confirmation',
        'm15_conservative_confirmation',
        'm15_two_family_confirmation',
        'm15_three_family_confirmation',
        // Evidence-compiled Edge packets preserve the same five causal
        // roles as fixed packets, but use explicit names so their component
        // credit cannot be confused with a historical repair family.
        'compiled_control',
        'compiled_primary',
        'compiled_refinement',
        'compiled_counterfactual',
        'compiled_negative_control',
        'frozen_control',
    ];
    public const ATTRIBUTION_ARMS = ['full_composition', 'no_confirmation', 'alternate_tactic', 'alternate_temporal_binding', 'frozen_minimal_control'];
    public const STAGES = ['imitation', 'recognition', 'execution', 'contextual_specialization', 'independent_nine_fold', 'composition_mastery', 'bounded_innovation'];

    public function __construct(
        private TacticCatalogueService $tactics,
        private ConfirmationEntryCapabilityEvidenceService $confirmationEvidence,
    ) {}

    /** Register an immutable, executable professional playbook for one seat. */
    public function enroll(LabAgent $agent, array $compositionPassport, array $contract = []): FullStackPlaybookPassport
    {
        if (! $this->available()) throw new \RuntimeException('Full Stack Playbook mastery tables are unavailable.');
        if ((string) data_get($compositionPassport, 'protocol') !== CompositionAuthorityKernelService::PROTOCOL) {
            throw new \InvalidArgumentException('Full Stack Playbook requires a frozen composition passport.');
        }
        $metadata = (array) $agent->modelVersion?->metadata;
        $edge = (array) data_get($metadata, 'edge_genesis', []);
        $packet = (string) ($contract['packet_key'] ?? data_get($edge, 'packet_key', 'unscoped'));
        $arm = (string) ($contract['arm'] ?? data_get($edge, 'arm', 'professional_reference'));
        if (! in_array($arm, [...self::ARMS, ...self::ATTRIBUTION_ARMS], true)) throw new \InvalidArgumentException('Unknown Full Stack Playbook arm.');
        $dataHash = (string) ($contract['data_hash'] ?? data_get($edge, 'data_hash', data_get($compositionPassport, 'provenance.data_hash', '')));
        $executionHash = (string) ($contract['execution_hash'] ?? data_get($edge, 'execution_hash', data_get($compositionPassport, 'provenance.execution_hash', '')));
        if ($dataHash === '' || $executionHash === '') throw new \InvalidArgumentException('Full Stack Playbook requires frozen data and execution hashes.');
        $playbook = $this->compile($compositionPassport, $contract);
        $key = hash('sha256', implode('|', [self::PROTOCOL, $agent->model_version_id, $packet, $arm, $dataHash, $executionHash, data_get($compositionPassport, 'composition_id')]));

        $passport = FullStackPlaybookPassport::updateOrCreate(['model_version_id' => $agent->model_version_id], [
            'passport_key' => $key, 'lab_agent_id' => $agent->id, 'edge_genesis_passport_id' => $contract['edge_genesis_passport_id'] ?? null,
            'symbol' => strtoupper($agent->symbol), 'timeframe' => strtoupper($agent->timeframe), 'packet_key' => $packet, 'arm' => $arm,
            'mastery_stage' => 'imitation', 'status' => 'enrolled', 'data_hash' => $dataHash, 'execution_hash' => $executionHash,
            'playbook' => $playbook, 'evidence' => ['protocol' => self::PROTOCOL, 'professional_prior_is_not_xauusd_edge' => true, 'research_only' => true, 'promotion_evidence' => false],
        ]);
        $model = $agent->modelVersion;
        if ($model) {
            $model->update(['metadata' => [...(array) $model->metadata, 'full_stack_playbook' => [
                'protocol' => self::PROTOCOL, 'passport_key' => $key, 'packet_key' => $packet, 'arm' => $arm,
                'mastery_stage' => 'imitation', 'mastery_status' => 'enrolled', 'risk_evolution_locked' => true,
                'pre_2026_only' => true, 'research_only' => true, 'promotion_evidence' => false,
            ]]]);
        }
        return $passport;
    }

    /** Stop a full replay before it starts when its procedural contract is not executable. */
    public function preflight(LabAgent $agent): array
    {
        $contract = (array) data_get($agent->modelVersion?->metadata, 'full_stack_playbook', []);
        if (data_get($contract, 'protocol') !== self::PROTOCOL) return ['allowed' => true, 'status' => 'not_full_stack_playbook', 'promotion_evidence' => false];
        $passport = FullStackPlaybookPassport::query()->where('model_version_id', $agent->model_version_id)->first();
        $playbook = (array) $passport?->playbook;
        $required = ['strategy_thesis', 'tactic_topology', 'temporal_roles', 'required_sequence', 'execution_prohibitions', 'risk_topology', 'management_profile', 'no_trade_conditions'];
        $missing = array_values(array_filter($required, fn (string $key): bool => empty($playbook[$key])));
        $allowed = $passport !== null && $missing === []
            && data_get($playbook, 'risk_topology.absolute_owner') === 'Central Risk Governor'
            && in_array('partial_candle', (array) data_get($playbook, 'execution_prohibitions', []), true)
            && in_array('late_entry', (array) data_get($playbook, 'execution_prohibitions', []), true)
            && (bool) data_get($contract, 'pre_2026_only', false);
        return ['protocol' => self::PROTOCOL, 'allowed' => $allowed, 'status' => $allowed ? 'admitted' : 'INVALID_PLAYBOOK_EXECUTION_CONTRACT', 'missing' => $missing, 'promotion_evidence' => false];
    }

    /** Settle only observed replay evidence; missing evidence stays unobserved, never inferred. */
    public function settleOutcome(LabAgent $agent, array $result): array
    {
        if (! $this->available()) return ['protocol' => self::PROTOCOL, 'status' => 'unavailable', 'promotion_evidence' => false];
        $passport = FullStackPlaybookPassport::query()->where('model_version_id', $agent->model_version_id)->first();
        if (! $passport) return ['protocol' => self::PROTOCOL, 'status' => 'not_full_stack_playbook', 'promotion_evidence' => false];
        $data = (string) data_get($result, 'data_manifest.sha256', data_get($result, 'data_hash', ''));
        $execution = (string) data_get($result, 'execution_contract.execution_hash', data_get($result, 'execution_hash', ''));
        $hashesMatch = hash_equals($passport->data_hash, $data) && hash_equals($passport->execution_hash, $execution);
        $capability = $this->confirmationEvidence->project($result);
        $dimensions = $this->dimensions($result, $capability, $hashesMatch);
        $economic = $this->economicEvidence($result, $hashesMatch);
        $folds = $economic['powered_folds'];
        $stage = $folds >= 9 ? 'composition_mastery' : ($folds >= 3 ? 'contextual_specialization' : ($folds >= 2 ? 'recognition' : 'imitation'));
        $fatal = ! $hashesMatch
            || (array_key_exists('risk_governor_compliant', $result) && ! (bool) data_get($result, 'risk_governor_compliant', false))
            || (bool) data_get($result, 'temporal_leakage', data_get($result, 'is_overfit', false))
            || (bool) data_get($result, 'forbidden_risk_bypass', false);
        $proceduralObserved = collect($dimensions)->every(fn (array $dimension): bool => $dimension['observed'] && $dimension['passed']);
        $economicEdge = $economic['passed'];
        $status = $fatal ? 'failed_contract_or_safety' : ($proceduralObserved && $economicEdge && $folds >= 9 ? 'master_candidate' : ($proceduralObserved ? 'procedural_mastery_only' : ($economicEdge ? 'economic_edge_unreliable_execution' : 'in_progress')));
        $proceduralScore = collect($dimensions)->avg(fn (array $dimension): float => (float) ($dimension['score'] ?? 0));
        PlaybookMasteryLedgerEntry::updateOrCreate(['entry_key' => hash('sha256', implode('|', [self::PROTOCOL, $passport->id, $data, $execution, $stage]))], [
            'full_stack_playbook_passport_id' => $passport->id, 'stage' => $stage, 'status' => $status, 'fold_count' => $folds,
            'procedural_dimensions' => $dimensions, 'economic_evidence' => $economic,
            'evidence' => ['protocol' => self::PROTOCOL, 'confirmation_entry' => $capability, 'two_fold_is_technical_preflight_only' => true, 'three_fold_is_diagnostic_only' => true, 'nine_fold_required_for_mastery' => true, 'promotion_evidence' => false], 'settled_at' => now(),
        ]);
        $passport->update(['mastery_stage' => $stage, 'status' => $status, 'procedural_score' => $proceduralScore, 'economic_score' => $economic['after_cost_expectancy_r'], 'assessed_at' => now(),
            'evidence' => [...(array) $passport->evidence, 'last_assessment' => ['dimensions' => $dimensions, 'economic' => $economic, 'status' => $status]]]);
        StrategyMasterPassport::updateOrCreate(['model_version_id' => $passport->model_version_id], [
            'strategy_id' => (string) data_get($passport->playbook, 'strategy_thesis.strategy_id', 'hybrid'),
            'mastery_stage' => $status === 'master_candidate' ? 'strategy_master_candidate' : ($proceduralObserved ? 'validated_specialist' : 'apprentice'),
            'status' => $status === 'master_candidate' ? 'validated' : 'provisional',
            'target_regimes' => (array) data_get($passport->playbook, 'tactic_topology.contract.target_regimes', []),
            'metrics' => ['procedural_score' => $proceduralScore, 'economic' => $economic, 'folds' => $folds],
            'evidence' => ['protocol' => self::PROTOCOL, 'master_requires_descendant_transfer' => true, 'promotion_evidence' => false], 'assessed_at' => now(),
        ]);
        $this->projectTacticPosterior($passport, $economic, $dimensions, $result);
        $model = $agent->modelVersion;
        if ($model) $model->update(['metadata' => [...(array) $model->metadata, 'full_stack_playbook' => [...(array) data_get($model->metadata, 'full_stack_playbook', []), 'mastery_stage' => $stage, 'mastery_status' => $status, 'risk_evolution_locked' => ! ($proceduralObserved && $economicEdge && $folds >= 9), 'promotion_evidence' => false]]]);
        return ['protocol' => self::PROTOCOL, 'status' => $status, 'stage' => $stage, 'procedural_mastery' => $proceduralObserved, 'economic_edge' => $economicEdge, 'folds' => $folds, 'promotion_evidence' => false];
    }

    /** A true master still needs downstream attribution/paper authority; this is only lineage admission. */
    public function parentAdmission(?ModelVersion $model): array
    {
        $contract = (array) data_get($model?->metadata, 'full_stack_playbook', []);
        if (data_get($contract, 'protocol') !== self::PROTOCOL) return ['allowed' => true, 'reason' => 'not_full_stack_playbook'];
        $allowed = in_array(data_get($contract, 'mastery_status'), ['master_candidate', 'master'], true)
            && in_array(data_get($contract, 'mastery_stage'), ['composition_mastery', 'bounded_innovation'], true);
        return ['protocol' => self::PROTOCOL, 'allowed' => $allowed, 'reason' => $allowed ? 'mastery_contract_satisfied' : 'FULL_STACK_MASTERY_REQUIRED_FOR_LINEAGE', 'promotion_evidence' => false];
    }

    /**
     * The only transition from master candidate to master. A descendant must
     * explicitly replay the inherited procedure; parent P&L alone can never
     * unlock innovation.
     */
    public function recordDescendantTransfer(ModelVersion $parent, ModelVersion $descendant, array $receipt): array
    {
        $passport = FullStackPlaybookPassport::query()->where('model_version_id', $parent->id)->first();
        if (! $passport || $passport->status !== 'master_candidate') return ['protocol' => self::PROTOCOL, 'status' => 'parent_not_master_candidate', 'promotion_evidence' => false];
        $checks = [
            'protocol' => data_get($receipt, 'protocol') === self::PROTOCOL,
            'parent_passport' => hash_equals($passport->passport_key, (string) data_get($receipt, 'parent_passport_key', '')),
            'child_identity' => (int) data_get($receipt, 'descendant_model_version_id', 0) === $descendant->id,
            'fidelity' => (bool) data_get($receipt, 'transfer_fidelity_observed', false),
            'risk' => (bool) data_get($receipt, 'risk_governor_compliant', false) && ! (bool) data_get($receipt, 'forbidden_risk_bypass', false),
            'economic' => (int) data_get($receipt, 'independent_windows', 0) >= 9 && (float) data_get($receipt, 'after_cost_expectancy_r', 0) > 0,
        ];
        if (in_array(false, $checks, true)) return ['protocol' => self::PROTOCOL, 'status' => 'descendant_transfer_unproven', 'checks' => $checks, 'promotion_evidence' => false];
        PlaybookMasteryLedgerEntry::updateOrCreate(['entry_key' => hash('sha256', implode('|', [self::PROTOCOL, $passport->id, 'descendant-transfer', $descendant->id]))], [
            'full_stack_playbook_passport_id' => $passport->id, 'stage' => 'bounded_innovation', 'status' => 'master', 'fold_count' => 9,
            'procedural_dimensions' => ['descendant_transfer_fidelity' => ['observed' => true, 'score' => 1., 'passed' => true]],
            'economic_evidence' => ['after_cost_expectancy_r' => (float) data_get($receipt, 'after_cost_expectancy_r'), 'independent_windows' => (int) data_get($receipt, 'independent_windows'), 'passed' => true],
            'evidence' => ['protocol' => self::PROTOCOL, 'descendant_model_version_id' => $descendant->id, 'checks' => $checks, 'promotion_evidence' => false], 'settled_at' => now(),
        ]);
        $passport->update(['mastery_stage' => 'bounded_innovation', 'status' => 'master', 'assessed_at' => now()]);
        StrategyMasterPassport::where('model_version_id', $parent->id)->update(['mastery_stage' => 'master', 'status' => 'validated', 'assessed_at' => now()]);
        $parent->update(['metadata' => [...(array) $parent->metadata, 'full_stack_playbook' => [...(array) data_get($parent->metadata, 'full_stack_playbook', []), 'mastery_stage' => 'bounded_innovation', 'mastery_status' => 'master', 'innovation_allowed' => true, 'risk_evolution_locked' => false, 'promotion_evidence' => false]]]);
        return ['protocol' => self::PROTOCOL, 'status' => 'master', 'innovation_allowed' => true, 'promotion_evidence' => false];
    }

    private function compile(array $composition, array $contract): array
    {
        $components = (array) data_get($composition, 'components', []);
        $tactic = $this->tactics->for('hybrid', (string) ($components['tactic_id'] ?? 'core_execution'));
        $primary = (string) ($components['tactic_id'] ?? 'core_execution');
        $secondary = (string) ($contract['secondary_tactic_id'] ?? $primary);
        return [
            'protocol' => self::PROTOCOL,
            'strategy_thesis' => ['strategy_id' => data_get($components, 'strategy_id'), 'contract' => data_get($composition, 'strategy_contract.strategy_spec', data_get($composition, 'strategy_contract'))],
            'tactic_topology' => ['primary' => $primary, 'secondary' => $secondary, 'compatibility_graph' => [$primary => ['allowed_secondary' => [$primary], 'reason' => 'The professional reference freezes one executable entry topology; an alternate tactic is only a registered causal arm.']], 'contract' => $tactic],
            'temporal_roles' => data_get($composition, 'temporal_policy.roles', data_get($components, 'temporal_roles', ['H1' => 'context', 'M15' => 'setup', 'M5' => 'trigger'])),
            'toolbox_modules' => array_values(array_unique((array) data_get($composition, 'decision_tools', []))),
            'required_sequence' => ['closed_candle', 'H1_context', 'M15_setup_location', 'M5_confirmation', 'entry', 'invalidation', 'Central Risk Governor approval', 'management_state_machine', 'exit_receipt'],
            'execution_prohibitions' => ['partial_candle', 'late_entry', 'abnormal_spread', 'martingale', 'loser_add'],
            'risk_topology' => ['absolute_owner' => 'Central Risk Governor', 'contract' => data_get($composition, 'risk_governor'), 'risk_evolution' => 'locked_until_independent_economic_edge'],
            'management_profile' => data_get($composition, 'management_contract'),
            'no_trade_conditions' => ['outside_declared_context', 'missing_closed_candle', 'missing_confirmation', 'abnormal_spread', 'risk_governor_veto', 'stale_or_late_entry'],
        ];
    }

    private function dimensions(array $result, array $capability, bool $hashesMatch): array
    {
        $score = fn ($value): ?float => is_numeric($value) ? max(0., min(1., (float) $value)) : null;
        $setup = $score(data_get($capability, 'process_scores.setup_selection'));
        $confirmation = $score(data_get($capability, 'process_scores.confirmation_quality'));
        $entry = $score(data_get($capability, 'process_scores.entry_precision'));
        $noTrade = $score(data_get($result, 'abstention_quality', data_get($result, 'no_trade_precision')));
        $exit = $score(data_get($result, 'exit_management_quality', data_get($result, 'management_quality')));
        return [
            'fidelity' => ['observed' => $hashesMatch && (bool) data_get($result, 'behavior_delta_observed', false), 'score' => $hashesMatch ? 1. : 0., 'passed' => $hashesMatch && (bool) data_get($result, 'behavior_delta_observed', false)],
            'setup_precision' => $this->dimension($setup),
            'confirmation_precision' => $this->dimension($confirmation),
            'entry_precision' => $this->dimension($entry),
            'no_trade_precision' => $this->dimension($noTrade),
            'risk_adherence' => ['observed' => array_key_exists('risk_governor_compliant', $result), 'score' => (bool) data_get($result, 'risk_governor_compliant', false) ? 1. : 0., 'passed' => (bool) data_get($result, 'risk_governor_compliant', false) && ! (bool) data_get($result, 'forbidden_risk_bypass', false)],
            'exit_management_quality' => $this->dimension($exit),
        ];
    }

    private function dimension(?float $score): array { return ['observed' => $score !== null, 'score' => $score ?? 0., 'passed' => $score !== null && $score >= .6]; }

    private function economicEvidence(array $result, bool $hashesMatch): array
    {
        $folds = (int) data_get($result, 'forward_window_protocol.powered_windows', data_get($result, 'walk_forward.forward_window_protocol.powered_windows', 0));
        $positive = (int) data_get($result, 'forward_window_protocol.positive_windows', data_get($result, 'walk_forward.forward_window_protocol.positive_windows', 0));
        $expectancy = (float) data_get($result, 'after_cost_expectancy_r', data_get($result, 'pf_attribution.after_cost_expectancy_r', 0));
        $pf = (float) data_get($result, 'statistical_evidence.edge_quality.bootstrap_pf.pf_5_percentile_lower_bound', data_get($result, 'pf_lower_confidence_bound', 0));
        return ['observed' => $hashesMatch && $folds > 0, 'powered_folds' => $folds, 'positive_folds' => $positive, 'after_cost_expectancy_r' => $expectancy, 'pf_lower_confidence_bound' => $pf,
            'passed' => $hashesMatch && $folds >= 9 && $positive >= 3 && $expectancy > 0 && $pf > 1 && (float) data_get($result, 'net_profit_percent', 0) > 0 && ! (bool) data_get($result, 'temporal_leakage', data_get($result, 'is_overfit', false))];
    }

    private function projectTacticPosterior(FullStackPlaybookPassport $passport, array $economic, array $dimensions, array $result): void
    {
        if (! $economic['observed'] || ! $dimensions['risk_adherence']['passed']) return;
        $tactic = (string) data_get($passport->playbook, 'tactic_topology.primary', 'unknown');
        $state = implode('|', [(string) data_get($result, 'market_regime', 'declared_context'), (string) data_get($result, 'volatility_regime', 'declared')]);
        $posterior = ExecutionTacticPosterior::firstOrNew(['tactic_key' => $tactic, 'symbol' => $passport->symbol, 'timeframe' => $passport->timeframe, 'state_key' => $state]);
        $old = (int) ($posterior->observations ?? 0); $n = $old + 1;
        $mean = (($old * (float) ($posterior->net_expectancy ?? 0)) + $economic['after_cost_expectancy_r']) / $n;
        $posterior->fill(['observations' => $n, 'net_expectancy' => $mean, 'uncertainty' => max(.05, 1 / sqrt($n)), 'mastery_stage' => $n >= 9 && $mean > 0 ? 'mastery_observed' : 'mastery_evidence_accumulating',
            'value_vector' => ['protocol' => self::PROTOCOL, 'procedural_score' => collect($dimensions)->avg('score'), 'after_cost_expectancy_r' => $economic['after_cost_expectancy_r'], 'source' => 'frozen_pre_2026_replay'], 'last_observed_at' => now()])->save();
    }

    private function available(): bool { return Schema::hasTable('full_stack_playbook_passports') && Schema::hasTable('playbook_mastery_ledger_entries'); }
}
