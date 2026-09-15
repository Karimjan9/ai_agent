<?php

namespace App\Services;

use App\Models\AgentLearningCausalExperiment;
use App\Models\InstrumentInvocationLedger;
use App\Models\LabAgent;
use App\Models\LabLearningLanePair;
use App\Models\PaperOrder;
use App\Models\PaperSignal;
use App\Models\PaperSignalOutcome;
use App\Models\TradingInstrument;

class InstrumentInvocationLedgerService
{
    public const PROTOCOL = 'instrument_invocation_ledger_v1';

    public function __construct(private TradingInstrumentOperatingSystemService $operatingSystem) {}

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
            || (string) data_get($trace, 'status') !== 'consumed'
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
            if (! is_array($runtime)
                || ($runtime['status'] ?? null) !== 'consumed'
                || data_get($runtime, 'decision_path_activated') !== true
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
                'used_in_decision' => true,
                // This column denotes paper/live execution. The replay was
                // genuinely executed but remains research-only metadata.
                'used_in_execution' => false,
                'verdict' => 'awaiting_paired_control',
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
                    'pair_reservation' => data_get($assignment, 'pair_reservation'),
                    'declaration' => $declaration,
                    'runtime_trace' => $runtime,
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
    public function settleResearchPair(?LabLearningLanePair $pair): int
    {
        if (! $pair) {
            return 0;
        }
        $pair->loadMissing('candidateAgent.modelVersion', 'controlResponseMap');
        $agent = $pair->candidateAgent;
        if (! $agent) {
            return 0;
        }
        $runId = (string) $pair->candidate_evidence_run_id;
        $rows = InstrumentInvocationLedger::query()
            ->where('lab_agent_id', $agent->id)
            ->whereNull('paper_signal_id')
            ->get()
            ->filter(fn (InstrumentInvocationLedger $row): bool => (string) data_get($row->metadata, 'evidence_run_id') === $runId);
        if ($rows->isEmpty()) {
            return 0;
        }
        $assignment = (array) data_get($agent->modelVersion?->metadata, 'instrument_research_assignment', []);
        $ordinaryReservationValid = (string) data_get($assignment, 'pair_reservation.status') === 'reserved'
            && filled(data_get($assignment, 'pair_reservation.pair_key'))
            && hash_equals(
                (string) $pair->pair_key,
                (string) data_get($assignment, 'pair_reservation.pair_key'),
            )
            && (int) data_get($assignment, 'pair_reservation.control_agent_id') === (int) $pair->control_agent_id;
        $reservationValid = $ordinaryReservationValid
            || $this->causalTripletReservationMatchesPair($assignment, $pair);
        if (! $pair->isVerifiedControlPair() || ! $reservationValid) {
            $rows->each(function (InstrumentInvocationLedger $row) use ($pair, $reservationValid): void {
                $metadata = (array) $row->metadata;
                $metadata['paired_control_rejection'] = [
                    'pair_id' => (int) $pair->id,
                    'pair_key' => (string) $pair->pair_key,
                    'pair_integrity_status' => (string) $pair->pair_integrity_status,
                    'reservation_valid' => $reservationValid,
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
        $bundleOutcomes = $this->bundleActivatedOutcomes($rows->first(), $outcomes, $bundleFullyActivated);
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
        $controlSlices = collect((array) data_get($controlTrace, 'context_slices', []))->keyBy('context_key');
        $windowKey = (string) ($pair->independent_window_key ?? '');
        $outcomes = [];
        foreach ((array) data_get($candidateTrace, 'context_slices', []) as $candidateSlice) {
            if (! is_array($candidateSlice) || data_get($candidateSlice, 'powered') !== true) {
                continue;
            }
            $key = (string) data_get($candidateSlice, 'context_key', '');
            $controlSlice = $controlSlices->get($key);
            if (! is_array($controlSlice) || data_get($controlSlice, 'powered') !== true) {
                continue;
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
        $active = array_flip(array_map('strval', (array) data_get($row->metadata, 'runtime_trace.activated_context_keys', [])));

        return collect($outcomes)
            ->filter(fn (array $outcome): bool => isset($active[(string) ($outcome['context_key'] ?? '')]))
            ->values()
            ->all();
    }

    /** @return list<array<string,mixed>> */
    private function bundleActivatedOutcomes(?InstrumentInvocationLedger $row, array $outcomes, bool $fullyActivated): array
    {
        if (! $row || ! $fullyActivated) {
            return [];
        }
        $active = array_flip(array_map('strval', (array) data_get($row->metadata, 'bundle_activation_context_keys', [])));

        return collect($outcomes)
            ->filter(fn (array $outcome): bool => isset($active[(string) ($outcome['context_key'] ?? '')]))
            ->values()
            ->all();
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

        return [
            'evidence_key' => $evidenceKey,
            'source_type' => (string) $outcome['source_type'],
            'source_key' => (string) $pair->pair_key,
            'independent_window_key' => (string) ($outcome['independent_window_key'] ?? ''),
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
