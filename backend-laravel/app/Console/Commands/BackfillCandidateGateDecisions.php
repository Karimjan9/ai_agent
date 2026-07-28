<?php

namespace App\Console\Commands;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\ModelMarketPerformance;
use App\Services\CandidateGateDecisionService;
use Illuminate\Console\Command;

class BackfillCandidateGateDecisions extends Command
{
    protected $signature = 'trading:backfill-gate-decisions {symbol} {generation?} {--timeframe=H1}';
    protected $description = 'Create screening/rescue and forward gate ledgers from existing replay evidence without changing promotion status';

    public function handle(CandidateGateDecisionService $decisions): int
    {
        $symbol = strtoupper((string) $this->argument('symbol'));
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $lab = AiLaboratory::where('symbol', $symbol)->where('timeframe', $timeframe)->firstOrFail();
        $generation = $this->argument('generation')
            ? $lab->generations()->where('generation', (int) $this->argument('generation'))->firstOrFail()
            : $lab->generations()->latest('generation')->firstOrFail();
        $agents = LabAgent::with('modelVersion')->where('lab_generation_id', $generation->id)->get();
        $screened = 0;
        $forward = 0;
        foreach ($agents as $agent) {
            $screen = data_get($agent->modelVersion?->metadata, 'last_screen_result');
            if (is_array($screen)) {
                $decisions->recordScreening($agent, $screen);
                $screened++;
            }
            $performance = ModelMarketPerformance::where('model_version_id', $agent->model_version_id)
                ->where('symbol', $symbol)->where('timeframe', $timeframe)->first();
            if ($performance && is_array($performance->metrics)) {
                $decisions->recordForward($performance, $performance->metrics);
                $forward++;
            }
        }
        $this->info("{$symbol} G{$generation->generation}: {$screened} screening/rescue, {$forward} forward gate decisions backfilled.");
        return self::SUCCESS;
    }
}
