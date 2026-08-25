<?php

namespace App\Services;

use App\Models\WinnerOnlyPyramidingLedgerEntry;
use Illuminate\Support\Facades\Schema;

/** Anti-martingale add-on permission; every add remains part of the parent sample. */
class WinnerOnlyPyramidingLedgerService
{
    public const PROTOCOL = 'winner_only_pyramiding_ledger_v1';

    /** @return array<string,mixed> */
    public function authorize(array $position, array $scope = []): array
    {
        $initial = max(0.0, (float) ($position['initial_risk_limit'] ?? 0));
        $before = max(0.0, (float) ($position['open_risk_before'] ?? $initial));
        $after = max(0.0, (float) ($position['open_risk_after'] ?? INF));
        $unrealized = (float) ($position['unrealized_r'] ?? 0);
        $protected = (bool) ($position['new_protected_structure'] ?? false);
        $allowed = $unrealized > 0 && $protected && $after <= $initial && (bool) ($position['funded_by_protected_risk'] ?? false);
        $state = $allowed ? 'shadow_authorized' : 'rejected';
        $result = ['protocol' => self::PROTOCOL, 'state' => $state, 'allowed' => $allowed, 'reasons' => $allowed ? [] : array_values(array_filter(['only_profitable_position' => $unrealized > 0, 'protected_structure_required' => $protected, 'worst_case_risk_capped' => $after <= $initial, 'must_be_financed_by_protected_risk' => (bool) ($position['funded_by_protected_risk'] ?? false)], fn ($v) => ! $v)), 'independent_learning_sample' => false, 'live_execution' => false, 'promotion_evidence' => false];
        if (Schema::hasTable('winner_only_pyramiding_ledger_entries')) {
            $positionKey = (string) ($position['position_key'] ?? hash('sha256', json_encode($position, JSON_UNESCAPED_SLASHES)));
            $key = hash('sha256', implode('|', [$positionKey, (string) ($position['add_number'] ?? 0)]));
            WinnerOnlyPyramidingLedgerEntry::query()->updateOrCreate(['ledger_key' => $key], ['position_key' => $positionKey, 'symbol' => strtoupper((string) ($scope['symbol'] ?? 'XAUUSD')), 'timeframe' => strtoupper((string) ($scope['timeframe'] ?? 'H1')), 'state' => $state, 'add_number' => (int) ($position['add_number'] ?? 0), 'open_risk_before' => $before, 'open_risk_after' => $after, 'initial_risk_limit' => $initial, 'unrealized_r' => $unrealized, 'contract' => $result, 'decided_at' => now()]);
        }
        return $result;
    }
}
