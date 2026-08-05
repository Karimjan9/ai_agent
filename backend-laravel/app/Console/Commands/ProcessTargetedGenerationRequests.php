<?php

namespace App\Console\Commands;

use App\Models\CandidateHandoffEvent;
use App\Services\CandidateHandoffService;
use App\Services\LabPopulationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ProcessTargetedGenerationRequests extends Command
{
    protected $signature = 'trading:process-targeted-generations';
    protected $description = 'Create one bounded targeted generation for each no-eligible-candidate handoff request';

    public function handle(LabPopulationService $populations, CandidateHandoffService $handoffs): int
    {
        // Scheduler-level withoutOverlapping() does not protect a manual
        // invocation from racing the scheduler.  Both paths can otherwise
        // consume the same immutable handoff and create duplicate targeted
        // populations (G93/G94), wasting the bounded mutation budget.
        $lock = Cache::lock('trading:targeted-generation-builder:v1', 300);
        if (! $lock->get()) {
            $this->info('Targeted generation builder already active; this invocation is safely deferred.');
            return self::SUCCESS;
        }

        try {
            return $this->buildTargetedGenerations($populations, $handoffs);
        } finally {
            optional($lock)->release();
        }
    }

    private function buildTargetedGenerations(LabPopulationService $populations, CandidateHandoffService $handoffs): int
    {
        $requests = CandidateHandoffEvent::query()->with('generation.laboratory')
            ->where('stage', 'waiting_for_targeted_generation')->where('status', 'waiting')->get();
        foreach ($requests as $request) {
            $source = $request->generation; $lab = $source?->laboratory;
            if (! $source || ! $lab) continue;
            $latest = $lab->generations()->latest('generation')->first();
            $latestIsAbandonedStaleProtocol = $latest
                && $latest->status === 'abandoned'
                && $latest->trigger_type === 'candidate_handoff'
                && data_get($latest->trigger_context, 'generation_protocol') !== LabPopulationService::GENERATION_PROTOCOL;
            if ($latest && $latest->id !== $source->id && ! $latestIsAbandonedStaleProtocol) {
                $handoffs->record($source, null, 'targeted_generation_created', 'completed', null, ['target_generation_id' => $latest->id, 'generation' => $latest->generation, 'deduplicated' => true]);
                continue;
            }
            $baseline = $lab->generations()->where('trigger_type', '!=', 'candidate_handoff')->max('generation');
            $targeted = $lab->generations()->where('trigger_type', 'candidate_handoff')
                // Abandoned populations produced no screening evidence and
                // must not consume the bounded targeted-generation budget.
                // This includes a duplicate population quarantined after a
                // manual/scheduler race.
                ->where('status', '!=', 'abandoned')
                ->when($baseline !== null, fn ($query) => $query->where('generation', '>', $baseline))->get();
            $targetedAttempts = $targeted->filter(fn ($generation) => data_get($generation->trigger_context, 'generation_protocol') === LabPopulationService::GENERATION_PROTOCOL)->count();
            $budgetAlreadyClosed = CandidateHandoffEvent::query()->where('lab_generation_id', $source->id)
                ->where('stage', 'targeted_generation_budget_exhausted')->get()
                ->contains(fn ($event) => data_get($event->payload, 'protocol_version') === LabPopulationService::GENERATION_PROTOCOL)
                && $targetedAttempts >= 2;
            if ($targetedAttempts >= 2 || $budgetAlreadyClosed) {
                if (! $budgetAlreadyClosed) {
                    $handoffs->record($source, null, 'targeted_generation_budget_exhausted', 'blocked', 'TARGETED_GENERATION_BUDGET_EXHAUSTED', [
                        'baseline_generation' => $baseline, 'targeted_attempts' => $targetedAttempts,
                        'protocol_version' => LabPopulationService::GENERATION_PROTOCOL,
                        'rule' => 'At most two targeted populations may follow one baseline without a new validated signal; require a data/edge audit before any further mutation.',
                        'next_action' => 'data_edge_audit_required',
                    ]);
                    $this->warn("{$lab->symbol}: targeted generation budget exhausted; data/edge audit required before further mutation.");
                }
                continue;
            }
            $created = $populations->build($lab->symbol, 'candidate_handoff', false, $lab->timeframe);
            if ($created) {
                $handoffs->record($source, null, 'targeted_generation_created', 'completed', null, ['target_generation_id' => $created->id, 'generation' => $created->generation,
                    'rule' => 'New bounded population is targeted by the recorded failure curriculum; no old screened candidate was force-replayed.']);
                $this->info("{$lab->symbol}: targeted G{$created->generation} created.");
            } else $this->warn("{$lab->symbol}: targeted generation remains waiting for market-data readiness.");
        }
        return self::SUCCESS;
    }
}
