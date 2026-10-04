<?php

namespace Tests\Feature;

require_once __DIR__.'/AcademyMtfValidatorReplacementTest.php';

use App\Models\LabEvidenceArtifact;
use App\Models\LabEvaluationRun;
use App\Services\AcademyExperimentContractCompilerService;
use App\Services\AcademyExperimentMaterializerService;
use App\Services\CausalCompoundingKernelService;
use App\Services\LearningVelocityGateService;
use App\Services\ResearchExperimentConversionKernelService;
use App\Services\TechnicalFailureClassifierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/** Stack-local reuse of real immutable cohort evidence, never a durable authority cache. */
class AcademyValidatorReadinessBatchTest extends TestCase
{
    use RefreshDatabase;

    private ?AcademyMtfValidatorReplacementTest $fixture = null;

    protected function tearDown(): void
    {
        if ($this->fixture !== null) {
            $dependency = (new \ReflectionProperty($this->fixture, 'dependencyFixture'))->getValue($this->fixture);
            if ($dependency !== null) {
                foreach ((new \ReflectionProperty($dependency, 'fixtureDirectories'))->getValue($dependency) as $path) {
                    File::deleteDirectory($path);
                }
            }
        }
        parent::tearDown();
    }

    private function cohort(): array
    {
        $this->fixture = new AcademyMtfValidatorReplacementTest('test_one_native_validator_repair_preserves_exact_twenty_vectors_and_cannot_chain');

        return (new \ReflectionMethod($this->fixture, 'failedValidator'))->invoke($this->fixture);
    }

    private function countedOwner(int $calls): void
    {
        $owner = Mockery::mock(AcademyExperimentMaterializerService::class, [
            app(AcademyExperimentContractCompilerService::class),
            app(ResearchExperimentConversionKernelService::class),
            app(CausalCompoundingKernelService::class),
        ])->makePartial();
        $owner->shouldReceive('validatorTerminalDispositionForGeneration')->times($calls)->passthru();
        app()->instance(AcademyExperimentMaterializerService::class, $owner);
    }

    public function test_velocity_reuses_one_fresh_proof_for_both_twenty_sibling_counters(): void
    {
        $cohort = $this->cohort();
        $this->countedOwner(1);
        config()->set('services.lab_selection.learning_velocity_lookback_generations', 1);
        $before = $cohort['generation']->fresh('agents.modelVersion')->toArray();

        $result = app(LearningVelocityGateService::class)->inspect($cohort['generation']->laboratory);

        $this->assertCount(1, $result['observations']);
        $observation = $result['observations'][0];
        $this->assertSame(20, $observation['agent_count']);
        $this->assertSame(0, $observation['technical_agents']);
        $this->assertSame(0, $observation['capability_quarantined_agents']);
        $this->assertSame(0, $result['technical_recovery_agents']);
        $this->assertFalse($result['promotion_evidence']);
        $this->assertSame($before, $cohort['generation']->fresh('agents.modelVersion')->toArray());
    }

    public function test_independent_batches_reread_compressed_bytes_even_on_the_same_service_instance(): void
    {
        $cohort = $this->cohort();
        $this->countedOwner(2);
        $classifier = app(TechnicalFailureClassifierService::class);
        $agents = $cohort['generation']->fresh('agents.modelVersion')->agents;

        $first = $classifier->forAgents($agents);
        $this->assertCount(20, $first);
        foreach ($first as $classification) {
            $this->assertSame('IMMUTABLE_ACADEMY_MTF_VALIDATOR_REFUSAL', $classification['reason_code']);
            $this->assertSame(TechnicalFailureClassifierService::TERMINAL, $classification['class']);
            $this->assertFalse($classification['blocks_global_generation']);
            $this->assertFalse($classification['scientific_outcome_observed']);
        }
        $artifact = LabEvidenceArtifact::query()->where('lab_generation_id', $cohort['generation']->id)
            ->where('artifact_type', 'evaluation_response')->oldest('id')->firstOrFail();
        Storage::disk(data_get($artifact->metadata, 'storage_disk'))->put($artifact->storage_path, 'corrupted immutable gzip');

        $second = $classifier->forAgents($agents);
        $this->assertCount(20, $second);
        foreach ($second as $classification) {
            $this->assertNotSame('IMMUTABLE_ACADEMY_MTF_VALIDATOR_REFUSAL', $classification['reason_code']);
            $this->assertTrue($classification['blocks_global_generation']);
        }
    }

    public function test_direct_agent_classification_is_fresh_after_an_earlier_batch(): void
    {
        $cohort = $this->cohort();
        $this->countedOwner(2);
        $classifier = app(TechnicalFailureClassifierService::class);
        $agents = $cohort['generation']->fresh('agents.modelVersion')->agents;
        $first = $classifier->forAgents($agents);
        $this->assertSame('IMMUTABLE_ACADEMY_MTF_VALIDATOR_REFUSAL', $first[$agents->first()->id]['reason_code']);
        $artifact = LabEvidenceArtifact::query()->where('lab_generation_id', $cohort['generation']->id)
            ->where('artifact_type', 'evaluation_response')->oldest('id')->firstOrFail();
        Storage::disk(data_get($artifact->metadata, 'storage_disk'))->put($artifact->storage_path, 'corrupted immutable gzip');

        $fresh = $classifier->forAgent($agents->first());

        $this->assertNotSame('IMMUTABLE_ACADEMY_MTF_VALIDATOR_REFUSAL', $fresh['reason_code']);
        $this->assertTrue($fresh['blocks_global_generation']);
    }

    public function test_partial_batch_cannot_lend_a_valid_cohort_disposition_to_an_out_of_roster_agent(): void
    {
        $cohort = $this->cohort();
        $this->countedOwner(1);
        $agents = $cohort['generation']->fresh('agents.modelVersion')->agents;
        $outsider = clone $agents->first();
        $outsider->id = 999999;
        $outsider->decision_reason = 'unclassified evaluator error';

        $classifications = app(TechnicalFailureClassifierService::class)->forAgents($agents->take(2)->concat([$outsider]));

        $this->assertCount(3, $classifications);
        $this->assertSame('IMMUTABLE_ACADEMY_MTF_VALIDATOR_REFUSAL', $classifications[$agents->first()->id]['reason_code']);
        $this->assertNotSame('IMMUTABLE_ACADEMY_MTF_VALIDATOR_REFUSAL', $classifications[$outsider->id]['reason_code']);
        $this->assertTrue($classifications[$outsider->id]['blocks_global_generation']);
    }

    public function test_ordinary_generation_batch_preserves_direct_classification_without_academy_proof(): void
    {
        $cohort = $this->cohort();
        $cohort['generation']->update(['trigger_type' => 'new_data']);
        $this->countedOwner(0);
        $classifier = app(TechnicalFailureClassifierService::class);
        $agents = $cohort['generation']->fresh('agents.modelVersion')->agents;

        $classifications = $classifier->forAgents($agents);

        $this->assertCount(20, $classifications);
        foreach ($agents as $agent) {
            $this->assertSame($classifier->forAgent($agent), $classifications[$agent->id]);
        }
    }

    public function test_valid_academy_batch_has_exact_direct_classification_parity(): void
    {
        $cohort = $this->cohort();
        $this->countedOwner(21);
        $classifier = app(TechnicalFailureClassifierService::class);
        $agents = $cohort['generation']->fresh('agents.modelVersion')->agents;

        $classifications = $classifier->forAgents($agents);

        foreach ($agents as $agent) {
            $this->assertSame($classifier->forAgent($agent), $classifications[$agent->id]);
        }
    }

    public function test_invalid_proof_is_reused_only_inside_the_current_batch(): void
    {
        $cohort = $this->cohort();
        LabEvaluationRun::where('lab_generation_id', $cohort['generation']->id)->oldest('id')->firstOrFail()
            ->update(['metrics' => ['profit_factor' => 0]]);
        $this->countedOwner(2);
        $classifier = app(TechnicalFailureClassifierService::class);
        $agents = $cohort['generation']->fresh('agents.modelVersion')->agents;

        $classifications = $classifier->forAgents($agents);

        foreach ($classifications as $classification) {
            $this->assertNotSame('IMMUTABLE_ACADEMY_MTF_VALIDATOR_REFUSAL', $classification['reason_code']);
        }
        $fresh = $classifier->forAgent($agents->first());
        $this->assertSame($classifications[$agents->first()->id], $fresh);
    }

    public function test_mixed_generations_cannot_share_a_proof_or_recompute_a_null_proof_per_sibling(): void
    {
        $cohort = $this->cohort();
        $agents = $cohort['generation']->fresh('agents.modelVersion')->agents;
        $otherGeneration = $cohort['generation']->replicate();
        // A caller can hold a stale or missing generation relation. It must
        // not borrow the original roster's proof or mutate its retry budget.
        $otherGeneration->id = 999997;
        $otherGeneration->generation += 1;
        $outsiders = collect([999995, 999996])->map(function (int $id) use ($agents, $otherGeneration) {
            $agent = clone $agents->first();
            $agent->id = $id;
            $agent->lab_generation_id = $otherGeneration->id;
            $agent->decision_reason = 'unclassified evaluator error';
            $agent->setRelation('generation', $otherGeneration);

            return $agent;
        });
        $this->countedOwner(2);

        $classifications = app(TechnicalFailureClassifierService::class)->forAgents($agents->concat($outsiders));

        $this->assertCount(22, $classifications);
        foreach ($agents as $agent) {
            $this->assertSame('IMMUTABLE_ACADEMY_MTF_VALIDATOR_REFUSAL', $classifications[$agent->id]['reason_code']);
        }
        foreach ($outsiders as $agent) {
            $this->assertNotSame('IMMUTABLE_ACADEMY_MTF_VALIDATOR_REFUSAL', $classifications[$agent->id]['reason_code']);
            $this->assertTrue($classifications[$agent->id]['blocks_global_generation']);
        }
    }

    public function test_a_previous_batch_cannot_authorize_artifact_corruption_at_locked_materialization(): void
    {
        $cohort = $this->cohort();
        (new \ReflectionMethod($this->fixture, 'changedSource'))->invoke($this->fixture);
        $realOwner = app(AcademyExperimentMaterializerService::class);
        $agents = $cohort['generation']->fresh('agents.modelVersion')->agents;
        $classifications = app(TechnicalFailureClassifierService::class)->forAgents($agents);
        $this->assertSame('IMMUTABLE_ACADEMY_MTF_VALIDATOR_REFUSAL', $classifications[$agents->first()->id]['reason_code']);
        $proposal = $realOwner->coldStartProposal();
        $this->assertSame('would_prepare_cold_start', $proposal['status']);
        $decision = (new \ReflectionMethod($this->fixture, 'open'))->invoke($this->fixture, $proposal, 'locked-batch-freshness');
        $artifact = LabEvidenceArtifact::query()->where('lab_generation_id', $cohort['generation']->id)
            ->where('artifact_type', 'evaluation_response')->oldest('id')->firstOrFail();
        $owner = Mockery::mock(AcademyExperimentMaterializerService::class, [
            app(AcademyExperimentContractCompilerService::class),
            app(ResearchExperimentConversionKernelService::class),
            app(CausalCompoundingKernelService::class),
        ])->makePartial();
        $calls = 0;
        $owner->shouldReceive('coldStartProposal')->twice()->andReturnUsing(function (string $symbol, string $timeframe) use ($realOwner, $artifact, &$calls): array {
            if (++$calls === 2) {
                Storage::disk(data_get($artifact->metadata, 'storage_disk'))->put($artifact->storage_path, 'corrupted after preflight before locked proof');
            }

            return $realOwner->coldStartProposal($symbol, $timeframe);
        });
        $generationsBefore = DB::table('lab_generations')->count();

        $result = $owner->prepareColdStart($decision);

        $this->assertSame(2, $calls);
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('ACADEMY_TECHNICAL_REPLACEMENT_CHANGED_UNDER_LOCK', $result['reason']);
        $this->assertDatabaseCount('edge_academy_trials', 1);
        $this->assertDatabaseCount('lab_generations', $generationsBefore);
        $this->assertDatabaseCount('evolutionary_authority_ledgers', 0);
        Queue::assertNothingPushed();
    }
}
