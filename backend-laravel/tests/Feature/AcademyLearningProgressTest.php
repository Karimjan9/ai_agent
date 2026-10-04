<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\AcademyExperimentContractCompilerService;
use App\Services\LabImmutableEvidenceService;
use App\Services\ResearchReleaseSealService;
use App\Services\StrategyParameterSchemaService;
use App\Services\XauusdEdgeFormationAcademyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Synthetic producer/owner integration only: no market or authority claims. */
class AcademyLearningProgressTest extends TestCase
{
    use RefreshDatabase;

    private ?AiLaboratory $laboratory = null;
    private int $sequence = 0;
    private ?string $runtimeHash = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('academy_learning_progress');
        config()->set('services.lab_evidence.disk', 'academy_learning_progress');
    }

    public function test_actual_completed_comparable_trials_measure_reduction_not_surprise(): void
    {
        $series = $this->series([.1, .3, .7, .8, .8]);
        $academy = app(XauusdEdgeFormationAcademyService::class);
        $progress = $academy->learningProgress($series[3]['passport_id']);
        $this->assertSame('learning_progress', $progress['status'], json_encode($progress));
        $this->assertSame(4, $progress['verified_comparable_trials']);
        $this->assertSame(5, $progress['registered_comparable_trials']);
        $this->assertGreaterThan(.4, $progress['error_reduction']);
        $this->assertGreaterThanOrEqual(0, $progress['uncertainty_reduction']);
        $this->assertSame('continue_bounded_probe', $progress['recommendation']['action']);
        $this->assertFalse($progress['metric_is_correctness_or_economic_loss']);
        $this->assertFalse($progress['independent_validation_proven']);
        $this->assertFalse($progress['economic_authority']);
        $this->assertFalse($progress['credit_authority']);
        $this->assertSame($progress, $academy->learningProgress($series[3]['passport_id']));
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_noisy_plateau_holds_existing_next_experiment_path_without_new_work(): void
    {
        $series = $this->series([.7, .1, .8, .2]);
        $academy = app(XauusdEdgeFormationAcademyService::class);
        $before = DB::table('edge_academy_trials')->count();
        $progress = $academy->learningProgress($series[3]['passport_id']);
        $this->assertSame('noisy_plateau', $progress['status'], json_encode($progress));
        $this->assertSame('hold_budget', $progress['recommendation']['action']);
        $next = $academy->nextExperiment($series[3]['passport_id'], ['status' => 'entry_mastery_required', 'surprise' => 999]);
        $this->assertSame('ACADEMY_LEARNING_PROGRESS_HOLDS_NEW_WORK', $next['reason']);
        $this->assertSame($before, DB::table('edge_academy_trials')->count());
    }

    public function test_easy_local_task_creates_only_an_adjacent_legal_axis_challenge(): void
    {
        $series = $this->series([.2, .4, 1., 1.1]);
        $academy = app(XauusdEdgeFormationAcademyService::class);
        $progress = $academy->learningProgress($series[3]['passport_id']);
        $this->assertSame('mastered_local', $progress['status'], json_encode($progress));
        $this->assertSame('adjacent_legal_challenge', $progress['recommendation']['action']);
        $original = DB::table('edge_academy_trials')->find($series[3]['trial_id']);
        $next = $academy->nextExperiment($series[3]['passport_id'], ['status' => 'entry_mastery_required']);
        $this->assertSame('planned', $next['status']);
        $this->assertSame('adjacent_location_tolerance_atr_challenge', $next['trial_type']);
        $this->assertSame('location_tolerance_atr', $next['axis']);
        $this->assertEqualsWithDelta(.9, $next['arms'][1]['value'], 1e-9);
        $this->assertSame($original->frozen_contract, DB::table('edge_academy_trials')->find($next['trial_id'])->frozen_contract);
        $this->assertSame((array) $original, (array) DB::table('edge_academy_trials')->find($series[3]['trial_id']));
        $this->assertFalse($next['promotion_evidence']);
    }

    public function test_original_task_seals_and_runtime_identity_reject_poisoned_or_retrofitted_evidence(): void
    {
        $series = $this->series([.1, .3, .7, .8]);
        $academy = app(XauusdEdgeFormationAcademyService::class);
        $item = $series[3];
        $candidate = $item['models'][1];
        $candidate->update(['parameters' => [...$candidate->parameters, 'max_chase_atr' => 1.8]]);
        $poisoned = $academy->learningProgress($item['passport_id']);
        $this->assertSame(3, $poisoned['verified_comparable_trials']);
        $this->assertSame('underpowered', $poisoned['status']);
        $owner = DB::table('edge_academy_passports')->find($series[2]['passport_id']);
        $curriculum = json_decode($owner->curriculum, true);
        $trial = DB::table('edge_academy_trials')->find($series[2]['trial_id']);
        $curriculum['learning_progress_tasks'][$trial->trial_key]['minimum_completed_comparable_trials'] = 1;
        DB::table('edge_academy_passports')->where('id', $owner->id)->update(['curriculum' => json_encode($curriculum)]);
        $this->assertSame(2, $academy->learningProgress($item['passport_id'])['verified_comparable_trials']);
        $other = DB::table('edge_academy_passports')->find($series[1]['passport_id']);
        $curriculum = json_decode($other->curriculum, true); unset($curriculum['learning_progress_tasks']);
        DB::table('edge_academy_passports')->where('id', $other->id)->update(['curriculum' => json_encode($curriculum)]);
        // Old complete rows are observable, never retrospectively preregistered.
        $this->assertSame(1, $academy->learningProgress($item['passport_id'])['verified_comparable_trials']);
    }

    public function test_receipt_bound_dependency_gets_value_only_after_actual_downstream_completion(): void
    {
        $source = $this->series([.7])[0];
        $academy = app(XauusdEdgeFormationAcademyService::class);
        $successor = (int) data_get($source, 'settlement.stage_progress.successor_passport_id');
        $nextId = (int) data_get($source, 'settlement.stage_progress.next_trial.trial_id');
        $this->assertGreaterThan(0, $nextId, json_encode($source['settlement']));
        $graph = $academy->skillDependencyGraph($successor);
        $this->assertSame('observed_dependency_work', $graph['status'], json_encode($graph));
        $this->assertSame(0, $graph['observed_research_enabling_value']);
        $this->assertSame('unlocked_preregistered_work', $graph['edges'][0]['status']);
        $recipient = DB::table('edge_academy_passports')->find($successor);
        $originalFrozen = $recipient->frozen_upstream_contract;
        $confounded = json_decode($originalFrozen, true); $confounded['context'] = ['session' => 'new_unobserved_session'];
        DB::table('edge_academy_passports')->where('id', $successor)->update(['frozen_upstream_contract' => json_encode($confounded)]);
        $this->assertSame('ACADEMY_PREREQUISITE_SCOPE_CONFOUNDED', $academy->skillDependencyGraph($successor)['reason']);
        DB::table('edge_academy_passports')->where('id', $successor)->update(['frozen_upstream_contract' => $originalFrozen]);
        $fixture = $this->fixture(.8, .8, .8);
        $this->completeTrial($nextId, $fixture);
        $answered = $academy->skillDependencyGraph($successor);
        $this->assertSame(1, $answered['observed_research_enabling_value'], json_encode($answered));
        $this->assertSame('completed_answered_downstream_work', $answered['edges'][0]['status']);
        $this->assertFalse($answered['edges'][0]['positive_stage_effect']);
        $this->assertFalse($answered['dependency_necessity_proven']);
        $this->assertFalse($answered['causal_enabling_value_proven']);
        $this->assertFalse($answered['economic_authority']);
        $source['models'][1]->update(['metadata' => [...$source['models'][1]->metadata, 'execution_contract' => ['tampered' => true]]]);
        $invalid = $academy->skillDependencyGraph($successor);
        $this->assertSame('blocked_prerequisite', $invalid['status']);
        $this->assertSame(0, $invalid['observed_research_enabling_value']);
    }

    public function test_claimed_depth_missing_prerequisites_and_cycles_never_unlock_learning_work(): void
    {
        $academy = app(XauusdEdgeFormationAcademyService::class);
        $parameters = app(StrategyParameterSchemaService::class)->defaults('confirmation_entry_mtf');
        $passport = $academy->passport('XAUUSD', 'H1', ['composition_key' => 'missing-prerequisite',
            'strategy_family' => 'confirmation_entry_mtf', 'deepest_stage' => 'management_specialist',
            'baseline_parameters' => $parameters,
            'prospective_source_identity' => ['source_evaluator_hash' => str_repeat('a', 64)]]);
        $progress = $academy->learningProgress($passport['passport_id']);
        $this->assertSame('blocked_prerequisite', $progress['status']);
        $this->assertSame('repair_prerequisite', $progress['recommendation']['action']);
        $next = $academy->nextExperiment($passport['passport_id'], ['status' => 'management_or_cost_mastery_required', 'prerequisite_verified' => true]);
        $this->assertSame('ACADEMY_LEARNING_PROGRESS_HOLDS_NEW_WORK', $next['reason']);
        $this->assertDatabaseCount('edge_academy_trials', 0);

        $source = $this->series([.4])[0];
        $sparseSuccessor = (int) data_get($source, 'settlement.stage_progress.successor_passport_id');
        $this->assertGreaterThan(0, $sparseSuccessor);
        $this->assertSame('blocked_prerequisite', $academy->skillDependencyGraph($sparseSuccessor)['status']);
        $this->assertSame(0, $academy->skillDependencyGraph($sparseSuccessor)['observed_research_enabling_value']);
        $row = DB::table('edge_academy_passports')->find($source['passport_id']);
        $frozen = json_decode($row->frozen_upstream_contract, true);
        $frozen['prospective_source_identity']['source_academy_trial_id'] = $source['trial_id'];
        DB::table('edge_academy_passports')->where('id', $row->id)->update(['frozen_upstream_contract' => json_encode($frozen)]);
        $this->assertSame('ACADEMY_PREREQUISITE_CYCLE', $academy->skillDependencyGraph($row->id)['reason']);
    }

    public function test_registered_trial_cap_counts_failed_and_unobserved_work_not_only_winners(): void
    {
        $series = $this->series([.1, .2, .3, .4, .5, .6], false);
        $academy = app(XauusdEdgeFormationAcademyService::class);
        $progress = $academy->learningProgress($series[5]['passport_id']);
        $this->assertSame(6, $progress['registered_comparable_trials']);
        $this->assertSame(0, $progress['verified_comparable_trials']);
        $this->assertSame('hold_budget', $progress['recommendation']['action']);
        $before = DB::table('edge_academy_trials')->count();
        $originalRows = DB::table('edge_academy_trials')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        $this->assertSame('ACADEMY_LEARNING_PROGRESS_HOLDS_NEW_WORK',
            $academy->nextExperiment($series[5]['passport_id'], ['status' => 'entry_mastery_required'])['reason']);
        $this->assertSame($before, DB::table('edge_academy_trials')->count());
        // A cap holds new work; already frozen planned trials stay unchanged.
        $this->assertSame('planned', $academy->planContextLocationProbe($series[5]['passport_id'])['status']);
        $this->assertSame($originalRows, DB::table('edge_academy_trials')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all());
    }

    public function test_initial_curriculum_progress_tracks_the_actual_executable_cold_axis(): void
    {
        $academy = app(XauusdEdgeFormationAcademyService::class);
        $parameters = app(StrategyParameterSchemaService::class)->defaults('confirmation_entry_mtf');
        $preview = $academy->previewColdStartExperiment($parameters);
        $this->assertSame('setup_topology_policy', $preview['axis']);
        $passport = $academy->passport('XAUUSD', 'H1', ['composition_key' => 'inactive-location-cold-task',
            'strategy_family' => 'confirmation_entry_mtf', 'baseline_parameters' => $parameters]);
        $trial = $academy->planColdStartExperiment($passport['passport_id']);
        $this->assertSame($preview['axis'], $trial['axis']);
        $this->assertSame($trial['axis'], $academy->learningProgress($passport['passport_id'])['axis']);
        $this->assertSame($trial['axis'], $trial['learning_progress']['axis']);
    }

    private function series(array $baselines, bool $complete = true): array
    {
        $academy = app(XauusdEdgeFormationAcademyService::class); $compiler = app(AcademyExperimentContractCompilerService::class);
        $items = [];
        foreach ($baselines as $baseline) {
            $fixture = $this->fixture($baseline, round($baseline + .1, 6), round($baseline < .15 ? $baseline + .2 : $baseline - .1, 6));
            $receipt = data_get($fixture, 'control.data_quality.decision_identity_receipt');
            $streams = collect($receipt['dependency_identity']['streams'])->map(fn ($stream): array => ['sha256' => $stream['actual_source_sha256']])->all();
            $parameters = [...app(StrategyParameterSchemaService::class)->defaults('confirmation_entry_mtf'),
                'location_tolerance_atr' => $baseline, 'setup_topology_policy' => 'liquidity_sweep_reclaim'];
            $model = ModelVersion::create(['name' => 'learning-source-'.(++$this->sequence), 'version' => 'source-'.$this->sequence,
                'strategy' => 'confirmation_entry_mtf_v1', 'generation' => 1, 'status' => 'testing', 'parameters' => $parameters]);
            // Freeze the persisted source, not pre-JSON numeric representations.
            $parameters = (array) $model->fresh()->parameters;
            $passport = $academy->passport('XAUUSD', 'H1', ['composition_key' => 'progress-'.$this->sequence,
                'strategy_family' => 'confirmation_entry_mtf', 'deepest_stage' => 'market_cartographer',
                'baseline_model_version_id' => $model->id, 'baseline_parameters' => $parameters,
                'baseline_parameter_hash' => $compiler->parameterHash($parameters), 'prospective_source_identity' => [
                    'pre_2026_only' => true, 'source_identity_protocol' => 'dual_runtime_source_identity_v1',
                    'data_hash' => $streams['H1']['sha256'], 'mtf_bundle_hash' => $receipt['bindings']['dataset_identity'],
                    'execution_hash' => $receipt['bindings']['execution_hash'], 'source_evaluator_hash' => $receipt['bindings']['full_runtime_source_hash'],
                    'python_source_hash' => $receipt['bindings']['python_source_hash'], 'mtf_bundle_manifest' => ['streams' => $streams]]]);
            $trial = $academy->planContextLocationProbe($passport['passport_id']);
            $this->assertSame('planned', $trial['status'], json_encode($trial));
            $items[] = ['passport_id' => (int) $passport['passport_id'], 'trial_id' => (int) $trial['trial_id'],
                ...($complete ? $this->completeTrial($trial['trial_id'], $fixture) : [])];
        }
        return $items;
    }

    private function completeTrial(int $trialId, array $fixture): array
    {
        $this->laboratory ??= AiLaboratory::create(['name' => 'Learning progress synthetic fixture', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_families' => ['confirmation_entry_mtf'], 'is_active' => false]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $this->laboratory->id, 'generation' => ++$this->sequence,
            'trigger_type' => 'academy_experiment', 'status' => 'screened']);
        $trial = DB::table('edge_academy_trials')->find($trialId); $frozen = json_decode($trial->frozen_contract, true);
        $arms = json_decode($trial->arms, true); $axis = $arms[0]['changed_axis'];
        $compiler = app(AcademyExperimentContractCompilerService::class); $evidence = app(LabImmutableEvidenceService::class);
        $compiled = $compiler->compile(['axis' => $axis, 'arms' => $arms], $frozen['baseline_parameters'],
            ['symbol' => 'XAUUSD', 'laboratory_timeframe' => 'H1', 'execution_timeframe' => 'M5']);
        $this->assertSame('compiled', $compiled['status'], json_encode($compiled));
        $models = []; $agents = []; $runs = [];
        foreach ($compiled['arms'] as $index => $arm) {
            $model = ModelVersion::create(['name' => 'progress-trial-'.$trialId.'-'.$index, 'version' => 'trial-'.$trialId.'-'.$index,
                'strategy' => 'confirmation_entry_mtf_v1', 'generation' => $generation->generation, 'status' => 'testing',
                'parameters' => $arm['runtime_parameters'], 'metadata' => ['base_strategy' => 'confirmation_entry_mtf_v1',
                    'academy_experiment' => ['academy_trial_id' => $trialId, 'arm_index' => $index, 'arm_role' => $arm['role']]]]);
            $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'confirmation_entry_mtf',
                'origin' => 'academy_experiment', 'lifecycle_status' => 'screened', 'parameter_diff' => []]);
            $metrics = $fixture[$arm['role'] === 'frozen_control' ? 'control' : ($arm['role'] === 'blinded_control' ? 'blinded' : 'candidate')];
            $run = $evidence->beginRun($agent, 'screening', 'screen', ['code_hash' => data_get($metrics, 'data_quality.decision_identity_receipt.bindings.full_runtime_source_hash')]);
            $evidence->attachRequest($run, ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'parameters' => $model->parameters,
                'replay_dataset_hash' => $metrics['data_hash'], 'composition_runtime_contract' => []]);
            $evidence->finishRun($run, 'completed', $metrics);
            $models[$index] = $model; $agents[$index] = $agent; $runs[$index] = $run->fresh();
            $this->assertNotNull($evidence->verifiedModelRuntimeIdentity($runs[$index]));
        }
        $comparisons = [];
        foreach ($compiled['arms'] as $index => $arm) {
            if ($arm['role'] !== 'candidate') continue;
            $comparison = ['role' => 'candidate', 'gene' => $axis];
            foreach (['control' => 0, 'candidate' => $index] as $role => $selected) {
                $comparison[$role.'_run_database_id'] = $runs[$selected]->id;
                $comparison[$role.'_run_id'] = $runs[$selected]->run_id;
                $comparison[$role.'_agent_id'] = $agents[$selected]->id;
                $comparison[$role.'_model_version_id'] = $models[$selected]->id;
                $comparison[$role.'_response_hash'] = $runs[$selected]->response_hash;
                $comparison[$role.'_parameter_hash'] = $compiler->parameterHash((array) $models[$selected]->parameters);
            }
            $comparisons[] = $comparison;
        }
        $settlement = app(XauusdEdgeFormationAcademyService::class)->settleTrial($trialId,
            ['setup' => 30, 'trigger' => 30, 'closed_trade' => 0], ['primary_arm_evidence_complete' => true,
                'expected_arm_count' => count($compiled['arms']), 'complete_arm_count' => count($compiled['arms']), 'stage_comparisons' => $comparisons]);
        return ['settlement' => $settlement, 'models' => $models, 'runs' => $runs];
    }

    private function fixture(float $control, float $candidate, float $blinded): array
    {
        $this->runtimeHash ??= app(LabImmutableEvidenceService::class)->codeHash();
        $producer = new Process(['python', base_path('../ai-service-python/tests/support/semantic_stage_receipt_fixture.py'),
            '--control-limit', (string) $control, '--candidate-limit', (string) $candidate, '--blinded-limit', (string) $blinded,
            '--location-distance-step', '.1', '--full-runtime-source-hash', $this->runtimeHash,
            '--python-source-hash', app(ResearchReleaseSealService::class)->pythonHash()], base_path('../ai-service-python'),
            env: ['TMP' => base_path('../.runtime'), 'TEMP' => base_path('../.runtime'), 'TMPDIR' => base_path('../.runtime')], timeout: 90);
        $producer->mustRun();
        return json_decode($producer->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}
