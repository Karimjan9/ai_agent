<?php

namespace Tests\Feature;

use App\Services\LearningTechnicalCircuitBreakerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LearningTechnicalCircuitBreakerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_quarantine_cools_down_to_one_half_open_probe_and_recovers_from_immutable_success(): void
    {
        Carbon::setTestNow('2026-08-26 00:00:00');
        config()->set('services.lab_queue.technical_breaker_cooldown_minutes', 30);
        config()->set('services.lab_queue.technical_breaker_probe_lease_minutes', 60);
        $breaker = app(LearningTechnicalCircuitBreakerService::class);

        $this->assertFalse($breaker->record('xauusd', 'h1', 'HTTP timeout 10'));
        $this->assertFalse($breaker->record('XAUUSD', 'H1', 'HTTP timeout 20'));
        $this->assertTrue($breaker->record('XAUUSD', 'H1', 'HTTP timeout 30'));
        $this->assertTrue($breaker->blocked('XAUUSD', 'H1'));

        Carbon::setTestNow('2026-08-26 00:31:00');
        $this->assertFalse($breaker->blocked('XAUUSD', 'H1'));
        $this->assertDatabaseHas('learning_technical_failures', [
            'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'occurrences' => 3, 'status' => 'half_open',
        ]);
        $this->assertTrue($breaker->blocked('XAUUSD', 'H1'), 'A second probe must be blocked while the first lease is live.');

        $this->assertSame(1, $breaker->recordSuccess('XAUUSD', 'H1', [
            'evidence_run_id' => 'immutable-success-1',
        ]));
        $this->assertFalse($breaker->blocked('XAUUSD', 'H1'));
        $this->assertDatabaseHas('learning_technical_failures', ['status' => 'recovered']);

        // A recovered historical count does not make one new incident a
        // repeat failure. This starts a fresh breaker streak.
        $this->assertFalse($breaker->record('XAUUSD', 'H1', 'HTTP timeout 40'));
        $this->assertDatabaseHas('learning_technical_failures', [
            'occurrences' => 1, 'status' => 'observed',
        ]);
    }

    public function test_failed_half_open_probe_returns_to_quarantine_and_expired_lease_can_rearm(): void
    {
        Carbon::setTestNow('2026-08-26 00:00:00');
        config()->set('services.lab_queue.technical_breaker_cooldown_minutes', 10);
        config()->set('services.lab_queue.technical_breaker_probe_lease_minutes', 20);
        $breaker = app(LearningTechnicalCircuitBreakerService::class);

        foreach (range(1, 3) as $attempt) {
            $breaker->record('XAUUSD', 'H1', "provider unavailable {$attempt}");
        }
        Carbon::setTestNow('2026-08-26 00:11:00');
        $this->assertFalse($breaker->blocked('XAUUSD', 'H1'));
        // Probe failure may have a different fingerprint than the incident
        // that opened it; the scope lease must still close fail-safe.
        $this->assertTrue($breaker->record('XAUUSD', 'H1', 'AI service connection refused'));
        $this->assertTrue($breaker->blocked('XAUUSD', 'H1'));
        $this->assertSame(1, DB::table('learning_technical_failures')->count());
        $this->assertDatabaseHas('learning_technical_failures', [
            'occurrences' => 4,
            'status' => 'technical_quarantine',
        ]);

        Carbon::setTestNow('2026-08-26 00:22:00');
        $this->assertFalse($breaker->blocked('XAUUSD', 'H1'));
        $this->assertTrue($breaker->blocked('XAUUSD', 'H1'));

        Carbon::setTestNow('2026-08-26 00:43:00');
        $this->assertFalse($breaker->blocked('XAUUSD', 'H1'));
        $context = json_decode((string) DB::table('learning_technical_failures')->value('context'), true);
        $this->assertSame(3, $context['half_open_attempt']);
        $this->assertSame('expired_probe_lease', $context['half_open_reason']);
    }

    public function test_population_admission_failure_releases_only_the_probe_acquired_by_this_instance(): void
    {
        Carbon::setTestNow('2026-08-26 00:00:00');
        config()->set('services.lab_queue.technical_breaker_cooldown_minutes', 10);
        $breaker = app(LearningTechnicalCircuitBreakerService::class);
        foreach (range(1, 3) as $attempt) {
            $breaker->record('XAUUSD', 'H1', "transport timeout {$attempt}");
        }

        $this->assertSame(0, app(LearningTechnicalCircuitBreakerService::class)
            ->releaseAcquiredProbe('XAUUSD', 'H1', 'FOREIGN_CALLER'));
        Carbon::setTestNow('2026-08-26 00:11:00');
        $this->assertFalse($breaker->blocked('XAUUSD', 'H1'));
        $this->assertSame(1, $breaker->releaseAcquiredProbe('XAUUSD', 'H1', 'NO_ELIGIBLE_LESSON'));
        $this->assertDatabaseHas('learning_technical_failures', ['status' => 'technical_quarantine']);

        $context = json_decode((string) DB::table('learning_technical_failures')->value('context'), true);
        $this->assertSame('NO_ELIGIBLE_LESSON', $context['probe_release_reason']);
        $this->assertFalse($context['probe_evaluator_called']);
        // last_seen_at remains the actual transport failure time; admission
        // policy is not misclassified as a fresh runtime incident.
        $this->assertSame('2026-08-26 00:00:00', (string) DB::table('learning_technical_failures')->value('last_seen_at'));
    }

    public function test_existing_confirmation_draft_can_adopt_and_release_its_live_probe(): void
    {
        Carbon::setTestNow('2026-08-26 00:00:00');
        config()->set('services.lab_queue.technical_breaker_cooldown_minutes', 10);
        $owner = app(LearningTechnicalCircuitBreakerService::class);
        foreach (range(1, 3) as $attempt) {
            $owner->record('XAUUSD', 'H1', "transport timeout {$attempt}");
        }
        Carbon::setTestNow('2026-08-26 00:11:00');
        $this->assertFalse($owner->blocked('XAUUSD', 'H1'));

        // A later command process resumes the already-created draft. It may
        // adopt that exact live lease, but an unrelated caller still cannot.
        $resumer = app(LearningTechnicalCircuitBreakerService::class);
        $this->assertTrue($resumer->adoptHalfOpenProbe('xauusd', 'h1'));
        $this->assertSame(1, $resumer->releaseAcquiredProbe(
            'XAUUSD',
            'H1',
            'LEARNING_CONFIRMATION_PREFLIGHT_REJECTED',
        ));
        $this->assertDatabaseHas('learning_technical_failures', [
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'status' => 'technical_quarantine',
        ]);
    }
}
