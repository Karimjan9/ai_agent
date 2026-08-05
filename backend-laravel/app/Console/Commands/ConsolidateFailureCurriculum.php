<?php

namespace App\Console\Commands;

use App\Models\AgentFailureCase;
use Illuminate\Console\Command;

/** Preserve old failure evidence while removing generation-id duplicate cases from active curriculum. */
class ConsolidateFailureCurriculum extends Command
{
    protected $signature = 'trading:consolidate-failure-curriculum {--dry-run}';

    protected $description = 'Quarantine superseded generation-specific failure cases into stable market deficit cases';

    public function handle(): int
    {
        $cases = AgentFailureCase::query()->where('discovered_by', 'CandidateHandoffProtocol')->get();
        $quarantined = 0;

        foreach ($cases as $case) {
            $stableKey = hash('sha256', "handoff|{$case->symbol}|{$case->timeframe}|{$case->failure_type}");
            $stable = $cases->firstWhere('failure_case_key', $stableKey);
            if (! $stable || $stable->id === $case->id || $case->regression_status !== 'open') continue;

            $quarantined++;
            if (! $this->option('dry-run')) {
                $evidence = (array) $case->evidence;
                $evidence['superseded_by'] = $stable->id;
                $evidence['quarantine_reason'] = 'GENERATION_SPECIFIC_DUPLICATE';
                $evidence['promotion_evidence'] = false;
                $case->update(['regression_status' => 'quarantined', 'evidence' => $evidence]);
            }
        }

        $this->info(($this->option('dry-run') ? 'Would quarantine: ' : 'Quarantined: ').$quarantined.' duplicate failure case(s).');
        return self::SUCCESS;
    }
}
