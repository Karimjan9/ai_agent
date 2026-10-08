<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Models\ResearchExperimentReceipt;
use App\Services\ExecutionContractService;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabAgentEvaluationService;
use App\Services\NativeSpreadContextStudyService;
use App\Services\ProspectiveRepairProbeWindowService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\SpecialistCouncilLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Test-only SQLite identities; these source fixtures make no market or economic claim. */
class NativeSpreadContextStudyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        Storage::fake('native_spread_study');
        config(['services.lab_evidence.disk' => 'native_spread_study',
            'services.mtf_pilot.enabled' => false, 'services.xauusd_organism.enabled' => false,
            'services.internal_api.token' => 'native-spread-study-test-key-at-least-32-bytes']);
    }

    public function test_two_unused_native_carriers_receive_original_signed_contracts_idempotently(): void
    {
        [$masked, $unmasked, $spec] = $this->fixture();
        $owner = app(NativeSpreadContextStudyService::class);
        $before = [$masked->modelVersion->parameters, $unmasked->modelVersion->parameters];
        $sealed = $owner->preregister($masked, $unmasked, $spec);
        $again = $owner->preregister($masked->fresh(), $unmasked->fresh(), $spec);
        $this->assertSame($sealed, $again);
        $this->assertDatabaseCount('lab_agents', 2);
        $this->assertDatabaseCount('lab_generations', 1);
        $this->assertSame(1, LabEvidenceArtifact::where('artifact_type', NativeSpreadContextStudyService::ARTIFACT)->count());
        $request = $this->batch($masked->fresh('modelVersion'), $unmasked->fresh('modelVersion'), $spec['request']);
        $request = $owner->bindRequest($request, [$masked->fresh('modelVersion')->modelVersion, $unmasked->fresh('modelVersion')->modelVersion]);
        foreach ($request['strategies'] as $index => $arm) {
            $contract = $arm[NativeSpreadContextStudyService::FIELD];
            $this->assertSame($index === 0 ? 'masked' : 'unmasked', $contract['arm']);
            $this->assertSame($sealed['study_id'], $contract['study_id']);
            $this->assertSame($before[$index], $arm['parameters']);
            $this->assertSame('research_only', $contract['authority']);
            foreach (['economic_authority', 'skill_authority', 'independent_evidence', 'promotion_evidence'] as $flag) $this->assertFalse($contract[$flag]);
            $this->assertSame(hash_hmac('sha256', NativeSpreadContextStudyService::PROTOCOL."\n".$contract['contract_hash'],
                config('services.internal_api.token')), $contract['server_seal']['hmac_sha256']);
        }
        $this->assertSame($request['strategies'][0][NativeSpreadContextStudyService::FIELD]['identity'], $request['strategies'][1][NativeSpreadContextStudyService::FIELD]['identity']);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('research_experiment_receipts', 0);
    }

    public function test_observed_carrier_cannot_be_preregistered(): void
    {
        [$masked, $unmasked, $spec] = $this->fixture();
        app(LabImmutableEvidenceService::class)->beginRun($masked, 'screening', 'incremental');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('NATIVE_SPREAD_STUDY_UNOBSERVED_GENERATION_REQUIRED');
        app(NativeSpreadContextStudyService::class)->preregister($masked, $unmasked, $spec);
    }

    public function test_queued_snapshot_or_an_existing_evaluation_owner_cannot_acquire_study_authority(): void
    {
        foreach (['queued', 'snapshot', 'causal_owner', 'different_program'] as $case) {
            [$masked, $unmasked, $spec] = $this->fixture($case);
            if ($case === 'queued') $unmasked->update(['lifecycle_status' => 'queued']);
            if ($case === 'snapshot') $masked->generation->update(['trigger_context' => ['canonical_dataset_snapshots' => ['hash' => 'frozen']]]);
            if ($case === 'causal_owner') $unmasked->modelVersion->update(['metadata' => [...$unmasked->modelVersion->metadata, 'causal_learning_cohort' => ['protocol' => 'owner']]]);
            if ($case === 'different_program') $unmasked->modelVersion->update(['parameters' => [...$unmasked->modelVersion->parameters, 'ema_fast' => 7]]);
            try { app(NativeSpreadContextStudyService::class)->preregister($masked, $unmasked, $spec); $this->fail('Study admitted '.$case); }
            catch (\LogicException $exception) { $this->assertStringStartsWith('NATIVE_SPREAD_STUDY_', $exception->getMessage()); }
        }
        $this->assertSame(0, LabEvidenceArtifact::where('artifact_type', NativeSpreadContextStudyService::ARTIFACT)->count());
    }

    public function test_declared_arm_contract_or_cost_drift_and_missing_marker_are_refused(): void
    {
        [$masked, $unmasked, $spec] = $this->fixture();
        $owner = app(NativeSpreadContextStudyService::class); $owner->preregister($masked, $unmasked, $spec);
        $model = $masked->fresh('modelVersion')->modelVersion;
        $request = $this->batch($masked->fresh('modelVersion'), $unmasked->fresh('modelVersion'), $spec['request']);
        $bound = $owner->bindRequest($request, [$model]);
        foreach (['contract', 'capital', 'dataset', 'native'] as $mutation) {
            $changed = $bound;
            if ($mutation === 'contract') $changed['strategies'][0][NativeSpreadContextStudyService::FIELD]['criterion'] = 'criterion_loss';
            if ($mutation === 'capital') $changed['initial_balance'] = 20000;
            if ($mutation === 'dataset') $changed['replay_dataset_hash'] = hash('sha256', 'different');
            if ($mutation === 'native') $changed['strategies'][0]['specialist_council_contract']['contract_hash'] = hash('sha256', 'different');
            try { $owner->bindRequest($changed, [$model]); $this->fail('Dispatch admitted '.$mutation); }
            catch (\LogicException $exception) { $this->assertStringStartsWith('NATIVE_SPREAD_STUDY_', $exception->getMessage()); }
        }
        $metadata = $model->metadata; unset($metadata[NativeSpreadContextStudyService::MARKER]); $model->update(['metadata' => $metadata]);
        $this->assertTrue($owner->declares($model->fresh()));
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('NATIVE_SPREAD_STUDY_ORIGINAL_PREREGISTRATION_REQUIRED');
        $owner->bindRequest($bound, [$model->fresh()]);
    }

    public function test_original_probe_replaces_only_a_valid_same_physical_generic_policy(): void
    {
        [$masked, $unmasked, $spec] = $this->fixture('probe-owner');
        $owner = app(NativeSpreadContextStudyService::class); $owner->preregister($masked, $unmasked, $spec);
        $request = $this->batch($masked->fresh('modelVersion'), $unmasked->fresh('modelVersion'), $spec['request']);
        $original = $request['policy_context']['prospective_probe_window']; $generic = $original;
        $generic['experiment_key'] = 'generic-academy-clean-window'; unset($generic['contract_hash']);
        $generic['contract_hash'] = hash('sha256', json_encode($generic, JSON_UNESCAPED_SLASHES));
        $request['policy_context']['prospective_probe_window'] = $generic;
        $bound = $owner->bindRequest($request, [$masked->fresh('modelVersion')->modelVersion]);
        $this->assertSame($original, $bound['policy_context']['prospective_probe_window']);
        $wrong = $generic; $wrong['evaluated_start'] = '2025-01-06T06:00:00Z'; unset($wrong['contract_hash']);
        $wrong['contract_hash'] = hash('sha256', json_encode($wrong, JSON_UNESCAPED_SLASHES));
        $request['policy_context']['prospective_probe_window'] = $wrong;
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('NATIVE_SPREAD_STUDY_ORIGINAL_PROBE_PHYSICAL_SCOPE_DRIFT');
        $owner->bindRequest($request, [$masked->fresh('modelVersion')->modelVersion]);
    }

    public function test_atr_source_requires_an_explicit_supported_binding_before_registration(): void
    {
        foreach ([null, 'atr_regime', 'fallback_if_missing'] as $binding) {
            [$masked, $unmasked, $spec] = $this->fixture('atr-binding-'.(string) ($binding ?? 'missing'));
            $spec['liquidity_atr_binding'] = $binding;
            try { app(NativeSpreadContextStudyService::class)->preregister($masked, $unmasked, $spec); $this->fail('Unknown ATR source admitted.'); }
            catch (\LogicException $exception) { $this->assertSame('NATIVE_SPREAD_STUDY_EXPLICIT_ATR_INPUT_BINDING_REQUIRED', $exception->getMessage()); }
        }
        $this->assertSame(0, LabEvidenceArtifact::where('artifact_type', NativeSpreadContextStudyService::ARTIFACT)->count());
    }

    public function test_pair_publication_waits_for_both_originals_and_technical_closure_is_idempotent(): void
    {
        [$masked, $unmasked, $spec] = $this->fixture();
        $owner = app(NativeSpreadContextStudyService::class); $owner->preregister($masked, $unmasked, $spec);
        $evidence = app(LabImmutableEvidenceService::class);
        $run = $evidence->beginRun($masked->fresh('modelVersion'), 'screening', 'incremental');
        $evidence->finishRun($run, 'technical_error');
        $this->assertSame('awaiting_original_pair', $owner->settleOriginalPair($run->fresh())['status']);
        $this->assertDatabaseCount('research_experiment_receipts', 0);
        $other = $evidence->beginRun($unmasked->fresh('modelVersion'), 'screening', 'incremental');
        $evidence->finishRun($other, 'technical_error');
        $first = $owner->settleOriginalPair($run->fresh());
        $second = $owner->settleOriginalPair($other->fresh());
        $this->assertSame('TECHNICAL_QUARANTINE', $first['classification']);
        $this->assertSame($first['receipt_key'], $second['receipt_key']);
        $this->assertDatabaseCount('research_experiment_receipts', 1);
        $this->assertDatabaseCount('research_experiment_work_items', 0);
        $model = $masked->fresh('modelVersion')->modelVersion;
        $model->update(['parameters' => ['ema_fast' => 99], 'metadata' => []]);
        config(['services.internal_api.token' => 'rotated-study-key-is-still-at-least-32-bytes']);
        $this->assertSame($first['receipt_key'], $owner->settleOriginalPair($run->fresh())['receipt_key']);
        ResearchExperimentReceipt::findOrFail($first['receipt_id'])->update(['classification' => 'POSITIVE_CANDIDATE']);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('NATIVE_SPREAD_STUDY_HISTORICAL_PUBLICATION_SOURCE_INVALID');
        $owner->settleOriginalPair($run->fresh());
    }

    public function test_new_administrative_ids_or_probe_label_cannot_reserve_the_same_physical_question(): void
    {
        [$masked, $unmasked, $spec] = $this->fixture('original-question');
        $owner = app(NativeSpreadContextStudyService::class); $owner->preregister($masked, $unmasked, $spec);
        [$renamedMasked, $renamedUnmasked, $renamed] = $this->fixture('renamed-question');
        $renamed['request'] = $spec['request'];
        $renamed['request']['policy_context']['prospective_probe_window']['experiment_key'] = 'new-administrative-label';
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('NATIVE_SPREAD_STUDY_PHYSICAL_QUESTION_ALREADY_RESERVED');
        $owner->preregister($renamedMasked, $renamedUnmasked, $renamed);
    }

    public function test_gate_effect_excludes_diverged_accounts_and_unreached_feature_gates(): void
    {
        $owner = app(NativeSpreadContextStudyService::class);
        $identity = ['minimum_paired_opportunities' => 1, 'exact_context' => $this->context(), 'quote_provenance_hash' => hash('sha256', 'quote'),
            'target_member_hash' => hash('sha256', 'target'), 'liquidity_atr_binding' => 'closed_strategy_atr_v1'];
        $events = [$this->event('eligible'), $this->event('diverged'), $this->event('unreached')];
        $masked = ['arm' => 'masked', 'status' => 'computed', 'events' => $events, 'counts' => ['matching_context_opportunities' => 3],
            'replay_executed_clock' => ['schedule_hash' => hash('sha256', 'schedule'), 'index_set_hash' => hash('sha256', 'indexes'), 'decision_rows' => 3,
                'first_evaluation_index' => 32, 'last_evaluation_index' => 34,
                'signal_start' => '2025-01-06T04:40:00Z', 'signal_end' => '2025-01-06T04:50:00Z', 'execution_start' => '2025-01-06T04:45:00Z', 'execution_end' => '2025-01-06T04:55:00Z'],
            'source_attestation' => ['actual_source_sha256' => hash('sha256', 'source')]];
        $unmasked = $masked; $unmasked['arm'] = 'unmasked';
        foreach ($unmasked['events'] as &$event) { $event['mask_applied'] = false; $event['gate_allowed'] = false; $event['action'] = 'WAIT';
            $event['gate_context']['spread_liquidity_state'] = 'liquid'; $event['gate_context_hash'] = app(ResearchPaperEpochContractService::class)->parameterHash($event['gate_context']); }
        unset($event);
        $unmasked['events'][1]['account_before_gate_hash'] = hash('sha256', 'other-account');
        $unmasked['events'][2]['feature_gate_reached'] = false;
        $reflection = new \ReflectionMethod(NativeSpreadContextStudyService::class, 'compare');
        $result = $reflection->invoke($owner, ['masked' => $this->receipt($masked), 'unmasked' => $this->receipt($unmasked)], $identity);
        $this->assertSame('measured_existing_data_sensitivity', $result['status']);
        $this->assertSame(1, $result['eligible_paired_opportunities']);
        $this->assertSame(1, $result['changed_entry_wait_decisions']);
        $this->assertSame(1, $result['excluded_diverged_account']);
        $this->assertSame(1, $result['excluded_unreached']);
        $this->assertFalse($result['market_value_proven']);
        foreach (['counts', 'quote_asof', 'clock', 'mask_scope', 'status', 'atr_binding', 'atr_input'] as $mutation) {
            $bad = $masked;
            if ($mutation === 'quote_asof') {
                $bad['events'][0]['source_quote']['quote_time'] = '2025-01-06T04:45:01Z';
                $bad['events'][0]['source_quote_hash'] = app(ResearchPaperEpochContractService::class)->parameterHash($bad['events'][0]['source_quote']);
            }
            if ($mutation === 'clock') $bad['replay_executed_clock']['last_evaluation_index'] = 33;
            if ($mutation === 'mask_scope') {
                $bad['events'][0]['gate_context']['regime'] = 'other';
                $bad['events'][0]['gate_context_hash'] = app(ResearchPaperEpochContractService::class)->parameterHash($bad['events'][0]['gate_context']);
            }
            if ($mutation === 'status') $bad['status'] = 'underpowered';
            if ($mutation === 'atr_binding') {
                $bad['events'][0]['source_quote']['atr_source_key'] = '_management_atr';
                $bad['events'][0]['source_quote']['atr_input_hash'] = app(ResearchPaperEpochContractService::class)->parameterHash(['source_key' => '_management_atr', 'value' => 1.0]);
                $bad['events'][0]['source_quote_hash'] = app(ResearchPaperEpochContractService::class)->parameterHash($bad['events'][0]['source_quote']);
            }
            if ($mutation === 'atr_input') {
                $bad['events'][0]['source_quote']['atr_input_hash'] = hash('sha256', 'other-value');
                $bad['events'][0]['source_quote_hash'] = app(ResearchPaperEpochContractService::class)->parameterHash($bad['events'][0]['source_quote']);
            }
            $badReceipt = $this->receipt($bad);
            if ($mutation === 'counts') {
                $body = json_decode($badReceipt['receipt_json'], true); $body['counts']['entry_actions']++;
                $badReceipt = [...$body, 'receipt_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($body),
                    'receipt_json' => json_encode($body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)];
            }
            try { $reflection->invoke($owner, ['masked' => $badReceipt, 'unmasked' => $this->receipt($unmasked)], $identity); $this->fail('Rehashed invalid facts accepted: '.$mutation); }
            catch (\LogicException $exception) { $this->assertStringStartsWith('NATIVE_SPREAD_STUDY_', $exception->getMessage()); }
        }
    }

    public function test_signed_php_contracts_cross_real_python_and_original_pair_settles_without_economic_credit(): void
    {
        $this->verifyRealWire('breakout', true);
    }

    public function test_real_ema_source_keeps_missing_atr_as_data_dependency_even_with_actual_quotes(): void
    {
        $this->verifyRealWire('ema_rsi', false);
    }

    public function test_quarantine_race_finishes_technical_without_reopening_agent_or_granting_credit(): void
    {
        [$agent] = $this->fixture('race'); $evidence = app(LabImmutableEvidenceService::class);
        $run = $evidence->beginRun($agent, 'screening', 'incremental'); $agent->update(['lifecycle_status' => 'technical_quarantine']);
        $this->mock(NativeSpreadContextStudyService::class, function ($mock): void {
            $mock->shouldReceive('declares')->once()->andReturn(true);
            $mock->shouldReceive('attestResult')->once()->andReturn(['status' => 'computed']);
            $mock->shouldReceive('settleOriginalPair')->once()->andReturn(['classification' => 'TECHNICAL_QUARANTINE']);
        });
        $method = new \ReflectionMethod(LabAgentEvaluationService::class, 'finishNativeSpreadContextStudy');
        $this->assertTrue($method->invoke(app(LabAgentEvaluationService::class), $agent->fresh(['modelVersion', 'generation']), $run,
            ['total_trades' => 0, 'trade_ledger' => [], 'trade_ledger_hash' => hash('sha256', 'empty'), 'decision_trace' => []]));
        $this->assertSame('technical_error', $run->fresh()->status);
        $this->assertSame('withheld', $run->fresh()->metadata['quality_verdict']);
        $this->assertSame('technical_quarantine', $agent->fresh()->lifecycle_status);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    private function verifyRealWire(string $family, bool $expectedEffect): void
    {
        [$masked, $unmasked, $spec] = $this->fixture('wire', true, $family);
        $rows = []; $start = new \DateTimeImmutable('2025-01-06T02:00:00Z');
        for ($index = 0; $index < 320; $index++) {
            $time = $start->modify('+'.($index * 5).' minutes'); $price = 2000.0 + 10 * sin($index / 12) + $index * .025;
            $rows[] = ['time' => $time->format(DATE_ATOM), 'open' => $price, 'high' => $price + .5, 'low' => $price - .5,
                'close' => $price, 'volume' => 100, 'volume_available' => 1, 'spread_available' => 1, 'spread' => .1,
                'bid_close' => $price, 'ask_close' => $price + .1, 'quote_time_utc' => $time->modify('+270 seconds')->format(DATE_ATOM),
                'quote_available_after_utc' => $time->modify('+300 seconds')->format(DATE_ATOM), 'quote_age_ms' => 30000];
        }
        $path = tempnam(sys_get_temp_dir(), 'native-spread-wire-');
        try {
            $csv = fopen($path, 'wb'); fputcsv($csv, array_keys($rows[0]), ',', '"', '');
            foreach ($rows as $row) fputcsv($csv, array_values($row), ',', '"', ''); fclose($csv);
            $sha = hash_file('sha256', $path); $base = $spec['request']; $base['dataset_path'] = $path; $base['replay_dataset_hash'] = $sha;
            $base['policy_context']['prospective_probe_window'] = app(ProspectiveRepairProbeWindowService::class)->seal(
                $rows, $sha, $base['execution_contract']['execution_hash'], 'wire-study', 288, 32);
            $base['mtf_snapshot_manifest'] = ['quote_spread_provenance' => ['protocol' => 'historical_quote_spread_snapshot_v1',
                'provider' => 'dukascopy_historical_synchronized_tick_v1', 'maximum_quote_age_ms' => 60000,
                'paper_2026_included' => false, 'promotion_evidence' => false, 'source_m5_csv_sha256' => $sha,
                'sources' => [['sha256' => $sha, 'fixture' => 'conditional_test_csv_not_provider_or_constructor_qualification']]]];
            $request = $this->single($masked, $base);
            $preview = $this->python(['mode' => 'preview_context', 'specialist_id' => 'scalp', 'request' => $request]);
            $this->assertTrue($preview['no_account_or_pnl_computed']);
            $spec['request'] = $base; $spec['exact_context'] = $preview['exact_context'];
            $owner = app(NativeSpreadContextStudyService::class); $registration = $owner->preregister($masked, $unmasked, $spec);
            $evidence = app(LabImmutableEvidenceService::class); $results = []; $runs = [];
            foreach (['masked' => $masked, 'unmasked' => $unmasked] as $arm => $agent) {
                $agent = $agent->fresh(['modelVersion', 'generation']);
                $request = $owner->bindRequest($this->single($agent, $base), [$agent->modelVersion]);
                $run = $evidence->beginRun($agent, 'screening', 'incremental');
                $evidence->attachRequest($run, $request, ['data_hash' => $sha]); $run = $run->fresh();
                $results[$arm] = $this->python(['mode' => 'replay', 'request' => $request]);
                $receipt = $owner->attestResult($agent->modelVersion, $run, $results[$arm]);
                $this->assertSame($registration['contracts'][$arm]['contract_hash'], $receipt['contract_hash']);
                $this->assertSame($expectedEffect ? 'computed' : 'data_missing', $receipt['status']);
                $complete = $evidence->replayEvidenceCompleteness($run, $results[$arm]);
                $this->assertTrue($complete['complete'], implode(',', [...$complete['reason_codes'], ...$complete['decision_trace_reason_codes']]));
                $evidence->finishRun($run, 'completed', $results[$arm]); $runs[$arm] = $run->fresh();
            }
            $settled = $owner->settleOriginalPair($runs['unmasked']);
            $this->assertSame($expectedEffect ? 'INCONCLUSIVE' : 'UNDERPOWERED', $settled['classification']);
            $outcome = ResearchExperimentReceipt::findOrFail($settled['receipt_id'])->payload['evidence']['outcome'];
            $this->assertSame($expectedEffect ? 'measured_existing_data_sensitivity' : 'data_missing', $outcome['status']);
            if ($expectedEffect) {
                $this->assertGreaterThan(0, $outcome['eligible_paired_opportunities']);
                $this->assertGreaterThan(0, $outcome['changed_entry_wait_decisions']);
            } else {
                $this->assertSame(0, $outcome['eligible_paired_opportunities']);
                $this->assertSame(0, $outcome['missing_quote_opportunities']);
                $this->assertGreaterThan(0, $outcome['missing_context_feature_opportunities']);
            }
            $this->assertFalse($outcome['market_value_proven']);
            $this->assertSame($settled['receipt_key'], $owner->settleOriginalPair($runs['masked'])['receipt_key']);
            $this->assertDatabaseCount('research_experiment_receipts', 1);
            $this->assertDatabaseCount('research_experiment_work_items', 0);
            $this->assertDatabaseCount('lab_evolution_credit_events', 0);
            $this->assertSame($sha, hash_file('sha256', $path));
        } finally { if (is_file($path)) unlink($path); }
    }

    private function fixture(string $name = 'base', bool $instrumentGate = false, string $family = 'ema_rsi'): array
    {
        $lab = AiLaboratory::firstOrCreate(['symbol' => 'XAUUSD', 'timeframe' => 'H1'], ['name' => 'Spread study '.$name, 'strategy_families' => ['ema_rsi']]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => (int) $lab->generations()->max('generation') + 1, 'trigger_type' => 'test', 'status' => 'draft', 'population_size' => 2]);
        $parameters = ['ema_fast' => 4, 'ema_slow' => 10, 'rsi_period' => 4, 'rsi_buy_min' => 1, 'rsi_buy_max' => 99, 'rsi_sell_min' => 1, 'rsi_sell_max' => 99];
        if ($family === 'breakout') $parameters = ['lookback' => 10, 'atr_period' => 14, 'atr_multiplier' => .1, 'confirmation_candles' => 1, 'retest_required' => false];
        $models = [];
        foreach (['source', 'masked', 'unmasked'] as $label) $models[$label] = ModelVersion::create(['name' => $name.'-'.$label,
            'strategy' => $family.'_v1', 'version' => '1', 'status' => 'testing', 'parameters' => $parameters, 'metadata' => ['base_strategy' => $family]]);
        $models['source']->update(['metadata' => [...$models['source']->metadata, 'specialist_council_membership' => [
            'contextual_cell' => ['liquidity_atr_binding' => 'closed_strategy_atr_v1']]]]);
        if ($instrumentGate) $models['source']->update(['metadata' => [...$models['source']->metadata, 'instrument_research_assignment' => [
            'protocol' => 'lab_instrument_research_assignment_v2', 'assignment_hash' => hash('sha256', 'test-assignment'),
            'activation_policy' => ['protocol' => 'instrument_runtime_activation_contract_v1'], 'selected' => [[
                'instrument_key' => 'regime_router', 'role' => 'model', 'activation_contract' => [
                    'protocol' => 'instrument_runtime_activation_contract_v1', 'required_runtime_events' => ['router_selected:*'],
                    'context' => ['declared_context' => ['spread_liquidity_state' => 'normal']]]]]]]]);
        $council = app(SpecialistCouncilLifecycleService::class);
        $version = $council->registerDraft(['council_id' => 'spread-'.$name, 'version' => '1', 'members' => [[
            'specialist_id' => 'scalp', 'role' => 'scalp', 'version' => '1', 'as_of' => '2024-12-31T00:00:00Z',
            'inputs' => ['as_of_closed_candles'], 'scope' => ['symbols' => ['XAUUSD'], 'contexts' => ['any']], 'known_limits' => ['test_only'],
            'resources' => ['max_compute_ms' => 100, 'max_memory_mb' => 32, 'max_lookback_bars' => 512],
            'horizon' => ['kind' => 'scalp', 'decision_interval_seconds' => 300, 'reevaluation_interval_seconds' => 300, 'max_holding_seconds' => 3600, 'execution_precision' => 'candle'],
            'data_requirements' => ['bid_ask', 'spread', 'slippage', 'quote_age', 'intrabar_ambiguity'],
            'model_version_id' => $models['source']->id, 'strategy_version' => 'strategy-1', 'tactic_version' => 'tactic-1', 'management_version' => 'management-1',
            'capital_weight' => .25, 'risk_per_trade_percent' => .1, 'sensor_timeframes' => ['M5']]], 'components' => [],
            'routing' => ['id' => 'router', 'version' => '1'], 'allocation' => ['id' => 'capital', 'version' => '1'], 'risk' => ['id' => 'risk', 'version' => '1'],
            'execution' => ['id' => 'execution', 'version' => '1', 'broker_position_mode' => 'hedging', 'opposite_position_policy' => 'hedge', 'max_open_positions' => 4,
                'max_reserved_capital_percent' => 100, 'max_gross_exposure_percent' => 100, 'max_total_risk_percent' => 2, 'max_drawdown_percent' => 10, 'max_daily_loss_percent' => 3, 'max_expected_cost_percent' => 1],
            'evaluation_policy' => ['objective' => 'net_return_at_equal_risk', 'champion_model_version_id' => $models['source']->id, 'solo_model_version_id' => $models['source']->id]], 'test-owner');
        $agents = [];
        foreach (['masked', 'unmasked'] as $arm) {
            $model = $council->attachResearchModel($version, $models[$arm]);
            $agents[$arm] = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'ema_rsi', 'origin' => 'test', 'lifecycle_status' => 'draft', 'parameter_diff' => []]);
        }
        $rows = []; $start = new \DateTimeImmutable('2025-01-06T02:00:00Z');
        for ($index = 0; $index < 64; $index++) $rows[] = ['time' => $start->modify('+'.($index * 5).' minutes')->format(DATE_ATOM), 'open' => 2000, 'high' => 2001, 'low' => 1999, 'close' => 2000];
        $dataset = hash('sha256', 'source-'.$name); $execution = app(ExecutionContractService::class)->for('XAUUSD', 'M5');
        $probe = app(ProspectiveRepairProbeWindowService::class)->seal($rows, $dataset, $execution['execution_hash'], 'study-'.$name, 32, 32);
        $base = ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'evaluation_mode' => 'incremental', 'initial_balance' => 10000,
            'emit_decision_trace' => true, 'include_trades' => true,
            'replay_dataset_hash' => $dataset, 'execution_contract' => $execution, 'execution' => $execution['parameters'],
            'policy_context' => ['prospective_probe_window' => $probe]];
        return [$agents['masked']->fresh(['modelVersion', 'generation']), $agents['unmasked']->fresh(['modelVersion', 'generation']),
            ['request' => $base, 'specialist_id' => 'scalp', 'exact_context' => $this->context(),
                'liquidity_atr_binding' => 'closed_strategy_atr_v1', 'minimum_paired_opportunities' => 1]];
    }

    private function batch(LabAgent $masked, LabAgent $unmasked, array $base): array
    {
        $strategies = [];
        foreach ([$masked, $unmasked] as $agent) $strategies[] = ['lab_agent_id' => (int) $agent->id, 'strategy' => $agent->modelVersion->strategy,
            'parameters' => $agent->modelVersion->parameters, 'specialist_council_contract' => app(SpecialistCouncilLifecycleService::class)->runtimeContractForModel(
                $agent->modelVersion, $base['timeframe'], $base['replay_dataset_hash'], $base['execution_contract']['execution_hash'], null, $base['symbol'])];
        return [...$base, 'strategies' => $strategies];
    }

    private function context(): array { return ['regime' => 'trend', 'volatility' => 'normal', 'session' => 'asia', 'venue_phase' => 'asia_core', 'direction' => 'BUY']; }
    private function single(LabAgent $agent, array $base): array
    {
        $mtf = isset($base['mtf_snapshot_manifest']) ? ['bundle_hash' => $base['replay_dataset_hash'], 'manifest' => $base['mtf_snapshot_manifest']] : null;
        return [...$base, 'strategy' => $agent->modelVersion->strategy, 'version' => $agent->modelVersion->version, 'base_strategy' => $agent->modelVersion->metadata['base_strategy'],
            'parameters' => $agent->modelVersion->parameters, 'specialist_council_contract' => app(SpecialistCouncilLifecycleService::class)->runtimeContractForModel(
                $agent->modelVersion, $base['timeframe'], $base['replay_dataset_hash'], $base['execution_contract']['execution_hash'], $mtf, $base['symbol'])];
    }
    private function python(array $input): array
    {
        $source = "import runpy;runpy.run_path('tests/support/native_spread_context_study_fixture.py',run_name='__main__')";
        $process = new Process(['python', '-c', $source], dirname(base_path()).'/ai-service-python',
            ['INTERNAL_API_TOKEN' => config('services.internal_api.token'), 'INTERNAL_API_TOKEN_FILE' => '']);
        $process->setTimeout(90); $process->setInput(json_encode($input, JSON_THROW_ON_ERROR)); $process->mustRun();
        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }
    private function receipt(array $body): array {
        $events = $body['events'];
        $body['counts'] = ['matching_context_opportunities' => count($events), 'raw_opportunities' => count($events), 'outside_context_opportunities' => 0,
            'observed_quote_opportunities' => count(array_filter($events, fn ($row) => $row['source_quote']['available'])),
            'gate_reached_opportunities' => count(array_filter($events, fn ($row) => $row['gate_reached'])),
            'feature_gate_reached_opportunities' => count(array_filter($events, fn ($row) => $row['feature_gate_reached'])),
            'entry_actions' => count(array_filter($events, fn ($row) => $row['action'] === 'ENTRY')),
            'wait_actions' => count(array_filter($events, fn ($row) => $row['action'] === 'WAIT')),
            'missing_quote_opportunities' => count(array_filter($events, fn ($row) => ! $row['source_quote']['available'])),
            'missing_context_feature_opportunities' => count(array_filter($events, fn ($row) => $row['source_quote']['available'] && ! $row['source_quote']['feature_available']))];
        return [...$body, 'receipt_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($body), 'receipt_json' => json_encode($body, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)];
    }
    private function event(string $name): array
    {
        $offset = ['eligible' => 0, 'diverged' => 1, 'unreached' => 2][$name]; $index = 32 + $offset;
        $time = new \DateTimeImmutable('2025-01-06T04:40:00Z'); $time = $time->modify('+'.($offset * 5).' minutes');
        $signal = $time->format('Y-m-d\TH:i:s\Z'); $execution = $time->modify('+5 minutes')->format('Y-m-d\TH:i:s\Z');
        $quote = ['available' => true, 'feature_available' => true, 'provenance_hash' => hash('sha256', 'quote'), 'source_sha256' => hash('sha256', 'source'), 'observed_state' => 'liquid',
            'atr_binding' => 'closed_strategy_atr_v1', 'atr_source_key' => 'atr',
            'atr_input_hash' => app(ResearchPaperEpochContractService::class)->parameterHash(['source_key' => 'atr', 'value' => 1.0]),
            'bid' => 2000.0, 'ask' => 2000.1, 'spread' => .1, 'atr' => 1.0, 'age_ms' => 30000,
            'quote_time' => $time->modify('+270 seconds')->format('Y-m-d\TH:i:s\Z'), 'available_at' => $execution];
        return ['event_id' => app(ResearchPaperEpochContractService::class)->parameterHash(['member' => hash('sha256', 'target'),
            'evaluation_index' => $index, 'signal_time' => $signal, 'execution_time' => $execution, 'direction' => 'BUY']),
            'evaluation_index' => $index, 'signal_time' => $signal, 'execution_time' => $execution,
            'direction' => 'BUY', 'raw_signal_hash' => hash('sha256', 'signal'), 'source_context' => $this->context(),
            'source_context_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($this->context()), 'source_quote' => $quote,
            'source_quote_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($quote), 'closed_input_hash' => hash('sha256', 'closed'),
            'mask_applied' => true, 'gate_reached' => true, 'feature_gate_reached' => true, 'gate_context' => [...$this->context(), 'spread_liquidity_state' => 'unknown'],
            'gate_context_hash' => app(ResearchPaperEpochContractService::class)->parameterHash([...$this->context(), 'spread_liquidity_state' => 'unknown']), 'gate_allowed' => true,
            'account_before_gate_hash' => hash('sha256', 'account'), 'action' => 'ENTRY', 'rejection_code' => null];
    }
}
