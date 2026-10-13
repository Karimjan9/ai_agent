<?php

namespace Tests\Feature;

use App\Services\AgentMethodLearningService;
use App\Services\CuratedInstrumentMethodCatalogService;
use App\Services\InstrumentMethodLibraryService;
use App\Services\InstrumentMethodResearchBindingService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CuratedInstrumentMethodCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_covers_all_cards_but_names_real_readiness_and_never_agent_mastery(): void
    {
        $catalog = app(CuratedInstrumentMethodCatalogService::class)->catalog();
        $this->assertSame('curated_research_catalog', $catalog['status'], json_encode($catalog));
        $this->assertCount(26, $catalog['coverage']);
        $this->assertSame(['instruments' => 26, 'sources' => 8, 'construction_adapters' => 6,
            'method_classes' => 8, 'method_revisions' => 10, 'typed_entry_veto_methods' => 2,
            'typed_entry_veto_revisions' => 4, 'hybrids' => 3, 'explicit_dependencies' => 18], $catalog['counts']);
        $this->assertSame('paper_only', $catalog['policy']['all_2026_and_later_market_data']);
        foreach ($catalog['coverage'] as $card) {
            $this->assertNotEmpty($card['next_proof']);
            if ($card['status'] === 'planned_dependency') $this->assertSame([], $card['method_refs']);
        }
        $this->assertFalse($catalog['agent_skill_changed']);
        $this->assertFalse($catalog['qualification_granted']);
        $this->assertDatabaseCount('instrument_method_sources', 0);
        $this->assertDatabaseCount('agent_method_policy_revisions', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_explicit_install_is_atomic_idempotent_and_does_not_write_agent_or_authority_state(): void
    {
        $service = app(CuratedInstrumentMethodCatalogService::class);
        $installed = $service->install();
        $this->assertSame('curated_research_catalog_installed', $installed['status'], json_encode($installed));
        $this->assertSame($installed, $service->install(['catalog_key' => $installed['catalog_key'], 'catalog_sha256' => $installed['catalog_sha256']]));
        $this->assertCount(2, $installed['typed_recipe_hashes']);
        $this->assertCount(2, $installed['typed_library_levels']['L1']);
        $this->assertCount(2, $installed['typed_library_levels']['L2']);
        $this->assertSame($installed['typed_recipe_hashes'], $installed['typed_library_levels']['L1']);
        $this->assertDatabaseCount('instrument_method_sources', 8);
        $this->assertDatabaseCount('instrument_method_revisions', 10);
        $this->assertDatabaseCount('instrument_recipe_revisions', 13);
        $this->assertDatabaseCount('agent_method_policy_revisions', 0);
        $this->assertDatabaseCount('agent_method_experiences', 0);
        $this->assertDatabaseCount('model_versions', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        $this->assertDatabaseCount('jobs', 0);
        foreach (DB::table('instrument_method_sources')->get() as $source) {
            $metadata = json_decode($source->metadata, true, 64, JSON_THROW_ON_ERROR);
            $this->assertSame('curator_paraphrase_not_page_snapshot', $metadata['representation']);
            $this->assertFalse($metadata['exposure']['complete_inventory']);
            $this->assertFalse($metadata['exposure']['unused_market_data_proven']);
            $this->assertNotEmpty($metadata['license']);
            $this->assertStringStartsWith('https://', $source->reference);
        }
    }

    public function test_fixed_selection_reopens_exact_revisions_without_using_ambient_latest_registry(): void
    {
        $service = app(CuratedInstrumentMethodCatalogService::class);
        $this->assertSame('CURATED_METHOD_INSTALL_REQUIRED', $service->installedSelection()['reason']);
        $installed = $service->install();
        $selection = $service->installedSelection();
        $this->assertSame('curated_research_selection', $selection['status'], json_encode($selection));
        $this->assertSame($installed['typed_library_levels'], $selection['typed_library_levels']);
        $hash = $installed['method_hashes']['internet_signal_score_veto_0_7_v1@1'];
        $contract = app(InstrumentMethodLibraryService::class)->inspectMethod($hash)['contract'];
        $newInput = array_intersect_key($contract, array_flip(['instrument_key', 'method_key', 'revision', 'context_predicate', 'horizon', 'source_keys', 'known_limitations', 'implementation']));
        $new = app(InstrumentMethodLibraryService::class)->registerMethod([...$newInput, 'method_key' => 'unrelated_method', 'revision' => 1]);
        $this->assertSame('registered_research_only', $new['status']);
        $this->assertSame($selection, $service->installedSelection());
        DB::table('instrument_method_sources')->where('source_key', $installed['source_hashes']['fidelity_atr'])->update(['content' => 'changed']);
        $this->assertSame('METHOD_ORIGINAL_SOURCE_DRIFT', $service->installedSelection()['reason']);
    }

    public function test_collision_rolls_back_new_install_rows_and_keeps_existing_immutable_history(): void
    {
        $service = app(CuratedInstrumentMethodCatalogService::class);
        $installed = $service->install();
        $key = 'internet_closed_atr_absolute_ceiling_v1@1';
        $old = DB::table('instrument_method_revisions')->where('method_hash', $installed['method_hashes'][$key])->first();
        // Simulate a corrupt original; reinstall must not "repair" history or create partial replacements.
        DB::table('instrument_method_revisions')->where('method_hash', $old->method_hash)->update(['contract' => '{}']);
        $counts = [DB::table('instrument_method_sources')->count(), DB::table('instrument_method_revisions')->count(), DB::table('instrument_recipe_revisions')->count()];
        $this->assertSame('METHOD_IMMUTABLE_CONTRACT_DRIFT', $service->install()['reason']);
        $this->assertSame($counts, [DB::table('instrument_method_sources')->count(), DB::table('instrument_method_revisions')->count(), DB::table('instrument_recipe_revisions')->count()]);
        $this->assertSame('{}', DB::table('instrument_method_revisions')->where('method_hash', $old->method_hash)->value('contract'));
        $this->assertSame('CURATED_METHOD_EXACT_CATALOG_REQUIRED', $service->install(['catalog_key' => 'changed'])['reason']);
        $this->assertSame('CURATED_METHOD_EXACT_CATALOG_REQUIRED', $service->install(['confirmed' => true])['reason']);
    }

    public function test_first_revision_collision_cannot_leave_a_partially_installed_source_catalog(): void
    {
        $library = app(InstrumentMethodLibraryService::class);
        $source = $library->registerSource(['kind' => 'imported_text', 'content' => 'Earlier distinct hypothesis.',
            'metadata' => ['license' => 'Original test declaration', 'access' => 'user_supplied', 'exposure' => ['status' => 'unknown']]]);
        $existing = $library->registerMethod(['instrument_key' => 'trend_pullback', 'method_key' => 'internet_ema_21_55_trend_v1',
            'revision' => 1, 'horizon' => 'hour', 'context_predicate' => ['regime' => 'trend_up', 'session' => 'london', 'volatility' => 'normal'],
            'source_keys' => [$source['source_key']], 'known_limitations' => ['Earlier distinct immutable hypothesis.'],
            'implementation' => ['kind' => 'parameter_adapter', 'strategy_family' => 'ema_rsi', 'parameters' => ['ema_fast' => 22, 'ema_slow' => 55]]]);
        $this->assertSame('registered_research_only', $existing['status']);
        $result = app(CuratedInstrumentMethodCatalogService::class)->install();
        $this->assertSame('METHOD_IMMUTABLE_REVISION_COLLISION', $result['reason']);
        $this->assertDatabaseCount('instrument_method_sources', 1);
        $this->assertDatabaseCount('instrument_method_revisions', 1);
        $this->assertDatabaseCount('instrument_recipe_revisions', 0);
        $this->assertSame($existing, $library->inspectMethod($existing['method_hash']));
    }

    public function test_all_declared_adapters_and_hybrids_compile_under_exact_compatible_baselines(): void
    {
        $service = app(CuratedInstrumentMethodCatalogService::class);
        $catalog = $service->catalog();
        $installed = $service->install();
        $library = app(InstrumentMethodLibraryService::class);
        foreach ($installed['recipe_hashes'] as $key => $recipeHash) {
            $recipe = $library->inspectRecipe($recipeHash)['contract'];
            $family = 'ema_rsi';
            foreach ($recipe['method_contracts'] as $method) if ($method['implementation']['kind'] === 'parameter_adapter') $family = $method['implementation']['strategy_family'];
            $parameters = $this->baselineParameters($family);
            // Construct the fresh vector explicitly; binding will later refuse any silent in-place vector change.
            foreach ($recipe['method_contracts'] as $method) if ($method['implementation']['kind'] === 'parameter_adapter') $parameters = [...$parameters, ...$method['implementation']['parameters']];
            $compiled = $library->compileRecipe($recipeHash, ['strategy_family' => $family, 'parameters' => $parameters],
                ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'pre_2026_only' => true, 'data_hash' => str_repeat('a', 64),
                    'execution_hash' => str_repeat('b', 64), 'context_predicate' => $recipe['context_predicate']]);
            $this->assertContains($compiled['status'], ['parameter_adapter_recipe', 'compiled_research_recipe'], $key.': '.json_encode($compiled));
            $this->assertFalse($compiled['qualification_granted']);
            $this->assertSame($parameters, $compiled['parameters']);
        }
        $this->assertCount(3, $catalog['hybrids']);
        $this->assertDatabaseCount('agent_method_policy_revisions', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_real_php_to_python_typed_revisions_change_veto_at_declared_boundaries(): void
    {
        $installed = app(CuratedInstrumentMethodCatalogService::class)->install();
        $library = app(InstrumentMethodLibraryService::class);
        $contracts = [];
        foreach ($installed['typed_library_levels'] as $level => $hashes) foreach ($hashes as $recipeHash) {
            $recipe = $library->inspectRecipe($recipeHash)['contract'];
            $registered = app(AgentMethodLearningService::class)->registerPolicy(['agent_key' => $level.':'.$recipe['recipe_key'], 'revision' => 1,
                'recipe_hashes' => [$recipeHash], 'rules' => [['context_predicate' => $recipe['context_predicate'], 'preferred_recipes' => [$recipeHash]]], 'default_action' => 'BASELINE']);
            $this->assertSame('registered_research_only', $registered['status'], json_encode($registered));
            $contract = app(InstrumentMethodResearchBindingService::class)->compilePolicy($registered['policy_hash'],
                ['strategy_family' => 'ema_rsi', 'parameters' => $this->baselineParameters('ema_rsi')],
                ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64),
                    'evaluated_start' => '2025-01-01T00:00:00Z', 'evaluated_end' => '2025-01-02T00:00:00Z',
                    'context_predicate' => $recipe['context_predicate']], $level.':'.$recipe['recipe_key']);
            $contracts[] = ['level' => $level, 'contract' => $contract];
        }
        $script = <<<'PY'
import json, sys
from app.services.instrument_method_learning import InstrumentMethodLearning
results=[]
for item in json.load(sys.stdin):
    contract=item['contract']
    runtime=InstrumentMethodLearning(contract,dataset_hash='a'*64,execution_hash='b'*64,expected_agent_key=contract['expected_agent_key'])
    event=runtime.evaluate({'close':2000.0,'_management_atr':25.0,'signal_confidence':0.75,'market_regime':'trend_up','market_session':'london','volatility_regime':'normal_volatility'},decision_id='curated-original-closed-1',phase='entry',decision_at='2025-01-02T00:00:00Z',observed_at='2025-01-01T23:55:00Z',direction='BUY')
    results.append({'level':item['level'],'event':event,'receipt':runtime.receipt()})
print(json.dumps(results))
PY;
        $process = new Process(['python', '-c', $script], dirname(base_path()).'/ai-service-python');
        $process->setTimeout(30)->setInput(json_encode($contracts))->mustRun();
        $results = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(4, $results);
        foreach ($results as $result) {
            $this->assertTrue($result['event']['used']);
            $this->assertSame($result['level'] === 'L1', $result['event']['outputs']['confirmation']);
            $this->assertFalse($result['receipt']['economic_authority']);
            $this->assertFalse($result['receipt']['promotion_evidence']);
        }
    }

    public function test_catalog_and_install_commands_are_discovered_without_input_or_network_fetch(): void
    {
        $this->assertSame(0, Artisan::call('trading:instrument-methods', ['action' => 'catalog', '--json' => true]));
        $preview = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('curated_research_catalog', $preview['status']);
        $this->assertDatabaseCount('instrument_method_sources', 0);
        $this->assertSame(0, Artisan::call('trading:instrument-methods', ['action' => 'install', '--json' => true]));
        $result = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('curated_research_catalog_installed', $result['status'], json_encode($result));
        $this->assertArrayNotHasKey('content', $result);
        $this->assertSame(1, Artisan::call('trading:instrument-methods', ['action' => 'install', '--fetch' => true, '--json' => true]));
        $this->assertDatabaseCount('agent_method_policy_revisions', 0);
    }

    private function baselineParameters(string $family): array
    {
        $schema = app(StrategyParameterSchemaService::class);
        return array_intersect_key($schema->defaults($family), $schema->schema($family));
    }
}
