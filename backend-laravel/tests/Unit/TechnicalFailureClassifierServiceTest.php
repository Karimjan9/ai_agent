<?php

namespace Tests\Unit;

use App\Services\TechnicalFailureClassifierService;
use PHPUnit\Framework\TestCase;

class TechnicalFailureClassifierServiceTest extends TestCase
{
    public function test_canonical_positive_gap_gate_is_a_data_dependency_not_a_transport_retry(): void
    {
        foreach (['Historical data hard-gate failed: 1 unexpected candle gaps.',
            'RuntimeException {"detail":"Historical data hard-gate failed: 12 unexpected candle gaps."}'] as $message) {
            $result = (new TechnicalFailureClassifierService)->classify($message);
            $this->assertSame(TechnicalFailureClassifierService::CAPABILITY, $result['class']);
            $this->assertSame('IMMUTABLE_HISTORICAL_CANDLE_GAP', $result['reason_code']);
            $this->assertFalse($result['blocks_global_generation']);
            $this->assertTrue($result['same_evidence_replay_forbidden']);
            $this->assertFalse($result['scientific_question_budget_reset']);
            $this->assertSame('withheld', $result['strategy_verdict']);
        }
        foreach (['Historical data hard-gate failed: 0 unexpected candle gaps.',
            'Historical data hard-gate failed: data_quality rejected.', 'unexpected candle gaps',
            'Historical data hard-gate failed: -1 unexpected candle gaps.'] as $message) {
            $result = (new TechnicalFailureClassifierService)->classify($message);
            $this->assertSame(TechnicalFailureClassifierService::TRANSIENT, $result['class']);
            $this->assertSame('UNCLASSIFIED_TRANSIENT', $result['reason_code']);
            $this->assertTrue($result['blocks_global_generation']);
        }
    }

    public function test_a_manual_draft_integrity_label_alone_does_not_satisfy_dispatch_attestation(): void
    {
        $result = (new TechnicalFailureClassifierService)->classify(
            'Draft identity/integrity contract failed; child quarantined before screening. Strategy verdict withheld.',
        );
        $this->assertSame(TechnicalFailureClassifierService::TRANSIENT, $result['class']);
        $this->assertSame('UNCLASSIFIED_TRANSIENT', $result['reason_code']);
        $this->assertTrue($result['blocks_global_generation']);
    }

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

    public function test_causal_fold_budget_timeout_uses_the_same_bounded_transport_recovery_lane(): void
    {
        $result = (new TechnicalFailureClassifierService)->classify(
            'RuntimeException {"detail":"TimeoutError: causal confirmation fold 6 exceeded its 180s budget; remaining folds were not executed and no learning credit was emitted."}',
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

    public function test_missing_autonomous_mtf_bundle_is_terminal_construction_history(): void
    {
        $result = (new TechnicalFailureClassifierService)->classify(
            'RuntimeException AUTONOMOUS_MTF_BUNDLE_MISSING',
        );

        $this->assertSame(TechnicalFailureClassifierService::TERMINAL, $result['class']);
        $this->assertSame('IMMUTABLE_MTF_ADMISSION_CONTRACT_MISSING', $result['reason_code']);
        $this->assertFalse($result['blocks_global_generation']);
        $this->assertSame('TERMINAL_DIAGNOSTIC', $result['action']);
    }

    public function test_composition_authority_mismatch_is_terminal_not_retried_as_transport(): void
    {
        $result = (new TechnicalFailureClassifierService)->classify(
            'COMPOSITION_INSTRUMENT_NOT_BOUND',
            'App\\Services\\CompositionRuntimeContractError',
        );

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
