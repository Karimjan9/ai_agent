<?php

namespace Tests\Feature;

use App\Console\Commands\RunHeadlessScheduler;
use Illuminate\Support\Facades\Cache;
use ReflectionMethod;
use Tests\TestCase;

class RunHeadlessSchedulerTest extends TestCase
{
    public function test_scheduler_claims_a_minute_once_across_a_process_restart(): void
    {
        $minute = '2099-01-02 03:04';
        $key = 'system:scheduler-tick:'.$minute;
        Cache::forget($key);

        try {
            $claim = new ReflectionMethod(RunHeadlessScheduler::class, 'claimMinute');
            $claim->setAccessible(true);

            $this->assertTrue($claim->invoke(app(RunHeadlessScheduler::class), $minute));
            $this->assertFalse($claim->invoke(app(RunHeadlessScheduler::class), $minute));
            $this->assertTrue($claim->invoke(app(RunHeadlessScheduler::class), '2099-01-02 03:05'));
        } finally {
            Cache::forget($key);
            Cache::forget('system:scheduler-tick:2099-01-02 03:05');
        }
    }
}
