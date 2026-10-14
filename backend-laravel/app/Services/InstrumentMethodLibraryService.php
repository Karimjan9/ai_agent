<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** External method declarations are hypotheses, separate from an agent's earned evidence. */
class InstrumentMethodLibraryService
{
    public const PROTOCOL = 'instrument_method_library_v1';
    /** These values are produced by the closed replay owner, never by supplied CSV columns. */
    public const FEATURE_OWNER = 'closed_instrument_method_features_v1';
    public const FEATURE_BOOL_INPUTS = ['method_history_available', 'method_session_available', 'method_swing_available',
        'method_bos', 'method_choch', 'method_sweep_reclaim', 'method_volume_available', 'method_loss_history_available',
        'method_signal_available', 'method_meta_model_available', 'method_compression_expansion',
        'method_dynamic_cooldown_ready', 'method_regime_aligned', 'method_regime_available', 'method_transition_available', 'method_quote_available',
        'method_band_available', 'method_band_reentry', 'method_pullback_available', 'method_pullback_confirmed',
        'method_retest_available', 'method_retest_confirmed',
        'method_macd_available', 'method_macd_cross', 'method_rsi_available', 'method_rsi_recovery',
        'method_chandelier_available', 'method_chandelier_exit'];
    public const FEATURE_NUMBER_INPUTS = ['method_atr_ratio', 'method_range_atr', 'method_session_range_atr',
        'method_session_distance_atr', 'method_swing_range_atr', 'method_fib_retracement', 'method_sr_distance_atr',
        'method_sr_touch_count', 'method_sr_age', 'method_equal_level_count', 'method_volume_ratio',
        'method_transition_age', 'method_loss_streak', 'method_bars_since_loss', 'method_signal_age',
        'method_signal_survival', 'method_regime_code', 'method_meta_score', 'method_spread_atr'];
    private const CONTEXT_AXES = ['regime', 'session', 'volatility', 'venue_phase', 'spread_liquidity_state',
        'transition_state', 'direction', 'session_instance_id', 'calendar_version'];

    public function __construct(
        private TradingInstrumentOperatingSystemService $instruments,
        private InstrumentMethodSourceService $sources,
        private TypedInstrumentFoundryService $foundry,
        private StrategyParameterSchemaService $parameterSchema,
        private ContextContractV2Service $contexts,
    ) {}

    public function inventory(): array
    {
        $inventory = $this->instruments->inventory();
        $methods = [];
        $recipes = [];
        if ($this->ready()) {
            foreach (DB::table('instrument_method_revisions')->orderBy('method_hash')->get(['method_hash']) as $row) {
                $methods[] = [...$this->inspectMethod($row->method_hash), 'method_hash' => $row->method_hash];
            }
            foreach (DB::table('instrument_recipe_revisions')->orderBy('recipe_hash')->get(['recipe_hash']) as $row) {
                $recipes[] = [...$this->inspectRecipe($row->recipe_hash), 'recipe_hash' => $row->recipe_hash];
            }
        }
        $contract = ['protocol' => self::PROTOCOL, 'instrument_inventory_hash' => $this->hash($inventory),
            'method_hashes' => array_column($methods, 'method_hash'), 'recipe_hashes' => array_column($recipes, 'recipe_hash')];

        return ['protocol' => self::PROTOCOL, 'status' => $this->ready() ? 'read_only_inventory' : 'migration_pending',
            'groups' => $inventory['groups'], 'instruments' => $inventory['instruments'], 'playbooks' => $inventory['playbooks'],
            'methods' => $methods, 'recipes' => $recipes, 'library_hash' => $this->hash($contract),
            'library_contract' => $contract, 'library_contract_json' => $this->encode($contract),
            'qualification_granted' => false, 'promotion_evidence' => false];
    }

    public function registerSource(array $input): array { return $this->sources->registerSource($input); }

    public function registerMethod(array $input): array
    {
        if (! $this->ready()) return $this->blocked('METHOD_LIBRARY_MIGRATION_REQUIRED');
        if ($this->sources->hasAuthorityClaim($input)) return $this->blocked('METHOD_CALLER_AUTHORITY_CLAIM_REFUSED');
        $cards = $this->instruments->inventory()['instruments'];
        $instrument = $input['instrument_key'] ?? null;
        $key = $input['method_key'] ?? null;
        $revision = $input['revision'] ?? null;
        $context = $this->declaredContext($input['context_predicate'] ?? null);
        $limitations = $input['known_limitations'] ?? null;
        $sourceKeys = $input['source_keys'] ?? null;
        if (! is_string($instrument) || ! isset($cards[$instrument]) || ! $this->label($key)
            || ! is_int($revision) || $revision < 1 || $revision > 1000000 || $context === null
            || ! in_array($input['horizon'] ?? null, ['scalp', 'hour', 'day', 'swing', 'position'], true)
            || ! is_array($limitations) || ! array_is_list($limitations) || $limitations === [] || count($limitations) > 16
            || array_filter($limitations, fn ($value) => ! is_string($value) || trim($value) === '' || strlen($value) > 2000) !== []
            || ! is_array($sourceKeys) || ! array_is_list($sourceKeys) || $sourceKeys === [] || count($sourceKeys) > 16
            || count($sourceKeys) !== count(array_unique($sourceKeys, SORT_REGULAR))) {
            return $this->blocked('METHOD_IDENTITY_USAGE_CONTEXT_AND_SOURCES_REQUIRED');
        }
        $card = $cards[$instrument];
        if (in_array($context['regime'], $card['failure_conditions']['forbidden_regimes'], true)) {
            return $this->blocked('METHOD_INSTRUMENT_CONTEXT_FORBIDDEN');
        }
        foreach ($sourceKeys as $sourceKey) {
            if (! $this->sha($sourceKey) || ($this->sources->inspectSource($sourceKey)['status'] ?? null) !== 'registered_reference_only') {
                return $this->blocked('METHOD_ORIGINAL_SOURCE_REQUIRED');
            }
        }
        sort($sourceKeys);
        $implementation = $this->implementation((array) ($input['implementation'] ?? []), $card);
        if (isset($implementation['reason'])) return $this->blocked($implementation['reason']);
        $contract = ['protocol' => 'instrument_method_contract_v1', 'instrument_key' => $instrument,
            'method_key' => $key, 'revision' => $revision, 'context_predicate' => $context, 'horizon' => $input['horizon'],
            'known_limitations' => array_values(array_unique([...$limitations, ...$card['runtime_capability']['limitations']])),
            'source_keys' => $sourceKeys, 'implementation' => $implementation,
            'instrument_capability_hash' => $this->hash($card['runtime_capability']),
            'usage_contract' => [...$card['usage_contract'], 'source_text_is_untrusted' => true,
                'baseline_code_facts_are_not_qualification' => true, 'qualification_granted' => false],
            'research_only' => true, 'qualification_granted' => false, 'promotion_evidence' => false];
        $hash = $this->hash($contract);

        return DB::transaction(function () use ($instrument, $key, $revision, $contract, $hash, $sourceKeys): array {
            $existing = DB::table('instrument_method_revisions')->where('instrument_key', $instrument)
                ->where('method_key', $key)->where('revision', $revision)->lockForUpdate()->first();
            if ($existing && $existing->method_hash !== $hash) return $this->blocked('METHOD_IMMUTABLE_REVISION_COLLISION');
            if (! $existing) DB::table('instrument_method_revisions')->insert(['method_hash' => $hash,
                'instrument_key' => $instrument, 'method_key' => $key, 'revision' => $revision,
                'contract' => $this->encode($contract), 'source_keys' => $this->encode($sourceKeys),
                'created_at' => now(), 'updated_at' => now()]);
            return $this->inspectMethod($hash);
        });
    }

    public function inspectMethod(string $hash): array
    {
        if (! $this->ready()) return $this->blocked('METHOD_LIBRARY_MIGRATION_REQUIRED');
        $row = DB::table('instrument_method_revisions')->where('method_hash', $hash)->first();
        if (! $row) return $this->blocked('METHOD_REVISION_NOT_FOUND');
        try { $contract = json_decode($row->contract, true, 64, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { return $this->blocked('METHOD_IMMUTABLE_CONTRACT_DRIFT'); }
        if (! is_array($contract) || ! hash_equals($hash, $this->hash($contract))
            || ($contract['instrument_key'] ?? null) !== $row->instrument_key
            || ($contract['method_key'] ?? null) !== $row->method_key || ($contract['revision'] ?? null) !== (int) $row->revision
            || $this->encode((array) ($contract['source_keys'] ?? [])) !== $this->encode((array) json_decode($row->source_keys, true))) {
            return $this->blocked('METHOD_IMMUTABLE_CONTRACT_DRIFT');
        }
        $card = $this->instruments->inventory()['instruments'][$row->instrument_key] ?? null;
        if (! $card || ($contract['instrument_capability_hash'] ?? null) !== $this->hash($card['runtime_capability'])) {
            return $this->blocked('METHOD_INSTRUMENT_CAPABILITY_DRIFT');
        }
        foreach ($contract['source_keys'] as $sourceKey) {
            if (($this->sources->inspectSource($sourceKey)['status'] ?? null) !== 'registered_reference_only') {
                return $this->blocked('METHOD_ORIGINAL_SOURCE_DRIFT');
            }
        }
        return ['status' => 'registered_research_only', 'method_hash' => $hash, 'contract' => $contract,
            'contract_json' => $this->encode($contract), 'qualification_granted' => false, 'promotion_evidence' => false];
    }

    public function registerRecipe(array $input): array
    {
        if (! $this->ready()) return $this->blocked('METHOD_LIBRARY_MIGRATION_REQUIRED');
        if ($this->sources->hasAuthorityClaim($input)) return $this->blocked('METHOD_CALLER_AUTHORITY_CLAIM_REFUSED');
        $key = $input['recipe_key'] ?? null;
        $revision = $input['revision'] ?? null;
        $hashes = $input['method_hashes'] ?? null;
        $context = $this->declaredContext($input['context_predicate'] ?? null);
        if (! $this->label($key) || ! is_int($revision) || $revision < 1 || $revision > 1000000 || $context === null
            || ! is_array($hashes) || ! array_is_list($hashes) || $hashes === [] || count($hashes) > 8
            || count($hashes) !== count(array_unique($hashes, SORT_REGULAR))) return $this->blocked('METHOD_RECIPE_EXACT_REVISIONS_REQUIRED');
        sort($hashes);
        $methods = [];
        $horizons = [];
        foreach ($hashes as $hash) {
            if (! $this->sha($hash)) return $this->blocked('METHOD_RECIPE_EXACT_REVISIONS_REQUIRED');
            $method = $this->inspectMethod($hash);
            if (($method['status'] ?? null) !== 'registered_research_only') return $this->blocked('METHOD_RECIPE_SOURCE_REVISION_INVALID');
            if ($method['contract']['context_predicate'] !== $context) return $this->blocked('METHOD_RECIPE_CONTEXT_BROADENING_REFUSED');
            $methods[$hash] = $method['contract'];
            $horizons[] = $method['contract']['horizon'];
        }
        if (count(array_unique($horizons)) !== 1) return $this->blocked('METHOD_RECIPE_HORIZON_MISMATCH');
        $compatibility = $this->compatibleMethods($methods);
        if ($compatibility !== null) return $this->blocked($compatibility);
        $contract = ['protocol' => 'instrument_method_recipe_v1', 'recipe_key' => $key, 'revision' => $revision,
            'method_hashes' => $hashes, 'method_contracts' => $methods, 'context_predicate' => $context,
            'horizon' => $horizons[0], 'hybrid' => count($hashes) > 1, 'research_only' => true,
            'qualification_granted' => false, 'promotion_evidence' => false];
        $hash = $this->hash($contract);
        return DB::transaction(function () use ($key, $revision, $contract, $hash): array {
            $existing = DB::table('instrument_recipe_revisions')->where('recipe_key', $key)->where('revision', $revision)->lockForUpdate()->first();
            if ($existing && $existing->recipe_hash !== $hash) return $this->blocked('METHOD_RECIPE_IMMUTABLE_REVISION_COLLISION');
            if (! $existing) DB::table('instrument_recipe_revisions')->insert(['recipe_hash' => $hash,
                'recipe_key' => $key, 'revision' => $revision, 'contract' => $this->encode($contract), 'created_at' => now(), 'updated_at' => now()]);
            return $this->inspectRecipe($hash);
        });
    }

    public function inspectRecipe(string $hash): array
    {
        if (! $this->ready()) return $this->blocked('METHOD_LIBRARY_MIGRATION_REQUIRED');
        $row = DB::table('instrument_recipe_revisions')->where('recipe_hash', $hash)->first();
        if (! $row) return $this->blocked('METHOD_RECIPE_NOT_FOUND');
        try { $contract = json_decode($row->contract, true, 64, JSON_THROW_ON_ERROR); }
        catch (\Throwable) { return $this->blocked('METHOD_RECIPE_IMMUTABLE_CONTRACT_DRIFT'); }
        if (! is_array($contract) || $this->hash($contract) !== $hash || ($contract['recipe_key'] ?? null) !== $row->recipe_key
            || ($contract['revision'] ?? null) !== (int) $row->revision) return $this->blocked('METHOD_RECIPE_IMMUTABLE_CONTRACT_DRIFT');
        foreach ((array) ($contract['method_hashes'] ?? []) as $methodHash) {
            $method = $this->inspectMethod($methodHash);
            if (($method['status'] ?? null) !== 'registered_research_only'
                || $this->hash((array) ($contract['method_contracts'][$methodHash] ?? [])) !== $methodHash
                || $method['contract'] !== $contract['method_contracts'][$methodHash]) return $this->blocked('METHOD_RECIPE_PINNED_METHOD_DRIFT');
        }
        return ['status' => 'registered_research_only', 'recipe_hash' => $hash, 'contract' => $contract,
            'contract_json' => $this->encode($contract), 'qualification_granted' => false, 'promotion_evidence' => false];
    }

    /** Freeze a selected intrinsic library; data and evaluator identities belong to later compilation. */
    public function librarySnapshot(array $recipeHashes): array
    {
        if ($recipeHashes === [] || count($recipeHashes) > 32 || ! array_is_list($recipeHashes)
            || count($recipeHashes) !== count(array_unique($recipeHashes, SORT_REGULAR))) return $this->blocked('METHOD_LIBRARY_BOUNDED_RECIPES_REQUIRED');
        sort($recipeHashes);
        $methods = [];
        foreach ($recipeHashes as $hash) {
            if (! $this->sha($hash)) return $this->blocked('METHOD_LIBRARY_BOUNDED_RECIPES_REQUIRED');
            $recipe = $this->inspectRecipe($hash);
            if (($recipe['status'] ?? null) !== 'registered_research_only') return $this->blocked('METHOD_LIBRARY_RECIPE_INVALID');
            $methods = [...$methods, ...$recipe['contract']['method_hashes']];
        }
        $methods = array_values(array_unique($methods));
        sort($methods);
        return $this->verifyLibrary(['protocol' => self::PROTOCOL, 'instrument_inventory_hash' => $this->hash($this->instruments->inventory()),
            'method_hashes' => $methods, 'recipe_hashes' => $recipeHashes]);
    }

    public function verifyLibrary(array $snapshot): array
    {
        $contract = (array) ($snapshot['library_contract'] ?? $snapshot);
        if (($contract['protocol'] ?? null) !== self::PROTOCOL
            || ($contract['instrument_inventory_hash'] ?? null) !== $this->hash($this->instruments->inventory())
            || ! $this->hashList($contract['method_hashes'] ?? null, 256) || ! $this->hashList($contract['recipe_hashes'] ?? null, 32)
            || isset($snapshot['library_hash']) && $snapshot['library_hash'] !== $this->hash($contract)
            || isset($snapshot['library_contract_json']) && $snapshot['library_contract_json'] !== $this->encode($contract)) {
            return $this->blocked('METHOD_LIBRARY_SNAPSHOT_IDENTITY_INVALID');
        }
        foreach ($contract['method_hashes'] as $hash) {
            if (! $this->sha($hash) || ($this->inspectMethod($hash)['status'] ?? null) !== 'registered_research_only') return $this->blocked('METHOD_LIBRARY_METHOD_INVALID');
        }
        foreach ($contract['recipe_hashes'] as $hash) {
            if (! $this->sha($hash)) return $this->blocked('METHOD_LIBRARY_RECIPE_INVALID');
            $recipe = $this->inspectRecipe($hash);
            if (($recipe['status'] ?? null) !== 'registered_research_only'
                || array_diff($recipe['contract']['method_hashes'], $contract['method_hashes']) !== []) return $this->blocked('METHOD_LIBRARY_RECIPE_INVALID');
        }
        return ['status' => 'verified_library_snapshot', 'valid' => true, 'library_hash' => $this->hash($contract),
            'library_contract' => $contract, 'library_contract_json' => $this->encode($contract), 'qualification_granted' => false,
            'promotion_evidence' => false];
    }

    /** Compile only after exact baseline, data, execution and declared context are bound. */
    public function compileRecipe(string $recipeHash, array $baseline, array $scope): array
    {
        $recipe = $this->inspectRecipe($recipeHash);
        if (($recipe['status'] ?? null) !== 'registered_research_only') return $recipe;
        $context = $this->declaredContext($scope['context_predicate'] ?? null);
        $family = $baseline['strategy_family'] ?? null;
        $parameters = $baseline['parameters'] ?? null;
        if (! $this->sha($scope['data_hash'] ?? null) || ! $this->sha($scope['execution_hash'] ?? null)
            || ($scope['pre_2026_only'] ?? false) !== true || ! is_string($scope['symbol'] ?? null)
            || ! preg_match('/^[A-Z0-9]{3,20}$/', $scope['symbol']) || ! in_array($scope['timeframe'] ?? null, ['M1', 'M5', 'M15', 'M30', 'H1', 'H4', 'D1'], true)
            || $context === null || $context !== $recipe['contract']['context_predicate'] || ! is_string($family)
            || ! is_array($parameters) || $parameters === [] || $this->sources->hasAuthorityClaim($scope)) return $this->blocked('METHOD_RECIPE_PREOUTCOME_BINDING_REQUIRED');
        try { $this->parameterSchema->validate($family, $parameters); }
        catch (\InvalidArgumentException) { return $this->blocked('METHOD_RECIPE_BASELINE_SCHEMA_INVALID'); }
        $operators = [];
        $adapters = [];
        $compiledParameters = $parameters;
        $scopeBindings = ['symbol' => $scope['symbol'], 'timeframe' => $scope['timeframe'],
            'data_hash' => $scope['data_hash'], 'execution_hash' => $scope['execution_hash'], 'pre_2026_only' => true,
            'baseline_hash' => $this->hash(['strategy_family' => $this->parameterSchema->family($family),
                'parameters' => $this->parameterSchema->canonicalizeForIdentity($family, $parameters)]),
            'context_hash' => $this->hash($context)];
        foreach ($recipe['contract']['method_contracts'] as $method) {
            if (array_intersect(array_values((array) ($method['implementation']['input_bindings'] ?? [])),
                [...self::FEATURE_BOOL_INPUTS, ...self::FEATURE_NUMBER_INPUTS]) !== []) {
                $scopeBindings['method_feature_owner'] = self::FEATURE_OWNER;
                break;
            }
        }
        // Transaction prevents a refused hybrid from leaving partially compiled program rows.
        try {
            return DB::transaction(function () use ($recipe, $recipeHash, $family, $parameters, $compiledParameters, $scopeBindings, $context, $operators, $adapters): array {
                foreach ($recipe['contract']['method_contracts'] as $methodHash => $method) {
                    $implementation = $method['implementation'];
                    if ($implementation['kind'] === 'parameter_adapter') {
                        if ($implementation['strategy_family'] !== $this->parameterSchema->family($family)) throw new \RuntimeException('METHOD_RECIPE_ADAPTER_FAMILY_MISMATCH');
                        foreach ($implementation['parameters'] as $gene => $value) {
                            if (! array_key_exists($gene, $parameters)) throw new \RuntimeException('METHOD_RECIPE_ADAPTER_BASELINE_GENE_REQUIRED');
                            $this->assertRiskPreserved($gene, $parameters[$gene], $value);
                            $compiledParameters[$gene] = $value;
                        }
                        $adapters[] = ['method_hash' => $methodHash, 'instrument_key' => $method['instrument_key'],
                            'strategy_family' => $implementation['strategy_family'], 'parameters' => $implementation['parameters']];
                        continue;
                    }
                    $program = $this->foundry->compileResearchCandidate($implementation['ast'], $scopeBindings, 'method:'.$methodHash);
                    if (! in_array($program['status'] ?? null, ['compiled_research_only', 'semantic_duplicate'], true)) throw new \RuntimeException($program['reason'] ?? 'METHOD_RECIPE_FOUNDRY_REFUSED');
                    // Foundry semantic duplicates are scope-checked here before their export is reused.
                    $row = DB::table('research_instrument_programs')->where('program_key', $program['program_key'])->first();
                    $originalContext = (array) data_get(json_decode($row->evidence, true), 'context', []);
                    foreach (array_keys($scopeBindings) as $axis) {
                        if (($originalContext[$axis] ?? null) !== $scopeBindings[$axis]) throw new \RuntimeException('METHOD_RECIPE_FOUNDRY_SCOPE_DRIFT');
                    }
                    $export = $this->foundry->decisionOperatorContract($program['program_key'], $implementation['target'], $implementation['input_bindings'], $implementation['budget']);
                    if (($export['status'] ?? null) !== 'native_decision_contract') throw new \RuntimeException($export['reason'] ?? 'METHOD_RECIPE_OPERATOR_EXPORT_REFUSED');
                    $operators[] = $export['operator'];
                }
                $this->parameterSchema->validate($family, $compiledParameters);
                $runtime = ['protocol' => 'instrument_method_recipe_runtime_v1', 'recipe_key' => $recipe['contract']['recipe_key'],
                    'recipe_hash' => $recipeHash, 'recipe_revision' => $recipe['contract'], 'recipe_revision_json' => $recipe['contract_json'],
                    'context_predicate' => $context, 'operators' => $operators, 'parameter_adapters' => $adapters,
                    'scope_bindings' => $scopeBindings];
                return ['protocol' => self::PROTOCOL, 'status' => $operators === [] ? 'parameter_adapter_recipe' : 'compiled_research_recipe',
                    'runtime_recipe' => [...$runtime, 'runtime_hash' => $this->hash($runtime), 'runtime_json' => $this->encode($runtime)],
                    'parameters' => $compiledParameters, 'qualification_granted' => false, 'promotion_evidence' => false];
            });
        } catch (\Illuminate\Database\QueryException) {
            return $this->blocked('METHOD_RECIPE_COMPILE_DATABASE_REFUSAL');
        } catch (\RuntimeException $error) { return $this->blocked($error->getMessage()); }
    }

    private function compatibleMethods(array $methods): ?string
    {
        $targets = [];
        $parameters = [];
        $family = null;
        foreach ($methods as $method) {
            $implementation = $method['implementation'];
            if ($implementation['kind'] === 'typed_ast') {
                if (in_array($implementation['target'], $targets, true)) return 'METHOD_RECIPE_OPERATOR_TARGET_COLLISION';
                $targets[] = $implementation['target'];
            } else {
                if ($family !== null && $family !== $implementation['strategy_family']) return 'METHOD_RECIPE_ADAPTER_FAMILY_MISMATCH';
                $family = $implementation['strategy_family'];
                foreach ($implementation['parameters'] as $gene => $value) {
                    if (array_key_exists($gene, $parameters) && $parameters[$gene] !== $value) return 'METHOD_RECIPE_PARAMETER_COLLISION';
                    $parameters[$gene] = $value;
                }
            }
        }
        return null;
    }

    private function implementation(array $implementation, array $card): array
    {
        if (($implementation['kind'] ?? null) === 'parameter_adapter') {
            $family = $implementation['strategy_family'] ?? null;
            $parameters = $implementation['parameters'] ?? null;
            if (! is_string($family) || ! is_array($parameters) || $parameters === [] || count($parameters) > 16
                || array_diff(array_keys($parameters), $card['runtime_capability']['parameter_surface']) !== []) return ['reason' => 'METHOD_EXACT_LEGAL_PARAMETER_ADAPTER_REQUIRED'];
            try { $this->parameterSchema->validate($family, $parameters); }
            catch (\InvalidArgumentException) { return ['reason' => 'METHOD_EXACT_LEGAL_PARAMETER_ADAPTER_REQUIRED']; }
            foreach ($parameters as $gene => $value) {
                if ($this->protectedBoolean($gene) && $value === false || $gene === 'state_machine_variant' && $value === 'none') return ['reason' => 'METHOD_RISK_GUARD_DISABLE_REFUSED'];
            }
            return ['kind' => 'parameter_adapter', 'strategy_family' => $this->parameterSchema->family($family), 'parameters' => $parameters];
        }
        if (($implementation['kind'] ?? null) !== 'typed_ast' || ! is_array($implementation['ast'] ?? null)
            || ! in_array($implementation['target'] ?? null, ['confirmation', 'risk_multiplier', 'exit'], true)
            || ! is_array($implementation['input_bindings'] ?? null) || ! is_array($implementation['budget'] ?? null)) return ['reason' => 'METHOD_BOUNDED_TYPED_AST_OR_ADAPTER_REQUIRED'];
        if (array_diff(array_keys($implementation), ['kind', 'ast', 'target', 'input_bindings', 'budget']) !== []) return ['reason' => 'METHOD_TYPED_AST_UNSUPPORTED_OR_UNBOUND'];
        if (count($implementation['input_bindings']) > 16 || array_diff(array_values($implementation['input_bindings']),
            ['close', '_management_atr', 'signal_confidence', 'volume', 'volume_available', 'risk_veto', 'news_veto',
                ...self::FEATURE_BOOL_INPUTS, ...self::FEATURE_NUMBER_INPUTS]) !== []) return ['reason' => 'METHOD_TYPED_AST_UNSUPPORTED_OR_UNBOUND'];
        $budget = $implementation['budget'];
        if (! is_numeric($budget['cpu_seconds'] ?? null) || $budget['cpu_seconds'] <= 0 || $budget['cpu_seconds'] > 1
            || ! is_int($budget['max_calls'] ?? null) || $budget['max_calls'] < 1 || $budget['max_calls'] > 100000
            || ! is_int($budget['max_node_evaluations'] ?? null) || $budget['max_node_evaluations'] < 1 || $budget['max_node_evaluations'] > 4800000) return ['reason' => 'METHOD_TYPED_COMPUTE_BUDGET_INVALID'];
        $nodes = 0;
        $type = $this->astType($implementation['ast'], $implementation['input_bindings'], $nodes);
        if ($type === null || $nodes > 48 || $type !== ($implementation['target'] === 'risk_multiplier' ? 'number' : 'bool')) return ['reason' => 'METHOD_TYPED_AST_UNSUPPORTED_OR_UNBOUND'];
        if ($implementation['target'] === 'risk_multiplier' && ($implementation['ast']['op'] ?? null) === 'CONST'
            && ($implementation['ast']['value'] <= 0 || $implementation['ast']['value'] > 1)) return ['reason' => 'METHOD_RISK_INCREASE_OR_DISABLE_REFUSED'];
        $implementation['budget'] = array_intersect_key($budget, array_flip(['cpu_seconds', 'max_calls', 'max_node_evaluations']));
        return array_intersect_key($implementation, array_flip(['kind', 'ast', 'target', 'input_bindings', 'budget']));
    }

    private function astType(array $node, array $bindings, int &$count): ?string
    {
        if (++$count > 48) return null;
        $op = $node['op'] ?? null;
        if (! is_string($op) || $op !== strtoupper($op) || array_diff(array_keys($node), ['op', 'args', 'type', 'value', 'available_at', 'input_key']) !== []) return null;
        if ($op === 'CONST') {
            if (array_diff(array_keys($node), ['op', 'type', 'value']) !== []) return null;
            if (($node['type'] ?? null) === 'bool' && is_bool($node['value'] ?? null)) return 'bool';
            return ($node['type'] ?? null) === 'number' && (is_int($node['value'] ?? null) || is_float($node['value'] ?? null))
                && is_finite((float) $node['value']) ? 'number' : null;
        }
        $leafTypes = ['PRICE_CLOSE' => 'price', 'ATR' => 'atr', 'BOOL' => 'bool', 'NUMBER' => 'number'];
        if (isset($leafTypes[$op])) {
            if (array_diff(array_keys($node), ['op', 'available_at', 'input_key']) !== []) return null;
            $key = $node['input_key'] ?? strtolower($op);
            $typedBindings = ['PRICE_CLOSE' => ['close'], 'ATR' => ['_management_atr'],
                'BOOL' => ['volume_available', 'risk_veto', 'news_veto', ...self::FEATURE_BOOL_INPUTS],
                'NUMBER' => ['signal_confidence', 'volume', ...self::FEATURE_NUMBER_INPUTS]];
            if (! is_string($key) || ! is_string($bindings[$key] ?? null)
                || ! in_array($bindings[$key], $typedBindings[$op], true)
                || ! is_string($node['available_at'] ?? null) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $node['available_at'])) return null;
            try { new \DateTimeImmutable($node['available_at']); } catch (\Throwable) { return null; }
            return $leafTypes[$op];
        }
        if (! is_array($node['args'] ?? null) || ! array_is_list($node['args']) || count($node['args']) > 16) return null;
        $types = [];
        foreach ($node['args'] as $arg) {
            if (! is_array($arg) || ($type = $this->astType($arg, $bindings, $count)) === null) return null;
            $types[] = $type;
        }
        $valid = match ($op) {
            'GREATER_THAN', 'LESS_THAN' => count($types) === 2 && count(array_diff($types, ['price', 'atr', 'number'])) === 0,
            'AND', 'OR', 'CONFIRMED_BY' => count($types) >= 2 && ($op !== 'CONFIRMED_BY' || count($types) === 2) && array_diff($types, ['bool']) === [],
            'NOT' => $types === ['bool'], default => false,
        };
        return $valid ? 'bool' : null;
    }

    private function assertRiskPreserved(string $gene, mixed $old, mixed $new): void
    {
        if ($this->protectedBoolean($gene) && $old === true && $new === false || $gene === 'state_machine_variant' && $old !== 'none' && $new === 'none') throw new \RuntimeException('METHOD_RISK_GUARD_DISABLE_REFUSED');
        $upperLimits = ['max_spread_atr_ratio', 'temporal_spread_atr_ratio_max', 'temporal_volatility_ratio_max',
            'temporal_loss_streak_limit', 'max_loss_streak_before_wait', 'signal_max_age_candles', 'temporal_drift_zscore_max'];
        $floors = ['minimum_confidence', 'minimum_signal_confidence', 'differential_target_min_signal_confidence',
            'temporal_confidence_decay_floor', 'temporal_followthrough_min_rate', 'loss_cooldown_candles',
            'loss_streak_wait_candles', 'transition_wait_candles'];
        if (in_array($gene, $upperLimits, true) && $new > $old || in_array($gene, $floors, true) && $new < $old
            || str_ends_with($gene, 'risk_multiplier') && $new > $old) throw new \RuntimeException('METHOD_RISK_LIMIT_WEAKENING_REFUSED');
    }

    private function protectedBoolean(string $gene): bool
    {
        return in_array($gene, ['transition_firewall_enabled', 'avoid_high_volatility', 'high_volatility_wait',
            'confidence_calibration_enabled', 'confidence_ev_lower_bound_enabled', 'temporal_survival_enabled',
            'adaptive_signal_expiry_enabled', 'drift_abstention_enabled', 'dynamic_cooldown_enabled', 'meta_label_enabled'], true);
    }

    private function declaredContext(mixed $raw): ?array
    {
        if (! is_array($raw) || array_diff(array_keys($raw), self::CONTEXT_AXES) !== []
            || array_diff(['regime', 'session', 'volatility'], array_keys($raw)) !== []) return null;
        $axes = $this->contexts->canonicalDeclaredAxes($raw);
        if (count($axes) !== count($raw)) return null;
        ksort($axes);
        return $axes;
    }

    private function ready(): bool { return Schema::hasTable('instrument_method_revisions') && Schema::hasTable('instrument_recipe_revisions') && Schema::hasTable('instrument_method_sources'); }
    private function hashList(mixed $values, int $maximum): bool
    {
        if (! is_array($values) || ! array_is_list($values) || count($values) > $maximum) return false;
        foreach ($values as $value) if (! $this->sha($value)) return false;
        $sorted = array_values(array_unique($values));
        sort($sorted);
        return $values === $sorted;
    }
    private function label(mixed $value): bool { return is_string($value) && preg_match('/^[A-Za-z0-9_.:-]{1,100}$/', $value); }
    private function sha(mixed $value): bool { return is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value); }
    private function canonicalize(mixed $value): mixed { if (! is_array($value)) return $value; if (! array_is_list($value)) ksort($value); foreach ($value as $key => $item) $value[$key] = $this->canonicalize($item); return $value; }
    private function encode(array $value): string { return json_encode($this->canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR); }
    private function hash(array $value): string { return hash('sha256', $this->encode($value)); }
    private function blocked(string $reason): array { return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'valid' => false, 'reason' => $reason, 'qualification_granted' => false, 'promotion_evidence' => false]; }
}
