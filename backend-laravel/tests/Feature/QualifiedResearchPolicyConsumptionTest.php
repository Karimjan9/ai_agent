<?php

namespace Tests\Feature;

use App\Jobs\RunScheduledArtisanCommandJob;
use App\Models\AiLaboratory;
use App\Models\LabGeneration;
use App\Models\ResearchExperimentWorkItem;
use App\Models\ResearchLoopDecision;
use App\Models\SpecialistCouncilVersion;
use App\Services\AutonomousModeService;
use App\Services\ResearchExperimentConversionKernelService;
use App\Services\ResearchExperimentWorkConsumerService;
use App\Services\ResearchKnowledgePortfolioService;
use App\Services\ResearchLoopArbiterService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\SpecialistCouncilContractService;
use App\Services\SpecialistCouncilLifecycleService;
use App\Services\SpecialistCouncilPanelReservationService;
use App\Services\SpecialistCouncilResearchFeedbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Tests\TestCase;

/** Routing fixtures condition only the original-proof boundary, never claim qualification or market evidence. */
class QualifiedResearchPolicyConsumptionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(bool $support = true, bool $invalid = false): array
    {
        Queue::fake(); $this->freezeTime();
        config(['services.internal_api.token' => str_repeat('k', 48)]);
        $kernel = app(ResearchExperimentConversionKernelService::class);
        $record = $kernel->record([
            'contract_version' => $kernel::CONTRACT_VERSION, 'source' => ['type' => 'routing-only-fixture', 'id' => 1],
            'scope' => ['symbol' => 'XAUUSD', 'laboratory_timeframe' => 'H1', 'execution_timeframe' => 'M5'],
            'identity' => ['baseline_epoch_hash' => 'base', 'data_and_mtf_hash' => 'data', 'runtime_and_contract_hash' => 'runtime',
                'intervention_hash' => 'delta', 'window_plan_hash' => 'window', 'evaluator_version' => 'test'],
            'arms' => [['role' => 'candidate'], ['role' => 'control']],
        ], ['routing_fixture_not_science' => true], 'INCONCLUSIVE', ['type' => 'specialist_council_independent_validation']);
        $work = ResearchExperimentWorkItem::findOrFail($record['work_id']);
        $work->update(['status' => 'ready', 'payload' => [...$work->payload,
            'owner' => ResearchLoopArbiterService::class, 'executor' => ResearchExperimentWorkConsumerService::class,
            'executable' => true], 'result' => ['panel_preparation' => ['panel_version_id' => 99]]]);
        $proof = ['protocol' => SpecialistCouncilResearchFeedbackService::FOLLOWUP_PROTOCOL, 'executable' => true,
            'authority' => 'research_only', 'work_item_id' => $work->id, 'work_key' => $work->work_key,
            'source_receipt_id' => $work->research_experiment_receipt_id, 'resolution_hash' => str_repeat('a', 64),
            'max_experiments' => 1, 'promotion_evidence' => false];
        $this->mock(SpecialistCouncilResearchFeedbackService::class)->shouldReceive('inspectFollowupReadiness')->andReturn($proof);
        $projection = ['protocol' => 'pending_original_native_panel_questions_v1', 'work_item_id' => $work->id,
            'work_key' => $work->work_key, 'reservation_hash' => str_repeat('a', 64), 'panel_version_id' => 99,
            'plan_hash' => str_repeat('b', 64), 'current_source_hash' => str_repeat('c', 64), 'cases' => []];
        foreach ([10, 10, 10] as $i => $cost) $projection['cases'][] = [
            'version_id' => 99, 'window_key' => 'window-'.($i + 1), 'question_hash' => hash('sha256', 'question-'.$i),
            'manifest_hash' => str_repeat('d', 64), 'plan_hash' => str_repeat('b', 64), 'physical_hash' => hash('sha256', 'physical-'.$i),
            'source_hash' => str_repeat('c', 64), 'scope' => [['symbols' => ['XAUUSD'], 'contexts' => ['trend']]],
            'evaluator_id' => 'original-case-examiner', 'arm_keys' => ['w'.($i + 1).':candidate', 'w'.($i + 1).':retention']];
        $this->mock(SpecialistCouncilPanelReservationService::class)->shouldReceive('pendingNativePanelQuestionCases')->once()
            ->withArgs(fn ($actual) => $actual->id === $work->id && $actual->status === 'ready')->andReturn($projection);
        $lifecycle = $this->mock(SpecialistCouncilLifecycleService::class);
        $lifecycle->shouldReceive('reconcilePendingEvaluations')->andReturn(['deliveries' => []]);
        $binding = []; $benchmark = [];
        if ($support) {
            $policy = app(ResearchKnowledgePortfolioService::class)->registerPolicy(['weights' => ['cost' => -1],
                'compute_cap_seconds' => 100, 'max_candidates' => 16, 'exploration_fraction' => .05]);
            $id = $policy['knowledge_key'];
            $source = SpecialistCouncilVersion::create(['council_id' => 'conditional-proof-boundary', 'version' => '1',
                'creator_id' => 'test', 'state' => 'evaluated', 'manifest_hash' => str_repeat('e', 64), 'sealed_at' => now(),
                'manifest' => ['components' => [['id' => $id, 'role' => 'learning', 'version' => '1']]],
                'assessment' => ['fixture_not_original_qualification' => true,
                    'support_role_qualifications' => [$id => ['status' => 'research_role_qualified']]]]);
            $binding = ['protocol' => 'specialist_support_research_binding_v1', 'source_version_id' => $source->id,
                'source_manifest_hash' => $source->manifest_hash, 'assessment_hash' => str_repeat('f', 64),
                'component_id' => $id, 'component_contract_hash' => str_repeat('1', 64), 'qualification_hash' => str_repeat('2', 64),
                'role' => 'learning', 'scope' => $projection['cases'][0]['scope'],
                'authority' => 'scoped_research_component_only', 'paper_authority_granted' => false, 'promotion_evidence' => false];
            $benchmark = ['challenge_key' => 'conditional-original-native-benchmark', 'challenge_hash' => str_repeat('3', 64),
                'policy_key' => $id, 'policy_hash' => str_repeat('4', 64)];
            DB::table('specialist_council_evaluation_plans')->insert(['specialist_council_version_id' => $source->id,
                'evaluator_id' => 'conditional-source-examiner', 'plan' => '{}', 'plan_hash' => str_repeat('5', 64),
                'sealed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $lifecycle->shouldReceive('rankQualifiedResearchQuestions')->once()->andReturnUsing(
                function ($actual, $component, $refs, $seed) use ($source, $id, $projection, $binding, $benchmark, $invalid): array {
                    $this->assertSame($source->id, $actual->id); $this->assertSame($id, $component);
                    $this->assertSame(array_map(fn ($case) => ['version_id' => 99, 'window_key' => $case['window_key']], $projection['cases']), $refs);
                    if ($invalid) throw new LogicException('SUPPORT_ROLE_ORIGINAL_PROOF_NO_LONGER_VALID');
                    $inputs = [];
                    foreach ([10, 10, 10] as $i => $cost) $inputs[] = ['question_id' => $projection['cases'][$i]['question_hash'],
                        'ready' => true, 'safety_preserved' => true, 'cost_ceiling_seconds' => $cost, 'features' => []];
                    // The production declarative ranker really executes; original qualification
                    // and case proof are explicitly conditional boundaries in this cheap test.
                    // One program's equal resource proxies produce only a stable tie, not benefit.
                    return [...app(ResearchKnowledgePortfolioService::class)->rankResearchQuestions($inputs, $seed, $id, false),
                        'original_qualification_binding' => $binding,
                        'original_question_cases' => array_map(fn ($case) => array_diff_key($case, ['arm_keys' => true]), $projection['cases']),
                        'source_plan_hash' => str_repeat('5', 64), 'native_benchmark_reference' => $benchmark,
                        'actual_policy_consumed' => true, 'research_only' => true, 'paper_authority_granted' => false, 'promotion_evidence' => false];
                });
            $lifecycle->shouldReceive('researchSupportBinding')->andReturn($binding)->byDefault();
            $this->mock(SpecialistCouncilContractService::class)->shouldReceive('supportNativePolicyBenchmarkReference')->andReturn($benchmark);
        } else $lifecycle->shouldNotReceive('rankQualifiedResearchQuestions');
        return [$work->fresh(), $projection, $lifecycle, $binding];
    }

    public function test_real_sole_arbiter_claim_persists_rank_and_decision_without_claiming_replay_consumption(): void
    {
        [$work, $projection] = $this->fixture();
        $lab = AiLaboratory::firstOrCreate(['symbol' => 'XAUUSD', 'timeframe' => 'H1'],
            ['name' => 'Routing fixture', 'strategy_families' => ['ema_rsi'], 'is_active' => true]);
        LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'population_size' => 1,
            'status' => 'research_reserved', 'trigger_type' => 'specialist_council_independent_panel',
            'trigger_context' => ['specialist_council_authorized_panel' => ['work_item_id' => $work->id]]]);
        app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'running');
        $decision = app(ResearchLoopArbiterService::class)->tick();
        $this->assertSame('RESUME_COUNCIL_DURABLE_NEXT_WORK', $decision['action']);
        $selection = $work->fresh()->result['research_policy_selection'];
        $questions = array_column($projection['cases'], 'question_hash'); sort($questions);
        $this->assertSame($questions[0], $selection['selected_question_hash']);
        $this->assertSame($selection, $decision['evidence_snapshot']['research_policy_selection']);
        $this->assertSame($selection, ResearchLoopDecision::sole()->evidence_snapshot['research_policy_selection']);
        $this->assertArrayNotHasKey('research_policy_consumption', $work->fresh()->result);
        $this->assertArrayNotHasKey('research_policy_consumption', $decision['evidence_snapshot']);
        $this->assertFalse($selection['promotion_evidence']);
        $this->assertFalse($selection['paper_authority_granted']);
        $this->assertSame(900, $work->fresh()->lease_expires_at->timestamp - now()->timestamp);
        Queue::assertPushed(RunScheduledArtisanCommandJob::class, 1);
        $this->assertSame($selection, app(ResearchExperimentConversionKernelService::class)->verifiedNativePolicySelection($work->fresh(), $projection));
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    public function test_absent_support_keeps_normal_ready_work_and_creates_no_policy_receipt(): void
    {
        [$work] = $this->fixture(support: false);
        $claimed = app(ResearchExperimentConversionKernelService::class)->claimForOwner(ResearchLoopArbiterService::class, 1);
        $this->assertSame($work->id, $claimed[0]->id);
        $this->assertArrayNotHasKey('research_policy_selection', $work->fresh()->result);
    }

    public function test_invalid_support_falls_back_without_blocking_or_starving_ready_work(): void
    {
        [$work] = $this->fixture(invalid: true);
        $claimed = app(ResearchExperimentConversionKernelService::class)->claimForOwner(ResearchLoopArbiterService::class, 1);
        $this->assertSame($work->id, $claimed[0]->id);
        $this->assertSame('leased', $work->fresh()->status);
        $this->assertArrayNotHasKey('research_policy_selection', $work->fresh()->result);
    }

    public function test_fresh_retry_reuses_the_exact_original_receipt_without_calling_ranker_again(): void
    {
        [$work, $projection] = $this->fixture(); $kernel = app(ResearchExperimentConversionKernelService::class);
        $first = $kernel->claimForOwner(ResearchLoopArbiterService::class, 1)[0];
        $selection = $first->result['research_policy_selection'];
        $this->assertTrue($kernel->defer($first, 'NEXT_ORIGINAL_UNIT', true));
        $retry = $kernel->claimForOwner(ResearchLoopArbiterService::class, 1)[0];
        $this->assertSame($selection, $retry->result['research_policy_selection']);
        $this->assertGreaterThan($first->fence_version, $retry->fence_version);
        $this->assertSame($selection, $kernel->verifiedNativePolicySelection($retry, $projection));
    }

    public function test_forged_selection_and_changed_actual_case_owner_are_refused(): void
    {
        [$work, $projection] = $this->fixture(); $kernel = app(ResearchExperimentConversionKernelService::class);
        $claimed = $kernel->claimForOwner(ResearchLoopArbiterService::class, 1)[0];
        $changed = $projection; $changed['cases'][0]['physical_hash'] = str_repeat('6', 64);
        try { $kernel->verifiedNativePolicySelection($claimed, $changed); $this->fail('Changed case was accepted.'); }
        catch (LogicException $error) { $this->assertSame('ORIGINAL_NATIVE_POLICY_SELECTION_CASE_DRIFT', $error->getMessage()); }
        $result = $claimed->result; $result['research_policy_selection']['selected_question_hash'] = str_repeat('7', 64);
        $claimed->update(['result' => $result]);
        $this->expectExceptionMessage('ORIGINAL_NATIVE_POLICY_SELECTION_SEAL_INVALID');
        $kernel->verifiedNativePolicySelection($claimed->fresh(), $projection);
    }

    public function test_current_qualification_revocation_refuses_consumption_without_reselection(): void
    {
        [$work, $projection, $lifecycle] = $this->fixture(); $kernel = app(ResearchExperimentConversionKernelService::class);
        $claimed = $kernel->claimForOwner(ResearchLoopArbiterService::class, 1)[0];
        $selection = $claimed->result['research_policy_selection'];
        $lifecycle->shouldReceive('researchSupportBinding')->andThrow(new LogicException('ORIGINAL_QUALIFICATION_REVOKED'));
        $this->expectExceptionMessage('ORIGINAL_QUALIFICATION_REVOKED');
        try { $kernel->verifiedNativePolicySelection($claimed, $projection); }
        finally { $this->assertSame($selection, $work->fresh()->result['research_policy_selection']); }
    }
}
