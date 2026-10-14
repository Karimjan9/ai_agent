<?php

namespace Tests\Feature;

use App\Services\InstrumentMethodLibraryService;
use App\Services\AgentMethodCandidateService;
use App\Services\InstrumentMethodSourceService;
use App\Services\InstrumentMethodStudyService;
use App\Services\InstrumentMethodMetaModelService;
use App\Services\InstrumentMethodMetaBindingService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ResearchInstrumentMethodsCommandTest extends TestCase
{
    use RefreshDatabase;

    private array $temporaryInputs = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryInputs as $path) if (is_file($path)) unlink($path);
        parent::tearDown();
    }

    public function test_default_inventory_and_status_are_read_only_and_command_is_discovered(): void
    {
        [$exit, $inventory] = $this->commandResult('inventory');
        $this->assertSame(0, $exit);
        $this->assertCount(26, $inventory['instruments']);
        $this->assertCount(5, $inventory['groups']);
        $this->assertCount(13, $inventory['playbooks']);
        [$exit, $status] = $this->commandResult('status');
        $this->assertSame(0, $exit);
        $this->assertSame(0, $status['instrument_library']['method_revisions']);
        $this->assertSame(0, $status['agent_learning']['contextual_experiences']);
        $this->assertDatabaseCount('trading_instruments', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_own_child_study_preparation_requires_bounded_owner_input_and_never_dispatches_from_invalid_ids(): void
    {
        [$exit, $missing] = $this->commandResult('study-prepare');
        $this->assertSame(1, $exit);
        $this->assertSame('METHOD_COMMAND_INPUT_REQUIRED', $missing['reason']);
        [$exit, $bad] = $this->commandResult('study-prepare', ['source_model_id' => 99999,
            'libraries' => ['L1' => [], 'L2' => []], 'study' => [], 'source' => []]);
        $this->assertSame(1, $exit);
        $this->assertSame('METHOD_POLICY_STUDY_UNUSED_OWN_CHILD_REQUIRED', $bad['reason']);
        $this->assertDatabaseCount('model_versions', 0);
        $this->assertDatabaseCount('research_experiment_work_items', 0);
        $this->assertDatabaseCount('lab_generations', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_meta_transfer_uses_original_owner_only_and_rejects_caller_assets_or_force(): void
    {
        $owner = $this->mock(InstrumentMethodMetaBindingService::class);
        $owner->shouldReceive('reattachToUnusedChild')->once()->with(7, 11)->andReturn([
            'status' => 'reattached_meta_model_prospectively', 'economic_authority' => false,
            'mastery_proven' => false, 'research_only' => true]);
        [$exit, $result] = $this->commandResult('meta-transfer', ['source_model_id' => 7, 'target_model_id' => 11]);
        $this->assertSame(0, $exit); $this->assertFalse($result['economic_authority']);
        foreach ([['source_model_id' => 7, 'target_model_id' => 11, 'force' => true],
            ['source_model_id' => 7, 'target_model_id' => 11, 'model_hash' => str_repeat('b', 64)]] as $bad) {
            [$exit, $result] = $this->commandResult('meta-transfer', $bad);
            $this->assertSame(1, $exit);
            $this->assertSame('METHOD_COMMAND_EXACT_META_TRANSFER_REQUIRED', $result['reason']);
        }
        $this->assertDatabaseCount('model_versions', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_source_method_recipe_policy_and_both_compile_routes_preserve_original_contracts(): void
    {
        $marker = 'DO_NOT_PRINT_RAW_EXTERNAL_SOURCE_TEXT';
        [$exit, $source, $printed] = $this->commandResult('source', $this->source($marker));
        $this->assertSame(0, $exit);
        $this->assertSame('registered_reference_only', $source['status']);
        $this->assertArrayNotHasKey('content', $source);
        $this->assertStringNotContainsString($marker, $printed);
        [$exit, $method] = $this->commandResult('method', $this->method($source['source_key']));
        $this->assertSame(0, $exit);
        $this->assertSame('registered_research_only', $method['status']);
        [$exit, $recipe] = $this->commandResult('recipe', ['recipe_key' => 'command-recipe', 'revision' => 1,
            'method_hashes' => [$method['method_hash']], 'context_predicate' => $this->context()]);
        $this->assertSame(0, $exit);
        [$exit, $inspection] = $this->commandResult('status', ['recipe_hash' => $recipe['recipe_hash']]);
        $this->assertSame(0, $exit);
        $this->assertSame($recipe['contract_json'], $inspection['contract_json']);
        [$exit, $compiled, $printed] = $this->commandResult('compile', ['recipe_hash' => $recipe['recipe_hash'],
            'baseline' => $this->baseline(), 'scope' => $this->scope()]);
        $this->assertSame(0, $exit, $printed);
        $this->assertSame('compiled_research_recipe', $compiled['status']);
        $this->assertSame($recipe['recipe_hash'], hash('sha256', $compiled['runtime_recipe']['recipe_revision_json']));
        $this->assertStringContainsString('1.0', $printed);
        [$exit, $policy, $printed] = $this->commandResult('policy', ['agent_key' => 'command-owned-agent', 'revision' => 1,
            'recipe_hashes' => [$recipe['recipe_hash']], 'rules' => [['context_predicate' => $this->context(),
                'preferred_recipes' => [$recipe['recipe_hash']]]], 'default_action' => 'BASELINE']);
        $this->assertSame(0, $exit, $printed);
        [$exit, $contract, $printed] = $this->commandResult('compile', ['policy_hash' => $policy['policy_hash'],
            'agent_key' => 'command-owned-agent', 'baseline' => $this->baseline(), 'scope' => $this->scope()]);
        $this->assertSame(0, $exit, $printed);
        $this->assertSame('instrument_method_learning_v1', $contract['protocol']);
        $this->assertSame($contract['contract_hash'], hash('sha256', $contract['contract_json']));
        $this->assertArrayNotHasKey('status', $contract);
        $this->assertFalse($contract['promotion_evidence']);
        $this->assertFalse($contract['economic_authority']);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_fetch_is_explicit_source_only_and_never_prints_fetched_content(): void
    {
        $mock = $this->mock(InstrumentMethodSourceService::class);
        $mock->shouldReceive('fetchSource')->once()->andReturn(['status' => 'registered_reference_only',
            'source_key' => str_repeat('a', 64), 'content' => 'DO_NOT_PRINT_FETCHED_REFERENCE', 'promotion_evidence' => false]);
        $mock->shouldNotReceive('registerSource');
        [$exit, $result, $printed] = $this->commandResult('source', ['reference' => 'https://example.com', 'metadata' => $this->source('x')['metadata']], ['--fetch' => true]);
        $this->assertSame(0, $exit);
        $this->assertArrayNotHasKey('content', $result);
        $this->assertStringNotContainsString('DO_NOT_PRINT_FETCHED_REFERENCE', $printed);
        [$exit, $result] = $this->commandResult('inventory', null, ['--fetch' => true]);
        $this->assertSame(1, $exit);
        $this->assertSame('METHOD_COMMAND_FETCH_SOURCE_ONLY', $result['reason']);
    }

    public function test_bounded_local_object_input_rejects_remote_streams_lists_invalid_json_and_oversized_files(): void
    {
        foreach (['https://example.com/input.json', 'php://memory', '//remote/share/input.json'] as $path) {
            $exit = Artisan::call('trading:instrument-methods', ['action' => 'source', '--input' => $path, '--json' => true]);
            $this->assertSame(1, $exit);
            $this->assertSame('METHOD_COMMAND_LOCAL_REGULAR_INPUT_REQUIRED', json_decode(Artisan::output(), true)['reason']);
        }
        foreach ([['[]', 'METHOD_COMMAND_JSON_OBJECT_REQUIRED'], ['{bad-json', 'METHOD_COMMAND_INPUT_INVALID_JSON'],
            [str_repeat('x', 1048577), 'METHOD_COMMAND_INPUT_SIZE_OR_TYPE_INVALID']] as [$raw, $reason]) {
            $exit = Artisan::call('trading:instrument-methods', ['action' => 'source', '--input' => $this->inputFile($raw), '--json' => true]);
            $this->assertSame(1, $exit);
            $this->assertSame($reason, json_decode(Artisan::output(), true)['reason']);
        }
        [$exit, $result] = $this->commandResult('method');
        $this->assertSame(1, $exit);
        $this->assertSame('METHOD_COMMAND_INPUT_REQUIRED', $result['reason']);
        $this->assertDatabaseCount('instrument_method_sources', 0);
    }

    public function test_missing_original_learning_and_unearned_proposals_fail_without_dispatch_or_credit(): void
    {
        [$exit, $result] = $this->commandResult('learn', ['run_id' => 'missing-original']);
        $this->assertSame(1, $exit);
        $this->assertSame('METHOD_COMMAND_ORIGINAL_RUN_NOT_FOUND', $result['reason']);
        [$exit, $result] = $this->commandResult('propose', ['policy_hash' => str_repeat('a', 64), 'revision' => 2]);
        $this->assertSame(1, $exit);
        $this->assertSame('POLICY_NOT_FOUND', $result['reason']);
        [$exit, $result] = $this->commandResult('attach', ['model_id' => 999999, 'policy_hash' => str_repeat('a', 64)]);
        $this->assertSame(1, $exit);
        $this->assertSame('METHOD_COMMAND_OPERATION_REFUSED', $result['reason']);
        $this->assertDatabaseCount('agent_method_experiences', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_unexpected_exceptions_and_blocked_service_messages_do_not_expose_sql_or_secrets(): void
    {
        $mock = $this->mock(InstrumentMethodLibraryService::class);
        $mock->shouldReceive('inventory')->once()->andThrow(new \RuntimeException('SQLSTATE secret_token=do-not-show; select * from private'));
        [$exit, $result, $printed] = $this->commandResult('inventory');
        $this->assertSame(1, $exit);
        $this->assertSame('METHOD_COMMAND_OPERATION_REFUSED', $result['reason']);
        $this->assertStringNotContainsString('secret_token', $printed);
        $mock->shouldReceive('registerMethod')->once()->andReturn(['status' => 'blocked', 'reason' => 'SQLSTATE select secret_token']);
        [$exit, $result, $printed] = $this->commandResult('method', ['method_key' => 'invalid']);
        $this->assertSame(1, $exit);
        $this->assertSame('METHOD_COMMAND_OPERATION_REFUSED', $result['reason']);
        $this->assertStringNotContainsString('secret_token', $printed);
    }

    public function test_study_and_settlement_delegate_exact_declared_inputs_without_scheduling_work(): void
    {
        $study = ['study_key' => 'command-study', 'data_hash' => str_repeat('a', 64)];
        $runs = ['original-L1A1', 'original-L2A1', 'original-L1A2', 'original-L2A2'];
        $mock = $this->mock(InstrumentMethodStudyService::class);
        $mock->shouldReceive('preregister')->once()->with($study)->andReturn(['status' => 'preregistered_diagnostic',
            'study_hash' => str_repeat('c', 64), 'research_only' => true, 'promotion_evidence' => false]);
        $mock->shouldReceive('settle')->once()->with(str_repeat('c', 64), $runs)->andReturn(['status' => 'assessed_diagnostic',
            'study_hash' => str_repeat('c', 64), 'research_only' => true, 'economic_authority' => false, 'promotion_evidence' => false]);
        [$exit, $registered] = $this->commandResult('study', $study);
        $this->assertSame(0, $exit);
        $this->assertSame('preregistered_diagnostic', $registered['status']);
        [$exit, $settled] = $this->commandResult('settle', ['study_hash' => $registered['study_hash'], 'run_ids' => $runs]);
        $this->assertSame(0, $exit);
        $this->assertSame('assessed_diagnostic', $settled['status']);
        $this->assertFalse($settled['economic_authority']);
        [$exit, $blocked] = $this->commandResult('settle', ['study_hash' => $registered['study_hash'], 'run_ids' => []]);
        $this->assertSame(1, $exit);
        $this->assertSame('METHOD_COMMAND_ORIGINAL_STUDY_RUNS_REQUIRED', $blocked['reason']);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_explicit_evolve_action_delegates_only_original_policy_identity_and_bounded_proposal_options(): void
    {
        $mock = $this->mock(AgentMethodCandidateService::class);
        $mock->shouldReceive('evolve')->once()->with(str_repeat('d', 64), 2, 3)
            ->andReturn(['status' => 'fresh_research_candidate', 'model_id' => 123, 'canonical_admission_required' => true,
                'queued' => false, 'research_only' => true, 'promotion_evidence' => false]);
        [$exit, $result] = $this->commandResult('evolve', ['policy_hash' => str_repeat('d', 64), 'revision' => 2]);
        $this->assertSame(0, $exit);
        $this->assertTrue($result['canonical_admission_required']);
        $this->assertFalse($result['queued']);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_meta_training_and_attachment_delegate_exact_bounded_references_without_dispatch(): void
    {
        $design = ['model_key' => 'original-training', 'original_run_ids' => ['original-run']];
        $hash = str_repeat('e', 64);
        $models = $this->mock(InstrumentMethodMetaModelService::class);
        $models->shouldReceive('preregister')->once()->with($design)->andReturn([
            'status' => 'registered_training_only', 'registration_hash' => $hash]);
        $models->shouldReceive('fit')->once()->with($hash)->andReturn([
            'status' => 'underpowered', 'registration_hash' => $hash, 'economic_authority' => false]);
        $models->shouldReceive('inspect')->once()->with($hash)->andReturn([
            'status' => 'registered_training_only', 'registration_hash' => $hash]);
        $binding = $this->mock(InstrumentMethodMetaBindingService::class);
        $binding->shouldReceive('attachUnusedModel')->once()->with(123, $hash)->andReturn([
            'status' => 'attached_training_asset', 'model_id' => 123, 'economic_authority' => false]);
        foreach ([['meta-register', $design], ['meta-fit', ['registration_hash' => $hash]],
            ['meta-status', ['registration_hash' => $hash]], ['meta-attach', ['model_id' => 123, 'model_hash' => $hash]]] as [$action, $input]) {
            [$exit, $result] = $this->commandResult($action, $input);
            $this->assertSame(0, $exit);
            $this->assertArrayHasKey('status', $result);
        }
        [$exit, $blocked] = $this->commandResult('meta-fit', ['registration_hash' => $hash, 'weights' => [1.0]]);
        $this->assertSame(1, $exit);
        $this->assertSame('METHOD_COMMAND_EXACT_META_REFERENCE_REQUIRED', $blocked['reason']);
        [$exit, $blocked] = $this->commandResult('meta-attach', ['model_id' => 123, 'model_hash' => $hash, 'force' => true]);
        $this->assertSame(1, $exit);
        $this->assertSame('METHOD_COMMAND_EXACT_META_ATTACHMENT_REQUIRED', $blocked['reason']);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('lab_generations', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    private function commandResult(string $action, ?array $input = null, array $options = []): array
    {
        $arguments = ['action' => $action, '--json' => true, ...$options];
        if ($input !== null) $arguments['--input'] = $this->inputFile(json_encode($input, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        $exit = Artisan::call('trading:instrument-methods', $arguments);
        $printed = Artisan::output();
        return [$exit, json_decode($printed, true, 512, JSON_THROW_ON_ERROR), $printed];
    }

    private function inputFile(string $raw): string
    {
        $path = tempnam(sys_get_temp_dir(), 'instrument-method-command-');
        $this->temporaryInputs[] = $path;
        file_put_contents($path, $raw);
        return $path;
    }

    private function source(string $content): array
    {
        return ['kind' => 'imported_text', 'reference' => 'operator supplied research reference', 'content' => $content,
            'metadata' => ['license' => 'user supplied research permission', 'access' => 'user_supplied', 'exposure' => ['status' => 'unknown']]];
    }
    private function context(): array { return ['regime' => 'trend_up', 'session' => 'london', 'volatility' => 'normal']; }
    private function method(string $source): array
    {
        return ['instrument_key' => 'trend_pullback', 'method_key' => 'command_method', 'revision' => 1,
            'context_predicate' => $this->context(), 'horizon' => 'hour', 'known_limitations' => ['Untested external closed-input rule'],
            'source_keys' => [$source], 'implementation' => ['kind' => 'typed_ast', 'target' => 'confirmation',
                'ast' => ['op' => 'GREATER_THAN', 'args' => [['op' => 'PRICE_CLOSE', 'available_at' => '2025-01-01T00:00:00Z'],
                    ['op' => 'CONST', 'type' => 'number', 'value' => 1.0]]], 'input_bindings' => ['price_close' => 'close'],
                'budget' => ['cpu_seconds' => .5, 'max_calls' => 100, 'max_node_evaluations' => 4800]]];
    }
    private function baseline(): array { return ['strategy_family' => 'breakout', 'parameters' => app(StrategyParameterSchemaService::class)->defaults('breakout')]; }
    private function scope(): array { return ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'pre_2026_only' => true,
        'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64), 'context_predicate' => $this->context(),
        'evaluated_start' => '2025-01-01T00:00:00Z', 'evaluated_end' => '2025-01-02T00:00:00Z', 'context' => $this->context()]; }
}
