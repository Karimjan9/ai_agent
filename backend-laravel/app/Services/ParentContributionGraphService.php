<?php

namespace App\Services;

use App\Models\LabAgent;

/**
 * Canonical read boundary for parent provenance.
 *
 * parent_a/parent_b are retained as compatibility projections, but they are
 * not the complete evolutionary graph. Every downstream evidence consumer
 * that needs the contributors must resolve this service so a third, fourth or
 * later capability parent cannot disappear merely because an old table had
 * two nullable columns.
 */
class ParentContributionGraphService
{
    public const PROTOCOL = 'lab_agent_parent_graph_v1';

    /** @return array<int, int> */
    public function ids(LabAgent $agent): array
    {
        $agent->loadMissing(['modelVersion', 'parentLinks']);
        $metadata = (array) ($agent->modelVersion?->metadata ?? []);
        $ids = [
            $agent->parent_a_model_version_id,
            $agent->parent_b_model_version_id,
            ...$agent->parentLinks->pluck('parent_model_version_id')->all(),
            ...((array) data_get($metadata, 'adaptive_parent_ecosystem.selected_parent_model_version_ids', [])),
            ...((array) data_get($metadata, 'semantic_lineage.genetic_parent_model_version_ids', [])),
            ...((array) data_get($metadata, 'parent_contribution_graph.all_parent_model_version_ids', [])),
            ...collect((array) data_get($metadata, 'capability_gene_provenance', []))
                ->map(fn ($provenance): mixed => data_get($provenance, 'source_parent_id'))
                ->all(),
        ];

        return array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn (int $id): bool => $id > 0,
        )));
    }

    public function primaryId(LabAgent $agent): ?int
    {
        return $this->ids($agent)[0] ?? null;
    }

    /** @return array<string, mixed> */
    public function snapshot(LabAgent $agent): array
    {
        $agent->loadMissing(['parentLinks']);
        $ids = $this->ids($agent);

        return [
            'protocol' => self::PROTOCOL,
            'parent_model_version_ids' => $ids,
            'primary_parent_model_version_id' => $ids[0] ?? null,
            'links' => $agent->parentLinks->map(fn ($link): array => [
                'parent_model_version_id' => (int) $link->parent_model_version_id,
                'relation_type' => $link->relation_type,
                'contribution_key' => $link->contribution_key,
                'metadata' => $link->metadata,
            ])->values()->all(),
            'complete' => $ids === [] || $agent->parentLinks->isNotEmpty(),
            'promotion_evidence' => false,
        ];
    }
}
