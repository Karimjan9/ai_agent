<?php
/** Twelve prospective requests through actual canonical owners in SQLite memory.
 * No dotenv, application bootstrap, production DB, dispatch, replay or outcome.
 */
declare(strict_types=1);

function recipeNeed(bool $ok, string $reason): void { if (! $ok) throw new LogicException($reason); }
function recipeEncode(array $value): string {
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
}
function recipeStatus(array $result, string $expected): array {
    recipeNeed(($result['status'] ?? null) === $expected, $result['reason'] ?? 'CANONICAL_'.$expected.'_REQUIRED');
    return $result;
}
function methodsRead(string $path, string $expected, string $reason): array {
    recipeNeed(is_file($path) && hash_file('sha256', $path) === $expected, $reason);
    return json_decode(file_get_contents($path), true, 256, JSON_THROW_ON_ERROR);
}
recipeNeed(count($argv) === 5 && $argv[1] === '--windows' && $argv[3] === '--windows-sha256',
    'EXACT_WINDOWS_IDENTITY_ARGUMENTS_REQUIRED');
$root = dirname(__DIR__, 2); $backend = $root.'/backend-laravel';
$manifestPath = realpath($argv[2]); $manifestSha = $argv[4];
recipeNeed($manifestPath !== false && $manifestSha === 'd8aa2d60c3cc4fbcb0bb3f2768dd7cc6b66e54d03e16f41056c7fae174b7fd9a',
    'EXACT_FIXED_TWO_MONTH_MANIFEST_REQUIRED');
$manifest = methodsRead($manifestPath, $manifestSha, 'EXACT_FIXED_TWO_MONTH_MANIFEST_REQUIRED');
recipeNeed(($manifest['protocol'] ?? null) === 'curator_intraday_replication_windows_v1'
    && array_keys($manifest['windows'] ?? []) === ['june2025', 'september2025']
    && ($manifest['timestamp_semantics'] ?? null) === 'original_M5_open_in_UTC_decision_inputs_available_only_after_closure',
    'FIXED_COMPLETE_TWO_MONTH_ROSTER_REQUIRED');
foreach (['june2025' => [5989, 5789, '494cd01a799905f85fcc864c6b75904898aaf0326283a137f7f255ba18a08a02'],
    'september2025' => [6241, 6041, 'bef23cd40a5faab063dd337ea2218db3c192af9a3c44c18534f46d494591d7f8']] as $key => [$loaded, $evaluated, $sha]) {
    $window = $manifest['windows'][$key];
    recipeNeed($window['loaded_rows'] === $loaded && $window['warmup_rows'] === 200
        && $window['evaluated_rows'] === $evaluated && $window['sha256'] === $sha
        && hash_file('sha256', $window['path']) === $sha
        && $window['calendar_audit']['unexpected_missing_count'] === 0,
        'EXACT_FULL_MONTH_AND_REAL_WARMUP_BYTES_REQUIRED:'.$key);
}
$sourceReviewPath = $root.'/docs/research/instruments/range_reentry/events/2026-10-16-article-review.json';
$sourceReviewSha = 'ed68b747e61d659e9f59912684f217c70b605de5a9f904645281309f9e5ebba7';
methodsRead($sourceReviewPath, $sourceReviewSha, 'ORIGINAL_REVIEWED_ARTICLE_EVENT_REQUIRED');
$methodSourcesPath = $root.'/docs/research/methods/intraday-completion/sources.json';
recipeNeed(is_file($methodSourcesPath), 'CURATOR_METHOD_SOURCE_REVIEW_REQUIRED');
$methodSourcesSha = hash_file('sha256', $methodSourcesPath);
methodsRead($methodSourcesPath, $methodSourcesSha, 'EXACT_CURATOR_METHOD_SOURCE_REVIEW_REQUIRED');
$controlPath = dirname($root).'/curator-context-research-20261015/.runtime/context-practice-completion-20261015/before-outcome-spec.json';
$controlSha = '5c3ac482449898e5c1d54012bc583b14125f289caa9059640558e5576e99a8c4';
$control = methodsRead($controlPath, $controlSha, 'ORIGINAL_FROZEN_ACCOUNT_COST_CONTROL_REQUIRED')['baselines']['october_pre_dst'];

// Reuse installed framework dependencies, but never their App class map. The
// actual source classes below must all resolve to this isolated checkout.
$autoload = is_file($backend.'/vendor/autoload.php') ? $backend.'/vendor/autoload.php'
    : dirname($root, 2).'/backend-laravel/vendor/autoload.php';
recipeNeed(is_file($autoload), 'INSTALLED_FRAMEWORK_AUTOLOADER_REQUIRED');
$loader = require $autoload; $overrides = [];
foreach ($loader->getClassMap() as $class => $file) {
    if (str_starts_with($class, 'App\\')) {
        $local = $backend.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
        if (is_file($local)) $overrides[$class] = $local;
    }
}
$loader->addPsr4('App\\', $backend.'/app', true); $loader->addClassMap($overrides);
$app = new Illuminate\Foundation\Application($backend);
$app->instance('config', new Illuminate\Config\Repository([
    'app' => ['env' => 'testing', 'timezone' => 'UTC', 'debug' => false],
    'database' => ['default' => 'sqlite', 'connections' => ['sqlite' => [
        'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
    ]]],
    'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
]));
$app->register(Illuminate\Database\DatabaseServiceProvider::class);
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$connection = Illuminate\Support\Facades\DB::connection();
recipeNeed($connection->getDriverName() === 'sqlite'
    && $connection->getConfig('database') === ':memory:'
    && count(config('database.connections')) === 1, 'MEMORY_ONLY_DATABASE_REQUIRED');
$databaseList = $connection->select('PRAGMA database_list');
recipeNeed(count($databaseList) === 1 && $databaseList[0]->name === 'main' && $databaseList[0]->file === '', 'NO_DATABASE_FILE_ALLOWED');
foreach (['2026_09_07_150000_create_neurotrader_lab_expansion_tables.php',
    '2026_10_13_120000_create_instrument_method_and_agent_learning.php',
    '2026_10_13_121000_scope_research_method_program_identity.php'] as $migration) {
    (require $backend.'/database/migrations/'.$migration)->up();
}

$requiredOwners = [App\Services\StrategyParameterSchemaService::class, App\Services\ExecutionContractService::class,
    App\Services\ResearchPaperEpochContractService::class, App\Services\InstrumentMethodLibraryService::class,
    App\Services\InstrumentMethodResearchBindingService::class, App\Services\AgentMethodLearningService::class,
    App\Services\InstrumentMethodSourceService::class, App\Services\TypedInstrumentFoundryService::class,
    App\Services\TradingInstrumentOperatingSystemService::class, App\Services\ContextContractV2Service::class,
    App\Services\MarketSessionCalendarService::class];
$loadedOwners = [];
foreach ($requiredOwners as $class) {
    $file = realpath((new ReflectionClass($class))->getFileName());
    recipeNeed(str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', realpath($backend.'/app')).'/'),
        'ACTUAL_ISOLATED_OWNER_SOURCE_REQUIRED');
    $loadedOwners[$class] = ['path' => $file, 'sha256' => hash_file('sha256', $file)];
}
$schema = $app->make(App\Services\StrategyParameterSchemaService::class);
$execution = $app->make(App\Services\ExecutionContractService::class);
$hashes = $app->make(App\Services\ResearchPaperEpochContractService::class);
$library = $app->make(App\Services\InstrumentMethodLibraryService::class);
$agents = $app->make(App\Services\AgentMethodLearningService::class);
$binding = $app->make(App\Services\InstrumentMethodResearchBindingService::class);

recipeNeed(! isset($control['policy_context']) && $control['initial_balance'] === 10000.0
    && $control['risk_per_trade'] === 1.0 && $control['timeframe'] === 'M5'
    && $control['execution_contract']['parameters'] === $control['execution']
    && $control['execution_contract']['execution_hash'] === $execution->hashParameters($control['execution']),
    'ORIGINAL_ACCOUNT_RISK_AND_EXECUTION_CONTRACT_REQUIRED');
$source = recipeStatus($library->registerSource(['kind' => 'imported_text',
    'reference' => 'https://www.bollingerbands.com/_files/ugd/58be43_d0481bfee7424c91a089d4f0a7383133.pdf',
    'content' => 'Curator extraction: the reviewed original Bollinger article divides variance by N. '
        .'This study uses closed20 close prices. A completed close at or beyond its20-close mean requests a directional owned exit. '
        .'That exit, existing recross, UTC session, six-row deadline and ATR protective orders are an explicit laboratory bundle, not author Methods I-IV.',
    'metadata' => ['license' => 'original curator summary; public author reference not redistributed', 'access' => 'public',
        'exposure' => ['reviewed_event_sha256' => $sourceReviewSha, 'reused_archive' => true],
        'reviewed_event_path' => $sourceReviewPath, 'source_method_boundary' => 'lab_recipe_not_author_full_method']]), 'registered_reference_only');
$recipes = $rules = $declaredContexts = [];
foreach (['trend_up', 'trend_down', 'range'] as $regime) {
    foreach (['low', 'normal', 'high'] as $volatility) {
        $preferred = [];
        foreach (['london', 'overlap'] as $session) {
            $context = ['regime' => $regime, 'session' => $session, 'volatility' => $volatility];
            $key = 'curator_midline20_'.$regime.'_'.$volatility.'_'.$session.'_v1';
            $method = recipeStatus($library->registerMethod(['instrument_key' => 'cost_aware_exit', 'method_key' => $key,
                'revision' => 1, 'context_predicate' => $context, 'horizon' => 'scalp', 'source_keys' => [$source['source_key']],
                'known_limitations' => [
                    'Explicit lab recipe, not a full reproduction of author Methods I-IV or a proven economic skill.',
                    'Only original completed20-close mean and actual held direction; BUY close>=mean or SELL close<=mean, including equality.',
                    'Exact known trend_up/trend_down/range, low/normal/high and London/overlap contexts only. Other/unknown contexts retain baseline.',
                    'Existing six observed-row deadline, ATR stop/target, gap handling, costs and account ownership remain unchanged.',
                ],
                'implementation' => ['kind' => 'typed_ast', 'target' => 'exit', 'ast' => ['op' => 'AND', 'args' => [
                    ['op' => 'BOOL', 'input_key' => 'available', 'available_at' => '2000-01-01T00:00:00Z'],
                    ['op' => 'BOOL', 'input_key' => 'effect', 'available_at' => '2000-01-01T00:00:00Z'],
                ]], 'input_bindings' => ['available' => 'method_midline20_available', 'effect' => 'method_midline20_exit'],
                    'budget' => ['cpu_seconds' => 1.0, 'max_calls' => 100000, 'max_node_evaluations' => 4800000]]]), 'registered_research_only');
            $recipe = recipeStatus($library->registerRecipe(['recipe_key' => $key.'_recipe', 'revision' => 1,
                'method_hashes' => [$method['method_hash']], 'context_predicate' => $context]), 'registered_research_only');
            $recipes[] = $recipe['recipe_hash']; $preferred[] = $recipe['recipe_hash'];
            $declaredContexts[] = $context;
        }
        // Actual PHP policy owner caps16 rules and8 preferences. Nine finite
        // rules cover18 exact recipes, never an empty/wildcard declaration.
        $rules[] = ['context_predicate' => ['regime' => $regime, 'volatility' => $volatility, 'session' => ['london', 'overlap']],
            'preferred_recipes' => $preferred];
    }
}
$agentKey = 'curator-bollinger-complete-recipe-v1';
$policy = recipeStatus($agents->registerPolicy(['agent_key' => $agentKey, 'revision' => 1, 'recipe_hashes' => $recipes,
    'rules' => $rules, 'default_action' => 'BASELINE']), 'registered_research_only');

$question = 'complete-intraday-methods-20261016-v1';
$baselines = $requests = $compiledContracts = [];
$common = ['volume_lane' => 'none', 'atr_stop_multiplier' => 1.5, 'atr_target_multiplier' => 2.5,
    'trailing_atr_multiplier' => 0.0, 'partial_take_profit_fraction' => 0.0,
    'minimum_signal_confidence' => 0.35, 'time_stop_candles' => 6];
$families = ['bb' => ['session_mean_reversion_v1', 'session_mean_reversion'],
    'ema' => ['trend_v1', 'trend'], 'retest' => ['trend_retest_v1', 'trend_retest']];
foreach ($manifest['windows'] as $windowKey => $window) {
    foreach ($families as $family => [$strategy, $baseStrategy]) {
        $base = $control;
        $base['strategy'] = $strategy; $base['base_strategy'] = $baseStrategy;
        $base['dataset_path'] = $window['path']; $base['replay_dataset_hash'] = $window['sha256'];
        $base['execution']['allowed_sessions_utc'] = ['7-14'];
        $base['execution_contract']['parameters'] = $base['execution'];
        $base['execution_contract']['execution_hash'] = $execution->hashParameters($base['execution']);
        $changes = $family === 'bb' ? ['session_start' => 7, 'session_end' => 14, 'lookback' => 20,
            'session_context_gate' => 'none'] : ['ema_fast' => 20, 'ema_slow' => 50,
            'rsi_period' => 14, 'trend_strength_min' => 20.0];
        if ($family === 'ema') $changes['entry_topology_variant'] = 'source_ema_pretrend_pullback_v1';
        $base['parameters'] = array_replace($schema->defaults($strategy), $common, $changes);
        recipeNeed($schema->validate($strategy, $base['parameters'], $baseStrategy) === $base['parameters'],
            'ACTUAL_COMPLETE_CONTROL_SCHEMA_REQUIRED:'.$family);
        if ($family === 'bb') recipeNeed($hashes->parameterHash($base['parameters'])
            === '7a9d42b6719845fa4acf0624e5443546049e1d3af663ec8bc264806c3fb35e49',
            'EXACT_PREVIOUS_BOLLINGER_CONTROL_VECTOR_REQUIRED');
        else recipeNeed(! array_key_exists('lookback', $base['parameters']), 'NO_UNOWNED_LOOKBACK_GENE_ALLOWED');
        $restored = $base; $restored['parameters'] = $control['parameters'];
        foreach (['strategy', 'base_strategy', 'dataset_path', 'replay_dataset_hash', 'execution', 'execution_contract'] as $field)
            $restored[$field] = $control[$field];
        recipeNeed($restored === $control, 'ONLY_DECLARED_DATA_PROGRAMME_SESSION_PARAMETER_CHANGES_ALLOWED');
        $baselines[$windowKey.'__'.$family] = $base;
        $treated = $base;
        if ($family === 'bb') {
            $treated['parameters']['session_band_variance'] = 'population';
            $compiled = $binding->compilePolicy($policy['policy_hash'],
                ['strategy_family' => $strategy, 'parameters' => $treated['parameters']],
                ['symbol' => $treated['symbol'], 'timeframe' => $treated['timeframe'], 'data_hash' => $window['sha256'],
                    'execution_hash' => $treated['execution_contract']['execution_hash'],
                    'evaluated_start' => $window['evaluation_start_utc'],
                    'evaluated_end' => $window['evaluation_last_close_utc'], 'context' => []], $agentKey);
            recipeNeed(count($compiled['recipes']) === 18 && count($compiled['policy']['rules']) === 9,
                'FINITE_CANONICAL_RECIPE_ROSTER_REQUIRED');
            $treated['policy_context']['instrument_method_learning'] = $compiled;
            $compiledContracts[$windowKey] = ['contract_hash' => $compiled['contract_hash'],
                'data_hash' => $window['sha256'], 'policy_hash' => $compiled['policy']['policy_hash'],
                'canonical_operator_contract_hashes' => array_map(static fn (array $recipe): string =>
                    $recipe['operators'][0]['contract_hash'], $compiled['recipes'])];
            $restored = $treated; unset($restored['parameters']['session_band_variance'], $restored['policy_context']);
            recipeNeed($restored === $base, 'ONLY_DECLARED_BOLLINGER_FORMULA_AND_EXIT_BUNDLE_ALLOWED');
        } else {
            if ($family === 'retest') $treated['parameters']['entry_topology_variant'] = 'source_frozen_retest_v1';
            $treated['parameters']['intraday_exit_variant'] = 'closed_setup_2r_v1';
            $restored = $treated; unset($restored['parameters']['intraday_exit_variant']);
            $restored['parameters']['entry_topology_variant'] = $base['parameters']['entry_topology_variant'];
            recipeNeed($restored === $base, 'ONLY_DECLARED_SOURCE_ENTRY_AND_STRUCTURAL_EXIT_ALLOWED');
        }
        recipeNeed($schema->validate($strategy, $treated['parameters'], $baseStrategy) === $treated['parameters'],
            'ACTUAL_COMPLETE_TREATMENT_SCHEMA_REQUIRED:'.$family);
        $arms = [$family.'_control' => $base,
            $family === 'bb' ? 'bb_recipe' : $family.'_structural' => $treated];
        foreach ($arms as $arm => $request) {
            $wire = recipeEncode($request);
            $requests[$windowKey.'__'.$arm] = ['window' => $windowKey, 'method' => $family, 'arm' => $arm,
                'baseline_key' => $windowKey.'__'.$family.'_control', 'baseline_id' => $windowKey.'__'.$family,
                'question_id' => $question, 'request' => $request, 'request_json' => $wire,
                'request_sha256' => hash('sha256', $wire), 'request_semantic_hash' => $hashes->parameterHash($request),
                'dataset_sha256' => $window['sha256'], 'cost_sha256' => $request['execution_contract']['execution_hash']];
        }
    }
}
$counts = [];
foreach (['instrument_method_sources', 'instrument_method_revisions', 'instrument_recipe_revisions',
    'agent_method_policy_revisions', 'research_instrument_programs', 'agent_method_experiences', 'instrument_method_studies'] as $table)
    $counts[$table] = Illuminate\Support\Facades\DB::table($table)->count();
recipeNeed($counts === ['instrument_method_sources' => 1, 'instrument_method_revisions' => 18,
    'instrument_recipe_revisions' => 18, 'agent_method_policy_revisions' => 1,
    'research_instrument_programs' => 36, 'agent_method_experiences' => 0, 'instrument_method_studies' => 0],
    'EXACT_TWO_SCOPE_CANONICAL_COMPILE_ONLY_COUNTS_REQUIRED');
recipeNeed(count($requests) === 12 && count($baselines) === 6, 'EXACT_TWELVE_ORIGINALS_AND_SIX_BASELINES_REQUIRED');
recipeNeed($compiledContracts['june2025']['contract_hash'] !== $compiledContracts['september2025']['contract_hash'],
    'DISTINCT_DATA_BOUND_RECIPE_CONTRACTS_REQUIRED');

echo recipeEncode(['protocol' => 'unfrozen_curator_instrument_panel_v1', 'study_owner' => 'intraday_methods_v1',
    'question_id' => $question, 'instrument' => 'complete_intraday_methods', 'instrument_key' => 'intraday_methods',
    'question' => 'existing_Bollinger_bundle_temporal_replication_and_two_prospective_source_entry_structural_exit_recipes',
    'research_date' => '2026-10-16', 'windows' => $manifest['windows'], 'baselines' => $baselines, 'requests' => $requests,
    'upstream' => ['windows' => ['path' => $manifestPath, 'sha256' => $manifestSha],
        'account_control' => ['path' => $controlPath, 'sha256' => $controlSha],
        'source_review' => ['path' => $sourceReviewPath, 'sha256' => $sourceReviewSha],
        'method_sources' => ['path' => $methodSourcesPath, 'sha256' => $methodSourcesSha]],
    'recipe_declaration' => ['protocol' => 'canonical_memory_only_curator_recipe_compilation_v1', 'database' => ':memory:',
        'dotenv_loaded' => false, 'application_bootstrap_loaded' => false, 'source_key' => $source['source_key'],
        'agent_key' => $agentKey, 'policy_hash' => $policy['policy_hash'], 'contexts' => $declaredContexts,
        'compiled_scopes' => $compiledContracts, 'registry_counts' => $counts, 'loaded_owners' => $loadedOwners,
        'finite_unknown_action' => 'BASELINE', 'session_domain' => ['london', 'overlap'],
        'bundle_only_no_individual_component_credit' => true,
        'migration_sources' => array_map(static fn (string $name): array => [
            'path' => $backend.'/database/migrations/'.$name,
            'sha256' => hash_file('sha256', $backend.'/database/migrations/'.$name),
        ], ['2026_09_07_150000_create_neurotrader_lab_expansion_tables.php',
            '2026_10_13_120000_create_instrument_method_and_agent_learning.php',
            '2026_10_13_121000_scope_research_method_program_identity.php'])],
    'predeclared_arms' => 12, 'per_original_timeout_seconds' => 1500, 'global_timeout_seconds' => 10800,
    'max_attempts_per_arm' => 1, 'serial_execution' => true, 'worker_threads' => 1,
    'process_priority' => 'below_normal_hidden',
    'exposure' => 'reused_native_archive_temporal_replication_discovery_not_independent_or_chronological_holdout',
    'source_boundary' => 'curator_source_concepts_plus_explicit_laboratory_complete_recipes_not_author_full_method_replication',
    'observed_quote_coverage_metadata_only_execution_costs_unchanged' => true,
    'research_only' => true, 'own_agent_outcome' => false, 'independent_validation' => false, 'credit' => false,
    'mastery' => false, 'economic_authority' => false, 'paper_authority' => false, 'live_authority' => false]).PHP_EOL;
