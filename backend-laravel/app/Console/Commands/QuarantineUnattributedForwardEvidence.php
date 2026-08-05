<?php

namespace App\Console\Commands;

use App\Models\CandidateGateDecision;
use Illuminate\Console\Command;

class QuarantineUnattributedForwardEvidence extends Command
{
    protected $signature = 'trading:quarantine-unattributed-forward {--dry-run : Report legacy rows without changing them}';

    protected $description = 'Quarantine historical forward decisions that have no deterministic laboratory agent attribution';

    public function handle(): int
    {
        $linked = CandidateGateDecision::query()
            ->where('stage', 'statistical_forward_gate')
            ->whereNotNull('lab_agent_id')
            ->where(function ($query): void {
                $query->whereNull('attribution_status')->orWhere('attribution_status', '!=', 'deterministic');
            })
            ->count();
        if ($linked > 0 && ! $this->option('dry-run')) {
            CandidateGateDecision::query()
                ->where('stage', 'statistical_forward_gate')
                ->whereNotNull('lab_agent_id')
                ->update(['attribution_status' => 'deterministic']);
        }
        if ($linked > 0) $this->info('Deterministically linked forward rows normalized: '.$linked.'.');

        $rows = CandidateGateDecision::query()
            ->where('stage', 'statistical_forward_gate')
            ->whereNull('lab_agent_id')
            ->where(function ($query): void {
                $query->whereNull('attribution_status')->orWhere('attribution_status', '!=', 'legacy_unresolved');
            })
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('Unattributed forward rows: 0.');
            return self::SUCCESS;
        }

        $this->info('Unattributed forward rows: '.$rows->count().'.');
        if ($this->option('dry-run')) return self::SUCCESS;

        foreach ($rows as $row) {
            $row->forceFill([
                'attribution_status' => 'legacy_unresolved',
                'quarantined_at' => now(),
                'quarantine_reason' => 'missing_lab_agent_identity_at_forward_write',
            ])->save();
        }

        $this->info('Legacy forward evidence quarantined; immutable decision and metrics were not changed.');
        return self::SUCCESS;
    }
}
