<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Models\ResearchExperimentReceipt;
use App\Models\ResearchExperimentWorkItem;
use App\Services\ExecutionContractService;
use App\Services\SpecialistCouncilContractService;
use App\Services\SpecialistCouncilIndependentPanelService;
use App\Services\SpecialistCouncilLifecycleService;
use App\Services\SpecialistCouncilPanelReservationService;
use App\Services\StrategyParameterSchemaService;
use App\Services\TypedInstrumentFoundryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Legal projection/native consumer tests, explicitly NOT a qualified market-parent fixture. */
class SpecialistCouncilDescendantProducerTest extends TestCase
{
    use RefreshDatabase;

    public function test_typed_public_registration_refuses_unqualified_original_and_caller_owned_results(): void
    {
        [$parent, $work, $input, $original] = $this->fixture();
        $owner = app(SpecialistCouncilPanelReservationService::class);
        foreach ([['results' => []], ['window_generation_ids' => [1, 2, 3]], ['target_version_id' => 999], ['qualified' => true]] as $forged) {
            try { $owner->register($work, [...$input, ...$forged], 'test', $parent, $original); $this->fail('Caller ownership/result claims were accepted.'); }
            catch (\LogicException $error) { $this->assertSame('COUNCIL_PANEL_SERVER_WINDOW_RESERVATION_REQUIRED', $error->getMessage()); }
        }
        try { $owner->register($work, $input, 'test', $parent, $original); $this->fail('An unqualified original became a descendant parent.'); }
        catch (\LogicException $error) { $this->assertSame('COUNCIL_DESCENDANT_ORIGINAL_QUALIFIED_PARENT_REQUIRED', $error->getMessage()); }
        $this->assertNull(data_get($work->fresh()->payload, 'pending_panel_intent'));
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_legal_projection_changes_only_one_declared_original_member_gene(): void
    {
        [$parent] = $this->fixture();
        $before = $parent->manifest;
        $specs = app(SpecialistCouncilIndependentPanelService::class)->projectDescendantSources($parent, 'bounded-risk', ['hour' => ['ema_fast' => 5]]);
        $this->assertCount(4, $specs);
        foreach ($specs as $role => $spec) {
            $member = collect($before['members'])->firstWhere('role', $role);
            $expected = $member['parameters']; if ($role === 'hour') $expected['ema_fast'] = 5;
            $this->assertSame($expected, $spec['parameters']);
            $this->assertSame($member['model_version_id'], $spec['model_version_id']);
            $this->assertCount($role === 'hour' ? 1 : 0, $spec['parameter_deltas']);
        }
        $this->assertSame($before, $parent->fresh()->manifest);
        $this->assertFalse(app(SpecialistCouncilLifecycleService::class)->qualifiedOriginalResearchProof($parent)['allowed']);
    }

    public function test_noop_unknown_risk_and_renormalized_interventions_are_refused(): void
    {
        [$parent] = $this->fixture();
        $owner = app(SpecialistCouncilIndependentPanelService::class);
        foreach ([[], ['hour' => ['ema_fast' => 4]], ['hour' => ['minimum_signal_confidence' => 0.0]], ['hour' => ['unknown_gene' => 1]],
            ['hour' => ['ema_fast' => 10]], ['hour' => ['stop_loss_percent' => 1.5]],
            ['hour' => ['ema_fast' => 5], 'day' => ['ema_fast' => 6], 'swing' => ['ema_fast' => 7]]] as $delta) {
            try { $owner->projectDescendantSources($parent, 'bounded-risk', $delta); $this->fail('Illegal/no-effect intervention was accepted.'); }
            catch (\Throwable $error) { $this->assertNotEmpty($error->getMessage()); }
        }
        $this->expectExceptionMessage('COUNCIL_DESCENDANT_EXACT_QUALIFIED_EXECUTABLE_TRAIT_REQUIRED');
        $owner->projectDescendantSources($parent, 'invented-trait', ['hour' => ['ema_fast' => 5]]);
    }

    public function test_server_program_projection_preserves_roster_cost_risk_and_exact_trait_removal(): void
    {
        [$parent, , , , $first, $body, $manifest] = $this->fixture();
        $programs = (new \ReflectionMethod(SpecialistCouncilPanelReservationService::class, 'prepareDescendantVersions'))
            ->invoke(app(SpecialistCouncilPanelReservationService::class), $body, $manifest, $first);
        $p = \App\Models\SpecialistCouncilVersion::findOrFail($programs['p_version_id']);
        $pt = \App\Models\SpecialistCouncilVersion::findOrFail($programs['pt_version_id']);
        $ptu = \App\Models\SpecialistCouncilVersion::findOrFail($programs['ptu_version_id']);
        $this->assertCount(4, $p->manifest['members']); $this->assertCount(4, $ptu->manifest['members']);
        $this->assertSame([], $p->manifest['components']);
        $this->assertSame($parent->manifest['components'], $pt->manifest['components']);
        $this->assertSame($parent->manifest['components'], $ptu->manifest['components']);
        foreach (['routing', 'allocation', 'risk', 'execution'] as $field) {
            $this->assertSame($parent->manifest[$field], $p->manifest[$field]);
            $this->assertSame($parent->manifest[$field], $ptu->manifest[$field]);
        }
        foreach ($ptu->manifest['members'] as $member) {
            $old = collect($parent->manifest['members'])->firstWhere('role', $member['role']);
            $this->assertNotSame($old['model_version_id'], $member['model_version_id']);
            $this->assertSame($old['specialist_id'], $member['specialist_id']);
            $this->assertSame($old['horizon'], $member['horizon']);
            $expected = $old['parameters']; if ($member['role'] === 'hour') $expected['ema_fast'] = 5;
            $this->assertSame($expected, $member['parameters']);
        }
        $this->assertCount(5, $ptu->manifest['evaluation_policy']['required_ablations']);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertFalse(app(SpecialistCouncilLifecycleService::class)->qualifiedOriginalResearchProof($ptu)['allowed']);
    }

    public function test_four_derived_programs_cross_the_actual_native_consumer_without_qualification(): void
    {
        [$parent, , , , $first, $body, $manifest] = $this->fixture();
        $service = app(SpecialistCouncilLifecycleService::class);
        $programs = (new \ReflectionMethod(SpecialistCouncilPanelReservationService::class, 'prepareDescendantVersions'))
            ->invoke(app(SpecialistCouncilPanelReservationService::class), $body, $manifest, $first);
        $versions = ['p' => \App\Models\SpecialistCouncilVersion::findOrFail($programs['p_version_id']),
            'pt' => \App\Models\SpecialistCouncilVersion::findOrFail($programs['pt_version_id']),
            'ptu' => \App\Models\SpecialistCouncilVersion::findOrFail($programs['ptu_version_id'])];
        $versions['pu'] = $versions['ptu']; // actual sealed T ablation, not a relabelled fourth version
        $path = tempnam(sys_get_temp_dir(), 'descendant-native-');
        $this->assertNotFalse($path);
        try {
            $file = fopen($path, 'wb'); fputcsv($file, ['time', 'open', 'high', 'low', 'close', 'volume', 'volume_available'], ',', '"', '');
            $start = new \DateTimeImmutable('2025-01-06T02:00:00Z');
            for ($i = 0; $i < 110; $i++) {
                $price = 2000 + 10 * sin($i / 12) + $i * .025;
                fputcsv($file, [$start->modify('+'.$i.' hours')->format('Y-m-d\TH:i:s\Z'), $price, $price + 10, $price - 10, $price, 100, 1], ',', '"', '');
            }
            fclose($file); $hash = hash_file('sha256', $path); $execution = app(ExecutionContractService::class)->for('XAUUSD', 'H1');
            $requests = []; $carriers = [];
            foreach ($versions as $key => $version) {
                $carrier = match ($key) { 'p' => ModelVersion::findOrFail($first['solo']->model_version_id),
                    'pt' => ModelVersion::findOrFail($first['champion']->model_version_id), default => $this->model('consumer-'.$key) };
                $carriers[$key] = $service->attachResearchModel($version, $carrier);
            }
            $policy = ['protocol' => 'specialist_council_original_full_source_v1', 'evaluation_mode' => 'full',
                'selection' => 'entire_authorized_source', 'maximum_source_rows' => 200000, 'maximum_runtime_seconds' => 600,
                'warmup_rows' => 0, 'no_walk_forward_selection' => true, 'promotion_evidence' => false];
            $scope = ['start_inclusive' => $start->format('Y-m-d\TH:i:s\Z'), 'end_exclusive' => $start->modify('+110 hours')->format('Y-m-d\TH:i:s\Z'),
                'rows' => 110, 'decision_rows' => 109, 'warmup_rows' => 0, 'policy_hash' => app(\App\Services\ResearchPaperEpochContractService::class)->parameterHash($policy)];
            $arms = [];
            foreach (['p' => 'solo', 'pt' => 'champion', 'ptu' => 'candidate', 'pu' => 'ablation'] as $key => $kind) $arms[] = [
                'arm_key' => $key, 'kind' => $kind, 'window_key' => 'small-native-projection', 'model_version_id' => $carriers[$key]->id,
                ...($key === 'pu' ? ['removed_id' => 'bounded-risk'] : [])];
            $service->sealEvaluationPlan($versions['ptu'], 'small-native-examiner', ['purpose' => 'research', 'evaluation_phase' => 'full_validation',
                'execution_timeframe' => 'H1', 'execution_hash' => $execution['execution_hash'], 'initial_capital' => 10000,
                'cost_model' => $execution['parameters'], 'risk_policy' => [...$parent->manifest['execution'], 'risk_per_trade_percent' => .5],
                'full_replay_runtime_policy' => $policy, 'windows' => [['window_key' => 'small-native-projection',
                    'start_inclusive' => $scope['start_inclusive'], 'end_exclusive' => $scope['end_exclusive'], 'dataset_sha256' => $hash, 'evaluation_scope' => $scope]],
                'arms' => $arms]);
            foreach ($carriers as $key => $carrier) $carriers[$key] = $service->attachEvaluationArm($versions['ptu']->fresh(), $key, $carrier);
            foreach ($versions as $key => $version) {
                $runtime = $key === 'pu' ? $service->runtimeContractForAblation($version->fresh(), 'bounded-risk', $hash, $execution['execution_hash'], 'H1', null, 'XAUUSD')
                    : $service->runtimeContract($version->fresh(), $hash, $execution['execution_hash'], 'H1', null, 'XAUUSD');
                $requests[$key] = ['strategy' => $carriers[$key]->strategy, 'base_strategy' => 'ema_rsi', 'version' => $carriers[$key]->version,
                    'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'initial_balance' => 10000.0, 'dataset_path' => $path,
                    'replay_dataset_hash' => $hash, 'execution' => $execution['parameters'], 'execution_contract' => $execution,
                    'specialist_council_contract' => $runtime];
            }
            $source = <<<'PY'
import json, sys
from app.schemas import SimpleBacktestRequest
from app.services.backtester import run_simple_ema_rsi_backtest
print(json.dumps({key: run_simple_ema_rsi_backtest(SimpleBacktestRequest.model_validate(request)).model_dump(mode='json') for key, request in json.load(sys.stdin).items()}))
PY;
            $process = new Process(['python', '-c', $source], dirname(base_path()).'/ai-service-python');
            $process->setTimeout(120); $process->setInput(json_encode($requests, JSON_THROW_ON_ERROR)); $process->mustRun();
            $results = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            foreach ($requests as $key => $request) {
                $receipt = $service->attestReplayResult($carriers[$key], $request, $results[$key]);
                $this->assertSame('computed', $receipt['status']); $this->assertCount(4, $receipt['members']);
                $this->assertGreaterThan(0, $receipt['members'][0]['stages']['decision:observed']);
                $this->assertNotEmpty($receipt['account_ledger']); $this->assertFalse($receipt['scientific_evidence']);
                $this->assertFalse($receipt['promotion_evidence']);
                if ($key === 'p') {
                    // Corrupt the actual producer result, retaining a valid
                    // object hash, to prove member coverage is independently
                    // enforced rather than trusting a receipt status flag.
                    $missing = array_diff_key($receipt, ['receipt_hash' => true, 'receipt_json' => true]);
                    array_pop($missing['members']);
                    $missing['receipt_hash'] = app(\App\Services\ResearchPaperEpochContractService::class)->parameterHash($missing);
                    $bad = $results[$key]; $bad['specialist_council_receipt'] = $missing;
                    $bad['data_quality']['specialist_council_receipt'] = $missing;
                    try { $service->attestReplayResult($carriers[$key], $request, $bad); $this->fail('Missing actual dispatched member was accepted.'); }
                    catch (\LogicException $error) { $this->assertSame('COUNCIL_MEMBER_DISPATCH_COVERAGE_MISMATCH', $error->getMessage()); }
                }
                $this->assertCount(in_array($key, ['pt', 'ptu']) ? 1 : 0, $request['specialist_council_contract']['component_identities']);
                if ($key === 'pu') $this->assertSame('bounded-risk', $request['specialist_council_contract']['ablation_removed_id']);
                if (in_array($key, ['pt', 'ptu'])) {
                    $operator = collect($receipt['members'])->firstWhere('role', 'hour')['operator_receipt'];
                    $this->assertGreaterThan(0, $operator['calls'], json_encode($receipt['members']));
                    $this->assertGreaterThan(0, $operator['behavior_delta_decisions']);
                }
            }
            $this->assertNotSame($requests['pt']['specialist_council_contract']['contract_hash'], $requests['ptu']['specialist_council_contract']['contract_hash']);
            $this->assertNotSame($requests['p']['specialist_council_contract']['contract_hash'], $requests['pu']['specialist_council_contract']['contract_hash']);
            $this->assertFalse($service->qualifiedOriginalResearchProof($parent)['allowed']);
            $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        } finally { if (is_string($path) && is_file($path)) unlink($path); }
    }

    /** Pure comparator semantics: no live lease/server reservation/qualification is claimed. */
    public function test_descendant_comparator_rebind_preserves_every_other_carrier_basis_and_exact_p_pt(): void
    {
        [$parent, , , , $first, $body, $manifest] = $this->fixture();
        $owner = app(SpecialistCouncilPanelReservationService::class); $lifecycle = app(SpecialistCouncilLifecycleService::class);
        $programs = (new \ReflectionMethod($owner, 'prepareDescendantVersions'))->invoke($owner, $body, $manifest, $first);
        $source = $lifecycle->attachResearchModel($parent, $this->model('original-carrier'));
        $reservationHash = hash('sha256', 'pure comparator semantics not server reservation');
        $lab = AiLaboratory::create(['name' => 'unqualified comparator semantic fixture', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_families' => ['ema_rsi']]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'status' => 'draft',
            'trigger_context' => ['specialist_council_authorized_panel' => ['reservation_hash' => $reservationHash, 'window_key' => 'semantic-window']]]);
        $roots = [];
        foreach (['solo' => 'p_version_id', 'champion' => 'pt_version_id'] as $kind => $field) {
            $model = $lifecycle->attachResearchModel(\App\Models\SpecialistCouncilVersion::findOrFail($programs[$field]), ModelVersion::findOrFail($first[$kind]->model_version_id));
            $model->update(['metadata' => [...$model->metadata, 'authorized_specialist_council_panel_seed' => ['arm_key' => $kind]]]);
            LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id, 'symbol' => 'XAUUSD',
                'timeframe' => 'H1', 'strategy_family' => 'ema_rsi', 'origin' => 'pure_semantic_fixture', 'lifecycle_status' => 'draft']);
            $roots[] = ['kind' => $kind, 'arm_key' => $kind, 'source_model_version_id' => $source->id,
                'source_model_hash' => app(SpecialistCouncilContractService::class)->modelHash($source)];
        }
        $body = [...$body, 'reservation_hash' => $reservationHash, 'arm_roots' => $roots];
        $plan = ['panel_reservation_hash' => $reservationHash, 'descendant_programs' => $programs];
        $check = new \ReflectionMethod($owner, 'assertComparatorProgram');
        foreach (['solo', 'champion'] as $kind) {
            $model = ModelVersion::findOrFail($first[$kind]->model_version_id);
            $arm = ['kind' => $kind, 'window_key' => 'semantic-window'];
            $check->invoke($owner, $body, $plan, $arm, $model); $this->assertTrue(true);
            $originalMetadata = $model->metadata;
            foreach (['risk_governor' => ['changed' => true], 'specialist_council_membership' => ['contextual_cell' => ['changed' => true]],
                'instrument_research_assignment' => ['changed' => true], 'smart_composition' => ['composition_passport' => ['changed' => true]]] as $field => $changed) {
                $model->update(['metadata' => [...$originalMetadata, $field => $changed]]);
                try { $check->invoke($owner, $body, $plan, $arm, $model->fresh()); $this->fail('A non-council carrier basis changed under semantic rebinding.'); }
                catch (\LogicException $error) { $this->assertSame('COUNCIL_DESCENDANT_ATTESTED_P_PT_COMPARATOR_PROGRAM_CHANGED', $error->getMessage()); }
            }
            $model->update(['metadata' => $originalMetadata]);
            $wrong = $lifecycle->researchVersionForModel($model)->manifest; $wrong['execution']['max_open_positions'] = 7;
            $wrong['version'] = 'counterfeit-'.$kind; unset($wrong['manifest_hash']);
            $wrongVersion = $lifecycle->registerDraft($wrong, 'counterfeit-fixture-creator');
            $metadata = $originalMetadata; unset($metadata['specialist_council']); $model->update(['metadata' => $metadata]);
            $model = $lifecycle->attachResearchModel($wrongVersion, $model->fresh());
            try { $check->invoke($owner, $body, $plan, $arm, $model); $this->fail('An altered P/PT native program was accepted.'); }
            catch (\LogicException $error) { $this->assertSame('COUNCIL_DESCENDANT_ATTESTED_P_PT_COMPARATOR_PROGRAM_CHANGED', $error->getMessage()); }
        }
        $this->assertDatabaseCount('lab_evaluation_runs', 0); $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    private function fixture(): array
    {
        config(['services.internal_api.token' => str_repeat('test-descendant-key-', 3), 'services.execution_contract.allowed_sessions_utc' => []]);
        $execution = app(ExecutionContractService::class)->for('XAUUSD', 'M5');
        $foundry = app(TypedInstrumentFoundryService::class);
        $program = $foundry->compileResearchCandidate(['op' => 'CONST', 'type' => 'number', 'value' => .5], [
            'pre_2026_only' => true, 'data_hash' => str_repeat('a', 64), 'execution_hash' => $execution['execution_hash'],
            'symbol' => 'XAUUSD', 'timeframe' => 'H1'], 'descendant-projection-test');
        $operator = $foundry->decisionOperatorContract($program['program_key'], 'risk_multiplier', [],
            ['cpu_seconds' => .5, 'max_calls' => 10000, 'max_node_evaluations' => 10000])['operator'];
        $op = array_diff_key($operator, ['contract_hash' => true, 'contract_json' => true]); $op['component_id'] = 'bounded-risk';
        $operator = [...$op, 'contract_hash' => app(\App\Services\ResearchPaperEpochContractService::class)->parameterHash($op),
            'contract_json' => json_encode($op, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)];
        $members = [];
        foreach (['scalp', 'hour', 'day', 'swing'] as $role) {
            $source = $this->model('source-'.$role);
            $members[] = ['specialist_id' => $role.'-owner', 'role' => $role, 'version' => '1', 'as_of' => '2024-12-31T00:00:00Z',
                'inputs' => ['as_of_closed_candles'], 'scope' => ['symbols' => ['XAUUSD'], 'contexts' => ['any']],
                'known_limits' => ['pure_projection_fixture_not_market_qualification'],
                'resources' => ['max_compute_ms' => 100, 'max_memory_mb' => 32, 'max_lookback_bars' => 512],
                'horizon' => ['kind' => $role, 'decision_interval_seconds' => 3600, 'reevaluation_interval_seconds' => 3600,
                    'max_holding_seconds' => $role === 'swing' ? 259200 : 10800, 'execution_precision' => 'candle'],
                'data_requirements' => match ($role) { 'scalp' => ['bid_ask', 'spread', 'slippage', 'quote_age', 'intrabar_ambiguity'],
                    'swing' => ['gap', 'carry', 'rollover', 'mature_holding_outcomes'], default => ['sessions', 'costs'] },
                'model_version_id' => $source->id, 'strategy_version' => 's1', 'tactic_version' => 't1', 'management_version' => 'm1',
                'capital_weight' => .2, 'risk_per_trade_percent' => .1, 'sensor_timeframes' => ['H4', 'H1', 'M15', 'M5'],
                ...($role === 'hour' ? ['operator_contract' => $operator] : [])];
        }
        $parent = app(SpecialistCouncilLifecycleService::class)->registerDraft(['council_id' => 'descendant-projection', 'version' => 'parent',
            'members' => $members, 'components' => [['id' => 'bounded-risk', 'version' => '1', 'role' => 'risk', 'input_type' => 'as_of_observation',
                'output_type' => 'risk_multiplier', 'consumer_roles' => ['hour'], 'as_of_only' => true, 'max_compute_ms' => 100]],
            'routing' => ['id' => 'router', 'version' => '1'], 'allocation' => ['id' => 'allocator', 'version' => '1'], 'risk' => ['id' => 'risk', 'version' => '1'],
            'execution' => ['id' => 'execution', 'version' => '1', 'broker_position_mode' => 'hedging', 'opposite_position_policy' => 'hedge',
                'max_open_positions' => 8, 'max_reserved_capital_percent' => 100, 'max_gross_exposure_percent' => 100,
                'max_total_risk_percent' => 2, 'max_drawdown_percent' => 10, 'max_daily_loss_percent' => 3, 'max_expected_cost_percent' => 1],
            'evaluation_policy' => ['objective' => 'net_return_at_equal_risk', 'champion_model_version_id' => $members[1]['model_version_id'],
                'solo_model_version_id' => $members[1]['model_version_id']]], 'original-creator');
        $original = ['manifest_hash' => $parent->manifest_hash, 'execution_timeframe' => 'M5', 'execution_hash' => $execution['execution_hash'],
            'cost_model' => $execution['parameters'], 'risk_policy' => [...$parent->manifest['execution'], 'risk_per_trade_percent' => .5],
            'arms' => [['kind' => 'candidate', 'model_version_id' => $members[1]['model_version_id']],
                ['kind' => 'ablation', 'removed_id' => 'bounded-risk', 'model_version_id' => $members[1]['model_version_id']]]];
        $parent->update(['assessment_hash' => str_repeat('a', 64)]);
        $receipt = ResearchExperimentReceipt::create(['receipt_key' => 'descendant-projection-receipt', 'source_type' => 'pure_projection_fixture',
            'source_id' => $parent->id, 'symbol' => 'XAUUSD', 'laboratory_timeframe' => 'H1', 'execution_timeframe' => 'M5', 'contract_version' => 'fixture',
            'rule_version' => 'fixture', 'contract_hash' => str_repeat('a', 64), 'evidence_hash' => str_repeat('b', 64), 'classification' => 'data_missing', 'payload' => []]);
        $work = ResearchExperimentWorkItem::create(['work_key' => 'descendant-projection-work', 'work_type' => 'specialist_council_descendant_transfer',
            'research_experiment_receipt_id' => $receipt->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'status' => 'blocked', 'payload' => []]);
        $input = ['protocol' => SpecialistCouncilPanelReservationService::PROTOCOL, 'authorization_ids' => ['a', 'b', 'c'],
            'creator_id' => 'descendant-creator', 'evaluator_id' => 'independent-evaluator', 'research_question' => 'Does original T transfer under one bounded U?',
            'trait_component_id' => 'bounded-risk', 'parameter_deltas' => ['hour' => ['ema_fast' => 5]]];
        $specs = app(SpecialistCouncilIndependentPanelService::class)->projectDescendantSources($parent, 'bounded-risk', $input['parameter_deltas']);
        $first = [];
        foreach ($specs as $role => $spec) $first['member:'.$role] = (object) ['modelVersion' => $this->model('derived-'.$role, $spec['parameters']),
            'model_version_id' => ModelVersion::latest('id')->value('id')];
        foreach (['champion', 'solo'] as $kind) $first[$kind] = (object) ['model_version_id' => $this->model('carrier-'.$kind)->id];
        $body = ['source_version_id' => $parent->id, 'source_assessment_hash' => $parent->assessment_hash, 'creator_id' => 'projection-test-creator',
            'original_plan' => $original, 'descendant_programs' => ['protocol' => 'specialist_council_descendant_programs_v1',
                'trait_component_id' => 'bounded-risk', 'member_sources' => $specs]];
        $manifest = $parent->manifest; $manifest['version'] = 'derived-test'; unset($manifest['manifest_hash']);
        $manifest['evaluation_policy']['champion_model_version_id'] = $first['champion']->model_version_id;
        $manifest['evaluation_policy']['solo_model_version_id'] = $first['solo']->model_version_id;
        return [$parent->fresh(), $work, $input, $original, $first, $body, $manifest];
    }

    private function model(string $name, ?array $parameters = null): ModelVersion
    {
        $schemas = app(StrategyParameterSchemaService::class);
        $base = array_intersect_key($schemas->defaults('ema_rsi'), $schemas->schema('ema_rsi'));
        $base = [...$base, 'ema_fast' => 4, 'ema_slow' => 10, 'rsi_period' => 4, 'rsi_buy_min' => 1, 'rsi_buy_max' => 99, 'rsi_sell_min' => 1, 'rsi_sell_max' => 99,
            'minimum_signal_confidence' => 0.0];
        return ModelVersion::create(['name' => 'descendant-'.$name, 'strategy' => 'ema_rsi_v1', 'version' => 'v1', 'status' => 'testing',
            'parameters' => $parameters ?? $schemas->validate('ema_rsi_v1', $base), 'metadata' => ['base_strategy' => 'ema_rsi', 'strategy_architecture' => 'ema_rsi']]);
    }
}
