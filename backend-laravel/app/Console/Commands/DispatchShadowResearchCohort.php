<?php

namespace App\Console\Commands;

use App\Models\AiLaboratory;
use App\Models\LabGeneration;
use App\Services\LabPopulationService;
use App\Services\LabQueueJobInspector;
use App\Services\LearningProtocolSafetyService;
use App\Services\OperatorApprovalService;
use App\Services\ReplayResourceAdmissionService;
use App\Services\ShadowResearchGovernorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * Explicit, bounded entrypoint for the research-only escape lane. Dry-run is
 * the default; apply requires an empty lab queue and operator approval.
 */
class DispatchShadowResearchCohort extends Command
{
    protected $signature = 'trading:dispatch-shadow-research {symbol=XAUUSD} {--timeframe=H1} {--source-generation=} {--apply} {--micro-probe} {--approved-by=} {--approval-reason=} {--json}';

    protected $description = 'Assess and optionally dispatch one research-only shadow escape cohort';

    public function handle(
        LabPopulationService $populations,
        ShadowResearchGovernorService $governor,
        LabQueueJobInspector $queue,
        OperatorApprovalService $approvals,
        ReplayResourceAdmissionService $resources,
    ): int {
        $symbol = strtoupper((string) $this->argument('symbol'));
        $timeframe = strtoupper((string) $this->option('timeframe'));
        if ($symbol !== LearningProtocolSafetyService::LIGHTHOUSE_SYMBOL
            || $timeframe !== LearningProtocolSafetyService::LIGHTHOUSE_TIMEFRAME) {
            return $this->failCommand('Shadow escape lane faqat XAUUSD H1 lighthouse uchun ruxsat etiladi.');
        }

        $lab = AiLaboratory::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->first();
        if (! $lab) return $this->failCommand("{$symbol} {$timeframe}: laboratory topilmadi.");
        $latest = $lab->generations()->latest('generation')->first();
        $source = $lab->generations()->when(
            $this->option('source-generation') !== null,
            fn ($query) => $query->where('generation', (int) $this->option('source-generation')),
        )->when(
            $this->option('source-generation') === null && $this->isConstructorAbortedShadow($latest),
            fn ($query) => $query->where('generation', '<', (int) $latest->generation),
        )->latest('generation')->first();
        if (! $source) return $this->failCommand('Shadow source generation topilmadi.');
        // A failed shadow constructor is immutable technical history, not a
        // usable source cohort. Allow one retry from its previous terminal
        // source after RepairLabIntegrity quarantines the partial row.
        if ($latest && (int) $latest->id !== (int) $source->id && ! $this->isConstructorAbortedShadow($latest)) {
            return $this->failCommand("Source G{$source->generation} latest emas; yangi evidence oqimi ustiga shadow cohort qo'shilmaydi.");
        }
        if (! in_array((string) $source->status, LabPopulationService::TERMINAL_GENERATION_STATUSES, true)) {
            return $this->failCommand("G{$source->generation} terminal emas ({$source->status}).");
        }
        $microProbe = (bool) $this->option('micro-probe');
        $queueSnapshot = $queue->queueSnapshot();
        if ($queue->hasLabJobs() && ! $microProbe) {
            return $this->failCommand('Lab queue bo\'sh emas; shadow cohort backlog ustiga qo\'shilmadi.');
        }
        $resourceAssessment = $microProbe ? $resources->assess($queueSnapshot) : [
            'protocol' => ReplayResourceAdmissionService::PROTOCOL,
            'allowed' => true,
            'mode' => 'ordinary_shadow_queue_idle',
            'promotion_evidence' => false,
        ];
        if ($microProbe && ! (bool) data_get($resourceAssessment, 'allowed', false)) {
            return $this->report([
                'action' => 'blocked',
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'source_generation' => (int) $source->generation,
                'micro_probe' => true,
                'resource_assessment' => $resourceAssessment,
                'promotion_evidence' => false,
            ], self::FAILURE);
        }

        $targetedRescueBlocked = app(\App\Services\RescueCircuitBreakerService::class)->blockedForLab($lab);
        $assessment = $governor->assess($source, $targetedRescueBlocked);
        if (! (bool) data_get($assessment, 'allowed', false)) {
            return $this->report([
                'action' => 'blocked',
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'source_generation' => (int) $source->generation,
                'assessment' => $assessment,
                'promotion_evidence' => false,
            ], self::FAILURE);
        }

        $already = $lab->generations()->where('trigger_type', 'shadow_research')->get()->first(
            fn (LabGeneration $generation): bool => (int) data_get($generation->trigger_context, 'previous_generation') === (int) $source->generation
                && ! $this->isConstructorAbortedShadow($generation)
        );
        if ($already) return $this->failCommand("G{$source->generation}: shadow cohort allaqachon mavjud (G{$already->generation}).");

        $population = max(20, min(20, (int) config('services.lab_selection.population_size', 20)));
        $summary = [
            'protocol' => ShadowResearchGovernorService::PROTOCOL,
            'allocation' => $governor->allocation($population, $targetedRescueBlocked),
            'assessment' => $assessment,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'source_generation_id' => (int) $source->id,
            'source_generation' => (int) $source->generation,
            'population_size' => $population,
            'queue' => $queue->queueSnapshot(),
            'micro_probe' => $microProbe,
            'resource_assessment' => $resourceAssessment,
            'promotion_evidence' => false,
            'parent_promotion' => false,
            'official_paper_eligible' => false,
        ];
        if (! (bool) $this->option('apply')) return $this->report($summary + ['action' => 'dry_run']);

        try {
            $approval = $approvals->requireForApply('dispatch-shadow-research', $this->option('approved-by'), $this->option('approval-reason'), $summary);
        } catch (RuntimeException $exception) {
            return $this->failCommand($exception->getMessage());
        }

        // A non-null populationLimit selects the bounded root-recovery plan
        // inside LabPopulationService. Shadow research needs the configured
        // full search budget so its smart-courage allocation can distribute
        // all twenty seats across architecture, robustness, regime and
        // adversarial lanes. The command already records the requested
        // population in its audit summary; leave plan sizing to the normal
        // shadow trigger path.
        $generation = $populations->build($symbol, 'shadow_research', false, $timeframe, [], false, false, null);
        if (! $generation) return $this->failCommand('Shadow cohort safety gate sabab yaratilmadi.');
        $generation = $generation->fresh(['agents']);
        if ($generation->status !== 'draft' || $generation->agents->count() !== $population) {
            return $this->failCommand(sprintf('Shadow constructor contract failed: G%s %s, agents %d/%d.', $generation->generation, $generation->status, $generation->agents->count(), $population));
        }

        if ($microProbe) {
            $context = (array) $generation->trigger_context;
            $context['shadow_micro_probe'] = [
                'protocol' => ReplayResourceAdmissionService::PROTOCOL,
                'max_rows' => (int) config('services.ai_service.shadow_micro_probe_max_rows', 512),
                'max_candidates' => (int) config('services.ai_service.shadow_micro_probe_max_candidates', 6),
                'trace' => false,
                'promotion_evidence' => false,
            ];
            $generation->update(['trigger_context' => $context]);
        }

        $exit = Artisan::call('trading:dispatch-lab', [
            'symbol' => $symbol,
            '--timeframe' => $timeframe,
            '--shadow-research' => true,
        ]);

        return $this->report($summary + [
            'action' => 'applied',
            'target_generation_id' => (int) $generation->id,
            'target_generation' => (int) $generation->generation,
            'dispatch_exit' => $exit,
            'operator_approval' => $approval,
        ]);
    }

    private function report(array $payload, int $exit = self::SUCCESS): int
    {
        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ((bool) $this->option('json')) $this->line((string) $encoded);
        elseif (($payload['action'] ?? '') === 'blocked') $this->error((string) data_get($payload, 'assessment.next_action', 'Shadow lane blocked.'));
        else $this->info(sprintf('Shadow research %s: %s', (string) ($payload['action'] ?? 'reported'), (string) ($payload['source_generation'] ?? 'n/a')));

        return $exit;
    }

    private function failCommand(string $message): int
    {
        if ((bool) $this->option('json')) $this->line(json_encode(['action' => 'blocked', 'message' => $message, 'promotion_evidence' => false], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        else $this->error($message);

        return self::FAILURE;
    }

    private function isConstructorAbortedShadow(?LabGeneration $generation): bool
    {
        if (! $generation || (string) $generation->trigger_type !== 'shadow_research') return false;
        if ((string) data_get($generation->trigger_context, 'shadow_research_constructor_abort.reason_code') === 'INCOMPLETE_SHADOW_RESEARCH_POPULATION') {
            return true;
        }

        $audit = (array) data_get($generation->trigger_context, 'constructor_audit', []);
        $planned = (int) data_get($audit, 'planned_slots', 0);
        $created = (int) data_get($audit, 'created_agents', 0);

        return $generation->status === 'technical_quarantine' && $planned > 0 && $created < $planned;
    }
}
