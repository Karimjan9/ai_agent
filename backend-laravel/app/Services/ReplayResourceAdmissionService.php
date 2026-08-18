<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Admission contract for the low-resource shadow micro-probe lane.
 *
 * A heavy full replay remains the sole coordinator. This service only admits
 * an explicitly marked, trace-free, small-tail probe when the AI service says
 * the full lane is active and no other screening lane is competing.
 */
class ReplayResourceAdmissionService
{
    public const PROTOCOL = 'resource_aware_shadow_probe_v1';

    /** @return array<string, mixed> */
    public function assess(array $queueSnapshot = []): array
    {
        $fullQueue = (string) config('services.lab_queue.full_validation_queue', 'lab-full-validation');
        $stats = (array) data_get($queueSnapshot, 'stats', []);
        $nonFullQueued = 0;
        foreach ($stats as $queue => $row) {
            if ((string) $queue === $fullQueue) continue;
            $nonFullQueued += (int) data_get($row, 'total', 0);
        }

        $status = $this->replayStatus();
        $queueKnown = data_get($queueSnapshot, 'available', true) !== false;
        $healthy = (bool) data_get($status, 'healthy', false);
        $fullActive = (int) data_get($status, 'full_active', 0);
        $screeningActive = (int) data_get($status, 'screening_active', 0);
        $capacity = max(1, (int) data_get($status, 'screening_capacity', 1));
        $resourceSafe = $queueKnown
            && $healthy
            && $screeningActive === 0
            && ($fullActive > 0 || (int) data_get($status, 'active_requests', 0) === 0)
            && $nonFullQueued === 0;

        return [
            'protocol' => self::PROTOCOL,
            'allowed' => $resourceSafe,
            'healthy' => $healthy,
            'queue_state_known' => $queueKnown,
            'full_active' => $fullActive,
            'screening_active' => $screeningActive,
            'screening_capacity' => $capacity,
            'non_full_queue_total' => $nonFullQueued,
            'queue_backend' => data_get($queueSnapshot, 'backend'),
            'replay_status' => $status,
            'promotion_evidence' => false,
            'rule' => 'Only an explicit trace-free bounded probe may coexist with one full coordinator; ordinary heavy screening remains blocked.',
        ];
    }

    /** @return array<string, mixed> */
    private function replayStatus(): array
    {
        $url = rtrim((string) config('services.ai_service.url'), '/').'/api/replay-status';
        $token = (string) config('services.internal_api.token');
        if ($token === '') return ['healthy' => false, 'reason' => 'internal_api_token_missing'];

        try {
            $response = Http::connectTimeout(2)->timeout(4)
                ->withHeaders(['X-Internal-Token' => $token])
                ->get($url);
            if ($response->failed()) return ['healthy' => false, 'reason' => 'replay_status_http_failed'];
            $body = (array) $response->json();
            $body['healthy'] = (string) data_get($body, 'protocol', '') !== '';

            return $body;
        } catch (\Throwable $exception) {
            return ['healthy' => false, 'reason' => get_class($exception)];
        }
    }
}
