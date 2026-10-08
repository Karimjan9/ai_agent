<?php

namespace Tests\Feature;

use App\Jobs\ProcessLabScreeningLearningProjection;
use App\Models\CandidateGateDecision;
use App\Models\LabLifecycleEvent;
use App\Models\ResearchExperimentWorkItem;
use App\Models\ResearchExperimentReceipt;
use App\Services\LabImmutableEvidenceService;
use App\Services\ParentAwareCreditService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\SpecialistCouncilResearchFeedbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Conditional eligible publisher facts exercise no-credit projection authority, not market improvement. */
class SpecialistCouncilObservedProjectionGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_observed_completion_cannot_feed_the_behavior_archive_or_research_priority(): void
    {
        [$agent, $run] = $this->fixture();
        $owner = app(\App\Services\TypedInstrumentFoundryService::class);
        $this->assertSame('BEHAVIOR_SOURCE_DERIVED_LEARNING_WITHHELD', $owner->recordBehaviorOutcome($run->run_id)['reason']);
        $proposal = $owner->behaviorProposalEvidence('XAUUSD', 'H1');
        $this->assertSame([], $proposal['sources']);
        $this->assertSame(0.0, $proposal['priority_signal']);
        $this->assertDatabaseCount('research_behavior_archive', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_signed_observed_completion_skips_every_derived_projection_even_with_positive_eligible_economics(): void
    {
        [$agent,$run,$decision,$projection]=$this->fixture();
        $credit=app(ParentAwareCreditService::class)->recordScreening($agent,$projection,'passed');
        $this->assertSame('recorded',$credit['status']);
        $this->assertDatabaseHas('lab_evolution_credit_events',['event_type'=>'information_credit']);
        $this->assertDatabaseHas('lab_evolution_credit_events',['event_type'=>'repair_credit']);
        $originalCreditRows=DB::table('lab_evolution_credit_events')->orderBy('id')->get()->toJson();
        $originalRun=$run->getRawOriginal(); $originalMetadata=$agent->modelVersion->getRawOriginal('metadata');
        $this->expectNoProjections();
        $job=new ProcessLabScreeningLearningProjection($agent->id,$run->run_id,$decision->id,$projection);
        $this->app->call([$job,'handle']); $this->app->call([$job,'handle']);
        $this->assertSame($originalCreditRows,DB::table('lab_evolution_credit_events')->orderBy('id')->get()->toJson());
        $this->assertSame($originalRun,$run->fresh()->getRawOriginal());
        $this->assertSame($originalMetadata,$agent->modelVersion->fresh()->getRawOriginal('metadata'));
        $events=LabLifecycleEvent::where('event_type','screening_learning_projection_withheld')->get();
        $this->assertCount(1,$events);
        $this->assertSame('OBSERVED_COUNCIL_TECHNICAL_COMPLETION_WITHHOLDS_DERIVED_LEARNING',$events[0]->reason_code);
        foreach(['agent_knowledge_cards','lab_mutation_response_maps','agent_learning_settlements','agent_memories','provisional_skill_cartridges']as$table)
            if(\Illuminate\Support\Facades\Schema::hasTable($table))$this->assertDatabaseCount($table,0);
        $this->assertSame([],$decision->fresh()->metrics);
    }

    #[DataProvider('forgedCases')]
    public function test_malformed_declared_continuation_cannot_fall_through_to_generic_projections(string $poison): void
    {
        [$agent,$run,$decision,$projection,$work]=$this->fixture();
        if($poison==='body_forged'){
            $payload=$work->payload; data_set($payload,'followup_resolution.scientific_question_kind','new_prospective_discovery_informed_by_original_observation');
            $work->update(['payload'=>$payload]);
        }elseif($poison==='missing_work')$work->delete();
        elseif($poison==='missing_intent')$agent->generation->update(['trigger_context'=>[]]);
        elseif($poison==='missing_seed')$agent->modelVersion->update(['metadata'=>[]]);
        elseif($poison==='resolution_pointer'){
            $context=$agent->generation->trigger_context;data_set($context,'native_specialist_council_intent.followup_resolution_hash',str_repeat('0',64));
            $agent->generation->update(['trigger_context'=>$context]);
        }elseif($poison==='wrong_work_type')$work->update(['work_type'=>'specialist_council_independent_validation']);
        elseif($poison==='unknown_work_type')$work->update(['work_type'=>'unknown_council_work']);
        elseif($poison==='deleted_work_ids'){
            $context=$agent->generation->trigger_context;unset($context['native_specialist_council_intent']['followup_work_item_id']);
            $agent->generation->update(['trigger_context'=>$context]);
            $metadata=$agent->modelVersion->metadata;unset($metadata['native_specialist_council_seed']['followup_work_item_id']);
            $agent->modelVersion->update(['metadata'=>$metadata]);
        }
        $this->expectNoProjections();
        $this->app->call([new ProcessLabScreeningLearningProjection($agent->id,$run->run_id,$decision->id,$projection),'handle']);
        $this->assertDatabaseCount('lab_evolution_credit_events',0);
        $this->assertDatabaseHas('lab_lifecycle_events',['event_type'=>'screening_learning_projection_withheld',
            'reason_code'=>'COUNCIL_SCREENING_PROJECTION_DECLARED_OWNER_INVALID']);
    }

    public static function forgedCases():array {return array_map(fn($case)=>[$case],['body_forged','missing_work','missing_intent','missing_seed','resolution_pointer','wrong_work_type','unknown_work_type','deleted_work_ids']);}

    public function test_non_discovery_panel_keeps_its_original_projection_owner_domain():void
    {
        [$agent,$run,$decision,$projection,$work]=$this->fixture('independent_panel_original_owned_evidence');
        $work->update(['work_type'=>'specialist_council_independent_validation']);
        $this->assertTrue(app(SpecialistCouncilResearchFeedbackService::class)->screeningProjectionDisposition($agent,$run)['allow_derived_learning']);
    }

    #[DataProvider('ordinaryKinds')]
    public function test_ordinary_valid_learning_paths_keep_the_existing_projection_pipeline(?string $kind): void
    {
        [$agent,$run,$decision,$projection]=$this->fixture($kind);
        $owner=app(SpecialistCouncilResearchFeedbackService::class);
        $this->assertTrue($owner->screeningProjectionDisposition($agent->fresh(['generation','modelVersion']),$run)['allow_derived_learning']);
        // First real pipeline seam proves the new guard did not intercept ordinary learning.
        $this->mock(\App\Services\CooperativeExperimentSettlementService::class)->shouldReceive('observe')->once()->andThrow(new \RuntimeException('ORDINARY_PIPELINE_REACHED'));
        $this->expectExceptionMessage('ORDINARY_PIPELINE_REACHED');
        $this->app->call([new ProcessLabScreeningLearningProjection($agent->id,$run->run_id,$decision->id,$projection),'handle']);
    }

    public static function ordinaryKinds():array{return [[null],['unobserved_same_question_source_repair'],
        ['source_repair_completion_after_observed_auxiliary_source'],['new_prospective_discovery_informed_by_original_observation']];}

    private function fixture(?string $kind='observed_probe_attestation_completion'):array
    {
        Queue::fake();config(['services.internal_api.token'=>str_repeat('projection-fixture-key-',3)]);
        $legacy=new DecisionTraceLearningContractTest('test_zero_trade_wait_history_is_complete_but_sparse_or_duplicate_candle_coverage_is_not');
        $appProperty=new \ReflectionProperty(TestCase::class,'app');$appProperty->setValue($legacy,$appProperty->getValue($this));
        $factory=new \ReflectionMethod($legacy,'evidenceRun');[$agent,$run]=$factory->invoke($legacy);
        $bodyFactory=new \ReflectionMethod($legacy,'response');$response=$bodyFactory->invoke($legacy);
        $ledger=[['entry_time'=>'2025-10-01T00:00:00Z','exit_time'=>'2025-10-01T01:00:00Z','net_profit'=>50]];
        $response=[...$response,'total_trades'=>1,'trade_ledger'=>$ledger,'trades'=>$ledger,'displayed_trade_count'=>1,
            'trade_ledger_hash'=>hash('sha256',json_encode($ledger,JSON_UNESCAPED_SLASHES)),
            'net_profit'=>50,'profit_factor'=>2,'max_drawdown_percent'=>1,
            'verified_mutation_skill'=>['requirements'=>['target_gate_improved'=>true,'non_target_gates_preserved'=>true],
                'same_data_manifest'=>true,'same_execution_contract'=>true]];
        app(LabImmutableEvidenceService::class)->finishRun($run,'completed',$response);$run=$run->fresh();
        $this->assertTrue(app(LabImmutableEvidenceService::class)->learningEligibility($run)['complete']);
        $work=null;
        if($kind!==null){
            $receipt=ResearchExperimentReceipt::create(['receipt_key'=>'conditional-projection-receipt','source_type'=>\App\Models\SpecialistCouncilVersion::class,
                'source_id'=>1,'symbol'=>'XAUUSD','laboratory_timeframe'=>'H1','execution_timeframe'=>'M5','contract_version'=>'conditional_fixture',
                'rule_version'=>'conditional_fixture','contract_hash'=>str_repeat('a',64),'evidence_hash'=>str_repeat('b',64),
                'classification'=>'TECHNICAL_QUARANTINE','payload'=>[]]);
            $work=ResearchExperimentWorkItem::create(['work_key'=>hash('sha256','projection-work'),'work_type'=>'specialist_council_technical_repair',
                'research_experiment_receipt_id'=>$receipt->id,'symbol'=>'XAUUSD','timeframe'=>'H1',
                'status'=>'settled','priority'=>5,'attempts'=>1,'payload'=>[]]);
            $body=['protocol'=>SpecialistCouncilResearchFeedbackService::FOLLOWUP_PROTOCOL,'work_item_id'=>$work->id,'work_key'=>$work->work_key,
                'source_receipt_id'=>$work->research_experiment_receipt_id,'work_type'=>$work->work_type,'authority'=>'research_only',
                'max_experiments'=>1,'promotion_evidence'=>false,'independent_evidence_claimed'=>false,'scientific_question_kind'=>$kind,
                'scientific_outcomes_may_have_been_observed'=>true,'scientific_novelty_claimed'=>false,'scientific_budget_renewed'=>false,
                'original_observed_probe_completion_proof'=>['protocol'=>'specialist_council_observed_probe_completion_proof_v1',
                    'max_completions_per_root'=>1,'root'=>['root_version_id'=>1]]];
            $body['resolution_hash']=app(ResearchPaperEpochContractService::class)->parameterHash($body);
            $owner=app(SpecialistCouncilResearchFeedbackService::class);$seal=new \ReflectionMethod($owner,'followupServerSeal');
            $body['server_seal']=$seal->invoke($owner,$body);$work->update(['payload'=>['followup_resolution'=>$body]]);
            $intentHash=hash('sha256','exact-projection-intent');
            $intent=['protocol'=>\App\Services\LabPopulationService::NATIVE_COUNCIL_INTENT_PROTOCOL,
                'intent_hash'=>$intentHash,'followup_work_item_id'=>$work->id,'followup_resolution_hash'=>$body['resolution_hash']];
            $agent->update(['origin'=>'native_council_root']);
            $agent->generation->update(['trigger_context'=>['native_specialist_council_intent'=>$intent]]);
            $agent->modelVersion->update(['metadata'=>['native_specialist_council_seed'=>[...$intent,'lab_generation_id'=>$agent->lab_generation_id]]]);
        }
        $decision=CandidateGateDecision::create(['lab_agent_id'=>$agent->id,'stage'=>'screening','decision'=>'passed','reason_codes'=>[],
            'metrics'=>[],'evaluated_at'=>now()]);
        return[$agent->fresh(['generation','modelVersion']),$run,$decision,[...$response,'evidence_run_id'=>$run->run_id],$work];
    }

    private function expectNoProjections():void
    {
        $methods=[\App\Services\CooperativeExperimentSettlementService::class=>['observe'],
            \App\Services\TypedInstrumentFoundryService::class=>['recordBehaviorOutcome','recordProgramOutcome'],
            \App\Services\InstrumentInvocationLedgerService::class=>['recordResearchObservation','settleResearchPair'],
            \App\Services\FailureRepairAnchorService::class=>['recordRepairScreeningOutcome','recordFromScreeningDecision'],
            \App\Services\SkillMentorService::class=>['markScreenValidatedSeed'],\App\Services\MutationResponseMapService::class=>['recordScreening'],
            \App\Services\AdversarialCoEvolutionService::class=>['plan'],\App\Services\ProvisionalSkillCartridgeService::class=>['record'],
            \App\Services\LearningLaneService::class=>['pairScreeningObservation','pairUnpairedScreeningObservations'],
            \App\Services\LearningReceiptService::class=>['settle'],\App\Services\ParentAwareCreditService::class=>['recordScreening'],
            \App\Services\AgentProgressCardService::class=>['sync'],\App\Services\AgentKnowledgeService::class=>['recordScreening']];
        foreach($methods as$class=>$names)$this->mock($class,function($mock)use($names){foreach($names as$name)$mock->shouldReceive($name)->never();});
    }
}
