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

    public function test_curl_connection_reset_is_a_typed_service_transport_failure(): void
    {
        $result = (new TechnicalFailureClassifierService)->classify(
            'cURL error 56: Recv failure: Connection was reset',
            'Illuminate\\Http\\Client\\ConnectionException',
        );

        $this->assertSame(TechnicalFailureClassifierService::TRANSIENT, $result['class']);
        $this->assertSame('AI_SERVICE_UNAVAILABLE', $result['reason_code']);
        $this->assertTrue($result['blocks_global_generation']);
    }

    public function test_database_column_width_mismatch_is_a_typed_runtime_schema_failure(): void
    {
        $result = (new TechnicalFailureClassifierService)->classify(
            "SQLSTATE[22001]: String data, right truncated: 1406 Data too long for column 'status' at row 1",
            'Illuminate\\Database\\QueryException',
        );

        $this->assertSame(TechnicalFailureClassifierService::TRANSIENT, $result['class']);
        $this->assertSame('DATABASE_SCHEMA_WIDTH_MISMATCH', $result['reason_code']);
        $this->assertTrue($result['blocks_global_generation']);
    }
}
