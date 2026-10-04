<?php

namespace Tests\Feature;

require_once file_exists(__DIR__.'/AcademyColdStartHandoffTest.php')
    ? __DIR__.'/AcademyColdStartHandoffTest.php'
    : dirname(__DIR__, 2).'/backend-laravel/tests/Feature/AcademyColdStartHandoffTest.php';

use App\Models\LabGeneration;
use App\Models\ResearchLoopDecision;
use App\Services\AcademyExperimentMaterializerService;
use App\Services\ExactCausalBaselineService;
use App\Services\FrozenControlScreeningAdmissionService;
use App\Services\LabInstrumentResearchService;
use Illuminate\Support\Facades\Queue;

/** Real canonical 20-seat materialization; isolated SQLite, no queue publication. */
class AcademyExactControlAdmissionTest extends AcademyColdStartHandoffTest
{
    #[\PHPUnit\Framework\Attributes\DataProvider('topologies')]
    public function test_actual_cold_twenty_seat_cohort_preserves_exact_control_admission_and_primary_ownership(string $topology, int $primaryCount, int $abstainCount): void
    {
        Queue::fake();
        [$source, $baseline] = (new \ReflectionMethod(AcademyColdStartHandoffTest::class, 'sourceAndData'))->invoke($this);
        $fixture = json_decode(file_get_contents(base_path('tests/Support/academy_confirmation_source.json')), true, flags: JSON_THROW_ON_ERROR);
        $parameters = $fixture['parameters'];
        $parameters['setup_topology_policy'] = $topology;
        $staleMetadata = [...$fixture['metadata'],
            'learning_receipt' => ['control_agent_id' => 999999, 'historical_receipt_only' => true],
            'control_pair_contract' => ['protocol' => 'stale_old_pair', 'control_agent_id' => 999999],
            'control_contract' => ['protocol' => 'frozen_control_v2', 'control_only' => true, 'role' => 'control', 'generation_id' => 999999]];
        $baseline->update(['parameters' => $parameters, 'metadata' => $staleMetadata]);
        $originalParameters = (array) $baseline->parameters;
        $owner = app(AcademyExperimentMaterializerService::class);
        $proposal = $owner->proposal();
        $this->assertSame('would_prepare_cold_start', $proposal['status'], json_encode($proposal));
        $this->assertSame($primaryCount, $proposal['primary_proof_seats']);
        $decision = ResearchLoopDecision::create(['decision_key' => 'pair-boundary-'.$topology,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'action' => 'OPEN_ACADEMY_EXPERIMENT',
            'command' => 'trading:admit-academy-experiment', 'arguments' => ['trial' => 0], 'status' => 'running',
            'evidence_hash' => str_repeat('f', 64), 'reason_codes' => [], 'contract' => [],
            'evidence_snapshot' => ['academy_proposal' => $proposal]]);
        $prepared = $owner->prepareColdStart($decision);
        $this->assertSame('pending_canonical_admission', $prepared['status'], json_encode($prepared));
        $generation = LabGeneration::with('agents.modelVersion')->findOrFail($prepared['generation_id']);
        $this->assertCount(20, $generation->agents);
        $kernel = (array) data_get($generation->trigger_context, 'causal_compounding_kernel');
        $this->assertSame(8, count($kernel['pairs']));
        $this->assertSame($abstainCount, $kernel['uncertainty_abstain_seats']);
        $reservation = new \ReflectionMethod(LabInstrumentResearchService::class, 'pairReservation');
        $research = app(LabInstrumentResearchService::class);
        $admission = app(FrozenControlScreeningAdmissionService::class);
        foreach ($kernel['pairs'] as $pair) {
            $control = $generation->agents->firstWhere('id', $pair['control_agent_id']);
            $candidate = $generation->agents->firstWhere('id', $pair['candidate_agent_id']);
            foreach ([$control, $candidate] as $member) {
                $this->assertSame($pair['pair_key'], data_get($member->modelVersion->metadata, 'control_pair_contract.pair_key'));
                $this->assertSame($baseline->id, data_get($member->modelVersion->metadata, 'control_pair_contract.causal_baseline_model_version_id'));
            }
            $this->assertTrue(app(ExactCausalBaselineService::class)->matches($candidate, $control));
            $this->assertTrue($admission->isControl($control));
            $this->assertSame('ready', $admission->admission($control)['status']);
            $candidateReservation = $reservation->invoke($research, $candidate, 'candidate');
            $controlReservation = $reservation->invoke($research, $control, 'frozen_control');
            $this->assertSame('reserved', $candidateReservation['status'], json_encode($candidateReservation));
            $this->assertSame('reserved', $controlReservation['status'], json_encode($controlReservation));
            $this->assertSame($control->id, $candidateReservation['control_agent_id']);
            $this->assertSame($candidate->id, $controlReservation['candidate_agent_id']);
            foreach ([$control, $candidate] as $member) {
                $this->assertSame($control->id, data_get($member->modelVersion->metadata, 'control_pair_contract.control_agent_id'));
                $this->assertSame($candidate->id, data_get($member->modelVersion->metadata, 'control_pair_contract.candidate_agent_id'));
            }
            $waiting = $admission->admission($candidate);
            $this->assertSame('waiting', $waiting['status']);
            $this->assertSame('FROZEN_CONTROL_REPLAY_PENDING', $waiting['reason']);
            $this->assertSame($control->id, $waiting['control_agent_id']);
        }
        foreach ($kernel['abstain_agent_ids'] as $id) {
            $abstain = $generation->agents->firstWhere('id', $id);
            $this->assertTrue($admission->isControl($abstain));
            $this->assertSame('ready', $admission->admission($abstain)['status']);
            $reference = $reservation->invoke($research, $abstain, 'frozen_control');
            $this->assertSame('control_role', $reference['status']);
            $this->assertFalse($reference['required']);
        }
        $primaryControl = $generation->agents->first(fn ($member) =>
            data_get($member->modelVersion->metadata, 'academy_experiment.arm_role') === 'frozen_control');
        $this->assertNotNull($primaryControl);
        $this->assertTrue($admission->isControl($primaryControl));
        $this->assertSame('ready', $admission->admission($primaryControl)['status']);
        $this->assertSame('CONTROL_SELF', $admission->admission($primaryControl)['reason']);
        $this->assertSame($baseline->id, data_get($primaryControl->modelVersion->metadata, 'control_contract.causal_baseline_model_version_id'));
        $this->assertSame($generation->id, data_get($primaryControl->modelVersion->metadata, 'control_contract.generation_id'));
        $this->assertSame(data_get($generation->trigger_context, 'data_hash'), data_get($primaryControl->modelVersion->metadata, 'control_contract.data_hash'));
        $this->assertSame(data_get($generation->trigger_context, 'execution_hash'), data_get($primaryControl->modelVersion->metadata, 'control_contract.execution_hash'));
        $primary = $generation->agents->where('origin', 'academy_experiment');
        $this->assertCount($primaryCount, $primary);
        foreach ($primary as $member) {
            $metadata = (array) $member->modelVersion->metadata;
            $this->assertArrayNotHasKey('control_pair_contract', $metadata);
            $this->assertSame('academy_exact_control_admission_v1', data_get($metadata, 'academy_control_admission.protocol'));
            $this->assertSame($primaryControl->id, data_get($metadata, 'academy_control_admission.control_agent_id'));
            $this->assertFalse(data_get($metadata, 'academy_control_admission.economic_pair'));
            $this->assertFalse(data_get($metadata, 'academy_control_admission.credit_authority'));
            $this->assertFalse(data_get($metadata, 'academy_control_admission.promotion_evidence'));
            if ($member->id === $primaryControl->id) {
                $this->assertArrayNotHasKey('learning_receipt', $metadata);
                continue;
            }
            $this->assertSame($primaryControl->id, data_get($metadata, 'learning_receipt.control_agent_id'));
            $this->assertNull(data_get($metadata, 'learning_receipt.historical_receipt_only'));
            $waiting = $admission->admission($member);
            $this->assertSame('waiting', $waiting['status']);
            $this->assertSame('FROZEN_CONTROL_REPLAY_PENDING', $waiting['reason']);
            $this->assertSame($primaryControl->id, $waiting['control_agent_id']);
        }
        // Preserve the exact source and compiled interventions, never claim
        // that pending controls constitute completed causal evidence.
        $this->assertSame($originalParameters, (array) $baseline->fresh()->parameters);
        $this->assertSame($staleMetadata, (array) $baseline->fresh()->metadata);
        $compiled = (array) data_get($generation->trigger_context, 'compiled_contract');
        foreach ($primary as $member) {
            $index = (int) data_get($member->modelVersion->metadata, 'academy_experiment.arm_index');
            $this->assertSame($compiled['arms'][$index]['runtime_parameters'], (array) $member->modelVersion->parameters);
            $this->assertSame($compiled['arms'][$index]['parameter_hash'], data_get($member->modelVersion->metadata, 'academy_experiment.parameter_hash'));
        }
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('causal_stage_mastery_assessments', 0);
        $this->assertDatabaseCount('evolutionary_authority_ledgers', 0);
        $this->assertDatabaseCount('agent_learning_causal_experiments', 0);
        $candidate = $generation->agents->first(fn ($member) => data_get($member->modelVersion->metadata, 'control_pair_contract.role') === 'candidate');
        $metadata = (array) $candidate->modelVersion->metadata;
        $metadata['control_pair_contract']['control_agent_id'] = 999999;
        $candidate->modelVersion->update(['metadata' => $metadata]);
        $candidate->unsetRelation('modelVersion')->load('modelVersion');
        $this->assertSame('blocked', $admission->admission($candidate)['status']);
        $this->assertSame('FROZEN_CONTROL_IDENTITY_MISMATCH', $admission->admission($candidate)['reason']);
        Queue::assertNothingPushed();
    }

    public static function topologies(): array
    {
        return ['actual four-arm plus sixteen kernel' => ['pullback_rejection', 4, 0],
            'three-arm plus honest abstain seat' => ['liquidity_sweep_reclaim', 3, 1]];
    }
}
