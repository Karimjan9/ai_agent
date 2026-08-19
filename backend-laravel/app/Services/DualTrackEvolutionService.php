<?php

namespace App\Services;

use App\Models\DualTrackEvolutionEvent;
use App\Models\DualTrackOutcome;
use Illuminate\Support\Facades\Schema;

/** Records capability-level evolution work without mutating parents or gates. */
class DualTrackEvolutionService
{
    public const PROTOCOL = 'dual_track_capability_evolution_v1';

    /** @return array<string, mixed> */
    public function recordOutcome(DualTrackOutcome $outcome): array
    {
        if (! Schema::hasTable('dual_track_evolution_events')) return ['status' => 'unavailable', 'promotion_evidence' => false];
        $failure = $outcome->actual_outcome !== 'win';
        $eventType = $failure ? 'failure_repair' : ($outcome->regret && $outcome->regret > 0 ? 'counterfactual_gap' : 'capability_observation');
        $island = $failure ? 'recovery' : ($outcome->lane === 'council' ? 'council' : ($outcome->lane === 'champion' ? 'champion' : 'adversarial'));
        $capability = $failure ? (string) data_get($outcome->metadata, 'failure_signature', 'risk_or_execution') : 'validated_'.$outcome->lane.'_behavior';
        $key = hash('sha256', self::PROTOCOL.'|'.$outcome->outcome_key.'|'.$eventType);
        $event = DualTrackEvolutionEvent::query()->updateOrCreate(
            ['event_key' => $key],
            [
                'symbol' => $outcome->symbol, 'timeframe' => $outcome->timeframe, 'cell_key' => $outcome->cell_key,
                'island_key' => $island, 'lane' => $outcome->lane, 'event_type' => $eventType, 'capability_key' => $capability,
                'source_parent_model_version_ids' => (array) data_get($outcome->metadata, 'source_parent_model_version_ids', []),
                'incremental_value' => $outcome->reward, 'status' => 'research',
                'evidence' => ['protocol' => self::PROTOCOL, 'counterfactual_required' => true, 'promotion_evidence' => false],
                'metadata' => ['outcome_id' => $outcome->id, 'promotion_evidence' => false], 'promotion_evidence' => false,
            ],
        );
        return ['status' => $event->status, 'event_id' => $event->id, 'island_key' => $island, 'capability_key' => $capability, 'promotion_evidence' => false];
    }

    /** @return array<string, mixed> */
    public function campaign(string $symbol, string $timeframe, string $cellKey, string $lane, ?string $failureSignature = null): array
    {
        return [
            'protocol' => self::PROTOCOL, 'symbol' => strtoupper($symbol), 'timeframe' => strtoupper($timeframe),
            'cell_key' => $cellKey, 'lane' => $lane, 'island_key' => $failureSignature ? 'recovery' : $lane,
            'mutation_mode' => $failureSignature ? 'bounded_failure_repair' : 'capability_module_search',
            'requires_counterfactual' => true, 'requires_fresh_holdout' => true, 'promotion_evidence' => false,
        ];
    }
}
