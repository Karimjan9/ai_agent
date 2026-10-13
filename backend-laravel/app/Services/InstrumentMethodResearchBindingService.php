<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\ModelVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Prospective model/library binding. It cannot grant qualification or change a sealed genome. */
class InstrumentMethodResearchBindingService
{
    public const METADATA = 'instrument_method_learning';
    public const PROTOCOL = 'instrument_method_learning_v1';

    public function __construct(
        private InstrumentMethodLibraryService $library,
        private AgentMethodLearningService $agents,
        private StrategyParameterSchemaService $schemas,
        private ResearchPaperEpochContractService $hashes,
    ) {}

    public function compilePolicy(string $policyHash, array $baseline, array $scope, string $agentKey, ?array $evaluationRequest = null): array
    {
        $registered = $this->agents->policy($policyHash);
        if (($registered['status'] ?? null) === 'blocked' || ($registered['agent_key'] ?? null) !== $agentKey) {
            throw new LogicException('METHOD_POLICY_OR_AGENT_OWNER_INVALID');
        }
        $library = $this->library->verifyLibrary(['library_contract' => $registered['library_contract'],
            'library_hash' => $registered['library_hash']]);
        if (($library['valid'] ?? false) !== true) throw new LogicException('METHOD_LIBRARY_SNAPSHOT_INVALID');
        $start = $this->utc($scope['evaluated_start'] ?? null);
        $end = $this->utc($scope['evaluated_end'] ?? null);
        if ($start > $end || $end >= $this->hashes->cutoff()) throw new LogicException('METHOD_RESEARCH_PRE2026_SCOPE_REQUIRED');
        $data = $scope['data_hash'] ?? null;
        $execution = $scope['execution_hash'] ?? null;
        if (! $this->sha($data) || ! $this->sha($execution)) throw new LogicException('METHOD_RESEARCH_DATA_EXECUTION_REQUIRED');
        $meta = null;
        if (preg_match('/^model:([1-9][0-9]*)$/D', $agentKey, $match)) {
            $model = ModelVersion::find((int) $match[1]);
            if ($model && (array_key_exists(InstrumentMethodMetaBindingService::METADATA, (array) $model->metadata)
                || array_key_exists(InstrumentMethodMetaBindingService::PROOF_METADATA, (array) $model->metadata))) {
                if ($evaluationRequest === null) throw new LogicException('META_BINDING_ACTUAL_EVALUATION_REQUEST_REQUIRED');
                if ($this->hashes->parameterHash((array) $model->parameters) !== $this->hashes->parameterHash((array) $baseline['parameters'])
                    || $scope['symbol'] !== ($evaluationRequest['symbol'] ?? null) || $scope['timeframe'] !== ($evaluationRequest['timeframe'] ?? null)
                    || $data !== ($evaluationRequest['replay_dataset_hash'] ?? null)
                    || $execution !== data_get($evaluationRequest, 'execution_contract.execution_hash')) {
                    throw new LogicException('META_BINDING_COMPILED_REQUEST_SCOPE_DRIFT');
                }
                $meta = app(InstrumentMethodMetaBindingService::class)->forModel($model, $evaluationRequest);
            }
        }
        return DB::transaction(function () use ($registered, $library, $baseline, $scope, $start, $end, $data, $execution, $agentKey, $meta) {
            $recipes = [];
            foreach ($registered['library_contract']['recipe_hashes'] as $hash) {
                $original = $this->library->inspectRecipe($hash);
                if (($original['status'] ?? null) !== 'registered_research_only') throw new LogicException('METHOD_RECIPE_REVISION_INVALID');
                $compiled = $this->library->compileRecipe($hash, $baseline, [
                    'symbol' => $scope['symbol'], 'timeframe' => $scope['timeframe'], 'pre_2026_only' => true,
                    'data_hash' => $data, 'execution_hash' => $execution,
                    'context_predicate' => $original['contract']['context_predicate'],
                ]);
                if (! in_array($compiled['status'] ?? null, ['compiled_research_recipe', 'parameter_adapter_recipe'], true)) {
                    throw new LogicException($compiled['reason'] ?? 'METHOD_RECIPE_COMPILATION_REQUIRED');
                }
                // Parameter variants belong to pre-construction, not a hidden
                // post-passport rewrite. Matching adapters are runtime references.
                if ($this->hashes->parameterHash($compiled['parameters']) !== $this->hashes->parameterHash($baseline['parameters'])) {
                    throw new LogicException('METHOD_PARAMETER_VARIANT_REQUIRES_FRESH_CONSTRUCTION');
                }
                $recipes[] = $compiled['runtime_recipe'];
            }
            usort($recipes, fn ($a, $b) => strcmp($a['recipe_hash'], $b['recipe_hash']));
            $pool = array_map(fn ($recipe) => ['recipe_hash' => $recipe['recipe_hash'], 'runtime_hash' => $recipe['runtime_hash']], $recipes);
            $policyBody = json_decode($registered['policy_json'], true, 64, JSON_THROW_ON_ERROR);
            $body = ['protocol' => self::PROTOCOL, 'expected_agent_key' => $agentKey,
                'data_hash' => $data, 'execution_hash' => $execution,
                'scope' => ['symbol' => $scope['symbol'], 'timeframe' => $scope['timeframe'],
                    'evaluated_start' => $start->format('Y-m-d\TH:i:s\Z'), 'evaluated_end' => $end->format('Y-m-d\TH:i:s\Z'),
                    'context' => (array) ($scope['context'] ?? [])],
                'runtime_parameters' => $baseline['parameters'],
                'runtime_parameters_hash' => $this->hashes->parameterHash($baseline['parameters']),
                'strategy_family' => $this->schemas->family($baseline['strategy_family']),
                'library_contract' => $library['library_contract'], 'library_contract_json' => $library['library_contract_json'],
                'library_hash' => $library['library_hash'], 'compiled_pool_hash' => $this->hashes->parameterHash(['recipes' => $pool]),
                'recipes' => $recipes, 'policy' => [...$policyBody, 'policy_hash' => $registered['policy_hash'], 'policy_json' => $registered['policy_json']],
                'research_only' => true, 'economic_authority' => false, 'promotion_evidence' => false,
                'paper_authority' => false, 'live_authority' => false];
            if ($meta !== null) $body['meta_model'] = $meta;
            return [...$body, 'contract_hash' => $this->hashes->parameterHash($body), 'contract_json' => $this->json($body)];
        });
    }

    /** Explicit attachment before a model has any attempted or sealed laboratory work. */
    public function attachToUnusedModel(int $modelId, string $policyHash): array
    {
        return DB::transaction(function () use ($modelId, $policyHash) {
            $model = ModelVersion::whereKey($modelId)->lockForUpdate()->firstOrFail();
            $policy = $this->agents->policy($policyHash);
            if (($policy['status'] ?? null) === 'blocked' || ($policy['agent_key'] ?? null) !== 'model:'.$modelId
                || LabEvaluationRun::where('model_version_id', $modelId)->exists()
                || LabAgent::where('model_version_id', $modelId)->exists()
                || $model->promoted_at !== null || $model->invalidated_at !== null
                || in_array($model->status, ['active', 'champion', 'paper', 'live', 'archived'], true)
                || data_get($model->metadata, 'specialist_council') !== null
                || data_get($model->metadata, 'specialist_council_evaluation') !== null
                ) throw new LogicException('METHOD_POLICY_UNUSED_OWNED_MODEL_REQUIRED');
            $prior = data_get($model->metadata, self::METADATA);
            $binding = ['protocol' => self::PROTOCOL, 'policy_hash' => $policyHash, 'agent_key' => 'model:'.$modelId];
            if ($prior !== null && $prior !== $binding) throw new LogicException('METHOD_POLICY_MODEL_BINDING_IMMUTABLE');
            $model->update(['metadata' => [...(array) $model->metadata, self::METADATA => $binding]]);
            return ['status' => 'attached_prospectively', 'model_id' => $modelId, ...$binding, 'promotion_evidence' => false];
        });
    }

    public function forModel(ModelVersion $model, array $request): ?array
    {
        $binding = data_get($model->metadata, self::METADATA);
        if ($binding === null) return null;
        if (! is_array($binding) || ($binding['protocol'] ?? null) !== self::PROTOCOL
            || ($binding['agent_key'] ?? null) !== 'model:'.$model->id) throw new LogicException('METHOD_POLICY_MODEL_DECLARATION_INVALID');
        $scope = $this->scopeFromRequest($request);
        $family = $this->schemas->family((string) data_get($model->metadata, 'base_strategy', $model->strategy));
        return $this->compilePolicy($binding['policy_hash'], ['strategy_family' => $family,
            'parameters' => (array) $model->parameters], $scope, 'model:'.$model->id, $request);
    }

    public function bindRequests(array $request, array $models): array
    {
        $bound = array_values(array_filter($models, fn ($model) => $model instanceof ModelVersion
            && data_get($model->metadata, self::METADATA) !== null));
        if (! isset($request['strategies']) && count($bound) > 1) {
            throw new LogicException('METHOD_RESEARCH_SINGLE_OWNER_AMBIGUOUS');
        }
        $studyCap = null;
        foreach ($models as $model) {
            if (! $model instanceof ModelVersion || data_get($model->metadata, self::METADATA) === null) continue;
            if (isset($request['strategies'])) {
                $ids = LabAgent::where('model_version_id', $model->id)->pluck('id')->all();
                $matches = array_keys(array_filter($request['strategies'], fn ($item) => in_array($item['lab_agent_id'] ?? null, $ids, true)));
                if (count($matches) !== 1) throw new LogicException('METHOD_RESEARCH_BATCH_OWNER_AMBIGUOUS');
                $request['strategies'][$matches[0]]['instrument_method_learning'] = $this->forModel($model, $request);
                $study = app(InstrumentMethodStudyService::class)->bindingForModel((int) $model->id);
                if ($study !== null) $request['strategies'][$matches[0]]['instrument_method_study'] = $study;
            } else {
                $request['policy_context']['instrument_method_learning'] = $this->forModel($model, $request);
                $study = app(InstrumentMethodStudyService::class)->bindingForModel((int) $model->id);
                if ($study !== null) $request['policy_context']['instrument_method_study'] = $study;
            }
            $request['emit_decision_trace'] = true;
            $request['emit_trade_ledger'] = true;
            if ($study !== null) {
                if ($studyCap !== null && $studyCap !== $study['resource_caps']) {
                    throw new LogicException('METHOD_RESEARCH_BATCH_RESOURCE_CAP_DRIFT');
                }
                $studyCap = $study['resource_caps'];
                $request['policy_context']['instrument_method_study_resource_cap'] = $studyCap;
            }
        }
        return $request;
    }

    public function scopeFromRequest(array $request): array
    {
        $probe = (array) data_get($request, 'policy_context.prospective_probe_window', []);
        $stream = (array) data_get($request, 'mtf_snapshot_manifest.streams.'.($request['timeframe'] ?? 'M5'), []);
        $candles = (array) ($request['candles'] ?? []);
        $start = $probe['evaluated_start'] ?? $stream['first_candle_at'] ?? ($candles[0]['time'] ?? null);
        $end = $probe['evaluated_end'] ?? $stream['last_candle_at'] ?? ($candles === [] ? null : $candles[array_key_last($candles)]['time']);
        return ['symbol' => $request['symbol'] ?? null, 'timeframe' => $request['timeframe'] ?? null,
            'data_hash' => $request['replay_dataset_hash'] ?? null,
            'execution_hash' => data_get($request, 'execution_contract.execution_hash'),
            'evaluated_start' => $this->utc($start)->format('Y-m-d\TH:i:s\Z'),
            'evaluated_end' => $this->utc($end)->format('Y-m-d\TH:i:s\Z')];
    }

    private function utc(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(Z|\+00:00)?$/D', $value)) {
            throw new LogicException('METHOD_RESEARCH_ORIGINAL_UTC_SCOPE_REQUIRED');
        }
        return CarbonImmutable::parse($value, 'UTC')->utc();
    }
    private function sha(mixed $value): bool { return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1; }
    private function json(array $value): string
    {
        $sort = function (array $items) use (&$sort): array {
            if (! array_is_list($items)) ksort($items);
            foreach ($items as $key => $item) if (is_array($item)) $items[$key] = $sort($item);
            return $items;
        };
        return json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
