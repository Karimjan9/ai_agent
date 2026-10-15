<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** Append-only curator exit instruction; existing owners execute every decision. */
trait InstrumentManagementPracticeTrait
{
    public const MANAGEMENT_PRACTICE_KEY = 'curator_management_lifecycle_20261015_v1';
    public const MANAGEMENT_PRACTICE_PATH = 'research/instrument-methods/management-exit-practice-v1.json';
    public const MANAGEMENT_PRACTICE_SHA256 = '9ebc74e2240a19541ef60fb734e3586f85c571ecdd9261877e8b17eaf5e5b504';

    public function managementPracticeCatalog(): array
    {
        try {
            $path = resource_path(self::MANAGEMENT_PRACTICE_PATH);
            $this->managementNeed(is_file($path) && ! is_link($path) && filesize($path) <= 65536
                && hash_file('sha256', $path) === self::MANAGEMENT_PRACTICE_SHA256, 'MANAGEMENT_PRACTICE_SOURCE_DRIFT');
            $design = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            $this->managementNeed(($design['protocol'] ?? null) === 'curator_management_practice_design_v1'
                && ($design['key'] ?? null) === self::MANAGEMENT_PRACTICE_KEY && ($design['revision'] ?? null) === 1
                && $design['instrument_key'] === 'cost_aware_exit'
                && $design['regimes'] === ['trend_up', 'trend_down', 'range', 'transition']
                && $design['sessions'] === ['asia', 'london', 'overlap', 'new_york']
                && $design['volatilities'] === ['normal', 'high'], 'MANAGEMENT_PRACTICE_DESIGN_INVALID');
            $sources = []; $sourceKeys = [];
            foreach ($design['sources'] as $source) {
                $id = $source['source_id']; unset($source['source_id']);
                $sourceKeys[$id] = $this->managementSourceKey($source);
                $sources[$id] = $source;
            }
            $methods = [];
            foreach ($design['regimes'] as $regime) foreach ($design['sessions'] as $session)
                foreach ($design['volatilities'] as $volatility) {
                    $key = "curator_chandelier22x3_{$regime}_{$session}_{$volatility}_v1";
                    $methods[$key] = ['style' => 'chandelier_close', 'method_input' => [
                        'instrument_key' => 'cost_aware_exit', 'method_key' => $key, 'revision' => 1, 'horizon' => 'hour',
                        'context_predicate' => ['regime' => $regime, 'session' => $session, 'volatility' => $volatility],
                        'source_keys' => [$sourceKeys['stockcharts_chandelier_exit']],
                        'known_limitations' => $design['limitations'],
                        'implementation' => ['kind' => 'typed_ast', 'target' => 'exit',
                            'ast' => ['op' => 'AND', 'args' => [$this->managementBool('available'), $this->managementBool('effect')]],
                            'input_bindings' => ['available' => 'method_chandelier_available', 'effect' => 'method_chandelier_exit'],
                            'budget' => ['cpu_seconds' => .5, 'max_calls' => 4096, 'max_node_evaluations' => 12288]]]];
                }
            ksort($methods); ksort($sources); ksort($sourceKeys);
            $body = ['protocol' => 'curator_management_practice_catalog_v1', 'key' => self::MANAGEMENT_PRACTICE_KEY,
                'design_sha256' => self::MANAGEMENT_PRACTICE_SHA256, 'design' => $design, 'sources' => $sources,
                'source_keys' => $sourceKeys, 'methods' => $methods, ...$this->managementCeiling()];
            return [...$body, 'status' => 'management_practice_catalog', 'catalog_hash' => $this->hashes->parameterHash($body)];
        } catch (Throwable $error) { return $this->managementBlocked($error); }
    }

    /** Explicit installation only; no policy attachment, dispatch or network capture. */
    public function installManagementPractice(): array
    {
        try {
            $catalog = $this->managementPracticeCatalog();
            $this->managementNeed(($catalog['status'] ?? null) === 'management_practice_catalog',
                $catalog['reason'] ?? 'MANAGEMENT_PRACTICE_DESIGN_INVALID');
            return DB::transaction(function () use ($catalog): array {
                foreach ($catalog['sources'] as $id => $source) {
                    $original = $this->library->registerSource($source);
                    $this->managementNeed(($original['status'] ?? null) === 'registered_reference_only'
                        && $original['source_key'] === $catalog['source_keys'][$id], 'MANAGEMENT_PRACTICE_ORIGINAL_SOURCE_REQUIRED');
                }
                $methods = [];
                foreach ($catalog['methods'] as $key => $row) {
                    $method = $this->library->registerMethod($row['method_input']);
                    $this->managementNeed(($method['status'] ?? null) === 'registered_research_only',
                        'MANAGEMENT_PRACTICE_METHOD_REGISTRATION_REQUIRED');
                    $recipe = $this->library->registerRecipe(['recipe_key' => $key.'_single', 'revision' => 1,
                        'method_hashes' => [$method['method_hash']], 'context_predicate' => $method['contract']['context_predicate']]);
                    $this->managementNeed(($recipe['status'] ?? null) === 'registered_research_only',
                        'MANAGEMENT_PRACTICE_RECIPE_REGISTRATION_REQUIRED');
                    $methods[$key] = ['style' => $row['style'], 'method_hash' => $method['method_hash'],
                        'contract' => $method['contract'], 'recipe_hash' => $recipe['recipe_hash']];
                }
                $body = ['catalog' => $catalog, 'methods' => $methods, ...$this->managementCeiling()];
                return [...$body, 'status' => 'management_practice_installed', 'install_hash' => $this->hashes->parameterHash($body)];
            });
        } catch (Throwable $error) { return $this->managementBlocked($error); }
    }

    private function managementBool(string $key): array
    {
        return ['op' => 'BOOL', 'input_key' => $key, 'available_at' => '2000-01-01T00:00:00Z'];
    }

    private function managementSourceKey(array $source): string
    {
        return $this->hashes->parameterHash(['protocol' => InstrumentMethodSourceService::PROTOCOL,
            'kind' => $source['kind'], 'reference' => $source['reference'], 'content_hash' => hash('sha256', $source['content']),
            'metadata' => $source['metadata'], 'untrusted_reference_only' => true, 'qualification_granted' => false, 'promotion_evidence' => false]);
    }

    private function managementCeiling(): array
    {
        return ['research_only' => true, 'attribution' => 'curator_source_research', 'own_agent_outcome' => false,
            'independent_validation' => false, 'credit' => false, 'qualification_granted' => false,
            'economic_authority' => false, 'paper_authority' => false, 'live_authority' => false];
    }

    private function managementNeed(bool $ok, string $reason): void { if (! $ok) throw new RuntimeException($reason); }
    private function managementBlocked(Throwable $error): array
    {
        return ['status' => 'blocked', 'reason' => preg_match('/^MANAGEMENT_PRACTICE_[A-Z_]+$/D', $error->getMessage())
            ? $error->getMessage() : 'MANAGEMENT_PRACTICE_INVALID', ...$this->managementCeiling()];
    }
}
