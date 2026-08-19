<?php

namespace App\Services;

use App\Models\LabAgent;

/**
 * Compiles an observed failure into a falsifiable, behaviour-level research
 * contract. This is deliberately research-only: it can rank and describe an
 * experiment, but it cannot create mutation credit, a parent or promotion
 * evidence.
 */
class CausalSkillCompilerService
{
    public const PROTOCOL = 'causal_skill_compiler_v1';
    public const REQUIRED_WINDOWS = 3;
    public const MINIMUM_POSITIVE_WINDOWS = 2;

    private const SCALAR_GENES = [
        'transition_wait_candles', 'cooldown_candles', 'signal_max_age_candles',
        'signal_decay_half_life_candles', 'confidence_calibration_min_samples',
        'max_spread_atr_ratio', 'spread_threshold', 'threshold', 'lookback',
        'breakout_lookback', 'roc_period', 'ema_period', 'transition_wait',
    ];

    private const STRUCTURAL_AXES = [
        'signal_construction', 'entry_exit_state', 'regime_classification',
    ];

    /** @return array<string, mixed> */
    public function compile(
        ?LabAgent $agent,
        array $failureSignature = [],
        array $result = [],
        ?array $targetDelta = null,
    ): array {
        $metadata = (array) ($agent?->modelVersion?->metadata ?? []);
        $target = strtolower(trim((string) (
            data_get($failureSignature, 'failure_target')
            ?: data_get($metadata, 'generation_target', 'unknown')
        )));
        $stage = $this->decisionStage($target, $failureSignature, $result);
        $gene = (string) (
            data_get($failureSignature, 'changed_gene')
            ?: data_get($metadata, 'hypothesis_contract.changed_gene', '')
        );
        $fingerprint = $this->behavioralFingerprint($result);
        $control = $this->exactControlContract($agent, $result);
        $counterfactual = $this->counterfactualContract($result);
        $windows = $this->windowEvidence($result);
        $prediction = $this->predictionContract($metadata, $target, $stage, $gene);
        $hypothesis = $this->hypothesis($target, $stage, $gene, $failureSignature, $result);
        $behavior = $this->behavioralDelta($result, $targetDelta, $stage);
        $lesson = $this->reusableLesson($control, $counterfactual, $windows, $behavior, $prediction);

        return [
            'protocol' => self::PROTOCOL,
            'status' => $lesson['status'] === 'reusable' ? 'research_ready' : 'research_incomplete',
            'failure_signature' => data_get($failureSignature, 'signature'),
            'decision_stage' => $stage,
            'declared_gene' => $gene !== '' ? $gene : null,
            'hypothesis' => $hypothesis,
            'behavioral_delta' => $behavior,
            'behavioral_fingerprint' => $fingerprint,
            'exact_control' => $control,
            'counterfactual_replay' => $counterfactual,
            'independent_windows' => $windows,
            'prediction_contract' => $prediction,
            'reusable_lesson' => $lesson,
            'promotion_evidence' => false,
        ];
    }

    /**
     * A semantic fingerprint prevents different numeric values with the same
     * decision trace from being counted as separate evolution.
     * @return array<string, mixed>
     */
    public function behavioralFingerprint(array $result): array
    {
        $sources = [
            'decisions' => data_get($result, 'decision_ledger', data_get($result, 'decision_events', [])),
            'trades' => data_get($result, 'trade_ledger', data_get($result, 'trades', [])),
            'abstentions' => data_get($result, 'abstention_ledger', data_get($result, 'veto_ledger', [])),
            'signals' => data_get($result, 'signal_ledger', data_get($result, 'signals', [])),
            'exits' => data_get($result, 'exit_ledger', []),
        ];
        $normalized = [];
        foreach ($sources as $name => $value) {
            $normalized[$name] = $this->normalizeForHash($value);
        }
        $aggregate = [
            'trade_count' => data_get($result, 'trade_count', data_get($result, 'trades_count', data_get($result, 'total_trades'))),
            'abstention_cells' => data_get($result, 'abstention_cells'),
            'missed_profitable_opportunity_cells' => data_get($result, 'missed_profitable_opportunity_cells'),
            'signal_count' => data_get($result, 'signal_count'),
        ];
        $payload = ['protocol' => 'behavioral_mutation_fingerprint_v1', 'ledgers' => $normalized, 'aggregates' => $aggregate];

        return [
            ...$payload,
            'hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)),
            'ledger_presence' => collect($sources)->mapWithKeys(fn ($value, $key): array => [$key => is_array($value) && $value !== []])->all(),
            // Aggregate scores alone are not a behavioural trace; requiring
            // an actual ledger prevents ordinary same-trade-count reports
            // from being misclassified as semantic duplicates.
            'has_behavioral_observation' => collect($normalized)->contains(fn ($value): bool => is_array($value) && $value !== []),
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function counterfactualContract(array $result): array
    {
        $required = ['veto_on', 'veto_off', 'delayed_entry', 'half_risk', 'alternate_exit'];
        $branches = (array) data_get($result, 'counterfactual_replay.branches', data_get($result, 'decision_blame_graph.branches', []));
        $cases = (array) data_get($result, 'counterfactual_blame_graph.cases', []);
        $observed = collect($branches)->map(function ($branch, $key): string {
            return is_array($branch)
                ? (string) data_get($branch, 'branch', data_get($branch, 'intervention_type', $key))
                : (string) $key;
        })->filter()->values()->all();
        foreach ($cases as $case) {
            if (! is_array($case)) continue;
            if (array_key_exists('no_trade', $case)) $observed[] = 'veto_on';
            if (array_key_exists('real_trade', $case)) $observed[] = 'veto_off';
            if ((string) data_get($case, 'delayed_entry.status', '') === 'assessed_fixed_exit') $observed[] = 'delayed_entry';
            if ((string) data_get($case, 'half_risk.status', '') === 'assessed') $observed[] = 'half_risk';
            if ((string) data_get($case, 'alternative_exit.status', '') === 'assessed') $observed[] = 'alternate_exit';
        }
        $observed = collect($observed)->map(fn (string $name): string => $name === 'alternative_exit' ? 'alternate_exit' : $name)->unique()->values()->all();
        $missing = array_values(array_diff($required, $observed));

        return [
            'protocol' => 'counterfactual_failure_replay_v1',
            'required_branches' => $required,
            'observed_branches' => $observed,
            'missing_branches' => $missing,
            'status' => $missing === [] ? 'assessed' : 'incomplete',
            'avoided_loss_required' => true,
            'missed_opportunity_required' => true,
            'abstention_alone_is_not_skill' => true,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function informationGainPriority(array $failure, array $context = []): array
    {
        $novelty = $this->bounded(data_get($context, 'novelty', data_get($failure, 'novelty', 0.5)));
        $causal = $this->bounded(data_get($context, 'causal_leverage', data_get($failure, 'causal_leverage', 0.5)));
        $readiness = $this->bounded(data_get($context, 'replay_readiness', data_get($failure, 'replay_readiness', 0.5)));
        $repeat = max(0.0, min(1.0, (float) data_get($context, 'repeat_count', data_get($failure, 'repeat_count', 0)) / 3));
        $score = ($novelty * .35) + ($causal * .35) + ($readiness * .20) + ((1 - $repeat) * .10);

        return [
            'protocol' => 'information_gain_priority_v1',
            'components' => ['novelty' => $novelty, 'causal_leverage' => $causal, 'replay_readiness' => $readiness, 'repeat_penalty' => $repeat],
            'score' => round($score, 6),
            'rule' => 'Prioritize novel, causally actionable and replay-ready failures; repeated duplicates are down-ranked.',
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function structuralEscapeContract(array $failures = []): array
    {
        $counts = collect($failures)->map(function ($failure): string {
            $gene = is_array($failure) ? (string) data_get($failure, 'changed_gene', data_get($failure, 'declared_gene', '')) : (string) $failure;
            return $this->axisForGene($gene);
        })->countBy()->all();
        $scalar = array_sum(array_intersect_key($counts, array_fill_keys(['scalar_wait', 'scalar_threshold'], true)));
        $repeated = $scalar >= 2;

        return [
            'protocol' => 'structural_escape_mode_v1',
            'scalar_failure_count' => $scalar,
            'freeze_scalar_search' => $repeated,
            'required_axes' => self::STRUCTURAL_AXES,
            'allowed_structural_surfaces' => ['signal_construction', 'entry_topology', 'exit_state_machine', 'regime_classifier', 'cost_aware_execution'],
            'reason' => $repeated ? 'two_or_more_independent_scalar_failures' : 'not_triggered',
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function predictionContract(array $metadata, string $target, string $stage, ?string $gene): array
    {
        $declared = (array) data_get($metadata, 'hypothesis_contract', data_get($metadata, 'causal_hypothesis', []));
        $hasPrediction = filled(data_get($declared, 'falsifiable_statement'))
            && filled(data_get($declared, 'expected_behavioral_delta'))
            && filled(data_get($declared, 'non_target_invariants'));

        return [
            'protocol' => 'pre_registered_prediction_v1',
            'status' => $hasPrediction ? 'declared' : 'missing',
            'target' => $target,
            'decision_stage' => $stage,
            'declared_gene' => $gene,
            'falsifiable_statement' => data_get($declared, 'falsifiable_statement'),
            'expected_behavioral_delta' => data_get($declared, 'expected_behavioral_delta'),
            'non_target_invariants' => data_get($declared, 'non_target_invariants', []),
            'expected_window' => data_get($declared, 'expected_window'),
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function mentorContract(array $verification = []): array
    {
        $windows = (int) data_get($verification, 'independent_windows', data_get($verification, 'independent_forward_windows.independent_windows', 0));
        $positive = (int) data_get($verification, 'positive_windows', data_get($verification, 'independent_forward_windows.positive_windows', 0));
        $confirmed = $windows >= self::REQUIRED_WINDOWS && $positive >= self::MINIMUM_POSITIVE_WINDOWS;

        return [
            'protocol' => 'confirmed_skill_mentor_v1',
            'status' => $confirmed ? 'confirmed_shadow_mentor' : 'provisional_shadow_only',
            'independent_windows' => $windows,
            'positive_windows' => $positive,
            'parent_eligible' => false,
            'mutation_credit' => false,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function interactionContract(array $genes, array $mentors = []): array
    {
        $genes = array_values(array_unique(array_filter(array_map('strval', $genes))));
        $confirmed = collect($mentors)->count() === 2 && collect($mentors)->every(fn ($mentor): bool =>
            in_array((string) data_get($mentor, 'status'), ['confirmed', 'confirmed_shadow_mentor'], true)
            && (int) data_get($mentor, 'independent_windows', 0) >= self::REQUIRED_WINDOWS
            && (int) data_get($mentor, 'positive_windows', 0) >= self::MINIMUM_POSITIVE_WINDOWS
        );

        return [
            'protocol' => 'factorial_gene_interaction_v1',
            'status' => count($genes) === 2 && $confirmed ? 'eligible_research_only' : 'blocked_missing_confirmed_mentors',
            'genes' => $genes,
            'required_arms' => ['control', 'gene_a', 'gene_b', 'gene_a_plus_b'],
            'exact_control_required' => true,
            'three_windows_required' => true,
            'mutation_credit' => false,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function routerContract(array $specialistSignals = []): array
    {
        $disagreement = collect($specialistSignals)->map(fn ($signal): string => strtolower((string) data_get($signal, 'decision', data_get($signal, 'action', 'unknown'))))->unique()->count() > 1;
        return [
            'protocol' => 'regime_router_safety_v1',
            'status' => $disagreement ? 'wait_on_disagreement' : 'consensus_or_unassessed',
            'disagreement_action' => 'WAIT',
            'requires_calibrated_confidence' => true,
            'requires_regime_ownership' => true,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function exactControlContract(?LabAgent $agent, array $result): array
    {
        $metadata = (array) ($agent?->modelVersion?->metadata ?? []);
        $declared = (array) data_get($metadata, 'control_pair_contract', []);
        $sameSnapshot = (bool) data_get($result, 'mutation_observability.control_relative.same_snapshot', data_get($result, 'same_snapshot', false));
        $sameExecution = (bool) data_get($result, 'mutation_observability.control_relative.same_execution_contract', data_get($result, 'same_execution_contract', false));
        $available = data_get($result, 'mutation_observability.control_relative.control_agent_id') !== null
            || (bool) data_get($result, 'control_pair_available', false);

        return [
            'protocol' => 'frozen_control_pair_v1',
            'status' => $available && ($sameSnapshot || data_get($declared, 'same_generation') === true) && ($sameExecution || data_get($declared, 'same_execution_contract') === true) ? 'available' : 'missing_or_unverified',
            'same_generation' => (bool) data_get($declared, 'same_generation', false),
            'same_snapshot' => $sameSnapshot,
            'same_execution_contract' => $sameExecution,
            'control_agent_id' => data_get($result, 'mutation_observability.control_relative.control_agent_id'),
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function windowEvidence(array $result): array
    {
        $windows = collect((array) data_get($result, 'market_adaptive_replay.checkpoint_windows', data_get($result, 'market_adaptive_replay.monthly_walk_forward.windows', [])));
        $positive = $windows->filter(function ($window): bool {
            $trades = (int) data_get($window, 'trades', data_get($window, 'summary.trades', 0));
            $pf = data_get($window, 'profit_factor', data_get($window, 'net_pf', data_get($window, 'summary.net_pf')));
            return $trades >= 10 && is_numeric($pf) && (float) $pf >= 1.30 && (float) data_get($window, 'net_profit_percent', 1) > 0;
        })->count();

        return [
            'protocol' => 'independent_chronological_windows_v1',
            'observed_windows' => $windows->count(),
            'positive_windows' => $positive,
            'required_windows' => self::REQUIRED_WINDOWS,
            'minimum_positive_windows' => self::MINIMUM_POSITIVE_WINDOWS,
            'status' => $windows->count() >= self::REQUIRED_WINDOWS && $positive >= self::MINIMUM_POSITIVE_WINDOWS ? 'passed' : 'insufficient',
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function hypothesis(string $target, string $stage, string $gene, array $signature, array $result): array
    {
        $statement = data_get($signature, 'falsifiable_statement', data_get($result, 'hypothesis_contract.falsifiable_statement'));
        if (! filled($statement)) {
            $statement = match ($stage) {
                'regime_transition' => 'Transition-state classification must reduce false transition entries without increasing missed profitable opportunities beyond the declared bound.',
                'entry' => 'The entry construction must change the accepted signal set in the declared state and improve the target relative to its frozen control.',
                'exit' => 'The exit state machine must reduce the declared loss mode while preserving the parent/control risk invariants.',
                'regime_classification' => 'The regime classifier must route the declared market state differently and improve state-specific survival.',
                default => 'The declared decision surface must change measurably and improve the named target against its frozen control.',
            };
        }
        return [
            'falsifiable_statement' => $statement,
            'decision_surface' => $stage,
            'gene' => $gene !== '' ? $gene : null,
            'expected_behavioral_delta' => data_get($signature, 'expected_behavioral_delta', 'decision/trade/abstention ledger must change in the declared surface'),
            'non_target_invariants' => data_get($signature, 'non_target_invariants', ['no_unbounded_drawdown', 'no_cost_regression', 'no_forbidden_gate_bypass']),
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function behavioralDelta(array $result, ?array $targetDelta, string $stage): array
    {
        $observability = (array) data_get($result, 'mutation_observability', []);
        $delta = $targetDelta ?: (array) data_get($observability, 'behavioral_delta', data_get($result, 'behavioral_delta', []));
        $changed = (bool) data_get($observability, 'observable_effect', data_get($delta, 'observable_effect', false));
        return [
            'protocol' => 'behavioral_delta_contract_v1',
            'status' => $changed ? 'observed' : 'not_observed',
            'decision_surface' => $stage,
            'target_delta' => $delta,
            'observable_effect' => $changed,
            'trade_ledger_changed' => (bool) data_get($delta, 'trade_ledger_changed', data_get($result, 'trade_ledger_changed', false)),
            'decision_ledger_changed' => (bool) data_get($delta, 'decision_ledger_changed', data_get($result, 'decision_ledger_changed', false)),
            'abstention_ledger_changed' => (bool) data_get($delta, 'abstention_ledger_changed', data_get($result, 'abstention_ledger_changed', false)),
            'promotion_evidence' => false,
        ];
    }

    private function reusableLesson(array $control, array $counterfactual, array $windows, array $behavior, array $prediction): array
    {
        $ready = data_get($control, 'status') === 'available'
            && data_get($counterfactual, 'status') === 'assessed'
            && data_get($windows, 'status') === 'passed'
            && data_get($behavior, 'status') === 'observed'
            && data_get($prediction, 'status') === 'declared';
        return [
            'status' => $ready ? 'reusable' : 'diagnostic_only',
            'reason' => $ready ? 'all_causal_artifacts_present' : 'missing_causal_artifact',
            'mutation_credit_allowed' => false,
            'parent_eligible' => false,
            'promotion_evidence' => false,
        ];
    }

    private function decisionStage(string $target, array $signature, array $result): string
    {
        $text = strtolower(implode('|', array_filter([
            $target, (string) data_get($signature, 'failure_reason'), (string) data_get($result, 'failure_reason'),
        ])));
        if (str_contains($text, 'temporal') || str_contains($text, 'calendar') || str_contains($text, 'transition') || data_get($signature, 'state.transition_state') !== 'unknown') return 'regime_transition';
        if (str_contains($text, 'exit') || str_contains($text, 'drawdown') || str_contains($text, 'risk')) return 'exit';
        if (str_contains($text, 'regime')) return 'regime_classification';
        if (str_contains($text, 'entry') || str_contains($text, 'signal')) return 'entry';
        return 'decision_surface';
    }

    private function axisForGene(string $gene): string
    {
        $gene = strtolower($gene);
        if (in_array($gene, self::SCALAR_GENES, true)) return str_contains($gene, 'threshold') || str_contains($gene, 'spread') ? 'scalar_threshold' : 'scalar_wait';
        if (str_contains($gene, 'regime') || str_contains($gene, 'classifier')) return 'regime_classification';
        if (str_contains($gene, 'entry') || str_contains($gene, 'signal') || str_contains($gene, 'topology')) return 'signal_construction';
        if (str_contains($gene, 'exit') || str_contains($gene, 'stop') || str_contains($gene, 'target')) return 'entry_exit_state';
        return 'other';
    }

    private function normalizeForHash(mixed $value): mixed
    {
        if (! is_array($value)) return is_scalar($value) || $value === null ? $value : (string) $value;
        if (array_is_list($value)) return array_values(array_map(fn ($item) => $this->normalizeForHash($item), $value));
        ksort($value);
        return array_map(fn ($item) => $this->normalizeForHash($item), $value);
    }

    private function bounded(mixed $value): float
    {
        return max(0.0, min(1.0, is_numeric($value) ? (float) $value : 0.5));
    }
}
