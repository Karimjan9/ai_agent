<?php

namespace App\Console\Commands;

use App\Models\AiLaboratory;
use App\Services\CandidateHandoffService;
use App\Services\LabCandidateSelectionService;
use Illuminate\Console\Command;

class BackfillCandidateHandoff extends Command
{
    protected $signature = 'trading:backfill-candidate-handoff {symbol} {generation} {--timeframe=H1}';
    protected $description = 'Backfill immutable candidate handoff evidence without dispatching or promoting a candidate';

    public function handle(CandidateHandoffService $handoffs, LabCandidateSelectionService $selection): int
    {
        $symbol = strtoupper((string) $this->argument('symbol')); $timeframe = strtoupper((string) $this->option('timeframe'));
        $generation = AiLaboratory::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->firstOrFail()
            ->generations()->with('agents.modelVersion')->where('generation', (int) $this->argument('generation'))->firstOrFail();
        $handoffs->backfill($generation);
        $screened = $generation->agents->where('lifecycle_status', 'screened')->values();
        $eligible = $selection->select($screened);
        // A completed generation with no forward-valid agent is also a real
        // no-candidate outcome (for example G32 screen/full collapse).  It
        // must enter the same failure-driven rescue protocol rather than
        // disappearing merely because its one replay has already finished.
        $noForwardCandidate = $generation->agents->whereIn('lifecycle_status', ['forward_validated', 'paper', 'champion'])->isEmpty();
        if ($eligible->isEmpty() && ($generation->status === 'screened' || ($generation->status === 'completed' && $noForwardCandidate))) {
            $handoffs->noEligibleCandidate($generation);
            $this->warn("{$symbol} G{$generation->generation}: NO_ELIGIBLE_CANDIDATE; targeted curriculum recorded, nothing dispatched.");
            return self::SUCCESS;
        }
        foreach ($eligible as $agent) $handoffs->record($generation, $agent, 'exportable', 'pending_operator_dispatch', null, ['backfilled' => true]);
        $this->info("{$symbol} G{$generation->generation}: {$eligible->count()} eligible near-miss/screen winner; no replay dispatched.");
        return self::SUCCESS;
    }
}
