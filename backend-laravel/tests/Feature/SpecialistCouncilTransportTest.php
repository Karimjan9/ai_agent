<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\LabAgentEvaluationService;
use App\Services\SpecialistCouncilLifecycleService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Real Laravel serialization / Python schema boundary; not economic evidence. */
class SpecialistCouncilTransportTest extends TestCase
{
    use RefreshDatabase;

    private function sourceAgent(array $metadata = []): LabAgent
    {
        $lab = AiLaboratory::firstOrCreate(['symbol' => 'XAUUSD', 'timeframe' => 'H1'], ['name' => 'native transport',
            'strategy_families' => ['trend'], 'is_active' => true,
            'lifecycle_mode' => 'lighthouse']);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => $lab->generations()->count() + 1,
            'trigger_type' => 'test', 'trigger_context' => [], 'population_size' => 1, 'status' => 'draft']);
        $model = ModelVersion::create(['name' => 'transport source '.$generation->id, 'strategy' => 'trend_retest_v1',
            'version' => 'v1', 'generation' => 1, 'status' => 'testing',
            'parameters' => app(StrategyParameterSchemaService::class)->defaults('trend'),
            'metadata' => ['base_strategy' => 'trend_retest_v1', ...$metadata], 'evidence_status' => 'valid']);

        return LabAgent::withoutEvents(fn () => LabAgent::create(['lab_generation_id' => $generation->id,
            'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'trend', 'origin' => 'test', 'lifecycle_status' => 'draft', 'parameter_diff' => []]));
    }

    private function payload(LabAgent $agent): array
    {
        return (new \ReflectionMethod(LabAgentEvaluationService::class, 'screeningStrategyPayload'))
            ->invoke(app(LabAgentEvaluationService::class), $agent->fresh('modelVersion'), 'M5', null, str_repeat('d', 64));
    }

    private function parse(array $strategies): array
    {
        $source = <<<'PY'
import json,sys
from pydantic import ValidationError
from app.schemas import SimpleBacktestRequest
try:
    p=SimpleBacktestRequest.model_validate(json.load(sys.stdin))
    print(json.dumps({'valid':True,'contracts':[s.specialist_council_contract for s in p.strategies]}))
except ValidationError as e:
    print(json.dumps({'valid':False,'errors':[{'loc':list(x['loc']),'type':x['type']} for x in e.errors()]}))
PY;
        $process = new Process(['python', '-c', $source], dirname(base_path()).'/ai-service-python');
        $process->setTimeout(30);
        $process->setInput(json_encode(['symbol' => 'XAUUSD', 'timeframe' => 'M5',
            'dataset_path' => 'schema-only-not-executed.csv', 'strategies' => $strategies], JSON_THROW_ON_ERROR));
        $process->mustRun();

        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_real_unbound_source_payload_omits_council_and_parses_as_ordinary_strategy(): void
    {
        $payload = $this->payload($this->sourceAgent());
        $this->assertArrayNotHasKey('specialist_council_contract', $payload);
        $parsed = $this->parse([$payload]);
        $this->assertTrue($parsed['valid']);
        $this->assertSame([[]], $parsed['contracts']);
    }

    public function test_declared_native_object_is_preserved_in_a_mixed_transport_not_cast_or_dropped(): void
    {
        $plain = $this->payload($this->sourceAgent());
        // This case exercises serialization only. Native runtime/hash admission
        // remains in the owner's existing real producer integration tests.
        $native = ['protocol' => 'specialist_council_runtime_v1', 'contract_hash' => str_repeat('a', 64),
            'members' => [['specialist_id' => 'sealed-hour', 'model_version_id' => 123]]];
        $this->mock(SpecialistCouncilLifecycleService::class, function ($mock) use ($native): void {
            $mock->shouldReceive('evaluationBindingForModel')->once()->andReturn(null);
            $mock->shouldReceive('runtimeContractForModel')->once()->andReturn($native);
        });
        $bound = $this->payload($this->sourceAgent());
        $this->assertSame($native, $bound['specialist_council_contract']);
        $parsed = $this->parse([$plain, $bound]);
        $this->assertTrue($parsed['valid']);
        $this->assertSame([[], $native], $parsed['contracts']);
    }

    public function test_explicit_null_list_or_scalar_council_stays_rejected_by_strict_python_schema(): void
    {
        $payload = $this->payload($this->sourceAgent());
        foreach ([null, [], 'council'] as $invalid) {
            $parsed = $this->parse([[...$payload, 'specialist_council_contract' => $invalid]]);
            $this->assertFalse($parsed['valid']);
            $this->assertSame(['loc' => ['strategies', 0, 'specialist_council_contract'], 'type' => 'dict_type'],
                $parsed['errors'][0]);
        }
    }

    public function test_declared_invalid_native_binding_is_rejected_before_payload_fallback(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('DECLARED_SPECIALIST_COUNCIL_BINDING_INVALID');
        $this->payload($this->sourceAgent(['specialist_council' => ['version_id' => 99999, 'manifest_hash' => str_repeat('a', 64)]]));
    }
}
