<?php

namespace App\Services;

use App\Models\AiLaboratory;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\LabTemporalAblationRun;
use App\Models\ModelVersion;
use App\Models\SystemEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Admission and execution boundary for the four-lane temporal ablation.
 *
 * The runner is manifest-driven: it never slices the rolling rescue dataset
 * into synthetic "independent" windows.  A valid manifest must point to
 * three operator-sealed, chronological files with distinct content hashes.
 * Results are stored in a research-only table and cannot write champion,
 * parent, paper, or mutation-credit state.
 */
class TemporalAblationRunnerService
{
    public const MANIFEST_PROTOCOL = 'temporal_clean_ablation_manifest_v2';
    public const LEGACY_MANIFEST_PROTOCOL = 'temporal_clean_ablation_manifest_v1';
    public const PLANNED = 'ADMITTED_CLEAN_ABLATION_PLAN';

    public function __construct(
        private TemporalAblationProtocolService $protocol,
        private StrategyParameterSchemaService $schemas,
        private ExecutionContractService $executionContracts,
    ) {}

    /** @return array<string, mixed> */
    public function plan(
        AiLaboratory $lab,
        ?LabGeneration $source = null,
        ?ModelVersion $model = null,
        array $manifest = [],
    ): array {
        $source ??= $lab->generations()->latest('generation')->first();
        $model ??= $this->sourceModel($source);
        $windows = array_values((array) data_get($manifest, 'windows', is_array($manifest) && array_is_list($manifest) ? $manifest : []));
        $hypothesisHash = $this->hypothesisHash($lab, $source, $manifest);
        $validation = $this->validateManifest($lab, $source, $manifest, $windows);
        $dataIdentityHash = $validation['data_identity_hash'];
        $executionHash = $validation['execution_hash'];
        $contract = $this->protocol->contract([
            'required_independent_windows' => 3,
            'temporal_threshold' => (float) data_get($manifest, 'temporal_threshold', config('services.rescue_circuit_breaker.temporal_threshold', 1.0)),
            'data_hash' => $dataIdentityHash,
            'execution_hash' => $executionHash,
        ]);
        $runKey = hash('sha256', json_encode([
            'protocol' => TemporalAblationProtocolService::PROTOCOL,
            'scoring_revision' => TemporalAblationProtocolService::SCORING_REVISION,
            'lab_id' => $lab->id,
            'source_generation_id' => $source?->id,
            'model_version_id' => $model?->id,
            'hypothesis_hash' => $hypothesisHash,
            'data_identity_hash' => $dataIdentityHash,
            'execution_hash' => $executionHash,
            'windows' => array_map(fn (array $window): array => [
                'window_id' => data_get($window, 'window_id'),
                'data_hash' => data_get($window, 'data_hash', data_get($window, 'sha256')),
                'execution_hash' => data_get($window, 'execution_hash'),
            ], $windows),
        ], JSON_UNESCAPED_SLASHES));
        $reasons = array_values(array_unique($validation['reason_codes']));
        $valid = $reasons === [];
        $decision = $valid ? self::PLANNED : RescueCircuitBreakerService::BLOCKED_NEED_NEW_EVIDENCE;
        $existing = LabTemporalAblationRun::query()
            ->where('run_key', $runKey)
            ->latest('id')
            ->first();
        if ($existing && (string) $existing->status !== 'planned') {
            // A completed clean ablation is evidence, not a reusable rescue
            // ticket. Replanning the same identity used to reset a failed
            // run back to `planned`, which allowed the same dataset and
            // hypothesis to loop indefinitely. A new run now requires a new
            // content identity, a new chronological window, or a genuinely
            // different hypothesis hash.
            $repeatReason = (string) $existing->status === 'running'
                ? 'TEMPORAL_ABLATION_ALREADY_RUNNING'
                : 'TEMPORAL_ABLATION_IDENTITY_ALREADY_EVALUATED';

            return [
                'run' => $existing->fresh(),
                'decision' => RescueCircuitBreakerService::BLOCKED_NEED_NEW_EVIDENCE,
                'allowed' => false,
                'contract' => $contract,
                'reason_codes' => [
                    $repeatReason,
                    'TEMPORAL_ABLATION_REQUIRES_NEW_DATASET_OR_HYPOTHESIS',
                ],
                'data_identity_hash' => $dataIdentityHash,
                'execution_hash' => $executionHash,
                'promotion_evidence' => false,
            ];
        }
        $row = LabTemporalAblationRun::updateOrCreate(
            ['run_key' => $runKey],
            [
                'ai_laboratory_id' => $lab->id,
                'lab_generation_id' => $source?->id,
                'model_version_id' => $model?->id,
                'symbol' => strtoupper((string) $lab->symbol),
                'timeframe' => strtoupper((string) $lab->timeframe),
                'protocol' => TemporalAblationProtocolService::PROTOCOL,
                'scoring_revision' => TemporalAblationProtocolService::SCORING_REVISION,
                'hypothesis_hash' => $hypothesisHash,
                'data_identity_hash' => $dataIdentityHash ?: null,
                'execution_hash' => $executionHash ?: null,
                'status' => $valid ? 'planned' : 'blocked',
                'decision' => $decision,
                'window_count' => count($windows),
                'variant_count' => count(TemporalAblationProtocolService::VARIANTS),
                'window_manifest' => [
                    'protocol' => self::MANIFEST_PROTOCOL,
                    'independent_holdout' => (bool) data_get($manifest, 'independent_holdout', data_get($manifest, 'independent_attestation', false)),
                    'independent_attestation' => (bool) data_get($manifest, 'independent_attestation', data_get($manifest, 'independent_holdout', false)),
                    'foundation_dataset' => (array) data_get($manifest, 'foundation_dataset', []),
                    'foundation_dataset_path' => data_get($manifest, 'foundation_dataset_path'),
                    'windows' => $windows,
                    'temporal_threshold' => data_get($contract, 'temporal_threshold'),
                    'variant_contract' => TemporalAblationProtocolService::VARIANTS,
                    'promotion_evidence' => false,
                ],
                'reason_codes' => $reasons,
                'mutation_credit_allowed' => false,
                'promotion_evidence' => false,
            ],
        );

        if (! $valid) $this->recordBlocked($row, $lab, $source, $contract, $reasons);

        return [
            'run' => $row->fresh(),
            'decision' => $decision,
            'allowed' => $valid,
            'contract' => $contract,
            'reason_codes' => $reasons,
            'data_identity_hash' => $dataIdentityHash,
            'execution_hash' => $executionHash,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function execute(LabTemporalAblationRun $run, ?ModelVersion $model = null): array
    {
        if ($run->status !== 'planned') {
            throw new RuntimeException('Temporal ablation is not executable: '.$run->status.'.');
        }
        $model ??= $run->modelVersion;
        if (! $model) throw new RuntimeException('Temporal ablation model version topilmadi.');
        $manifest = (array) $run->window_manifest;
        $windows = array_values((array) data_get($manifest, 'windows', []));
        if (count($windows) < 3) throw new RuntimeException('Temporal ablation uchun uchta window kerak.');

        $run->update(['status' => 'running']);
        $strategy = (string) $model->strategy;
        $family = (string) ($model->marketPerformances()->latest('id')->value('strategy_family') ?: $this->schemas->family($strategy));
        $baseStrategy = $this->schemas->runtimeBaseStrategy($strategy, data_get($model->metadata, 'base_strategy'), $family);
        $baseParameters = (array) ($model->parameters ?: $this->schemas->defaults($family));
        $baseParameters = $this->schemas->normalizeForGeneration($strategy, $this->schemas->clamp($strategy, $baseParameters));
        $variants = $this->variantParameters($strategy, $baseParameters);
        $observations = [];

        try {
            foreach ($windows as $window) {
                $windowId = (string) data_get($window, 'window_id');
                $execution = $this->executionContracts->for((string) $run->symbol, (string) $run->timeframe);
                $configs = [];
                foreach (TemporalAblationProtocolService::VARIANTS as $variant) {
                    $configs[] = [
                        'strategy' => $strategy,
                        'base_strategy' => $baseStrategy,
                        'version' => ($model->version ?: 'model')."__temporal_{$variant}",
                        'parameters' => $variants[$variant],
                    ];
                }
                $response = Http::connectTimeout(15)
                    ->timeout((int) config('services.ai_service.full_replay_timeout_seconds', 3600))
                    ->acceptJson()
                    ->withHeaders([
                        'X-Internal-Token' => (string) config('services.internal_api.token'),
                        'X-Lab-Request-Id' => 'temporal-ablation-'.$run->run_key.'-'.$windowId,
                    ])
                    ->post(rtrim((string) config('services.ai_service.url'), '/').'/api/backtest/run-all', [
                        'symbol' => $run->symbol,
                        'timeframe' => $run->timeframe,
                        'strategy' => $strategy,
                        'base_strategy' => $baseStrategy,
                        'evaluation_mode' => 'temporal_ablation',
                        'strategies' => $configs,
                        'initial_balance' => 10000,
                        'risk_per_trade' => 1,
                        'dataset_path' => data_get($window, 'dataset_path', data_get($window, 'path')),
                        'foundation_dataset_path' => data_get($manifest, 'foundation_dataset_path'),
                        'execution' => $execution['parameters'] ?? [],
                        'execution_contract' => $execution,
                        'policy_context' => [
                            'temporal_ablation' => [
                                'protocol' => TemporalAblationProtocolService::PROTOCOL,
                                'scoring_revision' => TemporalAblationProtocolService::SCORING_REVISION,
                                'run_key' => $run->run_key,
                                'window_id' => $windowId,
                                'paired_execution_identity' => data_get($window, 'execution_hash'),
                                'variant_contract' => TemporalAblationProtocolService::VARIANTS,
                                'promotion_evidence' => false,
                            ],
                            'snapshot_transport' => [
                                'protocol' => self::MANIFEST_PROTOCOL,
                                'dataset_path' => data_get($window, 'dataset_path', data_get($window, 'path')),
                                'dataset_sha256' => data_get($window, 'data_hash', data_get($window, 'sha256')),
                                'promotion_evidence' => false,
                            ],
                            'promotion_evidence' => false,
                        ],
                        // A 41k-candle x four-arm ablation uses compact event
                        // and trade digests. Full candle traces are opt-in
                        // diagnostics and can exhaust the bounded child
                        // before the paired window response returns.
                        'emit_decision_trace' => false,
                    ]);
                if ($response->failed()) throw new RuntimeException('Temporal window '.$windowId.' replay failed: '.$response->body());
                $leaderboard = (array) data_get($response->json(), 'leaderboard', []);
                $byVariant = [];
                foreach (TemporalAblationProtocolService::VARIANTS as $index => $variant) {
                    $item = $leaderboard[$index] ?? null;
                    if (is_array($item)) {
                        $itemVariant = (string) data_get($item, 'version', '');
                        foreach ($leaderboard as $candidate) {
                            if ((string) data_get($candidate, 'version', '') === ($model->version ?: 'model')."__temporal_{$variant}") {
                                $item = $candidate;
                                break;
                            }
                        }
                    }
                    $result = (array) data_get($item, 'result', []);
                    $metrics = $this->temporalMetrics($result);
                    $byVariant[$variant] = [
                        'temporal_score' => $this->temporalScore($result),
                        'score' => data_get($item, 'score'),
                        'forward_score' => data_get($item, 'forward_score'),
                        'data_hash' => data_get($window, 'data_hash', data_get($window, 'sha256')),
                        'execution_hash' => data_get($window, 'execution_hash'),
                        'parameter_hash' => hash('sha256', json_encode(
                            $variants[$variant],
                            JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES,
                        )),
                        'metrics' => $metrics,
                    ];
                }
                $observations[] = [
                    'window_id' => $windowId,
                    'chronological_order' => (int) data_get($window, 'chronological_order', count($observations) + 1),
                    'data_hash' => data_get($window, 'data_hash', data_get($window, 'sha256')),
                    'execution_hash' => data_get($window, 'execution_hash'),
                    'variants' => $byVariant,
                ];
            }
            $evaluation = $this->protocol->evaluate($observations, [
                'temporal_threshold' => data_get($manifest, 'temporal_threshold', 1.0),
                'data_hash' => $run->data_identity_hash,
                'execution_hash' => $run->execution_hash,
            ]);
            // Keep compact per-arm telemetry in the durable result. The raw
            // backtest response is intentionally not persisted; these fields
            // are enough to distinguish a real behavioral abstention from a
            // parameter mutation that had no observable effect.
            $evaluation['observations'] = $observations;
            $run->update([
                'status' => 'completed',
                'decision' => (string) $evaluation['status'],
                'results' => $evaluation,
                'reason_codes' => $evaluation['status'] === 'qualified' ? [] : ['TEMPORAL_ABLATION_NOT_QUALIFIED'],
                'mutation_credit_allowed' => false,
                'promotion_evidence' => false,
                'completed_at' => now(),
            ]);

            return ['run' => $run->fresh(), 'evaluation' => $evaluation, 'promotion_evidence' => false];
        } catch (\Throwable $exception) {
            $run->update([
                'status' => 'failed',
                'decision' => 'TEMPORAL_ABLATION_EXECUTION_FAILED',
                'reason_codes' => ['TEMPORAL_ABLATION_EXECUTION_FAILED'],
                'results' => ['error' => $exception->getMessage(), 'promotion_evidence' => false],
                'mutation_credit_allowed' => false,
                'promotion_evidence' => false,
                'completed_at' => now(),
            ]);
            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    private function validateManifest(AiLaboratory $lab, ?LabGeneration $source, array $manifest, array $windows): array
    {
        $reasons = [];
        $manifestProtocol = (string) data_get($manifest, 'protocol', self::MANIFEST_PROTOCOL);
        if ($manifestProtocol !== self::MANIFEST_PROTOCOL) {
            $reasons[] = 'TEMPORAL_ABLATION_MANIFEST_PROTOCOL_INVALID';
        }
        $independentAttestation = (bool) data_get($manifest, 'independent_attestation', data_get($manifest, 'independent_holdout', false));
        if (! $independentAttestation) {
            $reasons[] = 'TEMPORAL_ABLATION_INDEPENDENT_ATTESTATION_REQUIRED';
        }
        $foundationPath = (string) data_get($manifest, 'foundation_dataset_path', '');
        if ($foundationPath === '' || ! $this->pathInsideStorage($foundationPath)) {
            $reasons[] = 'TEMPORAL_ABLATION_FOUNDATION_DATASET_REQUIRED';
        }
        $foundation = (array) data_get($manifest, 'foundation_dataset', []);
        $foundationHash = trim((string) (
            data_get($foundation, 'content_hash')
            ?: data_get($foundation, 'sha256')
            ?: data_get($manifest, 'foundation_content_hash')
            ?: data_get($manifest, 'foundation_dataset_hash')
        ));
        if ($foundationHash === '') $reasons[] = 'TEMPORAL_ABLATION_FOUNDATION_CONTENT_HASH_REQUIRED';
        $foundationProvider = strtolower(trim((string) (
            data_get($foundation, 'source_provider')
            ?: data_get($foundation, 'source')
            ?: data_get($manifest, 'foundation_source_provider')
        )));
        $canonicalProvider = strtolower((string) config('services.market_data.canonical_provider', 'twelve'));
        if ($foundationProvider === '') {
            $reasons[] = 'TEMPORAL_ABLATION_FOUNDATION_SOURCE_REQUIRED';
        }
        if ($foundationProvider === 'historical_generation_snapshot'
            || $foundationProvider === $canonicalProvider
            || filled(data_get($foundation, 'source_archive_sha256'))
            || filled(data_get($foundation, 'reuse_protocol'))) {
            $reasons[] = 'TEMPORAL_ABLATION_INDEPENDENT_PROVIDER_REQUIRED';
        }
        if (! filled(data_get($foundation, 'timezone', data_get($manifest, 'foundation_timezone')))) {
            $reasons[] = 'TEMPORAL_ABLATION_FOUNDATION_TIMEZONE_REQUIRED';
        }
        $foundationCandles = data_get($foundation, 'new_candles', data_get($manifest, 'foundation_new_candles'));
        if (! is_numeric($foundationCandles) || (int) $foundationCandles < (int) config('services.rescue_circuit_breaker.foundation_minimum_candles', 72)) {
            $reasons[] = 'TEMPORAL_ABLATION_FOUNDATION_COVERAGE_INSUFFICIENT';
        }
        $foundationOverlap = data_get($foundation, 'overlap_ratio', data_get($manifest, 'foundation_overlap_ratio'));
        if (! is_numeric($foundationOverlap) || (float) $foundationOverlap > (float) config('services.rescue_circuit_breaker.foundation_max_overlap_ratio', 0.0)) {
            $reasons[] = 'TEMPORAL_ABLATION_FOUNDATION_OVERLAP_TOO_HIGH';
        }
        if ((int) data_get($foundation, 'overlap_candle_count', -1) !== 0
            || (int) data_get($foundation, 'attestation.accepted_rows_overlap_with_prior_generations', -1) !== 0
            || data_get($foundation, 'attestation.protocol') !== 'temporal_independent_source_attestation_v1') {
            $reasons[] = 'TEMPORAL_ABLATION_TIMESTAMP_INDEPENDENCE_UNPROVEN';
        }
        if (count($windows) !== 3) $reasons[] = 'TEMPORAL_ABLATION_WINDOWS_INCOMPLETE';
        $ids = [];
        $hashes = [];
        $ranges = [];
        $executionHashes = [];
        $roles = [];
        $windowRangeRows = [];
        $requiredRoles = ['development', 'validation', 'sealed_holdout'];
        foreach ($windows as $index => $window) {
            if (! is_array($window)) {
                $reasons[] = 'TEMPORAL_ABLATION_WINDOW_INVALID';
                continue;
            }
            $id = trim((string) data_get($window, 'window_id'));
            $dataHash = trim((string) data_get($window, 'data_hash', data_get($window, 'sha256')));
            $executionHash = trim((string) data_get($window, 'execution_hash'));
            $path = (string) data_get($window, 'dataset_path', data_get($window, 'path', ''));
            $role = trim((string) data_get($window, 'role', ''));
            if ($id === '' || in_array($id, $ids, true)) $reasons[] = 'TEMPORAL_ABLATION_WINDOW_ID_INVALID';
            if ($dataHash === '' || in_array($dataHash, $hashes, true)) $reasons[] = 'TEMPORAL_ABLATION_DATA_HASH_NOT_INDEPENDENT';
            if ($executionHash === '') $reasons[] = 'TEMPORAL_ABLATION_EXECUTION_HASH_MISSING';
            if (! (bool) data_get($window, 'independent_from_rescue', false)) $reasons[] = 'TEMPORAL_ABLATION_WINDOW_INDEPENDENCE_UNPROVEN';
            if (! in_array($role, $requiredRoles, true) || in_array($role, $roles, true)) $reasons[] = 'TEMPORAL_ABLATION_WINDOW_ROLE_INVALID';
            if (! (bool) data_get($window, 'sealed', false)) $reasons[] = 'TEMPORAL_ABLATION_WINDOW_NOT_SEALED';
            if (! is_numeric(data_get($window, 'purge_candles')) || ! is_numeric(data_get($window, 'embargo_candles'))) {
                $reasons[] = 'TEMPORAL_ABLATION_PURGE_EMBARGO_REQUIRED';
            }
            $windowOverlap = data_get($window, 'overlap_ratio');
            if (! is_numeric($windowOverlap) || (float) $windowOverlap > (float) config('services.rescue_circuit_breaker.foundation_max_overlap_ratio', 0.0)) {
                $reasons[] = 'TEMPORAL_ABLATION_WINDOW_OVERLAP_ATTESTATION_INVALID';
            }
            $windowCandles = data_get($window, 'candle_count', data_get($window, 'new_candles'));
            if (! is_numeric($windowCandles) || (int) $windowCandles < (int) config('services.rescue_circuit_breaker.window_minimum_candles', 24)) {
                $reasons[] = 'TEMPORAL_ABLATION_WINDOW_COVERAGE_INSUFFICIENT';
            }
            if ($path === '' || ! $this->pathInsideStorage($path)) {
                $reasons[] = 'TEMPORAL_ABLATION_DATASET_PATH_INVALID';
            } elseif ($dataHash !== '' && is_file($path) && ! hash_equals(strtolower($dataHash), strtolower((string) hash_file('sha256', $path)))) {
                $reasons[] = 'TEMPORAL_ABLATION_DATA_HASH_MISMATCH';
            }
            $start = $this->timestamp(data_get($window, 'first_candle_at', data_get($window, 'start')));
            $end = $this->timestamp(data_get($window, 'last_candle_at', data_get($window, 'end')));
            if (! $start || ! $end || $end->lessThanOrEqualTo($start)) {
                $reasons[] = 'TEMPORAL_ABLATION_WINDOW_RANGE_INVALID';
            } else {
                $ranges[] = [$start, $end, $index];
                $windowRangeRows[] = [
                    'start' => $start,
                    'end' => $end,
                    'purge' => (int) data_get($window, 'purge_candles', 0),
                    'embargo' => (int) data_get($window, 'embargo_candles', 0),
                    'index' => $index,
                ];
            }
            $ids[] = $id;
            $hashes[] = $dataHash;
            $executionHashes[] = $executionHash;
            $roles[] = $role;
        }
        if (array_diff($requiredRoles, $roles) !== []) $reasons[] = 'TEMPORAL_ABLATION_WINDOW_ROLES_INCOMPLETE';
        if ($roles !== $requiredRoles) $reasons[] = 'TEMPORAL_ABLATION_WINDOW_ROLE_ORDER_INVALID';
        usort($ranges, fn (array $left, array $right): int => $left[0] <=> $right[0]);
        foreach ($ranges as $index => $range) {
            if ($index > 0 && $range[0]->lessThanOrEqualTo($ranges[$index - 1][1])) {
                $reasons[] = 'TEMPORAL_ABLATION_WINDOWS_OVERLAP';
            }
        }
        usort($windowRangeRows, fn (array $left, array $right): int => $left['start'] <=> $right['start']);
        $intervalMinutes = strtoupper((string) $lab->timeframe) === 'M15' ? 15 : 60;
        foreach ($windowRangeRows as $index => $range) {
            if ($index === 0) continue;
            $previous = $windowRangeRows[$index - 1];
            $requiredGapMinutes = max((int) $previous['embargo'], (int) $range['purge']) * $intervalMinutes;
            $minimumNextStart = $previous['end']->addMinutes($requiredGapMinutes);
            if ($range['start']->lessThanOrEqualTo($minimumNextStart)) {
                $reasons[] = 'TEMPORAL_ABLATION_PURGE_EMBARGO_GAP_INVALID';
            }
        }
        $historyHashes = $this->rescueDataHashes($lab);
        if (array_intersect($hashes, $historyHashes) !== []) $reasons[] = 'TEMPORAL_ABLATION_REUSES_RESCUE_DATA';
        if ($foundationHash !== '' && in_array($foundationHash, $historyHashes, true)) {
            $reasons[] = 'TEMPORAL_ABLATION_FOUNDATION_REUSES_RESCUE_DATA';
        }
        if ($foundationPath !== '' && is_file($foundationPath) && $foundationHash !== ''
            && ! hash_equals(strtolower($foundationHash), strtolower((string) hash_file('sha256', $foundationPath)))) {
            $reasons[] = 'TEMPORAL_ABLATION_FOUNDATION_HASH_MISMATCH';
        }

        $rescueRanges = $this->rescueDataRanges($lab);
        if ($rescueRanges === []) {
            $reasons[] = 'TEMPORAL_ABLATION_RESCUE_RANGE_ATTESTATION_MISSING';
        } else {
            $foundationStart = $this->timestamp(data_get($foundation, 'coverage_start', data_get($foundation, 'first_candle_at')));
            $foundationEnd = $this->timestamp(data_get($foundation, 'coverage_end', data_get($foundation, 'last_candle_at')));
            if ($foundationStart && $foundationEnd && $this->rangesOverlap($foundationStart, $foundationEnd, $rescueRanges)) {
                $reasons[] = 'TEMPORAL_ABLATION_FOUNDATION_RESCUE_TIME_OVERLAP';
            }
            foreach ($windowRangeRows as $range) {
                if ($this->rangesOverlap($range['start'], $range['end'], $rescueRanges)) {
                    $reasons[] = 'TEMPORAL_ABLATION_WINDOW_RESCUE_TIME_OVERLAP';
                    break;
                }
            }
        }

        return [
            'reason_codes' => array_values(array_unique($reasons)),
            'data_identity_hash' => count($hashes) >= 3 ? hash('sha256', json_encode([
                'foundation' => $foundationHash,
                'windows' => $hashes,
            ], JSON_UNESCAPED_SLASHES)) : '',
            'execution_hash' => count($executionHashes) >= 3 ? hash('sha256', json_encode($executionHashes, JSON_UNESCAPED_SLASHES)) : '',
            'history_hashes' => $historyHashes,
            'foundation_hash' => $foundationHash,
            'foundation_candles' => is_numeric($foundationCandles) ? (int) $foundationCandles : null,
            'foundation_overlap_ratio' => is_numeric($foundationOverlap) ? (float) $foundationOverlap : null,
            'rescue_ranges' => array_map(fn (array $range): array => [
                'first_candle_at' => $range['first']->toIso8601String(),
                'last_candle_at' => $range['last']->toIso8601String(),
                'manifest' => $range['manifest'],
            ], $rescueRanges),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function variantParameters(string $strategy, array $base): array
    {
        // The control is an exact frozen copy. The two temporal variants are
        // structurally different hypotheses: signal expiry/half-life versus
        // market-state drift abstention. Neither mutates ROC/EMA/lookback.
        $expiry = $base;
        $expiry['temporal_survival_enabled'] = true;
        $expiry['adaptive_signal_expiry_enabled'] = true;
        $expiry['drift_abstention_enabled'] = false;
        $expiry['signal_max_age_candles'] = min(24, max(1, (int) ($expiry['signal_max_age_candles'] ?? 2)));
        $expiry['signal_decay_half_life_candles'] = min(24, max(1, (int) ($expiry['signal_decay_half_life_candles'] ?? 3)));
        $expiry['temporal_followthrough_window'] = min(12, max(1, (int) ($expiry['temporal_followthrough_window'] ?? 3)));

        $drift = $base;
        $drift['temporal_survival_enabled'] = true;
        $drift['adaptive_signal_expiry_enabled'] = false;
        $drift['drift_abstention_enabled'] = true;
        $drift['temporal_volatility_ratio_max'] = min(4.0, max(1.0, (float) ($drift['temporal_volatility_ratio_max'] ?? 2.5)));
        $drift['temporal_spread_atr_ratio_max'] = min(.5, max(.01, (float) ($drift['temporal_spread_atr_ratio_max'] ?? .25)));
        $drift['temporal_drift_zscore_max'] = min(5.0, max(.5, (float) ($drift['temporal_drift_zscore_max'] ?? 2.5)));
        $drift['temporal_loss_streak_limit'] = min(10, max(1, (int) ($drift['temporal_loss_streak_limit'] ?? 4)));

        $calibration = $base;
        $calibration['temporal_survival_enabled'] = false;
        $calibration['adaptive_signal_expiry_enabled'] = false;
        $calibration['drift_abstention_enabled'] = false;
        $calibration['confidence_calibration_enabled'] = true;
        $calibration['confidence_calibration_min_samples'] = min(200, max(15, (int) ($calibration['confidence_calibration_min_samples'] ?? 15) + 5));
        return [
            'control' => $base,
            'adaptive_expiry' => $this->schemas->normalizeForGeneration($strategy, $this->schemas->clamp($strategy, $expiry)),
            'drift_abstention' => $this->schemas->normalizeForGeneration($strategy, $this->schemas->clamp($strategy, $drift)),
            'calibration_only' => $this->schemas->normalizeForGeneration($strategy, $this->schemas->clamp($strategy, $calibration)),
        ];
    }

    private function sourceModel(?LabGeneration $source): ?ModelVersion
    {
        if (! $source) return null;
        return $source->agents()->with('modelVersion')->get()
            ->filter(fn ($agent): bool => $agent->modelVersion instanceof ModelVersion)
            ->sortByDesc(fn ($agent): float => (float) ($agent->profit_factor ?? $agent->validation_score ?? $agent->train_score ?? 0))
            ->first()?->modelVersion;
    }

    private function hypothesisHash(AiLaboratory $lab, ?LabGeneration $source, array $manifest): string
    {
        $profile = (array) data_get($source?->trigger_context, 'targeted_failure_profile', []);
        return (string) data_get($profile, 'hypothesis_hash') ?: hash('sha256', json_encode([
            'protocol' => TemporalAblationProtocolService::PROTOCOL,
            'scoring_revision' => TemporalAblationProtocolService::SCORING_REVISION,
            'symbol' => strtoupper((string) $lab->symbol),
            'timeframe' => strtoupper((string) $lab->timeframe),
            'target' => data_get($profile, 'failure_specific_lane', 'temporal_stability'),
            'manifest_hypothesis' => data_get($manifest, 'hypothesis'),
        ], JSON_UNESCAPED_SLASHES));
    }

    /** @return array<int, string> */
    private function rescueDataHashes(AiLaboratory $lab): array
    {
        $rescueGenerations = $lab->generations()->get()
            ->filter(fn (LabGeneration $generation): bool => filled(data_get($generation->trigger_context, 'targeted_failure_profile')))
            ->values();
        $generationIds = $rescueGenerations->pluck('id');
        $generationHashes = $rescueGenerations->pluck('data_fingerprint')
            ->merge($rescueGenerations->map(fn (LabGeneration $generation): mixed =>
                data_get($generation->trigger_context, 'canonical_dataset_snapshots.price.manifest.snapshot_sha256',
                    data_get($generation->trigger_context, 'canonical_dataset_snapshots.price.manifest.sha256'))
            ))
            ->filter()
            ->values();
        if ($generationIds->isEmpty()) return [];
        return $generationHashes->merge(LabEvaluationRun::query()->whereIn('lab_generation_id', $generationIds)
            ->whereIn('phase', ['screening', 'full_validation'])
            ->whereNotNull('data_hash')
            ->pluck('data_hash')
            ->filter()
            ->unique())
            ->values()
            ->all();
    }

    /** @return array<int, array{first: CarbonImmutable, last: CarbonImmutable, manifest: string}> */
    private function rescueDataRanges(AiLaboratory $lab): array
    {
        $symbol = strtoupper((string) $lab->symbol);
        $timeframe = strtoupper((string) $lab->timeframe);
        $targetedGenerations = $lab->generations()->get()->filter(
            fn (LabGeneration $generation): bool => is_array(data_get($generation->trigger_context, 'targeted_failure_profile'))
                && data_get($generation->trigger_context, 'targeted_failure_profile') !== [],
        );
        $ranges = [];
        foreach ($targetedGenerations as $generation) {
            $pattern = storage_path("app/lab-datasets/generations/G{$generation->generation}_id{$generation->id}_{$symbol}_{$timeframe}*.manifest.json");
            foreach (glob($pattern) ?: [] as $path) {
                $manifest = json_decode((string) file_get_contents($path), true);
                if (! is_array($manifest)) continue;
                $first = $this->timestamp(data_get($manifest, 'first_candle_at'));
                $last = $this->timestamp(data_get($manifest, 'last_candle_at'));
                if (! $first || ! $last || $last->lessThan($first)) continue;
                $key = $first->toIso8601String().'|'.$last->toIso8601String();
                $ranges[$key] = ['first' => $first, 'last' => $last, 'manifest' => $path];
            }
        }

        usort($ranges, fn (array $left, array $right): int => $left['first']->getTimestamp() <=> $right['first']->getTimestamp());

        return array_values($ranges);
    }

    /** @param array<int, array{first: CarbonImmutable, last: CarbonImmutable}> $ranges */
    private function rangesOverlap(CarbonImmutable $start, CarbonImmutable $end, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if ($start->lessThanOrEqualTo($range['last']) && $end->greaterThanOrEqualTo($range['first'])) return true;
        }

        return false;
    }

    private function pathInsideStorage(string $path): bool
    {
        $real = realpath($path);
        $root = realpath(storage_path('app'));
        if (! $real || ! $root) return false;
        $real = strtolower(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $real));
        $root = rtrim(strtolower(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $root)), DIRECTORY_SEPARATOR);
        return str_starts_with($real, $root.DIRECTORY_SEPARATOR);
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if (! filled($value)) return null;
        try { return CarbonImmutable::parse((string) $value)->utc(); } catch (\Throwable) { return null; }
    }

    private function temporalScore(array $result): ?float
    {
        foreach (['temporal_score', 'temporal_survival_score', 'worst_temporal_chunk_pf', 'worst_temporal_pf', 'worst_window_pf', 'worst_month_pf'] as $key) {
            $value = $this->findNumericKey($result, $key);
            if ($value !== null) return $value;
        }
        return null;
    }

    /** @return array<string, mixed> */
    private function temporalMetrics(array $result): array
    {
        $keys = [
            'temporal_score', 'temporal_survival_score', 'worst_temporal_chunk_pf',
            'worst_temporal_pf', 'worst_window_pf', 'worst_month_pf',
            'calendar_month_survival', 'temporal_survival', 'temporal_survival_rate',
            'total_trades', 'trade_count', 'max_drawdown_percent', 'max_drawdown',
        ];
        $metrics = [];
        foreach ($keys as $key) {
            $value = $this->findValueByKey($result, $key);
            if ($value !== null) $metrics[$key] = $value;
        }
        $survival = (array) data_get($result, 'temporal_survival', []);
        foreach ([
            'enabled', 'signal_count', 'abstention_count', 'followthrough_sample_count',
            'followthrough_rate', 'temporal_survival_score', 'drift_observations',
        ] as $key) {
            if (array_key_exists($key, $survival)) {
                $metrics['temporal_survival_'.$key] = $survival[$key];
            }
        }
        if (isset($survival['vetoes_by_reason']) && is_array($survival['vetoes_by_reason'])) {
            $metrics['temporal_survival_vetoes_by_reason'] = $survival['vetoes_by_reason'];
        }
        return $metrics;
    }

    private function findNumericKey(array $value, string $key): ?float
    {
        $found = $this->findValueByKey($value, $key);
        return is_numeric($found) ? (float) $found : null;
    }

    private function findValueByKey(array $value, string $key): mixed
    {
        if (array_key_exists($key, $value)) return $value[$key];
        foreach ($value as $child) {
            if (is_array($child)) {
                $found = $this->findValueByKey($child, $key);
                if ($found !== null) return $found;
            }
        }
        return null;
    }

    public function syncAuditEvent(LabTemporalAblationRun $run, ?AiLaboratory $lab = null, ?LabGeneration $source = null, ?array $contract = null): SystemEvent
    {
        $lab ??= $run->laboratory;
        $source ??= $run->generation;
        $contract ??= $this->protocol->contract([
            'required_independent_windows' => 3,
            'temporal_threshold' => (float) data_get($run->window_manifest, 'temporal_threshold', config('services.rescue_circuit_breaker.temporal_threshold', 1.0)),
            'data_hash' => $run->data_identity_hash,
            'execution_hash' => $run->execution_hash,
        ]);

        $superseded = $run->status === 'superseded';
        $nonQualification = in_array((string) $run->decision, [
            'failed',
            'TEMPORAL_ABLATION_NOT_QUALIFIED',
            'TEMPORAL_ABLATION_EXECUTION_FAILED',
        ], true);

        return SystemEvent::updateOrCreate(
            ['event_key' => 'temporal_ablation:'.$run->run_key],
            [
                'event_type' => $superseded
                    ? 'temporal_ablation_superseded'
                    : ($nonQualification ? 'temporal_ablation_not_qualified' : 'temporal_ablation_blocked'),
                'source_type' => self::class,
                'source_id' => $source?->id,
                'agent' => 'research',
                'symbol' => $lab?->symbol ?: $run->symbol,
                'timeframe' => $lab?->timeframe ?: $run->timeframe,
                'severity' => $superseded ? 'info' : 'warning',
                'summary' => ($superseded || $nonQualification)
                    ? (string) $run->decision
                    : RescueCircuitBreakerService::BLOCKED_NEED_NEW_EVIDENCE,
                'payload' => [
                    'run_id' => $run->id,
                    'run_key' => $run->run_key,
                    'status' => $run->status,
                    'decision' => $run->decision,
                    'reason_codes' => array_values((array) $run->reason_codes),
                    'contract' => $contract,
                    'payload_hash' => hash('sha256', json_encode([
                        'run_id' => $run->id,
                        'status' => $run->status,
                        'decision' => $run->decision,
                        'reason_codes' => array_values((array) $run->reason_codes),
                        'contract' => $contract,
                    ], JSON_UNESCAPED_SLASHES)),
                    'promotion_evidence' => false,
                ],
                'occurred_at' => now(),
            ],
        );
    }

    private function recordBlocked(LabTemporalAblationRun $run, AiLaboratory $lab, ?LabGeneration $source, array $contract, array $reasons): void
    {
        $this->syncAuditEvent($run, $lab, $source, $contract);
    }
}
