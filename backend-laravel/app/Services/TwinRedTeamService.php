<?php

namespace App\Services;

use App\Models\DualTrackRedTeamTrial;
use App\Models\DualTrackRun;
use Illuminate\Support\Facades\Schema;

/** Plans bounded adversarial trials; red-team output can never promote directly. */
class TwinRedTeamService
{
    public const PROTOCOL = 'twin_adversarial_red_team_v1';

    public function __construct(private ?DualTrackEvidenceWorkItemService $workItems = null) {}

    /** @return array<string, mixed> */
    public function plan(DualTrackRun $run): array
    {
        if (! Schema::hasTable('dual_track_red_team_trials')) {
            return ['status' => 'unavailable', 'promotion_evidence' => false];
        }
        $types = $run->selected_decision === 'WAIT'
            ? ['stale_feed', 'missing_candle', 'timestamp_shift', 'news_transition', 'low_liquidity', 'consecutive_losses', 'risk_sentinel_disagreement', 'council_member_removal']
            : ['stale_feed', 'missing_candle', 'duplicate_candle', 'timestamp_shift', 'h1_m15_mismatch', 'spread_2x', 'slippage_shock', 'volatility_explosion', 'low_liquidity', 'gap', 'sudden_trend_reversal', 'sudden_choch', 'delayed_execution', 'false_bos', 'failed_fibonacci_rejection', 'fvg_fill', 'stop_too_close', 'target_unreachable', 'partial_tp_failure', 'risk_sentinel_disagreement', 'missing_execution_hash', 'stale_skill', 'low_confidence_state', 'consecutive_losses', 'council_member_removal'];
        $trials = [];
        foreach ($types as $type) {
            $key = hash('sha256', self::PROTOCOL.'|'.$run->run_key.'|'.$type);
            $trial = DualTrackRedTeamTrial::query()->firstOrNew(['trial_key' => $key]);
            if (! $trial->exists) {
                $trial->fill([
                    'dual_track_run_id' => $run->id, 'symbol' => $run->symbol, 'timeframe' => $run->timeframe,
                    'cell_key' => $run->cell_key, 'target_lane' => $type === 'council_member_removal' ? 'council' : 'champion',
                    'adversary_type' => $type, 'status' => 'planned', 'challenge' => [
                        'protocol' => self::PROTOCOL, 'same_snapshot' => true, 'lookahead' => false,
                        'rule' => $this->rule($type), 'promotion_evidence' => false,
                    ], 'promotion_evidence' => false,
                ]);
                $trial->save();
            }
            $this->workItems?->enqueue('red_team', $key, $run, [
                'trial_key' => $key, 'adversary_type' => $type,
                'request' => data_get($run->metadata, 'twin_request', []),
                'baseline' => ['champion' => $run->champion_output, 'council' => $run->council_output],
            ], 8);
            $trials[] = ['id' => $trial->id, 'type' => $type, 'status' => $trial->status];
        }

        return ['status' => 'planned', 'trial_count' => count($trials), 'trials' => $trials, 'promotion_evidence' => false];
    }

    /** Complete a challenge only from an independent, sealed stress replay. */
    public function complete(string $trialKey, array $result): array
    {
        if (! Schema::hasTable('dual_track_red_team_trials')) {
            return ['status' => 'unavailable', 'promotion_evidence' => false];
        }
        $trial = DualTrackRedTeamTrial::query()->where('trial_key', $trialKey)->first();
        if (! $trial) {
            return ['status' => 'missing', 'promotion_evidence' => false];
        }
        $eligible = ($result['independent_snapshot'] ?? false) === true
            && ($result['holdout_replayed'] ?? false) === true
            && ($result['lookahead_free'] ?? false) === true;
        $damage = max(0.0, min(1.0, (float) ($result['damage_score'] ?? 1)));
        $trial->update([
            'status' => $eligible ? 'completed' : 'blocked', 'damage_score' => $damage,
            'result' => ['protocol' => self::PROTOCOL, ...$result, 'eligible' => $eligible, 'promotion_evidence' => false],
        ]);

        return ['status' => $trial->status, 'damage_score' => $damage, 'eligible' => $eligible, 'promotion_evidence' => false];
    }

    /**
     * Executable boundary for a stress replay worker. A planned trial can
     * never become completed from a verbal claim or a same-snapshot result.
     */
    public function execute(string $trialKey, array $replayEvidence = []): array
    {
        if (! Schema::hasTable('dual_track_red_team_trials')) {
            return ['status' => 'unavailable', 'promotion_evidence' => false];
        }
        $trial = DualTrackRedTeamTrial::query()->where('trial_key', $trialKey)->first();
        if (! $trial) {
            return ['status' => 'missing', 'promotion_evidence' => false];
        }
        if ($trial->status === 'completed') {
            return ['status' => 'already_completed', 'trial_id' => $trial->id, 'promotion_evidence' => false];
        }
        $required = ['independent_snapshot', 'holdout_replayed', 'lookahead_free', 'replay_hash', 'stress_snapshot_hash'];
        $missing = array_values(array_filter($required, fn (string $key): bool => empty($replayEvidence[$key])));
        if ($missing !== []) {
            $trial->update(['status' => 'blocked', 'result' => ['protocol' => self::PROTOCOL, 'missing' => $missing, 'reason' => 'sealed_replay_evidence_required', 'promotion_evidence' => false]]);

            return ['status' => 'blocked', 'missing' => $missing, 'trial_id' => $trial->id, 'promotion_evidence' => false];
        }

        return $this->complete($trialKey, $replayEvidence);
    }

    private function rule(string $type): string
    {
        return match ($type) {
            'regime_shift' => 'Replay the same decision under the next declared regime without changing the gene contract.',
            'cost_shock' => 'Increase spread and slippage within the sealed stress envelope.',
            'spread_2x' => 'Double spread while preserving the sealed candle and execution contract.',
            'slippage_shock' => 'Apply a bounded adverse fill shock without altering the signal candle.',
            'delayed_execution' => 'Apply deterministic signal delay without changing candle identity.',
            'false_bos' => 'Invert the declared BOS follow-through after the confirmed break.',
            'failed_fibonacci_rejection' => 'Continue through the Fibonacci rejection zone before the target.',
            'sudden_choch' => 'Inject a causal structure reversal after entry and measure abort latency.',
            'news_transition' => 'Apply the sealed news-risk transition and require the risk veto to remain active.',
            'low_liquidity' => 'Reduce liquidity quality and verify cost/risk gates do not widen exposure.',
            'stop_hunting' => 'Test an adverse wick through the local stop then a recovery branch.',
            'consecutive_losses' => 'Replay a bounded loss streak and verify cooldown and risk shrinking.',
            'stale_feed', 'missing_candle', 'duplicate_candle', 'timestamp_shift', 'h1_m15_mismatch' => 'Inject the declared data-integrity fault and require a safe abstention.',
            'volatility_explosion', 'gap', 'sudden_trend_reversal' => 'Inject bounded market shock and require reduce-only or safe close behavior.',
            'fvg_fill', 'stop_too_close', 'target_unreachable', 'partial_tp_failure' => 'Inject the declared tactic failure and record the safe state-machine response.',
            'risk_sentinel_disagreement', 'missing_execution_hash', 'stale_skill', 'low_confidence_state' => 'Inject governance disagreement or missing proof and require no promotion bypass.',
            'council_member_removal' => 'Run leave-one-out Council replay and record marginal decision damage.',
            default => 'Bounded adversarial replay.',
        };
    }
}
