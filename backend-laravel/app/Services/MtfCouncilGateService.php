<?php

namespace App\Services;

/**
 * Read-only admission gate for the combined XAUUSD MTF council proxy.
 *
 * Council members are hypotheses, not paper candidates. This gate only
 * decides whether the combined route has earned the next diagnostic stage;
 * it never creates a passport, changes a lifecycle status, or promotes a
 * member.
 */
class MtfCouncilGateService
{
    public const PROTOCOL = 'xauusd_mtf_council_combined_gate_v1';

    /**
     * @param array<string, mixed> $council
     * @param array<string, mixed> $control
     * @param list<array<string, mixed>> $members
     * @return array<string, mixed>
     */
    public function evaluate(array $council, array $control, array $members): array
    {
        $trades = (int) ($council['total_trades'] ?? 0);
        $profitFactor = (float) ($council['profit_factor'] ?? 0);
        $drawdown = (float) ($council['max_drawdown_percent'] ?? 100);
        $controlProfitFactor = (float) ($control['profit_factor'] ?? 0);
        $controlDrawdown = (float) ($control['max_drawdown_percent'] ?? 100);
        $minimumTrades = max(30, (int) config('services.lab_selection.minimum_screening_trades', 10));

        $reasons = [];
        if (count($members) < 2) {
            $reasons[] = 'combined_proxy_requires_two_members';
        }
        if ($trades < $minimumTrades) {
            $reasons[] = 'combined_proxy_low_sample';
        }
        if ($control === []) {
            $reasons[] = 'frozen_control_missing';
        }

        // A council must improve the reference MTF lane on PF, or preserve
        // PF while lowering drawdown. A small-sample PF spike is never enough.
        $economicImprovement = $control !== [] && (
            $profitFactor > $controlProfitFactor
            || ($drawdown < $controlDrawdown && $profitFactor >= $controlProfitFactor)
        );
        if (! $economicImprovement) {
            $reasons[] = 'no_combined_gate_improvement_vs_frozen_control';
        }

        $portfolioEvidence = (array) ($council['portfolio_evidence'] ?? []);
        $portfolioStatus = (string) ($portfolioEvidence['status'] ?? '');
        if ($portfolioStatus !== '' && in_array($portfolioStatus, ['failed', 'invalid', 'error'], true)) {
            $reasons[] = 'combined_portfolio_evidence_invalid';
        }

        $passed = $reasons === [];
        $shadowSandbox = app(MtfShadowCouncilSandboxService::class)->contract($members, [
            'control_present' => $control !== [],
            'control_protocol' => $control['protocol'] ?? null,
            'combined_metrics_observed' => $council !== [],
        ]);
        // Gate evaluation remains read-only. The research command explicitly
        // persists the ablation plan; a diagnostic gate must not create rows.
        $councilAblation = app(CouncilAblationService::class)->contract($members, [
            'symbol' => data_get($council, 'symbol', 'XAUUSD'),
            'timeframe' => data_get($council, 'timeframe', 'H1'),
            'data_hash' => data_get($council, 'data_hash', data_get($control, 'data_hash')),
            'execution_hash' => data_get($council, 'execution_hash', data_get($control, 'execution_hash')),
        ]);

        return [
            'protocol' => self::PROTOCOL,
            'status' => $passed ? 'passed' : 'deferred',
            'passed' => $passed,
            'target_gate' => 'council_combined_control_advantage',
            'reason_codes' => $reasons,
            'metrics' => [
                'member_count' => count($members),
                'total_trades' => $trades,
                'minimum_trades' => $minimumTrades,
                'profit_factor' => $profitFactor,
                'max_drawdown_percent' => $drawdown,
                'control_profit_factor' => $controlProfitFactor,
                'control_max_drawdown_percent' => $controlDrawdown,
                'economic_improvement' => $economicImprovement,
            ],
            'next_stage' => $passed
                ? 'cost_exit_stress_then_independent_forward_review'
                : 'keep_combined_council_research_only',
            'shadow_sandbox' => $shadowSandbox,
            'council_ablation' => $councilAblation,
            'official_proxy_requires_council_ablation' => (bool) config('services.lab_selection.council_ablation_required_before_official', true),
            'promotion_evidence' => false,
            'replacement_authorized' => false,
        ];
    }
}
