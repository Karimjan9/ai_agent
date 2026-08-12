<?php

namespace App\Services;

use App\Models\ModelMarketPerformance;

/**
 * Bounded, falsifiable MTF strategy hypotheses for the XAUUSD lighthouse.
 *
 * This catalog is deliberately small and explicit. It changes one declared
 * routing or specialist idea at a time; it is not a second random generator
 * and it never authorizes promotion by itself.
 */
class MtfStrategyResearchService
{
    public const PROTOCOL = 'xauusd_mtf_strategy_research_v2';

    public function __construct(private StrategyParameterSchemaService $schemas) {}

    /** @return list<array<string, mixed>> */
    public function catalog(): array
    {
        return [
            [
                'key' => 'regime_ensemble_router_v1',
                'label' => 'Frozen regime specialist ensemble',
                'strategy' => 'regime_ensemble_v1',
                'family' => 'regime_ensemble',
                'mutation_class' => 'regime_ownership',
                'target_gate' => 'regime_coverage_and_drawdown',
                'hypothesis' => 'Each closed H1 regime gets exactly one M15 specialist; unknown and transition stay WAIT.',
                'parameter_overrides' => [
                    'trend_down_strength_min' => 28.0,
                    'trend_down_pullback_atr_fraction' => 0.60,
                    'trend_down_risk_multiplier' => 0.50,
                    'adx_max' => 20.0,
                    'deviation' => 2.0,
                ],
            ],
            [
                'key' => 'regime_consensus_router_v1',
                'label' => 'Unknown-regime two-vote consensus',
                'strategy' => 'regime_consensus_v1',
                'family' => 'hybrid',
                'mutation_class' => 'evidence_abstention',
                'target_gate' => 'false_positive_control',
                'hypothesis' => 'Keep regime-owned entries, but require two independent specialists in unknown context.',
                'parameter_overrides' => [
                    'minimum_confidence' => 1.0,
                    'high_volatility_wait' => true,
                ],
            ],
            [
                'key' => 'hybrid_breakout_dominant_v1',
                'label' => 'Breakout-dominant hybrid',
                'strategy' => 'hybrid_v1',
                'family' => 'hybrid',
                'mutation_class' => 'entry_quality',
                'target_gate' => 'forward_profit_factor',
                'hypothesis' => 'The breakout candidate showed standalone alpha; give expansion entries more weight without removing H1 veto.',
                'parameter_overrides' => [
                    'trend_weight' => 1.0,
                    'breakout_weight' => 1.40,
                    'mean_reversion_weight' => 0.60,
                    'minimum_confidence' => 1.0,
                    'high_volatility_wait' => true,
                ],
            ],
            [
                'key' => 'hybrid_defensive_range_v1',
                'label' => 'Defensive range hybrid',
                'strategy' => 'hybrid_v1',
                'family' => 'hybrid',
                'mutation_class' => 'risk_and_exit_topology',
                'target_gate' => 'stress_drawdown',
                'hypothesis' => 'Range reversion should carry the low-volatility lane while high-volatility and transition remain abstention states.',
                'parameter_overrides' => [
                    'trend_weight' => 0.80,
                    'breakout_weight' => 0.80,
                    'mean_reversion_weight' => 1.40,
                    'minimum_confidence' => 1.20,
                    'range_low_volatility_only' => true,
                    'high_volatility_wait' => true,
                ],
            ],
            [
                'key' => 'differential_trend_up_v2',
                'label' => 'Trend-up one-lane rescue',
                'strategy' => 'differential_router_v1',
                'family' => 'differential_router',
                'mutation_class' => 'directional_specialist',
                'target_gate' => 'trend_up_stability',
                'hypothesis' => 'Change only the trend-up specialist; parent hybrid behavior remains byte-for-byte conceptually unchanged elsewhere.',
                'parameter_overrides' => [
                    'differential_target_regime' => 'trend_up',
                    'differential_router_version' => 'v2',
                    'trend_up_roc_period' => 12,
                    'trend_up_roc_threshold' => 0.20,
                    'trend_up_ema_period' => 50,
                ],
            ],
            [
                'key' => 'differential_range_reentry_v1',
                'label' => 'Range re-entry one-lane rescue',
                'strategy' => 'differential_router_v1',
                'family' => 'differential_router',
                'mutation_class' => 'range_specialist',
                'target_gate' => 'range_profit_factor',
                'hypothesis' => 'If the trend rescue is not the right failure lane, change only range topology to a falsifiable re-entry specialist.',
                'parameter_overrides' => [
                    'differential_target_regime' => 'range',
                    'differential_router_version' => 'v2',
                    'range_lookback' => 20,
                    'range_deviation' => 2.0,
                    'range_adx_max' => 20.0,
                    'range_low_volatility_only' => false,
                    'range_reentry_required' => true,
                    'range_signal_mode' => 'reentry',
                ],
            ],
            [
                'key' => 'volatility_managed_risk_v1',
                'label' => 'Volatility-managed risk envelope',
                'strategy' => 'hybrid_v1',
                'family' => 'hybrid',
                'mutation_class' => 'volatility_risk_management',
                'target_gate' => 'stress_drawdown',
                'hypothesis' => 'Keep the H1 veto and M15 entry topology fixed, but reduce exposure in the high-volatility tail; the edge should survive costs with less drawdown.',
                'parameter_overrides' => [
                    'high_volatility_risk_multiplier' => 0.35,
                    'avoid_high_volatility' => false,
                ],
                'evidence_basis' => [
                    'source' => 'Moreira & Muir (2017), Volatility-Managed Portfolios',
                    'claim' => 'Volatility timing can improve risk-adjusted performance when exposure is reduced during high realized volatility.',
                    'translation' => 'One execution-risk gene only; no signal or gate mutation.',
                    'source_url' => 'https://doi.org/10.1111/jofi.12513',
                    'caveat' => 'The paper is broad asset-pricing evidence, not proof of an XAUUSD M15 edge; this run is falsification only.',
                ],
            ],
            [
                'key' => 'trend_up_momentum_crash_firewall_v1',
                'label' => 'Trend-up momentum crash firewall',
                'strategy' => 'differential_router_v1',
                'family' => 'differential_router',
                'mutation_class' => 'directional_risk_defense',
                'target_gate' => 'trend_up_stability',
                'hypothesis' => 'Preserve the parent outside trend-up, but reduce trend-up exposure during volatility/regime transitions to test whether apparent alpha is crash-sensitive.',
                'parameter_overrides' => [
                    'differential_target_regime' => 'trend_up',
                    'differential_router_version' => 'v2',
                    'high_volatility_risk_multiplier' => 0.35,
                    'transition_firewall_enabled' => true,
                    'transition_wait_candles' => 2,
                ],
                'evidence_basis' => [
                    'source' => 'Daniel & Moskowitz (2016), Momentum Crashes',
                    'claim' => 'Momentum losses can cluster around sharp market rebounds and changing market conditions.',
                    'translation' => 'Test a bounded trend-up defense while keeping non-target parent behavior frozen.',
                    'source_url' => 'https://www.kentdaniel.net/papers/published/jfe_16.pdf',
                    'caveat' => 'A trend-up result with higher drawdown is a failed defense, not permission to relax the H1 veto.',
                ],
            ],
            [
                'key' => 'trend_up_risk_budget_v1',
                'label' => 'Trend-up volatility risk budget',
                'strategy' => 'differential_router_v1',
                'family' => 'differential_router',
                'mutation_class' => 'directional_risk_defense',
                'target_gate' => 'trend_up_stability',
                'hypothesis' => 'The trend-up differential lane retains entry alpha when its position risk is capped, reducing drawdown without changing H1 permission or non-target signals.',
                'parameter_overrides' => [
                    'differential_target_regime' => 'trend_up',
                    'differential_router_version' => 'v2',
                    'trend_up_risk_multiplier' => 0.75,
                ],
                'evidence_basis' => [
                    'source' => 'Moreira & Muir (2017), Volatility-Managed Portfolios',
                    'claim' => 'Reducing exposure when volatility risk is elevated can improve risk-adjusted outcomes without changing the underlying signal.',
                    'translation' => 'Apply one fixed risk budget only to the previously observed trend-up child lane.',
                    'source_url' => 'https://doi.org/10.1111/jofi.12513',
                    'caveat' => 'This is a bounded XAUUSD hypothesis; lower drawdown is not sufficient if PF or forward stability collapses.',
                ],
            ],
            [
                'key' => 'gold_session_liquidity_router_v1',
                'label' => 'Gold session-liquidity window',
                'strategy' => 'differential_router_v1',
                'family' => 'differential_router',
                'mutation_class' => 'temporal_session_filter',
                'target_gate' => 'monthly_survival',
                'hypothesis' => 'Gold M15 entries may be more stable in the declared UTC liquid/informed session; filter entry timing without changing the H1 permission rule.',
                'parameter_overrides' => [
                    'differential_target_regime' => 'trend_up',
                    'differential_router_version' => 'v2',
                    'differential_target_session_filter_enabled' => true,
                    'differential_target_session_start' => 7,
                    'differential_target_session_end' => 16,
                ],
                'evidence_basis' => [
                    'source' => 'Iwatsubo, Watkins & Xu (2018), Intraday Seasonality in Efficiency, Liquidity, Volatility, and Volume: Platinum and Gold Futures',
                    'claim' => 'Gold market microstructure differs across Tokyo, London, and New York sessions, with informed activity concentrated differently by venue/session.',
                    'translation' => 'Use one fixed UTC timing window as a shadow entry filter; no adaptive calendar learning.',
                    'source_url' => 'https://doi.org/10.1016/j.jcomm.2018.05.001',
                    'caveat' => 'Spot XAUUSD feed/session boundaries may differ from gold futures; the same-data replay must decide whether this transfers.',
                ],
            ],
            [
                'key' => 'transition_persistence_firewall_v1',
                'label' => 'Regime-transition persistence firewall',
                'strategy' => 'hybrid_v1',
                'family' => 'hybrid',
                'mutation_class' => 'transition_abstention',
                'target_gate' => 'stress_drawdown',
                'hypothesis' => 'A closed H1 regime change should earn a short M15 confirmation window; abstaining during the transition may reduce false entries and cost stress.',
                'parameter_overrides' => [
                    'transition_firewall_enabled' => true,
                    'transition_wait_candles' => 3,
                    'high_volatility_risk_multiplier' => 0.45,
                ],
                'evidence_basis' => [
                    'source' => 'Hamilton (1989), A New Approach to the Economic Analysis of Nonstationary Time Series and the Business Cycle',
                    'claim' => 'Regime changes can be treated as latent state transitions rather than as stationary continuation.',
                    'translation' => 'Keep the existing closed-candle transition detector and test only a bounded wait/risk policy.',
                    'source_url' => 'https://doi.org/10.2307/1912559',
                    'caveat' => 'The source establishes a regime-switching framework, not a trading rule; this is an engineering hypothesis.',
                ],
            ],
            ...$this->councilCatalog(),
        ];
    }

    /**
     * Role-complete council seats. Each seat owns one regime/volatility cell
     * and one bounded mutation. These are intentionally appended after the
     * general MTF catalog so the ordinary four-hypothesis budget remains
     * unchanged unless a caller explicitly requests the council keys.
     *
     * @return list<array<string, mixed>>
     */
    public function councilCatalog(): array
    {
        return [
            [
                'key' => 'council_trend_up_specialist_v1',
                'label' => 'Council trend-up momentum/crash specialist',
                'strategy' => 'differential_router_v1',
                'family' => 'differential_router',
                'mutation_class' => 'council_directional_specialist',
                'target_gate' => 'trend_up_stability',
                'council_role' => 'trend_up_specialist',
                'target_regime' => 'trend_up',
                'target_volatility' => 'high_volatility',
                'mutation' => [
                    'parameter' => 'trend_up_risk_multiplier',
                    'from' => 0.75,
                    'to' => 0.65,
                    'reason' => 'Tighten only the trend-up seat risk budget after the fresh snapshot raised drawdown.',
                ],
                'hypothesis' => 'Give the trend-up/high-volatility council seat its own momentum entry and crash-defense budget while all non-trend-up lanes remain frozen.',
                'parameter_overrides' => [
                    'differential_target_regime' => 'trend_up',
                    'differential_router_version' => 'v2',
                    'trend_up_roc_period' => 12,
                    'trend_up_roc_threshold' => 0.20,
                    'trend_up_ema_period' => 50,
                    'trend_up_risk_multiplier' => 0.75,
                    'high_volatility_risk_multiplier' => 0.35,
                    'transition_firewall_enabled' => true,
                    'transition_wait_candles' => 2,
                ],
                'evidence_basis' => [
                    'source' => 'Daniel & Moskowitz (2016), Momentum Crashes; Moreira & Muir (2017), Volatility-Managed Portfolios',
                    'claim' => 'Momentum exposure can be fragile around reversals; a bounded risk budget may reduce crash sensitivity.',
                    'translation' => 'One trend-up specialist owns high-volatility entries; no global gate or parent transfer.',
                    'source_url' => 'https://www.kentdaniel.net/papers/published/jfe_16.pdf',
                    'caveat' => 'The seat remains shadow-only until its own forward passport and the combined council replay pass.',
                ],
            ],
            [
                'key' => 'council_trend_down_specialist_v1',
                'label' => 'Council trend-down pullback specialist',
                'strategy' => 'differential_router_v1',
                'family' => 'differential_router',
                'mutation_class' => 'council_directional_specialist',
                'target_gate' => 'trend_down_opportunity_recall',
                'council_role' => 'trend_down_specialist',
                'target_regime' => 'trend_down',
                'target_volatility' => 'normal_volatility',
                'mutation' => [
                    'parameter' => 'trend_down_pullback_atr_fraction',
                    'from' => 0.60,
                    'to' => 0.50,
                    'reason' => 'Require a shallower pullback to test opportunity recall without changing directional ownership.',
                ],
                'hypothesis' => 'A dedicated trend-down pullback seat can recover directional opportunities without importing the trend-up mutation or range exits.',
                'parameter_overrides' => [
                    'differential_target_regime' => 'trend_down',
                    'differential_router_version' => 'v2',
                    'trend_down_strength_min' => 28.0,
                    'trend_down_pullback_atr_fraction' => 0.60,
                    'trend_down_risk_multiplier' => 0.50,
                    'differential_target_min_signal_confidence' => 0.34,
                    'transition_firewall_enabled' => true,
                    'transition_wait_candles' => 2,
                ],
                'evidence_basis' => [
                    'source' => 'Moskowitz, Ooi & Pedersen (2012), Time Series Momentum',
                    'claim' => 'Directional persistence can be tested as a separate time-series momentum envelope.',
                    'translation' => 'Strength and pullback genes are owned only by the trend-down/normal-volatility seat.',
                    'source_url' => 'https://pages.stern.nyu.edu/~lpederse/papers/TimeSeriesMomentum.pdf',
                    'caveat' => 'The source is cross-asset evidence; XAUUSD M15 must independently validate it.',
                ],
            ],
            [
                'key' => 'council_range_specialist_v1',
                'label' => 'Council low-volatility range re-entry specialist',
                'strategy' => 'hybrid_v1',
                'family' => 'hybrid',
                'mutation_class' => 'council_range_specialist',
                'target_gate' => 'range_coverage_and_cost_survival',
                'council_role' => 'range_specialist',
                'target_regime' => 'range',
                'target_volatility' => 'low_volatility',
                'hypothesis' => 'A low-volatility range seat with re-entry confirmation can complement directional seats while refusing expansion conditions.',
                'parameter_overrides' => [
                    'trend_weight' => 0.50,
                    'breakout_weight' => 0.50,
                    'mean_reversion_weight' => 1.20,
                    'minimum_confidence' => 1.20,
                    'high_volatility_wait' => true,
                    'range_lookback' => 20,
                    'range_deviation' => 2.0,
                    'range_adx_max' => 20.0,
                    'range_low_volatility_only' => true,
                    'range_reentry_required' => true,
                    'range_signal_mode' => 'reentry',
                    'transition_firewall_enabled' => true,
                    'transition_wait_candles' => 3,
                ],
                'mutation' => [
                    'parameter' => 'range_deviation',
                    'from' => 2.0,
                    'to' => 1.80,
                    'reason' => 'Test a slightly earlier low-volatility re-entry while keeping range ownership and exits fixed.',
                ],
                'evidence_basis' => [
                    'source' => 'Band-pass mean-reversion and volatility-regime separation (engineering hypothesis)',
                    'claim' => 'Range re-entry should be isolated from trend and high-volatility expansion rather than voted globally.',
                    'translation' => 'Low-volatility ownership plus re-entry confirmation; no trend seat parameter reuse.',
                    'source_url' => 'https://doi.org/10.2307/1912559',
                    'caveat' => 'The regime-switching source motivates state separation; it does not prove this range rule.',
                ],
            ],
            [
                'key' => 'council_transition_risk_router_v1',
                'label' => 'Council transition/risk firewall router',
                'strategy' => 'hybrid_v1',
                'family' => 'hybrid',
                'mutation_class' => 'council_transition_risk',
                'target_gate' => 'transition_cost_and_drawdown',
                'council_role' => 'transition_risk_router',
                'target_regime' => 'trend_up',
                'target_volatility' => 'high_volatility',
                'hypothesis' => 'A risk-owned high-volatility transition seat should reduce exposure and abstain briefly after state changes; it must not rewrite another seat signal.',
                'parameter_overrides' => [
                    'trend_weight' => 0.70,
                    'breakout_weight' => 0.50,
                    'mean_reversion_weight' => 0.30,
                    'minimum_confidence' => 1.30,
                    'high_volatility_wait' => false,
                    'high_volatility_risk_multiplier' => 0.35,
                    'transition_firewall_enabled' => true,
                    'transition_wait_candles' => 3,
                    'weak_regime_wait_candles' => 4,
                ],
                'mutation' => [
                    'parameter' => 'transition_wait_candles',
                    'from' => 3,
                    'to' => 2,
                    'reason' => 'Shorten only the risk router confirmation window to test whether the current firewall over-abstains.',
                ],
                'evidence_basis' => [
                    'source' => 'Hamilton (1989), regime switching; Daniel & Moskowitz (2016), momentum crash risk',
                    'claim' => 'State changes and sharp reversals justify a separate risk/abstention owner.',
                    'translation' => 'Routing-only council seat with explicit high-volatility risk reduction and transition wait.',
                    'source_url' => 'https://doi.org/10.2307/1912559',
                    'caveat' => 'This seat cannot pass as a champion; it must prove complementary risk contribution in combined replay.',
                ],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    public function select(?string $hypothesis = null, int $limit = 4): array
    {
        $catalog = $this->catalog();
        if (filled($hypothesis)) {
            $catalog = array_values(array_filter($catalog, fn (array $item): bool => $item['key'] === $hypothesis));
        }

        return array_slice($catalog, 0, max(1, min(12, $limit)));
    }

    /** @return array<string, mixed> */
    public function parametersFor(ModelMarketPerformance $candidate, array $experiment): array
    {
        $strategy = (string) $experiment['strategy'];
        $schemaKeys = array_keys($this->schemas->schema($strategy));
        $source = (array) ($candidate->modelVersion?->parameters ?? []);
        // A candidate's frozen parameters may belong to a different family
        // (for example hybrid -> regime_ensemble). Passing those keys through
        // would make a valid research idea look like a Python validation
        // failure. Carry only genes declared by the hypothesis family, then
        // fill the remainder from that family's sealed defaults.
        $compatible = array_intersect_key($source, array_flip($schemaKeys));

        return [
            ...$this->schemas->defaults($strategy),
            ...$compatible,
            ...((array) ($experiment['parameter_overrides'] ?? [])),
        ];
    }

    /** @return array<string, mixed> */
    public function contract(array $experiment, string $symbol, int $candidateId, string $dataHash, string $parameterHash, string $executionHash, ?int $frozenControlRunId = null): array
    {
        return [
            'protocol' => self::PROTOCOL,
            'hypothesis_key' => $experiment['key'],
            'label' => $experiment['label'],
            'strategy_identity' => $experiment['strategy'],
            'strategy_family' => $experiment['family'],
            'mutation_class' => $experiment['mutation_class'],
            'target_gate' => $experiment['target_gate'],
            'hypothesis' => $experiment['hypothesis'],
            'evidence_basis' => (array) ($experiment['evidence_basis'] ?? []),
            'symbol' => $symbol,
            'regime_timeframe' => 'H1',
            'entry_timeframe' => 'M15',
            'frozen_candidate_id' => $candidateId,
            'data_hash' => $dataHash,
            'parameter_hash' => $parameterHash,
            'execution_hash' => $executionHash,
            'frozen_control_run_id' => $frozenControlRunId,
            'frozen_control_available' => $frozenControlRunId !== null,
            'genetic_parent_transfer' => false,
            'same_data_contract' => true,
            'same_execution_contract' => true,
            'promotion_evidence' => false,
            'rule' => 'One hypothesis per run; no gate relaxation, paper promotion, or auto-learning from shadow outcomes.',
        ];
    }
}
