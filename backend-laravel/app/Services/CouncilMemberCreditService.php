<?php

namespace App\Services;

use App\Models\DualTrackMemberCredit;
use App\Models\DualTrackOutcome;
use Illuminate\Support\Facades\Schema;

/** Leave-one-out marginal credit for every declared Council member. */
class CouncilMemberCreditService
{
    public const PROTOCOL = 'council_member_counterfactual_credit_v1';

    public function __construct(private ?DualTrackEvidenceWorkItemService $workItems = null) {}

    /** @return array<string, mixed> */
    public function record(DualTrackOutcome $outcome): array
    {
        if (! Schema::hasTable('dual_track_member_credits')) return ['status' => 'unavailable', 'promotion_evidence' => false];
        $run = $outcome->run;
        $raw = data_get($run?->council_output, 'committee', data_get($run?->council_output, 'agents', []));
        $members = $this->members((array) $raw);
        if ($members === []) return ['status' => 'no_declared_members', 'promotion_evidence' => false];

        $fullReward = (float) app(LaneSpecificRewardService::class)->score($outcome)['reward'];
        $votes = collect($members)->mapWithKeys(fn (array $member, int|string $key): array => [
            $this->memberKey($member, $key) => strtoupper((string) ($member['decision'] ?? 'WAIT')),
        ]);
        $rows = [];
        foreach ($members as $key => $member) {
            $memberKey = $this->memberKey($member, $key);
            $remaining = $votes->except($memberKey)->values()->all();
            $ablatedDecision = $this->majority($remaining);
            $ablatedReward = $this->decisionReward($ablatedDecision, $outcome);
            $marginal = round($fullReward - $ablatedReward, 6);
            $creditKey = hash('sha256', self::PROTOCOL.'|'.$outcome->outcome_key.'|'.$memberKey);
            $rows[] = DualTrackMemberCredit::query()->updateOrCreate(
                ['credit_key' => $creditKey],
                [
                    'dual_track_run_id' => $outcome->dual_track_run_id, 'dual_track_outcome_id' => $outcome->id,
                    'symbol' => $outcome->symbol, 'timeframe' => $outcome->timeframe, 'cell_key' => $outcome->cell_key,
                    'member_key' => $memberKey, 'role' => $member['role'] ?? $member['schema'] ?? null,
                    'full_reward' => $fullReward, 'ablated_reward' => $ablatedReward, 'marginal_credit' => $marginal,
                    // The majority projection is only a proposal for replay.
                    // It is deliberately not causal credit until an
                    // independent ablation worker seals both executions.
                    'credit_type' => 'leave_one_out', 'status' => 'pending_ablation_replay',
                    'evidence' => [
                        'protocol' => self::PROTOCOL, 'full_decision' => $outcome->decision,
                        'ablated_decision' => $ablatedDecision, 'remaining_member_count' => count($remaining),
                        'causal_status' => 'heuristic_projection_only',
                        'promotion_evidence' => false,
                    ], 'promotion_evidence' => false,
                ],
            );
            if ($run !== null) {
                $this->workItems?->enqueue('council_ablation', $creditKey, $run, [
                    'credit_key' => $creditKey, 'member_key' => $memberKey,
                    'request' => data_get($run->metadata, 'twin_request', []),
                    'full_output' => $run->council_output,
                ], 7, $outcome);
            }
        }

        return ['status' => 'recorded', 'causal_status' => 'pending_ablation_replay', 'member_count' => count($rows), 'credits' => collect($rows)->pluck('marginal_credit', 'member_key')->all(), 'promotion_evidence' => false];
    }

    /** Seal true causal credit after a separate leave-one-out replay. */
    public function recordAblation(string $creditKey, array $replay): array
    {
        if (! Schema::hasTable('dual_track_member_credits')) return ['status' => 'unavailable', 'promotion_evidence' => false];
        $credit = DualTrackMemberCredit::query()->where('credit_key', $creditKey)->first();
        if (! $credit) return ['status' => 'missing', 'promotion_evidence' => false];
        $eligible = ($replay['independent_snapshot'] ?? false) === true
            && ($replay['holdout_replayed'] ?? false) === true
            && ($replay['lookahead_free'] ?? false) === true
            && is_numeric($replay['full_reward'] ?? null)
            && is_numeric($replay['ablated_reward'] ?? null)
            && trim((string) ($replay['full_output_hash'] ?? '')) !== ''
            && trim((string) ($replay['ablated_output_hash'] ?? '')) !== ''
            && ($replay['full_output_hash'] ?? '') !== ($replay['ablated_output_hash'] ?? '');
        if (! $eligible) {
            return ['status' => 'blocked', 'reason' => 'independent_ablation_evidence_required', 'promotion_evidence' => false];
        }
        $credit->update([
            'full_reward' => (float) $replay['full_reward'], 'ablated_reward' => (float) $replay['ablated_reward'],
            'marginal_credit' => round((float) $replay['full_reward'] - (float) $replay['ablated_reward'], 6),
            'status' => 'completed', 'evidence' => [...((array) $credit->evidence), 'causal_status' => 'independent_ablation_replay', 'replay' => $replay, 'promotion_evidence' => false],
        ]);
        return ['status' => 'completed', 'credit_id' => $credit->id, 'marginal_credit' => $credit->marginal_credit, 'promotion_evidence' => false];
    }

    /** @return array<int, array<string, mixed>> */
    private function members(array $raw): array
    {
        if (isset($raw['agents']) && is_array($raw['agents'])) $raw = $raw['agents'];
        return array_values(array_filter($raw, 'is_array'));
    }

    /** Stable identity keeps two members with the same agent label distinct. */
    private function memberKey(array $member, int|string $index): string
    {
        $declared = trim((string) ($member['member_key'] ?? ''));
        if ($declared !== '') return $declared;
        $agent = trim((string) ($member['agent'] ?? $member['role'] ?? $member['schema'] ?? 'member'));
        return $agent.'#'.$index;
    }

    /** @param array<int, string> $votes */
    private function majority(array $votes): string
    {
        $buy = count(array_filter($votes, fn (string $vote): bool => $vote === 'BUY'));
        $sell = count(array_filter($votes, fn (string $vote): bool => $vote === 'SELL'));
        return $buy >= 2 && $buy > $sell ? 'BUY' : ($sell >= 2 && $sell > $buy ? 'SELL' : 'WAIT');
    }

    private function decisionReward(string $decision, DualTrackOutcome $outcome): float
    {
        $actual = (string) $outcome->actual_outcome;
        $profit = abs((float) ($outcome->profit_percent ?? $outcome->regret ?? 1));
        if ($decision === 'WAIT') return match ($actual) { 'avoided_loss' => $profit, 'missed_opportunity' => -$profit, default => 0.0 };
        return match ($actual) { 'win' => $profit, 'loss' => -$profit, default => 0.0 };
    }
}
