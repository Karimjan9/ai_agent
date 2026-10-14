<?php

namespace Tests\Unit;

use App\Services\ReplayLivenessProbeService;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Synthetic transport/schema checks only, not evidence of production idle. */
class ReplayLivenessProbeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ai_service.url' => 'http://synthetic-replay-probe.test',
            'services.internal_api.token' => 'synthetic-probe-token-not-production']);
        Http::preventStrayRequests();
    }

    #[DataProvider('invalidCounters')]
    public function test_malformed_authenticated_counter_stays_unknown_without_healthy_public_fallback(string $field, mixed $value): void
    {
        $payload = ['protocol' => 'synthetic_probe_fixture', 'active_requests' => 0, $field => $value];
        Http::fake([
            '*/api/replay-status' => Http::response(json_encode($payload, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
                200, ['Content-Type' => 'application/json']),
            '*/health' => Http::response(['status' => 'ok', 'replay_liveness' => ['protocol' => 'synthetic_probe_fixture', 'active_requests' => 0]]),
        ]);
        $result = (new ReplayLivenessProbeService)->probe();
        $this->assertSame('unknown', $result['status']);
        $this->assertSame('replay-status', $result['source']);
        $this->assertNull($result['active_requests']);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/health'));
    }

    #[DataProvider('invalidCounters')]
    public function test_malformed_public_health_counter_cannot_claim_idle(string $field, mixed $value): void
    {
        config(['services.internal_api.token' => '']);
        $payload = ['status' => 'ok', 'replay_liveness' => ['protocol' => 'synthetic_probe_fixture', 'active_requests' => 0, $field => $value]];
        Http::fake(['*/health' => Http::response(json_encode($payload, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
            200, ['Content-Type' => 'application/json'])]);
        $result = (new ReplayLivenessProbeService)->probe();
        $this->assertSame('unknown', $result['status']);
        $this->assertSame('health', $result['source']);
        $this->assertNull($result['active_requests']);
        Http::assertSentCount(1);
    }

    public static function invalidCounters(): iterable
    {
        $invalid = ['negative' => -1, 'fractional' => .5, 'float_zero' => 0.0, 'numeric_string' => '0',
            'boolean' => false, 'null' => null, 'collection' => [0]];
        foreach (['active_requests', 'screening_active', 'full_active'] as $field) {
            foreach ($invalid as $name => $value) yield $field.'_'.$name => [$field, $value];
        }
    }

    #[DataProvider('validCounters')]
    public function test_valid_integer_counters_keep_the_existing_idle_active_and_optional_defaults(array $payload, string $status): void
    {
        Http::fake(['*/api/replay-status' => Http::response(['protocol' => 'synthetic_probe_fixture', ...$payload])]);
        $result = (new ReplayLivenessProbeService)->probe();
        $this->assertSame($status, $result['status']);
        $this->assertSame('replay-status', $result['source']);
        $this->assertSame($payload['active_requests'], $result['active_requests']);
        $this->assertSame($payload['screening_active'] ?? 0, $result['screening_active']);
        $this->assertSame($payload['full_active'] ?? 0, $result['full_active']);
        Http::assertSentCount(1);
    }

    public static function validCounters(): array
    {
        return [
            'legacy_idle_optional_absent' => [['active_requests' => 0], 'ok'],
            'legacy_active_optional_absent' => [['active_requests' => 1], 'active'],
            'all_idle' => [['active_requests' => 0, 'screening_active' => 0, 'full_active' => 0], 'ok'],
            'all_active' => [['active_requests' => 3, 'screening_active' => 2, 'full_active' => 1], 'active'],
        ];
    }

    public function test_missing_required_active_counter_and_malformed_protocol_stay_unknown(): void
    {
        foreach ([['protocol' => 'synthetic_probe_fixture'], ['protocol' => [], 'active_requests' => 0],
            ['protocol' => '', 'active_requests' => 0]] as $payload) {
            Http::fake(['*/api/replay-status' => Http::response($payload)]);
            $result = (new ReplayLivenessProbeService)->probe();
            $this->assertSame('unknown', $result['status']); $this->assertSame('replay-status', $result['source']);
            $this->assertNull($result['active_requests']);
        }
    }

    public function test_transport_failure_still_allows_explicit_valid_health_projection(): void
    {
        Http::fake(['*/api/replay-status' => Http::response([], 503),
            '*/health' => Http::response(['status' => 'ok', 'replay_liveness' => ['protocol' => 'synthetic_probe_fixture', 'active_requests' => 0]])]);
        $result = (new ReplayLivenessProbeService)->probe();
        $this->assertSame('ok', $result['status']); $this->assertSame('health', $result['source']);
        $this->assertSame(0, $result['active_requests']); Http::assertSentCount(2);
    }
}
