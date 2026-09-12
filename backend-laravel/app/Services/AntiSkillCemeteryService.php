<?php

namespace App\Services;

use App\Models\CapabilityAntiSkillCemetery;

/** Keeps failed hypotheses from returning under a different label without new evidence. */
class AntiSkillCemeteryService
{
    public const PROTOCOL = 'capability_anti_skill_cemetery_v1';

    /** @return array<string,mixed> */
    public function bury(array $failure): array
    {
        $symbol = strtoupper((string) ($failure['symbol'] ?? 'UNKNOWN'));
        $timeframe = strtoupper((string) ($failure['timeframe'] ?? 'UNKNOWN'));
        $state = (string) ($failure['state_key'] ?? 'unknown');
        $strategy = (string) ($failure['strategy_id'] ?? '');
        $tactic = (string) ($failure['tactic_id'] ?? '');
        $mode = (string) ($failure['failure_mode'] ?? 'unclassified');
        $gene = (string) ($failure['gene'] ?? $failure['changed_gene'] ?? '');
        $direction = (string) ($failure['direction'] ?? $failure['mutation_direction'] ?? '');
        $baselineHash = (string) ($failure['baseline_hash'] ?? data_get($failure, 'causal_baseline.parameter_hash', ''));
        $key = hash('sha256', implode('|', [
            self::PROTOCOL, $symbol, $timeframe, $state, $strategy, $tactic,
            $mode, $gene, $direction, $baselineHash,
        ]));
        $row = CapabilityAntiSkillCemetery::firstOrNew(['cemetery_key' => $key]);
        $existingEvidence = (array) ($row->evidence ?? []);
        $observationKey = (string) ($failure['independent_window_key']
            ?? $failure['evidence_run_id']
            ?? $failure['pair_id']
            ?? hash('sha256', json_encode($failure, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)));
        $observationKeys = collect((array) data_get($existingEvidence, 'anti_skill_contract.observation_keys', []))
            ->push($observationKey)->filter()->unique()->sort()->values()->all();
        $failures = count($observationKeys);
        $hardFailure = (bool) ($failure['hard_risk_violation'] ?? false);
        $status = $hardFailure || $failures >= 2 ? 'forbidden' : 'retry_with_new_hypothesis';
        $row->fill(['symbol' => $symbol, 'timeframe' => $timeframe, 'state_key' => $state, 'strategy_id' => $strategy ?: null, 'tactic_id' => $tactic ?: null, 'failure_mode' => $mode, 'status' => $status, 'failures' => $failures, 'evidence' => [
            ...$failure,
            'anti_skill_contract' => [
                'protocol' => self::PROTOCOL,
                'fingerprint_axes' => ['family', 'context', 'failure', 'gene', 'direction', 'baseline_hash'],
                'gene' => $gene ?: null,
                'direction' => $direction ?: null,
                'baseline_hash' => $baselineHash ?: null,
                'observation_keys' => $observationKeys,
                'independent_failure_count' => $failures,
                'forbidden_threshold' => 2,
                'duplicate_observations_do_not_increment' => true,
                'promotion_evidence' => false,
            ],
        ], 'buried_at' => now()])->save();

        return ['status' => $status, 'cemetery_id' => $row->id,
            'independent_failure_count' => $failures,
            'retry_requires_new_hypothesis' => $status !== 'forbidden', 'promotion_evidence' => false];
    }

    public function blocks(string $symbol, string $timeframe, string $stateKey, ?string $strategyId, ?string $tacticId): bool
    {
        return CapabilityAntiSkillCemetery::query()->where(['symbol' => strtoupper($symbol), 'timeframe' => strtoupper($timeframe), 'state_key' => $stateKey, 'status' => 'forbidden'])
            ->when($strategyId, fn ($q) => $q->where('strategy_id', $strategyId))
            ->when($tacticId, fn ($q) => $q->where('tactic_id', $tacticId))->exists();
    }
}
