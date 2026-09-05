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
    public const PROTOCOL = 'causal_stage_mastery_director_v3';

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
            'entry_model' => ['LOCATION_TO_SETUP', ['setup_topology_policy']],
            'setup_topology_policy' => ['LOCATION_TO_SETUP', ['breakout_and_retest', 'pullback_rejection', 'liquidity_sweep_reclaim', 'range_reentry', 'compression_expansion']],
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
        $upstream = $this->upstreamFor($stage);
        $controlCounts = $this->counts($control); $candidateCounts = $this->counts($candidate);
        $parameterChanged = json_encode($control['value'] ?? null) !== json_encode($candidate['value'] ?? null);
        $targetDelta = abs(($candidateCounts[$stage] ?? 0) - ($controlCounts[$stage] ?? 0));
        $upstreamPreserved = collect($upstream)->every(fn (string $key): bool => ($controlCounts[$key] ?? 0) === ($candidateCounts[$key] ?? 0));
        $eventDelta = array_sum(array_map(fn (string $key): int => abs(($candidateCounts[$key] ?? 0) - ($controlCounts[$key] ?? 0)), array_keys($candidateCounts)));
        $duplicate = $this->digest($control) === $this->digest($candidate);
        $excessive = $eventDelta > max(20, (array_sum($controlCounts) * 5) + 5);
        $checks = ['parameter_changed' => $parameterChanged, 'target_transition_changed' => $targetDelta >= 1,
            'upstream_identity_preserved' => $upstreamPreserved, 'minimum_event_delta_reached' => $eventDelta >= 1,
            'duplicate_behavior' => ! $duplicate, 'behavior_excessive' => ! $excessive];
        $passed = ! in_array(false, $checks, true);
        return $this->result($passed ? 'controllable' : 'non_controlling_axis', $owner, [
            'checks' => $checks, 'control_counts' => $controlCounts, 'candidate_counts' => $candidateCounts,
            'target_stage' => $stage, 'target_event_delta' => $targetDelta, 'event_delta' => $eventDelta,
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
        $nearCartridges = Schema::hasTable('lab_skill_zoo_entries') ? DB::table('lab_skill_zoo_entries')->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))
            ->where('status', 'provisional')->where('confidence', '>=', .5)->count() : 0;
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
        'CONTEXT_TO_LOCATION' => 'location', 'LOCATION_TO_SETUP' => 'setup', 'SETUP_TO_CONFIRMATION' => 'confirmation',
        'CONFIRMATION_TO_TRIGGER' => 'trigger', 'TRIGGER_TO_ENTRY' => 'entry', 'ENTRY_TO_CLOSED_TRADE' => 'closed_trade', default => 'closed_trade' }; }
    private function upstreamFor(string $stage): array { $all = ['opportunity', 'location', 'setup', 'confirmation', 'trigger', 'entry', 'closed_trade']; $index = array_search($stage, $all, true); return $index === false ? [] : array_slice($all, 0, $index); }
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
    private function digest(array $value): string { return hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)); }
    private function dojoCell(LabFailureDojoRun $run): string { return hash('sha256', implode('|', [$run->family, $run->target, $run->state_signature, data_get($run->failure_signature, 'context_v2', ''), data_get($run->evidence, 'data_hash', ''), data_get($run->evidence, 'execution_hash', ''), $run->repair_anchor_id])); }
    private function available(): bool { return Schema::hasTable('causal_stage_mastery_assessments'); }
}
