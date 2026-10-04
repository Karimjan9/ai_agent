<?php

namespace Tests\Unit;

use App\Services\CausalStageMasteryDirectorService;
use Tests\TestCase;

class CausalStageMasteryDirectorServiceTest extends TestCase
{
    public function test_declared_topology_must_change_its_owned_transition_without_disturbing_upstream(): void
    {
        $service = app(CausalStageMasteryDirectorService::class);
        $control = ['value' => 'balanced_retest_reaction', 'entry_contract_funnel' => ['stage_counts' => [
            'opportunity' => 10, 'location' => 8, 'setup' => 6, 'confirmation' => 4, 'trigger' => 1, 'entry_ready' => 1,
        ]], 'total_trades' => 1];
        $candidate = ['value' => 'aggressive_structure_close', 'entry_contract_funnel' => ['stage_counts' => [
            'opportunity' => 10, 'location' => 8, 'setup' => 6, 'confirmation' => 4, 'trigger' => 3, 'entry_ready' => 2,
        ]], 'total_trades' => 2];
        $control['data_quality']['decision_identity_receipt'] = $this->receipt();
        $candidate['data_quality']['decision_identity_receipt'] = $this->receipt(['trigger' => 'candidate-trigger', 'entry' => 'candidate-entry', 'closed_trade' => 'candidate-close']);

        $assessment = $service->assess('trigger_topology_policy', $control, $candidate);

        $this->assertSame('controllable', $assessment['status']);
        $this->assertSame('CONFIRMATION_TO_TRIGGER', data_get($assessment, 'owner.transition'));
        $this->assertTrue(data_get($assessment, 'checks.upstream_identity_preserved'));
        $this->assertSame(7, data_get($assessment, 'lexicographic_fitness.funnel_depth'));
        $this->assertFalse($assessment['promotion_evidence']);
    }

    public function test_noop_or_multi_axis_intervention_cannot_receive_causal_credit(): void
    {
        $service = app(CausalStageMasteryDirectorService::class);
        $axis = $service->inferAxis(['entry_mode' => 'balanced'], ['entry_mode' => 'aggressive', 'entry_model' => 'breakout_retest']);
        $noop = $service->assess('trigger_topology_policy', ['value' => 'balanced'], ['value' => 'aggressive']);

        $this->assertSame('not_assessable', $axis['status']);
        $this->assertSame('MULTI_AXIS_INTERVENTION', $axis['reason']);
        $this->assertSame('non_controlling_axis', $noop['status']);
        $this->assertFalse(data_get($noop, 'checks.target_transition_changed'));
    }

    public function test_equal_counts_with_different_upstream_candle_ids_do_not_prove_identity(): void
    {
        $control = ['value' => 'balanced', 'data_quality' => ['decision_identity_receipt' => $this->receipt()]];
        $candidate = ['value' => 'aggressive', 'data_quality' => ['decision_identity_receipt' => $this->receipt(['setup' => 'other-setup', 'trigger' => 'other-trigger'])]];
        $result = app(CausalStageMasteryDirectorService::class)->assess('trigger_topology_policy', $control, $candidate);
        $this->assertSame('non_controlling_axis', $result['status']);
        $this->assertTrue(data_get($result, 'checks.decision_identity_valid'));
        $this->assertFalse(data_get($result, 'checks.upstream_identity_preserved'));
    }

    public function test_setup_runtime_declares_its_multi_stage_footprint_but_never_changes_input_identity(): void
    {
        $control = ['value' => 'pullback_rejection', 'data_quality' => ['decision_identity_receipt' => $this->receipt()]];
        $candidate = ['value' => 'breakout_and_retest', 'data_quality' => ['decision_identity_receipt' => $this->receipt(['context' => 'new-context', 'location' => 'new-location', 'setup' => 'new-setup'])]];
        $service = app(CausalStageMasteryDirectorService::class);
        $result = $service->assess('setup_topology_policy', $control, $candidate);
        $this->assertSame('controllable', $result['status']);
        $this->assertSame('CONTEXT_LOCATION_TO_SETUP', data_get($result, 'owner.transition'));
        $this->assertFalse(data_get($result, 'owner.exclusive_single_transition_claim'));
        $this->assertSame(['opportunity'], $result['preserved_upstream_stages']);
        $candidate['data_quality']['decision_identity_receipt'] = $this->receipt(['opportunity' => 'different-domain', 'setup' => 'new-setup']);
        $this->assertSame('non_controlling_axis', $service->assess('setup_topology_policy', $control, $candidate)['status']);
    }

    public function test_missing_or_tampered_receipt_cannot_grant_research_scaffold(): void
    {
        $control = ['value' => 'balanced', 'data_quality' => ['decision_identity_receipt' => $this->receipt()]];
        $candidate = ['value' => 'aggressive', 'data_quality' => ['decision_identity_receipt' => $this->receipt(['trigger' => 'other-trigger'])]];
        $candidate['data_quality']['decision_identity_receipt']['candle_domain']['event_count'] = 999;
        $service = app(CausalStageMasteryDirectorService::class);
        $result = $service->assess('trigger_topology_policy', $control, $candidate);
        $this->assertFalse(data_get($result, 'checks.decision_identity_valid'));
        $this->assertFalse($service->scaffold($result)['next_baseline_authority']);
    }

    public function test_management_semantic_exit_change_is_controlling_with_identical_entries_and_counts(): void
    {
        $control = ['value' => 2.0, 'total_trades' => 3, 'data_quality' => ['decision_identity_receipt' => $this->receipt()]];
        $candidate = ['value' => 2.5, 'total_trades' => 3, 'data_quality' => ['decision_identity_receipt' => $this->receipt(['closed_trade' => 'different_exit_cost_and_pnl'])]];
        $result = app(CausalStageMasteryDirectorService::class)->assess('trailing_atr_multiplier', $control, $candidate);
        $this->assertSame('controllable', $result['status']);
        $this->assertSame(0, $result['event_delta']);
        $this->assertTrue($result['semantic_effect_observed']);
        $this->assertTrue($result['evidence_assessable']);
        $this->assertSame('SEMANTIC_EFFECT_OBSERVED', $result['reason']);
    }

    public function test_unchanged_semantics_are_an_assessable_no_effect_not_missing_evidence(): void
    {
        $control = ['value' => 2.0, 'data_quality' => ['decision_identity_receipt' => $this->receipt()]];
        $candidate = ['value' => 2.5, 'data_quality' => ['decision_identity_receipt' => $this->receipt()]];
        $result = app(CausalStageMasteryDirectorService::class)->assess('trailing_atr_multiplier', $control, $candidate);
        $this->assertSame('non_controlling_axis', $result['status']);
        $this->assertSame('NO_OBSERVED_SEMANTIC_EFFECT', $result['reason']);
        $this->assertTrue($result['evidence_assessable']);
    }

    public function test_actual_context_input_change_refuses_comparison_even_with_same_declared_bundle(): void
    {
        $control = ['value' => 2.0, 'data_quality' => ['decision_identity_receipt' => $this->receipt()]];
        $receipt = $this->receipt(['closed_trade' => 'different_exit']);
        $receipt['dependency_identity']['streams']['H1']['consumed_data_hash'] = hash('sha256', 'other_actual_H1_rows');
        $receipt['dependency_identity'] = $this->seal($receipt['dependency_identity']);
        $receipt['bindings']['dependency_receipt_hash'] = $receipt['dependency_identity']['receipt_hash'];
        $receipt = $this->seal($receipt);
        $result = app(CausalStageMasteryDirectorService::class)->assess('trailing_atr_multiplier', $control,
            ['value' => 2.5, 'data_quality' => ['decision_identity_receipt' => $receipt]]);
        $this->assertSame('DECISION_IDENTITY_INCOMPLETE', $result['reason']);
        $this->assertFalse($result['evidence_assessable']);
        $this->assertFalse(data_get($result, 'checks.decision_identity_valid'));
    }

    public function test_legacy_or_missing_dependency_receipts_remain_diagnostic_not_retirement_evidence(): void
    {
        foreach (['legacy', 'missing_dependency', 'missing_context', 'unattested_evaluator', 'missing_semantics'] as $kind) {
            $receipt = $this->receipt(['closed_trade' => 'different_exit']);
            if ($kind === 'legacy') $receipt['protocol'] = 'replay_decision_identity_v1';
            if ($kind === 'missing_dependency') unset($receipt['dependency_identity']);
            if ($kind === 'missing_context') unset($receipt['dependency_identity']['streams']['H1']);
            if ($kind === 'unattested_evaluator') $receipt['bindings']['source_evaluator_attested'] = false;
            if ($kind === 'missing_semantics') unset($receipt['stage_identities']['closed_trade']['semantic_schema']);
            if (isset($receipt['dependency_identity'])) {
                $receipt['dependency_identity'] = $this->seal($receipt['dependency_identity']);
                $receipt['bindings']['dependency_receipt_hash'] = $receipt['dependency_identity']['receipt_hash'];
            }
            $result = app(CausalStageMasteryDirectorService::class)->assess('trailing_atr_multiplier',
                ['value' => 2.0, 'data_quality' => ['decision_identity_receipt' => $this->receipt()]],
                ['value' => 2.5, 'data_quality' => ['decision_identity_receipt' => $this->seal($receipt)]]);
            $this->assertSame('non_controlling_axis', $result['status'], $kind);
            $this->assertSame('DECISION_IDENTITY_INCOMPLETE', $result['reason'], $kind);
            $this->assertFalse($result['evidence_assessable'], $kind);
        }
    }

    public function test_rehashed_omission_of_required_m5_context_is_not_mastery(): void
    {
        $receipt = $this->receipt();
        unset($receipt['dependency_identity']['streams']['H1']);
        $receipt['dependency_identity']['required_streams'] = ['H4', 'M15', 'M5'];
        $receipt['dependency_identity'] = $this->seal($receipt['dependency_identity']);
        $receipt['bindings']['dependency_receipt_hash'] = $receipt['dependency_identity']['receipt_hash'];
        $receipt = $this->seal($receipt);
        $result = app(CausalStageMasteryDirectorService::class)->assess('location_tolerance_atr',
            ['value' => 1.0, 'data_quality' => ['decision_identity_receipt' => $receipt]],
            ['value' => 1.1, 'data_quality' => ['decision_identity_receipt' => $receipt]]);
        $this->assertSame('DECISION_IDENTITY_INCOMPLETE', $result['reason']);
        $this->assertFalse($result['evidence_assessable']);
    }

    public function test_dual_runtime_source_scopes_cannot_alias_or_cross_bind(): void
    {
        $director = app(CausalStageMasteryDirectorService::class);
        foreach (['python_source_hash', 'full_runtime_source_hash'] as $field) {
            $control = $this->receipt();
            $candidate = $this->receipt(['location' => 'candidate-location']);
            $candidate['bindings'][$field] = str_repeat('e', 64);
            if ($field === 'python_source_hash') $candidate['bindings']['source_evaluator_hash'] = str_repeat('e', 64);
            $candidate = $this->seal($candidate);
            $assessment = $director->assess('location_tolerance_atr',
                ['value' => 1.0, 'data_quality' => ['decision_identity_receipt' => $control]],
                ['value' => 1.1, 'data_quality' => ['decision_identity_receipt' => $candidate]]);
            $this->assertFalse($assessment['evidence_assessable'], $field);
            $this->assertSame('DECISION_IDENTITY_INCOMPLETE', $assessment['reason'], $field);
        }
    }

    public function test_missing_dual_fields_or_legacy_python_alias_is_not_upgraded(): void
    {
        foreach (['missing_aggregate', 'missing_python', 'python_alias'] as $case) {
            $receipt = $this->receipt();
            if ($case === 'missing_aggregate') unset($receipt['bindings']['full_runtime_source_hash']);
            if ($case === 'missing_python') unset($receipt['bindings']['python_source_hash']);
            if ($case === 'python_alias') $receipt['bindings']['source_evaluator_hash'] = str_repeat('f', 64);
            $receipt = $this->seal($receipt);
            $assessment = app(CausalStageMasteryDirectorService::class)->assess('location_tolerance_atr',
                ['value' => 1.0, 'data_quality' => ['decision_identity_receipt' => $receipt]],
                ['value' => 1.1, 'data_quality' => ['decision_identity_receipt' => $receipt]]);
            $this->assertFalse($assessment['evidence_assessable'], $case);
        }
    }

    private function receipt(array $changed = []): array
    {
        $stages = [];
        foreach (['opportunity', 'context', 'location', 'setup', 'confirmation', 'trigger', 'entry', 'closed_trade', 'raw_signal'] as $stage) {
            $stages[$stage] = ['status' => 'observed', 'event_count' => 3, 'event_hash' => hash('sha256', $changed[$stage] ?? $stage),
                'semantic_schema' => match ($stage) { 'entry' => 'filled_entry_v2', 'closed_trade' => 'entry_linked_close_v2', default => 'stage_semantics_v2' }];
        }
        $dependencies = ['protocol' => 'consumed_dependency_identity_v1', 'status' => 'complete',
            'required_streams' => ['H1', 'H4', 'M15', 'M5'],
            'as_of_rule' => 'candle_open_plus_timeframe_duration_utc_v1', 'promotion_evidence' => false,
            'streams' => []];
        foreach ($dependencies['required_streams'] as $stream) {
            $dependencies['streams'][$stream] = ['stream' => $stream, 'status' => 'verified', 'consumed_rows' => 10,
                'actual_source_sha256' => str_repeat('a', 64), 'consumed_data_hash' => str_repeat('a', 64)];
            if ($stream !== 'M5') $dependencies['streams'][$stream] += ['join_status' => 'verified',
                'as_of_rule' => 'candle_open_plus_timeframe_duration_utc_v1', 'as_of_join_hash' => hash('sha256', $stream),
                'first_candle_utc' => '2025-01-01T00:00:00+00:00', 'last_candle_utc' => '2025-02-01T00:00:00+00:00'];
        }
        $dependencies = $this->seal($dependencies);
        $receipt = ['protocol' => 'replay_decision_identity_v2', 'status' => 'complete',
            'bindings' => ['symbol' => 'XAUUSD', 'execution_timeframe' => 'M5', 'dataset_identity' => 'frozen-test-data',
                'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64), 'source_evaluator_hash' => str_repeat('c', 64),
                'source_identity_protocol' => 'dual_runtime_source_identity_v1',
                'python_source_hash' => str_repeat('c', 64), 'full_runtime_source_hash' => str_repeat('f', 64),
                'actual_source_sha256' => str_repeat('a', 64), 'dataset_attestation_status' => 'verified',
                'source_evaluator_attested' => true, 'dependency_receipt_hash' => $dependencies['receipt_hash'], 'dependency_status' => 'complete',
                'as_of_rule' => 'candle_open_plus_timeframe_duration_utc_v1'],
            'dependency_identity' => $dependencies,
            'candle_domain' => ['event_count' => 10, 'event_hash' => hash('sha256', 'universe')],
            'stage_identities' => $stages, 'ordered_unique' => true, 'temporal_as_of_valid' => true,
            'counts_are_diagnostic_only' => true, 'promotion_evidence' => false];
        return $this->seal($receipt);
    }

    private function seal(array $receipt): array
    {
        unset($receipt['receipt_hash']);
        $canonical = function (mixed $value) use (&$canonical): mixed {
            if (! is_array($value)) return $value; if (! array_is_list($value)) ksort($value);
            foreach ($value as $key => $item) $value[$key] = $canonical($item); return $value;
        };
        $receipt['receipt_hash'] = hash('sha256', json_encode($canonical($receipt), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $receipt;
    }
}
