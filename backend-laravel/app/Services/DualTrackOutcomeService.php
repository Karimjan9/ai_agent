<?php

namespace App\Services;

use App\Models\DualTrackOutcome;
use App\Models\DualTrackRun;
use App\Models\ModelMarketPerformance;
use App\Models\PaperOrder;
use App\Models\PaperSignalOutcome;
use Illuminate\Support\Facades\Schema;

/** Settles both lane observations against immutable paper outcomes. */
class DualTrackOutcomeService
{
    public const PROTOCOL = 'dual_track_outcome_settlement_v1';

    public function __construct(
        private DualTrackCellPolicyService $policies,
        private DualTrackMemoryService $memory,
        private DualTrackEvolutionService $evolution,
        private DualTrackEvaluatorCalibrationService $evaluators,
    ) {}

    /** @return array<string, mixed> */
    public function settlePaperOutcome(ModelMarketPerformance $candidate, PaperOrder $order, PaperSignalOutcome $outcome): array
    {
        if (! Schema::hasTable('dual_track_outcomes')) return ['status' => 'unavailable', 'promotion_evidence' => false];
        $signal = $order->paperSignal;
        $dual = (array) data_get($signal?->payload, 'dual_track', []);
        $runKey = (string) data_get($dual, 'run_key', data_get($signal?->payload, 'dual_track.run_key', ''));
        if ($runKey === '') return ['status' => 'not_dual_track', 'promotion_evidence' => false];
        $run = DualTrackRun::query()->where('run_key', $runKey)->first();
        if (! $run) return ['status' => 'run_missing', 'run_key' => $runKey, 'promotion_evidence' => false];

        $actual = (string) $outcome->outcome;
        $profit = (float) $outcome->profit_percent;
        $rows = [];
        foreach (['champion', 'council'] as $lane) {
            $projection = (array) data_get($dual, $lane, data_get($run, $lane.'_output', []));
            $decision = strtoupper((string) data_get($projection, 'decision', 'WAIT'));
            $executedDecision = strtoupper((string) $signal?->decision);
            $sameAction = in_array($decision, ['BUY', 'SELL'], true) && $decision === $executedDecision;
            $wait = $decision === 'WAIT';
            $known = $sameAction || $wait;
            $laneOutcome = $sameAction ? $actual : ($wait ? ($profit > 0 ? 'missed_opportunity' : 'avoided_loss') : 'counterfactual_unknown');
            $correct = $sameAction ? ($profit > 0) : ($wait ? $profit <= 0 : null);
            $regret = $wait ? abs($profit) : (($sameAction && $profit < 0) ? abs($profit) : 0.0);
            $rows[] = DualTrackOutcome::query()->updateOrCreate(
                ['outcome_key' => hash('sha256', self::PROTOCOL.'|'.$runKey.'|'.$lane.'|'.$outcome->id)],
                [
                    'dual_track_run_id' => $run->id, 'symbol' => $candidate->symbol, 'timeframe' => $candidate->timeframe,
                    'task_type' => $run->task_type, 'cell_key' => $run->cell_key, 'lane' => $lane, 'decision' => $decision,
                    'outcome_status' => $known ? 'settled' : 'counterfactual', 'actual_outcome' => $laneOutcome,
                    'reward' => $sameAction ? $profit : -$regret, 'profit_percent' => $sameAction ? $profit : null,
                    'risk_percent' => data_get($signal?->payload, 'dual_track.risk_percent'), 'regret' => $regret,
                    'confidence' => data_get($projection, 'confidence'), 'correct' => $correct,
                    'evidence' => ['protocol' => self::PROTOCOL, 'paper_signal_outcome_id' => $outcome->id, 'promotion_evidence' => false],
                    'metadata' => ['failure_signature' => $correct === false ? 'dual_track_lane_loss' : null, 'executed_decision' => $executedDecision, 'promotion_evidence' => false],
                    'observed_at' => $signal?->created_at ?: now(), 'settled_at' => $known ? now() : null, 'promotion_evidence' => false,
                ],
            );
        }

        $results = [];
        foreach ($rows as $row) {
            if ($row->outcome_status === 'settled') {
                $results[] = [
                    'outcome_id' => $row->id,
                    'policy' => $this->policies->update($row),
                    'memory' => $this->memory->settle($row),
                    'evolution' => $this->evolution->recordOutcome($row),
                ];
            }
        }
        $evaluator = (string) data_get($run->metadata, 'evaluator', 'dual_track_adjudicator');
        $correct = collect($rows)->first(fn (DualTrackOutcome $row): bool => $row->lane === $run->selected_lane && $row->correct !== null)?->correct;
        $calibration = $correct === null ? ['status' => 'not_observable', 'promotion_evidence' => false] : $this->evaluators->record($evaluator, $run->cell_key, (float) data_get($run->scores, 'council', 0) / 100, (bool) $correct, ['run_key' => $runKey]);
        return ['status' => 'settled', 'run_key' => $runKey, 'outcomes' => $results, 'calibration' => $calibration, 'promotion_evidence' => false];
    }
}
