<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\AiLaboratory;
use App\Models\EliteAgentPortfolioMember;
use App\Models\LabAgent;
use App\Models\ModelMarketPerformance;
use App\Services\CandidateGateDecisionService;
use App\Services\CandidateHandoffService;
use App\Services\LabCandidateSelectionService;
use App\Services\LearningProtocolSafetyService;
use App\Services\LabQueueJobInspector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * Dispatches only the explicitly sealed portfolio-member lane from a
 * completed/screened generation. It never changes a standalone gate result.
 */
class DispatchPortfolioMemberReplay extends Command
{
    protected $signature = 'trading:dispatch-portfolio-member-replay {symbol?} {--timeframe=H1} {--refresh : Re-run sealed research members from the latest laboratory generation} {--refresh-all : Re-run all historical sealed portfolio members with the current evidence contract}';

    protected $description = 'Queue strict full replay for complementary niche members; never direct paper promotion';

    public function handle(
        LabCandidateSelectionService $selection,
        CandidateGateDecisionService $decisions,
        CandidateHandoffService $handoffs,
        LearningProtocolSafetyService $protocolSafety,
        LabQueueJobInspector $queueState,
    ): int {
        if ($protocolSafety->generationCreationPaused()) {
            $this->info('Learning protocol paused: portfolio full replay dispatch deferred.');

            return self::SUCCESS;
        }
        $symbols = $this->argument('symbol') ? [strtoupper($this->argument('symbol'))] : ['XAUUSD', 'EURUSD', 'GBPUSD'];
        $timeframe = strtoupper((string) $this->option('timeframe'));

        $queueSnapshot = $queueState->queueSnapshot(['lab-full-validation']);
        if (($queueSnapshot['available'] ?? true) === false) {
            $this->warn('Full-validation queue state unavailable; portfolio replay deferred fail-closed.');

            return self::SUCCESS;
        }
        $activeReplay = (int) ($queueSnapshot['total'] ?? 0)
            + (int) DB::table('job_batches')->where('name', 'Portfolio member full validation')->whereNull('finished_at')->count();
        if ($activeReplay > 0) {
            $this->warn('lab-full-validation band: mavjud replay tugamaguncha yangi portfolio replay dispatch qilinmadi.');

            return self::SUCCESS;
        }

        foreach ($symbols as $symbol) {
            $lab = AiLaboratory::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->first();
            if (! $lab || (string) $lab->lifecycle_mode !== 'lighthouse') {
                $this->info("{$symbol} {$timeframe}: shadow lab; portfolio replay skipped.");

                continue;
            }
            $generation = $lab?->generations()->with('agents.modelVersion')->latest('generation')->first();
            // Portfolio-member research is a second lane. It must never
            // change a freshly screened generation to full_validation before
            // the primary targeted full selector has consumed its scarce
            // council seats. Wait for the standalone full wave to finish.
            if (! $generation || $generation->status !== 'completed') {
                $this->info("{$symbol}: portfolio replay uchun generation tayyor emas.");

                continue;
            }

            $refreshAll = (bool) $this->option('refresh-all');
            if ($refreshAll) {
                // Existing portfolio rows may predate the exact
                // regime x volatility ledger. Replaying those sealed
                // members is evidence repair, not a promotion exception.
                // Keep the historical cohort first so all legacy members
                // receive the new canonical contract before adding a fresh
                // generation seed.
                $members = $this->historicalSealedMembers($symbol, $timeframe)
                    ->merge($this->refreshMembers($generation->agents))
                    ->unique(fn (LabAgent $agent): int => (int) $agent->model_version_id)
                    ->take(max(3, (int) config('services.lab_selection.parent_max_runtime', 8)))
                    ->values();
            } else {
                $screened = $this->refreshMembers($generation->agents)
                    ->when(! $this->option('refresh'), fn ($agents) => $agents->where('lifecycle_status', 'screened'))
                    ->values();
                $members = $selection->selectPortfolioMembers(
                    $screened,
                    max(3, (int) config('services.lab_selection.parent_max_runtime', 8)),
                );
            }
            if ($members->isEmpty()) {
                $this->info("{$symbol}: portfolio-member seed topilmadi.");

                continue;
            }

            $jobs = [];
            $queuedAgents = collect();
            foreach ($members as $rank => $agent) {
                $refreshMode = (bool) $this->option('refresh') || $refreshAll;
                if (! $refreshMode && $agent->lifecycle_status !== 'screened') {
                    continue;
                }
                if ($refreshMode && ! in_array($agent->lifecycle_status, ['challenger', 'stagnated', 'rejected'], true)) {
                    continue;
                }
                if ($refreshMode && $agent->modelVersion) {
                    $metadata = (array) $agent->modelVersion->metadata;
                    // The prior cohort result may have been produced against
                    // an older dataset snapshot. Refresh must force a new
                    // canonical replay rather than silently reusing it.
                    unset($metadata['full_validation_batch']);
                    $agent->modelVersion->update(['metadata' => $metadata]);
                }
                $decision = $decisions->recordFullReplaySelection($agent, true, 'PORTFOLIO_MEMBER_REPLAY');
                $agent->update([
                    'lifecycle_status' => 'full_queued',
                    'decision_reason' => 'Sealed complementary portfolio member #'.($rank + 1).'; standalone promotion remains blocked.',
                ]);
                $agent->generation()->update(['status' => 'full_validation', 'completed_at' => null]);
                $handoffs->record($agent->generation, $agent, 'portfolio_member_replay_queued', 'completed', null, [
                    'selection_decision_id' => $decision->id,
                    'selection_lane' => 'portfolio_member',
                    'replay_purpose' => 'portfolio_member_validation',
                ]);
                $jobs[] = new EvaluateLabAgentJob($agent->id, $symbol, 'full');
                $queuedAgents->push($agent);
            }

            if ($jobs === []) {
                continue;
            }
            foreach ($queuedAgents->groupBy('lab_generation_id') as $generationAgents) {
                $generationAgents->first()->generation()->update(['status' => 'full_validation', 'completed_at' => null]);
            }
            $batch = Bus::batch($jobs)->name('Portfolio member full validation')->onConnection((string) config('queue.default', 'redis'))->onQueue('lab-full-validation')->dispatch();
            $this->info("{$symbol} G{$generation->generation}: portfolio member batch {$batch->id}; ".count($jobs).' replay queued.');
        }

        return self::SUCCESS;
    }

    /**
     * Return members already sealed into the current portfolio, including
     * members from older generations whose replay predates the exact niche
     * ledger. Their status remains research-only throughout the refresh.
     */
    private function historicalSealedMembers(string $symbol, string $timeframe)
    {
        $performanceIds = EliteAgentPortfolioMember::query()
            ->whereHas('portfolio', fn ($query) => $query->where('symbol', $symbol)->where('timeframe', $timeframe))
            ->pluck('model_market_performance_id');
        if ($performanceIds->isEmpty()) {
            return collect();
        }

        $modelIds = ModelMarketPerformance::query()
            ->whereIn('id', $performanceIds)
            ->where('symbol', $symbol)
            ->where('timeframe', $timeframe)
            ->pluck('model_version_id');
        if ($modelIds->isEmpty()) {
            return collect();
        }

        return LabAgent::query()
            ->with(['modelVersion', 'generation'])
            ->where('symbol', $symbol)
            ->where('timeframe', $timeframe)
            ->whereIn('model_version_id', $modelIds)
            ->whereIn('lifecycle_status', ['challenger', 'stagnated', 'rejected'])
            ->get();
    }

    private function refreshMembers($agents)
    {
        if (! $this->option('refresh')) {
            return $agents;
        }

        // Refresh is deliberately explicit and limited to non-overfit
        // research candidates carrying the sealed portfolio contract. It
        // never reopens an overfit member or creates standalone evidence.
        return $agents->filter(fn (LabAgent $agent): bool => in_array($agent->lifecycle_status, ['challenger', 'stagnated', 'rejected'], true)
            && data_get($agent->modelVersion?->metadata, 'portfolio_research_contract.protocol') === 'portfolio_member_research_v1'
        )->values();
    }
}
