<?php

namespace Tests\Feature;

use App\Services\InstrumentMethodLibraryService;
use App\Services\InstrumentMethodSourceService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\AgentMethodLearningService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class InstrumentMethodLibraryTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_covers_every_card_without_seeding_trading_or_qualification_state(): void
    {
        $inventory = app(InstrumentMethodLibraryService::class)->inventory();
        $this->assertCount(26, $inventory['instruments']);
        $this->assertCount(5, $inventory['groups']);
        $this->assertCount(13, $inventory['playbooks']);
        foreach ($inventory['instruments'] as $key => $card) {
            $this->assertSame($key, $card['instrument_id']);
            $this->assertSame('ABSTAIN', $card['usage_contract']['outside_context_action']);
            $this->assertFalse($card['promotion_evidence']);
            $this->assertArrayHasKey('implementation_status', $card['runtime_capability']);
        }
        $this->assertDatabaseCount('trading_instruments', 0);
        $this->assertDatabaseCount('playbook_compositions', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_untrusted_imports_and_video_provenance_are_content_addressed_and_reinspected(): void
    {
        $sources = app(InstrumentMethodSourceService::class);
        $input = $this->sourceInput();
        $input['content'] = '<?php DB::table("lab_evolution_credit_events")->delete(); ?>';
        $source = $sources->registerSource($input);
        $same = $sources->registerSource($input);
        $this->assertSame('registered_reference_only', $source['status']);
        $this->assertSame($source['source_key'], $same['source_key']);
        $this->assertSame($input['content'], $same['content']);
        $this->assertDatabaseCount('instrument_method_sources', 1);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        $video = [...$input, 'kind' => 'video', 'reference' => 'https://www.youtube.com/watch?v=example',
            'metadata' => [...$input['metadata'], 'video_timestamp_seconds' => 91]];
        $this->assertSame('registered_reference_only', $sources->registerSource($video)['status']);
        unset($video['metadata']['video_timestamp_seconds']);
        $this->assertSame('METHOD_VIDEO_TIMESTAMP_REQUIRED', $sources->registerSource($video)['reason']);
        $this->assertSame('blocked', $sources->registerSource([...$input, 'confirmed' => true])['status']);
        DB::table('instrument_method_sources')->where('source_key', $source['source_key'])->update(['content' => 'changed']);
        $this->assertSame('METHOD_SOURCE_IMMUTABLE_CONTENT_DRIFT', $sources->inspectSource($source['source_key'])['reason']);
    }

    public function test_fetch_rejects_credentials_ports_plain_http_ip_and_local_hosts_before_network_access(): void
    {
        $sources = app(InstrumentMethodSourceService::class);
        foreach (['http://example.com', 'https://user:password@example.com', 'https://example.com:8443',
            'https://127.0.0.1/', 'https://localhost/', 'https://2130706433/'] as $reference) {
            $this->assertSame('blocked', $sources->fetchSource(['reference' => $reference,
                'metadata' => $this->sourceInput()['metadata']])['status']);
        }
        $this->assertDatabaseCount('instrument_method_sources', 0);
    }

    public function test_method_and_recipe_labels_cannot_overwrite_original_revisions(): void
    {
        $service = app(InstrumentMethodLibraryService::class);
        $input = $this->methodInput('entry', 'trend_pullback', 'confirmation');
        $method = $service->registerMethod($input);
        $this->assertSame('registered_research_only', $method['status']);
        $this->assertSame($method['method_hash'], $service->registerMethod($input)['method_hash']);
        $changed = $input;
        $changed['implementation']['ast']['args'][1]['value'] = 2.0;
        $this->assertSame('METHOD_IMMUTABLE_REVISION_COLLISION', $service->registerMethod($changed)['reason']);
        $changed['revision'] = 2;
        $second = $service->registerMethod($changed);
        $recipe = $service->registerRecipe($this->recipeInput([$method['method_hash']]));
        $collision = $this->recipeInput([$second['method_hash']]);
        $this->assertSame('METHOD_RECIPE_IMMUTABLE_REVISION_COLLISION', $service->registerRecipe($collision)['reason']);
        $this->assertSame([$method['method_hash']], $service->inspectRecipe($recipe['recipe_hash'])['contract']['method_hashes']);
        $this->assertFalse($method['contract']['usage_contract']['qualification_granted']);
    }

    public function test_registration_refuses_stateful_dsl_unbound_inputs_guard_disabling_and_conflicting_hybrids(): void
    {
        $service = app(InstrumentMethodLibraryService::class);
        $input = $this->methodInput('temporal', 'trend_pullback', 'confirmation');
        $input['implementation']['ast'] = ['op' => 'SEQUENCE', 'args' => [
            ['op' => 'CONST', 'type' => 'bool', 'value' => true], ['op' => 'CONST', 'type' => 'bool', 'value' => false]]];
        $this->assertSame('METHOD_TYPED_AST_UNSUPPORTED_OR_UNBOUND', $service->registerMethod($input)['reason']);
        $input = $this->methodInput('unbound', 'trend_pullback', 'confirmation');
        $input['implementation']['input_bindings'] = [];
        $this->assertSame('blocked', $service->registerMethod($input)['status']);
        $input = $this->adapterInput('disable', 'transition_protection', ['transition_firewall_enabled' => false]);
        $this->assertSame('METHOD_RISK_GUARD_DISABLE_REFUSED', $service->registerMethod($input)['reason']);
        $a = $service->registerMethod($this->adapterInput('tight_cost', 'cost_firewall', ['max_spread_atr_ratio' => .1]));
        $b = $service->registerMethod($this->adapterInput('loose_cost', 'cost_firewall', ['max_spread_atr_ratio' => .2]));
        $this->assertSame('METHOD_RECIPE_PARAMETER_COLLISION', $service->registerRecipe($this->recipeInput([$a['method_hash'], $b['method_hash']]))['reason']);
        $c = $service->registerMethod($this->methodInput('entry1', 'trend_pullback', 'confirmation'));
        $d = $service->registerMethod($this->methodInput('entry2', 'breakout_retest', 'confirmation'));
        $this->assertSame('METHOD_RECIPE_OPERATOR_TARGET_COLLISION', $service->registerRecipe($this->recipeInput([$c['method_hash'], $d['method_hash']]))['reason']);
    }

    public function test_hybrid_compilation_exports_two_real_pinned_operators_and_all_original_seals(): void
    {
        $service = app(InstrumentMethodLibraryService::class);
        $entry = $service->registerMethod($this->methodInput('entry', 'trend_pullback', 'confirmation'));
        $risk = $service->registerMethod($this->methodInput('reduce_risk', 'atr_risk_envelope', 'risk_multiplier'));
        $recipe = $service->registerRecipe($this->recipeInput([$entry['method_hash'], $risk['method_hash']]));
        $compiled = $service->compileRecipe($recipe['recipe_hash'], $this->baseline(), $this->scope());
        $this->assertSame('compiled_research_recipe', $compiled['status'], json_encode($compiled));
        $runtime = $compiled['runtime_recipe'];
        $this->assertCount(2, $runtime['operators']);
        $this->assertSame($recipe['recipe_hash'], hash('sha256', $runtime['recipe_revision_json']));
        $this->assertSame($runtime['runtime_hash'], hash('sha256', $runtime['runtime_json']));
        $this->assertSame($recipe['contract'], $runtime['recipe_revision']);
        $this->assertSame(str_repeat('a', 64), $runtime['scope_bindings']['data_hash']);
        $this->assertSame($this->baseline()['parameters'], $compiled['parameters']);
        $this->assertTrue($runtime['recipe_revision']['hybrid']);
        foreach ($runtime['operators'] as $operator) {
            $this->assertSame('typed_operator_decision_v1', $operator['protocol']);
            $this->assertSame(str_repeat('b', 64), $operator['source_context']['execution_hash']);
            $this->assertSame($operator['contract_hash'], app(ResearchPaperEpochContractService::class)->parameterHash(json_decode($operator['contract_json'], true)));
        }
        $same = $service->compileRecipe($recipe['recipe_hash'], $this->baseline(), $this->scope());
        $this->assertSame($runtime['runtime_hash'], $same['runtime_recipe']['runtime_hash']);
        $newScope = $this->scope(); $newScope['data_hash'] = str_repeat('c', 64);
        $newWindow = $service->compileRecipe($recipe['recipe_hash'], $this->baseline(), $newScope);
        $this->assertSame('compiled_research_recipe', $newWindow['status'], json_encode($newWindow));
        $this->assertSame($recipe['recipe_hash'], $newWindow['runtime_recipe']['recipe_hash']);
        $this->assertNotSame($runtime['runtime_hash'], $newWindow['runtime_recipe']['runtime_hash']);
        $this->assertDatabaseCount('research_instrument_programs', 4);
        $newBaseline = $this->baseline(); $newBaseline['parameters']['lookback'] = 42;
        $transfer = $service->compileRecipe($recipe['recipe_hash'], $newBaseline, $this->scope());
        $this->assertSame('compiled_research_recipe', $transfer['status'], json_encode($transfer));
        $this->assertNotSame($runtime['scope_bindings']['baseline_hash'], $transfer['runtime_recipe']['scope_bindings']['baseline_hash']);
        $this->assertSame($recipe['recipe_hash'], $transfer['runtime_recipe']['recipe_hash']);
        $this->assertDatabaseCount('research_instrument_programs', 6);
        $wrong = $this->scope(); $wrong['context_predicate']['session'] = 'asia';
        $this->assertSame('METHOD_RECIPE_PREOUTCOME_BINDING_REQUIRED', $service->compileRecipe($recipe['recipe_hash'], $this->baseline(), $wrong)['reason']);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_adapter_compilation_preserves_risk_and_does_not_forge_a_typed_operator(): void
    {
        $service = app(InstrumentMethodLibraryService::class);
        $method = $service->registerMethod($this->adapterInput('tight_cost', 'cost_firewall', ['max_spread_atr_ratio' => .1]));
        $recipe = $service->registerRecipe($this->recipeInput([$method['method_hash']]));
        $compiled = $service->compileRecipe($recipe['recipe_hash'], $this->baseline(), $this->scope());
        $this->assertSame('parameter_adapter_recipe', $compiled['status']);
        $this->assertSame(.1, $compiled['parameters']['max_spread_atr_ratio']);
        $this->assertSame([], $compiled['runtime_recipe']['operators']);
        $this->assertCount(1, $compiled['runtime_recipe']['parameter_adapters']);
        $baseline = $this->baseline(); $baseline['parameters']['max_spread_atr_ratio'] = .05;
        $this->assertSame('METHOD_RISK_LIMIT_WEAKENING_REFUSED', $service->compileRecipe($recipe['recipe_hash'], $baseline, $this->scope())['reason']);
        $this->assertDatabaseCount('research_instrument_programs', 0);
    }

    public function test_library_snapshot_retains_exact_original_membership_when_new_methods_are_added(): void
    {
        $service = app(InstrumentMethodLibraryService::class);
        $method = $service->registerMethod($this->methodInput('entry', 'trend_pullback', 'confirmation'));
        $recipe = $service->registerRecipe($this->recipeInput([$method['method_hash']]));
        $snapshot = $service->librarySnapshot([$recipe['recipe_hash']]);
        $this->assertTrue($snapshot['valid']);
        $service->registerMethod($this->methodInput('new_entry', 'breakout_retest', 'confirmation'));
        $this->assertSame($snapshot['library_hash'], $service->verifyLibrary($snapshot)['library_hash']);
        $this->assertNotSame($snapshot['library_hash'], $service->inventory()['library_hash']);
        DB::table('instrument_method_revisions')->where('method_hash', $method['method_hash'])->update(['contract' => '{}']);
        $this->assertFalse($service->verifyLibrary($snapshot)['valid']);
        $this->assertSame('METHOD_RECIPE_PINNED_METHOD_DRIFT', $service->inspectRecipe($recipe['recipe_hash'])['reason']);
    }

    public function test_actual_php_hybrid_export_survives_default_json_transport_and_native_pinned_validation(): void
    {
        $service = app(InstrumentMethodLibraryService::class);
        $entry = $service->registerMethod($this->methodInput('native_entry', 'trend_pullback', 'confirmation'));
        $risk = $service->registerMethod($this->methodInput('native_risk', 'atr_risk_envelope', 'risk_multiplier'));
        $recipe = $service->registerRecipe($this->recipeInput([$entry['method_hash'], $risk['method_hash']]));
        $compiled = $service->compileRecipe($recipe['recipe_hash'], $this->baseline(), $this->scope());
        $this->assertSame('compiled_research_recipe', $compiled['status'], json_encode($compiled));
        $snapshot = $service->librarySnapshot([$recipe['recipe_hash']]);
        $learning = app(AgentMethodLearningService::class);
        $registered = $learning->registerPolicy(['agent_key' => 'external-method-native-test', 'revision' => 1,
            'recipe_hashes' => [$recipe['recipe_hash']], 'rules' => [['context_predicate' => $this->context(),
                'preferred_recipes' => [$recipe['recipe_hash']]]], 'default_action' => 'BASELINE']);
        $this->assertSame('registered_research_only', $registered['status'], json_encode($registered));
        $policy = $learning->policy($registered['policy_hash']);
        $runtime = $compiled['runtime_recipe'];
        $hashes = app(ResearchPaperEpochContractService::class);
        $body = ['protocol' => 'instrument_method_learning_v1', 'data_hash' => str_repeat('a', 64),
            'execution_hash' => str_repeat('b', 64), 'scope' => ['symbol' => 'XAUUSD', 'timeframe' => 'M5',
                'evaluated_start' => '2025-01-01T00:00:00Z', 'evaluated_end' => '2025-01-02T00:00:00Z', 'context' => $this->context()],
            'library_hash' => $snapshot['library_hash'], 'library_contract' => $snapshot['library_contract'],
            'library_contract_json' => $snapshot['library_contract_json'], 'recipes' => [$runtime],
            'compiled_pool_hash' => $hashes->parameterHash(['recipes' => [['recipe_hash' => $recipe['recipe_hash'], 'runtime_hash' => $runtime['runtime_hash']]]]),
            'policy' => $policy, 'research_only' => true, 'promotion_evidence' => false, 'economic_authority' => false];
        $contract = [...$body, 'contract_hash' => $hashes->parameterHash($body), 'contract_json' => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)];
        $script = 'import json,sys; from app.services.instrument_method_learning import InstrumentMethodLearning; p=json.load(sys.stdin); e=InstrumentMethodLearning(p,dataset_hash="a"*64,execution_hash="b"*64,expected_agent_key="external-method-native-test"); r=e.evaluate({"close":100.0,"market_regime":"trend_up","market_session":"london","volatility_regime":"normal_volatility"},decision_id="original-closed-1",phase="entry",decision_at="2025-01-02T00:00:00Z",observed_at="2025-01-01T23:55:00Z",direction="BUY"); print(json.dumps({"event":r,"receipt":e.receipt()}))';
        $process = new Process(['python', '-c', $script], dirname(base_path()).'/ai-service-python');
        $process->setTimeout(20);
        // Default transport collapses 1.0, while each exact sealed JSON copy preserves its identity.
        $process->setInput(json_encode($contract));
        $process->mustRun();
        $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($result['event']['used']);
        $this->assertTrue($result['event']['behavior_changed']);
        $this->assertTrue($result['event']['outputs']['confirmation']);
        $this->assertSame(.5, $result['event']['outputs']['risk_multiplier']);
        $this->assertSame($recipe['recipe_hash'], $result['event']['recipe_hash']);
        $this->assertFalse($result['receipt']['economic_authority']);
        $this->assertFalse($result['receipt']['promotion_evidence']);
    }

    private function sourceInput(): array
    {
        return ['kind' => 'imported_text', 'reference' => 'operator supplied reference', 'content' => 'Conditional rule hypothesis.',
            'metadata' => ['license' => 'user supplied research permission', 'access' => 'user_supplied',
                'exposure' => ['status' => 'unknown', 'historical_training_inventory_complete' => false]]];
    }

    private function context(): array { return ['regime' => 'trend_up', 'session' => 'london', 'volatility' => 'normal']; }

    private function methodInput(string $key, string $instrument, string $target): array
    {
        $source = app(InstrumentMethodSourceService::class)->registerSource($this->sourceInput());
        return ['instrument_key' => $instrument, 'method_key' => $key, 'revision' => 1, 'context_predicate' => $this->context(),
            'horizon' => 'hour', 'known_limitations' => ['Untested external rule; close inputs only.'], 'source_keys' => [$source['source_key']],
            'implementation' => ['kind' => 'typed_ast', 'target' => $target,
                'ast' => $target === 'risk_multiplier' ? ['op' => 'CONST', 'type' => 'number', 'value' => .5]
                    : ['op' => 'GREATER_THAN', 'args' => [['op' => 'PRICE_CLOSE', 'available_at' => '2025-01-01T00:00:00Z'], ['op' => 'CONST', 'type' => 'number', 'value' => 1.0]]],
                'input_bindings' => $target === 'risk_multiplier' ? [] : ['price_close' => 'close'],
                'budget' => ['cpu_seconds' => .5, 'max_calls' => 100, 'max_node_evaluations' => 4800]]];
    }

    private function adapterInput(string $key, string $instrument, array $parameters): array
    {
        $input = $this->methodInput($key, $instrument, 'confirmation');
        $input['implementation'] = ['kind' => 'parameter_adapter', 'strategy_family' => 'breakout', 'parameters' => $parameters];
        return $input;
    }

    private function recipeInput(array $hashes): array { return ['recipe_key' => 'external_hybrid', 'revision' => 1, 'method_hashes' => $hashes, 'context_predicate' => $this->context()]; }
    private function baseline(): array { return ['strategy_family' => 'breakout', 'parameters' => app(StrategyParameterSchemaService::class)->defaults('breakout')]; }
    private function scope(): array { return ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'pre_2026_only' => true,
        'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64), 'context_predicate' => $this->context()]; }
}
