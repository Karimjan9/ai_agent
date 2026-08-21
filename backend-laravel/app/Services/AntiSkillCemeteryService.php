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
        $key = hash('sha256', implode('|', [$symbol, $timeframe, $state, $strategy, $tactic, $mode]));
        $row = CapabilityAntiSkillCemetery::firstOrNew(['cemetery_key' => $key]);
        $failures = ((int) ($row->failures ?? 0)) + 1;
        $hardFailure = (bool) ($failure['hard_risk_violation'] ?? false);
        $status = $hardFailure || $failures >= 3 ? 'forbidden' : 'retry_with_new_hypothesis';
        $row->fill(['symbol' => $symbol, 'timeframe' => $timeframe, 'state_key' => $state, 'strategy_id' => $strategy ?: null, 'tactic_id' => $tactic ?: null, 'failure_mode' => $mode, 'status' => $status, 'failures' => $failures, 'evidence' => $failure, 'buried_at' => now()])->save();

        return ['status' => $status, 'cemetery_id' => $row->id, 'retry_requires_new_hypothesis' => $status !== 'forbidden', 'promotion_evidence' => false];
    }

    public function blocks(string $symbol, string $timeframe, string $stateKey, ?string $strategyId, ?string $tacticId): bool
    {
        return CapabilityAntiSkillCemetery::query()->where(['symbol' => strtoupper($symbol), 'timeframe' => strtoupper($timeframe), 'state_key' => $stateKey, 'status' => 'forbidden'])
            ->when($strategyId, fn ($q) => $q->where('strategy_id', $strategyId))
            ->when($tacticId, fn ($q) => $q->where('tactic_id', $tacticId))->exists();
    }
}
