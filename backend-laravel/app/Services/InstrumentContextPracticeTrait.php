<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Curator-owned scoped research. Existing method/policy/evaluator owners execute it. */
trait InstrumentContextPracticeTrait
{
    public const CONTEXT_PRACTICE_KEY = 'curator_adx_dmi_context_20261015_v1';
    public const CONTEXT_PRACTICE_PATH = 'research/instrument-methods/context-adx-dmi-v1.json';
    // Pinned before original execution; earlier curriculum resources remain byte-identical.
    public const CONTEXT_PRACTICE_SHA256 = '90d54cbcacc41b9d938c44e63aff2eeb2ef999bafbc5ff680c7f4f1e9da2cbb4';

    public function contextPracticeCatalog(): array
    {
        try {
            $path = resource_path(self::CONTEXT_PRACTICE_PATH);
            $this->contextNeed(is_file($path) && ! is_link($path) && filesize($path) <= 65536
                && hash_file('sha256', $path) === self::CONTEXT_PRACTICE_SHA256, 'CONTEXT_PRACTICE_SOURCE_DRIFT');
            $design = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            $this->contextNeed(($design['protocol'] ?? null) === 'curator_context_practice_design_v1'
                && ($design['key'] ?? null) === self::CONTEXT_PRACTICE_KEY && ($design['revision'] ?? null) === 1
                && $design['styles'] === ['dmi', 'strong'] && $design['regimes'] === ['trend_up', 'trend_down']
                && $design['sessions'] === ['london', 'overlap', 'new_york'] && $design['volatilities'] === ['normal', 'high'],
                'CONTEXT_PRACTICE_DESIGN_INVALID');
            $sources = []; $sourceKeys = [];
            foreach ($design['sources'] as $source) {
                $key = $source['source_id']; unset($source['source_id']);
                $sourceKeys[$key] = $this->contextSourceKey($source);
                $sources[$key] = $source;
            }
            $methods = [];
            foreach ($design['styles'] as $style) foreach ($design['sessions'] as $session)
                foreach ($design['regimes'] as $regime) foreach ($design['volatilities'] as $volatility) {
                    $key = "curator_{$style}_{$regime}_{$session}_{$volatility}_v1";
                    $args = [$this->contextBool('available'), $this->contextBool('aligned')];
                    $bindings = ['available' => 'method_dmi_available', 'aligned' => 'method_dmi_aligned'];
                    if ($style === 'strong') { $args[] = $this->contextBool('strong'); $bindings['strong'] = 'method_adx_strong'; }
                    $methods[$key] = ['style' => $style, 'method_input' => [
                        'instrument_key' => 'regime_router', 'method_key' => $key, 'revision' => 1, 'horizon' => 'hour',
                        'context_predicate' => ['regime' => $regime, 'session' => $session, 'volatility' => $volatility],
                        'source_keys' => [$sourceKeys['stockcharts_directional_movement']],
                        'known_limitations' => $design['limitations'],
                        'implementation' => ['kind' => 'typed_ast', 'target' => 'confirmation',
                            'ast' => ['op' => 'AND', 'args' => $args], 'input_bindings' => $bindings,
                            'budget' => ['cpu_seconds' => .5, 'max_calls' => 4096, 'max_node_evaluations' => 32768]]]];
                }
            ksort($methods); ksort($sources); ksort($sourceKeys);
            $body = ['protocol' => 'curator_context_practice_catalog_v1', 'key' => self::CONTEXT_PRACTICE_KEY,
                'design_sha256' => self::CONTEXT_PRACTICE_SHA256, 'design' => $design, 'sources' => $sources,
                'source_keys' => $sourceKeys, 'methods' => $methods, ...$this->contextCeiling()];
            return [...$body, 'status' => 'context_practice_catalog', 'catalog_hash' => $this->hashes->parameterHash($body)];
        } catch (Throwable $error) { return $this->contextBlocked($error); }
    }

    /** Explicit append-only installation; no model attachment, dispatch or network capture. */
    public function installContextPractice(): array
    {
        try {
            $catalog = $this->contextPracticeCatalog();
            $this->contextNeed(($catalog['status'] ?? null) === 'context_practice_catalog', $catalog['reason'] ?? 'CONTEXT_PRACTICE_DESIGN_INVALID');
            return DB::transaction(function () use ($catalog): array {
                foreach ($catalog['sources'] as $id => $source) {
                    $original = $this->library->registerSource($source);
                    $this->contextNeed(($original['status'] ?? null) === 'registered_reference_only'
                        && $original['source_key'] === $catalog['source_keys'][$id], 'CONTEXT_PRACTICE_ORIGINAL_SOURCE_REQUIRED');
                }
                $methods = [];
                foreach ($catalog['methods'] as $key => $row) {
                    $method = $this->library->registerMethod($row['method_input']);
                    $this->contextNeed(($method['status'] ?? null) === 'registered_research_only',
                        'CONTEXT_PRACTICE_METHOD_REGISTRATION_REQUIRED');
                    $recipe = $this->library->registerRecipe(['recipe_key' => $key.'_single', 'revision' => 1,
                        'method_hashes' => [$method['method_hash']], 'context_predicate' => $method['contract']['context_predicate']]);
                    $this->contextNeed(($recipe['status'] ?? null) === 'registered_research_only',
                        'CONTEXT_PRACTICE_RECIPE_REGISTRATION_REQUIRED');
                    $methods[$key] = ['style' => $row['style'], 'method_hash' => $method['method_hash'],
                        'contract' => $method['contract'], 'recipe_hash' => $recipe['recipe_hash']];
                }
                $body = ['catalog' => $catalog, 'methods' => $methods, ...$this->contextCeiling()];
                return [...$body, 'status' => 'context_practice_installed', 'install_hash' => $this->hashes->parameterHash($body)];
            });
        } catch (Throwable $error) { return $this->contextBlocked($error); }
    }

    private function contextBool(string $key): array
    {
        return ['op' => 'BOOL', 'input_key' => $key, 'available_at' => '2000-01-01T00:00:00Z'];
    }

    private function contextSourceKey(array $source): string
    {
        return $this->hashes->parameterHash(['protocol' => InstrumentMethodSourceService::PROTOCOL,
            'kind' => $source['kind'], 'reference' => $source['reference'], 'content_hash' => hash('sha256', $source['content']),
            'metadata' => $source['metadata'], 'untrusted_reference_only' => true, 'qualification_granted' => false, 'promotion_evidence' => false]);
    }

    private function contextCeiling(): array
    {
        return ['research_only' => true, 'attribution' => 'curator_source_research', 'own_agent_outcome' => false,
            'independent_validation' => false, 'credit' => false, 'qualification_granted' => false,
            'economic_authority' => false, 'paper_authority' => false, 'live_authority' => false];
    }

    private function contextNeed(bool $ok, string $reason): void { if (! $ok) throw new RuntimeException($reason); }
    private function contextBlocked(Throwable $error): array
    {
        return ['status' => 'blocked', 'reason' => preg_match('/^CONTEXT_PRACTICE_[A-Z_]+$/D', $error->getMessage())
            ? $error->getMessage() : 'CONTEXT_PRACTICE_INVALID', ...$this->contextCeiling()];
    }
}
