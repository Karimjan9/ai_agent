<?php

namespace Tests\Feature;

use App\Services\CuratedInstrumentMethodCatalogService;
use App\Services\InstrumentMethodLibraryService;
use App\Services\StrategyParameterSchemaService;
use App\Services\TypedInstrumentFoundryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CuratedExpandedInstrumentMethodCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_v2_is_explicit_append_only_and_default_v1_remains_identical(): void
    {
        $owner = app(CuratedInstrumentMethodCatalogService::class);
        $preview = $owner->catalog();
        $original = $owner->install();
        $selection = $owner->installedSelection();
        $library = app(InstrumentMethodLibraryService::class)->librarySnapshot($original['typed_recipe_hashes']);
        $expanded = $owner->install($this->selection());
        $this->assertSame('curated_research_catalog_installed', $expanded['status'], json_encode($expanded));
        $this->assertSame($expanded, $owner->install($this->selection()));
        $this->assertSame($preview, $owner->catalog());
        $this->assertSame($original, $owner->install());
        $this->assertSame($selection, $owner->installedSelection());
        foreach ($original['method_hashes'] as $key => $hash) $this->assertSame($hash, $expanded['method_hashes'][$key]);
        foreach ($original['source_hashes'] as $key => $hash) $this->assertSame($hash, $expanded['source_hashes'][$key]);
        foreach ($original['recipe_hashes'] as $key => $hash) $this->assertSame($hash, $expanded['recipe_hashes'][$key]);
        $this->assertSame($library, app(InstrumentMethodLibraryService::class)->verifyLibrary($library));
        $fixedSelection = $owner->installedSelection($this->selection());
        $this->assertSame('curated_research_selection', $fixedSelection['status']);
        $this->assertCount(38, $fixedSelection['recipe_hashes']);
        $this->assertSame($expanded['recipe_hashes'], $fixedSelection['recipe_hashes']);
        $this->assertDatabaseCount('instrument_method_revisions', 30);
        $this->assertDatabaseCount('instrument_recipe_revisions', 38);
        foreach (['agent_method_policy_revisions', 'agent_method_experiences', 'model_versions', 'jobs', 'lab_evolution_credit_events'] as $table) $this->assertDatabaseCount($table, 0);
    }

    public function test_all_twenty_six_cards_have_real_usage_declarations_without_claiming_missing_data(): void
    {
        $catalog = app(CuratedInstrumentMethodCatalogService::class)->catalog($this->selection());
        $this->assertSame('curated_research_catalog', $catalog['status'], json_encode($catalog));
        $this->assertSame(['instruments' => 26, 'sources' => 17, 'construction_adapters' => 6,
            'method_classes' => 28, 'method_revisions' => 30, 'typed_entry_veto_methods' => 20,
            'typed_entry_veto_revisions' => 22, 'hybrids' => 8, 'explicit_dependencies' => 0,
            'typed_risk_reduction_revisions' => 1, 'typed_owned_exit_revisions' => 1,
            'data_conditional_instruments' => 3], $catalog['counts']);
        $this->assertSame('paper_only', $catalog['policy']['all_2026_and_later_market_data']);
        foreach ($catalog['coverage'] as $coverage) {
            $this->assertNotEmpty($coverage['method_refs']);
            $this->assertNotEmpty($coverage['next_proof']);
            $this->assertArrayHasKey('data_dependencies', $coverage);
        }
        foreach (['volume_confirmation', 'meta_label_filter', 'cost_firewall'] as $key) {
            $this->assertSame('data_conditional_research_method', $catalog['coverage'][$key]['status']);
            $this->assertNotEmpty($catalog['coverage'][$key]['data_dependencies']);
        }
        $this->assertStringContainsString('NOT a trained', $catalog['methods'][27]['known_limitations'][2] ?? '');
        foreach (['agent_skill_changed', 'qualification_granted', 'economic_authority', 'paper_authority', 'live_authority'] as $claim) $this->assertFalse($catalog[$claim]);
        $this->assertSame('CURATED_METHOD_EXACT_CATALOG_REQUIRED', app(CuratedInstrumentMethodCatalogService::class)->install(['catalog_key' => CuratedInstrumentMethodCatalogService::EXPANDED_CATALOG_KEY])['reason']);
        $this->assertSame('CURATED_METHOD_EXACT_CATALOG_REQUIRED', app(CuratedInstrumentMethodCatalogService::class)->catalog([...$this->selection(), 'latest' => true])['reason']);
    }

    public function test_every_new_single_and_hybrid_compiles_and_computed_features_require_owner_marker(): void
    {
        $installed = app(CuratedInstrumentMethodCatalogService::class)->install($this->selection());
        $this->assertSame('curated_research_catalog_installed', $installed['status'], json_encode($installed));
        $library = app(InstrumentMethodLibraryService::class);
        $targets = [];
        foreach ($installed['recipe_hashes'] as $key => $hash) {
            $recipe = $library->inspectRecipe($hash)['contract'];
            $family = 'differential_router';
            foreach ($recipe['method_contracts'] as $method) if ($method['implementation']['kind'] === 'parameter_adapter') $family = $method['implementation']['strategy_family'];
            $schema = app(StrategyParameterSchemaService::class);
            $parameters = array_intersect_key($schema->defaults($family), $schema->schema($family));
            foreach ($recipe['method_contracts'] as $method) if ($method['implementation']['kind'] === 'parameter_adapter') $parameters = [...$parameters, ...$method['implementation']['parameters']];
            $compiled = $library->compileRecipe($hash, ['strategy_family' => $family, 'parameters' => $parameters],
                ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'pre_2026_only' => true, 'data_hash' => str_repeat('a', 64),
                    'execution_hash' => str_repeat('b', 64), 'context_predicate' => $recipe['context_predicate']]);
            $this->assertContains($compiled['status'], ['compiled_research_recipe', 'parameter_adapter_recipe'], $key.': '.json_encode($compiled));
            $this->assertSame($parameters, $compiled['parameters']);
            foreach ($compiled['runtime_recipe']['operators'] as $operator) {
                $targets[$operator['target']] = true;
                foreach ($operator['input_bindings'] as $field) if (str_starts_with($field, 'method_')) {
                    $this->assertSame(InstrumentMethodLibraryService::FEATURE_OWNER, $operator['source_context']['method_feature_owner']);
                }
            }
        }
        $this->assertEqualsCanonicalizing(['confirmation', 'risk_multiplier', 'exit'], array_keys($targets));
        $this->assertDatabaseCount('agent_method_policy_revisions', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_new_method_field_types_are_exact_and_unknown_or_caller_marker_inputs_are_refused(): void
    {
        $owner = app(CuratedInstrumentMethodCatalogService::class);
        $installed = $owner->install($this->selection());
        $library = app(InstrumentMethodLibraryService::class);
        $method = $library->inspectMethod($installed['method_hashes']['internet_confirmed_structure_break_v2@1'])['contract'];
        $input = array_intersect_key($method, array_flip(['instrument_key', 'method_key', 'revision', 'context_predicate', 'horizon', 'source_keys', 'known_limitations', 'implementation']));
        $input['method_key'] = 'invalid_feature_type';
        $input['implementation']['input_bindings']['method_bos'] = 'method_atr_ratio';
        $this->assertSame('METHOD_TYPED_AST_UNSUPPORTED_OR_UNBOUND', $library->registerMethod($input)['reason']);
        $input['implementation']['input_bindings']['method_bos'] = 'method_user_invented';
        $this->assertSame('METHOD_TYPED_AST_UNSUPPORTED_OR_UNBOUND', $library->registerMethod($input)['reason']);
        $input['implementation']['input_bindings']['method_bos'] = 'method_bos';
        $input['implementation']['method_feature_owner'] = InstrumentMethodLibraryService::FEATURE_OWNER;
        $this->assertSame('METHOD_TYPED_AST_UNSUPPORTED_OR_UNBOUND', $library->registerMethod($input)['reason']);

        $ast = ['op' => 'BOOL', 'available_at' => '1900-01-01T00:00:00Z', 'input_key' => 'method_bos'];
        $foundry = app(TypedInstrumentFoundryService::class);
        $program = $foundry->compileResearchCandidate($ast, ['symbol' => 'XAUUSD', 'timeframe' => 'M5',
            'pre_2026_only' => true, 'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64)], 'not-method-owner');
        $result = $foundry->decisionOperatorContract($program['program_key'], 'confirmation', ['method_bos' => 'method_bos'],
            ['cpu_seconds' => .1, 'max_calls' => 2000, 'max_node_evaluations' => 96000]);
        $this->assertSame('DECISION_OPERATOR_METHOD_FEATURE_OWNER_REQUIRED', $result['reason']);
        $context = ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'pre_2026_only' => true,
            'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64),
            'baseline_hash' => str_repeat('c', 64), 'method_feature_owner' => InstrumentMethodLibraryService::FEATURE_OWNER];
        $program = $foundry->compileResearchCandidate($ast, $context, 'wrong-method-field-type');
        $result = $foundry->decisionOperatorContract($program['program_key'], 'confirmation', ['method_bos' => 'method_atr_ratio'],
            ['cpu_seconds' => .1, 'max_calls' => 2000, 'max_node_evaluations' => 96000]);
        $this->assertSame('DECISION_OPERATOR_METHOD_FEATURE_TYPE_INVALID', $result['reason']);
    }

    public function test_php_python_feature_type_contract_matches_without_optional_or_unknown_fields(): void
    {
        $script = 'import json; from app.services.instrument_method_features import BOOL_FIELDS,NUMBER_FIELDS; print(json.dumps({"bool":sorted(BOOL_FIELDS),"number":sorted(NUMBER_FIELDS)}))';
        $process = new Process(['python', '-c', $script], dirname(base_path()).'/ai-service-python');
        $process->setTimeout(30)->mustRun();
        $result = json_decode($process->getOutput(), true, 64, JSON_THROW_ON_ERROR);
        $this->assertEqualsCanonicalizing(InstrumentMethodLibraryService::FEATURE_BOOL_INPUTS, $result['bool']);
        $this->assertEqualsCanonicalizing(InstrumentMethodLibraryService::FEATURE_NUMBER_INPUTS, $result['number']);
    }

    private function selection(): array
    {
        return ['catalog_key' => CuratedInstrumentMethodCatalogService::EXPANDED_CATALOG_KEY,
            'catalog_sha256' => CuratedInstrumentMethodCatalogService::EXPANDED_CATALOG_SHA256];
    }
}
