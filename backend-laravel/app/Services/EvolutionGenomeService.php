<?php

namespace App\Services;

use App\Models\EvolutionProposal;
use App\Models\ModelVersion;
use App\Models\StrategyGenome;
use App\Models\TrainingSession;

/**
 * Read-only compatibility boundary for the retired genome projection.
 *
 * Canonical Lab agents carry lineage and inheritance evidence themselves.
 * The old TrainingSession/StrategyScore genome writer is deliberately kept
 * inert so stale jobs or integrations cannot reanimate legacy projections.
 */
class EvolutionGenomeService
{
    public function __construct(private StrategySemanticGroupService $semanticGroups) {}

    public function recordTrainingSession(TrainingSession $session): void
    {
        // Intentionally empty: historical genome projections are immutable.
    }

    public function recordAppliedProposal(EvolutionProposal $proposal, ModelVersion $childVersion): ?StrategyGenome
    {
        // Intentionally empty: canonical Lab generations own lineage writes.
        return null;
    }

    /**
     * Retained only for the semantic-group isolation regression test and
     * historical read tooling; it performs no writes.
     */
    private function sameSemanticGroup(StrategyGenome $left, StrategyGenome $right): bool
    {
        if ((string) $left->family === '' || (string) $left->family !== (string) $right->family) {
            return false;
        }

        $left->loadMissing('modelVersion');
        $right->loadMissing('modelVersion');

        if (! $left->modelVersion || ! $right->modelVersion) {
            return false;
        }

        return $this->semanticGroups->sameGroup(
            $left->modelVersion,
            $left->family,
            $right->modelVersion,
            $right->family,
        );
    }
}
