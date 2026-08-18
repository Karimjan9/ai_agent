<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Read-only replay-lane liveness probe.
 *
 * The authenticated replay-status endpoint remains authoritative when the
 * Laravel runtime has the internal token. A narrow local /health projection
 * is the fallback for CLI monitors when protected secret files are not
 * readable in a managed shell. It carries counters and protocol only.
 */
class ReplayLivenessProbeService
{
    public const PROTOCOL = 'replay_liveness_probe_v1';

    /** @return array<string, mixed> */
    public function probe(): array
    {
        $base = rtrim((string) config('services.ai_service.url', 'http://127.0.0.1:9000'), '/');
        $token = (string) config('services.internal_api.token');

        if ($token !== '') {
            try {
                $response = Http::connectTimeout(2)->timeout(5)->acceptJson()
                    ->withHeaders(['X-Internal-Token' => $token])
                    ->get($base.'/api/replay-status');
                $parsed = $this->parse($response->successful() ? (array) $response->json() : [], 'replay-status');
                if ($parsed['status'] !== 'unknown') return $parsed;
            } catch (\Throwable) {
                // Fall through to the narrow local health projection.
            }
        }

        try {
            $response = Http::connectTimeout(2)->timeout(5)->acceptJson()->get($base.'/health');
            return $this->parse(
                $response->successful() ? (array) $response->json('replay_liveness', []) : [],
                'health',
            ) + [
                'health_status' => $response->successful() ? (string) $response->json('status', '') : null,
            ];
        } catch (\Throwable $exception) {
            return [
                'protocol' => self::PROTOCOL,
                'status' => 'unknown',
                'reason' => 'health_exception',
                'active_requests' => null,
                'source' => 'none',
                'exception' => get_class($exception),
            ];
        }
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function parse(array $payload, string $source): array
    {
        $active = $payload['active_requests'] ?? null;
        $protocol = trim((string) ($payload['protocol'] ?? ''));
        if (! is_numeric($active) || $protocol === '') {
            return [
                'protocol' => self::PROTOCOL,
                'status' => 'unknown',
                'reason' => $source === 'health' ? 'health_schema_invalid' : 'replay_status_unavailable',
                'active_requests' => null,
                'source' => $source,
            ];
        }

        $active = max(0, (int) $active);

        return [
            'protocol' => self::PROTOCOL,
            'status' => $active === 0 ? 'ok' : 'active',
            'reason' => $active === 0 ? 'idle' : 'replay_in_progress',
            'active_requests' => $active,
            'screening_active' => (int) ($payload['screening_active'] ?? 0),
            'screening_capacity' => (int) ($payload['screening_capacity'] ?? 0),
            'full_active' => (int) ($payload['full_active'] ?? 0),
            'last_replay_started_at' => $payload['last_replay_started_at'] ?? null,
            'last_replay_finished_at' => $payload['last_replay_finished_at'] ?? null,
            'last_replay_termination' => $payload['last_replay_termination'] ?? null,
            'last_replay_stage_timings_ms' => (array) ($payload['last_replay_stage_timings_ms'] ?? []),
            'last_replay_checkpoint' => (array) ($payload['last_replay_checkpoint'] ?? []),
            'source' => $source,
            'service_protocol' => $protocol,
        ];
    }
}
