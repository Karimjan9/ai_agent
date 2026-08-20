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
        private DualTrackLaneCreditService $credits,
        private CouncilMemberCreditService $memberCredits,
        private TwinGenomeArchiveService $genomes,
        private OrganismHealthService $health,
        private TwinReflectionService $reflections,
        private DualTrackSettlementStateService $settlementStates,
        private DualTrackStatisticsService $statistics,
        private DualTrackDriftEngineService $drift,
        private DualTrackGeneProofService $geneProofs,
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
        $profit = is_numeric($outcome->profit_percent) ? (float) $outcome->profit_percent : null;
        $riskPercent = data_get($order->signal_context, 'risk.risk_percent', data_get($signal?->payload, 'dual_track.risk_percent'));
        $rows = [];
        foreach (['champion', 'council'] as $lane) {
            $projection = (array) data_get($dual, $lane, data_get($run, $lane.'_output', []));
            $decision = strtoupper((string) data_get($projection, 'decision', 'WAIT'));
            $executedDecision = strtoupper((string) $signal?->decision);
            $sameAction = in_array($decision, ['BUY', 'SELL'], true) && $decision === $executedDecision;
            $wait = $decision === 'WAIT';
            $known = $sameAction || $wait;
            $laneOutcome = $sameAction ? $actual : ($wait
                ? ($profit === null ? 'counterfactual_unknown' : ($profit > 0 ? 'missed_opportunity' : 'avoided_loss'))
                : 'counterfactual_unknown');
            $correct = $sameAction && $profit !== null ? ($profit > 0) : ($wait && $profit !== null ? $profit <= 0 : null);
            $regret = $profit === null ? null : ($wait ? abs($profit) : (($sameAction && $profit < 0) ? abs($profit) : 0.0));
            $rows[] = DualTrackOutcome::query()->updateOrCreate(
                ['outcome_key' => hash('sha256', self::PROTOCOL.'|'.$runKey.'|'.$lane.'|'.$outcome->id)],
                [
                    'dual_track_run_id' => $run->id, 'symbol' => $candidate->symbol, 'timeframe' => $candidate->timeframe,
                    'task_type' => $run->task_type, 'cell_key' => $run->cell_key, 'lane' => $lane, 'decision' => $decision,
                    'outcome_status' => $known ? 'settled' : 'counterfactual', 'actual_outcome' => $laneOutcome,
                    'reward' => $sameAction && $profit !== null ? $profit : ($regret === null ? null : -$regret), 'profit_percent' => $sameAction ? $profit : null,
                    'risk_percent' => is_numeric($riskPercent) ? (float) $riskPercent : null, 'regret' => $regret,
                    'confidence' => data_get($projection, 'confidence'), 'correct' => $correct,
                    'evidence' => ['protocol' => self::PROTOCOL, 'paper_signal_outcome_id' => $outcome->id, 'promotion_evidence' => false],
                    'metadata' => ['failure_signature' => $correct === false ? 'dual_track_lane_loss' : null, 'failure_class' => $correct === false ? ($actual ?: 'unknown_failure') : null, 'candidate_id' => $candidate->id, 'model_version_id' => $candidate->model_version_id, 'executed_decision' => $executedDecision, 'risk_evidence_missing' => ! is_numeric($riskPercent), 'promotion_evidence' => false],
                    'observed_at' => $signal?->created_at ?: now(), 'settled_at' => $known ? now() : null, 'promotion_evidence' => false,
                ],
            );
        }

        if (collect($rows)->where('outcome_status', 'settled')->isEmpty()) {
            return ['status' => 'counterfactual_only', 'run_key' => $runKey, 'outcomes' => [], 'promotion_evidence' => false];
        }

        $stateInfo = $this->settlementStates->begin($run, $outcome, $rows[0]);
        if ($stateInfo['already_completed']) return ['status' => 'already_settled', 'run_key' => $runKey, 'settlement_state' => ['id' => $stateInfo['state']?->id, 'stage' => 'completed'], 'promotion_evidence' => false];
        $state = $stateInfo['state'];
        $results = [];
        $policyResult = null;
        try {
            foreach ($rows as $row) {
                if ($row->outcome_status === 'settled') {
                    $results[] = [
                    'outcome_id' => $row->id,
                    'statistics' => $this->statistics->record($row),
                    'drift' => $this->drift->observe($row),
                    'policy' => $policyResult ??= $this->policies->update($row),
                    'memory' => $this->memory->settle($row),
                    'evolution' => $this->evolution->recordOutcome($row),
                    'credit' => $this->credits->record($row),
                    'member_credit' => $this->memberCredits->record($row),
                    'genome_archive' => $this->genomes->record($candidate, $row),
                    'health' => $this->health->record($row, $candidate),
                    'gene_proof' => $this->geneProofs->record($candidate),
                    'reflection' => $row->correct === false || in_array($row->actual_outcome, ['loss', 'missed_opportunity'], true)
                        ? $this->reflections->record($row) : ['status' => 'not_required', 'promotion_evidence' => false],
                    ];
                    $this->settlementStates->completeStage($state, 'lane_'.$row->lane, ['outcome_id' => $row->id]);
                }
            }
            $this->settlementStates->completeStage($state, 'calibration');
        } catch (\Throwable $error) {
            $this->settlementStates->fail($state, $error);
            throw $error;
        }
        $evaluator = (string) data_get($run->metadata, 'evaluator', 'dual_track_adjudicator');
        // In shadow mode selected_lane is intentionally "incumbent", which
        // is not one of the two organism lanes. Calibrating only the selected
        // lane would therefore produce zero samples forever. The Council is
        // the adjudicator lane; its confidence is calibrated independently
        // against the same immutable outcome, with Champion as a fallback.
        $calibrationRow = collect($rows)->first(fn (DualTrackOutcome $row): bool => $row->lane === 'council' && $row->correct !== null)
            ?: collect($rows)->first(fn (DualTrackOutcome $row): bool => $row->lane === 'champion' && $row->correct !== null);
        $correct = $calibrationRow?->correct;
        $probability = is_numeric($calibrationRow?->confidence) ? (float) $calibrationRow->confidence : (float) data_get($run->scores, 'council', 0) / 100;
        $calibration = $correct === null ? ['status' => 'not_observable', 'promotion_evidence' => false] : $this->evaluators->record($evaluator, $run->cell_key, $probability, (bool) $correct, ['run_key' => $runKey, 'calibration_lane' => $calibrationRow?->lane]);
        $this->settlementStates->complete($state, ['run_key' => $runKey, 'outcome_count' => count($results)]);
        return ['status' => 'settled', 'run_key' => $runKey, 'outcomes' => $results, 'calibration' => $calibration, 'settlement_state' => ['id' => $state?->id, 'stage' => 'completed'], 'promotion_evidence' => false];
    }
}
