<?php

namespace App\Console\Commands;

use App\Models\AiLaboratory;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/** Re-run a completed screen under a newer evidence contract without promoting it. */
class RescreenLabGeneration extends Command
{
    protected $signature = 'trading:rescreen-lab-generation {symbol} {generation} {--timeframe=H1}';
    protected $description = 'Return a screened, non-dispatched generation to draft for a newer screening evidence contract';

    public function handle(): int
    {
        $symbol = strtoupper((string) $this->argument('symbol'));
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $generation = AiLaboratory::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->firstOrFail()
            ->generations()->with('agents.modelVersion')->where('generation', (int) $this->argument('generation'))->firstOrFail();
        if (! in_array($generation->status, ['screened', 'screening', 'draft'], true)
            || $generation->agents->contains(fn ($agent) => in_array($agent->lifecycle_status, ['full_queued', 'training', 'challenger', 'forward_validated', 'paper', 'champion'], true))) {
            $this->error('Only a screened generation with no full-replay or promotion state can be re-screened.');
            return self::FAILURE;
        }

        DB::transaction(function () use ($generation): void {
            foreach ($generation->agents as $agent) {
                $model = $agent->modelVersion;
                if ($model) {
                    $metadata = $model->metadata ?? [];
                    $history = (array) data_get($metadata, 'screening_history', []);
                    if ($last = data_get($metadata, 'last_screen_result')) {
                        $history[] = ['superseded_at' => now()->utc()->toIso8601String(), 'reason' => 'SCREENING_CONTRACT_UPGRADE_TO_TWO_TIER_V2', 'result' => $last];
                    }
                    Arr::forget($metadata, 'last_screen_result');
                    data_set($metadata, 'screening_history', $history);
                    data_set($metadata, 'screening_contract_required', 'two_tier_v2');
                    $model->update(['metadata' => $metadata]);
                }
                $agent->update(['lifecycle_status' => 'draft', 'sample_count' => 0, 'profit_factor' => null,
                    'max_drawdown' => null, 'risk_of_ruin' => null, 'decision_reason' => 'Prior short-screen superseded; awaiting 2k opportunity + 5k survival screening.']);
            }
            $generation->update(['status' => 'draft', 'completed_at' => null,
                'trigger_context' => [...($generation->trigger_context ?? []), 'screening_contract_required' => 'two_tier_v2']]);
        });
        $this->info("{$symbol} G{$generation->generation}: returned to draft for two-tier re-screening; no full replay was dispatched.");
        return self::SUCCESS;
    }
}
