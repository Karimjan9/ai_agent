<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\FailureDojoService;
use App\Services\LearningVelocityGateService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LearningVelocityDiagnosticProjectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T14:00:00Z'));
        config(['services.lab_selection.learning_velocity_enabled' => true,
            'services.lab_selection.learning_velocity_lookback_generations' => 3,
            'services.lab_selection.learning_velocity_max_unresolved_screen_generations' => 1,
            'services.lab_selection.zero_pass_circuit_breaker_generations' => 3]);
        Http::preventStrayRequests();
        Queue::fake();
    }

    #[DataProvider('gateStates')]
    public function test_projection_preserves_every_fresh_gate_field(string $state, string $status, bool $allowed): void
    {
        $lab = $this->fixture($state);
        $owner = app(LearningVelocityGateService::class);
        $full = $owner->inspect($lab ?? 'EURUSD', 'H1');
        $projected = $owner->inspect($lab ?? 'EURUSD', 'H1', false);

        $this->assertSame($status, $full['status']);
        $this->assertSame($allowed, $full['allowed']);
        $this->assertSame(Arr::except($full, ['learning_truth']), $projected);
        $this->assertArrayNotHasKey('learning_truth', $projected);
        if (! in_array($state, ['missing_lab', 'disabled'], true)) {
            $this->assertArrayHasKey('learning_truth', $full);
        }
        if ($state === 'technical_recovery') {
            $this->assertSame(1, $projected['technical_recovery_agents']);
            $this->assertCount(1, $projected['technical_recovery_targets']);
        }
        if ($state === 'live_backlog') $this->assertTrue($projected['health_layers']['live_learning_backlog']['active']);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function gateStates(): array
    {
        return [
            'missing laboratory' => ['missing_lab', 'no_history', true],
            'disabled policy' => ['disabled', 'disabled', true],
            'empty history' => ['empty', 'no_history', true],
            'healthy history' => ['healthy', 'healthy', true],
            'technical recovery' => ['technical_recovery', 'blocked_technical_recovery', false],
            'active learning' => ['active_learning', 'learning_in_progress', false],
            'screen pass debt' => ['screen_backlog', 'blocked_learning_backlog', false],
            'below debt cap' => ['throughput_warning', 'learning_throughput_warning', true],
            'strategy deadlock' => ['deadlock', 'strategy_deadlock', false],
            'live learning starvation' => ['live_backlog', 'live_learning_backlog', false],
        ];
    }

    public function test_default_keeps_real_truth_scoreboard_but_projection_does_not_query_it(): void
    {
        $lab = $this->fixture('healthy');
        $owner = app(LearningVelocityGateService::class);
        DB::enableQueryLog();
        try {
            DB::flushQueryLog();
            $full = $owner->inspect($lab);
            $fullQueries = DB::getQueryLog();
            DB::flushQueryLog();
            $projected = $owner->inspect($lab, null, false);
            $projectedQueries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $lessonReads = static fn (array $queries): array => array_values(array_filter($queries,
            static fn (array $query): bool => preg_match('/^select\b.*\bfrom\s+["`]?agent_learning_lessons["`]?\b/i', $query['query']) === 1));
        $this->assertNotEmpty($lessonReads($fullQueries));
        $this->assertSame([], $lessonReads($projectedQueries));
        $this->assertSame(0, $full['learning_truth']['provisional_lesson_count']);
        $this->assertSame(Arr::except($full, ['learning_truth']), $projected);
        $this->assertArrayNotHasKey('learning_truth', $projected);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    private function fixture(string $state): ?AiLaboratory
    {
        if ($state === 'missing_lab') return null;
        $lab = AiLaboratory::create(['name' => 'Diagnostic projection', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'lifecycle_mode' => 'lighthouse', 'is_active' => true]);
        if ($state === 'disabled') {
            config(['services.lab_selection.learning_velocity_enabled' => false]);
            return $lab;
        }
        if ($state === 'empty') return $lab;
        if ($state === 'throughput_warning') config(['services.lab_selection.learning_velocity_max_unresolved_screen_generations' => 2]);
        if ($state === 'live_backlog') $this->mock(FailureDojoService::class, function ($mock): void {
            $mock->shouldReceive('summary')->twice()->with('XAUUSD', 'H1')
                ->andReturn(['pending' => 1, 'actionable_pending' => 1, 'legacy_invalid_pending' => 0]);
        });
        foreach (range(1, $state === 'deadlock' ? 3 : 1) as $number) {
            $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => $number,
                'trigger_type' => 'test', 'status' => 'screened', 'population_size' => 1, 'trigger_context' => []]);
            $model = ModelVersion::create(['name' => 'projection-'.$number, 'strategy' => 'hybrid', 'version' => 'projection-'.$number,
                'generation' => $number, 'status' => 'testing', 'parameters' => [], 'metadata' => []]);
            $lifecycle = match ($state) {
                'technical_recovery' => 'evaluation_error', 'active_learning' => 'full_queued', default => 'screened',
            };
            $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'test',
                'lifecycle_status' => $lifecycle, 'parameter_diff' => [],
                'decision_reason' => $state === 'technical_recovery' ? 'AI service unavailable: connection refused.' : null]);
            if (in_array($state, ['screen_backlog', 'throughput_warning', 'deadlock'], true)) {
                CandidateGateDecision::create(['lab_agent_id' => $agent->id, 'stage' => 'screening',
                    'decision' => $state === 'deadlock' ? 'failed' : 'passed', 'reason_codes' => [], 'metrics' => [], 'evaluated_at' => now()]);
            }
        }
        return $lab;
    }
}
