<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\AgentMethodGenerationService;
use App\Services\AgentMethodLearningService;
use App\Services\AgentMethodStudyPreparationService;
use App\Services\ExecutionContractService;
use App\Services\InstrumentMethodLibraryService;
use App\Services\InstrumentMethodResearchBindingService;
use App\Services\LabImmutableEvidenceService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\StrategyParameterSchemaService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Original compressed software fixtures and actual Python method events, not market evidence. */
class AgentMethodOwnedOutcomeTest extends TestCase
{
    use RefreshDatabase;
    private array $recipes;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('owned_outcome_test');
        config()->set('services.lab_evidence.disk', 'owned_outcome_test');
        $evidence = Mockery::mock(LabImmutableEvidenceService::class)->makePartial();
        $evidence->shouldReceive('learningEligibility')->andReturnUsing(fn ($run) => ['complete' => $run->status === 'completed']);
        $evidence->shouldReceive('verifiedModelRuntimeIdentity')->andReturnUsing(function ($run) {
            $request = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'evaluation_request')->first();
            return $request ? ['request_artifact_hash' => $request->sha256] : null;
        });
        $this->app->instance(LabImmutableEvidenceService::class, $evidence);
        $this->recipes = ['london' => $this->recipe('london'), 'new_york' => $this->recipe('new_york')];
    }

    public function test_personal_outcomes_prefer_positive_context_over_frequent_losing_invocations(): void
    {
        [$model, $policy, $run] = $this->original();
        $owner = app(AgentMethodLearningService::class);
        $owner->learnFromRun($run);
        $v1 = $owner->proposePolicy($policy['policy_hash'], 2);
        $this->assertSame('frozen_diagnostic_proposal', $v1['proposal_status'], json_encode($v1));
        $this->assertCount(2, $v1['rules']);
        $before = DB::table('agent_method_experiences')->orderBy('id')->get()->toJson();
        $learned = $owner->learnOwnedOutcomesFromRun($run);
        $this->assertSame('learned_owned_outcomes', $learned['status'], json_encode($learned));
        $this->assertSame(15, $learned['matured_episodes']);
        $this->assertDatabaseCount('agent_method_owned_outcomes', 15);
        $v2 = $owner->proposeOutcomePolicy($policy['policy_hash'], 3);
        $this->assertSame('frozen_owned_outcome_proposal', $v2['proposal_status'], json_encode($v2));
        $this->assertCount(1, $v2['rules']);
        $this->assertSame('new_york', $v2['rules'][0]['context_predicate']['session']);
        $this->assertSame([$this->recipes['new_york']], $v2['rules'][0]['preferred_recipes']);
        $statistics = $v2['learning_provenance']['contextual_statistics'];
        $all = array_merge(...$statistics);
        $losing = collect($all)->firstWhere('recipe_hash', $this->recipes['london']);
        $winning = collect($all)->firstWhere('recipe_hash', $this->recipes['new_york']);
        $this->assertSame(10, $losing['matured_episodes']);
        $this->assertSame(-2.0, $losing['mean_net_cash']);
        $this->assertFalse($losing['eligible']);
        $this->assertSame(5, $winning['matured_episodes']);
        $this->assertSame(10.0, $winning['mean_net_cash']);
        $this->assertSame($before, DB::table('agent_method_experiences')->orderBy('id')->get()->toJson());
        $this->assertSame($v1['policy_hash'], $owner->policy($v1['policy_hash'])['policy_hash']);
        $this->assertSame($policy['policy_hash'], $model->fresh()->metadata[InstrumentMethodResearchBindingService::METADATA]['policy_hash']);
        $this->assertFalse($v2['learning_provenance']['individual_causal_credit']);
        $this->assertFalse($v2['learning_provenance']['paper_authority']);
        $this->assertFalse($v2['learning_provenance']['mastery_proven']);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_duplicate_replay_aliases_do_not_create_extra_matured_episode_power(): void
    {
        [$model, $policy, $first, $products] = $this->original();
        $second = $this->publish($model, $products['request'], $products['response']);
        $owner = app(AgentMethodLearningService::class);
        $one = $owner->learnOwnedOutcomesFromRun($first); $two = $owner->learnOwnedOutcomesFromRun($second);
        $this->assertSame('learned_owned_outcomes', $two['status'], json_encode($two));
        $this->assertSame(15, $two['duplicate_episodes_excluded']);
        $this->assertSame($one['outcome_keys'], $two['outcome_keys']);
        $this->assertDatabaseCount('agent_method_owned_outcomes', 15);
        $proposal = $owner->proposeOutcomePolicy($policy['policy_hash'], 2);
        $this->assertSame(15, count($proposal['learning_provenance']['sources']));
    }

    public function test_conflicting_same_physical_episode_refuses_new_history_without_overwrite(): void
    {
        [$model, $_policy, $first, $products] = $this->original();
        $owner = app(AgentMethodLearningService::class); $owner->learnOwnedOutcomesFromRun($first);
        $before = DB::table('agent_method_owned_outcomes')->orderBy('id')->get()->toJson();
        foreach ($products['response']['trade_ledger'] as &$trade) $trade['balance'] += 1.0;
        unset($trade); $products['response']['final_balance'] += 1.0;
        $second = $this->publish($model, $products['request'], $products['response']);
        $result = $owner->learnOwnedOutcomesFromRun($second);
        $this->assertSame('OWNED_METHOD_PHYSICAL_EPISODE_CONFLICT', $result['reason']);
        $this->assertSame($before, DB::table('agent_method_owned_outcomes')->orderBy('id')->get()->toJson());
    }

    public function test_censored_outcomes_and_insufficient_samples_fall_back_to_baseline_not_harmful_authority(): void
    {
        [$_model, $policy, $run] = $this->original(['censored_last' => true]);
        $owner = app(AgentMethodLearningService::class); $learned = $owner->learnOwnedOutcomesFromRun($run);
        $this->assertSame(1, $learned['censored_excluded']);
        $this->assertSame(14, $learned['matured_episodes']);
        $proposal = $owner->proposeOutcomePolicy($policy['policy_hash'], 2);
        $this->assertSame('frozen_owned_outcome_proposal', $proposal['proposal_status']);
        $this->assertSame([], $proposal['rules']);
        $this->assertSame('BASELINE', $proposal['default_action']);
        $this->assertSame('insufficient_or_nonpositive_baseline', $proposal['outcome_ranking']);
        $this->assertFalse($proposal['confirmation_proven']);
    }

    public function test_wait_and_veto_receipts_do_not_receive_counterfeit_zero_or_positive_pnl(): void
    {
        [$model, $policy, $run, $products] = $this->original();
        $products['response']['trade_ledger'] = []; $products['response']['total_trades'] = 0;
        $products['response']['final_balance'] = 10000.0;
        foreach ($products['response']['decision_trace'] as &$row) if (($row['event_type'] ?? null) === 'signal_evaluation') $row['accepted'] = false;
        unset($row);
        $empty = $this->publish($model, $products['request'], $products['response']);
        $owner = app(AgentMethodLearningService::class); $learned = $owner->learnOwnedOutcomesFromRun($empty);
        $this->assertSame('learned_owned_outcomes', $learned['status']);
        $this->assertSame(0, $learned['matured_episodes']);
        $this->assertSame('unknown_without_exact_counterfactual', $learned['wait_veto_utility']);
        $this->assertDatabaseCount('agent_method_owned_outcomes', 0);
        $this->assertSame('OWNED_METHOD_ORIGINAL_OUTCOMES_REQUIRED', $owner->proposeOutcomePolicy($policy['policy_hash'], 2)['reason']);
    }

    public function test_foreign_position_future_signal_missing_or_duplicate_entry_links_fail_closed(): void
    {
        [$model, $_policy, $_run, $original] = $this->original();
        $owner = app(AgentMethodLearningService::class);
        foreach (['foreign', 'future_signal', 'missing', 'duplicate'] as $kind) {
            $products = $original;
            if ($kind === 'foreign') $products['response']['trade_ledger'][0]['portfolio_member'] = 'another-owner';
            elseif ($kind === 'future_signal') $products['response']['trade_ledger'][0]['signal_time'] = '2025-01-01T07:01:00+00:00';
            elseif ($kind === 'missing') $products['response']['decision_trace'] = array_values(array_filter($products['response']['decision_trace'],
                fn ($row) => ! (($row['event_type'] ?? null) === 'signal_evaluation' && $row['candle_index'] === 200)));
            else $products['response']['decision_trace'][] = collect($products['response']['decision_trace'])->firstWhere('event_type', 'signal_evaluation');
            $run = $this->publish($model, $products['request'], $products['response']);
            $result = $owner->learnOwnedOutcomesFromRun($run);
            $this->assertSame('blocked', $result['status'], $kind.': '.json_encode($result));
        }
        $this->assertDatabaseCount('agent_method_owned_outcomes', 0);
    }

    public function test_frozen_proposal_reopens_originals_and_refuses_poison_or_caller_copied_v2_provenance(): void
    {
        [$_model, $policy, $run] = $this->original();
        $owner = app(AgentMethodLearningService::class); $owner->learnOwnedOutcomesFromRun($run);
        $proposal = $owner->proposeOutcomePolicy($policy['policy_hash'], 2);
        $forged = array_intersect_key($proposal, array_flip(['agent_key', 'revision', 'library_hash', 'library_contract', 'rules', 'default_action', 'learning_provenance']));
        $forged['revision'] = 3;
        $this->assertSame('OWNED_METHOD_POLICY_REQUIRES_SERVER_DERIVATION', $owner->registerPolicy($forged)['reason']);
        $row = DB::table('agent_method_owned_outcomes')->first();
        $facts = json_decode($row->facts, true); $facts['episode']['net_cash'] = 99999.0;
        DB::table('agent_method_owned_outcomes')->where('id', $row->id)->update(['facts' => json_encode($facts)]);
        $this->assertSame('POLICY_LEARNING_PROVENANCE_INVALID', $owner->policy($proposal['policy_hash'])['reason']);
        DB::table('agent_method_owned_outcomes')->where('id', $row->id)->update(['facts' => $row->facts]);
        $artifact = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'trade_ledger')->first();
        Storage::disk('owned_outcome_test')->put($artifact->storage_path, gzencode('[]'));
        $this->assertSame('blocked', $owner->policy($proposal['policy_hash'])['status']);
    }

    public function test_generation_once_keeps_v1_frozen_and_normal_constructor_prefers_verified_positive_v2(): void
    {
        [$parent, $policy, $run] = $this->original();
        $learning = app(AgentMethodLearningService::class); $generation = app(AgentMethodGenerationService::class);
        $learning->learnFromRun($run); $old = $generation->proposalOnce($policy['policy_hash']);
        $oldRow = DB::table('agent_method_policy_revisions')->where('policy_hash', $old['policy_hash'])->first()->contract;
        $child = $this->model(); $before = $parent->fresh()->toArray();
        $lab = AiLaboratory::create(['name' => 'owned outcome fixture', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_families' => ['breakout']]);
        $cohort = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'status' => 'draft',
            'trigger_context' => ['instrument_method_generation_learning' => ['enabled' => true, 'recipe_hashes' => array_values($this->recipes)]]]);
        $prepared = $generation->prepareConstructorChild($child, $parent, $cohort, 1, []);
        $this->assertSame('prospective_child_policy_attached', $prepared['status'], json_encode($prepared));
        $own = $learning->policy($prepared['policy_hash']);
        $this->assertSame('original_parent_owned_outcome', $own['learning_provenance']['mode']);
        $this->assertTrue($own['learning_provenance']['constructor']['owned_outcome_learning']);
        $this->assertSame('OWNED_METHOD_POWERED_POSITIVE_ASSOCIATION', $own['learning_provenance']['constructor']['owned_outcome_selection']);
        $source = $generation->proposalOnce($policy['policy_hash'], true); $again = $generation->proposalOnce($policy['policy_hash'], true);
        $this->assertSame($source['policy_hash'], $again['policy_hash']); $this->assertTrue($again['already_proposed']);
        $this->assertSame($old['policy_hash'], $generation->proposalOnce($policy['policy_hash'])['policy_hash']);
        $this->assertSame($oldRow, DB::table('agent_method_policy_revisions')->where('policy_hash', $old['policy_hash'])->first()->contract);
        $this->assertSame($before, $parent->fresh()->toArray());
        $this->assertSame($parent->parameters, $child->fresh()->parameters);
        $baseline = app(AgentMethodStudyPreparationService::class)->baseline($child->fresh(), $own);
        $this->assertArrayNotHasKey(AgentMethodGenerationService::METADATA, $baseline['metadata']);
        $studyOwner = app(AgentMethodStudyPreparationService::class);
        $sourcePolicyOwner = new \ReflectionMethod($studyOwner, 'sourcePolicies');
        [$studyOwn, $studyBaseline, $studyRoot] = $sourcePolicyOwner->invoke($studyOwner, $child->fresh());
        $this->assertSame($own['policy_hash'], $studyOwn['policy_hash']);
        $this->assertSame($policy['policy_hash'], $studyBaseline['policy_hash']);
        $this->assertSame($source['policy_hash'], $studyRoot);
        $this->assertNotSame($old['policy_hash'], $studyRoot);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_normal_constructor_uses_owned_baseline_instead_of_retaining_frequent_negative_recipes(): void
    {
        [$parent, $policy, $run] = $this->original(['all_negative' => true]);
        app(AgentMethodLearningService::class)->learnFromRun($run);
        $lab = AiLaboratory::create(['name' => 'owned negative fixture', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_families' => ['breakout']]);
        $cohort = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'status' => 'draft',
            'trigger_context' => ['instrument_method_generation_learning' => ['enabled' => true, 'recipe_hashes' => array_values($this->recipes)]]]);
        $child = $this->model();
        $result = app(AgentMethodGenerationService::class)->prepareConstructorChild($child, $parent, $cohort, 1, []);
        $this->assertSame('prospective_child_policy_attached', $result['status'], json_encode($result));
        $own = app(AgentMethodLearningService::class)->policy($result['policy_hash']);
        $this->assertSame('original_parent_owned_outcome', $own['learning_provenance']['mode']);
        $this->assertSame('OWNED_METHOD_POWERED_NONPOSITIVE_BASELINE', $own['learning_provenance']['constructor']['owned_outcome_selection']);
        $this->assertTrue($own['learning_provenance']['constructor']['owned_outcome_learning']);
        $this->assertSame([], $own['rules']);
        $this->assertSame('BASELINE', $own['default_action']);
    }

    public function test_explicit_owned_parent_missing_accepted_entry_link_cannot_downgrade_to_v1_frequency_ranking(): void
    {
        [$ancestor, $policy, $run] = $this->original();
        $learning = app(AgentMethodLearningService::class); $generation = app(AgentMethodGenerationService::class);
        $learning->learnFromRun($run); $learning->learnOwnedOutcomesFromRun($run);
        $parent = $this->model();
        $parentAttached = $generation->prepareFreshChild($parent, $ancestor, ['owned_outcome_learning' => true]);
        $this->assertSame('prospective_child_policy_attached', $parentAttached['status'], json_encode($parentAttached));
        $parent = $parent->fresh(); $ownedPolicy = $learning->policy($parentAttached['policy_hash']);
        [$_parent, $_policy, $validRun, $products] = $this->original(['model' => $parent, 'policy' => $ownedPolicy]);
        $learning->learnFromRun($validRun);
        $legacy = $generation->proposalOnce($ownedPolicy['policy_hash']);
        $this->assertSame('frozen_diagnostic_proposal', $legacy['proposal_status']);
        $products['response']['decision_trace'] = array_values(array_filter($products['response']['decision_trace'],
            fn ($row) => ! (($row['event_type'] ?? null) === 'signal_evaluation' && $row['candle_index'] === 200)));
        $this->publish($parent, $products['request'], $products['response']);
        $lab = AiLaboratory::create(['name' => 'owned downgrade fixture', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_families' => ['breakout']]);
        $cohort = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'status' => 'draft',
            'trigger_context' => ['instrument_method_generation_learning' => ['enabled' => true, 'recipe_hashes' => array_values($this->recipes)]]]);
        $child = $this->model(); $before = $child->fresh()->toArray();
        $prepared = $generation->prepareConstructorChild($child, $parent, $cohort, 1, []);
        $this->assertSame('dependency', $prepared['status'], json_encode($prepared));
        $this->assertSame('OWNED_METHOD_ACCEPTED_ENTRY_LEDGER_LINK_REQUIRED', $prepared['reason']);
        $this->assertSame($before, $child->fresh()->toArray());
        $this->assertArrayNotHasKey(InstrumentMethodResearchBindingService::METADATA, $child->fresh()->metadata);
        $this->assertArrayNotHasKey(AgentMethodGenerationService::METADATA, $child->fresh()->metadata);
        $this->assertSame($legacy['policy_hash'], $generation->proposalOnce($ownedPolicy['policy_hash'])['policy_hash']);
    }

    public function test_multi_recipe_position_is_a_bundle_association_not_individual_recipe_credit(): void
    {
        [$_parent, $policy, $run] = $this->original(['multi_path' => true]);
        $owner = app(AgentMethodLearningService::class); $result = $owner->learnOwnedOutcomesFromRun($run);
        $this->assertSame('learned_owned_outcomes', $result['status'], json_encode($result));
        $facts = DB::table('agent_method_owned_outcomes')->get()->map(fn ($row) => json_decode($row->facts, true));
        $bundles = $facts->where('association', 'multi_recipe_bundle_not_individual_effect');
        $this->assertCount(5, $bundles);
        foreach ($bundles as $fact) { $this->assertNull($fact['episode']['rankable_recipe_hash']); $this->assertFalse($fact['individual_causal_credit']); }
        $proposal = $owner->proposeOutcomePolicy($policy['policy_hash'], 2);
        $this->assertSame([], $proposal['rules']);
        $this->assertSame('BASELINE', $proposal['default_action']);
    }

    public function test_original_v2_study_carrier_projection_uses_owned_outcomes_once_and_preserves_v1_study_mode(): void
    {
        [$parent, $initial, $run] = $this->original();
        $learning = app(AgentMethodLearningService::class); $generation = app(AgentMethodGenerationService::class);
        $this->assertSame('learned_diagnostic', $learning->learnFromRun($run)['status']);
        $legacy = $generation->proposalOnce($initial['policy_hash']);
        $legacyBefore = DB::table('agent_method_policy_revisions')->where('policy_hash', $legacy['policy_hash'])->first()->contract;
        $child = $this->model();
        $lab = AiLaboratory::create(['name' => 'owned study projection fixture', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_families' => ['breakout']]);
        $cohort = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'status' => 'draft',
            'trigger_context' => ['instrument_method_generation_learning' => ['enabled' => true, 'recipe_hashes' => array_values($this->recipes)]]]);
        $own = $generation->prepareConstructorChild($child, $parent, $cohort, 1, []);
        $this->assertSame('prospective_child_policy_attached', $own['status'], json_encode($own));
        $this->assertSame('original_parent_owned_outcome', $learning->policy($own['policy_hash'])['learning_provenance']['mode']);

        $library = app(InstrumentMethodLibraryService::class); $second = [];
        foreach ($this->recipes as $recipeHash) {
            $recipe = $library->inspectRecipe($recipeHash)['contract'];
            $revision = $library->registerRecipe(['recipe_key' => $recipe['recipe_key'], 'revision' => 2,
                'method_hashes' => $recipe['method_hashes'], 'context_predicate' => $recipe['context_predicate']]);
            $this->assertSame('registered_research_only', $revision['status'], json_encode($revision)); $second[] = $revision['recipe_hash'];
        }
        $root = storage_path('app/lab-datasets/owned-study-projection-'.Str::uuid()); File::ensureDirectoryExists($root);
        $start = CarbonImmutable::parse('2025-11-03T07:00:00Z'); $csv = "time,open,high,low,close\n";
        for ($index = 0; $index < 512; $index++) $csv .= $start->addMinutes($index * 5)->format('Y-m-d\TH:i:s\Z').",2000,2002,1998,2001\n";
        File::put($root.'/m5.csv', $csv); $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($root));
        $execution = app(ExecutionContractService::class)->for('XAUUSD', 'M5');
        $input = ['source_model_id' => (int) $child->id,
            'libraries' => ['L1' => array_values($this->recipes), 'L2' => $second],
            'source' => ['dataset_path' => $root.'/m5.csv', 'dataset_sha256' => hash('sha256', $csv)],
            'study' => ['data_hash' => hash('sha256', $csv), 'execution_hash' => $execution['execution_hash'],
                'scope' => ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'context' => ['regime' => 'trend_up', 'volatility' => 'normal'],
                    'evaluated_start' => $start->addMinutes(1000)->toIso8601String(), 'evaluated_end' => $start->addMinutes(2555)->toIso8601String()],
                'evaluated_rows' => 312, 'initial_balance' => 10000.0, 'risk_per_trade' => 1.0,
                'execution_costs' => $execution['parameters'], 'hard_limits' => ['max_total_risk_percent' => 5.0],
                'resource_caps' => ['max_rows' => 512, 'max_decision_events' => 4096, 'max_runtime_seconds' => 120], 'minimum_trades' => 10]];
        $studyOwner = app(AgentMethodStudyPreparationService::class); $prepared = $studyOwner->prepare($input);
        $this->assertSame('own_policy_study_registered', $prepared['status'], json_encode($prepared));
        $carrier = ModelVersion::findOrFail($prepared['arms']['L1A2']['model_id']);
        $carrierPolicy = $learning->policy($prepared['arms']['L1A2']['policy_hash']);
        $this->assertArrayNotHasKey(AgentMethodGenerationService::METADATA, $carrier->metadata);
        $this->assertTrue($studyOwner->verifyProvenance($carrier->metadata[AgentMethodStudyPreparationService::METADATA], $carrierPolicy));
        [$_model, $_policy, $carrierRun] = $this->original(['model' => $carrier, 'policy' => $carrierPolicy]);
        $projected = $generation->proposeFromOriginalRun($carrierRun);
        $this->assertSame('original_policy_proposals', $projected['status'], json_encode($projected));
        $this->assertCount(1, $projected['proposals']); $proposal = $projected['proposals'][0];
        $this->assertSame('frozen_owned_outcome_proposal', $proposal['proposal_status'], json_encode($proposal));
        $this->assertSame(AgentMethodLearningService::OWNED_OUTCOME_PROVENANCE, $proposal['learning_provenance']['protocol']);
        $this->assertSame($carrierPolicy['policy_hash'], $proposal['learning_provenance']['parent_policy_hash']);
        $this->assertSame(15, DB::table('agent_method_owned_outcomes')->where('policy_hash', $carrierPolicy['policy_hash'])->count());
        $count = DB::table('agent_method_policy_revisions')->count();
        $again = $generation->proposeFromOriginalRun($carrierRun);
        $this->assertSame($proposal['policy_hash'], $again['proposals'][0]['policy_hash']);
        $this->assertTrue($again['proposals'][0]['already_proposed']);
        $this->assertSame($count, DB::table('agent_method_policy_revisions')->count());

        $oldChild = $this->model(); $attached = $generation->prepareFreshChild($oldChild, $parent);
        $this->assertSame('prospective_child_policy_attached', $attached['status'], json_encode($attached));
        $input['source_model_id'] = (int) $oldChild->id; $oldStudy = $studyOwner->prepare($input);
        $this->assertSame('own_policy_study_registered', $oldStudy['status'], json_encode($oldStudy));
        $oldCarrier = ModelVersion::findOrFail($oldStudy['arms']['L1A1']['model_id']);
        $oldPolicy = $learning->policy($oldStudy['arms']['L1A1']['policy_hash']);
        [$_model, $_policy, $oldRun] = $this->original(['model' => $oldCarrier, 'policy' => $oldPolicy]);
        $oldProjected = $generation->proposeFromOriginalRun($oldRun);
        $this->assertSame('original_policy_proposals', $oldProjected['status'], json_encode($oldProjected));
        $this->assertSame('frozen_diagnostic_proposal', $oldProjected['proposals'][0]['proposal_status']);
        $this->assertSame('frozen_method_learning_exposure_v1', $oldProjected['proposals'][0]['learning_provenance']['protocol']);
        $this->assertSame(0, DB::table('agent_method_owned_outcomes')->where('policy_hash', $oldPolicy['policy_hash'])->count());
        $this->assertSame($legacyBefore, DB::table('agent_method_policy_revisions')->where('policy_hash', $legacy['policy_hash'])->first()->contract);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_year_end_labels_entering_paper_epoch_are_excluded_but_account_still_reconciles(): void
    {
        [$_parent, $policy, $run] = $this->original(['year_end' => true]);
        $owner = app(AgentMethodLearningService::class); $learned = $owner->learnOwnedOutcomesFromRun($run);
        $this->assertSame('learned_owned_outcomes', $learned['status'], json_encode($learned));
        $this->assertSame(1, $learned['matured_episodes']);
        $this->assertSame(1, $learned['post_paper_labels_excluded']);
        $proposal = $owner->proposeOutcomePolicy($policy['policy_hash'], 2);
        $this->assertSame([], $proposal['rules']);
        $this->assertSame('BASELINE', $proposal['default_action']);
    }

    public function test_append_only_rollback_and_normal_generation_tamper_guard_preserve_history(): void
    {
        [$parent, $policy, $run] = $this->original();
        $learning = app(AgentMethodLearningService::class); $learning->learnFromRun($run); $learning->learnOwnedOutcomesFromRun($run);
        $migration = require database_path('migrations/2026_10_13_150000_create_agent_method_owned_outcomes.php');
        try { $migration->down(); $this->fail('Nonempty owned history was deleted.'); }
        catch (\LogicException $error) { $this->assertSame('OWNED_METHOD_APPEND_ONLY_HISTORY_PREVENTS_ROLLBACK', $error->getMessage()); }
        $this->assertDatabaseCount('agent_method_owned_outcomes', 15);
        DB::table('agent_method_owned_outcomes')->where('policy_hash', $policy['policy_hash'])->update(['facts' => '{"forged":true}']);
        $lab = AiLaboratory::create(['name' => 'owned tamper fixture', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_families' => ['breakout']]);
        $cohort = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'status' => 'draft',
            'trigger_context' => ['instrument_method_generation_learning' => ['enabled' => true, 'recipe_hashes' => array_values($this->recipes)]]]);
        $child = $this->model(); $before = $child->fresh()->toArray();
        $result = app(AgentMethodGenerationService::class)->prepareConstructorChild($child, $parent, $cohort, 1, []);
        $this->assertSame('dependency', $result['status']);
        $this->assertSame('OWNED_METHOD_ORIGINAL_OUTCOME_CHANGED', $result['reason']);
        $this->assertSame($before, $child->fresh()->toArray());
    }

    private function original(array $options = []): array
    {
        $model = $options['model'] ?? $this->model(); $library = app(InstrumentMethodLibraryService::class)->librarySnapshot(array_values($this->recipes));
        $learning = app(AgentMethodLearningService::class);
        $policy = $options['policy'] ?? $learning->registerPolicy(['agent_key' => 'model:'.$model->id, 'revision' => 1,
            'library_hash' => $library['library_hash'], 'library_contract' => $library['library_contract'],
            'rules' => array_map(fn ($session, $recipe) => ['context_predicate' => ['session' => $session], 'preferred_recipes' => [$recipe]],
                array_keys($this->recipes), array_values($this->recipes)), 'default_action' => 'BASELINE']);
        if (! isset($options['policy'])) app(InstrumentMethodResearchBindingService::class)->attachToUnusedModel($model->id, $policy['policy_hash']);
        $execution = app(ExecutionContractService::class)->for('XAUUSD', 'M5');
        $yearEnd = ($options['year_end'] ?? false) === true;
        $count = $yearEnd ? 2 : 15;
        $base = $yearEnd ? '2025-12-31T23:35:00+00:00' : '2025-01-01T07:00:00+00:00';
        $scope = ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'data_hash' => str_repeat('a', 64),
            'execution_hash' => $execution['execution_hash'], 'evaluated_start' => $base,
            'evaluated_end' => $yearEnd ? '2025-12-31T23:55:00+00:00' : '2025-01-01T15:00:00+00:00',
            'context' => ['regime' => 'trend_up', 'volatility' => 'normal']];
        $contract = app(InstrumentMethodResearchBindingService::class)->compilePolicy($policy['policy_hash'],
            ['strategy_family' => 'breakout', 'parameters' => $model->parameters], $scope, 'model:'.$model->id);
        $calls = []; $ledger = []; $balance = 10000.0;
        for ($i = 0; $i < $count; $i++) {
            $entry = CarbonImmutable::parse($base)->addMinutes($i * ($yearEnd ? 15 : 30));
            $session = $i < 10 ? 'london' : 'new_york'; $index = 200 + $i * ($yearEnd ? 3 : 6);
            $calls[] = ['index' => $index, 'at' => $entry->toIso8601String(), 'phase' => 'entry', 'session' => $session];
            for ($j = 1; $j <= ($yearEnd ? 1 : ($i < 10 ? 2 : 1)); $j++) $calls[] = ['index' => $index + $j,
                'at' => $entry->addMinutes($j * 5)->toIso8601String(), 'phase' => 'exit',
                'session' => ($options['multi_path'] ?? false) && $i >= 10 ? 'london' : $session];
            $balance += ($i < 10 || ($options['all_negative'] ?? false)) ? -2.0 : 10.0;
            $ledger[] = ['entry_time' => $entry->toIso8601String(), 'signal_time' => $entry->subMinutes(5)->toIso8601String(),
                'exit_time' => $entry->addMinutes($yearEnd ? 5 : 15)->toIso8601String(), 'direction' => 'BUY', 'signal_confidence' => 0.8,
                'exit_reason' => ($options['censored_last'] ?? false) && $i === $count - 1 ? 'end_of_data' : 'time_stop',
                'execution_cost_percent' => 0.01, 'balance' => $balance];
        }
        $script = 'import json,sys; from app.services.instrument_method_learning import InstrumentMethodLearning; '
            .'x=json.load(sys.stdin); c=x["contract"]; m=InstrumentMethodLearning(c,dataset_hash=c["data_hash"],execution_hash=c["execution_hash"],expected_agent_key=c["expected_agent_key"]); '
            .'events=[m.evaluate({"close":100.0,"market_regime":"trend_up","market_session":q["session"],"market_venue_phase":"london_interfix" if q["session"]=="london" else "london_comex_overlap","volatility_regime":"normal_volatility","time":q["at"]},decision_id="owned-"+str(n),phase=q["phase"],decision_at=q["at"],observed_at=q["at"],direction="BUY") for n,q in enumerate(x["calls"])]; '
            .'print(json.dumps({"events":events,"receipt":m.receipt()}))';
        $process = new Process(['python', '-c', $script], dirname(base_path()).'/ai-service-python');
        $process->setInput(json_encode(['contract' => $contract, 'calls' => $calls], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        $process->setTimeout(30); $process->mustRun(); $actual = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $trace = [];
        foreach ($calls as $i => $call) {
            $trace[] = ['event_type' => 'instrument_method_decision', 'candle_index' => $call['index'], 'method_learning' => $actual['events'][$i]];
            if ($call['phase'] === 'entry') $trace[] = ['event_type' => 'signal_evaluation', 'candle_index' => $call['index'],
                'candle_time' => $call['at'], 'action' => 'BUY', 'accepted' => true];
        }
        $lastClockIndex = $yearEnd ? 204 : 296;
        $clock = ['protocol' => 'replay_executed_clock_v1', 'owner' => 'ordinary_single_position_v1', 'complete' => true,
            'semantics' => 'previous_closed_candle_next_open_v1', 'duration_seconds' => 300,
            'index_basis' => 'evaluated_frame_zero_based_v1', 'execution_timeframe' => 'M5',
            'input_rows' => $lastClockIndex + 1, 'evaluation_offset_rows' => 0, 'decision_rows' => $lastClockIndex - 200 + 1,
            'first_evaluation_index' => 200, 'last_evaluation_index' => $lastClockIndex,
            'index_set_hash' => hash('sha256', "replay-executed-clock-v1:indices\n".implode("\n", range(200, $lastClockIndex))."\n"),
            'schedule_hash' => str_repeat('e', 64), 'promotion_evidence' => false,
            'dataset_hash' => $scope['data_hash'], 'execution_hash' => $execution['execution_hash'],
            'signal_start' => CarbonImmutable::parse($scope['evaluated_start'])->subMinutes(5)->toIso8601String(),
            'signal_end' => CarbonImmutable::parse($scope['evaluated_end'])->subMinutes(5)->toIso8601String(),
            'execution_start' => $scope['evaluated_start'], 'execution_end' => $scope['evaluated_end']];
        $clock['receipt_hash'] = $this->hash($clock);
        $clock['receipt_json'] = $this->canonicalJson(array_diff_key($clock, ['receipt_hash' => true]));
        $request = ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'strategy' => 'breakout_v1', 'base_strategy' => 'breakout',
            'parameters' => $model->parameters, 'initial_balance' => 10000.0, 'risk_per_trade' => 1.0,
            'replay_dataset_hash' => $scope['data_hash'], 'execution_contract' => $execution,
            'policy_context' => ['instrument_method_learning' => $contract]];
        $response = ['decision_trace' => $trace, 'trade_ledger' => $ledger, 'total_trades' => $count,
            'initial_balance' => 10000.0, 'final_balance' => $balance, 'execution_contract' => $execution,
            'data_quality' => ['instrument_method_learning_receipt' => $actual['receipt'], 'replay_executed_clock' => $clock]];
        $run = $this->publish($model, $request, $response);
        return [$model->fresh(), $policy, $run, compact('request', 'response')];
    }

    private function publish(ModelVersion $model, array $request, array $response): LabEvaluationRun
    {
        $owner = app(LabImmutableEvidenceService::class);
        $run = LabEvaluationRun::create(['run_id' => (string) Str::uuid(), 'model_version_id' => $model->id,
            'phase' => 'screening', 'mode' => 'research', 'status' => 'completed', 'started_at' => now(), 'finished_at' => now()->addSecond(),
            'data_hash' => $request['replay_dataset_hash'], 'code_hash' => str_repeat('c', 64),
            'request_hash' => $owner->hash($request), 'response_hash' => $owner->hash($response)]);
        $owner->recordArtifact($run, 'evaluation_request', $request, ['request_hash' => $run->request_hash]);
        $owner->recordArtifact($run, 'evaluation_response', $response);
        $owner->recordArtifact($run, 'decision_trace', $response['decision_trace'], ['complete' => true]);
        $owner->recordArtifact($run, 'trade_ledger', $response['trade_ledger'], ['complete' => true]);
        return $run;
    }
    private function model(): ModelVersion
    {
        return ModelVersion::create(['name' => 'owned-outcome-'.Str::uuid(), 'strategy' => 'breakout_v1', 'status' => 'draft',
            'parameters' => app(StrategyParameterSchemaService::class)->defaults('breakout'),
            'metadata' => ['base_strategy' => 'breakout_v1', 'lab_symbol' => 'XAUUSD', 'lab_timeframe' => 'H1']]);
    }
    private function recipe(string $session): string
    {
        $library = app(InstrumentMethodLibraryService::class);
        $source = $library->registerSource(['kind' => 'imported_text', 'reference' => 'Owned outcome conditional fixture',
            'content' => 'Testing own observed recipe outcomes; not a market certificate.',
            'metadata' => ['license' => 'test fixture owned', 'access' => 'user_supplied', 'exposure' => ['status' => 'synthetic_fixture']]]);
        $methods = [];
        foreach (['confirmation', 'exit'] as $target) {
            $method = $library->registerMethod(['instrument_key' => 'trend_pullback', 'method_key' => 'owned-'.$session.'-'.$target,
                'revision' => 1, 'context_predicate' => ['session' => $session, 'regime' => 'trend_up', 'volatility' => 'normal'], 'horizon' => 'hour', 'known_limitations' => ['No market qualification.'],
                'source_keys' => [$source['source_key']], 'implementation' => ['kind' => 'typed_ast', 'target' => $target,
                    'ast' => ['op' => 'CONST', 'type' => 'bool', 'value' => $target === 'confirmation'], 'input_bindings' => [],
                    'budget' => ['cpu_seconds' => .5, 'max_calls' => 1000, 'max_node_evaluations' => 48000]]]);
            $this->assertSame('registered_research_only', $method['status'], json_encode($method)); $methods[] = $method['method_hash'];
        }
        $recipe = $library->registerRecipe(['recipe_key' => 'owned-'.$session, 'revision' => 1,
            'method_hashes' => $methods, 'context_predicate' => ['session' => $session, 'regime' => 'trend_up', 'volatility' => 'normal']]);
        $this->assertSame('registered_research_only', $recipe['status'], json_encode($recipe));
        return $recipe['recipe_hash'];
    }
    private function hash(array $body): string { return app(ResearchPaperEpochContractService::class)->parameterHash($body); }
    private function canonicalJson(array $body): string
    {
        $sort = function ($item) use (&$sort) { if (! is_array($item)) return $item; if (! array_is_list($item)) ksort($item); return array_map($sort, $item); };
        return json_encode($sort($body), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
