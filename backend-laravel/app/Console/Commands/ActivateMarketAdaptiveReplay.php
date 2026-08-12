<?php

namespace App\Console\Commands;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\ModelVersion;
use App\Services\LabPopulationService;
use App\Services\LearningProtocolSafetyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ActivateMarketAdaptiveReplay extends Command
{
    protected $signature = 'trading:activate-market-adaptive-replay {symbol?}';

    protected $description = 'Synchronize hybrid laboratories and replace unevaluated legacy generations with replay-compatible populations';

    public function handle(LabPopulationService $populations, LearningProtocolSafetyService $protocolSafety): int
    {
        if ($protocolSafety->generationCreationPaused()) {
            $this->info('Learning protocol paused: market-adaptive replay activation deferred; no legacy rows were superseded.');

            return self::SUCCESS;
        }
        $populations->ensureLaboratories();
        $symbols = $this->argument('symbol') ? [strtoupper($this->argument('symbol'))] : ['XAUUSD', 'EURUSD', 'GBPUSD'];

        foreach ($symbols as $symbol) {
            $lab = AiLaboratory::where('symbol', $symbol)->firstOrFail();
            if ((string) $lab->lifecycle_mode !== 'lighthouse') {
                $this->info("{$symbol}: shadow lab; market-adaptive replay activation skipped.");

                continue;
            }
            $active = $lab->generations()->whereIn('status', LabPopulationService::ACTIVE_GENERATION_STATUSES)->latest('generation')->first();
            if ($active) {
                $this->warn("{$symbol}: G{$active->generation} hali {$active->status}; replay activation yangi generation yaratmaydi.");
                continue;
            }
            $superseded = DB::transaction(function () use ($lab): int {
                $generations = $lab->generations()
                    ->whereIn('status', ['draft', 'queued', 'screening', 'screened', 'full_validation'])
                    ->with('agents.modelVersion')
                    ->lockForUpdate()
                    ->get();

                foreach ($generations as $generation) {
                    $agentIds = $generation->agents->pluck('id');
                    $modelIds = $generation->agents->pluck('model_version_id');
                    $generation->agents->each(fn (LabAgent $agent) => $agent->update([
                        'lifecycle_status' => 'archived',
                        'decision_reason' => 'Superseded before promotion: market-adaptive replay and hybrid protocol activated.',
                    ]));
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
