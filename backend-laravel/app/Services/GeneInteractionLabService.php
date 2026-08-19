<?php

namespace App\Services;

use App\Models\LabGeneInteraction;
use App\Models\LabMutationResponseMap;
use Illuminate\Support\Facades\Schema;

/** Builds a research frontier for gene interactions only after single-gene mentors exist. */
class GeneInteractionLabService
{
    public const PROTOCOL = 'gene_interaction_lab_v1';

    /** @return array{created:int,eligible_groups:int,available:bool} */
    public function prepare(string $symbol, string $timeframe, ?string $family = null, int $limit = 100): array
    {
        if (! Schema::hasTable('lab_gene_interactions')) return ['created' => 0, 'eligible_groups' => 0, 'available' => false];
        $maps = LabMutationResponseMap::query()
            ->with('agent')
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->where('status', 'independently_confirmed')
            ->whereNotNull('parameter_key')
            ->when($family, fn ($query) => $query->where('strategy_family', $family))
            ->latest('id')->limit(max(1, $limit))->get();
        $maps = $maps->filter(fn (LabMutationResponseMap $map): bool =>
            $map->parameter_key !== null
            && ($map->agent === null || count((array) $map->agent->parameter_diff) === 1)
            && data_get($map->metadata, 'causal_credit_eligible', null) !== false
        );
        $groups = $maps->groupBy(fn ($map) => implode('|', [
            $map->strategy_family, data_get($map->metadata, 'specialist_role'), $map->target,
        ]));
        $created = 0;
        foreach ($groups as $group) {
            $genes = $group->pluck('parameter_key')->filter()->unique()->sort()->values()->all();
            if (count($genes) < 2) continue;
            foreach (array_chunk($genes, 2) as $pairGenes) {
                if (count($pairGenes) !== 2) continue;
                $first = $group->firstWhere('parameter_key', $pairGenes[0]);
                $mentorEvidence = $group->whereIn('parameter_key', $pairGenes)->map(fn (LabMutationResponseMap $map): array => [
                    'status' => 'confirmed_shadow_mentor',
                    'response_map_id' => (int) $map->id,
                    'independent_windows' => (int) data_get($map->forward_confirmation, 'independent_forward_windows.independent_windows', 0),
                    'positive_windows' => (int) data_get($map->forward_confirmation, 'independent_forward_windows.positive_windows', 0),
                ])->values()->all();
                $interactionContract = app(CausalSkillCompilerService::class)->interactionContract($pairGenes, $mentorEvidence);
                $key = hash('sha256', json_encode([
                    self::PROTOCOL, strtoupper($symbol), strtoupper($timeframe), $first?->strategy_family,
                    data_get($first?->metadata, 'specialist_role'), $first?->target, $pairGenes,
                ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
                LabGeneInteraction::query()->firstOrCreate(
                    ['interaction_key' => $key],
                    [
                        'symbol' => strtoupper($symbol), 'timeframe' => strtoupper($timeframe),
                        'family' => $first?->strategy_family,
                        'specialist_role' => data_get($first?->metadata, 'specialist_role'),
                        'target' => $first?->target, 'genes' => $pairGenes,
                        'mentor_ids' => $group->whereIn('parameter_key', $pairGenes)->pluck('id')->values()->all(),
                        'status' => 'awaiting_interaction_replay',
                        'evidence' => [
                            'protocol' => self::PROTOCOL,
                            'interaction_contract' => $interactionContract,
                            'exact_control_required' => true,
                            'three_windows_required' => true,
                            'promotion_evidence' => false,
                        ],
                        'promotion_evidence' => false,
                    ],
                );
                $created++;
            }
        }
        return ['created' => $created, 'eligible_groups' => $groups->filter(fn ($group) => $group->pluck('parameter_key')->filter()->unique()->count() >= 2)->count(), 'available' => true];
    }

    /** @return array<string, mixed> */
    public function progress(string $symbol, string $timeframe): array
    {
        if (! Schema::hasTable('lab_gene_interactions')) return ['available' => false];
        $query = LabGeneInteraction::query()->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe));
        return [
            'available' => true,
            'total' => (clone $query)->count(),
            'awaiting_replay' => (clone $query)->where('status', 'awaiting_interaction_replay')->count(),
            'confirmed' => (clone $query)->where('status', 'confirmed')->count(),
        ];
    }
}
