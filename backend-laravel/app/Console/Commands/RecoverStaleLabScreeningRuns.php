<?php

namespace App\Console\Commands;

use App\Models\LabGeneration;
use App\Services\LabImmutableEvidenceService;
use App\Services\OperatorApprovalService;
use App\Services\ReplayLivenessProbeService;
use App\Services\StaleLabScreeningRecoveryService;
use Illuminate\Console\Command;
use RuntimeException;

class RecoverStaleLabScreeningRuns extends Command
{
    protected $signature = 'trading:recover-stale-lab-screening
        {symbol : Laboratory symbol}
        {--timeframe=H1 : Laboratory timeframe}
        {--generation= : Exact generation number; required for a state-changing run}
        {--older-than=30 : Minimum run age in minutes}
        {--dry-run : Inspect without changing evidence or queue state}
        {--apply : Reclaim proven stale runs as technical_error}
        {--approved-by=}
        {--approval-reason=}';

    protected $description = 'Fail-closed recovery for stale screening runs; never creates a strategy verdict';

    public function handle(
        StaleLabScreeningRecoveryService $recovery,
        ReplayLivenessProbeService $liveness,
        OperatorApprovalService $approvals,
    ): int {
        $generationNumber = (int) $this->option('generation');
        if ($generationNumber <= 0) {
            $this->error('--generation exact qiymat bilan majburiy.');
            return self::FAILURE;
        }

        $symbol = strtoupper(trim((string) $this->argument('symbol')));
        $timeframe = strtoupper(trim((string) $this->option('timeframe')));
        $generation = LabGeneration::query()
            ->with('laboratory')
            ->where('generation', $generationNumber)
            ->whereHas('laboratory', fn ($query) => $query
                ->where('symbol', $symbol)
                ->where('timeframe', $timeframe))
            ->first();
        if (! $generation) {
            $this->error("{$symbol} {$timeframe} G{$generationNumber} topilmadi.");
            return self::FAILURE;
        }

        $probe = $liveness->probe();
        if (($probe['status'] ?? 'unknown') !== 'ok') {
            $this->warn('Recovery deferred: replay lane '.($probe['reason'] ?? 'unknown').'.');
            return self::SUCCESS;
        }

        $olderThan = max(30, (int) $this->option('older-than'));
        $apply = (bool) $this->option('apply');
        if ($apply) {
            try {
                $approvals->requireForApply('recover-stale-lab-screening', $this->option('approved-by'), $this->option('approval-reason'), [
                    'symbol' => $symbol,
                    'timeframe' => $timeframe,
                    'generation' => $generationNumber,
                    'older_than_minutes' => $olderThan,
                ]);
            } catch (RuntimeException $exception) {
                $this->error($exception->getMessage());
                return self::FAILURE;
            }
        }

        $result = $apply
            ? $recovery->recover($generation, $olderThan)
            : $recovery->inspect($generation, $olderThan);

        $this->line(json_encode([
            'protocol' => StaleLabScreeningRecoveryService::PROTOCOL,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'generation' => $generationNumber,
            'replay_liveness' => [
                'status' => $probe['status'] ?? null,
                'active_requests' => $probe['active_requests'] ?? null,
                'source' => $probe['source'] ?? null,
            ],
            ...$result,
            'promotion_evidence' => false,
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return ($result['status'] ?? 'applied') === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
