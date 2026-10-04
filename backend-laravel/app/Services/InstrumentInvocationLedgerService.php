<?php

namespace App\Services;

use App\Models\AgentLearningCausalExperiment;
use App\Models\InstrumentInvocationLedger;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabLearningLanePair;
use App\Models\PaperOrder;
use App\Models\PaperSignal;
use App\Models\PaperSignalOutcome;
use App\Models\TradingInstrument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class InstrumentInvocationLedgerService
{
    public const PROTOCOL = 'instrument_invocation_ledger_v1';

    public function __construct(private TradingInstrumentOperatingSystemService $operatingSystem) {}

    /** Bounded recovery of missing projections, not a rewrite of settled history. */
    public function pendingResearchPairs(string $symbol, string $timeframe = 'H1', int $limit = 1, ?int $pairId = null): array
    {
        if (! Schema::hasTable('instrument_invocation_ledger') || ! Schema::hasTable('lab_learning_lane_pairs')) return [];
        $rows = InstrumentInvocationLedger::query()->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))->whereNull('paper_signal_id')
            ->whereNull('settled_at')->where('verdict', 'awaiting_paired_control')
            ->when($pairId, fn ($query) => $query->whereIn('lab_agent_id',
                LabLearningLanePair::whereKey($pairId)->pluck('candidate_agent_id')
                    ->concat(LabLearningLanePair::whereKey($pairId)->pluck('control_agent_id'))))
            ->orderBy('id')->limit(200)->get()->groupBy('lab_agent_id');
        if ($rows->isEmpty()) return [];
        $ready = [];
        $pairs = LabLearningLanePair::query()->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))->where('pair_integrity_status', 'verified')
            ->where(fn ($query) => $query->whereIn('candidate_agent_id', $rows->keys())->orWhereIn('control_agent_id', $rows->keys()))
            ->when($pairId, fn ($query) => $query->whereKey($pairId))
            ->with('candidateAgent.modelVersion', 'controlAgent.modelVersion',
                'candidateResponseMap', 'controlResponseMap')->orderByDesc('id')->limit(20)->get();
        foreach ($pairs as $pair) {
            if (! $pair->isVerifiedControlPair()) continue;
            $assignment = (array) data_get($pair->candidateAgent?->modelVersion?->metadata, 'instrument_research_assignment', []);
            // A reservation and an attested assignment must still name this
            // exact pair/run. Old terminal rejection receipts remain intact.
            if (! $this->ordinaryReservationMatchesPair($assignment, $pair)
                && ! $this->causalTripletReservationMatchesPair($assignment, $pair)) continue;
            $pending = $rows->get($pair->candidate_agent_id, collect())->filter(fn ($row): bool =>
                (string) data_get($row->metadata, 'evidence_run_id') === (string) $pair->candidate_evidence_run_id
                && filled(data_get($assignment, 'assignment_hash'))
                && hash_equals((string) $assignment['assignment_hash'], (string) data_get($row->metadata, 'assignment_hash', '')));
            $controlPending = $this->controlReferenceRows($pair);
            if ($pending->isEmpty() && $controlPending->isEmpty()) continue;
            $matched = $this->pairedContextOutcomes($pair, (array) $pair->candidate_metrics, (array) $pair->control_metrics);
            $ready[] = ['pair_id' => (int) $pair->id, 'generation_id' => (int) $pair->lab_generation_id,
                'candidate_agent_id' => (int) $pair->candidate_agent_id,
                'pending_invocations' => $pending->count() + $controlPending->count(),
                'pending_candidate_invocations' => $pending->count(),
                'pending_control_references' => $controlPending->count(),
                'powered_exact_contexts' => count($matched), 'priority' => $matched === [] ? 1 : 2,
                'source' => 'exact_control_projection_debt', 'economic_credit_allowed' => false];
        }
        usort($ready, fn (array $a, array $b): int => [$b['priority'], -$b['pair_id']] <=> [$a['priority'], -$a['pair_id']]);
        return array_slice($ready, 0, max(1, min(20, $limit)));
    }

    public function reconcileResearchPair(int $pairId): array
    {
        return DB::transaction(function () use ($pairId): array {
            $pair = LabLearningLanePair::query()->lockForUpdate()->find($pairId);
            $pending = $pair ? InstrumentInvocationLedger::query()->where('lab_agent_id', $pair->candidate_agent_id)
                ->whereNull('paper_signal_id')->whereNull('settled_at')->where('verdict', 'awaiting_paired_control')
                ->lockForUpdate()->get()->filter(fn ($row): bool =>
                    (string) data_get($row->metadata, 'evidence_run_id') === (string) $pair->candidate_evidence_run_id) : collect();
            $controlBefore = $pair ? $this->controlReferenceRows($pair)->count() : 0;
            $settled = $pending->isEmpty() ? 0 : $this->settleResearchPair($pair, true);
            if ($pair) $this->settleControlReferences($pair);
            $controlRemaining = $pair ? $this->controlReferenceRows($pair)->count() : 0;
            $remaining = $pending->filter(fn ($row): bool => $row->fresh()?->settled_at === null)->count();
            return ['protocol' => 'instrument_pending_pair_reconciliation_v1', 'status' => 'completed',
                'pair_id' => $pairId, 'pending_before' => $pending->count(), 'pending_after' => $remaining,
                'causal_projections' => $settled, 'historical_terminal_receipts_rewritten' => false,
                'control_pending_before' => $controlBefore, 'control_pending_after' => $controlRemaining,
                'control_references_closed' => $controlBefore - $controlRemaining,
                'promotion_evidence' => false];
        });
    }

    /** A control invocation is reference evidence, never a candidate improvement. */
    private function controlReferenceRows(LabLearningLanePair $pair): \Illuminate\Support\Collection
    {
        if (! $pair->isVerifiedControlPair()) return collect();
        $candidate = (array) data_get($pair->candidateAgent?->modelVersion?->metadata, 'instrument_research_assignment', []);
        $control = (array) data_get($pair->controlAgent?->modelVersion?->metadata, 'instrument_research_assignment', []);
        if ((! $this->ordinaryReservationMatchesPair($candidate, $pair)
                && ! $this->causalTripletReservationMatchesPair($candidate, $pair))
            || data_get($control, 'experiment_role') !== 'frozen_control'
            || (int) data_get($control, 'lab_agent_id') !== (int) $pair->control_agent_id
            || (int) data_get($control, 'model_version_id') !== (int) $pair->controlAgent?->model_version_id
            || (int) data_get($control, 'lab_generation_id') !== (int) $pair->lab_generation_id
            || data_get($control, 'pair_reservation.status') !== 'reserved'
            || (int) data_get($control, 'pair_reservation.control_agent_id') !== (int) $pair->control_agent_id
            || ! filled(data_get($control, 'assignment_hash'))
            || ! filled(data_get($control, 'instrument_key_role_hash'))
            || data_get($control, 'instrument_key_role_hash') !== data_get($candidate, 'instrument_key_role_hash')
            || ! filled(data_get($control, 'activation_context_hash'))
            || data_get($control, 'activation_context_hash') !== data_get($candidate, 'activation_context_hash')) return collect();
        $protocol = data_get($control, 'pair_reservation.protocol');
        if ($protocol !== data_get($candidate, 'pair_reservation.protocol')
            || ($protocol === 'instrument_exact_pair_reservation_v1'
                && ((int) data_get($control, 'pair_reservation.candidate_agent_id') !== (int) $pair->candidate_agent_id
                    || data_get($control, 'pair_reservation.pair_key') !== data_get($candidate, 'pair_reservation.pair_key')))
            || ($protocol === 'causal_triplet_instrument_reservation_v1'
                && data_get($control, 'pair_reservation.experiment_key') !== data_get($candidate, 'pair_reservation.experiment_key'))) return collect();
        return InstrumentInvocationLedger::where('lab_agent_id', $pair->control_agent_id)->whereNull('paper_signal_id')
            ->whereNull('settled_at')->where('verdict', 'awaiting_paired_control')->get()
            ->filter(fn ($row): bool => (string) data_get($row->metadata, 'evidence_run_id') === (string) $pair->control_evidence_run_id
                && hash_equals((string) $control['assignment_hash'], (string) data_get($row->metadata, 'assignment_hash', '')));
    }

    private function settleControlReferences(LabLearningLanePair $pair): int
    {
        return DB::transaction(function () use ($pair): int {
            $closed = 0;
            foreach ($this->controlReferenceRows($pair) as $row) {
                $current = InstrumentInvocationLedger::whereKey($row->id)->whereNull('settled_at')->lockForUpdate()->first();
                if (! $current) continue;
                $current->update(['verdict' => 'control_reference_consumed', 'settled_at' => now(),
                    'metadata' => [...(array) $current->metadata, 'paired_control_reference' => [
                        'pair_id' => (int) $pair->id, 'candidate_agent_id' => (int) $pair->candidate_agent_id,
                        'reference_only' => true, 'causal_credit_allowed' => false, 'promotion_evidence' => false]]]);
                $closed++;
            }
            return $closed;
        });
    }

    public function recordDecision(PaperSignal $signal, ?LabAgent $agent = null): int
    {
        $agent ??= LabAgent::query()->where('model_version_id', $signal->model_version_id)->latest('id')->first();
        $router = (array) data_get($signal->payload, 'trading_instrument_router', []);
        $bundle = (array) data_get($signal->payload, 'instrument_bundle', data_get($signal->payload, 'trading_instrument_router.instrument_bundle', []));
        $keys = array_values((array) ($bundle['keys'] ?? data_get($signal->payload, 'trading_cognitive_stack.instrument_composer.instrument_keys', [])));
        $state = (array) ($router['state'] ?? []);
        $input = ['state' => $state, 'bundle' => $keys, 'decision' => $signal->decision, 'payload_hash' => $signal->payload_hash];
        $inputHash = hash('sha256', json_encode($input, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        foreach ($keys as $key) {
            $instrument = TradingInstrument::query()->where('instrument_key', $key)->first();
            InstrumentInvocationLedger::updateOrCreate(['invocation_key' => hash('sha256', implode('|', [$signal->id, $key, $inputHash]))], [
                'lab_agent_id' => $agent?->id, 'lab_generation_id' => $agent?->lab_generation_id,
                'paper_signal_id' => $signal->id, 'trading_instrument_id' => $instrument?->id,
                'instrument_key' => $key, 'symbol' => $signal->symbol, 'timeframe' => $signal->timeframe,
                'state_key' => data_get($state, 'state_key'), 'input_hash' => $inputHash,
                'output_hash' => hash('sha256', (string) $signal->payload_hash),
                'used_in_decision' => true, 'used_in_execution' => false, 'verdict' => 'invoked',
                'metadata' => [
                    'protocol' => self::PROTOCOL, 'tool_card' => $instrument?->definition,
                    'strategy' => data_get($signal->payload, 'trading_cognitive_stack.strategy_proposer'),
                    'tactic' => data_get($signal->payload, 'trading_cognitive_stack.tactic_executor'),
                    'risk' => data_get($signal->payload, 'trading_cognitive_stack.execution_risk_sentinel'),
                    'promotion_evidence' => false,
                ], 'invoked_at' => now(),
            ]);
        }

        return count($keys);
    }

    public function settle(PaperOrder $order, PaperSignalOutcome $outcome): int
    {
        $control = (array) data_get($order->paperSignal?->payload, 'instrument_control', []);
        $paired = (bool) data_get($control, 'control_contract.paired_isolated', false) && (array) data_get($control, 'metrics', []) !== [];
        $delta = $paired ? ['profit_percent' => (float) $outcome->profit_percent - (float) data_get($control, 'metrics.profit_percent', 0)] : [];
        $verdict = ! $paired ? 'not_sufficiently_tested' : ((float) $delta['profit_percent'] > 0 ? 'helped' : ((float) $delta['profit_percent'] < 0 ? 'harmed' : 'neutral'));

        return InstrumentInvocationLedger::query()->where('paper_signal_id', $order->paper_signal_id)->update([
            'paper_order_id' => $order->id, 'used_in_execution' => $order->status === 'closed', 'verdict' => $verdict,
            'causal_contribution' => $paired ? (float) $delta['profit_percent'] : null,
            'control_delta' => $delta ?: null, 'settled_at' => now(),
        ]);
    }

    /**
     * Open a research-only invocation from Python's runtime attestation.
     * Merely assigning a catalogue item is intentionally insufficient.
     */
    public function recordResearchObservation(LabAgent $agent, array $result, string $stage = 'screening'): int
    {
        $agent->loadMissing('modelVersion');
        $assignment = (array) data_get($agent->modelVersion?->metadata, 'instrument_research_assignment', []);
        $trace = (array) data_get($result, 'instrument_research_trace', []);
        $runId = (string) data_get($result, 'evidence_run_id', '');
        if ((string) data_get($assignment, 'protocol') !== LabInstrumentResearchService::PROTOCOL
            || (string) data_get($assignment, 'status') !== 'assigned'
            || (string) data_get($trace, 'protocol') !== LabInstrumentResearchService::RUNTIME_TRACE_PROTOCOL
            || ! in_array((string) data_get($trace, 'status'), ['consumed', 'decision_observed'], true)
            || data_get($trace, 'assignment_hash_valid') !== true
            || data_get($trace, 'parameter_hash_valid') !== true
            || data_get($trace, 'runtime_bindings_valid') !== true
            || data_get($trace, 'activation_contracts_valid') !== true
            || data_get($trace, 'runtime_observations_valid') !== true
            || data_get($trace, 'runtime_observed') !== true
            || $runId === ''
            || ! hash_equals((string) data_get($assignment, 'assignment_hash'), (string) data_get($trace, 'assignment_hash'))) {
            return 0;
        }
        if ((string) data_get($assignment, 'experiment_role') === 'candidate'
            && (string) data_get($assignment, 'pair_reservation.status') !== 'reserved') {
            return 0;
        }

        $selected = collect((array) data_get($assignment, 'selected', []))->keyBy('instrument_key');
        $inputHash = hash('sha256', json_encode([
            'assignment_hash' => data_get($assignment, 'assignment_hash'),
            'parameter_hash' => data_get($assignment, 'parameter_hash'),
            'evidence_run_id' => $runId,
            'stage' => $stage,
        ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        $outputHash = hash('sha256', json_encode([
            'trace' => $trace,
            'net_profit_percent' => data_get($result, 'net_profit_percent'),
            'profit_factor' => data_get($result, 'profit_factor'),
            'max_drawdown_percent' => data_get($result, 'max_drawdown_percent', data_get($result, 'max_drawdown')),
            'total_trades' => data_get($result, 'total_trades'),
        ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        $count = 0;
        foreach ((array) data_get($trace, 'instruments', []) as $runtime) {
            $runtimeStatus = is_array($runtime) ? (string) ($runtime['status'] ?? '') : '';
            if (! is_array($runtime)
                || ! in_array($runtimeStatus, ['consumed', 'evaluated_veto', 'evaluated_abstain'], true)
                || data_get($runtime, 'runtime_observation_valid') !== true
                || data_get($runtime, 'runtime_receipt_consistent') !== true
                || (string) data_get($runtime, 'activation_contract_protocol') !== LabInstrumentResearchService::ACTIVATION_PROTOCOL) {
                continue;
            }
            $key = (string) ($runtime['instrument_key'] ?? '');
            $declaration = (array) ($selected->get($key) ?? []);
            if ($key === '' || $declaration === []) {
                continue;
            }
            $instrument = TradingInstrument::query()->where('instrument_key', $key)->first();
            if (! $instrument) {
                continue;
            }
            InstrumentInvocationLedger::query()->updateOrCreate([
                'invocation_key' => hash('sha256', implode('|', ['lab-research', $agent->id, $runId, $stage, $key, $inputHash])),
            ], [
                'lab_agent_id' => $agent->id,
                'lab_generation_id' => $agent->lab_generation_id,
                'paper_signal_id' => null,
                'paper_order_id' => null,
                'trading_instrument_id' => $instrument?->id,
                'instrument_key' => $key,
                'symbol' => strtoupper((string) $agent->symbol),
                'timeframe' => strtoupper((string) $agent->timeframe),
                'state_key' => 'historical_mixed|stratified_replay',
                'input_hash' => $inputHash,
                'output_hash' => $outputHash,
                'used_in_decision' => in_array($runtimeStatus, ['consumed', 'evaluated_veto'], true),
                // This column denotes paper/live execution. The replay was
                // genuinely executed but remains research-only metadata.
                'used_in_execution' => false,
                'verdict' => match ($runtimeStatus) {
                    'evaluated_veto' => 'evaluated_veto_research_only',
                    'evaluated_abstain' => 'evaluated_abstain_research_only',
                    default => 'awaiting_paired_control',
                },
                'causal_contribution' => null,
                'control_delta' => null,
                'metadata' => [
                    'protocol' => self::PROTOCOL,
                    'source' => 'lab_runtime_attestation',
                    'evidence_run_id' => $runId,
                    'stage' => $stage,
                    'assignment_hash' => data_get($assignment, 'assignment_hash'),
                    'playbook_key' => data_get($assignment, 'playbook_key'),
                    'bundle_identity' => data_get($assignment, 'bundle_identity'),
                    'activation_policy' => data_get($assignment, 'activation_policy'),
                    'bundle_fully_activated' => (bool) data_get($trace, 'bundle_fully_activated', false),
                    'bundle_activation_context_keys' => array_values((array) data_get($trace, 'bundle_activation_context_keys', [])),
                    'bundle_activation_exact_context_keys' => array_values((array) data_get($trace, 'bundle_activation_exact_context_keys', [])),
                    'pair_reservation' => data_get($assignment, 'pair_reservation'),
                    'declaration' => $declaration,
                    'runtime_trace' => $runtime,
                    'runtime_disposition' => $runtimeStatus,
                    'decision_effect' => $runtimeStatus === 'evaluated_veto'
                        ? 'VETO'
                        : ($runtimeStatus === 'consumed' ? 'ALLOW_OR_MODIFY' : 'NO_EFFECT'),
                    'research_replay_executed' => true,
                    'paper_execution_authority' => false,
                    'promotion_evidence' => false,
                ],
                'invoked_at' => now(),
            ]);
            $count++;
        }

        return $count;
    }

    /**
     * Move only an exact same-generation frozen pair into Block 2 evidence.
     * Supporting instruments remain visible, but only the declared changed
     * surface receives causal credit from a one-gene experiment.
     */
    public function settleResearchPair(?LabLearningLanePair $pair, bool $pendingOnly = false): int
    {
        if (! $pair) {
            return 0;
        }
        $this->settleControlReferences($pair);
        $pair->loadMissing(
            'candidateAgent.modelVersion',
            'controlAgent.modelVersion',
            'candidateResponseMap',
            'controlResponseMap',
        );
        $agent = $pair->candidateAgent;
        if (! $agent) {
            return 0;
        }
        $runId = (string) $pair->candidate_evidence_run_id;
        $rows = InstrumentInvocationLedger::query()
            ->where('lab_agent_id', $agent->id)
            ->whereNull('paper_signal_id')
            ->when($pendingOnly, fn ($query) => $query->whereNull('settled_at')->where('verdict', 'awaiting_paired_control'))
            ->get()
            ->filter(fn (InstrumentInvocationLedger $row): bool => (string) data_get($row->metadata, 'evidence_run_id') === $runId);
        if ($rows->isEmpty()) {
            return 0;
        }
        $assignment = (array) data_get($agent->modelVersion?->metadata, 'instrument_research_assignment', []);
        $ordinaryReservationValid = $this->ordinaryReservationMatchesPair($assignment, $pair);
        $reservationValid = $ordinaryReservationValid
            || $this->causalTripletReservationMatchesPair($assignment, $pair);
        $assignmentHash = (string) data_get($assignment, 'assignment_hash', '');
        $attestedAssignmentValid = $assignmentHash !== '' && $rows->every(
            fn (InstrumentInvocationLedger $row): bool => hash_equals(
                $assignmentHash,
                (string) data_get($row->metadata, 'assignment_hash', ''),
            ),
        );
        if (! $pair->isVerifiedControlPair() || ! $reservationValid || ! $attestedAssignmentValid) {
            $rows->each(function (InstrumentInvocationLedger $row) use ($pair, $reservationValid, $attestedAssignmentValid): void {
                $metadata = (array) $row->metadata;
                $metadata['paired_control_rejection'] = [
                    'pair_id' => (int) $pair->id,
                    'pair_key' => (string) $pair->pair_key,
                    'pair_integrity_status' => (string) $pair->pair_integrity_status,
                    'reservation_valid' => $reservationValid,
                    'attested_assignment_valid' => $attestedAssignmentValid,
                    'promotion_evidence' => false,
                ];
                $row->update([
                    'verdict' => 'not_sufficiently_tested',
                    'metadata' => $metadata,
                    'settled_at' => now(),
                ]);
            });

            return 0;
        }

        $candidate = (array) $pair->candidate_metrics;
        $control = (array) $pair->control_metrics;
        $contextualOutcomes = $this->pairedContextOutcomes($pair, $candidate, $control);
        // Aggregate replay improvement cannot teach when or where an
        // instrument helped. Without a powered same-context candidate/control
        // slice, keep the result as a replay outcome and award no instrument
        // posterior or inheritance credit.
        $outcomes = $contextualOutcomes;
        $settled = 0;
        foreach ($rows as $row) {
            if (data_get($row->metadata, 'declaration.causal_candidate') !== true) {
                $row->update(['verdict' => 'support_consumed', 'settled_at' => now()]);

                continue;
            }
            $activatedOutcomes = $this->activatedOutcomes($row, $outcomes);
            if ($activatedOutcomes === []) {
                $metadata = (array) $row->metadata;
                $metadata['paired_control_activation_rejection'] = [
                    'pair_id' => (int) $pair->id,
                    'reason_code' => 'INSTRUMENT_NOT_ACTIVATED_IN_MATCHED_CONTEXT',
                    'promotion_evidence' => false,
                ];
                $row->update([
                    'verdict' => 'not_activated_in_paired_context',
                    'metadata' => $metadata,
                    'settled_at' => now(),
                ]);

                continue;
            }
            foreach ($activatedOutcomes as $outcome) {
                $this->operatingSystem->recordEvidence(
                    (string) $row->instrument_key,
                    strtoupper((string) $row->symbol),
                    // M15 is the organism's decision layer. H1/H4 are closed
                    // context coordinates inside the same XAUUSD organism.
                    'M15',
                    (array) $outcome['context'],
                    $this->posteriorOutcome(
                        $pair,
                        (array) $outcome,
                        'lab-pair:'.$pair->id.':instrument:'.$row->instrument_key.':'.$outcome['suffix'],
                    ),
                );
            }
            $activatedDelta = $this->summarizeActivatedOutcomes($activatedOutcomes);
            $activatedVerdict = $activatedDelta['net_profit_percent'] > 0
                && $activatedDelta['profit_factor'] >= 0
                && $activatedDelta['max_drawdown_percent'] <= 0
                    ? 'helped'
                    : ($activatedDelta['net_profit_percent'] < 0 && $activatedDelta['profit_factor'] <= 0 ? 'harmed' : 'neutral');
            $metadata = (array) $row->metadata;
            $metadata['paired_control'] = [
                'pair_id' => (int) $pair->id,
                'pair_key' => (string) $pair->pair_key,
                'same_generation' => true,
                'same_data_hash' => true,
                'same_execution_hash' => true,
                'contextual_settlements' => count($activatedOutcomes),
                'activated_context_keys' => array_values(array_map(
                    fn (array $outcome): string => (string) ($outcome['context_key'] ?? 'aggregate'),
                    $activatedOutcomes,
                )),
                'context_source' => 'decision_time_trade_ledger',
                'promotion_evidence' => false,
            ];
            $row->update([
                'verdict' => $activatedVerdict,
                'causal_contribution' => $activatedDelta['net_profit_percent'],
                'control_delta' => $activatedDelta,
                'metadata' => $metadata,
                'settled_at' => now(),
            ]);
            $settled++;
        }

        // Learn the value of the exact observed composition as well as the
        // isolated changed instrument. This is deliberately *not* labelled
        // synergy: interaction authority requires a separate factorial A/B/
        // AB/control design, while this posterior answers whether this exact
        // bundle was useful in this context.
        $playbookKey = (string) data_get($assignment, 'playbook_key', '');
        $selectedKeys = array_values(array_unique(array_map('strval', (array) data_get($assignment, 'selected_keys', []))));
        $activatedKeys = $rows->pluck('instrument_key')->map(fn ($key): string => (string) $key)->unique()->values()->all();
        sort($selectedKeys);
        sort($activatedKeys);
        $bundleFullyActivated = $selectedKeys !== [] && $selectedKeys === $activatedKeys
            && $rows->every(fn (InstrumentInvocationLedger $row): bool => data_get($row->metadata, 'bundle_fully_activated') === true);
        $phaseScopedBundle = $rows->contains(
            fn (InstrumentInvocationLedger $row): bool => $this->requiresVenuePhase($row)
        );
        $bundleOutcomes = $this->bundleActivatedOutcomes(
            $rows->first(), $outcomes, $bundleFullyActivated, $phaseScopedBundle
        );
        if ($settled > 0 && $playbookKey !== '' && $bundleOutcomes !== []) {
            foreach ($bundleOutcomes as $outcome) {
                $this->operatingSystem->recordPlaybookEvidence(
                    $playbookKey,
                    strtoupper((string) $agent->symbol),
                    'M15',
                    (array) $outcome['context'],
                    [
                        ...$this->posteriorOutcome(
                            $pair,
                            (array) $outcome,
                            'lab-pair:'.$pair->id.':bundle:'.$playbookKey.':'.$outcome['suffix'],
                        ),
                        'source_type' => str_replace('paired_control', 'exact_bundle', (string) $outcome['source_type']),
                        'interaction_identified' => false,
                    ],
                );
            }
        }

        return $settled;
    }

    /**
     * A constructor pair key seals candidate/control identity. The learning
     * lane pair key identifies a later screening observation and is expected
     * to differ, so never compare those two unrelated hashes.
     */
    private function ordinaryReservationMatchesPair(array $assignment, LabLearningLanePair $pair): bool
    {
        $reservation = (array) data_get($assignment, 'pair_reservation', []);
        $constructorKey = (string) data_get($reservation, 'pair_key', '');
        $candidate = $pair->candidateAgent;
        $control = $pair->controlAgent;
        if ((string) data_get($reservation, 'protocol') !== 'instrument_exact_pair_reservation_v1'
            || (string) data_get($reservation, 'status') !== 'reserved'
            || $constructorKey === ''
            || ! $candidate || ! $control
            || (int) data_get($assignment, 'lab_agent_id') !== (int) $candidate->id
            || (int) data_get($assignment, 'model_version_id') !== (int) $candidate->model_version_id
            || (int) data_get($assignment, 'lab_generation_id') !== (int) $pair->lab_generation_id
            || (int) $candidate->lab_generation_id !== (int) $pair->lab_generation_id
            || (int) $control->lab_generation_id !== (int) $pair->lab_generation_id
            || (int) data_get($reservation, 'candidate_agent_id') !== (int) $candidate->id
            || (int) data_get($reservation, 'control_agent_id') !== (int) $control->id
            || data_get($reservation, 'same_generation') !== true
            || data_get($reservation, 'single_intervention') !== true
            || data_get($reservation, 'exact_parameter_baseline') !== true
            || (int) $pair->controlResponseMap?->lab_agent_id !== (int) $control->id
            || (int) $pair->controlResponseMap?->model_version_id !== (int) $control->model_version_id
            || (string) $pair->controlResponseMap?->evidence_run_id !== (string) $pair->control_evidence_run_id
            || (int) $pair->candidateResponseMap?->lab_agent_id !== (int) $candidate->id
            || (int) $pair->candidateResponseMap?->model_version_id !== (int) $candidate->model_version_id
            || (string) $pair->candidateResponseMap?->evidence_run_id !== (string) $pair->candidate_evidence_run_id) {
            return false;
        }

        $candidateContract = (array) data_get($candidate->modelVersion?->metadata, 'control_pair_contract', []);
        $controlContract = (array) data_get($control->modelVersion?->metadata, 'control_pair_contract', []);

        return (string) data_get($candidateContract, 'protocol') === 'exact_frozen_control_pair_v2'
            && (string) data_get($controlContract, 'protocol') === 'exact_frozen_control_pair_v2'
            && (string) data_get($candidateContract, 'role') === 'candidate'
            && (string) data_get($controlContract, 'role') === 'control'
            && hash_equals($constructorKey, (string) data_get($candidateContract, 'pair_key', ''))
            && hash_equals($constructorKey, (string) data_get($controlContract, 'pair_key', ''))
            && ((int) data_get($candidateContract, 'control_agent_id', 0) === 0
                || (int) data_get($candidateContract, 'control_agent_id') === (int) $control->id);
    }

    private function causalTripletReservationMatchesPair(array $assignment, LabLearningLanePair $pair): bool
    {
        $reservation = (array) data_get($assignment, 'pair_reservation', []);
        if ((string) data_get($reservation, 'protocol') !== 'causal_triplet_instrument_reservation_v1'
            || (string) data_get($reservation, 'status') !== 'reserved'
            || (int) data_get($reservation, 'candidate_agent_id') !== (int) $pair->candidate_agent_id
            || (int) data_get($reservation, 'control_agent_id') !== (int) $pair->control_agent_id) {
            return false;
        }
        $experiment = AgentLearningCausalExperiment::query()->find(
            (int) data_get($reservation, 'causal_experiment_id', 0)
        );
        if (! $experiment
            || (int) $experiment->lab_generation_id !== (int) $pair->lab_generation_id
            || (int) $experiment->control_agent_id !== (int) $pair->control_agent_id
            || ! hash_equals(
                (string) $experiment->experiment_key,
                (string) data_get($reservation, 'experiment_key', ''),
            )) {
            return false;
        }

        return in_array((int) $pair->candidate_agent_id, array_values(array_filter([
            (int) $experiment->guided_agent_id,
            (int) $experiment->blinded_agent_id,
        ])), true);
    }

    /**
     * Preserve the tested niche when the pair has one. Aggregate replay is
     * deliberately labelled mixed; it cannot masquerade as London, Asia or
     * another local posterior without stratified outcome evidence.
     *
     * @return array<string,mixed>
     */
    private function researchContext(LabLearningLanePair $pair, array $candidate): array
    {
        $state = (array) data_get($pair->failure_signature, 'state', []);
        $regime = data_get($state, 'regime', data_get($candidate, 'market_regime'));
        $session = data_get($state, 'session', data_get($candidate, 'session'));
        $volatility = data_get($state, 'volatility', data_get($candidate, 'volatility_regime'));
        $spreadRatio = data_get($candidate, 'spread_atr_ratio', data_get($candidate, 'cost_model.spread_atr_ratio'));
        $liquidity = data_get($state, 'spread_liquidity_state', data_get($state, 'liquidity_state'));
        $transition = data_get($state, 'transition_state');
        $specific = filled($regime) || filled($session) || filled($volatility) || filled($liquidity);
        if (! $specific) {
            return [
                'regime' => 'historical_mixed', 'm15_regime' => 'historical_mixed',
                'session' => 'stratified_replay', 'volatility' => 'mixed',
                'spread_atr_ratio' => null, 'liquidity' => 'unknown', 'transition' => false,
                'strategy_family' => (string) $pair->strategy_family,
            ];
        }

        return array_filter([
            'regime' => $regime,
            'm15_regime' => data_get($state, 'm15_regime', $regime),
            'session' => $session,
            'volatility' => $volatility,
            'spread_atr_ratio' => is_numeric($spreadRatio) ? (float) $spreadRatio : null,
            'liquidity' => $liquidity,
            'transition' => is_bool($transition) ? $transition : str_contains(strtolower((string) $transition), 'transition'),
            'strategy_family' => (string) $pair->strategy_family,
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    /** @return list<array<string,mixed>> */
    private function pairedContextOutcomes(LabLearningLanePair $pair, array $candidate, array $control): array
    {
        $candidateTrace = (array) data_get($candidate, 'instrument_research_trace', []);
        $controlTrace = (array) data_get($control, 'instrument_research_trace', []);
        if ((string) data_get($candidateTrace, 'context_source') !== 'decision_time_trade_ledger'
            || (string) data_get($controlTrace, 'context_source') !== 'decision_time_trade_ledger') {
            return [];
        }
        $candidateExact = data_get($candidateTrace, 'context_slice_protocol') === 'venue_phase_v1';
        $controlExact = data_get($controlTrace, 'context_slice_protocol') === 'venue_phase_v1';
        // Never compare an exact venue-phase arm with a legacy session-only
        // arm, or silently fall back to the broader slice of a new replay.
        if ($candidateExact !== $controlExact) {
            return [];
        }
        $sliceKey = $candidateExact ? 'exact_context_slices' : 'context_slices';
        $controlSlices = collect((array) data_get($controlTrace, $sliceKey, []))->keyBy('context_key');
        $windowKey = (string) ($pair->independent_window_key ?? '');
        $outcomes = [];
        foreach ((array) data_get($candidateTrace, $sliceKey, []) as $candidateSlice) {
            if (! is_array($candidateSlice) || data_get($candidateSlice, 'powered') !== true) {
                continue;
            }
            $key = (string) data_get($candidateSlice, 'context_key', '');
            $controlSlice = $controlSlices->get($key);
            if (! is_array($controlSlice) || data_get($controlSlice, 'powered') !== true) {
                continue;
            }
            if ($candidateExact) {
                $parts = explode('|', $key);
                $axes = ['regime', 'volatility', 'session', 'venue_phase', 'direction'];
                if (count($parts) !== count($axes)
                    || collect($axes)->contains(fn (string $axis, int $index): bool =>
                        $parts[$index] === ''
                        || (string) data_get($candidateSlice, 'context.'.$axis, '') !== $parts[$index]
                        || (string) data_get($controlSlice, 'context.'.$axis, '') !== $parts[$index]
                    )) {
                    continue;
                }
            }
            $candidateMetrics = (array) data_get($candidateSlice, 'metrics', []);
            $controlMetrics = (array) data_get($controlSlice, 'metrics', []);
            $candidateTrades = (int) data_get($candidateMetrics, 'trades', 0);
            $controlTrades = (int) data_get($controlMetrics, 'trades', 0);
            if (min($candidateTrades, $controlTrades) < 3) {
                continue;
            }
            $delta = [
                'net_profit_percent' => round((float) data_get($candidateMetrics, 'net_profit_percent', 0) - (float) data_get($controlMetrics, 'net_profit_percent', 0), 6),
                'profit_factor' => round((float) data_get($candidateMetrics, 'net_pf', 0) - (float) data_get($controlMetrics, 'net_pf', 0), 6),
                'max_drawdown_percent' => round((float) data_get($candidateMetrics, 'max_drawdown_percent', 0) - (float) data_get($controlMetrics, 'max_drawdown_percent', 0), 6),
                'execution_cost_percent' => round((float) data_get($candidateMetrics, 'execution_cost_percent', 0) - (float) data_get($controlMetrics, 'execution_cost_percent', 0), 6),
                'total_trades' => $candidateTrades - $controlTrades,
            ];
            $verdict = $delta['net_profit_percent'] > 0 && $delta['profit_factor'] >= 0 && $delta['max_drawdown_percent'] <= 0
                ? 'helped'
                : ($delta['net_profit_percent'] < 0 && $delta['profit_factor'] <= 0 ? 'harmed' : 'neutral');
            $context = (array) data_get($candidateSlice, 'context', []);
            $context['strategy_family'] = (string) $pair->strategy_family;
            $outcomes[] = [
                'context_key' => $key,
                'context_granularity' => $candidateExact ? 'venue_phase_v1' : 'legacy_session_v1',
                'context' => $context,
                'candidate' => [
                    'net_profit_percent' => (float) data_get($candidateMetrics, 'net_profit_percent', 0),
                    'profit_factor' => (float) data_get($candidateMetrics, 'net_pf', 0),
                    'max_drawdown_percent' => (float) data_get($candidateMetrics, 'max_drawdown_percent', 0),
                    'execution_cost_percent' => (float) data_get($candidateMetrics, 'execution_cost_percent', 0),
                    'total_trades' => $candidateTrades,
                ],
                'control' => [
                    'net_profit_percent' => (float) data_get($controlMetrics, 'net_profit_percent', 0),
                    'profit_factor' => (float) data_get($controlMetrics, 'net_pf', 0),
                    'max_drawdown_percent' => (float) data_get($controlMetrics, 'max_drawdown_percent', 0),
                    'execution_cost_percent' => (float) data_get($controlMetrics, 'execution_cost_percent', 0),
                    'total_trades' => $controlTrades,
                ],
                'delta' => $delta,
                'verdict' => $verdict,
                'suffix' => substr(hash('sha256', $key), 0, 16),
                'source_type' => 'lab_verified_paired_control_context_slice',
                'independent_window_key' => $windowKey,
            ];
        }

        return $outcomes;
    }

    /** @return list<array<string,mixed>> */
    private function activatedOutcomes(InstrumentInvocationLedger $row, array $outcomes): array
    {
        $exact = data_get($outcomes[0] ?? [], 'context_granularity') === 'venue_phase_v1';
        if (! $exact && $this->requiresVenuePhase($row)) {
            return [];
        }
        $active = array_flip(array_map('strval', (array) data_get(
            $row->metadata,
            $exact ? 'runtime_trace.activated_exact_context_keys' : 'runtime_trace.activated_context_keys',
            [],
        )));

        return collect($outcomes)
            ->filter(fn (array $outcome): bool => isset($active[(string) ($outcome['context_key'] ?? '')]))
            ->values()
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function bundleActivatedOutcomes(
        ?InstrumentInvocationLedger $row,
        array $outcomes,
        bool $fullyActivated,
        bool $phaseScopedBundle = false,
    ): array
    {
        if (! $row || ! $fullyActivated) {
            return [];
        }
        $exact = data_get($outcomes[0] ?? [], 'context_granularity') === 'venue_phase_v1';
        if (! $exact && $phaseScopedBundle) {
            return [];
        }
        $active = array_flip(array_map('strval', (array) data_get(
            $row->metadata,
            $exact ? 'bundle_activation_exact_context_keys' : 'bundle_activation_context_keys',
            [],
        )));

        return collect($outcomes)
            ->filter(fn (array $outcome): bool => isset($active[(string) ($outcome['context_key'] ?? '')]))
            ->values()
            ->all();
    }

    private function requiresVenuePhase(InstrumentInvocationLedger $row): bool
    {
        return filled(data_get(
            $row->metadata, 'declaration.activation_contract.context.declared_context.venue_phase'
        ));
    }

    /** @return array<string,mixed> */
    private function summarizeActivatedOutcomes(array $outcomes): array
    {
        $weight = fn (array $outcome): int => max(1, min(
            (int) data_get($outcome, 'candidate.total_trades', 0),
            (int) data_get($outcome, 'control.total_trades', 0),
        ));
        $totalWeight = max(1, collect($outcomes)->sum($weight));
        $weighted = fn (string $metric): float => (float) (collect($outcomes)->sum(
            fn (array $outcome): float => (float) data_get($outcome, 'delta.'.$metric, 0) * $weight($outcome),
        ) / $totalWeight);

        return [
            'net_profit_percent' => round((float) collect($outcomes)->sum(fn (array $outcome): float => (float) data_get($outcome, 'delta.net_profit_percent', 0)), 6),
            'profit_factor' => round($weighted('profit_factor'), 6),
            'max_drawdown_percent' => round((float) collect($outcomes)->max(fn (array $outcome): float => (float) data_get($outcome, 'delta.max_drawdown_percent', 0)), 6),
            'execution_cost_percent' => round((float) collect($outcomes)->sum(fn (array $outcome): float => (float) data_get($outcome, 'delta.execution_cost_percent', 0)), 6),
            'total_trades' => (int) collect($outcomes)->sum(fn (array $outcome): int => (int) data_get($outcome, 'delta.total_trades', 0)),
            'context_keys' => array_values(array_map(fn (array $outcome): string => (string) ($outcome['context_key'] ?? ''), $outcomes)),
            'scope' => 'activated_contexts_only',
        ];
    }

    /** @return array<string,mixed> */
    private function posteriorOutcome(LabLearningLanePair $pair, array $outcome, string $evidenceKey): array
    {
        $candidate = (array) $outcome['candidate'];
        $control = (array) $outcome['control'];
        $delta = (array) $outcome['delta'];
        $windowReceipt = (array) data_get($pair->metadata, 'instrument_research_window_receipt', []);
        if ((string) ($windowReceipt['window_key'] ?? '') !== (string) ($pair->independent_window_key ?? '')) {
            $windowReceipt = [];
        }
        $pair->loadMissing(['candidateAgent.modelVersion', 'controlAgent.modelVersion']);
        $baseline = (array) $pair->controlAgent?->modelVersion?->parameters;
        $parameters = (array) $pair->candidateAgent?->modelVersion?->parameters;
        $parameterDiff = [];
        $hashes = app(ResearchPaperEpochContractService::class);
        foreach (array_unique([...array_keys($baseline), ...array_keys($parameters)]) as $gene) {
            if (! array_key_exists($gene, $baseline) || ! array_key_exists($gene, $parameters)
                || $hashes->parameterHash(['value' => $baseline[$gene]]) !== $hashes->parameterHash(['value' => $parameters[$gene]])) {
                $parameterDiff[$gene] = ['old' => $baseline[$gene] ?? null, 'new' => $parameters[$gene] ?? null];
            }
        }
        $candidateRun = LabEvaluationRun::query()->where('run_id', (string) $pair->candidate_evidence_run_id)->first();
        $controlRun = LabEvaluationRun::query()->where('run_id', (string) $pair->control_evidence_run_id)->first();
        $evaluatorHash = $candidateRun !== null && $controlRun !== null
            && (string) $candidateRun->code_hash === (string) $controlRun->code_hash
                ? (string) $candidateRun->code_hash : '';
        $validation = app(InstrumentValidationEvidenceService::class);
        $gene = count($parameterDiff) === 1 ? (string) array_key_first($parameterDiff) : '';
        $tested = $gene !== '' ? $validation->sealDelta($gene, $parameterDiff[$gene]['old'], $parameterDiff[$gene]['new'],
            $hashes->parameterHash($baseline), $evaluatorHash,
            $this->operatingSystem->fingerprint($pair->symbol, 'M15', (array) $outcome['context'])) : null;
        $sourceReceipt = $validation->sealSource([
            'protocol' => InstrumentValidationEvidenceService::SOURCE_PROTOCOL,
            'pair_key' => (string) $pair->pair_key,
            'candidate_agent_id' => (int) $pair->candidate_agent_id, 'control_agent_id' => (int) $pair->control_agent_id,
            'candidate_model_version_id' => (int) $pair->candidateAgent?->model_version_id,
            'control_model_version_id' => (int) $pair->controlAgent?->model_version_id,
            'candidate_response_map_id' => (int) $pair->candidate_response_map_id, 'control_response_map_id' => (int) $pair->control_response_map_id,
            'candidate_evidence_run_id' => (int) $candidateRun?->id, 'control_evidence_run_id' => (int) $controlRun?->id,
            'candidate_run_key' => (string) $pair->candidate_evidence_run_id, 'control_run_key' => (string) $pair->control_evidence_run_id,
            'candidate_request_hash' => (string) $candidateRun?->request_hash, 'control_request_hash' => (string) $controlRun?->request_hash,
            'candidate_response_hash' => (string) $candidateRun?->response_hash, 'control_response_hash' => (string) $controlRun?->response_hash,
            'candidate_parameter_hash' => $hashes->parameterHash($parameters), 'control_parameter_hash' => $hashes->parameterHash($baseline),
            'evaluator_hash' => $evaluatorHash, 'data_hash' => (string) $pair->candidate_data_hash,
            'execution_hash' => (string) $pair->candidate_execution_hash,
        ]);

        return [
            'evidence_key' => $evidenceKey,
            'source_type' => (string) $outcome['source_type'],
            'source_key' => (string) $pair->pair_key,
            'independent_window_key' => (string) ($outcome['independent_window_key'] ?? ''),
            'instrument_research_window_receipt' => $windowReceipt,
            'tested_intervention' => $tested ?? [],
            'source_receipt' => $sourceReceipt,
            'outcome_state' => (string) $outcome['verdict'],
            'metrics' => [
                'net_edge' => (float) data_get($delta, 'net_profit_percent', 0) / 100,
                'profit_factor' => (float) data_get($candidate, 'profit_factor', 0),
                'cost_penalty' => max(0, (float) data_get($delta, 'execution_cost_percent', 0)) / 100,
                'drawdown_penalty' => max(0, (float) data_get($delta, 'max_drawdown_percent', 0)),
                'trade_frequency' => (float) data_get($candidate, 'total_trades', 0),
                'non_target_regression' => $this->hasNonTargetRegression($pair) ? 1 : 0,
                'incremental_lift' => 0,
            ],
            'control_metrics' => $control,
            'control_contract' => [
                'paired_isolated' => true,
                'same_generation' => true,
                'same_data_hash' => true,
                'same_execution_hash' => true,
                'data_hash' => (string) $pair->candidate_data_hash,
            ],
        ];
    }

    private function hasNonTargetRegression(LabLearningLanePair $pair): bool
    {
        $regression = (array) $pair->non_target_regression;

        return data_get($regression, 'passed') === false
            || data_get($regression, 'regression_detected') === true
            || (float) data_get($regression, 'penalty', 0) > 0;
    }
}
