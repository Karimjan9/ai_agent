<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LabFailureRepairAnchor;

/**
 * Separates a real behavioural mutation from a parameter-only change.
 *
 * This is a learning projection, not a promotion gate replacement. It uses
 * the same immutable screening snapshot/result that the normal gate sees and
 * records whether signal decisions, trade/event ledgers and the target gate
 * margin actually moved.
 */
class MutationObservabilityService
{
    public const PROTOCOL = 'mutation_observability_v1';

    /** @var array<string, \Illuminate\Support\Collection<int, LabAgent>> */
    private array $sameGenerationControlCache = [];

    /** @return array<string, mixed> */
    public function assess(LabAgent $agent, array $candidate): array
    {
        $agent->loadMissing('modelVersion', 'parentA', 'generation');
        $model = $agent->modelVersion;
        $metadata = (array) ($model?->metadata ?? []);
        $generationContext = (array) ($agent->generation?->trigger_context ?? []);
        $generationMode = (string) data_get(
            $generationContext,
            'research_allocation_budget.mode',
            data_get($generationContext, 'control_pairing_contract.mode', ''),
        );
        $generationTrigger = (string) ($agent->generation?->trigger_type ?? '');
        // A data-edge audit starts a fresh root cohort. It has no parent
        // mutation claim, so requiring a same-generation behavioural control
        // would turn a missing experiment design into a false strategy fail.
        $rootDataEdgeAudit = $generationTrigger === 'data_edge_audit';
        $normalGenerationExpected = $generationMode === 'normal_research'
            && (int) ($agent->generation?->population_size ?? 0) >= 2
            && ! in_array($generationTrigger, ['shadow_research', 'coverage_rescue', 'candidate_handoff', 'controlled_rescue', 'data_edge_audit'], true);
        $normalPairingContract = (array) data_get($generationContext, 'control_pairing_contract', []);
        $normalPairingAllowed = ! $normalGenerationExpected
            || (bool) data_get($normalPairingContract, 'allowed', false);
        $portfolioLane = (array) data_get($metadata, 'portfolio_council_lane', []);
        $shadowLane = (array) data_get($metadata, 'shadow_research_lane', data_get($portfolioLane, 'shadow_research_lane', []));
        $mutationContract = (array) (
            data_get($metadata, 'shadow_mutation_contract')
            ?: data_get($metadata, 'root_experiment_contract')
            ?: data_get($portfolioLane, 'shadow_mutation_contract', [])
        );
        $controlPairContract = (array) data_get($metadata, 'control_pair_contract', []);
        $declaredControlPairRequired = (bool) data_get($controlPairContract, 'required_for_candidate', false);
        $parameterDiff = (array) ($agent->parameter_diff ?? []);
        $parameterChanged = $parameterDiff !== [];
        $parameterFingerprint = (string) data_get($model?->metadata, 'parameter_fingerprint', '');
        if ($parameterFingerprint === '' && $parameterDiff !== []) {
            $parameterFingerprint = $this->hash($parameterDiff);
        }
        $controlOnly = (bool) data_get($metadata, 'repair_anchor.control_only', false)
            || (bool) data_get($metadata, 'g98_council_lane.control_only', false)
            || (bool) data_get($metadata, 'mutation_constructor_invariant.control_only', false)
            || (bool) data_get($portfolioLane, 'control_only', false)
            || data_get($metadata, 'repair_anchor_sibling.kind', data_get($metadata, 'repair_anchor.sibling_kind')) === 'frozen_control';
        $rootPortfolioIntervention = $rootDataEdgeAudit
            && (bool) data_get($metadata, 'root_experiment_portfolio', false)
            && ! $controlOnly;
        $rootDataEdgeAuditExempt = $rootDataEdgeAudit && ! $rootPortfolioIntervention;
        $controlPairRequired = ! $rootDataEdgeAuditExempt && ! $controlOnly && (
            $declaredControlPairRequired
            || $normalGenerationExpected
            || (bool) data_get($mutationContract, 'control_pair_required', false)
        );
        $requiresBehavioralDelta = ! $rootDataEdgeAuditExempt && ! $controlOnly && (
            (bool) data_get($mutationContract, 'behavioral_change_required', false)
            || (bool) data_get($mutationContract, 'behavioral_delta_required', false)
            || ($shadowLane !== [] && (bool) data_get($shadowLane, 'shadow_only', true))
            || $controlPairRequired
        );

        $baseline = $this->baseline($agent);
        $candidateSnapshot = $this->snapshot($candidate);
        $baselineSnapshot = $this->snapshot($baseline['result']);
        $target = (string) data_get(
            $model?->metadata,
            'repair_anchor.failure_target',
            data_get($model?->metadata, 'generation_target', 'profit_factor'),
        );
        $candidateMargin = app(GateMarginService::class)->screening($candidate, (array) data_get($candidate, 'reason_codes', []));
        $target = (string) data_get($candidateMargin, 'optimization_target', $target);
        $baselineMargin = $baseline['result'] !== []
            ? app(GateMarginService::class)->screening($baseline['result'], (array) data_get($baseline['result'], 'reason_codes', []))
            : [];
        $candidateTargetMargin = data_get($candidateMargin, 'target_margin');
        $baselineTargetMargin = data_get($baselineMargin, 'target_margin');
        $marginDelta = is_numeric(data_get($candidateTargetMargin, 'normalized_margin'))
            && is_numeric(data_get($baselineTargetMargin, 'normalized_margin'))
            ? round((float) data_get($candidateTargetMargin, 'normalized_margin') - (float) data_get($baselineTargetMargin, 'normalized_margin'), 6)
            : null;

        $signal = $this->compareDimension($candidateSnapshot, $baselineSnapshot, 'signal');
        $ledger = $this->compareDimension($candidateSnapshot, $baselineSnapshot, 'trade_ledger');
        $event = $this->compareDimension($candidateSnapshot, $baselineSnapshot, 'event_ledger');
        // Event digest is intentionally part of the final observability
        // contract. Signal/trade equality alone cannot prove that veto,
        // cooldown, transition or exit-path behaviour stayed unchanged.
        $behaviourComparable = $signal['available'] && $ledger['available']
            && $event['available'] && data_get($event, 'basis') === 'hash';
        $behaviourChanged = $behaviourComparable && ($signal['changed'] || $ledger['changed'] || ($event['available'] && $event['changed']));
        $strongNoEffectProof = $behaviourComparable
            && ! $behaviourChanged
            && data_get($signal, 'basis') === 'hash'
            && data_get($ledger, 'basis') === 'hash'
            && data_get($event, 'basis') === 'hash';

        $classification = match (true) {
            $rootDataEdgeAuditExempt => 'root_seed_observability',
            $controlOnly && ! $parameterChanged => 'frozen_control',
            ! $parameterChanged => 'zero_diff_mutation',
            ! $normalPairingAllowed => 'observability_incomplete',
            ! $baseline['available'] => 'baseline_missing',
            ! $behaviourComparable => 'observability_incomplete',
            $strongNoEffectProof => 'mutation_no_observable_effect',
            $behaviourChanged => 'observable_effect',
            default => 'observability_incomplete',
        };

        $controlPair = $this->sameGenerationControl(
            $agent,
            $controlOnly,
            $candidate,
            $normalGenerationExpected || $controlPairContract !== [],
        );
        if (! $baseline['available'] && $requiresBehavioralDelta && $controlPair['available']) {
            $baseline = $controlPair;
            $baselineSnapshot = $this->snapshot($baseline['result']);
            $signal = $this->compareDimension($candidateSnapshot, $baselineSnapshot, 'signal');
            $ledger = $this->compareDimension($candidateSnapshot, $baselineSnapshot, 'trade_ledger');
            $event = $this->compareDimension($candidateSnapshot, $baselineSnapshot, 'event_ledger');
            $behaviourComparable = $signal['available'] && $ledger['available']
                && $event['available'] && data_get($event, 'basis') === 'hash';
            $behaviourChanged = $behaviourComparable && ($signal['changed'] || $ledger['changed'] || $event['changed']);
            $classification = ! $behaviourComparable
                ? 'observability_incomplete'
                : ($behaviourChanged ? 'observable_effect' : 'mutation_no_observable_effect');
            $baselineMargin = app(GateMarginService::class)->screening($baseline['result'], (array) data_get($baseline['result'], 'reason_codes', []));
            $baselineTargetMargin = data_get($baselineMargin, 'target_margin');
            $marginDelta = is_numeric(data_get($candidateTargetMargin, 'normalized_margin'))
                && is_numeric(data_get($baselineTargetMargin, 'normalized_margin'))
                ? round((float) data_get($candidateTargetMargin, 'normalized_margin') - (float) data_get($baselineTargetMargin, 'normalized_margin'), 6)
                : null;
        }

        $controlPairAvailable = (bool) data_get($controlPair, 'available', false);
        $mutationContractStatus = ! $requiresBehavioralDelta
            ? 'not_required'
            : (! $normalPairingAllowed
                ? 'failed_evidence_incomplete'
                : (! $controlPairAvailable && $controlPairRequired
                    ? 'failed_evidence_incomplete'
                    : ($behaviourChanged
                        ? 'passed'
                        : ($behaviourComparable ? 'failed_no_behavior_delta' : 'failed_evidence_incomplete'))));
        $expectedBehavior = $this->expectedBehavioralAssessment(
            (array) data_get($mutationContract, 'behavioral_falsification.expected_change', []),
            $candidate,
            (array) ($baseline['result'] ?? []),
            $behaviourChanged,
        );

        $observability = [
            'protocol' => self::PROTOCOL,
            'agent_id' => (int) $agent->id,
            'target' => $target,
            'declared_gene' => data_get(
                $model?->metadata,
                'declared_gene',
                data_get($model?->metadata, 'root_experiment_contract.declared_gene', data_get($model?->metadata, 'repair_anchor_sibling.declared_gene')),
            ),
            'mutation_contract' => [
                'required' => $requiresBehavioralDelta,
                'status' => $mutationContractStatus,
                'protocol' => data_get($mutationContract, 'protocol'),
                'gene' => data_get($mutationContract, 'gene', $shadowLane['shadow_mutation_gene'] ?? null),
                'behavioral_change_required' => $requiresBehavioralDelta,
                'trade_ledger_delta_required' => (bool) data_get($mutationContract, 'trade_ledger_delta_required', $requiresBehavioralDelta),
                'control_pair_required' => $controlPairRequired,
                'control_pair_status' => ! $normalPairingAllowed
                    ? 'generation_pairing_contract_incomplete'
                    : ($controlPair['available'] ? 'available' : 'missing'),
                'control_pair_key' => data_get($controlPairContract, 'pair_key'),
                'control_pair_contract_declared' => $controlPairContract !== [],
                'normal_generation_pairing_expected' => $normalGenerationExpected,
                'normal_generation_pairing_allowed' => $normalPairingAllowed,
                'root_data_edge_audit_exempt' => $rootDataEdgeAuditExempt,
                'root_portfolio_intervention' => $rootPortfolioIntervention,
                'promotion_evidence' => false,
            ],
            'expected_behavioral_change' => $expectedBehavior,
            'parameter_changed' => $parameterChanged,
            'observability_scope' => $rootDataEdgeAuditExempt ? 'root_data_edge_audit' : 'mutation_comparison',
            'parameter_diff_count' => count($parameterDiff),
            'parameter_diff_keys' => array_keys($parameterDiff),
            'parameter_fingerprint' => $parameterFingerprint,
            'baseline' => [
                'available' => $baseline['available'],
                'source' => $baseline['source'],
                'agent_id' => $baseline['agent_id'],
            ],
            'signal_decisions' => $signal,
            'trade_ledger' => $ledger,
            'event_ledger' => $event,
            'signal_digest' => data_get($candidateSnapshot, 'signal_hash'),
            'trade_ledger_hash' => data_get($candidateSnapshot, 'trade_ledger_hash'),
            'event_ledger_hash' => data_get($candidateSnapshot, 'event_ledger_hash'),
            'behavior_fingerprint' => data_get($candidateSnapshot, 'behavior_fingerprint'),
            'behavior_fingerprint_components' => [
                'signal_digest' => data_get($candidateSnapshot, 'signal_hash'),
                'trade_ledger_hash' => data_get($candidateSnapshot, 'trade_ledger_hash'),
                'event_ledger_hash' => data_get($candidateSnapshot, 'event_ledger_hash'),
            ],
            'gate_margin' => [
                'candidate' => $candidateTargetMargin,
                'baseline' => $baselineTargetMargin,
                'target' => $target,
                'normalized_delta' => $marginDelta,
                'target_gate_improved' => $marginDelta !== null && $marginDelta > 0,
            ],
            'classification' => $classification,
            'reusable_gene_policy' => ! $normalPairingAllowed
                ? 'do_not_reuse_until_generation_control_contract_is_repaired'
                : (in_array($classification, ['mutation_no_observable_effect', 'zero_diff_mutation'], true)
                    || in_array((string) data_get($expectedBehavior, 'status'), ['contradicted', 'no_expected_effect', 'evidence_incomplete'], true)
                    ? 'do_not_reuse_until_new_observable_architecture_or_baseline'
                    : 'eligible_for_normal_learning_review'),
            'promotion_evidence' => false,
        ];

        $controlRelative = app(ControlRelativeRewardService::class)->assess($agent, $candidate, $observability);
        $observability['control_relative'] = $controlRelative;
        $observability['anchor_delta'] = data_get($observability, 'gate_margin.normalized_delta');
        $observability['control_delta'] = data_get($controlRelative, 'control_delta');
        $observability['non_target_regression'] = data_get($controlRelative, 'non_target_regression');
        $observability['holdout_confirmation'] = data_get($controlRelative, 'holdout_confirmation');
        $observability['gate_margin_vector'] = [
            ...(array) data_get($candidateMargin, 'gate_margin_vector', []),
            'control_delta' => data_get($controlRelative, 'control_delta'),
        ];
        $observability['observable_effect'] = $classification === 'observable_effect';
        $observability['control_relative_improved'] = (bool) data_get($controlRelative, 'control_relative_improved', false);
        $targetImprovement = max(
            0.0,
            (float) data_get($observability, 'gate_margin.normalized_delta', 0.0),
            (bool) data_get($controlRelative, 'control_relative_improved', false)
                ? (float) data_get($controlRelative, 'control_delta', 0.0)
                : 0.0,
        );
        $tradeCount = max(0, (int) data_get($candidateSnapshot, 'trade_count', 0));
        $runtimeMs = max(1000.0, (float) data_get(
            $candidate,
            'optimization.stage_timings_ms.total_ms',
            data_get($candidate, 'optimization.total_runtime_ms', 120000.0),
        ));
        $runtimeMinutes = max(1.0 / 60.0, $runtimeMs / 60000.0);
        $uncertainty = 1.0 / sqrt($tradeCount + 1.0);
        $novelty = data_get($observability, 'behavior_fingerprint') !== '' ? 1.0 : 0.5;
        $observability['information_gain_per_minute'] = round(
            ($targetImprovement * $uncertainty * $novelty) / $runtimeMinutes,
            8,
        );
        $bandit = app(ContextualMutationBanditService::class);
        $banditContext = $bandit->context(
            $agent,
            $candidate,
            $target,
            (string) data_get($observability, 'declared_gene', ''),
        );
        $observability['contextual_bandit'] = [
            ...$banditContext,
            ...$bandit->reward($observability, $controlRelative),
        ];
        $observability['progress_ladder'] = app(ProgressLadderService::class)->assess($observability, $candidate, $controlRelative);

        return $observability;
    }

    public function record(LabAgent $agent, array $observability): void
    {
        $model = $agent->modelVersion;
        if (! $model) return;

        $metadata = (array) ($model->metadata ?? []);
        $history = array_values((array) data_get($metadata, 'mutation_observability_history', []));
        $history[] = $observability;
        $metadata['mutation_observability'] = $observability;
        if (filled(data_get($observability, 'behavior_fingerprint'))) {
            $metadata['behavior_fingerprint'] = data_get($observability, 'behavior_fingerprint');
        }
        $metadata['mutation_observability_history'] = array_slice($history, -12);
        $model->update(['metadata' => $metadata]);
    }

    /**
     * Verify the pre-registered causal claim, rather than treating any changed
     * ledger as success. This constrains future gene reuse only; it never
     * relaxes or replaces the economic screening gates.
     *
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $baseline
     * @return array<string, mixed>
     */
    private function expectedBehavioralAssessment(array $expected, array $candidate, array $baseline, bool $behaviourChanged): array
    {
        if ($expected === []) return ['status' => 'not_declared', 'checked' => false, 'promotion_evidence' => false];
        if ($baseline === []) return ['status' => 'evidence_incomplete', 'checked' => false, 'expected' => $expected, 'promotion_evidence' => false];

        $candidateFunnel = (array) data_get($candidate, 'entry_funnel', []);
        $baselineFunnel = (array) data_get($baseline, 'entry_funnel', []);
        $rawCandidate = (int) data_get($candidateFunnel, 'raw_strategy_signals', data_get($candidateFunnel, 'flat_signal_opportunities', 0));
        $rawBaseline = (int) data_get($baselineFunnel, 'raw_strategy_signals', data_get($baselineFunnel, 'flat_signal_opportunities', 0));
        $acceptedCandidate = (int) data_get($candidateFunnel, 'accepted_entries', data_get($candidate, 'total_trades', 0));
        $acceptedBaseline = (int) data_get($baselineFunnel, 'accepted_entries', data_get($baseline, 'total_trades', 0));
        $channel = (string) data_get($expected, 'veto_channel', '');
        $vetoCandidate = $this->vetoCount($candidateFunnel, $channel);
        $vetoBaseline = $this->vetoCount($baselineFunnel, $channel);
        $checks = [];
        if (data_get($expected, 'raw_signal') === 'unchanged') {
            $checks['raw_signal'] = ['expected' => 'unchanged', 'baseline' => $rawBaseline, 'candidate' => $rawCandidate,
                'status' => $rawBaseline === $rawCandidate ? 'matched' : 'contradicted'];
        }
        if ($channel !== '') {
            $expectedDirection = (string) data_get($expected, 'veto_direction', 'change');
            $vetoStatus = match ($expectedDirection) {
                'decrease' => $vetoBaseline === 0
                    ? 'not_applicable_no_baseline_veto'
                    : ($vetoCandidate < $vetoBaseline ? 'matched' : ($vetoCandidate === $vetoBaseline ? 'no_effect' : 'contradicted')),
                'increase' => $vetoCandidate > $vetoBaseline ? 'matched' : ($vetoCandidate === $vetoBaseline ? 'no_effect' : 'contradicted'),
                // Session, topology and transition interventions declare a
                // non-directional change: opening or closing the veto path
                // is evidence, while no movement falsifies the claim.
                default => $vetoCandidate !== $vetoBaseline ? 'matched' : 'no_effect',
            };
            $checks['veto_channel'] = ['channel' => $channel, 'expected_direction' => data_get($expected, 'veto_direction', 'change'),
                'baseline' => $vetoBaseline, 'candidate' => $vetoCandidate,
                'status' => $vetoStatus];
        }
        if (in_array(data_get($expected, 'accepted_entries'), ['increase_or_same', 'change'], true)) {
            $checks['accepted_entries'] = ['expected' => data_get($expected, 'accepted_entries'), 'baseline' => $acceptedBaseline, 'candidate' => $acceptedCandidate,
                'status' => data_get($expected, 'accepted_entries') === 'increase_or_same'
                    ? ($acceptedCandidate >= $acceptedBaseline ? 'matched' : 'contradicted')
                    : ($acceptedCandidate !== $acceptedBaseline ? 'matched' : 'no_effect')];
        }
        if (data_get($expected, 'accepted_entries') === 'unchanged_or_near') {
            // Exit/risk interventions should not silently become entry
            // interventions.  Permit normal discrete replay noise, but
            // make a material trade-set shift falsify that causal claim.
            $tolerance = max(1, (int) ceil($acceptedBaseline * 0.10));
            $delta = abs($acceptedCandidate - $acceptedBaseline);
            $checks['accepted_entries'] = [
                'expected' => 'unchanged_or_near',
                'baseline' => $acceptedBaseline,
                'candidate' => $acceptedCandidate,
                'tolerance' => $tolerance,
                'status' => $delta <= $tolerance ? 'matched' : 'contradicted',
            ];
        }
        $checks = [
            ...$checks,
            ...$this->expectedDistributionChecks($expected, $candidate, $baseline),
        ];
        $statuses = collect($checks)->pluck('status')->all();
        $status = match (true) {
            $checks === [] => $behaviourChanged ? 'observed_generic_effect' : 'no_expected_effect',
            in_array('contradicted', $statuses, true) => 'contradicted',
            // A pre-registered structural claim cannot be credited merely
            // because a generic ledger hash moved. When its declared plane
            // is absent from the replay projection, the planner must treat
            // the experiment as unverified rather than reusable evidence.
            in_array('evidence_incomplete', $statuses, true) => 'evidence_incomplete',
            // An invariant such as unchanged raw signals is supporting
            // evidence, never the claimed causal effect by itself.  A
            // confidence/EV ablation with no applicable veto and no changed
            // trade/event behaviour must be recorded as a no-effect probe,
            // not as a successful expected-behaviour match.
            ! $behaviourChanged => 'no_expected_effect',
            in_array('matched', $statuses, true) => 'matched',
            $behaviourChanged => 'unexpected_behavioral_change',
            default => 'no_expected_effect',
        };

        return ['protocol' => 'expected_behavioral_change_v1', 'status' => $status, 'checked' => true,
            'expected' => $expected, 'checks' => $checks, 'behavior_changed' => $behaviourChanged, 'promotion_evidence' => false];
    }

    /** @param array<string, mixed> $funnel */
    private function vetoCount(array $funnel, string $channel): int
    {
        if ($channel === '') return 0;
        $total = 0;
        foreach ((array) data_get($funnel, 'rejected', []) as $reason => $count) {
            if (str_contains(strtolower((string) $reason), strtolower($channel))) $total += max(0, (int) $count);
        }
        return $total;
    }

    /**
     * Structural and exit mutations must be checked on their declared
     * behavioural plane, not merely credited because any ledger changed.
     * The replay payload varies by strategy version, so each dimension has a
     * small ordered set of canonical aliases.
     *
     * @param array<string, mixed> $expected
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $baseline
     * @return array<string, array<string, mixed>>
     */
    private function expectedDistributionChecks(array $expected, array $candidate, array $baseline): array
    {
        $dimensions = [
            'exit_state' => ['exit_distribution', 'trade_attribution.by_exit', 'event_ledger_hash'],
            'holding_time_distribution' => ['holding_time_distribution', 'trade_attribution.holding_time_distribution'],
            'stress_cost' => ['stress_test.profit_factor', 'stress_cost_exit.profit_factor'],
            'trade_loss_profile' => ['stress_test.trade_loss_profile', 'stress_cost_exit.trade_loss_profile'],
            'regime_coverage' => ['pf_attribution.breakdown.by_regime', 'pf_attribution.by_regime'],
            'entry_topology' => ['entry_topology_distribution', 'event_ledger_hash'],
            'accepted_trade_set' => ['trade_ledger_hash', 'observability_manifest.trade_ledger_hash'],
            'calendar_or_transition_distribution' => ['pf_attribution.breakdown.by_month', 'pf_attribution.by_month', 'event_ledger_hash'],
            'entry_or_exit_state' => ['event_ledger_hash'],
            'stress_or_temporal_behavior' => ['stress_test.profit_factor', 'temporal_stability', 'event_ledger_hash'],
        ];
        $checks = [];
        foreach ($dimensions as $dimension => $paths) {
            if (data_get($expected, $dimension) !== 'change') continue;
            $candidateValue = $this->firstPresentValue($candidate, $paths);
            $baselineValue = $this->firstPresentValue($baseline, $paths);
            if (! $candidateValue['available'] || ! $baselineValue['available']) {
                $checks[$dimension] = ['expected' => 'change', 'status' => 'evidence_incomplete'];
                continue;
            }
            $changed = $this->canonicalValue($candidateValue['value']) !== $this->canonicalValue($baselineValue['value']);
            $checks[$dimension] = [
                'expected' => 'change', 'path' => $candidateValue['path'],
                'status' => $changed ? 'matched' : 'no_effect',
            ];
        }

        return $checks;
    }

    /** @param array<string, mixed> $payload @param array<int, string> $paths */
    private function firstPresentValue(array $payload, array $paths): array
    {
        foreach ($paths as $path) {
            $marker = new \stdClass();
            $value = data_get($payload, $path, $marker);
            if ($value !== $marker) return ['available' => true, 'path' => $path, 'value' => $value];
        }
        return ['available' => false, 'path' => null, 'value' => null];
    }

    private function canonicalValue(mixed $value): string
    {
        if (is_array($value)) {
            $normalized = $value;
            ksort($normalized);
            return $this->hash($normalized);
        }
        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }

    /** @return array{available: bool, result: array<string, mixed>, source: string, agent_id: ?int} */
    private function baseline(LabAgent $agent): array
    {
        $model = $agent->modelVersion;
        $anchorId = (int) data_get($model?->metadata, 'repair_anchor.id', 0);
        if ($anchorId > 0) {
            $anchor = LabFailureRepairAnchor::query()->with('sourceModelVersion')->find($anchorId);
            $result = (array) data_get($anchor?->evidence, 'screening_result', []);
            if ($result === []) $result = (array) data_get($anchor?->sourceModelVersion?->metadata, 'last_screen_result', []);
            if ($result !== []) {
                return [
                    'available' => true,
                    'result' => $result,
                    'source' => 'immutable_repair_anchor',
                    'agent_id' => (int) ($anchor?->source_lab_agent_id ?: 0) ?: null,
                ];
            }
        }

        $parent = $agent->parentA;
        $result = (array) data_get($parent?->metadata, 'last_screen_result', []);
        if ($result !== []) {
            return [
                'available' => true,
                'result' => $result,
                'source' => 'validated_parent_screen',
                'agent_id' => null,
            ];
        }

        return ['available' => false, 'result' => [], 'source' => 'missing', 'agent_id' => null];
    }

    /** @return array{available: bool, result: array<string, mixed>, source: string, agent_id: ?int} */
    private function sameGenerationControl(
        LabAgent $agent,
        bool $controlOnly,
        array $candidate,
        bool $exactPairContractRequired = false,
    ): array
    {
        if ($controlOnly || ! $agent->lab_generation_id) {
            return ['available' => false, 'result' => [], 'source' => 'not_applicable', 'agent_id' => null];
        }

        $agent->loadMissing('modelVersion');
        $structural = (string) data_get($agent->modelVersion?->metadata, 'portfolio_council_lane.structural_cohort_id', '');
        $candidatePairKey = (string) data_get($agent->modelVersion?->metadata, 'control_pair_contract.pair_key', '');
        if ($exactPairContractRequired && $candidatePairKey === '') {
            return ['available' => false, 'result' => [], 'source' => 'same_generation_control_pair_contract_missing', 'agent_id' => null];
        }
        $controlCacheKey = implode('|', [(int) $agent->lab_generation_id, (string) $agent->strategy_family]);
        $controls = $this->sameGenerationControlCache[$controlCacheKey]
            ??= LabAgent::query()
                ->with('modelVersion')
                ->where('lab_generation_id', $agent->lab_generation_id)
                ->where('strategy_family', $agent->strategy_family)
                ->get();
        $controls = $controls
            ->filter(function (LabAgent $candidate) use ($agent, $structural): bool {
                if ((int) $candidate->id === (int) $agent->id) return false;
                if (! app(FrozenControlParityService::class)->isControl($candidate)) return false;
                $candidateStructural = (string) data_get($candidate->modelVersion?->metadata, 'portfolio_council_lane.structural_cohort_id', '');
                return $structural === '' || $candidateStructural === '' || $structural === $candidateStructural;
            });

        $candidateUsesVolume = $this->usesVolumeResearch($agent);
        $candidateDataHash = $this->resultIdentity($candidate, 'data');
        $candidateExecutionHash = $this->resultIdentity($candidate, 'execution');
        foreach ($controls as $control) {
            $controlPairKey = (string) data_get($control->modelVersion?->metadata, 'control_pair_contract.pair_key', '');
            if ($candidatePairKey !== '' && $controlPairKey !== $candidatePairKey) continue;
            $result = (array) data_get($control->modelVersion?->metadata, 'last_screen_result', []);
            if ($result === [] || $candidateUsesVolume !== $this->usesVolumeResearch($control)) continue;

            // A control with a different sealed snapshot or execution
            // contract is not a control for this candidate. Do not silently
            // fall back to a global control: that would recreate the causal
            // pairing gap this service is meant to close.
            $controlDataHash = $this->resultIdentity($result, 'data');
            $controlExecutionHash = $this->resultIdentity($result, 'execution');
            if ($candidateDataHash !== '' && $controlDataHash !== '' && $candidateDataHash !== $controlDataHash) continue;
            if ($candidateExecutionHash !== '' && $controlExecutionHash !== '' && $candidateExecutionHash !== $controlExecutionHash) continue;

            return [
                'available' => true,
                'result' => $result,
                'source' => 'same_generation_frozen_control_exact_contract',
                'agent_id' => (int) $control->id,
            ];
        }

        return ['available' => false, 'result' => [], 'source' => 'same_generation_control_exact_contract_missing', 'agent_id' => null];
    }

    private function usesVolumeResearch(LabAgent $agent): bool
    {
        $metadata = (array) ($agent->modelVersion?->metadata ?? []);
        $parameters = (array) ($agent->modelVersion?->parameters ?? []);

        return (bool) data_get($metadata, 'volume_research_contract.enabled', false)
            || data_get($metadata, 'volume_research_contract.protocol') === 'volume_council_v1'
            || (bool) data_get($metadata, 'portfolio_council_lane.volume_shadow', false)
            || (bool) data_get($metadata, 'risk_bounded_evolution.volume_shadow', false)
            || data_get($metadata, 'portfolio_council_lane.role') === 'volume_m15_specialist'
            || data_get($metadata, 'portfolio_council_lane.specialist_role') === 'volume_m15_specialist'
            || (string) data_get($parameters, 'volume_lane', 'none') !== 'none';
    }

    private function resultIdentity(array $result, string $kind): string
    {
        return match ($kind) {
            'data' => (string) data_get($result, 'data_hash', data_get(
                $result,
                'data_manifest.snapshot_sha256',
                data_get($result, 'data_manifest.data_hash', data_get($result, 'data_manifest.sha256', data_get($result, 'dataset_hash', ''))),
            )),
            'execution' => (string) data_get($result, 'execution_hash', data_get($result, 'execution_contract.execution_hash', '')),
            default => '',
        };
    }

    /** @return array<string, mixed> */
    private function snapshot(array $result): array
    {
        $trace = data_get($result, 'decision_trace', data_get($result, 'candle_decision_trace', data_get($result, 'decision_events')));
        $signalHash = $this->firstFilled($result, [
            'signal_decision_hash', 'decision_hash', 'signal_hash',
            'observability_manifest.decision_trace_hash', 'decision_trace_hash',
        ]);
        if ($signalHash === '' && is_array($trace)) $signalHash = $this->hash($trace);

        $ledger = data_get($result, 'trade_ledger', data_get($result, 'trades'));
        $ledgerHash = $this->firstFilled($result, [
            'trade_ledger_hash', 'observability_manifest.trade_ledger_hash',
        ]);
        if ($ledgerHash === '' && is_array($ledger)) $ledgerHash = $this->hash($ledger);

        $eventHash = $this->firstFilled($result, [
            'event_ledger_hash', 'event_hash', 'execution_event_hash',
            'observability_manifest.event_ledger_hash', 'event_digest.hash', 'execution_event_digest',
        ]);

        $behaviorFingerprint = '';
        if ($signalHash !== '' && $ledgerHash !== '' && $eventHash !== '') {
            $behaviorFingerprint = $this->hash([
                'signal_digest' => $signalHash,
                'trade_ledger_hash' => $ledgerHash,
                'event_ledger_hash' => $eventHash,
            ]);
        }

        return [
            'signal_hash' => $signalHash,
            'signal_count' => $this->firstNumeric($result, [
                'signal_count', 'total_signals', 'entry_funnel.raw_strategy_signals',
                'entry_funnel.accepted_entries', 'observability_manifest.decision_trace_count',
            ]),
            'trade_ledger_hash' => $ledgerHash,
            'trade_count' => $this->firstNumeric($result, [
                'total_trades', 'trade_count', 'sample_count',
                'observability_manifest.trade_ledger_count',
            ]),
            'event_ledger_hash' => $eventHash,
            'event_categories' => (array) data_get($result, 'event_ledger_categories', data_get($result, 'event_digest.categories', [])),
            'event_count' => $this->firstNumeric($result, [
                'event_count', 'decision_event_count', 'observability_manifest.event_count',
            ]),
            'behavior_fingerprint' => $behaviorFingerprint,
        ];
    }

    /** @return array<string, mixed> */
    private function compareDimension(array $candidate, array $baseline, string $dimension): array
    {
        $hashKey = match ($dimension) {
            'signal' => 'signal_hash',
            'trade_ledger' => 'trade_ledger_hash',
            default => 'event_ledger_hash',
        };
        $countKey = match ($dimension) {
            'signal' => 'signal_count',
            'trade_ledger' => 'trade_count',
            default => 'event_count',
        };
        $candidateHash = (string) ($candidate[$hashKey] ?? '');
        $baselineHash = (string) ($baseline[$hashKey] ?? '');
        if ($candidateHash !== '' && $baselineHash !== '') {
            return [
                'available' => true,
                'basis' => 'hash',
                'candidate' => $candidateHash,
                'baseline' => $baselineHash,
                'changed' => ! hash_equals($candidateHash, $baselineHash),
            ];
        }

        $candidateCount = $candidate[$countKey] ?? null;
        $baselineCount = $baseline[$countKey] ?? null;
        if (is_numeric($candidateCount) && is_numeric($baselineCount)) {
            return [
                'available' => true,
                'basis' => 'count',
                'candidate' => (int) $candidateCount,
                'baseline' => (int) $baselineCount,
                'changed' => (int) $candidateCount !== (int) $baselineCount,
            ];
        }

        return [
            'available' => false,
            'basis' => 'missing',
            'candidate' => $candidateHash !== '' ? $candidateHash : $candidateCount,
            'baseline' => $baselineHash !== '' ? $baselineHash : $baselineCount,
            'changed' => null,
        ];
    }

    private function firstFilled(array $payload, array $paths): string
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);
            if (is_scalar($value) && trim((string) $value) !== '') return (string) $value;
        }

        return '';
    }

    private function firstNumeric(array $payload, array $paths): ?float
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);
            if (is_numeric($value)) return (float) $value;
        }

        return null;
    }

    private function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));
    }
}
