<?php

namespace App\Services;

use App\Models\SystemEvent;

/** Persistent, auditable safety controls for an evolution-protocol rollout. */
class LearningProtocolSafetyService
{
    public const EXECUTION_CONTRACT = 'differential_paired_lane_v4_calendar_context_v1';
    private const PAUSE_EVENT_KEY = 'learning_protocol:generation_creation_paused';

    public function generationCreationPaused(): bool
    {
        return (bool) data_get(SystemEvent::query()->where('event_key', self::PAUSE_EVENT_KEY)->first()?->payload, 'paused', false);
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

    /** Resume is an explicit, auditable operator action after the frozen
     * protocol baseline has been verified. It never changes any candidate
     * gate; it only permits creation of the next research population. */
    public function resumeGenerationCreation(string $reason): void
    {
        SystemEvent::updateOrCreate(['event_key' => self::PAUSE_EVENT_KEY], [
            'event_type' => 'learning_protocol_safety',
            'agent' => 'operations',
            'severity' => 'info',
            'summary' => 'Lab-generation creation resumed after protocol verification.',
            'payload' => ['paused' => false, 'reason' => $reason, 'execution_contract' => self::EXECUTION_CONTRACT,
                'resumed_at' => now()->utc()->toIso8601String()],
            'occurred_at' => now(),
        ]);
    }
}
