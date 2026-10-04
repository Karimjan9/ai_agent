<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LabSkillZooEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** One non-promoting readiness policy shared by debt accounting and dispatch. */
class ProvisionalCartridgeReadinessService
{
    public const PROTOCOL = 'provisional_cartridge_dispatch_readiness_v1';

    public function inspect(LabSkillZooEntry $entry): array
    {
        $reasons = [];
        $baselineAgent = LabAgent::query()->with('modelVersion', 'generation')->find($entry->causal_baseline_agent_id);
        $baseline = $baselineAgent?->modelVersion;
        $intervention = (array) data_get($entry->evidence, 'intervention', []);
        $gene = (string) $entry->gene_key;
        if ($entry->status !== 'provisional') $reasons[] = 'CARTRIDGE_NOT_PROVISIONAL';
        $observations = Schema::hasTable('skill_cartridge_observations')
            ? DB::table('skill_cartridge_observations')->where('lab_skill_zoo_entry_id', $entry->id)->get() : collect();
        if ($observations->where('outcome', 'positive')->count() < 2) $reasons[] = 'TWO_POSITIVE_OBSERVATIONS_REQUIRED';
        if ($observations->where('outcome', 'negative')->isNotEmpty()) $reasons[] = 'NEGATIVE_OBSERVATION_PRESENT';
        if (! array_key_exists('old_value', $intervention) || ! array_key_exists('tested_value', $intervention)
            || $intervention['tested_value'] === null || json_encode($intervention['old_value']) === json_encode($intervention['tested_value'])) {
            $reasons[] = 'EXACT_NONZERO_INTERVENTION_REQUIRED';
        }
        if ((array) data_get($entry->evidence, 'contraindications', []) !== []) $reasons[] = 'CARTRIDGE_CONTRAINDICATION_PRESENT';
        if (! $baseline || ! $baselineAgent?->generation || $gene === ''
            || $baselineAgent->strategy_family !== $entry->strategy_family
            || strtoupper($baselineAgent->symbol) !== strtoupper($entry->symbol)
            || strtoupper($baselineAgent->timeframe) !== strtoupper($entry->timeframe)
            || ! array_key_exists($gene, (array) $baseline->parameters)
            || json_encode(data_get($baseline->parameters, $gene)) !== json_encode($intervention['old_value'] ?? null)) {
            $reasons[] = 'FROZEN_CAUSAL_BASELINE_DOES_NOT_MATCH_CARTRIDGE';
        }
        if (! filled(data_get($entry->evidence, 'provenance.data_hashes.0'))
            || ! filled(data_get($entry->evidence, 'provenance.execution_hashes.0'))
            || ! is_array(data_get($baselineAgent?->generation?->trigger_context, 'canonical_dataset_snapshots.price.manifest'))
            || ! is_array(data_get($baselineAgent?->generation?->trigger_context, 'canonical_dataset_snapshots.foundation.manifest'))) {
            $reasons[] = 'CARTRIDGE_FROZEN_DATASET_IDENTITY_MISSING';
        }
        if (Schema::hasTable('skill_cartridge_transplant_trials')) {
            $hasTransplant = DB::table('skill_cartridge_transplant_trials')->where('lab_skill_zoo_entry_id', $entry->id)
                ->whereIn('status', ['queued', 'running', 'settled_control', 'passed'])->exists();
            if ($hasTransplant && (! $baseline || ! app(CanonicalSkillCartridgeService::class)->canRetryRepairableTechnicalPreflightCohort($entry, $baseline->id))) {
                $reasons[] = 'CARTRIDGE_ALREADY_OWNED_OR_PROVEN';
            }
        }
        return ['protocol' => self::PROTOCOL, 'ready' => $reasons === [], 'reasons' => array_values(array_unique($reasons)),
            'baseline_model_version_id' => $baseline?->id, 'research_only' => true, 'promotion_evidence' => false];
    }

    public function readyEntries(string $symbol, string $timeframe): \Illuminate\Support\Collection
    {
        if (! Schema::hasTable('lab_skill_zoo_entries') || ! Schema::hasTable('skill_cartridge_observations')) return collect();
        return LabSkillZooEntry::query()->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))
            ->where('status', 'provisional')->whereNotNull('causal_baseline_agent_id')
            ->whereIn('id', DB::table('skill_cartridge_observations')->select('lab_skill_zoo_entry_id')->where('outcome', 'positive')
                ->groupBy('lab_skill_zoo_entry_id')->havingRaw('COUNT(*) >= 2'))
            ->whereNotIn('id', DB::table('skill_cartridge_observations')->select('lab_skill_zoo_entry_id')->where('outcome', 'negative'))
            ->orderByDesc('confidence')->orderByDesc('quality_score')->get()
            ->filter(fn (LabSkillZooEntry $entry): bool => $this->inspect($entry)['ready']);
    }
}
