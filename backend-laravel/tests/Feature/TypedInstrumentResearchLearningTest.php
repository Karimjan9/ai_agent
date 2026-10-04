<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\LabImmutableEvidenceService;
use App\Services\TypedInstrumentFoundryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Symfony\Component\Process\Process;

class TypedInstrumentResearchLearningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('research_foundry_test');
        config()->set('services.lab_evidence.disk', 'research_foundry_test');
        Queue::fake();
    }

    public function test_repeated_solved_subtrees_create_typed_parameterized_macro_and_exact_expansion(): void
    {
        [$program, $macro, $compression] = $this->trainedMacro();
        $owner = app(TypedInstrumentFoundryService::class);
        $this->assertSame('compressed_research_only', $compression['status']);
        $this->assertTrue($compression['compression']['exact_roundtrip']);
        $this->assertGreaterThan(0, $macro->net_savings);
        $definition = json_decode($macro->definition, true);
        $this->assertSame([['name' => 'p0', 'type' => 'number']], $definition['parameters']);
        $this->assertSame('PARAM', $definition['template']['args'][0]['args'][1]['op']);
        $this->assertSame('research_only', $macro->status);
        $this->assertNull($macro->validation_evidence);
        $before = $program->ast_hash;
        $this->assertSame('semantic_duplicate', $owner->compile($compression['compression']['compressed_ast'], $this->context(), $this->gates())['status']);
        $fresh = DB::table('research_instrument_programs')->where('id', $program->id)->first();
        $this->assertSame($before, $fresh->ast_hash);
        $this->assertSame($program->ast, $fresh->ast);
        $this->assertFalse($compression['promotion_evidence']);
        $call = $compression['compression']['compressed_ast'];
        $call['args'][0]['value'] = 19.0;
        $compiled = $owner->compile($call, $this->context(), $this->gates());
        $this->assertSame('compiled_research_only', $compiled['status']);
        $this->assertSame(19.0, $compiled['compiled_contract']['expanded_ast']['args'][0]['args'][1]['value']);
        $this->assertFalse($compiled['promotion_evidence']);
    }

    public function test_solved_flags_and_task_key_relabeling_cannot_mine_a_library(): void
    {
        $owner = app(TypedInstrumentFoundryService::class);
        $first = $this->program(1); $second = $this->program(2);
        [$run] = $this->fixtureRun($first, $this->ast(1), 1, ['research_program_task' => ['solved' => true]], [], false);
        $this->assertSame('ORIGINAL_SOLVED_TASK_PROGRAM_AND_OUTPUT_PROOF_REQUIRED', $owner->recordProgramOutcome($first->program_key, $run->run_id)['reason']);
        [$a] = $this->fixtureRun($first, $this->ast(1), 3);
        [$b] = $this->fixtureRun($second, $this->ast(2), 3, [], ['task_key' => 'only-the-name-changed']);
        $this->assertSame('solved_task_observed', $owner->recordProgramOutcome($first->program_key, $a->run_id)['status']);
        $this->assertSame('solved_task_observed', $owner->recordProgramOutcome($second->program_key, $b->run_id)['status']);
        $this->assertSame('no_verified_reusable_subtree', $owner->compress($first->program_key)['status']);
        $this->assertDatabaseCount('research_instrument_abstractions', 0);
    }

    public function test_single_source_duplicate_runs_and_unsolved_outputs_are_not_repeat_evidence(): void
    {
        $owner = app(TypedInstrumentFoundryService::class); $program = $this->program(1);
        [$bad] = $this->fixtureRun($program, $this->ast(1), 1, ['research_program_task' => ['outputs' => [false]]]);
        $this->assertSame('blocked', $owner->recordProgramOutcome($program->program_key, $bad->run_id)['status']);
        foreach ([1, 2, 3] as $task) {
            [$run] = $this->fixtureRun($program, $this->ast(1), $task);
            $this->assertSame('solved_task_observed', $owner->recordProgramOutcome($program->program_key, $run->run_id)['status']);
        }
        $this->assertSame('no_verified_reusable_subtree', $owner->compress($program->program_key)['status']);
        $this->assertDatabaseCount('research_instrument_abstractions', 0);
    }

    public function test_unused_dummy_inputs_and_different_ids_do_not_create_distinct_solved_tasks(): void
    {
        $owner = app(TypedInstrumentFoundryService::class);
        $first = $this->program(1); $second = $this->program(2);
        [$a] = $this->fixtureRun($first, $this->ast(1), 1, [], ['input_vectors' => [['unused_annotation' => 'first-label']]]);
        [$b] = $this->fixtureRun($second, $this->ast(2), 1, [], ['task_key' => 'different-id', 'input_vectors' => [['unused_annotation' => 'second-label']]]);
        $one = $owner->recordProgramOutcome($first->program_key, $a->run_id);
        $two = $owner->recordProgramOutcome($second->program_key, $b->run_id);
        $this->assertSame('solved_task_observed', $one['status']);
        $this->assertSame($one['task_identity'], $two['task_identity']);
        $this->assertSame('no_verified_reusable_subtree', $owner->compress($first->program_key)['status']);
        $this->assertDatabaseCount('research_instrument_abstractions', 0);
    }

    public function test_macro_argument_scope_content_and_expanded_complexity_guards_are_enforced(): void
    {
        [, $macro, $compression] = $this->trainedMacro(); $owner = app(TypedInstrumentFoundryService::class);
        $call = $compression['compression']['compressed_ast'];
        $wrong = $call; $wrong['args'][0] = ['op' => 'CONST', 'type' => 'bool', 'value' => true];
        $this->assertSame('ABSTRACTION_TYPED_ARGUMENT_MISMATCH', $owner->compile($wrong, $this->context(), $this->gates())['reason']);
        $wrong = $call; $wrong['macro_key'] = str_repeat('f', 64);
        $this->assertSame('ABSTRACTION_NOT_FOUND', $owner->compile($wrong, $this->context(), $this->gates())['reason']);
        $this->assertSame('ABSTRACTION_CONTENT_OR_SCOPE_MISMATCH', $owner->compile($call, [...$this->context(), 'execution_hash' => str_repeat('c', 64)], $this->gates())['reason']);
        $many = ['op' => 'AND', 'args' => array_fill(0, 9, $call)];
        $this->assertSame('DSL_COMPLEXITY_BUDGET_EXCEEDED', $owner->compile($many, $this->context(), $this->gates())['reason']);
        $definition = json_decode($macro->definition, true); $definition['template']['op'] = 'OR';
        DB::table('research_instrument_abstractions')->where('id', $macro->id)->update(['definition' => json_encode($definition), 'status' => 'library_utility_verified']);
        $this->assertSame('ABSTRACTION_CONTENT_OR_SCOPE_MISMATCH', $owner->compile($call, $this->context(), $this->gates())['reason']);
        $this->assertSame('blocked', $owner->compile($call, $this->context(), [])['status']);
    }

    public function test_native_novel_task_semantics_never_claims_search_efficiency_or_promoted_library_utility(): void
    {
        [, $macro, $compression] = $this->trainedMacro(); $owner = app(TypedInstrumentFoundryService::class);
        $call = $compression['compression']['compressed_ast']; $call['args'][0]['value'] = 3;
        $compiled = $owner->compile($call, $this->context(), $this->gates());
        $program = DB::table('research_instrument_programs')->where('program_key', $compiled['program_key'])->first();
        [$candidate] = $this->fixtureRun($program, $call, 99, [], [], true, 1.0);
        [$baseline] = $this->fixtureRun($program, $this->ast(3), 99, [], [], true, 4.0);
        $result = $owner->validateAbstraction($macro->macro_key, $program->program_key, $candidate->run_id, $baseline->run_id);
        $this->assertSame('novel_task_semantics_verified', $result['status']);
        $this->assertFalse($result['search_efficiency_measured']);
        $this->assertFalse($result['library_utility_promoted']);
        $this->assertFalse($result['promotion_evidence']);
        $receipt = json_decode(DB::table('research_instrument_abstractions')->where('id', $macro->id)->value('validation_evidence'), true);
        $this->assertFalse($receipt['independent_economic_benefit']);
        $this->assertSame('bounded_program_interpretation', $receipt['candidate']['search_resources']['timing_scope']);
        $this->assertFalse($receipt['search_efficiency_measured']);
    }

    public function test_familiar_task_missing_cpu_and_unequal_budget_do_not_grant_library_utility(): void
    {
        [$program, $macro, $compression] = $this->trainedMacro(); $owner = app(TypedInstrumentFoundryService::class);
        $call = $compression['compression']['compressed_ast'];
        [$a] = $this->fixtureRun($program, $call, 1, [], [], true, 1.0);
        [$b] = $this->fixtureRun($program, $this->ast(1), 1, [], [], true, 4.0);
        $this->assertSame('NOVEL_TASK_REQUIRED', $owner->validateAbstraction($macro->macro_key, $program->program_key, $a->run_id, $b->run_id)['reason']);
        [$a] = $this->fixtureRun($program, $call, 77, ['research_program_task' => ['search_resources' => ['cpu_seconds' => null]]]);
        [$b] = $this->fixtureRun($program, $this->ast(1), 77, [], [], true, 4.0);
        $this->assertSame('MEASURED_EQUAL_BUDGET_NOVEL_TASK_UTILITY_REQUIRED', $owner->validateAbstraction($macro->macro_key, $program->program_key, $a->run_id, $b->run_id)['reason']);
        [$a] = $this->fixtureRun($program, $call, 88, [], ['search_budget' => ['cpu_seconds' => 9.0]], true, 1.0);
        [$b] = $this->fixtureRun($program, $this->ast(1), 88, [], [], true, 4.0);
        $this->assertSame('MEASURED_EQUAL_BUDGET_NOVEL_TASK_UTILITY_REQUIRED', $owner->validateAbstraction($macro->macro_key, $program->program_key, $a->run_id, $b->run_id)['reason']);
        $this->assertNull(DB::table('research_instrument_abstractions')->where('id', $macro->id)->value('validation_evidence'));
    }

    public function test_source_identity_drift_or_cached_response_tampering_invalidates_reuse(): void
    {
        $owner = app(TypedInstrumentFoundryService::class); $program = $this->program(1);
        [$run, $response, $model] = $this->fixtureRun($program, $this->ast(1), 1);
        $response['research_program_task']['outputs'] = [false];
        $this->assertSame('ORIGINAL_IDENTITY_OR_RESPONSE_SHA_MISMATCH', $owner->recordProgramOutcome($program->program_key, $run->run_id, $response)['reason']);
        $this->assertSame('solved_task_observed', $owner->recordProgramOutcome($program->program_key, $run->run_id)['status']);
        $model->update(['parameters' => ['atr_period' => 15]]);
        $this->assertSame('ORIGINAL_IDENTITY_OR_RESPONSE_SHA_MISMATCH', $owner->recordProgramOutcome($program->program_key, $run->run_id)['reason']);
        $this->assertSame('no_verified_reusable_subtree', $owner->compress($program->program_key)['status']);
    }

    public function test_future_inputs_and_wrong_original_program_are_rejected(): void
    {
        $program = $this->program(1); $owner = app(TypedInstrumentFoundryService::class);
        [$run] = $this->fixtureRun($program, $this->ast(1), 1, [], ['input_vectors' => [['decision_at' => '2024-01-01T00:00:00Z']]]);
        $this->assertSame('SOLVED_TASK_FUTURE_INPUT_FORBIDDEN', $owner->recordProgramOutcome($program->program_key, $run->run_id)['reason']);
        [$run] = $this->fixtureRun($program, $this->ast(2), 2);
        $this->assertSame('SOLVED_TASK_ACTUAL_PROGRAM_MISMATCH', $owner->recordProgramOutcome($program->program_key, $run->run_id)['reason']);
    }

    public function test_actual_native_contract_executes_macro_and_original_path_copy_mismatch_is_refused(): void
    {
        [$program, , $compression] = $this->trainedMacro(); $owner = app(TypedInstrumentFoundryService::class);
        $contract = $owner->taskContract($program->program_key, 'unseen-native-question',
            [['decision_at' => '2025-01-01T11:00:00Z', 'price_close' => 2100, 'bool' => false]], [true], ['cpu_seconds' => 0.25, 'max_expansions' => 100]);
        $this->assertSame('native_task_contract', $contract['status']);
        $this->assertSame('policy_context.research_program_task', $contract['request_path']);
        $this->assertSame('benchmark.research_program_task', $contract['result_path']);
        $this->assertSame('CALL', $contract['task']['ast']['op']);
        $native = $this->nativeTask($contract['task']);
        $this->assertSame([true], $native['outputs']);
        $this->assertSame(6, $native['search_resources']['expansions']);
        $this->assertFalse($native['search_resources']['search_efficiency_measured']);
        [$run] = $this->fixtureRun($program, $compression['compression']['compressed_ast'], 55);
        $this->assertSame('solved_task_observed', $owner->recordProgramOutcome($program->program_key, $run->run_id)['status']);
        [$bad] = $this->fixtureRun($program, $this->ast(1), 66, [], ['legacy_path_copy' => ['task_key' => 'different-question']]);
        $this->assertSame('ORIGINAL_TASK_PATH_COPY_MISMATCH', $owner->recordProgramOutcome($program->program_key, $bad->run_id)['reason']);
    }

    public function test_native_hash_matches_actual_php_scientific_numbers_and_executes_tiny_and_large_constants(): void
    {
        $values = ['numbers' => [1e-7, 1e-6, 1e-5, 0.0001, 1e16, 1e17, 1e20, 1e21, 1e15, 0.0, -0.0, 1.234567890123456e16, 1.234e-7],
            'ascii_escaped_context' => 'тест/λ'];
        $process = new Process(['python', '-c', 'import json,sys; from app.services.research_program_tasks import canonical_hash; print(canonical_hash(json.load(sys.stdin)))'], dirname(base_path()).'/ai-service-python');
        $process->setInput(json_encode($values, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        $process->setTimeout(10); $process->mustRun();
        $this->assertSame($this->hashAst($values), trim($process->getOutput()));
        $owner = app(TypedInstrumentFoundryService::class);
        foreach ([1e-7, 1e16] as $threshold) {
            $ast = $this->ast(1); $ast['args'][0]['args'][1]['value'] = $threshold;
            $compiled = $owner->compile($ast, $this->context(), $this->gates());
            $this->assertSame('compiled_research_only', $compiled['status']);
            $task = $owner->taskContract($compiled['program_key'], 'scientific-number-'.$threshold,
                [['decision_at' => '2025-01-01T11:00:00Z', 'price_close' => $threshold * 2, 'bool' => false]], [true],
                ['cpu_seconds' => 0.25, 'max_expansions' => 100]);
            $actual = $this->nativeTask($task['task']);
            $this->assertSame([true], $actual['outputs']);
            $this->assertSame($this->hashAst($ast), $actual['ast_hash']);
            $this->assertSame('bounded_program_interpretation', $actual['search_resources']['timing_scope']);
            $this->assertFalse($actual['promotion_evidence']);
        }
    }

    public function test_original_task_parser_rejects_malformed_or_mismatched_preserved_ast_copies(): void
    {
        $program = $this->program(1); $owner = app(TypedInstrumentFoundryService::class);
        $other = $this->ast(2);
        foreach (['{invalid-json', json_encode($other), json_encode(['op' => 'CONST', 'type' => 'bool', 'value' => true])] as $copy) {
            [$run] = $this->fixtureRun($program, $this->ast(1), 55, [], ['ast_json' => $copy]);
            $this->assertSame('ORIGINAL_TASK_AST_JSON_COPY_MISMATCH', $owner->recordProgramOutcome($program->program_key, $run->run_id)['reason']);
            $this->assertSame('completed', $run->fresh()->status);
        }
        $this->assertDatabaseCount('research_instrument_abstractions', 0);
    }

    public function test_archive_is_actual_behavior_not_model_name_or_parameter_distance(): void
    {
        $owner = app(TypedInstrumentFoundryService::class); $program = $this->program(1);
        $trade = ['entry_time' => '2025-01-01T11:00:00Z', 'exit_time' => '2025-01-01T12:00:00Z', 'signal_time' => '2025-01-01T10:59:00Z'];
        [$a, $response] = $this->fixtureRun($program, $this->ast(1), 1, [], [], true, 1, [$trade], 2, ['atr_period' => 14]);
        [$b] = $this->fixtureRun($program, $this->ast(1), 2, [], [], true, 1, [$trade], 2, ['atr_period' => 40]);
        $trade['exit_time'] = '2025-01-01T16:00:00Z';
        [$c] = $this->fixtureRun($program, $this->ast(1), 3, [], [], true, 1, [$trade]);
        $first = $owner->recordBehaviorOutcome($a->run_id, $response);
        $second = $owner->recordBehaviorOutcome($b->run_id); $third = $owner->recordBehaviorOutcome($c->run_id);
        $this->assertSame('behavior_observed', $first['status']);
        $this->assertSame(60.0, $first['descriptors']['response_latency_seconds']);
        $this->assertSame(3600.0, $first['descriptors']['holding_seconds']);
        $this->assertSame($first['descriptor_cell'], $second['descriptor_cell']);
        $this->assertNotSame($first['descriptor_cell'], $third['descriptor_cell']);
        $this->assertNull($first['descriptors']['cost_sensitivity']);
        $this->assertNull($first['confirmed_value']);
        $this->assertFalse($first['promotion_evidence']);
        $this->assertSame('behavior_already_observed', $owner->recordBehaviorOutcome($a->run_id)['status']);
        $alternatives = $owner->behaviorAlternatives($first['entry_key']);
        $this->assertCount(2, $alternatives['alternatives']);
        $this->assertSame(['spread_limit' => 1], $alternatives['alternatives'][0]['shared_error_occurrences']);
        $this->assertDatabaseCount('research_behavior_archive', 3);
    }

    public function test_zero_trade_archive_reports_unknowns_and_excludes_incompatible_or_mutated_sources(): void
    {
        $owner = app(TypedInstrumentFoundryService::class); $program = $this->program(1);
        [$a, $response] = $this->fixtureRun($program, $this->ast(1), 1);
        [$b, , $model] = $this->fixtureRun($program, $this->ast(1), 2);
        [$c] = $this->fixtureRun($program, $this->ast(1), 3, [], [], true, 1, [], 2, [], str_repeat('c', 64));
        $first = $owner->recordBehaviorOutcome($a->run_id);
        $this->assertNull($first['descriptors']['holding_seconds']);
        $this->assertContains('response_latency', $first['descriptors']['missing_dimensions']);
        $this->assertContains('holding_time', $first['descriptors']['missing_dimensions']);
        $owner->recordBehaviorOutcome($b->run_id); $owner->recordBehaviorOutcome($c->run_id);
        $this->assertCount(1, $owner->behaviorAlternatives($first['entry_key'])['alternatives']);
        $model->update(['parameters' => ['atr_period' => 99]]);
        $this->assertCount(0, $owner->behaviorAlternatives($first['entry_key'])['alternatives']);
        $response['total_trades'] = 999;
        $this->assertSame('ORIGINAL_IDENTITY_OR_RESPONSE_SHA_MISMATCH', $owner->recordBehaviorOutcome($a->run_id, $response)['reason']);
        $this->assertSame('completed', $a->fresh()->status);
    }

    public function test_behavior_sampling_is_bounded_and_archive_tampering_is_not_silently_overwritten(): void
    {
        $program = $this->program(1); $owner = app(TypedInstrumentFoundryService::class);
        $trade = ['entry_time' => '2025-01-01T11:00:00Z', 'exit_time' => '2025-01-01T12:00:00Z'];
        [$run, $response] = $this->fixtureRun($program, $this->ast(1), 1, [], [], true, 1, array_fill(0, 300, $trade), 600);
        $result = $owner->recordBehaviorOutcome($run->run_id, $response);
        $this->assertSame(256, $result['descriptors']['sampled_trades']);
        $this->assertSame(512, $result['descriptors']['sampled_decisions']);
        $this->assertTrue($result['descriptors']['trades_truncated']);
        $this->assertTrue($result['descriptors']['decisions_truncated']);
        DB::table('research_behavior_archive')->where('entry_key', $result['entry_key'])->update(['descriptors' => '{}']);
        $this->assertSame('BEHAVIOR_IMMUTABLE_ENTRY_DRIFT', $owner->recordBehaviorOutcome($run->run_id)['reason']);
        $this->assertSame('{}', DB::table('research_behavior_archive')->where('entry_key', $result['entry_key'])->value('descriptors'));
    }

    private function trainedMacro(): array
    {
        $owner = app(TypedInstrumentFoundryService::class);
        foreach ([1, 2] as $threshold) {
            $program = $this->program($threshold);
            [$run] = $this->fixtureRun($program, $this->ast($threshold), $threshold);
            $this->assertSame('solved_task_observed', $owner->recordProgramOutcome($program->program_key, $run->run_id)['status']);
            if ($threshold === 1) $first = $program;
        }
        $compression = $owner->compress($first->program_key);
        return [$first, DB::table('research_instrument_abstractions')->firstOrFail(), $compression];
    }

    private function program(int $threshold): object
    {
        $result = app(TypedInstrumentFoundryService::class)->compile($this->ast($threshold), $this->context(), $this->gates());
        return DB::table('research_instrument_programs')->where('program_key', $result['program_key'])->firstOrFail();
    }

    private function ast(int $threshold): array
    {
        return ['op' => 'AND', 'args' => [
            ['op' => 'GREATER_THAN', 'args' => [['op' => 'PRICE_CLOSE', 'available_at' => '2025-01-01T10:00:00Z'], ['op' => 'CONST', 'type' => 'number', 'value' => $threshold]]],
            ['op' => 'NOT', 'args' => [['op' => 'BOOL', 'available_at' => '2025-01-01T10:00:00Z']]],
        ]];
    }

    private function candles(int $evaluated = 2): array { return array_fill(0, 200 + $evaluated, ['time' => '2025-01-01T10:00:00Z', 'close' => 2000]); }
    private function context(): array { return ['symbol' => 'XAUUSD', 'timeframe' => 'H1', 'pre_2026_only' => true,
        'data_hash' => app(LabImmutableEvidenceService::class)->hash($this->candles()), 'execution_hash' => str_repeat('b', 64)]; }
    private function gates(): array { return ['confirmed_cartridges' => 1, 'successful_transfers' => 1]; }

    private function fixtureRun(object $program, array $ast, int $taskNumber, array $responseOverride = [], array $taskOverride = [],
        bool $withTask = true, float $cpu = 1.0, array $trades = [], int $evaluated = 2, array $parameters = [], ?string $execution = null): array
    {
        $program = DB::table('research_instrument_programs')->where('id', $program->id)->firstOrFail();
        $lab = AiLaboratory::firstOrCreate(['name' => 'Research fixture', 'symbol' => 'XAUUSD', 'timeframe' => 'H1'], ['strategy_families' => ['trend']]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => LabGeneration::count() + 1]);
        $model = ModelVersion::create(['name' => 'Actual fixture model-'.$generation->id, 'strategy' => 'trend_v1', 'version' => 'test', 'parameters' => $parameters, 'metadata' => []]);
        $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'trend', 'origin' => 'test', 'parameter_diff' => []]);
        $owner = app(LabImmutableEvidenceService::class);
        $run = $owner->beginRun($agent, 'screening', 'test', ['code_hash' => str_repeat('a', 64)]);
        $contract = json_decode($program->compiled_contract, true);
        $definitions = [];
        foreach ((array) ($contract['abstraction_keys'] ?? []) as $key) $definitions[$key] = json_decode(DB::table('research_instrument_abstractions')->where('macro_key', $key)->value('definition'), true);
        foreach ((array) data_get($contract, 'compression.macro_keys', []) as $key) $definitions[$key] = json_decode(DB::table('research_instrument_abstractions')->where('macro_key', $key)->value('definition'), true);
        $baseTask = ['protocol' => 'sealed_research_program_task_v1', 'task_key' => 'question-'.$taskNumber,
            'program_key' => $program->program_key, 'ast_hash' => $program->ast_hash, 'ast' => $ast,
            'scope_key' => $contract['scope_key'], 'abstractions' => $definitions ?: (object) [],
            'input_vectors' => [['decision_at' => '2025-01-01T11:00:00Z', 'price_close' => 2000 + $taskNumber, 'bool' => false]], 'expected_outputs' => [true],
            'search_budget' => ['cpu_seconds' => 0.25, 'max_expansions' => 100]];
        // Execute actual native semantics, not a caller's solved boolean. For
        // malformed-request negative fixtures only, seal the deliberately bad
        // request beside an actual valid result to exercise the PHP boundary.
        $executionTask = $baseTask;
        $executionTask['ast'] = json_decode($program->ast, true);
        if ($this->hashAst($ast) === $program->ast_hash || ($ast['op'] ?? null) === 'CALL') $executionTask['ast'] = $ast;
        $nativeResult = $this->nativeTask($executionTask);
        $task = array_replace_recursive($baseTask, $taskOverride);
        $request = ['symbol' => 'XAUUSD', 'timeframe' => 'H1', 'parameters' => $model->parameters, 'candles' => $this->candles($evaluated),
            'execution_contract' => ['execution_hash' => $execution ?? str_repeat('b', 64)]];
        if ($withTask) $request['policy_context']['research_program_task'] = $task;
        if (isset($taskOverride['legacy_path_copy'])) $request['research_program_task'] = $taskOverride['legacy_path_copy'];
        $owner->attachRequest($run, $request);
        $trace = [];
        for ($i = 0; $i < $evaluated; $i++) $trace[] = ['candle_index' => 200 + $i,
            'candle_time' => (new \DateTimeImmutable('2025-01-01T10:00:00Z'))->modify('+'.$i.' minutes')->format('Y-m-d\TH:i:s\Z'),
            'event_type' => 'signal_evaluation', 'action' => 'WAIT', 'accepted' => false, 'rejection_code' => 'no_signal',
            'context_axes' => ['session' => 'london'], 'error_code' => $i === 0 ? 'spread_limit' : null];
        $response = array_replace_recursive(['total_trades' => count($trades), 'trade_ledger' => $trades, 'trades' => $trades,
            'displayed_trade_count' => count($trades), 'trade_ledger_hash' => $owner->hash($trades), 'decision_trace' => $trace,
            'benchmark' => ['research_program_task' => [...$nativeResult, 'task_key' => $task['task_key']]],
            'data_quality' => ['decision_trace' => ['protocol' => 'candle_decision_trace_v1', 'requested' => true, 'complete' => true,
                'event_count' => count($trace), 'evaluated_candle_count' => $evaluated]]], $responseOverride);
        if (isset($responseOverride['research_program_task'])) {
            unset($response['research_program_task']);
            $response['benchmark']['research_program_task'] = array_replace_recursive($response['benchmark']['research_program_task'], $responseOverride['research_program_task']);
        }
        $owner->finishRun($run, 'completed', $response);
        $this->assertTrue($owner->learningEligibility($run->fresh())['complete']);
        $this->assertNotNull($owner->verifiedModelRuntimeIdentity($run->fresh()));
        return [$run->fresh(), $response, $model];
    }

    private function nativeTask(array $task): array
    {
        $process = new Process(['python', '-c', 'import json,sys; from app.services.research_program_tasks import execute_task; print(json.dumps(execute_task(json.load(sys.stdin))))'], dirname(base_path()).'/ai-service-python');
        $process->setInput(json_encode($task)); $process->setTimeout(10); $process->mustRun();
        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function hashAst(array $ast): string
    {
        $normalize = function (array $value) use (&$normalize): array {
            if (! array_is_list($value)) ksort($value);
            foreach ($value as $key => $item) if (is_array($item)) $value[$key] = $normalize($item);
            return $value;
        };
        return hash('sha256', json_encode($normalize($ast), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }
}
