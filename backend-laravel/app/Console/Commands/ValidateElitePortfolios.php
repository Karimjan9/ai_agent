<?php

namespace App\Console\Commands;

use App\Models\ModelMarketPerformance;
use App\Models\AiLaboratory;
use App\Services\CalendarAlignmentEvidenceService;
use App\Services\EliteAgentPortfolioGateService;
use App\Services\ExecutionContractService;
use App\Services\LabDatasetExportService;
use App\Services\LearningProtocolSafetyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ValidateElitePortfolios extends Command
{
    protected $signature = 'trading:validate-elite-portfolios {symbol?} {--timeframe=H1}';

    protected $description = 'Replay complementary forward-valid specialists as one sealed portfolio';

    public function handle(EliteAgentPortfolioGateService $portfolios, LabDatasetExportService $datasets, CalendarAlignmentEvidenceService $calendarAlignment, LearningProtocolSafetyService $protocolSafety): int
    {
        if ($protocolSafety->generationCreationPaused()) {
            $this->info('Learning protocol paused: elite portfolio replay deferred.');

            return self::SUCCESS;
        }
        $symbols = $this->argument('symbol') ? [strtoupper($this->argument('symbol'))] : ['XAUUSD', 'EURUSD', 'GBPUSD'];
        $timeframe = strtoupper((string) $this->option('timeframe'));

        // The Python evaluator is a single heavy replay lane.  A combined
        // portfolio replay is just as expensive as a full candidate replay,
        // so it must never start while member recovery/full-validation work
        // is still reserved.  Otherwise the two requests compete for the
        // same process and a valid member can be misclassified as a timeout.
        if ($this->fullReplayLaneActive()) {
            $this->warn('lab-full-validation band: member/recovery replay faol; combined portfolio replay keyinga qoldirildi.');
            return self::SUCCESS;
        }

        foreach ($symbols as $symbol) {
            $lab = AiLaboratory::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->first();
            if (! $lab || (string) $lab->lifecycle_mode !== 'lighthouse') {
                $this->info("{$symbol} {$timeframe}: shadow lab; elite portfolio replay skipped.");

                continue;
            }
            $allCandidates = ModelMarketPerformance::with('modelVersion')
                ->where('symbol', $symbol)->where('timeframe', $timeframe)
                ->where('evidence_status', 'valid')
                ->whereHas('modelVersion', fn ($query) => $query->where('evidence_status', 'valid'))
                ->whereIn('status', ['forward_validated', 'paper', 'challenger', 'stagnated', 'rejected'])
                ->where('paper_status', '!=', 'failed')->get();
            $strictCandidates = $allCandidates->filter(fn (ModelMarketPerformance $candidate): bool =>
                in_array($candidate->status, ['forward_validated', 'paper'], true));
            if ($allCandidates->isEmpty()) {
                $this->info("{$symbol}: portfolio waiting — individual forward-valid member yo'q.");
                continue;
            }
            $state = $strictCandidates->isNotEmpty()
                ? $portfolios->syncMarket($symbol, $timeframe, $strictCandidates)
                : ['status' => 'waiting_for_portfolio_member_replay', 'portfolio' => null, 'members' => []];
            $portfolio = $state['portfolio'] ?? null;
            if (! $portfolio || count($state['members'] ?? []) < 2) {
                // Full-replayed niche members can enter this research lane,
                // but the combined replay still uses the unchanged global
                // promotion gates.
                $state = $portfolios->syncResearchMarket($symbol, $timeframe, $allCandidates);
                $portfolio = $state['portfolio'] ?? null;
                if (! $portfolio || count($state['members'] ?? []) < 2) {
                    $this->info("{$symbol}: {$state['status']}.");
                    continue;
                }
            }
            if ($portfolio->gate_status === 'passed') {
                $this->info("{$symbol}: portfolio already passed; no duplicate replay.");
                continue;
            }

            // Manual and scheduled invocations share this lock. Without it a
            // long canonical replay could overlap with the hourly scheduler
            // and make the same portfolio appear to have two contradictory
            // evidence records.
            $lock = Cache::lock("elite-portfolio-replay:{$symbol}:{$timeframe}", 4500);
            if (! $lock->get()) {
                $this->warn("{$symbol}: another sealed portfolio replay is active; this run was skipped.");
                continue;
            }

            // Match EvaluateLabAgentJob's actual WithoutOverlapping key. The
            // database preflight above is only a hint and has a race with a
            // worker reservation; this shared cache lock is the authority for
            // direct/scheduled portfolio replays.
            $laneLock = Cache::lock(
                'laravel-queue-overlap:'.(string) config('services.lab_queue.replay_mutex_key', 'neurotrader-ai-heavy-replay'),
                4500,
            );
            if (! $laneLock->get()) {
                $this->warn("{$symbol}: shared AI replay lane is active; combined portfolio replay keyinga qoldirildi.");
                $lock->release();
                continue;
            }

            try {
            $dataset = $datasets->export($symbol, $timeframe);
            $foundation = $datasets->ensureFoundationDataset($symbol, $timeframe);
            $timeout = min(3900, max(60, (int) config('services.lab_selection.portfolio_replay_timeout_seconds', 3900)));
            $requestId = 'portfolio-'.$symbol.'-'.strtolower($timeframe).'-'.bin2hex(random_bytes(6));
            if (! $this->replayLaneReady($requestId)) {
                $this->warn("{$symbol}: AI replay lane band; combined portfolio replay keyinga qoldirildi.");
                continue;
            }
            $response = Http::connectTimeout(15)->timeout($timeout)->withOptions([
                    'connect_timeout' => 15,
                    'timeout' => $timeout,
                    'curl' => [
                        CURLOPT_CONNECTTIMEOUT => 15,
                        CURLOPT_CONNECTTIMEOUT_MS => 15000,
                        CURLOPT_TIMEOUT => $timeout,
                        CURLOPT_TIMEOUT_MS => $timeout * 1000,
                    ],
                ])->acceptJson()
                ->withHeaders([
                    'X-Internal-Token' => (string) config('services.internal_api.token'),
                    'X-Lab-Request-Id' => $requestId,
                ])
                ->post(rtrim(config('services.ai_service.url'), '/').'/api/portfolio/backtest', [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                    'strategy' => 'portfolio_v1',
                    'base_strategy' => 'portfolio',
                    'version' => 'portfolio-v1',
                    'evaluation_mode' => 'replay',
                    'parameters' => $portfolios->portfolioParameters(),
                    'portfolio_members' => $portfolios->memberSpecs($portfolio->fresh(['members.performance.modelVersion'])),
                    'policy_context' => [
                        'portfolio_selection_context' => $portfolios->selectionContext($allCandidates),
                    ],
                    'initial_balance' => 10000,
                    'risk_per_trade' => 1,
                    'dataset_path' => $dataset,
                    'foundation_dataset_path' => $foundation['path'],
                    'execution' => $this->executionAssumptions($symbol),
                    'execution_contract' => app(ExecutionContractService::class)->for($symbol, $timeframe),
                ]);
            if ($response->failed()) {
                $this->warn("{$symbol}: portfolio replay transport failed ({$response->status()}); no gate status changed.");
                continue;
            }
            $combinedResult = (array) $response->json();
            // The Python portfolio endpoint has no Laravel model owner, so
            // attach the exact sidecar used for this replay before recording
            // evidence. Missing provenance must remain a failure, never a
            // silent passport pass.
            $manifestPath = $dataset.'.manifest.json';
            if (is_file($manifestPath)) {
                $manifest = json_decode((string) file_get_contents($manifestPath), true);
                if (is_array($manifest)) $combinedResult['data_manifest'] = $manifest;
            }
            $combinedResult = $calendarAlignment->enrich($symbol, $timeframe, $combinedResult);
            $gate = $portfolios->recordCombinedEvidence($portfolio->fresh(), $combinedResult);
            $this->info("{$symbol}: portfolio {$gate['status']} — ".implode(',', $gate['reason_codes'] ?? []));
            } finally {
                $laneLock->release();
                $lock->release();
            }
        }
        return self::SUCCESS;
    }

    private function fullReplayLaneActive(): bool
    {
        if (DB::table('jobs')->where('queue', 'lab-full-validation')->exists()) {
            return true;
        }

        return DB::table('job_batches')
            ->whereNull('finished_at')
            ->where(function ($query): void {
                $query->whereIn('name', [
                    'Portfolio member full validation',
                    'Global full validation',
                    'Bounded full evaluator recovery',
                ])->orWhere('name', 'like', 'Bounded lab evaluator recovery%');
            })
            ->exists();
    }

    /** Operational containment only; no portfolio gate or evidence is changed. */
    private function replayLaneReady(string $requestId): bool
    {
        try {
            $response = Http::connectTimeout(3)->timeout(5)->withOptions([
                'connect_timeout' => 3,
                'timeout' => 5,
                'curl' => [
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_CONNECTTIMEOUT_MS => 3000,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_TIMEOUT_MS => 5000,
                ],
            ])->acceptJson()->withHeaders([
                'X-Internal-Token' => (string) config('services.internal_api.token'),
                'X-Lab-Request-Id' => $requestId.'-preflight',
            ])->get(rtrim(config('services.ai_service.url'), '/').'/api/replay-status');
        } catch (\Throwable) {
            return false;
        }

        if ($response->failed()) return false;
        $status = $response->json();
        return is_array($status)
            && data_get($status, 'protocol') === 'replay_liveness_v2_bounded_worker'
            && (int) data_get($status, 'active_requests', 0) === 0;
    }

    private function executionAssumptions(string $symbol): array
    {
        return app(ExecutionContractService::class)->parameters($symbol);
    }
}
