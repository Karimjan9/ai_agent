<?php

namespace App\Services;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\LabGeneration;
use App\Models\LabGateDecisionEvent;
use App\Models\LabLifecycleEvent;
use App\Models\ModelVersion;
use App\Models\ResearchExperimentReceipt;
use App\Models\ResearchLoopDecision;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/** Creates draft models plus a pollable intent; only the arbiter admits/dispatches them. */
class AcademyExperimentMaterializerService
{
    public const PROTOCOL = 'academy_foundry_materializer_v2';
    public const COLD_START_PROTOCOL = 'prospective_academy_cold_start_v1';
    public const SOURCE_IDENTITY_PROTOCOL = 'dual_runtime_source_identity_v1';
    public const TECHNICAL_REPLACEMENT_PROTOCOL = 'academy_unobserved_identity_replacement_v1';
    public const PREPARATION_CONTAINMENT_PROTOCOL = 'academy_preparation_containment_v1';
    public const PREPARATION_FAULT = 'sealed_dataframe_attrs_copy_blowup_v1';
    public const PREPARATION_REPLACEMENT_PROTOCOL = 'academy_preparation_containment_repair_v1';
    public const VALIDATOR_REPLACEMENT_PROTOCOL = 'academy_unobserved_mtf_validator_replacement_v1';

    public function __construct(
        private AcademyExperimentContractCompilerService $compiler,
        private ResearchExperimentConversionKernelService $conversion,
        private CausalCompoundingKernelService $compoundingKernel,
    ) {}

    /** Read-only: proposals never create trials, alter historical evidence or publish queue jobs. */
    public function proposal(string $symbol = 'XAUUSD', string $timeframe = 'H1'): array
    {
        $rows = DB::table('edge_academy_trials as t')->join('edge_academy_passports as p', 'p.id', '=', 't.edge_academy_passport_id')
            ->where('p.symbol', strtoupper($symbol))->where('p.timeframe', strtoupper($timeframe))
            ->whereNull('t.settled_at')->whereIn('t.status', ['planned', 'materialized'])
            // A prepared draft is already-admitted ownership. An older planned
            // trial must not hide its publication intent from the arbiter.
            ->orderByRaw("CASE WHEN t.status = 'materialized' THEN 0 ELSE 1 END")->orderBy('t.id')
            ->select('t.*', 'p.frozen_upstream_contract')->get();
        $blocked = [];
        foreach ($rows as $trial) {
            $outcome = json_decode((string) $trial->outcome, true) ?: [];
            if ($trial->status === 'materialized' && data_get($outcome, 'canonical_admission.status') === 'pending') {
                $generation = LabGeneration::query()->find((int) ($outcome['generation_id'] ?? 0));
                if ($generation && in_array($generation->status, ['draft', 'queued', 'screening'], true)) return [
                    'protocol' => self::PROTOCOL, 'status' => 'pending_canonical_admission', 'trial_id' => (int) $trial->id,
                    'generation_id' => $generation->id, 'baseline_model_version_id' => data_get($generation->trigger_context, 'baseline_model_version_id'),
                    'identity' => (array) data_get($outcome, 'canonical_admission.identity', []), 'promotion_evidence' => false,
                ];
                $blocked[] = ['trial_id' => $trial->id, 'reason' => 'ACADEMY_MATERIALIZED_GENERATION_UNAVAILABLE'];
                continue;
            }
            $frozen = json_decode((string) $trial->frozen_upstream_contract, true) ?: [];
            if (($frozen['protocol'] ?? null) !== XauusdEdgeFormationAcademyService::PROTOCOL) {
                $blocked[] = ['trial_id' => $trial->id, 'reason' => 'ACADEMY_PROSPECTIVE_SOURCE_SEAL_REQUIRED'];
                continue;
            }
            $baselineId = (int) ($frozen['baseline_model_version_id'] ?? 0);
            $identity = [...(array) ($frozen['prospective_source_identity'] ?? []), 'pre_2026_only' => true,
                'baseline_parameter_hash' => $frozen['baseline_parameter_hash'] ?? null];
            $result = $this->materialize((int) $trial->id, $baselineId, $identity, false);
            if (($result['status'] ?? null) === 'would_queue') return [...$result, 'status' => 'would_materialize', 'identity' => $identity];
            $blocked[] = ['trial_id' => $trial->id, 'reason' => $result['reason'] ?? 'ACADEMY_BASELINE_NOT_READY'];
        }
        if ($rows->isEmpty()) {
            $coldStart = $this->coldStartProposal($symbol, $timeframe);
            return $coldStart;
        }
        return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason' => $rows->isEmpty() ? 'NO_READY_ACADEMY_TRIAL' : 'ACADEMY_TRIAL_DEPENDENCIES_NOT_READY',
            'blocked_trials' => $blocked, 'promotion_evidence' => false];
    }

    /** Old parameters are a hypothesis only; no old receipt or oracle is upgraded. */
    public function coldStartProposal(string $symbol = 'XAUUSD', string $timeframe = 'H1'): array
    {
        if (strtoupper($symbol) !== 'XAUUSD' || strtoupper($timeframe) !== 'H1') return $this->blocked('ACADEMY_COLD_START_SCOPE_REQUIRED');
        $dependencies = $this->coldStartDependencies($symbol, $timeframe);
        if (($dependencies['ready'] ?? false) !== true) return [...$this->blocked((string) ($dependencies['reason'] ?? 'ACADEMY_COLD_START_DATA_NOT_READY')),
            'data_readiness' => $dependencies['data_readiness'] ?? null];
        $scope = $this->dependencyBudgetScope($symbol, $timeframe, $dependencies);
        $existing = DB::table('edge_academy_passports')->where('symbol', $symbol)->where('timeframe', $timeframe)
            ->where('frozen_upstream_contract', 'like', '%'.self::COLD_START_PROTOCOL.'%')->get();
        $sameScope = $existing->filter(fn ($row): bool => data_get(json_decode($row->frozen_upstream_contract, true),
            'prospective_source_identity.cold_start.budget_scope') === $scope
            || data_get(json_decode($row->frozen_upstream_contract, true),
                'prospective_source_identity.cold_start.sealed_budget_scope') === $scope);
        if ($sameScope->isNotEmpty()) {
            // The typed validator allowance is an alternative to, not a
            // continuation of, the older constructor/preparation repair chain.
            if ($sameScope->contains(fn ($row): bool => filled(data_get(json_decode($row->frozen_upstream_contract, true),
                'prospective_source_identity.cold_start.validator_replacement')))) {
                return $this->blocked('ACADEMY_VALIDATOR_REPLACEMENT_ALLOWANCE_EXHAUSTED');
            }
            try { $validator = $this->validatorReplacementProposal($sameScope, $dependencies, $scope); }
            catch (RuntimeException|\ErrorException) { return $this->blocked('ACADEMY_VALIDATOR_REPLACEMENT_IMMUTABLE_EVIDENCE_INVALID'); }
            if ($validator !== null) return $validator;
            $preparation = $this->preparationReplacementProposal($sameScope, $dependencies, $scope);
            if ($preparation !== null) return $preparation;
            return $this->technicalReplacementProposal($sameScope, $dependencies, $scope);
        }
        $sources = LabAgent::query()->with('modelVersion')->where('symbol', $symbol)->where('timeframe', $timeframe)
            ->where('strategy_family', 'confirmation_entry_mtf')->where('origin', 'edge_genesis')
            ->whereIn('lifecycle_status', ['screened', 'completed', 'rejected', 'archived'])
            ->whereHas('generation', fn ($query) => $query->whereIn('status', ['screened', 'completed']))
            ->orderByDesc('id')->limit(20)->get();
        foreach ($sources as $source) {
            if (! $source->modelVersion) continue;
            $parameters = (array) $source->modelVersion->parameters;
            $preview = app(XauusdEdgeFormationAcademyService::class)->previewColdStartExperiment($parameters);
            if (($preview['status'] ?? null) !== 'previewed') continue;
            $key = $this->hash([self::COLD_START_PROTOCOL, $source->model_version_id,
                $this->compiler->parameterHash($parameters), $dependencies]);
            return ['protocol' => self::PROTOCOL, 'status' => 'would_prepare_cold_start', 'trial_id' => 0,
                'baseline_model_version_id' => $source->model_version_id, 'baseline_agent_id' => $source->id,
                'baseline_parameter_hash' => $this->compiler->parameterHash($parameters),
                'cold_start' => ['protocol' => self::COLD_START_PROTOCOL, 'key' => $key, 'budget_scope' => $scope,
                    'dependencies' => $dependencies, 'preview_hash' => $this->hash($preview['compiled_contract']),
                    'axis' => $preview['axis'], 'trial_type' => $preview['trial_type'], 'maximum_cohorts_per_scope' => 1],
                'source_role' => 'legacy_parameters_hypothesis_only', 'stage_depth' => 0,
                'primary_proof_seats' => count($preview['compiled_contract']['arms']),
                'research_only' => true, 'promotion_evidence' => false, 'independent_causal_skill' => false];
        }
        return $this->blocked('ACADEMY_COLD_START_LEGAL_HYPOTHESIS_BASELINE_MISSING');
    }

    /** ONE prospective repair of the exact clean-bundle validator refusal, never outcome-selected retry. */
    private function validatorReplacementProposal($sameScope, array $dependencies, string $scope, bool $attestOnly = false): ?array
    {
        if ($sameScope->count() !== 1) return null;
        $passport = $sameScope->first();
        $frozen = (array) (json_decode((string) $passport->frozen_upstream_contract, true) ?: []);
        $identity = (array) ($frozen['prospective_source_identity'] ?? []);
        $cold = (array) ($identity['cold_start'] ?? []);
        if (($identity['data_role'] ?? null) !== 'pre_2026_discovery_only'
            || ($identity['source_identity_protocol'] ?? null) !== self::SOURCE_IDENTITY_PROTOCOL
            || ($cold['protocol'] ?? null) !== self::COLD_START_PROTOCOL
            || isset($cold['technical_replacement']) || isset($cold['preparation_replacement'])
            || (! $attestOnly && isset($cold['validator_replacement']))) return null;
        if ($attestOnly && isset($cold['validator_replacement'])
            && (data_get($cold, 'validator_replacement.protocol') !== self::VALIDATOR_REPLACEMENT_PROTOCOL
                || data_get($cold, 'validator_replacement.maximum_validator_replacements') !== 1
                || data_get($cold, 'validator_replacement.maximum_total_cohorts') !== 2
                || data_get($cold, 'validator_replacement.scientific_question_budget_reset') !== false)) return null;
        $trials = DB::table('edge_academy_trials')->where('edge_academy_passport_id', $passport->id)->get();
        if ($trials->count() !== 1) return null;
        $trial = $trials->first();
        $generations = LabGeneration::query()->with('agents.modelVersion')->where('trigger_context->academy_trial_id', (int) $trial->id)->get();
        if ($generations->count() !== 1) return null;
        $generation = $generations->first();
        $agentIds = $generation->agents->pluck('id')->all();
        $runs = LabEvaluationRun::query()->where(fn ($q) => $q->where('lab_generation_id', $generation->id)
            ->orWhereIn('lab_agent_id', $agentIds))->orderBy('id')->get();
        if ($runs->isEmpty() || ! $runs->contains(fn ($run): bool => $this->isNativeMtfValidatorRefusal((string) $run->error_message))) return null;
        $refusal = 'ACADEMY_VALIDATOR_REPLACEMENT_ATTESTATION_REQUIRED';
        $agents = $generation->agents;
        $baseline = ModelVersion::find((int) ($frozen['baseline_model_version_id'] ?? 0));
        $baselineAgent = LabAgent::with('generation')->find((int) ($cold['hypothesis_baseline_agent_id'] ?? 0));
        if ($trial->status !== 'technical_quarantine' || $trial->settled_at === null || $generation->status !== 'technical_quarantine'
            || $generation->trigger_type !== 'academy_experiment' || (int) $generation->population_size !== 20
            || ($frozen['protocol'] ?? null) !== XauusdEdgeFormationAcademyService::PROTOCOL
            || $agents->count() !== 20 || $agents->pluck('model_version_id')->unique()->count() !== 20
            || $agents->contains(fn ($a): bool => ! $a->modelVersion || $a->lifecycle_status !== 'technical_quarantine'
                || (int) $a->sample_count !== 0 || $a->profit_factor !== null || $a->train_score !== null
                || $a->validation_score !== null || $a->forward_score !== null
                || data_get($a->modelVersion->metadata, 'last_result') !== null || data_get($a->modelVersion->metadata, 'last_screen_result') !== null)
            || ! $baseline || ! $baselineAgent || (int) $baselineAgent->model_version_id !== (int) $baseline->id
            || (int) $baselineAgent->generation?->ai_laboratory_id !== (int) $generation->ai_laboratory_id
            || preg_match('/^[a-f0-9]{64}$/', (string) ($identity['source_evaluator_hash'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/', (string) ($identity['python_source_hash'] ?? '')) !== 1
            || (! $attestOnly && (hash_equals((string) $identity['source_evaluator_hash'], (string) $dependencies['evaluator_hash'])
                || hash_equals((string) $identity['python_source_hash'], (string) $dependencies['python_source_hash'])))
            || LabGateDecisionEvent::where(fn ($q) => $q->where('lab_generation_id', $generation->id)->orWhereIn('lab_agent_id', $agentIds))->exists()
            || LabEvidenceArtifact::where(fn ($q) => $q->where('lab_generation_id', $generation->id)->orWhereIn('lab_agent_id', $agentIds))->whereIn('artifact_type',
                ['trade_ledger', 'decision_trace', 'decision_events', 'replay_result'])->exists()) return $this->blocked($refusal);
        $queue = app(LabQueueJobInspector::class)->generationQueueBacklog($agents->pluck('id')->all());
        if (($queue['available'] ?? false) !== true || ($queue['total'] ?? null) !== 0) return $this->blocked($refusal);
        foreach (['data_hash', 'execution_hash', 'mtf_bundle_hash', 'source_evaluator_hash', 'python_source_hash', 'source_identity_protocol'] as $field) {
            if (($identity[$field] ?? null) !== data_get($generation->trigger_context, $field)) return $this->blocked($refusal);
        }
        if (($identity['data_hash'] ?? null) !== $dependencies['foundation_sha256']
            || ($identity['execution_hash'] ?? null) !== $dependencies['execution_hash']
            || ($identity['mtf_bundle_hash'] ?? null) !== $dependencies['mtf_bundle_hash']
            || $this->hash((array) ($identity['discovery_scope'] ?? [])) !== $this->hash((array) ($dependencies['discovery_scope'] ?? []))
            || $this->hash((array) ($identity['mtf_bundle_manifest'] ?? [])) !== $this->hash((array) data_get($generation->trigger_context, 'mtf_bundle_manifest', []))
            || $this->hash(collect((array) data_get($identity, 'mtf_bundle_manifest.streams'))->only(['M5', 'M15', 'H1', 'H4'])
                ->map(fn ($s) => $s['sha256'] ?? null)->all()) !== $this->hash($dependencies['mtf_source_sha256'])) return $this->blocked($refusal);
        $preview = app(XauusdEdgeFormationAcademyService::class)->previewColdStartExperiment((array) $baseline->parameters);
        $compiled = (array) ($preview['compiled_contract'] ?? []);
        $trialCompiled = $this->compiler->compile(['axis' => $preview['axis'] ?? '', 'arms' => (array) json_decode((string) $trial->arms, true)],
            (array) $baseline->parameters, ['symbol' => 'XAUUSD', 'laboratory_timeframe' => 'H1', 'execution_timeframe' => 'M5']);
        if (($preview['status'] ?? null) !== 'previewed'
            || $frozen['baseline_parameter_hash'] !== $this->compiler->parameterHash((array) $baseline->parameters)
            || $cold['preview_hash'] !== $this->hash($compiled) || $this->hash($compiled) !== $this->hash($trialCompiled)
            || $this->hash($compiled) !== $this->hash((array) data_get($generation->trigger_context, 'compiled_contract', []))
            || $this->hash((array) $preview['event_density_contract']) !== $this->hash((array) json_decode((string) $trial->density_contract, true))) return $this->blocked($refusal);
        $primary = $agents->where('origin', 'academy_experiment');
        $kernel = (array) data_get($generation->trigger_context, 'causal_compounding_kernel', []);
        $kernelIds = collect((array) ($kernel['pairs'] ?? []))->flatMap(fn ($p) => [(int) $p['control_agent_id'], (int) $p['candidate_agent_id']])
            ->merge((array) ($kernel['abstain_agent_ids'] ?? []))->sort()->values()->all();
        if ($primary->count() !== count($compiled['arms'])
            || $primary->pluck('modelVersion.metadata.academy_experiment.arm_index')->unique()->count() !== $primary->count()
            || ($kernel['protocol'] ?? null) !== CausalCompoundingKernelService::PROTOCOL || ($kernel['state'] ?? null) !== 'sealed'
            || (int) ($kernel['population_size'] ?? 0) !== 20
            || ($kernel['data_hash'] ?? null) !== $identity['data_hash'] || ($kernel['execution_hash'] ?? null) !== $identity['execution_hash']
            || (int) ($kernel['causal_baseline_model_version_id'] ?? 0) !== (int) $baseline->id
            || $kernelIds !== $agents->where('origin', '!=', 'academy_experiment')->pluck('id')->sort()->values()->all()) return $this->blocked($refusal);
        foreach ($agents as $a) {
            $parameters = app(StrategyParameterSchemaService::class)->canonicalizeForIdentity($a->strategy_family, (array) $a->modelVersion->parameters);
            $encoded = json_encode($parameters, JSON_PRESERVE_ZERO_FRACTION);
            if (data_get($a->modelVersion->metadata, 'parameter_fingerprint') !== hash('sha256', $a->strategy_family.'|'.$encoded)
                || data_get($a->modelVersion->metadata, 'universal_genome.local_adapter.parameters_hash') !== hash('sha256', $encoded)) return $this->blocked($refusal);
        }
        foreach ($primary as $a) {
            $arm = $compiled['arms'][(int) data_get($a->modelVersion->metadata, 'academy_experiment.arm_index', -1)] ?? [];
            if (data_get($a->modelVersion->metadata, 'academy_experiment.protocol') !== self::PROTOCOL
                || (int) data_get($a->modelVersion->metadata, 'academy_experiment.academy_trial_id') !== (int) $trial->id
                || data_get($a->modelVersion->metadata, 'academy_experiment.arm_role') !== ($arm['role'] ?? null)
                || $this->compiler->parameterHash((array) $a->modelVersion->parameters) !== ($arm['parameter_hash'] ?? null)) return $this->blocked($refusal);
        }
        $plan = ['protocol' => CausalCompoundingKernelService::PREPARATION_PLAN, 'original_generation_id' => (int) $generation->id,
            'original_trial_id' => (int) $trial->id, 'baseline_model_version_id' => (int) $baseline->id,
            'data_hash' => $identity['data_hash'], 'execution_hash' => $identity['execution_hash'],
            'scientific_outcome_observed' => false, 'market_evidence_reused' => false,
            'abstain_seats' => count((array) ($kernel['abstain_agent_ids'] ?? [])), 'pairs' => []];
        foreach ((array) ($kernel['pairs'] ?? []) as $p) {
            $c = $agents->firstWhere('id', (int) $p['control_agent_id']); $a = $agents->firstWhere('id', (int) $p['candidate_agent_id']);
            if (! $c || ! $a || ! app(ExactCausalBaselineService::class)->matches($a, $c)
                || $this->compiler->parameterHash((array) $c->modelVersion->parameters) !== $frozen['baseline_parameter_hash']
                || (int) data_get($a->modelVersion->metadata, 'control_pair_contract.control_agent_id') !== (int) $c->id
                || data_get($c->modelVersion->metadata, 'control_pair_contract.pair_key') !== $p['pair_key']
                || data_get($a->modelVersion->metadata, 'control_pair_contract.pair_key') !== $p['pair_key']
                || data_get($a->parameter_diff, $p['gene'].'.old') !== $p['old_value']
                || data_get($a->parameter_diff, $p['gene'].'.new') !== $p['tested_value']) return $this->blocked($refusal);
            $plan['pairs'][] = ['gene' => $p['gene'], 'old_value' => $p['old_value'], 'tested_value' => $p['tested_value'],
                'selector_hash' => $p['selector_hash'], 'control_parameter_hash' => $this->compiler->parameterHash((array) $c->modelVersion->parameters),
                'candidate_parameter_hash' => $this->compiler->parameterHash((array) $a->modelVersion->parameters)];
        }
        foreach ((array) ($kernel['abstain_agent_ids'] ?? []) as $id) {
            if ($this->compiler->parameterHash((array) $agents->firstWhere('id', (int) $id)?->modelVersion?->parameters) !== $frozen['baseline_parameter_hash']) return $this->blocked($refusal);
        }
        $ledger = app(LabImmutableEvidenceService::class); $runProof = []; $probeHash = null;
        $release = (array) data_get($generation->trigger_context, 'research_release', []);
        $releaseBody = $release; unset($releaseBody['release_hash'], $releaseBody['sealed_at'], $releaseBody['promotion_evidence']);
        if (($release['protocol'] ?? null) !== ResearchReleaseSealService::PROTOCOL
            || ($release['source_hash'] ?? null) !== $identity['source_evaluator_hash']
            || ($release['python_source_hash'] ?? null) !== $identity['python_source_hash']
            || ($release['dataset_hash'] ?? null) !== $identity['mtf_bundle_hash']
            || ($release['release_hash'] ?? null) !== app(ExecutionContractService::class)->hashParameters($releaseBody)) return $this->blocked($refusal);
        foreach ($runs as $run) {
            $a = $agents->firstWhere('id', (int) $run->lab_agent_id);
            if (! $a || (int) $run->lab_generation_id !== (int) $generation->id || $run->status !== 'technical_error' || $run->phase !== 'screening'
                || ! $run->started_at || ! $run->finished_at || filled($run->trade_ledger_hash)
                || (int) $run->model_version_id !== (int) $a->model_version_id || $run->code_hash !== $identity['source_evaluator_hash']
                || data_get($run->metadata, 'worker_boot_source_hash') !== $identity['source_evaluator_hash']
                || data_get($run->metadata, 'research_release_hash') !== $release['release_hash']
                || $run->parameter_hash !== $ledger->parameterHash($a) || $run->data_hash !== $identity['mtf_bundle_hash']
                || ! $this->isNativeMtfValidatorRefusal((string) $run->error_message)
                || array_diff(array_keys((array) $run->metrics), ['total_trades', 'profit_factor', 'max_drawdown_percent',
                    'screening_survival', 'monthly_passport', 'gate_failure_context', 'event_ledger_hash']) !== []
                || collect((array) $run->metrics)->contains(fn ($value): bool => $value !== null)
                || $ledger->verifiedModelRuntimeIdentity($run) === null) return $this->blocked($refusal);
            $responses = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'evaluation_response')->get();
            $requests = LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'evaluation_request')->get();
            if ($responses->count() !== 1 || $requests->count() !== 1
                || ! filled($responses->first()->storage_path)
                || data_get($responses->first()->metadata, 'storage_protocol') !== 'compressed_artifact_v2'
                || ! filled($requests->first()->storage_path)
                || data_get($requests->first()->metadata, 'storage_protocol') !== 'compressed_artifact_v2') return $this->blocked($refusal);
            $response = $ledger->readArtifactPayload($responses->first()); $request = $ledger->readArtifactPayload($requests->first());
            if (! is_array($response) || ! is_array($request) || $responses->first()->sha256 !== $run->response_hash
                || array_diff(array_keys($response), ['terminal_replay_envelope', 'data_quality', 'trade_ledger_hash', 'total_trades', 'displayed_trade_count']) !== []
                || count($response) !== 5
                || array_diff(array_keys((array) ($response['data_quality'] ?? [])), ['decision_trace']) !== []
                || array_diff(array_keys((array) data_get($response, 'data_quality.decision_trace', [])), ['requested', 'complete', 'reason']) !== []
                || array_diff(array_keys((array) ($response['terminal_replay_envelope'] ?? [])), ['status', 'response_available', 'reason_code', 'error_class', 'error_message']) !== []
                || count((array) ($response['terminal_replay_envelope'] ?? [])) !== 5
                || data_get($response, 'terminal_replay_envelope.error_class') !== $run->error_class
                || data_get($response, 'terminal_replay_envelope.error_message') !== $run->error_message
                || data_get($response, 'terminal_replay_envelope.reason_code') !== data_get($run->metadata, 'reason_code')
                || data_get($response, 'displayed_trade_count') !== 0
                || data_get($response, 'terminal_replay_envelope.response_available') !== false
                || data_get($response, 'terminal_replay_envelope.status') !== 'technical_error'
                || ! $this->isNativeMtfValidatorRefusal((string) data_get($response, 'terminal_replay_envelope.error_message'))
                || data_get($response, 'data_quality.decision_trace.complete') !== false
                || data_get($response, 'total_trades') !== null || data_get($response, 'trade_ledger_hash') !== null
                || array_key_exists('trade_ledger', $response) || array_key_exists('decision_trace', $response)
                || ($request['evaluation_mode'] ?? null) !== 'incremental' || ($request['dataset_tail_rows'] ?? null) !== null
                || ($request['replay_dataset_hash'] ?? null) !== $identity['mtf_bundle_hash']
                || data_get($request, 'execution_contract.execution_hash') !== $identity['execution_hash']
                || $this->hash((array) ($request['research_release'] ?? [])) !== $this->hash($release)
                || $this->hash((array) ($request['mtf_snapshot_manifest'] ?? [])) !== $this->hash($identity['mtf_bundle_manifest'])
                || data_get($request, 'mtf_pilot.enabled') !== true
                || data_get($request, 'mtf_pilot.activation_status') !== 'execution_stream_bound') return $this->blocked($refusal);
            $strategies = collect((array) ($request['strategies'] ?? []));
            $strategy = $strategies->firstWhere('lab_agent_id', (int) $a->id);
            if (! $strategy || $this->compiler->parameterHash((array) ($strategy['parameters'] ?? [])) !== $this->compiler->parameterHash((array) $a->modelVersion->parameters)
                || ($strategy['strategy'] ?? null) !== $a->modelVersion->strategy || ($strategy['version'] ?? null) !== $a->modelVersion->version) return $this->blocked($refusal);
            $probe = (array) data_get($request, 'policy_context.prospective_probe_window', []);
            if (! app(ProspectiveRepairProbeWindowService::class)->attests($probe, [...$probe, 'complete' => true])
                || ($probe['dataset_hash'] ?? null) !== $identity['mtf_bundle_hash'] || ($probe['execution_hash'] ?? null) !== $identity['execution_hash']
                || $this->hash((array) data_get($request, 'policy_context.prospective_clean_discovery_scope', [])) !== $this->hash($identity['discovery_scope'])) return $this->blocked($refusal);
            foreach (['loaded_rows', 'warmup_rows', 'evaluated_rows', 'loaded_start', 'loaded_end', 'evaluated_start', 'evaluated_end', 'evaluated_month_counts'] as $field) {
                if (($probe[$field] ?? null) !== data_get($identity, 'discovery_scope.calendar.'.$field)) return $this->blocked($refusal);
            }
            if ($probeHash !== null && $probeHash !== $probe['contract_hash']) return $this->blocked($refusal);
            $probeHash = $probe['contract_hash'];
            $runProof[] = [$run->run_id, $run->request_hash, $run->response_hash, $requests->first()->sha256,
                $ledger->verifiedModelRuntimeIdentity($run)['artifact_hash'], $run->code_hash, $run->parameter_hash, $run->finished_at->toIso8601String()];
        }
        $unexecuted = [];
        foreach ($agents as $a) {
            if ($runs->contains('lab_agent_id', $a->id)) continue;
            // Batch admission has no evaluator run: re-attest its exact sealed
            // control and native error instead of fabricating a zero outcome.
            $admission = app(FrozenControlScreeningAdmissionService::class)->admission($a);
            $control = $agents->firstWhere('id', (int) ($admission['control_agent_id'] ?? 0));
            $expectedControlId = $a->origin === 'academy_experiment'
                ? (int) $primary->first(fn ($p) => data_get($p->modelVersion->metadata, 'academy_experiment.arm_role') === 'frozen_control')?->id
                : (int) data_get(collect((array) ($kernel['pairs'] ?? []))->firstWhere('candidate_agent_id', (int) $a->id), 'control_agent_id', 0);
            if (($admission['status'] ?? null) !== 'blocked' || ($admission['reason'] ?? null) !== 'FROZEN_CONTROL_REPLAY_INCOMPLETE'
                || ! $control || ! $runs->contains('lab_agent_id', $control->id)
                || $expectedControlId <= 0 || $expectedControlId !== (int) $control->id
                || $a->decision_reason !== 'Frozen control admission failed before screening; strategy verdict withheld: FROZEN_CONTROL_REPLAY_INCOMPLETE.'
                || LabEvidenceArtifact::where('lab_agent_id', $a->id)->exists()) return $this->blocked($refusal);
            $unexecuted[] = ['agent_id' => $a->id, 'control_agent_id' => $control->id, 'reason' => $admission['reason'],
                'parameter_hash' => $this->compiler->parameterHash((array) $a->modelVersion->parameters), 'scientific_outcome_observed' => false];
        }
        $receipts = ResearchExperimentReceipt::with('workItems')->where('source_type', 'edge_academy_trial')->where('source_id', $trial->id)
            ->where('classification', 'TECHNICAL_QUARANTINE')->get();
        $receipt = $receipts->first(); $works = $receipt?->workItems->where('work_type', 'academy_technical_quarantine');
        if ($receipts->count() !== 1 || $works?->count() !== 1 || $works->first()->status !== 'blocked' || (int) $works->first()->attempts !== 0
            || data_get($receipt->payload, 'evidence.academy_settlement.status') !== 'technical_quarantine'
            || (array) data_get($receipt->payload, 'evidence.immutable_arm_evidence', []) !== []) return $this->blocked($refusal);
        $replacement = ['protocol' => self::VALIDATOR_REPLACEMENT_PROTOCOL, 'fault' => 'AUTONOMOUS_MTF_MANIFEST_INVALID',
            'technical_retry_of_trial_id' => (int) $trial->id, 'technical_retry_of_generation_id' => (int) $generation->id,
            'source_receipt_id' => (int) $receipt->id, 'source_work_item_id' => (int) $works->first()->id,
            'original_question_key' => $cold['key'], 'original_budget_scope' => $scope,
            'old_source_hash' => $identity['source_evaluator_hash'], 'old_python_source_hash' => $identity['python_source_hash'],
            'new_source_hash' => $dependencies['evaluator_hash'], 'new_python_source_hash' => $dependencies['python_source_hash'],
            'run_evidence_hash' => $this->hash($runProof), 'unexecuted_dependent_arms' => $unexecuted,
            'preregistered_discovery_plan' => $plan, 'probe_contract_hash' => $probeHash,
            'maximum_validator_replacements' => 1, 'maximum_total_cohorts' => 2,
            'scientific_outcome_observed' => false, 'market_evidence_reused' => false,
            'independent_evidence' => false, 'scientific_question_budget_reset' => false];
        $key = $this->hash([self::VALIDATOR_REPLACEMENT_PROTOCOL, $replacement, $dependencies, $compiled]);
        return ['protocol' => self::PROTOCOL, 'status' => 'would_prepare_cold_start', 'trial_id' => 0,
            'baseline_model_version_id' => $baseline->id, 'baseline_agent_id' => $baselineAgent->id,
            'baseline_parameter_hash' => $frozen['baseline_parameter_hash'],
            'cold_start' => ['protocol' => self::COLD_START_PROTOCOL, 'key' => $key, 'budget_scope' => $scope,
                'dependencies' => $dependencies, 'preview_hash' => $this->hash($compiled), 'axis' => $preview['axis'],
                'trial_type' => $preview['trial_type'], 'maximum_cohorts_per_scope' => 1, 'validator_replacement' => $replacement],
            'source_role' => 'unobserved_mtf_validator_replacement_same_question', 'stage_depth' => 0,
            'primary_proof_seats' => count($compiled['arms']), 'research_only' => true, 'promotion_evidence' => false, 'independent_causal_skill' => false];
    }

    private function isNativeMtfValidatorRefusal(string $message): bool
    {
        $payload = json_decode($message, true);
        return is_array($payload) && $payload === ['detail' => 'AUTONOMOUS_MTF_MANIFEST_INVALID'];
    }

    /** Re-attest old terminal diagnostics without requiring or authorizing a fresh source/retry. */
    public function validatorTerminalDispositionForAgent(LabAgent $agent): ?array
    {
        $generation = $agent->generation()->with('agents.modelVersion')->first();
        if (! $generation || $generation->trigger_type !== 'academy_experiment' || $generation->status !== 'technical_quarantine'
            || data_get($generation->trigger_context, 'prospective_source_identity.data_role') !== 'pre_2026_discovery_only') return null;
        $trial = DB::table('edge_academy_trials')->find((int) data_get($generation->trigger_context, 'academy_trial_id'));
        $passport = $trial ? DB::table('edge_academy_passports')->find($trial->edge_academy_passport_id) : null;
        if (! $passport || $trial->status !== 'technical_quarantine' || $trial->settled_at === null) return null;
        $frozen = (array) (json_decode((string) $passport->frozen_upstream_contract, true) ?: []);
        $identity = (array) ($frozen['prospective_source_identity'] ?? []);
        $runs = LabEvaluationRun::where(fn ($q) => $q->where('lab_generation_id', $generation->id)
            ->orWhereIn('lab_agent_id', $generation->agents->pluck('id')))->orderBy('id')->get();
        if (! $runs->contains(fn ($r) => $this->isNativeMtfValidatorRefusal((string) $r->error_message))) return null;
        // Re-read compressed bytes on every classification. A persisted hash
        // label or a long-lived worker cache must not hide artifact corruption.
        // This path never loads the 15k candles or current archive readiness.
        $dependencies = ['foundation_sha256' => $identity['data_hash'] ?? null, 'execution_hash' => $identity['execution_hash'] ?? null,
                'mtf_bundle_hash' => $identity['mtf_bundle_hash'] ?? null, 'evaluator_hash' => $identity['source_evaluator_hash'] ?? null,
                'python_source_hash' => $identity['python_source_hash'] ?? null, 'discovery_scope' => $identity['discovery_scope'] ?? [],
                'mtf_source_sha256' => collect((array) data_get($identity, 'mtf_bundle_manifest.streams'))->only(['M5', 'M15', 'H1', 'H4'])
                    ->map(fn ($s) => $s['sha256'] ?? null)->all()];
        try { $proof = $this->validatorReplacementProposal(collect([$passport]), $dependencies, (string) data_get($identity, 'cold_start.budget_scope'), true); }
        catch (RuntimeException|\ErrorException) { $proof = null; }
        return ($proof['status'] ?? null) === 'would_prepare_cold_start'
                ? ['reason_code' => 'IMMUTABLE_ACADEMY_MTF_VALIDATOR_REFUSAL', 'strategy_verdict' => 'withheld',
                    'scientific_outcome_observed' => false, 'promotion_evidence' => false, 'trial_id' => (int) $trial->id,
                    'generation_id' => (int) $generation->id, 'run_evidence_hash' => data_get($proof, 'cold_start.validator_replacement.run_evidence_hash')]
            : null;
    }

    /** One output-preserving preparation repair; source changes never renew the question budget. */
    private function preparationReplacementProposal($sameScope, array $dependencies, string $scope): ?array
    {
        $refusal = 'ACADEMY_PREPARATION_REPAIR_ALLOWANCE_EXHAUSTED';
        $rows = $sameScope->sortBy('id')->values();
        if ($rows->contains(fn ($row): bool => data_get(json_decode($row->frozen_upstream_contract, true),
            'prospective_source_identity.cold_start.preparation_replacement.protocol') === self::PREPARATION_REPLACEMENT_PROTOCOL)) {
            return $this->blocked($refusal);
        }
        if ($rows->count() > 2) return $this->blocked($refusal);
        $passport = $rows->last();
        $frozen = (array) (json_decode((string) $passport->frozen_upstream_contract, true) ?: []);
        $identity = (array) ($frozen['prospective_source_identity'] ?? []);
        $cold = (array) ($identity['cold_start'] ?? []);
        $trials = DB::table('edge_academy_trials')->where('edge_academy_passport_id', $passport->id)->get();
        if ($trials->count() !== 1) return null;
        $trial = $trials->first();
        $generations = LabGeneration::query()->where('trigger_context->academy_trial_id', (int) $trial->id)->get();
        if ($generations->count() !== 1) return null;
        $generation = $generations->first();
        $saved = (array) data_get($generation->trigger_context, 'academy_preparation_containment', []);
        if ($saved === []) return null;
        if (($saved['protocol'] ?? null) !== self::PREPARATION_CONTAINMENT_PROTOCOL || ($saved['status'] ?? null) !== 'terminal'
            || ($saved['fault'] ?? null) !== self::PREPARATION_FAULT || ($saved['scientific_outcome_observed'] ?? null) !== false
            || ($saved['scientific_question_budget_reset'] ?? null) !== false || ($saved['no_active_runs'] ?? null) !== true
            || ($saved['queue_drained'] ?? null) !== true || $trial->status !== 'technical_quarantine' || $trial->settled_at === null
            || $generation->status !== 'technical_quarantine' || isset($cold['preparation_replacement'])
            || hash_equals((string) ($identity['source_evaluator_hash'] ?? ''), (string) $dependencies['evaluator_hash'])
            || hash_equals((string) ($identity['python_source_hash'] ?? ''), (string) $dependencies['python_source_hash'])) {
            return $this->blocked('ACADEMY_PREPARATION_REPAIR_FRESH_SOURCE_AND_TERMINAL_PROOF_REQUIRED');
        }
        if ($rows->count() === 2) {
            // Re-attest the constructor-only predecessor with its original
            // dependencies; a second unrelated question is not this chain.
            $prior = $this->technicalReplacementProposal(collect([$rows->first()]), (array) ($cold['dependencies'] ?? []), $scope);
            if (($prior['status'] ?? null) !== 'would_prepare_cold_start'
                || $this->hash((array) data_get($prior, 'cold_start.technical_replacement')) !== $this->hash((array) ($cold['technical_replacement'] ?? []))) {
                return $this->blocked('ACADEMY_PREPARATION_REPAIR_PREDECESSOR_ATTESTATION_REQUIRED');
            }
        } elseif (isset($cold['technical_replacement'])) return $this->blocked('ACADEMY_PREPARATION_REPAIR_CHAIN_MISMATCH');
        try { $proof = $this->preparationContainmentProof((int) $trial->id, (int) $generation->id, null, false); }
        catch (RuntimeException $error) { return $this->blocked($error->getMessage()); }
        $queue = app(LabQueueJobInspector::class)->generationQueueBacklog($proof['agent_ids']);
        if ($proof['active_run_ids'] !== [] || ($queue['available'] ?? true) !== true || ($queue['total'] ?? null) !== 0
            || $proof['generation']->agents->contains(fn ($agent): bool => $agent->lifecycle_status !== 'technical_quarantine')
            || ! hash_equals((string) $saved['run_evidence_hash'], (string) $proof['run_evidence_hash'])) {
            return $this->blocked('ACADEMY_PREPARATION_REPAIR_DRAINED_IMMUTABLE_HISTORY_REQUIRED');
        }
        $approval = \App\Models\SystemEvent::query()->find((int) ($saved['approval_event_id'] ?? 0));
        if (! $approval || $approval->event_type !== 'learning_protocol_operator_approval'
            || data_get($approval->payload, 'operation') !== 'academy-preparation-containment'
            || (int) data_get($approval->payload, 'scope.trial_id') !== (int) $trial->id
            || (int) data_get($approval->payload, 'scope.generation_id') !== (int) $generation->id) {
            return $this->blocked('ACADEMY_PREPARATION_REPAIR_APPROVED_DISPOSITION_REQUIRED');
        }
        if (! hash_equals((string) $identity['data_hash'], (string) $dependencies['foundation_sha256'])
            || ! hash_equals((string) $identity['execution_hash'], (string) $dependencies['execution_hash'])
            || ! hash_equals((string) $identity['mtf_bundle_hash'], (string) $dependencies['mtf_bundle_hash'])
            || $this->hash(collect((array) data_get($identity, 'mtf_bundle_manifest.streams'))->only(['M5', 'M15', 'H1', 'H4'])
                ->map(fn ($stream) => $stream['sha256'] ?? null)->all()) !== $this->hash($dependencies['mtf_source_sha256'])) {
            return $this->blocked('ACADEMY_PREPARATION_REPAIR_SAME_DATA_MTF_COST_REQUIRED');
        }
        $baseline = ModelVersion::query()->find((int) $frozen['baseline_model_version_id']);
        $baselineAgent = LabAgent::query()->find((int) ($cold['hypothesis_baseline_agent_id'] ?? 0));
        if (! $baseline || ! $baselineAgent || (int) $baselineAgent->model_version_id !== (int) $baseline->id) {
            return $this->blocked('ACADEMY_PREPARATION_REPAIR_ORIGINAL_BASELINE_REQUIRED');
        }
        $preview = app(XauusdEdgeFormationAcademyService::class)->previewColdStartExperiment((array) $baseline->parameters);
        $compiled = $proof['compiled'];
        if (($preview['status'] ?? null) !== 'previewed' || $this->hash($preview['compiled_contract']) !== $this->hash($compiled)) {
            return $this->blocked('ACADEMY_PREPARATION_REPAIR_SAME_QUESTION_REQUIRED');
        }
        $oldKernel = (array) data_get($generation->trigger_context, 'causal_compounding_kernel', []);
        $discoveryPlan = ['protocol' => CausalCompoundingKernelService::PREPARATION_PLAN,
            'original_generation_id' => (int) $generation->id, 'original_trial_id' => (int) $trial->id,
            'baseline_model_version_id' => (int) $baseline->id,
            'data_hash' => $identity['data_hash'], 'execution_hash' => $identity['execution_hash'],
            'scientific_outcome_observed' => false, 'market_evidence_reused' => false,
            'abstain_seats' => count((array) ($oldKernel['abstain_agent_ids'] ?? [])), 'pairs' => []];
        foreach ((array) ($oldKernel['pairs'] ?? []) as $pair) {
            $control = $proof['generation']->agents->firstWhere('id', (int) $pair['control_agent_id']);
            $candidate = $proof['generation']->agents->firstWhere('id', (int) $pair['candidate_agent_id']);
            $discoveryPlan['pairs'][] = ['gene' => $pair['gene'], 'old_value' => $pair['old_value'], 'tested_value' => $pair['tested_value'],
                'selector_hash' => $pair['selector_hash'],
                'control_parameter_hash' => $this->compiler->parameterHash((array) $control->modelVersion->parameters),
                'candidate_parameter_hash' => $this->compiler->parameterHash((array) $candidate->modelVersion->parameters)];
        }
        $replacement = ['protocol' => self::PREPARATION_REPLACEMENT_PROTOCOL,
            'technical_retry_of_trial_id' => (int) $trial->id, 'technical_retry_of_generation_id' => (int) $generation->id,
            'checkpoint_artifact_id' => (int) $saved['checkpoint_artifact_id'], 'checkpoint_sha256' => $saved['checkpoint_sha256'],
            'original_checkpoint_sha256' => $saved['original_checkpoint_sha256'], 'original_control_run_id' => $saved['control_run_id'],
            'request_artifact_sha256' => $saved['request_artifact_sha256'], 'run_evidence_hash' => $saved['run_evidence_hash'],
            'approval_event_id' => (int) $saved['approval_event_id'], 'fault' => self::PREPARATION_FAULT,
            'original_question_key' => (string) ($cold['technical_replacement']['original_question_key'] ?? $cold['key']),
            'original_budget_scope' => $scope, 'old_source_hash' => $identity['source_evaluator_hash'],
            'old_python_source_hash' => $identity['python_source_hash'], 'new_source_hash' => $dependencies['evaluator_hash'],
            'new_python_source_hash' => $dependencies['python_source_hash'], 'maximum_preparation_replacements' => 1,
            'preregistered_discovery_plan' => $discoveryPlan,
            'maximum_total_cohorts' => 3, 'market_evidence_reused' => false, 'independent_evidence' => false,
            'scientific_question_budget_reset' => false];
        $key = $this->hash([self::PREPARATION_REPLACEMENT_PROTOCOL, $replacement, $dependencies, $compiled]);
        return ['protocol' => self::PROTOCOL, 'status' => 'would_prepare_cold_start', 'trial_id' => 0,
            'baseline_model_version_id' => $baseline->id, 'baseline_agent_id' => $baselineAgent->id,
            'baseline_parameter_hash' => $frozen['baseline_parameter_hash'],
            'cold_start' => ['protocol' => self::COLD_START_PROTOCOL, 'key' => $key, 'budget_scope' => $scope,
                'dependencies' => $dependencies, 'preview_hash' => $this->hash($compiled), 'axis' => $preview['axis'],
                'trial_type' => $preview['trial_type'], 'maximum_cohorts_per_scope' => 1, 'preparation_replacement' => $replacement],
            'source_role' => 'contained_preparation_repair_same_unobserved_question', 'stage_depth' => 0,
            'primary_proof_seats' => count($compiled['arms']), 'research_only' => true, 'promotion_evidence' => false,
            'independent_causal_skill' => false];
    }

    /** One changed-source attempt for an entirely unobserved, attested identity failure. */
    private function technicalReplacementProposal($sameScope, array $dependencies, string $scope): array
    {
        $refusal = 'ACADEMY_COLD_START_SCOPE_BUDGET_EXHAUSTED';
        // The original question plus ONE technical replacement is the whole
        // budget. A code release, manifest label or failed replacement never
        // renews it, even when that attempt produced no market observation.
        if ($sameScope->count() !== 1) return $this->blocked($refusal);
        $passport = $sameScope->first();
        $frozen = (array) (json_decode((string) $passport->frozen_upstream_contract, true) ?: []);
        $identity = (array) ($frozen['prospective_source_identity'] ?? []);
        $cold = (array) ($identity['cold_start'] ?? []);
        if (($frozen['protocol'] ?? null) !== XauusdEdgeFormationAcademyService::PROTOCOL
            || ($cold['protocol'] ?? null) !== self::COLD_START_PROTOCOL
            || isset($cold['technical_replacement'])
            || ($identity['source_identity_protocol'] ?? null) !== self::SOURCE_IDENTITY_PROTOCOL
            || ! preg_match('/^[a-f0-9]{64}$/', (string) ($identity['source_evaluator_hash'] ?? ''))
            || ! preg_match('/^[a-f0-9]{64}$/', (string) ($identity['python_source_hash'] ?? ''))
            || hash_equals((string) $identity['source_evaluator_hash'], (string) $dependencies['evaluator_hash'])) return $this->blocked($refusal);
        $trials = DB::table('edge_academy_trials')->where('edge_academy_passport_id', $passport->id)->get();
        if ($trials->count() !== 1) return $this->blocked($refusal);
        $trial = $trials->first();
        if ($trial->status !== 'technical_quarantine' || $trial->settled_at === null) return $this->blocked($refusal);
        $generations = LabGeneration::query()->with('agents.modelVersion')
            ->where('trigger_context->academy_trial_id', (int) $trial->id)->get();
        if ($generations->count() !== 1) return $this->blocked($refusal);
        $generation = $generations->first();
        $agents = $generation->agents;
        if ($generation->status !== 'technical_quarantine' || (int) $generation->population_size !== CausalCompoundingKernelService::POPULATION_SIZE
            || $agents->count() !== CausalCompoundingKernelService::POPULATION_SIZE
            || $agents->pluck('model_version_id')->unique()->count() !== CausalCompoundingKernelService::POPULATION_SIZE
            || $agents->contains(fn (LabAgent $agent): bool => $agent->lifecycle_status !== 'technical_quarantine'
                || ! $agent->modelVersion || (int) $agent->sample_count !== 0 || $agent->train_score !== null
                || $agent->validation_score !== null || $agent->forward_score !== null || $agent->profit_factor !== null
                || data_get($agent->modelVersion?->metadata, 'last_result') !== null
                || data_get($agent->modelVersion?->metadata, 'last_screen_result') !== null
                || data_get($agent->modelVersion?->metadata, 'parameter_fingerprint') !== null
                || data_get($agent->modelVersion?->metadata, 'universal_genome.local_adapter.parameters_hash') !== null)) return $this->blocked($refusal);
        $agentIds = $agents->pluck('id')->all();
        if (LabEvaluationRun::query()->where(fn ($query) => $query->where('lab_generation_id', $generation->id)->orWhereIn('lab_agent_id', $agentIds))->exists()
            || LabEvidenceArtifact::query()->where(fn ($query) => $query->where('lab_generation_id', $generation->id)->orWhereIn('lab_agent_id', $agentIds))->exists()
            || LabGateDecisionEvent::query()->where(fn ($query) => $query->where('lab_generation_id', $generation->id)->orWhereIn('lab_agent_id', $agentIds))->exists()) return $this->blocked($refusal);
        $expectedViolations = ['PARAMETER_FINGERPRINT_MISMATCH', 'UNIVERSAL_PARAMETERS_HASH_MISMATCH'];
        $quarantines = collect((array) data_get($generation->trigger_context, 'draft_integrity_quarantines', []));
        $events = LabLifecycleEvent::query()->where('lab_generation_id', $generation->id)->where('event_type', 'draft_integrity_quarantine')->get();
        if ($quarantines->count() !== $agents->count() || $events->count() !== $agents->count()
            || $quarantines->pluck('agent_id')->sort()->values()->all() !== collect($agentIds)->sort()->values()->all()
            || $events->pluck('lab_agent_id')->sort()->values()->all() !== collect($agentIds)->sort()->values()->all()) return $this->blocked($refusal);
        foreach ($agents as $agent) {
            $quarantine = $quarantines->firstWhere('agent_id', $agent->id);
            $event = $events->firstWhere('lab_agent_id', $agent->id);
            if (! $event || $event->run_id !== null || $event->phase !== 'screening'
                || $event->reason_code !== 'DRAFT_IDENTITY_INTEGRITY_BREACH'
                || $event->source !== \App\Console\Commands\DispatchLabGeneration::class
                || $event->from_status !== 'draft' || $event->to_status !== 'technical_quarantine'
                || data_get($event->payload, 'quality_verdict') !== 'withheld'
                || data_get($event->payload, 'promotion_evidence') !== false
                || data_get($quarantine, 'promotion_evidence') !== false
                || collect((array) data_get($event->payload, 'violations'))->sort()->values()->all() !== $expectedViolations
                || collect((array) data_get($quarantine, 'violations'))->sort()->values()->all() !== $expectedViolations) return $this->blocked($refusal);
        }
        $baseline = ModelVersion::query()->find((int) ($frozen['baseline_model_version_id'] ?? 0));
        $baselineAgent = LabAgent::query()->find((int) ($cold['hypothesis_baseline_agent_id'] ?? 0));
        $preview = $baseline ? app(XauusdEdgeFormationAcademyService::class)->previewColdStartExperiment((array) $baseline->parameters) : [];
        $trialCompiled = $baseline ? $this->compiler->compile([
            'axis' => $preview['axis'] ?? '', 'arms' => (array) (json_decode((string) $trial->arms, true) ?: []),
        ], (array) $baseline->parameters, ['symbol' => 'XAUUSD', 'laboratory_timeframe' => 'H1', 'execution_timeframe' => 'M5']) : [];
        if (! $baseline || ! $baselineAgent || (int) $baselineAgent->model_version_id !== (int) $baseline->id
            || (int) $baselineAgent->generation?->ai_laboratory_id !== (int) $generation->ai_laboratory_id
            || ($preview['status'] ?? null) !== 'previewed'
            || ! hash_equals((string) ($frozen['baseline_parameter_hash'] ?? ''), $this->compiler->parameterHash((array) $baseline->parameters))
            || ! hash_equals((string) ($cold['preview_hash'] ?? ''), $this->hash($preview['compiled_contract']))
            || $this->hash($trialCompiled) !== $this->hash($preview['compiled_contract'])
            || $this->hash((array) (json_decode((string) $trial->density_contract, true) ?: [])) !== $this->hash((array) $preview['event_density_contract'])
            || $this->hash((array) data_get($generation->trigger_context, 'compiled_contract', [])) !== $this->hash($preview['compiled_contract'])
            || ! hash_equals((string) ($identity['data_hash'] ?? ''), (string) $dependencies['foundation_sha256'])
            || ! hash_equals((string) ($identity['execution_hash'] ?? ''), (string) $dependencies['execution_hash'])
            || ! hash_equals((string) ($identity['mtf_bundle_hash'] ?? ''), (string) $dependencies['mtf_bundle_hash'])
            || $this->hash(collect((array) data_get($identity, 'mtf_bundle_manifest.streams', []))->only(['M5', 'M15', 'H1', 'H4'])
                ->map(fn ($stream) => $stream['sha256'] ?? null)->all()) !== $this->hash($dependencies['mtf_source_sha256'])) return $this->blocked($refusal);
        $primary = $agents->where('origin', 'academy_experiment');
        $compiled = $preview['compiled_contract'];
        $kernel = (array) data_get($generation->trigger_context, 'causal_compounding_kernel', []);
        $kernelIds = collect((array) ($kernel['pairs'] ?? []))->flatMap(fn ($pair) => [
            (int) ($pair['control_agent_id'] ?? 0), (int) ($pair['candidate_agent_id'] ?? 0),
        ])->merge((array) ($kernel['abstain_agent_ids'] ?? []))->sort()->values()->all();
        if (($kernel['protocol'] ?? null) !== CausalCompoundingKernelService::PROTOCOL || ($kernel['state'] ?? null) !== 'sealed'
            || (int) ($kernel['population_size'] ?? 0) !== CausalCompoundingKernelService::POPULATION_SIZE
            || (int) ($kernel['causal_baseline_model_version_id'] ?? 0) !== (int) $baseline->id
            || ($kernel['data_hash'] ?? null) !== $identity['data_hash']
            || ($kernel['execution_hash'] ?? null) !== $identity['execution_hash']
            || $kernelIds !== $agents->where('origin', '!=', 'academy_experiment')->pluck('id')->sort()->values()->all()) return $this->blocked($refusal);
        if ($primary->count() !== count($compiled['arms'])
            || $primary->pluck('modelVersion.metadata.academy_experiment.arm_index')->unique()->count() !== $primary->count()) return $this->blocked($refusal);
        foreach ($primary as $agent) {
            $arm = $compiled['arms'][(int) data_get($agent->modelVersion->metadata, 'academy_experiment.arm_index', -1)] ?? [];
            if ($agent->strategy_family !== 'confirmation_entry_mtf'
                || data_get($agent->modelVersion->metadata, 'academy_experiment.protocol') !== self::PROTOCOL
                || (int) data_get($agent->modelVersion->metadata, 'academy_experiment.academy_trial_id', 0) !== (int) $trial->id
                || data_get($agent->modelVersion->metadata, 'academy_experiment.arm_role') !== ($arm['role'] ?? null)
                || data_get($agent->modelVersion->metadata, 'academy_experiment.parameter_hash') !== ($arm['parameter_hash'] ?? null)
                || data_get($agent->modelVersion->metadata, 'academy_experiment.contract_hash') !== hash('sha256', json_encode($compiled))
                || (int) data_get($agent->modelVersion->metadata, 'academy_experiment.causal_baseline_model_version_id') !== (int) $baseline->id
                || ! hash_equals((string) ($arm['parameter_hash'] ?? ''), $this->compiler->parameterHash((array) $agent->modelVersion->parameters))) return $this->blocked($refusal);
        }
        $receipts = ResearchExperimentReceipt::query()->with('workItems')->where('source_type', 'edge_academy_trial')
            ->where('source_id', $trial->id)->where('classification', 'TECHNICAL_QUARANTINE')->get();
        if ($receipts->count() !== 1) return $this->blocked($refusal);
        $receipt = $receipts->first();
        $works = $receipt->workItems->where('work_type', 'academy_technical_quarantine');
        if ($works->count() !== 1 || $works->first()->status !== 'blocked' || (int) $works->first()->attempts !== 0
            || data_get($works->first()->payload, 'owner') !== ResearchLoopArbiterService::class
            || data_get($receipt->payload, 'evidence.academy_settlement.status') !== 'technical_quarantine'
            || (array) data_get($receipt->payload, 'evidence.immutable_arm_evidence', []) !== []) return $this->blocked($refusal);
        $replacement = ['protocol' => self::TECHNICAL_REPLACEMENT_PROTOCOL, 'technical_retry_of_trial_id' => (int) $trial->id,
            'technical_retry_of_generation_id' => (int) $generation->id, 'source_receipt_id' => (int) $receipt->id,
            'source_work_item_id' => (int) $works->first()->id, 'original_question_key' => (string) ($cold['key'] ?? ''),
            'original_budget_scope' => $scope, 'old_source_hash' => $identity['source_evaluator_hash'],
            'new_source_hash' => $dependencies['evaluator_hash'], 'maximum_technical_replacements' => 1,
            'market_evidence_reused' => false, 'independent_evidence' => false, 'scientific_question_budget_reset' => false];
        $key = $this->hash([self::TECHNICAL_REPLACEMENT_PROTOCOL, $replacement, $dependencies, $compiled]);
        return ['protocol' => self::PROTOCOL, 'status' => 'would_prepare_cold_start', 'trial_id' => 0,
            'baseline_model_version_id' => $baseline->id, 'baseline_agent_id' => $baselineAgent->id,
            'baseline_parameter_hash' => $frozen['baseline_parameter_hash'],
            'cold_start' => ['protocol' => self::COLD_START_PROTOCOL, 'key' => $key, 'budget_scope' => $scope,
                'dependencies' => $dependencies, 'preview_hash' => $this->hash($compiled), 'axis' => $preview['axis'],
                'trial_type' => $preview['trial_type'], 'maximum_cohorts_per_scope' => 1, 'technical_replacement' => $replacement],
            'source_role' => 'unobserved_technical_replacement_same_question', 'stage_depth' => 0,
            'primary_proof_seats' => count($compiled['arms']), 'research_only' => true, 'promotion_evidence' => false,
            'independent_causal_skill' => false];
    }

    private function coldStartDependencies(string $symbol, string $timeframe): array
    {
        $foundation = app(LabDatasetExportService::class)->foundationDependencyWatermark($symbol, $timeframe);
        if (($foundation['archive_present'] ?? false) !== true || ! filled($foundation['manifest_hash'] ?? null)) return ['ready' => false, 'reason' => 'ACADEMY_COLD_START_FOUNDATION_NOT_READY'];
        if (filled(config('services.xauusd_organism.clean_discovery_bundle_hash'))) {
            return $this->cleanDiscoveryDependencies($symbol, $timeframe, $foundation);
        }
        $mtf = app(MultiTimeframeSnapshotService::class)->agentValidationReadiness($symbol);
        if (($mtf['ready'] ?? false) !== true) return ['ready' => false, 'reason' => 'ACADEMY_COLD_START_MTF_NOT_READY'];
        $cutoff = (string) ($mtf['entry_cutoff'] ?? '');
        if ($cutoff === '' || ! CarbonImmutable::parse($cutoff)->lt(CarbonImmutable::parse('2026-01-01', 'UTC'))) return ['ready' => false, 'reason' => 'ACADEMY_COLD_START_PRE2026_ONLY'];
        $foundationPath = (string) ($foundation['path'] ?? storage_path('app/lab-datasets/foundation/'.strtoupper($symbol).'_'.strtoupper($timeframe).'_2005-2025.csv'));
        $foundationSha = is_file($foundationPath) ? hash_file('sha256', $foundationPath) : false;
        if (! is_string($foundationSha)) return ['ready' => false, 'reason' => 'ACADEMY_COLD_START_FOUNDATION_BYTES_UNAVAILABLE'];
        $bundles = LabGeneration::query()->whereHas('laboratory', fn ($query) => $query->where('symbol', $symbol)->where('timeframe', $timeframe))
            ->orderByDesc('id')->limit(10)->get();
        $bundleHash = null;
        $sourceHashes = [];
        foreach ($bundles as $generation) {
            $manifest = (array) data_get($generation->trigger_context, 'mtf_bundle_manifest', []);
            // A prospectively repaired archive is not the old generation's
            // quoted bundle. Wait for the canonical snapshot owner to freeze
            // the selected dataset; never copy or relabel its old sidecar.
            $repair = (array) ($mtf['prospective_m5_repair'] ?? []);
            if (in_array($repair['protocol'] ?? null, ['frozen_m5_gap_recovery_v1', 'frozen_m5_gap_recovery_v2'], true)
                && ($repair['verified'] ?? false) === true
                && data_get($manifest, 'datasets.M5') !== ($repair['dataset_key'] ?? null)) continue;
            if (! filled($manifest['bundle_hash'] ?? null) || ! filled($manifest['entry_last_candle_at'] ?? null)
                || ! CarbonImmutable::parse($manifest['entry_last_candle_at'])->equalTo(CarbonImmutable::parse($cutoff))) continue;
            $valid = true;
            foreach (['M5', 'M15', 'H1', 'H4'] as $stream) {
                $path = (string) data_get($manifest, "streams.{$stream}.path", '');
                $sha = (string) data_get($manifest, "streams.{$stream}.sha256", '');
                if ($path === '' || $sha === '' || ! is_file($path) || ! hash_equals($sha, (string) hash_file('sha256', $path))) { $valid = false; break; }
            }
            if ($valid) {
                $dataReadiness = app(GenerationSnapshotAdmissionService::class)->historicalDatasetReadiness($manifest);
                if (! $dataReadiness['allowed']) return ['ready' => false,
                    'reason' => 'GENERATION_MTF_M5_KNOWN_CANDLE_GAP', 'data_readiness' => $dataReadiness];
                $bundleHash = (string) $manifest['bundle_hash'];
                foreach (['M5', 'M15', 'H1', 'H4'] as $stream) $sourceHashes[$stream] = (string) data_get($manifest, "streams.{$stream}.sha256");
                break;
            }
        }
        if ($bundleHash === null) return ['ready' => false, 'reason' => 'ACADEMY_COLD_START_CURRENT_SEALED_MTF_BYTES_UNAVAILABLE'];
        return ['ready' => true, 'foundation' => $foundation, 'mtf_streams' => $mtf['streams'], 'entry_cutoff' => $cutoff,
            'foundation_sha256' => $foundationSha, 'mtf_bundle_hash' => $bundleHash, 'mtf_source_sha256' => $sourceHashes,
            'execution_hash' => app(ExecutionContractService::class)->for($symbol, 'M5')['execution_hash'],
            'evaluator_hash' => app(LabImmutableEvidenceService::class)->codeHash(),
            'python_source_hash' => app(ResearchReleaseSealService::class)->pythonHash(),
            'source_identity_protocol' => self::SOURCE_IDENTITY_PROTOCOL];
    }

    /** An explicit frozen discovery scope does not claim whole-archive readiness. */
    private function cleanDiscoveryDependencies(string $symbol, string $timeframe, array $foundation): array
    {
        $bundleHash = (string) config('services.xauusd_organism.clean_discovery_bundle_hash');
        if (preg_match('/^[a-f0-9]{64}$/D', $bundleHash) !== 1) return ['ready' => false, 'reason' => 'ACADEMY_DISCOVERY_BUNDLE_HASH_INVALID'];
        $manifestPath = storage_path('app/lab-datasets/mtf/'.$bundleHash.'/manifest.json');
        $manifest = is_file($manifestPath) ? json_decode((string) File::get($manifestPath), true) : null;
        if (! is_array($manifest) || ($manifest['bundle_hash'] ?? null) !== $bundleHash) return ['ready' => false, 'reason' => 'ACADEMY_DISCOVERY_FROZEN_MANIFEST_MISSING'];
        $owner = app(MultiTimeframeSnapshotService::class);
        $readiness = $owner->discoveryBundleReadiness($manifest);
        if (($readiness['allowed'] ?? false) !== true) return ['ready' => false, 'reason' => 'ACADEMY_DISCOVERY_BUNDLE_NOT_READY', 'data_readiness' => $readiness];
        $dataReadiness = app(GenerationSnapshotAdmissionService::class)->historicalDatasetReadiness($manifest);
        if (! $dataReadiness['allowed']) return ['ready' => false, 'reason' => 'GENERATION_MTF_M5_KNOWN_CANDLE_GAP', 'data_readiness' => $dataReadiness];
        $foundationPath = (string) ($foundation['path'] ?? storage_path('app/lab-datasets/foundation/'.strtoupper($symbol).'_'.strtoupper($timeframe).'_2005-2025.csv'));
        $foundationSha = is_file($foundationPath) ? hash_file('sha256', $foundationPath) : false;
        if (! is_string($foundationSha)) return ['ready' => false, 'reason' => 'ACADEMY_COLD_START_FOUNDATION_BYTES_UNAVAILABLE'];
        $sourceHashes = [];
        foreach (['M5', 'M15', 'H1', 'H4'] as $stream) $sourceHashes[$stream] = (string) data_get($manifest, "streams.{$stream}.sha256");
        return ['ready' => true, 'foundation' => $foundation, 'mtf_streams' => $manifest['streams'],
            'entry_cutoff' => $manifest['entry_last_candle_at'], 'foundation_sha256' => $foundationSha,
            'mtf_bundle_hash' => $bundleHash, 'mtf_source_sha256' => $sourceHashes,
            'discovery_scope' => $manifest['discovery_scope'], 'discovery_bundle_manifest' => $manifest, 'data_role' => $manifest['data_role'],
            'execution_hash' => app(ExecutionContractService::class)->for($symbol, 'M5')['execution_hash'],
            'evaluator_hash' => app(LabImmutableEvidenceService::class)->codeHash(),
            'python_source_hash' => app(ResearchReleaseSealService::class)->pythonHash(),
            'source_identity_protocol' => self::SOURCE_IDENTITY_PROTOCOL];
    }

    /**
     * Operator-owned technical containment, never research admission.
     * Commit the disposition before cancelling only this generation's batches;
     * active replay is allowed to finish and is never killed or rewritten.
     */
    public function containPreparation(int $trialId, int $generationId, ?string $checkpointPath = null,
        bool $apply = false, bool $finalize = false, array $approval = []): array
    {
        try {
            $proof = $this->preparationContainmentProof($trialId, $generationId, $checkpointPath);
        } catch (RuntimeException $error) {
            return $this->blocked($error->getMessage());
        }
        $generation = $proof['generation'];
        $saved = (array) data_get($generation->trigger_context, 'academy_preparation_containment', []);
        if ($finalize && $saved === []) return $this->blocked('ACADEMY_PREPARATION_CONTAINMENT_REQUIRED');
        if (($saved['status'] ?? null) === 'terminal') return ['status' => 'contained_terminal', 'reused' => true,
            'generation_id' => $generationId, 'trial_id' => $trialId, 'promotion_evidence' => false];
        if ($finalize) {
            $queue = app(LabQueueJobInspector::class)->generationQueueBacklog($proof['agent_ids']);
            if ($proof['active_run_ids'] !== [] || ($queue['available'] ?? true) !== true
                || ($queue['total'] ?? null) === null || (int) $queue['total'] > 0) {
                return ['status' => 'containment_draining', 'generation_id' => $generationId,
                    'active_run_ids' => $proof['active_run_ids'], 'queue_total' => $queue['total'] ?? null,
                    'new_replay_allowed' => false, 'promotion_evidence' => false];
            }
        }
        if (! $apply) return ['status' => $finalize ? 'would_finalize_containment' : 'would_contain_preparation',
            'generation_id' => $generationId, 'trial_id' => $trialId, 'batch_ids' => $proof['batch_ids'],
            'active_run_ids' => $proof['active_run_ids'], 'checkpoint_sha256' => $proof['checkpoint_sha256'],
            'fault' => self::PREPARATION_FAULT, 'promotion_evidence' => false];
        $event = \App\Models\SystemEvent::query()->find((int) ($approval['event_id'] ?? 0));
        if (! $event || $event->event_type !== 'learning_protocol_operator_approval'
            || data_get($event->payload, 'operation') !== 'academy-preparation-containment'
            || (int) data_get($event->payload, 'scope.generation_id') !== $generationId
            || (int) data_get($event->payload, 'scope.trial_id') !== $trialId) {
            return $this->blocked('ACADEMY_PREPARATION_OPERATOR_APPROVAL_REQUIRED');
        }
        if (! $finalize) {
            $saved = DB::transaction(function () use ($trialId, $generationId, $checkpointPath, $approval): array {
                $locked = LabGeneration::query()->lockForUpdate()->findOrFail($generationId);
                $existing = (array) data_get($locked->trigger_context, 'academy_preparation_containment', []);
                if ($existing !== []) return $existing;
                $proof = $this->preparationContainmentProof($trialId, $generationId, $checkpointPath);
                $artifact = app(LabImmutableEvidenceService::class)->recordArtifact(
                    $proof['control_run'], 'academy_preparation_checkpoint', $proof['checkpoint'], [
                        'protocol' => self::PREPARATION_CONTAINMENT_PROTOCOL,
                        'original_checkpoint_sha256' => $proof['checkpoint_sha256'],
                        'request_artifact_sha256' => $proof['request_artifact_sha256'],
                        'generation_id' => $generationId, 'trial_id' => $trialId,
                        'promotion_evidence' => false,
                    ]);
                $record = [
                    'protocol' => self::PREPARATION_CONTAINMENT_PROTOCOL, 'status' => 'draining',
                    'fault' => self::PREPARATION_FAULT, 'generation_id' => $generationId, 'trial_id' => $trialId,
                    'checkpoint_artifact_id' => $artifact->id, 'checkpoint_sha256' => $artifact->sha256,
                    'original_checkpoint_sha256' => $proof['checkpoint_sha256'],
                    'request_artifact_sha256' => $proof['request_artifact_sha256'],
                    'control_run_id' => $proof['control_run']->run_id,
                    'control_run_database_id' => $proof['control_run']->id,
                    'agent_ids' => $proof['agent_ids'], 'batch_ids' => $proof['batch_ids'],
                    'source_identity' => $proof['identity'],
                    'compiled_contract_hash' => $this->hash($proof['compiled']),
                    'baseline_parameter_hash' => $proof['compiled']['baseline_parameter_hash'],
                    'scientific_outcome_observed' => false, 'scientific_question_budget_reset' => false,
                    'approval_event_id' => $approval['event_id'], 'recorded_at' => now()->utc()->toIso8601String(),
                    'active_runs_must_drain' => true, 'promotion_evidence' => false,
                ];
                $locked->update(['trigger_context' => [...(array) $locked->trigger_context,
                    'academy_preparation_containment' => $record]]);
                app(LabImmutableEvidenceService::class)->recordLifecycle(null, 'academy_preparation_containment', [
                    'generation_id' => $generationId, 'reason_code' => 'ACADEMY_PREPARATION_TECHNICAL_CONTAINMENT',
                    'disposition' => $record, 'strategy_verdict' => 'withheld', 'promotion_evidence' => false,
                ], 'screening', $proof['control_run']->run_id, null, self::class);
                return $record;
            });
            // Crash between disposition and cancellation is resumable. Never
            // cancel before the durable technical owner exists.
            foreach ((array) $saved['batch_ids'] as $batchId) {
                \Illuminate\Support\Facades\Bus::findBatch((string) $batchId)?->cancel();
            }
            return ['status' => 'containment_draining', 'generation_id' => $generationId,
                'trial_id' => $trialId, 'batch_ids' => $saved['batch_ids'], 'reused' => $saved !== [],
                'new_replay_allowed' => false, 'promotion_evidence' => false];
        }
        DB::transaction(function () use ($trialId, $generationId): void {
            $locked = LabGeneration::query()->lockForUpdate()->findOrFail($generationId);
            $proof = $this->preparationContainmentProof($trialId, $generationId, null);
            if ($proof['active_run_ids'] !== []) throw new RuntimeException('ACADEMY_PREPARATION_ACTIVE_RUN_RACE');
            $queue = app(LabQueueJobInspector::class)->generationQueueBacklog($proof['agent_ids']);
            if (($queue['available'] ?? true) !== true || ($queue['total'] ?? null) === null || (int) $queue['total'] !== 0) {
                throw new RuntimeException('ACADEMY_PREPARATION_QUEUE_DRAIN_RACE');
            }
            foreach ($proof['generation']->agents as $agent) {
                if ($agent->lifecycle_status === 'technical_quarantine') continue;
                $from = (string) $agent->lifecycle_status;
                $agent->update(['lifecycle_status' => 'technical_quarantine',
                    'decision_reason' => 'ACADEMY_PREPARATION_TECHNICAL_CONTAINMENT; strategy verdict withheld.']);
                app(LabImmutableEvidenceService::class)->recordLifecycle($agent, 'academy_preparation_terminal_disposition', [
                    'reason_code' => 'ACADEMY_PREPARATION_TECHNICAL_CONTAINMENT', 'generation_id' => $generationId,
                    'trial_id' => $trialId, 'source_disposition_protocol' => self::PREPARATION_CONTAINMENT_PROTOCOL,
                    'strategy_verdict' => 'withheld', 'promotion_evidence' => false,
                ], 'screening', null, null, self::class, null, $from, 'technical_quarantine');
            }
            $disposition = (array) data_get($locked->trigger_context, 'academy_preparation_containment');
            $locked->update(['status' => 'technical_quarantine', 'completed_at' => now(),
                'trigger_context' => [...(array) $locked->trigger_context, 'academy_preparation_containment' => [
                    ...$disposition, 'status' => 'terminal', 'terminal_at' => now()->utc()->toIso8601String(),
                    'run_evidence_hash' => $proof['run_evidence_hash'], 'no_active_runs' => true,
                    'queue_drained' => true, 'scientific_outcome_observed' => false,
                ]]]);
            $primary = $proof['generation']->agents->firstWhere('origin', 'academy_experiment')->fresh();
            $this->settleOutcome($primary);
        });
        return ['status' => 'contained_terminal', 'generation_id' => $generationId,
            'trial_id' => $trialId, 'new_replay_allowed' => false, 'promotion_evidence' => false];
    }

    /** Shared read-only recovery disposition; never grants the separate repair allowance. */
    public function preparationTerminalDispositionForAgent(LabAgent $agent): ?array
    {
        $agent->loadMissing('generation');
        $generation = $agent->generation;
        $saved = (array) data_get($generation?->trigger_context, 'academy_preparation_containment', []);
        if ($agent->lifecycle_status !== 'technical_quarantine' || $generation?->status !== 'technical_quarantine'
            || ($saved['protocol'] ?? null) !== self::PREPARATION_CONTAINMENT_PROTOCOL || ($saved['status'] ?? null) !== 'terminal'
            || ($saved['scientific_outcome_observed'] ?? null) !== false || ($saved['scientific_question_budget_reset'] ?? null) !== false
            || ($saved['no_active_runs'] ?? null) !== true || ($saved['queue_drained'] ?? null) !== true
            || ! in_array((int) $agent->id, (array) ($saved['agent_ids'] ?? []), true)) return null;
        try { $proof = $this->preparationContainmentProof((int) ($saved['trial_id'] ?? 0), $generation->id, null, false, false); }
        catch (RuntimeException $error) { return null; }
        $approval = \App\Models\SystemEvent::query()->find((int) ($saved['approval_event_id'] ?? 0));
        $queue = app(LabQueueJobInspector::class)->generationQueueBacklog($proof['agent_ids']);
        if (! $approval || $approval->event_type !== 'learning_protocol_operator_approval'
            || data_get($approval->payload, 'operation') !== 'academy-preparation-containment'
            || (int) data_get($approval->payload, 'scope.trial_id') !== (int) $saved['trial_id']
            || (int) data_get($approval->payload, 'scope.generation_id') !== (int) $generation->id
            || $proof['trial']->status !== 'technical_quarantine' || $proof['trial']->settled_at === null
            || $proof['active_run_ids'] !== [] || ($queue['available'] ?? true) !== true || ($queue['total'] ?? null) !== 0
            || ! hash_equals((string) ($saved['run_evidence_hash'] ?? ''), $proof['run_evidence_hash'])
            || $proof['generation']->agents->contains(fn ($member): bool => $member->lifecycle_status !== 'technical_quarantine')) return null;
        // The generation-scoped owner precedes cancellation and includes
        // already-terminal controls as well as untouched queued candidates.
        $events = LabLifecycleEvent::query()->where('lab_generation_id', $generation->id)
            ->whereNull('lab_agent_id')->where('event_type', 'academy_preparation_containment')->get();
        if ($events->count() !== 1 || $events->first()->source !== self::class
            || $events->first()->reason_code !== 'ACADEMY_PREPARATION_TECHNICAL_CONTAINMENT'
            || $events->first()->run_id !== $saved['control_run_id']
            || data_get($events->first()->payload, 'disposition.protocol') !== self::PREPARATION_CONTAINMENT_PROTOCOL
            || data_get($events->first()->payload, 'disposition.checkpoint_artifact_id') !== $saved['checkpoint_artifact_id']
            || data_get($events->first()->payload, 'disposition.checkpoint_sha256') !== $saved['checkpoint_sha256']
            || data_get($events->first()->payload, 'disposition.approval_event_id') !== $saved['approval_event_id']
            || $this->hash((array) data_get($events->first()->payload, 'disposition.source_identity')) !== $this->hash($proof['identity'])
            || data_get($events->first()->payload, 'strategy_verdict') !== 'withheld'
            || data_get($events->first()->payload, 'promotion_evidence') !== false) return null;
        return ['protocol' => self::PREPARATION_CONTAINMENT_PROTOCOL, 'reason_code' => 'IMMUTABLE_ACADEMY_PREPARATION_CONTAINMENT',
            'source_lifecycle_event_id' => $events->first()->event_id, 'checkpoint_artifact_id' => (int) $saved['checkpoint_artifact_id'],
            'strategy_verdict' => 'withheld', 'replacement_authorized' => false, 'promotion_evidence' => false];
    }

    /** Re-read immutable request/error proof and exact source; labels alone cannot authorize containment. */
    private function preparationContainmentProof(int $trialId, int $generationId, ?string $checkpointPath,
        bool $requirePaused = true, bool $requireLatest = true): array
    {
        $generation = LabGeneration::query()->with('agents.modelVersion', 'laboratory')->find($generationId);
        $trial = DB::table('edge_academy_trials')->find($trialId);
        $passport = $trial ? DB::table('edge_academy_passports')->find($trial->edge_academy_passport_id) : null;
        $frozen = $passport ? (array) (json_decode((string) $passport->frozen_upstream_contract, true) ?: []) : [];
        $identity = (array) ($frozen['prospective_source_identity'] ?? []);
        $compiled = (array) data_get($generation?->trigger_context, 'compiled_contract', []);
        if (! $generation || ! $trial || ! $passport || $generation->trigger_type !== 'academy_experiment'
            || (int) data_get($generation->trigger_context, 'academy_trial_id') !== $trialId
            || ($requireLatest && (int) $generation->laboratory->generations()->latest('generation')->value('id') !== $generationId)
            || ($requirePaused && ! in_array(data_get(app(AutonomousModeService::class)->status('XAUUSD', 'H1'), 'state'), ['pausing', 'paused'], true))
            || ($frozen['protocol'] ?? null) !== XauusdEdgeFormationAcademyService::PROTOCOL
            || ($identity['source_identity_protocol'] ?? null) !== self::SOURCE_IDENTITY_PROTOCOL
            || data_get($identity, 'cold_start.protocol') !== self::COLD_START_PROTOCOL
            || ! in_array($trial->status, ['materialized', 'technical_quarantine'], true)
            || ! in_array($generation->status, ['queued', 'screening', 'technical_quarantine'], true)) {
            throw new RuntimeException('ACADEMY_PREPARATION_EXACT_PAUSED_OWNER_REQUIRED');
        }
        $agents = $generation->agents;
        if ($agents->contains(fn ($agent): bool => ! $agent->modelVersion)) {
            throw new RuntimeException('ACADEMY_PREPARATION_MODEL_ROSTER_INCOMPLETE');
        }
        $agentIds = $agents->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        $primary = $agents->where('origin', 'academy_experiment');
        $controls = $primary->filter(fn ($agent) => data_get($agent->modelVersion->metadata, 'academy_experiment.arm_role') === 'frozen_control');
        if ($agents->count() !== 20 || $primary->count() !== count($compiled['arms'] ?? [])
            || $controls->count() !== 1 || $agents->pluck('model_version_id')->unique()->count() !== 20
            || $primary->pluck('modelVersion.metadata.academy_experiment.arm_index')->unique()->count() !== $primary->count()
            || $agents->contains(fn ($agent): bool => ! $agent->modelVersion || (int) $agent->sample_count !== 0
                || $agent->train_score !== null || $agent->validation_score !== null || $agent->forward_score !== null
                || $agent->profit_factor !== null || data_get($agent->modelVersion->metadata, 'last_result') !== null
                || data_get($agent->modelVersion->metadata, 'last_screen_result') !== null)) {
            throw new RuntimeException('ACADEMY_PREPARATION_NO_SCIENTIFIC_PROJECTION_REQUIRED');
        }
        foreach (['data_hash', 'execution_hash', 'mtf_bundle_hash', 'source_evaluator_hash', 'python_source_hash', 'source_identity_protocol'] as $key) {
            if (! filled($identity[$key] ?? null)
                || $this->hash([$identity[$key]]) !== $this->hash([data_get($generation->trigger_context, $key)])) {
                throw new RuntimeException('ACADEMY_PREPARATION_SOURCE_IDENTITY_MISMATCH');
            }
        }
        if ($this->hash((array) ($identity['mtf_bundle_manifest'] ?? [])) !== $this->hash((array) data_get($generation->trigger_context, 'mtf_bundle_manifest', []))) {
            throw new RuntimeException('ACADEMY_PREPARATION_FROZEN_MTF_MANIFEST_CHANGED');
        }
        $expectedSnapshots = (array) ($identity['canonical_dataset_snapshots'] ?? []);
        $actualSnapshots = (array) data_get($generation->trigger_context, 'canonical_dataset_snapshots', []);
        // Canonical dispatch adds this one sidecar pointer; it must point to
        // the same frozen foundation, not permit any content/manifest change.
        if (! array_key_exists('manifest_path', (array) ($expectedSnapshots['foundation'] ?? []))
            && array_key_exists('manifest_path', (array) ($actualSnapshots['foundation'] ?? []))) {
            if ($actualSnapshots['foundation']['manifest_path'] !== (string) data_get($expectedSnapshots, 'foundation.path').'.manifest.json') {
                throw new RuntimeException('ACADEMY_PREPARATION_FOUNDATION_SIDECAR_CHANGED');
            }
            unset($actualSnapshots['foundation']['manifest_path']);
        }
        if ($this->hash($expectedSnapshots) !== $this->hash($actualSnapshots)) {
            throw new RuntimeException('ACADEMY_PREPARATION_FROZEN_SNAPSHOT_CHANGED');
        }
        $baseline = ModelVersion::query()->find((int) ($frozen['baseline_model_version_id'] ?? 0));
        $preview = $baseline ? app(XauusdEdgeFormationAcademyService::class)->previewColdStartExperiment((array) $baseline->parameters) : [];
        $trialCompiled = $baseline ? $this->compiler->compile([
            'axis' => $preview['axis'] ?? '', 'arms' => (array) (json_decode((string) $trial->arms, true) ?: []),
        ], (array) $baseline->parameters, ['symbol' => 'XAUUSD', 'laboratory_timeframe' => 'H1', 'execution_timeframe' => 'M5']) : [];
        if (! $baseline || ($preview['status'] ?? null) !== 'previewed'
            || ! hash_equals((string) ($frozen['baseline_parameter_hash'] ?? ''), $this->compiler->parameterHash((array) $baseline->parameters))
            || ! hash_equals((string) data_get($identity, 'cold_start.preview_hash', ''), $this->hash($preview['compiled_contract']))
            || $this->hash($trialCompiled) !== $this->hash($compiled)
            || $this->hash($compiled) !== $this->hash($preview['compiled_contract'])
            || $this->hash((array) json_decode((string) $trial->density_contract, true)) !== $this->hash($preview['event_density_contract'])) {
            throw new RuntimeException('ACADEMY_PREPARATION_ORIGINAL_QUESTION_CHANGED');
        }
        $kernel = (array) data_get($generation->trigger_context, 'causal_compounding_kernel', []);
        $kernelIds = collect((array) ($kernel['pairs'] ?? []))->flatMap(fn ($pair) => [
            (int) ($pair['control_agent_id'] ?? 0), (int) ($pair['candidate_agent_id'] ?? 0),
        ])->merge((array) ($kernel['abstain_agent_ids'] ?? []))->sort()->values()->all();
        if (($kernel['protocol'] ?? null) !== CausalCompoundingKernelService::PROTOCOL || ($kernel['state'] ?? null) !== 'sealed'
            || ($kernel['data_hash'] ?? null) !== $identity['data_hash'] || ($kernel['execution_hash'] ?? null) !== $identity['execution_hash']
            || (int) ($kernel['causal_baseline_model_version_id'] ?? 0) !== (int) $baseline->id
            || $kernelIds !== $agents->where('origin', '!=', 'academy_experiment')->pluck('id')->sort()->values()->all()) {
            throw new RuntimeException('ACADEMY_PREPARATION_KERNEL_ROSTER_CHANGED');
        }
        foreach ((array) ($kernel['pairs'] ?? []) as $pair) {
            $control = $agents->firstWhere('id', (int) $pair['control_agent_id']);
            $candidate = $agents->firstWhere('id', (int) $pair['candidate_agent_id']);
            if (! $control || ! $candidate || ! app(ExactCausalBaselineService::class)->matches($candidate, $control)
                || $this->compiler->parameterHash((array) $control->modelVersion->parameters) !== $frozen['baseline_parameter_hash']
                || data_get($control->modelVersion->metadata, 'control_pair_contract.pair_key') !== $pair['pair_key']
                || data_get($candidate->modelVersion->metadata, 'control_pair_contract.pair_key') !== $pair['pair_key']
                || (int) data_get($candidate->modelVersion->metadata, 'control_pair_contract.control_agent_id') !== (int) $control->id
                || data_get($candidate->parameter_diff, $pair['gene'].'.old') !== $pair['old_value']
                || data_get($candidate->parameter_diff, $pair['gene'].'.new') !== $pair['tested_value']) {
                throw new RuntimeException('ACADEMY_PREPARATION_KERNEL_PARAMETER_CHANGED');
            }
        }
        foreach ((array) ($kernel['abstain_agent_ids'] ?? []) as $id) {
            if ($this->compiler->parameterHash((array) $agents->firstWhere('id', (int) $id)?->modelVersion?->parameters) !== $frozen['baseline_parameter_hash']) {
                throw new RuntimeException('ACADEMY_PREPARATION_ABSTAIN_PARAMETER_CHANGED');
            }
        }
        foreach ($primary as $agent) {
            $metadata = (array) $agent->modelVersion->metadata;
            $index = (int) data_get($metadata, 'academy_experiment.arm_index', -1);
            $arm = $compiled['arms'][$index] ?? [];
            if (data_get($metadata, 'academy_experiment.arm_role') !== ($arm['role'] ?? null)
                || ! hash_equals((string) ($arm['parameter_hash'] ?? ''), $this->compiler->parameterHash((array) $agent->modelVersion->parameters))
                || (int) data_get($metadata, 'academy_experiment.academy_trial_id') !== $trialId) {
                throw new RuntimeException('ACADEMY_PREPARATION_COMPILED_ROSTER_MISMATCH');
            }
        }
        if (LabGateDecisionEvent::query()->where('lab_generation_id', $generationId)->exists()) {
            throw new RuntimeException('ACADEMY_PREPARATION_SCIENTIFIC_GATE_EXISTS');
        }
        $runs = LabEvaluationRun::query()->where('lab_generation_id', $generationId)->get();
        $ledger = app(LabImmutableEvidenceService::class);
        foreach ($runs as $run) {
            $agent = $agents->firstWhere('id', $run->lab_agent_id);
            if (! $agent || (int) $run->model_version_id !== (int) $agent->model_version_id
                || ! hash_equals((string) $identity['source_evaluator_hash'], (string) $run->code_hash)
                || ! hash_equals($ledger->parameterHash($agent), (string) $run->parameter_hash)
                || $run->status === 'completed' || filled($run->trade_ledger_hash)
                || ! in_array($run->status, ['started', 'running', 'processing', 'technical_error', 'skipped', 'retry_released'], true)) {
                throw new RuntimeException('ACADEMY_PREPARATION_RUN_SOURCE_OR_OUTCOME_MISMATCH');
            }
            $responses = LabEvidenceArtifact::query()->where('run_id', $run->run_id)->where('artifact_type', 'evaluation_response')->get();
            foreach ($responses as $artifact) {
                $payload = $ledger->readArtifactPayload($artifact);
                if (! is_array($payload) || ! hash_equals((string) $run->response_hash, (string) $artifact->sha256)
                    || data_get($payload, 'terminal_replay_envelope.response_available') !== false
                    || data_get($payload, 'terminal_replay_envelope.status') !== $run->status
                    || data_get($payload, 'data_quality.decision_trace.complete') !== false
                    || data_get($payload, 'total_trades') !== null || data_get($payload, 'trade_ledger_hash') !== null
                    || array_key_exists('trade_ledger', $payload) || array_key_exists('decision_trace', $payload)) {
                    throw new RuntimeException('ACADEMY_PREPARATION_SCIENTIFIC_RESPONSE_EXISTS');
                }
            }
            if (filled($run->response_hash) && $responses->count() !== 1) throw new RuntimeException('ACADEMY_PREPARATION_RESPONSE_SEAL_MISSING');
        }
        if (LabEvidenceArtifact::query()->where('lab_generation_id', $generationId)->whereIn('artifact_type', ['trade_ledger', 'decision_trace', 'decision_events'])->exists()) {
            throw new RuntimeException('ACADEMY_PREPARATION_SCIENTIFIC_ARTIFACT_EXISTS');
        }
        $controlRuns = $runs->where('lab_agent_id', $controls->first()->id);
        $controlRun = $controlRuns->first();
        if ($controlRuns->count() !== 1 || ! $controlRun || $controlRun->status !== 'technical_error'
            || ! str_contains(strtolower((string) $controlRun->error_message), 'bounded ai replay exceeded 900s')
            || ! $controlRun->started_at || ! $controlRun->finished_at || ! filled($controlRun->request_hash)) {
            throw new RuntimeException('ACADEMY_PREPARATION_BOUND_900_TIMEOUT_REQUIRED');
        }
        $requests = LabEvidenceArtifact::query()->where('run_id', $controlRun->run_id)->where('artifact_type', 'evaluation_request')->get();
        if ($requests->count() !== 1) throw new RuntimeException('ACADEMY_PREPARATION_IMMUTABLE_REQUEST_REQUIRED');
        $requestArtifact = $requests->first();
        $request = $ledger->readArtifactPayload($requestArtifact);
        if (! is_array($request) || ! hash_equals((string) $controlRun->request_hash, (string) data_get($requestArtifact->metadata, 'request_hash'))
            || count($request['strategies'] ?? []) !== 1 || ($request['evaluation_mode'] ?? null) !== 'incremental'
            || ($request['replay_dataset_hash'] ?? null) !== $identity['mtf_bundle_hash']
            || data_get($request, 'execution_contract.execution_hash') !== $identity['execution_hash']) {
            throw new RuntimeException('ACADEMY_PREPARATION_REQUEST_SOURCE_MISMATCH');
        }
        $saved = (array) data_get($generation->trigger_context, 'academy_preparation_containment', []);
        if ($saved !== []) {
            if (($saved['protocol'] ?? null) !== self::PREPARATION_CONTAINMENT_PROTOCOL || ($saved['fault'] ?? null) !== self::PREPARATION_FAULT
                || (int) $saved['trial_id'] !== $trialId || ($saved['control_run_id'] ?? null) !== $controlRun->run_id
                || $this->hash($saved['source_identity'] ?? []) !== $this->hash($identity)
                || $this->hash($saved['agent_ids'] ?? []) !== $this->hash($agentIds)
                || ($saved['compiled_contract_hash'] ?? null) !== $this->hash($compiled)) {
                throw new RuntimeException('ACADEMY_PREPARATION_DISPOSITION_CHANGED');
            }
            $artifact = LabEvidenceArtifact::query()->find((int) ($saved['checkpoint_artifact_id'] ?? 0));
            $checkpoint = $artifact ? $ledger->readArtifactPayload($artifact) : null;
            if (! $artifact || $artifact->artifact_type !== 'academy_preparation_checkpoint' || $artifact->run_id !== $controlRun->run_id
                || ! hash_equals((string) $saved['checkpoint_sha256'], (string) $artifact->sha256)
                || ($saved['request_artifact_sha256'] ?? null) !== $requestArtifact->sha256) {
                throw new RuntimeException('ACADEMY_PREPARATION_CHECKPOINT_SEAL_MISSING');
            }
            $checkpointSha = (string) $saved['original_checkpoint_sha256'];
        } else {
            $root = realpath(base_path('../ai-service-python/.runtime/replay-checkpoints'));
            $path = $checkpointPath ? realpath($checkpointPath) : false;
            if (! $root || ! $path || strcasecmp(dirname($path), $root) !== 0 || ! is_file($path)) {
                throw new RuntimeException('ACADEMY_PREPARATION_LOCAL_CHECKPOINT_REQUIRED');
            }
            $raw = File::get($path);
            $checkpoint = json_decode($raw, true);
            $checkpointSha = hash('sha256', $raw);
        }
        if (! is_array($checkpoint) || ($checkpoint['protocol'] ?? null) !== 'replay_checkpoint_v1'
            || ($checkpoint['stage'] ?? null) !== 'snapshot_loaded' || ($checkpoint['completed_count'] ?? null) !== 0
            || ($checkpoint['completed_candidates'] ?? null) !== [] || ($checkpoint['pending_count'] ?? null) !== 1
            || data_get($checkpoint, 'details.source_rows', 0) < 5000 || ($checkpoint['promotion_evidence'] ?? null) !== false) {
            throw new RuntimeException('ACADEMY_PREPARATION_PRE_FEATURES_CHECKPOINT_REQUIRED');
        }
        $config = $request['strategies'][0];
        $candidateKey = (string) ($config['lab_agent_id'] ?? ($config['strategy'].':'.($config['version'] ?? 'v1').':0'));
        $parameters = (array) ($config['parameters'] ?? []);
        $candidate = ['candidate_key' => $candidateKey, 'strategy' => (string) $config['strategy'], 'version' => (string) ($config['version'] ?? 'v1'),
            'parameters_hash' => $this->pythonCheckpointHash($parameters)];
        $expected = $this->pythonCheckpointHash(['symbol' => $request['symbol'], 'timeframe' => $request['timeframe'],
            'candidates' => [$candidate], 'dataset_path' => $request['dataset_path'] ?? null, 'dataset_tail_rows' => $request['dataset_tail_rows'] ?? null]);
        $stateHash = $this->pythonCheckpointHash(['checkpoint_key' => $expected, 'stage' => 'snapshot_loaded',
            'completed_candidates' => [], 'pending_candidates' => [$candidateKey], 'details' => $checkpoint['details']]);
        if (($checkpoint['checkpoint_key'] ?? null) !== $expected || ($checkpoint['pending_candidates'] ?? null) !== [$candidateKey]
            || ($checkpoint['state_hash'] ?? null) !== $stateHash
            || CarbonImmutable::parse($checkpoint['heartbeat_at'])->lt($controlRun->started_at)
            // SQL run timestamps have second precision; the producer heartbeat
            // retains microseconds in the same immutable terminal second.
            || CarbonImmutable::parse($checkpoint['heartbeat_at'])->gt(CarbonImmutable::instance($controlRun->finished_at)->endOfSecond())
            || $this->compiler->parameterHash($parameters) !== $this->compiler->parameterHash((array) $controls->first()->modelVersion->parameters)) {
            throw new RuntimeException('ACADEMY_PREPARATION_CHECKPOINT_REQUEST_MISMATCH');
        }
        $batchIds = collect((array) data_get($generation->trigger_context, 'queue_batches.screening', []))->unique()->sort()->values()->all();
        if ($batchIds === []) throw new RuntimeException('ACADEMY_PREPARATION_SCOPED_BATCH_REQUIRED');
        $batches = DB::table('job_batches')->whereIn('id', $batchIds)->get();
        $expectedName = $generation->laboratory->symbol.' '.$generation->laboratory->timeframe.' Lab G'.$generation->generation.' screening';
        if ($batches->count() !== count($batchIds) || $batches->contains(fn ($batch): bool => $batch->name !== $expectedName)) {
            throw new RuntimeException('ACADEMY_PREPARATION_FOREIGN_BATCH_REFUSED');
        }
        return ['generation' => $generation, 'trial' => $trial, 'identity' => $identity, 'compiled' => $compiled,
            'agent_ids' => $agentIds, 'batch_ids' => $batchIds, 'checkpoint' => $checkpoint, 'checkpoint_sha256' => $checkpointSha,
            'request_artifact_sha256' => $requestArtifact->sha256, 'control_run' => $controlRun,
            'active_run_ids' => $runs->whereIn('status', ['started', 'running', 'processing'])->pluck('run_id')->all(),
            'run_evidence_hash' => $this->hash($runs->map(fn ($run) => [$run->run_id, $run->status, $run->request_hash, $run->response_hash,
                $run->code_hash, $run->parameter_hash, $run->data_hash, $run->finished_at?->toIso8601String()])->all())];
    }

    /** Checkpoint transport uses Python's sorted compact JSON, not model identity hashing. */
    private function pythonCheckpointHash(array $value): string
    {
        return hash('sha256', json_encode($this->canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** The single arbiter authorizes sealing and planning; publication remains the canonical dispatcher. */
    public function prepareColdStart(ResearchLoopDecision $decision): array
    {
        $decision = $decision->fresh();
        $proposal = (array) data_get($decision?->evidence_snapshot, 'academy_proposal', []);
        if (! $decision || $decision->status !== 'running' || $decision->action !== 'OPEN_ACADEMY_EXPERIMENT'
            || $decision->command !== 'trading:admit-academy-experiment'
            || (int) data_get($decision->arguments, 'trial', -1) !== 0
            || ($proposal['status'] ?? '') !== 'would_prepare_cold_start'
            || data_get($proposal, 'cold_start.protocol') !== self::COLD_START_PROTOCOL) return $this->blocked('ACADEMY_DURABLE_ARBITER_ADMISSION_REQUIRED');
        $prior = DB::table('edge_academy_trials as t')->join('edge_academy_passports as p', 'p.id', '=', 't.edge_academy_passport_id')
            ->where('p.frozen_upstream_contract', 'like', '%'.self::COLD_START_PROTOCOL.'%')->select('t.*', 'p.frozen_upstream_contract')->get()
            ->first(fn ($row): bool => (int) data_get(json_decode($row->frozen_upstream_contract, true),
                'prospective_source_identity.cold_start.arbiter_decision_id') === $decision->id);
        if ($prior) {
            $frozen = json_decode($prior->frozen_upstream_contract, true);
            return $this->materialize((int) $prior->id, (int) $proposal['baseline_model_version_id'],
                [...$frozen['prospective_source_identity'], 'arbiter_decision_id' => $decision->id], true);
        }
        $current = $this->coldStartProposal($decision->symbol, $decision->timeframe);
        if (($current['status'] ?? '') !== 'would_prepare_cold_start'
            || ! hash_equals((string) data_get($proposal, 'cold_start.key', ''), (string) data_get($current, 'cold_start.key', ''))) return $this->blocked('ACADEMY_COLD_START_DEPENDENCY_CHANGED');
        $datasets = app(LabDatasetExportService::class);
        $foundation = $datasets->ensureFoundationDataset($decision->symbol, $decision->timeframe);
        $discoveryManifest = (array) data_get($proposal, 'cold_start.dependencies.discovery_bundle_manifest', []);
        $bundle = $discoveryManifest !== []
            ? app(MultiTimeframeSnapshotService::class)->restoreAgentOwnedConfirmationValidationBundle($discoveryManifest, true)
            : app(MultiTimeframeSnapshotService::class)->forAgentOwnedConfirmationValidation($decision->symbol);
        $execution = app(ExecutionContractService::class)->for($decision->symbol, 'M5');
        if (! hash_equals((string) data_get($proposal, 'cold_start.dependencies.foundation_sha256'), (string) $foundation['sha256'])
            || ! hash_equals((string) data_get($proposal, 'cold_start.dependencies.mtf_bundle_hash'), (string) $bundle['bundle_hash'])) {
            return $this->blocked('ACADEMY_COLD_START_DATA_IDENTITY_CHANGED_DURING_SEAL');
        }
        if (data_get($foundation, 'manifest.source_role') !== 'foundation_training_only'
            || data_get($foundation, 'manifest.promotion_evidence') !== false
            || ! CarbonImmutable::parse((string) data_get($foundation, 'manifest.last_candle_at', data_get($foundation, 'manifest.foundation_end')))->lt(CarbonImmutable::parse('2026-01-01', 'UTC'))
            || ! CarbonImmutable::parse((string) data_get($bundle, 'manifest.entry_last_candle_at'))->lt(CarbonImmutable::parse('2026-01-01', 'UTC'))) return $this->blocked('ACADEMY_COLD_START_PRE2026_ATTESTATION_FAILED');
        $paperPath = $datasets->exportPaper($decision->symbol, $decision->timeframe, false);
        $paperManifestPath = $paperPath.'.manifest.json';
        if (! is_file($paperPath) || ! is_file($paperManifestPath)) return $this->blocked('ACADEMY_COLD_START_PAPER_COVERAGE_SEAL_FAILED');
        $identity = ['pre_2026_only' => true, 'data_hash' => $foundation['sha256'], 'execution_hash' => $execution['execution_hash'],
            'mtf_bundle_hash' => $bundle['bundle_hash'], 'mtf_bundle_manifest' => $bundle['manifest'],
            'canonical_dataset_snapshots' => ['foundation' => [...$foundation, 'promotion_evidence' => false],
                'price' => ['protocol' => 'lab_generation_pre_dispatch_coverage_v1', 'path' => $paperPath, 'manifest_path' => $paperManifestPath,
                    'manifest' => json_decode(File::get($paperManifestPath), true), 'sha256' => hash_file('sha256', $paperPath), 'promotion_evidence' => false]],
            'evaluator_version' => self::COLD_START_PROTOCOL, 'source_evaluator_hash' => data_get($proposal, 'cold_start.dependencies.evaluator_hash'),
            'python_source_hash' => data_get($proposal, 'cold_start.dependencies.python_source_hash'),
            'source_identity_protocol' => data_get($proposal, 'cold_start.dependencies.source_identity_protocol'),
            'execution_contract' => $execution,
            'cold_start' => [...$proposal['cold_start'], 'arbiter_decision_id' => $decision->id,
                'hypothesis_baseline_agent_id' => $proposal['baseline_agent_id'], 'old_evidence_reused' => false]];
        if ($discoveryManifest !== []) {
            $identity['discovery_scope'] = $bundle['manifest']['discovery_scope'];
            $identity['data_role'] = 'pre_2026_discovery_only';
        }
        $identity['cold_start']['sealed_budget_scope'] = $this->dependencyBudgetScope($decision->symbol, $decision->timeframe,
            ['foundation_sha256' => $identity['data_hash'], 'mtf_source_sha256' => collect($bundle['manifest']['streams'])->only(['M5', 'M15', 'H1', 'H4'])->map(fn ($stream): string => $stream['sha256'])->all(),
                'execution_hash' => $identity['execution_hash'], 'discovery_scope' => $identity['discovery_scope'] ?? []]);
        if ($this->hash($proposal['cold_start']['dependencies']) !== $this->hash($this->coldStartDependencies($decision->symbol, $decision->timeframe))) {
            return $this->blocked('ACADEMY_COLD_START_DEPENDENCY_CHANGED_DURING_SEAL');
        }
        $model = ModelVersion::findOrFail((int) $proposal['baseline_model_version_id']);
        if (! hash_equals($proposal['baseline_parameter_hash'], $this->compiler->parameterHash((array) $model->parameters))) return $this->blocked('ACADEMY_COLD_START_BASELINE_CHANGED');
        return DB::transaction(function () use ($decision, $proposal, $identity, $model): array {
            // The arbiter lease and lab row serialize budget consumption; a
            // crash can recreate neither a new baseline nor a second trial.
            $source = LabAgent::query()->with('generation')->findOrFail((int) $proposal['baseline_agent_id']);
            AiLaboratory::query()->lockForUpdate()->findOrFail($source->generation->ai_laboratory_id);
            $duplicate = DB::table('edge_academy_passports')->where('symbol', $decision->symbol)->where('timeframe', $decision->timeframe)
                ->where('frozen_upstream_contract', 'like', '%'.self::COLD_START_PROTOCOL.'%')->get()
                ->contains(fn ($row): bool => data_get(json_decode($row->frozen_upstream_contract, true), 'prospective_source_identity.cold_start.budget_scope') === $proposal['cold_start']['budget_scope']
                    || data_get(json_decode($row->frozen_upstream_contract, true), 'prospective_source_identity.cold_start.sealed_budget_scope') === $identity['cold_start']['sealed_budget_scope']);
            $replacementKey = filled(data_get($proposal, 'cold_start.validator_replacement')) ? 'validator_replacement'
                : (filled(data_get($proposal, 'cold_start.preparation_replacement')) ? 'preparation_replacement' : 'technical_replacement');
            $replacement = (array) data_get($proposal, 'cold_start.'.$replacementKey, []);
            if ($replacement !== []) {
                // Re-attest under the same laboratory lock used to consume
                // the original question. Nothing resets its stable budget.
                $current = $this->coldStartProposal($decision->symbol, $decision->timeframe);
                if (($current['status'] ?? null) !== 'would_prepare_cold_start'
                    || ! hash_equals((string) data_get($proposal, 'cold_start.key'), (string) data_get($current, 'cold_start.key'))
                    || $this->hash($replacement) !== $this->hash((array) data_get($current, 'cold_start.'.$replacementKey, []))) {
                    return $this->blocked('ACADEMY_TECHNICAL_REPLACEMENT_CHANGED_UNDER_LOCK');
                }
            } elseif ($duplicate) return $this->blocked('ACADEMY_COLD_START_SCOPE_BUDGET_EXHAUSTED');
            $academy = app(XauusdEdgeFormationAcademyService::class);
            $passport = $academy->passport($decision->symbol, $decision->timeframe, ['composition_key' => $proposal['cold_start']['key'],
                'strategy_family' => 'confirmation_entry_mtf', 'deepest_stage' => 'market_cartographer',
                'baseline_model_version_id' => $model->id, 'baseline_parameters' => (array) $model->parameters,
                'baseline_parameter_hash' => $proposal['baseline_parameter_hash'], 'prospective_source_identity' => $identity]);
            $plan = $academy->planColdStartExperiment((int) $passport['passport_id']);
            if (($plan['status'] ?? '') !== 'planned') throw new RuntimeException('ACADEMY_COLD_START_PLAN_FAILED:'.($plan['reason'] ?? 'UNKNOWN'));
            return $this->materialize((int) $plan['trial_id'], $model->id, [...$identity, 'arbiter_decision_id' => $decision->id], true);
        });
    }

    public function admit(int $trialId, int $baselineModelVersionId, array $identity, ResearchLoopDecision $decision): array
    {
        $decision = $decision->fresh();
        if (! $decision || ! in_array($decision->action, ['OPEN_ACADEMY_EXPERIMENT', 'DISPATCH_ACADEMY_EXPERIMENT'], true)
            || ! in_array($decision->status, ['selected', 'dispatched', 'running'], true)
            || strtoupper($decision->symbol) !== 'XAUUSD' || strtoupper($decision->timeframe) !== 'H1'
            || $decision->command !== 'trading:admit-academy-experiment'
            || (int) data_get($decision->arguments, 'trial', data_get($decision->arguments, '0', 0)) !== $trialId
            || (int) data_get($decision->evidence_snapshot, 'academy_proposal.trial_id', 0) !== $trialId
            || (int) data_get($decision->evidence_snapshot, 'academy_proposal.baseline_model_version_id', 0) !== $baselineModelVersionId) {
            return $this->blocked('ACADEMY_DURABLE_ARBITER_ADMISSION_REQUIRED');
        }
        return $this->materialize($trialId, $baselineModelVersionId, [...$identity, 'arbiter_decision_id' => $decision->id], true);
    }

    /** A queue/publication retry may acknowledge only the same canonically admitted generation. */
    public function confirmCanonicalAdmission(int $trialId, int $generationId): array
    {
        return DB::transaction(function () use ($trialId, $generationId): array {
            $trial = DB::table('edge_academy_trials')->lockForUpdate()->find($trialId);
            $outcome = $trial ? (json_decode((string) $trial->outcome, true) ?: []) : [];
            $generation = LabGeneration::query()->with('agents.modelVersion', 'laboratory')->find($generationId);
            $expectedSource = (string) data_get($generation?->trigger_context, 'source_evaluator_hash', '');
            if ($expectedSource !== '' && ! hash_equals($expectedSource, app(LabImmutableEvidenceService::class)->codeHash())) {
                return $this->blocked('ACADEMY_FROZEN_EVALUATOR_CHANGED');
            }
            $expectedPython = (string) data_get($generation?->trigger_context, 'python_source_hash', '');
            if ($expectedSource !== '' && ($expectedPython === ''
                || data_get($generation?->trigger_context, 'source_identity_protocol') !== self::SOURCE_IDENTITY_PROTOCOL)) {
                return $this->blocked('ACADEMY_TYPED_RUNTIME_SOURCE_SEAL_REQUIRED');
            }
            if ($expectedPython !== '' && ! hash_equals($expectedPython, app(ResearchReleaseSealService::class)->pythonHash())) {
                return $this->blocked('ACADEMY_FROZEN_PYTHON_EVALUATOR_CHANGED');
            }
            if (! $trial || (int) ($outcome['generation_id'] ?? 0) !== $generationId || ! $generation
                || $generation->status === 'draft' || $generation->agents->contains(fn (LabAgent $agent): bool => $agent->lifecycle_status === 'draft')) {
                return $this->blocked('ACADEMY_CANONICAL_DISPATCH_NOT_OBSERVED');
            }
            $primary = $generation->agents->filter(fn (LabAgent $agent): bool =>
                (int) data_get($agent->modelVersion?->metadata, 'academy_experiment.academy_trial_id', 0) === $trialId);
            if (! in_array($generation->status, ['queued', 'screening', 'training', 'full_queued', 'full_validation', 'screened', 'completed'], true)
                || $primary->count() !== count((array) data_get($outcome, 'compiled_contract.arms', []))
                || $primary->contains(fn (LabAgent $agent): bool => in_array($agent->lifecycle_status,
                    ['technical_quarantine', 'evaluation_error', 'draft'], true))) {
                return $this->blocked('ACADEMY_PRIMARY_COHORT_NOT_CANONICALLY_ADMITTED');
            }
            $admission = app(GenerationSnapshotAdmissionService::class)->inspect($generation);
            if (! ($admission['allowed'] ?? false)) return $this->blocked('ACADEMY_CANONICAL_SNAPSHOT_NOT_ADMITTED');
            if ($trial->settled_at !== null) return ['protocol' => self::PROTOCOL, 'status' => $trial->status, 'promotion_evidence' => false];
            data_set($outcome, 'canonical_admission.status', 'admitted');
            DB::table('edge_academy_trials')->where('id', $trialId)->update(['outcome' => json_encode($outcome), 'updated_at' => now()]);
            return ['protocol' => self::PROTOCOL, 'status' => 'admitted', 'generation_id' => $generationId, 'promotion_evidence' => false];
        });
    }

    public function materialize(int $trialId, int $baselineModelVersionId, array $identity, bool $apply = false): array
    {
        $trial = DB::table('edge_academy_trials')->find($trialId);
        $passport = $trial ? DB::table('edge_academy_passports')->find($trial->edge_academy_passport_id) : null;
        $baseline = ModelVersion::query()->find($baselineModelVersionId);
        $baselineAgent = LabAgent::query()->with('generation.laboratory')->where('model_version_id', $baselineModelVersionId)->latest('id')->first();
        if (! $trial || ! $passport || ! $baseline || ! $baselineAgent?->generation?->laboratory) {
            return $this->blocked('ACADEMY_TRIAL_BASELINE_AND_LAB_REQUIRED');
        }
        if ($trial->settled_at !== null) {
            return $this->blocked('SETTLED_ACADEMY_TRIAL_CANNOT_BE_MATERIALIZED');
        }
        $frozen = json_decode((string) $trial->frozen_contract, true) ?: [];
        if ($baselineAgent->strategy_family !== 'confirmation_entry_mtf'
            || (int) ($frozen['baseline_model_version_id'] ?? $baseline->id) !== (int) $baseline->id
            || (filled($frozen['baseline_parameter_hash'] ?? null)
                && ! hash_equals((string) $frozen['baseline_parameter_hash'], $this->compiler->parameterHash((array) $baseline->parameters)))) {
            return $this->blocked('ACADEMY_FROZEN_RUNTIME_BASELINE_MISMATCH');
        }
        if (($identity['pre_2026_only'] ?? false) !== true || ! filled($identity['data_hash'] ?? null)
            || ! filled($identity['execution_hash'] ?? null) || ! is_array($identity['canonical_dataset_snapshots'] ?? null)) {
            return $this->blocked('PRE2026_DATA_EXECUTION_AND_SNAPSHOT_CONTRACT_REQUIRED');
        }
        $compiled = $this->compiler->compile([
            'axis' => data_get(json_decode((string) $trial->outcome, true), 'axis', null) ?: $this->axisFromArms($trial->arms),
            'arms' => json_decode((string) $trial->arms, true) ?: [],
        ], (array) $baseline->parameters, ['symbol' => strtoupper($passport->symbol), 'laboratory_timeframe' => strtoupper($passport->timeframe), 'execution_timeframe' => 'M5']);
        if (($compiled['status'] ?? null) !== 'compiled') {
            return [...$compiled, 'status' => 'blocked'];
        }
        $researchContract = $this->researchContract($trial, $passport, $baseline, $identity, $compiled);
        $expectedSource = (string) ($identity['source_evaluator_hash'] ?? '');
        if ($expectedSource !== '' && ! hash_equals($expectedSource, app(LabImmutableEvidenceService::class)->codeHash())) {
            return $this->blocked('ACADEMY_FROZEN_EVALUATOR_CHANGED');
        }
        $expectedPython = (string) ($identity['python_source_hash'] ?? '');
        if ($expectedSource !== '' && $expectedPython === '') {
            return $this->blocked('ACADEMY_TYPED_PYTHON_SOURCE_SEAL_REQUIRED');
        }
        if (($expectedSource !== '' || $expectedPython !== '')
            && ($identity['source_identity_protocol'] ?? null) !== self::SOURCE_IDENTITY_PROTOCOL) {
            return $this->blocked('ACADEMY_TYPED_RUNTIME_SOURCE_SEAL_REQUIRED');
        }
        if ($expectedPython !== '' && ! hash_equals($expectedPython, app(ResearchReleaseSealService::class)->pythonHash())) {
            return $this->blocked('ACADEMY_FROZEN_PYTHON_EVALUATOR_CHANGED');
        }
        if (! $apply) {
            return ['protocol' => self::PROTOCOL, 'status' => 'would_queue', 'trial_id' => $trialId,
                'baseline_model_version_id' => $baseline->id, 'seats' => CausalCompoundingKernelService::POPULATION_SIZE,
                'primary_proof_seats' => count($compiled['arms']),
                'compiled_contract' => $compiled, 'research_experiment_contract' => $researchContract, 'promotion_evidence' => false];
        }
        $decision = ResearchLoopDecision::query()->find((int) ($identity['arbiter_decision_id'] ?? 0));
        $directTrial = $decision && (int) data_get($decision->arguments, 'trial', data_get($decision->arguments, '0', -1)) === $trialId
            && (int) data_get($decision->evidence_snapshot, 'academy_proposal.trial_id', -1) === $trialId;
        $coldStartTrial = $decision && $decision->action === 'OPEN_ACADEMY_EXPERIMENT'
            && (int) data_get($decision->arguments, 'trial', -1) === 0
            && (int) data_get($frozen, 'prospective_source_identity.cold_start.arbiter_decision_id', -1) === $decision->id
            && data_get($frozen, 'prospective_source_identity.cold_start.protocol') === self::COLD_START_PROTOCOL
            && filled(data_get($decision->evidence_snapshot, 'academy_proposal.cold_start.key'))
            && hash_equals((string) data_get($frozen, 'prospective_source_identity.cold_start.key'),
                (string) data_get($decision->evidence_snapshot, 'academy_proposal.cold_start.key'));
        if (! $decision || ! in_array($decision->action, ['OPEN_ACADEMY_EXPERIMENT', 'DISPATCH_ACADEMY_EXPERIMENT'], true)
            || ! in_array($decision->status, ['selected', 'dispatched', 'running'], true)
            || strtoupper((string) $decision->symbol) !== strtoupper((string) $passport->symbol)
            || strtoupper((string) $decision->timeframe) !== strtoupper((string) $passport->timeframe)
            || $decision->command !== 'trading:admit-academy-experiment'
            || ! ($directTrial || $coldStartTrial)
            || (int) data_get($decision->evidence_snapshot, 'academy_proposal.baseline_model_version_id', 0) !== $baselineModelVersionId) {
            return $this->blocked('ACADEMY_DURABLE_ARBITER_ADMISSION_REQUIRED');
        }
        if (($frozen['protocol'] ?? null) !== XauusdEdgeFormationAcademyService::PROTOCOL
            || ! filled($frozen['baseline_parameter_hash'] ?? null)
            || ! filled(data_get($identity, 'mtf_bundle_hash'))
            || ! is_array(data_get($identity, 'mtf_bundle_manifest.streams.M5'))
            || ! is_array(data_get($identity, 'canonical_dataset_snapshots.foundation.manifest'))) {
            return $this->blocked('ACADEMY_PROSPECTIVE_SOURCE_SEAL_REQUIRED');
        }
        if ($coldStartTrial && ! hash_equals((string) data_get($frozen, 'prospective_source_identity.cold_start.preview_hash'), $this->hash($compiled))) {
            return $this->blocked('ACADEMY_COLD_START_PREVIEW_CHANGED');
        }
        // A new arbiter decision may resume publication, never rebind the
        // sealed experiment to different bytes, costs or MTF streams.
        $identityKeys = ['data_hash', 'execution_hash', 'mtf_bundle_hash', 'mtf_bundle_manifest', 'canonical_dataset_snapshots'];
        if (array_key_exists('source_evaluator_hash', (array) ($frozen['prospective_source_identity'] ?? []))) $identityKeys[] = 'source_evaluator_hash';
        if (array_key_exists('python_source_hash', (array) ($frozen['prospective_source_identity'] ?? []))) $identityKeys[] = 'python_source_hash';
        if (array_key_exists('source_identity_protocol', (array) ($frozen['prospective_source_identity'] ?? []))) $identityKeys[] = 'source_identity_protocol';
        if (array_key_exists('discovery_scope', (array) ($frozen['prospective_source_identity'] ?? []))) $identityKeys[] = 'discovery_scope';
        if (array_key_exists('data_role', (array) ($frozen['prospective_source_identity'] ?? []))) $identityKeys[] = 'data_role';
        foreach ($identityKeys as $key) {
            if (! array_key_exists($key, (array) ($frozen['prospective_source_identity'] ?? []))
                || $this->hash(['value' => $frozen['prospective_source_identity'][$key]]) !== $this->hash(['value' => $identity[$key] ?? null])) {
                return $this->blocked('ACADEMY_PROSPECTIVE_IDENTITY_MISMATCH');
            }
        }
        $academyContext = (array) data_get(json_decode((string) $trial->frozen_contract, true) ?: [], 'context', []);
        $created = DB::transaction(function () use ($trial, $passport, $baseline, $baselineAgent, $identity, $compiled, $researchContract, $academyContext): array {
            $lab = AiLaboratory::query()->lockForUpdate()->findOrFail($baselineAgent->generation->laboratory->id);
            $locked = DB::table('edge_academy_trials')->lockForUpdate()->find($trial->id);
            if (! $locked || $locked->settled_at !== null) throw new RuntimeException('SETTLED_ACADEMY_TRIAL_CANNOT_BE_MATERIALIZED');
            $existingOutcome = json_decode((string) $locked->outcome, true) ?: [];
            if ($locked->status === 'materialized' && isset($existingOutcome['generation_id'])) {
                $generation = LabGeneration::query()->findOrFail((int) $existingOutcome['generation_id']);
                return ['generation' => $generation, 'agents' => $generation->agents()->get()->all(),
                    'kernel' => ['contract' => data_get($generation->trigger_context, 'causal_compounding_kernel', [])], 'reused' => true];
            }
            $lockedBaseline = ModelVersion::query()->lockForUpdate()->findOrFail($baseline->id);
            if (! hash_equals($compiled['baseline_parameter_hash'], $this->compiler->parameterHash((array) $lockedBaseline->parameters))) {
                throw new RuntimeException('ACADEMY_FROZEN_RUNTIME_BASELINE_MISMATCH');
            }
            if ($lab->generations()->whereIn('status', ['draft', 'queued', 'screening', 'training', 'full_queued', 'full_validation'])->exists()) {
                throw new RuntimeException('ACADEMY_ACTIVE_GENERATION_MUST_SETTLE_FIRST');
            }
            $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => ((int) $lab->generations()->max('generation')) + 1,
                'trigger_type' => 'academy_experiment', 'trigger_context' => ['protocol' => self::PROTOCOL, 'academy_trial_id' => $trial->id,
                    'baseline_model_version_id' => $baseline->id, 'genetic_parent_model_version_id' => null,
                    'data_hash' => $identity['data_hash'], 'execution_hash' => $identity['execution_hash'],
                    'canonical_dataset_snapshots' => $identity['canonical_dataset_snapshots'], 'compiled_contract' => $compiled,
                    'mtf_bundle_hash' => $identity['mtf_bundle_hash'] ?? null, 'mtf_bundle_manifest' => $identity['mtf_bundle_manifest'] ?? [],
                    'arbiter_decision_id' => $identity['arbiter_decision_id'],
                    'source_evaluator_hash' => $identity['source_evaluator_hash'] ?? null,
                    'python_source_hash' => $identity['python_source_hash'] ?? null,
                    'source_identity_protocol' => $identity['source_identity_protocol'] ?? null,
                    'prospective_source_identity' => $identity,
                    'research_experiment_contract' => $researchContract,
                    'pre_2026_only' => true, 'research_only' => true, 'promotion_evidence' => false],
                'data_fingerprint' => $identity['data_hash'], 'population_size' => CausalCompoundingKernelService::POPULATION_SIZE, 'status' => 'draft', 'started_at' => now()]);
            app(LearningProtocolEpochService::class)->openForNewGeneration($generation, $lab->symbol, $lab->timeframe);
            $agents = [];
            foreach ($compiled['arms'] as $index => $arm) {
                $label = 'academy_t'.$trial->id.'_g'.$generation->generation.'_a'.($index + 1);
                $metadata = [...$this->hypothesisRuntimeMetadata((array) $baseline->metadata), 'base_strategy' => 'confirmation_entry_mtf_v1',
                    'causal_baseline_model_version_id' => $baseline->id,
                    'genetic_parent_model_version_id' => null,
                    'academy_experiment' => ['protocol' => self::PROTOCOL, 'academy_trial_id' => $trial->id, 'arm_index' => $index, 'arm_role' => $arm['role'],
                        'parameter_hash' => $arm['parameter_hash'],
                        'planner_value' => $arm['planner_value'], 'runtime_value' => $arm['runtime_value'], 'contract_hash' => hash('sha256', json_encode($compiled)),
                        'research_experiment_contract' => $researchContract,
                        'context' => $academyContext,
                        'data_hash' => $identity['data_hash'], 'execution_hash' => $identity['execution_hash'],
                        'source_evaluator_hash' => $identity['source_evaluator_hash'] ?? null,
                        'python_source_hash' => $identity['python_source_hash'] ?? null,
                        'source_identity_protocol' => $identity['source_identity_protocol'] ?? null,
                        'causal_baseline_model_version_id' => $baseline->id, 'genetic_parent_model_version_id' => null,
                        'research_only' => true, 'promotion_evidence' => false]];
                $metadata = app(CompositionAuthorityKernelService::class)->confirmationReplayMetadata($metadata, $identity);
                // A fresh model needs its own current-family identity. The
                // archived hypothesis may have no seal or a seal for another
                // parameter vector; neither can be copied into admission.
                $identityParameters = app(StrategyParameterSchemaService::class)->canonicalizeForIdentity(
                    'confirmation_entry_mtf', (array) $arm['runtime_parameters']);
                $metadata['parameter_fingerprint'] = hash('sha256', 'confirmation_entry_mtf|'.json_encode(
                    $identityParameters, JSON_PRESERVE_ZERO_FRACTION));
                $metadata['universal_genome'] = app(UniversalAgentCapabilityService::class)->genome(
                    (string) $passport->symbol, (string) $passport->timeframe, 'confirmation_entry_mtf',
                    (string) data_get($metadata, 'architecture', 'confirmation_entry_mtf_v1'),
                    (array) $arm['runtime_parameters']);
                $model = ModelVersion::create(['name' => 'Academy trial '.$trial->id.' '.$arm['role'].' a'.($index + 1).' g'.$generation->generation,
                    'strategy' => $label, 'version' => 'academy-'.$trial->id.'-'.$generation->generation.'-'.($index + 1), 'generation' => $generation->generation,
                    'status' => 'testing', 'description' => 'Compiled Academy experiment; research-only.', 'change_log' => 'academy '.$arm['role'],
                    'parameters' => $arm['runtime_parameters'], 'metadata' => $metadata, 'evidence_status' => 'valid']);
                $agents[] = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id, 'parent_a_model_version_id' => null,
                    'symbol' => $passport->symbol, 'timeframe' => $passport->timeframe, 'strategy_family' => 'confirmation_entry_mtf',
                    'origin' => 'academy_experiment', 'lifecycle_status' => 'draft', 'parameter_diff' => $this->diff((array) $baseline->parameters, $arm['runtime_parameters']),
                    'decision_reason' => 'Compiled Academy '.$arm['role'].' arm; causal baseline only; research-only.']);
            }
            $protectedGenes = collect($agents)->flatMap(fn (LabAgent $agent): array => array_keys((array) $agent->parameter_diff))
                ->unique()->values()->all();
            $kernel = $this->compoundingKernel->complete(
                $generation,
                $baseline,
                $baselineAgent,
                (string) $identity['data_hash'],
                (string) $identity['execution_hash'],
                'selection_quality',
                $protectedGenes,
                (array) data_get($identity, 'cold_start.validator_replacement.preregistered_discovery_plan',
                    data_get($identity, 'cold_start.preparation_replacement.preregistered_discovery_plan', [])),
            );
            foreach ($generation->agents()->with('modelVersion')->get() as $member) {
                $metadata = $this->hypothesisRuntimeMetadata((array) $member->modelVersion->metadata);
                if ($member->origin === 'academy_experiment') $metadata['academy_experiment'] = data_get($member->modelVersion->metadata, 'academy_experiment');
                if (isset($identity['execution_contract'])) $metadata['execution_contract'] = $identity['execution_contract'];
                $member->modelVersion->update(['metadata' => $metadata]);
            }
            // Seal exact screening owners after hypothesis cleanup, but still
            // before this creation transaction can publish any draft.
            $this->sealPrimaryControlAdmission($generation, $trial, $baseline, $compiled, $identity);
            $generation->agents()->update(['lifecycle_status' => 'draft']);
            DB::table('edge_academy_trials')->where('id', $trial->id)->update(['status' => 'materialized',
                'outcome' => json_encode(['protocol' => self::PROTOCOL, 'generation_id' => $generation->id, 'compiled_contract' => $compiled,
                    'canonical_admission' => ['status' => 'pending', 'owner' => ResearchLoopArbiterService::class,
                        'arbiter_decision_id' => $identity['arbiter_decision_id'], 'identity' => $identity],
                    'promotion_evidence' => false]), 'updated_at' => now()]);

            return compact('generation', 'agents', 'kernel');
        });
        return ['protocol' => self::PROTOCOL, 'status' => 'pending_canonical_admission', 'generation_id' => $created['generation']->id,
            'trial_id' => $trialId, 'reused' => $created['reused'] ?? false,
            'agent_ids' => $created['generation']->agents()->pluck('id')->all(),
            'population_size' => CausalCompoundingKernelService::POPULATION_SIZE,
            'compounding_kernel' => $created['kernel']['contract'], 'promotion_evidence' => false];
    }

    /**
     * Admission ownership only: Academy's multi-arm proof is not an ordinary
     * economic candidate/control pair and grants no learning authority here.
     */
    private function sealPrimaryControlAdmission(
        LabGeneration $generation,
        object $trial,
        ModelVersion $baseline,
        array $compiled,
        array $identity,
    ): void {
        $members = $generation->agents()->with('modelVersion')->where('origin', 'academy_experiment')->get();
        $controls = $members->filter(fn (LabAgent $member): bool =>
            data_get($member->modelVersion->metadata, 'academy_experiment.arm_role') === 'frozen_control');
        if ($controls->count() !== 1 || $members->count() !== count($compiled['arms'])) {
            throw new RuntimeException('ACADEMY_PRIMARY_CONTROL_ROSTER_INVALID');
        }
        $control = $controls->first();
        if (! hash_equals((string) $compiled['baseline_parameter_hash'],
            $this->compiler->parameterHash((array) $control->modelVersion->parameters))) {
            throw new RuntimeException('ACADEMY_PRIMARY_CONTROL_BASELINE_MISMATCH');
        }
        $owner = [
            'protocol' => 'academy_exact_control_admission_v1',
            'generation_id' => (int) $generation->id,
            'academy_trial_id' => (int) $trial->id,
            'control_agent_id' => (int) $control->id,
            'control_model_version_id' => (int) $control->model_version_id,
            'causal_baseline_model_version_id' => (int) $baseline->id,
            'baseline_parameter_hash' => $compiled['baseline_parameter_hash'],
            'compiled_contract_hash' => hash('sha256', json_encode($compiled)),
            'data_hash' => $identity['data_hash'],
            'execution_hash' => $identity['execution_hash'],
            'source_evaluator_hash' => $identity['source_evaluator_hash'] ?? null,
            'python_source_hash' => $identity['python_source_hash'] ?? null,
            'source_identity_protocol' => $identity['source_identity_protocol'] ?? null,
            'purpose' => 'frozen_control_screening_admission_only',
            'research_only' => true,
            'economic_pair' => false,
            'credit_authority' => false,
            'promotion_evidence' => false,
        ];
        foreach ($members as $member) {
            $metadata = (array) $member->modelVersion->metadata;
            $index = (int) data_get($metadata, 'academy_experiment.arm_index', -1);
            $sealed = $compiled['arms'][$index] ?? [];
            if (($sealed['role'] ?? null) !== data_get($metadata, 'academy_experiment.arm_role')
                || ! hash_equals((string) ($sealed['parameter_hash'] ?? ''),
                    $this->compiler->parameterHash((array) $member->modelVersion->parameters))) {
                throw new RuntimeException('ACADEMY_PRIMARY_CONTROL_ROSTER_INVALID');
            }
            unset($metadata['control_pair_contract'], $metadata['control_contract'],
                $metadata['causal_compounding_kernel'], $metadata['learning_receipt']);
            $metadata['academy_control_admission'] = $owner;
            // Stale source learning receipts were already removed by cleanup.
            // This explicit link lets the existing frozen-control owner resolve
            // one exact control despite many same-family kernel controls.
            if ((int) $member->id !== (int) $control->id) {
                $metadata['learning_receipt'] = [
                    ...$owner,
                    'candidate_agent_id' => (int) $member->id,
                    'candidate_model_version_id' => (int) $member->model_version_id,
                    'arm_role' => $sealed['role'],
                ];
            } else {
                $metadata['control_contract'] = [
                    ...$owner,
                    'protocol' => 'frozen_control_v2',
                    'control_only' => true,
                    'role' => 'control',
                    'parameter_hash' => $sealed['parameter_hash'],
                    'status' => 'control_sealed_pending_replay',
                ];
            }
            $member->modelVersion->update(['metadata' => $metadata]);
        }
    }

    /** Settle only after every explicit arm has immutable replay evidence. */
    public function settleOutcome(LabAgent $agent): array
    {
        $agent->loadMissing('modelVersion', 'generation.agents.modelVersion');
        $contract = (array) data_get($agent->modelVersion?->metadata, 'academy_experiment', []);
        if (($contract['protocol'] ?? null) !== self::PROTOCOL) {
            return ['status' => 'not_academy_experiment', 'promotion_evidence' => false];
        }
        $trial = DB::table('edge_academy_trials')->find((int) ($contract['academy_trial_id'] ?? 0));
        if (! $trial) {
            return $this->blocked('ACADEMY_TRIAL_MISSING_AT_SETTLEMENT');
        }
        if ($trial->settled_at !== null) {
            return ['protocol' => self::PROTOCOL, 'status' => (string) $trial->status, 'promotion_evidence' => false];
        }
        $trialOutcome = json_decode((string) $trial->outcome, true) ?: [];
        if ((int) ($trialOutcome['generation_id'] ?? 0) !== (int) $agent->lab_generation_id
            || (int) data_get($agent->generation?->trigger_context, 'academy_trial_id', 0) !== (int) $trial->id) {
            return $this->blocked('ACADEMY_SETTLEMENT_GENERATION_OWNER_MISMATCH');
        }
        $rows = collect($agent->generation?->agents ?? [])->filter(fn (LabAgent $row): bool => data_get($row->modelVersion?->metadata, 'academy_experiment.academy_trial_id') === (int) $trial->id);
        if ($rows->isEmpty()) {
            return $this->blocked('ACADEMY_COHORT_MEMBERS_MISSING');
        }
        $compiledArms = (array) data_get($agent->generation?->trigger_context, 'compiled_contract.arms', []);
        if ($compiledArms === [] || $rows->count() !== count($compiledArms)
            || $rows->pluck('modelVersion.metadata.academy_experiment.arm_index')->unique()->count() !== count($compiledArms)) {
            return $this->settleTechnicalQuarantine($trial, $contract, [], 'ACADEMY_EXACT_ARM_MEMBERSHIP_MISMATCH');
        }
        foreach ($rows as $row) {
            $index = (int) data_get($row->modelVersion?->metadata, 'academy_experiment.arm_index', -1);
            $sealed = $compiledArms[$index] ?? [];
            if (($sealed['role'] ?? null) !== data_get($row->modelVersion?->metadata, 'academy_experiment.arm_role')
                || ! hash_equals((string) ($sealed['parameter_hash'] ?? ''), $this->compiler->parameterHash((array) $row->modelVersion?->parameters))) {
                return $this->settleTechnicalQuarantine($trial, $contract, [], 'ACADEMY_EXACT_ARM_PARAMETERS_MISMATCH');
            }
        }
        $expectedSource = (string) data_get($agent->generation?->trigger_context, 'source_evaluator_hash', '');
        $expectedPython = (string) data_get($agent->generation?->trigger_context, 'python_source_hash', '');
        $observations = $rows->map(function (LabAgent $row) use ($expectedSource): ?array {
            $run = LabEvaluationRun::query()->where('lab_agent_id', $row->id)->where('model_version_id', $row->model_version_id)
                ->where('lab_generation_id', $row->lab_generation_id)->where('status', 'completed')
                ->whereNotNull('response_hash')->whereNotNull('data_hash')->latest('id')->first();
            $evidence = app(LabImmutableEvidenceService::class);
            $artifact = $run ? LabEvidenceArtifact::query()->where('run_id', $run->run_id)
                ->where('artifact_type', 'evaluation_response')->latest('id')->first() : null;
            $metrics = $artifact ? $evidence->readArtifactPayload($artifact) : null;
            if (! is_array($metrics)) {
                return null;
            }
            if (! hash_equals((string) $run->response_hash, (string) $artifact->sha256)) return null;
            if (! filled($artifact->storage_path) && ! hash_equals((string) $artifact->sha256, $evidence->hash($metrics))) return null;
            if (! filled($run->parameter_hash)
                || ! hash_equals((string) $run->parameter_hash, $evidence->parameterHash($row))) return null;
            if ($expectedSource !== '' && (! filled($run->code_hash) || ! hash_equals($expectedSource, (string) $run->code_hash))) return null;

            return ['agent_id' => $row->id, 'model_version_id' => $row->model_version_id, 'run_id' => $run?->run_id,
                'run_database_id' => $run->id, 'response_hash' => $run->response_hash,
                'arm_index' => (int) data_get($row->modelVersion?->metadata, 'academy_experiment.arm_index', -1),
                'parameters' => (array) $row->modelVersion?->parameters,
                'parameter_hash' => $this->compiler->parameterHash((array) $row->modelVersion?->parameters),
                'role' => data_get($row->modelVersion?->metadata, 'academy_experiment.arm_role'), 'metrics' => $metrics];
        });
        if ($observations->contains(null)) {
            if ($rows->every(fn (LabAgent $row): bool => in_array($row->lifecycle_status,
                ['screened', 'completed', 'rejected', 'technical_quarantine', 'archived'], true))) {
                return $this->settleTechnicalQuarantine($trial, $contract, $observations->filter()->all(), 'ACADEMY_INCOMPLETE_IMMUTABLE_ARM_EVIDENCE');
            }
            return ['protocol' => self::PROTOCOL, 'status' => 'awaiting_all_arm_evidence', 'promotion_evidence' => false];
        }
        $expectedData = (string) ($contract['data_hash'] ?? '');
        $expectedExecution = (string) ($contract['execution_hash'] ?? '');
        foreach ($observations as $observation) {
            $metrics = $observation['metrics'];
            $receipt = (array) data_get($metrics, 'data_quality.decision_identity_receipt', []);
            $modern = $expectedSource !== '' || ($receipt['protocol'] ?? null) === 'replay_decision_identity_v2'
                || filled(data_get($metrics, 'data_manifest.mtf_bundle_hash'));
            $runtimeData = $modern ? (string) data_get($agent->generation?->trigger_context, 'mtf_bundle_hash', $expectedData) : $expectedData;
            $data = (string) data_get($metrics, 'data_manifest.mtf_bundle_hash', data_get($metrics, 'data_manifest.data_hash',
                data_get($metrics, 'data_manifest.sha256', data_get($metrics, 'data_hash', ''))));
            $execution = (string) data_get($metrics, 'execution_contract.execution_hash', data_get($metrics, 'execution_hash', ''));
            if ($data === '' || $execution === '' || ! hash_equals($runtimeData, $data) || ! hash_equals($expectedExecution, $execution)) {
                return $this->settleTechnicalQuarantine($trial, $contract, $observations, 'ACADEMY_ARM_HASH_MISMATCH');
            }
            if ($expectedSource !== '' || ($receipt['protocol'] ?? null) === 'replay_decision_identity_v2') {
                if (($receipt['protocol'] ?? null) !== 'replay_decision_identity_v2' || ($receipt['status'] ?? null) !== 'complete'
                    || ! hash_equals($runtimeData, (string) data_get($receipt, 'bindings.dataset_identity', ''))
                    || ($expectedSource !== '' && ($expectedPython === ''
                        || data_get($receipt, 'bindings.source_identity_protocol') !== self::SOURCE_IDENTITY_PROTOCOL
                        || ! hash_equals($expectedSource, (string) data_get($receipt, 'bindings.full_runtime_source_hash', ''))
                        || ! hash_equals($expectedPython, (string) data_get($receipt, 'bindings.python_source_hash', ''))
                        || ! hash_equals($expectedPython, (string) data_get($receipt, 'bindings.source_evaluator_hash', ''))))) {
                    return $this->settleTechnicalQuarantine($trial, $contract, $observations, 'ACADEMY_FROZEN_DECISION_SOURCE_MISMATCH');
                }
                foreach (['M5', 'M15', 'H1', 'H4'] as $stream) {
                    $sealed = (string) data_get($agent->generation?->trigger_context, "mtf_bundle_manifest.streams.{$stream}.sha256", '');
                    $actual = (string) data_get($receipt, "dependency_identity.streams.{$stream}.actual_source_sha256", '');
                    if ($sealed === '' || $actual === '' || ! hash_equals($sealed, $actual)) {
                        return $this->settleTechnicalQuarantine($trial, $contract, $observations, 'ACADEMY_FROZEN_MTF_DEPENDENCY_MISMATCH');
                    }
                }
            }
        }
        $metrics = $observations->pluck('metrics');
        $perArm = $observations->map(fn (array $observation): array => [
            'agent_id' => $observation['agent_id'], 'model_version_id' => $observation['model_version_id'], 'role' => $observation['role'],
            'setup' => (int) data_get($observation['metrics'], 'entry_contract_funnel.stage_counts.setup', 0),
            'trigger' => (int) data_get($observation['metrics'], 'entry_contract_funnel.stage_counts.trigger', 0),
            'closed_trade' => (int) data_get($observation['metrics'], 'total_trades', 0),
            'false_entry_rate' => (float) data_get($observation['metrics'], 'false_entry_rate', 0),
            'opportunity_flood_ratio' => (float) data_get($observation['metrics'], 'opportunity_flood_ratio', 0),
            'after_cost_expectancy_r' => (float) data_get($observation['metrics'], 'after_cost_expectancy_r', data_get($observation['metrics'], 'net_r', 0)),
            'metrics_hash' => $this->hash($observation['metrics']),
        ]);
        // Power is an arm-level property. Summing five arms would let four
        // underpowered candidates masquerade as one adequately tested arm.
        $counts = ['setup' => (int) $perArm->min('setup'), 'trigger' => (int) $perArm->min('trigger'),
            'closed_trade' => (int) $perArm->min('closed_trade'), 'false_entry_rate' => (float) $perArm->max('false_entry_rate'),
            'opportunity_flood_ratio' => (float) $perArm->max('opportunity_flood_ratio'), 'per_arm' => $perArm->all()];
        $control = $perArm->where('role', 'frozen_control');
        $candidates = $perArm->where('role', 'candidate');
        $controlExpectation = $control->isEmpty() ? null : (float) $control->avg('after_cost_expectancy_r');
        $candidateExpectation = $candidates->isEmpty() ? null : (float) $candidates->avg('after_cost_expectancy_r');
        $outcome = ['after_cost_expectancy_r' => (float) ($metrics->avg(fn ($m) => data_get($m, 'after_cost_expectancy_r', data_get($m, 'net_r', 0))) ?? 0),
            'control_after_cost_expectancy_r' => $controlExpectation, 'candidate_after_cost_expectancy_r' => $candidateExpectation,
            'treatment_effect_after_cost_r' => $controlExpectation === null || $candidateExpectation === null ? null : $candidateExpectation - $controlExpectation,
            'arm_count' => $observations->count(), 'control_present' => ! $control->isEmpty(), 'arm_evidence' => $perArm->all()];
        $outcome['primary_arm_evidence_complete'] = true;
        $outcome['expected_arm_count'] = count($compiledArms);
        $outcome['complete_arm_count'] = $observations->count();
        $outcome['stage_comparisons'] = $this->stageComparisons($observations, $agent->generation);
        $comparisons = collect($outcome['stage_comparisons']);
        $outcome['behavioral_proof'] = [
            'status' => $comparisons->contains(fn (array $comparison): bool => data_get($comparison, 'assessment.status') === 'controllable')
                ? 'stage_controllability_observed'
                : ($comparisons->isNotEmpty() && $comparisons->every(fn (array $comparison): bool =>
                    data_get($comparison, 'assessment.checks.evidence_assessable') === true
                    && data_get($comparison, 'assessment.reason') === 'NO_OBSERVED_SEMANTIC_EFFECT')
                    ? 'assessable_no_semantic_effect' : 'unassessable'),
            'economic_authority' => false,
        ];

        return DB::transaction(function () use ($trial, $contract, $observations, $counts, $outcome): array {
            $locked = DB::table('edge_academy_trials')->lockForUpdate()->find($trial->id);
            if (! $locked) {
                throw new RuntimeException('ACADEMY_TRIAL_MISSING_AT_SETTLEMENT');
            }
            if ($locked->settled_at !== null) {
                return ['protocol' => self::PROTOCOL, 'status' => (string) $locked->status, 'promotion_evidence' => false];
            }
            $settlement = app(XauusdEdgeFormationAcademyService::class)->settleTrial((int) $locked->id, $counts, $outcome);
            $classification = $this->classification($settlement, $outcome, $counts);
            $receipt = $this->recordReceipt($contract, $locked, $observations, $settlement, $classification, $this->compactOutcome($outcome), $counts);

            return [...$settlement, 'classification' => $classification, 'conversion_receipt' => $receipt, 'promotion_evidence' => false];
        });
    }

    /** Full source artifacts stay immutable; comparisons are transient input to the Academy owner. */
    private function stageComparisons($observations, LabGeneration $generation): array
    {
        $control = $observations->firstWhere('role', 'frozen_control');
        $axis = (string) data_get($generation->trigger_context, 'compiled_contract.axis', '');
        if (! $control || $axis === '') return [];
        $director = app(CausalStageMasteryDirectorService::class);

        return $observations->where('role', 'candidate')->map(function (array $candidate) use ($control, $axis, $director): array {
            $controlMetrics = [...$control['metrics'], 'value' => $control['parameters'][$axis] ?? null];
            $candidateMetrics = [...$candidate['metrics'], 'value' => $candidate['parameters'][$axis] ?? null];
            return [
                'role' => 'candidate', 'gene' => $axis, 'arm_index' => $candidate['arm_index'],
                'candidate_agent_id' => $candidate['agent_id'], 'candidate_model_version_id' => $candidate['model_version_id'],
                'control_agent_id' => $control['agent_id'], 'control_model_version_id' => $control['model_version_id'],
                'candidate_parameters' => $candidate['parameters'], 'control_parameters' => $control['parameters'],
                'candidate_parameter_hash' => $candidate['parameter_hash'], 'control_parameter_hash' => $control['parameter_hash'],
                'control_run_id' => $control['run_id'], 'candidate_run_id' => $candidate['run_id'],
                'control_run_database_id' => $control['run_database_id'], 'candidate_run_database_id' => $candidate['run_database_id'],
                'control_response_hash' => $control['response_hash'], 'candidate_response_hash' => $candidate['response_hash'],
                'control_metrics' => $controlMetrics, 'candidate_metrics' => $candidateMetrics,
                'assessment' => $director->assess($axis, $controlMetrics, $candidateMetrics),
                'promotion_evidence' => false,
            ];
        })->values()->all();
    }

    private function compactOutcome(array $outcome): array
    {
        $outcome['stage_comparisons'] = collect($outcome['stage_comparisons'] ?? [])->map(function (array $comparison): array {
            unset($comparison['control_metrics'], $comparison['candidate_metrics']);
            return $comparison;
        })->all();
        return $outcome;
    }

    /** Build the immutable contract before the generation id or attempt exists. */
    private function researchContract(object $trial, object $passport, ModelVersion $baseline, array $identity, array $compiled): array
    {
        $frozen = (array) (json_decode((string) $trial->frozen_contract, true) ?: []);
        $density = (array) (json_decode((string) $trial->density_contract, true) ?: []);
        $baselineEpoch = (string) ($identity['baseline_epoch_hash'] ?? $this->hash([
            'parameters' => (array) $baseline->parameters, 'metadata' => (array) $baseline->metadata, 'frozen_contract' => $frozen,
        ]));
        $dataAndMtf = (string) ($identity['data_and_mtf_hash'] ?? $this->hash([
            'data_hash' => $identity['data_hash'], 'canonical_dataset_snapshots' => $identity['canonical_dataset_snapshots'],
        ]));
        $runtime = (string) ($identity['runtime_and_contract_hash'] ?? $this->hash([
            'execution_hash' => $identity['execution_hash'], 'compiler_protocol' => $compiled['protocol'] ?? null,
            'compiled_arms' => collect($compiled['arms'] ?? [])->map(fn (array $arm): array => [
                'role' => $arm['role'] ?? null, 'parameter_hash' => $arm['parameter_hash'] ?? null,
            ])->all(),
        ]));
        $intervention = (string) ($identity['intervention_hash'] ?? $this->hash([
            'axis' => $compiled['axis'] ?? null, 'arms' => collect($compiled['arms'] ?? [])->map(fn (array $arm): array => [
                'role' => $arm['role'] ?? null, 'runtime_value' => $arm['runtime_value'] ?? null,
                'parameter_hash' => $arm['parameter_hash'] ?? null,
            ])->all(),
        ]));
        $windowPlan = (string) ($identity['window_plan_hash'] ?? $this->hash([
            'temporal_binding_hash' => $passport->temporal_binding_hash, 'density_contract' => $density,
            'window_plan' => (array) ($identity['window_plan'] ?? []),
        ]));

        return [
            'contract_version' => ResearchExperimentConversionKernelService::CONTRACT_VERSION,
            'discovery_scope' => (array) ($identity['discovery_scope'] ?? []),
            'confirmation_route' => app(ResearchPaperEpochContractService::class)->confirmationRoute('academy_independent_confirmation', $identity),
            'source' => ['type' => 'edge_academy_trial', 'id' => (int) $trial->id],
            'scope' => ['symbol' => strtoupper((string) $passport->symbol), 'laboratory_timeframe' => strtoupper((string) $passport->timeframe), 'execution_timeframe' => 'M5'],
            'claim' => ['target_stage' => (string) ($compiled['axis'] ?? 'academy_stage'),
                'hypothesis' => (string) $trial->trial_type, 'minimum_meaningful_effect' => $density],
            'identity' => ['baseline_epoch_hash' => $baselineEpoch, 'data_and_mtf_hash' => $dataAndMtf,
                'runtime_and_contract_hash' => $runtime, 'intervention_hash' => $intervention, 'window_plan_hash' => $windowPlan,
                'evaluator_version' => (string) ($identity['evaluator_version'] ?? 'academy_full_replay_statistical_v1')],
            'arms' => collect($compiled['arms'] ?? [])->map(fn (array $arm): array => [
                'role' => $arm['role'] ?? null, 'runtime_value' => $arm['runtime_value'] ?? null,
                'parameter_hash' => $arm['parameter_hash'] ?? null,
            ])->all(),
            'revisions' => ['subject' => max(1, (int) ($identity['subject_revision'] ?? 1)),
                'evidence' => max(1, (int) ($identity['evidence_revision'] ?? 1))],
        ];
    }

    private function settleTechnicalQuarantine(object $trial, array $armContract, iterable $observations, string $reason): array
    {
        return DB::transaction(function () use ($trial, $armContract, $observations, $reason): array {
            $locked = DB::table('edge_academy_trials')->lockForUpdate()->find($trial->id);
            if (! $locked) {
                throw new RuntimeException('ACADEMY_TRIAL_MISSING_AT_SETTLEMENT');
            }
            if ($locked->settled_at !== null) {
                return ['protocol' => self::PROTOCOL, 'status' => (string) $locked->status, 'promotion_evidence' => false];
            }
            $settlement = ['protocol' => self::PROTOCOL, 'status' => 'technical_quarantine', 'reason' => $reason, 'promotion_evidence' => false];
            DB::table('edge_academy_trials')->where('id', $locked->id)->update(['status' => 'technical_quarantine',
                'outcome' => json_encode($settlement), 'settled_at' => now(), 'updated_at' => now()]);
            $receipt = $this->recordReceipt($armContract, $locked, $observations, $settlement, 'TECHNICAL_QUARANTINE', [], []);

            return [...$settlement, 'classification' => 'TECHNICAL_QUARANTINE', 'conversion_receipt' => $receipt];
        });
    }

    private function classification(array $settlement, array $outcome, array $counts): string
    {
        // Raw power and deltas remain observations. A modern evidence guard
        // failure cannot propose an economic replication or condemn the
        // strategy; only its evidence dependency may be repaired.
        if (($settlement['status'] ?? null) === 'settled_unassessable_stage_evidence') {
            return 'TECHNICAL_QUARANTINE';
        }
        $density = (string) data_get($settlement, 'density.status');
        if ($density === 'powered_for_economic_settlement') {
            $effect = $outcome['treatment_effect_after_cost_r'] ?? null;

            return ! is_numeric($effect) ? 'INCONCLUSIVE' : ((float) $effect > 0 ? 'POSITIVE_CANDIDATE' : ((float) $effect < 0 ? 'HARMFUL' : 'INCONCLUSIVE'));
        }
        if ($density === 'topology_failure_insufficient_events') {
            return (int) ($counts['setup'] ?? 0) === 0 || (int) ($counts['trigger'] ?? 0) === 0 ? 'UNREACHABLE' : 'UNDERPOWERED';
        }

        return 'UNDERPOWERED';
    }

    /** Every terminal cohort either opens durable work or has a receipt-backed reason. */
    private function recordReceipt(array $armContract, object $trial, iterable $observations, array $settlement, string $classification, array $outcome, array $counts): array
    {
        $contract = (array) ($armContract['research_experiment_contract'] ?? []);
        if ($contract === []) {
            throw new RuntimeException('ACADEMY_RESEARCH_CONTRACT_MISSING_AT_SETTLEMENT');
        }
        $work = match ($classification) {
            'POSITIVE_CANDIDATE' => ['type' => 'academy_independent_replication', 'priority' => 8],
            'HARMFUL' => ['type' => 'academy_harmful_intervention_repair', 'priority' => 8],
            'UNREACHABLE' => ['type' => 'academy_upstream_repair', 'priority' => 9],
            'UNDERPOWERED' => ['type' => 'academy_power_extension', 'priority' => 7],
            'TECHNICAL_QUARANTINE' => ['type' => 'academy_technical_quarantine', 'priority' => 9],
            default => ['type' => 'academy_adversarial_ablation', 'priority' => 6],
        };
        $references = collect($observations)->map(fn (array $observation): array => [
            'agent_id' => $observation['agent_id'] ?? null, 'model_version_id' => $observation['model_version_id'] ?? null,
            'run_id' => $observation['run_id'] ?? null,
            'role' => $observation['role'] ?? null, 'metrics_hash' => isset($observation['metrics']) ? $this->hash($observation['metrics']) : null,
        ])->values()->all();
        $result = $this->conversion->record($contract, [
            'academy_trial_id' => (int) $trial->id, 'academy_settlement' => $settlement,
            'immutable_arm_evidence' => $references, 'outcome' => $outcome, 'counts' => $counts,
            'promotion_evidence' => false,
        ], $classification, [...$work, 'identity' => 'academy-trial:'.$trial->id], []);
        if (($result['status'] ?? null) !== 'recorded') {
            throw new RuntimeException('ACADEMY_CONVERSION_RECEIPT_FAILED:'.($result['reason'] ?? 'UNKNOWN'));
        }

        return $result;
    }

    private function axisFromArms(string $arms): ?string
    {
        $first = (json_decode($arms, true) ?: [])[0] ?? [];

        return $first['changed_axis'] ?? null;
    }

    private function diff(array $old, array $new): array
    {
        $out = [];
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) {
            if (($old[$key] ?? null) !== ($new[$key] ?? null)) {
                $out[$key] = ['old' => $old[$key] ?? null, 'new' => $new[$key] ?? null];
            }
        }

        return $out;
    }

    private function hash(mixed $value): string
    {
        return hash('sha256', json_encode($this->canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function coldStartBudgetScope(string $symbol, string $timeframe, string $foundationSha, array $streams, string $executionHash): string
    {
        // Evaluator releases and manifest labels do not create new market
        // observations. Only genuinely different sealed input/cost bytes
        // identify another bounded discovery scope.
        return $this->hash([self::COLD_START_PROTOCOL, $symbol, $timeframe, $foundationSha, $streams, $executionHash]);
    }

    private function dependencyBudgetScope(string $symbol, string $timeframe, array $dependencies): string
    {
        $discovery = (array) ($dependencies['discovery_scope'] ?? []);
        if ($discovery !== []) return $this->hash(['prospective_academy_physical_discovery_budget_v1', $symbol, $timeframe,
            $dependencies['foundation_sha256'], $discovery['parent_fork_price_sha256'],
            $discovery['parent_economic_rows_sha256'], $dependencies['execution_hash']]);
        return $this->coldStartBudgetScope($symbol, $timeframe, $dependencies['foundation_sha256'],
            $dependencies['mtf_source_sha256'], $dependencies['execution_hash']);
    }

    /** Fresh hypothesis models carry strategy facts, never archived admission ownership or outcomes. */
    private function hypothesisRuntimeMetadata(array $metadata): array
    {
        foreach (['edge_genesis', 'edge_genesis_attribution', 'full_stack_playbook', 'full_stack_research_playbook',
            'liquidity_trap', 'mtf_research_playbook', 'skill_cartridge_transplant', 'skill_cartridge_interaction',
            'skill_mentor', 'evolution_stage', 'elite_agent_passport', 'learning_lane', 'learning_receipt',
            'causal_learning_cohort', 'causal_learning_experiment', 'authority_incubator', 'authority_descendant',
            'parent_foundry', 'academy_experiment', 'last_result', 'last_screen_result', 'full_validation_batch',
            'instrument_assignment', 'portfolio_research_contract'] as $key) unset($metadata[$key]);
        return $metadata;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        } if (! array_is_list($value)) {
            ksort($value);
        } foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function blocked(string $reason): array
    {
        return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason' => $reason, 'promotion_evidence' => false];
    }
}
