<?php

namespace App\Services;

use App\Models\ModelVersion;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;

/** Typed research products and execution passports; no role catalog entry grants authority. */
class SpecialistCouncilContractService
{
    public const MANIFEST_PROTOCOL = 'specialist_council_manifest_v1';
    public const PASSPORT_PROTOCOL = 'specialist_passport_v1';
    public const RUNTIME_PROTOCOL = 'specialist_council_runtime_v1';
    public const NATIVE_SOLO_PROTOCOL = 'specialist_council_native_solo_v1';
    public const NATIVE_CHOSEN_SOLO_PROTOCOL = 'specialist_council_native_chosen_solo_v1';
    public const TRADING_ROLES = ['scalp', 'hour', 'day', 'swing'];
    public const SUPPORT_TRIAL_PROTOCOL = 'specialist_support_role_trial_v1';

    /** Benchmarks name original producer paths, not caller-supplied pass flags. */
    public const SUPPORT_BENCHMARKS = [
        'strategy' => 'native_operator_behavior', 'tactic' => 'native_operator_behavior',
        'toolbox' => 'native_operator_behavior', 'risk' => 'native_operator_risk',
        'capital' => 'native_account_capital', 'execution' => 'native_account_execution',
        'data' => 'native_asof_observation', 'learning' => 'native_memory_selector',
        'evolution' => 'native_candidate_selector',
    ];

    private const PRODUCTS = [
        'scalp' => ['trade_intent', 'position_management'],
        'hour' => ['trade_intent', 'position_management'],
        'day' => ['trade_intent', 'position_management'],
        'swing' => ['trade_intent', 'position_management'],
        'strategy' => ['strategy_program'], 'tactic' => ['tactic_program'],
        'risk' => ['risk_decision'], 'capital' => ['capital_allocation'],
        'execution' => ['order_lifecycle'], 'toolbox' => ['typed_operator'],
        'learning' => ['scoped_knowledge'], 'evolution' => ['candidate_proposal'],
        'data' => ['as_of_observation'], 'evaluator' => ['independent_assessment'],
    ];

    private const ACTIONS = [
        'scalp' => ['propose_trade', 'manage_owned_position', 'wait'],
        'hour' => ['propose_trade', 'manage_owned_position', 'wait'],
        'day' => ['propose_trade', 'manage_owned_position', 'wait'],
        'swing' => ['propose_trade', 'manage_owned_position', 'wait'],
        'strategy' => ['propose_strategy', 'wait'], 'tactic' => ['propose_tactic', 'wait'],
        'risk' => ['reduce_size', 'reject_intent', 'reduce_exposure', 'wait'],
        'capital' => ['reserve_capital', 'allocate_within_limits', 'wait'],
        'execution' => ['execute_approved_plan', 'reconcile_fills', 'wait'],
        'toolbox' => ['propose_operator', 'wait'], 'learning' => ['derive_mature_knowledge', 'wait'],
        'evolution' => ['propose_candidate', 'wait'], 'data' => ['provide_observation', 'wait'],
        'evaluator' => ['assess_original_evidence', 'wait'],
    ];

    private const PRODUCT_FIELDS = [
        'trade_intent' => ['symbol', 'direction', 'horizon', 'entry', 'capital_required', 'estimated_cost_percent',
            'estimated_risk_percent', 'expires_at', 'invalidation', 'management_owner', 'management_version'],
        'position_management' => ['position_id', 'management_owner', 'management_version', 'action'],
        'strategy_program' => ['program_id', 'program_version', 'input_type', 'output_type', 'max_compute_ms'],
        'tactic_program' => ['program_id', 'program_version', 'input_type', 'output_type', 'max_compute_ms'],
        'risk_decision' => ['intent_id', 'decision', 'original_size', 'approved_size', 'external_limit_version'],
        'capital_allocation' => ['account_id', 'intent_id', 'reservation_id', 'capital_amount', 'ledger_version'],
        'order_lifecycle' => ['approved_plan_id', 'order_id', 'idempotency_key', 'state', 'fills', 'costs', 'reconciliation'],
        'typed_operator' => ['component_id', 'operator_contract', 'input_type', 'output_type', 'consumer_roles'],
        'scoped_knowledge' => ['scope', 'original_source_ids', 'matured_at', 'conclusion', 'known_limits'],
        'candidate_proposal' => ['candidate_id', 'lineage', 'components', 'proposed_experiment'],
        'as_of_observation' => ['symbol', 'event_identity', 'available_at', 'provenance', 'values'],
        'independent_assessment' => ['candidate_creator_id', 'original_run_ids', 'preregistered_plan_hash', 'reason_codes'],
    ];

    public function __construct(private ResearchPaperEpochContractService $epochs) {}

    public function roles(): array
    {
        $roles = [];
        foreach (self::PRODUCTS as $role => $products) {
            $roles[$role] = ['products' => $products, 'allowed_actions' => self::ACTIONS[$role],
                'may_create_order' => $role === 'execution', 'may_self_certify' => false,
                'may_change_external_risk_limits' => false, 'promotion_evidence' => false];
        }
        return $roles;
    }

    /** Preregister a role's actual producer and criterion before any outcome is read. */
    public function sealSupportRoleTrials(array $manifest, array $trials): array
    {
        if (count($trials) > 16) throw new InvalidArgumentException('SUPPORT_ROLE_TRIAL_BUDGET_EXCEEDED');
        $sealed = [];
        foreach ($trials as $trial) {
            if (! is_array($trial) || array_diff(array_keys($trial), ['protocol', 'component_id', 'role', 'benchmark',
                'minimum_evaluations', 'minimum_behavior_delta_decisions', 'minimum_positive_windows', 'minimum_opportunity_fraction',
                'component_contract_hash', 'producer_contracts', 'policy_binding', 'original_policy_benchmark', 'original_native_policy_benchmark', 'trial_hash']) !== []) {
                throw new InvalidArgumentException('SUPPORT_ROLE_TRIAL_ASSERTED_OUTCOMES_FORBIDDEN');
            }
            $id = (string) ($trial['component_id'] ?? '');
            $component = collect($manifest['components'])->firstWhere('id', $id);
            $role = $trial['role'] ?? '';
            if (! $component || isset($sealed[$id]) || ($component['role'] ?? '') !== $role
                || ! isset(self::SUPPORT_BENCHMARKS[$role]) || ($trial['benchmark'] ?? '') !== self::SUPPORT_BENCHMARKS[$role]
                || ($trial['protocol'] ?? self::SUPPORT_TRIAL_PROTOCOL) !== self::SUPPORT_TRIAL_PROTOCOL) {
                throw new InvalidArgumentException('SUPPORT_ROLE_TRIAL_COMPONENT_OR_BENCHMARK_MISMATCH');
            }
            $producers = [];
            foreach ($manifest['members'] as $member) {
                $operator = $member['operator_contract'] ?? null;
                if (! is_array($operator) || ($operator['component_id'] ?? '') !== $id) continue;
                if (! in_array($member['role'], $component['consumer_roles'], true)) throw new InvalidArgumentException('SUPPORT_ROLE_PRODUCER_CONSUMER_SCOPE_MISMATCH');
                $registered = app(TypedInstrumentFoundryService::class)->decisionOperatorContract(
                    (string) ($operator['source_task_key'] ?? ''), (string) ($operator['target'] ?? ''),
                    (array) ($operator['input_bindings'] ?? []), (array) ($operator['budget'] ?? []));
                $copy = array_diff_key($operator, array_flip(['component_id', 'contract_hash', 'contract_json']));
                $original = array_diff_key((array) ($registered['operator'] ?? []), array_flip(['contract_hash', 'contract_json']));
                if (($registered['status'] ?? '') !== 'native_decision_contract'
                    || ! app(LabImmutableEvidenceService::class)->equivalentJsonValue($copy, $original)
                    || ($component['output_type'] ?? '') !== ($operator['target'] ?? '')
                    || ($role === 'risk' && ($operator['target'] ?? '') !== 'risk_multiplier')) {
                    throw new InvalidArgumentException('SUPPORT_ROLE_REGISTERED_OPERATOR_REQUIRED');
                }
                $producers[$member['specialist_id']] = ['contract_hash' => $operator['contract_hash'],
                    'ast_hash' => $operator['ast_hash'], 'source_task_key' => $operator['source_task_key'],
                    'target' => $operator['target'], 'budget' => $operator['budget'], 'passport_hash' => $member['passport_hash']];
            }
            $calls = $trial['minimum_evaluations'] ?? 8;
            $delta = $trial['minimum_behavior_delta_decisions'] ?? 1;
            $windows = $trial['minimum_positive_windows'] ?? 2;
            $opportunity = $trial['minimum_opportunity_fraction'] ?? 0.8;
            if (! is_int($calls) || $calls < 1 || $calls > 100000 || ! is_int($delta) || $delta < 1 || $delta > 100000
                || ! is_int($windows) || $windows < 2 || $windows > 12
                || ! is_numeric($opportunity) || $opportunity < 0.5 || $opportunity > 1) {
                throw new InvalidArgumentException('SUPPORT_ROLE_PREREGISTERED_POWER_OR_RETENTION_INVALID');
            }
            $body = ['protocol' => self::SUPPORT_TRIAL_PROTOCOL, 'component_id' => $id, 'role' => $role,
                'benchmark' => self::SUPPORT_BENCHMARKS[$role], 'component_contract_hash' => $component['contract_hash'],
                'producer_contracts' => $producers, 'minimum_evaluations' => $calls,
                'minimum_behavior_delta_decisions' => $delta, 'minimum_positive_windows' => $windows,
                'minimum_opportunity_fraction' => (float) $opportunity];
            if (in_array($role, ['capital', 'execution'], true)) {
                $policy = $manifest[$role === 'capital' ? 'allocation' : 'execution'];
                $body['policy_binding'] = ($policy['id'] === $id && (string) $policy['version'] === $component['version'])
                    ? ['id' => $id, 'version' => $component['version'], 'policy_hash' => $this->epochs->parameterHash($policy),
                        'member_weights' => array_column($manifest['members'], 'capital_weight', 'specialist_id')] : null;
            }
            if ($role === 'data') $body['policy_binding'] = $id === SpecialistCouncilDataUseService::PROTOCOL
                && $component['version'] === '1' ? ['protocol' => SpecialistCouncilDataUseService::PROTOCOL] : null;
            if (isset($trial['original_policy_benchmark'])) {
                if (! in_array($role, ['learning', 'evolution'], true)) throw new InvalidArgumentException('SUPPORT_ROLE_POLICY_BENCHMARK_ROLE_MISMATCH');
                $reference = (array) $trial['original_policy_benchmark'];
                if (array_diff(array_keys($reference), ['challenge_key', 'challenge_hash', 'policy_key', 'policy_hash',
                    'original_experiment_ids', 'question_hashes', 'equal_caps']) !== []) {
                    throw new InvalidArgumentException('SUPPORT_ROLE_POLICY_BENCHMARK_ASSERTED_RESULTS_FORBIDDEN');
                }
                $body['original_policy_benchmark'] = $this->supportPolicyBenchmarkReference($component,
                    (string) ($reference['challenge_key'] ?? ''), (string) ($reference['policy_key'] ?? ''));
            }
            if (isset($trial['original_native_policy_benchmark'])) {
                if (! in_array($role, ['learning', 'evolution'], true) || isset($trial['original_policy_benchmark'])) {
                    throw new InvalidArgumentException('SUPPORT_ROLE_NATIVE_POLICY_BENCHMARK_ROLE_MISMATCH');
                }
                $reference = (array) $trial['original_native_policy_benchmark'];
                if (array_diff(array_keys($reference), ['challenge_key', 'challenge_hash', 'policy_key', 'policy_hash']) !== []) {
                    throw new InvalidArgumentException('SUPPORT_ROLE_NATIVE_POLICY_BENCHMARK_ASSERTED_RESULTS_FORBIDDEN');
                }
                $body['original_native_policy_benchmark'] = $this->supportNativePolicyBenchmarkReference($component, (string) ($reference['challenge_key'] ?? ''));
                $body['policy_binding'] = ['protocol' => ResearchKnowledgePortfolioService::NATIVE_POLICY_PROTOCOL,
                    'id' => $id, 'version' => $component['version'], 'policy_hash' => $body['original_native_policy_benchmark']['policy_hash']];
            }
            $sealed[$id] = [...$body, 'trial_hash' => $this->epochs->parameterHash($body)];
        }
        return array_values($sealed);
    }

    /** Existing Portfolio journals are original producers; this reference grants no qualification. */
    public function supportPolicyBenchmarkReference(array $component, string $challengeKey, string $policyKey): array
    {
        $policy = $this->originalPolicyJournal($policyKey, 'policy');
        $challenge = $this->originalPolicyJournal($challengeKey, 'policy_challenge');
        if (! in_array($component['role'] ?? '', ['learning', 'evolution'], true)
            || ($component['id'] ?? '') !== $policyKey || ($component['version'] ?? '') !== '1'
            || ! isset($policy['definition'], $challenge['cases'], $challenge['choices'])
            || ! in_array($policyKey, $challenge['policy_keys'] ?? [], true)
            || ! isset($challenge['choices'][$policyKey])
            || ($challenge['evaluator'] ?? '') !== ResearchKnowledgePortfolioService::POLICY_EVALUATOR
            || count($challenge['cases']) < 3 || count($challenge['cases']) > 16
            || ! app(LabImmutableEvidenceService::class)->equivalentJsonValue($challenge['caps'] ?? [],
                array_intersect_key($policy['definition'], array_flip(['compute_cap_seconds', 'max_candidates', 'exploration_fraction'])))) {
            throw new InvalidArgumentException('SUPPORT_ROLE_ORIGINAL_EQUAL_BUDGET_POLICY_CHALLENGE_REQUIRED');
        }
        return ['challenge_key' => $challengeKey, 'challenge_hash' => $this->epochs->parameterHash($challenge),
            'policy_key' => $policyKey, 'policy_hash' => $this->epochs->parameterHash($policy),
            'original_experiment_ids' => array_map('intval', array_keys($challenge['cases'])),
            'question_hashes' => array_column($challenge['cases'], 'question_hash'), 'equal_caps' => $challenge['caps']];
    }

    public function originalPolicyJournal(string $key, string $kind): ?array
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $key)) return null;
        $row = DB::table('research_knowledge_entries')->where('knowledge_key', $key)
            ->where('subject_type', ResearchKnowledgePortfolioService::META_PROTOCOL.':'.$kind)
            ->where('authority', 'research_only')->first();
        if (! $row) return null;
        $claim = json_decode($row->claim, true); $evidence = json_decode($row->evidence, true);
        return is_array($claim) && ($claim['protocol'] ?? '') === ResearchKnowledgePortfolioService::META_PROTOCOL
            && ($evidence['claim_hash'] ?? '') === $this->epochs->parameterHash($claim) ? $claim : null;
    }

    public function supportNativePolicyBenchmarkReference(array $component, string $challengeKey): array
    {
        $challenge = $this->originalPolicyJournal($challengeKey, 'native_policy_challenge');
        $policy = $this->originalPolicyJournal((string) ($component['id'] ?? ''), 'policy');
        if (! in_array($component['role'] ?? '', ['learning', 'evolution'], true) || ($component['version'] ?? '') !== '1'
            || ($challenge['native_protocol'] ?? '') !== ResearchKnowledgePortfolioService::NATIVE_POLICY_PROTOCOL
            || ($challenge['candidate_policy_key'] ?? '') !== ($component['id'] ?? '') || ! isset($policy['definition'])
            || ! app(LabImmutableEvidenceService::class)->equivalentJsonValue($challenge['policies'][$component['id']] ?? null,
                $policy) || count($challenge['panels'] ?? []) < 3) {
            throw new InvalidArgumentException('SUPPORT_ROLE_ORIGINAL_AUTHORIZED_NATIVE_POLICY_CHALLENGE_REQUIRED');
        }
        return ['challenge_key' => $challengeKey, 'challenge_hash' => $this->epochs->parameterHash($challenge),
            'policy_key' => $component['id'], 'policy_hash' => $this->epochs->parameterHash($policy)];
    }

    /** Seal every relevant component and native model, rather than trusting a supplied hash. */
    public function sealManifest(array $manifest): array
    {
        if (($manifest['protocol'] ?? self::MANIFEST_PROTOCOL) !== self::MANIFEST_PROTOCOL) {
            throw new InvalidArgumentException('Unknown council manifest protocol.');
        }
        $this->identity($manifest['council_id'] ?? null, 'council_id');
        $this->identity((string) ($manifest['version'] ?? ''), 'version');
        if (! is_array($manifest['members'] ?? null) || count($manifest['members']) < 1 || count($manifest['members']) > 32) {
            throw new InvalidArgumentException('A council requires one to thirty-two typed members.');
        }
        $members = []; $ids = []; $weight = 0.0;
        foreach ($manifest['members'] as $passport) {
            $member = $this->sealPassport((array) $passport);
            if (isset($ids[$member['specialist_id']])) throw new InvalidArgumentException('Duplicate specialist identity.');
            $ids[$member['specialist_id']] = true;
            $weight += (float) ($member['capital_weight'] ?? 0);
            $members[] = $member;
        }
        if ($weight > 1.000000001) throw new InvalidArgumentException('Council capital weights exceed the common capital.');
        $components = [];
        foreach ((array) ($manifest['components'] ?? []) as $component) {
            $components[] = $this->sealComponent((array) $component);
        }
        foreach (['routing', 'allocation', 'risk', 'execution'] as $policy) {
            $entry = $manifest[$policy] ?? null;
            if (! is_array($entry)) throw new InvalidArgumentException("Missing versioned {$policy} policy.");
            $this->identity($entry['id'] ?? null, $policy.'.id');
            $this->identity((string) ($entry['version'] ?? ''), $policy.'.version');
        }
        $this->validateExecutionPolicy((array) $manifest['execution']);
        $evaluation = (array) ($manifest['evaluation_policy'] ?? []);
        if (! in_array($evaluation['objective'] ?? null, ['net_return_at_equal_risk', 'lower_risk_at_equal_return'], true)
            || ! isset($evaluation['champion_model_version_id'], $evaluation['solo_model_version_id'])) {
            throw new InvalidArgumentException('Preregister an objective, native champion and suitable solo comparator.');
        }
        foreach (['champion_model_version_id', 'solo_model_version_id'] as $key) {
            $model = ModelVersion::find($evaluation[$key]);
            if (! $model) throw new InvalidArgumentException('Comparator native model is missing.');
            $evaluation[$key.'_hash'] = $this->modelHash($model);
        }
        $evaluation['minimum_independent_windows'] = max(3, (int) ($evaluation['minimum_independent_windows'] ?? 3));
        $evaluation['minimum_paired_trades'] = max(8, (int) ($evaluation['minimum_paired_trades'] ?? 8));
        $evaluation['required_ablations'] = array_values(array_unique([
            ...array_column($members, 'specialist_id'), ...array_column($components, 'id'),
        ]));
        $evaluation['external_risk_limits_mutable'] = false;
        $sealed = [
            ...array_diff_key($manifest, array_flip(['manifest_hash', 'promotion_evidence'])),
            'protocol' => self::MANIFEST_PROTOCOL, 'version' => (string) $manifest['version'],
            'members' => $members, 'components' => $components, 'evaluation_policy' => $evaluation,
            'epoch_contract' => $this->epochs->contract(), 'promotion_evidence' => false,
        ];
        return [...$sealed, 'manifest_hash' => $this->epochs->parameterHash($sealed)];
    }

    public function sealPassport(array $passport): array
    {
        $role = $passport['role'] ?? null;
        if (! isset(self::PRODUCTS[$role ?? ''])) throw new InvalidArgumentException('Unknown specialist role.');
        $this->identity($passport['specialist_id'] ?? null, 'specialist_id');
        $this->identity((string) ($passport['version'] ?? ''), 'specialist version');
        $asOf = $this->time($passport['as_of'] ?? null);
        $actions = array_values((array) ($passport['allowed_actions'] ?? self::ACTIONS[$role]));
        if (array_diff($actions, self::ACTIONS[$role]) !== [] || ! in_array('wait', $actions, true)) {
            throw new InvalidArgumentException('Specialist permission exceeds its typed role or omits WAIT.');
        }
        $scope = (array) ($passport['scope'] ?? []);
        if (empty($scope['symbols']) || empty($scope['contexts']) || empty($passport['known_limits'])) {
            throw new InvalidArgumentException('A passport requires scoped instruments, contexts and known limits.');
        }
        $resources = (array) ($passport['resources'] ?? []);
        foreach (['max_compute_ms' => 60000, 'max_memory_mb' => 512, 'max_lookback_bars' => 1000000] as $key => $ceiling) {
            if (! is_numeric($resources[$key] ?? null) || $resources[$key] <= 0 || $resources[$key] > $ceiling) {
                throw new InvalidArgumentException('Missing or unbounded specialist resource budget.');
            }
        }
        if (! is_array($passport['inputs'] ?? null) || $passport['inputs'] === []) {
            throw new InvalidArgumentException('A specialist needs an explicit as-of input contract.');
        }
        $sealed = [
            ...array_diff_key($passport, array_flip(['passport_hash', 'promotion_evidence', 'qualified'])),
            'protocol' => self::PASSPORT_PROTOCOL, 'version' => (string) $passport['version'],
            'as_of' => $asOf->toIso8601String(), 'products' => self::PRODUCTS[$role],
            'allowed_actions' => $actions, 'inputs_available_as_of_required' => true,
            'qualified_evidence' => (array) ($passport['qualified_evidence'] ?? []),
            'recheck_conditions' => (array) ($passport['recheck_conditions'] ?? ['scope_or_component_change']),
            'uncertainty' => (array) ($passport['uncertainty'] ?? ['status' => 'unqualified']),
            'promotion_evidence' => false,
        ];
        if (in_array($role, self::TRADING_ROLES, true)) {
            $horizon = (array) ($passport['horizon'] ?? []);
            if (($horizon['kind'] ?? null) !== $role) throw new InvalidArgumentException('Trading horizon must be declared independently of sensor timeframes.');
            foreach (['decision_interval_seconds', 'reevaluation_interval_seconds', 'max_holding_seconds'] as $key) {
                if (! is_int($horizon[$key] ?? null) || $horizon[$key] <= 0 || $horizon[$key] > 31536000) {
                    throw new InvalidArgumentException('Invalid trading horizon duration.');
                }
            }
            if ($horizon['max_holding_seconds'] < $horizon['reevaluation_interval_seconds']) {
                throw new InvalidArgumentException('Reassessment must fit inside the holding horizon.');
            }
            $requirements = (array) ($passport['data_requirements'] ?? []);
            $precision = $horizon['execution_precision'] ?? 'candle';
            $required = $role === 'swing' ? ['gap', 'carry', 'rollover', 'mature_holding_outcomes']
                : ($role === 'scalp' ? ['bid_ask', 'spread', 'slippage', 'quote_age', 'intrabar_ambiguity'] : ['sessions', 'costs']);
            if ($role === 'scalp' && $horizon['decision_interval_seconds'] < 60) {
                $required = [...$required, 'ticks', 'latency', 'order_fills'];
                if ($precision !== 'tick') throw new InvalidArgumentException('Second-scale scalp requires tick execution evidence.');
            }
            if (array_diff($required, $requirements) !== []) throw new InvalidArgumentException('Horizon data prerequisites are incomplete.');
            $model = ModelVersion::find($passport['model_version_id'] ?? 0);
            if (! $model) throw new InvalidArgumentException('Trading specialist native model is missing.');
            foreach (['strategy_version', 'tactic_version', 'management_version'] as $key) {
                $this->identity((string) ($passport[$key] ?? ''), $key);
            }
            if (! is_numeric($passport['capital_weight'] ?? null) || $passport['capital_weight'] <= 0 || $passport['capital_weight'] > 1
                || ! is_numeric($passport['risk_per_trade_percent'] ?? null) || $passport['risk_per_trade_percent'] <= 0
                || $passport['risk_per_trade_percent'] > 2) {
                throw new InvalidArgumentException('Missing bounded capital or risk requirement.');
            }
            $sealed['model_version_id'] = $model->id;
            $sealed['source_model_hash'] = $this->modelHash($model);
            $sealed['strategy'] = $model->strategy;
            $sealed['parameters'] = (array) $model->parameters;
            $sealed['sensor_timeframes'] = array_values((array) ($passport['sensor_timeframes'] ?? []));
        }
        return [...$sealed, 'passport_hash' => $this->epochs->parameterHash($sealed)];
    }

    /** Components can enter bounded research without masquerading as trade-qualified agents. */
    public function componentAdmission(array $component, string $targetRole): array
    {
        try {
            $sealed = $this->sealComponent($component);
            if (! isset(self::PRODUCTS[$targetRole]) || ! in_array($targetRole, $sealed['consumer_roles'], true)) {
                throw new InvalidArgumentException('Component output cannot be consumed by that role.');
            }
            return ['status' => 'research_admitted', 'allowed' => true, 'component' => $sealed,
                'paper_authority_granted' => false, 'promotion_evidence' => false];
        } catch (InvalidArgumentException $e) {
            return ['status' => 'withheld', 'allowed' => false, 'reason_code' => 'COMPONENT_CONTRACT_INVALID',
                'detail' => $e->getMessage(), 'paper_authority_granted' => false, 'promotion_evidence' => false];
        }
    }

    /** Structural product admission is separate from evidence qualification and paper entitlement. */
    public function product(array $passport, array $product, string $asOf): array
    {
        $role = $passport['role'] ?? '';
        $type = $product['type'] ?? '';
        if (! isset(self::PRODUCTS[$role]) || ! in_array($type, self::PRODUCTS[$role], true)) {
            throw new InvalidArgumentException('PRODUCT_DOES_NOT_BELONG_TO_SPECIALIST_ROLE');
        }
        foreach (self::PRODUCT_FIELDS[$type] as $field) {
            if (! array_key_exists($field, $product) || $product[$field] === null) throw new InvalidArgumentException('TYPED_PRODUCT_FIELD_MISSING:'.$field);
        }
        $time = $this->time($asOf);
        if ($time->greaterThan(now()->utc())) throw new InvalidArgumentException('PRODUCT_AS_OF_CANNOT_BE_IN_THE_FUTURE');
        if ($type === 'trade_intent') {
            if (! in_array('propose_trade', (array) ($passport['allowed_actions'] ?? []), true)
                || ! in_array(strtoupper((string) $product['symbol']), array_map('strtoupper', $passport['scope']['symbols']), true)
                || ! in_array($product['direction'], ['BUY', 'SELL'], true)
                || $this->time($product['expires_at'])->lessThanOrEqualTo($time)
                || ($product['horizon']['kind'] ?? null) !== $role
                || $product['management_owner'] !== $passport['specialist_id']
                || $product['management_version'] !== $passport['management_version']
                || ! is_numeric($product['capital_required']) || $product['capital_required'] <= 0
                || ! is_numeric($product['estimated_risk_percent']) || $product['estimated_risk_percent'] <= 0
                || $product['estimated_risk_percent'] > $passport['risk_per_trade_percent']) {
                throw new InvalidArgumentException('TRADE_INTENT_SCOPE_PERMISSION_HORIZON_OR_OWNER_INVALID');
            }
        }
        if ($type === 'position_management' && ($product['management_owner'] !== $passport['specialist_id']
            || $product['management_version'] !== $passport['management_version'])) throw new InvalidArgumentException('POSITION_MANAGEMENT_OWNER_PIN_MISMATCH');
        if ($type === 'risk_decision' && (! in_array($product['decision'], ['approve_within_limits', 'reduce', 'reject'], true)
            || ! is_numeric($product['original_size']) || ! is_numeric($product['approved_size'])
            || $product['approved_size'] < 0 || $product['approved_size'] > $product['original_size'])) {
            throw new InvalidArgumentException('RISK_PRODUCT_CANNOT_INCREASE_PROPOSED_EXPOSURE');
        }
        if ($type === 'as_of_observation' && $this->time($product['available_at'])->greaterThan($time)) throw new InvalidArgumentException('OBSERVATION_NOT_AVAILABLE_AT_PRODUCT_DECISION');
        if ($type === 'scoped_knowledge' && (empty($product['original_source_ids']) || $this->time($product['matured_at'])->greaterThan($time))) throw new InvalidArgumentException('KNOWLEDGE_FEEDBACK_NOT_MATURE');
        if ($type === 'independent_assessment' && ($product['candidate_creator_id'] === $passport['specialist_id']
            || empty($product['original_run_ids']))) throw new InvalidArgumentException('EVALUATOR_CANNOT_SELF_CERTIFY_OR_SYNTHESIZE_EVIDENCE');
        foreach (['qualified', 'active', 'promotion_evidence', 'paper_authority_granted', 'live_authority_granted'] as $claim) {
            if (($product[$claim] ?? false) === true) throw new InvalidArgumentException('TYPED_PRODUCT_IS_NOT_AN_AUTHORITY_RECEIPT');
        }
        $sealed = [...array_diff_key($product, ['product_hash' => true]), 'protocol' => 'specialist_product_v1',
            'specialist_id' => $passport['specialist_id'], 'specialist_version' => $passport['version'],
            'role' => $role, 'as_of' => $time->toIso8601String(), 'promotion_evidence' => false];
        return [...$sealed, 'product_hash' => $this->epochs->parameterHash($sealed)];
    }

    public function manifestValid(array $manifest): bool
    {
        $hash = $manifest['manifest_hash'] ?? '';
        return is_string($hash) && strlen($hash) === 64 && hash_equals($hash,
            $this->epochs->parameterHash(array_diff_key($manifest, ['manifest_hash' => true])));
    }

    public function modelHash(ModelVersion $model): string
    {
        return $this->epochs->parameterHash(['model_version_id' => $model->id,
            'parameters' => (array) $model->parameters,
            'runtime_basis' => app(LabImmutableEvidenceService::class)->modelRuntimeBasis($model),
            'native_contextual_cell' => data_get($model->metadata, 'specialist_council_membership.contextual_cell'),
            'prospective_context_owner' => data_get($model->metadata, 'causal_learning_cohort'),
            'composition_owner' => data_get($model->metadata, 'smart_composition.composition_passport'),
            'instrument_owner' => data_get($model->metadata, 'instrument_research_assignment')]);
    }

    /** An explicit frozen source port is executable authority, never an inferred ATR fallback. */
    public function liquidityAtrBindingForModel(ModelVersion $model, string $timeframe): ?string
    {
        $cell = (array) data_get($model->metadata, 'specialist_council_membership.contextual_cell', []);
        if (! array_key_exists('liquidity_atr_binding', $cell)) return null;
        $binding = $cell['liquidity_atr_binding'];
        if (! is_string($binding) || ! in_array($binding, ['closed_strategy_atr_v1', 'closed_structure_atr_v1', 'closed_m5_management_atr_v1'], true)
            || ($binding === 'closed_m5_management_atr_v1' && strtoupper($timeframe) !== 'M5')) {
            throw new InvalidArgumentException('COUNCIL_DECISION_CONTEXT_ATR_BINDING_INVALID');
        }
        return $binding;
    }

    /** The caller resolves this manifest from storage; passports never accept caller-supplied model vectors. */
    public function runtimeContract(array $manifest, string $timeframe, string $dataHash, string $executionHash, array $nativeMembers = [], ?string $symbol = null): array
    {
        if (! $this->manifestValid($manifest)) throw new InvalidArgumentException('Council manifest seal is invalid.');
        $common = null;
        foreach ($manifest['members'] as $member) {
            if (! in_array($member['role'], self::TRADING_ROLES, true)) continue;
            $scoped = array_map('strtoupper', $member['scope']['symbols']);
            $common = $common === null ? $scoped : array_values(array_intersect($common, $scoped));
        }
        $symbol = $symbol !== null && $symbol !== '' ? strtoupper($symbol) : (count($common ?? []) === 1 ? $common[0] : null);
        if ($symbol === null || ! in_array($symbol, $common ?? [], true)) throw new InvalidArgumentException('COUNCIL_SINGLE_DATASET_INSTRUMENT_SCOPE_MISMATCH');
        foreach ([$dataHash, $executionHash] as $hash) {
            if (! preg_match('/^[a-f0-9]{64}$/', $hash)) throw new InvalidArgumentException('Missing data or execution identity.');
        }
        $seconds = $this->timeframeSeconds($timeframe);
        $members = [];
        foreach ($manifest['members'] as $member) {
            if (! in_array($member['role'], self::TRADING_ROLES, true)) continue;
            $model = ModelVersion::find($member['model_version_id']);
            if (! $model || ! hash_equals($member['source_model_hash'], $this->modelHash($model))) {
                throw new InvalidArgumentException('Council member native model changed after sealing.');
            }
            $horizon = $member['horizon'];
            foreach (['decision_interval_seconds', 'reevaluation_interval_seconds', 'max_holding_seconds'] as $field) {
                if ($horizon[$field] % $seconds !== 0) throw new InvalidArgumentException('Replay resolution is insufficient for this specialist horizon.');
            }
            $runtimeMember = [
                'specialist_id' => $member['specialist_id'], 'role' => $member['role'],
                'model_version_id' => $member['model_version_id'], 'strategy' => $member['strategy'],
                'base_strategy' => data_get($model->metadata, 'base_strategy', $member['strategy']),
                'version' => $member['version'], 'parameters' => $member['parameters'],
                'strategy_version' => $member['strategy_version'], 'tactic_version' => $member['tactic_version'],
                'management_version' => $member['management_version'], 'passport_hash' => $member['passport_hash'],
                'horizon' => ['kind' => $member['role'],
                    'decision_interval_bars' => intdiv($horizon['decision_interval_seconds'], $seconds),
                    'reevaluation_interval_bars' => intdiv($horizon['reevaluation_interval_seconds'], $seconds),
                    'max_holding_bars' => intdiv($horizon['max_holding_seconds'], $seconds),
                    'decision_interval_seconds' => $horizon['decision_interval_seconds'],
                    'reevaluation_interval_seconds' => $horizon['reevaluation_interval_seconds'],
                    'max_holding_seconds' => $horizon['max_holding_seconds'],
                    'execution_precision' => $horizon['execution_precision'] ?? 'candle'],
                'capital_weight' => $member['capital_weight'], 'risk_per_trade_percent' => $member['risk_per_trade_percent'],
                'scope' => $member['scope'], 'known_limits' => $member['known_limits'],
                'data_requirements' => $member['data_requirements'], 'resources' => $member['resources'],
                'sensor_timeframes' => $member['sensor_timeframes'], 'allowed_actions' => $member['allowed_actions'],
                'execution_requirements' => ['second_scalp' => $member['role'] === 'scalp' && $horizon['decision_interval_seconds'] < 60,
                    'tick_execution' => ($horizon['execution_precision'] ?? 'candle') === 'tick'],
            ];
            $native = $nativeMembers[$member['specialist_id']] ?? null;
            $atrBinding = $this->liquidityAtrBindingForModel($model, $timeframe);
            if ($atrBinding !== null) $runtimeMember['liquidity_atr_binding'] = $atrBinding;
            if (is_array($native)) {
                if (($native['strategy'] ?? null) !== $model->strategy
                    || $this->epochs->parameterHash((array) ($native['parameters'] ?? [])) !== $this->epochs->parameterHash((array) $model->parameters)) {
                    throw new InvalidArgumentException('COUNCIL_MEMBER_NATIVE_PARAMETER_IDENTITY_MISMATCH');
                }
                if (isset($native['symbol']) && strtoupper((string) $native['symbol']) !== $symbol) {
                    throw new InvalidArgumentException('COUNCIL_MEMBER_NATIVE_INSTRUMENT_IDENTITY_MISMATCH');
                }
                if (($native['liquidity_atr_binding'] ?? null) !== $atrBinding) {
                    throw new InvalidArgumentException('COUNCIL_MEMBER_NATIVE_ATR_BINDING_IDENTITY_MISMATCH');
                }
                $runtimeMember['symbol'] = $symbol;
                $runtimeMember['base_strategy'] = $native['base_strategy'] ?? $runtimeMember['base_strategy'];
                foreach (['specialist_context_contract', 'composition_runtime_contract', 'instrument_research_assignment', 'mtf_pilot'] as $key) {
                    if (is_array($native[$key] ?? null) && $native[$key] !== []) $runtimeMember[$key] = $native[$key];
                }
            } else {
                foreach (['specialist_context_contract', 'composition_runtime_contract', 'instrument_research_assignment', 'mtf_pilot'] as $key) {
                    $contract = data_get($model->metadata, $key);
                    if (is_array($contract) && $contract !== []) $runtimeMember[$key] = $contract;
                }
                if (! isset($runtimeMember['composition_runtime_contract'])
                    && filled(data_get($model->metadata, 'smart_composition.composition_passport.composition_id'))) {
                    throw new InvalidArgumentException('COUNCIL_MEMBER_COMPOSITION_REQUIRES_NATIVE_COMPILATION');
                }
            }
            if (isset($member['operator_contract'])) $runtimeMember['operator_contract'] = $member['operator_contract'];
            $members[] = $runtimeMember;
        }
        if ($members === []) throw new InvalidArgumentException('Council contains no executable trading specialist.');
        $contract = ['protocol' => self::RUNTIME_PROTOCOL, 'council_id' => $manifest['council_id'],
            'council_version' => $manifest['version'], 'manifest_hash' => $manifest['manifest_hash'],
            'symbol' => $symbol,
            'execution_timeframe' => strtoupper($timeframe), 'replay_dataset_hash' => $dataHash,
            'execution_hash' => $executionHash, 'members' => $members,
            'policy' => array_diff_key($manifest['execution'], array_flip(['id', 'version'])),
            'component_identities' => array_map(fn (array $component): array => ['id' => $component['id'],
                'version' => $component['version'], 'contract_hash' => $component['contract_hash']], $manifest['components']),
            'routing_identity' => $manifest['routing'], 'allocation_identity' => $manifest['allocation'],
            'risk_identity' => $manifest['risk'], 'execution_identity' => ['id' => $manifest['execution']['id'], 'version' => $manifest['execution']['version']],
            'upgrades' => [], 'promotion_evidence' => false];
        return [...$contract, 'contract_hash' => $this->epochs->parameterHash($contract)];
    }

    /** Explicit prospective comparator scope; neither mode certifies the best eligible standalone. */
    public function sealNativeSoloComparison(array $manifest, array $plan): ?array
    {
        if (! array_key_exists('solo_comparison', $plan)) return null;
        $declared = $plan['solo_comparison'];
        $fullChosen = is_array($declared) && ($declared['protocol'] ?? null) === self::NATIVE_CHOSEN_SOLO_PROTOCOL;
        if (! is_array($declared)
            || (! $fullChosen && ($declared['protocol'] ?? null) !== self::NATIVE_SOLO_PROTOCOL)
            || ($declared['comparison_kind'] ?? null) !== ($fullChosen ? 'chosen_source_full_account_allocation' : 'matched_member_allocation')
            || ($fullChosen && (($declared['selection_status'] ?? null) !== 'chosen_source_unqualified'
                || ($declared['selection_timing'] ?? null) !== 'preregistered_before_outcomes'))
            || ($declared['best_solo_full_budget_proven'] ?? null) !== false
            || ($plan['purpose'] ?? null) !== 'research' || isset($plan['panel_reservation_hash']) || isset($plan['descendant_programs'])) {
            throw new InvalidArgumentException($fullChosen
                ? 'COUNCIL_NATIVE_SOLO_FULL_ACCOUNT_REQUIRES_EXPLICIT_ORIGINAL_CHOICE_SCOPE'
                : 'COUNCIL_NATIVE_SOLO_REQUIRES_EXPLICIT_MATCHED_ALLOCATION_SCOPE');
        }
        $members = array_values(array_filter($manifest['members'], fn (array $member): bool =>
            in_array($member['role'], self::TRADING_ROLES, true)
            && $member['specialist_id'] === ($declared['specialist_id'] ?? null)
            && (int) $member['model_version_id'] === (int) $manifest['evaluation_policy']['solo_model_version_id']));
        if (count($members) !== 1) throw new InvalidArgumentException('COUNCIL_NATIVE_SOLO_EXACT_MEMBER_REQUIRED');
        $member = $members[0];
        $soloArms = array_filter((array) ($plan['arms'] ?? []), fn (array $arm): bool => ($arm['kind'] ?? null) === 'solo');
        if ($soloArms === [] || collect($soloArms)->contains(fn (array $arm): bool =>
            (int) ($arm['model_version_id'] ?? 0) !== (int) $member['model_version_id'])) {
            throw new InvalidArgumentException('COUNCIL_NATIVE_SOLO_ORIGINAL_ARM_MEMBER_MISMATCH');
        }
        $sealed = ['protocol' => $fullChosen ? self::NATIVE_CHOSEN_SOLO_PROTOCOL : self::NATIVE_SOLO_PROTOCOL,
            'comparison_kind' => $fullChosen ? 'chosen_source_full_account_allocation' : 'matched_member_allocation',
            'specialist_id' => $member['specialist_id'], 'model_version_id' => (int) $member['model_version_id'],
            'source_model_hash' => $member['source_model_hash'], 'passport_hash' => $member['passport_hash'],
            'capital_weight' => $fullChosen ? 1.0 : $member['capital_weight'], 'risk_per_trade_percent' => $member['risk_per_trade_percent'],
            'initial_account_capital_equal' => true, 'member_allocation_unchanged' => ! $fullChosen,
            'best_solo_full_budget_proven' => false];
        if ($fullChosen) $sealed = [...$sealed, 'source_capital_weight' => $member['capital_weight'],
            'programme_unchanged_except_capital_weight' => true, 'selection_status' => 'chosen_source_unqualified',
            'selection_timing' => 'preregistered_before_outcomes', 'promotion_evidence' => false];
        foreach ($declared as $key => $value) {
            if (! array_key_exists($key, $sealed)
                || ! app(LabImmutableEvidenceService::class)->equivalentJsonValue($value, $sealed[$key])) {
                throw new InvalidArgumentException('COUNCIL_NATIVE_SOLO_DECLARATION_IDENTITY_MISMATCH:'.$key);
            }
        }
        return $sealed;
    }

    public function assertNativeSoloComparison(array $manifest, array $plan): ?array
    {
        $expected = $this->sealNativeSoloComparison($manifest, $plan);
        if ($expected !== null && ! app(LabImmutableEvidenceService::class)->equivalentJsonValue($expected, $plan['solo_comparison'])) {
            throw new InvalidArgumentException('COUNCIL_NATIVE_SOLO_ORIGINAL_DECLARATION_INCOMPLETE');
        }
        return $expected;
    }

    public function timeframeSeconds(string $timeframe): int
    {
        if (! preg_match('/^(M|H|D)([1-9][0-9]*)$/i', $timeframe, $match)) throw new InvalidArgumentException('Unsupported council execution timeframe.');
        return (int) $match[2] * match (strtoupper($match[1])) { 'M' => 60, 'H' => 3600, 'D' => 86400 };
    }

    private function sealComponent(array $component): array
    {
        $this->identity($component['id'] ?? null, 'component id');
        $this->identity((string) ($component['version'] ?? ''), 'component version');
        if (! in_array($component['role'] ?? null, ['strategy', 'tactic', 'risk', 'toolbox', 'learning', 'evolution', 'capital', 'execution', 'data'], true)
            || empty($component['input_type']) || empty($component['output_type']) || empty($component['consumer_roles'])
            || array_diff((array) $component['consumer_roles'], array_keys(self::PRODUCTS)) !== []
            || ($component['as_of_only'] ?? null) !== true
            || ! is_numeric($component['max_compute_ms'] ?? null) || $component['max_compute_ms'] <= 0 || $component['max_compute_ms'] > 60000) {
            throw new InvalidArgumentException('Component requires typed inputs/outputs, compatible consumers, as-of policy and bounded computation.');
        }
        if (array_intersect((array) ($component['permissions'] ?? []), ['self_certify', 'edit_evaluator', 'edit_external_risk_limits', 'use_2026_for_research']) !== []) {
            throw new InvalidArgumentException('A component may not alter its evidence or external risk authority.');
        }
        $sealed = [...array_diff_key($component, array_flip(['contract_hash', 'promotion_evidence'])), 'promotion_evidence' => false];
        return [...$sealed, 'contract_hash' => $this->epochs->parameterHash($sealed)];
    }

    private function validateExecutionPolicy(array $policy): void
    {
        if (! in_array($policy['broker_position_mode'] ?? null, ['hedging', 'netting'], true)
            || ! in_array($policy['opposite_position_policy'] ?? null, ['reject', 'hedge'], true)
            || (($policy['broker_position_mode'] ?? null) === 'netting' && ($policy['opposite_position_policy'] ?? null) === 'hedge')) {
            throw new InvalidArgumentException('Unsupported broker or opposing-position policy.');
        }
        foreach (['max_open_positions' => 32, 'max_reserved_capital_percent' => 100, 'max_gross_exposure_percent' => 100,
            'max_total_risk_percent' => 5, 'max_drawdown_percent' => 20, 'max_daily_loss_percent' => 5, 'max_expected_cost_percent' => 5] as $key => $ceiling) {
            if (! is_numeric($policy[$key] ?? null) || $policy[$key] <= 0 || $policy[$key] > $ceiling) {
                throw new InvalidArgumentException('Council execution policy exceeds the external research envelope.');
            }
        }
    }

    private function identity(mixed $value, string $field): void
    {
        if (! is_string($value) || ! preg_match('/^[A-Za-z0-9_.:-]{1,150}$/', $value)) throw new InvalidArgumentException("Invalid {$field} identity.");
    }

    private function time(mixed $value): CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $value)) {
            throw new InvalidArgumentException('A passport requires an explicit UTC-offset as-of timestamp.');
        }
        return CarbonImmutable::parse($value)->utc();
    }
}
