<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\LabTrialLedger;
use App\Models\ModelVersion;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * Persistent experiment-count evidence for DSR and ranking diagnostics.
 * Holdout releases are intentionally never written here.
 */
class LabTrialLedgerService
{
    public const PROTOCOL = 'trial_ledger_dsr_v1';

    public function record(
        ?LabAgent $agent,
        ?ModelVersion $model,
        string $symbol,
        string $timeframe,
        string $stage,
        array $result,
        ?string $runId = null,
    ): array {
        $parameters = (array) ($model?->parameters ?? []);
        $parameterHash = $this->hash($parameters);
        $run = $runId !== null
            ? LabEvaluationRun::query()->where('run_id', $runId)->first(['run_id', 'data_hash', 'request_meta'])
            : null;
        $dataHash = strtolower(trim($this->resolveDataHash($result, $run)));
        $executionHash = (string) data_get($result, 'execution_contract.execution_hash',
            data_get($result, 'execution_hash', ''));
        $executionHash = strtolower(trim($executionHash));
        $requiresExecutionContract = in_array(strtolower($stage), ['full', 'full_replay', 'full_validation', 'paper', 'holdout'], true);
        $executionContract = data_get($result, 'execution_contract');
        $executionValid = ! $requiresExecutionContract
            || (is_array($executionContract)
                && app(ExecutionContractService::class)->matches($executionContract, $symbol, $timeframe));
        $score = $this->score($result);
        $observedSharpe = $this->observedSharpe($result);

        // A recovery receives a new immutable run id, so run_id is not a
        // trial identity.  The canonical identity must include the exact
        // dataset and execution hashes.  Missing hashes fail closed rather
        // than falling back to run_id and creating a duplicate experiment.
        if (! $this->isSha256($dataHash)) {
            throw new RuntimeException('TRIAL_IDENTITY_DATASET_HASH_MISSING');
        }
        if ($executionHash !== '' && ! $this->isSha256($executionHash)) {
            throw new RuntimeException('TRIAL_IDENTITY_EXECUTION_HASH_INVALID');
        }
        $identityFingerprint = $this->identityFingerprint(
            $symbol,
            $timeframe,
            $stage,
            $parameterHash,
            $dataHash,
            $executionHash !== '' ? $executionHash : null,
        );
        $runLedger = $runId !== null
            ? LabTrialLedger::query()->where('run_id', $runId)->first()
            : null;
        if ($runLedger?->identity_status === 'canonical') {
            if ((string) $runLedger->identity_fingerprint !== $identityFingerprint) {
                throw new RuntimeException('TRIAL_IDENTITY_RUN_ID_COLLISION');
            }
            $ledger = $runLedger;
        } else {
            $ledger = LabTrialLedger::query()
                ->where('identity_fingerprint', $identityFingerprint)
                ->where('identity_status', 'canonical')
                ->first();
        }

        $values = [
            'lab_generation_id' => $agent?->lab_generation_id,
            'lab_agent_id' => $agent?->id,
            'model_version_id' => $model?->id,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'strategy_family' => $agent?->strategy_family,
            'stage' => $stage,
            'run_id' => $runId,
            'parameter_hash' => $parameterHash,
            'data_manifest_hash' => $dataHash,
            'execution_hash' => $executionHash !== '' ? $executionHash : null,
            'identity_fingerprint' => $identityFingerprint,
            'identity_status' => 'canonical',
            'score' => $score,
            'observed_sharpe' => $observedSharpe,
            'status' => $executionValid ? 'recorded' : 'invalid_execution_contract',
            'metrics' => [
                ...$this->boundedMetrics($result),
                'execution_contract_valid' => $executionValid,
                'promotion_evidence' => false,
            ],
            'evaluated_at' => now(),
        ];

        if (! $ledger) {
            try {
                $ledger = LabTrialLedger::updateOrCreate(
                    ['identity_fingerprint' => $identityFingerprint],
                    $values,
                );
            } catch (QueryException $exception) {
                // Two recovery attempts can pass the read before either row
                // is committed. The unique constraint is the race guard;
                // converge on its canonical row instead of withholding a
                // valid replay as a database/evaluator failure.
                if (! str_contains($exception->getMessage(), 'lab_trial_identity_fingerprint_unique')) {
                    throw $exception;
                }
                $ledger = LabTrialLedger::query()
                    ->where('identity_fingerprint', $identityFingerprint)
                    ->where('identity_status', 'canonical')
                    ->firstOrFail();
            }
        }

        if ($ledger->exists && $ledger->getKey() !== null) {
            $existingRunId = $ledger->run_id;
            $ledger->fill($values);
            // Keep the first run reference for a deduplicated canonical trial;
            // the immutable evaluation run table retains every retry.
            if ($existingRunId !== null && $existingRunId !== $runId) {
                $ledger->run_id = $existingRunId;
            }
            $ledger->save();
        }

        $scope = LabTrialLedger::query()
            ->where('symbol', $symbol)
            ->where('timeframe', $timeframe)
            ->where('identity_status', 'canonical');
        $recordedTrialCount = (clone $scope)->count();
        $evidence = $this->trialEvidence($symbol, $timeframe, $scope);
        $trialCount = $recordedTrialCount + (int) data_get($evidence, 'unrecorded_trial_count', 0);
        $trialIndex = (int) ($ledger->trial_index ?: $recordedTrialCount);
        if ($ledger->wasRecentlyCreated) $trialIndex = $recordedTrialCount;
        $penalty = $this->selectionPenalty($trialCount);
        $adjusted = $score === null ? null : round($score - $penalty, 4);
        $ledger->update([
            'trial_index' => $trialIndex,
            'trial_count' => $trialCount,
            'selection_penalty_points' => $penalty,
            'selection_adjusted_score' => $adjusted,
        ]);

        return [
            'protocol' => self::PROTOCOL,
            'trial_id' => $ledger->id,
            'stage' => $stage,
            'trial_index' => $trialIndex,
            'trial_count' => $trialCount,
            'prior_trial_count' => max(0, $trialCount - 1),
            'observed_sharpe' => $observedSharpe,
            'selection_penalty_points' => $penalty,
            'selection_adjusted_score' => $adjusted,
            'trial_count_by_outcome' => data_get($evidence, 'counts', []),
            'cross_family_trial_count' => data_get($evidence, 'cross_family_trial_count', 0),
            'parameter_hash' => $parameterHash,
            'data_manifest_hash' => $dataHash !== '' ? $dataHash : null,
            'execution_hash' => $executionHash !== '' ? $executionHash : null,
            'promotion_evidence' => false,
            'rule' => 'Every bounded screening/full replay attempt is counted; the penalty is ranking-only and never relaxes a gate.',
        ];
    }

    public function selectionContext(string $symbol, string $timeframe): array
    {
        $query = LabTrialLedger::query()
            ->where('symbol', $symbol)
            ->where('timeframe', $timeframe)
            ->where('identity_status', 'canonical');
        $recordedTrialCount = (clone $query)->count();
        $rows = (clone $query)->whereNotNull('observed_sharpe')->orderByDesc('id')->limit(1000)->get(['id', 'observed_sharpe']);
        $sharpes = $rows->map(fn (LabTrialLedger $row): float => (float) $row->observed_sharpe)->filter(fn (float $value): bool => is_finite($value))->values()->all();
        $evidence = $this->trialEvidence($symbol, $timeframe, $query);
        $trialCount = $recordedTrialCount + (int) data_get($evidence, 'unrecorded_trial_count', 0);

        return [
            'protocol' => self::PROTOCOL,
            'trial_count' => $trialCount,
            'recorded_trial_count' => $recordedTrialCount,
            'trial_sharpe_count' => count($sharpes),
            'trial_sharpes' => $sharpes,
            'context_hash' => hash('sha256', json_encode($rows->map(fn (LabTrialLedger $row): array => [$row->id, $row->observed_sharpe])->all(), JSON_PRESERVE_ZERO_FRACTION)),
            'trial_count_by_outcome' => data_get($evidence, 'counts', []),
            'trial_count_rule' => 'Recorded replays plus quarantined variants, technical errors, abandoned generations, legacy parameter-grid attempts and cross-family same-data trials are counted; holdout is excluded.',
            'cross_family_trial_count' => data_get($evidence, 'cross_family_trial_count', 0),
            'legacy_parameter_grid_trial_count' => data_get($evidence, 'counts.legacy_parameter_grid', 0),
            'abandoned_generation_trial_count' => data_get($evidence, 'counts.abandoned_generation', 0),
            'technical_error_trial_count' => data_get($evidence, 'counts.technical_error', 0),
            'quarantined_trial_count' => data_get($evidence, 'counts.quarantined', 0),
            'holdout_included' => false,
            'promotion_evidence' => false,
            'rule' => 'Prior replay trials inform DSR; the sealed/gold holdout is excluded.',
        ];
    }

    /**
     * Count attempts that never reached the normal ledger row. These are
     * selection trials too: excluding them would make DSR increasingly
     * optimistic precisely when the queue is unstable or a generation is
     * quarantined.
     *
     * @return array<string, mixed>
     */
    private function trialEvidence(string $symbol, string $timeframe, $ledgerQuery): array
    {
        $agents = LabAgent::query()
            ->where('symbol', $symbol)
            ->where('timeframe', $timeframe)
            ->get(['id', 'lab_generation_id', 'model_version_id', 'lifecycle_status']);
        $agentIds = $agents->pluck('id')->filter()->values();
        $ledgerAgentIds = (clone $ledgerQuery)->whereNotNull('lab_agent_id')->pluck('lab_agent_id')->map(fn ($id): int => (int) $id)->all();
        $ledgerAgentSet = array_fill_keys($ledgerAgentIds, true);

        $quarantineStatuses = ['quarantined', 'technical_quarantine', 'overfit', 'rejected', 'stagnated'];
        $quarantined = $agents->whereIn('lifecycle_status', $quarantineStatuses)->count();
        $unrecordedQuarantine = $agents
            ->whereIn('lifecycle_status', $quarantineStatuses)
            ->reject(fn (LabAgent $agent): bool => isset($ledgerAgentSet[(int) $agent->id]))
            ->count();

        $evaluationRuns = $agentIds->isEmpty()
            ? collect()
            : LabEvaluationRun::query()->whereIn('lab_agent_id', $agentIds)->get(['id', 'status', 'phase', 'lab_generation_id']);
        $technicalRunStatuses = ['failed', 'evaluation_error', 'technical_quarantine', 'abandoned'];
        $technicalErrors = $evaluationRuns->whereIn('status', ['failed', 'evaluation_error', 'technical_quarantine'])->count()
            + $agents->where('lifecycle_status', 'evaluation_error')->reject(fn (LabAgent $agent): bool => isset($ledgerAgentSet[(int) $agent->id]))->count();
        $generations = $agents->pluck('lab_generation_id')->filter()->isEmpty()
            ? collect()
            : LabGeneration::query()->whereIn('id', $agents->pluck('lab_generation_id')->filter())->get(['id', 'status']);
        $abandonedGenerationIds = $generations->where('status', 'abandoned')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $abandoned = $agents->whereIn('lab_generation_id', $abandonedGenerationIds)->count();
        $unrecordedAbandoned = $agents
            ->whereIn('lab_generation_id', $abandonedGenerationIds)
            ->reject(fn (LabAgent $agent): bool => isset($ledgerAgentSet[(int) $agent->id]))
            ->count();

        $models = $agents->pluck('model_version_id')->filter()->isEmpty()
            ? collect()
            : ModelVersion::query()->whereIn('id', $agents->pluck('model_version_id')->filter())->get(['id', 'metadata']);
        $legacyGrid = $models->filter(function (ModelVersion $model): bool {
            $metadata = (array) ($model->metadata ?? []);
            return data_get($metadata, 'legacy_parameter_grid') === true
                || data_get($metadata, 'parameter_grid') !== null
                || data_get($metadata, 'trial_origin') === 'parameter_grid';
        })->count() + (clone $ledgerQuery)->whereIn('stage', ['parameter_grid', 'legacy_grid', 'grid_search'])->count();
        $unrecordedLegacyGrid = $models->filter(function (ModelVersion $model): bool {
            $metadata = (array) ($model->metadata ?? []);
            return data_get($metadata, 'legacy_parameter_grid') === true
                || data_get($metadata, 'parameter_grid') !== null
                || data_get($metadata, 'trial_origin') === 'parameter_grid';
        })->reject(fn (ModelVersion $model): bool => in_array((int) $model->id, $agents->pluck('model_version_id')->map(fn ($id): int => (int) $id)->all(), true))->count();

        $crossFamily = (clone $ledgerQuery)->whereNotNull('strategy_family')->count();
        $unrecorded = $unrecordedQuarantine + $technicalErrors + $unrecordedAbandoned + $unrecordedLegacyGrid;

        return [
            'unrecorded_trial_count' => $unrecorded,
            'cross_family_trial_count' => $crossFamily,
            'counts' => [
                'recorded' => (clone $ledgerQuery)->count(),
                'quarantined' => $quarantined,
                'technical_error' => $technicalErrors,
                'abandoned_generation' => $abandoned,
                'legacy_parameter_grid' => $legacyGrid,
                'cross_family_same_data' => $crossFamily,
                'technical_run_statuses_observed' => $evaluationRuns->whereIn('status', $technicalRunStatuses)->count(),
            ],
        ];
    }

    private function score(array $result): ?float
    {
        foreach (['fitness_score', 'forward_score', 'score'] as $key) {
            if (is_numeric(data_get($result, $key))) return (float) data_get($result, $key);
        }
        return null;
    }

    private function observedSharpe(array $result): ?float
    {
        $observed = data_get($result, 'statistical_evidence.deflated_sharpe.observed_sharpe');
        if (is_numeric($observed) && is_finite((float) $observed)) return (float) $observed;
        $curve = array_values(array_filter((array) data_get($result, 'equity_curve', []), fn ($value): bool => is_numeric($value) && (float) $value > 0));
        $returns = [];
        foreach (array_slice($curve, 1) as $index => $current) {
            $previous = (float) $curve[$index];
            if ($previous > 0) $returns[] = ((float) $current / $previous) - 1;
        }
        if (count($returns) < 2) return null;
        $average = array_sum($returns) / count($returns);
        $variance = array_sum(array_map(fn (float $value): float => ($value - $average) ** 2, $returns)) / count($returns);
        $deviation = sqrt($variance);
        return $deviation > 0 && is_finite($average / $deviation) ? $average / $deviation : null;
    }

    private function selectionPenalty(int $trialCount): float
    {
        return round(min(25.0, 5.0 * log10(max(1, $trialCount))), 4);
    }

    private function boundedMetrics(array $result): array
    {
        return [
            'total_trades' => data_get($result, 'total_trades'),
            'profit_factor' => data_get($result, 'profit_factor'),
            'net_profit_percent' => data_get($result, 'net_profit_percent'),
            'max_drawdown_percent' => data_get($result, 'max_drawdown_percent', data_get($result, 'max_drawdown')),
            'is_overfit' => data_get($result, 'is_overfit'),
            'noise_sanity' => data_get($result, 'noise_sanity'),
            'execution_digital_twin' => data_get($result, 'execution_digital_twin'),
        ];
    }

    private function hash(array $value): string
    {
        $normalize = function ($item) use (&$normalize) {
            if (! is_array($item)) return $item;
            if (! array_is_list($item)) ksort($item);
            foreach ($item as $key => $child) $item[$key] = $normalize($child);
            return $item;
        };
        return hash('sha256', json_encode($normalize($value), JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));
    }

    private function resolveDataHash(array $result, ?LabEvaluationRun $run): string
    {
        $candidates = [
            data_get($result, 'data_manifest.sha256'),
            data_get($result, 'data_manifest.data_hash'),
            data_get($result, 'dataset_hash'),
            $run?->data_hash,
            data_get($run?->request_meta, 'dataset_manifest.snapshot_sha256'),
            data_get($run?->request_meta, 'dataset_manifest.data_hash'),
        ];

        foreach ($candidates as $candidate) {
            if ($this->isSha256((string) $candidate)) return (string) $candidate;
        }

        return '';
    }

    private function identityFingerprint(
        string $symbol,
        string $timeframe,
        string $stage,
        string $parameterHash,
        string $dataHash,
        ?string $executionHash,
    ): string {
        return $this->hash([
            'protocol' => 'lab_trial_identity_v2',
            'symbol' => strtoupper($symbol),
            'timeframe' => strtoupper($timeframe),
            'stage' => strtolower($stage),
            'parameter_hash' => $parameterHash,
            'data_manifest_hash' => $dataHash,
            'execution_hash' => $executionHash,
        ]);
    }

    private function isSha256(string $value): bool
    {
        return (bool) preg_match('/^[a-f0-9]{64}$/i', trim($value));
    }
}
