<?php

namespace App\Services;

use App\Models\DualTrackRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Durable boundary between the two lanes and the production runtime.
 * Existing paper execution remains incumbent-owned while mode=shadow.
 */
class DualTrackOrchestratorService
{
    public function __construct(
        private DualTrackDecisionService $decisions,
        private CapabilityCellRouterService $router,
        private DualTrackRiskShieldService $riskShield,
    ) {}

    /** @return array<string, mixed> */
    public function observeSignal(
        array $context,
        array $champion,
        array $council,
        array $evidence = [],
        array $metadata = [],
    ): array {
        $started = microtime(true);
        $context['task_type'] ??= 'signal';
        $cellKey = DualTrackDecisionService::cellKey($context);
        $decision = $this->decisions->evaluate($context, $champion, $council, $evidence);
        $shield = $this->riskShield->assess($context, $champion, $council, $evidence);
        if (! $shield['allowed'] && $decision['selected_decision'] !== 'WAIT') {
            $decision['status'] = 'blocked';
            $decision['selected_decision'] = 'WAIT';
            $decision['reason'] = 'RISK_SHIELD_'.strtoupper((string) ($shield['decision'] ?? 'WAIT'));
        }
        $decision['risk_shield'] = $shield;
        $eventKey = (string) ($context['event_key'] ?? $cellKey.'|'.now()->timestamp);
        $routing = $this->router->decide($context, (array) ($context['transition'] ?? []), $eventKey);
        $inputHash = $this->hash([$context, $champion, $council, $evidence]);
        $outputHash = $this->hash([$decision, $routing]);
        $runKey = (string) ($context['run_key'] ?? hash('sha256', $inputHash.'|'.$eventKey));
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        // A deployment may run the application before the new migration has
        // been applied. Keep the runtime fail-closed and return the same
        // decision projection without pretending that durable evidence exists.
        if (! Schema::hasTable('dual_track_runs')) {
            return [
                ...$decision,
                'run_id' => null,
                'run_key' => $runKey,
                'routing' => $routing,
                'input_hash' => $inputHash,
                'output_hash' => $outputHash,
                'ledger_status' => 'unavailable',
                'promotion_evidence' => false,
            ];
        }

        $row = DB::transaction(function () use ($context, $champion, $council, $evidence, $metadata, $decision, $routing, $cellKey, $inputHash, $outputHash, $runKey, $durationMs): DualTrackRun {
            return DualTrackRun::query()->updateOrCreate(
                ['run_key' => $runKey],
                [
                    'protocol' => DualTrackDecisionService::PROTOCOL,
                    'symbol' => strtoupper((string) ($context['symbol'] ?? 'UNKNOWN')),
                    'timeframe' => strtoupper((string) ($context['timeframe'] ?? 'UNKNOWN')),
                    'task_type' => (string) ($context['task_type'] ?? 'signal'),
                    'cell_key' => $cellKey,
                    'market_regime' => $context['market_regime'] ?? null,
                    'volatility_regime' => $context['volatility_regime'] ?? null,
                    'mode' => (string) config('services.dual_track.mode', 'shadow'),
                    'status' => $decision['status'],
                    'selected_lane' => $decision['selected_lane'],
                    'selected_decision' => $decision['selected_decision'],
                    'champion_decision' => $decision['champion']['decision'],
                    'council_decision' => $decision['council']['decision'],
                    'disagreement_code' => $decision['disagreement_code'],
                    'snapshot_hash' => $context['snapshot_hash'] ?? null,
                    'input_hash' => $inputHash,
                    'output_hash' => $outputHash,
                    'duration_ms' => $durationMs,
                    'scores' => $decision['scores'],
                    'champion_output' => $champion,
                    'council_output' => $council,
                    'evidence' => ['decision' => $decision['hard_gate'], 'risk_shield' => $decision['risk_shield'], ...$evidence, 'promotion_evidence' => false],
                    'routing' => $routing,
                    'metadata' => ['protocol' => DualTrackDecisionService::PROTOCOL, ...$metadata, 'promotion_evidence' => false],
                    'started_at' => now()->subMilliseconds($durationMs),
                    'finished_at' => now(),
                    'promotion_evidence' => false,
                ],
            );
        });

        $this->memoryObservation($row, $context, $decision);

        return [
            ...$decision,
            'run_id' => $row->getKey(),
            'run_key' => $row->run_key,
            'routing' => $routing,
            'input_hash' => $inputHash,
            'output_hash' => $outputHash,
            'promotion_evidence' => false,
        ];
    }

    private function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function memoryObservation(DualTrackRun $run, array $context, array $decision): void
    {
        // The raw observation is intentionally not promoted to a lesson. The
        // settlement service will attach a verified/failure lesson only after
        // an immutable paper outcome exists.
        if (! \Illuminate\Support\Facades\Schema::hasTable('dual_track_memory_lessons')) return;
        $key = hash('sha256', DualTrackMemoryService::PROTOCOL.'|run|'.$run->run_key);
        \App\Models\DualTrackMemoryLesson::query()->updateOrCreate(
            ['lesson_key' => $key],
            [
                'layer' => 'raw', 'status' => 'observed',
                'symbol' => $run->symbol, 'timeframe' => $run->timeframe, 'cell_key' => $run->cell_key,
                'lane' => $decision['selected_lane'], 'statement' => 'Dual-track observation recorded for '.$run->cell_key.'.',
                'lesson' => 'Await immutable outcome before learning or promotion.', 'sample_count' => 1,
                'confidence' => 0, 'source_run_id' => $run->id,
                'evidence' => ['context' => $context, 'promotion_evidence' => false], 'promotion_evidence' => false,
            ],
        );
    }
}
