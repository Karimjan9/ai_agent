<?php

namespace Tests\Feature;

use App\Models\LabGeneration;
use App\Models\LabEvaluationRun;
use App\Models\ResearchExperimentWorkItem;
use App\Models\SystemEvent;
use App\Services\AutonomousModeService;
use App\Services\LabPopulationService;
use App\Services\ResearchExperimentConversionKernelService;
use App\Services\ResearchLoopArbiterService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\SpecialistCouncilFollowupExecutionService;
use App\Services\SpecialistCouncilPreparationService;
use App\Services\SpecialistCouncilResearchFeedbackService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;
use Throwable;

class SpecialistCouncilExecutorDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    /** Fixture lease only: no actual market replay or canonical dispatch is requested. */
    private function work(): ResearchExperimentWorkItem
    {
        app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'diagnostic fixture');
        $result = app(ResearchExperimentConversionKernelService::class)->record([
            'contract_version' => ResearchExperimentConversionKernelService::CONTRACT_VERSION,
            'source' => ['type' => 'executor-diagnostic-fixture', 'id' => 1],
            'scope' => ['symbol' => 'XAUUSD', 'laboratory_timeframe' => 'H1', 'execution_timeframe' => 'M5'],
            'identity' => ['baseline_epoch_hash' => 'base', 'data_and_mtf_hash' => 'data',
                'runtime_and_contract_hash' => 'runtime', 'intervention_hash' => 'change',
                'window_plan_hash' => 'window', 'evaluator_version' => 'diagnostic-fixture'],
            'arms' => [['role' => 'candidate'], ['role' => 'solo']],
        ], ['fixture' => true], 'UNDERPOWERED', [
            'type' => 'specialist_council_power_extension', 'identity' => 'original', 'executable' => false,
            'owner' => ResearchLoopArbiterService::class,
            'retry_condition' => ['code' => 'NEW_PREREGISTERED_SCOPE_REQUIRED', 'max_experiments' => 1],
        ]);
        $item = ResearchExperimentWorkItem::findOrFail($result['work_id']);
        $item->update(['status' => 'leased', 'attempts' => 1, 'fence_version' => 1,
            'lease_token' => 'fixture-only-lease', 'lease_expires_at' => now()->addMinutes(10), 'heartbeat_at' => now()]);
        return $item->fresh();
    }

    private function executor(Throwable $error, string $stage = 'readiness', ?callable $beforeThrow = null): SpecialistCouncilFollowupExecutionService
    {
        $feedback = Mockery::mock(SpecialistCouncilResearchFeedbackService::class);
        $population = Mockery::mock(LabPopulationService::class);
        if ($stage === 'construction') {
            $feedback->shouldReceive('inspectFollowupReadiness')->once()->andReturn([
                'executable' => true, 'research_question' => 'Diagnostic construction fixture',
                'creator_id' => 'fixture-only', 'resolution_hash' => str_repeat('b', 64),
            ]);
            $population->shouldReceive('build')->once()->andThrow($error);
        } else {
            $feedback->shouldReceive('inspectFollowupReadiness')->andReturnUsing(function () use ($error, $beforeThrow): never {
                if ($beforeThrow) $beforeThrow();
                throw $error;
            });
            $population->shouldNotReceive('build');
        }
        $executor = Mockery::mock(SpecialistCouncilFollowupExecutionService::class, [
            $feedback, app(ResearchExperimentConversionKernelService::class), $population,
            app(SpecialistCouncilPreparationService::class), app(ResearchPaperEpochContractService::class),
            app(AutonomousModeService::class),
        ])->makePartial();
        $executor->shouldReceive('retryPrerequisiteHash')->andReturn(str_repeat('a', 64));
        return $executor;
    }

    private function events()
    {
        return SystemEvent::where('event_type', 'specialist_council_executor_failure');
    }

    private function record(SpecialistCouncilFollowupExecutionService $executor, ResearchExperimentWorkItem $item, Throwable $error): void
    {
        (new ReflectionMethod(SpecialistCouncilFollowupExecutionService::class, 'recordExecutorFailure'))
            ->invoke($executor, $item, $error, 'COUNCIL_FOLLOWUP_EXECUTOR_TECHNICAL_FAILURE', 'readiness');
    }

    public function test_sql_failure_is_durable_sanitized_and_does_not_pollute_original_result_or_payload(): void
    {
        $item = $this->work();
        $originalPayload = $item->payload;
        $error = new QueryException('mysql', 'select * from private_table where password = ?',
            ['diagnostic-dummy-secret'], new \PDOException('fixture-password=diagnostic-dummy-password'));
        $outcome = $this->executor($error)->execute($item);
        $this->assertSame('COUNCIL_FOLLOWUP_EXECUTOR_TECHNICAL_FAILURE', $outcome['reason']);
        $this->assertSame('blocked', $item->fresh()->status);
        $this->assertSame($originalPayload, $item->fresh()->payload);
        $this->assertSame(['dependency_hold' => ['reason' => $outcome['reason'],
            'prerequisite_hash' => str_repeat('a', 64), 'promotion_evidence' => false]], $item->fresh()->result);
        $event = $this->events()->sole();
        $this->assertSame(ResearchExperimentWorkItem::class, $event->source_type);
        $this->assertSame($item->id, $event->source_id);
        $this->assertSame(QueryException::class, $event->payload['exception_class']);
        $this->assertSame(hash('sha256', $error->getMessage()), $event->payload['message_hash']);
        $this->assertSame('backend-laravel/tests/Feature/SpecialistCouncilExecutorDiagnosticsTest.php', $event->payload['file']);
        $this->assertGreaterThan(0, $event->payload['line']);
        $this->assertSame('readiness', $event->payload['stage']);
        $this->assertSame(1, $event->payload['attempt']);
        $this->assertSame(1, $event->payload['fence_version']);
        $this->assertFalse($event->payload['promotion_evidence']);
        $this->assertFalse($event->payload['scientific_evidence']);
        $this->assertNotNull($event->occurred_at);
        $stored = json_encode([$event->toArray(), $outcome, $item->fresh()->result]);
        foreach (['private_table', 'diagnostic-dummy-secret', 'diagnostic-dummy-password', 'fixture-only-lease',
            str_replace('\\', '/', base_path()), $error->getMessage()] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $stored);
        }
        $this->assertSame(1, $item->fresh()->attempts);
        $this->assertSame(1, $item->fresh()->fence_version);
        $this->assertSame(0, LabGeneration::count());
        $this->assertSame(0, LabEvaluationRun::count());
    }

    public static function reasons(): array
    {
        return [
            'logic machine code' => [\LogicException::class, 'COUNCIL_EXACT_SOURCE_REQUIRED', 'COUNCIL_EXACT_SOURCE_REQUIRED'],
            'argument machine code' => [\InvalidArgumentException::class, 'COUNCIL_INPUT_INVALID', 'COUNCIL_INPUT_INVALID'],
            'sensitive free text' => [\LogicException::class, 'password=fixture-secret at C:/private/file.php', 'COUNCIL_FOLLOWUP_EXECUTOR_TECHNICAL_FAILURE'],
            'unbounded code' => [\LogicException::class, str_repeat('A', 161), 'COUNCIL_FOLLOWUP_EXECUTOR_TECHNICAL_FAILURE'],
        ];
    }

    #[DataProvider('reasons')]
    public function test_only_bounded_machine_code_logic_reasons_are_preserved(string $class, string $message, string $expected): void
    {
        $item = $this->work();
        $error = new $class($message);
        $outcome = $this->executor($error)->execute($item);
        $this->assertSame($expected, $outcome['reason']);
        $this->assertSame($expected, $item->fresh()->last_error);
        $this->assertSame($expected, $this->events()->sole()->payload['reason_code']);
        $this->assertSame(hash('sha256', $message), $this->events()->sole()->payload['message_hash']);
    }

    public function test_anonymous_exception_class_does_not_disclose_its_embedded_absolute_path(): void
    {
        $item = $this->work();
        $error = new class('anonymous-fixture-secret') extends \RuntimeException {};
        $this->executor($error)->execute($item);
        $payload = $this->events()->sole()->payload;
        $this->assertSame(\RuntimeException::class, $payload['exception_class']);
        $this->assertSame(hash('sha256', get_class($error)), $payload['exception_class_hash']);
        $this->assertStringNotContainsString(get_class($error), json_encode($payload));
        $this->assertStringNotContainsString('anonymous-fixture-secret', json_encode($payload));
    }

    public function test_external_exception_file_and_line_are_withheld(): void
    {
        $item = $this->work();
        $error = new \RuntimeException('external-fixture-secret');
        (new ReflectionProperty(\Exception::class, 'file'))->setValue($error, 'C:/private-account/credentials.php');
        $this->executor($error)->execute($item);
        $payload = $this->events()->sole()->payload;
        $this->assertNull($payload['file']);
        $this->assertNull($payload['line']);
        $this->assertStringNotContainsString('private-account', json_encode($payload));
    }

    public function test_construction_failure_has_original_stage_and_never_builds_extra_science(): void
    {
        $item = $this->work();
        $this->executor(new \RuntimeException('constructor-fixture-secret'), 'construction')->execute($item);
        $this->assertSame('construction', $this->events()->sole()->payload['stage']);
        $this->assertSame(1, $item->fresh()->attempts);
        $this->assertSame(0, LabGeneration::count());
        $this->assertSame(0, LabEvaluationRun::count());
    }

    public function test_stop_and_stale_fences_cannot_append_diagnostic_or_dependency_hold(): void
    {
        $item = $this->work();
        app(AutonomousModeService::class)->stop('XAUUSD', 'H1', 'test', 'fixture-stop');
        $result = $this->executor(new \RuntimeException('never-read'))->execute($item);
        $this->assertSame('AUTONOMOUS_MODE_STOPPED', $result['reason']);
        $this->assertSame([], $item->fresh()->result ?? []);
        $this->assertSame(0, $this->events()->count());
        app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'fixture-resume');
        $item->refresh()->update(['status' => 'leased', 'fence_version' => 2, 'lease_token' => 'new-fixture-only-lease',
            'lease_expires_at' => now()->addMinutes(10)]);
        $oldFence = $item->replicate();
        $oldFence->id = $item->id;
        $oldFence->fence_version = 1;
        $oldFence->lease_token = 'fixture-only-lease';
        $result = $this->executor(new \RuntimeException('never-read'))->execute($oldFence);
        $this->assertSame('COUNCIL_FOLLOWUP_LEASE_NOT_CURRENT', $result['reason']);
        $this->assertSame('leased', $item->fresh()->status);
        $this->assertSame([], $item->fresh()->result ?? []);
        $this->assertSame(0, $this->events()->count());
        $this->assertSame(1, $item->fresh()->attempts);
    }

    public function test_superseded_lease_during_failure_cannot_write_under_new_owner(): void
    {
        $item = $this->work();
        $executor = $this->executor(new \RuntimeException('superseded-fixture-secret'), 'readiness', function () use ($item): void {
            ResearchExperimentWorkItem::whereKey($item->id)->update(['fence_version' => 2, 'lease_token' => 'new-owner']);
        });
        $executor->execute($item);
        $this->assertSame(0, $this->events()->count());
        $this->assertSame('leased', $item->fresh()->status);
        $this->assertSame([], $item->fresh()->result ?? []);
    }

    public function test_event_is_idempotent_for_same_fence_and_survives_original_defer_and_repeat(): void
    {
        $item = $this->work();
        $error = new \RuntimeException('durable-fixture-secret');
        $executor = $this->executor($error);
        $this->record($executor, $item, $error);
        $this->record($executor, $item, $error);
        $this->assertSame(1, $this->events()->count());
        $original = $this->events()->sole()->toArray();
        $executor->execute($item);
        $this->assertSame('blocked', $item->fresh()->status);
        $this->assertSame($original, $this->events()->sole()->toArray());
        $again = $executor->execute($item);
        $this->assertSame('COUNCIL_FOLLOWUP_LEASE_NOT_CURRENT', $again['reason']);
        $this->assertSame(1, $this->events()->count());
        $this->assertSame(1, $item->fresh()->attempts);
        $this->assertSame(1, $item->fresh()->fence_version);
    }

    public function test_storage_failure_has_only_sanitized_fallback_and_cannot_replace_disposition(): void
    {
        $item = $this->work();
        $error = new \RuntimeException('original-fixture-secret');
        $executor = $this->executor($error);
        $originalDatabase = DB::getFacadeRoot();
        $database = Mockery::mock($originalDatabase)->makePartial();
        $database->shouldReceive('transaction')->once()->andThrow(new \RuntimeException('database-fixture-secret'));
        DB::swap($database);
        Log::shouldReceive('error')->once()->with('Council executor diagnostic storage unavailable.', Mockery::on(function ($context) use ($error): bool {
            $this->assertSame(hash('sha256', $error->getMessage()), $context['diagnostic']['message_hash']);
            $this->assertStringNotContainsString('original-fixture-secret', json_encode($context));
            $this->assertStringNotContainsString('database-fixture-secret', json_encode($context));
            return true;
        }))->andThrow(new \RuntimeException('log-fixture-secret'));
        try {
            $this->record($executor, $item, $error);
        } finally {
            DB::swap($originalDatabase);
        }
        $this->assertSame('leased', $item->fresh()->status);
        $this->assertSame([], $item->fresh()->result ?? []);
        $this->assertSame(1, $item->fresh()->attempts);
    }
}
