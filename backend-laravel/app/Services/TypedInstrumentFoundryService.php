<?php

namespace App\Services;

use App\Models\AgentLearningCausalExperiment;
use App\Models\CausalFoldReceipt;
use App\Models\LabAgent;
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
        $validation = $this->infer($ast);
        if (($validation['valid'] ?? false) !== true) return $this->blocked((string) $validation['reason']);
        if ((int) $validation['node_count'] > self::MAX_NODES) return $this->blocked('DSL_COMPLEXITY_BUDGET_EXCEEDED');
        $normalized = $this->canonicalize($ast);
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
        $key = hash('sha256', implode('|', [self::PROTOCOL, $symbol, $timeframe, $astHash, $context['data_hash'], $context['execution_hash']]));
        DB::table('research_instrument_programs')->insert([
            'program_key' => $key, 'symbol' => $symbol, 'timeframe' => $timeframe, 'status' => 'compiled_research_only',
            'complexity' => $validation['node_count'], 'ast_hash' => $astHash, 'ast' => json_encode($normalized),
            'compiled_contract' => json_encode($compiled), 'evidence' => json_encode(['gates' => $gates, 'context' => $context, 'promotion_evidence' => false]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return ['protocol' => self::PROTOCOL, 'status' => 'compiled_research_only', 'program_key' => $key,
            'compiled_contract' => $compiled, 'promotion_evidence' => false];
    }

    /** Store only a semantics-preserving normalized AST; replay is still required. */
    public function compress(string $programKey): array
    {
        if (! Schema::hasTable('research_instrument_programs')) return $this->unavailable();
        $program = DB::table('research_instrument_programs')->where('program_key', $programKey)->first();
        if (! $program) return $this->blocked('INSTRUMENT_PROGRAM_NOT_FOUND');
        $ast = (array) json_decode((string) $program->ast, true);
        $normalized = $this->canonicalize($ast);
        $unchanged = $this->hash($ast) === $this->hash($normalized);
        DB::table('research_instrument_programs')->where('id', $program->id)->update([
            'status' => $unchanged ? 'compiled_research_only' : 'compression_replay_required',
            'ast' => json_encode($normalized), 'updated_at' => now(),
        ]);
        return ['protocol' => self::PROTOCOL, 'status' => $unchanged ? 'already_normalized' : 'compression_replay_required',
            'promotion_evidence' => false];
    }

    /** @return array<string,mixed> */
    public function recordEmitterOutcome(string $emitter, array $scope, array $result): array
    {
        if (! Schema::hasTable('research_emitter_credit_profiles')) return $this->unavailable();
        if (($result['settled'] ?? false) !== true) return $this->blocked('SETTLED_EMITTER_OUTCOME_REQUIRED');
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
