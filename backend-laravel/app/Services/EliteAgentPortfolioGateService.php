<?php

namespace App\Services;

use App\Models\AgentSkillAtlasEntry;
use App\Models\CandidateGateDecision;
use App\Models\EliteAgentPortfolio;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Models\PaperTradingEvaluation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds and certifies complementary specialist portfolios.
 *
 * This service is intentionally stricter than the individual candidate path:
 * a portfolio cannot rescue a failed member and cannot route live/paper risk
 * until its own combined canonical replay has passed.
 */
class EliteAgentPortfolioGateService
{
    private const PORTFOLIO_KEY = 'elite-regime-portfolio-v2';

    public function __construct(
        private StrategyParameterSchemaService $schemas,
        private AgentKnowledgeService $knowledge,
        private CandidateGateDecisionService $gateDecisions,
    ) {}

    public function syncMarket(string $symbol, string $timeframe, Collection $candidates): array
    {
        $eligible = $this->eligibleMembers($candidates->filter(fn (ModelMarketPerformance $candidate): bool =>
            $candidate->symbol === $symbol && $candidate->timeframe === $timeframe));

        if ($eligible->isEmpty()) {
            return ['status' => 'waiting_for_individual_forward', 'portfolio' => null, 'members' => []];
        }

        // A council is a staged protocol, not an accidental collection of
        // forward-valid models. If this candidate set contains declared
        // council members, require at least two distinct regime specialists
        // and one independently passed transition/risk router before any
        // combined replay can be created.
        $sequence = $this->councilSequence($eligible);
        if (! $sequence['ready']) {
            return [
                'status' => $sequence['status'],
                'portfolio' => null,
                'members' => [],
                'council_sequence' => $sequence,
            ];
        }

        $selected = $sequence['active']
            ? $this->selectCouncilMembers($eligible)
            : $this->selectComplementaryMembers($eligible);
        $portfolioKey = self::PORTFOLIO_KEY;
        $membershipHash = hash('sha256', json_encode([
            'portfolio_policy' => $this->portfolioPolicy(),
            'members' => $selected->map(fn (ModelMarketPerformance $candidate): array => [
                'id' => $candidate->id,
                'parameter_hash' => $this->parameterHash($candidate),
                'regime' => $this->targetRegime($candidate),
                'volatility' => $this->targetVolatility($candidate),
                'direction' => $this->targetDirection($candidate),
            ])->values()->all(),
        ], JSON_PRESERVE_ZERO_FRACTION));

        $portfolio = EliteAgentPortfolio::query()->firstOrCreate(
            ['symbol' => $symbol, 'timeframe' => $timeframe, 'portfolio_key' => $portfolioKey],
            ['status' => 'waiting', 'gate_status' => 'waiting_for_combined_replay']
        );
        $this->persistMembership($portfolio, $selected, $membershipHash, 'strict_forward_members');

        $portfolio->refresh()->load('members.performance.modelVersion');
        if ($selected->count() < 2 || $selected->pluck('strategy_family')->unique()->count() < 2
            || $selected->map(fn (ModelMarketPerformance $candidate): string => $this->targetRegime($candidate))->unique()->count() < 2) {
            $portfolio->update([
                'status' => 'waiting',
                'gate_status' => 'waiting_for_complementary_niches',
                'gate_reasons' => ['WAITING_FOR_TWO_INDEPENDENT_REGIME_SPECIALISTS'],
            ]);
        } elseif (data_get($portfolio->evidence, 'gate.status') !== 'passed') {
            $portfolio->update([
                'status' => 'waiting',
                'gate_status' => 'waiting_for_combined_replay',
                'gate_reasons' => ['WAITING_FOR_PORTFOLIO_REPLAY'],
            ]);
        }

        return [
            'status' => $portfolio->fresh()->gate_status,
            'portfolio' => $portfolio->fresh(['members.performance.modelVersion']),
            'members' => $selected->values(),
        ];
    }

    /**
     * Build a research-only portfolio from full-replayed niche members.
     *
     * The member contract is narrower than a standalone forward contract,
     * but the combined replay below keeps the unchanged global PF, stress,
     * temporal and calendar gates. A member can never become paper-eligible
     * through this lane by itself.
     */
    public function syncResearchMarket(string $symbol, string $timeframe, Collection $candidates): array
    {
        $eligible = $this->eligibleResearchMembers($candidates->filter(fn (ModelMarketPerformance $candidate): bool =>
            $candidate->symbol === $symbol && $candidate->timeframe === $timeframe));
        if ($eligible->isEmpty()) {
            return ['status' => 'waiting_for_portfolio_member_replay', 'portfolio' => null, 'members' => []];
        }

        // Research-only full replays cannot be used to bypass the same
        // specialist -> router order. They are useful evidence, but the
        // combined council remains blocked until the individual passports
        // are present for the declared specialist set.
        $sequence = $this->councilSequence($eligible);
        if (! $sequence['ready']) {
            return [
                'status' => $sequence['status'],
                'portfolio' => null,
                'members' => [],
                'council_sequence' => $sequence,
            ];
        }

        $selected = $sequence['active']
            ? $this->selectCouncilMembers($eligible)
            : $this->selectComplementaryMembers($eligible);
        if ($selected->count() < 2 || $selected->pluck('strategy_family')->unique()->count() < 2
            || $selected->map(fn (ModelMarketPerformance $candidate): string => $this->targetRegime($candidate))->unique()->count() < 2) {
            return ['status' => 'waiting_for_complementary_niches', 'portfolio' => null, 'members' => $selected];
        }

        $portfolioKey = self::PORTFOLIO_KEY;
        $membershipHash = hash('sha256', json_encode([
            'mode' => 'portfolio_member_research_v1',
            'portfolio_policy' => $this->portfolioPolicy(),
            'members' => $selected->map(fn (ModelMarketPerformance $candidate): array => [
                'id' => $candidate->id,
                'parameter_hash' => $this->parameterHash($candidate),
                'regime' => $this->targetRegime($candidate),
                'volatility' => $this->targetVolatility($candidate),
                'direction' => $this->targetDirection($candidate),
            ])->values()->all(),
        ], JSON_PRESERVE_ZERO_FRACTION));
        $portfolio = EliteAgentPortfolio::query()->firstOrCreate(
            ['symbol' => $symbol, 'timeframe' => $timeframe, 'portfolio_key' => $portfolioKey],
            ['status' => 'waiting', 'gate_status' => 'waiting_for_combined_replay']
        );
        $this->persistMembership($portfolio, $selected, $membershipHash, 'portfolio_member_research_v1');
        $portfolio->refresh()->load('members.performance.modelVersion');

        return [
            'status' => $portfolio->gate_status,
            'portfolio' => $portfolio,
            'members' => $selected->values(),
        ];
    }

    /**
     * Enforce the explicit agent-council order while leaving ordinary
     * non-council portfolio candidates on their existing path. This method
     * only runs after the caller has already applied the individual forward
     * passport; it never turns a failed member into evidence.
     */
    private function councilSequence(Collection $candidates): array
    {
        $council = $candidates->filter(fn (ModelMarketPerformance $candidate): bool =>
            data_get($candidate->modelVersion?->metadata, 'council_specialist_contract.protocol') === 'agent_council_v1'
        )->values();

        if ($council->isEmpty()) {
            return [
                'protocol' => 'agent_council_sequence_v1',
                'active' => false,
                'ready' => true,
                'status' => 'ordinary_portfolio_path',
                'specialist_regimes' => [],
                'specialist_count' => 0,
                'router_count' => 0,
                'rule' => 'Council ordering applies only to declared council members.',
            ];
        }

        $routerCount = $council->filter(fn (ModelMarketPerformance $candidate): bool =>
            $this->councilRole($candidate) === 'transition_risk_router'
        )->count();
        $specialistRegimes = $council
            ->filter(fn (ModelMarketPerformance $candidate): bool =>
                in_array($this->councilRole($candidate), ['trend_up_specialist', 'trend_down_specialist', 'range_specialist'], true)
            )
            ->map(fn (ModelMarketPerformance $candidate): string => $this->councilRegime($candidate))
            ->filter(fn (string $regime): bool => in_array($regime, ['trend_up', 'trend_down', 'range'], true))
            ->unique()->values()->all();

        if (count($specialistRegimes) < 2) {
            return [
                'protocol' => 'agent_council_sequence_v1',
                'active' => true,
                'ready' => false,
                'status' => 'waiting_for_two_specialist_passports',
                'specialist_regimes' => $specialistRegimes,
                'specialist_count' => count($specialistRegimes),
                'router_count' => $routerCount,
                'required' => ['specialist_regimes' => 2, 'transition_risk_router' => 1],
                'rule' => 'trend_up/trend_down/range standalone passports precede the router and combined replay.',
            ];
        }

        if ($routerCount < 1) {
            return [
                'protocol' => 'agent_council_sequence_v1',
                'active' => true,
                'ready' => false,
                'status' => 'waiting_for_transition_router_passport',
                'specialist_regimes' => $specialistRegimes,
                'specialist_count' => count($specialistRegimes),
                'router_count' => $routerCount,
                'required' => ['specialist_regimes' => 2, 'transition_risk_router' => 1],
                'rule' => 'The transition/risk router must pass individually before combined replay.',
            ];
        }

        return [
            'protocol' => 'agent_council_sequence_v1',
            'active' => true,
            'ready' => true,
            'status' => 'ready_for_combined_replay',
            'specialist_regimes' => $specialistRegimes,
            'specialist_count' => count($specialistRegimes),
            'router_count' => $routerCount,
            'required' => ['specialist_regimes' => 2, 'transition_risk_router' => 1],
            'rule' => 'Every member already passed its own unchanged individual passport.',
        ];
    }

    private function councilRole(ModelMarketPerformance $candidate): string
    {
        return (string) (
            data_get($candidate->modelVersion?->metadata, 'council_specialist_contract.role')
            ?: data_get($candidate->modelVersion?->metadata, 'portfolio_council_lane.specialist_role')
            ?: data_get($candidate->modelVersion?->metadata, 'portfolio_council_lane.role')
        );
    }

    private function councilRegime(ModelMarketPerformance $candidate): string
    {
        return (string) (
            data_get($candidate->modelVersion?->metadata, 'council_specialist_contract.owner_regime')
            ?: data_get($candidate->modelVersion?->metadata, 'portfolio_council_lane.regime')
            ?: data_get($candidate->modelVersion?->metadata, 'portfolio_research_contract.target_regime')
        );
    }

    /**
     * Select the actual council after its individual passports are present.
     * The router is not merely a prerequisite signal: it must be one of the
     * replay members so that the combined result represents specialist
     * routing, rather than a portfolio that happens to contain a router
     * somewhere in the eligible pool.
     */
    private function selectCouncilMembers(Collection $eligible): Collection
    {
        $specialists = $eligible->filter(fn (ModelMarketPerformance $candidate): bool =>
            in_array($this->councilRole($candidate), ['trend_up_specialist', 'trend_down_specialist', 'range_specialist'], true)
        )->values();
        $selected = $this->selectComplementaryMembers($specialists);

        // Keep the two-regime requirement explicit even if a future selector
        // changes its scoring/near-duplicate behavior.
        $selectedRegimes = $selected
            ->filter(fn (ModelMarketPerformance $candidate): bool =>
                in_array($this->councilRole($candidate), ['trend_up_specialist', 'trend_down_specialist', 'range_specialist'], true)
            )
            ->map(fn (ModelMarketPerformance $candidate): string => $this->councilRegime($candidate))
            ->filter()
            ->unique()
            ->values();
        if ($selectedRegimes->count() < 2) {
            foreach ($specialists->sortByDesc(fn (ModelMarketPerformance $candidate): float => $this->stateScore($candidate)) as $candidate) {
                $regime = $this->councilRegime($candidate);
                if ($regime === '' || $selected->contains(fn (ModelMarketPerformance $item): bool => $item->id === $candidate->id)) continue;
                $selected->push($candidate);
                $selectedRegimes = $selectedRegimes->push($regime)->unique()->values();
                if ($selectedRegimes->count() >= 2) break;
            }
        }

        $router = $eligible
            ->filter(fn (ModelMarketPerformance $candidate): bool =>
                $this->councilRole($candidate) === 'transition_risk_router'
            )
            ->sortByDesc(fn (ModelMarketPerformance $candidate): float => $this->stateScore($candidate))
            ->first();
        if ($router && ! $selected->contains(fn (ModelMarketPerformance $candidate): bool => $candidate->id === $router->id)) {
            $selected->push($router);
        }

        return $selected->values();
    }

    public function eligibleMembers(Collection $candidates): Collection
    {
        return $candidates->filter(function (ModelMarketPerformance $candidate): bool {
            if ((bool) data_get($candidate->metrics, 'portfolio_proxy', false)) return false;
            if (! in_array($candidate->status, ['forward_validated', 'paper'], true)
                || $candidate->evidence_status !== 'valid'
                || $candidate->modelVersion?->evidence_status !== 'valid') return false;
            if ((int) $candidate->sample_count < 30 || $this->profitFactor($candidate) < 1.3) return false;
            if ((float) data_get($candidate->metrics, 'pf_attribution.stress_cost.profit_factor', 0) < 1.05) return false;
            $forward = CandidateGateDecision::query()
                ->where('model_market_performance_id', $candidate->id)
                ->where('stage', 'statistical_forward_gate')->latest('evaluated_at')->first();
            return $forward?->decision === 'passed'
                && data_get($forward->metrics, 'elite_agent_passport.status') === 'passed';
        })->values();
    }

    /**
     * Combined-replay admission for a niche member.
     *
     * A council member is not allowed to rescue a failed standalone forward
     * passport. It must first pass the same individual statistical forward
     * gate; only its eventual champion/paper promotion remains portfolio-only.
     */
    public function eligibleResearchMembers(Collection $candidates): Collection
    {
        return $candidates->filter(function (ModelMarketPerformance $candidate): bool {
            // A mixed strategy may contain only specialists that already
            // passed their own unchanged forward passport. Near-miss members
            // remain learning evidence, never portfolio constituents.
            if ((bool) data_get($candidate->metrics, 'portfolio_proxy', false)
                || ! in_array($candidate->status, ['forward_validated', 'paper'], true)
                || $candidate->evidence_status !== 'valid'
                || $candidate->modelVersion?->evidence_status !== 'valid') return false;
            $contract = (array) data_get($candidate->modelVersion?->metadata, 'portfolio_research_contract', []);
            if (data_get($contract, 'protocol') !== 'portfolio_member_research_v1') return false;
            $forward = CandidateGateDecision::query()
                ->where('model_market_performance_id', $candidate->id)
                ->where('stage', 'statistical_forward_gate')
                ->latest('evaluated_at')
                ->first();
            if ($forward?->decision !== 'passed'
                || data_get($forward->metrics, 'elite_agent_passport.status') !== 'passed') return false;
            $globalTrades = (int) $candidate->sample_count;
            if ($globalTrades < 20
                || $this->profitFactor($candidate) < 1.3
                || (float) data_get($candidate->metrics, 'pf_attribution.stress_cost.profit_factor', 0) < 1.05) return false;
            $regime = (string) data_get($contract, 'target_regime', 'unproven');
            $volatility = (string) data_get($contract, 'target_volatility', 'any');
            $direction = (string) data_get($contract, 'target_direction', 'any');
            // The router does not execute a member on its global replay. It
            // executes it only inside the sealed regime x volatility lane.
            // A global/regime PF therefore cannot certify a portfolio member:
            // it would admit exactly the kind of April failure that the
            // combined replay exposed. New replays persist this intersection
            // under by_regime_volatility; legacy results fail closed until
            // refreshed under the richer evidence contract.
            $nicheKey = $regime.'|'.$volatility;
            $niche = $volatility !== '' && $volatility !== 'any'
                ? ($direction !== '' && $direction !== 'any'
                    ? (array) data_get($candidate->metrics, "pf_attribution.breakdown.by_regime_volatility_direction.{$nicheKey}.{$direction}", [])
                    : (array) data_get($candidate->metrics, "pf_attribution.breakdown.by_regime_volatility.{$nicheKey}", []))
                : (array) data_get($candidate->metrics, "pf_attribution.breakdown.by_regime.{$regime}", []);
            if ($volatility !== '' && $volatility !== 'any' && $niche === []) return false;
            // This is a research admission floor, not a promotion shortcut.
            // A 5-7 trade PF spike is too small to be a reusable council seat
            // and was the reason the old combined replay looked strong while
            // its member support was only 6/8/12/29 trades.  Require the same
            // ten-trade niche floor used by the selector; the combined
            // replay still faces every unchanged global gate afterwards.
            $nicheTrades = (int) data_get($niche, 'trades', 0);
            $nichePf = (float) data_get($niche, 'net_pf', 0);
            $normalNiche = $nicheTrades >= 10 && $nichePf >= 1.3;
            return $normalNiche
                && (float) data_get($candidate->metrics, 'monte_carlo.risk_of_ruin_percent', 0) <= 10;
        })->values();
    }

    public function ready(string $symbol, string $timeframe): ?EliteAgentPortfolio
    {
        $portfolio = EliteAgentPortfolio::query()->with('members.performance.modelVersion')
            ->where(compact('symbol', 'timeframe'))
            ->where('gate_status', 'passed')
            ->whereIn('status', ['forward_validated', 'paper'])
            ->latest('last_evaluated_at')->first();

        if (! $portfolio || ! $this->activePassport($portfolio)) return null;

        return $portfolio;
    }

    /**
     * A passed portfolio row is still only a projection. Re-check the proxy
     * forward ledger and every member passport at runtime so invalidation of
     * one specialist, a stale proxy, or a manually altered gate cannot leave
     * a previously-ready council executable.
     */
    private function activePassport(EliteAgentPortfolio $portfolio): bool
    {
        if (data_get($portfolio->evidence, 'gate.status') !== 'passed'
            || $portfolio->members->count() < 2
            || (int) $portfolio->member_count !== $portfolio->members->count()) {
            return false;
        }

        $proxyId = (int) data_get($portfolio->evidence, 'portfolio_performance_id', 0);
        $proxy = $proxyId > 0
            ? ModelMarketPerformance::with('modelVersion')->find($proxyId)
            : null;
        if (! $proxy
            || ! (bool) data_get($proxy->metrics, 'portfolio_proxy', false)
            || (int) data_get($proxy->metrics, 'elite_portfolio_id', 0) !== (int) $portfolio->id
            || $proxy->evidence_status !== 'valid'
            || $proxy->modelVersion?->evidence_status !== 'valid') {
            return false;
        }

        $proxyGate = CandidateGateDecision::query()
            ->where('model_market_performance_id', $proxy->id)
            ->where('stage', 'statistical_forward_gate')
            ->latest('evaluated_at')
            ->first();
        if ($proxyGate?->decision !== 'passed'
            || data_get($proxyGate->metrics, 'elite_agent_passport.status') !== 'passed') {
            return false;
        }

        foreach ($portfolio->members as $member) {
            $performance = $member->performance;
            if (! $performance
                || ! in_array((string) $performance->status, ['forward_validated', 'paper'], true)
                || $performance->evidence_status !== 'valid'
                || $performance->modelVersion?->evidence_status !== 'valid') {
                return false;
            }
            $decision = CandidateGateDecision::query()
                ->where('model_market_performance_id', $performance->id)
                ->where('stage', 'statistical_forward_gate')
                ->latest('evaluated_at')
                ->first();
            if ($decision?->decision !== 'passed'
                || data_get($decision->metrics, 'elite_agent_passport.status') !== 'passed') {
                return false;
            }
        }

        return true;
    }

    public function routeMembers(EliteAgentPortfolio $portfolio, string $regime, string $volatility): Collection
    {
        return $portfolio->members->filter(fn ($member): bool =>
            ($member->target_regime === null || $member->target_regime === $regime)
            && ($member->target_volatility === null || $member->target_volatility === $volatility));
    }

    /** Build the sealed request consumed by /api/portfolio/backtest. */
    public function memberSpecs(EliteAgentPortfolio $portfolio): array
    {
        return $portfolio->members->map(function ($member): array {
            $model = $member->performance?->modelVersion;
            return [
                'strategy' => $model?->strategy,
                'base_strategy' => $model?->strategy
                    ? $this->schemas->runtimeBaseStrategy($model->strategy, data_get($model?->metadata, 'base_strategy'), $member->performance?->strategy_family)
                    : null,
                'version' => $model?->version,
                'parameters' => $model?->parameters ?? [],
                // Stable attribution for portfolio diagnostics. This is an
                // identity key, never a performance label inferred in replay.
                'member_key' => $member->performance?->id ? 'performance:'.$member->performance->id : null,
                'role' => $member->role,
                'target_regime' => $member->target_regime,
                'target_volatility' => $member->target_volatility,
                'target_direction' => $member->target_direction,
            ];
        })->filter(fn (array $spec): bool => filled($spec['strategy']))->values()->all();
    }

    /**
     * Freeze the pre-replay candidate frontier for portfolio statistics.
     *
     * This is selection provenance, not promotion evidence: the combined
     * replay receives the frontier but cannot mutate it or use the sealed
     * holdout. Missing rows remain an evidence gap and are handled fail-closed
     * by the Python statistical lane.
     */
    public function selectionContext(Collection $candidates): array
    {
        $rows = [];
        $trialSharpes = [];
        $candidateIds = [];
        $firstCandidate = $candidates->first();
        $trialLedger = $firstCandidate
            ? app(LabTrialLedgerService::class)->selectionContext((string) $firstCandidate->symbol, (string) $firstCandidate->timeframe)
            : [];
        $windowIntervals = [];

        foreach ($candidates->sortBy('id')->values() as $candidate) {
            $metadata = (array) ($candidate->modelVersion?->metadata ?? []);
            // Do not mix legacy/old statistical contracts into the current
            // PBO/DSR trial universe.  Those rows remain in the audit, but
            // their checkpoint definitions are not comparable to protocol
            // v3 and would distort the benchmark against which a new council
            // is judged.  A portfolio proxy is also excluded: it is an
            // output of this very lane and must never become its own trial.
            if ((int) data_get($metadata, 'statistical_gate_version', 0) < 3) continue;
            // A two-trade row can have an extreme Sharpe purely from a tiny
            // denominator and would poison both PBO and DSR. It remains in
            // the historical audit, but not in the statistical trial
            // frontier. Ten trades is the unchanged screening evidence
            // floor; final portfolio promotion still requires its own 30+
            // trade and passport gates.
            if ((int) $candidate->sample_count < 10) continue;
            $metrics = (array) $candidate->metrics;
            if ((bool) data_get($metrics, 'portfolio_proxy', false)) continue;
            $scores = array_values(array_filter(
                (array) data_get($metrics, 'forward_window_scores', []),
                fn ($value): bool => is_numeric($value) && is_finite((float) $value),
            ));
            if (count($scores) < 4) {
                $scores = collect((array) data_get($metrics, 'market_adaptive_replay.checkpoint_windows', []))
                    ->map(fn ($window): mixed => data_get($window, 'score', data_get($window, 'forward_score')))
                    ->filter(fn ($value): bool => is_numeric($value) && is_finite((float) $value))
                    ->map(fn ($value): float => (float) $value)
                    ->values()->all();
            } else {
                $scores = array_map(fn ($value): float => (float) $value, $scores);
            }
            // PBO and DSR must share the same frozen trial universe. A
            // legacy candidate without four aligned checkpoints may remain
            // in the audit database, but it cannot contribute a Sharpe trial
            // to this portfolio frontier.
            if (count($scores) < 4) continue;
            $rows[] = $scores;
            $candidateIds[] = (int) $candidate->id;
            if ($windowIntervals === []) {
                $windowIntervals = collect((array) data_get($metrics, 'market_adaptive_replay.checkpoint_windows', []))
                    ->filter(fn ($window): bool => is_array($window)
                        && filled(data_get($window, 'start'))
                        && filled(data_get($window, 'end'))
                        && filled(data_get($window, 'label_start'))
                        && filled(data_get($window, 'label_end')))
                    ->map(fn (array $window): array => [
                        'start' => data_get($window, 'start'),
                        'end' => data_get($window, 'end'),
                        'label_start' => data_get($window, 'label_start'),
                        'label_end' => data_get($window, 'label_end'),
                    ])->values()->all();
            }

            $observedSharpe = data_get($metrics, 'statistical_evidence.deflated_sharpe.observed_sharpe');
            if (is_numeric($observedSharpe) && is_finite((float) $observedSharpe)) {
                $trialSharpes[] = (float) $observedSharpe;
                continue;
            }

            // Legacy full replays may contain the equity curve without the
            // post-selection DSR sidecar. Derive only the immutable observed
            // per-trade Sharpe; never infer a promotion verdict here.
            $equity = array_values(array_filter((array) data_get($metrics, 'equity_curve', []), 'is_numeric'));
            $returns = [];
            foreach (array_slice($equity, 1) as $index => $current) {
                $previous = (float) ($equity[$index] ?? 0);
                if ($previous > 0) $returns[] = ((float) $current / $previous) - 1;
            }
            if (count($returns) >= 2) {
                $mean = array_sum($returns) / count($returns);
                $variance = array_sum(array_map(fn (float $value): float => ($value - $mean) ** 2, $returns)) / count($returns);
                $deviation = sqrt($variance);
                if ($deviation > 0 && is_finite($deviation)) $trialSharpes[] = $mean / $deviation;
            }
        }

        return [
            'protocol' => 'portfolio_selection_frontier_v1',
            'candidate_ids' => $candidateIds,
            'candidate_count' => count($rows),
            'score_rows' => $rows,
            'trial_sharpes' => $trialSharpes,
            'trial_ledger' => $trialLedger,
            'trial_count' => (int) data_get($trialLedger, 'trial_count', count($rows)),
            'window_intervals' => count($windowIntervals) >= 4 ? $windowIntervals : [],
            // At least one observed bar is removed around each test fold so
            // holding-period labels cannot leak across the train/test edge.
            'purge_bars' => 1,
            'embargo_bars' => 1,
            'promotion_evidence' => false,
            'rule' => 'Frozen pre-replay candidate frontier; no holdout or same-window mutation.',
        ];
    }

    /** The portfolio owns a separate, sealed transition policy. */
    public function portfolioParameters(): array
    {
        return [
            'portfolio_policy_version' => 'transition_firewall_v1',
            // The transition firewall remains a candidate-level mutation
            // until a fresh independent replay proves it. Portfolio routing
            // must not promote an unconfirmed global veto policy.
            'transition_firewall_enabled' => false,
            'transition_wait_candles' => 2,
        ];
    }

    public function recordCombinedEvidence(EliteAgentPortfolio $portfolio, array $result): array
    {
        $reasons = [];
        $portfolio->loadMissing('members.performance.modelVersion');
        // Layer one of the portfolio gate: a combined PF may never conceal a
        // member that has not passed its own independent forward/passport
        // contract.  Research-only members remain useful diagnostics, but
        // cannot reach paper through the proxy.
        $memberGateFailures = $portfolio->members->filter(function ($member): bool {
            $performance = $member->performance;
            if (! $performance || ! in_array($performance->status, ['forward_validated', 'paper'], true)
                || $performance->evidence_status !== 'valid'
                || $performance->modelVersion?->evidence_status !== 'valid') return true;
            $decision = CandidateGateDecision::query()
                ->where('model_market_performance_id', $performance->id)
                ->where('stage', 'statistical_forward_gate')->latest('evaluated_at')->first();
            return $decision?->decision !== 'passed'
                || data_get($decision->metrics, 'elite_agent_passport.status') !== 'passed';
        });
        if ($memberGateFailures->isNotEmpty()) $reasons[] = 'FAILED_PORTFOLIO_MEMBER_INDIVIDUAL_GATE';
        if ((int) data_get($result, 'total_trades', 0) < 30) $reasons[] = 'FAILED_PORTFOLIO_TRADE_COUNT';
        if ((float) data_get($result, 'profit_factor', 0) < 1.3) $reasons[] = 'FAILED_PORTFOLIO_PROFIT_FACTOR';
        if ((float) data_get($result, 'pf_attribution.stress_cost.profit_factor', 0) < 1.05) $reasons[] = 'FAILED_PORTFOLIO_STRESS_COST';
        if ((bool) data_get($result, 'is_overfit', false)) $reasons[] = 'FAILED_PORTFOLIO_OVERFIT';
        if (data_get($result, 'selection_validation.status') === 'assessed'
            && (float) data_get($result, 'selection_validation.probability_of_backtest_overfitting', 0) > .5) {
            $reasons[] = 'FAILED_PORTFOLIO_OVERFIT';
        }
        if (data_get($result, 'statistical_evidence.deflated_sharpe.status') === 'assessed'
            && (float) data_get($result, 'statistical_evidence.deflated_sharpe.deflated_sharpe_probability', 0) < .95) {
            $reasons[] = 'FAILED_PORTFOLIO_OVERFIT';
        }
        $regimeRows = collect((array) data_get($result, 'regime_performance', []))
            ->filter(fn ($metrics): bool => (int) data_get($metrics, 'trades', 0) > 0);
        $regimePfs = $regimeRows->mapWithKeys(function ($metrics, $regime) use ($result): array {
            return [(string) $regime => $this->regimeProfitFactor($result, (string) $regime, (array) $metrics)];
        });
        if ($regimePfs->contains(fn ($pf): bool => $pf === null)) {
            $reasons[] = 'FAILED_PORTFOLIO_REGIME_EVIDENCE';
        }
        $worstRegime = $regimePfs->filter(fn ($pf): bool => $pf !== null)->min();
        if ($worstRegime !== null && $worstRegime < 1.0) $reasons[] = 'FAILED_PORTFOLIO_REGIME_COVERAGE';
        $qualifiedRegimePfs = $regimeRows->filter(fn ($metrics): bool => (int) data_get($metrics, 'trades', 0) >= 5)
            ->mapWithKeys(function ($metrics, $regime) use ($regimePfs): array {
                return [(string) $regime => $regimePfs->get((string) $regime)];
            });
        if ($qualifiedRegimePfs->count() < 2
            || $qualifiedRegimePfs->contains(fn ($pf): bool => $pf === null || $pf < 1.0)) {
            $reasons[] = 'FAILED_PORTFOLIO_REGIME_COVERAGE';
        }
        if (data_get($result, 'monthly_passport.status') !== 'consistent'
            || (int) data_get($result, 'monthly_passport.rolling_forward_wins', 0) < 3
            || (int) data_get($result, 'monthly_passport.failed_months', 0) > 0) {
            $reasons[] = 'FAILED_PORTFOLIO_CALENDAR_SURVIVAL';
        }
        $windows = (array) data_get($result, 'window_survival', []);
        if ((int) data_get($windows, 'positive_windows', 0) < 3 || (int) data_get($windows, 'catastrophic_windows', 0) > 0) {
            $reasons[] = 'FAILED_PORTFOLIO_TEMPORAL_SURVIVAL';
        }
        if ((float) data_get($result, 'portfolio_evidence.disagreement_rate', 1) > .10) {
            $reasons[] = 'FAILED_PORTFOLIO_ROUTER_DISAGREEMENT';
        }
        // The router has its own professional objective. It must be
        // calibrated and safe to abstain before a combined PF can be
        // considered; PF itself is deliberately not used to train this layer.
        if (data_get($result, 'router_evidence.status') !== 'assessed') {
            $reasons[] = 'FAILED_PORTFOLIO_ROUTER_CALIBRATION_EVIDENCE';
        }
        if ((float) data_get($result, 'router_evidence.abstention_precision', 0) < .50) {
            $reasons[] = 'FAILED_PORTFOLIO_ROUTER_ABSTENTION_PRECISION';
        }
        if (data_get($result, 'router_evidence.disagreement_wait_invariant') !== true) {
            $reasons[] = 'FAILED_PORTFOLIO_DISAGREEMENT_WAIT_INVARIANT';
        }
        if ((float) data_get($result, 'portfolio_evidence.loss_correlation.max_jaccard', 1) > .50) {
            $reasons[] = 'FAILED_PORTFOLIO_LOSS_CORRELATION';
        }
        if ((float) data_get($result, 'portfolio_evidence.leave_one_member_out.minimum_profit_factor', 0) < 1.0) {
            $reasons[] = 'FAILED_PORTFOLIO_LEAVE_ONE_MEMBER_OUT';
        }
        if ((float) data_get($result, 'portfolio_evidence.weight_perturbation.minimum_profit_factor', 0) < 1.0) {
            $reasons[] = 'FAILED_PORTFOLIO_WEIGHT_PERTURBATION';
        }
        if ((float) data_get($result, 'portfolio_evidence.router_stability.switch_rate', 1) > .25) {
            $reasons[] = 'FAILED_PORTFOLIO_ROUTER_STABILITY';
        }
        if ((float) data_get($result, 'portfolio_evidence.member_contribution.max_positive_share', 1) > .65) {
            $reasons[] = 'FAILED_PORTFOLIO_MEMBER_CONTRIBUTION_CAP';
        }
        if ((int) data_get($result, 'portfolio_evidence.opportunity_coverage.covered_regimes', 0) < 2) {
            $reasons[] = 'FAILED_PORTFOLIO_OPPORTUNITY_COVERAGE';
        }
        // The portfolio is a separate promotion object, not a shortcut around
        // the individual passport.  Re-apply every independent final-exam
        // lane to the combined replay and fail closed when any evidence is
        // absent.  Previously the portfolio checked PF/month/regime only,
        // which could have allowed a combined result with a missing hidden
        // arena or calendar-alignment proof to reach paper.
        $passport = $this->portfolioPassport($result);
        $reasons = [...$reasons, ...$passport['reason_codes']];
        // Carry the exact combined passport into the proxy result.  The proxy
        // is a first-class forward candidate, so paper admission must be able
        // to verify the same passport without reading mutable portfolio state.
        $result['portfolio_passport'] = $passport;
        $proxy = null;
        $portfolioForward = null;
        if ($reasons === []) {
            // Keep a portfolio proxy separate from every member's standalone
            // forward decision. The proxy is the only object allowed to
            // enter paper through this combined lane.
            $proxy = $this->ensurePortfolioPerformance($portfolio, $result);
            if (! $proxy) {
                $reasons[] = 'FAILED_PORTFOLIO_PROXY_IDENTITY';
            } else {
                $proxyPassport = (array) data_get($proxy->metrics, 'elite_agent_passport', []);
                $portfolioForward = $this->gateDecisions->recordPortfolioForward(
                    $proxy,
                    $result,
                    $proxyPassport,
                );
                if ($portfolioForward->decision !== 'passed') {
                    $reasons = [
                        ...$reasons,
                        'FAILED_PORTFOLIO_FORWARD_HANDOFF',
                        ...((array) $portfolioForward->reason_codes),
                    ];
                }
            }
        }
        $gate = ['status' => $reasons === [] ? 'passed' : 'failed', 'reason_codes' => array_values(array_unique($reasons))];
        $evidence = [
            'gate' => $gate,
            'portfolio_passport' => $passport,
            'member_individual_gate' => [
                'status' => $memberGateFailures->isEmpty() ? 'passed' : 'failed',
                'failed_member_performance_ids' => $memberGateFailures->pluck('model_market_performance_id')->values()->all(),
            ],
            'result' => $result,
            'recorded_at' => now()->toIso8601String(),
            'promotion_evidence' => true,
            'portfolio_forward_gate' => $portfolioForward?->toArray(),
        ];
        $evidence['portfolio_performance_id'] = $proxy?->id;
        $portfolio->update([
            'status' => $reasons === [] ? 'forward_validated' : 'blocked',
            'gate_status' => $gate['status'],
            'gate_reasons' => $gate['reason_codes'],
            'evidence' => $evidence,
            'execution_hash' => data_get($result, 'execution_contract.execution_hash', data_get($result, 'execution_hash')),
            'last_evaluated_at' => now(),
        ]);
        if ($gate['status'] === 'passed') {
            // The knowledge stage changes only after the independent member
            // gates and the combined passport both passed. It cannot make a
            // failed member look elite or create paper eligibility.
            try {
                $this->knowledge->markCouncilElite(
                    $portfolio->fresh(['members.performance.modelVersion']),
                    (string) data_get($result, 'evidence_run_id', data_get($result, 'execution_contract.execution_hash')),
                );
            } catch (\Throwable $exception) {
                report($exception);
            }
        }
        return $gate;
    }

    /**
     * Portfolio-level final exam.  This mirrors the immutable passport lanes
     * without pretending that a portfolio has a single model-version owner.
     * Missing fields are failures, never implicit passes.
     *
     * @return array{protocol: string, status: string, checks: array<string, bool>, reason_codes: array<int, string>}
     */
    private function portfolioPassport(array $result): array
    {
        $monthly = (array) data_get($result, 'monthly_passport', []);
        $redTeam = (array) data_get($result, 'red_team', []);
        $news = (array) data_get($redTeam, 'scenarios.news_window', []);
        $edge = (array) data_get($result, 'statistical_evidence.edge_quality', []);
        $bootstrap = (array) data_get($edge, 'bootstrap_pf', []);
        $pbo = (array) data_get($result, 'selection_validation', []);
        $dsr = (array) data_get($result, 'statistical_evidence.deflated_sharpe', []);
        $memberRows = collect((array) data_get($result, 'portfolio_evidence.member_breakdown', []))
            ->filter(fn ($member): bool => is_array($member) && (int) data_get($member, 'trades', 0) >= 10);
        $memberNiches = $memberRows->map(fn (array $member): string => implode('|', [
            (string) data_get($member, 'target_regime', data_get($member, 'role', 'any')),
            (string) data_get($member, 'target_volatility', 'any'),
            (string) data_get($member, 'target_direction', 'any'),
        ]))->unique()->values();
        $checks = [
            'signal_viability' => (int) data_get($result, 'entry_funnel.raw_strategy_signals', 0) > 0
                && (int) data_get($result, 'entry_funnel.accepted_entries', 0) > 0,
            'veto_regret' => array_key_exists('shadow_trade_count', (array) data_get($result, 'veto_regret', [])),
            'monthly_walk_forward' => (int) data_get($monthly, 'rolling_forward_wins', 0) >= 3
                && (int) data_get($monthly, 'failed_months', 0) === 0,
            'regime_coverage' => data_get($result, 'behavioral_diversity.status') === 'diverse'
                && (float) data_get($edge, 'worst_regime_pf', 0) >= 1.0,
            'drawdown_limit' => (float) data_get($result, 'max_drawdown_percent', data_get($result, 'max_drawdown', 100)) <= 15,
            'ruin_limit' => (float) data_get($result, 'monte_carlo.risk_of_ruin_percent', 100) <= 10,
            'behavioral_diversity' => data_get($result, 'behavioral_diversity.status') === 'diverse',
            // A council is not elite merely because one member has a large
            // PF and two other members emitted one lucky trade.  Require at
            // least three independently observed, materially sampled niches
            // before the combined object can enter the forward/paper lane.
            'member_support' => $memberRows->count() >= 3 && $memberNiches->count() >= 3,
            'bootstrap_edge' => data_get($bootstrap, 'status') === 'assessed'
                && (float) data_get($bootstrap, 'pf_5_percentile_lower_bound', 0) >= 1.1,
            'pbo' => data_get($pbo, 'status') === 'assessed'
                && (float) data_get($pbo, 'probability_of_backtest_overfitting', 1) <= .50,
            'deflated_sharpe' => data_get($dsr, 'status') === 'assessed'
                && (float) data_get($dsr, 'deflated_sharpe_probability', 0) >= .95,
            'red_team_stress' => data_get($redTeam, 'scenarios.double_cost_execution.status') === 'assessed'
                && (bool) data_get($redTeam, 'scenarios.double_cost_execution.pass'),
            'calendar_alignment' => data_get($news, 'status') === 'assessed' && (bool) data_get($news, 'pass'),
            'data_manifest' => data_get($result, 'data_manifest.status') === 'ready'
                && filled(data_get($result, 'data_manifest.sha256')),
            'next_candle_execution' => str_contains((string) data_get($result, 'market_adaptive_replay.protocol', ''), 'next candle execution'),
            'sealed_holdout' => data_get($result, 'market_adaptive_replay.sealed_holdout.used_for_training') === false
                && data_get($result, 'market_adaptive_replay.sealed_holdout.used_for_evolution') === false,
            'secret_adversarial_arena' => data_get($result, 'secret_adversarial_arena.status') === 'passed',
            'temporal_firewall' => data_get($result, 'temporal_firewall.status') === 'passed'
                && data_get($result, 'permanent_unseen_challenge.status') === 'sealed',
            'leave_one_member_out' => (float) data_get($result, 'portfolio_evidence.leave_one_member_out.minimum_profit_factor', 0) >= 1.0,
            'weight_perturbation' => (float) data_get($result, 'portfolio_evidence.weight_perturbation.minimum_profit_factor', 0) >= 1.0,
            'router_stability' => (float) data_get($result, 'portfolio_evidence.router_stability.switch_rate', 1) <= .25,
            'router_calibration' => data_get($result, 'router_evidence.status') === 'assessed'
                && (float) data_get($result, 'router_evidence.objective_score', 0) >= 50,
            'router_abstention_precision' => (float) data_get($result, 'router_evidence.abstention_precision', 0) >= .50,
            'disagreement_wait_invariant' => data_get($result, 'router_evidence.disagreement_wait_invariant') === true,
            'member_contribution_cap' => (float) data_get($result, 'portfolio_evidence.member_contribution.max_positive_share', 1) <= .65,
            'opportunity_coverage' => (int) data_get($result, 'portfolio_evidence.opportunity_coverage.covered_regimes', 0) >= 2,
        ];
        $failed = collect($checks)->filter(fn (bool $pass): bool => ! $pass)->keys()->map(
            fn (string $check): string => 'FAILED_PORTFOLIO_PASSPORT_'.strtoupper($check)
        )->values()->all();

        return [
            'protocol' => 'portfolio_elite_passport_v1',
            'status' => $failed === [] ? 'passed' : 'failed',
            'checks' => $checks,
            'reason_codes' => $failed,
            'rule' => 'A complementary council reaches paper only after the same independent final-exam lanes as one agent.',
        ];
    }

    private function selectComplementaryMembers(Collection $eligible): Collection
    {
        $selected = collect();
        $selectedRegimes = [];
        $pool = $eligible->values();
        $memberLimit = max(2, (int) config('services.lab_selection.parent_max_runtime', 8));
        while ($selected->count() < $memberLimit && $pool->isNotEmpty()) {
            // The first member is the strongest sealed niche. Every later
            // member must earn its place twice: positive standalone evidence
            // and a different failure signature. This turns the portfolio
            // idea into a real council rather than a bag of high-PF clones.
            $candidate = $pool->sortByDesc(function (ModelMarketPerformance $item) use ($selected): float {
                $state = $this->stateScore($item);
                $complement = $selected->isEmpty() ? 0.0 : $this->failureSignatureComplementarity($item, $selected);
                return $state + ($complement * 50.0);
            })->first();
            if (! $candidate) break;
            $pool = $pool->reject(fn (ModelMarketPerformance $item): bool => $item->id === $candidate->id)->values();
            if ($candidate->metrics && data_get($candidate->metrics, 'behavioral_diversity.status') === 'near_duplicate') continue;
            $niche = $this->targetRegime($candidate).'|'.$this->targetVolatility($candidate).'|'.$this->targetDirection($candidate);
            $candidateRegime = $this->targetRegime($candidate);
            // A universal portfolio must own at least two independent market
            // regimes. Same-regime councils may be added only after that
            // first orthogonal pair exists.
            if (count($selectedRegimes) < 2 && in_array($candidateRegime, $selectedRegimes, true)) continue;
            $sameNicheCount = $selected->filter(fn (ModelMarketPerformance $item): bool =>
                $this->targetRegime($item).'|'.$this->targetVolatility($item).'|'.$this->targetDirection($item) === $niche)->count();
            $sameFamily = $selected->contains(fn (ModelMarketPerformance $item): bool =>
                $item->strategy_family === $candidate->strategy_family
                && $this->targetRegime($item).'|'.$this->targetVolatility($item).'|'.$this->targetDirection($item) === $niche);
            if ($sameNicheCount >= 2 || $sameFamily) continue;
            $selected->push($candidate);
            if (! in_array($candidateRegime, $selectedRegimes, true)) $selectedRegimes[] = $candidateRegime;
        }
        return $selected;
    }

    /**
     * Compare only already-sealed regime x volatility contexts. Calendar
     * labels are diagnostics, never routing features: using month names here
     * would turn the portfolio selector into a hidden calendar overfit.
     */
    private function failureSignatureComplementarity(ModelMarketPerformance $candidate, Collection $selected): float
    {
        $candidateContexts = $this->contextProfile($candidate);
        if ($candidateContexts === []) return 0.0;
        $scores = [];
        foreach ($selected as $existing) {
            $existingContexts = $this->contextProfile($existing);
            $overlap = array_intersect(array_keys($candidateContexts), array_keys($existingContexts));
            foreach ($overlap as $context) {
                $candidateRow = $candidateContexts[$context];
                $existingRow = $existingContexts[$context];
                if ((int) data_get($candidateRow, 'trades', 0) <= 0 || (int) data_get($existingRow, 'trades', 0) <= 0) continue;
                $candidatePositive = (float) data_get($candidateRow, 'net_pf', 0) >= 1.0;
                $existingPositive = (float) data_get($existingRow, 'net_pf', 0) >= 1.0;
                $scores[] = match (true) {
                    $candidatePositive && ! $existingPositive => 1.0,
                    ! $candidatePositive && $existingPositive => -0.75,
                    ! $candidatePositive && ! $existingPositive => -1.0,
                    default => 0.10,
                };
            }
        }
        return $scores === [] ? 0.0 : round(array_sum($scores) / count($scores), 5);
    }

    private function contextProfile(ModelMarketPerformance $candidate): array
    {
        return (array) data_get($candidate->metrics, 'pf_attribution.breakdown.by_regime_volatility', []);
    }

    private function targetRegime(ModelMarketPerformance $candidate): string
    {
        $contractRegime = data_get($candidate->modelVersion?->metadata, 'portfolio_research_contract.target_regime');
        if (filled($contractRegime)) return (string) $contractRegime;
        $atlas = AgentSkillAtlasEntry::query()->where('model_market_performance_id', $candidate->id)->orderByDesc('quality_score')->first();
        return (string) (data_get($candidate->metrics, 'edge_claim.target_regime') ?: $atlas?->regime ?: 'unproven');
    }

    private function targetVolatility(ModelMarketPerformance $candidate): string
    {
        $contractVolatility = data_get($candidate->modelVersion?->metadata, 'portfolio_research_contract.target_volatility');
        if (filled($contractVolatility)) return (string) $contractVolatility;
        $atlas = AgentSkillAtlasEntry::query()->where('model_market_performance_id', $candidate->id)->orderByDesc('quality_score')->first();
        return (string) (data_get($candidate->metrics, 'edge_claim.target_volatility') ?: $atlas?->volatility ?: 'any');
    }

    private function targetDirection(ModelMarketPerformance $candidate): ?string
    {
        $contractDirection = data_get($candidate->modelVersion?->metadata, 'portfolio_research_contract.target_direction');
        if (filled($contractDirection) && in_array(strtoupper((string) $contractDirection), ['BUY', 'SELL'], true)) {
            return strtoupper((string) $contractDirection);
        }
        $edgeDirection = data_get($candidate->metrics, 'edge_claim.target_direction');
        return in_array(strtoupper((string) $edgeDirection), ['BUY', 'SELL'], true)
            ? strtoupper((string) $edgeDirection)
            : null;
    }

    private function declaredNicheEvidence(ModelMarketPerformance $candidate, string $field): mixed
    {
        $regime = $this->targetRegime($candidate);
        $volatility = $this->targetVolatility($candidate);
        $direction = $this->targetDirection($candidate);
        $contextKey = $regime.'|'.$volatility;
        if ($volatility !== 'any' && filled($direction)) {
            return data_get($candidate->metrics, "pf_attribution.breakdown.by_regime_volatility_direction.{$contextKey}.{$direction}.{$field}");
        }
        if ($volatility !== 'any') {
            return data_get($candidate->metrics, "pf_attribution.breakdown.by_regime_volatility.{$contextKey}.{$field}");
        }
        return data_get($candidate->metrics, "pf_attribution.breakdown.by_regime.{$regime}.{$field}");
    }

    private function role(ModelMarketPerformance $candidate): string
    {
        // The sealed research contract is authoritative. Older metrics may
        // carry a stale edge-claim label from the parent strategy and would
        // otherwise mislabel the router role while leaving the member itself
        // in the correct regime lane.
        return $this->targetRegime($candidate);
    }

    /**
     * Replay breakdowns expose regime PF under pf_attribution while the
     * human-readable regime table intentionally stores only win/loss totals.
     * Read the sealed PF evidence first and fail closed when it is absent.
     */
    private function regimeProfitFactor(array $result, string $regime, array $row): ?float
    {
        foreach ([
            data_get($result, "pf_attribution.breakdown.by_regime.{$regime}.net_pf"),
            data_get($result, "statistical_evidence.edge_quality.regime_pf.{$regime}"),
            data_get($row, 'profit_factor'),
        ] as $value) {
            if (is_numeric($value)) return (float) $value;
        }
        return null;
    }

    private function parameterHash(ModelMarketPerformance $candidate): string
    {
        return (string) (data_get($candidate->modelVersion?->metadata, 'parameter_fingerprint')
            ?: hash('sha256', json_encode($candidate->modelVersion?->parameters ?? [], JSON_PRESERVE_ZERO_FRACTION)));
    }

    private function memberEvidence(ModelMarketPerformance $candidate): array
    {
        return [
            'performance_id' => $candidate->id,
            'status' => $candidate->status,
            'sample_count' => $candidate->sample_count,
            'profit_factor' => $this->profitFactor($candidate),
            'stress_pf' => data_get($candidate->metrics, 'pf_attribution.stress_cost.profit_factor'),
            'passport' => data_get($candidate->metrics, 'elite_agent_passport.status'),
            'declared_niche' => [
                'regime' => $this->targetRegime($candidate),
                'volatility' => $this->targetVolatility($candidate),
                'direction' => $this->targetDirection($candidate),
                'trades' => $this->declaredNicheEvidence($candidate, 'trades'),
                'net_pf' => $this->declaredNicheEvidence($candidate, 'net_pf'),
                'evidence_protocol' => 'sealed_regime_volatility_direction_intersection_v1',
            ],
            'portfolio_contract' => data_get($candidate->modelVersion?->metadata, 'portfolio_research_contract'),
        ];
    }

    private function persistMembership(EliteAgentPortfolio $portfolio, Collection $selected, string $membershipHash, string $mode): void
    {
        $membershipChanged = $portfolio->membership_hash !== $membershipHash;
        DB::transaction(function () use ($portfolio, $selected, $membershipHash, $membershipChanged, $mode): void {
            if ($membershipChanged) {
                $portfolio->members()->delete();
                $portfolio->update([
                    'membership_hash' => $membershipHash,
                    'status' => 'waiting',
                    'gate_status' => 'waiting_for_combined_replay',
                    'gate_reasons' => ['WAITING_FOR_PORTFOLIO_REPLAY'],
                    'evidence' => null,
                ]);
            }
            foreach ($selected as $candidate) {
                $portfolio->members()->updateOrCreate(
                    ['model_market_performance_id' => $candidate->id],
                    [
                        'role' => $this->role($candidate),
                        'target_regime' => $this->targetRegime($candidate),
                        'target_volatility' => $this->targetVolatility($candidate),
                        'target_direction' => $this->targetDirection($candidate),
                        'risk_weight' => 1.0,
                        'parameter_hash' => $this->parameterHash($candidate),
                        'evidence' => $this->memberEvidence($candidate),
                    ]
                );
            }
            $portfolio->update([
                'member_count' => $selected->count(),
                'route_policy' => [
                    'router' => 'sealed_regime_volatility_direction_ownership_v1',
                    'admission_mode' => $mode,
                    'disagreement' => 'WAIT',
                'duplicate_trade_rule' => 'one_position_per_portfolio_signal',
                'member_independence_required' => true,
                'standalone_member_promotion' => false,
                'transition_firewall' => $this->portfolioPolicy(),
            ],
                'last_evaluated_at' => now(),
            ]);
        });
    }

    private function ensurePortfolioPerformance(EliteAgentPortfolio $portfolio, array $result): ?ModelMarketPerformance
    {
        $portfolio->load('members.performance.modelVersion');
        $primary = $portfolio->members->first()?->performance?->modelVersion;
        if (! $primary) return null;
        $strategy = 'portfolio_'.$portfolio->symbol.'_'.$portfolio->timeframe.'_v1';
        $memberSpecs = $this->memberSpecs($portfolio);
        $parameterHash = hash('sha256', json_encode([
            'members' => $memberSpecs, 'router_policy' => $this->portfolioPolicy(),
            'portfolio_parameters' => $this->portfolioParameters(),
        ], JSON_PRESERVE_ZERO_FRACTION));
        $portfolioPassport = [
            ...(array) data_get($result, 'portfolio_passport', []),
            'protocol' => 'portfolio_elite_passport_v1',
            'status' => 'passed',
            'portfolio_id' => $portfolio->id,
            'membership_hash' => $portfolio->membership_hash,
            'parameter_hash' => $parameterHash,
            'final_exam_result_hash' => hash(
                'sha256',
                json_encode($result, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES),
            ),
            'promotion_evidence' => true,
            'source' => 'recordCombinedEvidence',
            'rule' => 'Portfolio proxy inherits only a passed combined passport; member passports remain separate.',
        ];
        $metadata = [
            'base_strategy' => 'portfolio',
            'portfolio_proxy' => true,
            'elite_portfolio_id' => $portfolio->id,
            'portfolio_members' => $memberSpecs,
            'portfolio_parameters' => $this->portfolioParameters(),
            'agent_constitution' => [
                'protocol' => 'portfolio_constitution_v1',
                'status' => 'sealed',
                'allowed_regimes' => collect($memberSpecs)->pluck('target_regime')->filter()->unique()->values()->all(),
                'abstention_rules' => ['strong_member_disagreement', 'out_of_distribution', 'negative_net_ev'],
            ],
            // Router/calibration policy is part of the strategy identity. A
            // policy change therefore invalidates prior forward evidence; it
            // can never be silently deployed behind unchanged members.
            'parameter_fingerprint' => hash('sha256', json_encode([
                'members' => $memberSpecs, 'router_policy' => $this->portfolioPolicy(),
                'portfolio_parameters' => $this->portfolioParameters(),
            ], JSON_PRESERVE_ZERO_FRACTION)),
            'elite_agent_passport' => $portfolioPassport,
        ];
        $model = ModelVersion::query()->updateOrCreate(
            ['strategy' => $strategy],
            [
                'name' => strtoupper($strategy), 'version' => 'portfolio-v1', 'generation' => 0,
                'status' => 'testing', 'description' => 'Strict complementary specialist portfolio proxy.',
                'parameters' => $primary->parameters ?? [], 'metadata' => $metadata, 'evidence_status' => 'valid',
            ]
        );
        $metrics = [
            ...$result,
            'portfolio_proxy' => true,
            'elite_portfolio_id' => $portfolio->id,
            'portfolio_passport' => $portfolioPassport,
            'elite_agent_passport' => $portfolioPassport,
        ];
        $performance = ModelMarketPerformance::query()->updateOrCreate(
            ['model_version_id' => $model->id, 'symbol' => $portfolio->symbol, 'timeframe' => $portfolio->timeframe],
            [
                'strategy_family' => 'portfolio', 'status' => 'forward_validated', 'paper_status' => 'pending',
                'fitness' => (float) data_get($result, 'profit_factor', 0), 'forward_score' => (float) data_get($result, 'forward_score', 0),
                'sample_count' => (int) data_get($result, 'total_trades', 0), 'rolling_windows_count' => (int) data_get($result, 'window_survival.positive_windows', 0),
                'rolling_forward_wins' => (int) data_get($result, 'window_survival.positive_windows', 0), 'metrics' => $metrics,
                'evidence_status' => 'valid',
            ]
        );
        PaperTradingEvaluation::firstOrCreate(
            ['model_market_performance_id' => $performance->id, 'status' => 'pending'],
            ['started_at' => now()]
        );
        return $performance->fresh('modelVersion');
    }

    private function stateScore(ModelMarketPerformance $candidate): float
    {
        return ($this->profitFactor($candidate) * 100)
            + ((float) data_get($candidate->metrics, 'pf_attribution.stress_cost.profit_factor', 0) * 40)
            + ((float) $candidate->forward_score)
            - ((float) data_get($candidate->metrics, 'max_drawdown_percent', 100) * 2)
            - ((float) data_get($candidate->metrics, 'monte_carlo.risk_of_ruin_percent', 100) * 3);
    }

    private function portfolioPolicy(): array
    {
        return [
            'protocol' => 'evidence_constrained_specialist_router_v2',
            'enabled' => false,
            'wait_candles' => 2,
            'calibrated_net_edge_required' => true,
            'disagreement_action' => 'WAIT',
            'transition_action' => 'WAIT_OR_REDUCE_RISK',
            'robustness_policy' => ['leave_one_member_out', 'weight_perturbation', 'loss_correlation', 'router_stability', 'contribution_cap', 'opportunity_coverage'],
            'rule' => 'finite_transition_wait_and_positive_calibrated_net_edge_without_future_outcomes',
        ];
    }

    /** ModelMarketPerformance stores PF inside the sealed metrics payload;
     * it does not have a profit_factor database column. */
    private function profitFactor(ModelMarketPerformance $candidate): float
    {
        return (float) data_get(
            $candidate->metrics,
            'profit_factor',
            data_get($candidate->metrics, 'pf_attribution.normal_cost.profit_factor', 0)
        );
    }
}
