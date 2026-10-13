<?php

namespace Tests\Feature;

use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Services\AgentMethodLearningService;
use App\Services\InstrumentMethodLibraryService;
use App\Services\LabImmutableEvidenceService;
use App\Services\ResearchPaperEpochContractService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class AgentMethodLearningTest extends TestCase
{
    use RefreshDatabase;

    private array $library;
    private array $recipe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->recipe = ['protocol' => 'instrument_method_recipe_v1', 'recipe_key' => 'closed-confirmation',
            'revision' => 1, 'context_predicate' => ['regime' => 'trend_up'],
            'method_hashes' => [str_repeat('a', 64)], 'method_contracts' => [str_repeat('a', 64) => ['implementation' => ['kind' => 'typed_operator']]]];
        $recipeHash = $this->hash($this->recipe);
        $body = ['protocol' => 'instrument_method_library_v1', 'instrument_inventory_hash' => str_repeat('b', 64),
            'method_hashes' => [str_repeat('a', 64)], 'recipe_hashes' => [$recipeHash]];
        $this->library = ['valid' => true, 'library_hash' => $this->hash($body), 'library_contract' => $body,
            'library_contract_json' => $this->canonicalJson($body)];
        $libraryOwner = Mockery::mock(InstrumentMethodLibraryService::class);
        $libraryOwner->shouldReceive('inventory', 'verifyLibrary', 'librarySnapshot')->andReturn($this->library);
        $libraryOwner->shouldReceive('inspectRecipe')->with($recipeHash)->andReturn(['status' => 'registered_research_only',
            'recipe_hash' => $recipeHash, 'contract' => $this->recipe]);
        $this->app->instance(InstrumentMethodLibraryService::class, $libraryOwner);
    }

    public function test_policy_revision_is_frozen_and_cannot_accept_caller_authority(): void
    {
        $owner = app(AgentMethodLearningService::class); $input = $this->policyInput();
        $first = $owner->registerPolicy($input);
        $this->assertSame('registered_research_only', $first['status']);
        $this->assertSame($first['policy_hash'], $owner->registerPolicy($input)['policy_hash']);
        $this->assertSame('POLICY_REVISION_IS_IMMUTABLE', $owner->registerPolicy([...$input, 'default_action' => 'WAIT'])['reason']);
        $this->assertSame('POLICY_UNSUPPORTED_FIELDS', $owner->registerPolicy([...$input, 'master' => true])['reason']);
        $this->assertDatabaseCount('agent_method_policy_revisions', 1);
        $this->assertFalse($first['skill_credit']);
        $this->assertFalse($first['mentor_authority']);
        $this->assertSame($this->library['library_hash'], $owner->policy($first['policy_hash'])['library_hash']);
    }

    public function test_original_trace_yields_contextual_diagnostics_and_not_supplied_scores(): void
    {
        [$run, $request, $response] = $this->products();
        $response['scores'] = ['master' => true, 'profit' => 999999];
        $run->response_hash = app(LabImmutableEvidenceService::class)->hash($response);
        $result = app(AgentMethodLearningService::class)->settleOriginalRun($run, $request, $response);
        $this->assertSame('verified_diagnostic', $result['status'], $result['reason'] ?? '');
        $this->assertSame(1, $result['observations']['used_count']);
        $this->assertSame(1, $result['observations']['behavior_changed_count']);
        $this->assertArrayNotHasKey('scores', $result['experiences'][0]['facts']);
        $this->assertFalse($result['out_of_sample']);
        $this->assertFalse($result['confirmation_proven']);
        $this->assertDatabaseCount('agent_method_experiences', 0);
    }

    public function test_self_rehashed_counters_without_actual_trace_are_rejected(): void
    {
        [$run, $request, $response] = $this->products();
        $receipt = &$response['data_quality']['instrument_method_learning_receipt'];
        $receipt['used_count'] = 50; $receipt = $this->seal($receipt, 'receipt_hash', 'receipt_json');
        $run->response_hash = app(LabImmutableEvidenceService::class)->hash($response);
        $result = app(AgentMethodLearningService::class)->settleOriginalRun($run, $request, $response);
        $this->assertSame('METHOD_RECEIPT_COUNTER_MISMATCH', $result['reason']);
        $this->assertDatabaseCount('agent_method_experiences', 0);
    }

    public function test_trace_duplicates_future_observation_and_forged_behavior_are_rejected(): void
    {
        foreach (['duplicate', 'future', 'behavior'] as $mutation) {
            [$run, $request, $response] = $this->products(uniqid('agent-', true));
            $event = &$response['decision_trace'][0]['method_learning'];
            if ($mutation === 'future') $event['observed_at'] = '2025-12-20T12:02:00Z';
            if ($mutation === 'behavior') $event['behavior_changed'] = false;
            $event = $this->seal($event, 'receipt_hash');
            if ($mutation === 'duplicate') $response['decision_trace'][] = $response['decision_trace'][0];
            $run->response_hash = app(LabImmutableEvidenceService::class)->hash($response);
            $result = app(AgentMethodLearningService::class)->settleOriginalRun($run, $request, $response);
            $this->assertSame(['duplicate' => 'METHOD_TRACE_DUPLICATE_DECISION', 'future' => 'METHOD_TRACE_AS_OF_OR_SCOPE_INVALID',
                'behavior' => 'METHOD_TRACE_BEHAVIOR_MISMATCH'][$mutation], $result['reason']);
        }
    }

    public function test_post_2026_scope_changed_recipe_and_late_registration_cannot_learn(): void
    {
        [$run, $request, $response] = $this->products();
        $contract = &$request['instrument_method_learning'];
        $contract['scope']['evaluated_end'] = '2026-01-01T00:00:00Z';
        $contract = $this->seal($contract, 'contract_hash', 'contract_json');
        $this->assertSame('PRE_2026_METHOD_SCOPE_REQUIRED', app(AgentMethodLearningService::class)->settleOriginalRun($run, $request, $response)['reason']);
        [$run, $request, $response] = $this->products('second-agent');
        $recipe = &$request['instrument_method_learning']['recipes'][0];
        $recipe['recipe_revision']['recipe_key'] = 'forged'; $recipe = $this->seal($recipe, 'runtime_hash', 'runtime_json');
        $request['instrument_method_learning'] = $this->seal($request['instrument_method_learning'], 'contract_hash', 'contract_json');
        $this->assertSame('ORIGINAL_RECIPE_DECLARATION_MISMATCH', app(AgentMethodLearningService::class)->settleOriginalRun($run, $request, $response)['reason']);
        [$run, $request, $response] = $this->products('third-agent');
        DB::table('agent_method_policy_revisions')->where('policy_hash', $request['instrument_method_learning']['policy']['policy_hash'])
            ->update(['created_at' => $run->finished_at->addMinute()]);
        $this->assertSame('POLICY_MUST_PRECEDE_RUN', app(AgentMethodLearningService::class)->settleOriginalRun($run, $request, $response)['reason']);
    }

    public function test_durable_learning_reopens_originals_is_idempotent_and_freezes_proposal_sources(): void
    {
        [$run, $request, $response] = $this->products();
        $requestArtifact = $this->artifact($run, 'evaluation_request', $request, ['request_hash' => $run->request_hash]);
        $this->artifact($run, 'evaluation_response', $response);
        $owner = Mockery::mock(LabImmutableEvidenceService::class)->makePartial();
        $owner->shouldReceive('learningEligibility')->andReturn(['complete' => true]);
        $owner->shouldReceive('verifiedModelRuntimeIdentity')->andReturn(['request_artifact_hash' => $requestArtifact->sha256]);
        $this->app->instance(LabImmutableEvidenceService::class, $owner);
        $learning = app(AgentMethodLearningService::class);
        $run->status = 'technical_error'; // Caller memory is ignored; persisted original is reopened.
        $one = $learning->learnFromRun($run); $two = $learning->learnFromRun($run);
        $this->assertSame('learned_diagnostic', $one['status'], $one['reason'] ?? '');
        $this->assertSame($one['experience_keys'], $two['experience_keys']);
        $this->assertDatabaseCount('agent_method_experiences', 1);
        $proposal = $learning->proposePolicy($one['policy_hash'], 2);
        $this->assertSame('frozen_diagnostic_proposal', $proposal['proposal_status'], $proposal['reason'] ?? '');
        $this->assertSame($this->library['library_hash'], $proposal['library_hash']);
        $this->assertSame($one['experience_keys'], array_column($proposal['learning_provenance']['sources'], 'experience_key'));
        $this->assertSame([$this->library['library_contract']['recipe_hashes'][0]], $proposal['rules'][0]['preferred_recipes']);
        $this->assertFalse($proposal['learning_provenance']['out_of_sample']);
        $forged = array_intersect_key($proposal, array_flip(['agent_key', 'revision', 'library_hash', 'library_contract', 'rules', 'default_action', 'learning_provenance']));
        $forged['revision'] = 3;
        $forged['learning_provenance']['contextual_statistics'][0][0]['used_count'] = 999;
        $this->assertSame('POLICY_LEARNING_PROVENANCE_INVALID', $learning->registerPolicy($forged)['reason']);
        $this->assertDatabaseCount('instrument_recipe_revisions', 0);
        $stats = $learning->statistics();
        $this->assertSame(2, $stats['agent_learning']['policy_revisions']);
        $this->assertSame(1, $stats['agent_learning']['original_runs']);
        $this->assertSame(0, $stats['instrument_library']['recipe_revisions']);
        DB::table('agent_method_experiences')->update(['facts' => json_encode(['caller_score' => 100])]);
        $this->assertSame('PRIOR_EXPERIENCE_CONTENT_MISMATCH', $learning->proposePolicy($one['policy_hash'], 3)['reason']);
    }

    public function test_actual_policy_context_and_exact_strategy_owner_paths_are_used(): void
    {
        [$run, $request, $response] = $this->products();
        $contract = $request['instrument_method_learning'];
        $request = ['evaluation_mode' => 'screening', 'policy_context' => ['instrument_method_learning' => $contract]];
        $owner = app(AgentMethodLearningService::class);
        $this->assertSame('verified_diagnostic', $owner->settleOriginalRun($run, $request, $response)['status']);
        $run->lab_agent_id = 81;
        $request = ['strategies' => [['lab_agent_id' => 81, 'instrument_method_learning' => $contract],
            ['lab_agent_id' => 82, 'instrument_method_learning' => ['invalid_sibling' => true]]]];
        $this->assertSame('verified_diagnostic', $owner->settleOriginalRun($run, $request, $response)['status']);
        $run->lab_agent_id = 82;
        $this->assertSame('ORIGINAL_METHOD_CONTRACT_REQUIRED', $owner->settleOriginalRun($run, $request, $response)['reason']);
        $run->lab_agent_id = 999;
        $this->assertSame('ORIGINAL_EXACT_STRATEGY_OWNER_REQUIRED', $owner->settleOriginalRun($run, $request, $response)['reason']);
    }

    public function test_native_members_learn_independent_policies_and_cannot_borrow_sibling_owner(): void
    {
        [$run, $oneRequest, $oneResponse] = $this->products('model:101');
        [, $twoRequest, $twoResponse] = $this->products('model:102');
        $members = [['specialist_id' => 'one', 'model_version_id' => 101, 'method_learning' => $oneRequest['instrument_method_learning']],
            ['specialist_id' => 'two', 'model_version_id' => 102, 'method_learning' => $twoRequest['instrument_method_learning']]];
        $native = $this->seal(['protocol' => 'specialist_council_runtime_v1', 'council_version' => str_repeat('e', 64),
            'members' => $members], 'contract_hash', 'contract_json');
        $trace = [['method_learning_events' => [$oneResponse['decision_trace'][0]['method_learning'], $twoResponse['decision_trace'][0]['method_learning']]]];
        $nativeReceipt = ['contract_hash' => $native['contract_hash'], 'decision_trace_identity' => [
            'protocol' => 'native_council_decision_trace_v1', 'contract_hash' => $native['contract_hash'], 'trace_hash' => $this->hash($trace)],
            'members' => array_map(fn ($member, $receipt) => ['specialist_id' => $member['specialist_id'],
                'member_version_hash' => $this->hash(['council_version' => $native['council_version'], 'member' => $member]),
                'method_learning_receipt' => $receipt], $members, [$oneResponse['data_quality']['instrument_method_learning_receipt'],
                    $twoResponse['data_quality']['instrument_method_learning_receipt']])];
        $response = ['decision_trace' => $trace, 'specialist_council_receipt' => $nativeReceipt,
            'data_quality' => ['decision_trace' => ['scope_owner' => 'native_specialist_council_v1', 'trace_hash' => $this->hash($trace)]]];
        $request = ['specialist_council_contract' => $native];
        $run->response_hash = app(LabImmutableEvidenceService::class)->hash($response);
        $result = app(AgentMethodLearningService::class)->settleOriginalRun($run, $request, $response);
        $this->assertSame('verified_diagnostic', $result['status'], $result['reason'] ?? '');
        $this->assertCount(2, $result['policy_hashes']);
        $this->assertNull($result['policy_hash']);
        $this->assertSame(2, $result['observations']['used_count']);
        $run->request_hash = app(LabImmutableEvidenceService::class)->hash($request);
        $run->save();
        $requestArtifact = $this->artifact($run, 'evaluation_request', $request, ['request_hash' => $run->request_hash]);
        $this->artifact($run, 'evaluation_response', $response);
        $immutable = Mockery::mock(LabImmutableEvidenceService::class)->makePartial();
        $immutable->shouldReceive('learningEligibility')->andReturn(['complete' => true]);
        $immutable->shouldReceive('verifiedModelRuntimeIdentity')->andReturn(['request_artifact_hash' => $requestArtifact->sha256]);
        $this->app->instance(LabImmutableEvidenceService::class, $immutable);
        $owner = app(AgentMethodLearningService::class);
        $this->assertSame('learned_diagnostic', $owner->learnFromRun($run)['status']);
        $this->assertSame('learned_diagnostic', $owner->learnFromRun($run)['status']);
        $this->assertDatabaseCount('agent_method_experiences', 2);
        $this->assertSame(2, DB::table('agent_method_experiences')->distinct()->count('policy_hash'));
        $native['members'][0]['model_version_id'] = 999;
        $native = $this->seal($native, 'contract_hash', 'contract_json');
        $response['specialist_council_receipt']['contract_hash'] = $native['contract_hash'];
        $response['specialist_council_receipt']['decision_trace_identity']['contract_hash'] = $native['contract_hash'];
        $response['specialist_council_receipt']['members'][0]['member_version_hash'] = $this->hash([
            'council_version' => $native['council_version'], 'member' => $native['members'][0]]);
        $run->response_hash = app(LabImmutableEvidenceService::class)->hash($response);
        $this->assertSame('ORIGINAL_METHOD_POLICY_OWNER_MISMATCH', app(AgentMethodLearningService::class)
            ->settleOriginalRun($run, ['specialist_council_contract' => $native], $response)['reason']);
    }

    public function test_rebase_provenance_requires_dedicated_server_derivation_and_verified_learning(): void
    {
        $owner = app(AgentMethodLearningService::class);
        $caller = [...$this->policyInput(), 'learning_provenance' => ['protocol' => 'fresh_model_method_learning_rebase_v1',
            'source_policy_hash' => str_repeat('a', 64), 'target_model_id' => 999, 'confirmation_proven' => false]];
        $this->assertSame('POLICY_REBASE_REQUIRES_SERVER_DERIVATION', $owner->registerPolicy($caller)['reason']);
        $base = $owner->registerPolicy([...$this->policyInput(), 'agent_key' => 'model:1']);
        $this->assertSame('POLICY_REBASE_VERIFIED_LEARNED_PROPOSAL_REQUIRED', $owner->registerRebasedPolicy($base['policy_hash'], 999)['reason']);
        $this->assertDatabaseCount('agent_method_policy_revisions', 1);
    }

    public function test_inspection_and_rebasing_reopen_frozen_original_experiences_after_poison_or_source_drift(): void
    {
        [$run, $request, $response] = $this->products('model:1');
        $requestArtifact = $this->artifact($run, 'evaluation_request', $request, ['request_hash' => $run->request_hash]);
        $this->artifact($run, 'evaluation_response', $response);
        $immutable = Mockery::mock(LabImmutableEvidenceService::class)->makePartial();
        $immutable->shouldReceive('learningEligibility')->andReturn(['complete' => true]);
        $immutable->shouldReceive('verifiedModelRuntimeIdentity')->andReturn(['request_artifact_hash' => $requestArtifact->sha256]);
        $this->app->instance(LabImmutableEvidenceService::class, $immutable);
        $owner = app(AgentMethodLearningService::class);
        $learned = $owner->learnFromRun($run); $proposal = $owner->proposePolicy($learned['policy_hash'], 2);
        $this->assertSame($proposal['policy_hash'], $owner->policy($proposal['policy_hash'])['policy_hash']);
        $row = DB::table('agent_method_experiences')->first(); $originalFacts = $row->facts;
        DB::table('agent_method_experiences')->where('id', $row->id)->update(['facts' => json_encode(['caller_score' => 999999])]);
        $this->assertSame('PRIOR_EXPERIENCE_CONTENT_MISMATCH', $owner->policy($proposal['policy_hash'])['reason']);
        $this->assertSame('POLICY_REBASE_VERIFIED_LEARNED_PROPOSAL_REQUIRED', $owner->registerRebasedPolicy($proposal['policy_hash'], 999)['reason']);
        DB::table('agent_method_experiences')->where('id', $row->id)->update(['facts' => $originalFacts]);
        $requestArtifact->update(['payload' => [...$request, 'evaluation_mode' => 'paper']]);
        $this->assertSame('ORIGINAL_REQUEST_ARTIFACT_HASH_MISMATCH', $owner->policy($proposal['policy_hash'])['reason']);
        $this->assertSame('POLICY_REBASE_VERIFIED_LEARNED_PROPOSAL_REQUIRED', $owner->registerRebasedPolicy($proposal['policy_hash'], 999)['reason']);
        $this->assertDatabaseCount('agent_method_policy_revisions', 2);
    }

    public function test_each_local_loop_reopens_one_original_for_many_facts_and_next_inspection_stays_fresh(): void
    {
        [$run, $request, $response] = $this->products();
        $events = [];
        foreach (['london', 'new_york', 'asia'] as $index => $session) {
            $event = $response['decision_trace'][0]['method_learning'];
            $event['decision_id'] = 'same-run-context-'.$index;
            $event['context'] = ['regime' => 'trend_up', 'session' => $session];
            $event['context_hash'] = $this->hash($event['context']);
            $events[] = $this->seal($event, 'receipt_hash');
        }
        $response['decision_trace'] = array_map(fn ($event) => ['event_type' => 'instrument_method_decision', 'method_learning' => $event], $events);
        $receipt = $response['data_quality']['instrument_method_learning_receipt'];
        foreach (['event_count', 'used_count', 'in_scope_count', 'behavior_changed_count'] as $field) $receipt[$field] = 3;
        $receipt['reason_counts'] = ['recipe_evaluated' => 3]; $receipt['samples'] = $events;
        $framed = '';
        foreach ($events as $event) { $encoded = $this->canonicalJson($event); $framed .= pack('N2', 0, strlen($encoded)).$encoded; }
        $receipt['events_hash'] = hash('sha256', $framed);
        $response['data_quality']['instrument_method_learning_receipt'] = $this->seal($receipt, 'receipt_hash', 'receipt_json');
        $originalOwner = app(LabImmutableEvidenceService::class);
        $run->update(['response_hash' => $originalOwner->hash($response)]);
        $requestArtifact = $this->artifact($run, 'evaluation_request', $request, ['request_hash' => $run->request_hash]);
        $this->artifact($run, 'evaluation_response', $response);
        $reads = [];
        $immutable = Mockery::mock(LabImmutableEvidenceService::class)->makePartial();
        $immutable->shouldReceive('learningEligibility')->andReturn(['complete' => true]);
        $immutable->shouldReceive('verifiedModelRuntimeIdentity')->andReturn(['request_artifact_hash' => $requestArtifact->sha256]);
        $immutable->shouldReceive('readArtifactPayload')->andReturnUsing(function ($artifact) use ($originalOwner, &$reads) {
            $reads[$artifact->artifact_type] = ($reads[$artifact->artifact_type] ?? 0) + 1;
            return $originalOwner->readArtifactPayload($artifact);
        });
        $this->app->instance(LabImmutableEvidenceService::class, $immutable);
        $owner = app(AgentMethodLearningService::class); $learned = $owner->learnFromRun($run);
        $this->assertSame('learned_diagnostic', $learned['status'], json_encode($learned));
        $this->assertCount(3, $learned['experience_keys']);
        $rows = DB::table('agent_method_experiences')->orderBy('experience_key')->get();
        $before = $rows->toJson(); $sources = []; $groups = [];
        foreach ($rows as $row) {
            $fact = json_decode($row->facts, true);
            $sources[] = ['experience_key' => $row->experience_key, 'run_id' => $row->run_id,
                'request_hash' => $fact['request_hash'], 'response_hash' => $fact['response_hash'], 'facts_hash' => $this->hash($fact)];
            $groups[$row->context_key] = ['context' => $fact['context'], 'recipe_hash' => $fact['recipe_hash'],
                'event_count' => 1, 'in_scope_count' => 1, 'used_count' => 1, 'behavior_changed_count' => 1, 'veto_count' => 1];
        }
        ksort($groups);
        $expected = ['protocol' => AgentMethodLearningService::POLICY_PROTOCOL, 'agent_key' => 'agent-one', 'revision' => 2,
            'library_hash' => $this->library['library_hash'], 'library_contract' => $this->library['library_contract'],
            'rules' => array_values(array_map(fn ($item) => ['context_predicate' => $item['context'], 'preferred_recipes' => [$item['recipe_hash']]], $groups)),
            'default_action' => 'BASELINE', 'learning_provenance' => ['protocol' => 'frozen_method_learning_exposure_v1',
                'parent_policy_hash' => $learned['policy_hash'], 'sources' => $sources, 'selection' => 'observed_use_then_behavior_then_veto',
                'max_recipes' => 3, 'contextual_statistics' => array_values(array_map(fn ($item) => [$item], $groups)),
                'out_of_sample' => false, 'confirmation_proven' => false]];
        $reads = []; $proposal = $owner->proposePolicy($learned['policy_hash'], 2);
        $this->assertSame('frozen_diagnostic_proposal', $proposal['proposal_status'], json_encode($proposal));
        $this->assertSame($this->hash($expected), $proposal['policy_hash']);
        // Proposal and registration each own a fresh loop: two reads/run/loop, not two reads/fact.
        $this->assertSame(['evaluation_request' => 2, 'evaluation_response' => 2], $reads);
        foreach ([1, 2] as $inspection) {
            $reads = [];
            $this->assertSame($proposal['policy_hash'], $owner->policy($proposal['policy_hash'])['policy_hash']);
            $this->assertSame(['evaluation_request' => 1, 'evaluation_response' => 1], $reads, 'Fresh inspection '.$inspection);
        }
        $last = $rows->last();
        DB::table('agent_method_experiences')->where('id', $last->id)->update(['facts' => '{"caller_score":999999}']);
        $this->assertSame('PRIOR_EXPERIENCE_CONTENT_MISMATCH', $owner->policy($proposal['policy_hash'])['reason']);
        DB::table('agent_method_experiences')->where('id', $last->id)->update(['facts' => $last->facts]);
        $requestArtifact->update(['payload' => [...$request, 'evaluation_mode' => 'paper']]);
        $this->assertSame('ORIGINAL_REQUEST_ARTIFACT_HASH_MISMATCH', $owner->policy($proposal['policy_hash'])['reason']);
        $requestArtifact->update(['payload' => $request]);
        $reads = [];
        $this->assertSame($proposal['policy_hash'], $owner->policy($proposal['policy_hash'])['policy_hash']);
        $this->assertSame(['evaluation_request' => 1, 'evaluation_response' => 1], $reads);
        $this->assertSame($before, DB::table('agent_method_experiences')->orderBy('experience_key')->get()->toJson());
        $this->assertDatabaseCount('agent_method_policy_revisions', 2);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    private function products(string $agentKey = 'agent-one'): array
    {
        $owner = app(AgentMethodLearningService::class);
        $registered = $owner->registerPolicy([...$this->policyInput(), 'agent_key' => $agentKey]);
        $this->assertSame('registered_research_only', $registered['status']);
        $policy = $owner->policy($registered['policy_hash']);
        $scope = ['symbol' => 'XAUUSD', 'timeframe' => 'M1', 'evaluated_start' => '2025-12-20T12:00:00Z',
            'evaluated_end' => '2025-12-20T12:10:00Z', 'context' => ['regime' => 'trend_up']];
        $recipeHash = $this->library['library_contract']['recipe_hashes'][0];
        $runtime = $this->seal(['protocol' => 'instrument_method_recipe_runtime_v1', 'recipe_hash' => $recipeHash,
            'recipe_key' => $this->recipe['recipe_key'], 'recipe_revision' => $this->recipe,
            'context_predicate' => $this->recipe['context_predicate'], 'operators' => [['target' => 'confirmation']], 'parameter_adapters' => [],
            'scope_bindings' => ['symbol' => 'XAUUSD', 'timeframe' => 'M1', 'data_hash' => str_repeat('c', 64),
                'execution_hash' => str_repeat('d', 64), 'pre_2026_only' => true]], 'runtime_hash', 'runtime_json');
        $contract = $this->seal(['protocol' => AgentMethodLearningService::CONTRACT_PROTOCOL, 'data_hash' => str_repeat('c', 64),
            'execution_hash' => str_repeat('d', 64), 'scope' => $scope, 'library_hash' => $this->library['library_hash'],
            'library_contract' => $this->library['library_contract'], 'recipes' => [$runtime], 'policy' => $policy,
            'compiled_pool_hash' => $this->hash(['recipes' => [['recipe_hash' => $recipeHash, 'runtime_hash' => $runtime['runtime_hash']]]])],
            'contract_hash', 'contract_json');
        $event = $this->seal(['protocol' => 'instrument_method_decision_v1', 'decision_id' => 'closed-decision-one', 'phase' => 'entry',
            'decision_at' => '2025-12-20T12:01:00Z', 'observed_at' => '2025-12-20T12:00:00Z',
            'context' => ['regime' => 'trend_up'], 'context_hash' => $this->hash(['regime' => 'trend_up']),
            'contract_hash' => $contract['contract_hash'], 'library_hash' => $contract['library_hash'],
            'compiled_pool_hash' => $contract['compiled_pool_hash'], 'policy_hash' => $policy['policy_hash'], 'agent_key' => $agentKey,
            'recipe_hash' => $recipeHash, 'runtime_hash' => $runtime['runtime_hash'], 'in_scope' => true, 'used' => true,
            'reason' => 'recipe_evaluated', 'outputs' => ['confirmation' => false], 'behavior_changed' => true,
            'research_only' => true, 'promotion_evidence' => false, 'economic_authority' => false], 'receipt_hash');
        $encoded = $this->canonicalJson($event);
        $receipt = $this->seal(['protocol' => 'instrument_method_learning_receipt_v1', 'contract_hash' => $contract['contract_hash'],
            'library_hash' => $contract['library_hash'], 'compiled_pool_hash' => $contract['compiled_pool_hash'],
            'policy_hash' => $policy['policy_hash'], 'agent_key' => $agentKey, 'revision' => 1,
            'data_hash' => $contract['data_hash'], 'execution_hash' => $contract['execution_hash'], 'scope' => $scope,
            'event_count' => 1, 'used_count' => 1, 'in_scope_count' => 1, 'behavior_changed_count' => 1,
            'reason_counts' => ['recipe_evaluated' => 1], 'events_hash' => hash('sha256', pack('N2', 0, strlen($encoded)).$encoded),
            'samples' => [$event], 'operator_receipts' => [], 'research_only' => true, 'promotion_evidence' => false,
            'economic_authority' => false], 'receipt_hash', 'receipt_json');
        $request = ['evaluation_mode' => 'screening', 'instrument_method_learning' => $contract];
        $response = ['decision_trace' => [['event_type' => 'instrument_method_decision', 'method_learning' => $event]],
            'data_quality' => ['instrument_method_learning_receipt' => $receipt]];
        $run = LabEvaluationRun::create(['run_id' => (string) Str::uuid(), 'phase' => 'screening', 'mode' => 'research',
            'status' => 'completed', 'started_at' => now()->addMinute(), 'finished_at' => now()->addMinutes(2),
            'data_hash' => $contract['data_hash'], 'request_hash' => app(LabImmutableEvidenceService::class)->hash($request),
            'response_hash' => app(LabImmutableEvidenceService::class)->hash($response)]);
        return [$run, $request, $response];
    }

    private function policyInput(): array
    {
        return ['agent_key' => 'agent-one', 'revision' => 1, 'library_hash' => $this->library['library_hash'],
            'library_contract' => $this->library['library_contract'], 'rules' => [['context_predicate' => ['regime' => 'trend_up'],
                'preferred_recipes' => $this->library['library_contract']['recipe_hashes']]], 'default_action' => 'BASELINE'];
    }

    private function artifact(LabEvaluationRun $run, string $type, array $body, array $metadata = []): LabEvidenceArtifact
    {
        return LabEvidenceArtifact::create(['artifact_id' => (string) Str::uuid(), 'run_id' => $run->run_id,
            'artifact_type' => $type, 'sha256' => app(LabImmutableEvidenceService::class)->hash($body), 'payload' => $body,
            'metadata' => $metadata, 'recorded_at' => now()]);
    }
    private function hash(array $body): string { return app(ResearchPaperEpochContractService::class)->parameterHash($body); }
    private function seal(array $body, string $key, ?string $jsonKey = null): array
    {
        $body = array_diff_key($body, array_flip(array_filter([$key, $jsonKey])));
        return [...$body, $key => $this->hash($body), ...($jsonKey ? [$jsonKey => $this->canonicalJson($body)] : [])];
    }
    private function canonicalJson(array $body): string
    {
        $sort = function ($value) use (&$sort) { if (! is_array($value)) return $value;
            if (! array_is_list($value)) ksort($value); return array_map($sort, $value); };
        return json_encode($sort($body), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
