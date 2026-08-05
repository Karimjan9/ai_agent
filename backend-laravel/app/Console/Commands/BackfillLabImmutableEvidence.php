<?php

namespace App\Console\Commands;

use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\CandidateHandoffEvent;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\MutationMemory;
use App\Services\LabImmutableEvidenceService;
use Illuminate\Console\Command;

class BackfillLabImmutableEvidence extends Command
{
    protected $signature = 'trading:lab-backfill-immutable-evidence {symbol?} {--timeframe=} {--generation=} {--all}';
    protected $description = 'Bridge old laboratory snapshots into the immutable ledger and label their limits honestly';

    public function handle(LabImmutableEvidenceService $ledger): int
    {
        if (! $this->option('all') && ! $this->argument('symbol') && ! $this->option('generation')) {
            $this->error('Scope belgilang: symbol, --generation yoki --all.');
            return self::INVALID;
        }
        $query = LabGeneration::query()->with('laboratory', 'agents.modelVersion');
        if ($symbol = $this->argument('symbol')) $query->whereHas('laboratory', fn ($q) => $q->where('symbol', strtoupper((string) $symbol)));
        if ($timeframe = $this->option('timeframe')) $query->whereHas('laboratory', fn ($q) => $q->where('timeframe', strtoupper((string) $timeframe)));
        if ($generation = $this->option('generation')) $query->where('generation', (int) $generation);
        $generations = $query->orderBy('id')->get();
        $agentsBackfilled = 0; $projectionsBackfilled = 0;

        foreach ($generations as $generation) {
            foreach ($generation->agents as $agent) {
                $latestSnapshot = LabEvaluationRun::query()->where('lab_agent_id', $agent->id)
                    ->where('phase', 'legacy_backfill')->latest('id')->first();
                if ($latestSnapshot && data_get($latestSnapshot->metadata, 'snapshot_hash') === $ledger->legacySnapshotHash($agent)) continue;
                $run = $ledger->backfillLegacySnapshot($agent);
                $agentsBackfilled++;

                $performanceIds = $agent->model_version_id ? \App\Models\ModelMarketPerformance::query()->where('model_version_id', $agent->model_version_id)->pluck('id') : collect();
                $decisions = CandidateGateDecision::query()->where(function ($q) use ($agent, $performanceIds): void {
                    $q->where('lab_agent_id', $agent->id);
                    if ($performanceIds->isNotEmpty()) $q->orWhereIn('model_market_performance_id', $performanceIds->all());
                })->get();
                foreach ($decisions as $decision) {
                    $ledger->recordGateDecision($decision, ['source' => 'legacy_snapshot_backfill', 'completeness' => 'snapshot_only'], $run->run_id);
                    $projectionsBackfilled++;
                }
                foreach (CandidateHandoffEvent::query()->where('lab_generation_id', $generation->id)->where('lab_agent_id', $agent->id)->get() as $handoff) {
                    $ledger->recordHandoff($generation, $agent, $handoff->stage, $handoff->status, $handoff->terminal_reason, [
                        ...(array) $handoff->payload, 'source' => 'legacy_snapshot_backfill', 'completeness' => 'snapshot_only',
                    ]);
                    $projectionsBackfilled++;
                }
                foreach (MutationMemory::query()->where('lab_agent_id', $agent->id)->get() as $memory) {
                    $ledger->recordMutationCredit($memory, [
                        'source' => 'legacy_snapshot_backfill', 'completeness' => 'snapshot_only',
                    ], $run->run_id);
                    $projectionsBackfilled++;
                }
            }
            foreach (CandidateHandoffEvent::query()->where('lab_generation_id', $generation->id)->whereNull('lab_agent_id')->get() as $handoff) {
                $ledger->recordHandoff($generation, null, $handoff->stage, $handoff->status, $handoff->terminal_reason, [
                    ...(array) $handoff->payload, 'source' => 'legacy_snapshot_backfill', 'completeness' => 'snapshot_only',
                    'generation_id' => $generation->id,
                ]);
                $projectionsBackfilled++;
            }
            $this->info("{$generation->laboratory?->symbol} G{$generation->generation}: snapshot bridge yozildi.");
        }
        $this->info("Backfill tugadi: {$agentsBackfilled} agent snapshot, {$projectionsBackfilled} projection event.");
        $this->warn('Legacy yozuvlar exact retry history emas; ular snapshot_only deb belgilandi va promotion evidence sifatida ishlatilmaydi.');
        return self::SUCCESS;
    }
}
