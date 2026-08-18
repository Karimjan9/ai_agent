<?php

namespace App\Services;

use App\Models\AiLaboratory;
use App\Models\SystemEvent;

/** Persistent, auditable safety controls for an evolution-protocol rollout. */
class LearningProtocolSafetyService
{
    public const EXECUTION_CONTRACT = 'differential_paired_lane_v4_calendar_context_v1';
    public const CONTROLLED_RESCUE_PROTOCOL = 'controlled_targeted_rescue_v1';
    public const LIGHTHOUSE_SYMBOL = 'XAUUSD';
    public const LIGHTHOUSE_TIMEFRAME = 'H1';
    private const PAUSE_EVENT_KEY = 'learning_protocol:generation_creation_paused';

    public function generationCreationPaused(): bool
    {
        return (bool) data_get(SystemEvent::query()->where('event_key', self::PAUSE_EVENT_KEY)->first()?->payload, 'paused', false);
    }

    /**
     * A paused laboratory may admit one explicitly audited rescue cohort.
     * Legacy profiles retain the 20-seat five-by-four contract. The newer
     * gate-margin profile is intentionally narrower: five seats for one
     * immutable anchor (four bounded siblings plus one control).
     */
    public function controlledRescueAllowed(string $trigger, ?int $populationLimit, ?array $profile): bool
    {
        $groups = (array) data_get($profile, 'group_plan', []);

        if ((string) data_get($profile, 'cohort_mode') === StructuralResearchCohortService::COHORT_MODE
            || (string) data_get($profile, 'structural_research_contract.protocol') === StructuralResearchCohortService::PROTOCOL) {
            $families = (array) data_get($profile, 'structural_research_contract.structural_families', []);

            return $trigger === 'candidate_handoff'
                && (int) $populationLimit === StructuralResearchCohortService::POPULATION_SIZE
                && (string) data_get($profile, 'rescue_protocol') === self::CONTROLLED_RESCUE_PROTOCOL
                && (bool) data_get($profile, 'temporary', false)
                && (bool) data_get($profile, 'promotion_evidence', true) === false
                && strtoupper((string) data_get($profile, 'symbol')) === self::LIGHTHOUSE_SYMBOL
                && strtoupper((string) data_get($profile, 'timeframe')) === self::LIGHTHOUSE_TIMEFRAME
                && count($groups) === 5
                && collect($groups)->every(fn (mixed $group): bool => count((array) data_get($group, 'targets', [])) === 4)
                && data_get($profile, 'structural_research_contract.protocol') === StructuralResearchCohortService::PROTOCOL
                && (int) data_get($profile, 'structural_research_contract.population_size') === StructuralResearchCohortService::POPULATION_SIZE
                && (bool) data_get($profile, 'structural_research_contract.control_pair.required_for_every_candidate')
                && (bool) data_get($profile, 'structural_research_contract.causal_micro_probe.required_before_full_replay')
                && (bool) data_get($profile, 'structural_research_contract.independent_evidence.non_overlap_required')
                && count($families) >= 5;
        }

        if ((string) data_get($profile, 'cohort_mode') === 'four_siblings_plus_control_v1') {
            return $trigger === 'candidate_handoff'
                && (int) $populationLimit === 5
                && (string) data_get($profile, 'rescue_protocol') === self::CONTROLLED_RESCUE_PROTOCOL
                && (bool) data_get($profile, 'temporary', false)
                && (bool) data_get($profile, 'promotion_evidence', true) === false
                && strtoupper((string) data_get($profile, 'symbol')) === self::LIGHTHOUSE_SYMBOL
                && strtoupper((string) data_get($profile, 'timeframe')) === self::LIGHTHOUSE_TIMEFRAME
                && count((array) data_get($profile, 'repair_anchors', [])) === 1
                && count($groups) === 1
                && count((array) data_get($groups, 'repair_anchor_cohort.targets', [])) === 5
                && data_get($profile, 'cohort_contract.protocol') === 'four_siblings_plus_control_v1'
                && (int) data_get($profile, 'cohort_contract.bounded_siblings') === 4
                && (int) data_get($profile, 'cohort_contract.frozen_control') === 1;
        }

        return $trigger === 'candidate_handoff'
            && (int) $populationLimit === 20
            && (string) data_get($profile, 'rescue_protocol') === self::CONTROLLED_RESCUE_PROTOCOL
            && (bool) data_get($profile, 'temporary', false)
            && (bool) data_get($profile, 'promotion_evidence', true) === false
            && strtoupper((string) data_get($profile, 'symbol')) === self::LIGHTHOUSE_SYMBOL
            && strtoupper((string) data_get($profile, 'timeframe')) === self::LIGHTHOUSE_TIMEFRAME
            && count($groups) === 5
            && collect($groups)->every(fn (mixed $group): bool => count((array) data_get($group, 'targets', [])) === 4);
    }

    /** Record an explicit rescue admission without lifting the global pause. */
    public function recordControlledRescueAdmission(array $payload): void
    {
        $fingerprint = hash('sha256', json_encode([
            'protocol' => self::CONTROLLED_RESCUE_PROTOCOL,
            'symbol' => data_get($payload, 'symbol'),
            'timeframe' => data_get($payload, 'timeframe'),
            'source_generation_id' => data_get($payload, 'source_generation_id'),
            'profile_hash' => data_get($payload, 'profile_hash'),
        ], JSON_UNESCAPED_SLASHES));

        SystemEvent::create([
            'event_type' => 'learning_protocol_controlled_rescue',
            'event_key' => 'learning_protocol:controlled_rescue:'.$fingerprint,
            'agent' => 'operations',
            'symbol' => data_get($payload, 'symbol'),
            'timeframe' => data_get($payload, 'timeframe'),
            'severity' => 'warning',
            'summary' => 'One temporary 20-seat targeted rescue cohort admitted while normal generation creation remains paused.',
            'payload' => [
                'protocol' => self::CONTROLLED_RESCUE_PROTOCOL,
                'promotion_evidence' => false,
                ...$payload,
            ],
            'occurred_at' => now(),
        ]);
    }

    public function pauseGenerationCreation(string $reason): void
    {
        SystemEvent::updateOrCreate(['event_key' => self::PAUSE_EVENT_KEY], [
            'event_type' => 'learning_protocol_safety',
            'agent' => 'operations',
            'severity' => 'warning',
            'summary' => 'New lab-generation creation paused while a new execution contract is being verified.',
            'payload' => ['paused' => true, 'reason' => $reason, 'execution_contract' => self::EXECUTION_CONTRACT],
            'occurred_at' => now(),
        ]);
    }

    /**
     * Normal resume is allowed only after every active lab reports the full
     * funnel contract. A reason string alone must never reopen the scheduler.
     * Controlled rescue uses a separate, temporary admission path.
     */
    public function resumeGenerationCreation(string $reason): bool
    {
        $readiness = $this->resumeReadiness();
        if (! $readiness['ready']) {
            SystemEvent::updateOrCreate(['event_key' => 'learning_protocol:resume_blocked'], [
                'event_type' => 'learning_protocol_safety',
                'agent' => 'operations',
                'severity' => 'warning',
                'summary' => 'Normal generation resume blocked because the full evolution funnel is not open.',
                'payload' => [
                    'paused' => true,
                    'reason' => $reason,
                    'readiness' => $readiness,
                    'controlled_rescue_only' => true,
                ],
                'occurred_at' => now(),
            ]);

            return false;
        }

        SystemEvent::updateOrCreate(['event_key' => self::PAUSE_EVENT_KEY], [
            'event_type' => 'learning_protocol_safety',
            'agent' => 'operations',
            'severity' => 'info',
            'summary' => 'Lab-generation creation resumed after protocol verification.',
            'payload' => ['paused' => false, 'reason' => $reason, 'execution_contract' => self::EXECUTION_CONTRACT,
                'resumed_at' => now()->utc()->toIso8601String()],
            'occurred_at' => now(),
        ]);

        return true;
    }

    /** @return array{ready: bool, required: array<int, string>, labs: array<int, array<string, mixed>>} */
    public function resumeReadiness(): array
    {
        $required = [
            'evolution_safe', 'screening_pass_rate', 'full_validation_completion_rate',
            'forward_valid_agents', 'independently_confirmed_mutations', 'parent_links', 'paper_eligible',
        ];
        $labs = [];
        // Shadow labs remain visible to monitoring and research, but they are
        // intentionally outside the first lighthouse's normal-resume gate.
        // Including them here would make a successful XAUUSD H1 pilot unable
        // to reopen its own funnel while EUR/GBP/M15 are still shadow-only.
        foreach (AiLaboratory::query()
            ->where('is_active', true)
            ->where('lifecycle_mode', 'lighthouse')
            ->orderBy('symbol')
            ->orderBy('timeframe')
            ->get() as $lab) {
            $latest = $lab->generations()->latest('generation')->first();
            $kpis = (array) data_get($latest?->trigger_context, 'latest_generation_report.kpis', []);
            $checks = [
                'evolution_safe' => data_get($kpis, 'evolution_safe') === true,
                'screening_pass_rate' => (float) data_get($kpis, 'screening_pass_rate', 0) > 0,
                'full_validation_completion_rate' => (float) data_get($kpis, 'full_validation_completion_rate', 0) >= 100,
                'forward_valid_agents' => (int) data_get($kpis, 'forward_valid_agents', 0) > 0,
                'independently_confirmed_mutations' => (int) data_get($kpis, 'independently_confirmed_mutations', 0) >= 2,
                'parent_links' => (int) data_get($kpis, 'parent_links', 0) > 0,
                'paper_eligible' => (int) data_get($kpis, 'paper_eligible', 0) > 0,
            ];
            $labs[] = [
                'symbol' => $lab->symbol,
                'timeframe' => $lab->timeframe,
                'generation_id' => $latest?->id,
                'generation' => $latest?->generation,
                'status' => $latest?->status,
                'checks' => $checks,
                'failed_checks' => array_values(array_keys(array_filter($checks, fn (bool $passed): bool => ! $passed))),
            ];
        }

        return [
            'ready' => $labs !== [] && collect($labs)->every(fn (array $lab): bool => $lab['failed_checks'] === []),
            'required' => $required,
            'labs' => $labs,
        ];
    }
}
