<?php
namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\LabImmutableEvidenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OriginalModelRuntimeIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_request_preserves_raw_transport_hash_and_freezes_real_nested_treatment_and_generated_contract(): void
    {
        [$run, $model] = $this->fixtureRun(); $owner = app(LabImmutableEvidenceService::class);
        $request = ['symbol' => 'XAUUSD', 'parameters' => $model->parameters, 'candles' => [], 'regime_candles' => null,
            'composition_runtime_contract' => ['protocol' => 'owner_generated_test_contract', 'components' => ['strategy_id' => 'actual']]];
        $owner->attachRequest($run, $request);
        $this->assertSame($owner->hash($request), $run->fresh()->request_hash);
        $requestArtifact = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'evaluation_request')->firstOrFail();
        $this->assertNotSame($run->fresh()->request_hash, $requestArtifact->sha256);
        $seal = $owner->verifiedModelRuntimeIdentity($run->fresh()); $this->assertNotNull($seal);
        $this->assertSame('actual', data_get($seal, 'runtime_basis.components.smart_composition_treatment.components.strategy_id'));
        $this->assertSame($requestArtifact->sha256, $seal['request_artifact_hash']);
        $this->assertSame(app(\App\Services\ResearchPaperEpochContractService::class)->parameterHash($request['composition_runtime_contract']), $seal['compiled_runtime_contract_hash']);
        $original = $model->metadata;
        $model->update(['metadata' => array_replace_recursive($original, ['smart_composition' => ['composition_passport' => ['data_hash' => 'another-window']]])]);
        $this->assertNotNull($owner->verifiedModelRuntimeIdentity($run->fresh()));
        $model->update(['metadata' => array_replace_recursive($original, ['smart_composition' => ['composition_passport' => ['components' => ['strategy_id' => 'changed']]]])]);
        $this->assertNull($owner->verifiedModelRuntimeIdentity($run->fresh()));
        $model->update(['metadata' => $original]);
        $model->update(['metadata' => array_replace_recursive($original, ['smart_composition' => [
            'composition_passport' => ['typed_program' => ['program_id' => 'changed-runtime-graph']]]])]);
        $this->assertNull($owner->verifiedModelRuntimeIdentity($run->fresh()));
        $model->update(['metadata' => $original]);
        $owner->finishRun($run, 'completed', ['total_trades' => 0]);
        $this->assertNotNull($owner->verifiedModelRuntimeIdentity($run->fresh()));
        $identity = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'model_runtime_identity')->firstOrFail();
        \Illuminate\Support\Facades\DB::table('lab_evidence_artifacts')->where('id', $identity->id)->update(['created_at' => $run->fresh()->finished_at->copy()->addSecond()]);
        $this->assertNull($owner->verifiedModelRuntimeIdentity($run->fresh()));
    }

    public function test_terminal_old_request_never_receives_a_backfilled_original_model_seal(): void
    {
        [$run] = $this->fixtureRun(); $owner = app(LabImmutableEvidenceService::class);
        $run->update(['status' => 'completed', 'finished_at' => now()]);
        $owner->attachRequest($run, ['symbol' => 'XAUUSD', 'candles' => []]);
        $this->assertSame(0, LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'model_runtime_identity')->count());
        $this->assertNull($owner->verifiedModelRuntimeIdentity($run->fresh()));
    }

    public function test_stale_started_instance_cannot_backfill_a_run_completed_by_another_callback(): void
    {
        [$run] = $this->fixtureRun(); $owner = app(LabImmutableEvidenceService::class);
        LabEvaluationRun::whereKey($run->id)->update(['status' => 'completed', 'finished_at' => now(),
            'request_hash' => str_repeat('a', 64), 'data_hash' => str_repeat('b', 64)]);
        $this->assertSame('started', $run->status);
        $owner->attachRequest($run, ['symbol' => 'XAUUSD', 'candles' => []]);
        $this->assertSame(0, LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'model_runtime_identity')->count());
        $this->assertNull($owner->verifiedModelRuntimeIdentity($run->fresh()));
        $this->assertSame(str_repeat('a', 64), $run->fresh()->request_hash);
        $this->assertSame(str_repeat('b', 64), $run->fresh()->data_hash);
        $this->assertSame(0, LabEvidenceArtifact::where('run_id', $run->run_id)->count());
    }

    private function fixtureRun(): array
    {
        Storage::fake('model_identity_test'); config()->set('services.lab_evidence.disk', 'model_identity_test');
        $lab = AiLaboratory::create(['name' => 'identity-fixture', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_families' => ['trend']]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1]);
        $model = ModelVersion::create(['name' => 'identity model', 'strategy' => 'trend_v1', 'version' => 'test', 'parameters' => ['atr_period' => 14],
            'metadata' => ['smart_composition' => ['composition_passport' => ['protocol' => 'xauusd_composition_authority_kernel_v1',
                'components' => ['strategy_id' => 'actual'], 'data_hash' => 'original-window']]]]);
        $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'trend', 'origin' => 'test', 'parameter_diff' => []]);
        $run = LabEvaluationRun::create(['run_id' => (string) \Illuminate\Support\Str::uuid(), 'lab_generation_id' => $generation->id,
            'lab_agent_id' => $agent->id, 'model_version_id' => $model->id, 'phase' => 'screening', 'mode' => 'test', 'attempt' => 1, 'status' => 'started']);
        return [$run, $model];
    }
}
