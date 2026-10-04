<?php

namespace Tests\Feature;

require_once __DIR__.'/AcademyPreExecutionReplacementTest.php';

use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\LabLifecycleEvent;
use App\Models\ResearchLoopDecision;
use App\Services\AutonomousLearningProgressDirectorService;
use App\Services\AutonomousModeService;
use App\Services\LearningVelocityGateService;
use App\Services\ResearchLoopArbiterService;
use App\Services\TechnicalFailureClassifierService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;

/** Reuse the real twenty-seat constructor/dispatcher/technical settlement fixture. */
class DraftIntegrityRecoveryDispositionTest extends AcademyPreExecutionReplacementTest
{
    private function terminalDraftFixture(): array
    {
        $fixture = (new \ReflectionMethod(AcademyPreExecutionReplacementTest::class, 'unobservedIdentityFailure'))->invoke($this);
        foreach ($fixture[1]->agents as $agent) {
            $agent->update(['decision_reason' => 'Draft identity/integrity contract failed; child quarantined before screening. Strategy verdict withheld.']);
        }
        return $fixture;
    }

    public function test_attested_draft_failures_are_terminal_and_shared_readiness_selects_the_bounded_replacement(): void
    {
        [$owner, $generation] = $this->terminalDraftFixture();
        $history = $generation->fresh('agents.modelVersion')->toArray();
        $events = LabLifecycleEvent::orderBy('id')->get()->toArray();
        $trial = DB::table('edge_academy_trials')->get()->toArray();
        $classifier = app(TechnicalFailureClassifierService::class);
        foreach ($generation->fresh('agents.modelVersion')->agents as $agent) {
            $result = $classifier->forAgent($agent);
            $this->assertSame(TechnicalFailureClassifierService::TERMINAL, $result['class']);
            $this->assertSame('IMMUTABLE_DRAFT_INTEGRITY_QUARANTINE', $result['reason_code']);
            $this->assertFalse($result['blocks_global_generation']);
            $this->assertFalse($result['replacement_authorized']);
            $this->assertSame('withheld', $result['strategy_verdict']);
        }
        config()->set('services.lab_selection.learning_velocity_enabled', true);
        $velocity = app(LearningVelocityGateService::class)->inspect('XAUUSD', 'H1');
        $this->assertSame(0, $velocity['technical_recovery_agents'], json_encode($velocity));
        $this->assertNotSame('blocked_technical_recovery', $velocity['status']);
        (new \ReflectionMethod(AcademyPreExecutionReplacementTest::class, 'newSource'))->invoke($this);
        $proposal = $owner->proposal();
        $this->assertSame('would_prepare_cold_start', $proposal['status'], json_encode($proposal));
        $this->assertFalse(data_get($proposal, 'cold_start.technical_replacement.scientific_question_budget_reset'));
        ResearchLoopDecision::query()->update(['status' => 'completed']);
        config()->set('services.xauusd_organism.historical_research_until_champion', false);
        $director = Mockery::mock(AutonomousLearningProgressDirectorService::class);
        $director->shouldReceive('advance')->andReturn(['status' => 'blocked', 'reason' => 'EDGE_HYPOTHESIS_COMPILER_BLOCKED']);
        app()->instance(AutonomousLearningProgressDirectorService::class, $director);
        app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'bounded technical replacement');
        $decision = app(ResearchLoopArbiterService::class)->tick('XAUUSD', 'H1', true);
        $this->assertSame('OPEN_ACADEMY_EXPERIMENT', $decision['action'], json_encode($decision));
        $this->assertSame(0, $decision['arguments']['trial']);
        $this->assertSame($history, $generation->fresh('agents.modelVersion')->toArray());
        $this->assertSame($events, LabLifecycleEvent::orderBy('id')->get()->toArray());
        $this->assertEquals($trial, DB::table('edge_academy_trials')->get()->toArray());
        $this->assertDatabaseCount('causal_stage_mastery_assessments', 0);
        $this->assertDatabaseCount('evolutionary_authority_ledgers', 0);
        Queue::assertNothingPushed();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unattestedOrObservedShapes')]
    public function test_draft_terminal_projection_refuses_unattested_manual_labels_or_any_execution(string $shape): void
    {
        [, $generation] = $this->terminalDraftFixture();
        $agent = $generation->agents->first();
        $event = LabLifecycleEvent::where('lab_agent_id', $agent->id)->where('event_type', 'draft_integrity_quarantine')->firstOrFail();
        if ($shape === 'missing_event') $event->delete();
        if ($shape === 'unknown_source') $event->update(['source' => 'manual-label']);
        if ($shape === 'wrong_phase') $event->update(['phase' => 'full_validation']);
        if ($shape === 'linked_run') $event->update(['run_id' => 'unattested-run']);
        if ($shape === 'quality_not_withheld') $event->update(['payload' => [...$event->payload, 'quality_verdict' => 'failed']]);
        if ($shape === 'promotion') $event->update(['payload' => [...$event->payload, 'promotion_evidence' => true]]);
        if ($shape === 'empty_violations') $event->update(['payload' => [...$event->payload, 'violations' => []]]);
        if ($shape === 'attestation_mismatch') {
            $context = $generation->trigger_context;
            $context['draft_integrity_quarantines'][0]['violations'] = ['UNRELATED_ERROR'];
            $generation->update(['trigger_context' => $context]);
        }
        if ($shape === 'generation_not_terminal') $generation->update(['status' => 'screening']);
        if (in_array($shape, ['completed_run', 'transient_run', 'another_arm_run'], true)) {
            $member = $shape === 'another_arm_run' ? $generation->agents->last() : $agent;
            LabEvaluationRun::create(['run_id' => 'actual-observed-'.$shape, 'lab_generation_id' => $generation->id,
                'lab_agent_id' => $member->id, 'model_version_id' => $member->model_version_id, 'phase' => 'screening',
                'mode' => 'test', 'attempt' => 1, 'status' => $shape === 'transient_run' ? 'technical_error' : 'completed',
                'error_message' => $shape === 'transient_run' ? 'cURL error 28: Operation timed out after 900001 milliseconds' : null]);
        }
        if ($shape === 'artifact') LabEvidenceArtifact::create(['artifact_id' => 'actual-evidence',
            'lab_generation_id' => $generation->id, 'lab_agent_id' => $agent->id, 'artifact_type' => 'replay_result',
            'sha256' => str_repeat('a', 64), 'byte_size' => 1, 'content_encoding' => 'json', 'recorded_at' => now()]);
        $result = app(TechnicalFailureClassifierService::class)->forAgent($agent->fresh(['generation', 'modelVersion']));
        $this->assertSame(TechnicalFailureClassifierService::TRANSIENT, $result['class'], $shape);
        $this->assertTrue($result['blocks_global_generation']);
        $this->assertSame($shape === 'transient_run' ? 'REPLAY_TRANSPORT_TIMEOUT' : 'UNCLASSIFIED_TRANSIENT', $result['reason_code']);
        config()->set('services.lab_selection.learning_velocity_enabled', true);
        if ($shape !== 'generation_not_terminal') {
            $velocity = app(LearningVelocityGateService::class)->inspect('XAUUSD', 'H1');
            $this->assertSame('blocked_technical_recovery', $velocity['status']);
            $this->assertGreaterThan(0, $velocity['technical_recovery_agents']);
        }
        $this->assertDatabaseCount('edge_academy_trials', 1);
        Queue::assertNothingPushed();
    }

    public static function unattestedOrObservedShapes(): array
    {
        return array_map(fn ($shape) => [$shape], ['missing_event', 'unknown_source', 'wrong_phase', 'linked_run',
            'quality_not_withheld', 'promotion', 'empty_violations', 'attestation_mismatch', 'generation_not_terminal',
            'completed_run', 'transient_run', 'another_arm_run', 'artifact']);
    }
}
