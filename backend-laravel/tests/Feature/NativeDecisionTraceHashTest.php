<?php

namespace Tests\Feature;

use App\Services\LabImmutableEvidenceService;
use App\Services\ResearchPaperEpochContractService;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Serialization-only tests: no strategy, native replay, database or source publication. */
class NativeDecisionTraceHashTest extends TestCase
{
    public function test_real_python_wire_float_and_empty_container_hash_survives_exact_http_encoding(): void
    {
        $process = new Process(['python', '-B', '-c', <<<'PY'
import json
from app.services.specialist_council import _receipt_json_value
from app.services.research_program_tasks import canonical_hash
trace = _receipt_json_value([{'price': 2000.0, 'state': {'cash_at_open': 10000.0, 'equity_at_open': 10000.0,
    'reserved_capital_at_open': 0.0}, 'small': 1e-8, 'large': 1e18, 'negative_zero': -0.0,
    'member_decisions': [{'stage_receipts': [{'detail': {}}]}]}])
print(json.dumps({'decision_trace': trace, 'producer_hash': canonical_hash(trace)}, separators=(',', ':')))
PY], dirname(base_path()).'/ai-service-python');
        $process->setTimeout(30); $process->mustRun();
        $wire = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsFloat($wire['decision_trace'][0]['price']);
        $this->assertSame([], $wire['decision_trace'][0]['member_decisions'][0]['stage_receipts'][0]['detail']);
        $response = $this->response($wire['decision_trace'], $wire['producer_hash']);
        $owner = app(LabImmutableEvidenceService::class);
        $this->assertTrue($owner->nativeDecisionTraceHashValid($response, $response['specialist_council_receipt']));

        $exactWire = json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $exact = json_decode($exactWire, true, 512, JSON_THROW_ON_ERROR);
        $this->assertTrue($owner->nativeDecisionTraceHashValid($exact, $exact['specialist_council_receipt']));
        $lossy = json_decode(json_encode($response, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsInt($lossy['decision_trace'][0]['price']);
        $this->assertFalse($owner->nativeDecisionTraceHashValid($lossy, $lossy['specialist_council_receipt']));
        // Agreeing stale hash copies do not authorize a changed actual trace.
        $exact['decision_trace'][0]['state']['cash_at_open'] += 1;
        $this->assertFalse($owner->nativeDecisionTraceHashValid($exact, $exact['specialist_council_receipt']));
    }

    public function test_native_hash_requires_matching_original_identity_and_preserves_legacy_hash_algorithm(): void
    {
        $trace = [['price' => 1.0, 'state' => [], 'member_decisions' => []]];
        $hash = app(ResearchPaperEpochContractService::class)->parameterHash($trace);
        $response = $this->response($trace, $hash); $owner = app(LabImmutableEvidenceService::class);
        $this->assertTrue($owner->nativeDecisionTraceHashValid($response, $response['specialist_council_receipt']));
        foreach (['trace_hash', 'contract_hash', 'protocol'] as $field) {
            $tampered = $response;
            $tampered['specialist_council_receipt']['decision_trace_identity'][$field] = 'wrong';
            $this->assertFalse($owner->nativeDecisionTraceHashValid($tampered, $tampered['specialist_council_receipt']));
        }
        $response['data_quality']['decision_trace']['trace_hash'] = str_repeat('0', 64);
        $this->assertFalse($owner->nativeDecisionTraceHashValid($response, $response['specialist_council_receipt']));
        $this->assertSame($hash, app(ResearchPaperEpochContractService::class)->parameterHash($trace));
    }

    private function response(array $trace, string $hash): array
    {
        $native = ['contract_hash' => str_repeat('a', 64), 'decision_trace_identity' => [
            'protocol' => 'native_council_decision_trace_v1', 'contract_hash' => str_repeat('a', 64), 'trace_hash' => $hash]];
        return ['decision_trace' => $trace, 'specialist_council_receipt' => $native,
            'data_quality' => ['decision_trace' => ['scope_owner' => 'native_specialist_council_v1', 'trace_hash' => $hash]]];
    }
}
