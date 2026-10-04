<?php

namespace App\Console\Commands;

use App\Models\LabGeneration;
use App\Models\ResearchLoopDecision;
use App\Services\AcademyExperimentMaterializerService;
use App\Services\AutonomousModeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/** Bounded child of the sole research arbiter, never an alternate scheduler. */
class AdmitAcademyExperiment extends Command
{
    protected $signature = 'trading:admit-academy-experiment {trial} {--research-loop-decision=} {--contain-preparation} {--finalize-preparation} {--generation=} {--checkpoint=} {--apply} {--approved-by=} {--approval-reason=}';

    protected $description = 'Prepare or canonically dispatch one sealed research-only Academy experiment.';

    public function handle(AcademyExperimentMaterializerService $materializer, AutonomousModeService $autonomy): int
    {
        if ($this->option('contain-preparation') || $this->option('finalize-preparation')) {
            if (($this->option('contain-preparation') && $this->option('finalize-preparation'))
                || (int) $this->option('generation') <= 0) {
                return $this->result(['status' => 'blocked', 'reason' => 'ACADEMY_PREPARATION_EXPLICIT_SINGLE_OPERATION_REQUIRED']);
            }
            $trialId = (int) $this->argument('trial');
            $generationId = (int) $this->option('generation');
            $finalize = (bool) $this->option('finalize-preparation');
            $preview = $materializer->containPreparation($trialId, $generationId,
                $this->option('checkpoint') ? (string) $this->option('checkpoint') : null, false, $finalize);
            if (! $this->option('apply') || ! in_array($preview['status'] ?? null,
                ['would_contain_preparation', 'would_finalize_containment'], true)) return $this->result($preview);
            try {
                $approval = app(\App\Services\OperatorApprovalService::class)->requireForApply('academy-preparation-containment',
                    $this->option('approved-by'), $this->option('approval-reason'),
                    ['trial_id' => $trialId, 'generation_id' => $generationId,
                        'operation' => $finalize ? 'finalize' : 'contain',
                        'fault' => AcademyExperimentMaterializerService::PREPARATION_FAULT]);
            } catch (\RuntimeException $error) {
                return $this->result(['status' => 'blocked', 'reason' => $error->getMessage()]);
            }
            return $this->result($materializer->containPreparation($trialId, $generationId,
                $this->option('checkpoint') ? (string) $this->option('checkpoint') : null, true, $finalize, $approval));
        }
        $decision = ResearchLoopDecision::query()->find((int) $this->option('research-loop-decision'));
        $trialId = (int) $this->argument('trial');
        if (! $decision || $decision->status !== 'running' || $decision->command !== 'trading:admit-academy-experiment'
            || (int) data_get($decision->arguments, 'trial', data_get($decision->arguments, '0')) !== $trialId
            || ! in_array($decision->action, ['OPEN_ACADEMY_EXPERIMENT', 'DISPATCH_ACADEMY_EXPERIMENT'], true)
            || ($decision->action === 'OPEN_ACADEMY_EXPERIMENT'
                && data_get($autonomy->status($decision->symbol, $decision->timeframe), 'state') === 'stopped')
            || in_array(data_get($autonomy->status($decision->symbol, $decision->timeframe), 'state'), ['paused', 'pausing', 'safety_halt'], true)) {
            return $this->result(['status' => 'blocked', 'reason' => 'ACADEMY_RUNNING_ARBITER_DECISION_REQUIRED']);
        }
        $proposal = (array) data_get($decision->evidence_snapshot, 'academy_proposal', []);
        if ((int) ($proposal['trial_id'] ?? 0) !== $trialId) {
            return $this->result(['status' => 'blocked', 'reason' => 'ACADEMY_PROPOSAL_IDENTITY_MISMATCH']);
        }
        $prepared = $trialId === 0 && $decision->action === 'OPEN_ACADEMY_EXPERIMENT'
            ? $materializer->prepareColdStart($decision)
            : $materializer->admit($trialId, (int) ($proposal['baseline_model_version_id'] ?? 0),
                (array) ($proposal['identity'] ?? []), $decision);
        if (($prepared['status'] ?? '') !== 'pending_canonical_admission') return $this->result($prepared);
        if ($decision->action === 'OPEN_ACADEMY_EXPERIMENT') {
            return $this->result([...$prepared, 'status' => 'prepared']);
        }
        $generation = LabGeneration::query()->with('laboratory')->find((int) $prepared['generation_id']);
        $latestId = $generation?->laboratory?->generations()->orderByDesc('generation')->orderByDesc('id')->value('id');
        if (! $generation || (int) $latestId !== $generation->id
            || (int) ($proposal['generation_id'] ?? 0) !== $generation->id) {
            return $this->result(['status' => 'blocked', 'reason' => 'ACADEMY_CANONICAL_GENERATION_OWNER_MISMATCH']);
        }
        // The durable intent survives any crash before this call. The normal
        // dispatcher owns dataset/MTF/release/snapshot sealing and queue admission.
        $exit = Artisan::call('trading:dispatch-lab', ['symbol' => $decision->symbol,
            '--timeframe' => $decision->timeframe, '--resume-draft-agents' => true]);
        if ($exit !== 0) return $this->result(['status' => 'deferred', 'reason' => 'ACADEMY_CANONICAL_DISPATCH_WITHHELD']);
        return $this->result($materializer->confirmCanonicalAdmission($trialId, $generation->id));
    }

    private function result(array $result): int
    {
        $this->line(json_encode([...$result, 'promotion_evidence' => false], JSON_UNESCAPED_SLASHES));
        return self::SUCCESS; // Typed refusal is not transport/strategy failure.
    }
}
