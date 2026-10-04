<?php

namespace App\Services;

use App\Models\LabFailureDojoRun;
use App\Models\ModelVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes a topology's causal scope explicit before it consumes the next
 * expensive stage. It classifies an observed change; it never infers an edge
 * from a parameter delta or grants paper/parent authority.
 */
class CausalStageMasteryDirectorService
{
    public const PROTOCOL = 'causal_stage_mastery_director_v6';

    /** @return array<string,mixed> */
    public function owner(string $gene): array
    {
        $gene = strtolower($gene);
        $map = [
            'confirmation_family_policy' => ['SETUP_TO_CONFIRMATION', ['price_reaction_family', 'structure_event', 'participation_family']],
            'minimum_independent_confirmations' => ['SETUP_TO_CONFIRMATION', ['confirmation_family_policy']],
            'trigger_topology_policy' => ['CONFIRMATION_TO_TRIGGER', ['aggressive_trigger', 'balanced_trigger_reaction', 'conservative_continuation']],
            'entry_mode' => ['CONFIRMATION_TO_TRIGGER', ['trigger_topology_policy']],
            'rejection_wick_ratio' => ['CONFIRMATION_TO_TRIGGER', ['price_reaction_family', 'balanced_trigger_reaction']],
            'entry_model' => ['CONTEXT_LOCATION_TO_SETUP', ['model_context', 'model_location', 'setup_topology_policy']],
            'setup_topology_policy' => ['CONTEXT_LOCATION_TO_SETUP', ['model_context', 'model_location', 'breakout_and_retest', 'pullback_rejection', 'liquidity_sweep_reclaim', 'range_reentry', 'compression_expansion']],
            'breakout_setup_timeframe' => ['CONTEXT_TO_LOCATION', ['temporal_binding', 'location_topology']],
            'location_tolerance_atr' => ['CONTEXT_TO_LOCATION', ['location_topology']],
            'minimum_reward_space_r' => ['TRIGGER_TO_ENTRY', ['reward_space']],
            'max_chase_atr' => ['TRIGGER_TO_ENTRY', ['chase_geometry']],
            'invalidation_buffer_atr' => ['TRIGGER_TO_ENTRY', ['invalidation']],
            'atr_target_multiplier' => ['ENTRY_TO_CLOSED_TRADE', ['management']],
            'trailing_atr_multiplier' => ['ENTRY_TO_CLOSED_TRADE', ['management']],
            'time_stop_candles' => ['ENTRY_TO_CLOSED_TRADE', ['management']],
        ];
        [$transition, $owns] = $map[$gene] ?? (str_contains($gene, 'risk') || str_contains($gene, 'cooldown')
            ? ['CLOSED_TRADE_TO_EDGE', ['risk_governor_only_after_edge']]
            : ['UNDECLARED', []]);
        return ['protocol' => self::PROTOCOL, 'gene' => $gene, 'transition' => $transition, 'owns' => $owns,
            'owned_stages' => $transition === 'CONTEXT_LOCATION_TO_SETUP' ? ['context', 'location', 'setup'] : [$this->stageFor($transition)],
            'exclusive_single_transition_claim' => $transition !== 'CONTEXT_LOCATION_TO_SETUP',
            'does_not_own' => array_values(array_diff(['CONTEXT_TO_LOCATION', 'LOCATION_TO_SETUP', 'SETUP_TO_CONFIRMATION', 'CONFIRMATION_TO_TRIGGER', 'TRIGGER_TO_ENTRY', 'ENTRY_TO_CLOSED_TRADE', 'CLOSED_TRADE_TO_EDGE'], [$transition])),
            'declared' => $transition !== 'UNDECLARED', 'promotion_evidence' => false];
    }

    /** Translate categorical curriculum choices into parameters the engine really consumes. */
    public function topologyPacket(string $kind, string $policy): array
    {
        $packets = [
            'confirmation_family_policy' => [
                'all_three_simultaneous' => ['confirmation_family_policy' => $policy],
                'structure_plus_reaction' => ['confirmation_family_policy' => $policy],
                'structure_plus_participation' => ['confirmation_family_policy' => $policy],
                'sequential_three' => ['confirmation_family_policy' => $policy],
                'state_adaptive_two_of_three' => ['confirmation_family_policy' => $policy],
            ],
            'trigger_topology_policy' => [
                'aggressive_structure_close' => ['trigger_topology_policy' => $policy],
                'balanced_retest_reaction' => ['trigger_topology_policy' => $policy],
                'conservative_continuation' => ['trigger_topology_policy' => $policy],
                'session_adaptive' => ['trigger_topology_policy' => $policy],
                'volatility_adaptive' => ['trigger_topology_policy' => $policy],
            ],
            'setup_topology_policy' => [
                'breakout_and_retest' => ['setup_topology_policy' => $policy],
                'pullback_rejection' => ['setup_topology_policy' => $policy],
                'liquidity_sweep_reclaim' => ['setup_topology_policy' => $policy],
                'range_reentry' => ['setup_topology_policy' => $policy],
                'compression_expansion' => ['setup_topology_policy' => $policy],
            ],
        ];
        $parameters = $packets[$kind][$policy] ?? null;
        return $parameters === null
            ? ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason' => 'UNSUPPORTED_TOPOLOGY_POLICY', 'promotion_evidence' => false]
            : ['protocol' => self::PROTOCOL, 'status' => 'executable', 'kind' => $kind, 'policy' => $policy, 'parameters' => $parameters, 'promotion_evidence' => false];
    }

    /** Identify the single intervention that a paired run can legitimately credit. */
    public function inferAxis(array $controlParameters, array $candidateParameters): array
    {
        $changed = collect(array_unique([...array_keys($controlParameters), ...array_keys($candidateParameters)]))
            ->filter(fn (string $key): bool => json_encode($controlParameters[$key] ?? null)
                !== json_encode($candidateParameters[$key] ?? null))->values();
        if ($changed->count() !== 1) {
            return ['protocol' => self::PROTOCOL, 'status' => 'not_assessable',
                'reason' => $changed->isEmpty() ? 'NO_PARAMETER_CHANGE' : 'MULTI_AXIS_INTERVENTION',
                'changed_genes' => $changed->all(), 'promotion_evidence' => false];
        }
        $gene = (string) $changed->first();
        return ['protocol' => self::PROTOCOL, 'status' => 'single_axis', 'gene' => $gene,
            'owner' => $this->owner($gene), 'promotion_evidence' => false];
    }

    /** @return array<string,mixed> */
    public function assess(string $gene, array $control, array $candidate): array
    {
        $owner = $this->owner($gene);
        if (! $owner['declared']) return $this->result('non_controlling_axis', $owner, ['reason' => 'STAGE_OWNER_UNDECLARED']);
        $transition = (string) $owner['transition'];
        $stage = $this->stageFor($transition);
        $upstream = array_values(array_diff($this->upstreamFor($stage), (array) $owner['owned_stages']));
        $controlCounts = $this->counts($control); $candidateCounts = $this->counts($candidate);
        $parameterChanged = json_encode($control['value'] ?? null) !== json_encode($candidate['value'] ?? null);
        $controlIdentity = (array) data_get($control, 'data_quality.decision_identity_receipt', []);
        $candidateIdentity = (array) data_get($candidate, 'data_quality.decision_identity_receipt', []);
        $identityValid = $this->decisionIdentityValid($controlIdentity) && $this->decisionIdentityValid($candidateIdentity)
            && $this->digest($controlIdentity['bindings'] ?? []) === $this->digest($candidateIdentity['bindings'] ?? [])
            && $this->digest($controlIdentity['candle_domain'] ?? []) === $this->digest($candidateIdentity['candle_domain'] ?? []);
        $upstreamPreserved = $identityValid && collect($upstream)->every(fn (string $key): bool =>
            data_get($controlIdentity, "stage_identities.{$key}.status") === 'observed'
            && data_get($candidateIdentity, "stage_identities.{$key}.status") === 'observed'
            && hash_equals((string) data_get($controlIdentity, "stage_identities.{$key}.event_hash", ''),
                (string) data_get($candidateIdentity, "stage_identities.{$key}.event_hash", '')));
        $targetChanged = $identityValid && data_get($controlIdentity, "stage_identities.{$stage}.status") === 'observed'
            && data_get($candidateIdentity, "stage_identities.{$stage}.status") === 'observed'
            && ! hash_equals((string) data_get($controlIdentity, "stage_identities.{$stage}.event_hash", ''),
                (string) data_get($candidateIdentity, "stage_identities.{$stage}.event_hash", ''));
        $targetDelta = $targetChanged ? max(1, abs((int) data_get($candidateIdentity, "stage_identities.{$stage}.event_count", 0)
            - (int) data_get($controlIdentity, "stage_identities.{$stage}.event_count", 0))) : 0;
        $eventDelta = array_sum(array_map(fn (string $key): int => abs(($candidateCounts[$key] ?? 0) - ($controlCounts[$key] ?? 0)), array_keys($candidateCounts)));
        $duplicate = $this->digest($control) === $this->digest($candidate);
        $excessive = $eventDelta > max(20, (array_sum($controlCounts) * 5) + 5);
        $checks = ['parameter_changed' => $parameterChanged, 'target_transition_changed' => $targetChanged,
            'decision_identity_valid' => $identityValid,
            'upstream_identity_preserved' => $upstreamPreserved, 'minimum_event_delta_reached' => $targetChanged,
            'duplicate_behavior' => ! $duplicate, 'behavior_excessive' => ! $excessive];
        $passed = ! in_array(false, $checks, true);
        $reason = ! $identityValid ? 'DECISION_IDENTITY_INCOMPLETE'
            : (! $upstreamPreserved ? 'UPSTREAM_SEMANTICS_CHANGED'
                : (! $parameterChanged ? 'NO_PARAMETER_CHANGE'
                    : (! $targetChanged ? 'NO_OBSERVED_SEMANTIC_EFFECT'
                        : ($excessive ? 'BEHAVIOR_DELTA_EXCESSIVE' : 'SEMANTIC_EFFECT_OBSERVED'))));
        return $this->result($passed ? 'controllable' : 'non_controlling_axis', $owner, [
            'reason' => $reason, 'evidence_assessable' => $identityValid && $upstreamPreserved,
            'semantic_effect_observed' => $targetChanged,
            'checks' => $checks, 'control_counts' => $controlCounts, 'candidate_counts' => $candidateCounts,
            'target_stage' => $stage, 'target_event_delta' => $targetDelta, 'event_delta' => $eventDelta,
            'preserved_upstream_stages' => $upstream, 'count_equality_is_identity' => false,
            'lexicographic_fitness' => $this->fitness($candidateCounts, $candidate),
        ]);
    }

    /** Persist a one-time result and create explicit retirement rather than retrying a no-op axis. */
    public function record(?object $trial, ?object $controlTrial, ?ModelVersion $model, string $gene, array $control, array $candidate, string $symbol, string $timeframe): array
    {
        $assessment = $this->assess($gene, $control, $candidate);
        if (! $this->available()) return $assessment;
        $key = hash('sha256', implode('|', [self::PROTOCOL, $trial?->id, $controlTrial?->id, $gene, $this->digest($control), $this->digest($candidate)]));
        DB::table('causal_stage_mastery_assessments')->updateOrInsert(['assessment_key' => $key], [
            'edge_genesis_trial_id' => $trial?->id, 'control_edge_genesis_trial_id' => $controlTrial?->id, 'model_version_id' => $model?->id,
            'symbol' => strtoupper($symbol), 'timeframe' => strtoupper($timeframe), 'gene_key' => $gene,
            'transition_key' => data_get($assessment, 'owner.transition', 'UNDECLARED'), 'status' => $assessment['status'],
            'assessment' => json_encode($assessment, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), 'assessed_at' => now(), 'updated_at' => now(), 'created_at' => now(),
        ]);
        return $assessment;
    }

    /** A structural behavior gain may become the next baseline, never a parent or paper candidate. */
    public function scaffold(array $assessment): array
    {
        $fitness = (array) data_get($assessment, 'lexicographic_fitness', []);
        $activates = $assessment['status'] === 'controllable' && (int) data_get($fitness, 'funnel_depth', 0) >= 4
            && (int) data_get($fitness, 'closed_trade_power', 0) < 3;
        return ['protocol' => self::PROTOCOL, 'authority' => $activates ? 'path_activating_scaffold' : 'none',
            'next_baseline_authority' => $activates, 'parent_authority' => false, 'paper_authority' => false,
            'promotion_evidence' => false];
    }

    /** @return array<string,mixed> */
    public function promotionDebt(string $symbol, string $timeframe): array
    {
        // Queued full replays are capacity usage, not promotion debt. Debt is
        // evidence that has already changed behavior and awaits settlement.
        $pendingTrials = Schema::hasTable('edge_genesis_trials') ? DB::table('edge_genesis_trials as t')->join('edge_genesis_passports as p', 'p.id', '=', 't.edge_genesis_passport_id')
            ->where('p.symbol', strtoupper($symbol))->where('p.timeframe', strtoupper($timeframe))->whereIn('t.status', ['queued', 'running', 'edge_progressing'])->count() : 0;
        $nearCartridges = app(ProvisionalCartridgeReadinessService::class)->readyEntries($symbol, $timeframe)->count();
        $unsettledBehavior = $this->available() ? DB::table('causal_stage_mastery_assessments')->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->where('status', 'controllable')->count() : 0;
        $dojoCells = Schema::hasTable('lab_failure_dojo_runs') ? LabFailureDojoRun::query()->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->where('status', 'pending')->get()
            ->unique(fn (LabFailureDojoRun $run): string => $this->dojoCell($run))->count() : 0;
        $settlementLag = Schema::hasTable('edge_genesis_trials') ? DB::table('edge_genesis_trials as t')->join('edge_genesis_passports as p', 'p.id', '=', 't.edge_genesis_passport_id')
            ->where('p.symbol', strtoupper($symbol))->where('p.timeframe', strtoupper($timeframe))->whereIn('t.status', ['edge_progressing', 'authority_observed'])->count() : 0;
        $score = $settlementLag + ($nearCartridges * 2) + $dojoCells + $unsettledBehavior;
        $limit = max(1, (int) config('services.edge_director.promotion_debt_limit', 8));
        return ['protocol' => self::PROTOCOL, 'active_replay_work' => $pendingTrials, 'settlement_lag' => $settlementLag, 'near_confirmable_cartridges' => $nearCartridges,
            'unresolved_unique_dojo_cells' => $dojoCells, 'unsettled_behavior_changed_packets' => $unsettledBehavior,
            'score' => $score, 'limit' => $limit, 'consolidation_required' => $score > $limit, 'promotion_evidence' => false];
    }

    private function stageFor(string $transition): string { return match ($transition) {
        'CONTEXT_TO_LOCATION' => 'location', 'LOCATION_TO_SETUP', 'CONTEXT_LOCATION_TO_SETUP' => 'setup', 'SETUP_TO_CONFIRMATION' => 'confirmation',
        'CONFIRMATION_TO_TRIGGER' => 'trigger', 'TRIGGER_TO_ENTRY' => 'entry', 'ENTRY_TO_CLOSED_TRADE' => 'closed_trade', default => 'closed_trade' }; }
    private function upstreamFor(string $stage): array { $all = ['opportunity', 'context', 'location', 'setup', 'confirmation', 'trigger', 'entry', 'closed_trade']; $index = array_search($stage, $all, true); return $index === false ? [] : array_slice($all, 0, $index); }
    /** Read-only source attestation; this does not grant stage or economic mastery. */
    public function decisionIdentityValid(array $receipt): bool
    {
        if (($receipt['protocol'] ?? null) !== 'replay_decision_identity_v2' || ($receipt['status'] ?? null) !== 'complete'
            || ($receipt['ordered_unique'] ?? false) !== true || ($receipt['temporal_as_of_valid'] ?? false) !== true
            || ($receipt['counts_are_diagnostic_only'] ?? null) !== true
            || ($receipt['promotion_evidence'] ?? null) !== false) return false;
        foreach (['data_hash', 'execution_hash', 'source_evaluator_hash', 'python_source_hash', 'full_runtime_source_hash', 'actual_source_sha256', 'dependency_receipt_hash'] as $key) {
            if (preg_match('/^[a-f0-9]{64}$/', (string) data_get($receipt, "bindings.{$key}", '')) !== 1) return false;
        }
        if (data_get($receipt, 'bindings.source_identity_protocol') !== 'dual_runtime_source_identity_v1'
            || ! hash_equals((string) data_get($receipt, 'bindings.source_evaluator_hash'), (string) data_get($receipt, 'bindings.python_source_hash'))
            || ! filled(data_get($receipt, 'bindings.dataset_identity'))
            || data_get($receipt, 'bindings.dataset_attestation_status') !== 'verified'
            || data_get($receipt, 'bindings.source_evaluator_attested') !== true
            || data_get($receipt, 'bindings.dependency_status') !== 'complete'
            || ! $this->dependencyIdentityValid((array) ($receipt['dependency_identity'] ?? []), (array) ($receipt['bindings'] ?? []))
            || data_get($receipt, 'bindings.as_of_rule') !== 'candle_open_plus_timeframe_duration_utc_v1'
            || (int) data_get($receipt, 'candle_domain.event_count', 0) <= 0
            || preg_match('/^[a-f0-9]{64}$/', (string) data_get($receipt, 'candle_domain.event_hash', '')) !== 1) return false;
        $required = ['opportunity', 'context', 'location', 'setup', 'confirmation', 'trigger', 'entry', 'closed_trade', 'raw_signal'];
        if (array_diff($required, array_keys((array) ($receipt['stage_identities'] ?? []))) !== []) return false;
        foreach ((array) ($receipt['stage_identities'] ?? []) as $stage => $identity) {
            $schema = match ($stage) { 'entry' => 'filled_entry_v2', 'closed_trade' => 'entry_linked_close_v2', default => 'stage_semantics_v2' };
            if (($identity['status'] ?? null) !== 'observed' || ($identity['semantic_schema'] ?? null) !== $schema) return false;
            if (($identity['status'] ?? null) === 'observed' && (preg_match('/^[a-f0-9]{64}$/', (string) ($identity['event_hash'] ?? '')) !== 1
                || ! is_int($identity['event_count'] ?? null) || $identity['event_count'] < 0)) return false;
        }
        $expected = (string) ($receipt['receipt_hash'] ?? ''); unset($receipt['receipt_hash']);
        return preg_match('/^[a-f0-9]{64}$/', $expected) === 1 && hash_equals($expected, $this->digest($receipt));
    }
    private function dependencyIdentityValid(array $receipt, array $bindings): bool
    {
        if (($receipt['protocol'] ?? null) !== 'consumed_dependency_identity_v1'
            || ($receipt['status'] ?? null) !== 'complete' || ($receipt['promotion_evidence'] ?? null) !== false
            || ($receipt['as_of_rule'] ?? null) !== 'candle_open_plus_timeframe_duration_utc_v1') return false;
        $streams = (array) ($receipt['streams'] ?? []);
        $required = $receipt['required_streams'] ?? null;
        if (! is_array($required) || ! array_is_list($required) || $required === []) return false;
        $actual = array_keys($streams); sort($actual);
        if ($required !== $actual) return false;
        $primary = (string) ($bindings['execution_timeframe'] ?? '');
        if (! isset($streams[$primary])) return false;
        if ($primary === 'M5' && array_diff(['H4', 'H1', 'M15'], $required) !== []) return false;
        foreach ($streams as $stream => $source) {
            if (! is_array($source) || ($source['stream'] ?? null) !== $stream || ($source['status'] ?? null) !== 'verified'
                || ! is_int($source['consumed_rows'] ?? null) || $source['consumed_rows'] <= 0) return false;
            foreach (['actual_source_sha256', 'consumed_data_hash'] as $key) {
                if (preg_match('/^[a-f0-9]{64}$/', (string) ($source[$key] ?? '')) !== 1) return false;
            }
            if ($stream !== $primary) {
                $asOf = $stream === 'REGIME_H1' ? 'candle_open_utc_v1' : 'candle_open_plus_timeframe_duration_utc_v1';
                if (($source['join_status'] ?? null) !== 'verified' || ($source['as_of_rule'] ?? null) !== $asOf
                    || ! filled($source['first_candle_utc'] ?? null) || ! filled($source['last_candle_utc'] ?? null)
                    || preg_match('/^[a-f0-9]{64}$/', (string) ($source['as_of_join_hash'] ?? '')) !== 1) return false;
            }
        }
        if (($streams[$primary]['actual_source_sha256'] ?? null) !== ($bindings['actual_source_sha256'] ?? null)
            || ($streams[$primary]['consumed_data_hash'] ?? null) !== ($bindings['data_hash'] ?? null)) return false;
        $expected = (string) ($receipt['receipt_hash'] ?? ''); unset($receipt['receipt_hash']);
        return preg_match('/^[a-f0-9]{64}$/', $expected) === 1
            && hash_equals($expected, (string) ($bindings['dependency_receipt_hash'] ?? ''))
            && hash_equals($expected, $this->digest($receipt));
    }
    private function counts(array $metrics): array { return [
        'opportunity' => (int) data_get($metrics, 'edge_observability.opportunity_detected.count', data_get($metrics, 'entry_contract_funnel.stage_counts.opportunity', 0)),
        'location' => (int) data_get($metrics, 'edge_observability.setup_location_valid.location_count', data_get($metrics, 'entry_contract_funnel.stage_counts.location', 0)),
        'setup' => (int) data_get($metrics, 'entry_contract_funnel.stage_counts.setup', data_get($metrics, 'edge_observability.setup_location_valid.setup_count', 0)),
        'confirmation' => (int) data_get($metrics, 'entry_contract_funnel.stage_counts.confirmation', data_get($metrics, 'edge_observability.confirmation.count', 0)),
        'trigger' => (int) data_get($metrics, 'entry_contract_funnel.stage_counts.trigger', 0),
        'entry' => (int) data_get($metrics, 'entry_contract_funnel.stage_counts.entry_ready', data_get($metrics, 'edge_observability.entry.count', 0)),
        'closed_trade' => (int) data_get($metrics, 'edge_observability.exit_outcome.closed_trade_count', data_get($metrics, 'total_trades', 0)),
    ]; }
    private function fitness(array $counts, array $metrics): array { $depth = 0; foreach (['opportunity','location','setup','confirmation','trigger','entry','closed_trade'] as $index => $stage) if (($counts[$stage] ?? 0) > 0) $depth = $index + 1; return [
        'behavioral_ownership' => (int) ($counts['trigger'] ?? 0) > 0, 'funnel_depth' => $depth,
        'event_density' => array_sum($counts), 'closed_trade_power' => (int) ($counts['closed_trade'] ?? 0),
        'after_cost_expectancy_r' => (float) data_get($metrics, 'after_cost_expectancy_r', 0),
        'independent_window_stability' => (int) data_get($metrics, 'forward_window_protocol.positive_windows', 0),
        'tail_safe' => ! (bool) data_get($metrics, 'forbidden_risk_bypass', false),
    ]; }
    private function result(string $status, array $owner, array $evidence): array { return ['protocol' => self::PROTOCOL, 'status' => $status, 'owner' => $owner, ...$evidence, 'promotion_evidence' => false]; }
    private function digest(array $value): string { return hash('sha256', json_encode($this->canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)); }
    private function canonicalize(mixed $value): mixed { if (! is_array($value)) return $value; if (! array_is_list($value)) ksort($value); foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item); return $value; }
    private function dojoCell(LabFailureDojoRun $run): string { return hash('sha256', implode('|', [$run->family, $run->target, $run->state_signature, data_get($run->failure_signature, 'context_v2', ''), data_get($run->evidence, 'data_hash', ''), data_get($run->evidence, 'execution_hash', ''), $run->repair_anchor_id])); }
    private function available(): bool { return Schema::hasTable('causal_stage_mastery_assessments'); }
}
