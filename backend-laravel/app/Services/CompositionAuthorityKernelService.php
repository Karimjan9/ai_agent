<?php

namespace App\Services;

use App\Models\ModelVersion;
use Illuminate\Support\Arr;

/**
 * The only compiler permitted to freeze a research composition. It produces
 * a deterministic passport before a replay/job is created; it is never a
 * live-trade selector and deliberately fails closed outside XAUUSD.
 */
class CompositionAuthorityKernelService
{
    public const PROTOCOL = 'xauusd_composition_authority_kernel_v1';
    public const PROSPECTIVE_RECIPE_PROTOCOL = 'prospective_composition_recipe_v1';
    public const PROSPECTIVE_RECIPE_METADATA = 'prospective_scoped_composition_recipe';

    /** Freeze executable semantics before the future dataset has an identity. */
    public function prospectiveRecipeFromMetadata(ModelVersion $model): array
    {
        $metadata = (array) $model->metadata;
        $intrinsic = (array) data_get(app(LabImmutableEvidenceService::class)->modelRuntimeBasis($model), 'components', []);
        unset($intrinsic['composition_passport'], $intrinsic['composition_runtime_contract'],
            $intrinsic['execution_contract'], $intrinsic['smart_composition_treatment'],
            $intrinsic[self::PROSPECTIVE_RECIPE_METADATA], $intrinsic[self::PROSPECTIVE_RECIPE_METADATA.'_hash']);
        $intrinsic += Arr::only($metadata, ['strategy_family', 'specialist_role', 'specialist_genetic_envelope',
            'causal_learning_cohort', 'liquidity_atr_binding']);
        $passport = (array) data_get($metadata, 'smart_composition.composition_passport', []);
        if ($passport !== [] && (($passport['protocol'] ?? null) !== self::PROTOCOL || empty($passport['composition_id']))) {
            throw new \InvalidArgumentException('PROSPECTIVE_RECIPE_SOURCE_PASSPORT_INVALID');
        }
        $assignment = (array) ($metadata['instrument_research_assignment'] ?? []);
        unset($assignment['assignment_hash'], $assignment['parameter_hash'], $assignment['lab_agent_id'],
            $assignment['lab_generation_id'], $assignment['model_version_id'], $assignment['data_hash'],
            $assignment['dataset_hash'], $assignment['execution_hash'], $assignment['mtf_bundle_hash'],
            $assignment['mtf_manifest'], $assignment['window_composition_id']);
        if (isset($assignment['source_components'])) unset($assignment['source_components']['composition_id']);
        $recipe = $this->canonicalize([
            'protocol' => self::PROSPECTIVE_RECIPE_PROTOCOL,
            'strategy' => (string) $model->strategy, 'version' => (string) $model->version,
            'parameters' => (array) $model->parameters,
            'source_parameters' => (array) ($metadata['prospective_scoped_source_parameters']
                ?? data_get($metadata, self::PROSPECTIVE_RECIPE_METADATA.'.source_parameters', $model->parameters ?? [])),
            'intrinsic_metadata' => $intrinsic,
            'source_passport_semantics' => $this->prospectivePassportSemantics($passport),
            'program_tuple' => $passport === [] ? [] : Arr::only($passport, ['components', 'typed_program']),
            'instrument_assignment_semantics' => $assignment,
        ]);
        $hash = app(ResearchPaperEpochContractService::class)->parameterHash($recipe);
        if (array_key_exists(self::PROSPECTIVE_RECIPE_METADATA, $metadata)
            && (! is_array($metadata[self::PROSPECTIVE_RECIPE_METADATA])
                || app(ResearchPaperEpochContractService::class)->parameterHash($metadata[self::PROSPECTIVE_RECIPE_METADATA]) !== $hash
                || (isset($metadata[self::PROSPECTIVE_RECIPE_METADATA.'_hash'])
                    && $metadata[self::PROSPECTIVE_RECIPE_METADATA.'_hash'] !== $hash))) {
            throw new \InvalidArgumentException('PROSPECTIVE_RECIPE_SOURCE_SEMANTIC_DRIFT');
        }
        foreach (array_unique([...array_keys($recipe['parameters']), ...array_keys($recipe['source_parameters'])]) as $gene) {
            if (! $this->prospectiveParameterInterventionAllowed($recipe, (string) $gene)
                && app(ResearchPaperEpochContractService::class)->parameterHash(Arr::only($recipe['parameters'], [$gene]))
                    !== app(ResearchPaperEpochContractService::class)->parameterHash(Arr::only($recipe['source_parameters'], [$gene]))) {
                throw new \InvalidArgumentException('PROSPECTIVE_RECIPE_INTERVENTION_OVERRIDDEN_BY_SOURCE_MANAGEMENT');
            }
        }

        return $recipe;
    }

    /** The constructor can restrict its original legal mask before selecting a single intervention. */
    public function prospectiveParameterInterventionAllowed(array $recipe, string $gene): bool
    {
        if (($recipe['program_tuple'] ?? []) === []) return true;
        $adapter = (array) data_get($recipe, 'source_passport_semantics.management_contract.runtime_adapter', []);
        if (($adapter['engine'] ?? null) === 'parameter_preserving_replay_v1') return true;
        if (($adapter['engine'] ?? null) !== 'single_partial_runner_v1') return false;
        $owned = ['partial_take_profit_fraction', 'trailing_atr_multiplier', 'time_stop_candles'];
        if (($adapter['partial_target_r'] ?? null) !== null
            && (float) data_get($recipe, 'source_parameters.atr_stop_multiplier', 0) > 0) $owned[] = 'partial_target_atr_multiplier';
        if (($adapter['final_target_r'] ?? null) !== null) $owned[] = 'atr_target_multiplier';

        return ! in_array($gene, $owned, true);
    }

    /** Programme parity permits declared parameter contrasts, never a different predicate, tool or risk policy. */
    public function prospectiveProgrammeHash(ModelVersion $model): string
    {
        $programme = $this->prospectiveRecipeFromMetadata($model);
        unset($programme['parameters'], $programme['source_parameters'], $programme['strategy'], $programme['version']);
        $metadata = (array) $model->metadata;
        $schemas = app(StrategyParameterSchemaService::class);
        $programme['normalized_runtime'] = [
            'base_strategy' => $schemas->runtimeBaseStrategy((string) $model->strategy, $metadata['base_strategy'] ?? null,
                $metadata['strategy_family'] ?? data_get($programme, 'source_passport_semantics.strategy_contract.strategy_spec.family')),
        ];
        $programme['normalized_runtime']['family'] = $schemas->family($programme['normalized_runtime']['base_strategy']);
        $bindings = ['experiment_key', 'experiment_id', 'role', 'source_lesson_id', 'source_pair_id',
            'source_candidate_agent_id', 'source_control_agent_id', 'baseline_model_version_id',
            'gene', 'value', 'baseline_old_value', 'selector_hash', 'selector_protocol', 'selector_policy'];
        foreach ([
            'source_passport_semantics.learning_experiment' => $bindings,
            'intrinsic_metadata.causal_learning_cohort' => [...$bindings, 'experiment_kind'],
            'intrinsic_metadata.causal_learning_cohort.blinded_selector' => ['gene', 'value', 'old_value', 'seed',
                'selection_hash', 'parameter_hash', 'parent_parameter_hash', 'baseline_parameter_hash', 'candidate_parameter_hash'],
            'intrinsic_metadata.causal_learning_cohort.memory_search_receipt' => ['receipt_hash', 'source_lesson_id',
                'source_pair_id', 'source_agent_id', 'source_model_version_id', 'selected_gene', 'selected_value'],
            'instrument_assignment_semantics' => ['changed_gene', 'sealed_treatment_gene', 'experiment_role', 'selection_mode'],
            'instrument_assignment_semantics.decision_doctrine' => ['changed_gene', 'objective'],
            'instrument_assignment_semantics.pair_reservation' => ['pair_id', 'reservation_id', 'reservation_key',
                'reservation_hash', 'experiment_id', 'experiment_key', 'lab_generation_id', 'control_agent_id', 'candidate_agent_id',
                'control_model_version_id', 'candidate_model_version_id', 'baseline_parameter_hash', 'control_parameter_hash',
                'candidate_parameter_hash', 'dataset_hash', 'data_hash', 'execution_hash'],
        ] as $path => $keys) {
            $value = data_get($programme, $path);
            if (is_array($value)) data_set($programme, $path, Arr::except($value, $keys));
        }
        // These source declarations may carry cost/security limits outside the passport.
        $programme['external_execution_semantics'] = Arr::except((array) ($metadata['execution_contract'] ?? []),
            ['execution_hash', 'data_hash', 'dataset_hash', 'replay_dataset_hash']);
        $runtime = (array) ($metadata['composition_runtime_contract'] ?? []);
        $runtime = Arr::except($runtime, ['contract_hash', 'composition_id']);
        foreach ([
            'runtime_bindings.risk' => ['value'],
            'execution_authority.dataset' => ['replay_dataset_hash'],
            'execution_authority.execution' => ['execution_hash'],
            'execution_authority.instrument' => ['assignment_hash'],
            'execution_authority.mtf' => ['bundle_hash', 'stream_hashes'],
        ] as $path => $keys) {
            $value = data_get($runtime, $path);
            if (is_array($value)) data_set($runtime, $path, Arr::except($value, $keys));
        }
        $programme['external_runtime_semantics'] = $runtime;

        return app(ResearchPaperEpochContractService::class)->parameterHash($programme);
    }

    /** Compile only a request clone; neither the source model nor its historical passport is persisted. */
    public function compileProspectiveRecipeForRequest(ModelVersion $model, array $actualScope): array
    {
        $recipe = $this->prospectiveRecipeFromMetadata($model);
        $recipeHash = app(ResearchPaperEpochContractService::class)->parameterHash($recipe);
        if (! is_string($actualScope['expected_recipe_hash'] ?? null)
            || ! hash_equals($recipeHash, $actualScope['expected_recipe_hash'])) {
            throw new \InvalidArgumentException('PROSPECTIVE_RECIPE_ORIGINAL_SEAL_REQUIRED');
        }
        $manifest = (array) ($actualScope['mtf_manifest'] ?? []);
        if (($actualScope['timeframe'] ?? null) !== 'M5'
            || ! is_string($actualScope['dataset_hash'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $actualScope['dataset_hash'])
            || ! is_string($actualScope['execution_hash'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $actualScope['execution_hash'])
            || ($manifest['protocol'] ?? null) !== MultiTimeframeSnapshotService::PROTOCOL
            || ($manifest['bundle_hash'] ?? null) !== $actualScope['dataset_hash']
            || ! collect(['M5', 'M15', 'H1', 'H4'])->every(fn (string $timeframe): bool =>
                is_string(data_get($manifest, 'streams.'.$timeframe.'.sha256'))
                && preg_match('/^[a-f0-9]{64}$/D', data_get($manifest, 'streams.'.$timeframe.'.sha256')) === 1)) {
            throw new \InvalidArgumentException('PROSPECTIVE_RECIPE_ACTUAL_MTF_IDENTITY_REQUIRED');
        }
        $metadata = (array) $model->metadata;
        $source = (array) data_get($metadata, 'smart_composition.composition_passport', []);
        $passport = [];
        if ($source !== []) {
            $components = (array) ($source['components'] ?? []);
            $runtime = $this->strategies->runtime((string) ($components['strategy_id'] ?? ''));
            $base = app(StrategyParameterSchemaService::class)->runtimeBaseStrategy((string) $model->strategy,
                $metadata['base_strategy'] ?? null, (string) ($metadata['strategy_family'] ?? data_get($runtime, 'family')));
            if (! is_array($runtime)
                || $base !== $this->strategies->runtimeBaseStrategy((string) ($components['strategy_id'] ?? ''))
                || (string) ($metadata['strategy_architecture'] ?? '') !== (string) ($runtime['architecture'] ?? '')
                || (string) data_get($metadata, 'tactic_contract.architecture', '') !== (string) ($components['tactic_id'] ?? '')) {
                throw new \InvalidArgumentException('PROSPECTIVE_RECIPE_SOURCE_RUNTIME_MISMATCH');
            }
            $adapter = $this->management->runtimeAdapter((string) ($components['management_id'] ?? ''));
            if (! is_array($adapter)) throw new \InvalidArgumentException('PROSPECTIVE_RECIPE_MANAGEMENT_ADAPTER_REQUIRED');
            $passport = $this->freeze($this->prospectivePassportProposal($source, $actualScope));
            if (isset($source['learning_experiment'])) {
                $experiment = (array) $source['learning_experiment'];
                if (($experiment['role'] ?? null) === 'blinded') $experiment['blinded_selector'] = [
                    'protocol' => $experiment['selector_protocol'] ?? null, 'gene' => $experiment['gene'] ?? null,
                    'value' => $experiment['value'] ?? null, 'selection_hash' => $experiment['selector_hash'] ?? null];
                $passport = $this->bindLearningExperiment($passport, $experiment);
            }
            if (array_key_exists('learning_receipt_ids', $source)) {
                $passport = $this->bindLearningDirective($passport, (array) ($source['learning_directive'] ?? []));
            }
            if (app(ResearchPaperEpochContractService::class)->parameterHash($this->prospectivePassportSemantics($passport))
                !== app(ResearchPaperEpochContractService::class)->parameterHash($recipe['source_passport_semantics'])) {
                throw new \InvalidArgumentException('PROSPECTIVE_RECIPE_COMPILER_SEMANTIC_DRIFT');
            }
            $metadata['smart_composition']['composition_passport'] = $passport;
        }
        unset($metadata['instrument_research_assignment'], $metadata['instrument_assignment'],
            $metadata['composition_runtime_contract'], $metadata['composition_passport'], $metadata['execution_contract']);
        $metadata[self::PROSPECTIVE_RECIPE_METADATA] = $recipe;
        $metadata[self::PROSPECTIVE_RECIPE_METADATA.'_hash'] = $recipeHash;
        $transient = clone $model;
        $transient->metadata = $metadata;

        return ['status' => 'compiled_prospective_recipe', 'model' => $transient,
            'recipe' => $recipe, 'recipe_hash' => $recipeHash, 'passport' => $passport];
    }

    private function prospectivePassportSemantics(array $passport): array
    {
        unset($passport['composition_id'], $passport['data_hash'], $passport['execution_hash']);
        if (isset($passport['provenance'])) unset($passport['provenance']['data_hash'], $passport['provenance']['execution_hash']);

        // A compiled 0.0 and its persisted JSON 0 are the same passport semantics.
        return json_decode(json_encode($this->canonicalize($passport), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            true, 512, JSON_THROW_ON_ERROR);
    }

    /** Recover only inputs represented in the original compiler product; an unrecoverable product fails the comparison. */
    private function prospectivePassportProposal(array $source, array $actualScope): array
    {
        $dataPlane = (array) data_get($source, 'temporal_policy.data_contract.data_plane', []);
        $dataContract = [];
        foreach (['m1_canonical', 'bid_ask_history', 'spread_history', 'slippage_model', 'latency_model',
            'deterministic_aggregation', 'gap_audit', 'closed_at_available_at', 'backward_only_alignment'] as $key) {
            $dataContract[$key] = ! in_array($key, (array) ($dataPlane['missing_requirements'] ?? []), true);
        }
        $dataContract['m5_canonical'] = (bool) ($dataPlane['m5_canonical'] ?? false);
        $dataContract['provider'] = $dataPlane['base_provider'] ?? null;
        $location = (array) ($source['location_thesis'] ?? []);
        $volume = (array) ($source['volume_provenance'] ?? []);

        return [
            'symbol' => $source['symbol'] ?? $source['tradable_symbol'] ?? '',
            ...Arr::only((array) ($source['components'] ?? []), ['strategy_id', 'tactic_id', 'risk_id', 'management_id']),
            'data_hash' => $actualScope['dataset_hash'], 'execution_hash' => $actualScope['execution_hash'],
            'data_contract' => $dataContract, 'market_state' => (array) ($source['market_state'] ?? []),
            'prior_ids' => (array) data_get($source, 'prior_contract.prior_ids', []),
            'horizon_mode' => $source['horizon_mode'] ?? 'day_structure',
            'horizon_contract' => (array) ($source['horizon_contract'] ?? []),
            'location_context' => [...$location, 'location_strength' => $location['strength'] ?? 0,
                'location_available' => ($location['trigger_admissible'] ?? false) === true],
            'volume_context' => [...$volume, 'volume_available' => ! ($volume['volume_unavailable'] ?? true)],
            'risk_state' => data_get($source, 'risk_hysteresis.state', 'NORMAL'),
            'invalidation_model' => $source['invalidation_model'] ?? 'structure_stop',
            'target_model' => $source['target_model'] ?? 'H1_liquidity_target',
            'session_news_context' => ['session_state' => $source['session_handoff_state'] ?? 'unclassified',
                'news_state' => $source['news_state'] ?? 'normal'],
            'confirmation_families' => (array) data_get($source, 'typed_program.compatibility_graph.independent_confirmation_families', []),
            'learning_directive' => (array) ($source['learning_directive'] ?? []),
            'setup_expires_at' => $source['setup_expires_at'] ?? null, 'trigger_expires_at' => $source['trigger_expires_at'] ?? null,
        ];
    }

    /**
     * Fresh confirmation research keeps the archived parameters exactly, but
     * never inherits an Edge passport for a different strategy runtime. This
     * is prospective metadata: the source passport and its evidence remain
     * unchanged. Management uses the already-implemented parameter lifecycle.
     */
    public function confirmationReplayMetadata(array $metadata, array $identity): array
    {
        $manifest = (array) ($identity['mtf_bundle_manifest'] ?? []);
        if ((string) ($manifest['protocol'] ?? '') !== MultiTimeframeSnapshotService::PROTOCOL
            || ! preg_match('/^[a-f0-9]{64}$/', (string) ($identity['data_hash'] ?? ''))
            || ! preg_match('/^[a-f0-9]{64}$/', (string) ($identity['execution_hash'] ?? ''))
            || ! collect(['M5', 'M15', 'H1', 'H4'])->every(fn (string $timeframe): bool =>
                preg_match('/^[a-f0-9]{64}$/', (string) data_get($manifest, 'streams.'.$timeframe.'.sha256', '')) === 1)) {
            throw new \InvalidArgumentException('CONFIRMATION_REPLAY_SEALED_MTF_IDENTITY_REQUIRED');
        }
        $passport = $this->freeze([
            'symbol' => 'XAUUSD', 'strategy_id' => 'str_042_confirmation_entry_mtf',
            'tactic_id' => 'confirmation_entry_mtf', 'risk_id' => 'atr_risk_envelope',
            'management_id' => 'parameter_preserving_research',
            'data_hash' => (string) $identity['data_hash'], 'execution_hash' => (string) $identity['execution_hash'],
            // These are the closed-candle, backward-only dataset contract;
            // runtime must still independently attest the actual stream bytes.
            'data_contract' => ['m5_canonical' => true, 'closed_at_available_at' => true, 'backward_only_alignment' => true],
        ]);
        $metadata['prospective_confirmation_runtime'] = [
            'protocol' => 'prospective_confirmation_runtime_v1',
            'historical_composition_id' => data_get($metadata, 'smart_composition.composition_passport.composition_id'),
            'source_passport_reused' => false, 'parameters_overridden' => false,
            'management_owner' => 'sealed_runtime_parameters', 'promotion_evidence' => false,
        ];
        $metadata['base_strategy'] = 'confirmation_entry_mtf_v1';
        $metadata['strategy_architecture'] = 'confirmation_entry_mtf';
        $metadata['architecture'] = 'confirmation_entry_mtf';
        $metadata['tactic_contract'] = $this->tactics->for('confirmation_entry_mtf', 'confirmation_entry_mtf');
        $metadata['smart_composition'] = [
            'strategy_library_id' => 'str_042_confirmation_entry_mtf',
            'tactic_library_key' => 'confirmation_entry_mtf',
            'risk_library_id' => 'atr_risk_envelope', 'management_id' => 'parameter_preserving_research',
            'composition_passport' => $passport,
        ];
        unset($metadata['instrument_research_assignment'], $metadata['instrument_assignment']);

        return $metadata;
    }

    /** A historical program is hypothesis provenance, never a passport to patch in place. */
    public function refreezeHistoricalHypothesis(array $sourcePassport, string $runtimeBaseStrategy): array
    {
        if ((string) data_get($sourcePassport, 'protocol') !== self::PROTOCOL) {
            throw new \InvalidArgumentException('Historical composition passport is invalid.');
        }
        $components = (array) data_get($sourcePassport, 'components', []);
        $passport = $this->freeze([
            'symbol' => 'XAUUSD',
            'strategy_id' => (string) data_get($components, 'strategy_id', ''),
            'tactic_id' => (string) data_get($components, 'tactic_id', ''),
            'risk_id' => (string) data_get($components, 'risk_id', ''),
            'management_id' => (string) data_get($components, 'management_id', ''),
        ]);
        if ($passport['components'] !== $components
            || (string) data_get($passport, 'strategy_signal_scope.runtime') !== $runtimeBaseStrategy) {
            throw new \InvalidArgumentException('Prospective composition is not the historical executable hypothesis.');
        }

        return $passport;
    }

    public function __construct(
        private StrategyLibraryCompilerService $strategies,
        private TacticCatalogueService $tactics,
        private RiskManagementLibraryService $risks,
        private TradeManagementLibraryService $management,
        private TemporalRoleBinderService $temporalRoles,
        private PriorKnowledgeVaultService $priors,
        private EvidenceOrthogonalityService $orthogonality,
        private TemporalAuthorityMatrixService $temporalAuthority,
        private LocationAtlasService $locations,
        private VolumeProvenanceContractService $volumeProvenance,
        private RiskHysteresisControllerService $riskHysteresis,
        private HorizonControllerService $horizons,
        private InvalidationTargetModelLibraryService $invalidationTargets,
        private SessionNewsStateMachineService $sessionNews,
        private ProfessionalTradingProgramCompilerService $typedPrograms,
    ) {}

    /** @return array<string, mixed> */
    public function freeze(array $proposal): array
    {
        $symbol = strtoupper((string) ($proposal['symbol'] ?? 'XAUUSD'));
        if ($symbol !== 'XAUUSD') {
            throw new \InvalidArgumentException('Composition Authority faqat XAUUSD research scope uchun ruxsat beradi.');
        }
        $storageTimeframe = strtoupper((string) config('services.xauusd_organism.laboratory_storage_timeframe', 'H1'));
        $executionTimeframe = strtoupper((string) config('services.xauusd_organism.execution_timeframe', 'M5'));
        $sensorScope = array_map('strtoupper', array_keys((array) config('services.xauusd_organism.timeframe_roles', [
            'H4' => 'macro_bias', 'H1' => 'regime_and_location', 'M15' => 'setup_and_confirmation', 'M5' => 'entry_and_execution',
        ])));

        $strategyId = (string) ($proposal['strategy_id'] ?? '');
        $tacticId = (string) ($proposal['tactic_id'] ?? 'trend_pullback');
        $riskId = (string) ($proposal['risk_id'] ?? 'atr_risk_envelope');
        $managementId = (string) ($proposal['management_id'] ?? 'balanced_professional');
        $strategy = $this->strategies->compile($strategyId);
        $runtimeBaseStrategy = $this->strategies->runtimeBaseStrategy($strategyId);
        if (! is_string($runtimeBaseStrategy) || $runtimeBaseStrategy === '') {
            throw new \InvalidArgumentException('COMPOSITION_ACTIVATION_SCOPE_UNPROVEN');
        }
        $strategySignalScope = $this->strategies->signalScope($runtimeBaseStrategy);
        $tacticRuntime = $this->tactics->for(
            (string) data_get($strategy, 'strategy_spec.family', ''),
            $tacticId,
        );
        if (array_intersect(
            (array) data_get($strategySignalScope, 'regimes', []),
            (array) data_get($tacticRuntime, 'target_regimes', []),
        ) === []) {
            throw new \InvalidArgumentException('COMPOSITION_ACTIVATION_SCOPE_EMPTY');
        }
        $risk = $this->risks->compile($riskId);
        $temporal = $this->temporalRoles->bind((array) data_get($strategy, 'temporal_role_contract.required_roles', []), (array) ($proposal['data_contract'] ?? []));
        $management = $this->management->compile($managementId, (string) data_get($strategy, 'strategy_spec.regime.allowed.0', 'trend'));
        $priorIds = array_values(array_unique(array_filter((array) ($proposal['prior_ids'] ?? []), 'is_string')));
        $priorBias = array_map(fn (string $id): array => $this->priors->proposalBias($id, (int) ($proposal['local_evidence_count'] ?? 0)), $priorIds);
        $state = (array) ($proposal['market_state'] ?? []);
        $components = [
            'strategy_id' => $strategyId,
            'tactic_id' => $tacticId,
            'risk_id' => $riskId,
            'management_id' => $managementId,
            'temporal_roles' => $temporal['roles'],
            'tools' => array_values(array_unique(array_merge(
                (array) data_get($strategy, 'strategy_spec.required_values', []),
                ['atr_risk_envelope', 'cost_firewall'],
            ))),
        ];
        $orthogonality = $this->orthogonality->assess($components['tools']);
        $horizon = (string) ($proposal['horizon_mode'] ?? 'day_structure');
        $horizonContract = $this->horizons->compile($horizon, (array) ($proposal['horizon_contract'] ?? []));
        $temporalAuthority = $this->temporalAuthority->compile((array) $temporal['roles'], $horizon);
        $location = $this->locations->thesis((array) ($proposal['location_context'] ?? []));
        $volume = $this->volumeProvenance->compile((array) ($proposal['volume_context'] ?? []));
        $riskHysteresis = $this->riskHysteresis->transition((string) ($proposal['risk_state'] ?? 'NORMAL'), (array) ($proposal['risk_metrics'] ?? []));
        $invalidationTarget = $this->invalidationTargets->compile((string) ($proposal['invalidation_model'] ?? 'structure_stop'), (string) ($proposal['target_model'] ?? 'H1_liquidity_target'), (array) data_get($temporalAuthority, 'owners', []));
        $sessionNews = $this->sessionNews->compile((array) ($proposal['session_news_context'] ?? []));
        $typedProgram = $this->typedPrograms->compile($proposal, $components, $temporal, $invalidationTarget, $management);
        $learningDirective = (array) ($proposal['learning_directive'] ?? []);
        $payload = $this->canonicalize([
            'protocol' => self::PROTOCOL, 'symbol' => $symbol, 'timeframe' => $storageTimeframe,
            'components' => $components, 'state' => $state, 'prior_ids' => $priorIds,
            'strategy_signal_scope' => $strategySignalScope,
            'typed_program_id' => (string) data_get($typedProgram, 'program_id', ''),
            'learning_receipt_ids' => (array) ($learningDirective['consumed_receipt_ids'] ?? []),
            'data_hash' => (string) ($proposal['data_hash'] ?? ''), 'execution_hash' => (string) ($proposal['execution_hash'] ?? ''),
        ]);
        $compositionId = 'xau-comp-'.substr(hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES)), 0, 24);

        return [
            'protocol' => self::PROTOCOL,
            'composition_id' => $compositionId,
            'status' => 'frozen_research_only',
            'tradable_symbol' => 'XAUUSD',
            'population_scope' => (string) config('services.xauusd_organism.population_scope', 'symbol'),
            'laboratory_storage_timeframe' => $storageTimeframe,
            'execution_timeframe' => $executionTimeframe,
            'temporal_sensor_scope' => $sensorScope,
            'decision_tools' => $components['tools'],
            'market_state' => $state,
            'components' => $components,
            'strategy_contract' => $strategy,
            'strategy_signal_scope' => $strategySignalScope,
            'risk_governor' => $risk['central_risk_governor'],
            'risk_contract' => $risk,
            'management_contract' => $management,
            'temporal_policy' => $temporal,
            'horizon_mode' => $horizon,
            'horizon_contract' => $horizonContract,
            'location_thesis' => $location,
            'information_families' => $orthogonality,
            'temporal_owners' => $temporalAuthority,
            'setup_expires_at' => $proposal['setup_expires_at'] ?? null,
            'trigger_expires_at' => $proposal['trigger_expires_at'] ?? null,
            'invalidation_model' => $invalidationTarget['invalidation_model'],
            'target_model' => $invalidationTarget['target_model'],
            'invalidation_target_contract' => $invalidationTarget,
            'management_state_machine' => $management['state_machine'],
            'session_handoff_state' => $sessionNews['session_handoff_state'],
            'news_state' => $sessionNews['news_state'],
            'session_news_contract' => $sessionNews,
            'typed_program' => $typedProgram,
            'filter_funnel_version' => OpportunityFunnelLedgerService::PROTOCOL,
            'volume_provenance' => $volume,
            'risk_hysteresis' => $riskHysteresis,
            'learning_directive' => $learningDirective,
            'consumed_learning_receipt_ids' => array_values(array_filter((array) ($learningDirective['consumed_receipt_ids'] ?? []), fn ($id): bool => is_numeric($id) && (int) $id > 0)),
            'prior_contract' => ['prior_ids' => $priorIds, 'biases' => $priorBias, 'runtime_authority' => false, 'promotion_authority' => false],
            'provenance' => [
                'library_version_hash' => hash('sha256', json_encode($this->canonicalize($components), JSON_UNESCAPED_SLASHES)),
                'selector_version' => StrategyTacticRiskCompositionPlannerService::PROTOCOL,
                'data_hash' => (string) ($proposal['data_hash'] ?? ''),
                'execution_hash' => (string) ($proposal['execution_hash'] ?? ''),
            ],
            'validation' => [
                'one_symbol_only' => true, 'closed_candle_only' => true,
                'martingale_forbidden' => true, 'loser_add_forbidden' => true,
                'm1_false_precision_blocked' => (bool) data_get($temporal, 'data_contract.m1_false_precision_blocked', true),
                'location_required_before_trigger' => true,
                'redundancy_penalty' => (int) ($orthogonality['redundancy_penalty'] ?? 0),
                'promotion_evidence' => false,
            ],
        ];
    }

    /**
     * Bind a pre-registered causal learning arm before the child is persisted.
     * Re-hashing prevents a passport that names one composition from being
     * reused after its learning/control role has changed.
     *
     * @return array<string,mixed>
     */
    public function bindLearningExperiment(array $passport, array $experiment): array
    {
        if ((string) data_get($passport, 'protocol') !== self::PROTOCOL) {
            throw new \InvalidArgumentException('A frozen composition passport is required.');
        }
        $role = (string) ($experiment['role'] ?? '');
        if (! in_array($role, ['memory_guided', 'hypothesis_guided', 'repair_guided', 'blinded', 'frozen_control'], true)) {
            return $passport;
        }
        if (in_array($role, ['memory_guided', 'hypothesis_guided', 'repair_guided'], true)
            && (! filled($experiment['gene'] ?? null) || ! array_key_exists('value', $experiment))) {
            throw new \InvalidArgumentException('A memory-guided causal arm requires an exact gene and value.');
        }
        $blindedSelector = (array) ($experiment['blinded_selector'] ?? []);
        if ($role === 'blinded'
            && (data_get($blindedSelector, 'protocol') !== CausalBlindedMutationSelectorService::PROTOCOL
                || ! filled(data_get($blindedSelector, 'gene'))
                || ! array_key_exists('value', $blindedSelector))) {
            throw new \InvalidArgumentException('A blinded causal arm requires a pre-registered exact single-gene selector.');
        }
        $bound = [
            ...$passport,
            'learning_experiment' => [
                'protocol' => CausalLearningCohortPlannerService::PROTOCOL,
                'experiment_key' => $experiment['experiment_key'] ?? null,
                'role' => $role,
                'source_lesson_id' => $experiment['source_lesson_id'] ?? null,
                'gene' => in_array($role, ['memory_guided', 'hypothesis_guided', 'repair_guided'], true)
                    ? ($experiment['gene'] ?? null)
                    : ($role === 'blinded' ? data_get($blindedSelector, 'gene') : null),
                'value' => in_array($role, ['memory_guided', 'hypothesis_guided', 'repair_guided'], true)
                    ? ($experiment['value'] ?? null)
                    : ($role === 'blinded' ? data_get($blindedSelector, 'value') : null),
                'selector_policy' => $role === 'blinded' ? 'cold_start_memory_blinded_selector' : null,
                'selector_protocol' => $role === 'blinded' ? data_get($blindedSelector, 'protocol') : null,
                'selector_hash' => $role === 'blinded' ? data_get($blindedSelector, 'selection_hash') : null,
                'same_parent_required' => true,
                'same_dataset_required' => true,
                'same_execution_contract_required' => true,
                'promotion_evidence' => false,
            ],
        ];
        unset($bound['composition_id']);
        $bound['composition_id'] = 'xau-comp-'.substr(hash('sha256', json_encode($this->canonicalize($bound), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)), 0, 24);

        return $bound;
    }

    /** @return array<string,mixed> */
    public function bindLearningDirective(array $passport, array $directive): array
    {
        if ((string) data_get($passport, 'protocol') !== self::PROTOCOL) {
            throw new \InvalidArgumentException('A frozen composition passport is required.');
        }
        $bound = [
            ...$passport,
            'learning_directive' => $directive,
            'learning_receipt_ids' => array_values(array_filter(
                (array) ($directive['consumed_receipt_ids'] ?? []),
                fn ($id): bool => is_numeric($id) && (int) $id > 0,
            )),
            'consumed_learning_receipt_ids' => array_values(array_filter(
                (array) ($directive['consumed_receipt_ids'] ?? []),
                fn ($id): bool => is_numeric($id) && (int) $id > 0,
            )),
        ];
        unset($bound['composition_id']);
        $bound['composition_id'] = 'xau-comp-'.substr(hash('sha256', json_encode(
            $this->canonicalize($bound),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        )), 0, 24);

        return $bound;
    }

    private function canonicalize(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
