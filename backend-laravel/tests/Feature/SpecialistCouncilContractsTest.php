<?php

namespace Tests\Feature;

use App\Models\LabEvaluationRun;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\AiLaboratory;
use App\Models\ModelVersion;
use App\Models\PaperOrder;
use App\Models\SpecialistCouncilVersion;
use App\Services\ResearchPaperEpochContractService;
use App\Services\ExecutionContractService;
use App\Services\LabImmutableEvidenceService;
use App\Services\SpecialistCouncilContractService;
use App\Services\SpecialistCouncilDataUseService;
use App\Services\SpecialistCouncilLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class SpecialistCouncilContractsTest extends TestCase
{
    use RefreshDatabase;

    public function test_roles_have_distinct_products_and_cannot_escalate_permissions(): void
    {
        $service = app(SpecialistCouncilContractService::class);
        $roles = $service->roles();
        $this->assertCount(14, $roles);
        $this->assertSame(['risk_decision'], $roles['risk']['products']);
        $this->assertSame(['typed_operator'], $roles['toolbox']['products']);
        $this->assertFalse($roles['evolution']['may_self_certify']);
        $passport = $this->passport('hour', $this->model('hour'));
        $passport['allowed_actions'] = ['propose_trade', 'self_certify', 'wait'];
        $this->expectException(\InvalidArgumentException::class);
        $service->sealPassport($passport);
    }

    public function test_all_four_horizons_are_sealed_independently_of_shared_sensor_timeframes(): void
    {
        $members = [];
        foreach (['scalp', 'hour', 'day', 'swing'] as $role) $members[] = $this->passport($role, $this->model($role));
        $version = $this->draft($members);
        $runtime = app(SpecialistCouncilLifecycleService::class)->runtimeContract($version, str_repeat('d', 64), str_repeat('e', 64), 'M5');
        $this->assertSame(['scalp', 'hour', 'day', 'swing'], array_column($runtime['members'], 'role'));
        $this->assertSame(3600, $runtime['members'][1]['horizon']['max_holding_seconds']);
        $this->assertSame(86400, $runtime['members'][3]['horizon']['max_holding_seconds']);
        $this->assertSame(288, $runtime['members'][3]['horizon']['max_holding_bars']);
        foreach ($version->manifest['members'] as $member) $this->assertSame(['H4', 'H1', 'M15', 'M5'], $member['sensor_timeframes']);
        $this->assertFalse($runtime['promotion_evidence']);
    }

    public function test_second_scalp_requires_its_own_tick_prerequisites(): void
    {
        $passport = $this->passport('scalp', $this->model('scalp'));
        $passport['horizon']['decision_interval_seconds'] = 5;
        $this->expectException(\InvalidArgumentException::class);
        app(SpecialistCouncilContractService::class)->sealPassport($passport);
    }

    public function test_sealed_manifest_and_float_identity_survive_database_roundtrip(): void
    {
        $passport = $this->passport('hour', $this->model('hour'));
        $passport['capital_weight'] = 1.0;
        $version = $this->draft([$passport]);
        $this->assertTrue(app(SpecialistCouncilContractService::class)->manifestValid($version->fresh()->manifest));
        $this->assertSame(1.0, $version->fresh()->manifest['members'][0]['capital_weight']);
        $this->expectException(\LogicException::class);
        $version->forceFill(['manifest' => [...$version->manifest, 'version' => 'forged']])->save();
    }

    public function test_declared_missing_binding_fails_closed_instead_of_running_legacy(): void
    {
        $model = $this->model('carrier');
        $metadata = $model->metadata;
        $metadata['specialist_council'] = ['protocol' => 'specialist_council_binding_v1', 'version_id' => 9999, 'manifest_hash' => str_repeat('f', 64)];
        $model->forceFill(['metadata' => $metadata])->save();
        $this->expectException(\LogicException::class);
        app(SpecialistCouncilLifecycleService::class)->runtimeContractForModel($model, 'M5', str_repeat('d', 64), str_repeat('e', 64));
    }

    public function test_carrier_cannot_be_an_existing_specialist_or_previously_evaluated_model(): void
    {
        $member = $this->model('hour'); $version = $this->draft([$this->passport('hour', $member)]);
        $this->expectException(\LogicException::class);
        app(SpecialistCouncilLifecycleService::class)->attachResearchModel($version, $member);
    }

    public function test_creator_cannot_seal_its_own_evaluation_plan(): void
    {
        $version = $this->draft();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('CREATOR_OR_MEMBER_CANNOT_SELF_CERTIFY');
        app(SpecialistCouncilLifecycleService::class)->sealEvaluationPlan($version, 'evolution-owner', $this->plan($version));
    }

    public function test_historical_comparison_and_forged_flags_never_grant_approval(): void
    {
        $service = app(SpecialistCouncilLifecycleService::class); $version = $this->draft();
        $plan = $service->sealEvaluationPlan($version, 'independent-evaluator', $this->plan($version));
        $assessment = $service->evaluateOriginalRuns($version->fresh(), 'independent-evaluator', []);
        $this->assertFalse($assessment['qualified']);
        $this->assertContains('RESEARCH_COMPARISON_HAS_NO_INDEPENDENT_PROMOTION_AUTHORITY', $assessment['reason_codes']);
        DB::table('specialist_council_versions')->where('id', $version->id)->update([
            'assessment' => json_encode([...$assessment, 'qualified' => true, 'independent' => true, 'passed' => true]),
        ]);
        $approval = $service->approve($version->fresh(), 'independent-approver');
        $this->assertFalse($approval['allowed']);
        $this->assertSame('ORIGINAL_INDEPENDENT_COUNCIL_EVIDENCE_INCOMPLETE', $approval['reason_code']);
        $this->assertSame('research', $plan['purpose']);
    }

    public function test_independent_evaluation_requires_actual_server_authorized_unused_windows(): void
    {
        $version = $this->draft(); $plan = $this->plan($version);
        $plan['purpose'] = 'independent';
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('NO_COMPLETED_AUTHORIZED_INDEPENDENT_WINDOW');
        app(SpecialistCouncilLifecycleService::class)->sealEvaluationPlan($version, 'independent-evaluator', $plan);
    }

    public function test_provider_hash_and_timeframe_relabels_do_not_make_used_events_independent(): void
    {
        $version = $this->draft(); $service = app(SpecialistCouncilDataUseService::class);
        $event = $this->event();
        $service->recordUse($version, [$event], 'training', 'hour-program-v1', '2025-12-01T00:00:00Z');
        $changed = [...$event, 'event_end' => '2025-01-06T01:00:00Z', 'available_at' => '2025-01-06T01:00:00Z',
            'matured_at' => '2025-01-06T01:00:00Z', 'provenance' => ['provider' => 'other', 'hash' => str_repeat('a', 64), 'timeframe' => 'H1']];
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('EVALUATION_EVENTS_ALREADY_USED_FOR_TRAINING_OR_SELECTION');
        $service->recordUse($version, [$changed], 'evaluation', 'evaluator-v2', '2025-12-01T00:00:00Z');
    }

    public function test_unavailable_and_immature_feedback_cannot_enter_research(): void
    {
        $event = $this->event(); $event['matured_at'] = '2025-12-02T00:00:00Z';
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('FEEDBACK_OR_EVALUATION_OUTCOME_NOT_MATURE');
        app(SpecialistCouncilDataUseService::class)->recordUse($this->draft(), [$event], 'training', 'learning-v1', '2025-12-01T00:00:00Z');
    }

    public function test_2026_market_events_remain_paper_only(): void
    {
        $event = $this->event();
        foreach (['event_start', 'event_end', 'available_at', 'matured_at'] as $field) $event[$field] = str_replace('2025-', '2026-', $event[$field]);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('2026_PAPER_OR_AUTHORIZED_PAPER_EVENTS_ARE_NOT_RESEARCH_DATA');
        app(SpecialistCouncilDataUseService::class)->recordUse($this->draft(), [$event], 'selection', 'evolution-v1', '2026-10-01T00:00:00Z');
    }

    public function test_data_use_is_idempotent_and_open_swing_feedback_is_not_mature(): void
    {
        $version = $this->draft(); $service = app(SpecialistCouncilDataUseService::class);
        $first = $service->recordUse($version, [$this->event()], 'training', 'learning-v1', '2025-12-01T00:00:00Z', 'run-1');
        $retry = $service->recordUse($version, [$this->event()], 'training', 'learning-v1', '2025-12-01T00:00:00Z', 'run-1');
        $this->assertSame(1, $first['recorded']); $this->assertSame(0, $retry['recorded']);
        $this->assertDatabaseCount('specialist_council_data_events', 1);
        $order = new PaperOrder(['status' => 'open', 'opened_at' => '2025-01-06T00:00:00Z']);
        $this->assertSame('POSITION_FEEDBACK_NOT_MATURE', $service->recordMaturePaperFeedback($order, $version, 'learning-v1', '2025-12-01T00:00:00Z')['reason_code']);
    }

    public function test_retired_version_manages_original_pin_but_cannot_open_new_positions(): void
    {
        $model = $this->model('hour'); $version = $this->draft([$this->passport('hour', $model)]);
        $version->forceFill(['state' => 'retired', 'approved_at' => now(), 'retired_at' => now()])->save();
        $binding = ['protocol' => 'specialist_council_binding_v1', 'version_id' => $version->id,
            'specialist_id' => 'hour', 'management_version' => 'management-v1'];
        $service = app(SpecialistCouncilLifecycleService::class);
        $this->assertFalse($service->paperBinding($model, 'XAUUSD', 'H1', $binding)['allowed']);
        $model->forceFill(['parameters' => ['ema_fast' => 99]])->save();
        $manager = $service->paperBinding($model, 'XAUUSD', 'H1', [...$binding, 'management_only' => true]);
        $this->assertTrue($manager['allowed']); $this->assertSame('management-v1', $manager['management_version']);
        $this->assertSame('hour', $manager['specialist_id']);
    }

    public function test_typed_component_admission_is_research_only_and_compatible_with_consumer(): void
    {
        $component = ['id' => 'learned-confirmation', 'version' => '1', 'role' => 'toolbox',
            'input_type' => 'as_of_observation', 'output_type' => 'confirmation', 'consumer_roles' => ['hour'],
            'as_of_only' => true, 'max_compute_ms' => 100];
        $service = app(SpecialistCouncilContractService::class);
        $admitted = $service->componentAdmission($component, 'hour');
        $this->assertTrue($admitted['allowed']); $this->assertFalse($admitted['paper_authority_granted']);
        $this->assertFalse($service->componentAdmission($component, 'swing')['allowed']);
        $component['permissions'] = ['edit_external_risk_limits'];
        $this->assertFalse($service->componentAdmission($component, 'hour')['allowed']);
    }

    public function test_progress_counts_manifest_activity_separately_from_verified_skills(): void
    {
        $version = $this->draft(); $carrier = $this->model('carrier');
        $service = app(SpecialistCouncilLifecycleService::class);
        $carrier = $service->attachResearchModel($version, $carrier);
        $progress = $service->progressForModels([$carrier->id]);
        $this->assertSame(1, $progress['drafted_versions']);
        $this->assertSame(0, $progress['research_compared_versions']);
        $this->assertSame(0, $progress['independently_qualified_versions']);
        $this->assertSame(0, $progress['roles']['hour']['independently_qualified']);
        $this->assertContains('ORIGINAL_INDEPENDENT_ASSESSMENT_NOT_SETTLED', $progress['unresolved_prerequisites']);
        $this->assertSame(0, $service->progressForModels([])['drafted_versions']);
    }

    public function test_changing_native_member_after_seal_refuses_replay(): void
    {
        $member = $this->model('hour'); $version = $this->draft([$this->passport('hour', $member)]);
        $member->forceFill(['parameters' => ['ema_fast' => 99]])->save();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('COUNCIL_MEMBER_NATIVE_MODEL_DRIFT');
        app(SpecialistCouncilLifecycleService::class)->runtimeContract($version, str_repeat('d', 64), str_repeat('e', 64), 'M5');
    }

    public function test_real_python_receipt_attests_exact_php_manifest_and_detects_resealed_account_tampering(): void
    {
        $members = [];
        foreach (['scalp', 'hour', 'day', 'swing'] as $role) {
            $passport = $this->passport($role, $this->model($role));
            $passport['scope']['contexts'] = ['any'];
            $passport['scope']['symbols'] = ['EURUSD'];
            $members[] = $passport;
        }
        $version = $this->draft($members); $service = app(SpecialistCouncilLifecycleService::class);
        $carrier = $service->attachResearchModel($version, $this->model('carrier'));
        $execution = app(ExecutionContractService::class)->for('EURUSD', 'M5');
        $contract = $service->runtimeContract($version, str_repeat('d', 64), $execution['execution_hash'], 'M5', null, 'EURUSD');
        $request = ['strategy' => 'portfolio_v1', 'base_strategy' => 'portfolio', 'symbol' => 'EURUSD',
            'timeframe' => 'M5', 'initial_balance' => 10000, 'replay_dataset_hash' => str_repeat('d', 64),
            'execution' => $execution['parameters'], 'execution_contract' => $execution,
            'specialist_council_contract' => $contract];
        $source = <<<'PY'
import json,sys,math,pandas as pd
from app.schemas import SimpleBacktestRequest
from app.services.backtester import run_simple_ema_rsi_backtest_on_dataframe
p=json.load(sys.stdin)
prices=[1.2+.02*math.sin(i/12)+i*.00005 for i in range(200)]
frame=pd.DataFrame({'time':pd.date_range('2025-01-06T02:00:00Z',periods=200,freq='5min'),'open':prices,'high':[x+.001 for x in prices],'low':[x-.001 for x in prices],'close':prices,'volume':100.0,'volume_available':True})
result=run_simple_ema_rsi_backtest_on_dataframe(SimpleBacktestRequest(**p),frame)
print(result.model_dump_json())
PY;
        $process = new Process(['python', '-c', $source], dirname(base_path()).'/ai-service-python');
        $process->setTimeout(30); $process->setInput(json_encode($request, JSON_THROW_ON_ERROR));
        $process->mustRun();
        $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $receipt = $service->attestReplayResult($carrier, $request, $result);
        $this->assertSame('computed', $receipt['status']);
        $this->assertCount(4, $receipt['members']);
        $this->assertGreaterThan(0, array_sum($receipt['members'][0]['stages']));
        $this->assertFalse($receipt['scientific_evidence']);
        $receipt['account']['final_balance'] += 10;
        unset($receipt['receipt_hash'], $receipt['receipt_json']);
        $receipt['receipt_hash'] = app(ResearchPaperEpochContractService::class)->parameterHash($receipt);
        $result['specialist_council_receipt'] = $receipt;
        $result['data_quality']['specialist_council_receipt'] = $receipt;
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('COUNCIL_SHARED_ACCOUNT_CONSERVATION_FAILED');
        $service->attestReplayResult($carrier, $request, $result);
    }

    public function test_native_command_registers_existing_unobserved_lab_carrier_without_dispatch_or_authority(): void
    {
        Storage::fake('local');
        $version = $this->draft(); $carrier = $this->model('carrier');
        $lab = AiLaboratory::create(['name' => 'Native council carrier fixture', 'symbol' => 'XAUUSD',
            'timeframe' => 'H1', 'strategy_families' => ['ema_rsi'], 'is_active' => false]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'trigger_type' => 'test', 'status' => 'draft', 'population_size' => 1, 'trigger_context' => []]);
        $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $carrier->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'ema_rsi',
            'origin' => 'test', 'lifecycle_status' => 'created', 'parameter_diff' => []]);
        Storage::disk('local')->put('council-fixture/manifest.json', json_encode($version->manifest, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        $path = Storage::disk('local')->path('council-fixture/manifest.json');
        $this->artisan('trading:specialist-council', ['action' => 'register', '--manifest' => $path,
            '--carrier-model' => $carrier->id, '--actor' => 'evolution-owner'])->assertSuccessful();
        $this->assertSame($version->id, data_get($carrier->fresh()->metadata, 'specialist_council.version_id'));
        $this->assertSame('created', $agent->fresh()->lifecycle_status);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('paper_authority_admissions', 0);
    }

    public function test_risk_product_cannot_vote_as_a_trader_or_increase_exposure(): void
    {
        $passport = $this->passport('hour', $this->model('hour'));
        $passport['role'] = 'risk'; $passport['specialist_id'] = 'risk';
        $passport = app(SpecialistCouncilContractService::class)->sealPassport($passport);
        $product = ['type' => 'risk_decision', 'intent_id' => 'intent-hour-1', 'decision' => 'reduce',
            'original_size' => 1, 'approved_size' => .5, 'external_limit_version' => 'hard-limits-v1'];
        $result = app(SpecialistCouncilContractService::class)->product($passport, $product, '2025-12-01T00:00:00Z');
        $this->assertSame('risk', $result['role']); $this->assertFalse($result['promotion_evidence']);
        $product['approved_size'] = 2;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('RISK_PRODUCT_CANNOT_INCREASE_PROPOSED_EXPOSURE');
        app(SpecialistCouncilContractService::class)->product($passport, $product, '2025-12-01T00:00:00Z');
    }

    public function test_completion_delivery_is_durable_bounded_and_never_rewrites_original_run(): void
    {
        $run = LabEvaluationRun::create(['run_id' => 'original-published-run', 'phase' => 'screening', 'mode' => 'incremental',
            'status' => 'completed', 'started_at' => now()->subMinute(), 'finished_at' => now(),
            'request_meta' => ['payload' => ['specialist_council_evaluation' => ['version_id' => 999999, 'plan_hash' => str_repeat('f', 64)]]]]);
        $service = app(SpecialistCouncilLifecycleService::class);
        $receipt = $service->notifyCompletedRun($run);
        $this->assertSame('evaluation_delivery_pending', $receipt['status']);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(1, DB::table('specialist_council_evaluation_deliveries')->value('attempts'));
        $service->reconcilePendingEvaluations();
        $this->assertSame(1, DB::table('specialist_council_evaluation_deliveries')->value('attempts'));
        $this->travel(6)->minutes(); $service->reconcilePendingEvaluations();
        $this->travel(6)->minutes(); $service->reconcilePendingEvaluations();
        $this->assertSame('blocked', DB::table('specialist_council_evaluation_deliveries')->value('status'));
        $this->assertSame(3, DB::table('specialist_council_evaluation_deliveries')->value('attempts'));
        $service->reconcilePendingEvaluations();
        $this->assertSame(3, DB::table('specialist_council_evaluation_deliveries')->value('attempts'));
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_legacy_completion_notification_has_no_extra_queries(): void
    {
        $service = app(SpecialistCouncilLifecycleService::class);
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->assertNull($service->notifyCompletedRun(new LabEvaluationRun(['request_meta' => ['payload' => ['strategy' => 'ema_rsi_v1']]])));
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_native_dispatcher_handoff_applies_original_plan_economics_and_probe_before_sealing(): void
    {
        [$version, $carrier, $plan, $request] = $this->boundPlanFixture();
        $dispatcher = app(\App\Services\LabAgentEvaluationService::class);
        $handoff = new \ReflectionMethod($dispatcher, 'bindCouncilEvaluationRequests');
        $bound = $handoff->invoke($dispatcher, $request, [$carrier]);
        $this->assertSame(10000.0, $bound['initial_balance']);
        $this->assertSame(0.5, $bound['risk_per_trade']);
        $this->assertSame($plan['execution_hash'], $bound['execution_hash']);
        $this->assertSame($plan['cost_model'], $bound['cost_model']);
        $this->assertSame($plan['windows'][0]['prospective_probe_window'], $bound['policy_context']['prospective_probe_window']);
        $this->assertSame($version->id, $bound['specialist_council_evaluation']['version_id']);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    public function test_evaluation_plan_cannot_decorate_different_actual_costs_or_native_risk(): void
    {
        [$version, $carrier, $plan, $request] = $this->boundPlanFixture();
        $request['specialist_council_contract']['policy']['max_total_risk_percent'] = 4;
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('COUNCIL_PLAN_RISK_NOT_APPLIED_TO_NATIVE_ACCOUNT:max_total_risk_percent');
        app(SpecialistCouncilLifecycleService::class)->bindEvaluationRequestForModel($carrier, $request);
    }

    public function test_conflicting_batch_plan_policy_fails_before_dispatch(): void
    {
        [$version, $carrier, $plan, $request] = $this->boundPlanFixture();
        $service = app(SpecialistCouncilLifecycleService::class);
        $binding = $service->evaluationBindingForModel($carrier, $request['replay_dataset_hash']);
        $request['strategies'] = [['lab_agent_id' => 1, 'specialist_council_evaluation' => $binding,
            'specialist_council_contract' => $request['specialist_council_contract']]];
        unset($request['specialist_council_contract']);
        $bound = $service->bindEvaluationRequestForModel($carrier, $request);
        $bound['specialist_council_evaluation_policy']['initial_balance'] = 20000.0;
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('BATCH_COUNCIL_EVALUATION_POLICIES_CONFLICT');
        $service->bindEvaluationRequestForModel($carrier, $bound);
    }

    public function test_equal_dataset_hash_does_not_replace_original_comparator_window_receipt(): void
    {
        [$version, $carrier, $plan, $request] = $this->boundPlanFixture();
        $service = app(SpecialistCouncilLifecycleService::class);
        $sealed = DB::table('specialist_council_evaluation_plans')->where('specialist_council_version_id', $version->id)->first();
        $arm = json_decode($sealed->plan, true)['arms']['candidate-2025'];
        $probe = $plan['windows'][0]['prospective_probe_window'];
        $bound = $service->bindEvaluationRequestForModel($carrier, $request);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('ORIGINAL_COMPARATOR_EXECUTED_CLOCK_RECEIPT_MISSING');
        $service->assertOriginalArmScope($arm, $bound, ['prospective_probe_window_receipt' => [...$probe, 'complete' => true]], 'M5');
    }

    public function test_memory_blinded_arm_cannot_be_annotated_without_original_selector(): void
    {
        $version = $this->draft(); $plan = $this->plan($version);
        $plan['arms'][0]['kind'] = 'memory_blinded';
        $plan['arms'][0]['selection_protocol'] = ['equal_compute' => true, 'memory_blinded' => true];
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('MEMORY_BLINDED_ARM_REQUIRES_ORIGINAL_VERIFIED_SELECTOR_EXPERIMENT');
        app(SpecialistCouncilLifecycleService::class)->sealEvaluationPlan($version, 'independent-evaluator', $plan);
    }

    public function test_php_native_member_compiler_refuses_cross_instrument_rebinding(): void
    {
        $member = $this->model('hour');
        $lab = AiLaboratory::create(['name' => 'Frozen XAU owner', 'symbol' => 'XAUUSD', 'timeframe' => 'M5',
            'strategy_families' => ['ema_rsi'], 'is_active' => false]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'trigger_type' => 'test', 'status' => 'draft', 'population_size' => 1, 'trigger_context' => []]);
        LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $member->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'M5', 'strategy_family' => 'ema_rsi', 'origin' => 'test',
            'lifecycle_status' => 'created', 'parameter_diff' => []]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('COUNCIL_MEMBER_NATIVE_INSTRUMENT_MISMATCH');
        app(\App\Services\LabAgentEvaluationService::class)->specialistCouncilMemberPayload($member, 'M5', null, str_repeat('d', 64), 'EURUSD');
    }

    public function test_additive_council_schema_index_names_fit_mysql_identifier_limits(): void
    {
        foreach (['specialist_council_versions', 'specialist_council_evaluations', 'specialist_council_evaluation_plans',
            'specialist_council_evaluation_deliveries', 'specialist_council_data_events', 'specialist_council_data_uses'] as $table) {
            foreach (\Illuminate\Support\Facades\Schema::getIndexes($table) as $index) {
                $this->assertLessThanOrEqual(64, strlen($index['name']), $table.':'.$index['name']);
            }
        }
        $this->assertContains('sc_eval_plan_version_unique', array_column(\Illuminate\Support\Facades\Schema::getIndexes('specialist_council_evaluation_plans'), 'name'));
        $this->assertContains('sc_delivery_version_index', array_column(\Illuminate\Support\Facades\Schema::getIndexes('specialist_council_evaluation_deliveries'), 'name'));
    }

    public function test_mysql_grammar_requires_literal_datetime_without_implicit_timestamp_defaults(): void
    {
        $connection = new \Illuminate\Database\MySqlConnection(null, 'schema_compile_only', '', [
            'driver' => 'mysql', 'version' => '5.7.0', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
            'strict' => true,
        ]);
        $connection->setSchemaGrammar(new \Illuminate\Database\Schema\Grammars\MySqlGrammar($connection));
        $compiled = [];
        \Illuminate\Support\Facades\Schema::shouldReceive('create')->times(7)->andReturnUsing(
            function (string $table, \Closure $callback) use ($connection, &$compiled): void {
                $blueprint = new \Illuminate\Database\Schema\Blueprint($connection, $table);
                $blueprint->create(); $callback($blueprint);
                $compiled[$table] = $blueprint->toSql();
            }
        );
        $migration = require database_path('migrations/2026_10_05_120000_create_specialist_council_contracts.php');
        $migration->up();
        $this->assertCount(7, $compiled);
        foreach (['specialist_council_versions' => ['sealed_at'], 'specialist_council_evaluation_plans' => ['sealed_at'],
            'specialist_council_data_events' => ['event_start', 'event_end', 'available_at'],
            'specialist_council_data_uses' => ['as_of']] as $table => $columns) {
            $create = $compiled[$table][0];
            foreach ($columns as $column) {
                $this->assertMatchesRegularExpression('/`'.preg_quote($column, '/').'` datetime not null(?:,|\\))/', $create);
                $this->assertStringNotContainsString('`'.$column.'` timestamp', $create);
            }
            $this->assertStringNotContainsString('CURRENT_TIMESTAMP', $create);
            $this->assertStringNotContainsString('0000-00-00', $create);
        }
    }

    public function test_rollback_restores_future_entry_binding_for_shared_native_model_without_rebinding_position_pin(): void
    {
        $model = $this->model('shared-hour');
        $previous = $this->draft([$this->passport('hour', $model)]);
        $manifest = $previous->manifest;
        unset($manifest['manifest_hash']);
        $manifest['version'] = '2';
        $service = app(SpecialistCouncilLifecycleService::class);
        $current = $service->registerDraft($manifest, 'evolution-owner');
        // Preapproved versions are fixtures for the rollback transition, not
        // permission to bypass original independent approval in production.
        $previous->update(['state' => 'retired', 'approved_at' => now(), 'retired_at' => now()]);
        $current->update(['state' => 'active', 'approved_at' => now(), 'previous_version_id' => $previous->id]);
        $pin = ['protocol' => SpecialistCouncilLifecycleService::BINDING_PROTOCOL,
            'version_id' => $current->id, 'specialist_id' => 'hour', 'management_version' => 'management-v1'];
        $model->update(['metadata' => [...$model->metadata, 'specialist_council_binding' => $pin]]);
        $this->mock(\App\Services\PaperAuthorityAdmissionService::class, function ($mock): void {
            $mock->shouldReceive('verifyFrozenCandidate')->andReturn(['allowed' => true, 'identity_hash' => 'fixture-only']);
            $mock->shouldReceive('championEligible')->andReturn(true);
            $mock->shouldReceive('observationReadiness')->andReturn(['allowed' => true]);
        });

        $result = $service->rollback($current, 'prospective council regression');
        $this->assertTrue($result['allowed']);
        $this->assertSame($previous->id, data_get($model->fresh()->metadata, 'specialist_council_binding.version_id'));
        $this->assertSame('active', $previous->fresh()->state);
        $this->assertSame('rolled_back', $current->fresh()->state);
        $this->assertTrue($service->paperBinding($model->fresh(), 'XAUUSD', 'H1')['allowed']);
        $management = $service->paperBinding($model->fresh(), 'XAUUSD', 'H1', [...$pin, 'management_only' => true]);
        $this->assertTrue($management['allowed']);
        $this->assertSame($current->id, $management['version_id']);
        $this->assertSame($current->id, $pin['version_id']);
    }

    public function test_rollback_cannot_restore_shared_model_with_changed_native_parameters(): void
    {
        $model = $this->model('shared-hour');
        $previous = $this->draft([$this->passport('hour', $model)]);
        $manifest = $previous->manifest;
        unset($manifest['manifest_hash']);
        $manifest['version'] = '2';
        $service = app(SpecialistCouncilLifecycleService::class);
        $current = $service->registerDraft($manifest, 'evolution-owner');
        $previous->update(['state' => 'retired', 'approved_at' => now(), 'retired_at' => now()]);
        $current->update(['state' => 'active', 'approved_at' => now(), 'previous_version_id' => $previous->id]);
        $model->update(['parameters' => ['ema_fast' => 99, 'ema_slow' => 100]]);
        $result = $service->rollback($current, 'changed native source');
        $this->assertFalse($result['allowed']);
        $this->assertSame('COUNCIL_MEMBER_NATIVE_MODEL_DRIFT', $result['reason_code']);
        $this->assertSame('active', $current->fresh()->state);
        $this->assertSame('retired', $previous->fresh()->state);
    }

    public function test_research_council_screen_cannot_become_an_ordinary_economic_survivor_or_repair_anchor(): void
    {
        [$version, $carrier] = $this->boundPlanFixture();
        $lab = AiLaboratory::create(['symbol' => 'XAUUSD', 'name' => 'Council research gate', 'timeframe' => 'H1',
            'strategy_families' => ['mean_reversion'], 'is_active' => true]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'trigger_type' => 'historical_research', 'population_size' => 1, 'status' => 'screening']);
        $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $carrier->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'mean_reversion',
            'origin' => 'test', 'lifecycle_status' => 'screening', 'parameter_diff' => []]);
        $this->mock(\App\Services\FailureRepairAnchorService::class, function ($mock): void {
            $mock->shouldNotReceive('recordFromScreeningDecision');
            $mock->shouldNotReceive('recordRepairScreeningOutcome');
        });
        $this->mock(\App\Services\CooperativeExperimentSettlementService::class, function ($mock): void {
            $mock->shouldNotReceive('settleGeneration');
        });
        $result = app(\App\Services\CandidateGateDecisionService::class)->recordScreening($agent,
            ['total_trades' => 100, 'profit_factor' => 10, 'net_profit' => 1000, 'max_drawdown' => 0,
                'risk_of_ruin' => 0, 'screening_survival' => ['status' => 'survivor']]);
        $this->assertSame('failed', $result->decision);
        $this->assertSame(['SPECIALIST_COUNCIL_RESEARCH_ONLY'], $result->reason_codes);
        $this->assertTrue($result->metrics['specialist_council_research_only']);
        $this->assertFalse($result->metrics['promotion_evidence']);
        $this->assertSame('research', app(SpecialistCouncilLifecycleService::class)->evaluationPurposeForModel($carrier));
        $selection = app(\App\Services\CandidateGateDecisionService::class)->recordFullReplaySelection($agent, true);
        $this->assertSame('failed', $selection->decision);
        $this->assertSame(['SPECIALIST_COUNCIL_RESEARCH_ONLY'], $selection->reason_codes);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        try {
            app(\App\Services\LabAgentEvaluationService::class)->evaluate($agent);
            $this->fail('Research plan entered ordinary full validation.');
        } catch (\RuntimeException $error) {
            $this->assertSame('SPECIALIST_COUNCIL_RESEARCH_ONLY_FULL_VALIDATION_FORBIDDEN', $error->getMessage());
        }
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    public function test_council_screen_purpose_cannot_be_forged_in_model_metadata(): void
    {
        [$version, $carrier] = $this->boundPlanFixture();
        $metadata = $carrier->metadata;
        $metadata['specialist_council_evaluation']['plan_hash'] = str_repeat('a', 64);
        $metadata['specialist_council_evaluation']['purpose'] = 'independent';
        $carrier->update(['metadata' => $metadata]);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('DECLARED_COUNCIL_EVALUATION_BINDING_INVALID');
        app(SpecialistCouncilLifecycleService::class)->evaluationPurposeForModel($carrier->fresh());
    }

    public function test_prepared_source_drift_is_rejected_before_original_replay(): void
    {
        [$version, , , $request] = $this->boundPlanFixture(str_repeat('0', 64));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('COUNCIL_PREPARATION_SOURCE_CHANGED_BEFORE_ORIGINAL_REPLAY');
        app(SpecialistCouncilLifecycleService::class)->bindEvaluationRequest($version->fresh(), 'candidate-2025', $request);
    }

    public function test_current_prepared_source_can_bind_original_request(): void
    {
        $currentSource = app(LabImmutableEvidenceService::class)->codeHash();
        [$version, , , $request] = $this->boundPlanFixture($currentSource);
        $bound = app(SpecialistCouncilLifecycleService::class)->bindEvaluationRequest($version->fresh(), 'candidate-2025', $request);
        $this->assertSame($version->id, $bound['specialist_council_evaluation']['version_id']);
    }

    private function boundPlanFixture(?string $preparationSource = null): array
    {
        $version = $this->draft(); $service = app(SpecialistCouncilLifecycleService::class);
        $carrier = $service->attachResearchModel($version, $this->model('carrier'));
        $execution = app(ExecutionContractService::class)->for('XAUUSD', 'M5');
        $rows = [];
        for ($index = 0; $index < 6; $index++) $rows[] = ['time' => \Carbon\CarbonImmutable::parse('2025-01-06T02:00:00Z')->addMinutes(5 * $index)->toIso8601String()];
        $probe = app(\App\Services\ProspectiveRepairProbeWindowService::class)->seal($rows, str_repeat('d', 64), $execution['execution_hash'], 'original-council-plan', 4, 2);
        $plan = $this->plan($version);
        if ($preparationSource !== null) $plan['preparation_source_hash'] = $preparationSource;
        $plan['execution_hash'] = $execution['execution_hash']; $plan['cost_model'] = $execution['parameters'];
        $plan['risk_policy'] = array_diff_key($version->manifest['execution'], ['id' => true, 'version' => true]);
        $plan['risk_policy']['risk_per_trade_percent'] = .5;
        $plan['arms'][0]['model_version_id'] = $carrier->id;
        $plan['windows'][0]['prospective_probe_window'] = $probe;
        $plan['windows'][0]['evaluation_scope'] = ['start_inclusive' => $probe['evaluated_start'],
            'end_exclusive' => '2025-01-06T02:30:00Z', 'rows' => 4, 'decision_rows' => 3, 'warmup_rows' => 2,
            'policy_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($probe)];
        $service->sealEvaluationPlan($version, 'independent-evaluator', $plan);
        $carrier = $service->attachEvaluationArm($version->fresh(), 'candidate-2025', $carrier);
        $request = ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'evaluation_mode' => 'incremental', 'initial_balance' => 500,
            'risk_per_trade' => 1, 'replay_dataset_hash' => str_repeat('d', 64), 'execution' => $execution['parameters'],
            'execution_contract' => $execution, 'specialist_council_contract' => ['policy' => $plan['risk_policy']]];
        return [$version, $carrier, $plan, $request];
    }

    private function model(string $name): ModelVersion
    {
        return ModelVersion::create(['name' => $name, 'strategy' => 'ema_rsi_v1', 'version' => 'v1-'.$name,
            'generation' => 1, 'status' => 'testing', 'parameters' => ['ema_fast' => 4, 'ema_slow' => 10],
            'metadata' => ['base_strategy' => 'ema_rsi']]);
    }

    private function passport(string $role, ModelVersion $model): array
    {
        return ['specialist_id' => $role, 'role' => $role, 'version' => 'v1', 'as_of' => '2025-12-01T00:00:00Z',
            'inputs' => ['as_of_closed_candles'], 'scope' => ['symbols' => ['XAUUSD'], 'contexts' => ['trend']],
            'known_limits' => ['research_unqualified'], 'resources' => ['max_compute_ms' => 100, 'max_memory_mb' => 32, 'max_lookback_bars' => 512],
            'horizon' => ['kind' => $role, 'decision_interval_seconds' => 300, 'reevaluation_interval_seconds' => 300,
                'max_holding_seconds' => $role === 'swing' ? 86400 : 3600, 'execution_precision' => 'candle'],
            'data_requirements' => match ($role) { 'scalp' => ['bid_ask', 'spread', 'slippage', 'quote_age', 'intrabar_ambiguity'],
                'swing' => ['gap', 'carry', 'rollover', 'mature_holding_outcomes'], default => ['sessions', 'costs'] },
            'model_version_id' => $model->id, 'strategy_version' => 'strategy-v1', 'tactic_version' => 'tactic-v1',
            'management_version' => 'management-v1', 'capital_weight' => 0.2, 'risk_per_trade_percent' => 0.5,
            'sensor_timeframes' => ['H4', 'H1', 'M15', 'M5']];
    }

    private function draft(?array $members = null): SpecialistCouncilVersion
    {
        $members ??= [$this->passport('hour', $this->model('hour'))];
        $id = $members[0]['model_version_id'];
        return app(SpecialistCouncilLifecycleService::class)->registerDraft([
            'council_id' => 'council-test', 'version' => '1', 'members' => $members, 'components' => [],
            'routing' => ['id' => 'scope-router', 'version' => '1'], 'allocation' => ['id' => 'common-capital', 'version' => '1'],
            'risk' => ['id' => 'hard-risk', 'version' => '1'], 'execution' => [
                'id' => 'canonical-execution', 'version' => '1', 'broker_position_mode' => 'hedging', 'opposite_position_policy' => 'hedge',
                'max_open_positions' => 8, 'max_reserved_capital_percent' => 100, 'max_gross_exposure_percent' => 100,
                'max_total_risk_percent' => 2, 'max_drawdown_percent' => 10, 'max_daily_loss_percent' => 3, 'max_expected_cost_percent' => 1],
            'evaluation_policy' => ['objective' => 'net_return_at_equal_risk', 'champion_model_version_id' => $id, 'solo_model_version_id' => $id],
        ], 'evolution-owner');
    }

    private function plan(SpecialistCouncilVersion $version): array
    {
        $member = $version->manifest['members'][0];
        return ['purpose' => 'research', 'execution_hash' => str_repeat('e', 64), 'execution_timeframe' => 'M5',
            'initial_capital' => 10000, 'cost_model' => ['commission_percent' => .1], 'risk_policy' => ['max_risk' => 2],
            'windows' => [['window_key' => 'original-2025', 'start_inclusive' => '2025-01-01T00:00:00Z',
                'end_exclusive' => '2025-02-01T00:00:00Z', 'dataset_sha256' => str_repeat('d', 64)]],
            'arms' => [['arm_key' => 'candidate-2025', 'kind' => 'candidate', 'window_key' => 'original-2025', 'model_version_id' => $member['model_version_id']]]];
    }

    private function event(): array
    {
        return ['symbol' => 'XAUUSD', 'event_start' => '2025-01-06T00:00:00Z', 'event_end' => '2025-01-06T00:05:00Z',
            'available_at' => '2025-01-06T00:05:00Z', 'matured_at' => '2025-01-06T00:05:00Z',
            'provenance' => ['provider' => 'original', 'hash' => str_repeat('d', 64)]];
    }
}
