<?php

namespace App\Services;

use App\Models\CooperativeModuleSpeciesMember;
use App\Models\LabAgent;
use Illuminate\Support\Facades\Schema;

/** Builds temporary organisms from independently tracked module species. */
class CooperativeModuleSpeciesService
{
    public const PROTOCOL = 'cooperative_module_species_v1';

    public const SPECIES = [
        'strategy', 'model_regime_router', 'tactic', 'toolbox_instrument',
        'risk', 'trade_management', 'activation_router',
    ];

    /** @return array<string,mixed> */
    public function assemble(array $slot, array $cell, array $arm = [], ?array $idea = null): array
    {
        $niche = (array) data_get($slot, 'niche', []);
        $passport = (array) data_get($niche, 'composition_passport', []);
        $passportComponents = (array) data_get($passport, 'components', []);
        $tools = array_values(array_unique(array_filter(array_map('strval', [
            ...(array) data_get($passport, 'decision_tools', []),
            ...(array) data_get($niche, 'instrument_bundle.instrument_keys', []),
            ...(array) data_get($niche, 'instrument_pair.instrument_ids', []),
            ...(array) data_get($niche, 'learning_evolution.inherited_components', []),
        ]))));
        sort($tools);
        $components = [
            'strategy' => (string) (data_get($passportComponents, 'strategy_id') ?: data_get($niche, 'strategy_library_id') ?: data_get($slot, 'family', 'hybrid')),
            'model_regime_router' => (string) (data_get($niche, 'regime_classifier_variant') ?: 'regime_router'),
            'tactic' => (string) (data_get($passportComponents, 'tactic_id') ?: data_get($niche, 'tactic_library_key') ?: 'context_default_tactic'),
            'toolbox_instrument' => $tools !== [] ? $tools : ['no_optional_instrument'],
            'risk' => (string) (data_get($passportComponents, 'risk_id') ?: data_get($niche, 'risk_library_id') ?: 'atr_risk_envelope'),
            'trade_management' => (string) (data_get($passportComponents, 'management_id') ?: data_get($niche, 'management_id') ?: 'balanced_professional'),
            'activation_router' => [
                'cell_hash' => data_get($cell, 'cell_hash'), 'outside_scope_action' => 'WAIT',
                'maintenance_action' => data_get($cell, 'execution_policy') === 'abstain_only' ? 'WAIT' : 'context_owned',
            ],
        ];
        $componentHashes = collect($components)->map(fn (mixed $value, string $species): string => hash(
            'sha256', json_encode([$species, $value], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)
        ))->all();
        $capsule = [
            'protocol' => self::PROTOCOL,
            'components' => $components,
            'component_hashes' => $componentHashes,
            'arm' => $arm,
            'idea_reference' => $idea,
            'context_cell_hash' => data_get($cell, 'cell_hash'),
            'genome_hash' => hash('sha256', json_encode([$components, data_get($cell, 'cell_hash'), $arm], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)),
            'temporary_organism' => true,
            'module_authority_is_context_local' => true,
            'unsettled_modules_are_research_only' => true,
            'promotion_evidence' => false,
        ];

        return $capsule;
    }

    /** @return array<string,mixed> */
    public function populationContract(array $capsules): array
    {
        $populations = [];
        foreach (self::SPECIES as $species) {
            $values = collect($capsules)->map(fn (array $capsule): mixed => data_get($capsule, "components.{$species}"))
                ->map(fn (mixed $value): string => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION))
                ->unique()->values();
            $populations[$species] = ['member_count' => $values->count(), 'members' => $values->map(fn (string $value): mixed => json_decode($value, true))->all()];
        }
        return ['protocol' => self::PROTOCOL, 'species' => $populations,
            'organism_rule' => 'An agent is a temporary context-bound composition assembled from all seven species.',
            'global_winner_required' => false, 'promotion_evidence' => false];
    }

    public function recordMembers(LabAgent $agent, array $capsule, array $evidence = []): void
    {
        if (! Schema::hasTable('cooperative_module_species_members')) return;
        $cellKey = (string) data_get($capsule, 'context_cell_hash', '');
        foreach (self::SPECIES as $species) {
            $component = data_get($capsule, "components.{$species}");
            $componentKey = hash('sha256', json_encode([$species, $component], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
            $memberKey = hash('sha256', implode('|', [self::PROTOCOL, $agent->model_version_id, $cellKey, $species, $componentKey]));
            CooperativeModuleSpeciesMember::query()->updateOrCreate(['member_key' => $memberKey], [
                'symbol' => strtoupper((string) $agent->symbol), 'timeframe' => strtoupper((string) $agent->timeframe),
                'species' => $species, 'component_key' => $componentKey, 'context_cell_key' => $cellKey ?: null,
                'model_version_id' => $agent->model_version_id, 'lab_agent_id' => $agent->id,
                'genome' => ['component' => $component, 'capsule_genome_hash' => data_get($capsule, 'genome_hash')],
                'evidence' => $evidence, 'authority_level' => 'hypothesis', 'status' => 'research',
            ]);
        }
    }
}
