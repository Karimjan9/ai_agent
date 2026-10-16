<?php
/** Actual canonical method compilation in a disposable, memory-only application.
 * No dotenv, application bootstrap/providers/routes, production DB or dispatch.
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

recipeNeed(count($argv) === 5 && $argv[1] === '--windows' && $argv[3] === '--windows-sha256', 'EXACT_WINDOWS_IDENTITY_ARGUMENTS_REQUIRED');
$root = dirname(__DIR__, 2); $backend = $root.'/backend-laravel';
$manifestPath = realpath($argv[2]); $manifestSha = $argv[4];
recipeNeed($manifestPath !== false && $manifestSha === 'ed1b690d4556fc4524c4b9cedafde9693d9d0e4c677b1fb105f00755e477d23b'
    && hash_file('sha256', $manifestPath) === $manifestSha, 'EXACT_COMPLETE_OCTOBER_MANIFEST_REQUIRED');
$manifest = json_decode(file_get_contents($manifestPath), true, 256, JSON_THROW_ON_ERROR);
recipeNeed(($manifest['protocol'] ?? null) === 'curator_complete_month_window_v1'
    && array_keys($manifest['windows'] ?? []) === ['october2025'], 'FIXED_OCTOBER_WINDOW_REQUIRED');
$window = $manifest['windows']['october2025'];
recipeNeed(($window['declared_start_utc'] ?? null) === '2025-10-01T00:00:00Z'
    && ($window['declared_end_utc_exclusive'] ?? null) === '2025-11-01T00:00:00Z'
    && $window['loaded_rows'] === 6524 && $window['warmup_rows'] === 200 && $window['evaluated_rows'] === 6324
    && hash_file('sha256', $window['path']) === $window['sha256'], 'ORIGINAL_COMPLETE_MONTH_BYTES_REQUIRED');
$sourceReviewPath = $root.'/docs/research/instruments/range_reentry/events/2026-10-16-article-review.json';
$sourceReviewSha = 'ed68b747e61d659e9f59912684f217c70b605de5a9f904645281309f9e5ebba7';
recipeNeed(hash_file('sha256', $sourceReviewPath) === $sourceReviewSha, 'ORIGINAL_REVIEWED_ARTICLE_EVENT_REQUIRED');
$controlPath = dirname($root).'/curator-context-research-20261015/.runtime/context-practice-completion-20261015/before-outcome-spec.json';
$controlSha = '5c3ac482449898e5c1d54012bc583b14125f289caa9059640558e5576e99a8c4';
recipeNeed(hash_file('sha256', $controlPath) === $controlSha, 'ORIGINAL_FROZEN_ACCOUNT_COST_CONTROL_REQUIRED');
$control = json_decode(file_get_contents($controlPath), true, 256, JSON_THROW_ON_ERROR)['baselines']['october_pre_dst'];

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
$base = $control; $base['strategy'] = 'session_mean_reversion_v1'; $base['base_strategy'] = 'session_mean_reversion';
$base['dataset_path'] = $window['path']; $base['replay_dataset_hash'] = $window['sha256'];
$base['execution']['allowed_sessions_utc'] = ['7-14'];
$base['execution_contract']['parameters'] = $base['execution'];
$base['execution_contract']['execution_hash'] = $execution->hashParameters($base['execution']);
$base['parameters'] = array_replace($schema->defaults($base['strategy']), ['session_start' => 7, 'session_end' => 14,
    'lookback' => 20, 'volume_lane' => 'none', 'atr_stop_multiplier' => 1.5, 'atr_target_multiplier' => 2.5,
    'trailing_atr_multiplier' => 0.0, 'partial_take_profit_fraction' => 0.0, 'minimum_signal_confidence' => 0.35,
    'time_stop_candles' => 6, 'session_context_gate' => 'none']);
recipeNeed($schema->validate($base['strategy'], $base['parameters'], $base['base_strategy']) === $base['parameters'], 'ACTUAL_CONTROL_SCHEMA_REQUIRED');
$treated = $base; $treated['parameters']['session_band_variance'] = 'population';
recipeNeed($schema->validate($treated['strategy'], $treated['parameters'], $treated['base_strategy']) === $treated['parameters'], 'ACTUAL_TREATMENT_SCHEMA_REQUIRED');

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
$compiled = $binding->compilePolicy($policy['policy_hash'], ['strategy_family' => $treated['strategy'], 'parameters' => $treated['parameters']],
    ['symbol' => $treated['symbol'], 'timeframe' => $treated['timeframe'], 'data_hash' => $treated['replay_dataset_hash'],
        'execution_hash' => $treated['execution_contract']['execution_hash'], 'evaluated_start' => $window['evaluation_start_utc'],
        'evaluated_end' => $window['evaluation_last_close_utc'], 'context' => []], $agentKey);
recipeNeed(count($compiled['recipes']) === 18 && count($compiled['policy']['rules']) === 9, 'FINITE_CANONICAL_RECIPE_ROSTER_REQUIRED');
$treated['policy_context']['instrument_method_learning'] = $compiled;
$restored = $treated; unset($restored['parameters']['session_band_variance'], $restored['policy_context']);
recipeNeed($restored === $base, 'ONLY_DECLARED_FORMULA_AND_EXIT_BUNDLE_ALLOWED');
$counts = [];
foreach (['instrument_method_sources', 'instrument_method_revisions', 'instrument_recipe_revisions',
    'agent_method_policy_revisions', 'research_instrument_programs', 'agent_method_experiences', 'instrument_method_studies'] as $table) {
    $counts[$table] = Illuminate\Support\Facades\DB::table($table)->count();
}
recipeNeed($counts === ['instrument_method_sources' => 1, 'instrument_method_revisions' => 18, 'instrument_recipe_revisions' => 18,
    'agent_method_policy_revisions' => 1, 'research_instrument_programs' => 18, 'agent_method_experiences' => 0,
    'instrument_method_studies' => 0], 'CANONICAL_COMPILE_ONLY_COUNTS_REQUIRED');
$question = 'bollinger-complete-recipe-20261016-v1'; $requests = [];
foreach (['control' => $base, 'population_midline' => $treated] as $arm => $request) {
    $wire = recipeEncode($request);
    $requests['october2025__'.$arm] = ['window' => 'october2025', 'arm' => $arm, 'baseline_key' => 'october2025__control',
        'question_id' => $question, 'request' => $request, 'request_json' => $wire, 'request_sha256' => hash('sha256', $wire),
        'request_semantic_hash' => $hashes->parameterHash($request), 'dataset_sha256' => $window['sha256'],
        'cost_sha256' => $request['execution_contract']['execution_hash']];
}
echo recipeEncode(['protocol' => 'unfrozen_curator_instrument_panel_v1', 'study_owner' => 'bollinger_recipe_v1',
    'question_id' => $question, 'instrument' => 'bollinger_bands', 'instrument_key' => 'range_reentry',
    'question' => 'existing_sample_recross_ATR_vs_population_recross_plus_owned_closed_midline20_exit_bundle',
    'research_date' => '2026-10-16', 'windows' => $manifest['windows'], 'baselines' => ['october2025' => $base], 'requests' => $requests,
    'upstream' => ['windows' => ['path' => $manifestPath, 'sha256' => $manifestSha],
        'account_control' => ['path' => $controlPath, 'sha256' => $controlSha],
        'source_review' => ['path' => $sourceReviewPath, 'sha256' => $sourceReviewSha]],
    'recipe_declaration' => ['protocol' => 'canonical_memory_only_curator_recipe_compilation_v1', 'database' => ':memory:',
        'dotenv_loaded' => false, 'application_bootstrap_loaded' => false, 'source_key' => $source['source_key'],
        'agent_key' => $agentKey, 'policy_hash' => $policy['policy_hash'], 'contexts' => $declaredContexts,
        'registry_counts' => $counts, 'loaded_owners' => $loadedOwners, 'finite_unknown_action' => 'BASELINE',
        'session_domain' => ['london', 'overlap'], 'bundle_only_no_individual_component_credit' => true,
        'migration_sources' => array_map(static fn (string $name): array => [
            'path' => $backend.'/database/migrations/'.$name,
            'sha256' => hash_file('sha256', $backend.'/database/migrations/'.$name),
        ], ['2026_09_07_150000_create_neurotrader_lab_expansion_tables.php',
            '2026_10_13_120000_create_instrument_method_and_agent_learning.php',
            '2026_10_13_121000_scope_research_method_program_identity.php']),
        // Calendar-only curator review of this exact pinned CSV before any
        // replay; this is a clock census, not counts of reached exit calls.
        'calendar_only_scope_review' => [
            'reviewed_owner' => 'app.services.market_sessions.session_membership',
            'reviewed_owner_sha256' => hash_file('sha256', $root.'/ai-service-python/app/services/market_sessions.py'),
            'reviewed_clock' => 'all_original_October_rows_UTC_07:00_through_14:25_inclusive_point_membership',
            'reviewed_rows' => 2070, 'sessions' => ['london' => 1320, 'overlap' => 600, 'asia' => 100, 'new_york' => 50],
            'outside_recipe_session_rows' => 150, 'outside_recipe_action' => 'BASELINE_existing_ATR_and_six_row_deadline',
            'prices_or_outcomes_used' => false, 'actual_exit_invocations_inferred' => false,
        ]],
    'predeclared_arms' => 2, 'per_original_timeout_seconds' => 2700, 'global_timeout_seconds' => 6000,
    'max_attempts_per_arm' => 1, 'serial_execution' => true, 'worker_threads' => 1, 'process_priority' => 'below_normal_hidden',
    'exposure' => 'reused_original_October_archive_discovery_not_untouched_validation',
    'source_boundary' => 'explicit_lab_complete_trade_recipe_not_author_Method_I_II_III_IV_replication',
    'observed_quote_coverage_metadata_only_execution_costs_unchanged' => true,
    'research_only' => true, 'own_agent_outcome' => false, 'independent_validation' => false, 'credit' => false,
    'mastery' => false, 'economic_authority' => false, 'paper_authority' => false, 'live_authority' => false]).PHP_EOL;
