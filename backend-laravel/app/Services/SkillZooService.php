<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LabSkillZooEntry;
use Illuminate\Support\Facades\Schema;

/** A quality-diversity archive of reusable strategy modules, never champions. */
class SkillZooService
{
    public const PROTOCOL = 'quality_diversity_skill_zoo_v1';

    /** @return array<string, mixed>|null */
    public function record(LabAgent $agent, array $result, ?array $response = null): ?array
    {
        if (! $this->available() || ! $agent->modelVersion) return null;
        $gene = (string) data_get($response, 'parameter_key', array_key_first((array) $agent->parameter_diff));
        if ($gene === '' || str_starts_with($gene, '__')) return null;
        $target = (string) data_get($response, 'target', data_get($agent->modelVersion->metadata, 'generation_target', 'unknown'));
        $context = (array) data_get($response, 'contextual_bandit', data_get($result, 'mutation_observability.contextual_bandit', []));
        $module = $this->moduleFor($gene);
        $niche = $this->nicheKey($target, $context, $agent);
        $delta = data_get($response, 'target_delta.delta', data_get($result, 'mutation_observability.control_delta', 0));
        $delta = is_numeric($delta) ? (float) $delta : 0.0;
        $safe = ! (bool) data_get($result, 'mutation_observability.non_target_regression.failed', false);
        $responseStatus = (string) data_get($response, 'status', 'observed');
        $status = ! $safe ? 'harmful' : (in_array($responseStatus, ['confirmed', 'independently_confirmed', 'validated'], true) ? 'confirmed' : ($delta > 0 ? 'provisional' : 'observed'));
        $key = hash('sha256', json_encode([self::PROTOCOL, $agent->id, $module, $niche, $gene, data_get($response, 'id'), data_get($result, 'evidence_run_id')], JSON_UNESCAPED_SLASHES));
        $entry = LabSkillZooEntry::query()->firstOrCreate(['skill_key' => $key], [
            'symbol' => strtoupper($agent->symbol), 'timeframe' => strtoupper($agent->timeframe), 'strategy_family' => $agent->strategy_family,
            'module_key' => $module, 'niche_key' => $niche, 'gene_key' => $gene, 'lab_agent_id' => $agent->id,
            'model_version_id' => $agent->model_version_id, 'lab_mutation_response_map_id' => data_get($response, 'id'),
            'quality_score' => $delta, 'confidence' => $status === 'confirmed' ? .8 : ($status === 'provisional' ? .5 : .2), 'status' => $status,
            'evidence' => ['protocol' => self::PROTOCOL, 'target' => $target, 'context' => $context, 'target_delta' => $delta,
                'safe_non_target_regression' => $safe, 'response_status' => $responseStatus,
                'rule' => 'A skill-zoo entry is a niche-local research artifact; it cannot become a parent or champion without ordinary independent gates.', 'promotion_evidence' => false],
        ]);
        return ['protocol' => self::PROTOCOL, 'id' => $entry->id, 'module' => $module, 'niche' => $niche, 'status' => $entry->status, 'promotion_evidence' => false];
    }

    /** @return array<string, mixed> */
    public function semanticCrossoverPlan(string $symbol, string $timeframe, string $family, array $requiredModules = []): array
    {
        if (! $this->available()) return ['protocol' => self::PROTOCOL, 'status' => 'migration_pending', 'promotion_evidence' => false];
        $entries = LabSkillZooEntry::query()->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))
            ->where('strategy_family', $family)->whereIn('status', ['provisional', 'confirmed'])->orderByDesc('quality_score')->get();
        $modules = $requiredModules ?: ['regime_detector', 'setup_detector', 'entry_trigger', 'confirmation', 'initial_risk', 'exit', 'cost_filter', 'position_sizing'];
        $selected = collect($modules)->mapWithKeys(function (string $module) use ($entries): array {
            $entry = $entries->where('module_key', $module)->first();
            return [$module => $entry ? ['skill_zoo_entry_id' => $entry->id, 'gene_key' => $entry->gene_key, 'niche_key' => $entry->niche_key, 'status' => $entry->status] : null];
        })->all();
        return ['protocol' => self::PROTOCOL, 'status' => 'research_plan', 'selected_modules' => $selected,
            'ablation_arms' => ['control', 'module_a', 'module_b', 'module_a_plus_b', 'module_a_plus_b_minus_filter'],
            'rule' => 'Semantic crossover is admissible only as a frozen-control paired experiment; module provenance does not transfer performance or promotion status.', 'promotion_evidence' => false];
    }

    public function moduleFor(string $gene): string
    {
        $gene = strtolower($gene);
        return match (true) {
            str_contains($gene, 'regime') || str_contains($gene, 'transition') => 'regime_detector',
            str_contains($gene, 'exit') || str_contains($gene, 'target') || str_contains($gene, 'stop') || str_contains($gene, 'trail') => 'exit',
            str_contains($gene, 'risk') || str_contains($gene, 'loss') || str_contains($gene, 'drawdown') || str_contains($gene, 'size') => 'initial_risk',
            str_contains($gene, 'spread') || str_contains($gene, 'slippage') || str_contains($gene, 'cost') => 'cost_filter',
            str_contains($gene, 'confirm') => 'confirmation',
            str_contains($gene, 'entry') || str_contains($gene, 'signal') || str_contains($gene, 'threshold') => 'entry_trigger',
            default => 'setup_detector',
        };
    }

    private function nicheKey(string $target, array $context, LabAgent $agent): string
    {
        return implode('|', [$target ?: 'unknown', data_get($context, 'regime', 'unknown'), data_get($context, 'session', 'unknown'), data_get($context, 'side', 'both'), $agent->strategy_family]);
    }

    private function available(): bool { try { return Schema::hasTable('lab_skill_zoo_entries'); } catch (\Throwable) { return false; } }
}
