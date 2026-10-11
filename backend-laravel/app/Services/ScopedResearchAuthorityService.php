<?php

namespace App\Services;

use App\Models\AgentLearningCausalExperiment;
use App\Models\CausalFoldReceipt;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\SpecialistCouncilVersion;
use Carbon\CarbonImmutable;
use LogicException;

/** Scope-specific issuer. Its input contains original IDs, never success flags. */
class ScopedResearchAuthorityService
{
    public const PROTOCOL = 'original_scoped_independent_assessment_v1';

    public function assess(array $registration, array $products): array
    {
        try {
            $design = $registration['design'];
            if (($design['authority_policy'] ?? null) !== ScopedResearchCertificateService::AUTHORITY_POLICY) {
                throw new LogicException('DIAGNOSTIC_QUESTION_HAS_NO_INDEPENDENT_ISSUER_POLICY');
            }
            $id = (int) $registration['certificate_id'];
            $scope = $registration['scope'];
            if ($scope === 'selector') {
                return $this->selector($registration);
            }
            if ($scope === 'council') {
                return $this->council($registration);
            }
            if (! in_array($scope, ['component', 'inheritance'], true)) {
                throw new LogicException('UNKNOWN_INDEPENDENT_AUTHORITY_SCOPE');
            }
            $ready = app(ScopedResearchCertificateService::class)->independentWindowReadiness($id);
            if (! $ready['ready']) {
                throw new LogicException($ready['reason_codes'][0] ?? 'ORIGINAL_WINDOW_ROSTER_NOT_READY');
            }
            if (! array_is_list($products) || count($products) !== count($ready['windows'])) {
                throw new LogicException('ALL_PREREGISTERED_WINDOW_PRODUCTS_REQUIRED');
            }
            $windows = collect($ready['windows'])->keyBy(fn ($row) => $row['window']['window_key']);
            $seen = [];
            $physicalHashes = [];
            $originalRuns = [];
            $effects = [];
            $windowProofs = [];
            $refusals = [];
            $underpowered = [];
            $rule = (array) ($design['stopping_rule'] ?? []);
            $minimumTrades = max(3, (int) config('services.learning_lane.causal_minimum_trades_per_window', 8),
                (int) ($rule['minimum_trades_per_arm'] ?? 0));
            $minimumEffect = $rule['minimum_effect'] ?? null;
            if (! is_numeric($minimumEffect) || ! is_finite((float) $minimumEffect) || $minimumEffect <= 0) {
                throw new LogicException('PREREGISTERED_MINIMUM_EFFECT_REQUIRED');
            }
            $context = app(ContextContractV2Service::class)->project((array) data_get($design, 'subject.context', []));
            foreach (['regime', 'volatility', 'session', 'venue_phase', 'direction'] as $axis) {
                if (empty($context['extended_axes'][$axis])) {
                    throw new LogicException('EXACT_COMPONENT_CONTEXT_REQUIRED');
                }
            }
            if ($context['identity_hash'] !== $design['context_hash']) {
                throw new LogicException('EXACT_COMPONENT_CONTEXT_HASH_MISMATCH');
            }
            $subjects = $this->subjects($registration);
            foreach ($products as $group) {
                if (! is_array($group) || array_diff(array_keys($group), ['window_key', 'run_ids']) !== []
                    || ! is_string($group['window_key'] ?? null) || isset($seen[$group['window_key']])
                    || ! ($window = $windows->get($group['window_key']))) {
                    throw new LogicException('ORIGINAL_WINDOW_PRODUCTS_NOT_PREREGISTERED');
                }
                $seen[$group['window_key']] = true;
                $hash = $window['window']['dataset_sha256'];
                if (isset($physicalHashes[$hash])) {
                    throw new LogicException('REUSED_PHYSICAL_DATA_IS_NOT_REPLICATION');
                }
                $physicalHashes[$hash] = true;
                $runIds = $group['run_ids'] ?? [];
                if (! is_array($runIds) || array_diff(array_keys($subjects), array_keys($runIds)) !== []
                    || count($runIds) !== count($subjects)) {
                    throw new LogicException('COMPLETE_ORIGINAL_ARM_ROSTER_REQUIRED');
                }
                $payloads = [];
                $slices = [];
                $witnesses = [];
                $windowPowered = true;
                foreach ($subjects as $arm => $subject) {
                    $runId = $runIds[$arm] ?? null;
                    if (! is_int($runId) || $runId <= 0 || isset($originalRuns[$runId])) {
                        throw new LogicException('DISTINCT_ORIGINAL_RUN_IDS_REQUIRED');
                    }
                    $originalRuns[$runId] = true;
                    $original = $this->original($registration, $runId, $subject, $window);
                    if (! $original['safety']['hard_risk_passed']) {
                        $refusals[] = ['reason_code' => 'ORIGINAL_HARD_RISK_LIMIT_FAILED',
                            'window_key' => $group['window_key'], 'arm' => $arm, 'safety' => $original['safety']];
                    }
                    $payloads[$arm] = $original['result'];
                    $slices[$arm] = $this->slice($original['result'], $context['extended_axes'], $minimumTrades);
                    if ($slices[$arm]['trades'] < $minimumTrades) {
                        $windowPowered = false;
                        $underpowered[] = ['window_key' => $group['window_key'], 'arm' => $arm,
                            'trades' => $slices[$arm]['trades'], 'required_trades' => $minimumTrades];
                    }
                    $witnesses[$arm] = $original['witness'];
                }
                $pair = $scope === 'component' ? ['candidate', 'control'] : ['P+T+U', 'P+U'];
                $target = $scope === 'component' ? $subjects['candidate']['target'] : (string) $design['metric'];
                if (! $windowPowered) {
                    // Zero/insufficient original contextual outcomes are a
                    // measured power refusal, not missing statistical metrics
                    // invented into a strategy failure or a new replay debt.
                    $windowProofs[] = ['window' => $window['window'], 'classification' => 'underpowered',
                        'original_arm_witnesses' => $witnesses,
                        'exposure_proof_hash' => $window['original_readiness']['readiness_hash']];

                    continue;
                }
                $comparison = app(CausalLearningConfirmationService::class)->compareOriginalComponent(
                    match ($target) {
                        'total_return_percent' => 'net_profit', 'architecture' => 'profit_factor',
                        'max_drawdown' => 'drawdown', default => $target
                    }, $payloads[$pair[0]], $payloads[$pair[1]]);
                if (($comparison['non_target']['status'] ?? null) === 'incomplete') {
                    throw new LogicException('ORIGINAL_NON_TARGET_EVIDENCE_INCOMPLETE');
                }
                if (($comparison['non_target']['safe'] ?? false) !== true) {
                    $refusals[] = ['reason_code' => 'unsafe_or_incomplete_non_target',
                        'window_key' => $group['window_key'], 'comparison' => $comparison];
                }
                $metric = $this->metric($target);
                $delta = $metric['direction'] * ($this->number($slices[$pair[0]], $metric['path'])
                    - $this->number($slices[$pair[1]], $metric['path']));
                if ($scope === 'inheritance') {
                    $topology = app(DescendantScopedProofService::class)->effects(array_map(
                        fn ($slice) => $metric['direction'] * $this->number($slice, $metric['path']), $slices), (float) $minimumEffect);
                    if (($topology['trait_retained'] ?? false) !== true) {
                        $refusals[] = ['reason_code' => 'bundle_only_or_trait_not_retained',
                            'window_key' => $group['window_key'], 'effects' => $topology];
                    }
                }
                $effects[] = $delta;
                $windowProofs[] = ['window' => $window['window'], 'delta' => $delta,
                    'comparison' => $comparison, 'original_arm_witnesses' => $witnesses,
                    'exposure_proof_hash' => $window['original_readiness']['readiness_hash']];
            }
            if ($refusals !== []) {
                return $this->terminal($registration, false, $refusals[0]['reason_code'],
                    [...$refusals[0], 'refusals' => $refusals, 'original_windows' => $windowProofs]);
            }
            if ($underpowered !== []) {
                return $this->terminal($registration, false, 'original_context_windows_underpowered',
                    ['underpowered' => $underpowered, 'original_windows' => $windowProofs]);
            }
            $stat = $this->bootstrap($effects, $design['statistical_guard'] ?? []);
            $positive = count(array_filter($effects, fn ($delta) => $delta >= $minimumEffect));
            $positiveRequired = max(2, (int) config('services.learning_lane.causal_minimum_positive_windows', 4),
                (int) ($rule['minimum_positive_windows'] ?? 0));
            $passed = $positive >= $positiveRequired && $stat['mean'] >= $minimumEffect && $stat['lower_bound'] > 0;

            return $this->terminal($registration, $passed, $passed ? 'independently_replicated' : 'negative_or_inconclusive', [
                'trait_delta' => $scope === 'component' ? $subjects['candidate']['trait_delta'] : data_get($design, 'subject.trait_delta'),
                'candidate_agent_id' => $subjects['candidate']['agent_id'] ?? null,
                'candidate_model_version_id' => $subjects['candidate']['model_id'] ?? null,
                'control_model_version_id' => $subjects['control']['model_id'] ?? null,
                'child_model_version_id' => $subjects['P+T+U']['model_id'] ?? null,
                'context' => $context['extended_axes'], 'context_hash' => $context['identity_hash'],
                'original_windows' => $windowProofs, 'positive_windows' => $positive,
                'statistics' => $stat, 'selector_superiority_required' => false,
            ]);
        } catch (\Throwable $error) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked_dependency', 'confirmed' => false,
                'reason_code' => $error instanceof LogicException ? $error->getMessage() : 'ORIGINAL_SCOPED_PRODUCER_UNAVAILABLE',
                'paper_or_live_authority' => false, 'promotion_evidence' => false];
        }
    }

    private function subjects(array $registration): array
    {
        $design = $registration['design'];
        if ($registration['scope'] === 'inheritance') {
            $sourceCertificateId = (int) data_get($design, 'subject.source_component_certificate_id');
            if ($sourceCertificateId === (int) $registration['certificate_id'] || $sourceCertificateId <= 0) {
                throw new LogicException('ORIGINAL_SOURCE_COMPONENT_CERTIFICATE_REQUIRED');
            }
            $source = app(ScopedResearchCertificateService::class)->inspect($sourceCertificateId);
            if (data_get($source, 'original_authority.component.confirmed') !== true
                || data_get($source, 'original_authority.component.context_hash') !== $design['context_hash']) {
                throw new LogicException('ORIGINAL_SOURCE_COMPONENT_AUTHORITY_UNVERIFIED');
            }
            $models = (array) data_get($design, 'subject.arm_models');
            $vectors = (array) data_get($design, 'subject.arm_parameters');
            $topology = app(DescendantScopedProofService::class)->topology($vectors,
                (string) data_get($source, 'original_authority.component.trait_delta.gene'));
            if (($topology['status'] ?? null) !== 'topology_valid'
                || app(ResearchPaperEpochContractService::class)->parameterHash((array) $topology['trait_delta'])
                    !== app(ResearchPaperEpochContractService::class)->parameterHash((array) data_get($source, 'original_authority.component.trait_delta'))) {
                throw new LogicException('ORIGINAL_SOURCE_TRAIT_TOPOLOGY_MISMATCH');
            }
            $subjects = [];
            foreach (DescendantScopedProofService::ARMS as $arm) {
                $subjects[$arm] = ['model_id' => (int) ($models[$arm]['model_version_id'] ?? 0)];
            }

            return $subjects;
        }
        $experiment = AgentLearningCausalExperiment::findOrFail($registration['source_id']);
        $role = data_get($design, 'subject.candidate_role');
        $agentId = match ($role) {
            'guided' => $experiment->guided_agent_id, 'blinded' => $experiment->blinded_agent_id,
            default => throw new LogicException('PREREGISTERED_COMPONENT_ROLE_REQUIRED')
        };
        $candidate = LabAgent::with('modelVersion')->findOrFail($agentId);
        $control = LabAgent::with('modelVersion')->findOrFail($experiment->control_agent_id);
        if (! app(ExactCausalBaselineService::class)->matches($candidate, $control)) {
            throw new LogicException('EXACT_COMPONENT_BASELINE_REQUIRED');
        }
        $diff = (array) $candidate->parameter_diff;
        $gene = array_key_first($diff);
        $delta = ['gene' => $gene, 'old' => $diff[$gene]['old'], 'new' => $diff[$gene]['new']];
        if (app(ResearchPaperEpochContractService::class)->parameterHash($delta)
            !== app(ResearchPaperEpochContractService::class)->parameterHash((array) data_get($design, 'subject.trait_delta'))) {
            throw new LogicException('EXACT_COMPONENT_TRAIT_DELTA_REQUIRED');
        }

        return ['candidate' => ['model_id' => (int) $candidate->model_version_id, 'agent_id' => (int) $candidate->id,
            // The new question's utility was sealed before validation; an old
            // repair target is lineage, not a different implicit success gate.
            'target' => $design['metric'], 'trait_delta' => $delta],
            'control' => ['model_id' => (int) $control->model_version_id, 'agent_id' => (int) $control->id]];
    }

    private function original(array $registration, int $runId, array $subject, array $window): array
    {
        $run = LabEvaluationRun::findOrFail($runId);
        $immutable = app(LabImmutableEvidenceService::class);
        if ($run->status !== 'completed' || (int) $run->model_version_id !== $subject['model_id']
            || ! $run->started_at || $run->started_at->lessThanOrEqualTo(CarbonImmutable::parse($registration['preregistered_at']))
            || $run->data_hash !== $window['window']['dataset_sha256']
            || $run->code_hash !== $registration['design']['evaluator_hash']
            || ($immutable->learningEligibility($run)['complete'] ?? false) !== true
            || ! $immutable->verifiedModelRuntimeIdentity($run)) {
            throw new LogicException('COMPLETE_MATCHED_ORIGINAL_PRODUCT_REQUIRED');
        }
        $request = $immutable->latestArtifactPayload($run, 'evaluation_request');
        $response = $immutable->latestArtifactPayload($run, 'evaluation_response');
        $strategy = array_values((array) ($request['strategies'] ?? []));
        $expectedManifest = isset($registration['design']['native_execution'])
            ? app(InstrumentResearchWindowService::class)->canonicalScopedManifest($window, $window['manifest'])
            : $window['manifest'];
        if (count($strategy) !== 1 || (int) ($strategy[0]['lab_agent_id'] ?? 0) !== (int) $run->lab_agent_id
            || data_get($request, 'execution_contract.execution_hash', data_get($request, 'execution_hash'))
                !== $registration['design']['execution_hash']
            || ($request['replay_dataset_hash'] ?? null) !== $run->data_hash
            || app(ResearchPaperEpochContractService::class)->parameterHash((array) ($request['mtf_snapshot_manifest'] ?? []))
                !== app(ResearchPaperEpochContractService::class)->parameterHash($expectedManifest)) {
            throw new LogicException('ORIGINAL_REQUEST_EXECUTION_DATA_PARITY_REQUIRED');
        }
        if (isset($registration['design']['native_execution'])) {
            $holding = (int) data_get($registration, 'design.exposure_policy.holding_fence_seconds');
            $end = CarbonImmutable::parse($window['window']['end_exclusive'])->utc();
            $fence = ['protocol' => 'scoped_original_maturity_fence_v1',
                'entry_end_exclusive' => $end->subSeconds($holding)->toIso8601String(),
                'end_exclusive' => $end->toIso8601String(), 'holding_fence_seconds' => $holding];
            if (app(ResearchPaperEpochContractService::class)->parameterHash((array) data_get($request, 'policy_context.scoped_position_maturity_fence'))
                !== app(ResearchPaperEpochContractService::class)->parameterHash($fence)) {
                throw new LogicException('ORIGINAL_PREREGISTERED_MATURITY_FENCE_REQUIRED');
            }
        }
        if (isset($response['leaderboard'])) {
            $matches = array_values(array_filter((array) $response['leaderboard'], fn ($item) => is_array($item) && (int) ($item['lab_agent_id'] ?? 0) === (int) $run->lab_agent_id));
            if (count($matches) !== 1) {
                throw new LogicException('ORIGINAL_RESPONSE_ARM_IDENTITY_REQUIRED');
            }
            $response = $matches[0];
        }
        if (! is_array($response)) {
            throw new LogicException('ORIGINAL_RESPONSE_BYTES_REQUIRED');
        }
        $safety = $this->verifyOriginalSafety($response, $registration, $window['window']);

        return ['result' => $response, 'safety' => $safety, 'witness' => ['run_id' => (int) $run->id, 'request_hash' => $run->request_hash,
            'response_hash' => $run->response_hash, 'model_version_id' => (int) $run->model_version_id,
            'data_hash' => $run->data_hash, 'code_hash' => $run->code_hash]];
    }

    /** Actual original account/trades, not absent-censor or caller safety flags. */
    public function verifyOriginalSafety(array $response, array $registration, array $window): array
    {
        $drawdown = $this->number($response, 'max_drawdown_percent');
        $ruin = $this->number($response, 'monte_carlo.risk_of_ruin_percent');
        if ($drawdown < 0 || $ruin < 0 || $ruin > 100) {
            throw new LogicException('ORIGINAL_RISK_MEASUREMENT_DOMAIN_INVALID');
        }
        $risk = (array) ($registration['design']['risk_guard'] ?? []);
        $maxDrawdown = min(15.0, (float) config('services.dual_track.max_drawdown_percent', 15),
            (float) ($risk['max_drawdown_percent'] ?? 15));
        $maxRuin = min(10.0, (float) config('services.dual_track.max_risk_of_ruin_percent', 10),
            (float) ($risk['max_risk_of_ruin_percent'] ?? 10));
        $censor = data_get($response, 'statistical_evidence.censored_trade_count', data_get($response, 'censored_trade_count'));
        if ($censor !== null && (! is_numeric($censor) || (int) $censor !== 0)) {
            throw new LogicException('ORIGINAL_POSITION_OUTCOMES_NOT_MATURE');
        }
        $ledger = $response['trade_ledger'] ?? null;
        if (! is_array($ledger) || ! array_is_list($ledger) || count($ledger) !== (int) ($response['total_trades'] ?? -1)) {
            throw new LogicException('ORIGINAL_MATURE_TRADE_LEDGER_REQUIRED');
        }
        $maturity = data_get($response, 'statistical_evidence.original_position_maturity');
        if (! is_array($maturity) || ($maturity['protocol'] ?? null) !== 'original_position_maturity_v1'
            || ($maturity['closed_trade_count'] ?? null) !== count($ledger)
            || ($maturity['open_position_count'] ?? null) !== 0
            || ($maturity['censored_trade_count'] ?? null) !== 0
            || ($maturity['unknown_maturity_count'] ?? null) !== 0
            || ($maturity['forced_terminal_close_applied'] ?? null) !== false) {
            throw new LogicException('ORIGINAL_POSITION_STATE_MATURITY_REQUIRED');
        }
        $start = CarbonImmutable::parse($window['start_inclusive'])->utc();
        $end = CarbonImmutable::parse($window['end_exclusive'])->utc();
        foreach ($ledger as $trade) {
            if (! is_array($trade) || ! is_string($trade['entry_time'] ?? null) || ! is_string($trade['exit_time'] ?? null)) {
                throw new LogicException('ORIGINAL_POSITION_OUTCOMES_NOT_MATURE');
            }
            $entry = CarbonImmutable::parse($trade['entry_time'])->utc();
            $exit = CarbonImmutable::parse($trade['exit_time'])->utc();
            $reason = strtolower((string) ($trade['exit_reason'] ?? ''));
            if ($entry->lt($start) || $exit->lt($entry) || $exit->gte($end)
                || preg_match('/(?:end_of_(?:replay|data)|forced_(?:close|end)|censored|unrealized)/', $reason)) {
                throw new LogicException('ORIGINAL_POSITION_OUTCOMES_NOT_MATURE');
            }
        }

        return ['drawdown' => $drawdown, 'risk_of_ruin' => $ruin, 'maximum_drawdown' => $maxDrawdown,
            'maximum_risk_of_ruin' => $maxRuin, 'hard_risk_passed' => $drawdown <= $maxDrawdown && $ruin <= $maxRuin,
            'mature_original_trades' => count($ledger)];
    }

    private function slice(array $result, array $context, int $minimum): array
    {
        $trace = (array) ($result['instrument_research_trace'] ?? []);
        if (array_key_exists('scoped_research_context_trace', $result)) {
            $trace = $result['scoped_research_context_trace'];
            if (! is_array($trace) || ($trace['protocol'] ?? null) !== 'scoped_original_trade_context_v1'
                || ! is_string($trace['trade_ledger_hash'] ?? null)
                || ! preg_match('/^[a-f0-9]{64}$/', $trace['trade_ledger_hash'])
                || ($trace['trade_ledger_hash'] ?? null) !== ($result['trade_ledger_hash'] ?? null)
                || ! is_array($result['trade_ledger'] ?? null) || ! array_is_list($result['trade_ledger'])
                || ($trace['trade_ledger_count'] ?? null) !== count($result['trade_ledger'])
                || ($trace['instrument_assignment_or_activation_claimed'] ?? null) !== false
                || ($trace['promotion_evidence'] ?? null) !== false) {
                throw new LogicException('ORIGINAL_SCOPED_TRADE_CONTEXT_BINDING_REQUIRED');
            }
        }
        if (($trace['context_source'] ?? null) !== 'decision_time_trade_ledger'
            || ($trace['context_slice_protocol'] ?? null) !== 'venue_phase_v1'
            || ! is_array($trace['exact_context_slices'] ?? null)) {
            throw new LogicException('ORIGINAL_EXACT_CONTEXT_LEDGER_REQUIRED');
        }
        $matches = [];
        foreach ((array) ($trace['exact_context_slices'] ?? []) as $slice) {
            $axes = app(ContextContractV2Service::class)->canonicalAxes((array) ($slice['context'] ?? []));
            $same = true;
            foreach (['regime', 'volatility', 'session', 'venue_phase', 'direction'] as $axis) {
                $same = $same && ($axes[$axis] ?? null) === ($context[$axis] ?? null);
            }
            if ($same) {
                $metrics = (array) ($slice['metrics'] ?? []);
                // This is the original Python exact-ledger schema, not an
                // aggregate PF substitution for missing contextual evidence.
                if (! array_key_exists('profit_factor', $metrics) && array_key_exists('net_pf', $metrics)) {
                    $metrics['profit_factor'] = $metrics['net_pf'];
                }
                $matches[] = $metrics;
            }
        }
        if ($matches === []) {
            return ['trades' => 0, 'context_reached' => false];
        }
        if (count($matches) !== 1 || ! is_numeric($matches[0]['trades'] ?? null) || $matches[0]['trades'] < 0) {
            throw new LogicException('ORIGINAL_EXACT_CONTEXT_LEDGER_INVALID');
        }

        return $matches[0];
    }

    private function selector(array $registration): array
    {
        $panel = app(ScopedSelectorPanelService::class)->assessOriginalPanel((int) $registration['certificate_id']);
        if (($panel['valid'] ?? false) !== true || ($panel['terminal'] ?? false) !== true) {
            throw new LogicException($panel['reason_code'] ?? 'ORIGINAL_SELECTOR_PANEL_REQUIRED');
        }
        $groups = [];
        foreach ((array) ($panel['original_products'] ?? []) as $product) {
            $runIds = [];
            foreach (['guided', 'blinded', 'control'] as $arm) {
                $recordId = $product[$arm.'_run_record_id'] ?? null;
                if (! is_int($recordId) || $recordId <= 0) {
                    throw new LogicException('ORIGINAL_SELECTOR_RUN_RECORD_ID_REQUIRED');
                }
                $original = LabEvaluationRun::findOrFail($recordId);
                if ($original->run_id !== ($product[$arm.'_run_id'] ?? null)) {
                    throw new LogicException('ORIGINAL_SELECTOR_RUN_UUID_RECORD_MISMATCH');
                }
                $runIds[] = $recordId;
            }
            $run = LabEvaluationRun::findOrFail($runIds[0]);
            $request = app(LabImmutableEvidenceService::class)->latestArtifactPayload($run, 'evaluation_request');
            $manifest = (array) ($request['mtf_snapshot_manifest'] ?? []);
            $window = data_get($request, 'policy_context.scoped_research_certificate') !== null
                ? $this->selectorWindowFromOriginalFolds($registration, $product, $runIds, $request)
                : app(InstrumentResearchWindowService::class)->sealForDataset((string) $run->data_hash,
                    (array) data_get(app(LabImmutableEvidenceService::class)->latestArtifactPayload($run), 'replay_manifest', []));
            if (! $window) {
                throw new LogicException('ORIGINAL_SELECTOR_PANEL_WINDOW_REQUIRED');
            }
            $key = $window['window_key'];
            $groups[$key] ??= ['window' => $window, 'manifest' => $manifest, 'run_ids' => [], 'fold_ids' => []];
            $groups[$key]['run_ids'] = [...$groups[$key]['run_ids'], ...$runIds];
            $groups[$key]['fold_ids'] = [...$groups[$key]['fold_ids'], ...(array) ($product['fold_receipt_ids'] ?? [])];
        }
        $periodKey = fn (array $period): string => CarbonImmutable::parse($period['start_inclusive'])->utc()->toIso8601String()
            .'|'.CarbonImmutable::parse($period['end_exclusive'])->utc()->toIso8601String();
        $expected = array_map($periodKey, (array) ($registration['design']['validation_windows'] ?? []));
        $actual = array_map(fn ($group) => $periodKey($group['window']), array_values($groups));
        sort($expected);
        sort($actual);
        if ($expected === [] || $expected !== $actual) {
            throw new LogicException('SELECTOR_ALL_PREREGISTERED_PHYSICAL_WINDOWS_REQUIRED');
        }
        foreach ($groups as $group) {
            $proof = app(ResearchWindowExposureInventoryService::class)->assessForCertificate(
                (int) $registration['certificate_id'], $group['window'], $group['manifest'],
                array_values(array_unique($group['run_ids'])), array_values(array_unique($group['fold_ids'])));
            if (($proof['ready'] ?? false) !== true) {
                throw new LogicException('ORIGINAL_SELECTOR_PANEL_EXPOSURE_REQUIRED');
            }
        }

        return $this->terminal($registration, $panel['verdict'] === 'positive', $panel['verdict'], ['original_selector_panel' => $panel]);
    }

    /** A native fold aggregate is not a replay-manifest producer. Reopen its sealed original ingress instead. */
    private function selectorWindowFromOriginalFolds(array $registration, array $product, array $runIds, array $request): array
    {
        $epochs = app(ResearchPaperEpochContractService::class);
        $windows = app(InstrumentResearchWindowService::class);
        $declaration = (array) data_get($request, 'policy_context.scoped_research_certificate', []);
        $assertDeclaration = function (array $original) use ($registration, $declaration, $epochs): void {
            $scope = (array) data_get($original, 'policy_context.scoped_research_certificate', []);
            if (($scope['protocol'] ?? null) !== ScopedResearchCertificateService::AUTHORITY_POLICY
                || ($scope['purpose'] ?? null) !== 'independent_scoped_selector_research'
                || ($scope['certificate_id'] ?? null) !== (int) $registration['certificate_id']
                || ($scope['design_hash'] ?? null) !== $registration['design_hash']
                || ($scope['selector_panel_key'] ?? null) !== data_get($registration, 'design.subject.selector_panel_key')
                || ($scope['promotion_evidence'] ?? null) !== false
                || ($original['evaluation_mode'] ?? null) !== 'full'
                || $epochs->parameterHash($scope) !== $epochs->parameterHash($declaration)) {
                throw new LogicException('ORIGINAL_SELECTOR_CAPTURED_SCOPE_MISMATCH');
            }
        };
        $assertDeclaration($request);
        $manifest = (array) ($request['mtf_snapshot_manifest'] ?? []);
        $dataHash = $request['replay_dataset_hash'] ?? null;
        $foldIds = $product['fold_receipt_ids'] ?? null;
        if (! is_string($dataHash) || ($manifest['bundle_hash'] ?? null) !== $dataHash
            || ! is_array($foldIds) || ! array_is_list($foldIds) || $foldIds === []
            || count(array_unique($foldIds)) !== count($foldIds)) {
            throw new LogicException('ORIGINAL_SELECTOR_CAPTURED_FOLD_ROSTER_REQUIRED');
        }
        $agents = [];
        foreach ($runIds as $runId) {
            $original = LabEvaluationRun::findOrFail($runId);
            $publishedRequest = app(LabImmutableEvidenceService::class)->latestArtifactPayload($original, 'evaluation_request');
            if (! is_array($publishedRequest) || $original->data_hash !== $dataHash
                || ($publishedRequest['replay_dataset_hash'] ?? null) !== $dataHash
                || $epochs->parameterHash((array) ($publishedRequest['mtf_snapshot_manifest'] ?? [])) !== $epochs->parameterHash($manifest)
                || data_get($publishedRequest, 'policy_context.causal_fold_aggregate.experiment_id') !== ($product['experiment_id'] ?? null)) {
                throw new LogicException('ORIGINAL_SELECTOR_PUBLISHED_SOURCE_MISMATCH');
            }
            $assertDeclaration($publishedRequest);
            $agents[] = (int) $original->lab_agent_id;
        }
        sort($agents);
        $window = null;
        foreach ($foldIds as $foldId) {
            if (! is_int($foldId) || $foldId <= 0) {
                throw new LogicException('ORIGINAL_SELECTOR_CAPTURED_FOLD_ROSTER_REQUIRED');
            }
            $fold = CausalFoldReceipt::findOrFail($foldId);
            $captured = app(ResearchWindowExposureInventoryService::class)->verifiedOriginalFoldRequest($fold,
                (int) $registration['certificate_id']);
            $assertDeclaration($captured);
            $transport = (array) data_get($captured, 'policy_context.authorized_research_transport', []);
            $capturedWindow = (array) ($transport['window'] ?? []);
            $current = $windows->seal((string) ($capturedWindow['authorization_id'] ?? ''), $dataHash);
            if ($current === null || ($transport['protocol'] ?? null) !== InstrumentResearchWindowService::TRANSPORT_PROTOCOL
                || ($transport['dataset_hash'] ?? null) !== $dataHash
                || data_get($transport, 'scoped_original_window.protocol') !== 'scoped_original_window_v1'
                || data_get($transport, 'scoped_original_window.purpose') !== 'independent_scoped_selector_research'
                || data_get($transport, 'scoped_original_window.certificate_id') !== (int) $registration['certificate_id']
                || data_get($transport, 'scoped_original_window.design_hash') !== $registration['design_hash']
                || $epochs->parameterHash($capturedWindow) !== $epochs->parameterHash($current)
                || ($declaration['window_key'] ?? null) !== $current['window_key']
                || (int) $fold->agent_learning_causal_experiment_id !== ($product['experiment_id'] ?? null)
                || $fold->dataset_hash !== $dataHash || ($captured['replay_dataset_hash'] ?? null) !== $dataHash
                || $epochs->parameterHash((array) ($captured['mtf_snapshot_manifest'] ?? [])) !== $epochs->parameterHash($manifest)
                || $epochs->parameterHash($windows->canonicalScopedManifest($current, $manifest)) !== $epochs->parameterHash($manifest)
                || ($window !== null && $epochs->parameterHash($window) !== $epochs->parameterHash($current))) {
                throw new LogicException('ORIGINAL_SELECTOR_CAPTURED_PHYSICAL_WINDOW_MISMATCH');
            }
            $window = $current;
            $response = (array) $fold->response_payload;
            $items = collect((array) ($response['leaderboard'] ?? []))->keyBy('lab_agent_id');
            if ($fold->response_hash !== $epochs->parameterHash($response)
                || $items->count() !== 3 || $items->keys()->map(fn ($id) => (int) $id)->sort()->values()->all() !== $agents) {
                throw new LogicException('ORIGINAL_SELECTOR_CAPTURED_RESPONSE_MISMATCH');
            }
            foreach ($items as $item) {
                $clock = (array) data_get($item, 'result.data_quality.replay_executed_clock', []);
                try {
                    $signalStart = CarbonImmutable::parse($clock['signal_start'] ?? '', 'UTC')->utc();
                    $signalEnd = CarbonImmutable::parse($clock['signal_end'] ?? '', 'UTC')->utc();
                    $executionStart = CarbonImmutable::parse($clock['execution_start'] ?? '', 'UTC')->utc();
                    $executionEnd = CarbonImmutable::parse($clock['execution_end'] ?? '', 'UTC')->utc();
                    $start = CarbonImmutable::parse($window['start_inclusive'])->utc();
                    $end = CarbonImmutable::parse($window['end_exclusive'])->utc();
                } catch (\Throwable) {
                    throw new LogicException('ORIGINAL_SELECTOR_CAPTURED_CLOCK_OUTSIDE_WINDOW');
                }
                if (($clock['protocol'] ?? null) !== 'replay_executed_clock_v1' || ($clock['complete'] ?? null) !== true
                    || ($clock['dataset_hash'] ?? null) !== $dataHash || ($clock['execution_hash'] ?? null) !== $fold->execution_hash
                    || ! is_int($clock['decision_rows'] ?? null) || $clock['decision_rows'] <= 0
                    || ! is_int($clock['duration_seconds'] ?? null) || $clock['duration_seconds'] <= 0
                    || empty($clock['signal_start']) || empty($clock['signal_end'])
                    || empty($clock['execution_start']) || empty($clock['execution_end'])
                    || $signalStart->lessThan($start) || $signalEnd->lessThan($signalStart)
                    || $executionStart->lessThan($signalStart) || $executionEnd->lessThan($executionStart)
                    || $signalEnd->greaterThan($executionEnd) || ! $executionEnd->addSeconds($clock['duration_seconds'])->lessThanOrEqualTo($end)) {
                    throw new LogicException('ORIGINAL_SELECTOR_CAPTURED_CLOCK_OUTSIDE_WINDOW');
                }
            }
        }

        return $window;
    }

    private function council(array $registration): array
    {
        $version = SpecialistCouncilVersion::findOrFail($registration['source_id']);
        $proof = app(SpecialistCouncilLifecycleService::class)->qualifiedOriginalResearchProof($version);
        if (($proof['allowed'] ?? false) !== true) {
            throw new LogicException($proof['reason'] ?? 'ORIGINAL_COUNCIL_INDEPENDENT_PANEL_REQUIRED');
        }
        $ready = app(ScopedResearchCertificateService::class)->independentWindowReadiness((int) $registration['certificate_id']);
        if (! $ready['ready']) {
            throw new LogicException($ready['reason_codes'][0] ?? 'ORIGINAL_COUNCIL_EXPOSURE_REQUIRED');
        }

        return $this->terminal($registration, true, 'original_equal_account_panel_qualified', ['original_council_proof' => $proof]);
    }

    private function bootstrap(array $deltas, array $guard): array
    {
        if (($guard['method'] ?? null) !== 'paired_window_bootstrap_percentile'
            || ! is_int($guard['replicates'] ?? null) || $guard['replicates'] < 500 || $guard['replicates'] > 5000
            || ! is_int($guard['seed'] ?? null) || ($guard['lower_quantile'] ?? null) !== 0.05) {
            throw new LogicException('PREREGISTERED_STATISTICAL_GUARD_REQUIRED');
        }
        $samples = [];
        $n = count($deltas);
        for ($i = 0; $i < $guard['replicates']; $i++) {
            $sum = 0;
            for ($j = 0; $j < $n; $j++) {
                $sum += $deltas[hexdec(substr(hash('sha256', $guard['seed'].':'.$i.':'.$j), 0, 8)) % $n];
            }
            $samples[] = $sum / $n;
        }
        sort($samples, SORT_NUMERIC);

        return ['mean' => array_sum($deltas) / $n, 'lower_bound' => $samples[(int) floor(($guard['replicates'] - 1) * .05)],
            'guard' => $guard, 'paired_window_count' => $n];
    }

    private function metric(string $target): array
    {
        return match ($target) {
            'profit_factor', 'architecture' => ['path' => 'profit_factor', 'direction' => 1],
            // Native context slices express return in percent, not account cash.
            'net_profit', 'total_return_percent' => ['path' => 'net_profit_percent', 'direction' => 1],
            'drawdown', 'max_drawdown' => ['path' => 'max_drawdown_percent', 'direction' => -1],
            default => throw new LogicException('EXPLICIT_SUPPORTED_COMPONENT_UTILITY_REQUIRED'),
        };
    }

    private function number(array $source, string $path): float
    {
        $value = data_get($source, $path);
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            throw new LogicException('ORIGINAL_MEASUREMENT_INCOMPLETE');
        }

        return (float) $value;
    }

    private function terminal(array $registration, bool $confirmed, string $reason, array $evidence): array
    {
        return ['protocol' => self::PROTOCOL, 'status' => $confirmed ? 'confirmed' : 'negative_or_inconclusive',
            'confirmed' => $confirmed, 'scope' => $registration['scope'],
            'authority_type' => 'context_bound_research_'.$registration['scope'],
            'certificate_id' => (int) $registration['certificate_id'], 'source_id' => (int) $registration['source_id'],
            'design_hash' => $registration['design_hash'], 'source_hash' => $registration['source_hash'],
            'reason_code' => $reason, ...$evidence, 'paper_or_live_authority' => false,
            'parent_eligible' => false, 'promotion_evidence' => false];
    }
}
