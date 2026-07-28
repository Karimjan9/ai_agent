<?php

namespace App\Console\Commands;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\ModelVersion;
use App\Services\LabPopulationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ActivateMarketAdaptiveReplay extends Command
{
    protected $signature = 'trading:activate-market-adaptive-replay {symbol?}';

    protected $description = 'Synchronize hybrid laboratories and replace unevaluated legacy generations with replay-compatible populations';

    public function handle(LabPopulationService $populations): int
    {
        $populations->ensureLaboratories();
        $symbols = $this->argument('symbol') ? [strtoupper($this->argument('symbol'))] : ['XAUUSD', 'EURUSD', 'GBPUSD'];

        foreach ($symbols as $symbol) {
            $lab = AiLaboratory::where('symbol', $symbol)->firstOrFail();
            $superseded = DB::transaction(function () use ($lab): int {
                $generations = $lab->generations()
                    ->whereIn('status', ['draft', 'queued', 'screening', 'screened', 'full_validation'])
                    ->with('agents.modelVersion')
                    ->lockForUpdate()
                    ->get();

                foreach ($generations as $generation) {
                    $agentIds = $generation->agents->pluck('id');
                    $modelIds = $generation->agents->pluck('model_version_id');
                    LabAgent::whereIn('id', $agentIds)->update([
                        'lifecycle_status' => 'archived',
                        'decision_reason' => 'Superseded before promotion: market-adaptive replay and hybrid protocol activated.',
                    ]);
                    ModelVersion::whereIn('id', $modelIds)->update(['status' => 'archived']);
                    $context = $generation->trigger_context ?? [];
                    $context['superseded_reason'] = 'market_adaptive_replay_activation';
                    $generation->update(['status' => 'archived', 'trigger_context' => $context, 'completed_at' => now()]);
                }

                return $generations->count();
            });

            $generation = $populations->build($symbol, 'protocol_activation', true);
            $this->info("{$symbol}: {$superseded} legacy generation archived; replay generation {$generation?->generation} created.");
        }

        return self::SUCCESS;
    }
}
