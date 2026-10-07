<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\ResearchExperimentReceipt;
use App\Models\ResearchExperimentWorkItem;
use App\Services\ExecutionContractService;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabQueueJobInspector;
use App\Services\ProspectiveRepairProbeWindowService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\ResearchReleaseSealService;
use App\Services\SpecialistCouncilLifecycleService;
use App\Services\SpecialistCouncilResearchFeedbackService;
use Illuminate\Support\Facades\DB;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Conditional original-producer ownership fixture, not market/skill evidence. */
class SpecialistCouncilObservedProbeCompletionTest extends TestCase
{
    use RefreshDatabase;
    public function test_observed_probe_completion_seals_one_explicit_observed_completion_without_credit(): void
    {
        [$work, $proposal, $version, $runs] = $this->observedProbeFixture();
        $before = $runs->map(fn ($run) => $run->getRawOriginal())->all();
        $owner = app(SpecialistCouncilResearchFeedbackService::class);
        $ready = $owner->registerFollowupProof($work->id, $proposal, 'synthetic-operator');
        $this->assertSame('ready', $ready['status']);
        $this->assertSame('observed_probe_attestation_completion', $ready['scientific_question_kind']);
        $this->assertTrue($ready['scientific_outcomes_may_have_been_observed']);
        $this->assertTrue($ready['same_physical_question_acknowledged']);
        $this->assertFalse($ready['scientific_novelty_claimed']);
        $this->assertFalse($ready['scientific_budget_renewed']);
        $this->assertFalse($ready['independent_evidence_claimed']);
        $this->assertSame(1, $ready['original_observed_probe_completion_proof']['max_completions_per_root']);
        $this->assertSame($version->id, $ready['original_observed_probe_completion_proof']['root']['root_version_id']);
        $this->assertCount(3, $ready['original_observed_probe_completion_proof']['original_arm_witnesses']);
        $this->assertSame($ready['resolution_hash'], $owner->registerFollowupProof($work->id, $proposal)['resolution_hash']);
        $this->assertSame($before, $runs->map(fn ($run) => $run->fresh()->getRawOriginal())->all());
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        $this->assertDatabaseCount('lab_generations', 1);
        $this->assertDatabaseCount('research_experiment_work_items', 1);
    }

    #[DataProvider('poisonCases')]
    public function test_observed_probe_completion_refuses_incomplete_relabelled_or_mutated_originals(string $poison): void
    {
        [$work, $proposal, $version, $runs] = $this->observedProbeFixture();
        if ($poison === 'missing_arm') $runs[2]->delete();
        elseif ($poison === 'completed_arm') $runs[0]->update(['status' => 'completed']);
        elseif ($poison === 'other_refusal') $runs[0]->update(['error_message' => 'OTHER_ERROR']);
        elseif ($poison === 'generic_relabel') {
            $meta = $runs[0]->request_meta;
            data_set($meta, 'dataset_manifest.prospective_probe_window', $proposal['evaluation_plan']['windows']['original']['prospective_probe_window']);
            $runs[0]->update(['request_meta' => $meta]);
        } elseif ($poison === 'model_drift') {
            $model = $runs[0]->modelVersion;
            $model->update(['parameters' => [...$model->parameters, 'ema_fast' => 7]]);
        } elseif ($poison === 'retune') $proposal['parameter_deltas'] = ['hour' => ['ema_fast' => 5]];
        elseif ($poison === 'window_change') $proposal['evaluation_plan']['windows']['original']['start_inclusive'] = '2024-01-01T00:00:00Z';
        elseif ($poison === 'wrong_kind') $proposal['continuation_kind'] = 'same_question_source_repair';
        elseif ($poison === 'active_cohort') $runs[0]->agent->generation->update(['status' => 'screening', 'completed_at' => null]);
        elseif ($poison === 'queued_old_arm') $this->mock(LabQueueJobInspector::class)->shouldReceive('generationQueueBacklog')->andReturn(['total' => 1]);
        elseif ($poison === 'missing_original_archive') {
            $this->partialMock(ResearchReleaseSealService::class)->shouldReceive('verifySourceArtifact')->andThrow(new \RuntimeException('SOURCE_ARTIFACT_ARCHIVE_MISSING'));
        } elseif ($poison === 'missing_current_archive') {
            $this->partialMock(ResearchReleaseSealService::class,function($mock){
                $mock->shouldReceive('pythonHash')->andReturn(str_repeat('a',64));
                $mock->shouldReceive('verifySourceArtifact')->andReturnUsing(fn($ref)=>['status'=>'verified','manifest'=>['source_identity'=>['source_hash'=>$ref['source_hash'],'python_source_hash'=>$ref['python_source_hash']]]]);
                $mock->shouldReceive('currentSourceArtifact')->andReturnNull();
            });
        }
        elseif ($poison === 'same_source') {
            $this->partialMock(LabImmutableEvidenceService::class)->shouldReceive('codeHash')->andReturn(str_repeat('e',64));
        }
        try {
            app(SpecialistCouncilResearchFeedbackService::class)->registerFollowupProof($work->id, $proposal);
            $this->fail('Poisoned observed completion admitted: '.$poison);
        } catch (\RuntimeException $error) {
            $this->assertMatchesRegularExpression('/^(COUNCIL_|SOURCE_ARTIFACT_)/', $error->getMessage());
        } catch (\LogicException $error) {
            $this->assertStringStartsWith('COUNCIL_', $error->getMessage());
        }
        $this->assertNull(data_get($work->fresh()->payload, 'followup_resolution'));
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public static function poisonCases(): array
    {
        return array_map(fn ($case) => [$case], ['missing_arm', 'completed_arm', 'other_refusal', 'generic_relabel',
            'model_drift', 'retune', 'window_change', 'wrong_kind', 'active_cohort', 'queued_old_arm', 'same_source',
            'missing_original_archive', 'missing_current_archive']);
    }

    public function test_observed_probe_completion_once_cap_survives_new_work_id_changed_source_and_failed_target(): void
    {
        [$work, $proposal, $version, $runs] = $this->observedProbeFixture();
        $owner = app(SpecialistCouncilResearchFeedbackService::class);
        $ready = $owner->registerFollowupProof($work->id, $proposal);
        // The first immutable claim remains spent even if its target never succeeds.
        $work->update(['status' => 'failed', 'completed_at' => now()]);
        $copy = $work->replicate(); $copy->work_key = hash('sha256','second-synthetic-receipt');
        $copy->status = 'blocked'; $copy->completed_at = null; $copy->result = null;
        $payload = $copy->payload; unset($payload['followup_resolution']); $copy->payload = $payload; $copy->save();
        $this->partialMock(LabImmutableEvidenceService::class)->shouldReceive('codeHash')->andReturn(str_repeat('9',64));
        $this->expectExceptionMessage('COUNCIL_OBSERVED_PROBE_COMPLETION_GLOBAL_ONCE_CAP_EXHAUSTED');
        $owner->registerFollowupProof($copy->id, $proposal);
    }

    public function test_observed_probe_completion_readiness_rechecks_original_projection_and_archive(): void
    {
        [$work, $proposal, $version, $runs] = $this->observedProbeFixture();
        $owner = app(SpecialistCouncilResearchFeedbackService::class);
        $owner->registerFollowupProof($work->id, $proposal);
        $metadata = $runs[1]->request_meta;
        data_set($metadata, 'dataset_manifest.prospective_probe_window.loaded_rows', 1);
        $runs[1]->update(['request_meta' => $metadata]);
        $proof = $owner->inspectFollowupReadiness($work->fresh());
        $this->assertSame('blocked', $proof['status']);
        $this->assertSame('COUNCIL_OBSERVED_PROBE_COMPLETION_ORIGINAL_OWNER_COLLISION_NOT_PROVEN', $proof['reason']);
    }

    public function test_observed_probe_completion_root_link_cannot_reset_the_once_cap(): void
    {
        [$work,$proposal,$version,$runs]=$this->observedProbeFixture();
        $owner=app(SpecialistCouncilResearchFeedbackService::class);
        $ready=$owner->registerFollowupProof($work->id,$proposal);
        $generation=$runs[0]->agent->generation;
        $context=$generation->trigger_context;
        $context['native_specialist_council_intent']=['followup_work_item_id'=>$work->id,'followup_resolution_hash'=>$ready['resolution_hash']];
        $generation->update(['trigger_context'=>$context]);
        $method=new \ReflectionMethod($owner,'observedProbeCompletionRoot');
        $this->expectExceptionMessage('COUNCIL_OBSERVED_PROBE_COMPLETION_GLOBAL_ONCE_CAP_EXHAUSTED');
        $method->invoke($owner,$version,$generation,$proposal['evaluation_plan']);
    }

    public function test_observed_probe_completion_tampered_sealed_observation_acknowledgement_is_not_ready(): void
    {
        [$work,$proposal]=$this->observedProbeFixture();
        $owner=app(SpecialistCouncilResearchFeedbackService::class); $owner->registerFollowupProof($work->id,$proposal);
        $payload=$work->fresh()->payload; data_set($payload,'followup_resolution.scientific_outcomes_may_have_been_observed',false);
        $work->update(['payload'=>$payload]);
        $this->assertSame('COUNCIL_FOLLOWUP_ORIGINAL_RESOLUTION_DRIFT',$owner->inspectFollowupReadiness($work->fresh())['reason']);
    }

    public function test_observed_probe_completion_accepts_mysql_object_key_order_without_rehashing_original_probe(): void
    {
        [$work,$proposal,$version]=$this->observedProbeFixture();
        $row=DB::table('specialist_council_evaluation_plans')->where('specialist_council_version_id',$version->id)->sole();
        $plan=json_decode($row->plan,true); $oldHash=$plan['windows']['original']['prospective_probe_window']['contract_hash'];
        ksort($plan['windows']['original']['prospective_probe_window']);
        DB::table('specialist_council_evaluation_plans')->where('id',$row->id)->update(['plan'=>json_encode($plan)]);
        $ready=app(SpecialistCouncilResearchFeedbackService::class)->registerFollowupProof($work->id,$proposal);
        $this->assertSame('ready',$ready['status']);
        $this->assertSame($oldHash,$ready['original_observed_probe_completion_proof']['original_arm_witnesses'][0]['original_probe_hash']);
    }

    public function test_observed_probe_completion_physical_identity_excludes_only_strategy_display_label(): void
    {
        [$work,$proposal,$version]=$this->observedProbeFixture();
        $owner=app(SpecialistCouncilResearchFeedbackService::class);
        $method=new \ReflectionMethod($owner,'observedCompletionQuestionFingerprint');
        $original=$method->invoke($owner,$version->manifest,$proposal['evaluation_plan']);
        $renamed=$version->manifest;
        foreach($renamed['members']as&$member)$member['strategy']='display-label-g999-'.$member['role'];unset($member);
        $this->assertSame($original,$method->invoke($owner,$renamed,$proposal['evaluation_plan']));
        $this->assertNotSame($owner->questionFingerprint($version->manifest,$proposal['evaluation_plan']),$owner->questionFingerprint($renamed,$proposal['evaluation_plan']));
        $renamed['members'][0]['parameters']['ema_fast']=8;
        $this->assertNotSame($original,$method->invoke($owner,$renamed,$proposal['evaluation_plan']));
    }

    #[DataProvider('lineagePoisonCases')]
    public function test_observed_probe_completion_refuses_broken_ancestor_or_cyclic_original_links(string $poison): void
    {
        [$work,$proposal,$version,$runs]=$this->observedProbeFixture();
        $owner=app(SpecialistCouncilResearchFeedbackService::class); $ready=$owner->registerFollowupProof($work->id,$proposal);
        $body=data_get($work->fresh()->payload,'followup_resolution');
        // A conditional server-resealed old kind isolates lineage guards from the once-cap guard.
        $body['scientific_question_kind']='new_prospective_discovery_informed_by_original_observation';
        if($poison==='ancestor_hash')$body['source_manifest_hash']=str_repeat('0',64);
        unset($body['resolution_hash'],$body['server_seal']);
        $body['resolution_hash']=app(ResearchPaperEpochContractService::class)->parameterHash($body);
        $seal=new \ReflectionMethod($owner,'followupServerSeal'); $body['server_seal']=$seal->invoke($owner,$body);
        $work->update(['payload'=>[...$work->fresh()->payload,'followup_resolution'=>$body]]);
        $generation=$runs[0]->agent->generation; $context=$generation->trigger_context;
        $context['native_specialist_council_intent']=['followup_work_item_id'=>$work->id,'followup_resolution_hash'=>$body['resolution_hash']];
        $generation->update(['trigger_context'=>$context]);
        $method=new \ReflectionMethod($owner,'observedProbeCompletionRoot');
        $this->expectExceptionMessage($poison==='ancestor_hash'?'COUNCIL_OBSERVED_PROBE_COMPLETION_ORIGINAL_LINEAGE_DRIFT':'COUNCIL_OBSERVED_PROBE_COMPLETION_LINEAGE_CYCLE');
        $method->invoke($owner,$version,$generation,$proposal['evaluation_plan']);
    }

    public static function lineagePoisonCases():array { return [['ancestor_hash'],['cycle']]; }

    private function observedProbeFixture(): array
    {
        // Reuse the established exact native manifest/three-arm construction fixture.
        // Sharing only its isolated app avoids inheriting/rerunning the entire old test suite.
        $fixtureCase = new SpecialistCouncilFollowupReadinessTest('test_ready_work_is_not_executable_without_original_owner_proof');
        $appProperty = new \ReflectionProperty(TestCase::class, 'app');
        $appProperty->setValue($fixtureCase, $appProperty->getValue($this));
        $fixture = new \ReflectionMethod(SpecialistCouncilFollowupReadinessTest::class, 'fixture');
        [$oldWork, $proposal, $version, $models] = $fixture->invoke($fixtureCase, true, 'technical_unassessable');
        $epochs = app(ResearchPaperEpochContractService::class);
        $evidence = app(LabImmutableEvidenceService::class);
        $planRow = DB::table('specialist_council_evaluation_plans')->where('specialist_council_version_id',$version->id)->sole();
        $plan = json_decode($planRow->plan, true);
        $rows = [];
        for ($index=0; $index<15512; $index++) $rows[]=['time'=>gmdate('c',strtotime('2025-01-01T00:00:00Z')+$index*300)];
        $probe = app(ProspectiveRepairProbeWindowService::class)->seal($rows,str_repeat('c',64),str_repeat('d',64),'original-council-probe',15000,512);
        $scope=['calendar'=>array_intersect_key($probe,array_flip(['loaded_rows','warmup_rows','evaluated_rows','loaded_start','loaded_end','evaluated_start','evaluated_end','evaluated_month_counts'])),
            'data_role'=>'pre_2026_discovery_only']; $scope['scope_hash']=app(ExecutionContractService::class)->hashParameters($scope);
        $plan['windows']['original']['prospective_probe_window']=$probe;
        $plan['windows']['original']['start_inclusive']=$probe['evaluated_start'];
        $plan['windows']['original']['end_exclusive']=gmdate('c',strtotime($probe['evaluated_end'])+300);
        unset($plan['plan_hash']); $planHash=$epochs->parameterHash($plan);
        DB::table('specialist_council_evaluation_plans')->where('id',$planRow->id)->update(['plan'=>json_encode($plan),'plan_hash'=>$planHash]);
        $lab=AiLaboratory::create(['symbol'=>'XAUUSD','timeframe'=>'H1','name'=>'observed collision synthetic','strategy_families'=>['ema_rsi'],'is_active'=>false]);
        $preparation=['version_id'=>$version->id,'plan_hash'=>$planHash,'manifest_hash'=>$version->manifest_hash,'preparation_source_hash'=>str_repeat('e',64)];
        $preparation['receipt_hash']=$epochs->parameterHash($preparation);
        $generation=LabGeneration::create(['ai_laboratory_id'=>$lab->id,'generation'=>1,'population_size'=>6,'trigger_type'=>'historical_research',
            'status'=>'technical_quarantine','completed_at'=>now(),'trigger_context'=>['specialist_council_preparation'=>$preparation]]);
        $agents=[];
        foreach($models as $role=>$model)$agents[$role]=LabAgent::create(['lab_generation_id'=>$generation->id,'model_version_id'=>$model->id,
            'symbol'=>'XAUUSD','timeframe'=>'H1','strategy_family'=>'ema_rsi','origin'=>'native_council_root','lifecycle_status'=>'technical_quarantine','parameter_diff'=>[]]);
        $oldArchive=['source_hash'=>str_repeat('e',64),'python_source_hash'=>str_repeat('b',64),'artifact_hash'=>str_repeat('1',64)];
        $currentArchive=['source_hash'=>str_repeat('f',64),'python_source_hash'=>str_repeat('a',64),'artifact_hash'=>str_repeat('2',64)];
        $this->partialMock(ResearchReleaseSealService::class,function($mock)use($currentArchive){
            $mock->shouldReceive('pythonHash')->andReturn(str_repeat('a',64));
            $mock->shouldReceive('currentSourceArtifact')->andReturn($currentArchive);
            $mock->shouldReceive('verifySourceArtifact')->andReturnUsing(fn($ref)=>['status'=>'verified','manifest'=>['source_identity'=>['source_hash'=>$ref['source_hash'],'python_source_hash'=>$ref['python_source_hash']]]]);
        });
        $this->mock(LabQueueJobInspector::class)->shouldReceive('generationQueueBacklog')->andReturn(['total'=>0]);
        $runs=collect();
        foreach($plan['arms'] as $key=>$arm){
            $agent=collect($agents)->firstWhere('model_version_id',$arm['model_version_id']); $model=$agent->modelVersion;
            $run=LabEvaluationRun::create(['run_id'=>'observed-collision-'.$key,'lab_generation_id'=>$generation->id,'lab_agent_id'=>$agent->id,
                'model_version_id'=>$model->id,'phase'=>'screening','mode'=>'incremental','status'=>'started','started_at'=>now(),
                'code_hash'=>str_repeat('e',64),'data_hash'=>str_repeat('c',64),'parameter_hash'=>$evidence->parameterHash($agent)]);
            $seal=['protocol'=>ResearchReleaseSealService::PROTOCOL,'source_hash'=>str_repeat('e',64),'python_source_hash'=>str_repeat('b',64),
                'dataset_hash'=>str_repeat('c',64),'source_artifact'=>$oldArchive];
            $seal['release_hash']=app(ExecutionContractService::class)->hashParameters($seal);
            $seal['sealed_at']=now()->subMinute()->utc()->toIso8601String();
            $request=['replay_dataset_hash'=>str_repeat('c',64),'execution_hash'=>str_repeat('d',64),'initial_balance'=>$plan['initial_capital'],
                'cost_model'=>$plan['cost_model'],'risk_policy'=>$plan['risk_policy'],'research_release'=>$seal,
                'policy_context'=>['prospective_probe_window'=>$probe,'prospective_clean_discovery_scope'=>$scope],
                'strategies'=>[['lab_agent_id'=>$agent->id,'strategy'=>$model->strategy,'parameters'=>$model->parameters,
                    'specialist_council_evaluation'=>['version_id'=>$version->id,'manifest_hash'=>$version->manifest_hash,'plan_hash'=>$planHash,'arm_key'=>$key]]]];
            $generic=$probe; $generic['experiment_key']='academy_clean_discovery:'.str_repeat('c',64).':'.$scope['scope_hash']; unset($generic['contract_hash']);
            $generic['contract_hash']=hash('sha256',json_encode($generic,JSON_UNESCAPED_SLASHES));
            $evidence->attachRequest($run,$request,['dataset_manifest'=>['prospective_probe_window'=>$generic]]);
            $evidence->finishRun($run,'technical_error',null,[],['reason_code'=>'BATCH_REPLAY_TRANSPORT_FAILURE'],new \RuntimeException('PROSPECTIVE_PROBE_WINDOW_RECEIPT_MISMATCH'));
            $runs->push($run->fresh());
        }
        $assessment=$version->assessment; $assessment['plan_hash']=$planHash; $assessment['original_run_ids']=$runs->pluck('run_id')->all();
        $assessmentHash=$epochs->parameterHash($assessment);
        DB::table('specialist_council_evaluations')->where('specialist_council_version_id',$version->id)->update([
            'original_run_ids'=>json_encode($assessment['original_run_ids']),
            'assessment'=>json_encode($assessment),'assessment_hash'=>$assessmentHash]);
        $version->update(['assessment'=>$assessment,'assessment_hash'=>$assessmentHash]);
        DB::table('research_knowledge_entries')->delete(); DB::table('research_experiment_work_items')->delete(); DB::table('research_experiment_receipts')->delete();
        app(SpecialistCouncilResearchFeedbackService::class)->recordAssessment($version->fresh());
        $proposal['evaluation_plan']=$plan; $proposal['continuation_kind']='observed_probe_attestation_completion'; $proposal['parameter_deltas']=[];
        return [ResearchExperimentWorkItem::sole(),$proposal,$version->fresh(),$runs];
    }
}
