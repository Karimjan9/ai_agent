<?php

namespace App\Services;

use App\Models\AgentLearningCausalExperiment;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabLearningLanePair;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A promising screen buys one fresh experiment, never retrospective skill. */
class ProspectiveRepairExperimentService
{
    public const PROTOCOL = 'prospective_screen_repair_v1';
    public const KIND = 'prospective_screen_repair';
    public const PROBE_POLICY = ['protocol' => 'prospective_repair_probe_v1',
        'training_tail_rows' => 15000, 'warmup_rows' => 512,
        'compute_profile' => 'bounded_15k_differential_screen_v2',
        'minimum_context_opportunities' => 20,
        'minimum_trades_per_arm' => 8, 'minimum_observed_quote_coverage' => 0.95,
        'independent_validation_evidence' => false, 'promotion_evidence' => false];

    public function eligible(string $symbol, string $timeframe, ?int $pairId = null): ?array
    {
        if (! Schema::hasTable('lab_learning_lane_pairs')) return null;
        $query = LabLearningLanePair::query()->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))->where('pair_integrity_status', 'verified');
        if ($pairId !== null) $query->whereKey($pairId);
        foreach ($query->latest('id')->limit(100)->get() as $pair) {
            $source = $this->source($pair);
            if ($source === null) continue;
            $retryOf = $this->technicalRetryOf($pair, $source);
            if ($retryOf === null) continue;
            if ($retryOf > 0) {
                $source['technical_retry_of_experiment_id'] = $retryOf;
                unset($source['source_hash']);
                $source['source_hash'] = $this->hash($source);
            }
            return $source;
        }
        return null;
    }

    /** Bounded fresh attempts only while no scientific result was observed. */
    private function technicalRetryOf(LabLearningLanePair $pair, array $source): ?int
    {
        $attempts = AgentLearningCausalExperiment::query()
            ->where('evidence->experiment_kind', self::KIND)
            ->where('evidence->source_pair_id', $pair->id)
            ->latest('id')->limit(4)->get();
        if ($attempts->isEmpty()) return 0;
        // G245/G246 were pre-replay constructor failures. G247's exact
        // control opened one run but received only a bounded-timeout error,
        // no strategy result or ledger. Allow one last versioned transport
        // repair; a fourth failed attempt exhausts this source permanently.
        if ($attempts->count() > 3) return null;

        $prior = $attempts->first()->load('generation');
        $priorSourceHash = (string) data_get($prior->evidence, 'prospective_source_hash', '');
        if ((string) $prior->status !== 'invalid_counterfactual_contract'
            || (string) $prior->generation?->status !== 'technical_quarantine'
            || strlen($priorSourceHash) !== 64
            || hash_equals($priorSourceHash, (string) $source['source_hash'])) return null;

        $armIds = array_values(array_unique(array_filter(array_map('intval', [
            $prior->guided_agent_id, $prior->blinded_agent_id, $prior->control_agent_id,
        ]))));
        if (count($armIds) !== 3) return null;
        $arms = LabAgent::query()->whereIn('id', $armIds)->get();
        if ($arms->count() !== 3 || $arms->contains(fn (LabAgent $arm): bool =>
            (string) $arm->lifecycle_status !== 'technical_quarantine')) return null;
        $runs = LabEvaluationRun::query()->whereIn('lab_agent_id', $armIds)->get();
        if ($attempts->count() === 3) {
            if (! $this->unobservedTransportTimeout($prior, $arms, $runs)) return null;
        } elseif ($runs->isNotEmpty() || $arms->contains(fn (LabAgent $arm): bool =>
            ! str_contains((string) $arm->decision_reason, 'NON_EXACT_SEMANTIC_PARENT')
            && ! str_contains((string) $arm->decision_reason,
                'Generation construction incomplete; candidate quarantined before replay'))) return null;

        if ($attempts->count() === 2) {
            $older = $attempts->last()->load('generation');
            $olderIds = array_values(array_unique(array_filter(array_map('intval', [
                $older->guided_agent_id, $older->blinded_agent_id, $older->control_agent_id,
            ]))));
            if (count($olderIds) !== 3 || (string) $older->status !== 'invalid_counterfactual_contract'
                || (string) $older->generation?->status !== 'technical_quarantine'
                || LabEvaluationRun::query()->whereIn('lab_agent_id', $olderIds)->exists()
                || LabAgent::query()->whereIn('id', $olderIds)->count() !== 3
                || LabAgent::query()->whereIn('id', $olderIds)->get()->contains(fn (LabAgent $arm): bool =>
                    (string) $arm->lifecycle_status !== 'technical_quarantine'
                    || ! str_contains((string) $arm->decision_reason, 'NON_EXACT_SEMANTIC_PARENT'))
                || ! $arms->every(fn (LabAgent $arm): bool => str_contains((string) $arm->decision_reason,
                    'Generation construction incomplete; candidate quarantined before replay'))) return null;
        }

        if ($attempts->count() === 3) {
            $middle = $attempts[1]->load('generation');
            $oldest = $attempts[2]->load('generation');
            if (! $this->unobservedConstructorFailure($middle)
                || ! $this->unobservedSemanticFailure($oldest)) return null;
        }

        return (int) $prior->id;
    }

    private function unobservedTransportTimeout(AgentLearningCausalExperiment $prior, $arms, $runs): bool
    {
        if ($runs->count() !== 1) return false;
        $run = $runs->first();
        $control = $arms->firstWhere('id', (int) $prior->control_agent_id);
        if (! $control || (int) $run->lab_agent_id !== (int) $control->id
            || (string) $run->phase !== 'screening'
            || (string) $run->status !== 'technical_error'
            || ! str_contains((string) $run->error_message, 'Bounded AI replay exceeded 780s')
            || data_get($run->request_meta, 'payload.policy_context.prospective_probe_window.evaluator_version')
                !== 'incremental_probe_window_v1'
            || (int) data_get($run->request_meta, 'payload.policy_context.prospective_probe_window.evaluated_rows')
                !== 15000
            || $run->trade_ledger_hash !== null
            || data_get($run->metrics, 'total_trades') !== null
            || data_get($run->response_meta, 'decision_trace_present') === true
            || data_get($run->response_meta, 'trade_ledger_complete') !== false
            || (int) data_get($run->response_meta, 'displayed_trade_count', -1) !== 0
            || ! (str_contains((string) $control->decision_reason, 'evaluator error isolated')
                || str_contains((string) $control->decision_reason,
                    'Frozen same-generation recovery contract is unavailable'))) return false;

        return $arms->reject(fn (LabAgent $arm): bool => (int) $arm->id === (int) $control->id)
            ->every(fn (LabAgent $arm): bool =>
            str_contains((string) $arm->decision_reason, 'FROZEN_CONTROL_REPLAY_INCOMPLETE'));
    }

    private function unobservedConstructorFailure(AgentLearningCausalExperiment $attempt): bool
    {
        return $this->unobservedEarlierFailure($attempt,
            'Generation construction incomplete; candidate quarantined before replay');
    }

    private function unobservedSemanticFailure(AgentLearningCausalExperiment $attempt): bool
    {
        return $this->unobservedEarlierFailure($attempt, 'NON_EXACT_SEMANTIC_PARENT');
    }

    private function unobservedEarlierFailure(AgentLearningCausalExperiment $attempt, string $reason): bool
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', [
            $attempt->guided_agent_id, $attempt->blinded_agent_id, $attempt->control_agent_id,
        ]))));
        if (count($ids) !== 3 || (string) $attempt->status !== 'invalid_counterfactual_contract'
            || (string) $attempt->generation?->status !== 'technical_quarantine'
            || LabEvaluationRun::query()->whereIn('lab_agent_id', $ids)->exists()) return false;
        $arms = LabAgent::query()->whereIn('id', $ids)->get();

        return $arms->count() === 3 && $arms->every(fn (LabAgent $arm): bool =>
            (string) $arm->lifecycle_status === 'technical_quarantine'
            && str_contains((string) $arm->decision_reason, $reason));
    }

    public function source(LabLearningLanePair $pair): ?array
    {
        if (! $pair->isVerifiedControlPair()) return null;
        $pair->loadMissing('generation', 'candidateAgent.modelVersion', 'controlAgent.modelVersion');
        if (! in_array($pair->generation?->status, ['screened', 'completed'], true)) return null;
        $sourceMtfHash = strtolower(trim((string) data_get($pair->generation?->trigger_context, 'mtf_bundle_hash', '')));
        if (! preg_match('/^[a-f0-9]{64}$/', $sourceMtfHash)
            || ! hash_equals($sourceMtfHash, strtolower((string) $pair->candidate_data_hash))
            || ! hash_equals($sourceMtfHash, strtolower((string) $pair->control_data_hash))) return null;
        $candidate = $pair->candidateAgent; $control = $pair->controlAgent;
        if (! $candidate || ! $control || ! app(ExactCausalBaselineService::class)->matches($candidate, $control)) return null;
        $diff = (array) $candidate?->parameter_diff;
        if (count($diff) !== 1 || ! $candidate?->modelVersion || ! $control?->modelVersion) return null;
        $gene = (string) array_key_first($diff); $change = (array) $diff[$gene];
        $result = (array) data_get($candidate->modelVersion->metadata, 'last_screen_result', []);
        if ((int) ($result['total_trades'] ?? 0) < 3 || (float) ($result['profit_factor'] ?? 0) <= 1
            || ! array_key_exists($gene, app(StrategyParameterSchemaService::class)->schema($pair->strategy_family))
            || data_get($control->modelVersion->parameters, $gene) !== ($change['old'] ?? null)
            || data_get($candidate->modelVersion->parameters, $gene) !== ($change['new'] ?? null)) return null;
        $runs = [];
        foreach ([$candidate, $control] as $arm) {
            $run = LabEvaluationRun::where('lab_agent_id', $arm->id)->where('phase', 'screening')
                ->where('status', 'completed')->latest('id')->first();
            if (! $run || ! app(LabImmutableEvidenceService::class)->learningEligibility($run)['complete']) return null;
            if ($arm->id === $candidate->id) {
                $frozenResult = app(LabImmutableEvidenceService::class)->latestArtifactPayload($run);
                if ((int) ($frozenResult['total_trades'] ?? 0) < 3 || (float) ($frozenResult['profit_factor'] ?? 0) <= 1) return null;
            }
            $runs[] = ['id' => $run->id, 'request_hash' => $run->request_hash,
                'response_hash' => $run->response_hash, 'code_hash' => $run->code_hash];
        }
        if (empty($runs[0]['code_hash']) || $runs[0]['code_hash'] !== $runs[1]['code_hash']) return null;
        $selected = (array) data_get($candidate->modelVersion->metadata, 'instrument_research_assignment.selected', []);
        $context = app(ContextContractV2Service::class)->canonicalDeclaredAxes(
            (array) data_get($selected, '0.activation_contract.context.declared_context', []));
        if (empty($context['venue_phase'])) return null;
        $semanticGroups = app(StrategySemanticGroupService::class);
        if (! $semanticGroups->sameGroup($candidate->modelVersion, (string) $pair->strategy_family,
            $control->modelVersion, (string) $pair->strategy_family)) return null;
        $parentGroup = $semanticGroups->fromModel($control->modelVersion, (string) $pair->strategy_family);
        if (! $semanticGroups->exactParentCompatible($control->modelVersion, $pair->symbol,
            $pair->timeframe, (string) $pair->strategy_family, [
                'specialist_role' => data_get($parentGroup, 'role'),
                'regime' => data_get($parentGroup, 'regime'),
                'volatility' => data_get($parentGroup, 'volatility'),
                'direction' => data_get($parentGroup, 'direction'),
            ])) return null;
        $parentContext = app(ContextContractV2Service::class)->canonicalDeclaredAxes($parentGroup);
        foreach (['regime', 'volatility', 'direction'] as $axis) {
            if (isset($parentContext[$axis]) && ($context[$axis] ?? null) !== $parentContext[$axis]) return null;
        }
        $target = $gene === 'transition_wait_candles' ? 'temporal_stability' : (string) $pair->target;
        $source = ['protocol' => self::PROTOCOL, 'source_pair_id' => (int) $pair->id,
            'source_candidate_agent_id' => (int) $candidate->id,
            'source_control_agent_id' => (int) $control->id,
            'baseline_model_version_id' => (int) $control->model_version_id,
            'strategy_family' => (string) $pair->strategy_family, 'gene' => $gene,
            'old_value' => $change['old'], 'value' => $change['new'], 'target' => $target,
            'source_data_hash' => (string) $pair->candidate_data_hash,
            'source_mtf_bundle_hash' => $sourceMtfHash,
            'source_execution_hash' => (string) $pair->candidate_execution_hash,
            'source_context_scope' => $context,
            'source_parent_semantic_group' => array_intersect_key($parentGroup,
                array_flip(['key', 'role', 'regime', 'volatility', 'direction'])),
            'source_runs' => $runs,
            // A changed evaluator is a new sealed attempt, never the same
            // experiment re-labeled after an unfavorable replay.
            'probe_evaluator_version' => ProspectiveRepairProbeWindowService::EVALUATOR,
            'probe_policy_hash' => $this->hash(self::PROBE_POLICY),
            'source_hypothesis_only' => true, 'old_control_is_not_treatment_proof' => true,
            'source_selected_after_screening' => true, 'promotion_evidence' => false];
        $source['source_hash'] = $this->hash($source);
        return $source;
    }

    public function seedPlan(array $source): array
    {
        return collect(['repair_guided', 'blinded', 'frozen_control'])->map(fn ($role, $i) => [
            'family' => $source['strategy_family'], 'origin' => 'prospective_repair',
            'target' => $source['target'], 'evolution_mode' => $role === 'frozen_control' ? 'frozen_control' : 'causal_repair_counterfactual',
            'niche' => ['slot' => $i + 1, 'data_lane' => 'price',
                'prospective_repair_source_pair_id' => $source['source_pair_id'], 'promotion_evidence' => false],
        ])->all();
    }

    public function materialize(array $plan, string $symbol, string $timeframe, int $generationId): array
    {
        $pairId = (int) collect($plan)->map(fn ($slot) => data_get($slot, 'niche.prospective_repair_source_pair_id'))->filter()->first();
        $source = $this->eligible($symbol, $timeframe, $pairId);
        $base = ['protocol' => self::PROTOCOL, 'status' => 'source_unavailable', 'promotion_evidence' => false];
        if ($source === null) return ['plan' => $plan, 'contract' => $base];
        $pair = LabLearningLanePair::with('controlAgent.modelVersion')->findOrFail($pairId);
        $model = $pair->controlAgent->modelVersion;
        $passport = app(StrategyTacticRiskCompositionPlannerService::class)->freezeConfirmationBaseline(
            $source['strategy_family'], $timeframe, $source['source_data_hash'], $source['source_execution_hash'],
            (string) data_get($model->metadata, 'strategy_architecture', ''),
            (string) data_get($model->metadata, 'tactic_contract.architecture', ''),
            (array) data_get($model->metadata, 'smart_composition.composition_passport', []));
        // The blind arm receives the same baseline mutation firewall as the
        // guided arm, but no lesson or outcome. An executable yet forbidden
        // risk/management gene must not make the triplet unconstructable.
        $admission = app(DependencyAwareEdgeGenesisFoundryService::class);
        $allowedGenes = array_values(array_filter(
            array_keys(app(StrategyParameterSchemaService::class)->schema($source['strategy_family'])),
            fn (string $gene): bool => array_key_exists($gene, (array) $model->parameters)
                && (bool) data_get($admission->mutationAdmission($model, $gene), 'allowed', false),
        ));
        if (! in_array($source['gene'], $allowedGenes, true)) {
            return ['plan' => $plan, 'contract' => [...$base, 'status' => 'guided_gene_not_admitted']];
        }
        $blind = app(CausalBlindedMutationSelectorService::class)->select($source['strategy_family'],
            $source['target'], (array) $model->parameters, $source['source_hash'], $source['gene'], $source['value'],
            allowedGenes: $allowedGenes);
        $parentGroup = (array) ($source['source_parent_semantic_group'] ?? []);
        $indexes = collect($plan)->keys()->filter(fn ($i) => data_get($plan[$i], 'niche.prospective_repair_source_pair_id') === $pairId)->take(3)->values();
        if ($passport === [] || $blind === null || $indexes->count() !== 3
            || empty($parentGroup['key']) || empty($parentGroup['role'])) {
            return ['plan' => $plan, 'contract' => [...$base, 'status' => 'exact_triplet_not_compilable']];
        }
        $validation = app(ActivationValidationPlanService::class)->reserve([
            'hypothesis_key' => $source['source_hash'], 'source_data_hash' => $source['source_data_hash'],
            'source_response_hash' => $this->hash($source['source_runs']),
            'source_execution_hash' => $source['source_execution_hash'],
            'source_mtf_bundle_hash' => $source['source_mtf_bundle_hash']]);
        $experimentKey = hash('sha512', self::PROTOCOL.'|'.$generationId.'|'.$source['source_hash']);
        foreach (['repair_guided', 'blinded', 'frozen_control'] as $offset => $role) {
            $i = $indexes[$offset]; $niche = (array) data_get($plan[$i], 'niche', []);
            $contract = [...$source, 'protocol' => CausalLearningCohortPlannerService::PROTOCOL,
                'experiment_kind' => self::KIND, 'experiment_key' => $experimentKey,
                'role' => $role, 'source_lesson_id' => null, 'baseline_old_value' => $source['old_value'],
                'source_authority' => 'screening_hypothesis_only', 'blinded_selector' => $blind,
                'construction_protocol' => CausalRepairFrontierService::CONSTRUCTION_PROTOCOL,
                'same_parent_required' => true, 'same_dataset_required' => true,
                'same_execution_contract_required' => true, 'validation_plan' => $validation,
                'probe_policy' => self::PROBE_POLICY,
                'validation_route' => ['route' => 'reserved_authorized_future_research',
                    'historical_route_status' => 'not_admitted',
                    'historical_blocker' => 'NO_PREREGISTERED_UNUSED_HISTORICAL_WINDOW',
                    'source_screen_is_discovery' => true, 'paper_2026_reuse_allowed' => false],
                'source_context_hash' => $this->hash($source['source_context_scope'])];
            $niche = [...$niche, ...$source['source_context_scope'],
                'specialist_role' => $parentGroup['role'],
                'regime' => $parentGroup['regime'],
                'volatility' => $parentGroup['volatility'],
                'direction' => $parentGroup['direction'],
                'causal_learning_cohort' => $contract, 'composition_passport' => $passport,
                'composition_lane' => 'prospective_repair', 'learning_memory_required' => false,
                'learning_memory_blinded' => $role === 'blinded', 'control_only' => $role === 'frozen_control'];
            $plan[$i] = [...$plan[$i], 'family' => $source['strategy_family'], 'target' => $source['target'],
                'niche' => app(CausalLearningCohortPlannerService::class)->isolateCausalNiche($niche)];
        }
        return ['plan' => array_values($plan), 'contract' => [...$base, 'status' => 'materialized',
            'experiment_key' => $experimentKey, 'experiment_kind' => self::KIND, 'source' => $source,
            'validation_plan' => $validation, 'slots' => $indexes->map(fn ($i) => $i + 1)->all()]];
    }

    private function hash(array $value): string
    {
        return app(ResearchPaperEpochContractService::class)->parameterHash($value);
    }

    /** Screening closes a discovery; it cannot masquerade as independent proof. */
    public function closeDiscovery(AgentLearningCausalExperiment $experiment): array
    {
        return DB::transaction(function () use ($experiment): array {
            $locked = AgentLearningCausalExperiment::whereKey($experiment->id)->lockForUpdate()->firstOrFail();
            if (data_get($locked->evidence, 'prospective_discovery_closure')) {
                return (array) data_get($locked->evidence, 'prospective_discovery_closure');
            }
            $armIds = [$locked->guided_agent_id, $locked->blinded_agent_id, $locked->control_agent_id];
            if (data_get($locked->evidence, 'experiment_kind') !== self::KIND
                || count(array_unique(array_filter($armIds))) !== 3) {
                throw new \RuntimeException('PROSPECTIVE_REPAIR_EXACT_TRIPLET_REQUIRED');
            }
            $quality = app(CausalScreeningBehaviorPreflightService::class)->assessLearnability($locked);
            $plan = (array) data_get($locked->evidence, 'prospective_validation_plan', []);
            if (! app(ActivationValidationPlanService::class)->valid($plan, $plan)) {
                throw new \RuntimeException('PROSPECTIVE_REPAIR_VALIDATION_PLAN_DRIFT');
            }
            $classification = match ($quality['status']) {
                'underpowered' => 'UNDERPOWERED', 'no_effect' => 'UNREACHABLE',
                'ready_for_independent_validation' => 'BEHAVIORAL_ACTIVATION_HYPOTHESIS',
                default => 'INCONCLUSIVE',
            };
            $next = $quality['status'] === 'no_effect' ? [] : [
                'type' => $quality['status'] === 'ready_for_independent_validation'
                    ? 'activation_independent_validation' : 'activation_new_opportunity_window',
                'validation_plan' => $plan,
                'identity' => $plan['plan_hash'], 'priority' => 8,
                'dependency_key' => 'authorized_research_dataset:'.$plan['plan_hash'],
                'retry_condition' => ['code' => $quality['status'] === 'data_missing'
                    ? 'OBSERVED_QUOTES_AND_MTF_ON_AUTHORIZED_NEW_WINDOW_REQUIRED'
                    : ($quality['status'] === 'underpowered'
                        ? 'AUTHORIZED_POWERED_CONTEXT_WINDOW_REQUIRED'
                        : 'AUTHORIZED_INDEPENDENT_VALIDATION_WINDOW_REQUIRED'),
                    'max_experiments' => 1, 'same_evidence_replay_forbidden' => true],
                'causal_experiment_id' => $locked->id, 'executable' => false];
            $receipt = app(ResearchExperimentConversionKernelService::class)->record([
                'contract_version' => ResearchExperimentConversionKernelService::CONTRACT_VERSION,
                'source' => ['type' => AgentLearningCausalExperiment::class, 'id' => $locked->id],
                'scope' => ['symbol' => $locked->symbol, 'laboratory_timeframe' => $locked->timeframe, 'execution_timeframe' => 'M5'],
                'claim' => ['target_stage' => 'independent_validation',
                    'hypothesis' => $locked->gene_key.' versus exact unchanged control and memory-blinded selector'],
                'identity' => ['baseline_epoch_hash' => (string) data_get($locked->evidence, 'prospective_source_hash'),
                    'data_and_mtf_hash' => (string) data_get($locked->generation?->trigger_context, 'mtf_bundle_hash',
                        $locked->generation?->data_fingerprint ?: data_get($locked->evidence, 'prospective_source_data_hash')),
                    'runtime_and_contract_hash' => $this->hash(['parity' => data_get($locked->evidence, 'causal_arm_parity', []),
                        'release' => data_get($locked->generation?->trigger_context, 'research_release', [])]),
                    'intervention_hash' => $this->hash(['gene' => $locked->gene_key, 'source' => data_get($locked->evidence, 'prospective_source_hash')]),
                    'window_plan_hash' => $plan['plan_hash'], 'evaluator_version' => self::PROTOCOL],
                'arms' => [['role' => 'guided', 'agent_id' => $locked->guided_agent_id],
                    ['role' => 'blinded', 'agent_id' => $locked->blinded_agent_id],
                    ['role' => 'frozen_control', 'agent_id' => $locked->control_agent_id]],
            ], $quality, $classification, $next,
                $next === [] ? ['code' => 'NO_EXECUTABLE_EFFECT_ON_PREREGISTERED_PROBE'] : []);
            if (($receipt['status'] ?? '') !== 'recorded') throw new \RuntimeException('PROSPECTIVE_DISCOVERY_RECEIPT_REQUIRED');
            $closure = ['protocol' => self::PROTOCOL, 'status' => $quality['status'], 'quality' => $quality,
                'conversion_receipt' => $receipt, 'independent_validation' => ['executable' => false,
                    'reason' => 'AUTHORIZED_UNUSED_RESEARCH_WINDOW_REQUIRED', 'plan_hash' => $plan['plan_hash']],
                'causal_credit' => false, 'paper_authority' => false, 'promotion_evidence' => false];
            $locked->update(['status' => 'provisional', 'evidence' => [...(array) $locked->evidence,
                'prospective_discovery_closure' => $closure], 'confirmed_at' => null]);
            return $closure;
        });
    }
}
