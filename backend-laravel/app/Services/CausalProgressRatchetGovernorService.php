<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Constitution for expensive research: retain causal depth, settle valuable
 * evidence first, and never mistake generation count for progress.
 */
class CausalProgressRatchetGovernorService
{
    public const PROTOCOL = 'causal_progress_ratchet_governor_v1';
    public const STAGES = ['none', 'context', 'location', 'setup', 'confirmation', 'trigger', 'entry', 'closed_trade', 'positive_after_cost_edge'];
    public const PHASES = ['DISCOVERED', 'CONFIRMATION_QUEUED', 'CONFIRMATION_SETTLED', 'REPLICATION_QUEUED', 'REPLICATION_SETTLED', 'ATTRIBUTED', 'CARTRIDGE_MATERIALIZED', 'MENTOR_INCUBATED', 'DESCENDANT_PROVEN', 'PARENT_ELIGIBLE', 'PAPER_ADMITTED'];
    public const LANES = ['edge_exploration', 'skill_consolidation', 'topology_pivot', 'adversarial_falsification'];

    public function __construct(private CausalStageMasteryDirectorService $stages) {}

    /** @return array<string,mixed> */
    public function classify(array $assessment, array $control, array $candidate): array
    {
        if (($assessment['status'] ?? '') === 'controllable') {
            $trades = (int) data_get($candidate, 'edge_observability.exit_outcome.closed_trade_count', data_get($candidate, 'total_trades', 0));
            $powered = $trades >= max(3, (int) config('services.edge_director.minimum_closed_trade_power', 10));
            $edge = $powered && (float) data_get($candidate, 'after_cost_expectancy_r', 0) > 0
                && ! (bool) data_get($candidate, 'forbidden_risk_bypass', false);
            return ['classification' => $edge ? 'positive_after_cost_edge' : ($powered ? 'behavior_changed_no_edge' : 'behavior_changed_economically_underpowered'),
                'retire' => false, 'next_action' => $edge ? 'confirm_replicate' : ($powered ? 'scalar_escape_ladder' : 'increase_economic_power')];
        }
        $target = (string) data_get($assessment, 'target_stage', 'none');
        $previous = $this->previousStage($target);
        $upstream = (int) data_get($assessment, 'candidate_counts.'.$previous, 0);
        $events = (int) data_get($assessment, 'event_delta', 0);
        if ($previous !== 'none' && $upstream === 0) return ['classification' => 'upstream_unreachable', 'retire' => false, 'next_action' => 'repair_upstream_stage'];
        if ($events === 0) return ['classification' => 'non_controlling_axis', 'retire' => true, 'next_action' => 'retire_axis'];
        return ['classification' => 'invariant_saturation', 'retire' => false, 'next_action' => 'topology_pivot'];
    }

    /** Record a stage result and advance only; a later no-op cannot erase a deeper ratchet. */
    public function recordAssessment(array $assessment, array $context, array $control, array $candidate): array
    {
        $classification = $this->classify($assessment, $control, $candidate);
        if (! $this->available()) return ['protocol' => self::PROTOCOL, ...$classification, 'status' => 'storage_unavailable', 'promotion_evidence' => false];
        $identity = $this->identity($context);
        $ratchet = DB::table('causal_progress_ratchets')->where('ratchet_key', $identity['ratchet_key'])->first();
        $stage = $classification['classification'] === 'positive_after_cost_edge' ? 'positive_after_cost_edge'
            : (($assessment['status'] ?? '') === 'controllable' ? (string) data_get($assessment, 'target_stage', 'none') : 'none');
        $depth = $this->depth($stage);
        $now = now();
        if (! $ratchet) {
            DB::table('causal_progress_ratchets')->insert([
                'ratchet_key' => $identity['ratchet_key'], 'symbol' => $identity['symbol'], 'timeframe' => $identity['timeframe'],
                'composition_key' => $identity['composition_key'], 'causal_baseline_id' => $context['causal_baseline_id'] ?? null,
                'baseline_epoch_hash' => $identity['baseline_epoch_hash'], 'data_hash' => $identity['data_hash'], 'execution_hash' => $identity['execution_hash'],
                'deepest_stage' => $stage, 'stage_depth' => $depth,
                'authority' => $depth >= $this->depth('trigger') && $stage !== 'positive_after_cost_edge' ? 'path_activating_scaffold' : 'none',
                'status' => 'active', 'evidence' => json_encode(['protocol' => self::PROTOCOL, 'history' => [], 'promotion_evidence' => false]), 'created_at' => $now, 'updated_at' => $now,
            ]);
            $ratchet = DB::table('causal_progress_ratchets')->where('ratchet_key', $identity['ratchet_key'])->first();
        }
        $oldDepth = (int) $ratchet->stage_depth;
        $advanced = $depth > $oldDepth;
        $scaffold = $depth >= $this->depth('trigger') && $stage !== 'positive_after_cost_edge';
        $ablation = ['required' => $scaffold, 'status' => $scaffold ? 'pending_scaffold_removal_ablation' : 'not_required',
            'arms' => $scaffold ? ['frozen_scaffold', 'scaffold_removed_ablation'] : [],
            'rule' => 'A scaffold is causal only while removal changes the same frozen-path outcome.', 'promotion_evidence' => false];
        $evidence = is_string($ratchet->evidence) ? (array) json_decode($ratchet->evidence, true) : (array) $ratchet->evidence;
        $history = (array) ($evidence['history'] ?? []); $history[] = ['assessment' => $assessment, 'classification' => $classification, 'ablation' => $ablation, 'at' => now()->utc()->toIso8601String()];
        DB::table('causal_progress_ratchets')->where('id', $ratchet->id)->update([
            'deepest_stage' => $advanced ? $stage : $ratchet->deepest_stage, 'stage_depth' => max($oldDepth, $depth),
            'authority' => max($oldDepth, $depth) >= $this->depth('trigger') && ($ratchet->authority ?? 'none') !== 'revoked' ? 'path_activating_scaffold' : $ratchet->authority,
            'evidence' => json_encode([...$evidence, 'history' => array_slice($history, -30), 'scaffold_ablation' => $ablation, 'promotion_evidence' => false]), 'updated_at' => $now,
        ]);
        $retirement = $this->recordAxisOutcome((int) $ratchet->id, $identity, (string) data_get($assessment, 'owner.gene', $context['axis'] ?? 'unknown'), $classification, $assessment);
        return ['protocol' => self::PROTOCOL, 'status' => 'recorded', 'ratchet_id' => $ratchet->id, 'deepest_stage' => $advanced ? $stage : $ratchet->deepest_stage,
            'advanced' => $advanced, 'authority' => max($oldDepth, $depth) >= $this->depth('trigger') ? 'path_activating_scaffold' : 'none',
            'classification' => $classification, 'retirement' => $retirement, 'scaffold_ablation' => $ablation, 'promotion_evidence' => false];
    }

    /** A preserved scaffold has an ablation obligation; no effect revokes its authority. */
    public function recordAblation(array $context, array $withScaffold, array $withoutScaffold): array
    {
        $identity = $this->identity($context);
        $same = $this->digest($withScaffold) === $this->digest($withoutScaffold);
        if ($this->available()) {
            $ratchet = DB::table('causal_progress_ratchets')->where('ratchet_key', $identity['ratchet_key'])->first();
            if ($ratchet) {
                $evidence = is_string($ratchet->evidence) ? (array) json_decode($ratchet->evidence, true) : (array) $ratchet->evidence;
                data_set($evidence, 'scaffold_ablation.status', $same ? 'ablation_failed_authority_revoked' : 'ablation_preserved');
                data_set($evidence, 'scaffold_ablation.settled_at', now()->utc()->toIso8601String());
                DB::table('causal_progress_ratchets')->where('id', $ratchet->id)->update([
                    'authority' => $same ? 'revoked' : $ratchet->authority,
                    'status' => $same ? 'scaffold_ablation_failed' : $ratchet->status,
                    'evidence' => json_encode($evidence), 'updated_at' => now(),
                ]);
            }
        }
        return ['protocol' => self::PROTOCOL, 'status' => $same ? 'scaffold_authority_revoked' : 'scaffold_ablation_preserved',
            'parent_authority' => false, 'paper_authority' => false, 'promotion_evidence' => false];
    }

    /**
     * Only a separately admitted economic authority replay can move a
     * ratchet beyond closed-trade depth. This opens replication/attribution
     * sequencing, not paper or parent authority.
     */
    public function recordPositiveAfterCostEdge(array $context, array $metrics): array
    {
        $identity = $this->identity($context);
        $powered = (int) data_get($metrics, 'edge_observability.exit_outcome.closed_trade_count', data_get($metrics, 'total_trades', 0))
            >= max(3, (int) config('services.edge_director.minimum_closed_trade_power', 10));
        $positive = (float) data_get($metrics, 'after_cost_expectancy_r', 0) > 0;
        $safe = ! (bool) data_get($metrics, 'forbidden_risk_bypass', false);
        if (! $powered || ! $positive || ! $safe) return ['protocol' => self::PROTOCOL, 'status' => 'economic_edge_not_admitted',
            'powered' => $powered, 'positive' => $positive, 'safe' => $safe, 'promotion_evidence' => false];
        if (! $this->available()) return ['protocol' => self::PROTOCOL, 'status' => 'storage_unavailable', 'promotion_evidence' => false];
        $ratchet = DB::table('causal_progress_ratchets')->where('ratchet_key', $identity['ratchet_key'])->first();
        if (! $ratchet) return ['protocol' => self::PROTOCOL, 'status' => 'ratchet_missing', 'promotion_evidence' => false];
        $evidence = is_string($ratchet->evidence) ? (array) json_decode($ratchet->evidence, true) : (array) $ratchet->evidence;
        DB::table('causal_progress_ratchets')->where('id', $ratchet->id)->update([
            'deepest_stage' => 'positive_after_cost_edge', 'stage_depth' => $this->depth('positive_after_cost_edge'),
            'authority' => 'economic_edge_confirmed', 'evidence' => json_encode([...$evidence,
                'positive_after_cost_edge' => ['metrics' => $metrics, 'recorded_at' => now()->utc()->toIso8601String(), 'promotion_evidence' => false],
                'promotion_evidence' => false]), 'updated_at' => now(),
        ]);
        return ['protocol' => self::PROTOCOL, 'status' => 'economic_edge_recorded', 'ratchet_id' => $ratchet->id,
            'deepest_stage' => 'positive_after_cost_edge', 'parent_authority' => false, 'paper_authority' => false, 'promotion_evidence' => false];
    }

    /** @return array<string,mixed> */
    public function mutationAuthority(string $stage, array $metrics = []): array
    {
        $stage = in_array($stage, self::STAGES, true) ? $stage : 'none';
        $mfe = (float) data_get($metrics, 'mfe_capture_ratio', data_get($metrics, 'mfe_capture', 0));
        $entry = $this->depth($stage) >= $this->depth('entry');
        $closed = $this->depth($stage) >= $this->depth('closed_trade');
        $positive = $stage === 'positive_after_cost_edge';
        $allowed = match (true) {
            $this->depth($stage) < $this->depth('confirmation') => ['strategy_location_setup'],
            $this->depth($stage) < $this->depth('trigger') => ['confirmation_family'],
            $this->depth($stage) < $this->depth('entry') => ['trigger_topology_mode'],
            ! $entry => ['invalidation_reward_chase_cost_admission'],
            ! $closed => ['execution_fill'],
            $mfe > 0 && $mfe < .70 => ['event_density_execution', 'management_exit'],
            $positive => ['replication_attribution'],
            default => ['event_density_execution'],
        };
        return ['protocol' => self::PROTOCOL, 'stage' => $stage, 'allowed' => $allowed,
            'management_allowed' => $closed && $mfe > 0 && $mfe < .70,
            'risk_allowed' => $positive, 'paper_allowed' => false, 'parent_allowed' => false, 'promotion_evidence' => false];
    }

    /** Promotion debt is deduplicated causal work, never raw-row pressure. */
    public function debt(string $symbol, string $timeframe, bool $persist = false): array
    {
        $symbol = strtoupper($symbol); $timeframe = strtoupper($timeframe);
        $base = $this->stages->promotionDebt($symbol, $timeframe);
        $unattributed = $this->available() ? DB::table('causal_progress_ratchets')->where('symbol', $symbol)->where('timeframe', $timeframe)
            ->where('authority', 'path_activating_scaffold')->where('status', 'active')->count() : 0;
        $ablationDebt = $this->available() ? DB::table('causal_progress_ratchets')->where('symbol', $symbol)->where('timeframe', $timeframe)
            ->where('authority', 'path_activating_scaffold')->where('status', 'active')->where('evidence->scaffold_ablation->status', 'pending_scaffold_removal_ablation')->count() : 0;
        $positiveEdges = $this->available() ? DB::table('causal_progress_ratchets')->where('symbol', $symbol)->where('timeframe', $timeframe)
            ->where('deepest_stage', 'positive_after_cost_edge')->where('status', 'active')->count() : 0;
        $mentors = Schema::hasTable('lab_skill_zoo_entries') ? DB::table('lab_skill_zoo_entries')->where('symbol', $symbol)->where('timeframe', $timeframe)
            ->where('component_status', 'mentor_seed')->whereNotIn('organism_viability', ['descendant_proven', 'eligible_parent'])->count() : 0;
        // settlement_lag is one actionable class of work.  Exposing it twice
        // under two names made the governor manufacture debt and could starve
        // discovery after an otherwise ordinary settlement backlog.
        $parts = ['unsettled_terminal_trials' => (int) ($base['settlement_lag'] ?? 0),
            'near_confirmable_cartridges' => (int) ($base['near_confirmable_cartridges'] ?? 0), 'unique_actionable_dojo_cells' => (int) ($base['unresolved_unique_dojo_cells'] ?? 0),
            'behavior_changed_unattributed_packets' => $unattributed, 'mentor_waiting_for_descendants' => $mentors,
            'pending_scaffold_ablations' => $ablationDebt, 'positive_after_cost_edges' => $positiveEdges];
        $score = array_sum($parts); $limit = max(1, (int) config('services.edge_director.promotion_debt_limit', 8));
        $result = ['protocol' => self::PROTOCOL, ...$parts, 'score' => $score, 'limit' => $limit,
            'high_value_debt' => $score >= $limit, 'promotion_evidence' => false];
        if ($persist && $this->available()) DB::table('causal_governor_debt_ledgers')->updateOrInsert(['ledger_key' => hash('sha256', implode('|', [self::PROTOCOL, $symbol, $timeframe, now()->utc()->format('YmdHi')]))],
            ['symbol' => $symbol, 'timeframe' => $timeframe, 'debt_score' => $score, 'mode' => $this->mode($result), 'debt' => json_encode($result), 'updated_at' => now(), 'created_at' => now()]);
        return $result;
    }

    /** Adaptive 2:1 starting point plus never-zero topology/adversarial lanes. */
    public function allocate(string $symbol, string $timeframe, bool $persist = false): array
    {
        $debt = $this->debt($symbol, $timeframe, $persist); $mode = $this->mode($debt);
        $allocation = match ($mode) {
            'positive_edge_autopilot' => ['edge_exploration' => 0, 'skill_consolidation' => 80, 'topology_pivot' => 10, 'adversarial_falsification' => 10],
            'debt_consolidation' => ['edge_exploration' => 0, 'skill_consolidation' => 75, 'topology_pivot' => 15, 'adversarial_falsification' => 10],
            'near_confirmation' => ['edge_exploration' => 35, 'skill_consolidation' => 45, 'topology_pivot' => 10, 'adversarial_falsification' => 10],
            'all_debt_closed' => ['edge_exploration' => 55, 'skill_consolidation' => 25, 'topology_pivot' => 10, 'adversarial_falsification' => 10],
            default => ['edge_exploration' => 40, 'skill_consolidation' => 30, 'topology_pivot' => 20, 'adversarial_falsification' => 10],
        };
        // Allocation is not frozen at its starting percentages. Only durable
        // research yield may tune it; generation count and raw P&L do not.
        $feedback = $this->rewardFeedback($symbol, $timeframe);
        if (! in_array($mode, ['positive_edge_autopilot', 'debt_consolidation'], true)) {
            $consolidationLift = min(15, (int) floor($feedback['reusable_skill_yield'] * 10) + (int) floor($feedback['descendant_transfer_yield'] * 10));
            $topologyLift = min(10, (int) floor($feedback['causal_depth_yield'] * 10));
            $allocation['skill_consolidation'] += $consolidationLift;
            $allocation['topology_pivot'] += $topologyLift;
            $allocation['edge_exploration'] = max(5, $allocation['edge_exploration'] - $consolidationLift - $topologyLift);
        }
        $cadence = match ($mode) {
            'positive_edge_autopilot' => ['skill_consolidation', 'skill_consolidation', 'skill_consolidation'],
            'debt_consolidation' => ['skill_consolidation'],
            'near_confirmation' => ['edge_exploration', 'skill_consolidation'],
            'all_debt_closed' => ['edge_exploration', 'edge_exploration', 'edge_exploration', 'skill_consolidation'],
            default => ['edge_exploration', 'edge_exploration', 'skill_consolidation'],
        };
        $result = ['protocol' => self::PROTOCOL, 'mode' => $mode, 'allocation_percent' => $allocation, 'debt' => $debt, 'reward_feedback' => $feedback,
            'cadence' => $cadence,
            'invariants' => ['novelty_lane_nonzero' => $allocation['edge_exploration'] > 0 || $allocation['topology_pivot'] > 0,
                'adversarial_lane_nonzero' => $allocation['adversarial_falsification'] > 0, 'scalar_exploration_blocked' => $debt['high_value_debt']], 'promotion_evidence' => false];
        if ($persist && $this->available()) DB::table('causal_governor_allocations')->updateOrInsert(['allocation_key' => hash('sha256', implode('|', [self::PROTOCOL, strtoupper($symbol), strtoupper($timeframe), now()->utc()->format('YmdHi')]))],
            ['symbol' => strtoupper($symbol), 'timeframe' => strtoupper($timeframe), 'mode' => $mode, 'allocation' => json_encode($result), 'updated_at' => now(), 'created_at' => now()]);
        return $result;
    }

    /** Admission requires a durable decisive outcome contract, not completion alone. */
    public function admitExpensivePacket(array $packet, string $symbol, string $timeframe): array
    {
        $debt = $this->debt($symbol, $timeframe); $axis = (string) ($packet['structural_axis'] ?? $packet['axis'] ?? '');
        $topology = str_contains($axis, 'policy') || in_array($axis, ['entry_model', 'entry_mode'], true);
        $value = ((float) ($packet['decisive_outcome_probability'] ?? .5) * (float) ($packet['causal_depth_gain'] ?? 1)
            * (float) ($packet['transfer_potential'] ?? .5) * (float) ($packet['novelty'] ?? .5)) / max(.1, (float) ($packet['compute_cost'] ?? 1))
            - (float) ($packet['duplicate_penalty'] ?? 0) - (($debt['high_value_debt'] && ! $topology) ? .5 : 0);
        $allowed = ! ($debt['high_value_debt'] && ! $topology);
        return ['protocol' => self::PROTOCOL, 'allowed' => $allowed && $value > 0, 'reason' => ! $allowed ? 'HIGH_VALUE_PROMOTION_DEBT_CONSOLIDATION_REQUIRED' : ($value > 0 ? null : 'INFORMATION_GAIN_NON_POSITIVE'),
            'expected_value' => round($value, 6), 'durable_outcomes' => ['edge_found', 'axis_causally_rejected', 'reusable_scaffold', 'next_topology_pivot'],
            'debt' => $debt, 'promotion_evidence' => false];
    }

    /** Exactly-once progression state; restarts resume this identity instead of creating a cohort. */
    public function progress(array $context, string $phase, string $status, array $evidence = []): array
    {
        if (! in_array($phase, self::PHASES, true)) return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason' => 'UNKNOWN_PROGRESS_PHASE', 'promotion_evidence' => false];
        $identity = $this->identity($context); $key = hash('sha256', implode('|', [$identity['composition_key'], $context['causal_baseline_id'] ?? 0, $phase, $identity['data_hash'], $identity['execution_hash'], $context['window_plan_hash'] ?? '', $context['intervention_hash'] ?? '']));
        if ($this->available()) DB::table('causal_progress_states')->updateOrInsert(['progress_key' => $key], ['causal_progress_ratchet_id' => $context['ratchet_id'] ?? null,
            'phase' => $phase, 'status' => $status, 'intervention_hash' => (string) ($context['intervention_hash'] ?? ''), 'window_plan_hash' => (string) ($context['window_plan_hash'] ?? ''),
            'evidence' => json_encode(['protocol' => self::PROTOCOL, ...$evidence, 'promotion_evidence' => false]), 'settled_at' => str_ends_with($status, 'SETTLED') || in_array($phase, ['ATTRIBUTED', 'CARTRIDGE_MATERIALIZED', 'MENTOR_INCUBATED', 'DESCENDANT_PROVEN', 'PARENT_ELIGIBLE', 'PAPER_ADMITTED'], true) ? now() : null, 'updated_at' => now(), 'created_at' => now()]);
        return ['protocol' => self::PROTOCOL, 'status' => 'recorded', 'progress_key' => $key, 'phase' => $phase, 'promotion_evidence' => false];
    }

    /** Leading indicators measure authority gained, not generations emitted. */
    public function kpis(string $symbol, string $timeframe): array
    {
        $symbol = strtoupper($symbol); $timeframe = strtoupper($timeframe);
        if (! $this->available()) return ['protocol' => self::PROTOCOL, 'available' => false, 'promotion_evidence' => false];
        $axes = DB::table('causal_axis_retirements')->where('symbol', $symbol)->where('timeframe', $timeframe);
        $axisTotal = (clone $axes)->count(); $noops = (clone $axes)->where('classification', 'non_controlling_axis')->count();
        $ratchets = DB::table('causal_progress_ratchets')->where('symbol', $symbol)->where('timeframe', $timeframe);
        $cohorts = Schema::hasTable('edge_genesis_trials') ? DB::table('edge_genesis_trials as t')->join('edge_genesis_passports as p', 'p.id', '=', 't.edge_genesis_passport_id')
            ->where('p.symbol', $symbol)->where('p.timeframe', $timeframe)->count() : 0;
        $decisive = (clone $axes)->whereIn('classification', ['non_controlling_axis', 'invariant_saturation', 'positive_after_cost_edge', 'behavior_changed_no_edge'])->count();
        $transplants = Schema::hasTable('skill_cartridge_transplant_trials') ? DB::table('skill_cartridge_transplant_trials')->where('symbol', $symbol)->where('timeframe', $timeframe) : null;
        $settledTransplants = $transplants ? (clone $transplants)->whereNotNull('settled_at')->count() : 0;
        $passedTransplants = $transplants ? (clone $transplants)->whereIn('status', ['preserved', 'mentor_seed', 'confirmed'])->count() : 0;
        $settledTrials = Schema::hasTable('edge_genesis_trials') ? DB::table('edge_genesis_trials as t')->join('edge_genesis_passports as p', 'p.id', '=', 't.edge_genesis_passport_id')
            ->where('p.symbol', $symbol)->where('p.timeframe', $timeframe)->whereNotNull('t.settled_at')->get(['t.created_at', 't.settled_at']) : collect();
        $latencies = $settledTrials->map(fn ($trial): int => max(0, \Carbon\Carbon::parse($trial->created_at)->diffInSeconds(\Carbon\Carbon::parse($trial->settled_at))));
        $descendants = Schema::hasTable('descendant_value_trials') ? DB::table('descendant_value_trials')->where('symbol', $symbol)->where('timeframe', $timeframe)->where('status', 'settled')->get(['evidence']) : collect();
        $improvingDescendants = $descendants->filter(fn ($row): bool => (bool) data_get(json_decode((string) $row->evidence, true), 'improved_over_mentor', false))->count();
        return ['protocol' => self::PROTOCOL, 'available' => true,
            'no_op_rate' => $axisTotal > 0 ? round($noops / $axisTotal, 6) : 0.0,
            'causal_depth_gain_per_cohort' => $cohorts > 0 ? round((float) (clone $ratchets)->sum('stage_depth') / $cohorts, 6) : 0.0,
            'decisive_outcome_rate' => $cohorts > 0 ? round($decisive / $cohorts, 6) : 0.0,
            'promotion_debt' => $this->debt($symbol, $timeframe), 'near_confirmable_cartridges' => (int) data_get($this->debt($symbol, $timeframe), 'near_confirmable_cartridges', 0),
            'path_activating_scaffolds' => (clone $ratchets)->where('authority', 'path_activating_scaffold')->count(),
            'settlement_latency_seconds' => $latencies->isNotEmpty() ? round((float) $latencies->avg(), 3) : null,
            'skill_transfer_rate' => $settledTransplants > 0 ? round($passedTransplants / $settledTransplants, 6) : null,
            'descendant_lift' => $descendants->isNotEmpty() ? round($improvingDescendants / $descendants->count(), 6) : null,
            'topology_pivot_success' => (clone $axes)->where('classification', 'invariant_saturation')->count(),
            'knowledge_yield_per_expensive_cohort' => $cohorts > 0 ? round($decisive / $cohorts, 6) : 0.0,
            'promotion_evidence' => false];
    }

    /** The next work is read from durable state; a restart never invents a new identity. */
    public function resumePlan(string $symbol, string $timeframe): array
    {
        if (! $this->available()) return ['protocol' => self::PROTOCOL, 'status' => 'unavailable', 'promotion_evidence' => false];
        $states = DB::table('causal_progress_states as s')->join('causal_progress_ratchets as r', 'r.id', '=', 's.causal_progress_ratchet_id')
            ->where('r.symbol', strtoupper($symbol))->where('r.timeframe', strtoupper($timeframe))
            ->whereIn('s.status', ['queued', 'DISCOVERED', 'CONFIRMATION_QUEUED', 'REPLICATION_QUEUED'])->orderBy('s.id')->get(['s.progress_key', 's.phase', 's.status', 'r.id as ratchet_id']);
        return ['protocol' => self::PROTOCOL, 'status' => $states->isEmpty() ? 'idle' : 'resume_exact_existing_state',
            'states' => $states->map(fn ($state): array => ['progress_key' => $state->progress_key, 'phase' => $state->phase, 'status' => $state->status, 'ratchet_id' => $state->ratchet_id])->all(),
            'new_cohort_creation_allowed' => false, 'promotion_evidence' => false];
    }

    /**
     * A consolidation slot settles durable evidence only. It intentionally
     * cannot create a fresh generation or dispatch speculative exploration.
     * Calls are idempotent and each underlying reconciliation owns its own
     * exactly-once identity.
     *
     * @return array<string,mixed>
     */
    public function consolidationPlan(string $symbol, string $timeframe, bool $apply = false): array
    {
        $allocation = $this->allocate($symbol, $timeframe, $apply);
        $actions = [
            'edge_terminal_settlement' => ['eligible' => true, 'result' => app(DependencyAwareEdgeGenesisFoundryService::class)->reconcileDiscoveryOutcomes($symbol, $timeframe, $apply)],
            'compiled_axis_settlement' => ['eligible' => true, 'result' => app(DependencyAwareEdgeGenesisFoundryService::class)->reconcileCompiledHypothesisSettlements($symbol, $timeframe, $apply)],
            'context_contract_v2_reprojection' => ['eligible' => true, 'result' => app(FailureDojoService::class)->reprojectContextContractV2($symbol, $timeframe, $apply)],
            'dojo_context_firewall' => ['eligible' => true, 'result' => app(FailureDojoService::class)->reconcileContextFirewall($symbol, $timeframe, $apply)],
            'legacy_cartridge_terminalization' => ['eligible' => true, 'result' => $apply
                ? app(CanonicalSkillCartridgeService::class)->reconcileLegacy($symbol, $timeframe)
                : ['status' => 'would_reconcile_legacy_cartridges']],
            'provisional_cartridge_next_actions' => ['eligible' => true, 'result' => $apply
                ? app(CanonicalSkillCartridgeService::class)->reconcileProvisionalConfirmationState($symbol, $timeframe)
                : ['status' => 'would_reconcile_provisional_cartridge_actions']],
            'immutable_cartridge_revision_seal' => ['eligible' => true, 'result' => $apply
                ? app(CanonicalSkillCartridgeService::class)->backfillImmutableRevisions($symbol, $timeframe)
                : ['status' => 'would_seal_immutable_revisions']],
            'academy_historical_projection' => ['eligible' => true, 'result' => app(DependencyAwareEdgeGenesisFoundryService::class)->reconcileAcademyProjections($symbol, $timeframe, $apply)],
        ];
        return ['protocol' => self::PROTOCOL, 'status' => $apply ? 'consolidated' : 'planned', 'allocation' => $allocation,
            'actions' => $actions, 'new_cohort_creation_allowed' => false, 'promotion_evidence' => false];
    }

    public function escalation(array $context, string $axis): array
    {
        if (! $this->available()) return ['level' => 'L0_scalar_threshold', 'next_action' => 'scalar'];
        $identity = $this->identity($context);
        $count = DB::table('causal_axis_retirements')->where('composition_key', $identity['composition_key'])->where('axis', $axis)
            ->where('classification', 'behavior_changed_no_edge')->sum('observations');
        $level = min(5, intdiv((int) $count, 2));
        $actions = ['scalar_threshold', 'component_topology', 'strategy_tactic_recombination', 'temporal_role_binding', 'regime_specialist_router', 'architecture_retirement'];
        return ['protocol' => self::PROTOCOL, 'level' => 'L'.$level.'_'.$actions[$level], 'next_action' => $actions[$level],
            'scalar_reentry_forbidden' => $level >= 1, 'promotion_evidence' => false];
    }

    private function recordAxisOutcome(int $ratchetId, array $identity, string $axis, array $classification, array $assessment): array
    {
        $key = hash('sha256', implode('|', [$identity['composition_key'], $identity['baseline_epoch_hash'], $axis, $identity['data_hash'], $identity['execution_hash'], $classification['classification']]));
        $row = DB::table('causal_axis_retirements')->where('retirement_key', $key)->first(); $observations = ((int) ($row->observations ?? 0)) + 1;
        $retired = (bool) $classification['retire'] && $observations >= 2;
        DB::table('causal_axis_retirements')->updateOrInsert(['retirement_key' => $key], ['causal_progress_ratchet_id' => $ratchetId, 'symbol' => $identity['symbol'], 'timeframe' => $identity['timeframe'], 'composition_key' => $identity['composition_key'],
            'axis' => $axis, 'classification' => $classification['classification'], 'observations' => $observations, 'retired' => $retired,
            'evidence' => json_encode(['assessment' => $assessment, 'next_action' => $classification['next_action'], 'promotion_evidence' => false]), 'updated_at' => now(), 'created_at' => now()]);
        return ['classification' => $classification['classification'], 'observations' => $observations, 'retired' => $retired, 'next_action' => $classification['next_action']];
    }
    private function identity(array $context): array { $symbol = strtoupper((string) ($context['symbol'] ?? '')); $timeframe = strtoupper((string) ($context['timeframe'] ?? '')); $composition = (string) ($context['composition_key'] ?? 'unknown'); $baseline = (string) ($context['baseline_epoch_hash'] ?? 'unknown'); $data = (string) ($context['data_hash'] ?? 'unknown'); $execution = (string) ($context['execution_hash'] ?? 'unknown'); return ['symbol' => $symbol, 'timeframe' => $timeframe, 'composition_key' => $composition, 'baseline_epoch_hash' => $baseline, 'data_hash' => $data, 'execution_hash' => $execution, 'ratchet_key' => hash('sha256', implode('|', [self::PROTOCOL, $symbol, $timeframe, $composition, $baseline, $data, $execution]))]; }
    private function previousStage(string $stage): string { $index = array_search($stage, self::STAGES, true); return $index === false || $index < 1 ? 'none' : self::STAGES[$index - 1]; }
    private function depth(string $stage): int { $index = array_search($stage, self::STAGES, true); return $index === false ? 0 : $index; }
    private function digest(array $value): string { return hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)); }
    /** Durable learning yield feeds allocation; nothing here authorizes promotion. */
    private function rewardFeedback(string $symbol, string $timeframe): array
    {
        $ratchets = $this->available() ? DB::table('causal_progress_ratchets')->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe)) : collect();
        $count = $ratchets instanceof \Illuminate\Database\Query\Builder ? (clone $ratchets)->count() : 0;
        $depth = $count > 0 ? (float) (clone $ratchets)->sum('stage_depth') / $count : 0.;
        $skills = Schema::hasTable('lab_skill_zoo_entries') ? DB::table('lab_skill_zoo_entries')->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->whereIn('component_status', ['confirmed', 'mentor_seed'])->count() : 0;
        $descendants = Schema::hasTable('descendant_value_trials') ? DB::table('descendant_value_trials')->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->where('status', 'settled')->count() : 0;
        return ['causal_depth_yield' => round($depth / max(1, $this->depth('positive_after_cost_edge')), 6),
            'reusable_skill_yield' => round($skills / max(1, $count), 6), 'descendant_transfer_yield' => round($descendants / max(1, $count), 6),
            'generation_count_excluded' => true, 'raw_profit_excluded' => true, 'promotion_evidence' => false];
    }
    private function mode(array $debt): string { if ((int) ($debt['positive_after_cost_edges'] ?? 0) > 0) return 'positive_edge_autopilot'; if ((bool) ($debt['high_value_debt'] ?? false)) return 'debt_consolidation'; if ((int) ($debt['near_confirmable_cartridges'] ?? 0) > 0) return 'near_confirmation'; if ((int) ($debt['score'] ?? 0) === 0) return 'all_debt_closed'; return 'normal_two_explore_one_consolidate'; }
    private function available(): bool { return Schema::hasTable('causal_progress_ratchets') && Schema::hasTable('causal_progress_states'); }
}
