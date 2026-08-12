<?php

namespace App\Console\Commands;

use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\LabGeneration;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabPopulationService;
use App\Services\LearningProtocolSafetyService;
use App\Services\OperatorApprovalService;
use App\Services\TargetedRescueProfileService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DispatchControlledTargetedRescueCohort extends Command
{
    protected $signature = 'trading:dispatch-controlled-targeted-rescue {symbol} {--timeframe=H1} {--source-generation=} {--apply : Create and dispatch one five-by-four rescue cohort} {--approved-by=} {--approval-reason=} {--json}';
    protected $description = 'Create one temporary 20-agent targeted rescue cohort while normal generation creation stays paused';

    public function handle(
        LabPopulationService $populations,
        LearningProtocolSafetyService $safety,
        TargetedRescueProfileService $profiles,
        LabImmutableEvidenceService $evidence,
        OperatorApprovalService $approvals,
    ): int {
        $symbol = strtoupper((string) $this->argument('symbol'));
        $timeframe = strtoupper((string) $this->option('timeframe'));
        if ($symbol !== LearningProtocolSafetyService::LIGHTHOUSE_SYMBOL || $timeframe !== LearningProtocolSafetyService::LIGHTHOUSE_TIMEFRAME) {
            return $this->failCommand('Controlled rescue faqat XAUUSD H1 lighthouse uchun ruxsat etiladi; boshqa lablar research/shadow rejimida.');
        }
        $lab = AiLaboratory::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->first();
        if (! $lab) return $this->failCommand("{$symbol} {$timeframe}: laboratory topilmadi.");

        $source = $lab->generations()->with('agents')->when(
            $this->option('source-generation') !== null,
            fn ($query) => $query->where('generation', (int) $this->option('source-generation')),
        )->latest('generation')->first();
        if (! $source) return $this->failCommand("{$symbol} {$timeframe}: source generation topilmadi.");
        if (! in_array((string) $source->status, ['screened', 'technical_quarantine'], true)) {
            return $this->failCommand("{$symbol} {$timeframe} G{$source->generation}: source status {$source->status}; rescue faqat terminal cohort uchun.");
        }
        if ($source->agents->contains(fn ($agent): bool => in_array((string) $agent->lifecycle_status, ['draft', 'queued', 'screening', 'training', 'evaluation_error', 'full_queued', 'full_validation'], true))) {
            return $this->failCommand("{$symbol} {$timeframe} G{$source->generation}: source cohort hali terminal emas.");
        }
        if ($this->labQueueHasJobs()) {
            return $this->failCommand("Lab queue bo'sh emas; rescue cohort backlog ustiga qo'shilmadi.");
        }
        if ($this->hasEvidenceCompleteScreenPass($source)) {
            return $this->failCommand("{$symbol} {$timeframe} G{$source->generation}: evidence-complete screening pass mavjud; targeted rescue ochilmadi.");
        }

        $profile = $profiles->forGeneration($source);
        if (! $safety->controlledRescueAllowed('candidate_handoff', 20, $profile)) {
            return $this->failCommand('Controlled rescue contract invalid; generation pause bypass qilinmadi.');
        }
        if ((int) data_get($profile, 'actionable_failure_count', 0) < 1) {
            return $this->failCommand("{$symbol} {$timeframe} G{$source->generation}: actionable screening failure yo'q; technical/legacy evidence rescue mutationga kiritilmadi.");
        }
        $alreadyAdmitted = $lab->generations()->get()->contains(function (LabGeneration $generation) use ($source, $profile): bool {
            return data_get($generation->trigger_context, 'controlled_rescue_admission.protocol') === LearningProtocolSafetyService::CONTROLLED_RESCUE_PROTOCOL
                && (int) data_get($generation->trigger_context, 'targeted_failure_profile.source_generation_id') === (int) $source->id
                && (string) data_get($generation->trigger_context, 'targeted_failure_profile.profile_hash') === (string) $profile['profile_hash'];
        });
        if ($alreadyAdmitted) {
            return $this->failCommand("{$symbol} {$timeframe} G{$source->generation}: shu source uchun controlled rescue allaqachon admitted.");
        }

        $summary = [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'source_generation_id' => (int) $source->id,
            'source_generation' => (int) $source->generation,
            'profile_hash' => $profile['profile_hash'],
            'population_size' => 20,
            'groups' => $profile['group_plan'],
            'reason_counts' => $profile['reason_counts'],
            'target_counts' => $profile['target_counts'],
            'temporary' => true,
            'promotion_evidence' => false,
        ];
        if (! $this->option('apply')) {
            return $this->report($summary + ['action' => 'dry_run']);
        }

        try {
            $approval = $approvals->requireForApply('dispatch-controlled-targeted-rescue', $this->option('approved-by'), $this->option('approval-reason'), $summary);
        } catch (RuntimeException $exception) {
            return $this->failCommand($exception->getMessage());
        }

        $generation = $populations->build(
            $symbol,
            'candidate_handoff',
            false,
            $timeframe,
            [],
            false,
            false,
            20,
            $profile,
            true,
        );
        if (! $generation) return $this->failCommand('Rescue generation safety/data gate sabab yaratilmadi.');

        $safety->recordControlledRescueAdmission([
            ...$summary,
            'target_generation_id' => (int) $generation->id,
            'target_generation' => (int) $generation->generation,
        ]);
        $dispatchExit = Artisan::call('trading:dispatch-lab', [
            'symbol' => $symbol,
            '--timeframe' => $timeframe,
            '--controlled-rescue' => true,
        ]);
        $dispatchOutput = trim(Artisan::output());
        $evidence->recordLifecycle(null, 'controlled_rescue_dispatched', [
            'protocol' => LearningProtocolSafetyService::CONTROLLED_RESCUE_PROTOCOL,
            'generation_id' => $generation->id,
            'dispatch_exit' => $dispatchExit,
            'dispatch_output' => substr($dispatchOutput, 0, 1000),
            'promotion_evidence' => false,
        ], 'screening', null, null, self::class);

        return $this->report($summary + [
            'action' => 'applied',
            'target_generation_id' => (int) $generation->id,
            'target_generation' => (int) $generation->generation,
            'dispatch_exit' => $dispatchExit,
            'dispatch_output' => $dispatchOutput,
            'operator_approval' => $approval,
        ]);
    }

    private function labQueueHasJobs(): bool
    {
        $queues = array_values(array_unique([
            (string) config('services.lab_queue.screening_queue', 'lab-screening'),
            (string) config('services.lab_queue.frontier_queue', 'lab-frontier'),
            (string) config('services.lab_queue.full_validation_queue', 'lab-full-validation'),
            ...((array) config('services.lab_queue.legacy_screening_queues', [])),
        ]));

        return DB::table('jobs')->whereIn('queue', $queues)->exists();
    }

    private function hasEvidenceCompleteScreenPass(LabGeneration $generation): bool
    {
        $evidence = app(LabImmutableEvidenceService::class);
        foreach ($generation->agents as $agent) {
            $decision = CandidateGateDecision::query()->where('lab_agent_id', $agent->id)
                ->where('stage', 'screening')->latest('id')->first();
            if (! $decision || $decision->decision !== 'passed') continue;
            $run = $agent->id
                ? \App\Models\LabEvaluationRun::query()->where('lab_agent_id', $agent->id)->where('phase', 'screening')->latest('id')->first()
                : null;
            if ($run && $evidence->learningEligibility($run)['complete']) return true;
        }

        return false;
    }

    private function report(array $payload): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(($payload['action'] ?? 'unknown').': '.($payload['symbol'] ?? '').' '.($payload['timeframe'] ?? '').' '.($payload['target_generation'] ?? '-'));
            $this->line('Five groups x four seats = '.($payload['population_size'] ?? 20).'; promotion evidence=false.');
            if (! empty($payload['dispatch_output'])) $this->line($payload['dispatch_output']);
        }

        return self::SUCCESS;
    }

    private function failCommand(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
