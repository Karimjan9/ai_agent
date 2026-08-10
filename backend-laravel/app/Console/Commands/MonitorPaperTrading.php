<?php

namespace App\Console\Commands;

use App\Models\CandidateGateDecision;
use App\Models\ModelMarketPerformance;
use App\Services\PaperTradingExecutionService;
use Illuminate\Console\Command;

class MonitorPaperTrading extends Command
{
    protected $signature = 'trading:paper-monitor';

    protected $description = 'Open, reconcile, and score paper orders for forward-validated laboratory challengers';

    public function handle(PaperTradingExecutionService $execution): int
    {
        $stats = $execution->run();

        $this->info("Paper execution ({$stats['mode']}, {$stats['broker']}): {$stats['candidates']} candidates, {$stats['opened']} opened, {$stats['closed']} closed.");
        if ((int) ($stats['candidates'] ?? 0) === 0) {
            $this->explainNoPaperCandidate();
        }

        return self::SUCCESS;
    }

    /**
     * A zero-candidate monitor run is a valid safety state, but it must not
     * look like a silent worker failure. This is read-only observability and
     * never changes forward, paper, or portfolio gates.
     */
    private function explainNoPaperCandidate(): void
    {
        $performances = ModelMarketPerformance::query()
            ->where('evidence_status', 'valid')
            ->get();
        $forwardEligible = $performances->whereIn('status', ['forward_validated', 'paper'])
            ->where('paper_status', '!=', 'failed');

        if ($forwardEligible->isNotEmpty()) {
            $councilMembers = $forwardEligible->filter(fn (ModelMarketPerformance $candidate): bool =>
                data_get($candidate->modelVersion?->metadata, 'council_specialist_contract.protocol') === 'agent_council_v1'
                || data_get($candidate->modelVersion?->metadata, 'portfolio_council_lane.protocol') === 'portfolio_council_v1'
            );
            if ($councilMembers->count() === $forwardEligible->count()) {
                $this->warn('Paper blocked: council member passports exist, but the passed combined council proxy is not ready; individual members cannot paper-trade.');
                $this->line('Required order: >=2 distinct specialist passports -> transition/risk router passport -> combined replay -> portfolio passport.');
                return;
            }
            $markets = $forwardEligible
                ->groupBy(fn (ModelMarketPerformance $candidate): string => $candidate->symbol.'/'.$candidate->timeframe)
                ->map->count()->map(fn (int $count, string $market): string => "{$market}={$count}")
                ->implode(', ');
            $this->warn("Paper capture blocked before execution: forward candidate exists ({$markets}); check feed readiness, portfolio ownership, or AI signal capture.");
            return;
        }

        $statusCounts = $performances->groupBy('status')->map->count()
            ->map(fn (int $count, string $status): string => "{$status}={$count}")
            ->implode(', ');
        $this->warn('Paper blocked: no forward-validated candidate exists; statistical forward passport must pass first.');
        $this->line('Current performance statuses: '.($statusCounts !== '' ? $statusCounts : 'none').'.');

        $reasons = CandidateGateDecision::query()
            ->where('stage', 'statistical_forward_gate')
            ->whereIn('model_market_performance_id', $performances->pluck('id'))
            ->get()
            ->flatMap(fn (CandidateGateDecision $decision): array => (array) $decision->reason_codes)
            ->countBy()->sortDesc()->take(5)
            ->map(fn (int $count, string $reason): string => "{$reason}={$count}")
            ->implode(', ');
        if ($reasons !== '') {
            $this->line('Top forward blockers: '.$reasons.'.');
        }
    }
}
