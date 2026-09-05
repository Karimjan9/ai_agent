<?php

namespace App\Services;

use App\Exceptions\ReplayLaneBusyException;
use App\Models\MtfPlaybookFrozenControlRun;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Executes every catalogue hypothesis against one unchanged M5 control.
 *
 * The control and candidate receive byte-identical immutable data paths,
 * context manifest, cost map and next-open execution contract. This is an
 * evidence-only lane: a completed row cannot authorize paper, champion or
 * promotion state.
 */
class MtfPlaybookFrozenControlService
{
    public const PROTOCOL = 'mtf_playbook_frozen_control_v2_multifidelity';

    /**
     * Semantic seal for payload/comparison behavior. Bump this value whenever
     * replay inputs, power rules or verdict semantics change. Keeping it
     * independent of PHP whitespace prevents formatting-only deployments from
     * invalidating immutable market evidence and wasting replay compute.
     */
    public const RUNNER_CONTRACT_HASH = '06cb9b9b8fa408c2c6c51f146e88f7c8a68c6fadf57c34eb9dfc79ebbbb7b88b';

    private ?string $runtimeHash = null;

    private ?string $runnerHash = null;

    public function __construct(
        private StrategyResearchCatalogueService $catalogue,
        private MultiTimeframeSnapshotService $snapshots,
        private StrategyParameterSchemaService $schemas,
        private ExecutionContractService $executionContracts,
        private LabQueueJobInspector $queues,
    ) {}

    /** @return array{python_runtime_hash:string,runner_contract_hash:string} */
    public function currentIdentity(): array
    {
        return [
            'python_runtime_hash' => $this->runtimeHash(),
            'runner_contract_hash' => $this->runnerHash(),
        ];
    }

    /** @return array<string,mixed> */
    public function run(
        string $symbol,
        string $modelId,
        ?string $relatedSymbol = null,
        array $candidateOverrides = [],
        array $trialContext = [],
    ): array {
        $model = $this->catalogue->model($modelId);
        $symbol = $this->symbol($symbol);
        $required = array_values((array) ($model['required_streams'] ?? []));
        $confirmationModels = [
            'confirmation_trend_continuation' => 'trend_continuation',
            'confirmation_breakout_retest' => 'breakout_retest',
            'confirmation_false_break_reversal' => 'false_break_reversal',
            'confirmation_range_sweep' => 'range_sweep',
            'confirmation_htf_reversal' => 'htf_reversal',
        ];
        $candidateStrategy = match (true) {
            $modelId === 'liquidity_trap_mtf' => 'liquidity_trap_mtf_v1',
            array_key_exists($modelId, $confirmationModels) => 'confirmation_entry_mtf_v1',
            default => 'mtf_research_playbook_v1',
        };
        $this->assertBoundedCandidateOverrides($candidateStrategy, $candidateOverrides);
        $variant = $this->candidateVariant($candidateOverrides, $trialContext);
        $runtimeHash = $this->runtimeHash();
        $runnerHash = $this->runnerHash();
        $requiresRelatedMarket = in_array('related_market', array_map('strtolower', $required), true);
        $frozenRelatedSymbol = $requiresRelatedMarket && $relatedSymbol !== null && trim($relatedSymbol) !== ''
            ? $this->symbol($relatedSymbol)
            : null;
        $sourceRun = null;
        if ((string) $variant['id'] === 'frozen_default') {
            $bundle = $this->snapshots->forResearchPlaybookReplay($symbol, $required, $frozenRelatedSymbol);
        } elseif ((string) data_get($variant, 'variant_class') === 'evidence_budget_expansion') {
            $sourceRun = $this->validSourceRun(
                (int) $variant['source_run_id'], $modelId, $symbol, $frozenRelatedSymbol,
                $runtimeHash, $runnerHash,
            );
            $sourceBudget = (int) data_get(
                $sourceRun->dataset_manifest,
                'bounded_entry_rows',
                MultiTimeframeSnapshotService::RESEARCH_MAX_M5_ROWS,
            );
            $targetBudget = (int) data_get($variant, 'evidence_budget_rows', 0);
            if ($this->nextEvidenceBudget($sourceBudget) !== $targetBudget
                || ! $this->promisingUnderpowered((array) $sourceRun->comparison)) {
                throw new RuntimeException('MTF evidence expansion source is not a promising adjacent budget tier.');
            }
            $bundle = $this->snapshots->forResearchPlaybookReplay(
                $symbol, $required, $frozenRelatedSymbol, $targetBudget,
            );
            $variant['old_value'] = $sourceBudget;
            $variant['source_data_hash'] = (string) $sourceRun->data_hash;
            $variant['same_source_data_hash'] = hash_equals((string) $sourceRun->data_hash, (string) $bundle['bundle_hash']);
            $variant['source_evidence_budget_rows'] = $sourceBudget;
        } else {
            $sourceRun = $this->validSourceRun(
                (int) $variant['source_run_id'], $modelId, $symbol, $frozenRelatedSymbol,
                $runtimeHash, $runnerHash,
            );
            if ((string) data_get($sourceRun->comparison, 'candidate_variant.id') !== 'frozen_default') {
                throw new RuntimeException('Bounded Confirmation repair source identity is invalid or stale.');
            }
            $bundle = $this->snapshots->restoreResearchPlaybookBundle((array) $sourceRun->dataset_manifest);
            if (! hash_equals((string) $sourceRun->data_hash, (string) $bundle['bundle_hash'])) {
                throw new RuntimeException('Bounded Confirmation repair source data hash mismatch.');
            }
            $variant['source_data_hash'] = (string) $sourceRun->data_hash;
            $variant['same_source_data_hash'] = true;
        }
        $execution = $this->executionContracts->for($symbol, 'M5');
        $controlParameters = $this->schemas->validate(
            'mtf_research_control_v1',
            $this->schemas->defaults('mtf_research_control_v1'),
        );
        $candidateDefaults = $this->schemas->defaults($candidateStrategy);
        if ($candidateStrategy === 'mtf_research_playbook_v1') {
            $candidateDefaults['research_model_id'] = $modelId;
        } elseif ($candidateStrategy === 'confirmation_entry_mtf_v1') {
            $candidateDefaults['entry_model'] = $confirmationModels[$modelId];
        }
        $candidateParameters = $this->schemas->validate(
            $candidateStrategy,
            [...$candidateDefaults, ...$candidateOverrides],
        );
        $controlHash = $this->hash($this->schemas->canonicalizeForIdentity('mtf_research_control_v1', $controlParameters));
        $candidateHash = $this->hash($this->schemas->canonicalizeForIdentity($candidateStrategy, $candidateParameters));
        $sourceControl = null;
        if ($sourceRun !== null) {
            $priorControl = (array) $sourceRun->control_result;
            if ($priorControl === []
                || ! hash_equals((string) $sourceRun->execution_hash, (string) $execution['execution_hash'])
                || ! hash_equals((string) $sourceRun->control_parameter_hash, $controlHash)) {
                throw new RuntimeException('MTF source control identity is invalid or stale.');
            }
            $variant['source_control_result_hash'] = $this->hash($priorControl);
            if ((string) data_get($variant, 'variant_class') === 'parameter_repair') {
                $sourceControl = $priorControl;
                $variant['control_source_run_id'] = (int) $sourceRun->id;
                $variant['control_result_reused'] = true;
            } else {
                $variant['control_source_run_id'] = null;
                $variant['control_result_reused'] = false;
            }
        }
        $runKey = $this->hash([
            'protocol' => self::PROTOCOL,
            'model_id' => $modelId,
            'symbol' => $symbol,
            'related_symbol' => $frozenRelatedSymbol,
            'data_hash' => $bundle['bundle_hash'],
            'execution_hash' => $execution['execution_hash'],
            'control_parameter_hash' => $controlHash,
            'candidate_parameter_hash' => $candidateHash,
            'candidate_variant' => $variant,
            // Strategy code is an execution input. Without these hashes a
            // completed DB row could be reused after the Python model or the
            // Laravel comparison contract changed.
            'python_runtime_hash' => $runtimeHash,
            'runner_contract_hash' => $runnerHash,
        ]);
        $existing = MtfPlaybookFrozenControlRun::query()->where('run_key', $runKey)->first();
        if ($existing && (string) $existing->status === 'completed') {
            return $this->result($existing, true);
        }
        // A transport error contains no market verdict and must be retryable.
        // Conversely, a fresh `started` row owns this immutable run key and
        // prevents a second operator/scheduler from launching the same pair.
        if ($existing && (string) $existing->status === 'started'
            && $existing->updated_at?->isAfter(now()->subMinutes(30))) {
            throw new ReplayLaneBusyException('The identical immutable MTF research run is already in progress.');
        }

        // If the control finished but the candidate lost the replay-lane
        // race, resume from that immutable control instead of spending the
        // same compute again. The run key already seals data, execution,
        // parameters and runtime identity; the embedded execution contract
        // is checked once more before reuse.
        $resumableControl = null;
        if ($existing && in_array((string) $existing->status, ['retry_deferred', 'technical_error'], true)) {
            $partial = (array) $existing->control_result;
            if ($partial !== [] && $this->executionContracts->matches(
                (array) data_get($partial, 'execution_contract', []),
                $symbol,
                'M5',
            )) {
                $resumableControl = $partial;
            }
        }

        $run = $existing ?: new MtfPlaybookFrozenControlRun(['run_key' => $runKey]);
        $run->fill([
            'protocol' => self::PROTOCOL,
            'research_model_id' => $modelId,
            'symbol' => $symbol,
            'entry_timeframe' => 'M5',
            'related_symbol' => $frozenRelatedSymbol,
            'data_hash' => $bundle['bundle_hash'],
            'execution_hash' => $execution['execution_hash'],
            'control_parameter_hash' => $controlHash,
            'candidate_parameter_hash' => $candidateHash,
            'status' => 'started',
            'required_streams' => $required,
            'dataset_manifest' => (array) $bundle['manifest'],
            'control_result' => $resumableControl,
            'candidate_result' => null,
            'comparison' => null,
            'reason_codes' => [],
            'completed_at' => null,
            'promotion_evidence' => false,
        ])->save();

        try {
            // A bounded repair is compared with the exact immutable control
            // already produced by its source run. Replaying that byte-identical
            // control wastes half the compute and can only add operational
            // variance; the source identity/data/execution/parameter hashes
            // are all revalidated above before this reuse is authorized.
            $control = $sourceControl ?? $resumableControl;
            if ($control === null) {
                $this->yieldToCanonicalEvolution('control');
                $control = $this->replay($this->payload(
                    $symbol, 'mtf_research_control_v1', $controlParameters, $bundle, $execution,
                ));
            }
            // Seal partial progress before the candidate request. A healthy
            // lane handoff between these two calls must not erase completed
            // control compute or turn it into apparent strategy evidence.
            $run->update(['control_result' => $control]);
            $this->yieldToCanonicalEvolution('candidate');
            $candidate = $this->replay($this->payload($symbol, $candidateStrategy, $candidateParameters, $bundle, $execution));
            $comparison = $this->comparison(
                $control, $candidate, $model, $bundle, $execution,
                $runtimeHash, $runnerHash, $candidateParameters, $variant,
            );
            $run->update([
                'status' => 'completed',
                'control_result' => $control,
                'candidate_result' => $candidate,
                'comparison' => $comparison,
                'reason_codes' => [],
                'completed_at' => now(),
                'promotion_evidence' => false,
            ]);
        } catch (ReplayLaneBusyException $exception) {
            $run->update([
                'status' => 'retry_deferred',
                'reason_codes' => ['REPLAY_LANE_BUSY_DEFERRED'],
                'comparison' => [
                    'protocol' => self::PROTOCOL,
                    'status' => 'retry_deferred',
                    'python_runtime_hash' => $runtimeHash,
                    'runner_contract_hash' => $runnerHash,
                    'candidate_variant' => $variant,
                    'control_result_preserved' => (array) $run->control_result !== [],
                    'message' => substr($exception->getMessage(), 0, 1000),
                    'quality_verdict' => 'withheld',
                    'promotion_evidence' => false,
                ],
                'completed_at' => null,
                'promotion_evidence' => false,
            ]);

            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            $run->update([
                'status' => 'technical_error',
                'reason_codes' => ['REPLAY_TECHNICAL_ERROR:'.$exception::class],
                'comparison' => [
                    'protocol' => self::PROTOCOL,
                    'status' => 'technical_error',
                    'python_runtime_hash' => $runtimeHash,
                    'runner_contract_hash' => $runnerHash,
                    'candidate_variant' => $variant,
                    'message' => substr($exception->getMessage(), 0, 1000),
                    'promotion_evidence' => false,
                ],
                'completed_at' => now(),
                'promotion_evidence' => false,
            ]);
        }

        return $this->result($run->fresh(), false);
    }

    /** @return array<int,array<string,mixed>> */
    public function runAll(string $symbol, ?string $relatedSymbol = null): array
    {
        $rows = [];
        foreach ((array) data_get($this->catalogue->catalogue(), 'models', []) as $model) {
            $id = (string) ($model['id'] ?? '');
            if ($id === '') {
                continue;
            }
            try {
                $rows[] = $this->run($symbol, $id, $relatedSymbol);
            } catch (ReplayLaneBusyException $exception) {
                throw $exception;
            } catch (RuntimeException $exception) {
                // A model with a declared but absent prerequisite is recorded
                // as blocked in the operator table; it must never borrow a
                // different model's data or silently become a generic signal.
                $rows[] = [
                    'research_model_id' => $id,
                    'label' => $model['label'] ?? $id,
                    'status' => 'blocked',
                    'reason_codes' => [$this->reasonCode($exception)],
                    'promotion_evidence' => false,
                ];
            }
        }

        return $rows;
    }

    /** @return array<string,mixed> */
    private function payload(string $symbol, string $strategy, array $parameters, array $bundle, array $execution): array
    {
        $relatedContexts = (array) $bundle['related_context_dataset_paths'];

        return [
            'symbol' => $symbol,
            'timeframe' => 'M5',
            'strategy' => $strategy,
            'parameters' => $parameters,
            'initial_balance' => 10000.0,
            'risk_per_trade' => 1.0,
            'dataset_path' => $bundle['entry_dataset_path'],
            'mtf_dataset_paths' => (array) $bundle['context_dataset_paths'],
            // FastAPI declares this field as a dictionary. PHP's empty array
            // serializes as JSON [], so preserve the empty-map shape when a
            // playbook does not need a related market.
            'related_mtf_dataset_paths' => $relatedContexts === [] ? (object) [] : $relatedContexts,
            'mtf_snapshot_manifest' => (array) $bundle['manifest'],
            'execution' => $execution['parameters'],
            'execution_contract' => $execution,
            'evaluation_mode' => 'replay',
            // The streaming signal/event/trade digests prove behavioural
            // identity without materializing a
            // pandas Series and a full decision object for every M5 candle.
            // Full trace mode made a 10k-row Confirmation prior exceed the
            // bounded 300-second child budget before producing any evidence.
            'emit_decision_trace' => false,
            // Comparison consumes aggregate metrics, funnel observations and
            // the ordered trade-ledger hash/count. Shipping the full ledger
            // back through FastAPI/PHP adds no authority and can stall the
            // handoff after computation has already completed.
            'emit_trade_ledger' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function replay(array $payload): array
    {
        try {
            $response = Http::timeout((int) config('services.ai_service.backtest_timeout_seconds', 900))
                ->acceptJson()
                ->withHeaders(['X-Internal-Token' => (string) config('services.internal_api.token')])
                ->post(rtrim((string) config('services.ai_service.url'), '/').'/api/backtest/run', $payload);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('MTF playbook Python replay service unavailable.', 0, $exception);
        }
        if ($response->status() === 429) {
            throw new ReplayLaneBusyException('AI replay lane is busy; research replay deferred without a quality verdict.');
        }
        if ($response->failed()) {
            throw new RuntimeException('MTF playbook replay failed: '.substr((string) $response->body(), 0, 1000));
        }
        $result = (array) $response->json();
        if ($result === []) {
            throw new RuntimeException('MTF playbook replay returned no result.');
        }
        if (! $this->executionContracts->matches((array) ($result['execution_contract'] ?? []), (string) $payload['symbol'], 'M5')) {
            throw new RuntimeException('MTF playbook execution contract mismatch.');
        }

        return $result;
    }

    /**
     * Snapshot preparation can take several minutes. Re-check the durable
     * priority intent at the actual HTTP boundary so an evolution cohort that
     * appeared during preflight always wins the single replay lane. This is an
     * admission decision, never negative market evidence.
     */
    private function yieldToCanonicalEvolution(string $leg): void
    {
        if ($this->queues->evolutionReplayIsWaiting()) {
            throw new ReplayLaneBusyException(
                "Canonical evolution appeared before the MTF {$leg} replay leg.",
            );
        }
    }

    /** @return array<string,mixed> */
    private function comparison(
        array $control,
        array $candidate,
        array $model,
        array $bundle,
        array $execution,
        string $runtimeHash,
        string $runnerHash,
        array $candidateParameters,
        array $variant,
    ): array {
        $controlMetrics = $this->metrics($control);
        $candidateMetrics = $this->metrics($candidate);
        $tradeDelta = $candidateMetrics['total_trades'] - $controlMetrics['total_trades'];
        $pfDelta = $candidateMetrics['profit_factor'] - $controlMetrics['profit_factor'];
        $netDelta = $candidateMetrics['net_profit_percent'] - $controlMetrics['net_profit_percent'];
        $ddDelta = $candidateMetrics['max_drawdown_percent'] - $controlMetrics['max_drawdown_percent'];
        $changed = $tradeDelta !== 0 || abs($pfDelta) > .0001 || abs($netDelta) > .0001 || abs($ddDelta) > .0001;
        $minimumTrades = 8;
        $powered = $controlMetrics['total_trades'] >= $minimumTrades
            && $candidateMetrics['total_trades'] >= $minimumTrades;
        $reasonCodes = array_values(array_filter([
            $controlMetrics['total_trades'] < $minimumTrades ? 'CONTROL_ACTIVITY_INSUFFICIENT' : null,
            $candidateMetrics['total_trades'] < $minimumTrades ? 'CANDIDATE_ACTIVITY_INSUFFICIENT' : null,
        ]));

        return [
            'protocol' => self::PROTOCOL,
            'status' => 'completed',
            'research_model_id' => $model['id'],
            'data_role' => (string) data_get($bundle, 'manifest.data_role', 'paper_shadow_prior_only'),
            'agent_owned_evidence' => false,
            'same_data_hash' => true,
            'same_execution_hash' => true,
            'data_hash' => $bundle['bundle_hash'],
            'execution_hash' => $execution['execution_hash'],
            'python_runtime_hash' => $runtimeHash,
            'runner_contract_hash' => $runnerHash,
            'evidence_budget' => [
                'protocol' => 'mtf_evidence_budget_ladder_v1',
                'entry_rows' => (int) data_get($bundle, 'manifest.bounded_entry_rows', MultiTimeframeSnapshotService::RESEARCH_MAX_M5_ROWS),
                'maximum_entry_rows' => max(MultiTimeframeSnapshotService::RESEARCH_EVIDENCE_BUDGETS),
                'gate_relaxed' => false,
            ],
            'candidate_variant' => $variant,
            'candidate_parameter_diff' => (array) ($variant['overrides'] ?? []),
            'control' => $controlMetrics,
            'candidate' => $candidateMetrics,
            'power' => [
                'status' => $powered ? 'powered' : 'insufficient',
                'minimum_trades_per_arm' => $minimumTrades,
                'reason_codes' => $reasonCodes,
            ],
            'delta' => [
                'total_trades' => $tradeDelta,
                'profit_factor' => round($pfDelta, 6),
                'net_profit_percent' => round($netDelta, 6),
                'max_drawdown_percent' => round($ddDelta, 6),
            ],
            'mutation_effective' => $changed,
            'interpretation' => ! $powered
                ? 'insufficient_candidate_activity'
                : (! $changed
                ? 'no_observable_difference'
                : (($pfDelta >= 0 && $netDelta >= 0 && $ddDelta <= 0) ? 'candidate_improved_on_this_frozen_replay' : 'candidate_not_dominant')),
            'learning_directive' => $this->learningDirective($candidate, $candidateMetrics, $candidateParameters, $variant, $minimumTrades),
            'promotion_evidence' => false,
        ];
    }

    /**
     * Autonomous frozen-prior repair is deliberately narrower than the full
     * schema: one activity bottleneck, one changed gene, one repair depth.
     */
    private function assertBoundedCandidateOverrides(string $strategy, array $overrides): void
    {
        if ($overrides === []) {
            return;
        }
        if ($strategy !== 'confirmation_entry_mtf_v1') {
            throw new RuntimeException('Candidate overrides are sealed to Confirmation & Entry research.');
        }
        if (array_keys($overrides) !== ['minimum_independent_confirmations']) {
            throw new RuntimeException('Bounded Confirmation repair may change only minimum_independent_confirmations.');
        }
        if ((int) $overrides['minimum_independent_confirmations'] !== 2) {
            throw new RuntimeException('Bounded Confirmation repair is sealed to minimum_independent_confirmations=2.');
        }
    }

    /** @return array<string,mixed> */
    private function candidateVariant(array $overrides, array $trialContext): array
    {
        $sourceRunId = (int) ($trialContext['source_run_id'] ?? 0);
        $evidenceBudget = (int) ($trialContext['evidence_budget_rows'] ?? 0);
        $isExpansion = $evidenceBudget > 0;
        $default = $overrides === [] && ! $isExpansion;
        if ($isExpansion) {
            if ($overrides !== [] || $sourceRunId < 1
                || ! in_array($evidenceBudget, array_slice(MultiTimeframeSnapshotService::RESEARCH_EVIDENCE_BUDGETS, 1), true)) {
                throw new RuntimeException('MTF evidence expansion requires one immutable source and a sealed higher budget tier.');
            }

            return [
                'protocol' => 'mtf_candidate_variant_v2_multifidelity',
                'id' => 'evidence_budget_'.$evidenceBudget,
                'variant_class' => 'evidence_budget_expansion',
                'overrides' => [],
                'source_run_id' => $sourceRunId,
                'changed_axis' => 'evidence_budget_rows',
                'old_value' => null,
                'new_value' => $evidenceBudget,
                'evidence_budget_rows' => $evidenceBudget,
                'target_metric' => 'candidate_activity_power_without_gate_relaxation',
                'bounded_depth' => array_search(
                    $evidenceBudget,
                    MultiTimeframeSnapshotService::RESEARCH_EVIDENCE_BUDGETS,
                    true,
                ),
                'stopping_rule' => 'stop_at_power_or_40k_then_require_independent_agent_owned_windows',
                'promotion_evidence' => false,
            ];
        }
        if (! $default && $sourceRunId < 1) {
            throw new RuntimeException('Bounded Confirmation repair requires an immutable source_run_id.');
        }

        return [
            'protocol' => 'mtf_candidate_variant_v2_multifidelity',
            'id' => $default ? 'frozen_default' : 'min_independent_confirmations_2',
            'variant_class' => $default ? 'scout' : 'parameter_repair',
            'overrides' => $overrides,
            'source_run_id' => $default ? null : $sourceRunId,
            'changed_axis' => $default ? null : 'minimum_independent_confirmations',
            'old_value' => $default ? null : 3,
            'new_value' => $default ? null : 2,
            'target_metric' => $default ? null : 'candidate_activity',
            'bounded_depth' => $default ? 0 : 1,
            'stopping_rule' => $default ? null : 'one_repair_only_then_advance_model',
            'promotion_evidence' => false,
        ];
    }

    private function validSourceRun(
        int $sourceRunId,
        string $modelId,
        string $symbol,
        ?string $relatedSymbol,
        string $runtimeHash,
        string $runnerHash,
    ): MtfPlaybookFrozenControlRun {
        $sourceRun = MtfPlaybookFrozenControlRun::query()->find($sourceRunId);
        if (! $sourceRun
            || (string) $sourceRun->status !== 'completed'
            || (string) $sourceRun->research_model_id !== $modelId
            || (string) $sourceRun->symbol !== $symbol
            || (string) ($sourceRun->related_symbol ?? '') !== (string) ($relatedSymbol ?? '')
            || (string) data_get($sourceRun->comparison, 'python_runtime_hash') !== $runtimeHash
            || (string) data_get($sourceRun->comparison, 'runner_contract_hash') !== $runnerHash) {
            throw new RuntimeException('MTF source run identity is invalid or stale.');
        }

        return $sourceRun;
    }

    private function nextEvidenceBudget(int $current): ?int
    {
        $index = array_search($current, MultiTimeframeSnapshotService::RESEARCH_EVIDENCE_BUDGETS, true);

        return $index === false
            ? null
            : (MultiTimeframeSnapshotService::RESEARCH_EVIDENCE_BUDGETS[$index + 1] ?? null);
    }

    /** A small profitable sample earns more data, never promotion authority. */
    private function promisingUnderpowered(array $comparison): bool
    {
        $control = (array) data_get($comparison, 'control', []);
        $candidate = (array) data_get($comparison, 'candidate', []);
        $minimum = max(1, (int) data_get($comparison, 'power.minimum_trades_per_arm', 8));
        $trades = (int) ($candidate['total_trades'] ?? 0);

        return $trades > 0
            && $trades < $minimum
            && (float) ($candidate['profit_factor'] ?? 0) > (float) ($control['profit_factor'] ?? 0)
            && (float) ($candidate['net_profit_percent'] ?? 0) > (float) ($control['net_profit_percent'] ?? 0)
            && (float) ($candidate['max_drawdown_percent'] ?? INF) <= (float) ($control['max_drawdown_percent'] ?? -INF);
    }

    /** @return array<string,mixed> */
    private function learningDirective(
        array $candidate,
        array $candidateMetrics,
        array $candidateParameters,
        array $variant,
        int $minimumTrades,
    ): array {
        if ((string) data_get($variant, 'variant_class') === 'evidence_budget_expansion') {
            $powered = $candidateMetrics['total_trades'] >= $minimumTrades;

            return [
                'protocol' => MtfPlaybookLearningDirectorService::PROTOCOL,
                'status' => $powered ? 'evidence_budget_power_reached' : 'evidence_budget_observed',
                'next_action' => $powered
                    ? 'require_agent_owned_independent_window_pair'
                    : 'expand_only_if_still_promising_and_next_sealed_budget_exists',
                'evidence_budget_rows' => (int) data_get($variant, 'evidence_budget_rows', 0),
                'gate_relaxed' => false,
                'further_relaxation_allowed' => false,
                'promotion_evidence' => false,
            ];
        }
        if ((string) ($variant['id'] ?? '') !== 'frozen_default') {
            return [
                'protocol' => MtfPlaybookLearningDirectorService::PROTOCOL,
                'status' => 'bounded_repair_terminal',
                'next_action' => 'advance_to_next_confirmation_model',
                'further_relaxation_allowed' => false,
                'promotion_evidence' => false,
            ];
        }

        $funnel = (array) ($candidate['entry_contract_funnel'] ?? []);
        $setup = (int) data_get($funnel, 'stage_counts.setup', 0);
        $confirmation = (int) data_get($funnel, 'stage_counts.confirmation', 0);
        $configuredMinimum = (int) ($candidateParameters['minimum_independent_confirmations'] ?? 0);
        $eligible = $candidateMetrics['total_trades'] < $minimumTrades
            && $setup >= $minimumTrades
            && $confirmation < $minimumTrades
            && $configuredMinimum === 3;

        return [
            'protocol' => MtfPlaybookLearningDirectorService::PROTOCOL,
            'status' => $eligible ? 'ready' : 'not_applicable',
            'reason' => $eligible
                ? 'confirmation_stage_is_the_measured_activity_bottleneck'
                : 'single_axis_confirmation_repair_not_supported_by_funnel',
            'changed_axis' => $eligible ? 'minimum_independent_confirmations' : null,
            'old_value' => $eligible ? 3 : null,
            'new_value' => $eligible ? 2 : null,
            'source_stage_counts' => [
                'setup' => $setup,
                'confirmation' => $confirmation,
                'entry_ready' => (int) data_get($funnel, 'stage_counts.entry_ready', 0),
            ],
            'max_repair_depth' => 1,
            'further_relaxation_allowed' => false,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string,int|float> */
    private function metrics(array $result): array
    {
        return [
            'total_trades' => (int) ($result['total_trades'] ?? 0),
            'profit_factor' => (float) ($result['profit_factor'] ?? 0),
            'net_profit_percent' => (float) ($result['net_profit_percent'] ?? 0),
            'max_drawdown_percent' => (float) ($result['max_drawdown_percent'] ?? $result['max_drawdown'] ?? 0),
            'winrate' => (float) ($result['winrate'] ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    private function result(MtfPlaybookFrozenControlRun $run, bool $reused): array
    {
        return [
            'run_id' => $run->id,
            'research_model_id' => $run->research_model_id,
            'status' => $run->status,
            'data_hash' => $run->data_hash,
            'execution_hash' => $run->execution_hash,
            'comparison' => (array) $run->comparison,
            'reason_codes' => (array) $run->reason_codes,
            'reused' => $reused,
            'agent_owned_evidence' => false,
            'promotion_evidence' => false,
        ];
    }

    private function symbol(string $value): string
    {
        return strtoupper(str_replace(['/', '_', '-'], '', trim($value)));
    }

    private function hash(array $value): string
    {
        $this->canonicalize($value);

        return hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function canonicalize(array &$value): void
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $this->canonicalize($item);
            }
        }
        unset($item);
    }

    private function reasonCode(RuntimeException $exception): string
    {
        return str_contains(strtolower($exception->getMessage()), 'related symbol')
            ? 'RELATED_MARKET_REQUIRED'
            : 'IMMUTABLE_DATA_PREREQUISITE:'.substr($exception->getMessage(), 0, 180);
    }

    private function runtimeHash(): string
    {
        if ($this->runtimeHash !== null) {
            return $this->runtimeHash;
        }

        $root = realpath(base_path('../ai-service-python/app'));
        if (! is_string($root) || ! is_dir($root)) {
            throw new RuntimeException('AI runtime source tree topilmadi; model identity muzlatilmadi.');
        }
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (! $file->isFile() || strtolower($file->getExtension()) !== 'py') {
                continue;
            }
            $paths[] = $file->getPathname();
        }
        sort($paths, SORT_STRING);
        $context = hash_init('sha256');
        foreach ($paths as $path) {
            hash_update($context, str_replace('\\', '/', substr($path, strlen($root)))."\0");
            hash_update_file($context, $path);
        }

        return $this->runtimeHash = hash_final($context);
    }

    private function runnerHash(): string
    {
        return $this->runnerHash ??= self::RUNNER_CONTRACT_HASH;
    }
}
