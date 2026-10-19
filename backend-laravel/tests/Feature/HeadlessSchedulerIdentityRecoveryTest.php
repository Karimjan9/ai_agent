<?php

namespace Tests\Feature;

use App\Console\Commands\RunHeadlessScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class HeadlessSchedulerIdentityRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-19T13:05:00Z'));
        Log::spy();
    }

    #[DataProvider('ownerIdentities')]
    public function test_only_proven_stale_local_owner_identity_is_recoverable(string $case, bool $recoverable): void
    {
        $key = 'test:scheduler-identity:'.$case;
        $owner = Cache::lock($key, 7200);
        $this->assertTrue($owner->get());
        $metadata = $this->metadata($key);
        $identity = $this->identity();
        if ($case === 'live_scheduler' || $case === 'new_scheduler') {
            $identity['command_line'] = '"'.$identity['executable_path'].'" "'.base_path('artisan').'" schedule:headless-work';
            if ($case === 'live_scheduler') $identity['created_at'] = '2026-10-19T12:30:00Z';
        }
        if ($case === 'failed_probe') $identity = null;
        if ($case === 'missing_command') unset($identity['command_line']);
        if ($case === 'invalid_birth') $identity['created_at'] = 'not-a-time';
        if ($case === 'missing_start') unset($metadata['started_at']);
        if ($case === 'older_worker') $identity['created_at'] = '2026-10-19T12:00:00Z';
        if ($case === 'another_project') $identity['command_line'] = 'php.exe "C:/other/artisan" queue:work redis';
        if ($case === 'another_host') $metadata['hostname'] = 'not-this-local-host';
        if ($case === 'fresh_heartbeat') $metadata['heartbeat_at'] = now()->toIso8601String();
        if ($case === 'absent_pid') $identity = ['running' => false];
        Cache::put('system:scheduler-lease', $metadata, 7200);
        Cache::put('system:scheduler-heartbeat', 'original-stale-heartbeat', 600);
        $command = \Mockery::mock(RunHeadlessScheduler::class)->makePartial()->shouldAllowMockingProtectedMethods();
        if (in_array($case, ['another_host', 'fresh_heartbeat'], true)) $command->shouldNotReceive('localProcessIdentity');
        else $command->shouldReceive('localProcessIdentity')->once()->with(11120)->andReturn($identity);
        $contender = Cache::lock($key, 7200);
        $this->assertSame($recoverable, $this->recover($command, $contender, $key));
        $this->assertSame($metadata, Cache::get('system:scheduler-lease'));
        $this->assertSame('original-stale-heartbeat', Cache::get('system:scheduler-heartbeat'));
        if ($recoverable) {
            $this->assertTrue($contender->get());
            $this->assertFalse($owner->isOwnedByCurrentProcess());
            $contender->release();
        } else $this->assertTrue($owner->isOwnedByCurrentProcess());
        $owner->forceRelease();
    }

    public static function ownerIdentities(): array
    {
        return array_map(static fn (string $case): array => [$case, in_array($case, ['reused_worker', 'absent_pid'], true)],
            ['reused_worker', 'absent_pid', 'live_scheduler', 'new_scheduler', 'failed_probe', 'missing_command',
                'invalid_birth', 'missing_start', 'older_worker', 'another_project', 'another_host', 'fresh_heartbeat']);
    }

    public function test_metadata_changed_during_identity_probe_is_never_released_or_erased(): void
    {
        $key = 'test:scheduler-identity:metadata-race';
        $owner = Cache::lock($key, 7200); $owner->get();
        Cache::put('system:scheduler-lease', $this->metadata($key), 7200);
        $replacement = [...$this->metadata($key), 'pid' => 22222, 'heartbeat_at' => now()->toIso8601String()];
        $command = \Mockery::mock(RunHeadlessScheduler::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $command->shouldReceive('localProcessIdentity')->once()->andReturnUsing(function () use ($replacement): array {
            Cache::put('system:scheduler-lease', $replacement, 7200);
            Cache::put('system:scheduler-heartbeat', 'replacement-heartbeat', 600);
            return $this->identity();
        });
        $this->assertFalse($this->recover($command, Cache::lock($key, 7200), $key));
        $this->assertTrue($owner->isOwnedByCurrentProcess());
        $this->assertSame($replacement, Cache::get('system:scheduler-lease'));
        $this->assertSame('replacement-heartbeat', Cache::get('system:scheduler-heartbeat'));
        $owner->forceRelease();
    }

    public function test_replacement_lock_token_is_never_force_released_even_before_its_metadata_publication(): void
    {
        $key = 'test:scheduler-identity:token-race';
        $owner = Cache::lock($key, 7200); $owner->get();
        $metadata = $this->metadata($key);
        Cache::put('system:scheduler-lease', $metadata, 7200);
        $replacement = Cache::lock($key, 7200);
        $command = \Mockery::mock(RunHeadlessScheduler::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $command->shouldReceive('localProcessIdentity')->once()->andReturnUsing(function () use ($owner, $replacement): array {
            $owner->release();
            $this->assertTrue($replacement->get());
            return $this->identity();
        });
        $this->assertFalse($this->recover($command, Cache::lock($key, 7200), $key));
        $this->assertTrue($replacement->isOwnedByCurrentProcess());
        $this->assertSame($metadata, Cache::get('system:scheduler-lease'));
        $replacement->release();
    }

    public function test_simultaneous_recovery_owner_keeps_duplicate_recoverer_passive(): void
    {
        $key = 'test:scheduler-identity:recovery-owner';
        $owner = Cache::lock($key, 7200); $owner->get();
        $recovery = Cache::lock($key.':stale-recovery', 15); $recovery->get();
        Cache::put('system:scheduler-lease', $this->metadata($key), 7200);
        $command = \Mockery::mock(RunHeadlessScheduler::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $command->shouldNotReceive('localProcessIdentity');
        $this->assertFalse($this->recover($command, Cache::lock($key, 7200), $key));
        $this->assertTrue($owner->isOwnedByCurrentProcess());
        $recovery->release(); $owner->release();
    }

    private function metadata(string $key): array
    {
        return ['protocol' => 'headless_scheduler_singleton_v1', 'lease_key' => $key,
            'pid' => 11120, 'hostname' => (string) (gethostname() ?: php_uname('n')),
            'started_at' => '2026-10-19T12:30:49Z', 'heartbeat_at' => '2026-10-19T12:48:26Z', 'lease_seconds' => 7200];
    }

    private function identity(): array
    {
        return ['running' => true, 'pid' => 11120, 'created_at' => '2026-10-19T13:01:18Z',
            'executable_path' => 'C:/private/php.exe',
            'command_line' => '"C:/private/php.exe" "'.base_path('artisan').'" queue:work redis --queue=scheduler-ops'];
    }

    private function recover(RunHeadlessScheduler $command, mixed $lease, string $key): bool
    {
        return (new ReflectionMethod(RunHeadlessScheduler::class, 'recoverStaleLocalLease'))->invoke($command, $lease, $key, 20);
    }
}
