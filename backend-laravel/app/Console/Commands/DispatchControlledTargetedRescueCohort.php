<?php

namespace App\Console\Commands;

use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\LabGeneration;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabPopulationService;
use App\Services\LearningProtocolSafetyService;
use App\Services\OperatorApprovalService;
use App\Services\RescueCircuitBreakerService;
use App\Services\TargetedRescueProfileService;
use App\Services\LabQueueJobInspector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DispatchControlledTargetedRescueCohort extends Command
{
    protected $signature = 'trading:dispatch-controlled-targeted-rescue {symbol} {--timeframe=H1} {--source-generation=} {--apply : Create and dispatch one audited targeted rescue cohort} {--approved-by=} {--approval-reason=} {--json}';
    protected $description = 'Create one temporary targeted rescue cohort while normal generation creation stays paused';

    public function handle(
        LabPopulationService $populations,
        LearningProtocolSafetyService $safety,
        TargetedRescueProfileService $profiles,
        LabImmutableEvidenceService $evidence,
        OperatorApprovalService $approvals,
        RescueCircuitBreakerService $rescueCircuitBreaker,
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
        if (! in_array((string) $source->status, ['screened', 'completed', 'technical_quarantine'], true)) {
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
        $populationSize = max(1, (int) data_get($profile, 'population_size', 20));
        $causalRepair = null;
        if ((int) $source->generation === 62) {
            $causalRepair = app(\App\Services\G62CausalContractService::class)->audit($source);
            if (! (bool) data_get($causalRepair, 'corrected_contract.allowed', false)) {
                return $this->failCommand('G62 causal contract repair is not valid; controlled rescue remains closed.');
            }
        }
        if (! $safety->controlledRescueAllowed('candidate_handoff', $populationSize, $profile)) {
            return $this->failCommand('Controlled rescue contract invalid; generation pause bypass qilinmadi.');
        }
        if ((int) data_get($profile, 'actionable_failure_count', 0) < 1) {
            return $this->failCommand("{$symbol} {$timeframe} G{$source->generation}: actionable screening failure yo'q; technical/legacy evidence rescue mutationga kiritilmadi.");
        }
        $independentEvidence = null;
        if (app(\App\Services\StructuralResearchCohortService::class)->isProfile($profile)) {
            $independentEvidence = $rescueCircuitBreaker->independentEvidenceAdmission(
                $lab,
                $source,
                $profile,
                $rescueCircuitBreaker->currentDataSnapshot($lab),
            );
            if (! (bool) data_get($independentEvidence, 'allowed', false)) {
                $rescueCircuitBreaker->recordBlocked($lab, $independentEvidence, $source);
                if ($this->option('json')) {
                    $this->line(json_encode([
                        'action' => 'blocked',
                        'symbol' => $symbol,
                        'timeframe' => $timeframe,
                        'source_generation' => $source->generation,
                        'cohort_mode' => $profile['cohort_mode'] ?? null,
                        'independent_evidence_admission' => $independentEvidence,
                        'promotion_evidence' => false,
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                }
                return $this->failCommand('Structural cohort uchun yangi non-overlap chronological evidence yoki sealed holdout hali tayyor emas.');
            }
        }
        $rescueAdmission = $rescueCircuitBreaker->admission($lab, $profile, $source);
        if (! (bool) data_get($rescueAdmission, 'allowed', false)) {
            $rescueCircuitBreaker->recordBlocked($lab, $rescueAdmission, $source);
            if ($this->option('json')) {
                $this->line(json_encode([
                    'action' => 'blocked',
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                    'source_generation' => $source->generation,
                    'rescue_admission' => $rescueAdmission,
                    'promotion_evidence' => false,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $this->error(RescueCircuitBreakerService::BLOCKED_NEED_NEW_EVIDENCE);
                $this->line((string) data_get($rescueAdmission, 'rule', 'New independent market evidence is required.'));
            }

            return self::FAILURE;
        }
        $priorRescues = $lab->generations()->get()->filter(function (LabGeneration $generation) use ($source, $profile): bool {
            return (int) data_get($generation->trigger_context, 'targeted_failure_profile.source_generation_id') === (int) $source->id
                && (string) data_get($generation->trigger_context, 'targeted_failure_profile.profile_hash') === (string) $profile['profile_hash'];
        });
        $alreadyAdmitted = $priorRescues->contains(function (LabGeneration $generation): bool {
            $context = (array) $generation->trigger_context;
            // A constructor-aborted cohort never reached the queue and is not
            // an admitted rescue. It may be retried once after the compiler
            // is repaired; a dispatched/admitted cohort remains idempotently
            // protected forever.
            return data_get($context, 'controlled_rescue_admission.protocol') === LearningProtocolSafetyService::CONTROLLED_RESCUE_PROTOCOL
                && ! data_get($context, 'controlled_rescue_constructor_abort.reason_code');
        });
        if ($alreadyAdmitted) {
            return $this->failCommand("{$symbol} {$timeframe} G{$source->generation}: shu source uchun controlled rescue allaqachon admitted.");
        }
        $abortedRescues = $priorRescues->filter(fn (LabGeneration $generation): bool => (string) data_get(
            $generation->trigger_context,
            'controlled_rescue_constructor_abort.reason_code',
        ) === 'INCOMPLETE_CONTROLLED_RESCUE_POPULATION');
        if ($abortedRescues->count() >= 2) {
            return $this->failCommand("{$symbol} {$timeframe} G{$source->generation}: constructor-aborted rescue retry budget exhausted.");
        }

        $summary = [
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'source_generation_id' => (int) $source->id,
            'source_generation' => (int) $source->generation,
            'profile_hash' => $profile['profile_hash'],
            'cohort_mode' => $profile['cohort_mode'] ?? null,
            'population_size' => $populationSize,
            'groups' => $profile['group_plan'],
            'reason_counts' => $profile['reason_counts'],
            'target_counts' => $profile['target_counts'],
            'temporal_mutation_hypothesis' => $profile['temporal_mutation_hypothesis'] ?? null,
            'temporal_edge_audit' => $profile['temporal_edge_audit'] ?? null,
            'structural_research_contract' => $profile['structural_research_contract'] ?? null,
            'g62_causal_contract_repair' => $causalRepair,
            'independent_evidence_admission' => $independentEvidence,
            'rescue_admission' => $rescueAdmission,
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
            $populationSize,
            $profile,
            true,
        );
        if (! $generation) return $this->failCommand('Rescue generation safety/data gate sabab yaratilmadi.');

        // A controlled rescue is valid only as the complete declared cohort.
        // A partial constructor result is technical evidence and must never
        // be dispatched as if it were a smaller strategy sample.
        $generation = $generation->fresh(['agents']);
        if ($generation->status !== 'draft' || $generation->agents->count() !== $populationSize) {
            return $this->failCommand(sprintf(
                'Controlled rescue constructor contract failed: G%s status=%s, agents=%d/%d. No replay dispatched.',
                $generation->generation,
                $generation->status,
                $generation->agents->count(),
                $populationSize,
            ));
        }
        $nonDraftAgents = $generation->agents->reject(fn ($agent): bool => $agent->lifecycle_status === 'draft');
        if ($nonDraftAgents->isNotEmpty()) {
            return $this->failCommand('Controlled rescue constructor contract failed: non-draft child present. No replay dispatched.');
        }

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
        $backlog = app(LabQueueJobInspector::class)->labQueueBacklog();

        return $backlog['total'] === null || $backlog['total'] > 0;
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
            $this->line('Declared rescue cohort seats = '.($payload['population_size'] ?? 20).'; promotion evidence=false.');
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
