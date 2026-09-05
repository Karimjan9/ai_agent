<?php

namespace App\Services;

use App\Exceptions\ReplayLaneBusyException;
use App\Models\CompositionSettlement;
use App\Models\ModelVersion;
use App\Models\MtfAgentValidationRun;
use App\Models\MtfPlaybookFrozenControlRun;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Turns a powered paper-shadow prior into model-owned chronological evidence.
 *
 * Discovery data can schedule this lane but is never reused as final paper
 * evidence. Candidate and frozen baseline receive the same nine disjoint,
 * purged and embargoed historical folds. The terminal settlement remains E2
 * research evidence and cannot promote, parent or trade by itself.
 */
class MtfPoweredPriorValidationService
{
    public const PROTOCOL = 'mtf_powered_prior_agent_validation_v1';

    public const FOLD_COUNT = 9;

    public const MINIMUM_PAIRED_POSITIVE_WINDOWS = 6;

    public const MINIMUM_POWERED_WINDOWS = 6;

    public const PORTABILITY_PROTOCOL = 'mtf_prior_portability_diagnosis_v1';

    public function __construct(
        private MtfPlaybookFrozenControlService $priorRunner,
        private MultiTimeframeSnapshotService $snapshots,
        private StrategyParameterSchemaService $schemas,
        private ExecutionContractService $executionContracts,
        private CompositionAuthorityKernelService $compositionKernel,
    ) {}

    public function nextEligible(string $symbol = 'XAUUSD'): ?MtfPlaybookFrozenControlRun
    {
        $symbol = strtoupper(str_replace(['/', '_', '-'], '', trim($symbol)));
        if ($symbol !== 'XAUUSD'
            || ! Schema::hasTable('mtf_playbook_frozen_control_runs')
            || ! Schema::hasTable('mtf_agent_validation_runs')) {
            return null;
        }
        $identity = $this->priorRunner->currentIdentity();

        return MtfPlaybookFrozenControlRun::query()
            ->where('symbol', $symbol)
            ->where('status', 'completed')
            ->orderByDesc('id')
            // Candidate/control replay bodies are multi-megabyte immutable
            // artifacts. Eligibility needs identity and comparison only.
            ->get(['id', 'research_model_id', 'symbol', 'status', 'comparison'])
            ->first(function (MtfPlaybookFrozenControlRun $run) use ($identity): bool {
                if (! $this->eligible($run, $identity)) {
                    return false;
                }

                if (MtfAgentValidationRun::query()
                    ->where('source_run_id', $run->id)
                    ->where('status', 'started')
                    ->exists()) {
                    return false;
                }

                return $this->nextEvidenceBudget($run) !== null;
            });
    }

    /** @return array<string,mixed> */
    public function run(int $sourceRunId): array
    {
        $source = MtfPlaybookFrozenControlRun::query()->findOrFail($sourceRunId);
        $identity = $this->priorRunner->currentIdentity();
        if (! $this->eligible($source, $identity)) {
            throw new RuntimeException('MTF source is not a current powered and dominant frozen prior.');
        }
        $latestCompleted = MtfAgentValidationRun::query()
            ->where('protocol', self::PROTOCOL)
            ->where('source_run_id', $source->id)
            ->where('status', 'completed')
            ->latest('id')
            ->first();
        $evidenceBudget = $this->nextEvidenceBudget($source);
        if ($evidenceBudget === null) {
            if ($latestCompleted) {
                return $this->result($latestCompleted, true);
            }
            throw new RuntimeException('MTF agent validation has no admissible sealed evidence budget.');
        }
        [$entryModel, $strategyId, $tacticId, $managementId, $priorId] = $this->modelContract((string) $source->research_model_id);
        $candidateParameters = $this->schemas->validate('confirmation_entry_mtf_v1', [
            ...$this->schemas->defaults('confirmation_entry_mtf_v1'),
            'entry_model' => $entryModel,
        ]);
        $candidateHash = $this->parameterHash('confirmation_entry_mtf_v1', $candidateParameters);
        if (! hash_equals((string) $source->candidate_parameter_hash, $candidateHash)
            || (array) data_get($source->comparison, 'candidate_parameter_diff', []) !== []) {
            throw new RuntimeException('Powered prior parameters cannot be reconstructed exactly for agent ownership.');
        }
        $controlParameters = $this->schemas->validate(
            'mtf_research_control_v1',
            $this->schemas->defaults('mtf_research_control_v1'),
        );
        $controlHash = $this->parameterHash('mtf_research_control_v1', $controlParameters);

        // The source prior consumed 2026 paper candles. Agent-owned causal
        // confirmation uses only the pre-2026 foundation archive; a future
        // paper gate must begin strictly after the discovery cutoff.
        $resumable = $this->resumableRun($source->id, $evidenceBudget);
        $bundle = $resumable
            ? $this->snapshots->restoreAgentOwnedConfirmationValidationBundle((array) $resumable->dataset_manifest)
            : $this->snapshots->forAgentOwnedConfirmationValidation('XAUUSD', $evidenceBudget);
        $execution = $this->executionContracts->for('XAUUSD', 'M5');
        $strategyName = 'xauusd_'.strtolower((string) $source->research_model_id).'_owner_'.$source->id;
        $passport = $this->compositionKernel->freeze([
            'symbol' => 'XAUUSD', 'timeframe' => 'M5',
            'strategy_id' => $strategyId, 'tactic_id' => $tacticId,
            'risk_id' => 'atr_risk_envelope', 'management_id' => $managementId,
            'prior_ids' => [$priorId], 'local_evidence_count' => 1,
            'market_state' => ['state_key' => 'cross_regime', 'regime' => 'cross_regime'],
            'data_contract' => ['m5_canonical' => true, 'm1_execution' => false],
            'data_hash' => (string) $bundle['bundle_hash'],
            'execution_hash' => (string) $execution['execution_hash'],
        ]);
        $owner = ModelVersion::query()->firstOrCreate(['name' => $strategyName], [
            'strategy' => $strategyName,
            'version' => 'mtf-owner-v1',
            'generation' => 1,
            'status' => 'research',
            'parameters' => $candidateParameters,
            'metadata' => [
                'base_strategy' => 'confirmation_entry_mtf_v1',
                'strategy_family' => 'confirmation_entry_mtf',
                'source_powered_prior_run_id' => $source->id,
                'smart_composition' => ['composition_passport' => $passport],
                'evidence_authority' => 'awaiting_agent_owned_paired_historical_confirmation',
                'runtime_trade_authority' => false,
                'parent_authority' => false,
                'promotion_evidence' => false,
            ],
            'evidence_status' => 'research',
        ]);
        if ((int) data_get($owner->metadata, 'source_powered_prior_run_id') !== (int) $source->id
            || $this->parameterHash('confirmation_entry_mtf_v1', (array) $owner->parameters) !== $candidateHash) {
            throw new RuntimeException('MTF model owner identity conflicts with the powered prior.');
        }

        $contract = $this->validationContract($source, $owner, $passport, $bundle, $execution, $evidenceBudget);
        $runKey = $this->hash([
            'protocol' => self::PROTOCOL,
            'source_run_id' => $source->id,
            'model_version_id' => $owner->id,
            'data_hash' => $bundle['bundle_hash'],
            'execution_hash' => $execution['execution_hash'],
            'candidate_parameter_hash' => $candidateHash,
            'control_parameter_hash' => $controlHash,
            'python_runtime_hash' => $identity['python_runtime_hash'],
            'runner_contract_hash' => $identity['runner_contract_hash'],
            'validation_contract' => $contract,
        ]);
        $existing = MtfAgentValidationRun::query()->where('run_key', $runKey)->first();
        if ($existing && (string) $existing->status === 'completed') {
            return $this->result($existing, true);
        }
        if ($existing && (string) $existing->status === 'started'
            && $existing->updated_at?->isAfter(now()->subMinutes(25))) {
            throw new ReplayLaneBusyException('Identical MTF agent validation is already running.');
        }
        $run = $existing ?: new MtfAgentValidationRun(['run_key' => $runKey]);
        $run->fill([
            'protocol' => self::PROTOCOL,
            'source_run_id' => $source->id,
            'model_version_id' => $owner->id,
            'symbol' => 'XAUUSD', 'entry_timeframe' => 'M5',
            'status' => 'started', 'attempts' => (int) ($existing?->attempts ?? 0) + 1,
            'data_hash' => $bundle['bundle_hash'],
            'execution_hash' => $execution['execution_hash'],
            'candidate_parameter_hash' => $candidateHash,
            'control_parameter_hash' => $controlHash,
            'dataset_manifest' => (array) $bundle['manifest'],
            'validation_contract' => $contract,
            'candidate_result' => null, 'control_result' => null,
            'paired_summary' => null, 'causal_accounting' => null,
            'reason_codes' => [], 'last_error' => null,
            'promotion_evidence' => false, 'started_at' => now(), 'completed_at' => null,
        ])->save();

        try {
            $response = $this->replay($this->payload(
                $strategyName, $candidateParameters, $controlParameters, $bundle, $execution, $contract,
            ));
            $items = collect((array) data_get($response, 'leaderboard', []))->keyBy('strategy');
            $candidate = (array) data_get($items->get($strategyName), 'result', []);
            $controlName = $strategyName.'_frozen_control';
            $control = (array) data_get($items->get($controlName), 'result', []);
            $this->assertResult($candidate, 'candidate');
            $this->assertResult($control, 'control');
            $summary = $this->pairedSummary($source, $candidate, $control, $evidenceBudget);
            $accounting = app(CausalEdgeAccountingService::class)->project($candidate);
            $run->update([
                'status' => 'completed', 'candidate_result' => $candidate,
                'control_result' => $control, 'paired_summary' => $summary,
                'causal_accounting' => $accounting, 'reason_codes' => (array) $summary['reason_codes'],
                'last_error' => null, 'completed_at' => now(), 'promotion_evidence' => false,
            ]);
            $this->settle($run->fresh(), $owner, $passport, $summary, $accounting);
        } catch (ReplayLaneBusyException $exception) {
            $run->update(['status' => 'retry_deferred', 'last_error' => $exception->getMessage()]);
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            $run->update([
                'status' => 'technical_error',
                'reason_codes' => ['MTF_AGENT_VALIDATION_TECHNICAL_ERROR'],
                'last_error' => substr($exception->getMessage(), 0, 1500),
                'completed_at' => now(), 'promotion_evidence' => false,
            ]);
        }

        return $this->result($run->fresh(), false);
    }

    /**
     * Rebuild derived learning projections from already sealed replay bytes.
     * This never calls Python and never changes the underlying arm results.
     *
     * @return array<string,mixed>
     */
    public function reprojectCompleted(int $validationRunId): array
    {
        $run = MtfAgentValidationRun::query()->with(['sourceRun', 'modelVersion'])->findOrFail($validationRunId);
        if ((string) $run->status !== 'completed' || ! $run->sourceRun || ! $run->modelVersion) {
            throw new RuntimeException('Only completed, model-owned MTF validation can be reprojected.');
        }
        $candidate = (array) $run->candidate_result;
        $control = (array) $run->control_result;
        $this->assertResult($candidate, 'candidate');
        $this->assertResult($control, 'control');
        $evidenceBudget = (int) data_get(
            $run->validation_contract,
            'historical_evidence_budget_rows',
            data_get($run->paired_summary, 'historical_evidence_budget_rows', 0),
        );
        if ($evidenceBudget <= 0) {
            throw new RuntimeException('Completed MTF validation is missing its frozen evidence budget.');
        }
        $summary = $this->pairedSummary($run->sourceRun, $candidate, $control, $evidenceBudget);
        $accounting = (array) $run->causal_accounting;
        if ($accounting === []) {
            $accounting = app(CausalEdgeAccountingService::class)->project($candidate);
        }
        $passport = (array) data_get($run->validation_contract, 'composition_passport', []);
        if ($passport === []) {
            $ownerPassport = (array) data_get($run->modelVersion->metadata, 'smart_composition.composition_passport', []);
            $compositionId = (string) data_get($run->validation_contract, 'composition_id', '');
            $components = (array) data_get($ownerPassport, 'components', []);
            $passport = $compositionId !== '' && $components !== [] ? [
                'protocol' => CompositionAuthorityKernelService::PROTOCOL,
                'composition_id' => $compositionId,
                'components' => $components,
            ] : [];
        }
        if ($passport === []) {
            throw new RuntimeException('Completed MTF validation owner is missing its frozen composition passport.');
        }
        $run->update([
            'paired_summary' => $summary,
            'causal_accounting' => $accounting,
            'reason_codes' => (array) $summary['reason_codes'],
            'promotion_evidence' => false,
        ]);
        $this->settle($run->fresh(), $run->modelVersion, $passport, $summary, $accounting);

        return [...$this->result($run->fresh(), true), 'reprojected' => true];
    }

    /** @return array<string,mixed> */
    private function payload(
        string $strategyName,
        array $candidateParameters,
        array $controlParameters,
        array $bundle,
        array $execution,
        array $contract,
    ): array {
        $controlName = $strategyName.'_frozen_control';
        $fold = [
            'protocol' => 'bounded_cold_start_learning_confirmation_v1',
            'role' => 'powered_mtf_prior_agent_owner',
            'admitted' => true,
            'maximum_holding_bars' => 240,
            'purge_bars' => 240,
            'embargo_bars' => 1,
            'fold_count' => self::FOLD_COUNT,
            'max_rows_per_fold' => 4096,
            'audit_trace_rows' => 512,
            // Sparse specialists are powered by breadth across independent
            // windows plus aggregate activity, not by loosening entry gates.
            'minimum_trades_per_window' => 1,
            'minimum_powered_windows' => self::MINIMUM_POWERED_WINDOWS,
            'minimum_positive_windows' => self::MINIMUM_PAIRED_POSITIVE_WINDOWS,
            'promotion_evidence' => false,
        ];

        return [
            'symbol' => 'XAUUSD', 'timeframe' => 'M5',
            'strategy' => $strategyName,
            'base_strategy' => 'confirmation_entry_mtf_v1',
            'strategies' => [
                ['strategy' => $strategyName, 'base_strategy' => 'confirmation_entry_mtf_v1', 'version' => 'mtf-owner-v1', 'parameters' => $candidateParameters],
                ['strategy' => $controlName, 'base_strategy' => 'mtf_research_control_v1', 'version' => 'mtf-control-v1', 'parameters' => $controlParameters],
            ],
            'parameters' => $candidateParameters,
            'initial_balance' => 10000.0, 'risk_per_trade' => 1.0,
            'dataset_path' => $bundle['entry_dataset_path'],
            'mtf_dataset_paths' => (array) $bundle['context_dataset_paths'],
            'related_mtf_dataset_paths' => (object) [],
            'mtf_snapshot_manifest' => (array) $bundle['manifest'],
            'execution' => $execution['parameters'], 'execution_contract' => $execution,
            'evaluation_mode' => 'replay',
            'policy_context' => [
                'learning_confirmation_contracts' => [
                    $strategyName => $fold,
                    $controlName => [...$fold, 'role' => 'frozen_control'],
                ],
                'powered_prior_agent_validation' => $contract,
                'data_boundary' => [
                    'training_end_exclusive' => '2026-01-01T00:00:00Z',
                    'source_discovery_paper_reuse_for_final_paper' => false,
                    'promotion_evidence' => false,
                ],
            ],
            'emit_decision_trace' => false, 'emit_trade_ledger' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function replay(array $payload): array
    {
        try {
            $response = Http::connectTimeout(15)->timeout(930)->acceptJson()
                ->withHeaders(['X-Internal-Token' => (string) config('services.internal_api.token')])
                ->post(rtrim((string) config('services.ai_service.url'), '/').'/api/backtest/run-all', $payload);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('MTF agent validation service unavailable.', 0, $exception);
        }
        if ($response->status() === 429) {
            throw new ReplayLaneBusyException('AI replay lane is busy; MTF agent validation deferred.');
        }
        if ($response->failed()) {
            throw new RuntimeException('MTF agent validation failed: '.substr((string) $response->body(), 0, 1000));
        }

        return (array) $response->json();
    }

    private function assertResult(array $result, string $arm): void
    {
        if ($result === []
            || ! $this->executionContracts->matches((array) data_get($result, 'execution_contract', []), 'XAUUSD', 'M5')
            || (string) data_get($result, 'learning_confirmation.status') !== 'completed'
            || (string) data_get($result, 'walk_forward.forward_window_protocol.protocol') !== 'disjoint_forward_folds_v1') {
            throw new RuntimeException("MTF agent validation {$arm} result is incomplete or unsealed.");
        }
    }

    /** @return array<string,mixed> */
    private function pairedSummary(
        MtfPlaybookFrozenControlRun $source,
        array $candidate,
        array $control,
        int $evidenceBudget,
    ): array {
        $candidateProtocol = (array) data_get($candidate, 'walk_forward.forward_window_protocol', []);
        $controlProtocol = (array) data_get($control, 'walk_forward.forward_window_protocol', []);
        $candidateWindows = collect((array) ($candidateProtocol['windows'] ?? []))->keyBy('id');
        $controlWindows = collect((array) ($controlProtocol['windows'] ?? []))->keyBy('id');
        $ids = $candidateWindows->keys()->intersect($controlWindows->keys())->values();
        $pairs = $ids->map(function (string $id) use ($candidateWindows, $controlWindows): array {
            $candidate = (array) $candidateWindows->get($id);
            $control = (array) $controlWindows->get($id);
            $powered = (int) ($candidate['trades'] ?? 0) >= 1 && (int) ($control['trades'] ?? 0) >= 1;
            $improved = $powered
                && (float) ($candidate['profit_factor'] ?? 0) >= (float) ($control['profit_factor'] ?? 0)
                && (float) ($candidate['net_profit_percent'] ?? 0) > (float) ($control['net_profit_percent'] ?? 0);

            return [
                'id' => $id, 'powered' => $powered, 'candidate_improved' => $improved,
                'candidate' => $candidate, 'control' => $control,
            ];
        })->values();
        $powered = $pairs->where('powered', true)->count();
        $positive = $pairs->where('candidate_improved', true)->count();
        $independent = (bool) data_get($candidateProtocol, 'independence_verified', false)
            && (bool) data_get($controlProtocol, 'independence_verified', false)
            && ! (bool) data_get($candidateProtocol, 'overlap_detected', true)
            && ! (bool) data_get($controlProtocol, 'overlap_detected', true);
        $candidateTrades = (int) data_get($candidate, 'total_trades', 0);
        $supported = $ids->count() === self::FOLD_COUNT
            && $independent
            && $powered >= self::MINIMUM_POWERED_WINDOWS
            && $positive >= self::MINIMUM_PAIRED_POSITIVE_WINDOWS
            && $candidateTrades >= 8;
        $underpowered = $ids->count() < self::FOLD_COUNT
            || ! $independent
            || $powered < self::MINIMUM_POWERED_WINDOWS
            || $candidateTrades < 8;
        $verdict = $supported ? 'local_e2_candidate_supported' : ($underpowered ? 'underpowered' : 'falsified_by_paired_historical_confirmation');
        $portability = $this->portabilityDiagnosis(
            $source,
            $verdict,
            $candidateTrades,
            $evidenceBudget,
        );

        return [
            'protocol' => 'mtf_agent_owned_paired_fold_summary_v1',
            'verdict' => $verdict,
            'observed_windows' => $ids->count(),
            'powered_paired_windows' => $powered,
            'positive_paired_windows' => $positive,
            'minimum_powered_windows' => self::MINIMUM_POWERED_WINDOWS,
            'minimum_positive_windows' => self::MINIMUM_PAIRED_POSITIVE_WINDOWS,
            'candidate_total_trades' => $candidateTrades,
            'historical_evidence_budget_rows' => $evidenceBudget,
            'historical_evidence_budget_max_rows' => max(MultiTimeframeSnapshotService::AGENT_VALIDATION_EVIDENCE_BUDGETS),
            'independence_verified' => $independent,
            'windows' => $pairs->all(),
            'source_discovery' => [
                'run_id' => $source->id,
                'data_role' => data_get($source->comparison, 'data_role'),
                'paper_data_consumed' => true,
                'paper_reuse_for_final_gate' => false,
                'discovery_cutoff' => data_get($source->dataset_manifest, 'closed_cutoff'),
            ],
            'portability_diagnosis' => $portability,
            'evidence_scope' => [
                'historical_fold_independence' => $independent,
                'hypothesis_selection_out_of_sample' => false,
                'post_selection_historical_confirmation' => true,
                'forward_or_promotion_authority' => false,
            ],
            'next_evidence' => $supported
                ? 'fresh_paper_after_discovery_cutoff_then_sealed_holdout'
                : ($underpowered
                    ? ($evidenceBudget < max(MultiTimeframeSnapshotService::AGENT_VALIDATION_EVIDENCE_BUDGETS)
                        ? 'expand_historical_breadth_without_gate_relaxation'
                        : 'reformulate_as_regime_specialist_or_retire_prior')
                    : 'retire_or_reformulate_composition'),
            'reason_codes' => array_values(array_filter([
                $ids->count() !== self::FOLD_COUNT ? 'NINE_FOLDS_INCOMPLETE' : null,
                ! $independent ? 'FOLD_INDEPENDENCE_FAILED' : null,
                $powered < self::MINIMUM_POWERED_WINDOWS ? 'PAIRED_WINDOW_POWER_INSUFFICIENT' : null,
                $candidateTrades < 8 ? 'AGGREGATE_CANDIDATE_ACTIVITY_INSUFFICIENT' : null,
                $underpowered && $evidenceBudget < max(MultiTimeframeSnapshotService::AGENT_VALIDATION_EVIDENCE_BUDGETS)
                    ? 'HISTORICAL_BREADTH_EXPANSION_REQUIRED' : null,
                $underpowered && $evidenceBudget >= max(MultiTimeframeSnapshotService::AGENT_VALIDATION_EVIDENCE_BUDGETS)
                    ? 'MAX_HISTORICAL_BREADTH_EXHAUSTED' : null,
                ($portability['classification'] ?? null) === 'period_regime_or_data_domain_conditioned_prior'
                    ? 'PRIOR_PORTABILITY_FAILURE_REQUIRES_CAUSAL_ROUTER_TRIAL' : null,
                ! $supported && ! $underpowered ? 'PAIRED_OOS_DOMINANCE_FAILED' : null,
            ])),
            'runtime_trade_authority' => false,
            'parent_authority' => false,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function portabilityDiagnosis(
        MtfPlaybookFrozenControlRun $source,
        string $verdict,
        int $historicalTrades,
        int $evidenceBudget,
    ): array {
        $discoveryTrades = (int) data_get(
            $source->candidate_result,
            'total_trades',
            data_get($source->comparison, 'candidate.total_trades', 0),
        );
        $maximum = max(MultiTimeframeSnapshotService::AGENT_VALIDATION_EVIDENCE_BUDGETS);
        $breadthExhausted = $evidenceBudget >= $maximum;
        $classification = match (true) {
            $verdict === 'local_e2_candidate_supported' => 'historically_portable_candidate_not_promotion',
            $breadthExhausted && $discoveryTrades >= 8 && $historicalTrades === 0 => 'period_regime_or_data_domain_conditioned_prior',
            $verdict === 'falsified_by_paired_historical_confirmation' => 'historically_nonportable_prior',
            $breadthExhausted => 'terminally_underpowered_portability_evidence',
            default => 'historical_breadth_expansion_pending',
        };
        $conditioned = $classification === 'period_regime_or_data_domain_conditioned_prior';

        return [
            'protocol' => self::PORTABILITY_PROTOCOL,
            'classification' => $classification,
            'discovery_candidate_trades' => $discoveryTrades,
            'historical_candidate_trades' => $historicalTrades,
            'activity_transfer_ratio' => $discoveryTrades > 0
                ? round($historicalTrades / $discoveryTrades, 6)
                : null,
            'historical_breadth_exhausted' => $breadthExhausted,
            'causal_claim' => $conditioned
                ? 'unresolved_between_period_regime_feed_and_activation_domain_shift'
                : null,
            'causal_claim_authority' => false,
            'evolution_directive' => $conditioned ? [
                'protocol' => 'mtf_regime_portability_experiment_v1',
                'status' => 'ready_as_bounded_hypothesis',
                'changed_axis' => 'market_state_admission_policy',
                'freeze_components' => [
                    'strategy', 'tactic', 'temporal_roles', 'confirmation_order',
                    'risk', 'management', 'execution_contract',
                ],
                'treatment' => 'source_state_fingerprint_router',
                'controls' => [
                    'ungated_source_composition',
                    'time_shifted_state_fingerprint_negative_control',
                ],
                'preflight' => [
                    'feed_and_session_capability_equivalence_audit',
                    'state_activation_coverage_map',
                ],
                'target' => 'recover_power_only_in_matching_states_without_relaxing_entry_or_risk_gates',
                'stopping_rule' => 'one_paired_single_axis_trial_then_retire_if_no_powered_transfer',
                'fresh_paper_after_discovery_cutoff_required' => true,
                'parent_authority' => false,
                'promotion_evidence' => false,
            ] : null,
            'runtime_trade_authority' => false,
            'parent_authority' => false,
            'promotion_evidence' => false,
        ];
    }

    private function settle(
        MtfAgentValidationRun $run,
        ModelVersion $owner,
        array $passport,
        array $summary,
        array $accounting,
    ): void {
        $verdict = (string) ($summary['verdict'] ?? 'underpowered');
        CompositionSettlement::query()->updateOrCreate([
            'settlement_key' => 'mtf-agent-validation:'.$run->run_key,
        ], [
            'model_version_id' => $owner->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'M5',
            'composition_id' => data_get($passport, 'composition_id'),
            'status' => $verdict,
            'components' => (array) data_get($passport, 'components', []),
            'evidence' => [
                'protocol' => self::PROTOCOL,
                'validation_run_id' => $run->id,
                'source_prior_run_id' => $run->source_run_id,
                'paired_summary' => $summary,
                'causal_edge_accounting' => data_get($accounting, 'causal_edge_accounting'),
                'process_outcome_audit' => data_get($accounting, 'process_outcome_audit'),
                'evidence_tier' => $verdict === 'local_e2_candidate_supported' ? 'E2_candidate' : 'E1_diagnostic',
                'runtime_trade_authority' => false,
                'parent_authority' => false,
                'promotion_evidence' => false,
            ],
            'settled_at' => now(),
        ]);
        $metadata = (array) $owner->metadata;
        data_set($metadata, 'mtf_agent_validation', [
            'protocol' => self::PROTOCOL,
            'run_id' => $run->id,
            'verdict' => $verdict,
            'evidence_tier' => $verdict === 'local_e2_candidate_supported' ? 'E2_candidate' : 'E1_diagnostic',
            'next_evidence' => $summary['next_evidence'] ?? null,
            'portability_diagnosis' => $summary['portability_diagnosis'] ?? null,
            'composition_passport' => $passport,
            'source_paper_reuse_for_final_gate' => false,
            'runtime_trade_authority' => false,
            'parent_authority' => false,
            'promotion_evidence' => false,
        ]);
        $owner->update([
            'status' => $verdict === 'local_e2_candidate_supported' ? 'research_validated' : 'research',
            'best_winrate' => (float) data_get($run->candidate_result, 'winrate', 0),
            'best_profit' => (float) data_get($run->candidate_result, 'net_profit_percent', 0),
            'best_drawdown' => (float) data_get($run->candidate_result, 'max_drawdown_percent', 0),
            'metadata' => $metadata,
            'evidence_status' => 'research',
        ]);
    }

    /** @return array<string,mixed> */
    private function validationContract(
        MtfPlaybookFrozenControlRun $source,
        ModelVersion $owner,
        array $passport,
        array $bundle,
        array $execution,
        int $evidenceBudget,
    ): array {
        return [
            'protocol' => self::PROTOCOL,
            'source_prior_run_id' => $source->id,
            'owner_model_version_id' => $owner->id,
            'composition_id' => data_get($passport, 'composition_id'),
            'composition_passport' => $passport,
            'paired_control' => 'mtf_research_control_v1',
            'fold_count' => self::FOLD_COUNT,
            'historical_evidence_budget_rows' => $evidenceBudget,
            'historical_entry_rows' => (int) data_get($bundle, 'manifest.streams.M5.row_count'),
            'purge_bars' => 240, 'embargo_bars' => 1,
            'data_hash' => $bundle['bundle_hash'],
            'execution_hash' => $execution['execution_hash'],
            'paper_discovery_data_is_not_final_paper' => true,
            'post_selection_historical_confirmation' => true,
            'hypothesis_selection_out_of_sample' => false,
            'one_frozen_composition' => true,
            'risk_and_entry_gates_relaxed' => false,
            'runtime_trade_authority' => false,
            'parent_authority' => false,
            'promotion_evidence' => false,
        ];
    }

    private function eligible(MtfPlaybookFrozenControlRun $run, array $identity): bool
    {
        return str_starts_with((string) $run->research_model_id, 'confirmation_')
            && (string) data_get($run->comparison, 'python_runtime_hash') === $identity['python_runtime_hash']
            && (string) data_get($run->comparison, 'runner_contract_hash') === $identity['runner_contract_hash']
            && (string) data_get($run->comparison, 'power.status') === 'powered'
            && (string) data_get($run->comparison, 'interpretation') === 'candidate_improved_on_this_frozen_replay'
            && (bool) data_get($run->comparison, 'agent_owned_evidence', false) === false;
    }

    private function nextEvidenceBudget(MtfPlaybookFrozenControlRun $source): ?int
    {
        $latest = MtfAgentValidationRun::query()
            ->where('protocol', self::PROTOCOL)
            ->where('source_run_id', $source->id)
            ->where('status', 'completed')
            ->latest('id')
            ->first(['id', 'paired_summary', 'validation_contract', 'dataset_manifest']);
        $budgets = MultiTimeframeSnapshotService::AGENT_VALIDATION_EVIDENCE_BUDGETS;
        $requested = $budgets[0];
        if ($latest) {
            if ((string) data_get($latest->paired_summary, 'verdict') !== 'underpowered') {
                return null;
            }
            $previous = (int) data_get(
                $latest->validation_contract,
                'historical_evidence_budget_rows',
                data_get(
                    $latest->paired_summary,
                    'historical_evidence_budget_rows',
                    data_get($latest->dataset_manifest, 'streams.M5.row_count', 0),
                ),
            );
            $requested = collect($budgets)->first(fn (int $budget): bool => $budget > $previous) ?? 0;
            if ($requested <= 0) {
                return null;
            }
        }
        $readiness = $this->snapshots->agentValidationReadiness('XAUUSD', $requested);
        if (! (bool) data_get($readiness, 'ready', false)) {
            return null;
        }
        if ($latest) {
            $previousRows = (int) data_get(
                $latest->validation_contract,
                'historical_entry_rows',
                data_get($latest->dataset_manifest, 'streams.M5.row_count', 0),
            );
            if ((int) data_get($readiness, 'bounded_m5_rows', 0) <= $previousRows) {
                return null;
            }
        }

        return $requested;
    }

    private function resumableRun(int $sourceRunId, int $evidenceBudget): ?MtfAgentValidationRun
    {
        return MtfAgentValidationRun::query()
            ->where('protocol', self::PROTOCOL)
            ->where('source_run_id', $sourceRunId)
            ->whereIn('status', ['started', 'retry_deferred', 'technical_error'])
            ->latest('id')
            ->limit(10)
            ->get(['id', 'status', 'dataset_manifest', 'validation_contract'])
            ->first(fn (MtfAgentValidationRun $run): bool => (int) data_get(
                $run->validation_contract,
                'historical_evidence_budget_rows',
                data_get($run->dataset_manifest, 'bounded_cost_contract.requested_m5_rows', 0),
            ) === $evidenceBudget);
    }

    /** @return array{string,string,string,string,string} */
    private function modelContract(string $modelId): array
    {
        return match ($modelId) {
            'confirmation_trend_continuation' => ['trend_continuation', 'str_001_ema_adx_pullback', 'trend_pullback', 'balanced_professional', 'prior_trend_pullback_001'],
            'confirmation_breakout_retest' => ['breakout_retest', 'str_031_bos_retest', 'breakout_retest', 'breakout_measured_move', 'prior_break_retest_001'],
            'confirmation_false_break_reversal' => ['false_break_reversal', 'str_032_choch_reversal', 'liquidity_reversal', 'reversal_reduced_risk', 'prior_liquidity_reversal_001'],
            'confirmation_range_sweep' => ['range_sweep', 'str_020_bb_rsi_reversion', 'range_mean_reversion', 'range_fixed_target', 'prior_range_reversion_001'],
            'confirmation_htf_reversal' => ['htf_reversal', 'str_032_choch_reversal', 'liquidity_reversal', 'reversal_reduced_risk', 'prior_liquidity_reversal_001'],
            default => throw new RuntimeException('Unsupported powered Confirmation model.'),
        };
    }

    private function parameterHash(string $strategy, array $parameters): string
    {
        return $this->hash($this->schemas->canonicalizeForIdentity($strategy, $parameters));
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
    }

    /** @return array<string,mixed> */
    private function result(MtfAgentValidationRun $run, bool $reused): array
    {
        return [
            'run_id' => $run->id, 'source_run_id' => $run->source_run_id,
            'model_version_id' => $run->model_version_id, 'status' => $run->status,
            'verdict' => data_get($run->paired_summary, 'verdict'),
            'paired_summary' => (array) $run->paired_summary,
            'reason_codes' => (array) $run->reason_codes,
            'reused' => $reused,
            'runtime_trade_authority' => false,
            'parent_authority' => false,
            'promotion_evidence' => false,
        ];
    }
}
