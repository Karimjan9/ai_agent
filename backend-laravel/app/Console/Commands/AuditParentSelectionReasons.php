<?php

namespace App\Console\Commands;

use App\Models\LabParentSelectionDecision;
use App\Models\LabGeneration;
use App\Services\EvolutionArchiveService;
use Illuminate\Console\Command;

class AuditParentSelectionReasons extends Command
{
    protected $signature = 'trading:audit-parent-selection-reasons {--generation=} {--apply} {--json}';

    protected $description = 'Audit and backfill explicit parent firewall reason codes without changing lineage';

    public function handle(EvolutionArchiveService $archive): int
    {
        $generationId = $this->resolveGenerationId($this->option('generation'));
        $query = LabParentSelectionDecision::query();
        if ($generationId !== null) $query->where('lab_generation_id', $generationId);
        $total = 0;
        $missing = 0;
        $query->orderBy('id')->each(function (LabParentSelectionDecision $row) use (&$total, &$missing): void {
            $total++;
            if (! filled(data_get($row->policy, 'parent_selection_reason'))
                || ! is_array(data_get($row->policy, 'parent_selection_reasons'))) {
                $missing++;
            }
        });
        $updated = (bool) $this->option('apply') ? $archive->backfillParentSelectionReasons($generationId) : 0;
        $payload = [
            'total' => $total,
            'missing_before_apply' => $missing,
            'updated' => $updated,
            'generation_id' => $generationId,
            'apply' => (bool) $this->option('apply'),
            'promotion_evidence' => false,
        ];
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info("Parent reason audit: {$total} rows, {$missing} missing, {$updated} updated.");
        }
        return self::SUCCESS;
    }

    private function resolveGenerationId(mixed $value): ?int
    {
        if ($value === null || trim((string) $value) === '') return null;
        $requested = (int) $value;
        // Operators naturally refer to G44 by its generation number. Accept
        // that form first, while retaining primary-key compatibility for
        // historical scripts that already pass a lab_generation_id.
        return (int) (
            LabGeneration::query()->where('generation', $requested)->value('id')
            ?: LabGeneration::query()->whereKey($requested)->value('id')
            ?: $requested
        );
    }
}
