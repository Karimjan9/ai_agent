<?php

namespace App\Console\Commands;

use App\Models\AiLaboratory;
use App\Models\LabLearningLanePair;
use App\Services\ChampionCouncilMonitorService;
use App\Services\ChampionCouncilTransitionService;
use App\Services\ChampionIncumbentBaselineService;
use App\Services\CouncilDisagreementService;
use App\Services\FailureDojoService;
use App\Services\G62CausalContractService;
use App\Services\LearningVelocityGateService;
use Illuminate\Console\Command;

class MonitorChampionReadiness extends Command
{
    protected $signature = 'trading:monitor-champion-readiness {symbol?} {--timeframe=H1} {--source-generation=62} {--json}';
    protected $description = 'Fast, read-only Champion Council readiness and evidence bottleneck monitor';

    public function handle(
        FailureDojoService $dojo,
        CouncilDisagreementService $disagreements,
        LearningVelocityGateService $velocity,
        ChampionIncumbentBaselineService $baseline,
        ChampionCouncilMonitorService $council,
        ChampionCouncilTransitionService $transition,
        G62CausalContractService $g62,
    ): int {
        $symbol = strtoupper((string) ($this->argument('symbol') ?: 'XAUUSD'));
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $lab = AiLaboratory::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->first();
        $generation = $lab?->generations()->where('generation', (int) $this->option('source-generation'))->first();
        $pairQuery = LabLearningLanePair::query()->where('symbol', $symbol)->where('timeframe', $timeframe);
        $pairCounts = [
            'total' => (clone $pairQuery)->count(),
            'missing_control' => (clone $pairQuery)->where('status', 'missing_control')->count(),
            'paired' => (clone $pairQuery)->where('status', '!=', 'missing_control')->count(),
        ];
        $baselineState = $baseline->current($symbol, $timeframe);
        $result = [
            'protocol' => 'champion_council_readiness_monitor_v1',
            'scope' => [$symbol, $timeframe],
            'g62_causal_contract' => $generation ? $g62->audit($generation) : ['status' => 'missing_generation'],
            'evidence' => [
                'pairings' => $pairCounts,
                'failure_dojo' => $dojo->summary($symbol, $timeframe),
                'council_disagreements' => $disagreements->progress($symbol, $timeframe),
                'learning_velocity' => $velocity->summary($symbol, $timeframe),
            ],
            'incumbent_baseline' => $baselineState,
            'council' => $council->report($symbol, $timeframe),
            'transition_policy' => $transition->policy(),
            'activation' => [
                'status' => 'incumbent_protected',
                'live_council_allowed' => false,
                'fallback' => 'incumbent_champion',
                'reason' => 'transition evidence is not complete; no automatic live activation',
            ],
            'promotion_evidence' => false,
        ];
        if ($this->option('json')) $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        else $this->info('Council readiness: incumbent_protected; live activation=false');
        return self::SUCCESS;
    }
}
