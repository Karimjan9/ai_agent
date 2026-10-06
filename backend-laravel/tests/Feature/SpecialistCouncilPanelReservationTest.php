<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Models\ResearchExperimentWorkItem;
use App\Models\SpecialistCouncilVersion;
use App\Services\AutonomousModeService;
use App\Services\ExecutionContractService;
use App\Services\InstrumentResearchWindowService;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabInstrumentResearchService;
use App\Services\LabPopulationService;
use App\Services\LearningVelocityGateService;
use App\Services\ResearchExperimentConversionKernelService;
use App\Services\ResearchLoopArbiterService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\ResearchReleaseSealService;
use App\Services\SpecialistCouncilAuthorizedArmExecutionService;
use App\Services\SpecialistCouncilLifecycleService;
use App\Services\SpecialistCouncilPanelReservationService;
use App\Services\SpecialistCouncilResearchFeedbackService;
use App\Services\StrategyParameterSchemaService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Real issuer/constructor/sealing integration, with explicitly synthetic, non-authoritative source observations. */
class SpecialistCouncilPanelReservationTest extends TestCase
{
    use RefreshDatabase;
    use OriginalCouncilPanelReservationFixture;

    private string $root;
    private string $originalStorage;
    private ?int $monotonicClockStart = null;
    private int $monotonicClockOffset = 0;
    private const KEY = 'fixture-panel-original-server-key-at-least-32-characters';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2028-01-01T00:00:00Z'));
        $this->root = sys_get_temp_dir().'/council-panel-original-test-'.bin2hex(random_bytes(8));
        $this->originalStorage = storage_path();
        File::ensureDirectoryExists($this->root.'/app/lab-datasets');
        $this->app->useStoragePath($this->root);
        config(['services.internal_api.token' => self::KEY, 'services.market_data.provider' => 'csv',
            'services.lab_selection.constructor_initial_seat_budget' => 12,
            'services.instrument_policy.authorized_research_windows' => [],
            'services.research_paper_epochs.authorized_paper_epochs' => []]);
        app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'fixture', 'real original panel ownership integration');
        // Only unrelated velocity readiness is isolated. No issuer, constructor,
        // manifest/plan, release, transport or scientific comparator is mocked.
        $this->mock(LearningVelocityGateService::class, fn ($mock) => $mock->shouldReceive('inspect')
            ->andReturn(['status' => 'healthy', 'allowed' => true]));
        app()->instance(LabPopulationService::class, app(LabPopulationService::class));
        AiLaboratory::create(['name' => 'real original panel constructor', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_families' => ['ema_rsi'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
    }

    protected function tearDown(): void
    {
        if (isset($this->originalStorage)) $this->app->useStoragePath($this->originalStorage);
        if (isset($this->root)) {
            $resolved = realpath($this->root);
            $prefix = str_replace('\\', '/', (string) realpath(sys_get_temp_dir())).'/council-panel-original-test-';
            if ($resolved && str_starts_with(str_replace('\\', '/', $resolved), $prefix)) File::deleteDirectory($resolved);
        }
        parent::tearDown();
    }

    public function test_real_registration_reserves_three_canonical_cohorts_then_seals_original_full_signed_units(): void
    {
        $this->originalPanelAcceptance();
    }

    public function test_public_preparation_seals_all_original_requests_then_uses_a_fresh_real_budget_for_native_and_solo(): void
    {
        $this->monotonicClockStart = hrtime(true);
        $this->travelTo(fn () => CarbonImmutable::parse('2028-01-01T00:00:00Z')
            ->addMicroseconds(intdiv(hrtime(true) - $this->monotonicClockStart, 1000))
            ->addSeconds($this->monotonicClockOffset));
        $this->originalPanelAcceptance(2);
    }

    private function originalPanelAcceptance(int $originalUnitLimit = 24): void
    {
        [$work, $input, $parent, $sourceModels] = $this->fixture();
        $this->panelTestProgress('original source and three authorized windows created');
        $feedback = app(SpecialistCouncilResearchFeedbackService::class);
        $proof = $feedback->registerFollowupProof($work->id, $input, 'fixture-operator');
        $this->panelTestProgress('original reservation registered');
        $this->assertTrue($proof['executable'], json_encode($proof));
        $this->assertFalse($proof['promotion_evidence']);
        $again = $feedback->registerFollowupProof($work->id, $input, 'different-retry-actor');
        $this->assertSame($proof['resolution_hash'], $again['resolution_hash']);
        $this->assertSame('fixture-operator', data_get($work->fresh()->payload, 'pending_panel_intent.registered_by'));
        foreach ($proof['reservation']['arm_roots'] as $root) {
            $this->assertSame(app(StrategyParameterSchemaService::class)->family($root['strategy']), $root['family'],
                json_encode($root));
        }
        $kernel = app(ResearchExperimentConversionKernelService::class);
        $owner = app(SpecialistCouncilPanelReservationService::class);
        config(['services.lab_selection.constructor_initial_seat_budget' => 3]);
        for ($ordinal = 1; $ordinal <= 3; $ordinal++) {
            $leased = $kernel->claimForOwner(ResearchLoopArbiterService::class, 1);
            $this->assertCount(1, $leased, json_encode([
                'work' => $work->fresh()->only(['status', 'last_error']),
                'readiness' => $leased === [] ? $feedback->inspectFollowupReadiness($work->fresh()) : null,
            ]));
            $this->assertGreaterThanOrEqual(630, now()->diffInSeconds($leased[0]->lease_expires_at));
            $this->panelTestProgress('claimed original window '.$ordinal);
            $result = $owner->execute($leased[0]);
            $this->assertSame('COUNCIL_PANEL_NEXT_PREREGISTERED_RESERVATION', $result['reason'] ?? null,
                json_encode([$result, app(LabPopulationService::class)->lastBuildOutcome()]));
            $this->assertSame($ordinal, LabGeneration::where('trigger_type', LabPopulationService::AUTHORIZED_COUNCIL_PANEL_TRIGGER)->count());
            $this->assertDatabaseCount('lab_evaluation_runs', 0);
            $this->panelTestProgress('reserved original window '.$ordinal);
            if ($ordinal === 1) {
                $originalCohort = LabGeneration::where('trigger_type', LabPopulationService::AUTHORIZED_COUNCIL_PANEL_TRIGGER)->sole();
                $firstIds = $originalCohort->agents()->orderBy('id')->pluck('id')->all();
                $this->assertCount(3, $firstIds);
                $resume = $kernel->claimCouncilContinuationForGeneration($originalCohort);
                $this->assertNotNull($resume, json_encode($work->fresh()->only(['status', 'last_error'])));
                $this->assertSame($work->id, $resume->id);
                config(['services.lab_selection.constructor_initial_seat_budget' => 12]);
                $resumed = $owner->execute($resume);
                $this->assertSame('COUNCIL_PANEL_ORIGINAL_CONSTRUCTION_CONTINUATION', $resumed['reason'] ?? null, json_encode($resumed));
                $this->assertSame($firstIds, $originalCohort->agents()->orderBy('id')->limit(3)->pluck('id')->all());
                $this->assertSame(7, $originalCohort->agents()->count(),
                    json_encode(data_get($originalCohort->fresh()->trigger_context, 'constructor_continuation')));
                $this->assertTrue(LabPopulationService::constructionIncomplete($originalCohort->fresh()));
                $finalSeatLease = $kernel->claimCouncilContinuationForGeneration($originalCohort->fresh());
                $this->assertNotNull($finalSeatLease, json_encode($work->fresh()->only(['status', 'last_error'])));
                $finalSeat = $owner->execute($finalSeatLease);
                $this->assertSame('COUNCIL_PANEL_ORIGINAL_CONSTRUCTION_CONTINUATION', $finalSeat['reason'] ?? null, json_encode($finalSeat));
                $this->assertSame('research_reserved', $originalCohort->fresh()->status);
                $this->assertSame(8, $originalCohort->agents()->count());
                $this->assertSame($firstIds, $originalCohort->agents()->orderBy('id')->limit(3)->pluck('id')->all());
                $this->panelTestProgress('completed original window 1 through two bounded continuations');
                $this->assertSame(1, LabGeneration::where('trigger_type', LabPopulationService::AUTHORIZED_COUNCIL_PANEL_TRIGGER)->count());
            }
        }
        $cohorts = LabGeneration::where('trigger_type', LabPopulationService::AUTHORIZED_COUNCIL_PANEL_TRIGGER)->orderBy('id')->get();
        foreach ($cohorts as $cohort) {
            $this->assertSame('research_reserved', $cohort->status);
            $this->assertSame(8, $cohort->agents()->count());
            $this->assertSame($work->id, data_get($cohort->trigger_context, 'specialist_council_authorized_panel.work_item_id'));
        }
        $leased = $kernel->claimForOwner(ResearchLoopArbiterService::class, 1);
        $this->assertCount(1, $leased);
        $replayTransportEnabled = false;
        Http::fake(function () use (&$replayTransportEnabled) {
            if (! $replayTransportEnabled) $this->fail('Preparation and its retired lease must never submit replay HTTP.');
            // Laravel appends fake callbacks; yielding null lets the later
            // genuine Python transport handle requests after this guard ends.
            return null;
        });
        $preparationLease = clone $leased[0];
        $preparationStarted = hrtime(true);
        $preparation = $owner->execute($leased[0]);
        $preparationWall = (hrtime(true) - $preparationStarted) / 1e9;
        $this->panelTestProgress('public original preparation delivery wall_seconds='.round($preparationWall, 3));
        $this->assertSame('COUNCIL_PANEL_ORIGINAL_PREPARATION_SEALED', $preparation['reason'] ?? null, json_encode($preparation));
        $this->assertLessThan(900, $preparationWall, 'The real preparation wall time must fit its bounded lease.');
        $this->assertNotSame('leased', $work->fresh()->status);
        $this->assertNull($work->fresh()->lease_token);
        $prepared = data_get($work->fresh()->result, 'panel_preparation');
        $this->panelTestProgress('global original plan and 24 requests sealed');
        $this->assertCount(24, $prepared['units']);
        $this->assertDatabaseCount('specialist_council_versions', 2);
        $target = SpecialistCouncilVersion::findOrFail($prepared['panel_version_id']);
        $this->assertSame('evaluating', $target->state);
        $this->assertTrue(app(LabImmutableEvidenceService::class)->equivalentJsonValue(
            $parent->manifest['members'], $target->manifest['members']),
            'The newly sealed council must preserve every original native member value and list position.');
        $this->assertFalse($target->manifest['promotion_evidence']);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        foreach ($cohorts as $cohort) {
            $cohort->refresh();
            app(ResearchReleaseSealService::class)->assertCurrent($cohort);
            $this->assertSame('full_validation', $cohort->status);
            $this->assertCount(8, data_get($cohort->trigger_context, 'specialist_council_authorized_panel.arm_units'));
        }
        $unit = array_diff_key($prepared['units'][0], ['generation_id' => true]);
        $cohort = LabGeneration::findOrFail($prepared['units'][0]['generation_id']);
        if ($this->monotonicClockStart !== null) {
            $this->advanceFixtureClock(901);
            $this->assertTrue($preparationLease->lease_expires_at->isPast());
        }
        try {
            app(SpecialistCouncilAuthorizedArmExecutionService::class)->execute($cohort, $unit, $preparationLease,
                'research_loop_arbiter', $preparationLease->lease_token, $preparationLease->fence_version);
            $this->fail('A retired preparation lease reached replay execution.');
        } catch (\LogicException $error) {
            $this->assertStringContainsString('LEASE', $error->getMessage());
        }
        $leased = $kernel->claimForOwner(ResearchLoopArbiterService::class, 1);
        $this->assertCount(1, $leased, json_encode($feedback->inspectFollowupReadiness($work->fresh())));
        $this->assertGreaterThan($preparationLease->fence_version, $leased[0]->fence_version);
        $request = app(SpecialistCouncilAuthorizedArmExecutionService::class)->compileRequest($cohort, $unit, $leased[0]);
        $this->assertSame($sourceModels['candidate']->metadata['instrument_research_assignment'],
            $request['strategies'][0]['instrument_research_assignment']);
        foreach ($request['strategies'][0]['specialist_council_contract']['members'] as $member) {
            $this->assertSame($sourceModels[$member['role']]->metadata['instrument_research_assignment'], $member['instrument_research_assignment']);
            $this->assertArrayNotHasKey('specialist_council_evaluation', $member);
        }
        $soloUnit = collect($prepared['units'])->first(fn ($entry): bool => $entry['kind'] === 'solo');
        $soloRequest = app(SpecialistCouncilAuthorizedArmExecutionService::class)->compileRequest(
            LabGeneration::findOrFail($soloUnit['generation_id']), array_diff_key($soloUnit, ['generation_id' => true]), $leased[0]);
        $this->assertSame($sourceModels['hour']->metadata['instrument_research_assignment'], $soloRequest['strategies'][0]['instrument_research_assignment']);
        $this->assertSame($unit['request_hash'], app(LabImmutableEvidenceService::class)->hash($request));
        $signed = $request['policy_context']['authorized_research_transport'];
        $this->assertSame(InstrumentResearchWindowService::TRANSPORT_PROTOCOL, $signed['protocol']);
        $this->assertSame($unit['window_key'], $signed['window']['window_key']);
        $this->assertCount(4, $signed['files']);
        $this->assertFalse($signed['independent_evidence']);
        $this->assertFalse($signed['promotion_evidence']);
        $fixture = base_path('../ai-service-python/tests/support/authorized_research_transport_fixture.py');
        $process = new Process(['python', $fixture, '--test-data-root', $this->root, '--fixture-clock', '2028-01-01T00:00:00Z'],
            base_path('../ai-service-python'), ['INTERNAL_API_TOKEN' => self::KEY, 'INTERNAL_API_TOKEN_FILE' => ''],
            json_encode($request, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), timeout: 45);
        $process->mustRun();
        $actual = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($actual['admission_verified']);
        $this->assertSame($signed['contract_hash'], $actual['contract_hash']);
        $this->assertSame($unit['window_key'], $actual['window_key']);
        $this->assertFalse($actual['independent_evidence']);

        // Inspection itself uses no replay. Release that genuine lease and
        // obtain the execution lease without renewing either original token.
        $this->assertTrue($kernel->defer($leased[0], 'FIXTURE_PREREGISTERED_REQUEST_INSPECTION_FINISHED', true));
        $this->advanceFixtureClock(30);
        $executionLease = $kernel->claimForOwner(ResearchLoopArbiterService::class, 1);
        $this->assertCount(1, $executionLease, json_encode($feedback->inspectFollowupReadiness($work->fresh())));
        $this->assertGreaterThan($leased[0]->fence_version, $executionLease[0]->fence_version);
        $leased = $executionLease;

        $posts = 0;
        $replayTransportEnabled = true;
        Http::fake(function ($httpRequest) use (&$posts) {
            if (str_ends_with($httpRequest->url(), '/api/replay-status')) {
                $health = new Process(['python', '-c',
                    'import json; from app.services.research_release import health_receipt; print(json.dumps(health_receipt()))'],
                    base_path('../ai-service-python'), timeout: 20);
                $health->mustRun();
                return Http::response(['protocol' => 'replay_liveness_v2_bounded_worker', 'active_requests' => 0,
                    'research_source' => json_decode($health->getOutput(), true, flags: JSON_THROW_ON_ERROR)]);
            }
            $this->assertStringEndsWith('/api/backtest/run-all', $httpRequest->url());
            $posts++;
            $runner = new Process(['python', base_path('../ai-service-python/tests/support/authorized_council_arm_fixture.py'),
                '--test-data-root', $this->root, '--fixture-clock', '2028-01-01T00:00:00Z'],
                base_path('../ai-service-python'), ['INTERNAL_API_TOKEN' => self::KEY, 'INTERNAL_API_TOKEN_FILE' => ''],
                json_encode($httpRequest->data(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), timeout: 60);
            $runner->mustRun();
            $originalJson = $runner->getOutput();
            json_decode($originalJson, true, flags: JSON_THROW_ON_ERROR);
            // The real HTTP boundary transports these original JSON bytes.
            // Re-encoding an array without preserving 10000.0 as a float
            // corrupts the producer-bound trace hash before PHP receives it.
            return Http::response($originalJson, 200, ['Content-Type' => 'application/json']);
        });
        $executor = app(SpecialistCouncilAuthorizedArmExecutionService::class);
        $firstStarted = hrtime(true);
        $accepted = $owner->execute($leased[0]);
        $this->panelTestProgress('public original native delivery wall_seconds='.round((hrtime(true) - $firstStarted) / 1e9, 3));
        $this->assertSame('COUNCIL_PANEL_NEXT_ORIGINAL_ARM_UNIT', $accepted['reason'] ?? null, json_encode($accepted));
        $this->assertSame(1, $posts, json_encode($accepted));
        $this->assertDatabaseCount('lab_evaluation_runs', 1);
        $run = LabEvaluationRun::sole();
        $this->assertSame('completed', $run->status, json_encode([
            'accepted' => $accepted,
            ...$this->originalRunDiagnostic($run),
        ]));
        $this->assertSame('full_validation', $run->phase);
        $this->assertSame($unit['request_hash'], $run->request_hash);
        $this->assertSame($unit['window_key'], data_get($run->metadata, 'council_panel.window_key'));
        $this->assertSame($run->run_id, data_get($work->fresh()->result, 'panel_units.'.$unit['arm_key'].'.run_id'));
        $this->panelTestProgress('observed original arm 1');
        $this->advanceFixtureClock(30);
        $nextLease = $kernel->claimForOwner(ResearchLoopArbiterService::class, 1);
        $this->assertCount(1, $nextLease);
        $this->assertGreaterThan($leased[0]->fence_version, $nextLease[0]->fence_version);
        $retryRequest = $executor->compileRequest($cohort->fresh(), $unit, $nextLease[0]);
        $this->assertSame($unit['request_hash'], app(LabImmutableEvidenceService::class)->hash($retryRequest));
        $this->assertSame($signed, $retryRequest['policy_context']['authorized_research_transport']);
        $retry = $executor->execute($cohort->fresh(), $unit, $nextLease[0], 'research_loop_arbiter',
            $nextLease[0]->lease_token, $nextLease[0]->fence_version);
        $this->assertTrue($retry['already_terminal'], json_encode($retry));
        $this->assertSame(1, $posts);
        $this->assertDatabaseCount('lab_evaluation_runs', 1);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);

        // Resume through the public original owner. Each delivery consumes
        // one new preregistered arm and reuses every earlier terminal unit.
        $delivery = $nextLease[0];
        $originalRunId = $run->run_id;
        $pendingQueueJobId = null;
        for ($observed = 2; $observed <= $originalUnitLimit; $observed++) {
            if ($observed === 8) {
                // Hold a real database-transport job owned by this cohort at
                // its last arm. Never execute this deliberate backlog fixture.
                $this->assertSame(':memory:', DB::connection()->getDatabaseName());
                $queue = (string) config('services.lab_queue.full_validation_queue', 'lab-full-validation');
                $pendingQueueJobId = Queue::connection('database')->push(
                    new \App\Jobs\EvaluateLabAgentJob((int) $unit['lab_agent_id'], 'XAUUSD', queue: $queue), '', $queue);
                $this->assertNotNull($pendingQueueJobId);
            }
            $deliveryStarted = hrtime(true);
            $continued = $owner->execute($delivery);
            $this->panelTestProgress('public original delivery '.$observed.' wall_seconds='.round((hrtime(true) - $deliveryStarted) / 1e9, 3));
            $this->assertSame('COUNCIL_PANEL_NEXT_ORIGINAL_ARM_UNIT', $continued['reason'] ?? null, json_encode($continued));
            $this->assertSame($observed, $posts, json_encode($continued));
            $this->assertDatabaseCount('lab_evaluation_runs', $observed);
            $newOriginal = LabEvaluationRun::latest('id')->firstOrFail();
            $this->assertSame('completed', $newOriginal->status, json_encode([
                'public_delivery' => $continued, ...$this->originalRunDiagnostic($newOriginal),
            ]));
            $this->assertSame($originalRunId, data_get($work->fresh()->result, 'panel_units.'.$unit['arm_key'].'.run_id'));
            $this->panelTestProgress('observed original arm '.$observed);
            if ($originalUnitLimit < 24 && $observed === $originalUnitLimit) {
                $this->assertNotSame('settled', $work->fresh()->status);
                $this->assertNull($work->fresh()->completed_at);
                $this->assertSame($prepared, data_get($work->fresh()->result, 'panel_preparation'));
                $this->assertCount(24, $prepared['units']);
                $this->assertDatabaseCount('lab_evolution_credit_events', 0);
                return;
            }
            $this->advanceFixtureClock(30);
            $claimed = $kernel->claimForOwner(ResearchLoopArbiterService::class, 1);
            $this->assertCount(1, $claimed, json_encode([
                'work' => $work->fresh()->only(['status', 'last_error']),
                'readiness' => $claimed === [] ? $feedback->inspectFollowupReadiness($work->fresh()) : null,
            ]));
            $delivery = $claimed[0];
        }
        $pending = $owner->execute($delivery);
        $this->assertSame('COUNCIL_PANEL_CANONICAL_TERMINAL_BOUNDARY_PENDING', $pending['reason'] ?? null, json_encode($pending));
        $this->assertSame(24, $posts);
        $this->assertNotSame('settled', $work->fresh()->status);
        $this->assertNull($work->fresh()->completed_at);
        $this->assertFalse(data_get($work->fresh()->result, 'panel_terminal_projection.complete'));
        $this->assertSame('full_validation', $cohorts[0]->fresh()->status);
        $originalAssessmentHash = $target->fresh()->assessment_hash;
        $this->assertNotEmpty($originalAssessmentHash);
        $this->assertSame(1, DB::table('jobs')->where('id', $pendingQueueJobId)->delete());
        $this->advanceFixtureClock(30);
        $terminalLease = $kernel->claimForOwner(ResearchLoopArbiterService::class, 1);
        $this->assertCount(1, $terminalLease, json_encode($feedback->inspectFollowupReadiness($work->fresh())));
        $this->assertSame($work->id, $terminalLease[0]->id);
        $delivery = $terminalLease[0];
        $settled = $owner->execute($delivery);
        $this->panelTestProgress('global original assessment and terminal projection returned');
        $this->assertSame('original_panel_settled', $settled['status'] ?? null, json_encode($settled));
        $this->assertSame(24, $posts);
        $this->assertDatabaseCount('lab_evaluation_runs', 24);
        $this->assertSame(24, LabEvaluationRun::where('status', 'completed')->count(),
            json_encode(LabEvaluationRun::orderBy('id')->get(['run_id', 'status'])->toArray()));
        $this->assertSame('settled', $work->fresh()->status);
        $this->assertNotNull($work->fresh()->completed_at);
        $target->refresh();
        $this->assertSame($originalAssessmentHash, $target->assessment_hash);
        $this->assertSame('evaluated', $target->state);
        $this->assertFalse($target->assessment['qualified']);
        $this->assertCount(24, $target->assessment['original_run_ids']);
        $this->assertSame(LabEvaluationRun::orderBy('id')->pluck('run_id')->all(), $target->assessment['original_run_ids']);
        foreach ($cohorts as $completedCohort) {
            $completedCohort->refresh();
            $this->assertNotContains($completedCohort->status, ['research_reserved', 'draft', 'queued', 'screening', 'full_validation']);
            $this->assertNotNull($completedCohort->completed_at);
        }
        $closedWork = $work->fresh();
        $this->assertTrue(data_get($closedWork->result, 'panel_terminal_projection.complete'));
        $this->assertCount(3, data_get($closedWork->result, 'panel_terminal_projection.generation_receipts'));
        $this->assertCount(24, data_get($closedWork->result, 'panel_units'));
        $evidence = app(LabImmutableEvidenceService::class);
        foreach ($prepared['units'] as $originalUnit) {
            $checkpoint = data_get($closedWork->result, 'panel_units.'.$originalUnit['arm_key']);
            $this->assertSame('terminal', $checkpoint['status']);
            $original = LabEvaluationRun::where('run_id', $checkpoint['run_id'])->sole();
            $this->assertSame($originalUnit['request_hash'], $original->request_hash);
            $receipt = $checkpoint['terminal_receipt'];
            $this->assertSame($original->request_hash, $receipt['request_hash']);
            $this->assertSame($original->response_hash, $receipt['response_hash']);
            $this->assertFalse($receipt['promotion_evidence']);
            $this->assertFalse($receipt['economic_skill_proven']);
            $this->assertFalse($receipt['trading_authority_granted']);
            $artifact = \App\Models\LabEvidenceArtifact::where('run_id', $original->run_id)
                ->where('artifact_type', 'evaluation_response')->sole();
            $originalResponse = $evidence->readArtifactPayload($artifact);
            $completeness = $evidence->replayEvidenceCompleteness($original, $originalResponse);
            $this->assertTrue($completeness['complete'], json_encode($completeness));
            $this->assertTrue($completeness['trade_ledger']);
        }
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    private function panelTestProgress(string $phase): void
    {
        if (getenv('SPECIALIST_COUNCIL_PANEL_TEST_PROGRESS') === '1') {
            fwrite(STDERR, '[original-panel-test] '.$phase.PHP_EOL);
        }
    }

    private function advanceFixtureClock(int $seconds): void
    {
        if ($this->monotonicClockStart !== null) $this->monotonicClockOffset += $seconds;
        else $this->travel($seconds)->seconds();
    }

    private function originalRunDiagnostic(LabEvaluationRun $run): array
    {
        if ($run->status === 'completed') return ['original_run' => $run->only(['run_id', 'status', 'request_hash', 'response_hash'])];
        $artifact = \App\Models\LabEvidenceArtifact::where('run_id', $run->run_id)
            ->where('artifact_type', 'evaluation_response')->first();
        $response = $artifact ? app(LabImmutableEvidenceService::class)->readArtifactPayload($artifact) : [];
        $trace = (array) data_get($response, 'decision_trace', []);
        return [
            'original_run' => $run->only(['status', 'error_class', 'error_message', 'metadata', 'response_meta']),
            'original_failure_artifacts' => $run->status !== 'completed' ? $this->preserveOriginalFailureArtifacts($run) : null,
            'original_evidence_completeness' => is_array($response)
                ? app(LabImmutableEvidenceService::class)->replayEvidenceCompleteness($run, $response) : null,
            'original_decision_trace_producer' => data_get($response, 'data_quality.decision_trace'),
            'original_decision_trace_first_event' => $trace[0] ?? null,
            'original_decision_trace_last_event' => $trace[array_key_last($trace)] ?? null,
            'original_native_trace_identity' => data_get($response, 'specialist_council_receipt.decision_trace_identity'),
            'original_native_scope' => data_get($response, 'specialist_council_receipt.evaluated_scope'),
            'original_response_scope' => data_get($response, 'data_quality.replay_evaluation_scope'),
        ];
    }

    /** Preserve the actual immutable bytes on failure, never replacement evidence or API headers. */
    private function preserveOriginalFailureArtifacts(LabEvaluationRun $run): string
    {
        $this->assertMatchesRegularExpression('/^[a-f0-9-]{36}$/i', $run->run_id);
        $directory = base_path('.runtime/specialist-council-panel-failure/'.$run->run_id);
        File::ensureDirectoryExists($directory);
        File::put($directory.'/original-run.json', json_encode($run->getAttributes(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $storageRoot = str_replace('\\', '/', (string) realpath($this->root)).'/';
        foreach (\App\Models\LabEvidenceArtifact::where('run_id', $run->run_id)
            ->whereIn('artifact_type', ['evaluation_request', 'evaluation_response', 'decision_trace', 'decision_trace_manifest'])->get() as $artifact) {
            $originalPath = realpath(storage_path('app/'.$artifact->storage_path));
            $this->assertNotFalse($originalPath);
            $this->assertStringStartsWith($storageRoot, str_replace('\\', '/', $originalPath));
            File::copy($originalPath, $directory.'/'.$artifact->artifact_type.'.json.gz');
            File::put($directory.'/'.$artifact->artifact_type.'.metadata.json', json_encode($artifact->getAttributes(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
        return $directory;
    }

    public function test_real_owned_queue_backlog_cannot_be_discarded_by_the_panel_terminal_guard(): void
    {
        [$work, $input, , $models] = $this->fixture();
        $feedback = app(SpecialistCouncilResearchFeedbackService::class);
        $proof = $feedback->registerFollowupProof($work->id, $input, 'fixture-terminal-operator');
        $this->assertTrue($proof['executable'], json_encode($proof));
        $leased = app(ResearchExperimentConversionKernelService::class)->claimForOwner(ResearchLoopArbiterService::class, 1);
        $this->assertCount(1, $leased);
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $cohorts = [];
        foreach ([2, 3, 4] as $ordinal) {
            // These isolated rows test only the operational closer. They are
            // not canonical panel materialization or scientific evidence.
            $cohort = LabGeneration::create(['ai_laboratory_id' => AiLaboratory::firstOrFail()->id,
                'generation' => $ordinal, 'trigger_type' => 'test', 'status' => 'full_validation',
                'population_size' => 1, 'trigger_context' => ['synthetic_terminal_guard_fixture' => true]]);
            $agent = LabAgent::create(['lab_generation_id' => $cohort->id, 'model_version_id' => $models['hour']->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'ema_rsi',
                'origin' => 'synthetic_terminal_guard_fixture', 'lifecycle_status' => 'completed', 'parameter_diff' => []]);
            $queue = (string) config('services.lab_queue.full_validation_queue', 'lab-full-validation');
            Queue::connection('database')->push(new \App\Jobs\EvaluateLabAgentJob($agent->id, 'XAUUSD', queue: $queue), '', $queue);
            $cohorts[] = $cohort;
        }
        $method = new \ReflectionMethod(SpecialistCouncilPanelReservationService::class, 'canonicalTerminalProjection');
        $projection = $method->invoke(app(SpecialistCouncilPanelReservationService::class), $leased[0], $cohorts);
        $this->assertFalse($projection['complete']);
        $this->assertCount(3, $projection['generation_receipts']);
        foreach ($projection['generation_receipts'] as $receipt) {
            $this->assertSame('GENERATION_QUEUE_WORK_REMAINS', $receipt['reason_code']);
            $this->assertFalse($receipt['canonical_terminal_verified']);
            $this->assertNull($receipt['completed_at']);
        }
        $this->assertSame('leased', $work->fresh()->status);
        $this->assertNull($work->fresh()->completed_at);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
    }

    public function test_original_source_schema_is_rejected_before_an_executable_reservation_is_sealed(): void
    {
        [$work, $input] = $this->fixture(true);
        try {
            app(SpecialistCouncilResearchFeedbackService::class)->registerFollowupProof($work->id, $input, 'fixture-operator');
            $this->fail('An invalid original parameter program cannot acquire an executable reservation.');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString('trend_strength_min, pullback_atr_fraction', $error->getMessage());
        }
        $this->assertNull(data_get($work->fresh()->payload, 'pending_panel_intent'));
        $this->assertNull(data_get($work->fresh()->payload, 'followup_resolution'));
        $this->assertSame(0, LabGeneration::where('trigger_type', LabPopulationService::AUTHORIZED_COUNCIL_PANEL_TRIGGER)->count());
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    public function test_original_shared_account_policy_is_rejected_before_any_canonical_reservation(): void
    {
        [$work, $input] = $this->fixture(invalidOriginalAccountPolicy: true);
        try {
            app(SpecialistCouncilResearchFeedbackService::class)->registerFollowupProof($work->id, $input, 'fixture-operator');
            $this->fail('A source plan missing its original shared account mode cannot become executable.');
        } catch (\LogicException $error) {
            $this->assertSame('COUNCIL_PANEL_ORIGINAL_SHARED_ACCOUNT_POLICY_INVALID:broker_position_mode', $error->getMessage());
        }
        $this->assertNull(data_get($work->fresh()->payload, 'pending_panel_intent'));
        $this->assertNull(data_get($work->fresh()->payload, 'followup_resolution'));
        $this->assertSame(0, LabGeneration::where('trigger_type', LabPopulationService::AUTHORIZED_COUNCIL_PANEL_TRIGGER)->count());
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    public function test_an_untyped_registered_bundle_cannot_become_an_executable_panel_window(): void
    {
        [$work, $input] = $this->fixture(invalidOriginalBundleProtocol: true);
        try {
            app(SpecialistCouncilResearchFeedbackService::class)->registerFollowupProof($work->id, $input, 'fixture-operator');
            $this->fail('A file registry label cannot stand in for the canonical authorized window bundle.');
        } catch (\LogicException $error) {
            $this->assertSame('COUNCIL_PANEL_AUTHORIZED_WINDOW_BUNDLE_PROTOCOL_REQUIRED', $error->getMessage());
        }
        $this->assertNull(data_get($work->fresh()->payload, 'pending_panel_intent'));
        $this->assertNull(data_get($work->fresh()->payload, 'followup_resolution'));
        $this->assertSame(0, LabGeneration::where('trigger_type', LabPopulationService::AUTHORIZED_COUNCIL_PANEL_TRIGGER)->count());
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
    }

    public function test_caller_window_labels_or_changed_original_file_never_create_a_canonical_cohort(): void
    {
        [$work, $input] = $this->fixture();
        $registry = config('services.instrument_policy.authorized_research_windows');
        File::append($registry[0]['mtf_bundle_manifest']['streams']['M5']['path'], "2027-02-02T00:00:00Z,100,101,99,100,10\n");
        $this->expectExceptionMessage('RESEARCH_TRANSPORT_SOURCE_HASH_MISMATCH');
        try { app(SpecialistCouncilResearchFeedbackService::class)->registerFollowupProof($work->id, $input, 'fixture-operator'); }
        finally {
            $this->assertSame(0, LabGeneration::where('trigger_type', LabPopulationService::AUTHORIZED_COUNCIL_PANEL_TRIGGER)->count());
            $this->assertNull(data_get($work->fresh()->payload, 'pending_panel_intent'));
            $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        }
    }

    public function test_actual_registered_windows_may_not_overlap_the_same_market_events(): void
    {
        [$work, $input] = $this->fixture();
        $registry = config('services.instrument_policy.authorized_research_windows');
        $registry[0]['end_exclusive'] = '2027-05-01T00:00:00Z';
        config(['services.instrument_policy.authorized_research_windows' => $registry]);
        $this->expectExceptionMessage('COUNCIL_PANEL_WINDOWS_MUST_BE_ORIGINAL_DISJOINT_EVENTS');
        try { app(SpecialistCouncilResearchFeedbackService::class)->registerFollowupProof($work->id, $input, 'fixture-operator'); }
        finally {
            $this->assertSame(0, LabGeneration::where('trigger_type', LabPopulationService::AUTHORIZED_COUNCIL_PANEL_TRIGGER)->count());
            $this->assertNull(data_get($work->fresh()->payload, 'pending_panel_intent'));
            $this->assertDatabaseCount('lab_evaluation_runs', 0);
        }
    }

}

/** Reusable real owner fixture; its source assessment is deliberately not market proof. */
trait OriginalCouncilPanelReservationFixture
{
    protected function fixture(bool $invalidOriginalSchema = false, bool $invalidOriginalAccountPolicy = false,
        bool $invalidOriginalBundleProtocol = false): array
    {
        $lifecycle = app(SpecialistCouncilLifecycleService::class);
        $execution = app(ExecutionContractService::class)->for('XAUUSD', 'M5');
        // Legacy/source-generation rows are explicitly synthetic input, not
        // new canonical panel materialization or evidence of terminal research.
        $sourceGeneration = LabGeneration::create(['ai_laboratory_id' => AiLaboratory::firstOrFail()->id, 'generation' => 1,
            'trigger_type' => 'historical_research', 'population_size' => 5, 'status' => 'completed', 'completed_at' => now(),
            'trigger_context' => ['synthetic_source_fixture_not_market_proof' => true]]);
        $models = [];
        $schemas = app(StrategyParameterSchemaService::class);
        $sourceParameters = $invalidOriginalSchema ? $schemas->defaults('ema_rsi')
            : $schemas->validate('ema_rsi_v1', array_intersect_key($schemas->defaults('ema_rsi'), $schemas->schema('ema_rsi')));
        foreach (['scalp', 'hour', 'day', 'swing', 'candidate'] as $role) {
            $models[$role] = ModelVersion::create(['name' => 'nonmarket original '.$role, 'strategy' => 'ema_rsi_v1',
                'version' => 'v1', 'status' => 'testing', 'parameters' => $sourceParameters,
                'metadata' => ['base_strategy' => 'ema_rsi', 'strategy_architecture' => 'ema_rsi', 'strategy_family' => 'ema_rsi', 'execution_contract' => $execution]]);
            $sourceAgent = LabAgent::create(['lab_generation_id' => $sourceGeneration->id, 'model_version_id' => $models[$role]->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'ema_rsi', 'origin' => 'synthetic_original_source_fixture',
                'lifecycle_status' => 'completed', 'parameter_diff' => []]);
            $assignment = app(LabInstrumentResearchService::class)->assignment($sourceAgent);
            $this->assertSame(LabInstrumentResearchService::PROTOCOL, $assignment['protocol']);
            $this->assertNotEmpty($assignment['assignment_hash']);
            $models[$role]->refresh();
        }
        $members = [];
        foreach (['scalp', 'hour', 'day', 'swing'] as $role) $members[] = [
            'specialist_id' => $role.'-owner', 'role' => $role, 'version' => 'v1', 'as_of' => '2025-01-01T00:00:00Z',
            'inputs' => ['as_of_closed_candles'], 'scope' => ['symbols' => ['XAUUSD'], 'contexts' => ['trend']],
            'known_limits' => ['synthetic_original_assessment_fixture_not_market_or_authority'],
            'resources' => ['max_compute_ms' => 100, 'max_memory_mb' => 32, 'max_lookback_bars' => 512],
            'horizon' => ['kind' => $role, 'decision_interval_seconds' => 300, 'reevaluation_interval_seconds' => 300,
                'max_holding_seconds' => $role === 'swing' ? 259200 : 3600, 'execution_precision' => 'candle'],
            'data_requirements' => $role === 'scalp' ? ['bid_ask', 'spread', 'slippage', 'quote_age', 'intrabar_ambiguity']
                : ($role === 'swing' ? ['gap', 'carry', 'rollover', 'mature_holding_outcomes'] : ['sessions', 'costs']),
            'model_version_id' => $models[$role]->id, 'strategy_version' => 'v1', 'tactic_version' => 'v1', 'management_version' => 'v1',
            'capital_weight' => .2, 'risk_per_trade_percent' => .5, 'sensor_timeframes' => ['H4', 'H1', 'M15', 'M5']];
        $limits = ['max_open_positions' => 8, 'max_reserved_capital_percent' => 100, 'max_gross_exposure_percent' => 100,
            'max_total_risk_percent' => 2, 'max_drawdown_percent' => 10, 'max_daily_loss_percent' => 3, 'max_expected_cost_percent' => 1];
        $version = $lifecycle->registerDraft(['council_id' => 'real-issuer-nonmarket-panel-fixture', 'version' => 'source-v1', 'members' => $members,
            'components' => [], 'routing' => ['id' => 'router', 'version' => '1'], 'allocation' => ['id' => 'allocation', 'version' => '1'],
            'risk' => ['id' => 'risk', 'version' => '1'], 'execution' => ['id' => 'native', 'version' => '1', 'broker_position_mode' => 'hedging',
                'opposite_position_policy' => 'hedge', ...$limits], 'evaluation_policy' => ['objective' => 'net_return_at_equal_risk',
                'champion_model_version_id' => $models['hour']->id, 'solo_model_version_id' => $models['hour']->id]], 'original-creator');
        $models['candidate'] = $lifecycle->attachResearchModel($version, $models['candidate']);
        $riskPolicy = ['broker_position_mode' => 'hedging', 'opposite_position_policy' => 'hedge', ...$limits,
            'risk_per_trade_percent' => .5];
        if ($invalidOriginalAccountPolicy) unset($riskPolicy['broker_position_mode']);
        $plan = $lifecycle->sealEvaluationPlan($version, 'original-evaluator', ['purpose' => 'research',
            'preparation_source_hash' => app(LabImmutableEvidenceService::class)->codeHash(), 'execution_hash' => $execution['execution_hash'],
            'execution_timeframe' => 'M5', 'initial_capital' => 10000, 'cost_model' => $execution['parameters'],
            'risk_policy' => $riskPolicy,
            'windows' => [['window_key' => 'original-discovery-fixture', 'start_inclusive' => '2025-01-01T00:00:00Z',
                'end_exclusive' => '2025-03-01T00:00:00Z', 'dataset_sha256' => str_repeat('c', 64)]],
            'arms' => [['arm_key' => 'candidate', 'kind' => 'candidate', 'window_key' => 'original-discovery-fixture', 'model_version_id' => $models['candidate']->id],
                ['arm_key' => 'champion', 'kind' => 'champion', 'window_key' => 'original-discovery-fixture', 'model_version_id' => $models['hour']->id],
                ['arm_key' => 'solo', 'kind' => 'solo', 'window_key' => 'original-discovery-fixture', 'model_version_id' => $models['hour']->id],
                ['arm_key' => 'ablation', 'kind' => 'ablation', 'window_key' => 'original-discovery-fixture', 'model_version_id' => $models['candidate']->id, 'removed_id' => 'scalp-owner']]]);
        // Only the scientific starting observation is synthetic. It has no
        // original market runs, qualification or credits, and is labelled so.
        $assessment = ['protocol' => SpecialistCouncilLifecycleService::ASSESSMENT_PROTOCOL, 'version_id' => $version->id,
            'manifest_hash' => $version->manifest_hash, 'plan_hash' => $plan['plan_hash'], 'original_run_ids' => [], 'original_sources' => [],
            'research_observation_status' => 'research_compared', 'comparisons' => [['powered' => true, 'incremental_value' => true,
                'net_profit_delta_vs_solo' => 1, 'ablations' => [['incremental_value_observed' => true]]]],
            'qualified' => false, 'positive_independent_windows' => 0, 'reason_codes' => ['SYNTHETIC_FIXTURE_NOT_MARKET_PROOF']];
        $hash = app(ResearchPaperEpochContractService::class)->parameterHash($assessment);
        DB::table('specialist_council_evaluations')->insert(['specialist_council_version_id' => $version->id, 'evaluator_id' => 'original-evaluator',
            'original_run_ids' => '[]', 'assessment' => json_encode($assessment), 'assessment_hash' => $hash, 'created_at' => now(), 'updated_at' => now()]);
        $version->forceFill(['state' => 'evaluated', 'assessment' => $assessment, 'assessment_hash' => $hash])->save();
        app(SpecialistCouncilResearchFeedbackService::class)->recordAssessment($version->fresh());
        $work = ResearchExperimentWorkItem::sole();
        $this->assertSame('specialist_council_independent_validation', $work->work_type);
        $registries = [];
        foreach ([2, 4, 6] as $month) {
            $start = sprintf('2027-%02d-01T00:00:00Z', $month);
            $end = sprintf('2027-%02d-01T00:00:00Z', $month + 1);
            $streams = [];
            foreach (['M5' => 300, 'M15' => 900, 'H1' => 3600, 'H4' => 14400] as $tf => $step) {
                $path = $this->root.'/app/lab-datasets/window-'.$month.'-'.$tf.'.csv';
                $second = CarbonImmutable::parse($start)->addSeconds($step)->format('Y-m-d\TH:i:s\Z');
                File::put($path, "time,open,high,low,close,volume\n{$start},100,101,99,100,10\n{$second},100,102,99,101,11\n");
                $streams[$tf] = ['path' => $path, 'sha256' => hash_file('sha256', $path), 'rows' => 2,
                    'first_candle_at' => $start, 'last_candle_at' => $second];
            }
            $bundleHash = app(ResearchPaperEpochContractService::class)->parameterHash($streams);
            $registries[] = ['authorization_id' => 'actual-panel-window-'.$month, 'research_epoch_id' => 'post-paper-research',
                'purpose' => 'instrument_independent_validation', 'dataset_sha256' => $bundleHash, 'start_inclusive' => $start, 'end_exclusive' => $end,
                'mtf_bundle_manifest' => ['protocol' => $invalidOriginalBundleProtocol ? 'multi_timeframe_snapshot_v1'
                        : \App\Services\MultiTimeframeSnapshotService::PROTOCOL,
                    'validation_bundle_protocol' => SpecialistCouncilPanelReservationService::BUNDLE_PROTOCOL,
                    'symbol' => 'XAUUSD', 'bundle_hash' => $bundleHash,
                    'streams' => $streams, 'promotion_evidence' => false]];
        }
        config(['services.instrument_policy.authorized_research_windows' => $registries]);
        return [$work, ['protocol' => SpecialistCouncilPanelReservationService::PROTOCOL,
            'authorization_ids' => array_column($registries, 'authorization_id'), 'creator_id' => 'panel-creator',
            'evaluator_id' => 'panel-independent-examiner', 'research_question' => 'Does the frozen native council retain incremental value on three originally authorized unseen event windows?'], $version->fresh(), $models];
    }
}
