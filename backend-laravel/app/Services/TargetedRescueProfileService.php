<?php

namespace App\Services;

use App\Models\CandidateGateDecision;
use App\Models\LabEvaluationRun;
use App\Models\LabFailureRepairAnchor;
use App\Models\LabGeneration;

/** Builds an auditable failure curriculum without turning failures into priors. */
class TargetedRescueProfileService
{
    /** @return array<string, mixed> */
    public function forGeneration(LabGeneration $generation): array
    {
        $generation->loadMissing('laboratory', 'agents.modelVersion');
        $agentIds = $generation->agents->pluck('id')->values()->all();
        $decisions = CandidateGateDecision::query()
            ->whereIn('lab_agent_id', $agentIds)
            ->where('stage', 'screening')
            ->orderByDesc('id')
            ->get()
            ->groupBy('lab_agent_id')
            ->map(fn ($rows) => $rows->first())
            ->values();

        $reasonCounts = [];
        $targetCounts = [];
        $decompositionCounts = [];
        $incompleteAgentIds = [];
        $technicalExcludedAgentIds = [];
        $nearMisses = [];
        $evidence = app(LabImmutableEvidenceService::class);
        foreach ($decisions as $decision) {
            $reasons = array_values(array_unique(array_map('strtoupper', (array) $decision->reason_codes)));
            $run = LabEvaluationRun::query()
                ->where('lab_agent_id', $decision->lab_agent_id)
                ->where('phase', 'screening')
                ->latest('id')->first();
            $eligible = $run ? $evidence->learningEligibility($run) : ['complete' => false];
            $technical = ! $eligible['complete']
                || collect($reasons)->contains(fn (string $reason): bool =>
                    $reason !== 'INSUFFICIENT_SCREENING_EVIDENCE'
                    && (str_contains($reason, 'EVIDENCE')
                        || str_contains($reason, 'TECHNICAL')
                        || str_contains($reason, 'SNAPSHOT')
                        || str_contains($reason, 'DATASET'))
                );
            if ($technical) {
                $incompleteAgentIds[] = (int) $decision->lab_agent_id;
                $technicalExcludedAgentIds[] = (int) $decision->lab_agent_id;
                continue;
            }
            foreach ($reasons as $reason) {
                if ($reason === '') continue;
                $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
                $target = $this->targetForReason($reason);
                if ($target !== null) $targetCounts[$target] = ($targetCounts[$target] ?? 0) + 1;
            }
            $decomposition = (array) data_get($decision->metrics, 'causal_funnel_attribution.failure_decomposition', []);
            $primaryMode = (string) data_get($decomposition, 'primary_failure_mode', '');
            if ($primaryMode !== '') $decompositionCounts[$primaryMode] = ($decompositionCounts[$primaryMode] ?? 0) + 1;
            $margin = (array) data_get($decision->metrics, 'gate_margin', []);
            if ($margin === []) {
                $margin = app(GateMarginService::class)->screening((array) $decision->metrics, $reasons);
            }
            $nearMisses[] = [
                'agent_id' => (int) $decision->lab_agent_id,
                'score' => (float) data_get($margin, 'near_miss_score', 0),
                'dominant_target' => (string) data_get($margin, 'dominant_target', $this->targetForReason((string) ($reasons[0] ?? '')) ?: ''),
                'failure_specific_lane' => $this->failureSpecificLane($margin, $reasons),
                'target_margin' => data_get($margin, 'target_margin'),
                'total_normalized_deficit' => (float) data_get($margin, 'total_normalized_deficit', 0),
                'reason_codes' => $reasons,
                'failure_decomposition' => $decomposition,
                'promotion_evidence' => false,
            ];
        }
        arsort($reasonCounts);
        arsort($targetCounts);
        arsort($decompositionCounts);

        $symbol = strtoupper((string) $generation->laboratory?->symbol);
        $timeframe = strtoupper((string) $generation->laboratory?->timeframe);
        // This service is also called directly by the operator-approved
        // rescue command. Reconcile anchors at this boundary so that command
        // cannot silently lose the immutable failed vectors.
        // Resolve an existing repair lineage before recording new anchors.
        // A legacy targeted generation may lack child metadata but still
        // point to an earlier cohort in its immutable source context. In that
        // case no new anchor is allowed: creating one would fork/reset the
        // bounded three-attempt policy.
        $inheritedAnchorIds = $this->inheritedRepairAnchorIds($generation);
        $repairAnchors = $inheritedAnchorIds === []
            ? app(FailureRepairAnchorService::class)->recordFromHandoff($generation, 'screening')
            : [];
        $cellAudit = app(LabFailureStudyService::class)->cellAnalysisForGeneration($generation);
        $nearMisses = collect($nearMisses)->sortByDesc('score')->values()->all();
        $dominantTarget = (string) (array_key_first($targetCounts) ?: '');
        $nearMiss = collect($nearMisses)
            ->first(fn (array $row): bool => $dominantTarget === '' || (string) ($row['dominant_target'] ?? '') === $dominantTarget)
            ?: ($nearMisses[0] ?? null);

        // A repair sibling must continue using the original immutable anchor.
        // recordFromHandoff() intentionally does not create a child anchor for
        // a failed sibling, otherwise every cohort would reset the three-clean
        // attempt budget. Recover that existing anchor descriptor from the
        // sibling metadata so the next targeted generation remains a bounded
        // four-sibling-plus-control cohort instead of silently falling back to
        // the legacy twenty-seat curriculum.
        $nearMissAgentIds = collect($nearMisses)->pluck('agent_id');
        $existingAnchorIds = $generation->agents
            ->filter(fn ($agent): bool => $nearMissAgentIds->contains((int) $agent->id))
            ->map(fn ($agent): int => (int) data_get($agent->modelVersion?->metadata, 'repair_anchor.id', 0))
            ->filter()
            ->all();
        $existingAnchorIds = array_values(array_unique(array_filter([
            ...$inheritedAnchorIds,
            ...$existingAnchorIds,
        ])));
        if ($existingAnchorIds !== []) {
            $existingDescriptors = LabFailureRepairAnchor::query()
                ->whereIn('id', $existingAnchorIds)
                ->where('status', 'open')
                ->get()
                ->map(fn (LabFailureRepairAnchor $anchor): array => app(FailureRepairAnchorService::class)->descriptor($anchor))
                ->all();
            $repairAnchors = collect([...$repairAnchors, ...$existingDescriptors])
                ->filter(fn (mixed $anchor): bool => is_array($anchor) && filled(data_get($anchor, 'id')))
                ->unique('id')
                ->values()
                ->all();
        }
        $selectedAnchor = collect($repairAnchors)->first(function (array $anchor) use ($nearMiss, $dominantTarget): bool {
            if (! is_array($nearMiss)) return false;

            return (int) data_get($anchor, 'source_lab_agent_id') === (int) data_get($nearMiss, 'agent_id')
                && ($dominantTarget === '' || (string) data_get($anchor, 'failure_target') === $dominantTarget);
        });
        $selectedAnchor ??= collect($repairAnchors)->first(fn (array $anchor): bool =>
            $dominantTarget === '' || (string) data_get($anchor, 'failure_target') === $dominantTarget
        );
        $anchorCohort = is_array($selectedAnchor) && filled(data_get($selectedAnchor, 'id'));
        // The next admitted research cohort is always the structural causal
        // cohort.  Repair anchors remain immutable diagnostic inputs, but a
        // five-seat scalar rescue can no longer consume the whole budget or
        // bypass a frozen-control pair/independent-evidence contract.
        $structuralCohort = app(StructuralResearchCohortService::class);
        $cohortMode = StructuralResearchCohortService::COHORT_MODE;
        $controlParity = app(FrozenControlParityService::class)->assess($generation);
        $failureSpecificPlan = [
            'temporal_stability' => [
                'specialist_role' => 'temporal_calendar_specialist',
                'hypothesis_protocol' => LabPopulationService::TEMPORAL_STATE_PERSISTENCE_HYPOTHESIS,
                'hypothesis' => 'Repeated chronological/session/side weakness is tested as executable transition, post-loss and weak-regime state persistence; indicator timing remains frozen and parameter-only mutations are excluded.',
                'genes' => [
                    'transition_firewall_enabled',
                    'max_loss_streak_before_wait', 'loss_cooldown_candles',
                    'loss_streak_wait_candles', 'weak_regime_wait_candles',
                ],
                'direction_rule' => [
                    'transition_firewall_enabled' => 'enable',
                    'max_loss_streak_before_wait' => 'decrease',
                    'loss_cooldown_candles' => 'decrease',
                    'loss_streak_wait_candles' => 'increase',
                    'weak_regime_wait_candles' => 'increase',
                ],
                'already_active_controls' => ['dynamic_cooldown_enabled' => true],
                'historically_exhausted_controls' => ['transition_wait_candles', 'session_filter_enabled'],
                'observability_guard' => [
                    'transition_wait_candles' => 'requires_transition_firewall_enabled',
                    'session_filter_enabled' => 'requires_non_full_session_window',
                    'loss_cooldown_candles_increase' => 'capped_in_trend_normal_dynamic_cooldown',
                ],
                'non_target_parent_freeze' => true,
                'promotion_evidence' => false,
            ],
            // A train/forward gap is a robustness problem, not a calendar
            // problem. The split and evaluation contract remain immutable;
            // only bounded calibration/abstention genes may be probed.
            'train_forward_robustness' => [
                'specialist_role' => 'robustness_split_specialist',
                'genes' => [
                    'confidence_calibration_min_samples', 'weak_regime_min_samples',
                    'meta_label_min_history', 'cooldown_shadow_min_samples',
                ],
                'split_contract' => 'immutable_same_train_forward_split',
                'evaluation_contract_mutation' => false,
            ],
            'stress_cost' => ['specialist_role' => 'cost_stability_specialist', 'genes' => ['atr_stop_multiplier', 'atr_target_multiplier', 'max_spread_atr_ratio', 'trailing_atr_multiplier']],
            'regime_coverage' => ['specialist_role' => 'regime_coverage_specialist', 'genes' => ['trend_up_strength_min', 'trend_down_strength_min', 'trend_up_roc_period', 'trend_down_roc_period']],
            'drawdown_risk' => ['specialist_role' => 'non_target_regression_specialist', 'genes' => ['time_stop_candles', 'partial_target_atr_multiplier', 'partial_take_profit_fraction', 'max_loss_streak_before_wait']],
            'profit_factor' => ['specialist_role' => 'edge_quality_specialist', 'genes' => ['minimum_signal_confidence', 'atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier']],
        ];
        $selectedFailureLane = (string) data_get($nearMiss, 'failure_specific_lane', $dominantTarget);
        if (! isset($failureSpecificPlan[$selectedFailureLane])) $selectedFailureLane = $dominantTarget;
        $selectedAnchors = $anchorCohort ? [$selectedAnchor] : $repairAnchors;
        $selectedTargets = $anchorCohort && $dominantTarget !== '' ? [$dominantTarget] : array_values(array_unique([
            ...array_keys($targetCounts), 'profit_factor', 'stress_cost', 'temporal_stability', 'regime_coverage',
        ]));
        $failureSpecificLanesObserved = collect($nearMisses)
            ->pluck('failure_specific_lane')->filter()->unique()->values()->all();
        // Train/forward is intentionally retained as a separate robustness
        // lane even though the legacy anchor target taxonomy keeps the
        // temporal target for lineage compatibility.
        if (($reasonCounts['FAILED_TRAIN_FORWARD_GAP'] ?? 0) > 0
            && ! in_array('train_forward_robustness', $failureSpecificLanesObserved, true)) {
            $failureSpecificLanesObserved[] = 'train_forward_robustness';
        }
        $profile = [
            'protocol' => LabPopulationService::TARGETED_RESCUE_PROFILE_PROTOCOL,
            'rescue_curriculum' => $anchorCohort ? LabPopulationService::GATE_MARGIN_RESCUE_CURRICULUM : LabPopulationService::TARGETED_RESCUE_CURRICULUM,
            'rescue_protocol' => LearningProtocolSafetyService::CONTROLLED_RESCUE_PROTOCOL,
            'temporary' => true,
            'population_size' => StructuralResearchCohortService::POPULATION_SIZE,
            'group_plan' => $structuralCohort->groupPlan(),
            'cohort_mode' => $cohortMode,
            'cohort_contract' => $structuralCohort->contract([
                'source_generation_id' => (int) $generation->id,
            ]),
            'structural_research_contract' => $structuralCohort->contract([
                'source_generation_id' => (int) $generation->id,
            ]),
            'source_generation_id' => (int) $generation->id,
            'source_generation' => (int) $generation->generation,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'reason_counts' => $reasonCounts,
            'target_counts' => $targetCounts,
            'failure_decomposition_counts' => $decompositionCounts,
            'primary_failure_mode' => (string) (array_key_first($decompositionCounts) ?: 'mixed_or_insufficient'),
            'primary_decomposition_lane' => (string) data_get(
                collect($nearMisses)->first(fn (array $row): bool => data_get($row, 'failure_decomposition.recommended_experiment_lane') !== null),
                'failure_decomposition.recommended_experiment_lane',
                'hold_or_replicate',
            ),
            'targets' => $selectedTargets,
            'targeted_repair_lanes' => [
                [
                    'lane' => 'profit_factor',
                    'objective' => 'edge_quality',
                    'target' => 'profit_factor',
                    'control_pair_required' => true,
                    'same_generation' => true,
                    'stress_cost' => false,
                ],
                [
                    'lane' => 'stress_cost',
                    'objective' => 'cost_stability',
                    'target' => 'stress_cost',
                    'control_pair_required' => true,
                    'same_generation' => true,
                    'stress_cost' => true,
                ],
                [
                    'lane' => 'temporal_survival',
                    'objective' => 'temporal_survival',
                    'target' => 'temporal_stability',
                    'control_pair_required' => true,
                    'same_generation' => true,
                    'stress_cost' => false,
                ],
                [
                    'lane' => 'regime_coverage',
                    'objective' => 'regime_coverage',
                    'target' => 'regime_coverage',
                    'control_pair_required' => true,
                    'same_generation' => true,
                    'stress_cost' => false,
                ],
                [
                    'lane' => 'non_target_regression',
                    'objective' => 'protected_invariants',
                    'target' => 'non_target_regression',
                    'control_pair_required' => true,
                    'same_generation' => true,
                    'stress_cost' => false,
                ],
            ],
            'incomplete_evidence_agent_ids' => array_values(array_unique($incompleteAgentIds)),
            'technical_excluded_agent_ids' => array_values(array_unique($technicalExcludedAgentIds)),
            'repair_anchors' => $selectedAnchors,
            'repair_anchor_protocol' => FailureRepairAnchorService::PROTOCOL,
            'temporal_edge_audit' => [
                'protocol' => 'temporal_failure_cell_audit_v1',
                'source' => 'immutable_response_artifact_pf_attribution',
                'dominant_cells' => (array) data_get($cellAudit, 'dominant_cells', []),
                'diagnostic_only' => true,
                'promotion_evidence' => false,
            ],
            'near_miss_agents' => array_slice($nearMisses, 0, 12),
            'selected_near_miss' => $nearMiss,
            'dominant_target' => $dominantTarget,
            'failure_specific_lane' => $selectedFailureLane,
            'failure_specific_lanes_observed' => $failureSpecificLanesObserved,
            'failure_specific_plan' => $failureSpecificPlan,
            'temporal_mutation_hypothesis' => $dominantTarget === 'temporal_stability'
                ? (array) data_get($failureSpecificPlan, 'temporal_stability', [])
                : null,
            'frozen_control_parity' => $controlParity,
            'control_recompute_required' => true,
            'actionable_failure_count' => array_sum($targetCounts),
            'causal_prior_allowed' => false,
            'promotion_evidence' => false,
            'rule' => 'Failure observations route a 20-seat structural, control-paired research cohort only; no failed or legacy record becomes a parent, mutation credit or promotion evidence.',
        ];
        $profile['temporal_ablation'] = $dominantTarget === 'temporal_stability'
            ? app(TemporalAblationProtocolService::class)->contract([
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'source_generation_id' => (int) $generation->id,
                'data_hash' => $generation->data_fingerprint
                    ?: data_get($generation->trigger_context, 'canonical_dataset_snapshots.price.manifest.snapshot_sha256'),
                'execution_hash' => data_get($generation->trigger_context, 'execution_contract.execution_hash'),
            ])
            : null;
        $profile['anchor_fingerprint'] = (string) (
            data_get($selectedAnchor, 'anchor_fingerprint')
            ?: data_get($selectedAnchor, 'parameter_fingerprint')
            ?: ((int) data_get($selectedAnchor, 'id', 0) > 0 ? 'anchor:'.(int) data_get($selectedAnchor, 'id') : 'anchor:none')
        );
        $profile['hypothesis_hash'] = app(RescueCircuitBreakerService::class)->hypothesisHash($profile);
        $profile['source_data_fingerprint'] = (string) ($generation->data_fingerprint
            ?: data_get($generation->trigger_context, 'canonical_dataset_snapshots.price.manifest.snapshot_sha256')
            ?: data_get($generation->trigger_context, 'canonical_dataset_snapshots.price.manifest.sha256')
            ?: 'unknown');
        $profile['profile_hash'] = hash('sha256', json_encode($profile, JSON_UNESCAPED_SLASHES));

        return $profile;
    }

    /** @return array<int, int> */
    private function inheritedRepairAnchorIds(LabGeneration $generation): array
    {
        $ids = $generation->agents
            ->map(fn ($agent): int => (int) data_get($agent->modelVersion?->metadata, 'repair_anchor.id', 0))
            ->filter()
            ->all();
        if ($ids !== []) {
            $ids = array_values(array_unique(array_filter($ids)));
            foreach ($generation->agents as $agent) {
                $anchorId = (int) data_get($agent->modelVersion?->metadata, 'repair_anchor.id', 0);
                if ($anchorId <= 0 || ! in_array($anchorId, $ids, true)) continue;

                $decision = CandidateGateDecision::query()
                    ->where('lab_agent_id', $agent->id)
                    ->where('stage', 'screening')
                    ->latest('id')
                    ->first();
                if (! $decision) continue;

                $anchor = LabFailureRepairAnchor::query()->find($anchorId);
                if (! $anchor || ! app(FailureRepairAnchorService::class)->snapshotMatches($anchor, (array) $decision->metrics)) {
                    // The inherited anchor belongs to a different immutable
                    // data stream. Do not fall back to an older lineage here;
                    // recordFromHandoff() will create a fresh baseline while
                    // preserving the stale anchor as historical evidence.
                    return [];
                }
            }

            return $ids;
        }

        $sourceGenerationId = (int) data_get($generation->trigger_context, 'targeted_failure_profile.source_generation_id', 0);
        $visited = [(int) $generation->id];
        for ($depth = 0; $depth < 4 && $sourceGenerationId > 0; $depth++) {
            if (in_array($sourceGenerationId, $visited, true)) break;
            $visited[] = $sourceGenerationId;
            $sourceGeneration = LabGeneration::query()->find($sourceGenerationId);
            if (! $sourceGeneration) break;
            $sourceIds = [];
            foreach ((array) data_get($sourceGeneration->trigger_context, 'targeted_failure_profile.repair_anchors', []) as $anchor) {
                $anchorId = (int) data_get($anchor, 'id', 0);
                if ($anchorId > 0) $sourceIds[] = $anchorId;
            }
            // The nearest source cohort owns the active bounded repair
            // lineage. Do not merge older historical anchors into the same
            // profile: that would make anchor selection non-deterministic and
            // could rewind the wrong attempt counter.
            if ($sourceIds !== []) return array_values(array_unique(array_filter($sourceIds)));
            $sourceGenerationId = (int) data_get($sourceGeneration->trigger_context, 'targeted_failure_profile.source_generation_id', 0);
        }

        return array_values(array_unique(array_filter($ids)));
    }

    private function targetForReason(string $reason): ?string
    {
        return app(FailureRepairAnchorService::class)->targetForReason($reason) ?? match ($reason) {
            'FAILED_PROFIT_FACTOR' => 'profit_factor',
            'FAILED_STRESS_COST' => 'stress_cost',
            'FAILED_TEMPORAL_CHUNK_SURVIVAL',
            'FAILED_CALENDAR_MONTH_SURVIVAL',
            'FAILED_TRAIN_FORWARD_GAP',
            'FAILED_TEMPORAL_SCORE_DRIFT',
            'FAILED_PARAMETER_STABILITY',
            'FAILED_SIGNAL_TIMING_STABILITY' => 'temporal_stability',
            'FAILED_REGIME_COVERAGE',
            'INSUFFICIENT_REGIME_EVIDENCE',
            'FAILED_TRANSITION' => 'regime_coverage',
            'FAILED_NON_TARGET_REGRESSION' => 'drawdown_risk',
            'FAILED_DRAWDOWN',
            'FAILED_RUIN' => 'drawdown_risk',
            'FAILED_OVERFIT',
            'FAILED_STATISTICAL' => 'architecture',
            default => null,
        };
    }

    /**
     * Select the most deficient gate that the observed failure actually
     * names. This keeps train/forward robustness separate from temporal and
     * calendar survival while preserving the existing target taxonomy used by
     * anchors and legacy tests.
     */
    private function failureSpecificLane(array $margin, array $reasons): string
    {
        $reasonGates = [
            'FAILED_TRAIN_FORWARD_GAP' => 'train_forward_robustness',
            'FAILED_TEMPORAL_SCORE_DRIFT' => 'temporal_stability',
            'FAILED_TEMPORAL_CHUNK_SURVIVAL' => 'temporal_stability',
            'FAILED_CALENDAR_MONTH_SURVIVAL' => 'calendar_stability',
            'FAILED_MONTHLY_SURVIVAL' => 'calendar_stability',
            'FAILED_PARAMETER_STABILITY' => 'parameter_stability',
            'FAILED_SIGNAL_TIMING_STABILITY' => 'temporal_stability',
            'FAILED_STRESS_COST' => 'stress_cost',
            'FAILED_EXECUTION_STRESS_GATE' => 'stress_cost',
            'FAILED_REGIME_COVERAGE' => 'regime_coverage',
            'INSUFFICIENT_REGIME_EVIDENCE' => 'regime_coverage',
            'FAILED_TRANSITION' => 'regime_coverage',
            'FAILED_NON_TARGET_REGRESSION' => 'drawdown_risk',
            'FAILED_DRAWDOWN' => 'drawdown_risk',
            'FAILED_RUIN' => 'ruin_risk',
            'FAILED_RUIN_RISK' => 'ruin_risk',
            'FAILED_PROFIT_FACTOR' => 'profit_factor',
            'FAILED_NON_POSITIVE_SCORE' => 'profit_factor',
        ];
        $candidates = collect($reasons)
            ->map(fn (string $reason): ?string => $reasonGates[$reason] ?? null)
            ->filter()
            ->unique()
            ->filter(fn (string $gate): bool => data_get($margin, 'gates.'.$gate.'.status') !== 'unknown')
            ->sortBy(fn (string $gate): float => (float) data_get($margin, 'gates.'.$gate.'.normalized_margin', INF));

        return (string) ($candidates->first() ?? data_get($margin, 'dominant_target', 'profit_factor'));
    }
}
