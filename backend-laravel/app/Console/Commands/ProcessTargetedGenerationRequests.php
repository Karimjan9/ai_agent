<?php

namespace App\Console\Commands;

use App\Models\CandidateHandoffEvent;
use App\Services\CandidateHandoffService;
use App\Services\LabPopulationService;
use Illuminate\Console\Command;

class ProcessTargetedGenerationRequests extends Command
{
    protected $signature = 'trading:process-targeted-generations';
    protected $description = 'Create one bounded targeted generation for each no-eligible-candidate handoff request';

    public function handle(LabPopulationService $populations, CandidateHandoffService $handoffs): int
    {
        $requests = CandidateHandoffEvent::query()->with('generation.laboratory')
            ->where('stage', 'waiting_for_targeted_generation')->where('status', 'waiting')->get();
        foreach ($requests as $request) {
            $source = $request->generation; $lab = $source?->laboratory;
            if (! $source || ! $lab) continue;
            $latest = $lab->generations()->latest('generation')->first();
            if ($latest && $latest->id !== $source->id) {
                $handoffs->record($source, null, 'targeted_generation_created', 'completed', null, ['target_generation_id' => $latest->id, 'generation' => $latest->generation, 'deduplicated' => true]);
                continue;
            }
            $created = $populations->build($lab->symbol, 'candidate_handoff', false, $lab->timeframe);
            if ($created) {
                $handoffs->record($source, null, 'targeted_generation_created', 'completed', null, ['target_generation_id' => $created->id, 'generation' => $created->generation,
                    'rule' => 'New bounded population is targeted by the recorded failure curriculum; no old screened candidate was force-replayed.']);
                $this->info("{$lab->symbol}: targeted G{$created->generation} created.");
            } else $this->warn("{$lab->symbol}: targeted generation remains waiting for market-data readiness.");
        }
        return self::SUCCESS;
    }
}
