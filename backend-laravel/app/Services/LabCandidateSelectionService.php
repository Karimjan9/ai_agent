<?php

namespace App\Services;

use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use Illuminate\Support\Collection;

class LabCandidateSelectionService
{
    /**
     * A finalist count is never pre-decided. Every non-dominated, behaviourally
     * distinct research claim that has enough screening evidence proceeds to
     * full replay; it may be zero or the whole viable frontier.
     */
    public function select(Collection $agents): Collection
    {
        $ranked = $agents->filter(fn ($agent) => $this->isWorthFullReplay($agent))
            ->sortByDesc(fn ($agent) => [
            $this->survivalScore($agent),
            $this->stressRobustness($agent),
            (float) data_get($this->result($agent), 'negative_space_portfolio.diversification_score', 0),
            $this->worstRegimePf($agent),
            $this->worstWindowPf($agent),
            $this->parameterStability($agent),
            $this->signalTimingStability($agent),
            -$this->trainForwardGap($agent),
            $this->coverage($agent),
            $this->rollingConsistency($agent),
            (float) $agent->profit_factor,
            (float) $agent->forward_score,
            (int) $agent->sample_count,
            -(float) $agent->max_drawdown,
        ])->values();
        $front = $ranked->filter(fn ($candidate) => ! $ranked->contains(
            fn ($other) => $other->id !== $candidate->id && $this->dominates($other, $candidate)
        ))->values();
        return $front->filter(fn ($agent) => ! $this->isNearDuplicate($agent, $front))->values();
    }

    /** Three independent replay purposes prevent one attractive screen PF
     * from monopolising the only expensive validation worker. */
    public function selectValidationLanes(Collection $agents): array
    {
        $roleSelection = $this->selectRoleCompleteCouncilLanes($agents);
        $front = $this->select($agents)->values();
        $general = $front->first();
        $orthogonal = $general ? $front->first(fn ($agent) => $agent->strategy_family !== $general->strategy_family) : null;
        $causal = $this->selectCausalProbeBundle($agents, 1);
        // A failed global calendar gate does not prove that every declared
        // niche is useless.  Admit only strong, complementary niche seeds to
        // a separate full replay lane.  They can never become standalone
        // forward candidates; only the frozen combined portfolio may pass
        // its own strict global gate later.
        $portfolioMembers = $this->selectPortfolioMembers($agents, 4);
        // G98 lanes are deliberately allowed to fail a cheap screen on the
        // very dimension they are meant to repair. Without this bounded
        // research lane, monthly/regime/transition mutations are discarded
        // before chronological full replay and only one screen survivor can
        // reach immutable evidence. These candidates remain research-only.
        $targetedResearch = $this->selectTargetedResearchLanes($agents, 4);
        // Monthly survival is a separate passport dimension. Preserve a
        // small number of viable monthly children even when a high-PF recall
        // or transition child dominates the global frontier; otherwise the
        // calendar gate remains an untested diagnosis and the council cannot
        // evolve toward failed_months = 0.
        $monthlyCoverage = $this->selectMonthlyCoverageLanes($agents, 2);
        // A volume council is a sealed four-way control cohort. Its children
        // are not G98 targeted research and must not disappear simply because
        // the generic Pareto selector sees the same parent metrics. Each
        // member still needs positive, cost-aware screen evidence and then
        // faces the unchanged standalone full passport.
        $volumeResearch = $this->selectVolumeResearchLanes($agents);
        $selected = collect([$general, $orthogonal])->filter()
            ->merge($causal)->unique('id')->values();
        $selected = $selected->merge($portfolioMembers)->merge($monthlyCoverage)->merge($targetedResearch)->merge($volumeResearch)->unique('id')->values();
        $selected = $selected->merge($roleSelection['agents'])->unique('id')->values();
        $lanes = [];
        if ($general) $lanes[$general->id] = 'general_candidate';
        if ($orthogonal) $lanes[$orthogonal->id] = 'orthogonal_specialist';
        foreach ($causal as $agent) $lanes[$agent->id] = $agent->origin === 'causal_isolation' ? 'causal_probe' : 'causal_probe_control';
        foreach ($portfolioMembers as $agent) {
            if (! isset($lanes[$agent->id])) $lanes[$agent->id] = 'portfolio_member';
        }
        foreach ($targetedResearch as $agent) {
            if (! isset($lanes[$agent->id])) $lanes[$agent->id] = 'targeted_research';
        }
        foreach ($monthlyCoverage as $agent) {
            if (! isset($lanes[$agent->id])) $lanes[$agent->id] = 'targeted_research';
        }
        foreach ($volumeResearch as $agent) {
            $lanes[$agent->id] = 'volume_context';
        }
        foreach ($roleSelection['agents'] as $agent) {
            $lanes[$agent->id] = 'council_role_full_replay';
        }

        // The selector may collect several bounded research lanes above, but
        // the Python replay lane is intentionally scarce. Keep at most a
        // small, high-quality frontier and prefer one representative from
        // each available architecture before filling the remaining slots.
        $roleComplete = $roleSelection['required'] !== [];
        $limit = max(1, (int) config('services.lab_selection.max_full_validation_candidates', 4));
        if ($roleComplete) $limit = max(4, $limit);
        $selected = $this->capDiverseReplayFrontier($selected, $limit, $roleSelection['agents']);
        $lanes = collect($lanes)->only($selected->pluck('id')->all())->all();

        return [
            'agents' => $selected,
            'lanes' => $lanes,
            'council_role_coverage' => [
                'protocol' => 'role_complete_council_selection_v1',
                'required_roles' => $roleSelection['required'],
                'selected_roles' => $roleSelection['selected_roles'],
                'missing_roles' => $roleSelection['missing_roles'],
                'full_replay_required' => $roleComplete,
                'promotion_evidence' => false,
            ],
        ];
    }

    /**
     * Bound expensive full validation without making a weak candidate pass.
     * Diversity is a tie-breaker: every retained candidate still came from
     * the unchanged screening/research admission rules above.
     */
    private function capDiverseReplayFrontier(Collection $selected, int $limit, ?Collection $protected = null): Collection
    {
        if ($selected->count() <= $limit) return $selected->values();

        $ranked = $selected->sortByDesc(fn ($agent): array => $this->fullReplayPriority($agent))->values();
        $protected ??= collect();
        $chosen = $protected->unique('id')->values();
        $families = [];
        $regimes = [];

        foreach ($chosen as $candidate) {
            $families[(string) ($candidate->strategy_family ?? 'unknown')] = true;
            $regime = $this->declaredReplayRegime($candidate);
            if ($regime !== '') $regimes[$regime] = true;
        }

        // These are the three intended mixed-strategy architectures. If one
        // is absent from the screened frontier, no placeholder is created.
        foreach (['hybrid', 'regime_ensemble', 'differential_router'] as $family) {
            $candidate = $ranked->first(fn ($agent): bool =>
                (string) ($agent->strategy_family ?? '') === $family
                && ! $chosen->contains('id', $agent->id)
            );
            if (! $candidate || $chosen->count() >= $limit) continue;
            $chosen->push($candidate);
            $families[$family] = true;
            $regime = $this->declaredReplayRegime($candidate);
            if ($regime !== '') $regimes[$regime] = true;
        }

        // Before adding a same-family duplicate, preserve a new market niche
        // where the generation actually contains one.
        foreach ($ranked as $candidate) {
            if ($chosen->count() >= $limit || $chosen->contains('id', $candidate->id)) continue;
            $family = (string) ($candidate->strategy_family ?? 'unknown');
            $regime = $this->declaredReplayRegime($candidate);
            if (! isset($families[$family]) || ($regime !== '' && ! isset($regimes[$regime]))) {
                $chosen->push($candidate);
                $families[$family] = true;
                if ($regime !== '') $regimes[$regime] = true;
            }
        }

        foreach ($ranked as $candidate) {
            if ($chosen->count() >= $limit || $chosen->contains('id', $candidate->id)) continue;
            $chosen->push($candidate);
        }

        return $chosen->values();
    }

    /** @return array<string, mixed> */
    private function selectRoleCompleteCouncilLanes(Collection $agents): array
    {
        $required = ['trend_up_specialist', 'trend_down_specialist', 'range_specialist', 'transition_risk_router'];
        $roleAgents = $agents->filter(fn ($agent): bool =>
            data_get($agent, 'modelVersion.metadata.role_complete_council.protocol') === 'role_complete_council_v1'
            && data_get($agent, 'modelVersion.metadata.role_complete_council.full_replay_required') === true
        )->groupBy(fn ($agent): string => (string) data_get($agent, 'modelVersion.metadata.role_complete_council.role'));
        $selected = collect();
        $selectedRoles = [];
        $missing = [];
        foreach ($required as $role) {
            $candidate = $roleAgents->get($role, collect())
                ->filter(fn ($agent): bool => $agent->lifecycle_status === 'screened')
                ->sortByDesc(fn ($agent): array => $this->fullReplayPriority($agent))
                ->first();
            if (! $candidate) {
                $missing[] = $role;
                continue;
            }
            $selected->push($candidate);
            $selectedRoles[] = $role;
        }

        return [
            'required' => $roleAgents->isEmpty() ? [] : $required,
            'agents' => $selected->unique('id')->values(),
            'selected_roles' => $selectedRoles,
            'missing_roles' => $missing,
        ];
    }

    private function fullReplayPriority(object $agent): array
    {
        return [
            (float) data_get($this->result($agent), 'fitness_score', 0),
            $this->survivalScore($agent),
            $this->stressRobustness($agent),
            $this->worstRegimePf($agent),
            $this->worstCalendarMonthPf($agent),
            $this->worstWindowPf($agent),
            $this->portfolioCalendarStabilityScore($agent),
            $this->coverage($agent),
            $this->rollingConsistency($agent),
            (float) $agent->forward_score,
            (float) $agent->profit_factor,
            (int) $agent->sample_count,
            -(float) $agent->max_drawdown,
        ];
    }

    private function declaredReplayRegime(object $agent): string
    {
        return (string) (
            data_get($agent, 'modelVersion.metadata.portfolio_research_contract.target_regime')
            ?: data_get($agent, 'modelVersion.metadata.portfolio_council_lane.regime')
            ?: data_get($agent, 'modelVersion.metadata.g98_council_lane.regime')
        );
    }

    /**
     * Volume children are a separate research cohort, not ordinary G98
     * lanes. Admit the complete control + specialist set when each member
     * has enough screen observations; failed screen dimensions are still
     * tested in the immutable full replay and never become promotion proof.
     */
    private function selectVolumeResearchLanes(Collection $agents): Collection
    {
        $minimumTrades = (int) config('services.lab_selection.minimum_screening_trades', 10);

        return $agents->filter(function ($agent) use ($minimumTrades): bool {
            if (data_get($agent, 'modelVersion.metadata.volume_research_contract.protocol') !== 'volume_council_v1') {
                return false;
            }
            if ($agent->lifecycle_status !== 'screened') return false;
            if ((int) $agent->sample_count < $minimumTrades
                || (float) $agent->profit_factor < 1.0
                || (float) $agent->forward_score <= 0
                || (float) ($agent->risk_of_ruin ?? 0) > 10
                || $this->validOpportunities($agent) <= 0) {
                return false;
            }

            return data_get($agent, 'modelVersion.metadata.volume_research_contract.standalone_forward_required') === true;
        })->sortBy('id')->values();
    }

    /** Select bounded full replays for the G98 lane a candidate is meant to repair. */
    private function selectTargetedResearchLanes(Collection $agents, int $limit = 4): Collection
    {
        $ranked = $agents->filter(function ($agent): bool {
            $metadata = (array) data_get($agent, 'modelVersion.metadata', []);
            // Volume children may inherit an older model's projection in
            // legacy rows. Their own contract is a separate shadow/context
            // lane and must never be admitted as G98 targeted research merely
            // because stale g98_council_lane metadata is present.
            if (data_get($metadata, 'volume_research_contract.protocol') === 'volume_council_v1') {
                return false;
            }
            if (data_get($metadata, 'g98_council_lane.protocol') !== LabPopulationService::GENERATION_PROTOCOL) {
                return false;
            }
            $lane = (string) data_get($metadata, 'g98_council_lane.lane', data_get($metadata, 'generation_target', ''));
            // The research lane must be able to test the failure it was
            // created to repair. Stress/exit/transition variants therefore
            // may enter after a stress screen miss, while a failed PF,
            // non-target identity, drawdown or opportunity-free screen still
            // remains a hard exclusion. This is learning-only admission; no
            // gate is relaxed and no promotion evidence is minted here.
            $allowedFailuresByLane = [
                'monthly_survival' => ['FAILED_CALENDAR_MONTH_SURVIVAL', 'FAILED_TEMPORAL_CHUNK_SURVIVAL'],
                'regime_coverage' => ['FAILED_REGIME_COVERAGE', 'FAILED_CALENDAR_MONTH_SURVIVAL', 'FAILED_TEMPORAL_CHUNK_SURVIVAL'],
                'volatility_session_stability' => ['FAILED_STRESS_COST', 'FAILED_CALENDAR_MONTH_SURVIVAL', 'FAILED_TEMPORAL_CHUNK_SURVIVAL'],
                'exit_topology' => ['FAILED_STRESS_COST', 'FAILED_CALENDAR_MONTH_SURVIVAL', 'FAILED_TEMPORAL_CHUNK_SURVIVAL'],
                'transition_firewall' => ['FAILED_STRESS_COST', 'FAILED_REGIME_COVERAGE', 'FAILED_CALENDAR_MONTH_SURVIVAL', 'FAILED_TEMPORAL_CHUNK_SURVIVAL'],
                'portfolio_router' => ['FAILED_REGIME_COVERAGE', 'FAILED_CALENDAR_MONTH_SURVIVAL', 'FAILED_TEMPORAL_CHUNK_SURVIVAL'],
                // Recall is a repair lane for opportunity capture, not a
                // cost exemption. A stress miss is therefore admissible as
                // research evidence, but the unchanged stress/cost gate must
                // still pass in the immutable full replay before promotion.
                'opportunity_recall' => ['FAILED_PASSPORT_OPPORTUNITY_RECALL', 'FAILED_STRESS_COST', 'FAILED_REGIME_COVERAGE', 'FAILED_CALENDAR_MONTH_SURVIVAL', 'FAILED_TEMPORAL_CHUNK_SURVIVAL'],
            ];
            $allowedFailures = $allowedFailuresByLane[$lane] ?? [
                'FAILED_CALENDAR_MONTH_SURVIVAL',
                'FAILED_REGIME_COVERAGE',
                'FAILED_TEMPORAL_CHUNK_SURVIVAL',
            ];
            $decision = CandidateGateDecision::query()
                ->where('lab_agent_id', $agent->id ?? null)
                ->where('stage', 'screening')->latest('evaluated_at')->first();
            $recallLane = $lane === 'opportunity_recall';
            if (! $decision) return false;
            $reasons = array_values(array_unique((array) $decision->reason_codes));
            if ($recallLane) {
                // Recall is measured in the full replay, so a clean screen is
                // the expected admission state for this lane. A screen miss
                // is admitted only when it is itself one of the declared
                // contextual failures; PF/zero-opportunity misses stay out.
                if ($decision->decision !== 'passed' && ($reasons === [] || array_diff($reasons, $allowedFailures) !== [])) return false;
            } elseif ($decision->decision === 'passed' || $reasons === [] || array_diff($reasons, $allowedFailures) !== []) {
                return false;
            }

            $stressRepairLane = in_array($lane, ['volatility_session_stability', 'exit_topology', 'transition_firewall', 'opportunity_recall'], true);
            return (int) $agent->sample_count >= (int) config('services.lab_selection.minimum_screening_trades', 10)
                && (float) $agent->profit_factor >= 1.0
                && (float) $agent->forward_score > 0
                && ($stressRepairLane || $this->stressRobustness($agent) >= 1.05)
                && $this->validOpportunities($agent) > 0
                && $this->hasContextViability($agent);
        })->sortByDesc(fn ($agent): array => [
            $this->targetedLanePriority($agent),
            $this->targetedResearchRegime($agent) === 'unproven' ? 0 : 1,
            (float) $agent->forward_score,
            (float) $agent->profit_factor,
            (int) $agent->sample_count,
        ])->values();

        $selected = collect();
        $keys = [];
        // Recall coverage is its own council obligation. Global Pareto
        // dominance is allowed to rank a recall child out of the frontier,
        // but it must not erase the only evidence lane for a declared regime.
        // This is bounded research-only admission; no passport or promotion
        // decision is created here.
        $recallCoverage = $this->selectOpportunityRecallCoverageLanes($ranked, min(3, $limit));
        foreach ($recallCoverage as $candidate) {
            $key = $this->targetedResearchKey($candidate);
            if (isset($keys[$key])) continue;
            $keys[$key] = true;
            $this->sealPortfolioResearchContract($candidate, $this->portfolioNiche($candidate));
            $selected->push($candidate);
            if ($selected->count() >= $limit) break;
        }
        if ($selected->count() >= $limit) return $selected->values();

        // The transition/risk router is a first-class council owner, not a
        // cosmetic metadata label. Reserve one eligible child before the
        // regime seats so a strong range screen cannot consume the entire
        // scarce full-validation frontier.
        $transition = $ranked->first(fn ($agent): bool =>
            $this->isTransitionRiskOwner($agent)
            && ! isset($keys[$this->targetedResearchKey($agent)])
        );
        if ($transition) {
            $key = $this->targetedResearchKey($transition);
            $keys[$key] = true;
            $this->sealPortfolioResearchContract($transition, $this->portfolioNiche($transition));
            $selected->push($transition);
        }
        // A council is only complementary when the scarce research lane
        // actually preserves regime ownership. Reserve one best eligible
        // child for each declared regime before filling the remaining slots
        // by lane diversity. Without this pass, a strong range screen can
        // consume all four seats and silently recreate the single-regime
        // failure that the council was meant to solve.
        foreach (['trend_up', 'trend_down', 'range'] as $regime) {
            $candidate = $ranked->first(fn ($agent): bool =>
                $this->targetedResearchRegime($agent) === $regime
                && ! isset($keys[$this->targetedResearchKey($agent)])
            );
            if (! $candidate) continue;
            $key = $this->targetedResearchKey($candidate);
            $keys[$key] = true;
            $this->sealPortfolioResearchContract($candidate, $this->portfolioNiche($candidate));
            $selected->push($candidate);
            if ($selected->count() >= $limit) break;
        }
        foreach ($ranked as $agent) {
            $key = $this->targetedResearchKey($agent);
            if (isset($keys[$key])) continue;
            $keys[$key] = true;
            // Targeted G98 replay is also a real singleton specialist replay:
            // freeze the declared regime/volatility ownership before the
            // worker builds its request. The lane remains research-only.
            $this->sealPortfolioResearchContract($agent, $this->portfolioNiche($agent));
            $selected->push($agent);
            if ($selected->count() >= $limit) break;
        }
        return $selected->values();
    }

    /**
     * Reserve one eligible opportunity-recall child per declared regime.
     * The candidate must already satisfy the bounded research admission
     * contract in selectTargetedResearchLanes(); this method only protects
     * complementary evidence from global dominance and lane monopolies.
     */
    private function selectOpportunityRecallCoverageLanes(Collection $ranked, int $limit = 3): Collection
    {
        $selected = collect();
        foreach (['trend_up', 'trend_down', 'range'] as $regime) {
            $candidate = $ranked->first(fn ($agent): bool =>
                data_get($agent, 'modelVersion.metadata.g98_council_lane.lane') === 'opportunity_recall'
                && $this->targetedResearchRegime($agent) === $regime
                && ! $selected->contains('id', $agent->id)
            );
            if (! $candidate) continue;
            $selected->push($candidate);
            if ($selected->count() >= $limit) break;
        }

        return $selected->values();
    }

    /**
     * Keep monthly-survival evidence visible in the full frontier. This is
     * still research-only admission and requires positive screen edge,
     * stress robustness, opportunity evidence, and an observed declared
     * regime×volatility cell. It never grants a monthly or elite passport.
     */
    private function selectMonthlyCoverageLanes(Collection $agents, int $limit = 2): Collection
    {
        $ranked = $agents->filter(function ($agent): bool {
            $metadata = (array) data_get($agent, 'modelVersion.metadata', []);
            if (data_get($metadata, 'volume_research_contract.protocol') === 'volume_council_v1') return false;
            if (data_get($metadata, 'g98_council_lane.protocol') !== LabPopulationService::GENERATION_PROTOCOL) return false;
            if ((string) data_get($metadata, 'g98_council_lane.lane', '') !== 'monthly_survival') return false;

            $decision = CandidateGateDecision::query()
                ->where('lab_agent_id', $agent->id ?? null)
                ->where('stage', 'screening')->latest('evaluated_at')->first();
            if (! $decision) return false;
            $allowed = ['FAILED_CALENDAR_MONTH_SURVIVAL', 'FAILED_TEMPORAL_CHUNK_SURVIVAL'];
            $reasons = array_values(array_unique((array) $decision->reason_codes));
            if ($decision->decision === 'passed' || $reasons === [] || array_diff($reasons, $allowed) !== []) return false;

            return (int) $agent->sample_count >= (int) config('services.lab_selection.minimum_screening_trades', 10)
                && (float) $agent->profit_factor >= 1.0
                && (float) $agent->forward_score > 0
                && $this->stressRobustness($agent) >= 1.05
                && $this->validOpportunities($agent) > 0
                && $this->hasContextViability($agent);
        })->sortByDesc(fn ($agent): array => [
            $this->targetedResearchRegime($agent) === 'unproven' ? 0 : 1,
            $this->portfolioCalendarStabilityScore($agent),
            (float) $agent->profit_factor,
            (float) $agent->forward_score,
            (int) $agent->sample_count,
        ])->values();

        $selected = collect();
        foreach (['trend_up', 'trend_down', 'range'] as $regime) {
            $candidate = $ranked->first(fn ($agent): bool =>
                $this->targetedResearchRegime($agent) === $regime
                && ! $selected->contains('id', $agent->id)
            );
            if (! $candidate) continue;
            $this->sealPortfolioResearchContract($candidate, $this->portfolioNiche($candidate));
            $selected->push($candidate);
            if ($selected->count() >= $limit) break;
        }

        return $selected->values();
    }

    /**
     * A declared council seat must have observed evidence in its exact
     * regime×volatility envelope. Global trades cannot be borrowed to make a
     * zero-observation specialist look viable; sparse but real cells remain
     * researchable and must prove themselves in the unchanged full gates.
     */
    private function hasContextViability(object $agent, int $minimumTrades = 3): bool
    {
        $niche = $this->portfolioNiche($agent);
        return (int) data_get($niche, 'trades', 0) >= $minimumTrades
            && (float) data_get($niche, 'profit_factor', 0) > 0;
    }

    private function isTransitionRiskOwner(object $agent): bool
    {
        $metadata = (array) data_get($agent, 'modelVersion.metadata', []);
        return data_get($metadata, 'portfolio_council_lane.specialist_role') === 'transition_risk_router'
            || data_get($metadata, 'council_specialist_contract.role') === 'transition_risk_router'
            || data_get($metadata, 'portfolio_council_lane.owner_context') === 'transition_risk';
    }

    private function targetedResearchKey(object $agent): string
    {
        $metadata = (array) data_get($agent, 'modelVersion.metadata', []);
        $lane = (string) data_get($metadata, 'g98_council_lane.lane', data_get($metadata, 'generation_target', 'unknown'));
        return $lane.'|'.$this->targetedResearchRegime($agent);
    }

    private function targetedResearchRegime(object $agent): string
    {
        $metadata = (array) data_get($agent, 'modelVersion.metadata', []);
        $declared = data_get($metadata, 'portfolio_council_lane.regime');
        if (filled($declared)) return (string) $declared;
        $target = data_get($agent, 'modelVersion.parameters.differential_target_regime');
        return in_array($target, ['trend_up', 'trend_down', 'range'], true) ? (string) $target : 'unproven';
    }

    private function targetedLanePriority(object $agent): int
    {
        $metadata = (array) data_get($agent, 'modelVersion.metadata', []);
        return match ((string) data_get($metadata, 'g98_council_lane.lane', data_get($metadata, 'generation_target', ''))) {
            'transition_firewall' => 5,
            'regime_coverage' => 4,
            'monthly_survival' => 3,
            'volatility_session_stability' => 2,
            'exit_topology' => 1,
            'portfolio_router' => 0,
            'opportunity_recall' => 6,
            default => 0,
        };
    }

    /**
     * Selects research-only members for a complementary portfolio.
     *
     * This is intentionally a different contract from the standalone
     * screening survivor.  The member must already have positive cost-aware
     * edge and enough evidence in its own niche, while the portfolio's later
     * canonical replay still has to pass the unchanged global PF, stress,
     * temporal and calendar gates.  No failed member is promoted by this
     * lane.
     */
    public function selectPortfolioMembers(Collection $agents, int $limit = 4): Collection
    {
        $selected = collect();
        $usedNiches = [];
        $usedFamilies = [];
        $knownFailedFingerprints = $this->knownFailedPortfolioFingerprints($agents);

        $ranked = $agents->filter(fn ($agent): bool =>
            $this->isPortfolioSeed($agent)
            && ! in_array($this->parameterFingerprint($agent), $knownFailedFingerprints, true)
        )
            ->sortByDesc(fn ($agent): array => [
                // Rank a research member by context/time survival before
                // point PF.  Otherwise the council repeatedly chooses the
                // most profitable niche even when all of its losses cluster
                // in the same temporal shape.  This score uses only counts
                // and PF ratios; no calendar label is fed into mutation or
                // routing.
                $this->portfolioCalendarStabilityScore($agent),
                $this->portfolioNiche($agent)['profit_factor'],
                $this->stressRobustness($agent),
                $this->portfolioNiche($agent)['trades'],
                (float) $agent->profit_factor,
                (float) $agent->forward_score,
            ])->values();

        foreach ($ranked as $agent) {
            $niche = $this->portfolioNiche($agent);
            $nicheKey = $niche['regime'].'|'.$niche['volatility'];
            // A portfolio needs orthogonal failure modes.  Permit a second
            // member in the same regime only when it belongs to another
            // family and has a different volatility envelope.
            $sameNicheFamily = $selected->contains(fn ($item): bool =>
                $item->strategy_family === $agent->strategy_family
                && ($this->portfolioNiche($item)['regime'].'|'.$this->portfolioNiche($item)['volatility']) === $nicheKey);
            // Two independent families may form a same-niche council, but
            // the same family may not be duplicated. Runtime disagreement
            // for such a council is resolved as WAIT.
            if (($usedNiches[$nicheKey] ?? 0) >= 2 || $sameNicheFamily) continue;
            // Same architecture can legitimately own two different sealed
            // niches (for example trend_up|normal and range|low). Rejecting
            // that pair merely because the family label matches would force
            // the portfolio to add an unrelated family and would weaken the
            // causal specialist design. Same-niche duplicates remain blocked
            // above; niche diversity is the invariant that matters here.

            $selected->push($agent);
            $usedNiches[$nicheKey] = ($usedNiches[$nicheKey] ?? 0) + 1;
            $usedFamilies[] = $agent->strategy_family;
            $this->sealPortfolioResearchContract($agent, $niche);
            if ($selected->count() >= $limit) break;
        }

        return $selected->values();
    }

    /**
     * Do not spend another sealed replay on an identical parameter topology
     * that already failed full/forward evidence on the same market. A new
     * data snapshot may justify a fresh hypothesis, but the generator must
     * create a materially different fingerprint first; otherwise evolution
     * merely replays the same screen overfit under a new generation number.
     */
    private function knownFailedPortfolioFingerprints(Collection $agents): array
    {
        $sample = $agents->first();
        $symbol = (string) data_get($sample, 'symbol', '');
        $timeframe = (string) data_get($sample, 'timeframe', '');
        if ($symbol === '' || $timeframe === '') return [];

        $failedModelIds = ModelMarketPerformance::query()
            ->where('symbol', $symbol)->where('timeframe', $timeframe)
            ->where('status', 'overfit')->pluck('model_version_id')->all();

        $failedAgentIds = CandidateGateDecision::query()
            ->where('stage', 'statistical_forward_gate')->where('decision', 'failed')
            ->whereHas('labAgent', fn ($query) => $query->where('symbol', $symbol)->where('timeframe', $timeframe))
            ->pluck('lab_agent_id')->all();
        if ($failedAgentIds !== []) {
            $failedModelIds = array_merge(
                $failedModelIds,
                LabAgent::query()->whereIn('id', $failedAgentIds)->pluck('model_version_id')->all(),
            );
        }

        return ModelVersion::query()->whereIn('id', array_values(array_unique($failedModelIds)))
            ->get(['metadata'])
            ->map(fn (ModelVersion $model): ?string => data_get($model->metadata, 'parameter_fingerprint'))
            ->filter(fn ($fingerprint): bool => is_string($fingerprint) && $fingerprint !== '')
            ->unique()->values()->all();
    }

    private function parameterFingerprint(object $agent): string
    {
        return (string) data_get($agent, 'modelVersion.metadata.parameter_fingerprint', '');
    }

    /**
     * A bounded evidence lane prevents a deadlock: no failed screening agent
     * may be promoted, but an isolated one-gene experiment still needs a
     * frozen full replay before it can ever earn causal credit. These probes
     * are research evidence only and are capped per market.
     */
    public function selectCausalProbes(Collection $agents, int $limit = 2): Collection
    {
        return $agents->filter(function ($agent): bool {
            if ($agent->origin !== 'causal_isolation') return false;
            // A causal probe is a replay-purpose label, not an exception to
            // screening survival. Failed screens belong in diagnostic rescue;
            // they cannot consume full-replay capacity or accidentally share
            // a path with promotion evidence.
            if (! $this->isWorthFullReplay($agent)) return false;
            if ((int) $agent->sample_count < 5 || (float) $agent->profit_factor < .40) return false;
            return $this->validOpportunities($agent) > 0;
        })->sortByDesc(fn ($agent) => [
            (float) $agent->profit_factor,
            (int) $agent->sample_count,
            $this->coverage($agent),
            -(float) $agent->max_drawdown,
        ])->take($limit)->values();
    }

    /** Include one same-family control for every probe so paired causal credit
     * has a real alternative rather than remaining pending forever. */
    public function selectCausalProbeBundle(Collection $agents, int $limit = 2): Collection
    {
        $probes = $this->selectCausalProbes($agents, $limit);
        $bundle = $probes->values();
        foreach ($probes as $probe) {
            $control = $agents->filter(fn ($candidate) => $candidate->strategy_family === $probe->strategy_family
                && $candidate->id !== $probe->id && $candidate->origin !== 'causal_isolation'
                && (int) $candidate->sample_count >= 5 && $this->validOpportunities($candidate) > 0)
                ->sortByDesc(fn ($candidate) => [(float) $candidate->profit_factor, (int) $candidate->sample_count])
                ->first();
            if ($control) $bundle->push($control);
        }
        return $bundle->unique('id')->values();
    }

    private function isWorthFullReplay(object $agent): bool
    {
        // A candidate with an explicit failed screening gate is a learning
        // case, not a scarce full-replay candidate.  Older test/legacy rows
        // may not have a gate record; those still use the defensive metric
        // fallback below, but new operational rows must carry the decision.
        $screening = CandidateGateDecision::query()
            ->where('lab_agent_id', $agent->id ?? null)
            ->where('stage', 'screening')
            ->latest('evaluated_at')
            ->first();
        if ($screening && $screening->decision !== 'passed') {
            return false;
        }
        $survival = (array) data_get($this->result($agent), 'screening_survival', []);
        if ($survival !== [] && data_get($survival, 'status') !== 'survivor') return false;

        // This is not a promotion gate. It only avoids burning a historical
        // replay on a candidate that emitted no observable evidence at all.
        return (int) $agent->sample_count >= (int) config('services.lab_selection.minimum_screening_trades', 10)
            && (float) $agent->forward_score > 0
            && (float) $agent->profit_factor > 0
            && $this->validOpportunities($agent) > 0;
    }

    private function isPortfolioSeed(object $agent): bool
    {
        // Volume experiments have their own control/quality/promotion
        // contract. They are not portfolio-member seeds, even when an older
        // parent projection left a stale portfolio contract on the child.
        if (data_get($agent->modelVersion?->metadata, 'volume_research_contract.protocol') === 'volume_council_v1') {
            return false;
        }
        // Portfolio research is deliberately a separate admission lane. A
        // specialist may miss a global calendar/regime gate while still
        // carrying useful, cost-aware evidence inside its sealed niche. The
        // old call to isWorthFullReplay() rejected every such near-miss first
        // because that method correctly treats any failed screen as ineligible
        // for standalone replay. That made the complementary-agent design
        // unreachable in production. Keep the standalone gate unchanged and
        // admit only the narrow research-only near-miss contract here.
        if (! $this->isAllowedPortfolioNearMiss($agent)
            || (int) $agent->sample_count < 30
            || (float) $agent->profit_factor < 1.3
            || $this->stressRobustness($agent) < 1.05
            || (float) ($agent->risk_of_ruin ?? 0) > 10
            || $this->validOpportunities($agent) <= 0) return false;

        $survival = $this->result($agent)['screening_survival'] ?? [];
        $reasons = array_values((array) data_get($survival, 'reason_codes', []));
        // A specialist is allowed to fail outside its declared operating
        // envelope. That is the point of a portfolio: a trend-down member
        // need not pretend to have positive range PF before it can be tested
        // beside a range specialist. Temporal, stress and parameter failures
        // remain hard research exclusions. This is not a promotion
        // exception: the member must still pass full replay and the combined
        // global portfolio gate before any forward/paper proxy can exist.
        $allowedNearMisses = [
            'FAILED_CALENDAR_MONTH_SURVIVAL',
            'FAILED_TRAIN_FORWARD_GAP',
            'FAILED_REGIME_COVERAGE',
        ];
        if (array_diff($reasons, $allowedNearMisses) !== []) return false;
        if ((float) data_get($survival, 'train_forward_gap', 999) > 60) return false;
        $niche = $this->portfolioNiche($agent);
        // A high PF on five trades is not a specialist; it is an attractive
        // small-denominator hypothesis.  The previous exception admitted
        // exactly those members into the sealed council and allowed a
        // 6-trade member to influence the combined portfolio.  Keep the
        // research lane complementary, but require a real niche sample
        // before spending a full replay and before that member can affect a
        // future portfolio selection frontier.
        if ($niche['trades'] < 10) return false;

        $declared = (array) data_get($agent->modelVersion?->metadata, 'portfolio_council_lane', []);
        $direction = strtoupper((string) data_get($declared, 'direction', ''));
        if ($direction !== '' && $direction !== 'ANY') {
            $directionEvidence = $this->portfolioDirectionalEvidence($agent, $niche, $direction);
            // A directional council seat must have its own evidence.  The
            // aggregate regime PF cannot be borrowed when the contract says
            // the child owns only BUY or only SELL.
            if ($directionEvidence === null
                || (int) data_get($directionEvidence, 'trades', 0) < 8
                || (float) data_get($directionEvidence, 'net_pf', data_get($directionEvidence, 'profit_factor', 0)) < 1.1) {
                return false;
            }
        }

        return true;
    }

    private function portfolioCalendarStabilityScore(object $agent): float
    {
        $result = $this->result($agent);
        $months = (array) data_get($result, 'screening_survival.calendar_month_survival.months', []);
        if ($months === []) {
            $months = (array) data_get($result, 'pf_attribution.breakdown.by_month', []);
        }

        $observed = collect($months)->filter(fn ($row): bool => is_array($row) && (int) data_get($row, 'trades', 0) >= 2);
        $positive = $observed->filter(fn (array $row): bool => (float) data_get($row, 'profit_factor', 0) >= 1.0)->count();
        $failures = $observed->filter(fn (array $row): bool => (float) data_get($row, 'profit_factor', 0) < 1.0)->count();
        $monthRatio = $observed->count() > 0 ? $positive / $observed->count() : 0.0;

        $chunks = (array) data_get($result, 'screening_survival.temporal_chunk_survival.window_profit_factors', []);
        $chunkValues = collect($chunks)->filter(fn ($value): bool => is_numeric($value));
        $chunkRatio = $chunkValues->count() > 0
            ? $chunkValues->filter(fn ($value): bool => (float) $value >= 1.0)->count() / $chunkValues->count()
            : 0.0;

        // The score is a ranking aid only.  The unchanged monthly/temporal
        // gates remain the authority for full validation and promotion.
        return round(($monthRatio * 2.0) + $chunkRatio - ($failures * .15), 6);
    }

    private function portfolioDirectionalEvidence(object $agent, array $niche, string $direction): ?array
    {
        $key = (string) ($niche['regime'] ?? '').'|'.(string) ($niche['volatility'] ?? '');
        if ($key === '|') return null;
        $row = data_get(
            $this->result($agent),
            "pf_attribution.breakdown.by_regime_volatility_direction.{$key}.{$direction}",
        );

        return is_array($row) ? $row : null;
    }

    /**
     * A portfolio seed may be a controlled near-miss, never a weak candidate.
     * Only global calendar, train-forward gap, or declared regime coverage
     * failures are admissible. Profit, stress, temporal survival, trade count,
     * parameter stability and risk failures remain hard exclusions. The
     * resulting member is tagged portfolio_member and can only be evaluated
     * by a later sealed combined replay; it can never create standalone
     * forward or paper evidence.
     */
    private function isAllowedPortfolioNearMiss(object $agent): bool
    {
        $screening = CandidateGateDecision::query()
            ->where('lab_agent_id', $agent->id ?? null)
            ->where('stage', 'screening')
            ->latest('evaluated_at')
            ->first();

        if ($screening && $screening->decision === 'passed') return true;

        $resultReasons = array_values(array_unique(array_filter((array) data_get(
            $this->result($agent),
            'screening_survival.reason_codes',
            []
        ))));
        $ledgerReasons = $screening
            ? array_values(array_unique(array_filter((array) $screening->reason_codes)))
            : [];
        $reasons = array_values(array_unique(array_merge($resultReasons, $ledgerReasons)));
        if ($reasons === []) return false;

        $allowed = [
            'FAILED_CALENDAR_MONTH_SURVIVAL',
            'FAILED_TRAIN_FORWARD_GAP',
            'FAILED_REGIME_COVERAGE',
        ];

        return array_diff($reasons, $allowed) === [];
    }

    private function portfolioNiche(object $agent): array
    {
        $result = $this->result($agent);
        // Portfolio routing is a two-dimensional contract. Prefer the
        // persisted regime x volatility ledger whenever available; choosing
        // the best regime and best volatility independently can manufacture
        // a niche that never actually had a positive trade sample.
        $contextRows = (array) data_get($result, 'pf_attribution.breakdown.by_regime_volatility', []);
        $declared = (array) data_get($agent->modelVersion?->metadata, 'portfolio_council_lane', []);
        $declaredRegime = (string) data_get($declared, 'regime', '');
        $declaredVolatility = (string) data_get($declared, 'volatility', '');
        $declaredKey = $declaredRegime !== '' && $declaredVolatility !== ''
            ? $declaredRegime.'|'.$declaredVolatility : null;
        // A council child is created to test one exact envelope. Prefer that
        // sealed declaration over the best global context so screening cannot
        // silently relabel a weak trend-up/range specialist as a strong
        // trend-down member.
        if ($declaredKey !== null && array_key_exists($declaredKey, $contextRows)) {
            $row = (array) $contextRows[$declaredKey];
            return [
                'regime' => $declaredRegime,
                'volatility' => $declaredVolatility,
                'profit_factor' => (float) data_get($row, 'net_pf', data_get($row, 'profit_factor', 0)),
                'trades' => (int) data_get($row, 'trades', 0),
                'evidence_protocol' => 'sealed_regime_volatility_intersection_v1',
            ];
        }
        if ($declaredKey !== null) {
            // A council member with no observation in its declared envelope
            // is not allowed to borrow another niche's PF. Returning an
            // empty declared row makes the normal research floor reject it
            // instead of manufacturing complementarity from unrelated trades.
            return [
                'regime' => $declaredRegime,
                'volatility' => $declaredVolatility,
                'profit_factor' => 0.0,
                'trades' => 0,
                'evidence_protocol' => 'sealed_regime_volatility_intersection_v1',
            ];
        }
        $bestContext = null;
        foreach ($contextRows as $key => $row) {
            $parts = explode('|', (string) $key, 2);
            if (count($parts) !== 2 || ! is_array($row)) continue;
            $trades = (int) data_get($row, 'trades', 0);
            if ($trades < 5) continue;
            $pf = (float) data_get($row, 'net_pf', data_get($row, 'profit_factor', 0));
            $candidate = [
                'regime' => $parts[0], 'volatility' => $parts[1],
                'profit_factor' => $pf, 'trades' => $trades,
                'evidence_protocol' => 'sealed_regime_volatility_intersection_v1',
            ];
            if ($bestContext === null
                || $candidate['profit_factor'] > $bestContext['profit_factor']
                || ($candidate['profit_factor'] === $bestContext['profit_factor'] && $candidate['trades'] > $bestContext['trades'])) {
                $bestContext = $candidate;
            }
        }
        if ($bestContext !== null) return $bestContext;

        $regimePfs = (array) data_get($result, 'statistical_evidence.edge_quality.regime_pf', []);
        $regimeRows = (array) data_get($result, 'regime_performance', []);
        $bestRegime = null;
        foreach ($regimePfs as $regime => $pf) {
            $trades = (int) data_get($regimeRows, "{$regime}.trades", 0);
            if ($trades < 10) continue;
            $candidate = ['regime' => (string) $regime, 'profit_factor' => (float) $pf, 'trades' => $trades];
            if ($bestRegime === null || $candidate['profit_factor'] > $bestRegime['profit_factor']) $bestRegime = $candidate;
        }
        if ($bestRegime === null) $bestRegime = ['regime' => 'unproven', 'profit_factor' => 0.0, 'trades' => 0];

        $volatilityRows = (array) data_get($result, 'volatility_performance', []);
        $bestVolatility = 'any';
        $bestVolatilityScore = -INF;
        foreach ($volatilityRows as $volatility => $row) {
            $trades = (int) data_get($row, 'trades', 0);
            if ($trades < 10) continue;
            // Screening rows expose profit percent rather than PF for this
            // breakdown. Use PF when present, otherwise use the observed
            // niche net result only to assign a router envelope; regime PF
            // remains the admission criterion.
            $score = (float) data_get($row, 'profit_factor', data_get($row, 'net_pf', data_get($row, 'profit_percent', 0)));
            if ($score > $bestVolatilityScore) {
                $bestVolatility = (string) $volatility;
                $bestVolatilityScore = $score;
            }
        }

        return [
            'regime' => $bestRegime['regime'],
            'volatility' => $bestVolatility,
            'profit_factor' => $bestRegime['profit_factor'],
            'trades' => $bestRegime['trades'],
            'evidence_protocol' => 'legacy_independent_axes_v1',
        ];
    }

    private function sealPortfolioResearchContract(object $agent, array $niche): void
    {
        $model = $agent->modelVersion;
        if (! $model) return;
        $metadata = $model->metadata ?? [];
        // The generation plan is the sealed hypothesis.  A later broad
        // fallback (best observed regime) must never relabel a council child
        // into another niche merely because its screen had more trades there.
        // Keep the exact regime x volatility declaration that the generator
        // assigned, while still requiring the measured niche evidence below.
        $declared = (array) data_get($metadata, 'portfolio_council_lane', []);
        if (data_get($declared, 'protocol') === 'portfolio_council_v1'
            && filled(data_get($declared, 'regime'))
            && filled(data_get($declared, 'volatility'))) {
            $niche['regime'] = (string) data_get($declared, 'regime');
            $niche['volatility'] = (string) data_get($declared, 'volatility');
            if (filled(data_get($declared, 'direction'))) {
                $niche['direction'] = strtoupper((string) data_get($declared, 'direction'));
            }
        }
        $metadata['portfolio_research_contract'] = [
            'protocol' => 'portfolio_member_research_v1',
            'status' => 'screening_seed',
            'target_regime' => $niche['regime'],
            'target_volatility' => $niche['volatility'],
            'target_direction' => filled($niche['direction'] ?? null) ? strtoupper((string) $niche['direction']) : 'any',
            'screening_agent_id' => $agent->id,
            'promotion_rule' => 'standalone_forward_passport_required; member_never_promotes_as_champion; combined_portfolio_after_passports',
            'standalone_forward_required' => true,
            'combined_replay_only_after_individual_passports' => true,
        ];
        $model->update(['metadata' => $metadata]);
        if (method_exists($agent, 'setRelation')) {
            $agent->setRelation('modelVersion', $model->fresh());
        } else {
            // Keeps the selector deterministic for lightweight diagnostic
            // objects used by replay-selection tests and offline audits.
            $agent->modelVersion = $model->fresh();
        }
    }

    private function isNearDuplicate(object $candidate, Collection $front): bool
    {
        $metrics = data_get($candidate, 'modelVersion.metadata.last_result.behavioral_diversity', []);
        if (data_get($metrics, 'status') === 'near_duplicate') return true;
        // Screening currently has no batch diversity evidence. Do not discard
        // a different family simply because its indicators look similar; full
        // replay computes signal/trade/equity similarity before promotion.
        return false;
    }

    private function dominates(object $left, object $right): bool
    {
        $betterOrEqual = (float) $left->profit_factor >= (float) $right->profit_factor
            && $this->coverage($left) >= $this->coverage($right)
            && $this->rollingConsistency($left) >= $this->rollingConsistency($right)
            && $this->stressRobustness($left) >= $this->stressRobustness($right)
            && (float) $left->forward_score >= (float) $right->forward_score
            && (float) $left->max_drawdown <= (float) $right->max_drawdown
            && (float) $left->risk_of_ruin <= (float) $right->risk_of_ruin
            && (int) $left->sample_count >= (int) $right->sample_count;
        $strictlyBetter = (float) $left->profit_factor > (float) $right->profit_factor
            || $this->coverage($left) > $this->coverage($right)
            || $this->rollingConsistency($left) > $this->rollingConsistency($right)
            || $this->stressRobustness($left) > $this->stressRobustness($right)
            || (float) $left->forward_score > (float) $right->forward_score
            || (float) $left->max_drawdown < (float) $right->max_drawdown
            || (float) $left->risk_of_ruin < (float) $right->risk_of_ruin
            || (int) $left->sample_count > (int) $right->sample_count;
        return $betterOrEqual && $strictlyBetter;
    }

    private function result(object $agent): array
    {
        return (array) data_get($agent, 'modelVersion.metadata.last_screen_result', []);
    }

    private function validOpportunities(object $agent): int
    {
        return (int) data_get($this->result($agent), 'opportunity_metrics.valid_signal_opportunities', data_get($this->result($agent), 'entry_funnel.flat_signal_opportunities', $agent->sample_count ?? 0));
    }

    private function coverage(object $agent): float
    {
        $result = $this->result($agent);
        $opportunities = $this->validOpportunities($agent);
        return (float) data_get($result, 'opportunity_metrics.coverage', $opportunities ? (int) data_get($result, 'entry_funnel.accepted_entries', 0) / $opportunities : 0);
    }

    private function rollingConsistency(object $agent): int
    {
        return (int) data_get($this->result($agent), 'monthly_passport.rolling_forward_wins', data_get($this->result($agent), 'window_survival.positive_windows', 0));
    }

    private function stressRobustness(object $agent): float
    {
        // Screening stores the compact stress metric under
        // screening_survival; full replay stores the sealed attribution.
        // Reading only the latter silently discards strong near-miss seeds
        // before they can receive their one research replay.
        return (float) data_get(
            $this->result($agent),
            'pf_attribution.stress_cost.profit_factor',
            data_get($this->result($agent), 'screening_survival.stress_cost_pf', $agent->profit_factor)
        );
    }

    private function survivalScore(object $agent): float { return data_get($this->result($agent), 'screening_survival.status') === 'survivor' ? 1.0 : 0.0; }
    private function worstRegimePf(object $agent): float { return (float) data_get($this->result($agent), 'screening_survival.worst_regime_pf', 0); }
    private function worstWindowPf(object $agent): float { return (float) data_get($this->result($agent), 'screening_survival.worst_window_pf', 0); }
    private function worstCalendarMonthPf(object $agent): float
    {
        $result = $this->result($agent);
        $explicit = data_get($result, 'screening_survival.worst_calendar_month_pf');
        if (is_numeric($explicit)) return (float) $explicit;

        $months = (array) data_get($result, 'screening_survival.calendar_month_survival.months', []);
        if ($months === []) $months = (array) data_get($result, 'pf_attribution.breakdown.by_month', []);
        $values = collect($months)->filter(fn ($row): bool => is_array($row) && (int) data_get($row, 'trades', 0) >= 2)
            ->map(fn (array $row): float => (float) data_get($row, 'profit_factor', data_get($row, 'net_pf', 0)))
            ->filter(fn (float $value): bool => $value > 0);
        return $values->isEmpty() ? 0.0 : (float) $values->min();
    }
    private function parameterStability(object $agent): float { return (float) data_get($this->result($agent), 'screening_survival.parameter_perturbation_ratio', 0); }
    private function signalTimingStability(object $agent): float { return (float) data_get($this->result($agent), 'screening_survival.signal_timing_stability', 0); }
    private function trainForwardGap(object $agent): float { return (float) data_get($this->result($agent), 'screening_survival.train_forward_gap', 999); }
}
