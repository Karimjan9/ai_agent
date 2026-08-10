<?php

namespace App\Console\Commands;

use App\Models\LabGeneration;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Quarantines a volume council whose screen payload failed the volume source
 * contract. Existing evidence remains immutable; only lifecycle eligibility is
 * closed so it cannot be mistaken for a valid volume experiment.
 */
class QuarantineVolumeGeneration extends Command
{
    protected $signature = 'trading:quarantine-volume-generation {generation : Volume council generation number}';

    protected $description = 'Quarantine volume council evidence produced before the canonical UTC payload fix';

    public function handle(): int
    {
        $generationNumber = (int) $this->argument('generation');
        $generations = LabGeneration::query()
            ->where('generation', $generationNumber)
            ->where('trigger_type', 'volume_context_council')
            ->with('agents.modelVersion')
            ->get();
        if ($generations->isEmpty()) {
            $this->error('Volume council generation topilmadi.');
            return self::FAILURE;
        }

        DB::transaction(function () use ($generations): void {
            foreach ($generations as $generation) {
                $generation->agents()->each(function ($agent): void {
                    $metadata = (array) ($agent->modelVersion?->metadata ?? []);
                    data_set($metadata, 'volume_research_contract.screen_integrity', [
                        'status' => 'technical_quarantine',
                        'reason' => 'volume_available_marker_lost_at_non_utc_payload_key',
                        'evidence_immutable' => true,
                        'promotion_evidence' => false,
                    ]);
                    $agent->modelVersion?->update(['metadata' => $metadata]);
                    $agent->update([
                        'lifecycle_status' => 'technical_quarantine',
                        'decision_reason' => 'Technical quarantine: volume screen payload had zero canonical matches before UTC timestamp normalization; evidence unchanged.',
                    ]);
                });
                $context = (array) $generation->trigger_context;
                $context['technical_quarantine'] = [
                    'reason' => 'volume_available_marker_lost_at_non_utc_payload_key',
                    'evidence_immutable' => true,
                    'corrective_protocol' => 'canonical_volume_payload_utc_v1',
                    'promotion_evidence' => false,
                ];
                $generation->update([
                    'status' => 'technical_quarantine',
                    'completed_at' => now(),
                    'trigger_context' => $context,
                ]);
            }
        });

        $this->info("Volume council G{$generationNumber} technical_quarantine qilindi; immutable evidence saqlandi.");
        return self::SUCCESS;
    }
}
