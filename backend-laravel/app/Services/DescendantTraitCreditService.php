<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LabEvolutionCreditEvent;
use App\Models\MutationMemory;
use Illuminate\Support\Facades\Schema;

/**
 * Gives a gene credit only when a direct descendant has sealed replay
 * evidence.  The source memory is deliberately never rewritten: every hop is
 * an immutable event, which makes child and grandchild attribution auditable.
 */
class DescendantTraitCreditService
{
    public const PROTOCOL = 'descendant_trait_credit_v1';

    /** @return array<string, mixed> */
    public function record(LabAgent $agent, array $result, ?object $forwardDecision = null): array
    {
        if (! $this->available() || $this->technical($result) || ! filled(data_get($result, 'evidence_run_id'))) {
            return ['protocol' => self::PROTOCOL, 'status' => 'evidence_incomplete', 'events' => [], 'promotion_evidence' => false];
        }

        $agent->loadMissing('modelVersion');
        if (! $agent->modelVersion) {
            return ['protocol' => self::PROTOCOL, 'status' => 'model_missing', 'events' => [], 'promotion_evidence' => false];
        }

        $genes = array_values(array_filter(array_keys((array) $agent->parameter_diff), fn (string $gene): bool => ! str_starts_with($gene, '__')));
        if ($genes === []) {
            return ['protocol' => self::PROTOCOL, 'status' => 'no_mutated_trait', 'events' => [], 'promotion_evidence' => false];
        }

        $parentIds = app(ParentContributionGraphService::class)->ids($agent);
        if ($parentIds === []) {
            return ['protocol' => self::PROTOCOL, 'status' => 'root_child', 'events' => [], 'promotion_evidence' => false];
        }

        $delta = data_get($result, 'mutation_observability.control_delta', data_get($result, 'verified_mutation_skill.target_gate.normalized_delta'));
        $delta = is_numeric($delta) ? (float) $delta : null;
        $forwardPassed = data_get($forwardDecision, 'decision') === 'passed';
        $safe = ! (bool) data_get($result, 'mutation_observability.non_target_regression.failed', false)
            && (bool) data_get($result, 'mutation_observability.non_target_regression.safe', true);
        $status = match (true) {
            ! $safe || ($delta !== null && $delta < 0) => 'descendant_harmful',
            $forwardPassed && $delta !== null && $delta > 0 => 'independently_confirmed',
            $delta !== null && $delta > 0 => 'provisional_descendant_gain',
            default => 'descendant_no_effect',
        };
        // Only independently confirmed forward evidence has non-zero credit.
        // A screen/full-replay gain remains visible but cannot bias parents.
        $amount = $status === 'independently_confirmed' ? 1.0 : ($status === 'descendant_harmful' ? -1.0 : 0.0);
        $parents = LabAgent::query()->whereIn('model_version_id', $parentIds)->get()->keyBy('model_version_id');
        $events = [];

        foreach ($parentIds as $parentModelId) {
            $parent = $parents->get($parentModelId);
            if (! $parent) continue;
            $memories = MutationMemory::query()
                ->where('lab_agent_id', $parent->id)
                ->whereIn('parameter_key', $genes)
                ->get();
            foreach ($memories as $memory) {
                // The direct edge is recorded at every generation. Looking
                // back through already-sealed edges gives the edge its real
                // genealogical depth without rewriting an ancestor's proof.
                $priorEdges = LabEvolutionCreditEvent::query()
                    ->where('model_version_id', $parentModelId)
                    ->where('event_type', 'descendant_trait')->get()
                    ->filter(fn (LabEvolutionCreditEvent $edge): bool => data_get($edge->payload, 'source_trait') === $memory->parameter_key);
                $lineageDepth = 1 + (int) ($priorEdges->max(fn (LabEvolutionCreditEvent $edge): int => (int) data_get($edge->payload, 'lineage_depth', 1)) ?? 0);
                $lineageValue = (float) $priorEdges->sum(fn (LabEvolutionCreditEvent $edge): float => (float) $edge->amount) + $amount;
                $contextKey = hash('sha256', json_encode([
                    'gene' => $memory->parameter_key,
                    'target' => data_get($agent->modelVersion->metadata, 'generation_target', 'unknown'),
                    'regime' => data_get($result, 'dominant_regime', data_get($memory->behavioral_effect, 'trait_ledger.applicable_context', 'unknown')),
                ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
                $fingerprint = hash('sha256', json_encode([
                    'protocol' => self::PROTOCOL, 'agent' => $agent->id, 'source_memory' => $memory->id,
                    'evidence_run_id' => data_get($result, 'evidence_run_id'), 'status' => $status,
                ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
                $event = LabEvolutionCreditEvent::query()->firstOrCreate(
                    ['evidence_fingerprint' => $fingerprint],
                    [
                        'lab_agent_id' => $agent->id,
                        'model_version_id' => $agent->model_version_id,
                        'parent_model_version_id' => $parentModelId,
                        'symbol' => strtoupper($agent->symbol), 'timeframe' => strtoupper($agent->timeframe),
                        'strategy_family' => $agent->strategy_family, 'event_type' => 'descendant_trait',
                        'context_key' => $contextKey, 'amount' => $amount, 'status' => $status,
                        'payload' => [
                            'protocol' => self::PROTOCOL, 'source_mutation_memory_id' => $memory->id,
                            'source_trait' => $memory->parameter_key, 'generation_distance' => 1, 'lineage_depth' => $lineageDepth,
                            'lineage_value_to_date' => $lineageValue,
                            'target_delta' => $delta, 'forward_passed' => $forwardPassed,
                            'safe_non_target_regression' => $safe, 'evidence_run_id' => data_get($result, 'evidence_run_id'),
                            'rule' => 'Each direct descendant hop is recorded independently; aggregate lineage value is a monitor metric, never promotion evidence.',
                            'promotion_evidence' => false,
                        ],
                        'recorded_at' => now()->utc(),
                    ],
                );
                $events[] = ['id' => (int) $event->id, 'source_trait' => $memory->parameter_key, 'status' => $event->status, 'amount' => (float) $event->amount, 'lineage_depth' => $lineageDepth];
            }
        }

        return ['protocol' => self::PROTOCOL, 'status' => $events === [] ? 'no_inherited_trait_memory' : 'recorded', 'events' => $events, 'promotion_evidence' => false];
    }

    private function available(): bool
    {
        try {
            return Schema::hasTable('lab_evolution_credit_events') && Schema::hasTable('mutation_memories');
        } catch (\Throwable) {
            return false;
        }
    }

    private function technical(array $result): bool
    {
        return in_array((string) data_get($result, 'quality_verdict'), ['withheld', 'technical_error', 'technical_quarantine'], true)
            || in_array((string) data_get($result, 'status'), ['technical_error', 'technical_quarantine'], true)
            || (bool) data_get($result, 'technical_error', false);
    }
}
