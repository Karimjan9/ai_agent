<?php

namespace App\Console\Commands;

use App\Models\LabGeneration;
use App\Models\SystemEvent;
use App\Services\LearningProtocolSafetyService;
use App\Services\LabPopulationService;
use App\Services\LabQueueJobInspector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Stop an old long-running worker's draft population from blocking the current protocol. */
class QuarantineStaleTargetedGenerations extends Command
{
    protected $signature = 'trading:quarantine-stale-targeted-generations {--dry-run} {--repair-partial : Quarantine an interrupted, un-dispatched targeted constructor after exact queue/open-run checks}';

    protected $description = 'Quarantine stale empty draft candidate-handoff generations without touching active work';

    public function handle(LabQueueJobInspector $queueInspector): int
    {
        // This command predates the Redis queue migration and contains a
        // destructive database-jobs delete path. Never let it infer an empty
        // DB queue while Redis is the live transport. Recovery/quarantine
        // commands that understand Redis own that path; this one fails closed
        // until it is explicitly ported.
        if ((string) config('queue.default') === 'redis') {
            $snapshot = $queueInspector->queueSnapshot();
            if (($snapshot['available'] ?? false) !== true) {
                $this->error('Redis queue state is unavailable; stale generation quarantine was skipped.');

                return self::FAILURE;
            }
            $this->line('Redis queue is active; DB-only stale-generation quarantine was skipped safely.');

            return self::SUCCESS;
        }

        $cutoff = now()->subMinutes(90);
        $pauseEvent = SystemEvent::query()->where('event_key', 'learning_protocol:generation_creation_paused')->first();
        $paused = app(LearningProtocolSafetyService::class)->generationCreationPaused();
        $pausedAt = $pauseEvent?->occurred_at;
        $rows = LabGeneration::query()->with('agents.modelVersion')
            // A stale population can come from the older portfolio-failure
            // trigger as well as candidate_handoff.  The safety condition
            // below (no active agent and no queued job) is the authority;
            // trigger labels are only historical provenance.  We also load
            // recently-created empty drafts so an accidental duplicate
            // generation can be retired as soon as a newer generation is
            // active; this prevents the dispatcher from leaving a dead G98
            // behind G99 after a manual retry.
            ->whereIn('status', ['draft', 'screening', 'screened'])
            ->get();
        $quarantined = 0;
        $orphanAgentsQuarantined = $this->quarantineOrphanDraftAgents((bool) $this->option('dry-run'));
        foreach ($rows as $generation) {
            // The stale-age rule applies only to empty draft rows. A completed
            // screened cohort may be old by design and must never be
            // quarantined merely because it is outside this cutoff.
            $isStale = $generation->status === 'draft'
                && ($generation->updated_at?->lte($cutoff) ?? true);
            $hasNewerGeneration = LabGeneration::query()
                ->where('ai_laboratory_id', $generation->ai_laboratory_id)
                ->where('generation', '>', $generation->generation)
                ->whereIn('status', ['queued', 'training', 'screening', 'screened', 'full_queued', 'full_validation', 'completed'])
                ->exists();
            $isSupersededEmpty = $hasNewerGeneration
                && $generation->agents->every(fn ($agent): bool => $agent->lifecycle_status === 'draft');
            $isIncompletePausedTargeted = $paused
                && $generation->trigger_type === 'candidate_handoff'
                && (int) $generation->population_size < count(LabPopulationService::POPULATION_GROUPS) * LabPopulationService::POPULATION_GROUP_SEATS
                && in_array(data_get($generation->trigger_context, 'targeted_failure_profile.protocol'), [
                    'targeted_failure_profile_v1', LabPopulationService::TARGETED_RESCUE_PROFILE_PROTOCOL,
                ], true);
            $constructorAudit = (array) data_get($generation->trigger_context, 'constructor_audit', []);
            $plannedSlots = (int) data_get($constructorAudit, 'planned_slots', 0);
            if ($plannedSlots < 1 && $generation->trigger_type === 'candidate_handoff') {
                $plannedSlots = max(
                    (int) $generation->population_size,
                    (int) data_get($generation->trigger_context, 'targeted_failure_profile.population_size', 0),
                );
            }
            $observedAgents = $generation->agents->count();
            // A targeted plan is an auditable five-by-four experiment. If
            // constructor policy rejects lanes, the old draft must not be
            // dispatched as a misleading partial cohort. Require the
            // completed constructor audit and only touch drafts with no
            // active agent/job; an already-dispatched cohort is evidence and
            // remains under its normal lifecycle.
            $isIncompleteTargetedConstructor = $generation->status === 'draft'
                && $generation->trigger_type === 'candidate_handoff'
                && in_array(data_get($generation->trigger_context, 'targeted_failure_profile.protocol'), [
                    'targeted_failure_profile_v1', LabPopulationService::TARGETED_RESCUE_PROFILE_PROTOCOL,
                ], true)
                && $plannedSlots >= count(LabPopulationService::POPULATION_GROUPS) * LabPopulationService::POPULATION_GROUP_SEATS
                && $observedAgents < $plannedSlots
                && $constructorAudit !== [];
            $isInterruptedTargeted = (bool) $this->option('repair-partial')
                && $generation->status === 'draft'
                && $generation->trigger_type === 'candidate_handoff'
                && in_array(data_get($generation->trigger_context, 'targeted_failure_profile.protocol'), [
                    'targeted_failure_profile_v1', LabPopulationService::TARGETED_RESCUE_PROFILE_PROTOCOL,
                ], true)
                && $plannedSlots >= count(LabPopulationService::POPULATION_GROUPS) * LabPopulationService::POPULATION_GROUP_SEATS
                && $observedAgents < $plannedSlots;
            $allAgentsUnstarted = $generation->agents->isNotEmpty()
                && $generation->agents->every(fn ($agent): bool => in_array((string) $agent->lifecycle_status, ['draft', 'queued'], true));
            $allAgentsControlOnly = $generation->agents->isNotEmpty()
                && $generation->agents->every(function ($agent): bool {
                    $diff = $agent->parameter_diff;
                    if (is_string($diff)) $diff = json_decode($diff, true);

                    return (array) $diff === []
                        && (bool) data_get($agent->modelVersion?->metadata, 'mutation_constructor_invariant.control_only', false);
                });
            $hasOpenRun = DB::table('lab_evaluation_runs')
                ->whereIn('lab_agent_id', $generation->agents->pluck('id')->all())
                ->whereNull('finished_at')
                ->exists();
            $isPartialTargetedAdmission = (bool) $this->option('repair-partial')
                && in_array((string) $generation->status, ['draft', 'queued', 'training', 'screening'], true)
                && $generation->trigger_type === 'candidate_handoff'
                && in_array(data_get($generation->trigger_context, 'targeted_failure_profile.protocol'), [
                    'targeted_failure_profile_v1', LabPopulationService::TARGETED_RESCUE_PROFILE_PROTOCOL,
                ], true)
                && $plannedSlots >= count(LabPopulationService::POPULATION_GROUPS) * LabPopulationService::POPULATION_GROUP_SEATS
                && $observedAgents < $plannedSlots
                && $allAgentsUnstarted
                && $allAgentsControlOnly
                && ! $hasOpenRun;
            if ($isPartialTargetedAdmission) {
                $jobIds = $this->queuedJobIdsForAgents($generation->agents->pluck('id')->map(fn ($id): int => (int) $id)->all());
                if ($this->option('dry-run')) {
                    $this->line("Would quarantine partial control cohort G{$generation->generation} ({$generation->id}) status={$generation->status}, agents={$observedAgents}/{$plannedSlots}, jobs=".count($jobIds));
                }
                if (! $this->option('dry-run')) {
                    if ($jobIds !== []) DB::table('jobs')->whereIn('id', $jobIds)->delete();
                    foreach ($generation->agents as $agent) {
                        $agent->update([
                            'lifecycle_status' => 'technical_quarantine',
                            'decision_reason' => 'Technical quarantine: pre-policy targeted cohort was control-only and never entered replay; no strategy verdict.',
                        ]);
                    }
                    $generation->update([
                        'status' => 'technical_quarantine',
                        'completed_at' => now(),
                        'trigger_context' => [...($generation->trigger_context ?? []), 'quarantine' => [
                            'status' => 'partial_targeted_control_cohort',
                            'current_protocol' => LabPopulationService::GENERATION_PROTOCOL,
                            'required_population' => count(LabPopulationService::POPULATION_GROUPS) * LabPopulationService::POPULATION_GROUP_SEATS,
                            'observed_population' => $observedAgents,
                            'constructor_planned_slots' => $plannedSlots,
                            'cancelled_job_ids' => $jobIds,
                            'cancelled_job_count' => count($jobIds),
                            'verified_no_open_run' => true,
                            'promotion_evidence' => false,
                        ]],
                    ]);
                }
                $quarantined++;
                continue;
            }
            $isIncompletePausedDraft = $paused
                && $generation->status === 'draft'
                && $observedAgents < (int) $generation->population_size
                && ! data_get($generation->trigger_context, 'controlled_rescue_admission.protocol')
                && ($pausedAt === null || $generation->created_at?->lte($pausedAt));
            if (! $isStale && ! $isSupersededEmpty && ! $isIncompletePausedTargeted && ! $isIncompleteTargetedConstructor && ! $isInterruptedTargeted && ! $isIncompletePausedDraft) continue;
            if ($this->option('dry-run')) {
                $this->line("Would quarantine G{$generation->generation} ({$generation->id}) status={$generation->status}, agents={$observedAgents}/{$plannedSlots}");
            }
            $activeAgentIds = $generation->agents
                ->whereIn('lifecycle_status', ['queued', 'screening', 'training', 'full_queued'])
                ->pluck('id')->values()->all();
            if ($activeAgentIds !== []) continue;
            $queuedPayloads = DB::table('jobs')->where('payload', 'like', '%labAgentId%')->pluck('payload');
            $hasQueuedAgentJob = collect($generation->agents->pluck('id')->all())->contains(
                function (int $agentId) use ($queuedPayloads): bool {
                    $serializedMarker = 's:10:"labAgentId";i:'.$agentId.';';
                    $legacyMarker = 'labAgentId;'.$agentId;

                    return $queuedPayloads->contains(function (mixed $payload) use ($serializedMarker, $legacyMarker): bool {
                        $decoded = json_decode((string) $payload, true);
                        $command = (string) data_get($decoded, 'data.command', '');

                        return str_contains($command, $serializedMarker)
                            || str_contains($command, $legacyMarker);
                    });
                },
            );
            if ($hasQueuedAgentJob) continue;
            $quarantined++;
            if (! $this->option('dry-run')) {
                $generation->update([
                    'status' => 'abandoned',
                    'completed_at' => now(),
                    'trigger_context' => [...($generation->trigger_context ?? []),
                        'quarantine' => [
                            'status' => $isIncompletePausedDraft
                                ? 'incomplete_draft_during_pause'
                                : ($isInterruptedTargeted
                                ? 'interrupted_targeted_constructor'
                                : ($isIncompleteTargetedConstructor
                                ? 'incomplete_targeted_population_constructor'
                                : ($isIncompletePausedTargeted
                                ? 'incomplete_targeted_population_during_pause'
                                : ($isSupersededEmpty
                                ? 'superseded_empty_generation'
                                : (data_get($generation->trigger_context, 'generation_protocol') === LabPopulationService::GENERATION_PROTOCOL
                                    ? 'stale_empty_dispatch' : 'stale_protocol'))))),
                            'current_protocol' => LabPopulationService::GENERATION_PROTOCOL,
                            'required_population' => count(LabPopulationService::POPULATION_GROUPS) * LabPopulationService::POPULATION_GROUP_SEATS,
                            'observed_population' => $observedAgents,
                            'constructor_planned_slots' => $plannedSlots ?: null,
                            'normal_generation_creation_paused' => $paused,
                            'cutoff_minutes' => 90,
                            'superseded_by_newer_generation' => $isSupersededEmpty,
                            'verified_no_active_agent_or_job' => true,
                        ]],
                ]);
            }
        }
        $this->info(($this->option('dry-run') ? 'Would quarantine: ' : 'Quarantined: ').$quarantined.' stale draft targeted generation(s).');
        $this->info(($this->option('dry-run') ? 'Would quarantine: ' : 'Quarantined: ').$orphanAgentsQuarantined.' orphan/invalid agent(s) from abandoned generations.');
        return self::SUCCESS;
    }

    private function quarantineOrphanDraftAgents(bool $dryRun): int
    {
        $generations = LabGeneration::query()->with('agents')
            ->whereIn('status', ['abandoned', 'technical_quarantine'])
            ->where(function ($query): void {
                $query->where('trigger_type', 'candidate_handoff')
                    ->orWhere('trigger_type', 'data_edge_audit')
                    ->orWhereJsonContains('trigger_context->quarantine->status', 'incomplete_draft_during_pause')
                    ->orWhereJsonContains('trigger_context->targeted_failure_profile->protocol', LabPopulationService::TARGETED_RESCUE_PROFILE_PROTOCOL);
            })
            ->get();
        $count = 0;
        foreach ($generations as $generation) {
            $agentIds = $generation->agents->pluck('id')->map(fn ($id): int => (int) $id)->all();
            if ($agentIds === []) continue;
            if (DB::table('lab_evaluation_runs')->whereIn('lab_agent_id', $agentIds)->whereNull('finished_at')->exists()) continue;
            if ($this->queuedJobIdsForAgents($agentIds) !== []) continue;

            $quarantineStatus = (string) data_get($generation->trigger_context, 'quarantine.status', '');
            $invalidTargetedGeneration = in_array($quarantineStatus, [
                'incomplete_targeted_population_during_pause',
                'partial_targeted_control_cohort',
                'interrupted_targeted_constructor',
                'incomplete_targeted_population_constructor',
            ], true);
            $draftAgents = $generation->agents->filter(function ($agent) use ($invalidTargetedGeneration): bool {
                if ((string) $agent->lifecycle_status === 'technical_quarantine') return false;

                return $invalidTargetedGeneration
                    ? ! in_array((string) $agent->lifecycle_status, ['queued', 'screening', 'training', 'full_queued', 'full_validation'], true)
                    : (string) $agent->lifecycle_status === 'draft';
            });
            if ($draftAgents->isEmpty()) continue;
            if ($dryRun) {
                $this->line("Would quarantine orphan/invalid agents for G{$generation->generation} ({$generation->id}): ".$draftAgents->count());
                $count += $draftAgents->count();
                continue;
            }
            foreach ($draftAgents as $agent) {
                $agent->update([
                    'lifecycle_status' => 'technical_quarantine',
                    'decision_reason' => $invalidTargetedGeneration
                        ? 'Technical quarantine: generation was incomplete/invalid and abandoned; no strategy verdict or mutation credit.'
                        : 'Technical quarantine: generation was abandoned before replay; no strategy verdict or mutation credit.',
                ]);
                $count++;
            }
        }

        return $count;
    }

    /** @param array<int, int> $agentIds @return array<int, int> */
    private function queuedJobIdsForAgents(array $agentIds): array
    {
        if ($agentIds === []) return [];

        return DB::table('jobs')->where('payload', 'like', '%labAgentId%')->get(['id', 'payload'])
            ->filter(function ($job) use ($agentIds): bool {
                $decoded = json_decode((string) $job->payload, true);
                $command = (string) data_get($decoded, 'data.command', '');
                foreach ($agentIds as $agentId) {
                    if (str_contains($command, 's:10:"labAgentId";i:'.$agentId.';')
                        || str_contains($command, 'labAgentId;'.$agentId)) return true;
                }

                return false;
            })
            ->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
    }
}
