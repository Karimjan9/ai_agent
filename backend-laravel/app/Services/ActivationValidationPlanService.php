<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Preregisters a future validation interval without treating paper candles or
 * an unobserved dataset as research evidence. The interval alone is not an
 * executable replay admission.
 */
class ActivationValidationPlanService
{
    public const PROTOCOL = 'activation_future_research_window_v2';

    public const LEGACY_PROTOCOL = 'activation_future_research_window_v1';

    /** @return array<string, mixed> */
    public function reserve(array $proposal): array
    {
        return $this->build($proposal, true);
    }

    private function build(array $proposal, bool $modern): array
    {
        $paper = (array) data_get(app(ResearchPaperEpochContractService::class)->contract(), 'paper_epoch', []);
        $paperEnd = CarbonImmutable::parse((string) ($paper['end_exclusive'] ?? ''), 'UTC')->utc();
        $start = $paperEnd;
        $end = $start->addMonths(6);
        $epochs = app(ResearchPaperEpochContractService::class);
        $identity = [
            'protocol' => $modern ? self::PROTOCOL : self::LEGACY_PROTOCOL,
            'hypothesis_key' => (string) ($proposal['hypothesis_key'] ?? ''),
            'source_data_hash' => (string) ($proposal['source_data_hash'] ?? ''),
            'source_response_hash' => (string) ($proposal['source_response_hash'] ?? ''),
            'source_execution_hash' => (string) ($proposal['source_execution_hash'] ?? ''),
            'source_mtf_bundle_hash' => (string) ($proposal['source_mtf_bundle_hash'] ?? ''),
            'paper_epoch_end_exclusive' => $paperEnd->toIso8601String(),
            'validation_start_inclusive' => $start->toIso8601String(),
            'validation_end_exclusive' => $end->toIso8601String(),
            'window_count' => 6,
            'window_unit' => 'calendar_month',
            'execution_timeframe' => 'M5',
            'context_timeframes' => ['H4', 'H1', 'M15'],
            'minimum_trades_per_window' => (int) config('services.learning_lane.causal_minimum_trades_per_window', 8),
            'minimum_powered_windows' => (int) config('services.learning_lane.causal_minimum_powered_windows', 6),
            'minimum_positive_windows' => (int) config('services.learning_lane.causal_minimum_positive_windows', 4),
            'maximum_holding_bars' => (int) config('services.learning_lane.confirmation_maximum_holding_bars', 240),
            'exact_control_required' => true,
            'blinded_comparator_required' => true,
            'same_cost_and_risk_contract_required' => true,
            'paper_2026_eligible' => false,
            'research_epoch_authorization_required' => true,
            'same_evidence_replay_forbidden' => true,
            ...($modern ? ['validation_period_disjoint_from_paper' => $epochs->researchIntervalDisjointFromPaper(
                $start->toIso8601String(), $end->toIso8601String(),
            ),
            'confirmation_route' => $epochs->confirmationRoute('activation_independent_validation', [
                'hypothesis_key' => (string) ($proposal['hypothesis_key'] ?? ''),
                'source_data_hash' => (string) ($proposal['source_data_hash'] ?? ''),
                'source_response_hash' => (string) ($proposal['source_response_hash'] ?? ''),
                'source_execution_hash' => (string) ($proposal['source_execution_hash'] ?? ''),
                'source_mtf_bundle_hash' => (string) ($proposal['source_mtf_bundle_hash'] ?? ''),
            ])] : []),
        ];

        return [
            ...$identity,
            'plan_hash' => $this->hash($identity),
            'status' => 'reserved_awaiting_authorized_research_epoch',
            'executable' => false,
            ...($modern ? ['dependency_status' => 'BLOCKED_DEPENDENCY',
            'reason_code' => $identity['validation_period_disjoint_from_paper']
                ? 'NO_PREREGISTERED_UNUSED_AUTHORIZED_WINDOW'
                : 'RESEARCH_VALIDATION_OVERLAPS_PAPER_EPOCH'] : []),
            'promotion_evidence' => false,
        ];
    }

    public function valid(array $plan, array $proposal): bool
    {
        if (! in_array((string) ($plan['protocol'] ?? ''), [self::PROTOCOL, self::LEGACY_PROTOCOL], true)
            || (string) ($plan['status'] ?? '') !== 'reserved_awaiting_authorized_research_epoch'
            || ($plan['executable'] ?? null) !== false
            || ($plan['paper_2026_eligible'] ?? null) !== false
            || ($plan['research_epoch_authorization_required'] ?? null) !== true) {
            return false;
        }

        // Historical v1 plans remain reservations only. Revalidate their
        // original bytes; do not backfill a modern route or make them executable.
        return $plan === $this->build($proposal, ($plan['protocol'] ?? '') === self::PROTOCOL);
    }

    private function hash(array $identity): string
    {
        return hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }
}
