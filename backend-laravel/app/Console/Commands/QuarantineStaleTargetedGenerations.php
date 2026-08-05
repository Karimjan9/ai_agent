<?php

namespace App\Console\Commands;

use App\Models\LabGeneration;
use App\Services\LabPopulationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Stop an old long-running worker's draft population from blocking the current protocol. */
class QuarantineStaleTargetedGenerations extends Command
{
    protected $signature = 'trading:quarantine-stale-targeted-generations {--dry-run}';

    protected $description = 'Quarantine stale empty draft candidate-handoff generations without touching active work';

    public function handle(): int
    {
        $cutoff = now()->subMinutes(90);
        $rows = LabGeneration::query()->with('agents')
            // A stale population can come from the older portfolio-failure
            // trigger as well as candidate_handoff.  The safety condition
            // below (no active agent and no queued job) is the authority;
            // trigger labels are only historical provenance.  We also load
            // recently-created empty drafts so an accidental duplicate
            // generation can be retired as soon as a newer generation is
            // active; this prevents the dispatcher from leaving a dead G98
            // behind G99 after a manual retry.
            ->where('status', 'draft')
            ->get();
        $quarantined = 0;
        foreach ($rows as $generation) {
            $isStale = $generation->updated_at?->lte($cutoff) ?? true;
            $hasNewerGeneration = LabGeneration::query()
                ->where('ai_laboratory_id', $generation->ai_laboratory_id)
                ->where('generation', '>', $generation->generation)
                ->whereIn('status', ['queued', 'training', 'screening', 'screened', 'full_queued', 'full_validation', 'completed'])
                ->exists();
            $isSupersededEmpty = $hasNewerGeneration
                && $generation->agents->every(fn ($agent): bool => $agent->lifecycle_status === 'draft');
            if (! $isStale && ! $isSupersededEmpty) continue;
            $activeAgentIds = $generation->agents
                ->whereIn('lifecycle_status', ['queued', 'screening', 'training', 'full_queued'])
                ->pluck('id')->values()->all();
            if ($activeAgentIds !== []) continue;
            $hasQueuedAgentJob = collect($generation->agents->pluck('id')->all())->contains(
                fn (int $agentId): bool => DB::table('jobs')->where('payload', 'like', '%labAgentId%'.$agentId.'%')->exists(),
            );
            if ($hasQueuedAgentJob) continue;
            $quarantined++;
            if (! $this->option('dry-run')) {
                $generation->update([
                    'status' => 'abandoned',
                    'completed_at' => now(),
                    'trigger_context' => [...($generation->trigger_context ?? []),
                        'quarantine' => [
                            'status' => $isSupersededEmpty
                                ? 'superseded_empty_generation'
                                : (data_get($generation->trigger_context, 'generation_protocol') === LabPopulationService::GENERATION_PROTOCOL
                                    ? 'stale_empty_dispatch' : 'stale_protocol'),
                            'current_protocol' => LabPopulationService::GENERATION_PROTOCOL,
                            'cutoff_minutes' => 90,
                            'superseded_by_newer_generation' => $isSupersededEmpty,
                            'verified_no_active_agent_or_job' => true,
                        ]],
                ]);
            }
        }
        $this->info(($this->option('dry-run') ? 'Would quarantine: ' : 'Quarantined: ').$quarantined.' stale draft targeted generation(s).');
        return self::SUCCESS;
    }
}
