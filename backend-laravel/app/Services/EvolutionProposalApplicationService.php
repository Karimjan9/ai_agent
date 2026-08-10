<?php

namespace App\Services;

use App\Models\EvolutionProposal;
use App\Models\ModelVersion;

/**
 * Compatibility boundary for the retired EvolutionProposal apply flow.
 *
 * Proposal approval/application is intentionally disabled.  New generations
 * are produced only by the canonical Lab pipeline and its evidence gates.
 */
class EvolutionProposalApplicationService
{
    public function apply(EvolutionProposal $proposal): ?ModelVersion
    {
        return null;
    }
}
