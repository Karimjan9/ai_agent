<?php

namespace Tests\Feature;

use App\Models\LabGeneration;
use App\Models\ResearchLoopDecision;
use App\Services\AcademyExperimentMaterializerService;
use App\Services\LabAgentEvaluationService;
use App\Services\LabInstrumentResearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AcademyPrimaryInstrumentSurfaceTest extends TestCase
{
    use RefreshDatabase;

    private function cohort(): LabGeneration
    {
        require_once base_path('tests/Feature/AcademyColdStartHandoffTest.php');
        $helper = new \ReflectionMethod(AcademyColdStartHandoffTest::class, 'sourceAndData');
        [, $baseline] = $helper->invoke(new AcademyColdStartHandoffTest('test_old_passport_and_parameters_create_only_a_bounded_fresh_prospective_question'));
        $source = json_decode(file_get_contents(base_path('tests/Support/academy_confirmation_source.json')), true, 512, JSON_THROW_ON_ERROR);
        $metadata = $source['metadata'];
        // Source 2382's real declared semantic group, not an invented wider
        // scope. Role/architecture labels are retained; canonical context is
        // still trend_up/normal with no declared direction requirement.
        $metadata['semantic_group'] = ['protocol' => 'strategy_semantic_group_v1',
            'key' => 'XAUUSD|H1|confirmation_entry_mtf|edge_trend_pullback_compiled_fb2a4eca36_specialist|trend_up|normal|-',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'confirmation_entry_mtf',
            'role' => 'edge_trend_pullback_compiled_fb2a4eca36_specialist', 'regime' => 'trend_up',
            'volatility' => 'normal', 'direction' => '-', 'architecture' => 'edge_trend_pullback_compiled_fb2a4eca36',
            'declared' => true, 'promotion_evidence' => false];
        $baseline->update(['parameters' => $source['parameters'], 'metadata' => $metadata]);
        $owner = app(AcademyExperimentMaterializerService::class);
        $proposal = $owner->proposal();
        $this->assertSame('would_prepare_cold_start', $proposal['status'], json_encode($proposal));
        $decision = ResearchLoopDecision::create(['decision_key' => 'academy-common-surface', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'action' => 'OPEN_ACADEMY_EXPERIMENT', 'priority' => 81, 'command' => 'trading:admit-academy-experiment',
            'arguments' => ['trial' => 0], 'status' => 'running', 'evidence_hash' => str_repeat('f', 64),
            'reason_codes' => [], 'contract' => [], 'evidence_snapshot' => ['academy_proposal' => $proposal], 'selected_at' => now()]);
        $prepared = $owner->prepareColdStart($decision);
        $this->assertSame('pending_canonical_admission', $prepared['status'], json_encode($prepared));
        $generation = LabGeneration::findOrFail($prepared['generation_id']);
        $this->assertSame(20, $generation->agents()->count());
        $this->assertSame(4, $generation->agents()->where('origin', 'academy_experiment')->count());
        return $generation;
    }

    public function test_real_twenty_seat_materialization_has_one_exact_common_primary_surface_and_python_preflight(): void
    {
        $generation = $this->cohort();
        $assignments = [];
        $identity = data_get($generation->trigger_context, 'prospective_source_identity');
        $manifest = [...$identity['mtf_bundle_manifest'], 'bundle_hash' => $identity['mtf_bundle_hash']];
        foreach ($generation->agents()->with('modelVersion')->where('origin', 'academy_experiment')->get() as $agent) {
            $role = data_get($agent->modelVersion->metadata, 'academy_experiment.arm_role');
            $this->assertSame($role === 'candidate' ? ['setup_topology_policy'] : [], array_keys($agent->parameter_diff));
            $assignment = app(LabInstrumentResearchService::class)->assignment($agent);
            $this->assertSame('assigned', $assignment['status'], json_encode($assignment['pair_reservation']));
            $this->assertSame('reserved', data_get($assignment, 'pair_reservation.status'));
            $this->assertSame('setup_topology_policy', $assignment['sealed_treatment_gene']);
            $this->assertSame($role, $assignment['experiment_role']);
            $this->assertFalse(data_get($assignment, 'pair_reservation.isolated_instrument_credit'));
            $this->assertArrayNotHasKey('pair_key', $assignment['pair_reservation']);
            $this->assertSame(['adaptive_entry_topology', 'atr_risk_envelope', 'cost_aware_exit'], $assignment['selected_keys']);
            foreach ($assignment['selected'] as $selected) {
                $this->assertSame('academy_stage_observation_only_no_isolated_instrument_credit', $selected['learning_authority']);
            }
            $assignments[] = $assignment;
            $agent = $agent->fresh('modelVersion');
            $contract = (new \ReflectionMethod(LabAgentEvaluationService::class, 'compositionRuntimeContract'))->invoke(
                app(LabAgentEvaluationService::class), $agent, $assignment, 'M5',
                ['bundle_hash' => $identity['mtf_bundle_hash'], 'manifest' => $manifest], $identity['mtf_bundle_hash']);
            $process = new Process([PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3', '-c', <<<'PYTHON'
import json, sys
import importlib.util, os
stage = os.environ.get('ACADEMY_INSTRUMENT_RUNTIME_STAGE')
if stage:
    spec=importlib.util.spec_from_file_location('app.services.composition_runtime',stage)
    module=importlib.util.module_from_spec(spec); sys.modules[spec.name]=module; spec.loader.exec_module(module)
from app.services.composition_runtime import validate_composition_runtime_contract
p=json.load(sys.stdin)
validate_composition_runtime_contract(p['contract'], base_strategy='confirmation_entry_mtf_v1', parameters=p['parameters'], execution_timeframe='M5', runtime_authority=p['authority'])
from app.services.backtester import _instrument_contract_context_matches
for item in p['authority']['instrument_assignment']['selected']:
    assert _instrument_contract_context_matches(item['activation_contract'], {'regime':'trend_up','volatility':'normal','direction':'BUY'})
    assert not _instrument_contract_context_matches(item['activation_contract'], {'regime':'trend_down','volatility':'normal','direction':'BUY'})
print('strict-common-surface')
PYTHON
            ], base_path('../ai-service-python'), ['ACADEMY_INSTRUMENT_RUNTIME_STAGE' => getenv('ACADEMY_INSTRUMENT_RUNTIME_STAGE') ?: null]);
            $process->setInput(json_encode(['contract' => $contract, 'parameters' => $agent->modelVersion->parameters,
                'authority' => ['symbol' => 'XAUUSD', 'execution_timeframe' => 'M5', 'replay_dataset_hash' => $identity['mtf_bundle_hash'],
                    'execution_hash' => $identity['execution_hash'], 'instrument_assignment' => $assignment,
                    'mtf_snapshot_manifest' => $manifest]], JSON_THROW_ON_ERROR));
            $process->setTimeout(30)->run();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            $this->assertSame('strict-common-surface', trim($process->getOutput()));
        }
        $control = collect($assignments)->firstWhere('experiment_role', 'frozen_control');
        foreach ($assignments as $assignment) {
            $this->assertSame($control['instrument_key_role_hash'], $assignment['instrument_key_role_hash']);
            $this->assertSame($control['activation_context_hash'], $assignment['activation_context_hash']);
            $this->assertSame(data_get($control, 'pair_reservation.roster_hash'), data_get($assignment, 'pair_reservation.roster_hash'));
            $this->assertSame($control['lab_agent_id'], data_get($assignment, 'pair_reservation.control_agent_id'));
            $this->assertSame(array_column($control['selected'], 'activation_contract'), array_column($assignment['selected'], 'activation_contract'));
        }
        $this->assertSame(0, DB::table('instrument_value_posteriors')->count());
        $this->assertSame(0, DB::table('lab_evolution_credit_events')->count());
    }

    public function test_cached_assignment_cannot_hide_a_deleted_exact_frozen_control(): void
    {
        $generation = $this->cohort();
        $peers = $generation->agents()->with('modelVersion')->where('origin', 'academy_experiment')->get();
        $candidate = $peers->first(fn ($a) => data_get($a->modelVersion->metadata, 'academy_experiment.arm_role') === 'candidate');
        $control = $peers->first(fn ($a) => data_get($a->modelVersion->metadata, 'academy_experiment.arm_role') === 'frozen_control');
        $owner = app(LabInstrumentResearchService::class);
        $this->assertSame('assigned', $owner->assignment($candidate)['status']);
        $control->delete();
        $result = $owner->assignment($candidate);
        $this->assertSame('blocked_exact_pair_reservation_missing', $result['status']);
        $this->assertSame([], $result['selected']);
        $this->assertSame('ACADEMY_PRIMARY_COMPLETE_ROSTER_REQUIRED', data_get($result, 'pair_reservation.reason_code'));
    }

    public function test_required_blocked_reservation_cannot_be_rehashed_into_an_accepted_replay(): void
    {
        $generation = $this->cohort();
        $peers = $generation->agents()->with('modelVersion')->where('origin', 'academy_experiment')->get();
        $candidate = $peers->first(fn ($a) => data_get($a->modelVersion->metadata, 'academy_experiment.arm_role') === 'candidate');
        $control = $peers->first(fn ($a) => data_get($a->modelVersion->metadata, 'academy_experiment.arm_role') === 'frozen_control');
        $owner = app(LabInstrumentResearchService::class);
        $valid = $owner->assignment($candidate);
        $this->assertSame('assigned', $valid['status']);
        $control->delete();
        $blocked = $owner->assignment($candidate);
        $identity = data_get($generation->trigger_context, 'prospective_source_identity');
        $manifest = [...$identity['mtf_bundle_manifest'], 'bundle_hash' => $identity['mtf_bundle_hash']];
        $contract = (new \ReflectionMethod(LabAgentEvaluationService::class, 'compositionRuntimeContract'))->invoke(
            app(LabAgentEvaluationService::class), $candidate->fresh('modelVersion'), $blocked, 'M5',
            ['bundle_hash' => $identity['mtf_bundle_hash'], 'manifest' => $manifest], $identity['mtf_bundle_hash']);
        $this->assertFalse(data_get($contract, 'execution_authority.instrument.bound'));
        $process = new Process([PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3', '-c', <<<'PYTHON'
import json,sys,os,copy,importlib.util
stage=os.environ.get('ACADEMY_INSTRUMENT_RUNTIME_STAGE')
if stage:
    spec=importlib.util.spec_from_file_location('app.services.composition_runtime',stage)
    module=importlib.util.module_from_spec(spec);sys.modules[spec.name]=module;spec.loader.exec_module(module)
from app.services.composition_runtime import validate_composition_runtime_contract, CompositionRuntimeContractError, _hash
p=json.load(sys.stdin)
for mutate in ('missing','status_alias','false_required','string_required','null_required','selected_empty'):
    assignment=copy.deepcopy(p['valid'] if mutate!='missing' else p['blocked'])
    if mutate=='status_alias': assignment['status']='reserved'
    if mutate=='false_required': assignment['pair_reservation']['required']=False
    if mutate=='string_required': assignment['pair_reservation']['required']='true'
    if mutate=='null_required': assignment['pair_reservation']['required']=None
    if mutate=='selected_empty': assignment['selected']=[];assignment['selected_keys']=[]
    assignment.pop('assignment_hash',None);assignment['assignment_hash']=_hash(assignment)
    contract=copy.deepcopy(p['contract']);binding=contract['execution_authority']['instrument']
    binding['bound']=True;binding['assignment_hash']=assignment['assignment_hash'];binding['selected_keys']=assignment['selected_keys']
    contract.pop('contract_hash',None);contract['contract_hash']=_hash(contract)
    authority=copy.deepcopy(p['authority']);authority['instrument_assignment']=assignment
    try: validate_composition_runtime_contract(contract,base_strategy='confirmation_entry_mtf_v1',parameters=p['parameters'],execution_timeframe='M5',runtime_authority=authority)
    except CompositionRuntimeContractError as error: assert str(error)=='COMPOSITION_INSTRUMENT_NOT_BOUND', str(error)
    else: raise AssertionError('accepted '+mutate)
print('rehash-cannot-authorize')
PYTHON
        ], base_path('../ai-service-python'), ['ACADEMY_INSTRUMENT_RUNTIME_STAGE' => getenv('ACADEMY_INSTRUMENT_RUNTIME_STAGE') ?: null]);
        $process->setInput(json_encode(['contract' => $contract, 'valid' => $valid, 'blocked' => $blocked,
            'parameters' => $candidate->modelVersion->parameters, 'authority' => ['symbol' => 'XAUUSD', 'execution_timeframe' => 'M5',
                'replay_dataset_hash' => $identity['mtf_bundle_hash'], 'execution_hash' => $identity['execution_hash'],
                'mtf_snapshot_manifest' => $manifest]], JSON_THROW_ON_ERROR));
        $process->setTimeout(30)->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        $this->assertSame('rehash-cannot-authorize', trim($process->getOutput()));
    }

    public function test_cached_assignment_cannot_hide_changed_peer_parameters_or_stale_compiler_hash(): void
    {
        $generation = $this->cohort();
        $peers = $generation->agents()->with('modelVersion')->where('origin', 'academy_experiment')->get();
        $control = $peers->first(fn ($a) => data_get($a->modelVersion->metadata, 'academy_experiment.arm_role') === 'frozen_control');
        $peer = $peers->first(fn ($a) => data_get($a->modelVersion->metadata, 'academy_experiment.arm_role') === 'candidate');
        $owner = app(LabInstrumentResearchService::class);
        $this->assertSame('assigned', $owner->assignment($control)['status']);
        $original = $peer->modelVersion->parameters;
        $peer->modelVersion->update(['parameters' => [...$original, 'risk_per_trade' => .02]]);
        $this->assertSame('blocked_exact_pair_reservation_missing', $owner->assignment($control)['status']);
        $peer->modelVersion->update(['parameters' => $original]);
        $this->assertSame('assigned', $owner->assignment($control)['status']);
        $meta = $peer->modelVersion->metadata;
        data_set($meta, 'academy_experiment.contract_hash', str_repeat('a', 64));
        $peer->modelVersion->update(['metadata' => $meta]);
        $this->assertSame('blocked_exact_pair_reservation_missing', $owner->assignment($control)['status']);
    }

    public function test_cached_assignment_cannot_hide_arm_role_diff_or_policy_context_poison(): void
    {
        $generation = $this->cohort();
        $peers = $generation->agents()->with('modelVersion')->where('origin', 'academy_experiment')->get();
        $control = $peers->first(fn ($a) => data_get($a->modelVersion->metadata, 'academy_experiment.arm_role') === 'frozen_control');
        $peer = $peers->first(fn ($a) => data_get($a->modelVersion->metadata, 'academy_experiment.arm_role') === 'candidate');
        $owner = app(LabInstrumentResearchService::class);
        $this->assertSame('assigned', $owner->assignment($control)['status']);
        $diff = $peer->parameter_diff;
        $peer->update(['parameter_diff' => []]);
        $this->assertSame('blocked_exact_pair_reservation_missing', $owner->assignment($control)['status']);
        $peer->update(['parameter_diff' => $diff]);
        $meta = $peer->modelVersion->metadata;
        data_set($meta, 'semantic_group.regime', 'trend_down');
        $peer->modelVersion->update(['metadata' => $meta]);
        $this->assertSame('blocked_exact_pair_reservation_missing', $owner->assignment($control)['status']);
    }

    public function test_cached_assignment_rechecks_each_peers_declared_frozen_runtime_identity(): void
    {
        $generation = $this->cohort();
        $peers = $generation->agents()->with('modelVersion')->where('origin', 'academy_experiment')->get();
        $control = $peers->first(fn ($a) => data_get($a->modelVersion->metadata, 'academy_experiment.arm_role') === 'frozen_control');
        $peer = $peers->first(fn ($a) => data_get($a->modelVersion->metadata, 'academy_experiment.arm_role') === 'candidate');
        $owner = app(LabInstrumentResearchService::class);
        $original = $peer->modelVersion->metadata;
        foreach (['data_hash', 'execution_hash', 'source_evaluator_hash', 'python_source_hash', 'source_identity_protocol'] as $key) {
            $this->assertSame('assigned', $owner->assignment($control)['status']);
            $metadata = $original;
            data_set($metadata, 'academy_experiment.'.$key, $key === 'source_identity_protocol' ? 'untyped_alias' : str_repeat('9', 64));
            $peer->modelVersion->update(['metadata' => $metadata]);
            $result = $owner->assignment($control);
            $this->assertSame('blocked_exact_pair_reservation_missing', $result['status']);
            $this->assertSame('ACADEMY_PRIMARY_FROZEN_RUNTIME_IDENTITY_CHANGED', data_get($result, 'pair_reservation.reason_code'));
            $peer->modelVersion->update(['metadata' => $original]);
        }
    }
}
