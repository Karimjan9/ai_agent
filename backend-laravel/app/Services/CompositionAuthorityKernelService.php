<?php

namespace App\Services;

/**
 * The only compiler permitted to freeze a research composition. It produces
 * a deterministic passport before a replay/job is created; it is never a
 * live-trade selector and deliberately fails closed outside XAUUSD.
 */
class CompositionAuthorityKernelService
{
    public const PROTOCOL = 'xauusd_composition_authority_kernel_v1';

    public function __construct(
        private StrategyLibraryCompilerService $strategies,
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
        if (! in_array($role, ['memory_guided', 'repair_guided', 'blinded', 'frozen_control'], true)) {
            return $passport;
        }
        if (in_array($role, ['memory_guided', 'repair_guided'], true)
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
                'gene' => in_array($role, ['memory_guided', 'repair_guided'], true)
                    ? ($experiment['gene'] ?? null)
                    : ($role === 'blinded' ? data_get($blindedSelector, 'gene') : null),
                'value' => in_array($role, ['memory_guided', 'repair_guided'], true)
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
