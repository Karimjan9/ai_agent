<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Code-owned Internet hypotheses, never an agent policy or an earned skill. */
class CuratedInstrumentMethodCatalogService
{
    use InstrumentOperatingSkillCurriculumTrait;
    use InstrumentContextPracticeTrait;
    use InstrumentManagementPracticeTrait;

    public const PROTOCOL = 'curated_instrument_method_catalog_v1';
    public const CATALOG_KEY = 'internet_instrument_methods_20261013_v1';
    public const CATALOG_SHA256 = 'ef8e53d5549ccbb55800dc4ec2f25e3d7d466b8eb64faf325d4202314e497e20';
    public const CATALOG_PATH = 'resources/research/instrument-methods/curated-v1.json';
    public const EXPANDED_CATALOG_KEY = 'internet_instrument_methods_20261013_v2';
    public const EXPANDED_CATALOG_SHA256 = '2a62f3d69e223b1cd4cc180c10f3f0628949a9af6ca02645abd92c5e23090ae7';
    public const EXPANDED_CATALOG_PATH = 'resources/research/instrument-methods/curated-v2.json';

    public function __construct(
        private InstrumentMethodLibraryService $library,
        private InstrumentMethodSourceService $sources,
        private TradingInstrumentOperatingSystemService $instruments,
        private ResearchPaperEpochContractService $hashes,
    ) {}

    /** Preview the pinned checked-in declaration without reading/writing agent state. */
    public function catalog(array $input = []): array
    {
        try {
            $catalog = $this->selectedDeclaration($input);
            $sources = array_map(function (array $source): array { unset($source['summary'], $source['_catalog_key'], $source['_catalog_sha256']); return $source; }, $catalog['sources']);
            return [...$this->base(), 'status' => 'curated_research_catalog', 'catalog_key' => $catalog['catalog_key'],
                'catalog_sha256' => $catalog['_catalog_sha256'], 'policy' => $catalog['policy'], 'sources' => $sources,
                'coverage' => $catalog['coverage'], 'methods' => $catalog['methods'], 'hybrids' => $catalog['hybrids'],
                'counts' => $this->counts($catalog)];
        } catch (RuntimeException $error) { return $this->blocked($error->getMessage()); }
    }

    /** Explicit append-only install. No fetch, model rebinding, policy creation or dispatch. */
    public function install(array $input = []): array
    {
        try {
            $catalog = $this->selectedDeclaration($input);
            return DB::transaction(function () use ($catalog): array {
                $sourceHashes = [];
                foreach ($catalog['sources'] as $source) {
                    $registered = $this->sources->registerSource($this->sourceInput($source));
                    $this->requireStatus($registered, 'registered_reference_only');
                    $sourceHashes[$source['source_id']] = $registered['source_key'];
                }
                $methodHashes = [];
                $recipeHashes = [];
                $typed = [];
                $levels = ['L1' => [], 'L2' => []];
                foreach ($catalog['methods'] as $method) {
                    $registered = $this->library->registerMethod($this->methodInput($method, $sourceHashes));
                    $this->requireStatus($registered, 'registered_research_only');
                    $methodHashes[$this->methodRef($method)] = $registered['method_hash'];
                    $key = $this->singleRecipeKey($method['method_key']);
                    $recipe = $this->library->registerRecipe(['recipe_key' => $key, 'revision' => $method['revision'],
                        'method_hashes' => [$registered['method_hash']], 'context_predicate' => $method['context_predicate']]);
                    $this->requireStatus($recipe, 'registered_research_only');
                    $recipeHashes[$key.'@'.$method['revision']] = $recipe['recipe_hash'];
                    if ($method['implementation']['kind'] === 'typed_ast') {
                        $levels['L'.$method['revision']][] = $recipe['recipe_hash'];
                        if ($method['revision'] === 1) $typed[] = $recipe['recipe_hash'];
                    }
                }
                foreach ($catalog['hybrids'] as $hybrid) {
                    $recipe = $this->library->registerRecipe(['recipe_key' => $hybrid['recipe_key'], 'revision' => $hybrid['revision'],
                        'method_hashes' => array_map(fn (string $key): string => $methodHashes[$key.'@1'], $hybrid['method_refs']),
                        'context_predicate' => $hybrid['context_predicate']]);
                    $this->requireStatus($recipe, 'registered_research_only');
                    $recipeHashes[$hybrid['recipe_key'].'@'.$hybrid['revision']] = $recipe['recipe_hash'];
                }
                ksort($sourceHashes); ksort($methodHashes); ksort($recipeHashes); sort($typed);
                foreach ($levels as &$hashes) sort($hashes); unset($hashes);
                $body = ['protocol' => 'curated_instrument_method_install_v1', 'catalog_key' => $catalog['catalog_key'],
                    'catalog_sha256' => $catalog['_catalog_sha256'], 'source_hashes' => $sourceHashes,
                    'method_hashes' => $methodHashes, 'recipe_hashes' => $recipeHashes, 'typed_recipe_hashes' => $typed,
                    'typed_library_levels' => $levels,
                    'instrument_inventory_hash' => $this->hashes->parameterHash($this->instruments->inventory()),
                    'counts' => $this->counts($catalog), 'agent_skill_changed' => false,
                    'research_only' => true, 'qualification_granted' => false, 'economic_authority' => false,
                    'promotion_evidence' => false, 'paper_authority' => false, 'live_authority' => false];
                return [...$body, 'status' => 'curated_research_catalog_installed', 'install_hash' => $this->hashes->parameterHash($body)];
            });
        } catch (RuntimeException $error) { return $this->blocked($error->getMessage()); }
    }

    /** Reopen exact fixed revisions. Ambient registry additions cannot change this selection. */
    public function installedSelection(array $input = []): array
    {
        try {
            $catalog = $this->selectedDeclaration($input);
            $methods = [];
            foreach ($catalog['methods'] as $method) {
                $row = DB::table('instrument_method_revisions')->where('instrument_key', $method['instrument_key'])
                    ->where('method_key', $method['method_key'])->where('revision', $method['revision'])->first(['method_hash']);
                if (! $row) return $this->blocked('CURATED_METHOD_INSTALL_REQUIRED');
                $actual = $this->library->inspectMethod($row->method_hash);
                $this->requireStatus($actual, 'registered_research_only');
                // Fixed file checksum alone does not attest that the database retained its exact declarations.
                $sourceHashes = [];
                foreach ($catalog['sources'] as $source) $sourceHashes[$source['source_id']] = $this->expectedSourceHash($source);
                foreach ($actual['contract']['source_keys'] as $hash) $this->requireStatus($this->sources->inspectSource($hash), 'registered_reference_only');
                $expected = $this->methodInput($method, $sourceHashes);
                foreach (['instrument_key', 'method_key', 'revision', 'horizon', 'implementation', 'source_keys', 'context_predicate'] as $key) {
                    $expectedValue = $expected[$key];
                    if ($key === 'source_keys') sort($expectedValue);
                    if ($this->hashes->parameterHash(['value' => $expectedValue]) !== $this->hashes->parameterHash(['value' => $actual['contract'][$key] ?? null])) {
                        throw new RuntimeException('CURATED_METHOD_INSTALLED_DECLARATION_DRIFT');
                    }
                }
                $card = $this->instruments->inventory()['instruments'][$method['instrument_key']];
                $limitations = array_values(array_unique([...$expected['known_limitations'], ...$card['runtime_capability']['limitations']]));
                if ($limitations !== $actual['contract']['known_limitations']) throw new RuntimeException('CURATED_METHOD_INSTALLED_DECLARATION_DRIFT');
                $methods[$this->methodRef($method)] = $actual;
            }
            $typed = [];
            $recipes = [];
            $levels = ['L1' => [], 'L2' => []];
            foreach ($catalog['methods'] as $method) {
                $recipeKey = $this->singleRecipeKey($method['method_key']);
                $row = DB::table('instrument_recipe_revisions')->where('recipe_key', $recipeKey)->where('revision', $method['revision'])->first(['recipe_hash']);
                if (! $row) return $this->blocked('CURATED_METHOD_INSTALL_REQUIRED');
                $actual = $this->library->inspectRecipe($row->recipe_hash);
                $this->requireStatus($actual, 'registered_research_only');
                if ($actual['contract']['method_hashes'] !== [$methods[$this->methodRef($method)]['method_hash']]
                    || $actual['contract']['horizon'] !== $method['horizon']
                    || $this->hashes->parameterHash($actual['contract']['context_predicate']) !== $this->hashes->parameterHash($method['context_predicate'])) {
                    throw new RuntimeException('CURATED_METHOD_INSTALLED_RECIPE_DRIFT');
                }
                $recipes[$recipeKey.'@'.$method['revision']] = $row->recipe_hash;
                if ($method['implementation']['kind'] === 'typed_ast') {
                    $levels['L'.$method['revision']][] = $row->recipe_hash;
                    if ($method['revision'] === 1) $typed[] = $row->recipe_hash;
                }
            }
            if ($catalog['catalog_key'] === self::EXPANDED_CATALOG_KEY) {
                foreach ($catalog['hybrids'] as $hybrid) {
                    $row = DB::table('instrument_recipe_revisions')->where('recipe_key', $hybrid['recipe_key'])
                        ->where('revision', $hybrid['revision'])->first(['recipe_hash']);
                    if (! $row) return $this->blocked('CURATED_METHOD_INSTALL_REQUIRED');
                    $actual = $this->library->inspectRecipe($row->recipe_hash);
                    $this->requireStatus($actual, 'registered_research_only');
                    $expected = array_map(fn (string $key): string => $methods[$key.'@1']['method_hash'], $hybrid['method_refs']);
                    sort($expected);
                    if ($actual['contract']['method_hashes'] !== $expected
                        || $actual['contract']['hybrid'] !== true
                        || $this->hashes->parameterHash($actual['contract']['context_predicate']) !== $this->hashes->parameterHash($hybrid['context_predicate'])) {
                        throw new RuntimeException('CURATED_METHOD_INSTALLED_RECIPE_DRIFT');
                    }
                    $recipes[$hybrid['recipe_key'].'@'.$hybrid['revision']] = $row->recipe_hash;
                }
            }
            sort($typed); ksort($recipes);
            foreach ($levels as &$hashes) sort($hashes); unset($hashes);
            $selection = ['protocol' => 'curated_instrument_method_selection_v1', 'catalog_key' => $catalog['catalog_key'],
                'catalog_sha256' => $catalog['_catalog_sha256'], 'recipe_hashes' => $recipes, 'typed_recipe_hashes' => $typed,
                'typed_library_levels' => $levels,
                'research_only' => true, 'agent_skill_changed' => false, 'qualification_granted' => false,
                'economic_authority' => false, 'promotion_evidence' => false, 'paper_authority' => false, 'live_authority' => false];
            return [...$selection, 'status' => 'curated_research_selection', 'selection_hash' => $this->hashes->parameterHash($selection)];
        } catch (RuntimeException $error) { return $this->blocked($error->getMessage()); }
    }

    private function selectedDeclaration(array $input): array
    {
        $key = $input['catalog_key'] ?? self::CATALOG_KEY;
        $sha = match ($key) { self::CATALOG_KEY => self::CATALOG_SHA256,
            self::EXPANDED_CATALOG_KEY => self::EXPANDED_CATALOG_SHA256, default => null };
        if ($sha === null || array_diff(array_keys($input), ['catalog_key', 'catalog_sha256']) !== []
            || ($input['catalog_sha256'] ?? ($key === self::CATALOG_KEY ? $sha : null)) !== $sha) {
            throw new RuntimeException('CURATED_METHOD_EXACT_CATALOG_REQUIRED');
        }
        return $this->declaration($key);
    }

    private function declaration(string $key = self::CATALOG_KEY): array
    {
        $expanded = $key === self::EXPANDED_CATALOG_KEY;
        $sha = $expanded ? self::EXPANDED_CATALOG_SHA256 : self::CATALOG_SHA256;
        $path = resource_path($expanded ? 'research/instrument-methods/curated-v2.json' : 'research/instrument-methods/curated-v1.json');
        if (! is_file($path) || is_link($path) || filesize($path) > 1048576
            || hash_file('sha256', $path) !== $sha) throw new RuntimeException('CURATED_METHOD_CATALOG_BYTE_DRIFT');
        try { $data = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { throw new RuntimeException('CURATED_METHOD_CATALOG_INVALID'); }
        if (! is_array($data) || ($data['protocol'] ?? null) !== self::PROTOCOL || ($data['catalog_key'] ?? null) !== $key
            || ($data['revision'] ?? null) !== ($expanded ? 2 : 1) || ($data['evidence_status'] ?? null) !== 'unvalidated_research_hypotheses'
            || ($data['policy']['all_2026_and_later_market_data'] ?? null) !== 'paper_only') throw new RuntimeException('CURATED_METHOD_CATALOG_INVALID');
        $data['_catalog_sha256'] = $sha;
        foreach ($data['sources'] as &$source) {
            $source['_catalog_key'] = $key;
            $source['_catalog_sha256'] = $sha;
        }
        unset($source);
        if ($expanded) {
            if (($data['extends'] ?? null) !== ['catalog_key' => self::CATALOG_KEY, 'catalog_sha256' => self::CATALOG_SHA256]
                || count($data['methods'] ?? []) !== 20 || count($data['hybrids'] ?? []) !== 5) {
                throw new RuntimeException('CURATED_METHOD_PARENT_CATALOG_REQUIRED');
            }
            $parent = $this->declaration();
            // Reuse the parent's exact source declarations and method hashes, never re-version them as v2.
            $data['sources'] = [...$parent['sources'], ...$data['sources']];
            $data['methods'] = [...$parent['methods'], ...$data['methods']];
            $data['hybrids'] = [...$parent['hybrids'], ...$data['hybrids']];
        }
        $cards = $this->instruments->inventory()['instruments'];
        $keys = array_keys($cards); $covered = array_keys($data['coverage'] ?? []); sort($keys); sort($covered);
        if ($keys !== $covered || count($data['methods'] ?? []) !== ($expanded ? 30 : 10)
            || count($data['hybrids'] ?? []) !== ($expanded ? 8 : 3)) throw new RuntimeException('CURATED_METHOD_INVENTORY_COVERAGE_DRIFT');
        foreach ($data['coverage'] as $key => $coverage) {
            if (($coverage['role'] ?? null) !== $cards[$key]['role']
                || ! in_array($coverage['status'] ?? null, ['executable_research_method', 'planned_dependency', 'data_conditional_research_method'], true)
                || ! is_string($coverage['next_proof'] ?? null) || $coverage['next_proof'] === '') throw new RuntimeException('CURATED_METHOD_INVENTORY_COVERAGE_DRIFT');
        }
        if ($expanded) {
            $methodKeys = array_column($data['methods'], 'method_key');
            if (count(array_unique(array_column($data['sources'], 'source_id'))) !== count($data['sources'])) throw new RuntimeException('CURATED_METHOD_SOURCE_ID_COLLISION');
            foreach ($data['coverage'] as $coverage) {
                if (! is_array($coverage['method_refs'] ?? null) || $coverage['method_refs'] === []
                    || array_diff($coverage['method_refs'], $methodKeys) !== [] || ! is_array($coverage['data_dependencies'] ?? null)) {
                    throw new RuntimeException('CURATED_METHOD_EXECUTABLE_COVERAGE_REQUIRED');
                }
            }
        }
        return $data;
    }

    private function sourceInput(array $source): array
    {
        return ['kind' => 'url', 'reference' => $source['reference'], 'content' => $source['summary'],
            'metadata' => ['license' => $source['license'], 'access' => 'public', 'title' => $source['title'],
                'publisher' => $source['publisher'], 'source_id' => $source['source_id'],
                'catalog_key' => $source['_catalog_key'] ?? self::CATALOG_KEY,
                'catalog_sha256' => $source['_catalog_sha256'] ?? self::CATALOG_SHA256,
                'reviewed_on' => '2026-10-13', 'representation' => 'curator_paraphrase_not_page_snapshot',
                'exposure' => ['market_outcomes_in_original_page' => 'unknown', 'prior_agent_access' => 'unknown',
                    'complete_inventory' => false, 'unused_market_data_proven' => false]]];
    }

    private function expectedSourceHash(array $source): string
    {
        $input = $this->sourceInput($source);
        return $this->hashes->parameterHash(['protocol' => InstrumentMethodSourceService::PROTOCOL,
            'kind' => $input['kind'], 'reference' => $input['reference'], 'content_hash' => hash('sha256', $input['content']),
            'metadata' => $input['metadata'], 'untrusted_reference_only' => true, 'qualification_granted' => false, 'promotion_evidence' => false]);
    }

    private function methodInput(array $method, array $sourceHashes): array
    {
        return ['instrument_key' => $method['instrument_key'], 'method_key' => $method['method_key'], 'revision' => $method['revision'],
            'context_predicate' => $method['context_predicate'], 'horizon' => $method['horizon'],
            'known_limitations' => [...$method['known_limitations'], 'Exact rule: '.$method['exact_rule'],
                'Lab rationale: '.$method['parameter_rationale']],
            'source_keys' => array_map(fn (string $id): string => $sourceHashes[$id], $method['source_refs']),
            'implementation' => $method['implementation']];
    }

    private function singleRecipeKey(string $methodKey): string { return $methodKey.'_single'; }
    private function methodRef(array $method): string { return $method['method_key'].'@'.$method['revision']; }
    private function requireStatus(array $result, string $status): void
    {
        if (($result['status'] ?? null) !== $status) throw new RuntimeException($result['reason'] ?? 'CURATED_METHOD_REGISTRATION_REFUSED');
    }
    private function counts(array $catalog): array
    {
        $counts = ['instruments' => count($catalog['coverage']), 'sources' => count($catalog['sources']),
            'construction_adapters' => count(array_filter($catalog['methods'], fn (array $method): bool => $method['implementation']['kind'] === 'parameter_adapter')),
            'method_classes' => count(array_unique(array_column($catalog['methods'], 'method_key'))),
            'method_revisions' => count($catalog['methods']),
            'typed_entry_veto_methods' => count(array_unique(array_column(array_filter($catalog['methods'], fn (array $method): bool => $method['implementation']['kind'] === 'typed_ast' && $method['implementation']['target'] === 'confirmation'), 'method_key'))),
            'typed_entry_veto_revisions' => count(array_filter($catalog['methods'], fn (array $method): bool => $method['implementation']['kind'] === 'typed_ast' && $method['implementation']['target'] === 'confirmation')),
            'hybrids' => count($catalog['hybrids']),
            'explicit_dependencies' => count(array_filter($catalog['coverage'], fn (array $row): bool => $row['status'] === 'planned_dependency'))];
        if ($catalog['catalog_key'] === self::EXPANDED_CATALOG_KEY) {
            $counts['typed_risk_reduction_revisions'] = count(array_filter($catalog['methods'], fn (array $row): bool => ($row['implementation']['target'] ?? null) === 'risk_multiplier'));
            $counts['typed_owned_exit_revisions'] = count(array_filter($catalog['methods'], fn (array $row): bool => ($row['implementation']['target'] ?? null) === 'exit'));
            $counts['data_conditional_instruments'] = count(array_filter($catalog['coverage'], fn (array $row): bool => $row['status'] === 'data_conditional_research_method'));
        }
        return $counts;
    }
    private function base(): array
    {
        return ['protocol' => self::PROTOCOL, 'research_only' => true, 'agent_skill_changed' => false,
            'qualification_granted' => false, 'economic_authority' => false, 'promotion_evidence' => false,
            'paper_authority' => false, 'live_authority' => false];
    }
    private function blocked(string $reason): array
    {
        $safe = preg_match('/^[A-Z][A-Z0-9_]{2,160}$/D', $reason) ? $reason : 'CURATED_METHOD_REGISTRATION_REFUSED';
        return [...$this->base(), 'status' => 'blocked', 'reason' => $safe];
    }
}
