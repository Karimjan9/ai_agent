<?php

namespace Tests\Feature;

use App\Services\ReleaseSealService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReleaseSealTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_release_manifest_fails_closed(): void
    {
        config(['services.release_seal.manifest_path' => storage_path('framework/testing/missing-release-seal.json')]);
        @unlink((string) config('services.release_seal.manifest_path'));

        $result = app(ReleaseSealService::class)->verify();

        $this->assertSame('blocked', $result['status']);
        $this->assertSame(['RELEASE_MANIFEST_MISSING'], $result['reason_codes']);
        $this->artisan('trading:release-seal', ['action' => 'verify'])->assertExitCode(1);
    }

    public function test_release_scope_attests_the_python_replay_runtime(): void
    {
        $snapshot = app(ReleaseSealService::class)->snapshot();

        $this->assertContains('../ai-service-python/app', $snapshot['release_scope']);
        $this->assertContains('../ai-service-python/requirements.txt', $snapshot['release_scope']);
        $this->assertNotSame('', $snapshot['source_checksum']);
    }
}
