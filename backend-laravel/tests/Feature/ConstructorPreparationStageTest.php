<?php

namespace Tests\Feature;

use App\Services\AutonomousModeService;
use App\Services\LabPopulationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class ConstructorPreparationStageTest extends TestCase
{
    private const KEY = 'lab-population-constructor:XAUUSD:H1:v1:owner';

    protected function setUp(): void
    {
        parent::setUp();
        Log::spy();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T12:00:00Z'));
    }

    public function test_diagnostic_keeps_original_expiry_heartbeat_and_attempt_identity(): void
    {
        $attempt = $this->attempt();
        $expires = CarbonImmutable::parse($attempt['acquired_at'])->addSeconds(LabPopulationService::CONSTRUCTOR_LOCK_TTL_SECONDS);
        Cache::put(self::KEY, $attempt, $expires);
        $owner = (new ReflectionClass(LabPopulationService::class))->newInstanceWithoutConstructor();
        DB::enableQueryLog();
        $this->publish($owner, $attempt, 'generation_plan');
        $first = Cache::get(self::KEY);
        $this->assertSame($attempt, array_diff_key($first, ['preparation' => true]));
        $this->assertSame('generation_plan', $first['preparation']['stage']);
        $this->assertFalse($first['preparation']['promotion_evidence']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $first['preparation']['attempt_key']);
        $this->travelTo($expires->subSecond());
        $this->publish($owner, $attempt, 'adaptive_governor');
        $second = Cache::get(self::KEY);
        $this->assertSame($first['preparation']['attempt_key'], $second['preparation']['attempt_key']);
        $this->assertSame($attempt['heartbeat_at'], $second['heartbeat_at']);
        $this->assertCount(0, DB::getQueryLog());
        DB::disableQueryLog();
        $this->travelTo($expires->addSecond());
        $this->assertNull(Cache::get(self::KEY), 'Diagnostics must not renew the constructor owner/lease deadline');
        Log::shouldHaveReceived('info')->twice();
    }

    public function test_missing_or_replaced_attempt_cannot_publish_or_manufacture_ownership(): void
    {
        $owner = (new ReflectionClass(LabPopulationService::class))->newInstanceWithoutConstructor();
        $attempt = $this->attempt();
        $this->publish($owner, $attempt, 'generation_plan');
        $this->assertNull(Cache::get(self::KEY));
        foreach (['pid', 'acquired_at', 'trigger', 'hostname'] as $field) {
            $replacement = $attempt;
            $replacement[$field] = $field === 'pid' ? getmypid() + 1 : $replacement[$field].'-changed';
            Cache::put(self::KEY, $replacement, 200);
            $this->publish($owner, $attempt, 'generation_plan');
            $this->assertSame($replacement, Cache::get(self::KEY));
        }
        Log::shouldNotHaveReceived('info');
    }

    public function test_public_constructor_records_phase_before_first_heavy_call_and_exception_cleanup_preserves_log(): void
    {
        $mode = Mockery::mock(AutonomousModeService::class);
        $mode->shouldReceive('enabled')->once()->with('XAUUSD', 'H1')->andReturn(true);
        $this->app->instance(AutonomousModeService::class, $mode);
        $owner = Mockery::mock(LabPopulationService::class)->makePartial();
        $owner->shouldReceive('recoverStaleConstructorLease')->once()->andReturn(['status' => 'not_active']);
        $owner->shouldReceive('ensureLaboratories')->once()->andReturnUsing(function (): void {
            $record = Cache::get(self::KEY);
            $this->assertSame('laboratory_identity', $record['preparation']['stage']);
            $this->assertNull($record['generation_id']);
            throw new RuntimeException('CONDITIONAL_FIRST_DEPENDENCY_REFUSAL');
        });
        try {
            $owner->build('XAUUSD', 'historical_research', false, 'H1', refreshHistoricalLearning: false);
            $this->fail('Original dependency error must propagate');
        } catch (RuntimeException $error) {
            $this->assertSame('CONDITIONAL_FIRST_DEPENDENCY_REFUSAL', $error->getMessage());
        }
        $this->assertNull(Cache::get(self::KEY));
        Log::shouldHaveReceived('info')->once()->with('Population constructor preparation stage.', Mockery::on(function (array $record): bool {
            $this->assertSame('laboratory_identity', $record['stage']);
            $this->assertFalse($record['promotion_evidence']);
            $this->assertArrayNotHasKey('command', $record);
            $this->assertArrayNotHasKey('parameters', $record);
            return true;
        }));
    }

    public function test_pause_guard_stays_before_owner_diagnostics_and_any_heavy_dependency(): void
    {
        $mode = Mockery::mock(AutonomousModeService::class);
        $mode->shouldReceive('enabled')->once()->with('XAUUSD', 'H1')->andReturn(false);
        $this->app->instance(AutonomousModeService::class, $mode);
        $owner = Mockery::mock(LabPopulationService::class)->makePartial();
        $owner->shouldNotReceive('recoverStaleConstructorLease');
        $owner->shouldNotReceive('ensureLaboratories');
        $this->assertNull($owner->build('XAUUSD', 'historical_research'));
        $this->assertNull(Cache::get(self::KEY));
        $this->assertSame('GENERATION_ADMISSION_BLOCK_HARD', $owner->lastBuildOutcome()['reason_code']);
        Log::shouldNotHaveReceived('info');
    }

    private function publish(LabPopulationService $owner, array $attempt, string $phase): void
    {
        (new ReflectionMethod(LabPopulationService::class, 'publishConstructorPreparationStage'))
            ->invoke($owner, 'XAUUSD', 'H1', $attempt, $phase);
    }

    private function attempt(): array
    {
        return ['protocol' => 'lab_population_constructor_owner_v1', 'operation' => 'build',
            'trigger' => 'historical_research', 'generation_id' => null, 'command' => 'conditional-owned-command',
            'pid' => getmypid(), 'hostname' => 'conditional-test-host', 'acquired_at' => now()->subSeconds(30)->toIso8601String(),
            'heartbeat_at' => now()->subSeconds(30)->toIso8601String(), 'promotion_evidence' => false];
    }
}
