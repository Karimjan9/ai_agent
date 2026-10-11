<?php

namespace Tests\Unit;

use App\Models\ModelVersion;
use App\Services\CompositionAuthorityKernelService;
use App\Services\LabInstrumentResearchService;
use App\Services\MultiTimeframeSnapshotService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\TacticCatalogueService;
use Tests\TestCase;

class ProspectiveCompositionRecipeTest extends TestCase
{
    public function test_future_request_recompiles_exact_program_without_updating_the_persisted_source(): void
    {
        $owner = app(CompositionAuthorityKernelService::class);
        $source = $this->source();
        $original = $source->getAttributes();
        $recipe = $owner->prospectiveRecipeFromMetadata($source);
        $compiled = $owner->compileProspectiveRecipeForRequest($source, $this->scope($recipe));

        $this->assertSame('compiled_prospective_recipe', $compiled['status']);
        $this->assertNotSame($source, $compiled['model']);
        $this->assertSame($source->id, $compiled['model']->id);
        $this->assertSame($original, $source->getAttributes());
        $this->assertFalse($source->isDirty());
        $this->assertTrue($source->exists);
        $this->assertSame($source->parameters, $compiled['model']->parameters);
        $this->assertSame($this->hash($recipe['program_tuple']['components']), $this->hash($compiled['passport']['components']));
        $this->assertSame($this->hash($recipe['program_tuple']['typed_program']), $this->hash($compiled['passport']['typed_program']));
        $this->assertSame($recipe, $compiled['recipe']);
        $this->assertSame($this->hash($recipe), $compiled['recipe_hash']);
        $this->assertSame(str_repeat('b', 64), data_get($compiled, 'passport.provenance.data_hash'));
        $this->assertSame(str_repeat('e', 64), data_get($compiled, 'passport.provenance.execution_hash'));
        $this->assertNotSame(data_get($source->metadata, 'smart_composition.composition_passport.composition_id'),
            $compiled['passport']['composition_id']);
        $this->assertArrayNotHasKey('instrument_research_assignment', $compiled['model']->metadata);
        $this->assertSame(['atr_risk_envelope'], $recipe['instrument_assignment_semantics']['selected_keys']);
        $this->assertArrayNotHasKey('parameter_hash', $recipe['instrument_assignment_semantics']);
        $this->assertArrayNotHasKey('assignment_hash', $recipe['instrument_assignment_semantics']);
        $this->assertArrayNotHasKey('composition_id', $recipe['source_passport_semantics']);
        $this->assertArrayNotHasKey('data_hash', $recipe['source_passport_semantics']['provenance']);
    }

    public function test_child_parameters_are_preserved_under_the_original_parameter_owned_manager(): void
    {
        $owner = app(CompositionAuthorityKernelService::class);
        $child = $this->source();
        $recipe = $owner->prospectiveRecipeFromMetadata($child);
        $child->parameters = [...$child->parameters, 'time_stop_candles' => 53, 'partial_take_profit_fraction' => .27];
        $recipe['parameters'] = $child->parameters;
        $child->metadata = [...$child->metadata, CompositionAuthorityKernelService::PROSPECTIVE_RECIPE_METADATA => $recipe,
            CompositionAuthorityKernelService::PROSPECTIVE_RECIPE_METADATA.'_hash' => $this->hash($recipe)];
        $child->syncOriginal();
        $original = $child->getAttributes();

        $compiled = $owner->compileProspectiveRecipeForRequest($child, $this->scope($recipe));

        $this->assertSame($original, $child->getAttributes());
        $this->assertSame($recipe['parameters'], $compiled['model']->parameters);
        $this->assertSame($recipe['source_parameters'], $compiled['recipe']['source_parameters']);
        $this->assertSame('parameter_preserving_research', data_get($compiled, 'passport.components.management_id'));
        $this->assertSame([], data_get($compiled, 'passport.management_contract.runtime_adapter.overrides'));
    }

    public function test_a_plain_native_strategy_keeps_its_primitive_recipe_and_never_gets_a_compound_passport(): void
    {
        $owner = app(CompositionAuthorityKernelService::class);
        $source = $this->source(false);
        $original = $source->getAttributes();
        $recipe = $owner->prospectiveRecipeFromMetadata($source);

        $compiled = $owner->compileProspectiveRecipeForRequest($source, $this->scope($recipe));

        $this->assertSame([], $compiled['passport']);
        $this->assertSame([], $recipe['program_tuple']);
        $this->assertSame([], $recipe['source_passport_semantics']);
        $this->assertSame($source->parameters, $compiled['model']->parameters);
        $this->assertSame($original, $source->getAttributes());
        $this->assertArrayNotHasKey('smart_composition', $compiled['model']->metadata);
    }

    public function test_the_original_recipe_seal_is_required_and_parameter_drift_is_rejected(): void
    {
        $owner = app(CompositionAuthorityKernelService::class);
        $source = $this->source();
        $recipe = $owner->prospectiveRecipeFromMetadata($source);
        $source->parameters = [...$source->parameters, 'lookback' => 99];

        $this->expectExceptionMessage('PROSPECTIVE_RECIPE_ORIGINAL_SEAL_REQUIRED');
        $owner->compileProspectiveRecipeForRequest($source, $this->scope($recipe));
    }

    public function test_a_changed_stored_program_recipe_cannot_be_resealed_at_request_time(): void
    {
        $owner = app(CompositionAuthorityKernelService::class);
        $source = $this->source();
        $recipe = $owner->prospectiveRecipeFromMetadata($source);
        $metadata = [...$source->metadata, CompositionAuthorityKernelService::PROSPECTIVE_RECIPE_METADATA => $recipe];
        $metadata['tactic_contract']['architecture'] = 'unregistered_tactic';
        $source->metadata = $metadata;

        $this->expectExceptionMessage('PROSPECTIVE_RECIPE_SOURCE_SEMANTIC_DRIFT');
        $owner->compileProspectiveRecipeForRequest($source, $this->scope($recipe));
    }

    public function test_a_passport_the_existing_compiler_cannot_reproduce_is_rejected(): void
    {
        $owner = app(CompositionAuthorityKernelService::class);
        $source = $this->source();
        $metadata = $source->metadata;
        $metadata['smart_composition']['composition_passport']['typed_program']['nodes'][0]['provides'] = 'FabricatedCapability';
        $source->metadata = $metadata;
        $recipe = $owner->prospectiveRecipeFromMetadata($source);

        $this->expectExceptionMessage('PROSPECTIVE_RECIPE_COMPILER_SEMANTIC_DRIFT');
        $owner->compileProspectiveRecipeForRequest($source, $this->scope($recipe));
    }

    public function test_original_management_cannot_silently_erase_a_new_parameter_intervention(): void
    {
        $owner = app(CompositionAuthorityKernelService::class);
        $source = $this->source(true, 'balanced_professional');
        $recipe = $owner->prospectiveRecipeFromMetadata($source);
        $source->parameters = [...$source->parameters, 'time_stop_candles' => 53];
        $recipe['parameters'] = $source->parameters;
        $source->metadata = [...$source->metadata, CompositionAuthorityKernelService::PROSPECTIVE_RECIPE_METADATA => $recipe];

        $this->expectExceptionMessage('PROSPECTIVE_RECIPE_INTERVENTION_OVERRIDDEN_BY_SOURCE_MANAGEMENT');
        $owner->compileProspectiveRecipeForRequest($source, $this->scope($recipe));
    }

    public function test_the_original_legal_mask_can_exclude_manager_owned_genes_before_selection(): void
    {
        $owner = app(CompositionAuthorityKernelService::class);
        $recipe = $owner->prospectiveRecipeFromMetadata($this->source(true, 'balanced_professional'));
        $this->assertTrue($owner->prospectiveParameterInterventionAllowed($recipe, 'lookback'));
        $this->assertFalse($owner->prospectiveParameterInterventionAllowed($recipe, 'time_stop_candles'));
        $this->assertFalse($owner->prospectiveParameterInterventionAllowed($recipe, 'atr_target_multiplier'));
        $preserving = $owner->prospectiveRecipeFromMetadata($this->source());
        $this->assertTrue($owner->prospectiveParameterInterventionAllowed($preserving, 'time_stop_candles'));
    }

    public function test_missing_actual_stream_identity_cannot_create_a_future_request_passport(): void
    {
        $owner = app(CompositionAuthorityKernelService::class);
        $source = $this->source();
        $recipe = $owner->prospectiveRecipeFromMetadata($source);
        $scope = $this->scope($recipe);
        unset($scope['mtf_manifest']['streams']['H4']);

        $this->expectExceptionMessage('PROSPECTIVE_RECIPE_ACTUAL_MTF_IDENTITY_REQUIRED');
        $owner->compileProspectiveRecipeForRequest($source, $scope);
    }

    public function test_programme_parity_ignores_only_parameter_vectors_public_labels_and_old_experiment_bindings(): void
    {
        $owner = app(CompositionAuthorityKernelService::class);
        $guided = $this->source(); $control = $this->source();
        $guidedMetadata = $guided->metadata; $controlMetadata = $control->metadata;
        $guidedMetadata['causal_learning_cohort'] = ['role' => 'memory_guided', 'experiment_key' => 'old-guided',
            'source_lesson_id' => 41, 'gene' => 'lookback', 'value' => 28, 'source_context_scope' => ['session' => 'london'],
            'blinded_selector' => ['gene' => 'lookback', 'value' => 28, 'seed' => 'old-guided',
                'operational_veto' => ['maximum_score' => .9]],
            'operational_veto' => ['minimum_context_confidence' => .6]];
        $controlMetadata['causal_learning_cohort'] = [...$guidedMetadata['causal_learning_cohort'],
            'role' => 'frozen_control', 'experiment_key' => 'old-control', 'source_lesson_id' => 42, 'value' => 30];
        $controlMetadata['causal_learning_cohort']['blinded_selector']['seed'] = 'old-control';
        $controlMetadata['causal_learning_cohort']['blinded_selector']['value'] = 30;
        $guidedMetadata['smart_composition']['composition_passport'] = $owner->bindLearningExperiment(
            $guidedMetadata['smart_composition']['composition_passport'],
            ['role' => 'memory_guided', 'experiment_key' => 'old-guided', 'source_lesson_id' => 41, 'gene' => 'lookback', 'value' => 28]);
        $controlMetadata['smart_composition']['composition_passport'] = $owner->bindLearningExperiment(
            $controlMetadata['smart_composition']['composition_passport'], ['role' => 'frozen_control', 'experiment_key' => 'old-control']);
        $guided->metadata = $guidedMetadata; $control->metadata = $controlMetadata;
        $control->strategy = 'public_control_label'; $control->version = 'other-public-version';
        $control->parameters = [...$control->parameters, 'lookback' => 30];

        $this->assertSame($owner->prospectiveProgrammeHash($guided), $owner->prospectiveProgrammeHash($control));
        $nested = $controlMetadata;
        $nested['causal_learning_cohort']['blinded_selector']['operational_veto']['maximum_score'] = .8;
        $control->metadata = $nested;
        $this->assertNotSame($owner->prospectiveProgrammeHash($guided), $owner->prospectiveProgrammeHash($control));
        $controlMetadata['causal_learning_cohort']['operational_veto']['minimum_context_confidence'] = .7;
        $control->metadata = $controlMetadata;
        $this->assertNotSame($owner->prospectiveProgrammeHash($guided), $owner->prospectiveProgrammeHash($control));
    }

    public function test_a_source_programme_twist_or_cost_limit_change_cannot_share_parameter_only_parity(): void
    {
        $owner = app(CompositionAuthorityKernelService::class);
        $source = $this->source(); $other = $this->source();
        $source->metadata = [...$source->metadata,
            'execution_contract' => ['execution_hash' => str_repeat('a', 64), 'maximum_spread_points' => 10]];
        $other->metadata = [...$other->metadata,
            'execution_contract' => ['execution_hash' => str_repeat('b', 64), 'maximum_spread_points' => 10]];
        $original = $owner->prospectiveProgrammeHash($source);
        $this->assertSame($original, $owner->prospectiveProgrammeHash($other));
        $metadata = $other->metadata;
        $metadata['smart_composition']['composition_passport']['typed_program']['nodes'][0]['veto_codes'][] = 'additional_operator_veto';
        $other->metadata = $metadata;
        $this->assertNotSame($original, $owner->prospectiveProgrammeHash($other));
        $other = $this->source();
        $other->metadata = [...$other->metadata,
            'execution_contract' => ['execution_hash' => str_repeat('b', 64), 'maximum_spread_points' => 11]];
        $this->assertNotSame($original, $owner->prospectiveProgrammeHash($other));
    }

    private function source(bool $compound = true, string $management = 'parameter_preserving_research'): ModelVersion
    {
        $metadata = ['base_strategy' => 'differential_router_v1', 'strategy_family' => 'differential_router',
            'strategy_architecture' => 'frozen_parent_differential_router',
            'tactic_contract' => app(TacticCatalogueService::class)->for('differential_router', 'frozen_parent_differential_router'),
            'causal_learning_cohort' => ['role' => 'frozen_control'],
            'instrument_research_assignment' => ['protocol' => LabInstrumentResearchService::PROTOCOL,
                'assignment_hash' => str_repeat('1', 64), 'parameter_hash' => str_repeat('2', 64),
                'lab_agent_id' => 901, 'model_version_id' => 731, 'selected_keys' => ['atr_risk_envelope'],
                'selected' => [['instrument_key' => 'atr_risk_envelope', 'role' => 'frozen_support']]]];
        if ($compound) $metadata['smart_composition']['composition_passport'] = app(CompositionAuthorityKernelService::class)->freeze([
            'symbol' => 'XAUUSD', 'strategy_id' => 'mix_011_differential_router', 'tactic_id' => 'frozen_parent_differential_router',
            'risk_id' => 'atr_risk_envelope', 'management_id' => $management,
            'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('c', 64),
            'data_contract' => ['m5_canonical' => true, 'closed_at_available_at' => true, 'backward_only_alignment' => true],
            'horizon_mode' => 'day_structure', 'horizon_contract' => ['overnight_allowed' => false],
            'confirmation_families' => ['price_reaction', 'market_structure'],
        ]);
        $model = new ModelVersion;
        $model->setRawAttributes(['id' => 731, 'strategy' => 'differential_router', 'version' => 'source-v1',
            'parameters' => json_encode(['lookback' => 28, 'atr_stop_multiplier' => 1.2, 'time_stop_candles' => 24,
                'partial_take_profit_fraction' => .4, 'structural_vector' => ['entry' => ['enabled' => true]]]),
            'metadata' => json_encode($metadata)], true);
        $model->exists = true;

        return $model;
    }

    private function scope(array $recipe): array
    {
        $hash = str_repeat('b', 64);

        return ['dataset_hash' => $hash, 'execution_hash' => str_repeat('e', 64), 'timeframe' => 'M5',
            'expected_recipe_hash' => $this->hash($recipe),
            'mtf_manifest' => ['protocol' => MultiTimeframeSnapshotService::PROTOCOL, 'bundle_hash' => $hash,
                'streams' => array_fill_keys(['M5', 'M15', 'H1', 'H4'], ['sha256' => str_repeat('d', 64)])]];
    }

    private function hash(array $value): string
    {
        return app(ResearchPaperEpochContractService::class)->parameterHash($value);
    }
}
