<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\ResearchExperimentWorkItem;
use App\Services\AutonomousModeService;
use App\Services\ExecutionContractService;
use App\Services\LabDatasetExportService;
use App\Services\LabPopulationService;
use App\Services\LearningVelocityGateService;
use App\Services\ProspectiveRepairProbeWindowService;
use App\Services\ResearchExperimentConversionKernelService;
use App\Services\ResearchExperimentWorkConsumerService;
use App\Services\ResearchLoopArbiterService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\SpecialistCouncilFollowupExecutionService;
use App\Services\SpecialistCouncilResearchFeedbackService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SpecialistCouncilFollowupExecutionTest extends TestCase
{
    use RefreshDatabase;

    private function work(): ResearchExperimentWorkItem
    {
        $result = app(ResearchExperimentConversionKernelService::class)->record([
            'contract_version' => ResearchExperimentConversionKernelService::CONTRACT_VERSION,
            'source' => ['type' => 'constructor-seam-fixture', 'id' => 1],
            'scope' => ['symbol' => 'XAUUSD', 'laboratory_timeframe' => 'H1', 'execution_timeframe' => 'M5'],
            'identity' => ['baseline_epoch_hash' => 'base', 'data_and_mtf_hash' => 'data', 'runtime_and_contract_hash' => 'runtime',
                'intervention_hash' => 'change', 'window_plan_hash' => 'window', 'evaluator_version' => 'fixture'],
            'arms' => [['role' => 'candidate'], ['role' => 'solo']],
        ], ['fixture' => true], 'UNDERPOWERED', ['type' => 'specialist_council_power_extension', 'identity' => 'original',
            'executable' => false, 'owner' => ResearchLoopArbiterService::class,
            'retry_condition' => ['code' => 'NEW_PREREGISTERED_SCOPE_REQUIRED', 'max_experiments' => 1]]);
        return ResearchExperimentWorkItem::findOrFail($result['work_id']);
    }

    /** Actual original constructor; only data/technical readiness infrastructure is a fixture. */
    private function fixture(?callable $readinessHook = null): array
    {
        config()->set('services.internal_api.token', 'isolated-native-solo-test-only-hmac-key');
        config()->set('services.xauusd_organism.historical_research_until_champion', true);
        config()->set('services.market_data.provider', 'csv');
        config()->set('services.lab_selection.constructor_initial_seat_budget', 6);
        app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'prospective question');
        $lab = AiLaboratory::create(['name' => 'followup-native-fixture', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
        $lab->generations()->create(['generation' => 1, 'trigger_type' => 'historical_research', 'status' => 'completed',
            'population_size' => 0, 'trigger_context' => ['data_count' => 0], 'completed_at' => now()]);
        $this->mock(LearningVelocityGateService::class, fn ($m) => $m->shouldReceive('inspect')->andReturn(['status' => 'healthy', 'allowed' => true]));
        $this->mock(LabDatasetExportService::class, fn ($m) => $m->shouldReceive('ensureFoundationDataset')->andReturn([
            'sha256' => str_repeat('a', 64), 'path' => 'fixture.csv', 'manifest' => ['row_count' => 123000,
                'first_candle_at' => '2005-01-03T00:00:00Z', 'last_candle_at' => '2025-12-31T23:00:00Z']]));
        $intent = ['protocol' => LabPopulationService::NATIVE_COUNCIL_INTENT_PROTOCOL, 'purpose' => 'research', 'symbol' => 'XAUUSD',
            'storage_timeframe' => 'H1', 'population_size' => 6, 'creator_id' => 'followup-creator', 'research_question' => 'Original technical research question'];
        $population = app(LabPopulationService::class);
        $source = $population->build('XAUUSD', 'historical_research', false, 'H1', [], false, false, 6, null, false, null, $intent);
        $this->assertNotNull($source, json_encode($population->lastBuildOutcome()));
        $models = $source->agents()->with('modelVersion')->orderBy('id')->get()->map(fn ($a) => $a->modelVersion);
        $this->assertCount(6, $models);
        // This fixture tests constructor/intake ownership, not synthetic market confirmation.
        $source->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
        $source->agents()->update(['lifecycle_status' => 'screened']);
        $this->mock(\App\Services\LabQueueJobInspector::class, fn ($m) => $m->shouldReceive('queueSnapshot')->andReturn(['available' => true, 'total' => 0]));
        $epochs = app(ResearchPaperEpochContractService::class);
        $specs = []; $members = [];
        foreach (['scalp', 'hour', 'day', 'swing'] as $i => $role) {
            $model = $models[$i];
            $specs[$role] = ['model_version_id' => $model->id, 'family' => $source->agents()->where('model_version_id', $model->id)->value('strategy_family'),
                'model_hash' => app(\App\Services\SpecialistCouncilContractService::class)->modelHash($model),
                'strategy' => $model->strategy, 'strategy_architecture' => data_get($model->metadata, 'strategy_architecture'),
                'base_strategy' => data_get($model->metadata, 'base_strategy'), 'parameters' => $model->parameters,
                'parameter_hash' => $epochs->parameterHash($model->parameters)];
            $members[] = ['specialist_id' => $role, 'role' => $role, 'version' => '1', 'as_of' => '2025-01-06T02:00:00Z',
                'inputs' => ['as_of_closed_candles'], 'scope' => ['symbols' => ['XAUUSD'], 'contexts' => ['trend']],
                'known_limits' => ['research_unqualified'], 'resources' => ['max_compute_ms' => 100, 'max_memory_mb' => 32, 'max_lookback_bars' => 512],
                'horizon' => ['kind' => $role, 'decision_interval_seconds' => 300, 'reevaluation_interval_seconds' => 300,
                    'max_holding_seconds' => $role === 'swing' ? 259200 : 3600, 'execution_precision' => 'candle'],
                'data_requirements' => match ($role) {'scalp' => ['bid_ask', 'spread', 'slippage', 'quote_age', 'intrabar_ambiguity'],
                    'swing' => ['gap', 'carry', 'rollover', 'mature_holding_outcomes'], default => ['sessions', 'costs']},
                'model_version_id' => $model->id, 'strategy_version' => 'strategy-v1', 'tactic_version' => 'tactic-v1', 'management_version' => 'management-v1',
                'capital_weight' => .25, 'risk_per_trade_percent' => .5, 'sensor_timeframes' => ['H4', 'H1', 'M15', 'M5']];
        }
        $work = $this->work();
        $account = ['id' => 'canonical-execution', 'version' => '1', 'broker_position_mode' => 'hedging', 'opposite_position_policy' => 'hedge',
            'max_open_positions' => 8, 'max_reserved_capital_percent' => 100, 'max_gross_exposure_percent' => 100, 'max_total_risk_percent' => 2,
            'max_drawdown_percent' => 10, 'max_daily_loss_percent' => 3, 'max_expected_cost_percent' => 1];
        $execution = app(ExecutionContractService::class)->for('XAUUSD', 'M5');
        $rows = [];
        for ($i = 0; $i < 8; $i++) $rows[] = ['time' => CarbonImmutable::parse('2025-01-06T02:00:00Z')->addMinutes(5 * $i)->toIso8601String()];
        $probe = app(ProspectiveRepairProbeWindowService::class)->seal($rows, str_repeat('d', 64), $execution['execution_hash'], 'constructor-seam-fixture', 6, 2);
        $proof = ['executable' => true, 'resolution_hash' => str_repeat('f', 64), 'work_item_id' => $work->id, 'source_receipt_id' => $work->research_experiment_receipt_id,
            'native_source_models' => $specs, 'creator_id' => 'followup-creator', 'evaluator_id' => 'followup-evaluator', 'research_question' => 'A new prospectively sealed discovery question',
            'manifest_template' => ['council_id' => 'followup-native-council', 'version' => 'original', 'members' => $members, 'components' => [],
                'routing' => ['id' => 'scope-router', 'version' => '1'], 'allocation' => ['id' => 'shared-capital', 'version' => '1'],
                'risk' => ['id' => 'external-hard-risk', 'version' => '1'], 'execution' => $account,
                'evaluation_policy' => ['objective' => 'net_return_at_equal_risk', 'champion_model_version_id' => $models[0]->id, 'solo_model_version_id' => $models[0]->id]],
            'evaluation_plan' => ['purpose' => 'research', 'execution_hash' => $execution['execution_hash'], 'execution_timeframe' => 'M5',
                'solo_comparison' => ['protocol' => \App\Services\SpecialistCouncilContractService::NATIVE_SOLO_PROTOCOL,
                    'comparison_kind' => 'matched_member_allocation', 'specialist_id' => 'scalp', 'best_solo_full_budget_proven' => false],
                'initial_capital' => 10000, 'cost_model' => $execution['parameters'],
                'risk_policy' => [...array_diff_key($account, ['id' => true, 'version' => true]), 'risk_per_trade_percent' => .5],
                'windows' => [['window_key' => 'new-question-window', 'start_inclusive' => $probe['loaded_start'], 'end_exclusive' => '2025-01-06T02:40:00Z',
                    'dataset_sha256' => str_repeat('d', 64), 'prospective_probe_window' => $probe,
                    'evaluation_scope' => ['start_inclusive' => $probe['evaluated_start'], 'end_exclusive' => '2025-01-06T02:40:00Z', 'rows' => 6,
                        'decision_rows' => 5, 'warmup_rows' => 2, 'policy_hash' => $epochs->parameterHash($probe)]]],
                'arms' => [['arm_key' => 'candidate', 'kind' => 'candidate', 'window_key' => 'new-question-window', 'model_version_id' => $models[4]->id],
                    ['arm_key' => 'solo', 'kind' => 'solo', 'window_key' => 'new-question-window', 'model_version_id' => $models[0]->id],
                    ['arm_key' => 'without-hour', 'kind' => 'ablation', 'removed_id' => 'hour', 'window_key' => 'new-question-window', 'model_version_id' => $models[5]->id]]],
            'discovery_bundle_manifest' => null];
        $originalOwner = app(\App\Services\SpecialistCouncilLifecycleService::class);
        $originalVersion = $originalOwner->registerDraft($proof['manifest_template'], $proof['creator_id']);
        $originalPlan = $originalOwner->sealEvaluationPlan($originalVersion, $proof['evaluator_id'], $proof['evaluation_plan']);
        $proof['evaluation_plan'] = $originalPlan;
        $proof['source_version_id'] = (int) $originalVersion->id;
        $proof['source_manifest_hash'] = $originalVersion->manifest_hash;
        $proof['source_plan_hash'] = $originalPlan['plan_hash'];
        $seal = new \ReflectionMethod(SpecialistCouncilResearchFeedbackService::class, 'followupServerSeal');
        $proof['server_seal'] = $seal->invoke(app(SpecialistCouncilResearchFeedbackService::class), $proof);
        $feedback = \Mockery::mock(app(SpecialistCouncilResearchFeedbackService::class))->makePartial();
        $feedback->shouldReceive('inspectFollowupReadiness')->andReturnUsing(function () use ($proof, $readinessHook): array {
            if ($readinessHook !== null) $readinessHook($proof);
            return $proof;
        });
        // The fixture replaces the original readiness producer, not the
        // constructor. Its small live-source fence stays explicitly bound.
        $feedback->shouldReceive('inspectFollowupSourceBinding')->andReturn(['source_hash' => str_repeat('f', 64),
            'python_source_hash' => str_repeat('a', 64), 'amendment_hash' => null, 'resolution_body_hash' => str_repeat('d', 64)]);
        $feedback->shouldReceive('assertUnobservedConstructorBinding')->andReturnNull();
        // This fixture already replaces the original signed readiness owner;
        // its unsigned placeholder is not a genuine pristine-target proof.
        // Real cold-start registration/snapshot/build coverage lives in
        // SpecialistCouncilFollowupReadinessTest and does not use this stub.
        $feedback->shouldReceive('pristineUnbuiltFollowupSnapshot')->andReturn([
            'protocol' => 'conditional_original_readiness_fixture_not_owner_authority']);
        $this->app->instance(SpecialistCouncilResearchFeedbackService::class, $feedback);
        $work->update(['payload' => [...$work->payload, 'followup_resolution' => ['resolution_hash' => $proof['resolution_hash']]]]);
        $lease = app(ResearchExperimentConversionKernelService::class)->claimForOwner(ResearchLoopArbiterService::class)[0];
        return [$source, $lease, $proof];
    }

    /** Replace only the archive I/O boundary after the actual slot proof. */
    private function duringArchiveSync(callable $hook): LabPopulationService
    {
        $archive = \Mockery::mock(\App\Services\EvolutionArchiveService::class, [
            app(\App\Services\StrategySemanticGroupService::class),
            app(\App\Services\EvolutionGovernorService::class),
        ])->makePartial();
        $archive->shouldReceive('sync')->andReturnUsing(function () use ($hook): array {
            $hook();
            return [];
        });
        $this->app->instance(\App\Services\EvolutionArchiveService::class, $archive);
        $this->app->forgetInstance(LabPopulationService::class);

        return app(LabPopulationService::class);
    }

    public function test_actual_six_constructor_preserves_verified_vectors_with_zero_parent_authority_and_same_generation_resume(): void
    {
        [$source, $work, $proof] = $this->fixture();
        config()->set('services.lab_selection.constructor_initial_seat_budget', 3);
        $owner = app(LabPopulationService::class);
        $next = $owner->build('XAUUSD', 'historical_research', false, 'H1', [], false, false, 6, null, false, null,
            ['protocol' => LabPopulationService::NATIVE_COUNCIL_INTENT_PROTOCOL, 'purpose' => 'research', 'symbol' => 'XAUUSD', 'storage_timeframe' => 'H1',
                'population_size' => 6, 'creator_id' => $proof['creator_id'], 'research_question' => $proof['research_question'],
                'followup_work_item_id' => $work->id, 'followup_resolution_hash' => $proof['resolution_hash']]);
        $this->assertNotNull($next, json_encode($owner->lastBuildOutcome()));
        $this->assertSame(3, $next->agents()->count(), json_encode($next->trigger_context));
        $firstIds = $next->agents()->pluck('id')->all();
        // A partially quarantined constructor retains its original work fence;
        // it cannot fall through to generic lifecycle continuation unleased.
        $next->update(['status' => 'technical_quarantine']);
        $this->mock(\App\Services\AcademyExperimentMaterializerService::class, fn ($m) => $m->shouldReceive('proposal')->andReturn([]));
        $tick = new \ReflectionMethod(ResearchLoopArbiterService::class, 'tickLocked');
        $waiting = $tick->invoke(app(ResearchLoopArbiterService::class), 'XAUUSD', 'H1', false);
        $this->assertSame('WAIT_COUNCIL_DURABLE_NEXT_WORK', $waiting['action'], json_encode($waiting));
        $next->update(['status' => 'draft']);
        $result = $owner->continueInterruptedConstruction($next->id, 6);
        $this->assertSame([], $result['failures'] ?? null, json_encode($result));
        $this->assertSame(6, $next->agents()->count());
        $this->assertSame($firstIds, $next->agents()->orderBy('id')->limit(3)->pluck('id')->all());
        foreach ($next->agents()->with('modelVersion')->orderBy('id')->get() as $i => $agent) {
            $role = ['scalp', 'hour', 'day', 'swing', 'day', 'day'][$i];
            $this->assertSame($proof['native_source_models'][$role]['parameter_hash'], app(ResearchPaperEpochContractService::class)->parameterHash($agent->modelVersion->parameters));
            $this->assertSame($proof['native_source_models'][$role]['strategy_architecture'], data_get($agent->modelVersion->metadata, 'strategy_architecture'));
            $this->assertNull($agent->parent_a_model_version_id);
            $this->assertNull($agent->parent_b_model_version_id);
            $this->assertSame([], $agent->parameter_diff);
        }
        $before = \App\Models\LabGeneration::count();
        // Canonical preparation is real; only queue/provider infrastructure is simulated.
        Artisan::shouldReceive('call')->once()->with('trading:dispatch-lab', \Mockery::type('array'))->andReturn(0);
        $executor = app(SpecialistCouncilFollowupExecutionService::class);
        $refused = $executor->execute($work);
        $this->assertSame('COUNCIL_FOLLOWUP_CANONICAL_DISPATCH_NOT_ADMITTED', $refused['reason'], json_encode($refused));
        $originalHold = data_get($work->fresh()->result, 'dependency_hold');
        $this->assertSame($refused['reason'], $originalHold['reason'] ?? null);
        $this->assertSame('blocked', $work->fresh()->status);
        $this->assertSame([], app(ResearchExperimentConversionKernelService::class)->claimForOwner(ResearchLoopArbiterService::class));
        $this->assertSame($before, \App\Models\LabGeneration::count());
        // A genuine queue dependency event may authorize a bounded retry;
        // unchanged prerequisites did not consume another lease above.
        $this->mock(\App\Services\LabQueueJobInspector::class, fn ($m) => $m->shouldReceive('queueSnapshot')->andReturn(['available' => true, 'total' => 1]));
        $work = app(ResearchExperimentConversionKernelService::class)->claimForOwner(ResearchLoopArbiterService::class)[0];
        Artisan::shouldReceive('call')->once()->with('trading:dispatch-lab', \Mockery::type('array'))->andReturnUsing(function () use ($next): int {
            $next->refresh(); $context = $next->trigger_context; $context['queue_batches']['screening'] = ['fixture-original-batch'];
            $next->forceFill(['status' => 'screening', 'trigger_context' => $context])->save(); return 0;
        });
        $execution = app(SpecialistCouncilFollowupExecutionService::class)->execute($work);
        $this->assertSame('completed', $execution['status'], json_encode($execution));
        $this->assertSame($next->id, $execution['generation_id']);
        $this->assertSame($before, \App\Models\LabGeneration::count());
        $this->assertSame('settled', $work->fresh()->status);
        $this->assertSame($originalHold, data_get($work->fresh()->result, 'dependency_hold'));
        $this->assertSame($originalHold, $execution['dependency_hold'] ?? null);
        $this->assertFalse($execution['promotion_evidence']);
        $this->assertSame((int) $work->id, data_get($next->fresh()->trigger_context, 'specialist_council_preparation.learning_consumption_receipt.original_followup.work_item_id'));
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('specialist_council_versions', 2);
    }

    public function test_future_preparation_remaps_only_derived_solo_identity_without_changing_original_plan_or_hmac(): void
    {
        [$source, $work, $proof] = $this->fixture();
        $row = \Illuminate\Support\Facades\DB::table('specialist_council_evaluation_plans')
            ->where('specialist_council_version_id', $proof['source_version_id'])->sole();
        $original = $proof;
        // Model the fresh unused six references without dispatching or executing.
        $copy = $source->replicate();
        $copy->forceFill(['generation' => 3, 'status' => 'draft', 'trigger_context' => [], 'completed_at' => null])->save();
        foreach ($source->agents()->with('modelVersion')->orderBy('id')->get() as $agent) {
            $model = $agent->modelVersion->replicate();
            $model->forceFill(['name' => $model->name.'-fresh-solo'])->save();
            $member = $agent->replicate();
            $member->forceFill(['lab_generation_id' => $copy->id, 'model_version_id' => $model->id, 'lifecycle_status' => 'draft'])->save();
        }
        $owner = app(SpecialistCouncilFollowupExecutionService::class);
        $request = $owner->preparationRequest($copy, $proof);
        $this->assertSame(['protocol', 'comparison_kind', 'specialist_id', 'best_solo_full_budget_proven'],
            array_keys($request['evaluation_plan']['solo_comparison']));
        $this->assertSame('matched_member_allocation', $request['evaluation_plan']['solo_comparison']['comparison_kind']);
        $this->assertFalse($request['evaluation_plan']['solo_comparison']['best_solo_full_budget_proven']);
        $freshSolo = collect($request['evaluation_plan']['arms'])->firstWhere('kind', 'solo')['model_version_id'];
        $this->assertNotSame($proof['evaluation_plan']['solo_comparison']['model_version_id'], $freshSolo);
        $this->assertSame($original, $proof);
        $freshRow = \Illuminate\Support\Facades\DB::table('specialist_council_evaluation_plans')->where('id', $row->id)->sole();
        $this->assertSame($row->plan, $freshRow->plan);
        $this->assertSame($row->plan_hash, $freshRow->plan_hash);
        $this->assertSame($proof['server_seal'], hash_hmac('sha256', SpecialistCouncilResearchFeedbackService::FOLLOWUP_PROTOCOL."\n"
            .app(ResearchPaperEpochContractService::class)->parameterHash(array_diff_key($proof, ['server_seal' => true])),
            config('services.internal_api.token')));
        foreach (['missing', 'unknown', 'passport'] as $mutation) {
            $wrong = $proof;
            if ($mutation === 'missing') unset($wrong['evaluation_plan']['solo_comparison']);
            if ($mutation === 'unknown') $wrong['evaluation_plan']['solo_comparison']['protocol'] = 'unknown';
            if ($mutation === 'passport') $wrong['evaluation_plan']['solo_comparison']['passport_hash'] = str_repeat('a', 64);
            try { $owner->preparationRequest($copy, $wrong); $this->fail('An undeclared or changed original SOLO was remapped.'); }
            catch (\LogicException|\InvalidArgumentException $error) { $this->assertStringContainsString('SOLO', $error->getMessage()); }
        }
        $this->assertSame('leased', $work->fresh()->status);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    public function test_native_constructor_expensive_proof_is_reused_only_inside_one_locked_invocation(): void
    {
        $calls = 0;
        [$source, $work, $proof] = $this->fixture(function () use (&$calls): void {
            $calls++;
        });
        $calls = 0;
        $owner = app(LabPopulationService::class);
        config()->set('services.lab_selection.constructor_initial_seat_budget', 3);
        $intent = ['protocol' => LabPopulationService::NATIVE_COUNCIL_INTENT_PROTOCOL, 'purpose' => 'research', 'symbol' => 'XAUUSD',
            'storage_timeframe' => 'H1', 'population_size' => 6, 'creator_id' => $proof['creator_id'],
            'research_question' => $proof['research_question'], 'followup_work_item_id' => $work->id,
            'followup_resolution_hash' => $proof['resolution_hash']];
        $next = $owner->build('XAUUSD', 'historical_research', false, 'H1', [], false, false, 6, null, false, null, $intent);
        $this->assertNotNull($next, json_encode($owner->lastBuildOutcome()));
        $this->assertSame(3, $next->agents()->count());
        $this->assertSame(2, $calls, 'One pre-lock intent proof and one fresh proof under the constructor lock.');
        $owner->continueInterruptedConstruction($next->id, 6);
        $this->assertSame(6, $next->agents()->count());
        $this->assertSame(3, $calls, 'A later invocation must obtain a new original proof.');
    }

    public function test_native_constructor_expired_lease_after_expensive_slot_rolls_back_model_and_agent(): void
    {
        [$source, $work, $proof] = $this->fixture();
        $this->freezeTime();
        $work->update(['lease_expires_at' => now()->addSeconds(30)]);
        $owner = $this->duringArchiveSync(fn () => $this->travel(31)->seconds());
        config()->set('services.lab_selection.constructor_initial_seat_budget', 1);
        $beforeModels = \App\Models\ModelVersion::count();
        $next = $owner->build('XAUUSD', 'historical_research', false, 'H1', [], false, false, 6, null, false, null,
            ['protocol' => LabPopulationService::NATIVE_COUNCIL_INTENT_PROTOCOL, 'purpose' => 'research', 'symbol' => 'XAUUSD',
                'storage_timeframe' => 'H1', 'population_size' => 6, 'creator_id' => $proof['creator_id'],
                'research_question' => $proof['research_question'], 'followup_work_item_id' => $work->id,
                'followup_resolution_hash' => $proof['resolution_hash']]);
        $this->assertNotNull($next, json_encode($owner->lastBuildOutcome()));
        $this->assertSame(0, $next->agents()->count());
        $this->assertSame($beforeModels, \App\Models\ModelVersion::count());
        $this->assertSame('technical_quarantine', $next->status);
        $this->assertStringContainsString('NATIVE_COUNCIL_FOLLOWUP_CURRENT_OWNER_REQUIRED',
            (string) data_get($next->trigger_context, 'constructor_audit.skipped_zero_diff_slots.0.reason'));
    }

    public function test_native_constructor_changed_original_source_vector_during_slot_cannot_persist_cached_proof(): void
    {
        [, $work, $proof] = $this->fixture();
        $source = \App\Models\ModelVersion::findOrFail($proof['native_source_models']['scalp']['model_version_id']);
        $originalHash = app(\App\Services\SpecialistCouncilContractService::class)->modelHash($source);
        $visited = false;
        $owner = $this->duringArchiveSync(function () use ($source, &$visited): void {
            $visited = true;
            $source->update(['parameters' => [...$source->parameters, 'ema_fast' => 3]]);
        });
        config()->set('services.lab_selection.constructor_initial_seat_budget', 1);
        $beforeModels = \App\Models\ModelVersion::count();
        $next = $owner->build('XAUUSD', 'historical_research', false, 'H1', [], false, false, 6, null, false, null,
            ['protocol' => LabPopulationService::NATIVE_COUNCIL_INTENT_PROTOCOL, 'purpose' => 'research', 'symbol' => 'XAUUSD',
                'storage_timeframe' => 'H1', 'population_size' => 6, 'creator_id' => $proof['creator_id'],
                'research_question' => $proof['research_question'], 'followup_work_item_id' => $work->id,
                'followup_resolution_hash' => $proof['resolution_hash']]);
        $this->assertTrue($visited, 'The original model changed after this actual slot obtained its proof.');
        $this->assertNotNull($next, json_encode($owner->lastBuildOutcome()));
        $this->assertSame(0, $next->agents()->count());
        $this->assertSame($beforeModels, \App\Models\ModelVersion::count());
        $this->assertSame($originalHash, app(\App\Services\SpecialistCouncilContractService::class)->modelHash($source->fresh()));
        $this->assertStringContainsString('NATIVE_COUNCIL_FOLLOWUP_SOURCE_VECTOR_CHANGED_DURING_CONSTRUCTION',
            (string) data_get($next->trigger_context, 'constructor_audit.skipped_zero_diff_slots.0.reason'));
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    public function test_native_constructor_changed_live_lease_fence_after_persistence_rolls_back_model_and_agent(): void
    {
        [, $work, $proof] = $this->fixture();
        $persisted = null;
        \App\Models\LabAgent::created(function (\App\Models\LabAgent $agent) use ($work, &$persisted): void {
            if (data_get($agent->modelVersion->metadata, 'native_specialist_council_seed.followup_work_item_id') !== $work->id) return;
            $persisted = ['agent_id' => $agent->id, 'model_version_id' => $agent->model_version_id];
            $work->update(['lease_token' => 'replacement-lease', 'fence_version' => $work->fence_version + 1]);
        });
        $owner = app(LabPopulationService::class);
        config()->set('services.lab_selection.constructor_initial_seat_budget', 1);
        $beforeModels = \App\Models\ModelVersion::count();
        $next = $owner->build('XAUUSD', 'historical_research', false, 'H1', [], false, false, 6, null, false, null,
            ['protocol' => LabPopulationService::NATIVE_COUNCIL_INTENT_PROTOCOL, 'purpose' => 'research', 'symbol' => 'XAUUSD',
                'storage_timeframe' => 'H1', 'population_size' => 6, 'creator_id' => $proof['creator_id'],
                'research_question' => $proof['research_question'], 'followup_work_item_id' => $work->id,
                'followup_resolution_hash' => $proof['resolution_hash']]);
        $this->assertNotNull($persisted, 'The work fence changed after the actual model and agent were persisted.');
        $this->assertNotNull($next, json_encode($owner->lastBuildOutcome()));
        $this->assertSame(0, $next->agents()->count());
        $this->assertSame($beforeModels, \App\Models\ModelVersion::count());
        $this->assertDatabaseMissing('lab_agents', ['id' => $persisted['agent_id']]);
        $this->assertDatabaseMissing('model_versions', ['id' => $persisted['model_version_id']]);
        $this->assertStringContainsString('NATIVE_COUNCIL_FOLLOWUP_CURRENT_OWNER_REQUIRED',
            (string) data_get($next->trigger_context, 'constructor_audit.skipped_zero_diff_slots.0.reason'));
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    public function test_caller_executable_flag_and_stale_fence_cannot_dispatch_unproved_council_work(): void
    {
        $work = $this->work();
        $work->update(['status' => 'ready', 'payload' => [...$work->payload, 'executable' => true]]);
        $this->mock(SpecialistCouncilResearchFeedbackService::class, fn ($m) => $m->shouldReceive('inspectFollowupReadiness')
            ->andReturn(['executable' => false, 'reason' => 'ORIGINAL_COUNCIL_PREREQUISITE_PROOF_REQUIRED']));
        $owner = app(ResearchExperimentConversionKernelService::class);
        $this->assertSame([], $owner->claimForOwner(ResearchLoopArbiterService::class));
        $this->assertSame('blocked', $work->fresh()->status);
        Artisan::shouldReceive('call')->never();
        $result = app(ResearchExperimentWorkConsumerService::class)->execute($work->id, 'stale', 99);
        $this->assertSame('WORK_LEASE_NOT_CURRENT', $result['reason']);
        $this->assertDatabaseCount('lab_generations', 0);
    }

    public function test_actual_cohort_projection_loss_cannot_create_a_second_cohort_from_the_same_work(): void
    {
        [, $work, $proof] = $this->fixture();
        config()->set('services.lab_selection.constructor_initial_seat_budget', 1);
        $owner = app(LabPopulationService::class);
        $original = $owner->build('XAUUSD', 'historical_research', false, 'H1', [], false, false, 6, null, false, null,
            ['protocol' => LabPopulationService::NATIVE_COUNCIL_INTENT_PROTOCOL, 'purpose' => 'research', 'symbol' => 'XAUUSD', 'storage_timeframe' => 'H1',
                'population_size' => 6, 'creator_id' => $proof['creator_id'], 'research_question' => $proof['research_question'],
                'followup_work_item_id' => $work->id, 'followup_resolution_hash' => $proof['resolution_hash']]);
        $this->assertNotNull($original, json_encode($owner->lastBuildOutcome()));
        $this->assertSame((int) $work->id, (int) data_get($original->agents()->with('modelVersion')->first()->modelVersion->metadata,
            'native_specialist_council_seed.followup_work_item_id'));
        $work->update(['result' => ['protocol' => SpecialistCouncilFollowupExecutionService::PROTOCOL,
            'generation_id' => $original->id, 'resolution_hash' => $proof['resolution_hash']]]);
        $context = $original->trigger_context;
        unset($context['native_specialist_council_intent']);
        $original->forceFill(['status' => 'completed', 'trigger_context' => $context, 'completed_at' => now()])->save();
        $before = \App\Models\LabGeneration::count();
        Artisan::shouldReceive('call')->never();
        $result = app(SpecialistCouncilFollowupExecutionService::class)->execute($work);
        $this->assertSame('COUNCIL_FOLLOWUP_ORIGINAL_COHORT_PROOF_DRIFT', $result['reason'], json_encode($result));
        $this->assertSame($before, \App\Models\LabGeneration::count());
        $this->assertSame($original->id, data_get($work->fresh()->result, 'generation_id'));
        $this->assertSame('blocked', $work->fresh()->status);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    public function test_specific_generation_resume_claim_cannot_take_another_higher_priority_work(): void
    {
        [$source, $work] = $this->fixture();
        $work->update(['lease_expires_at' => now()->subSecond()]);
        $context = $source->trigger_context; $context['native_specialist_council_intent']['followup_work_item_id'] = $work->id;
        $source->forceFill(['trigger_context' => $context])->save();
        $other = ResearchExperimentWorkItem::create(['work_key' => 'unrelated-ready', 'research_experiment_receipt_id' => $work->research_experiment_receipt_id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'work_type' => 'foreign', 'status' => 'ready', 'priority' => 99,
            'payload' => ['owner' => ResearchLoopArbiterService::class, 'executable' => true]]);
        $lease = app(ResearchExperimentConversionKernelService::class)->claimCouncilContinuationForGeneration($source);
        $this->assertSame($work->id, $lease?->id);
        $this->assertGreaterThan($work->fence_version, $lease->fence_version);
        $this->assertSame('ready', $other->fresh()->status);
    }

    public function test_exhausted_operational_work_is_blocked_without_creating_or_removing_its_original_generation(): void
    {
        $work = $this->work();
        $lab = AiLaboratory::create(['name' => 'original-owned-draft', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'is_active' => false, 'strategy_families' => ['hybrid']]);
        $generation = $lab->generations()->create(['generation' => 1, 'status' => 'draft', 'trigger_type' => 'historical_research', 'population_size' => 6,
            'trigger_context' => ['native_specialist_council_intent' => ['followup_work_item_id' => $work->id]]]);
        $work->update(['status' => 'blocked', 'attempts' => 8]);
        // The immutable owner guard is exercised against real proof in
        // SpecialistCouncilFollowupReadinessTest; here verify kernel routing.
        $this->mock(SpecialistCouncilResearchFeedbackService::class, fn ($m) => $m->shouldReceive('inspectFollowupReadiness')->andReturn([
            'executable' => false, 'reason' => 'COUNCIL_FOLLOWUP_OPERATIONAL_LEASE_BUDGET_EXHAUSTED']));
        $this->assertNull(app(ResearchExperimentConversionKernelService::class)->claimCouncilContinuationForGeneration($generation));
        $this->assertSame('blocked', $work->fresh()->status);
        $this->assertSame(8, $work->fresh()->attempts);
        $this->assertDatabaseCount('lab_generations', 1);
        $this->assertSame('draft', $generation->fresh()->status);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }
}
