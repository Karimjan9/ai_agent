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
}
