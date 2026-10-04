<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use RuntimeException;

/** One frozen discovery window; it is never an independent validation window. */
class ProspectiveRepairProbeWindowService
{
    public const PROTOCOL = 'prospective_repair_probe_window_v1';

    public const EVALUATOR = 'incremental_probe_window_v2';

    public function seal(array $rows, string $datasetHash, string $executionHash, string $experimentKey,
        int $evaluationRows, int $warmupRows): array
    {
        $required = $evaluationRows + $warmupRows;
        if ($evaluationRows < 2 || $warmupRows < 0 || count($rows) !== $required
            || strlen($datasetHash) !== 64 || strlen($executionHash) !== 64 || $experimentKey === '') {
            throw new RuntimeException('PROSPECTIVE_PROBE_WINDOW_DATA_OR_IDENTITY_MISSING');
        }
        $times = array_map(fn (array $row): string => $this->utc((string) ($row['time'] ?? '')), $rows);
        foreach ($times as $index => $time) {
            if ($time >= '2026-01-01T00:00:00Z'
                || ($index > 0 && $time <= $times[$index - 1])) {
                throw new RuntimeException('PROSPECTIVE_PROBE_WINDOW_NOT_CHRONOLOGICAL_PRE_PAPER');
            }
        }
        $evaluated = array_slice($times, $warmupRows);
        $months = [];
        foreach ($evaluated as $time) {
            $month = substr($time, 0, 7);
            $months[$month] = ($months[$month] ?? 0) + 1;
        }
        $contract = [
            'protocol' => self::PROTOCOL,
            'evaluator_version' => self::EVALUATOR,
            'experiment_key' => $experimentKey,
            'dataset_hash' => $datasetHash,
            'execution_hash' => $executionHash,
            'loaded_rows' => $required,
            'warmup_rows' => $warmupRows,
            'evaluated_rows' => $evaluationRows,
            'loaded_start' => $times[0],
            'loaded_end' => $times[$required - 1],
            'evaluated_start' => $evaluated[0],
            'evaluated_end' => $evaluated[$evaluationRows - 1],
            'evaluated_month_counts' => $months,
            'independent_validation' => false,
            'paper_2026_eligible' => false,
        ];
        $contract['contract_hash'] = hash('sha256', json_encode($contract, JSON_UNESCAPED_SLASHES));

        return $contract;
    }

    public function attests(array $contract, array $receipt): bool
    {
        if (($contract['protocol'] ?? null) !== self::PROTOCOL
            || ($receipt['protocol'] ?? null) !== self::PROTOCOL
            || ($receipt['evaluator_version'] ?? null) !== self::EVALUATOR) {
            return false;
        }
        $unhashed = $contract;
        $hash = (string) ($unhashed['contract_hash'] ?? '');
        unset($unhashed['contract_hash']);
        if (strlen($hash) !== 64
            || ! hash_equals(hash('sha256', json_encode($unhashed, JSON_UNESCAPED_SLASHES)), $hash)) {
            return false;
        }
        foreach ($contract as $key => $value) {
            if (! array_key_exists($key, $receipt) || $receipt[$key] !== $value) {
                return false;
            }
        }

        return ($receipt['complete'] ?? false) === true;
    }

    private function utc(string $time): string
    {
        try {
            if ($time === '') throw new RuntimeException('empty');

            return CarbonImmutable::parse($time, 'UTC')->utc()->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable) {
            throw new RuntimeException('PROSPECTIVE_PROBE_WINDOW_TIMESTAMP_INVALID');
        }
    }
}
