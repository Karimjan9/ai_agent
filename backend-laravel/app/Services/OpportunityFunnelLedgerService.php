<?php

namespace App\Services;

use App\Models\OpportunityFunnelEntry;
use Illuminate\Support\Facades\Schema;

/** Keeps rejected opportunities as shadow observations instead of discarding them. */
class OpportunityFunnelLedgerService
{
    public const PROTOCOL = 'opportunity_funnel_ledger_v1';
    public const STAGES = ['setup_detected', 'regime_allowed', 'htf_direction_allowed', 'location_valid', 'session_valid', 'volatility_valid', 'news_clear', 'trigger_confirmed', 'spread_and_cost_valid', 'risk_approved', 'executed'];

    /** @return array<string,mixed> */
    public function contract(array $checks = []): array
    {
        $rows = []; $firstReject = null;
        foreach (self::STAGES as $stage) {
            $value = $checks[$stage] ?? ($stage === 'setup_detected');
            $passed = is_array($value) ? (bool) ($value['passed'] ?? false) : (bool) $value;
            $reason = is_array($value) ? ($value['rejected_reason'] ?? null) : null;
            if (! $passed && $firstReject === null) $firstReject = ['stage' => $stage, 'reason' => $reason ?? strtoupper($stage).'_REJECTED'];
            $rows[$stage] = ['passed' => $passed && $firstReject === null, 'rejected_reason' => $firstReject && $firstReject['stage'] === $stage ? $firstReject['reason'] : null];
        }
        $executed = (bool) data_get($rows, 'executed.passed', false);
        return ['protocol' => self::PROTOCOL, 'stages' => $rows, 'decision' => $executed ? 'EXECUTE' : 'WAIT', 'terminal_stage' => $executed ? 'executed' : ($firstReject['stage'] ?? 'setup_detected'), 'rejected_reason' => $firstReject['reason'] ?? null, 'shadow_required' => ! $executed, 'promotion_evidence' => false];
    }

    /** @return array<string,mixed> */
    public function record(array $payload): array
    {
        $contract = $this->contract((array) ($payload['checks'] ?? []));
        if (! Schema::hasTable('opportunity_funnel_entries')) return $contract;
        $requestedKey = trim((string) ($payload['opportunity_key'] ?? ''));
        $key = $requestedKey !== '' ? $requestedKey : hash('sha256', json_encode([$payload['symbol'] ?? 'XAUUSD', $payload['timeframe'] ?? 'H1', $payload['available_at'] ?? now()->toIso8601String(), $contract], JSON_UNESCAPED_SLASHES));
        $row = OpportunityFunnelEntry::query()->updateOrCreate(['opportunity_key' => $key], ['model_version_id' => $payload['model_version_id'] ?? null, 'symbol' => strtoupper((string) ($payload['symbol'] ?? 'XAUUSD')), 'timeframe' => strtoupper((string) ($payload['timeframe'] ?? 'H1')), 'composition_id' => $payload['composition_id'] ?? null, 'stage' => $contract['terminal_stage'], 'decision' => $contract['decision'], 'rejected_reason' => $contract['rejected_reason'], 'funnel' => $contract, 'evidence_snapshot' => (array) ($payload['evidence_snapshot'] ?? []), 'expected_value_before_filter' => $payload['expected_value_before_filter'] ?? null, 'expected_value_after_filter' => $payload['expected_value_after_filter'] ?? null, 'shadow_outcome' => $payload['shadow_outcome'] ?? null, 'available_at' => $payload['available_at'] ?? now(), 'decided_at' => $payload['decided_at'] ?? now()]);
        return [...$contract, 'opportunity_funnel_entry_id' => $row->id];
    }

    /** Record a conservative counterfactual for a rejected or executed decision. */
    public function settleShadowOutcome(string $opportunityKey, array $outcome): void
    {
        if (! Schema::hasTable('opportunity_funnel_entries')) return;
        $entry = OpportunityFunnelEntry::query()->where('opportunity_key', $opportunityKey)->first();
        if (! $entry) return;
        $entry->update(['shadow_outcome' => $outcome]);
        if ((string) $entry->decision === 'WAIT') app(LearningCompilerService::class)->compileWait($entry->toArray(), $outcome);
    }

    /** @return array<string,float|int> */
    public function metrics(string $symbol, string $timeframe): array
    {
        if (! Schema::hasTable('opportunity_funnel_entries')) return ['rejected' => 0, 'filter_precision' => 0, 'filter_regret' => 0, 'capture_rate' => 0];
        $rows = OpportunityFunnelEntry::query()->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->get();
        $rejected = $rows->where('decision', 'WAIT'); $executed = $rows->where('decision', 'EXECUTE');
        $harmfulRejected = $rejected->filter(fn ($row): bool => (float) data_get($row->shadow_outcome, 'net_r', 0) <= 0)->count();
        $beneficialRejected = $rejected->filter(fn ($row): bool => (float) data_get($row->shadow_outcome, 'net_r', 0) > 0)->count();
        $beneficialTotal = $beneficialRejected + $executed->filter(fn ($row): bool => (float) data_get($row->shadow_outcome, 'net_r', 0) > 0)->count();
        return ['rejected' => $rejected->count(), 'filter_precision' => $rejected->count() ? round($harmfulRejected / $rejected->count(), 6) : 0, 'filter_regret' => $rejected->count() ? round($beneficialRejected / $rejected->count(), 6) : 0, 'capture_rate' => $beneficialTotal ? round(($beneficialTotal - $beneficialRejected) / $beneficialTotal, 6) : 0];
    }
}
