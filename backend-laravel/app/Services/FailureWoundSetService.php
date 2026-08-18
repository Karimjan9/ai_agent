<?php

namespace App\Services;

use App\Models\AgentFailureCase;
use App\Models\LabAgent;

/**
 * Turns a failed screening target into a sealed, repeatable regression case.
 *
 * Wounds are learning evidence only. They never mint a parent, paper signal,
 * or promotion evidence. A later candidate must improve the same target on
 * the same data/execution identity before the mutation is credited.
 */
class FailureWoundSetService
{
    public const PROTOCOL = 'failure_wound_set_v1';

    /** @var array<string, array{key:string, direction:string, paths:array<int,string>}> */
    private const TARGETS = [
        'FAILED_TEMPORAL_CHUNK_SURVIVAL' => [
            'key' => 'temporal_chunk',
            'direction' => 'higher',
            'paths' => [
                'screening_survival.worst_temporal_chunk_pf',
                'screening_survival.worst_window_pf',
                'monthly_passport.worst_month_pf',
            ],
        ],
        'FAILED_CALENDAR_MONTH_SURVIVAL' => [
            'key' => 'calendar_month',
            'direction' => 'higher',
            'paths' => [
                'screening_survival.worst_calendar_month_pf',
                'window_survival.positive_windows',
                'monthly_passport.rolling_forward_wins',
                'rolling_forward_wins',
            ],
        ],
        'FAILED_MONTHLY_SURVIVAL' => [
            'key' => 'calendar_month',
            'direction' => 'higher',
            'paths' => [
                'screening_survival.worst_calendar_month_pf',
                'window_survival.positive_windows',
                'monthly_passport.rolling_forward_wins',
                'rolling_forward_wins',
            ],
        ],
        'FAILED_TRAIN_FORWARD_GAP' => [
            'key' => 'train_forward_gap',
            'direction' => 'lower',
            'paths' => ['screening_survival.train_forward_gap', 'train_forward_gap'],
        ],
        'FAILED_STRESS_COST' => [
            'key' => 'cost_exit_stress',
            'direction' => 'higher',
            'paths' => [
                'screening_survival.stress_cost_pf',
                'pf_attribution.stress_cost.profit_factor',
                'stress_test.profit_factor',
            ],
        ],
        'FAILED_EXECUTION_STRESS_GATE' => [
            'key' => 'cost_exit_stress',
            'direction' => 'higher',
            'paths' => [
                'screening_survival.stress_cost_pf',
                'pf_attribution.stress_cost.profit_factor',
                'stress_test.profit_factor',
            ],
        ],
    ];

    /** @return array<string, mixed> */
    public function evaluateForScreening(LabAgent $agent, array $result): array
    {
        $cases = AgentFailureCase::query()
            ->where('symbol', $agent->symbol)
            ->where('timeframe', $agent->timeframe)
            ->where('regression_status', 'open')
            ->where('failure_type', 'like', 'wound_%')
            ->get();

        $rows = [];
        foreach ($cases as $case) {
            // A retry of the source replay must not compare the wound against
            // itself. Siblings and later generations still must improve it.
            if ((int) $case->source_model_version_id > 0
                && (int) $case->source_model_version_id === (int) $agent->model_version_id) {
                continue;
            }

            $evidence = (array) $case->evidence;
            $baseline = data_get($evidence, 'baseline.value');
            $candidate = $this->metric($result, (string) data_get($evidence, 'target_key', ''));
            $sameData = $this->sameDataIdentity($result, (string) data_get($evidence, 'data_hash', ''));

            $status = match (true) {
                ! $sameData => 'not_assessed',
                ! is_numeric($baseline) || ! is_numeric($candidate) => 'not_assessed',
                $this->improved((float) $candidate, (float) $baseline, (string) data_get($evidence, 'direction', 'higher')) => 'improved',
                default => 'failed',
            };

            $rows[] = [
                'failure_case_id' => (int) $case->id,
                'failure_type' => (string) $case->failure_type,
                'target_key' => data_get($evidence, 'target_key'),
                'baseline' => is_numeric($baseline) ? (float) $baseline : null,
                'candidate' => is_numeric($candidate) ? (float) $candidate : null,
                'same_data' => $sameData,
                'status' => $status,
                'promotion_evidence' => false,
            ];
        }

        $blocking = collect($rows)->where('status', 'failed')->values()->all();
        $assessed = collect($rows)->whereIn('status', ['failed', 'improved'])->count();

        return [
            'protocol' => self::PROTOCOL,
            'status' => $rows === [] ? 'not_applicable' : ($blocking === [] ? 'passed' : 'failed'),
            'wound_count' => count($rows),
            'assessed_count' => $assessed,
            'blocking_failure_count' => count($blocking),
            'blocking_failures' => $blocking,
            'cases' => $rows,
            'promotion_evidence' => false,
            'rule' => 'A candidate receives wound progress only when its declared target improves on the sealed data and execution identity.',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function sealFromScreening(LabAgent $agent, array $result, array $reasonCodes): array
    {
        $dataHash = $this->dataHash($result, $agent);
        $resultHash = (string) data_get($result, 'result_hash', '');
        $rows = [];

        foreach (array_values(array_unique(array_map('strval', $reasonCodes))) as $reason) {
            $normalized = strtoupper(trim($reason));
            $normalized = preg_replace('/^FAILED_RESCUE_/', 'FAILED_', $normalized) ?: $normalized;
            $target = self::TARGETS[$normalized] ?? null;
            if ($target === null) continue;

            $targetKey = (string) $target['key'];
            $baselineValue = $this->firstNumeric($result, $target['paths']);
            $windowEvidence = $this->sealedWindowEvidence($result, $targetKey);
            $failureKey = hash('sha256', implode('|', [
                self::PROTOCOL,
                $agent->symbol,
                $agent->timeframe,
                $targetKey,
                $dataHash,
                (string) $agent->lab_generation_id,
            ]));

            $case = AgentFailureCase::firstOrCreate(
                ['failure_case_key' => $failureKey],
                [
                    'market_slice_hash' => $dataHash,
                    'symbol' => $agent->symbol,
                    'timeframe' => $agent->timeframe,
                    'regime' => (string) data_get($result, 'regime', data_get($result, 'dominant_regime', 'all')),
                    'failure_type' => 'wound_'.$targetKey,
                    'severity' => 'P1_QUALITY',
                    // This relational projection is VARCHAR(64). Keep the
                    // full instruction in immutable evidence below so a
                    // descriptive wound cannot turn a clean replay into
                    // SQLSTATE[22001].
                    'expected_safe_behavior' => $this->safeBehaviorCode($targetKey),
                    'expected_action' => 'targeted_mutation:'.$targetKey,
                    'discovered_by' => 'g44_wound_set',
                    'source_model_version_id' => $agent->model_version_id,
                    'regression_status' => 'open',
                    'discovered_at' => now(),
                    'evidence' => [
                        'protocol' => self::PROTOCOL,
                        'sealed_at' => now()->toIso8601String(),
                        'reason_code' => $normalized,
                        'target_key' => $targetKey,
                        'direction' => $target['direction'],
                        'expected_safe_behavior_description' => 'Improve sealed '.$targetKey.' evidence without non-target regression.',
                        'metric_paths' => $target['paths'],
                        'baseline' => [
                            'value' => $baselineValue,
                            'result_hash' => $resultHash !== '' ? $resultHash : $this->compactHash($result),
                        ],
                        'data_hash' => $dataHash,
                        'execution_hash' => (string) data_get($result, 'execution_contract.execution_hash', data_get($result, 'execution_hash', '')),
                        'sealed_windows' => $windowEvidence,
                        'promotion_evidence' => false,
                    ],
                ],
            );

            $rows[] = [
                'failure_case_id' => (int) $case->id,
                'failure_case_key' => $failureKey,
                'target_key' => $targetKey,
                'failure_type' => 'wound_'.$targetKey,
                'status' => $case->wasRecentlyCreated ? 'sealed' : 'already_sealed',
                'promotion_evidence' => false,
            ];
        }

        return $rows;
    }

    /** Keep the compact relational projection within agent_failure_cases limits. */
    private function safeBehaviorCode(string $targetKey): string
    {
        $normalized = strtoupper((string) preg_replace('/[^A-Z0-9]+/i', '_', $targetKey));

        return substr('IMPROVE_'.$normalized.'_NO_REGRESSION', 0, 64);
    }

    private function metric(array $result, string $targetKey): ?float
    {
        foreach (self::TARGETS as $target) {
            if ($target['key'] !== $targetKey) continue;
            return $this->firstNumeric($result, $target['paths']);
        }

        return null;
    }

    private function firstNumeric(array $result, array $paths): ?float
    {
        foreach ($paths as $path) {
            $value = data_get($result, $path);
            if (is_numeric($value)) return (float) $value;
        }

        return null;
    }

    private function improved(float $candidate, float $baseline, string $direction): bool
    {
        // Avoid claiming progress from floating-point noise in a gate metric.
        $epsilon = 0.000001;

        return $direction === 'lower'
            ? $candidate < ($baseline - $epsilon)
            : $candidate > ($baseline + $epsilon);
    }

    private function dataHash(array $result, LabAgent $agent): string
    {
        return (string) data_get(
            $result,
            'data_manifest.snapshot_sha256',
            data_get($result, 'data_manifest.sha256', data_get($result, 'dataset_hash', '')),
        ) ?: hash('sha256', implode('|', [$agent->symbol, $agent->timeframe, (string) $agent->lab_generation_id]));
    }

    private function sameDataIdentity(array $result, string $sealedHash): bool
    {
        if ($sealedHash === '') return false;
        $candidateHash = (string) data_get(
            $result,
            'data_manifest.snapshot_sha256',
            data_get($result, 'data_manifest.sha256', data_get($result, 'dataset_hash', '')),
        );

        return $candidateHash !== '' && hash_equals($sealedHash, $candidateHash);
    }

    /** @return array<string, mixed> */
    private function sealedWindowEvidence(array $result, string $targetKey): array
    {
        $paths = match ($targetKey) {
            'temporal_chunk' => ['screening_survival.temporal_chunk_survival', 'screening_survival.window_profit_factors', 'screening_survival.worst_temporal_chunk_pf'],
            'calendar_month' => ['window_survival', 'monthly_passport', 'screening_survival.calendar_month_survival'],
            'train_forward_gap' => ['screening_survival.train_forward_gap', 'train_forward_gap', 'split_manifest'],
            'cost_exit_stress' => ['screening_survival.stress_cost_pf', 'pf_attribution.stress_cost', 'stress_test'],
            default => [],
        };
        $compact = [];
        foreach ($paths as $path) {
            $value = data_get($result, $path);
            if ($value === null) continue;
            $compact[$path] = $this->compact($value);
        }

        return [
            'target_key' => $targetKey,
            'window_digest' => hash('sha256', json_encode($compact, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)),
            'observations' => $compact,
        ];
    }

    /** @return mixed */
    private function compact(mixed $value): mixed
    {
        if (! is_array($value)) return is_scalar($value) || $value === null ? $value : (string) $value;

        $result = [];
        foreach (array_slice($value, 0, 24, true) as $key => $item) {
            $result[(string) $key] = is_array($item) ? $this->compact($item) : $item;
        }

        return $result;
    }

    private function compactHash(array $value): string
    {
        return hash('sha256', json_encode($this->compact($value), JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));
    }
}
