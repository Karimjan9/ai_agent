<?php

namespace App\Services;

use App\Models\EvolutionProposal;
use App\Models\TrainingSession;

/**
 * Compatibility boundary for the retired TrainingSession evolution flow.
 *
 * Canonical Lab generations are created from immutable Lab evidence.  This
 * service remains resolvable for historical integrations, but it must never
 * create EvolutionProposal rows from the old projection tables.
 */
class AgentEvolutionService
{
    public function createProposalFromSession(TrainingSession $session): ?EvolutionProposal
    {
        return null;
    }
}
