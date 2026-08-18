<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RuntimeMonitorTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_monitor_persists_a_safe_testing_snapshot(): void
    {
        $this->artisan('system:runtime-monitor', [
            '--json' => true,
            '--persist' => true,
        ])
            ->expectsOutputToContain('"protocol":"runtime_monitor_v1"')
            ->assertExitCode(0);

        $this->assertDatabaseHas('service_health_checks', [
            'service_key' => 'runtime:redis',
            'status' => 'ok',
        ]);
        $this->assertDatabaseHas('service_health_checks', [
            'service_key' => 'runtime:queue',
            'status' => 'ok',
        ]);
    }
}
