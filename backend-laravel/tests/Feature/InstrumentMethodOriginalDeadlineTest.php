<?php

namespace Tests\Feature;

use App\Models\LabAgent;
use App\Models\LabEvidenceArtifact;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Models\ResearchExperimentWorkItem;
use App\Services\AgentMethodLearningService;
use App\Services\InstrumentMethodResearchBindingService;
use App\Services\InstrumentMethodStudyExecutionService;
use App\Services\InstrumentMethodStudyService;
use App\Services\LabImmutableEvidenceService;
use App\Services\ResearchExperimentConversionKernelService;
use App\Services\ResearchLoopArbiterService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\File;
use Carbon\CarbonImmutable;
use ReflectionProperty;
use Symfony\Component\Process\Process;

/** Inherited fixture is SQLite/software only; no original market receipt is changed. */
class InstrumentMethodOriginalDeadlineTest extends InstrumentMethodStudyExecutionTest
{
    private const KEY = 'synthetic-original-deadline-key-not-production-0123456789';

    public function test_new_php_original_600_request_hmac_selects_real_python_simple_budget_without_full_authority(): void
    {
        [$work, $owner] = $this->newLongWork();
        $queued = $owner->execute($work);
        $this->assertSame('queued', $queued['status'], json_encode($queued));
        $proof = $owner->inspectWork($work->fresh());
        $generation = LabGeneration::findOrFail(data_get($work->fresh()->result, 'original_barrier.generation_id'));
        $agent = $generation->agents()->with('modelVersion')->where('model_version_id', $proof['design']['arms']['L1A1']['model_id'])->sole();
        $request = $owner->compileRequest($generation, $agent, 'L1A1', $proof);
        $marker = $request['policy_context'][InstrumentMethodStudyExecutionService::DEADLINE_MARKER];
        $this->assertSame(600, $marker['resource_caps']['max_runtime_seconds']);
        $this->assertSame(hash('sha256', $marker['contract_json']), $marker['contract_hash']);
        $this->assertFalse($marker['independent_validation']);
        $this->assertFalse($marker['economic_authority']);
        $this->assertIsFloat($request['parameters']['trend_strength_min']);
        $this->assertIsInt($request['parameters']['lookback']);
        $this->assertIsBool($request['parameters']['retest_required']);
        $this->assertSame($request, $owner->compileRequest($generation, $agent, 'L1A1', $proof));
        $script = <<<'PY'
import json,sys
from unittest.mock import patch
from fastapi import HTTPException
from app import main
from app.schemas import SimpleBacktestRequest
p=json.load(sys.stdin)
request=SimpleBacktestRequest.model_validate(p['request'])
from app.services.parameter_schema import validate_strategy_parameters
normalized=validate_strategy_parameters(request.strategy,request.parameters,request.base_strategy)
assert normalized==request.parameters
assert all(type(value) is type(normalized.get(name)) for name,value in request.parameters.items())
selected=[]
def conditional_child(operation,actual):
    main._validate_native_diagnostics_before_cache(actual,operation=operation)
    selected.append(main._bounded_replay_seconds(actual,operation))
    raise HTTPException(status_code=418,detail='software pre-worker deadline proof')
with patch('app.main._internal_api_token',return_value=p['key']):
    with patch('app.main._verified_research_transport',side_effect=AssertionError('must not borrow independent/full transport')):
        assert main._bounded_replay_seconds(request,'simple')==600
    with patch('app.main._run_bounded_replay',side_effect=conditional_child):
        try: main.run_simple_backtest_api(request)
        except HTTPException as error: assert error.status_code==418
assert selected==[600]
print(json.dumps(dict(selected=selected,software_fixture=True,independent_validation=False)))
PY;
        $process = new Process([env('PYTHON_BINARY', 'python'), '-c', $script], dirname(base_path()).'/ai-service-python');
        $process->setInput(json_encode(['request' => $request, 'key' => self::KEY], JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
        $process->setTimeout(30); $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $actual = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame([600], $actual['selected']);
        $this->assertFalse($actual['independent_validation']);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    public function test_original_non_success_http_retains_typed_timeout_before_technical_close_without_raw_detail(): void
    {
        [$work, $owner] = $this->newLongWork();
        $this->assertSame('queued', $owner->execute($work)['status']);
        $queue = data_get($work->fresh()->result, 'queued_arm');
        $body = json_encode(['detail' => 'Bounded AI replay exceeded 600s; strategy verdict withheld.',
            'debug' => 'PRIVATE_TOKEN_MUST_NOT_BE_RECORDED'], JSON_THROW_ON_ERROR);
        Http::fake(['*' => Http::response($body, 504, ['Content-Type' => 'application/json'])]);
        $owner->executeQueuedArm($work->id, $work->lease_token, $work->fence_version,
            $queue['arm'], $queue['request_hash'], $queue['budget']);
        $artifact = LabEvidenceArtifact::where('artifact_type', 'method_study_transport_failure')->sole();
        $facts = app(LabImmutableEvidenceService::class)->readArtifactPayload($artifact);
        $this->assertSame('instrument_method_transport_failure_v1', $facts['protocol']);
        $this->assertSame(504, $facts['http_status']);
        $this->assertSame(hash('sha256', $body), $facts['body_sha256']);
        $this->assertSame(600, $facts['observed_timeout_seconds']);
        $this->assertFalse($facts['deadline_mismatch_observed']);
        $this->assertFalse($facts['raw_body_retained']);
        $this->assertStringNotContainsString('PRIVATE_TOKEN', json_encode($facts));
        $this->assertFalse($facts['economic_authority']);
        $this->assertDatabaseCount('agent_method_experiences', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_transport_timeout_classifier_does_not_retain_arbitrary_detail_or_invent_timeout(): void
    {
        $owner = app(InstrumentMethodStudyExecutionService::class);
        foreach (['{"detail":"secret text"}', '{"detail":"Bounded AI replay exceeded 600s; strategy verdict withheld. secret"}',
            '<html>PRIVATE_TOKEN</html>', str_repeat('x', 8193)] as $body) {
            $facts = $owner->transportFailureEnvelope(504, $body, 600);
            $this->assertNull($facts['observed_timeout_seconds']);
            $this->assertSame('unclassified_non_success_response', $facts['detail_code']);
            $this->assertStringNotContainsString('PRIVATE_TOKEN', json_encode($facts));
            $this->assertSame(hash('sha256', $body), $facts['body_sha256']);
        }
        $body = '{"detail":"Bounded AI replay exceeded 300s; strategy verdict withheld."}';
        $this->assertNull($owner->transportFailureEnvelope(500, $body, 600)['observed_timeout_seconds']);
        $this->assertTrue($owner->transportFailureEnvelope(504, $body, 600)['deadline_mismatch_observed']);
    }

    private function newLongWork(): array
    {
        config()->set('services.internal_api.token', self::KEY);
        $design = (new ReflectionProperty(InstrumentMethodStudyExecutionTest::class, 'design'))->getValue($this);
        $source = (new ReflectionProperty(InstrumentMethodStudyExecutionTest::class, 'source'))->getValue($this);
        $design['study_key'] = 'separate-new-600-software-original-question';
        $design['resource_caps']['max_runtime_seconds'] = 600;
        // Separately generated, SHA-pinned raw-source fixture uses the actual
        // provider CSV vocabulary (quoted bare UTC), without touching the old
        // parent's source or changing the explicit scope/receipt UTC vocabulary.
        $start = CarbonImmutable::parse($design['scope']['evaluated_start'])->subMinutes(200 * 5);
        $csv = "time,open,high,low,close\n";
        for ($index = 0; $index < 512; $index++) {
            $csv .= '"'.$start->addMinutes($index * 5)->format('Y-m-d H:i:s').'",2000,2002,1998,2001' . "\n";
        }
        $path = dirname($source['dataset_path']).'/deadline-raw-utc.csv';
        File::put($path, $csv);
        $source = ['dataset_path' => $path, 'dataset_sha256' => hash('sha256', $csv)];
        $design['data_hash'] = $source['dataset_sha256'];
        // Fresh fixture owners, never mutate the parent's original 120s study.
        foreach ($design['arms'] as $arm => $identity) {
            $old = ModelVersion::findOrFail($identity['model_id']);
            $model = ModelVersion::create(['name' => 'deadline-'.$arm, 'strategy' => $old->strategy, 'version' => 1,
                'status' => 'draft', 'parameters' => $old->parameters,
                'metadata' => array_diff_key((array) $old->metadata, array_flip([InstrumentMethodResearchBindingService::METADATA]))]);
            $oldPolicy = app(AgentMethodLearningService::class)->policy($identity['policy_hash']);
            $policy = app(AgentMethodLearningService::class)->registerPolicy([
                'agent_key' => 'model:'.$model->id, 'revision' => 1, 'recipe_hashes' => $oldPolicy['library_contract']['recipe_hashes'],
                'rules' => $oldPolicy['rules'], 'default_action' => $oldPolicy['default_action']]);
            $this->assertSame('registered_research_only', $policy['status'], json_encode($policy));
            app(InstrumentMethodResearchBindingService::class)->attachToUnusedModel($model->id, $policy['policy_hash']);
            $design['arms'][$arm] = ['model_id' => (int) $model->id, 'policy_hash' => $policy['policy_hash']];
        }
        $study = app(InstrumentMethodStudyService::class)->preregister($design);
        $this->assertSame('preregistered_diagnostic', $study['status'], json_encode($study));
        $owner = app(InstrumentMethodStudyExecutionService::class);
        $registered = $owner->registerWork($study['study_hash'], $source);
        $this->assertSame('original_work_registered', $registered['status'], json_encode($registered));
        $registeredWork = ResearchExperimentWorkItem::findOrFail($registered['work_id']);
        $readiness = $owner->inspectWork($registeredWork);
        $this->assertTrue($readiness['executable'], json_encode($readiness));
        $claimed = app(ResearchExperimentConversionKernelService::class)->claimForOwner(ResearchLoopArbiterService::class, 1);
        $this->assertCount(1, $claimed, json_encode($owner->inspectWork($registeredWork->fresh())));
        return [$claimed[0], $owner];
    }
}
