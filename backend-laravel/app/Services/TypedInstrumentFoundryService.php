<?php

namespace App\Services;

use App\Models\AgentLearningCausalExperiment;
use App\Models\CausalFoldReceipt;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A deliberately small, temporal-safe DSL. Compilation is research-only and
 * can target only declared runtime primitives; it never creates live policy.
 */
class TypedInstrumentFoundryService
{
    public const PROTOCOL = 'typed_instrument_foundry_v1';
    private const MAX_NODES = 48;
    private const MAX_SOURCE_PROGRAMS = 16;
    private const MAX_SOURCE_TASKS = 16;
    private const MAX_MACROS = 8;
    private const MAX_TRACE_SAMPLE = 512;
    private const MAX_TRADE_SAMPLE = 256;

    /** @return array<string,mixed> */
    public function compile(array $ast, array $context, array $gates): array
    {
        if (! Schema::hasTable('research_instrument_programs')) return $this->unavailable();
        if ((int) ($gates['confirmed_cartridges'] ?? 0) < 1 || (int) ($gates['successful_transfers'] ?? 0) < 1) {
            return $this->blocked('CONFIRMED_CARTRIDGE_AND_SUCCESSFUL_TRANSFER_REQUIRED');
        }
        if (($context['pre_2026_only'] ?? false) !== true || ! filled($context['data_hash'] ?? null) || ! filled($context['execution_hash'] ?? null)) {
            return $this->blocked('PRE2026_DATA_AND_EXECUTION_IDENTITY_REQUIRED');
        }
        try {
            $expanded = $this->expand($ast, $this->programScope($context));
        } catch (\RuntimeException $error) {
            return $this->blocked($error->getMessage());
        }
        $validation = $this->infer($expanded);
        if (($validation['valid'] ?? false) !== true) return $this->blocked((string) $validation['reason']);
        if ((int) $validation['node_count'] > self::MAX_NODES) return $this->blocked('DSL_COMPLEXITY_BUDGET_EXCEEDED');
        $normalized = $this->canonicalize($expanded);
        $astHash = $this->hash($normalized);
        $symbol = strtoupper((string) ($context['symbol'] ?? 'XAUUSD'));
        $timeframe = strtoupper((string) ($context['timeframe'] ?? 'H1'));
        $existing = DB::table('research_instrument_programs')->where('symbol', $symbol)->where('timeframe', $timeframe)->where('ast_hash', $astHash)->first();
        if ($existing) return ['protocol' => self::PROTOCOL, 'status' => 'semantic_duplicate', 'program_key' => $existing->program_key,
            'promotion_evidence' => false];
        $compiled = ['protocol' => self::PROTOCOL, 'version' => 'typed_dsl_v1', 'result_type' => $validation['type'],
            'runtime_primitives' => $validation['primitives'], 'prefix_invariant' => true,
            'available_at_required' => true, 'data_hash' => $context['data_hash'], 'execution_hash' => $context['execution_hash'],
            'research_only' => true, 'promotion_evidence' => false];
        $compiled['expanded_ast'] = $normalized;
        $compiled['source_ast'] = $this->canonicalize($ast);
        $compiled['abstraction_keys'] = $this->callKeys($ast);
        $compiled['scope_key'] = $this->programScope($context);
        $executorPath = dirname(base_path()).'/ai-service-python/app/services/research_program_tasks.py';
        $compiled['task_executor_hash'] = is_file($executorPath) ? hash_file('sha256', $executorPath) : null;
        $compiled['library_utility_is_not_economic_authority'] = true;
        $key = hash('sha256', implode('|', [self::PROTOCOL, $symbol, $timeframe, $astHash, $context['data_hash'], $context['execution_hash']]));
        DB::table('research_instrument_programs')->insert([
            'program_key' => $key, 'symbol' => $symbol, 'timeframe' => $timeframe, 'status' => 'compiled_research_only',
            'complexity' => $validation['node_count'], 'ast_hash' => $astHash, 'ast' => $this->encode($normalized),
            'compiled_contract' => $this->encode($compiled), 'evidence' => $this->encode(['gates' => $gates, 'context' => $context, 'promotion_evidence' => false]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return ['protocol' => self::PROTOCOL, 'status' => 'compiled_research_only', 'program_key' => $key,
            'compiled_contract' => $compiled, 'promotion_evidence' => false];
    }

    /** Mine bounded, parameterized subtrees; preserve the original primitive program. */
    public function compress(string $programKey): array
    {
        if (! Schema::hasTable('research_instrument_programs')) return $this->unavailable();
        $program = DB::table('research_instrument_programs')->where('program_key', $programKey)->first();
        if (! $program) return $this->blocked('INSTRUMENT_PROGRAM_NOT_FOUND');
        if (! Schema::hasTable('research_instrument_abstractions')) return $this->unavailable();
        $ast = (array) json_decode((string) $program->ast, true);
        $context = (array) data_get(json_decode($program->evidence, true), 'context', []);
        $scope = $this->programScope($context);
        $mined = $this->mineAbstractions($program, $scope);
        $compressed = $ast;
        foreach (DB::table('research_instrument_abstractions')->where('scope_key', $scope)->orderByDesc('net_savings')->limit(self::MAX_MACROS)->get() as $macro) {
            $definition = (array) json_decode($macro->definition, true);
            if ($this->hash($definition) !== $macro->macro_key) continue;
            $compressed = $this->rewrite($compressed, $definition, $macro->macro_key);
        }
        try { $roundtrip = $this->expand($compressed, $scope); }
        catch (\RuntimeException $error) { return $this->blocked($error->getMessage()); }
        if ($this->hash($roundtrip) !== $program->ast_hash) return $this->blocked('ABSTRACTION_SEMANTICS_ROUNDTRIP_FAILED');
        $saving = $this->nodeCount($ast) - $this->nodeCount($compressed);
        $contract = (array) json_decode($program->compiled_contract, true);
        $contract['compression'] = ['protocol' => 'parameterized_ast_abstraction_v1', 'compressed_ast' => $compressed,
            'expanded_ast_hash' => $program->ast_hash, 'exact_roundtrip' => true, 'saved_program_nodes' => $saving,
            'macro_keys' => $this->callKeys($compressed), 'library_utility_requires_novel_task' => true];
        DB::table('research_instrument_programs')->where('id', $program->id)->update([
            'compiled_contract' => $this->encode($contract), 'updated_at' => now()]);
        return ['protocol' => self::PROTOCOL, 'status' => $saving > 0 ? 'compressed_research_only' : 'no_verified_reusable_subtree',
            'compression' => $contract['compression'], 'mined_macros' => $mined, 'promotion_evidence' => false];
    }

    /** @return array<string,mixed> */
    public function recordEmitterOutcome(string $emitter, array $scope, array $result): array
    {
        if (! Schema::hasTable('research_emitter_credit_profiles')) return $this->unavailable();
        if (($result['settled'] ?? false) !== true) return $this->blocked('SETTLED_EMITTER_OUTCOME_REQUIRED');
        if (filled($result['evidence_run_id'] ?? null)) {
            $behavior = $this->recordBehaviorOutcome((string) $result['evidence_run_id']);
            if (($behavior['status'] ?? null) === 'blocked') return $behavior;
            if (filled($result['program_key'] ?? null)) $this->recordProgramOutcome((string) $result['program_key'], (string) $result['evidence_run_id']);
        }
        $symbol = strtoupper((string) ($scope['symbol'] ?? 'XAUUSD')); $timeframe = strtoupper((string) ($scope['timeframe'] ?? 'H1'));
        $scopeKey = $this->hash(['failure_stage' => $scope['failure_stage'] ?? 'unknown', 'strategy_family' => $scope['strategy_family'] ?? 'unknown', 'context' => $scope['context'] ?? []]);
        $key = hash('sha256', implode('|', [self::PROTOCOL, 'emitter', $symbol, $timeframe, $emitter, $scopeKey]));
        $previous = DB::table('research_emitter_credit_profiles')->where('profile_key', $key)->first();
        $old = $previous ? (array) json_decode((string) $previous->results, true) : ['cohorts'=>0,'behavior_changed'=>0,'stage_advanced'=>0,'reusable_skills'=>0,'successful_transfers'=>0,'no_op'=>0,'compute_minutes'=>0,'duplicate_behavior'=>0,'overfit_exposure'=>0,'uncertainty_resolved'=>0,'archive_coverage'=>0];
        foreach (array_keys($old) as $metric) $old[$metric] += (float) ($result[$metric] ?? 0);
        $cohorts = max(1., $old['cohorts']);
        $estimate = (($old['stage_advanced'] * 2) + ($old['reusable_skills'] * 2) + ($old['successful_transfers'] * 3) + $old['uncertainty_resolved'] + $old['archive_coverage']
            - ($old['duplicate_behavior'] * 2) - ($old['no_op'] * 1.5) - ($old['overfit_exposure'] * 2) - ($old['compute_minutes'] / 60)) / $cohorts;
        DB::table('research_emitter_credit_profiles')->updateOrInsert(['profile_key' => $key], [
            'symbol'=>$symbol,'timeframe'=>$timeframe,'emitter'=>$emitter,'scope_key'=>$scopeKey,'estimate'=>$estimate,
            'uncertainty'=>round(1 / sqrt($cohorts), 6),'settled_cohorts'=>(int) $cohorts,'results'=>json_encode($old),
            'evidence'=>json_encode(['protocol'=>self::PROTOCOL,'scope'=>$scope,'promotion_evidence'=>false]),'assessed_at'=>now(),'updated_at'=>now(),'created_at'=>now(),
        ]);
        return ['protocol'=>self::PROTOCOL,'status'=>'profiled','estimate'=>round($estimate,6),'settled_cohorts'=>(int)$cohorts,'promotion_evidence'=>false];
    }

    /** @return array<string,mixed> */
    public function planCompoundingBenchmark(array $contract): array
    {
        if (! Schema::hasTable('research_compounding_benchmarks')) return $this->unavailable();
        if ((int) ($contract['confirmed_cartridges'] ?? 0) < 1 || (int) ($contract['successful_transfers'] ?? 0) < 1) return $this->blocked('CONFIRMED_CARTRIDGE_AND_TRANSFER_REQUIRED');
        if (! filled($contract['sealed_challenge_hash'] ?? null) || ! isset($contract['equal_compute_limit'])) return $this->blocked('SEALED_CHALLENGE_AND_EQUAL_COMPUTE_REQUIRED');
        $symbol=strtoupper((string)($contract['symbol']??'XAUUSD')); $timeframe=strtoupper((string)($contract['timeframe']??'H1'));
        $key=$this->hash([self::PROTOCOL,$symbol,$timeframe,$contract['sealed_challenge_hash'],$contract['equal_compute_limit']]);
        DB::table('research_compounding_benchmarks')->updateOrInsert(['benchmark_key'=>$key],[
            'symbol'=>$symbol,'timeframe'=>$timeframe,'status'=>'planned','sealed_contract'=>json_encode(['protocol'=>self::PROTOCOL,...$contract,'promotion_evidence'=>false]),
            'updated_at'=>now(),'created_at'=>now(),
        ]);
        return ['protocol'=>self::PROTOCOL,'status'=>'planned','benchmark_key'=>$key,'promotion_evidence'=>false];
    }

    /** Observe the existing three-arm runner; do not open a second compute loop. */
    public function registerCausalBenchmark(AgentLearningCausalExperiment $experiment, array $request): array
    {
        if (! Schema::hasTable('research_compounding_benchmarks')) return $this->unavailable();
        $release = (array) ($request['research_release'] ?? []);
        if (($release['protocol'] ?? null) !== ResearchReleaseSealService::PROTOCOL) {
            return $this->blocked('LEGACY_UNSEALED_EXPERIMENT_NOT_BACKFILLED');
        }
        if ($this->hash($release) !== $this->hash((array) data_get($experiment->generation?->trigger_context, 'research_release', []))) {
            return $this->blocked('BENCHMARK_GENERATION_RELEASE_MISMATCH');
        }
        $ids = [(int) $experiment->guided_agent_id, (int) $experiment->blinded_agent_id, (int) $experiment->control_agent_id];
        $arms = (array) data_get($request, 'policy_context.learning_confirmation_contracts', []);
        $budgets = [];
        foreach ($ids as $id) {
            $arm = (array) ($arms[(string) $id] ?? []);
            $budgets[] = [(int) ($arm['fold_universe_count'] ?? 0),
                (int) ($arm['per_fold_budget_seconds'] ?? 0), (int) ($arm['max_rows_per_fold'] ?? 0)];
        }
        if (count(array_unique($ids)) !== 3 || $budgets[0] !== $budgets[1] || $budgets[0] !== $budgets[2]
            || min($budgets[0]) < 1) return $this->blocked('THREE_ARM_EQUAL_COMPUTE_CONTRACT_REQUIRED');
        $search = $this->verifiedSelectorObservation($experiment);
        if (($search['status'] ?? null) === 'invalid') return $this->blocked($search['reason_code']);
        $key = $this->hash(['causal_equal_budget_observation_v1', $experiment->id, $release['release_hash'] ?? null]);
        $contract = ['protocol' => 'causal_equal_budget_observation_v1', 'experiment_id' => $experiment->id,
            'release_hash' => $release['release_hash'], 'dataset_hash' => $request['replay_dataset_hash'] ?? null,
            'execution_hash' => data_get($request, 'execution_contract.execution_hash'),
            'arm_ids' => ['memory_enabled' => $ids[0], 'memory_blinded' => $ids[1], 'frozen_control' => $ids[2]],
            'fold_count' => $budgets[0][0], 'per_arm_fold_seconds_limit' => $budgets[0][1],
            'max_rows_per_fold' => $budgets[0][2],
            'arm_program_hash' => $this->hash((array) ($request['strategies'] ?? [])),
            'selector_observation' => $search,
            'compute_limit_is_not_equal_observed_cpu' => true, 'promotion_evidence' => false];
        return DB::transaction(function () use ($key, $contract, $experiment): array {
            $existing = DB::table('research_compounding_benchmarks')->where('benchmark_key', $key)->lockForUpdate()->first();
            if ($existing) {
                return $this->hash((array) json_decode($existing->sealed_contract, true)) === $this->hash($contract)
                    ? ['status' => $existing->status, 'benchmark_key' => $key, 'promotion_evidence' => false]
                    : $this->blocked('BENCHMARK_PREREGISTERED_CONTRACT_DRIFT');
            }
            if (CausalFoldReceipt::where('agent_learning_causal_experiment_id', $experiment->id)
                ->where(fn ($q) => $q->whereNotNull('request_hash')->orWhere('attempt_count', '>', 1))->exists()) {
                return $this->blocked('BENCHMARK_CANNOT_BE_PREREGISTERED_AFTER_EXECUTION');
            }
            DB::table('research_compounding_benchmarks')->insert(['benchmark_key' => $key,
                'symbol' => $experiment->symbol, 'timeframe' => $experiment->timeframe, 'status' => 'planned',
                'sealed_contract' => json_encode($contract), 'created_at' => now(), 'updated_at' => now()]);
            return ['status' => 'planned', 'benchmark_key' => $key, 'promotion_evidence' => false];
        });
    }

    /** Executed comparison is diagnostic, never a compounding/skill authority shortcut. */
    public function settleCausalBenchmark(AgentLearningCausalExperiment $experiment, array $aggregate): array
    {
        if (! Schema::hasTable('research_compounding_benchmarks')) return $this->unavailable();
        $release = (array) data_get($experiment->generation?->trigger_context, 'research_release', []);
        $key = $this->hash(['causal_equal_budget_observation_v1', $experiment->id, $release['release_hash'] ?? null]);
        return DB::transaction(function () use ($key, $experiment, $aggregate, $release): array {
            $row = DB::table('research_compounding_benchmarks')->where('benchmark_key', $key)->lockForUpdate()->first();
            if (! $row) return $this->blocked('NO_PROSPECTIVE_BENCHMARK_CONTRACT');
            if ($row->assessment !== null) return ['status' => $row->status, 'benchmark_key' => $key, 'promotion_evidence' => false];
            $contract = (array) json_decode($row->sealed_contract, true);
            $folds = CausalFoldReceipt::where('agent_learning_causal_experiment_id', $experiment->id)->orderBy('fold_index')->get();
            $count = (int) $contract['fold_count'];
            if ($folds->count() !== $count || $folds->where('status', 'completed')->count() !== $count
                || $folds->pluck('fold_index')->map(fn ($value): int => (int) $value)->all() !== range(1, $count)) {
                return $this->blocked('BENCHMARK_COMPLETE_FOLD_SET_REQUIRED');
            }
            foreach ($folds as $fold) {
                if (! filled($fold->request_hash) || ! hash_equals((string) $fold->request_hash, $this->hash($fold->request_payload))
                    || ! filled($fold->response_hash)
                    || ! hash_equals((string) $fold->response_hash, $this->hash($fold->response_payload))
                    || ! hash_equals((string) $contract['dataset_hash'], (string) $fold->dataset_hash)
                    || ! hash_equals((string) $contract['execution_hash'], (string) $fold->execution_hash)) {
                    return $this->blocked('BENCHMARK_FOLD_IDENTITY_INVALID');
                }
                $foldItems = collect((array) data_get($fold->response_payload, 'leaderboard', []));
                if ($foldItems->count() !== 3 || $foldItems->pluck('lab_agent_id')->map(fn ($value): int => (int) $value)->sort()->values()->all()
                    !== collect($contract['arm_ids'])->map(fn ($value): int => (int) $value)->sort()->values()->all()) {
                    return $this->blocked('BENCHMARK_FOLD_ARM_IDENTITY_INVALID');
                }
                foreach ($foldItems as $item) {
                    if (! is_array(data_get($item, 'result.trade_ledger'))
                        || count(data_get($item, 'result.trade_ledger')) !== (int) data_get($item, 'result.total_trades', -1)) {
                        return $this->blocked('BENCHMARK_COMPLETE_TRADE_LEDGER_REQUIRED');
                    }
                    if (! app(ResearchReleaseSealService::class)->responseValid($release,
                        (array) data_get($item, 'result.data_quality.research_release_receipt', []))) {
                        return $this->blocked('BENCHMARK_WORKER_RELEASE_INVALID');
                    }
                }
            }
            $items = collect((array) ($aggregate['leaderboard'] ?? []))->keyBy('lab_agent_id');
            if ($items->count() !== 3 || $items->keys()->map(fn ($value): int => (int) $value)->sort()->values()->all()
                !== collect($contract['arm_ids'])->map(fn ($value): int => (int) $value)->sort()->values()->all()) {
                return $this->blocked('BENCHMARK_AGGREGATE_ARM_IDENTITY_INVALID');
            }
            $results = []; $resources = [];
            foreach ($contract['arm_ids'] as $role => $id) {
                $result = (array) data_get($items->get($id), 'result', []);
                $results[$role] = ['agent_id' => $id, 'total_trades' => data_get($result, 'total_trades'),
                    'after_cost_expectancy_r' => data_get($result, 'after_cost_expectancy_r'),
                    'net_profit_percent' => data_get($result, 'net_profit_percent'),
                    'max_drawdown_percent' => data_get($result, 'max_drawdown_percent')];
                $measurements = $folds->map(fn ($fold) => data_get(collect((array)
                    data_get($fold->response_payload, 'leaderboard', []))->firstWhere('lab_agent_id', $id),
                    'result.benchmark.arm_replay_resources'))->all();
                $valid = collect($measurements)->every(fn ($measurement): bool => is_array($measurement)
                    && ($measurement['protocol'] ?? '') === 'arm_replay_resources_v1'
                    && ($measurement['scope'] ?? '') === 'economic_replay_only_excludes_shared_features_and_audit'
                    && is_numeric($measurement['cpu_seconds'] ?? null) && is_finite((float) $measurement['cpu_seconds'])
                    && (float) $measurement['cpu_seconds'] > 0
                    && is_numeric($measurement['wall_seconds'] ?? null) && is_finite((float) $measurement['wall_seconds'])
                    && (float) $measurement['wall_seconds'] > 0);
                $resources[$role] = $valid ? ['cpu_seconds' => collect($measurements)->sum('cpu_seconds'),
                    'wall_seconds' => collect($measurements)->sum('wall_seconds'), 'measured_folds' => count($measurements)] : null;
            }
            $assessment = ['protocol' => $contract['protocol'], 'fold_receipt_ids' => $folds->pluck('id')->all(),
                'aggregate_hash' => $this->hash($aggregate), 'equal_preregistered_caps' => true,
                'actual_per_arm_cpu_attested' => ! in_array(null, $resources, true),
                'arm_resources' => $resources,
                'resource_scope' => 'economic_replay_only_excludes_shared_features_and_audit',
                'compounding_proven' => false,
                'confirmation_and_descendant_proofs_still_required' => true,
                'frozen_control_result' => $results['frozen_control'], 'promotion_evidence' => false];
            $assessment['selector_search_comparison'] = $this->selectorSearchComparison($contract, $folds, $resources, $experiment);
            DB::table('research_compounding_benchmarks')->where('id', $row->id)->update([
                'status' => 'executed_diagnostic', 'memory_enabled_result' => json_encode($results['memory_enabled']),
                'memory_blinded_result' => json_encode($results['memory_blinded']),
                'assessment' => json_encode($assessment), 'updated_at' => now()]);
            return ['status' => 'executed_diagnostic', 'benchmark_key' => $key, 'assessment' => $assessment, 'promotion_evidence' => false];
        });
    }

    /** Only the prospectively sealed constructor can supply selector timing and exposure. */
    private function verifiedSelectorObservation(AgentLearningCausalExperiment $experiment): array
    {
        $agents = LabAgent::whereIn('id', [$experiment->guided_agent_id, $experiment->blinded_agent_id,
            $experiment->control_agent_id])->with('modelVersion')->get()->keyBy('id');
        $observations = $agents->map(fn ($agent): array => (array) data_get($agent->modelVersion?->metadata,
            'portfolio_council_lane.causal_learning_cohort.memory_search_receipt', []));
        if ($observations->every(fn ($receipt): bool => $receipt === [])) {
            return ['status' => 'not_measured', 'reason_code' => 'NO_PREREGISTERED_SELECTOR_OBSERVATION'];
        }
        $invalid = ['status' => 'invalid', 'reason_code' => 'BENCHMARK_SELECTOR_OBSERVATION_INVALID'];
        if ($agents->count() !== 3 || $observations->contains(fn ($receipt): bool => $receipt === [])) return $invalid;
        $receipt = $observations->get($experiment->guided_agent_id);
        $core = array_diff_key($receipt, ['receipt_hash' => true]);
        $hashes = app(ExecutionContractService::class);
        if (($receipt['protocol'] ?? null) !== 'causal_selector_observation_v1'
            || ! filled($receipt['receipt_hash'] ?? null) || ! hash_equals($receipt['receipt_hash'], $hashes->hashParameters($core))
            || ! $observations->every(fn ($other): bool => $hashes->hashParameters($other) === $hashes->hashParameters($receipt))
            || ($receipt['blinded_guided_treatment_exclusion'] ?? true) !== false
            || (int) ($receipt['minimum_distinct_questions'] ?? 0) < 5
            || ! filled($receipt['question_key'] ?? null) || ! filled($receipt['timing_scope'] ?? null)
            || (float) ($receipt['per_selector_admission_seconds_limit'] ?? 0) <= 0
            || (array) data_get($receipt, 'arms.memory_blinded.memory_input_ids', []) !== []) return $invalid;
        $baseline = (array) $agents->get($experiment->control_agent_id)?->modelVersion?->parameters;
        $schema = app(StrategyParameterSchemaService::class)->schema($experiment->strategy_family);
        if (! hash_equals((string) ($receipt['baseline_parameter_hash'] ?? ''), $hashes->hashParameters($baseline))
            || ! hash_equals((string) ($receipt['legal_mutation_space_hash'] ?? ''), $hashes->hashParameters([
                'schema' => $schema, 'baseline' => $baseline, 'target' => $experiment->target]))) return $invalid;
        foreach (['memory_enabled' => $experiment->guided_agent_id, 'memory_blinded' => $experiment->blinded_agent_id] as $role => $id) {
            $choice = (array) data_get($receipt, 'arms.'.$role, []);
            $gene = (string) ($choice['selected_gene'] ?? '');
            $seconds = $choice['wall_seconds'] ?? null;
            if (! is_numeric($seconds) || ! is_finite((float) $seconds) || (float) $seconds < 0
                || ! array_key_exists($gene, $schema) || ! array_key_exists($gene, $baseline)
                || ! array_key_exists('value', $choice) || ! array_key_exists('old_value', $choice)
                || $hashes->hashParameters(['value' => $choice['old_value']]) !== $hashes->hashParameters(['value' => $baseline[$gene]])
                || $hashes->hashParameters(['value' => $choice['value']]) === $hashes->hashParameters(['value' => $baseline[$gene]])) return $invalid;
            $expected = $baseline;
            $expected[$gene] = $choice['value'];
            if ($hashes->hashParameters($expected) !== $hashes->hashParameters((array) $agents->get($id)?->modelVersion?->parameters)) return $invalid;
            try {
                app(StrategyParameterSchemaService::class)->validate($experiment->strategy_family, $expected);
            } catch (\InvalidArgumentException) {
                return $invalid;
            }
            if (($choice['within_admission_budget'] ?? null)
                !== ((float) $seconds <= (float) $receipt['per_selector_admission_seconds_limit'])) return $invalid;
        }
        return ['status' => 'measured_constructor_observation', 'receipt' => $receipt,
            'baseline_and_legal_space_verified' => true, 'three_arm_receipt_identity_verified' => true,
            'promotion_evidence' => false];
    }

    private function selectorSearchComparison(array $contract, $folds, array $resources, AgentLearningCausalExperiment $experiment): array
    {
        $observation = (array) ($contract['selector_observation'] ?? []);
        if (($observation['status'] ?? null) !== 'measured_constructor_observation') {
            return ['status' => 'not_measured', 'reason_code' => 'NO_PREREGISTERED_SELECTOR_OBSERVATION',
                'repeated_known_error' => null, 'independent_window_retention' => null,
                'memory_superiority_proven' => false, 'promotion_evidence' => false];
        }
        $receipt = $observation['receipt'];
        $arms = [];
        $minimumTrades = max(1, (int) config('services.learning_lane.causal_minimum_trades_per_window', 8));
        foreach (['memory_enabled', 'memory_blinded'] as $role) {
            $id = (int) $contract['arm_ids'][$role];
            $cpu = 0.0; $wall = 0.0; $first = null; $measured = true;
            foreach ($folds as $fold) {
                $items = collect((array) data_get($fold->response_payload, 'leaderboard', []))->keyBy('lab_agent_id');
                $result = (array) data_get($items->get($id), 'result', []);
                $control = (array) data_get($items->get($contract['arm_ids']['frozen_control']), 'result', []);
                $measurement = (array) data_get($result, 'benchmark.arm_replay_resources', []);
                if ($resources[$role] === null) $measured = false;
                $cpu += (float) ($measurement['cpu_seconds'] ?? 0); $wall += (float) ($measurement['wall_seconds'] ?? 0);
                $comparison = app(GateMarginService::class)->compare($result, $control, $experiment->target);
                if ((int) ($result['total_trades'] ?? 0) >= $minimumTrades && (int) ($control['total_trades'] ?? 0) >= $minimumTrades
                    && data_get($comparison, 'candidate_better') === true) {
                    $first = ['status' => 'local_powered_target_candidate', 'fold_index' => (int) $fold->fold_index,
                        'selector_wall_seconds' => data_get($receipt, 'arms.'.$role.'.wall_seconds'),
                        'cumulative_replay_cpu_seconds' => $measured ? $cpu : null,
                        'cumulative_replay_wall_seconds' => $measured ? $wall : null,
                        'independent_benefit_proven' => false];
                    break;
                }
            }
            $arms[$role] = ['selector_wall_seconds' => data_get($receipt, 'arms.'.$role.'.wall_seconds'),
                'within_selector_admission_budget' => data_get($receipt, 'arms.'.$role.'.within_admission_budget'),
                'first_local_powered_target_candidate' => $first,
                'repeated_known_error' => null, 'repeated_error_reason' => 'ORIGINAL_MATCHING_NEGATIVE_EXPOSURE_NOT_ATTESTED',
                'independent_window_retention' => null];
        }
        return ['status' => 'measured_selector_and_replay_diagnostic', 'question_key' => $receipt['question_key'],
            'selector_receipt_hash' => $receipt['receipt_hash'], 'minimum_distinct_questions' => $receipt['minimum_distinct_questions'],
            'legal_mutation_space_hash' => $receipt['legal_mutation_space_hash'], 'selector_wall_timing_scope' => $receipt['timing_scope'],
            'equal_selector_admission_caps' => true, 'selector_cpu_measured' => false,
            'arms' => $arms, 'independent_window_retention' => null,
            'memory_superiority_proven' => false, 'promotion_evidence' => false];
    }

    /** A solved flag is insufficient: the original frozen task, program and measured outputs must agree. */
    public function taskContract(string $programKey, string $taskKey, array $vectors, array $expectedOutputs, array $budget): array
    {
        if (! Schema::hasTable('research_instrument_programs')) return $this->unavailable();
        $program = DB::table('research_instrument_programs')->where('program_key', $programKey)->first();
        if (! $program) return $this->blocked('INSTRUMENT_PROGRAM_NOT_FOUND');
        $contract = (array) json_decode($program->compiled_contract, true);
        $context = (array) data_get(json_decode($program->evidence, true), 'context', []);
        $scope = $this->programScope($context);
        $ast = (array) data_get($contract, 'compression.compressed_ast', $contract['source_ast'] ?? json_decode($program->ast, true));
        if ($taskKey === '' || ! array_is_list($vectors) || count($vectors) < 1 || count($vectors) > 128
            || ! array_is_list($expectedOutputs) || count($expectedOutputs) !== count($vectors)
            || ! $this->sha($contract['task_executor_hash'] ?? null)
            || ! $this->withinSearchBudget(['cpu_seconds' => 0, 'expansions' => 0], $budget)
            || $budget['cpu_seconds'] > 1 || $budget['max_expansions'] > 6144) return $this->blocked('BOUNDED_NATIVE_TASK_CONTRACT_REQUIRED');
        $definitions = [];
        foreach ($this->callKeys($ast) as $key) {
            $macro = Schema::hasTable('research_instrument_abstractions') ? DB::table('research_instrument_abstractions')->where('macro_key', $key)->first() : null;
            $definition = $macro ? (array) json_decode($macro->definition, true) : [];
            if ($this->hash($definition) !== $key || ($definition['scope_key'] ?? null) !== $scope) return $this->blocked('ABSTRACTION_CONTENT_OR_SCOPE_MISMATCH');
            $definitions[$key] = $definition;
        }
        return ['status' => 'native_task_contract', 'request_path' => 'policy_context.research_program_task',
            'result_path' => 'benchmark.research_program_task', 'task' => ['protocol' => 'sealed_research_program_task_v1',
                'task_key' => $taskKey, 'program_key' => $programKey, 'ast_hash' => $program->ast_hash, 'ast' => $ast,
                'ast_json' => $this->encode($ast),
                'scope_key' => $scope, 'abstractions' => $definitions, 'input_vectors' => $vectors,
                'expected_outputs' => $expectedOutputs, 'search_budget' => $budget], 'promotion_evidence' => false];
    }

    /** Persist observations only after the original native task has completed. */
    public function recordProgramOutcome(string $programKey, string $runId, ?array $response = null): array
    {
        if (! Schema::hasTable('research_instrument_programs')) return $this->unavailable();
        $program = DB::table('research_instrument_programs')->where('program_key', $programKey)->first();
        if (! $program) return $this->blocked('INSTRUMENT_PROGRAM_NOT_FOUND');
        $proof = $this->solvedProgramProof($program, $runId, $response);
        if (($proof['status'] ?? null) === 'blocked') return $proof;
        return DB::transaction(function () use ($program, $proof): array {
            $fresh = DB::table('research_instrument_programs')->where('id', $program->id)->lockForUpdate()->first();
            $evidence = (array) json_decode($fresh->evidence, true);
            $observations = (array) ($evidence['solved_task_observations'] ?? []);
            foreach ($observations as $observation) if (($observation['run_id'] ?? null) === $proof['run_id']) {
                return ['status' => 'solved_task_already_observed', 'promotion_evidence' => false];
            }
            if (count($observations) >= self::MAX_SOURCE_TASKS) return $this->blocked('SOLVED_TASK_ARCHIVE_BUDGET_EXCEEDED');
            $observations[] = $proof;
            $evidence['solved_task_observations'] = $observations;
            DB::table('research_instrument_programs')->where('id', $program->id)->update(['evidence' => $this->encode($evidence), 'updated_at' => now()]);
            return ['status' => 'solved_task_observed', 'task_identity' => $proof['task_identity'], 'promotion_evidence' => false];
        });
    }

    /** Native unseen-task semantics is not synthesis/search efficiency or generalized authority. */
    public function validateAbstraction(string $macroKey, string $programKey, string $runId, string $baselineRunId): array
    {
        if (! Schema::hasTable('research_instrument_abstractions')) return $this->unavailable();
        $macro = DB::table('research_instrument_abstractions')->where('macro_key', $macroKey)->first();
        $program = DB::table('research_instrument_programs')->where('program_key', $programKey)->first();
        if (! $macro || ! $program) return $this->blocked('ABSTRACTION_OR_PROGRAM_NOT_FOUND');
        $definition = (array) json_decode($macro->definition, true);
        if ($this->hash($definition) !== $macroKey) return $this->blocked('ABSTRACTION_CONTENT_IDENTITY_MISMATCH');
        $candidate = $this->solvedProgramProof($program, $runId);
        $baseline = $this->solvedProgramProof($program, $baselineRunId);
        if (($candidate['status'] ?? null) === 'blocked') return $candidate;
        if (($baseline['status'] ?? null) === 'blocked') return $baseline;
        $sources = (array) json_decode($macro->source_evidence, true);
        if (in_array($candidate['task_identity'], array_column($sources, 'task_identity'), true)) return $this->blocked('NOVEL_TASK_REQUIRED');
        if ($runId === $baselineRunId || $candidate['task_identity'] !== $baseline['task_identity']
            || $candidate['scope_key'] !== $macro->scope_key || $baseline['scope_key'] !== $macro->scope_key
            || $candidate['evaluator_hash'] !== $baseline['evaluator_hash']
            || ! in_array($macroKey, $candidate['macro_keys'], true) || $baseline['macro_keys'] !== []) {
            return $this->blocked('NOVEL_TASK_SAME_SCOPE_PRIMITIVE_BASELINE_REQUIRED');
        }
        $a = $candidate['search_resources']; $b = $baseline['search_resources'];
        if ($candidate['search_budget'] === [] || $this->hash($candidate['search_budget']) !== $this->hash($baseline['search_budget'])
            || ! $this->withinSearchBudget($a, $candidate['search_budget']) || ! $this->withinSearchBudget($b, $baseline['search_budget'])
            || ($a['timing_scope'] ?? null) !== 'bounded_program_interpretation'
            || ($b['timing_scope'] ?? null) !== 'bounded_program_interpretation'
            || $candidate['representation_nodes'] >= $baseline['representation_nodes']) {
            return $this->blocked('MEASURED_EQUAL_BUDGET_NOVEL_TASK_UTILITY_REQUIRED');
        }
        $receipt = ['protocol' => 'novel_task_macro_utility_v1', 'candidate' => $candidate, 'baseline' => $baseline,
            'scope' => 'one_original_unseen_task_interpretation_only', 'semantics_verified' => true,
            'search_efficiency_measured' => false, 'library_utility_promoted' => false,
            'promotion_evidence' => false, 'independent_economic_benefit' => false];
        DB::table('research_instrument_abstractions')->where('id', $macro->id)->whereNull('validation_evidence')->update([
            'status' => 'novel_task_semantics_verified', 'validation_evidence' => $this->encode($receipt), 'updated_at' => now()]);
        return ['status' => 'novel_task_semantics_verified', 'macro_key' => $macroKey,
            'search_efficiency_measured' => false, 'library_utility_promoted' => false, 'promotion_evidence' => false];
    }

    /** Archive a bounded sample of original behavior, never mutable labels or parameter distance. */
    public function recordBehaviorOutcome(string $runId, ?array $alreadyLoadedImmutablePayload = null): array
    {
        if (! Schema::hasTable('research_behavior_archive')) return $this->unavailable();
        $proof = $this->verifiedOutcome($runId, $alreadyLoadedImmutablePayload);
        if (($proof['status'] ?? null) === 'blocked') return $proof;
        $run = $proof['run']; $request = $proof['request']; $response = $proof['response'];
        $symbol = strtoupper((string) ($request['symbol'] ?? ''));
        $timeframe = strtoupper((string) ($request['timeframe'] ?? ''));
        $execution = data_get($request, 'execution_contract.execution_hash');
        $evaluator = data_get($request, 'research_release.python_source_hash', $run->code_hash);
        if ($symbol === '' || $timeframe === '' || ! $this->sha($execution) || ! $this->sha($evaluator)) {
            return $this->blocked('BEHAVIOR_COMPATIBLE_SCOPE_DEPENDENCY_MISSING');
        }
        $scope = ['symbol' => $symbol, 'timeframe' => $timeframe, 'data_hash' => $run->data_hash,
            'execution_hash' => $execution, 'evaluator_hash' => $evaluator, 'sample_protocol' => 'immutable_prefix_512_decisions_256_trades_v1'];
        $descriptors = $this->behaviorDescriptors($response);
        $cell = $this->hash(['latency' => $this->behaviorBin($descriptors['response_latency_seconds']),
            'holding' => $this->behaviorBin($descriptors['holding_seconds']), 'contexts' => $descriptors['observed_contexts'],
            'errors' => array_keys($descriptors['error_occurrences']), 'cost_sensitivity' => null]);
        $evidence = ['protocol' => 'immutable_behavior_archive_v1', 'run_id' => $runId, 'request_hash' => $run->request_hash,
            'response_hash' => $run->response_hash, 'original_model_identity' => $proof['identity'], 'scope' => $scope,
            'promotion_evidence' => false, 'confirmed_value_dependency' => 'CANONICAL_INDEPENDENT_INSTRUMENT_VALIDATION_REQUIRED'];
        $key = $this->hash(['immutable_behavior_archive_v1', $runId, $run->response_hash]);
        $value = ['observed_decisions' => $descriptors['sampled_decisions'], 'observed_trades' => $descriptors['sampled_trades'],
            'diagnostic_dimensions' => count(array_filter([$descriptors['response_latency_seconds'], $descriptors['holding_seconds']], fn ($v) => $v !== null))
                + (int) ($descriptors['observed_contexts'] !== []) + (int) ($descriptors['error_occurrences'] !== []),
            'scope' => 'bounded_observation_not_profit_or_diversity_authority'];
        return DB::transaction(function () use ($key, $runId, $symbol, $timeframe, $scope, $cell, $descriptors, $value, $evidence): array {
            $old = DB::table('research_behavior_archive')->where('run_id', $runId)->lockForUpdate()->first();
            if ($old && ($old->entry_key !== $key || $this->hash(json_decode($old->evidence, true)) !== $this->hash($evidence)
                || $this->hash(json_decode($old->descriptors, true)) !== $this->hash($descriptors)
                || $old->scope_key !== $this->hash($scope) || $old->descriptor_cell !== $cell
                || $old->status !== 'observed_research_only' || $old->confirmed_value !== null
                || $this->hash(json_decode($old->research_value, true)) !== $this->hash($value))) return $this->blocked('BEHAVIOR_IMMUTABLE_ENTRY_DRIFT');
            if (! $old) DB::table('research_behavior_archive')->insert(['entry_key' => $key, 'run_id' => $runId,
                'symbol' => $symbol, 'timeframe' => $timeframe, 'scope_key' => $this->hash($scope), 'descriptor_cell' => $cell,
                'status' => 'observed_research_only', 'descriptors' => $this->encode($descriptors), 'research_value' => $this->encode($value),
                'confirmed_value' => null, 'evidence' => $this->encode($evidence), 'created_at' => now(), 'updated_at' => now()]);
            return ['status' => $old ? 'behavior_already_observed' : 'behavior_observed', 'entry_key' => $key,
                'scope_key' => $this->hash($scope), 'descriptor_cell' => $cell, 'descriptors' => $descriptors,
                'confirmed_value' => null, 'promotion_evidence' => false];
        });
    }

    /** Revalidate every returned alternative, with an exact shared replay scope and sample protocol. */
    public function behaviorAlternatives(string $entryKey, int $limit = 8): array
    {
        if (! Schema::hasTable('research_behavior_archive')) return $this->unavailable();
        $entry = DB::table('research_behavior_archive')->where('entry_key', $entryKey)->first();
        if (! $entry) return $this->blocked('BEHAVIOR_ENTRY_NOT_FOUND');
        $base = $this->recordBehaviorOutcome($entry->run_id);
        if (($base['status'] ?? null) === 'blocked') return $base;
        $alternatives = [];
        foreach (DB::table('research_behavior_archive')->where('scope_key', $entry->scope_key)->where('entry_key', '!=', $entryKey)
            ->orderBy('id')->limit(min(16, max(1, $limit)))->get() as $other) {
            $fresh = $this->recordBehaviorOutcome($other->run_id);
            if (($fresh['status'] ?? null) === 'blocked') continue;
            $left = $base['descriptors']['error_occurrences']; $right = $fresh['descriptors']['error_occurrences'];
            $shared = [];
            foreach ($left as $code => $positions) if (isset($right[$code])) $shared[$code] = count(array_intersect($positions, $right[$code]));
            $alternatives[] = ['entry_key' => $other->entry_key, 'descriptor_cell' => $other->descriptor_cell,
                'different_observed_cell' => $other->descriptor_cell !== $entry->descriptor_cell, 'shared_error_occurrences' => $shared,
                'confirmed_value' => null];
        }
        return ['status' => 'compatible_behavior_alternatives', 'alternatives' => $alternatives,
            'research_only' => true, 'promotion_evidence' => false];
    }

    private function verifiedOutcome(string $runId, ?array $response = null): array
    {
        $owner = app(LabImmutableEvidenceService::class);
        $run = $owner->findRun($runId);
        if (! $run || ! $owner->learningEligibility($run)['complete']) return $this->blocked('ORIGINAL_COMPLETE_IMMUTABLE_OUTCOME_REQUIRED');
        try {
            $identity = $owner->verifiedModelRuntimeIdentity($run);
            $requestArtifact = $identity ? LabEvidenceArtifact::where('run_id', $runId)->where('artifact_type', 'evaluation_request')
                ->where('sha256', $identity['request_artifact_hash'])->oldest('id')->first() : null;
            $request = $requestArtifact ? $owner->readArtifactPayload($requestArtifact) : null;
            $response ??= $owner->latestArtifactPayload($run);
        } catch (\RuntimeException) { return $this->blocked('IMMUTABLE_ARTIFACT_VERIFICATION_FAILED'); }
        if (! $identity || ! is_array($request) || ! is_array($response)
            || ! $this->sha($run->response_hash) || $owner->hash($response) !== $run->response_hash) return $this->blocked('ORIGINAL_IDENTITY_OR_RESPONSE_SHA_MISMATCH');
        // readArtifactPayload verifies compressed bytes before decoding; the
        // original seal pins that exact request artifact, not a later alias.
        return ['run' => $run, 'request' => $request, 'response' => $response, 'identity' => $identity];
    }

    private function solvedProgramProof(object $program, string $runId, ?array $response = null): array
    {
        $proof = $this->verifiedOutcome($runId, $response);
        if (($proof['status'] ?? null) === 'blocked') return $proof;
        $task = (array) data_get($proof['request'], 'policy_context.research_program_task', []);
        $result = (array) data_get($proof['response'], 'benchmark.research_program_task', []);
        if ((isset($proof['request']['research_program_task']) && $this->hash($proof['request']['research_program_task']) !== $this->hash($task))
            || (isset($proof['response']['research_program_task']) && $this->hash($proof['response']['research_program_task']) !== $this->hash($result))) {
            return $this->blocked('ORIGINAL_TASK_PATH_COPY_MISMATCH');
        }
        $contract = (array) json_decode($program->compiled_contract, true);
        if (isset($task['ast_json'])) {
            try { $preserved = json_decode($task['ast_json'], true, 32, JSON_THROW_ON_ERROR); }
            catch (\JsonException|\TypeError) { return $this->blocked('ORIGINAL_TASK_AST_JSON_COPY_MISMATCH'); }
            if (! is_array($preserved) || $this->hash($preserved) !== $this->hash((array) ($task['ast'] ?? []))) {
                return $this->blocked('ORIGINAL_TASK_AST_JSON_COPY_MISMATCH');
            }
        }
        $context = (array) data_get(json_decode($program->evidence, true), 'context', []);
        $scope = $this->programScope($context);
        $input = $task['input_vectors'] ?? null; $expected = $task['expected_outputs'] ?? null;
        if (($task['protocol'] ?? null) !== 'sealed_research_program_task_v1' || ! filled($task['task_key'] ?? null)
            || ($task['program_key'] ?? null) !== $program->program_key || ($task['ast_hash'] ?? null) !== $program->ast_hash
            || ($result['task_key'] ?? null) !== $task['task_key'] || ($result['ast_hash'] ?? null) !== $program->ast_hash
            || ! is_array($input) || ! array_is_list($input) || count($input) < 1 || count($input) > 128
            || ! is_array($expected) || ! array_is_list($expected) || count($expected) !== count($input)
            || ! is_array($result['outputs'] ?? null) || $this->hash($expected) !== $this->hash($result['outputs'])
            || ($result['producer_protocol'] ?? null) !== 'bounded_typed_program_execution_v1'
            || ! $this->sha($contract['task_executor_hash'] ?? null)
            || ($result['executor_hash'] ?? null) !== $contract['task_executor_hash']
            || ($task['scope_key'] ?? null) !== $scope
            || $proof['run']->data_hash !== ($context['data_hash'] ?? null)
            || data_get($proof['request'], 'execution_contract.execution_hash') !== ($context['execution_hash'] ?? null)) {
            return $this->blocked('ORIGINAL_SOLVED_TASK_PROGRAM_AND_OUTPUT_PROOF_REQUIRED');
        }
        try { $expanded = $this->expand((array) ($task['ast'] ?? []), $scope); }
        catch (\RuntimeException $error) { return $this->blocked($error->getMessage()); }
        if ($this->hash($expanded) !== $program->ast_hash) return $this->blocked('SOLVED_TASK_ACTUAL_PROGRAM_MISMATCH');
        $type = $this->infer($expanded);
        if (! ($type['valid'] ?? false)) return $this->blocked('SOLVED_TASK_ACTUAL_PROGRAM_MISMATCH');
        foreach ($expected as $output) {
            if ($type['type'] === 'bool' ? ! is_bool($output) : (! is_int($output) && ! is_float($output))) return $this->blocked('SOLVED_TASK_TYPED_OUTPUT_REQUIRED');
        }
        $consumedKeys = [];
        foreach ($this->subtrees($expanded) as $node) if (in_array($node['op'] ?? null, ['PRICE_CLOSE', 'ATR', 'NUMBER', 'BOOL', 'DURATION'], true)) {
            $consumedKeys[] = (string) ($node['input_key'] ?? strtolower($node['op']));
        }
        $consumedKeys = array_values(array_unique($consumedKeys)); sort($consumedKeys);
        $consumedVectors = [];
        foreach ($input as $vector) {
            $decision = is_array($vector) ? $this->utcSeconds($vector['decision_at'] ?? null) : null;
            if ($decision === null || $decision >= strtotime('2026-01-01T00:00:00Z')) return $this->blocked('SOLVED_TASK_PRE2026_ASOF_INPUT_REQUIRED');
            foreach ($this->subtrees($expanded) as $node) if (isset($node['available_at'])) {
                $available = $this->utcSeconds($node['available_at']);
                if ($available === null || $available > $decision) return $this->blocked('SOLVED_TASK_FUTURE_INPUT_FORBIDDEN');
            }
            $consumed = ['decision_at' => $vector['decision_at']];
            foreach ($consumedKeys as $key) {
                if (! array_key_exists($key, $vector)) return $this->blocked('SOLVED_TASK_CONSUMED_INPUT_REQUIRED');
                $consumed[$key] = $vector[$key];
            }
            $consumedVectors[] = $consumed;
        }
        return ['status' => 'verified_solved_research_task', 'run_id' => $runId, 'request_hash' => $proof['run']->request_hash,
            'response_hash' => $proof['run']->response_hash, 'program_key' => $program->program_key,
            // Labels, IDs and unused dummy fields do not create another task.
            'task_identity' => $this->hash(['consumed_input_vectors' => $consumedVectors, 'expected_outputs' => $expected]),
            'scope_key' => $scope, 'evaluator_hash' => data_get($proof['request'], 'research_release.python_source_hash', $proof['run']->code_hash), 'ast_hash' => $program->ast_hash,
            'macro_keys' => $this->callKeys((array) $task['ast']), 'representation_nodes' => $this->nodeCount((array) $task['ast']),
            'search_budget' => (array) ($task['search_budget'] ?? []), 'search_resources' => (array) ($result['search_resources'] ?? []),
            'promotion_evidence' => false];
    }

    private function mineAbstractions(object $target, string $scope): array
    {
        $groups = [];
        foreach (DB::table('research_instrument_programs')->where('symbol', $target->symbol)->where('timeframe', $target->timeframe)
            ->orderBy('id')->limit(self::MAX_SOURCE_PROGRAMS)->get() as $program) {
            $evidence = (array) json_decode($program->evidence, true);
            if ($this->programScope((array) ($evidence['context'] ?? [])) !== $scope) continue;
            foreach (array_slice((array) ($evidence['solved_task_observations'] ?? []), 0, self::MAX_SOURCE_TASKS) as $observation) {
                $proof = $this->solvedProgramProof($program, (string) ($observation['run_id'] ?? ''));
                if (($proof['status'] ?? null) === 'blocked' || $this->hash($proof) !== $this->hash($observation)) continue;
                foreach ($this->subtrees((array) json_decode($program->ast, true)) as $subtree) {
                    $parameters = []; $template = $this->parameterize($subtree, $parameters);
                    $nodes = $this->nodeCount($template);
                    if ($nodes < 5 || count($parameters) < 1 || count($parameters) > 3) continue;
                    $type = $this->infer($subtree);
                    if (! ($type['valid'] ?? false)) continue;
                    $definition = ['protocol' => 'parameterized_ast_abstraction_v1', 'scope_key' => $scope,
                        'template' => $template, 'parameters' => $parameters, 'result_type' => $type['type']];
                    $key = $this->hash($definition);
                    $groups[$key]['definition'] = $definition;
                    // Count only one physical program and one distinct frozen question per source.
                    $groups[$key]['sources'][$program->program_key] = $proof;
                    $groups[$key]['nodes'] = $nodes;
                }
            }
        }
        $created = [];
        foreach ($groups as $key => $group) {
            $sources = array_values($group['sources']);
            if (count($sources) < 2 || count(array_unique(array_column($sources, 'task_identity'))) < 2
                || count(array_unique(array_column($sources, 'evaluator_hash'))) !== 1 || ! $this->sha($sources[0]['evaluator_hash'])) continue;
            $definitionCost = $group['nodes'] + count($group['definition']['parameters']);
            $saving = count($sources) * ($group['nodes'] - 1 - count($group['definition']['parameters'])) - $definitionCost;
            if ($saving <= 0) continue;
            DB::table('research_instrument_abstractions')->insertOrIgnore(['macro_key' => $key, 'scope_key' => $scope,
                'status' => 'research_only', 'result_type' => $group['definition']['result_type'],
                'definition' => $this->encode($group['definition']), 'source_evidence' => $this->encode($sources),
                'definition_nodes' => $definitionCost, 'net_savings' => $saving, 'created_at' => now(), 'updated_at' => now()]);
            $created[] = $key;
            if (count($created) >= self::MAX_MACROS) break;
        }
        return $created;
    }

    private function expand(array $node, string $scope, int $depth = 0): array
    {
        if ($depth > 12 || $this->nodeCount($node) > self::MAX_NODES) throw new \RuntimeException('DSL_COMPLEXITY_BUDGET_EXCEEDED');
        if (strtoupper((string) ($node['op'] ?? '')) === 'CALL') {
            $key = (string) ($node['macro_key'] ?? '');
            $macro = Schema::hasTable('research_instrument_abstractions') ? DB::table('research_instrument_abstractions')->where('macro_key', $key)->first() : null;
            if (! $macro) throw new \RuntimeException('ABSTRACTION_NOT_FOUND');
            $definition = (array) json_decode($macro->definition, true);
            if ($this->hash($definition) !== $key || ($definition['scope_key'] ?? null) !== $scope || $macro->scope_key !== $scope) throw new \RuntimeException('ABSTRACTION_CONTENT_OR_SCOPE_MISMATCH');
            $parameters = (array) ($definition['parameters'] ?? []); $arguments = (array) ($node['args'] ?? []);
            if (count($parameters) !== count($arguments)) throw new \RuntimeException('ABSTRACTION_TYPED_ARGUMENT_MISMATCH');
            $substitutions = [];
            foreach ($parameters as $index => $parameter) {
                if (! is_array($arguments[$index] ?? null)) throw new \RuntimeException('ABSTRACTION_TYPED_ARGUMENT_MISMATCH');
                $argument = $this->expand($arguments[$index], $scope, $depth + 1); $type = $this->infer($argument);
                if (! ($type['valid'] ?? false) || $type['type'] !== ($parameter['type'] ?? null)) throw new \RuntimeException('ABSTRACTION_TYPED_ARGUMENT_MISMATCH');
                $substitutions[$parameter['name']] = $argument;
            }
            $expanded = $this->substitute((array) ($definition['template'] ?? []), $substitutions, $depth + 1);
            if ($this->callKeys($expanded) !== [] || $this->nodeCount($expanded) > self::MAX_NODES) throw new \RuntimeException('ABSTRACTION_RECURSIVE_OR_COMPLEXITY_INVALID');
            $valid = $this->infer($expanded);
            if (! ($valid['valid'] ?? false) || $valid['type'] !== ($definition['result_type'] ?? null)) throw new \RuntimeException('ABSTRACTION_RESULT_TYPE_INVALID');
            return $expanded;
        }
        foreach ((array) ($node['args'] ?? []) as $index => $argument) {
            if (! is_array($argument)) throw new \RuntimeException('DSL_AST_ARGUMENT_REQUIRED');
            $node['args'][$index] = $this->expand($argument, $scope, $depth + 1);
        }
        if ($this->nodeCount($node) > self::MAX_NODES) throw new \RuntimeException('DSL_COMPLEXITY_BUDGET_EXCEEDED');
        return $node;
    }

    private function substitute(array $node, array $substitutions, int $depth): array
    {
        if ($depth > 24) throw new \RuntimeException('ABSTRACTION_RECURSIVE_OR_COMPLEXITY_INVALID');
        if (($node['op'] ?? null) === 'PARAM') {
            if (! isset($substitutions[$node['name'] ?? ''])) throw new \RuntimeException('ABSTRACTION_PARAMETER_UNBOUND');
            return $substitutions[$node['name']];
        }
        foreach ((array) ($node['args'] ?? []) as $i => $argument) $node['args'][$i] = $this->substitute($argument, $substitutions, $depth + 1);
        return $node;
    }

    private function parameterize(array $node, array &$parameters): array
    {
        if (strtoupper((string) ($node['op'] ?? '')) === 'CONST') {
            $name = 'p'.count($parameters); $type = (string) ($node['type'] ?? 'number');
            $parameters[] = ['name' => $name, 'type' => $type];
            return ['op' => 'PARAM', 'name' => $name, 'type' => $type];
        }
        foreach ((array) ($node['args'] ?? []) as $i => $argument) $node['args'][$i] = $this->parameterize($argument, $parameters);
        return $node;
    }

    private function rewrite(array $node, array $definition, string $key): array
    {
        $parameters = []; $template = $this->parameterize($node, $parameters);
        if ($this->hash($template) === $this->hash($definition['template']) && $parameters === $definition['parameters']) {
            $values = [];
            foreach ($this->subtrees($node) as $subtree) if (strtoupper((string) ($subtree['op'] ?? '')) === 'CONST') $values[] = $subtree;
            return ['op' => 'CALL', 'macro_key' => $key, 'args' => $values];
        }
        foreach ((array) ($node['args'] ?? []) as $i => $argument) $node['args'][$i] = $this->rewrite($argument, $definition, $key);
        return $node;
    }

    private function subtrees(array $node): array
    {
        $all = [$node]; foreach ((array) ($node['args'] ?? []) as $argument) $all = array_merge($all, $this->subtrees($argument));
        return $all;
    }

    private function callKeys(array $node): array
    {
        $keys = [];
        foreach ($this->subtrees($node) as $subtree) if (strtoupper((string) ($subtree['op'] ?? '')) === 'CALL') $keys[] = (string) ($subtree['macro_key'] ?? '');
        return array_values(array_unique($keys));
    }

    private function nodeCount(array $node): int
    {
        $pending = [$node]; $count = 0;
        while ($pending !== []) {
            $item = array_pop($pending);
            if (++$count > self::MAX_NODES) return $count;
            foreach ((array) ($item['args'] ?? []) as $argument) {
                if (! is_array($argument)) return self::MAX_NODES + 1;
                $pending[] = $argument;
                if (count($pending) > self::MAX_NODES) return self::MAX_NODES + 1;
            }
        }
        return $count;
    }
    private function programScope(array $context): string { return $this->hash(['symbol' => strtoupper((string) ($context['symbol'] ?? 'XAUUSD')),
        'timeframe' => strtoupper((string) ($context['timeframe'] ?? 'H1')), 'data_hash' => $context['data_hash'] ?? null, 'execution_hash' => $context['execution_hash'] ?? null]); }
    private function sha(mixed $value): bool { return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1; }
    private function withinSearchBudget(array $measurement, array $budget): bool
    {
        return is_numeric($measurement['cpu_seconds'] ?? null) && is_finite((float) $measurement['cpu_seconds']) && $measurement['cpu_seconds'] >= 0
            && is_int($measurement['expansions'] ?? null) && $measurement['expansions'] >= 0
            && is_numeric($budget['cpu_seconds'] ?? null) && is_finite((float) $budget['cpu_seconds']) && $budget['cpu_seconds'] > 0
            && is_int($budget['max_expansions'] ?? null) && $budget['max_expansions'] > 0
            && $measurement['cpu_seconds'] <= $budget['cpu_seconds'] && $measurement['expansions'] <= $budget['max_expansions'];
    }

    private function behaviorDescriptors(array $response): array
    {
        $ledger = (array) ($response['trade_ledger'] ?? []); $events = (array) ($response['decision_trace'] ?? []);
        $latencies = []; $holding = []; $contexts = []; $errors = [];
        foreach (array_slice($ledger, 0, self::MAX_TRADE_SAMPLE) as $trade) {
            $entry = $this->utcSeconds($trade['entry_time'] ?? null); $exit = $this->utcSeconds($trade['exit_time'] ?? null);
            $signal = $this->utcSeconds($trade['signal_time'] ?? $trade['setup_time'] ?? null);
            if ($entry !== null && $exit !== null && $exit >= $entry) $holding[] = $exit - $entry;
            if ($entry !== null && $signal !== null && $entry >= $signal) $latencies[] = $entry - $signal;
        }
        foreach (array_slice($events, 0, self::MAX_TRACE_SAMPLE) as $event) {
            foreach (array_slice((array) ($event['context_axes'] ?? $event['context'] ?? []), 0, 16, true) as $axis => $value) {
                if (! is_string($axis) || ! is_scalar($value) || strlen($axis) > 80 || strlen((string) $value) > 120) continue;
                if (count($contexts) >= 16 && ! isset($contexts[$axis])) continue;
                $contexts[$axis][(string) $value] = true;
            }
            $code = $event['error_code'] ?? null; $index = $event['candle_index'] ?? null; $time = $event['candle_time'] ?? null;
            if (is_string($code) && strlen($code) <= 80 && $code !== '' && is_int($index) && $this->utcSeconds($time) !== null) {
                if (count($errors) < 16 || isset($errors[$code])) $errors[$code][] = $index.'|'.$time;
            }
        }
        foreach ($contexts as $axis => $values) { $contexts[$axis] = array_keys($values); sort($contexts[$axis]); } ksort($contexts); ksort($errors);
        foreach ($errors as $code => $positions) $errors[$code] = array_values(array_unique($positions));
        return ['protocol' => 'bounded_observed_behavior_v1', 'response_latency_seconds' => $this->median($latencies),
            'holding_seconds' => $this->median($holding), 'cost_sensitivity' => null,
            'cost_sensitivity_dependency' => 'CONTROLLED_SAME_SCOPE_COST_PERTURBATION_REQUIRED',
            'observed_contexts' => $contexts, 'error_occurrences' => $errors,
            'sampled_trades' => min(count($ledger), self::MAX_TRADE_SAMPLE), 'sampled_decisions' => min(count($events), self::MAX_TRACE_SAMPLE),
            'latency_sample_count' => count($latencies), 'holding_sample_count' => count($holding),
            'trades_truncated' => count($ledger) > self::MAX_TRADE_SAMPLE, 'decisions_truncated' => count($events) > self::MAX_TRACE_SAMPLE,
            'missing_dimensions' => array_values(array_filter([$latencies === [] ? 'response_latency' : null, $holding === [] ? 'holding_time' : null,
                $contexts === [] ? 'contexts' : null, 'controlled_cost_sensitivity']))];
    }

    private function utcSeconds(mixed $value): ?int
    {
        if (! is_string($value) || preg_match('/(?:Z|\+00:00)$/D', $value) !== 1) return null;
        try { return (new \DateTimeImmutable($value))->getTimestamp(); } catch (\Exception) { return null; }
    }
    private function median(array $values): ?float { if ($values === []) return null; sort($values); $n = count($values); return $n % 2 ? (float) $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2; }
    private function behaviorBin(?float $seconds): ?int { return $seconds === null ? null : (int) floor(log(1 + $seconds, 2)); }
    private function encode(mixed $value): string { return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR); }

    private function infer(array $node): array
    {
        $op = strtoupper((string) ($node['op'] ?? '')); $args = (array) ($node['args'] ?? []);
        $leaf = ['PRICE_CLOSE'=>'price','ATR'=>'atr','DURATION'=>'duration','BOOL'=>'bool','NUMBER'=>'number'];
        if (isset($leaf[$op])) {
            if (! filled($node['available_at'] ?? null)) return ['valid'=>false,'reason'=>'DSL_AVAILABLE_AT_REQUIRED'];
            return ['valid'=>true,'type'=>$leaf[$op],'node_count'=>1,'primitives'=>[$op]];
        }
        if ($op === 'CONST') return ['valid'=>true,'type'=>(string)($node['type']??'number'),'node_count'=>1,'primitives'=>[]];
        $children=[]; foreach ($args as $arg) { if (! is_array($arg)) return ['valid'=>false,'reason'=>'DSL_AST_ARGUMENT_REQUIRED']; $child=$this->infer($arg); if (!($child['valid']??false)) return $child; $children[]=$child; }
        $types=array_column($children,'type'); $count=1+array_sum(array_column($children,'node_count')); $primitives=array_values(array_unique(array_merge(...array_map(fn($c)=>(array)$c['primitives'],$children ?: [['primitives'=>[]]]))));
        $ok = match ($op) {
            'GREATER_THAN','LESS_THAN' => count($types)===2 && in_array($types[0],['price','atr','number'],true) && in_array($types[1],['price','atr','number'],true),
            'AND','OR' => count($types)>=2 && !in_array(false,array_map(fn($t)=>$t==='bool',$types),true),
            'NOT' => $types===['bool'],
            'SEQUENCE' => count($types)>=2 && !in_array(false,array_map(fn($t)=>$t==='bool',$types),true),
            'WITHIN' => $types===['bool','duration'],
            'CONFIRMED_BY' => $types===['bool','bool'],
            default => false,
        };
        return $ok ? ['valid'=>true,'type'=>'bool','node_count'=>$count,'primitives'=>$primitives] : ['valid'=>false,'reason'=>'DSL_TYPE_OR_OPERATOR_INVALID'];
    }

    private function canonicalize(mixed $value): mixed { if (!is_array($value)) return $value; if (!array_is_list($value)) ksort($value); foreach($value as $key=>$item) $value[$key]=$this->canonicalize($item); return $value; }
    private function hash(mixed $value): string { return hash('sha256',json_encode($this->canonicalize($value),JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION)); }
    private function unavailable(): array { return ['protocol'=>self::PROTOCOL,'status'=>'migration_pending','promotion_evidence'=>false]; }
    private function blocked(string $reason): array { return ['protocol'=>self::PROTOCOL,'status'=>'blocked','reason'=>$reason,'promotion_evidence'=>false]; }
}
