<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\ScreeningLearningOutbox;

/** Screening facts and gate decisions are durable before optional learning writes run. */
class ScreeningLearningOutboxService
{
    public function enqueue(LabAgent $agent, array $result, float $forwardScore): void
    {
        ScreeningLearningOutbox::updateOrCreate(['lab_agent_id' => $agent->id], [
            'model_version_id' => $agent->model_version_id, 'screen_result' => $result, 'forward_score' => $forwardScore,
            'status' => 'pending', 'available_at' => now(), 'last_error' => null,
        ]);
    }

    public function process(int $limit = 100): int
    {
        $processed = 0;
        ScreeningLearningOutbox::query()->whereIn('status', ['pending', 'retry'])
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->orderBy('id')->limit($limit)->get()->each(function (ScreeningLearningOutbox $outbox) use (&$processed): void {
                $agent = LabAgent::with('modelVersion')->find($outbox->lab_agent_id);
                if (! $agent || ! $agent->modelVersion) { $outbox->update(['status' => 'discarded', 'processed_at' => now()]); return; }
                try {
                    app(ScreeningLearningService::class)->record($agent, $agent->modelVersion, $outbox->screen_result, (float) $outbox->forward_score);
                    $outbox->update(['status' => 'completed', 'attempts' => $outbox->attempts + 1, 'processed_at' => now(), 'last_error' => null]);
                    $processed++;
                } catch (\Throwable $exception) {
                    $attempts = $outbox->attempts + 1;
                    $outbox->update(['status' => 'retry', 'attempts' => $attempts, 'last_error' => substr($exception->getMessage(), 0, 1000),
                        'available_at' => now()->addMinutes(min(60, max(1, $attempts * 2)))]);
                }
            });
        return $processed;
    }
}
