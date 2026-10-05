<?php

namespace Tests\Feature;

use App\Services\TypedInstrumentFoundryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TypedOperatorDecisionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_research_candidate_admission_does_not_fake_academy_or_economic_authority(): void
    {
        $owner = app(TypedInstrumentFoundryService::class);
        $ast = ['op' => 'CONST', 'type' => 'number', 'value' => .5];
        $this->assertSame('blocked', $owner->compile($ast, $this->context(), [])['status']);
        $proposal = $owner->compileResearchCandidate($ast, $this->context(), 'proposal:bounded-risk');
        $this->assertSame('compiled_research_only', $proposal['status']);
        $this->assertFalse($proposal['promotion_evidence']);
        $program = DB::table('research_instrument_programs')->where('program_key', $proposal['program_key'])->first();
        $evidence = json_decode($program->evidence, true);
        $this->assertSame(0, $evidence['gates']['confirmed_cartridges']);
        $this->assertFalse($evidence['gates']['qualification_granted']);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        $operator = $owner->decisionOperatorContract($proposal['program_key'], 'risk_multiplier', [], $this->budget());
        $this->assertSame('native_decision_contract', $operator['status']);
        $this->assertFalse($operator['qualification_granted']);
        $executed = $this->execute($operator['operator'], []);
        $this->assertSame(.5, $executed['output']);
        $this->assertSame(1, $executed['receipt']['calls']);
        $this->assertFalse($executed['receipt']['promotion_evidence']);
    }

    public function test_php_export_survives_actual_numeric_json_transport_and_uses_closed_inputs(): void
    {
        $owner = app(TypedInstrumentFoundryService::class);
        $ast = ['op' => 'GREATER_THAN', 'args' => [
            ['op' => 'PRICE_CLOSE', 'available_at' => '2025-01-01T00:00:00Z'],
            ['op' => 'CONST', 'type' => 'number', 'value' => 1.0],
        ]];
        $program = $owner->compileResearchCandidate($ast, $this->context(), 'proposal:entry-threshold');
        $contract = $owner->decisionOperatorContract($program['program_key'], 'confirmation', ['price_close' => 'close'], $this->budget());
        $this->assertSame('native_decision_contract', $contract['status']);
        $this->assertArrayNotHasKey('input_vectors', $contract['operator']);
        $this->assertArrayNotHasKey('expected_outputs', $contract['operator']);
        // Symfony receives the same default JSON encoding used by Guzzle:
        // 1.0 becomes 1 outside the exact sealed string copy.
        $this->assertTrue($this->execute($contract['operator'], ['close' => 2.0])['output']);
        $this->assertFalse($this->execute($contract['operator'], ['close' => .5])['output']);
        $wrong = $contract['operator']; $wrong['ast']['args'][1]['value'] = 9;
        $this->assertNativeRejection($wrong, ['close' => 10], 'DECISION_OPERATOR_JSON_COPY_MISMATCH');
    }

    public function test_unbound_future_output_and_size_increase_cannot_be_exported_or_executed(): void
    {
        $owner = app(TypedInstrumentFoundryService::class);
        $ast = ['op' => 'PRICE_CLOSE', 'available_at' => '2025-01-01T00:00:00Z'];
        $p = $owner->compileResearchCandidate($ast, $this->context(), 'proposal:bad-binding');
        $this->assertSame('DECISION_OPERATOR_TYPED_PROGRAM_IDENTITY_INVALID',
            $owner->decisionOperatorContract($p['program_key'], 'risk_multiplier', [], $this->budget())['reason']);
        $ast = ['op' => 'GREATER_THAN', 'args' => [$ast, ['op' => 'CONST', 'type' => 'number', 'value' => 1]]];
        $p = $owner->compileResearchCandidate($ast, $this->context(), 'proposal:bad-binding-boolean');
        $this->assertSame('DECISION_OPERATOR_UNBOUND_INPUT',
            $owner->decisionOperatorContract($p['program_key'], 'confirmation', [], $this->budget())['reason']);
        $this->assertSame('DECISION_OPERATOR_INPUT_BINDING_INVALID',
            $owner->decisionOperatorContract($p['program_key'], 'confirmation', ['price_close' => 'future_return'], $this->budget())['reason']);
        $p = $owner->compileResearchCandidate(['op' => 'CONST', 'type' => 'number', 'value' => 1.1], $this->context(), 'proposal:increase');
        $operator = $owner->decisionOperatorContract($p['program_key'], 'risk_multiplier', [], $this->budget());
        $this->assertNativeRejection($operator['operator'], [], 'DECISION_OPERATOR_RISK_INCREASE_FORBIDDEN');
    }

    private function context(): array
    {
        return ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'pre_2026_only' => true,
            'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64)];
    }

    private function budget(): array
    {
        return ['cpu_seconds' => .5, 'max_calls' => 100, 'max_node_evaluations' => 4800];
    }

    private function execute(array $operator, array $row): array
    {
        $source = 'import json,sys; from app.services.research_program_tasks import BoundedDecisionProgram; p=json.load(sys.stdin); engine=BoundedDecisionProgram(p["operator"]); output=engine.evaluate(p["row"],decision_at="2025-01-02T00:00:00Z",observed_at="2025-01-01T23:55:00Z"); print(json.dumps({"output":output,"receipt":engine.receipt()}))';
        $process = new Process(['python', '-c', $source], dirname(base_path()).'/ai-service-python');
        $process->setTimeout(10); $process->setInput(json_encode(['operator' => $operator, 'row' => $row]));
        $process->mustRun();
        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function assertNativeRejection(array $operator, array $row, string $reason): void
    {
        try { $this->execute($operator, $row); $this->fail('Malformed operator was executed.'); }
        catch (\Symfony\Component\Process\Exception\ProcessFailedException $error) {
            $this->assertStringContainsString($reason, $error->getProcess()->getErrorOutput());
        }
    }
}
