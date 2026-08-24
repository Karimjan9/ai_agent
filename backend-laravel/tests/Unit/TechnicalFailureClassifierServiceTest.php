<?php

namespace Tests\Unit;

use App\Services\TechnicalFailureClassifierService;
use PHPUnit\Framework\TestCase;

class TechnicalFailureClassifierServiceTest extends TestCase
{
    public function test_curl_operation_timeout_is_a_typed_recoverable_replay_timeout(): void
    {
        $result = (new TechnicalFailureClassifierService)->classify(
            'cURL error 28: Operation timed out after 900001 milliseconds with 0 bytes received',
            'Illuminate\\Http\\Client\\ConnectionException',
        );

        $this->assertSame(TechnicalFailureClassifierService::TRANSIENT, $result['class']);
        $this->assertSame('REPLAY_TRANSPORT_TIMEOUT', $result['reason_code']);
        $this->assertTrue($result['blocks_global_generation']);
    }

    public function test_constructor_failure_remains_terminal_and_non_replayable(): void
    {
        $result = (new TechnicalFailureClassifierService)->classify('ONE_GENE_INVARIANT_FAILED constructor contract');

        $this->assertSame(TechnicalFailureClassifierService::TERMINAL, $result['class']);
        $this->assertSame('IMMUTABLE_EXPERIMENT_TERMINAL', $result['reason_code']);
        $this->assertFalse($result['blocks_global_generation']);
    }
}
