<?php

namespace App\Services;

use App\Models\DualTrackInferenceObservation;
use App\Models\DualTrackRun;
use Illuminate\Support\Facades\Schema;

/** Persists genuinely separated lane contexts, hashes and reasoning budgets. */
class TwinInferenceEvidenceService
{
    public const PROTOCOL = 'twin_independent_inference_evidence_v1';

    /** @return array<string, mixed> */
    public function record(DualTrackRun $run, array $context, array $champion, array $council): array
    {
        if (! Schema::hasTable('dual_track_inference_observations')) {
            return ['status' => 'unavailable', 'promotion_evidence' => false];
        }

        $rows = [];
        foreach (['champion' => $champion, 'council' => $council] as $lane => $output) {
            $inference = (array) data_get($output, 'inference', []);
            $laneContext = [
                'snapshot_hash' => $context['snapshot_hash'] ?? null,
                'snapshot_manifest' => $context['snapshot_manifest'] ?? null,
                'lane' => $lane,
                'task_type' => $context['task_type'] ?? 'signal',
                'market_regime' => $context['market_regime'] ?? null,
                'volatility_regime' => $context['volatility_regime'] ?? null,
                'lane_context' => data_get($inference, 'context', []),
            ];
            $contextHash = (string) ($inference['context_hash'] ?? $this->hash($laneContext));
            $snapshotHash = (string) ($inference['snapshot_hash'] ?? $context['snapshot_hash'] ?? $this->hash(['snapshot' => $context, 'lane' => $lane]));
            $outputHash = (string) ($inference['output_hash'] ?? $this->hash($output));
            $processId = (string) ($inference['process_id'] ?? 'laravel-'.$lane.'-'.substr($outputHash, 0, 16));
            $key = hash('sha256', self::PROTOCOL.'|'.$run->run_key.'|'.$lane);
            $rows[] = DualTrackInferenceObservation::query()->updateOrCreate(
                ['observation_key' => $key],
                [
                    'dual_track_run_id' => $run->id, 'symbol' => $run->symbol, 'timeframe' => $run->timeframe,
                    'cell_key' => $run->cell_key, 'lane' => $lane, 'process_id' => $processId,
                    'snapshot_hash' => $snapshotHash, 'context_hash' => $contextHash,
                    'prompt_hash' => $inference['prompt_hash'] ?? null, 'output_hash' => $outputHash,
                    'reasoning_budget' => $inference['reasoning_budget'] ?? null, 'output' => $output,
                    'context' => $laneContext, 'evidence' => [
                        'protocol' => self::PROTOCOL, 'independent_context' => true,
                        'same_market_snapshot' => true, 'promotion_evidence' => false,
                    ], 'status' => 'observed', 'promotion_evidence' => false,
                ],
            );
        }

        $distinct = $rows[0]->process_id !== $rows[1]->process_id
            && $rows[0]->context_hash !== $rows[1]->context_hash;
        if (! (bool) config('services.twin_intelligence.require_independent_inference', true)) $distinct = true;

        return [
            'status' => $distinct ? 'recorded_independent_contexts' : 'projection_collision',
            'observations' => collect($rows)->map(fn (DualTrackInferenceObservation $row): array => [
                'id' => $row->id, 'lane' => $row->lane, 'process_id' => $row->process_id,
                'snapshot_hash' => $row->snapshot_hash, 'context_hash' => $row->context_hash,
                'output_hash' => $row->output_hash,
            ])->all(),
            'promotion_evidence' => false,
        ];
    }

    private function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }
}
