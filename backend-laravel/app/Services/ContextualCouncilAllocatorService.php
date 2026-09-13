<?php

namespace App\Services;

use App\Models\AiLaboratory;

/** Backward-compatible entry point for the cooperative experiment-block council. */
class ContextualCouncilAllocatorService
{
    public const PROTOCOL = CooperativeContextualEvolutionCouncilService::PROTOCOL;

    public function __construct(private CooperativeContextualEvolutionCouncilService $council) {}

    /** @return array{plan:array<int,array<string,mixed>>,contract:array<string,mixed>} */
    public function allocate(array $plan, AiLaboratory $lab, array $governorSnapshot = []): array
    {
        return $this->council->allocate($plan, $lab, $governorSnapshot);
    }
}
