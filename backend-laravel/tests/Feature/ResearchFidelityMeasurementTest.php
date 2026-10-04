<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\LabImmutableEvidenceService;
use App\Services\MultiModalLearningPortfolioService;
use App\Services\ResearchExperimentConversionKernelService;
use App\Services\ResearchKnowledgePortfolioService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Synthetic fixtures exercise real immutable owners; no fixture is market evidence. */
class ResearchFidelityMeasurementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('fidelity_test');
        config()->set('services.lab_evidence.disk', 'fidelity_test');
        $this->travelTo(CarbonImmutable::parse('2026-10-04T10:00:00Z'));
    }

    public function test_random_rejection_audit_seals_pool_before_outcome_and_counts_only_matched_later_receipts(): void
    {
        $owner = app(MultiModalLearningPortfolioService::class);
        $pool = [$this->receipt('one'), $this->receipt('two'), $this->receipt('three')];
        $policy = ['seed' => 'frozen-random-seed', 'policy_version' => 'v1', 'max_sample' => 2];
        $seal = $owner->preregisterRejectionAudit($pool, $policy);
        $again = $owner->preregisterRejectionAudit(array_reverse($pool), $policy);
        $this->assertSame('preregistered', $seal['status']);
        $this->assertSame($seal, $again);
        $this->assertCount(2, $seal['spec']['sample']);
        $this->assertSame('blocked_dependency', $owner->assessRejectionAudit($seal['preregistration_key'], [])['status']);
        $this->travel(2)->seconds();
        $higher = [];
        foreach ($seal['spec']['sample'] as $index => $sample) {
            $name = collect(['one', 'two', 'three'])->first(fn ($name) => hash('sha256', $name) === $sample['candidate_key']);
            $higher[] = $this->receipt($name, 'replication', $seal['preregistration_key'], $index === 0, 3);
        }
        $measured = $owner->assessRejectionAudit($seal['preregistration_key'], $higher);
        $this->assertSame('measured', $measured['status']);
        $this->assertSame(2, $measured['matched_count']);
        $this->assertSame(1, $measured['false_rejections']);
        $this->assertSame(.5, $measured['false_rejection_rate']);
        $this->assertFalse($measured['promotion_evidence']);
        $this->assertFalse($measured['cheap_negative_is_final_skill_verdict']);
        $this->assertDatabaseCount('research_experiment_work_items', 0);
    }

    public function test_audit_refuses_post_outcome_stale_incomplete_and_tampered_witnesses(): void
    {
        $owner = app(MultiModalLearningPortfolioService::class);
        $cheap = $this->receipt('one');
        $old = $this->receipt('one', 'replication', 'unknown-seal', true);
        $seal = $owner->preregisterRejectionAudit([$cheap], ['seed' => 'seed', 'policy_version' => 'v1']);
        $this->assertNull($owner->assessRejectionAudit($seal['preregistration_key'], [$old])['false_rejection_rate']);
        $this->travel(2)->seconds();
        $incomplete = $this->receipt('one', 'replication', $seal['preregistration_key'], true, 3, true);
        $this->assertNull($owner->assessRejectionAudit($seal['preregistration_key'], [$incomplete])['false_rejection_rate']);
        $mismatched = $this->receipt('other', 'replication', $seal['preregistration_key'], true);
        $this->assertNull($owner->assessRejectionAudit($seal['preregistration_key'], [$mismatched])['false_rejection_rate']);
        DB::table('research_knowledge_entries')->where('knowledge_key', $seal['preregistration_key'])
            ->update(['evidence' => json_encode(['spec' => ['sample' => []], 'spec_hash' => str_repeat('a', 64)])]);
        $this->assertSame('AUDIT_PREREGISTRATION_MISSING_OR_TAMPERED',
            $owner->assessRejectionAudit($seal['preregistration_key'], [])['reason']);
    }

    public function test_actual_sealed_hypotheses_and_matched_existing_data_produce_only_acquisition_dependency(): void
    {
        $owner = app(MultiModalLearningPortfolioService::class);
        [$hypotheses, $observation] = $this->measurementSpec();
        $seal = $owner->proposeMeasurementAcquisition($hypotheses, $observation);
        $this->assertSame('preregistered', $seal['status']);
        $this->travel(2)->seconds();
        $masked = $this->measurementReceipt($seal, 'masked', 1.0);
        $unmasked = $this->measurementReceipt($seal, 'unmasked', .25);
        $result = $owner->assessMeasurementAcquisition($seal['preregistration_key'], [$masked, $unmasked]);
        $this->assertSame('measured_existing_data_sensitivity', $result['status']);
        $this->assertSame(.75, $result['criterion_loss_reduction']);
        $this->assertTrue($result['decision_changed']);
        $this->assertSame('bounded_prospective_acquisition_dependency', $result['recommendation']);
        $this->assertFalse($result['paid_api_calls_authorized']);
        $this->assertFalse($result['masked_unmasked_is_market_causal_proof']);
        $this->assertFalse($result['promotion_evidence']);
        $this->assertDatabaseCount('research_experiment_work_items', 0);
    }

    public function test_measurement_refuses_missing_hypotheses_future_availability_unmatched_inputs_and_unknown_cost(): void
    {
        $owner = app(MultiModalLearningPortfolioService::class);
        [$hypotheses, $observation] = $this->measurementSpec();
        $this->assertSame('blocked_dependency', $owner->proposeMeasurementAcquisition(['not-sealed', 'missing'], $observation)['status']);
        $wrongScope = $observation; $wrongScope['scope']['data_hash'] = hash('sha256', 'another');
        $this->assertSame('MEASUREMENT_HYPOTHESIS_SCOPE_MISMATCH', $owner->proposeMeasurementAcquisition($hypotheses, $wrongScope)['reason']);
        $seal = $owner->proposeMeasurementAcquisition($hypotheses, $observation);
        $this->travel(2)->seconds();
        $masked = $this->measurementReceipt($seal, 'masked', 1.0);
        $future = $this->measurementReceipt($seal, 'unmasked', .25, '2025-09-13T00:00:00Z');
        $this->assertSame('blocked_dependency', $owner->assessMeasurementAcquisition($seal['preregistration_key'], [$masked, $future])['status']);
        $different = $this->measurementReceipt($seal, 'unmasked', .25, '2025-09-12T00:10:00Z', 'different-input');
        $this->assertSame('blocked_dependency', $owner->assessMeasurementAcquisition($seal['preregistration_key'], [$masked, $different])['status']);
        $observation['cost'] = ['status' => 'unknown'];
        $unknown = $owner->proposeMeasurementAcquisition($hypotheses, $observation);
        $this->travel(2)->seconds();
        $pair = [$this->measurementReceipt($unknown, 'masked', 1.0), $this->measurementReceipt($unknown, 'unmasked', .25)];
        $this->assertSame('no_acquisition_justified', $owner->assessMeasurementAcquisition($unknown['preregistration_key'], $pair)['recommendation']);
    }

    private function measurementSpec(): array
    {
        $context = ['question_key' => 'spread-separation', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'baseline_hash' => hash('sha256', 'baseline'), 'data_hash' => hash('sha256', 'dataset')];
        $sealed = app(ResearchKnowledgePortfolioService::class)->sealCompetingHypotheses([
            ['id' => 'spread-limited', 'prior' => .5, 'predictions' => ['spread' => ['changes' => .9, 'unchanged' => .1]]],
            ['id' => 'density-limited', 'prior' => .5, 'predictions' => ['spread' => ['changes' => .1, 'unchanged' => .9]]],
        ], $context);
        return [array_column($sealed['hypothesis_refs'], 'knowledge_key'), [
            'observation_key' => 'spread', 'source' => 'synthetic-existing-quote-ledger', 'scope' => $context,
            'event_set_hash' => $this->canonicalHash($this->eventRows(3)), 'criterion_hash' => hash('sha256', 'loss-criterion'),
            'decision_as_of_utc' => '2025-09-12T01:00:00Z', 'cost' => ['amount' => 0, 'unit' => 'USD', 'source' => 'already_owned_dataset'],
        ]];
    }

    private function receipt(string $name, string $kind = 'diagnostic', string $seal = '', bool $positive = false, int $events = 1, bool $incomplete = false): string
    {
        [$run, $owner] = $this->createRun($name, $events);
        $witness = ['candidate_key' => hash('sha256', $name), 'kind' => $kind, 'verdict' => $positive ? 'accepted' : 'rejected',
            'preregistration_key' => $seal, 'program_hash' => $run->parameter_hash, 'source_hash' => $run->code_hash,
            'criterion_hash' => hash('sha256', 'screen-criterion'), 'physical_event_set_hash' => $this->canonicalHash($this->eventRows($events))];
        $response = $this->response($events);
        $response['fidelity_witness'] = $witness;
        if ($incomplete) $response['data_quality']['decision_trace']['complete'] = false;
        $owner->finishRun($run, 'completed', $response);
        return $this->convert($run->fresh(), ['fidelity_witness' => $witness], $positive,
            in_array($kind, ['semantic', 'diagnostic', 'discovery'], true) ? 'INCONCLUSIVE' : null);
    }

    private function measurementReceipt(array $seal, string $arm, float $loss, string $available = '2025-09-12T00:10:00Z', string $input = 'same-input'): string
    {
        $observation = $seal['spec']['observation'];
        $probe = ['preregistration_key' => $seal['preregistration_key'], 'arm' => $arm,
            'observation_key' => 'spread', 'source' => $observation['source'],
            'availability_by_event' => array_map(fn ($row) => [...$row,
                'available_at_utc' => $available === '2025-09-12T00:10:00Z' ? $row['candle_time'] : $available], $this->eventRows(3))];
        [$run, $owner] = $this->createRun('measurement', 3, ['measurement_probe' => $probe, 'matched_fixture_input' => $input]);
        $requestArtifact = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'evaluation_request')->firstOrFail();
        $request = $owner->readArtifactPayload($requestArtifact); unset($request['measurement_probe']);
        $response = $this->response(3);
        if ($arm === 'unmasked') $response['decision_trace'][0]['action'] = 'BUY';
        $witness = [...$probe, 'program_hash' => $run->parameter_hash, 'source_hash' => $run->code_hash,
            'event_set_hash' => $observation['event_set_hash'], 'criterion_hash' => $observation['criterion_hash'],
            'matched_input_hash' => $this->canonicalHash($request), 'decisions_hash' => $this->canonicalHash($response['decision_trace']),
            'observations' => 3, 'latest_available_at_utc' => $available, 'criterion_loss' => $loss];
        $response['measurement_witness'] = $witness;
        $owner->finishRun($run, 'completed', $response);
        return $this->convert($run->fresh(), ['measurement_witness' => $witness], false);
    }

    private function createRun(string $name, int $events, array $extra = []): array
    {
        $lab = AiLaboratory::firstOrCreate(['name' => 'Synthetic fidelity fixture'],
            ['symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_families' => ['trend']]);
        $generation = LabGeneration::firstOrCreate(['ai_laboratory_id' => $lab->id, 'generation' => 1],
            ['trigger_type' => 'synthetic_test', 'status' => 'screened', 'population_size' => 20]);
        $model = ModelVersion::firstOrCreate(['name' => $name],
            ['strategy' => 'trend_v1', 'version' => 'test', 'parameters' => ['candidate' => $name]]);
        $agent = LabAgent::firstOrCreate(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id],
            ['symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'trend', 'origin' => 'synthetic_test', 'parameter_diff' => []]);
        $owner = app(LabImmutableEvidenceService::class);
        $run = $owner->beginRun($agent, 'full_validation', 'synthetic_test', ['code_hash' => hash('sha256', 'synthetic-source')]);
        $rows = [];
        for ($index = 0; $index < 200 + $events; $index++) {
            $rows[] = ['time' => CarbonImmutable::parse('2025-09-12T00:00:00Z')->addMinutes(($index - 200) * 5)->toIso8601ZuluString(),
                'close' => 2000, 'bid' => 2000, 'ask' => 2001];
        }
        $owner->attachRequest($run, ['symbol' => 'XAUUSD', 'parameters' => $model->parameters, 'candles' => $rows, ...$extra],
            ['data_hash' => hash('sha256', 'dataset')]);
        return [$run->fresh(), $owner];
    }

    private function response(int $events): array
    {
        return ['total_trades' => 0, 'trade_ledger_hash' => hash('sha256', 'empty-ledger'), 'trade_ledger' => [], 'trades' => [],
            'displayed_trade_count' => 0, 'decision_trace' => array_map(fn ($event) => [...$event,
                'event_type' => 'signal_evaluation', 'action' => 'WAIT', 'accepted' => false], $this->eventRows($events)),
            'data_quality' => ['decision_trace' => ['protocol' => 'candle_decision_trace_v1', 'requested' => true,
                'complete' => true, 'event_count' => $events, 'evaluated_candle_count' => $events]]];
    }

    private function eventRows(int $events): array
    {
        return array_map(fn ($index) => ['candle_index' => 200 + $index,
            'candle_time' => CarbonImmutable::parse('2025-09-12T00:00:00Z')->addMinutes($index * 5)->toIso8601ZuluString()], range(0, $events - 1));
    }

    private function convert(LabEvaluationRun $run, array $witness, bool $positive, ?string $classification = null): string
    {
        $contract = ['contract_version' => ResearchExperimentConversionKernelService::CONTRACT_VERSION,
            'source' => ['type' => 'fidelity_fixture', 'id' => $run->id],
            'scope' => ['symbol' => 'XAUUSD', 'laboratory_timeframe' => 'H1', 'execution_timeframe' => 'M5',
                'physical_event_range_utc' => ['start' => '2025-09-11T00:00:00Z', 'end' => '2025-09-13T00:00:00Z']],
            'identity' => ['baseline_epoch_hash' => hash('sha256', 'baseline'), 'data_and_mtf_hash' => $run->data_hash,
                'runtime_and_contract_hash' => hash('sha256', 'runtime'), 'intervention_hash' => $run->parameter_hash,
                'window_plan_hash' => hash('sha256', 'target-window'), 'evaluator_version' => hash('sha256', 'synthetic-source')],
            'arms' => [['role' => 'frozen_control'], ['role' => 'candidate']]];
        $result = app(ResearchExperimentConversionKernelService::class)->record($contract,
            ['evidence_run_id' => $run->run_id, 'response_hash' => $run->response_hash, ...$witness],
            $classification ?? ($positive ? 'POSITIVE_CANDIDATE' : 'HARMFUL'), [], ['code' => 'SYNTHETIC_TERMINAL']);
        $this->assertSame('recorded', $result['status']);
        return $result['receipt_key'];
    }

    private function canonicalHash(array $value): string
    {
        $canonical = function (array $items) use (&$canonical): array {
            if (! array_is_list($items)) ksort($items);
            foreach ($items as $key => $item) if (is_array($item)) $items[$key] = $canonical($item);
            return $items;
        };
        return hash('sha256', json_encode($canonical($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }
}
