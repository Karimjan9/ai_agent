<?php

namespace App\Services;

use App\Models\AgentLearningRetrieval;
use Illuminate\Support\Facades\Schema;

/** Opportunity-normalized memory accounting: abstention is valid, omission is visible. */
class MemoryOpportunityService
{
    public const PROTOCOL = 'compatible_memory_opportunity_v1';

    /** @return array<string,mixed> */
    public function metrics(string $symbol, string $timeframe, ?string $family = null): array
    {
        if (! Schema::hasTable('agent_learning_retrievals')) return ['available' => false];
        $rows = AgentLearningRetrieval::query()->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->when($family, fn ($q) => $q->where('strategy_family', $family));
        $all = (clone $rows)->get();
        $considered = $all->filter(fn ($row) => data_get($row->metadata, 'retrieval_decision.considered') === true);
        $compatible = $considered->filter(fn ($row) => data_get($row->metadata, 'retrieval_decision.compatible') === true);
        $accepted = $compatible->filter(fn ($row) => data_get($row->metadata, 'retrieval_decision.accepted') === true);
        $linked = $accepted->filter(fn ($row) => $row->outcome_linked_at !== null);
        return ['protocol' => self::PROTOCOL, 'available' => true, 'compatible_memory_opportunities' => $compatible->count(), 'memory_considered' => $considered->count(),
            'memory_accepted' => $accepted->count(), 'memory_abstained' => $compatible->count() - $accepted->count(), 'retrieval_to_outcome_linkage' => $linked->count(),
            'considered_rate' => $compatible->count() ? round($considered->count() / $compatible->count(), 4) : 1.0,
            'linkage_rate' => $accepted->count() ? round($linked->count() / $accepted->count(), 4) : 1.0, 'promotion_evidence' => false];
    }
}
