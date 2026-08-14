<?php

namespace App\Services;

use App\Models\CandidateGateDecision;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Models\LabAgent;
use App\Models\MutationMemory;
use App\Models\PaperTradingEvaluation;
use App\Services\MarketData\MarketReadinessService;
use Illuminate\Support\Facades\DB;

class MarketChampionService
{
    public function __construct(
        private StrategyParameterSchemaService $schemas,
        private AgentDiagnosisService $diagnoses,
        private DecisionLearningService $decisionLearning,
        private MarketReadinessService $marketReadiness,
        private PaperEvidenceReadinessService $paperEvidence,
        private ForwardGateProgressService $gateProgress,
        private CandidateGateDecisionService $gateDecisions,
        private EliteAgentPassportService $passport,
        private CalendarAlignmentEvidenceService $calendarAlignment,
        private AgentEvolutionQualityService $evolutionQuality,
        private UniversalAgentCapabilityService $universalCapabilities,
        private EliteEcosystemService $eliteEcosystem,
        private SequentialPaperEvidenceService $sequentialPaperEvidence,
        private VetoPolicyLaboratoryService $vetoPolicies,
        private RegimeReservoirService $regimeReservoir,
        private TransferMatrixService $transferMatrix,
        private FailureCurriculumService $failureCurriculum,
        private CandidateHandoffService $handoffs,
        private CausalMutationCreditService $causalMutationCredit,
        private StrategySemanticGroupService $semanticGroups,
        private MutationSkillVerificationService $mutationSkills,
        private ParentContributionGraphService $parentGraphService,
    ) {}

    public function evaluate(string $strategy, string $symbol, string $timeframe, int $fitness, array $result, ?ModelVersion $modelVersion = null): ModelMarketPerformance
    {
        return DB::transaction(function () use ($strategy, $symbol, $timeframe, $fitness, $result, $modelVersion): ModelMarketPerformance {
            $result['evidence_streams'] = array_merge([
                'synthetic_forward_evidence' => ['status' => 'assessed', 'promotion_sufficient' => false],
                'real_time_paper_evidence' => ['status' => 'required', 'promotion_sufficient' => false],
            ], (array) ($result['evidence_streams'] ?? []));
            // Lab agents may share a strategy family or even a runtime label.
            // A full replay must therefore carry the immutable model identity;
            // resolving it by strategy name can silently attach evidence to an
            // older agent and leave the evaluated agent in `training` forever.
            if ($modelVersion !== null && (string) $modelVersion->strategy !== $strategy) {
                throw new \InvalidArgumentException('Champion evaluation strategy/model identity mismatch.');
            }
            $model = $modelVersion !== null
                ? ModelVersion::query()->whereKey($modelVersion->getKey())->where('evidence_status', 'valid')->lockForUpdate()->firstOrFail()
                : ModelVersion::query()->where('strategy', $strategy)->where('evidence_status', 'valid')->lockForUpdate()->firstOrFail();
            $family = $this->schemas->family($strategy);
            // A champion belongs to the candidate's semantic group, not to
            // every strategy that happens to share a family label. A trend
            // council member, router and generic family specialist must not
            // overwrite one another's champion baseline.
            $champion = $this->groupChampion($symbol, $timeframe, $family, $model);

            $windowScores = array_values($result['forward_window_scores'] ?? []);
            $forwardWindowEvidence = $this->mutationSkills->independentForwardWindows($result);
            $observedForwardWindows = max(
                (int) data_get($forwardWindowEvidence, 'observed_windows', 0),
                count($windowScores),
            );
            $positiveForwardWindows = (int) data_get($forwardWindowEvidence, 'positive_windows', 0);
            if ($positiveForwardWindows === 0 && $windowScores !== []) {
                $positiveForwardWindows = collect($windowScores)
                    ->filter(fn ($score): bool => (float) $score > 0)->count();
            }
            // A missing champion is not evidence that a checkpoint won. The
            // forward gate requires at least three genuinely positive replay
            // windows, independently of any champion comparison.
            $passportMonths = (array) data_get($result, 'monthly_passport.months', []);
            $wins = $passportMonths !== []
                ? (int) data_get($result, 'monthly_passport.rolling_forward_wins', 0)
                : $positiveForwardWindows;
            $forward = (float) ($result['forward_score'] ?? 0);
            $sampleCount = (int) ($result['total_trades'] ?? 0);
            // Keep the measured rolling result in the immutable gate ledger;
            // otherwise a later diagnostic would confuse an absent payload
            // field with zero rolling wins.
            $result['rolling_forward_wins'] = $wins;
            $result['forward_window_protocol'] = [
                ...$forwardWindowEvidence,
                'observed_windows' => $observedForwardWindows,
                'positive_windows' => $positiveForwardWindows,
                'promotion_evidence' => false,
            ];
            $result['challenger_protocol'] = [
                'protocol' => 'frozen_champion_2_3_challenger_v1',
                'independent_forward_windows_required' => 3,
                'observed_forward_windows' => $observedForwardWindows,
                'positive_forward_windows' => $wins,
                'champion_replacement_rule' => 'replace only after all independent forward windows and cost-adjusted gates pass',
                'stateful_checkpoint_diagnostic_only' => (bool) data_get($forwardWindowEvidence, 'stateful_diagnostic_only', false),
                'promotion_evidence' => (bool) data_get($forwardWindowEvidence, 'independence_verified', false)
                    && ! (bool) data_get($forwardWindowEvidence, 'stateful_diagnostic_only', false),
            ];
            $agent = LabAgent::query()->with(['mutationMemories', 'generation'])->where('model_version_id', $model->id)->latest()->first();
            $learningLane = $agent !== null
                && data_get($agent->modelVersion?->metadata, 'learning_lane.protocol') === LearningLaneService::PROTOCOL
                && data_get($agent->modelVersion?->metadata, 'learning_lane.promotion_evidence', false) !== true;
            if ($learningLane) {
                $result['learning_lane'] = [
                    ...((array) data_get($agent->modelVersion?->metadata, 'learning_lane', [])),
                    'promotion_evidence' => false,
                ];
            }
            if ($agent && (int) data_get($agent->modelVersion?->metadata, 'repair_anchor.id', 0) > 0) {
                // The repair contract must be available before passport and
                // backtest gates are built. Waiting until the memory update
                // would make every valid repair look unverified at the gate.
                $result['no_regression_contract'] = $this->evolutionQuality->noRegressionContract(
                    app(FailureRepairAnchorService::class)->baselineResult($agent),
                    $result,
                );
                $result['repair_anchor_verification'] = app(FailureRepairAnchorService::class)
                    ->verifyRepairCandidate($agent, $result);
            }
            $result['trial_ledger'] = [
                'generation_id' => $agent?->lab_generation_id, 'model_version_id' => $model->id,
                'generation_trials' => $agent ? LabAgent::query()->where('lab_generation_id', $agent->lab_generation_id)->count() : 1,
                'selection_count' => $agent ? LabAgent::query()->where('lab_generation_id', $agent->lab_generation_id)->whereIn('lifecycle_status', ['full_queued', 'training', 'challenger', 'forward_validated'])->count() : 1,
                'rule' => 'Forward evidence records trial multiplicity; raw PF is never interpreted without selection context.',
            ];
            $result['veto_policy_lab'] = $agent ? $this->vetoPolicies->evaluate($agent) : ['status' => 'waiting_for_lab_agent'];
            $result['transfer_matrix'] = $this->transferMatrix->sync($model, $result);
            $result['epistemic_boundary'] = app(UniversalAgentCapabilityService::class)->selfKnowledge($result);
            $result['cross_market_transfer_passport'] = $this->universalCapabilities->transferPassport($model, $result);
            $result['pass_k_reliability'] = $this->universalCapabilities->passKReliability($result);
            // Compute the parent/control identity before the passport is
            // built. Otherwise a repaired child would receive a passport
            // that predates its paired replay even when the sibling cohort
            // has already supplied the required alternative.
            if ($agent) {
                $parentModelIds = $this->parentGraphService->ids($agent);
                $parentPerformances = $parentModelIds === []
                    ? collect()
                    : ModelMarketPerformance::query()->with('modelVersion')
                        ->whereIn('model_version_id', $parentModelIds)
                        ->where('symbol', $symbol)->where('timeframe', $timeframe)
                        ->latest('id')->get()
                        ->filter(fn (ModelMarketPerformance $candidate): bool =>
                            $candidate->modelVersion !== null
                            && $this->semanticGroups->sameGroup(
                                $model,
                                $family,
                                $candidate->modelVersion,
                                (string) $candidate->strategy_family,
                            )
                        )
                        ->unique('model_version_id')
                        ->sortBy(fn (ModelMarketPerformance $candidate): int => (int) array_search(
                            (int) $candidate->model_version_id,
                            $parentModelIds,
                            true,
                        ))
                        ->values();
                $result['parent_model_version_ids'] = $parentPerformances->pluck('model_version_id')->values()->all();
                $result['paired_replay'] = $this->pairedReplayProjection(
                    $agent,
                    $parentPerformances->first()?->metrics,
                    $result,
                    $parentPerformances->all(),
                );
            }
            // Official calendar alignment is joined only after the sealed
            // market replay. Missing historical events remain an explicit
            // passport failure; no provider gap is converted into a pass.
            $result = $this->calendarAlignment->enrich($symbol, $timeframe, $result);
            // A role-complete council child must carry its professional exam
            // projection into the same immutable passport build. Recording
            // the exams only after the forward decision would allow a stale
            // passport to reach the gate before hidden-state/drift/router
            // evidence was considered.
            if ($agent && data_get($model->metadata, 'role_complete_council.protocol') === 'role_complete_council_v1') {
                $result['professional_exams'] = app(\App\Services\AgentProfessionalExamService::class)
                    ->assessAndRecord($agent, $model, null, $result, null);
            }
            $elitePassport = $this->passport->build($model, $agent, $result);
            $elitePassport = $this->passport->freezeIfFinalist($model, $elitePassport, $result);
            $result['elite_agent_passport'] = $elitePassport;
            $model->update(['metadata' => array_merge($model->metadata ?? [], ['elite_agent_passport' => $elitePassport])]);

            $performance = ModelMarketPerformance::query()->updateOrCreate(
                ['model_version_id' => $model->id, 'symbol' => $symbol, 'timeframe' => $timeframe],
                [
                    'strategy_family' => $family, 'fitness' => $fitness, 'forward_score' => $forward,
                    'sample_count' => $sampleCount, 'rolling_windows_count' => $observedForwardWindows,
                    'rolling_forward_wins' => $wins, 'metrics' => $result,
                    'status' => $champion?->model_version_id === $model->id ? 'champion' : 'challenger',
                    'champion_slot' => $champion?->model_version_id === $model->id ? 'champion' : null,
                ],
            );
            $result['failure_curriculum'] = $this->failureCurriculum->evaluate($performance, $result);
            // Curriculum evidence is available only after the immutable
            // performance row exists. Rebuild the passport so v4 candidates
            // see the same P0 curriculum contract as the gate evaluator.
            $elitePassport = $this->passport->build($model, $agent, $result);
            $elitePassport = $this->passport->freezeIfFinalist($model, $elitePassport, $result);
            $result['elite_agent_passport'] = $elitePassport;
            $model->update(['metadata' => array_merge($model->metadata ?? [], ['elite_agent_passport' => $elitePassport])]);
            $performance->update(['metrics' => $result]);

            $result['trial_ledger'] = array_merge(
                (array) ($result['trial_ledger'] ?? []),
                app(LabTrialLedgerService::class)->record(
                    $agent, $model, $symbol, $timeframe, 'full_replay', $result,
                    data_get($result, 'evidence_run_id'),
                ),
            );
            $performance->update(['metrics' => $result]);

            if ($learningLane) {
                // Learning-lane replays are deliberately economic
                // observations, not a hidden forward/paper shortcut.  Even a
                // score that beats the current frontier stays a challenger
                // until it enters the ordinary screen -> full -> forward
                // protocol in a fresh promotion lane.
                $performance->update([
                    'status' => 'challenger',
                    'champion_slot' => null,
                    'paper_status' => null,
                ]);
            } elseif ($champion?->id === $performance->id) {
                $performance->update(['status' => 'champion', 'champion_slot' => 'champion']);
            } elseif ($this->backtestGatesPass($performance, $champion, $result)) {
                $performance->update(['status' => 'forward_validated', 'champion_slot' => null]);
                $paperEvaluation = PaperTradingEvaluation::firstOrCreate(
                    ['model_market_performance_id' => $performance->id, 'status' => 'pending'],
                    ['started_at' => now()],
                );
                if ($agent) {
                    $this->handoffs->record($agent->generation, $agent, 'paper_eligible', 'completed', null, [
                        'performance_id' => $performance->id,
                        'paper_evaluation_id' => $paperEvaluation->id,
                        'next_action' => 'capture_immutable_paper_signal',
                        'gate_decision_unchanged' => true,
                    ]);
                }
                if ($performance->paper_status === 'passed') {
                    $this->promote($performance, $champion, $model);
                }
            } elseif (! $champion || $champion->id !== $performance->id) {
                $stagnation = $performance->consecutive_no_improvement + 1;
                $failedStatus = (bool) ($result['is_overfit'] ?? false)
                    ? 'overfit'
                    : (((float) ($result['profit_factor'] ?? 0) < 0.8
                        || (float) data_get($result, 'monte_carlo.risk_of_ruin_percent', 0) > 30)
                        ? 'rejected'
                        : ($stagnation >= 3 ? 'stagnated' : 'challenger'));
                $performance->update([
                    'consecutive_no_improvement' => $stagnation,
                    'status' => $failedStatus,
                    'champion_slot' => null,
                ]);
            }

            $this->updateLabAgentAndMemory($performance->fresh(), $champion, $result);
            // The atlas and Red-Queen records are learning evidence only. They
            // do not turn a challenger into a paper candidate and therefore
            // cannot weaken the promotion protocol.
            $this->eliteEcosystem->sync(
                $performance->fresh(),
                $result,
                $this->evolutionQuality->capabilityVector($result),
            );
            $result['regime_reservoir'] = $this->regimeReservoir->sync($performance->fresh(), $result);
            $performance->update(['metrics' => [...($performance->metrics ?? []), 'regime_reservoir' => $result['regime_reservoir'],
                'veto_policy_lab' => $result['veto_policy_lab'], 'transfer_matrix' => $result['transfer_matrix']]]);
            $this->diagnoses->diagnose($performance->fresh(), $result);
            $this->decisionLearning->learn($performance->fresh(), $result);
            // Bind the parent counterfactual before recordForward evaluates
            // the parent-benefit contract. This is still research evidence;
            // it only makes an already-observed A/B/C branch visible to the
            // gate and never grants promotion by itself.
            if ($agent) {
                try {
                    $preForwardParentCredit = app(ParentAwareCreditService::class)->recordFullReplay(
                        $agent->fresh(['modelVersion']),
                        $result,
                        $performance->fresh(),
                        null,
                    );
                    $result['parent_aware_credit'] = $preForwardParentCredit;
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }
            $forwardDecision = $this->gateDecisions->recordForward($performance->fresh(), $result);
            $repairQuarantined = $agent && ! $learningLane
                ? $this->applyRepairQuarantine($agent, $model, $performance, $forwardDecision, $result)
                : false;
            // The gate ledger is the authoritative evaluation record; mirror
            // its immutable decision into the operational handoff so the
            // screened -> ... -> forward_gate chain has no missing endpoint.
            if ($agent) {
                // updateLabAgentAndMemory has now computed the verified skill
                // contract. Only at this point can a screened Seed become a
                // Mentor; the projection never opens a promotion gate.
                try {
                    $mentorContract = null;
                    if ($learningLane) {
                        $learningProjection = app(LearningLaneService::class)->recordFullReplayObservation(
                            $agent->fresh(['modelVersion']),
                            $performance->fresh(),
                            $result,
                        );
                        $result['learning_lane_projection'] = $learningProjection;
                        $mentorContract = data_get($agent->fresh('modelVersion')->modelVersion->metadata, 'skill_mentor');
                        $result['skill_mentor'] = $mentorContract;
                        $result['mutation_response_map'] = [
                            'id' => data_get($learningProjection, 'response_map_id'),
                            'status' => data_get($learningProjection, 'status', 'learning_observed'),
                            'promotion_evidence' => false,
                        ];
                    } else {
                        $mentorContract = app(SkillMentorService::class)->recordFullReplayOutcome(
                            $agent->fresh(['modelVersion']),
                            $performance->fresh(),
                            $result,
                            $forwardDecision,
                        );
                        $result['skill_mentor'] = $mentorContract;
                        $result['mutation_response_map'] = app(MutationResponseMapService::class)->recordFullReplay(
                            $agent->fresh(['modelVersion']),
                            $result,
                            $performance->fresh(),
                            data_get($result, 'repair_anchor_verification', data_get($result, 'verified_mutation_skill')),
                        );
                        if ((int) data_get($result, 'repair_anchor_verification.repair_anchor_id', 0) > 0) {
                            $result['repair_anchor_forward_outcome'] = app(FailureRepairAnchorService::class)
                                ->recordRepairForwardOutcome(
                                    $agent->fresh(['modelVersion']),
                                    (array) data_get($result, 'repair_anchor_verification', []),
                                    $result,
                                );
                        }
                    }
                    $performance->update(['metrics' => [...((array) $performance->metrics), 'skill_mentor' => $mentorContract, 'mutation_response_map' => $result['mutation_response_map'] ?? ['status' => 'learning_projection_unavailable', 'promotion_evidence' => false]]]);
                } catch (\Throwable $exception) {
                    report($exception);
                }
                try {
                    // Parent-aware credit is deliberately downstream of the
                    // immutable full-replay/forward decision. It records
                    // performance, learning and discovery separately and
                    // keeps parent credit blocked until autonomous/mentored/
                    // ablated branches are observed on the same contract.
                    $parentAwareCredit = app(ParentAwareCreditService::class)->recordFullReplay(
                        $agent->fresh(['modelVersion']),
                        $result,
                        $performance->fresh(),
                        $forwardDecision,
                    );
                    $result['parent_aware_credit'] = $parentAwareCredit;
                    $performance->update([
                        'metrics' => [
                            ...((array) $performance->metrics),
                            'parent_aware_credit' => $parentAwareCredit,
                            'promotion_evidence' => false,
                        ],
                    ]);
                } catch (\Throwable $exception) {
                    report($exception);
                }
                $this->handoffs->record($agent->generation, $agent, 'forward_gate', $forwardDecision->decision, null, [
                    'candidate_gate_decision_id' => $forwardDecision->id,
                    'performance_id' => $performance->id,
                    'reason_codes' => $forwardDecision->reason_codes,
                    'next_action' => $repairQuarantined
                        ? 'repair_lineage_quarantined'
                        : ($forwardDecision->decision === 'passed' ? 'paper_eligibility_review' : 'targeted_generation'),
                    'repair_quarantine' => $repairQuarantined,
                ]);
                try {
                    app(AgentProgressCardService::class)->sync(
                        $agent->fresh(['modelVersion', 'generation']),
                        $performance->fresh(),
                        $result,
                        $forwardDecision,
                    );
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }

            return $performance->fresh();
        });
    }

    public function recordPaperResult(ModelMarketPerformance $performance, array $metrics): ModelMarketPerformance
    {
        return DB::transaction(function () use ($performance, $metrics): ModelMarketPerformance {
            $performance = ModelMarketPerformance::query()->where('evidence_status', 'valid')->lockForUpdate()->findOrFail($performance->id);
            $sampleCount = (int) ($metrics['sample_count'] ?? 0);
            $profitFactor = (float) ($metrics['profit_factor'] ?? 0);
            $drawdown = (float) ($metrics['max_drawdown'] ?? 100);
            $minimumSamples = max(50, (int) config('services.promotion.paper_min_samples', 50));
            // This is an observational, anytime-valid sequence.  The existing
            // immutable >=50 real paper outcome requirement remains decisive.
            $metrics['sequential_evidence'] = $this->sequentialPaperEvidence->observe($performance, $metrics);
            $passed = $sampleCount >= $minimumSamples && $profitFactor >= 1.3 && $drawdown <= 15
                && (float) ($metrics['net_profit_percent'] ?? 0) > 0;
            $status = $passed ? 'passed' : ($sampleCount >= $minimumSamples ? 'failed' : 'running');
            $performance->update([
                'paper_status' => $status, 'paper_sample_count' => $sampleCount,
                'paper_profit_factor' => $profitFactor, 'paper_max_drawdown' => $drawdown,
                'status' => $passed ? 'paper' : ($status === 'failed' ? 'rejected' : 'forward_validated'),
            ]);
            PaperTradingEvaluation::updateOrCreate(
                ['model_market_performance_id' => $performance->id, 'status' => $status],
                ['sample_count' => $sampleCount, 'profit_factor' => $profitFactor, 'max_drawdown' => $drawdown,
                    'net_profit_percent' => $metrics['net_profit_percent'] ?? 0, 'metrics' => $metrics,
                    'started_at' => now(), 'completed_at' => $status === 'running' ? null : now()],
            );

            $agent = LabAgent::where('model_version_id', $performance->model_version_id)->latest()->first();
            $agent?->update(['lifecycle_status' => $performance->fresh()->status, 'decision_reason' => $passed ? 'Paper trading gate passed.' : 'Paper trading evidence insufficient or failed.']);
            $paperDecision = $this->gateDecisions->recordPaper($performance->fresh(), $metrics);
            if ($agent) {
                try {
                    app(AgentProgressCardService::class)->sync(
                        $agent->fresh(['modelVersion', 'generation']),
                        $performance->fresh(),
                        [...$metrics, 'evidence_run_id' => data_get($metrics, 'evidence_run_id')],
                        $paperDecision,
                    );
                } catch (\Throwable $exception) {
                    report($exception);
                }
            }
            return $performance->fresh();
        });
    }

    private function pairedReplayProjection(?LabAgent $agent, ?array $parentResult, array $current, array $parentPerformances = []): array
    {
        if (! $agent) {
            return ['protocol' => 'paired_parent_child_replay_v1', 'status' => 'pending', 'promotion_evidence' => false];
        }
        $parents = collect($parentPerformances)
            ->filter(fn ($candidate): bool => $candidate instanceof ModelMarketPerformance)
            ->values();
        if ($parents->isEmpty() && is_array($parentResult) && $parentResult !== []) {
            $parents = collect([[
                'model_version_id' => $agent->parent_a_model_version_id ?: $agent->parent_b_model_version_id,
                'metrics' => $parentResult,
            ]]);
        }
        $parentRows = $parents->map(function ($parent) use ($agent, $current): array {
            $metrics = $parent instanceof ModelMarketPerformance ? (array) $parent->metrics : (array) data_get($parent, 'metrics', []);
            $parentModelId = $parent instanceof ModelMarketPerformance
                ? (int) $parent->model_version_id
                : (int) data_get($parent, 'model_version_id', 0);
            $experiment = $this->evolutionQuality->pairedExperiment($agent, $metrics, $current);
            $sameData = filled(data_get($metrics, 'data_manifest.sha256'))
                && data_get($metrics, 'data_manifest.sha256') === data_get($current, 'data_manifest.sha256');
            $sameExecution = filled(data_get($metrics, 'execution_contract.execution_hash'))
                && data_get($metrics, 'execution_contract.execution_hash') === data_get($current, 'execution_contract.execution_hash');
            return [
                'parent_model_version_id' => $parentModelId,
                'status' => data_get($experiment, 'status') === 'confirmed' && $sameData && $sameExecution
                    ? 'confirmed' : data_get($experiment, 'status', 'pending'),
                'same_data_hash' => $sameData,
                'same_execution_hash' => $sameExecution,
                'experiment' => $experiment,
            ];
        })->values();
        $sameData = $parentRows->isNotEmpty() && $parentRows->every(fn (array $row): bool => $row['same_data_hash'] === true);
        $sameExecution = $parentRows->isNotEmpty() && $parentRows->every(fn (array $row): bool => $row['same_execution_hash'] === true);
        $firstParentRow = $parentRows->first() ?? [];
        $experiment = $firstParentRow['experiment'] ?? $this->evolutionQuality->pairedExperiment($agent, $parentResult, $current);
        $status = $parentRows->isNotEmpty() && $parentRows->every(fn (array $row): bool => $row['status'] === 'confirmed')
            && $sameData && $sameExecution ? 'confirmed' : data_get($experiment, 'status', 'pending');
        return [
            'protocol' => 'paired_parent_child_replay_v1',
            'status' => $status,
            'parent_model_version_id' => $firstParentRow['parent_model_version_id'] ?? ($agent->parent_a_model_version_id ?: $agent->parent_b_model_version_id),
            'parent_model_version_ids' => $parentRows->pluck('parent_model_version_id')->filter()->values()->all(),
            'child_model_version_id' => $agent->model_version_id,
            'same_data_hash' => $sameData,
            'same_execution_hash' => $sameExecution,
            'same_cost_contract' => $sameExecution,
            'experiment' => $experiment,
            'per_parent' => $parentRows->all(),
            'promotion_evidence' => false,
            'rule' => 'Every contributing parent must share the replay data and execution-cost identity before a composite parent/child claim is credited.',
        ];
    }

    public function finalizeHoldout(ModelMarketPerformance $performance, array $holdout): ModelMarketPerformance
    {
        return DB::transaction(function() use($performance,$holdout){
            $performance=ModelMarketPerformance::query()->where('evidence_status', 'valid')->lockForUpdate()->findOrFail($performance->id);
            $result=$holdout['result']??[]; $score=(float)($holdout['score']??0);
            $passed=$performance->paper_status==='passed' && $score>=50
                && (float)($result['profit_factor']??0)>=1.3
                && (float)($result['max_drawdown_percent']??100)<=15
                && (float)data_get($result,'monte_carlo.risk_of_ruin_percent',100)<=10
                && (int)($result['total_trades']??0)>=30;
            $performance->update(['holdout_status'=>$passed?'passed':'failed','holdout_score'=>$score,
                'status'=>$passed?'paper':'rejected']);
            if($passed && $this->marketReadiness->promotionReady() && $this->paperEvidence->ready()){$champion=$this->groupChampion(
                $performance->symbol, $performance->timeframe, $performance->strategy_family, $performance->modelVersion,
            );
                if($this->backtestGatesPass($performance,$champion,$performance->metrics??[]))$this->promote($performance,$champion,$performance->modelVersion);}
            LabAgent::where('model_version_id',$performance->model_version_id)->get()->each(fn (LabAgent $agent) => $agent->update([
                'lifecycle_status' => $performance->fresh()->status,
                'decision_reason' => $passed ? 'Sealed holdout and paper gates passed.' : 'Sealed holdout failed.',
            ]));
            $holdoutDecision = $this->gateDecisions->recordHoldout($performance->fresh(), $holdout);
            LabAgent::where('model_version_id', $performance->model_version_id)->get()->each(function (LabAgent $agent) use ($performance, $holdout, $holdoutDecision): void {
                try {
                    app(AgentProgressCardService::class)->sync(
                        $agent->fresh(['modelVersion', 'generation']),
                        $performance->fresh(),
                        [...(array) data_get($holdout, 'result', []), 'evidence_run_id' => data_get($holdout, 'evidence_run_id')],
                        $holdoutDecision,
                    );
                } catch (\Throwable $exception) {
                    report($exception);
                }
            });
            return $performance->fresh();
        });
    }

    private function groupChampion(string $symbol, string $timeframe, string $family, ModelVersion $candidate): ?ModelMarketPerformance
    {
        $candidateHasDeclaredGroup = $this->semanticGroups->hasDeclaredGroup($candidate, $family);
        return ModelMarketPerformance::query()
            ->with('modelVersion')
            ->where(compact('symbol', 'timeframe'))
            ->where('strategy_family', $family)
            ->where('evidence_status', 'valid')
            ->where('status', 'champion')
            ->lockForUpdate()
            ->get()
            ->filter(fn (ModelMarketPerformance $performance): bool => $performance->modelVersion !== null
                && ($candidateHasDeclaredGroup
                    ? $this->semanticGroups->sameGroup(
                        $candidate,
                        $family,
                        $performance->modelVersion,
                        (string) $performance->strategy_family,
                    )
                    // Runtime-only/legacy models predate semantic groups.
                    // They may still be compared as a diagnostic benchmark,
                    // but they are never returned by the genetic parent
                    // boundary in LabPopulationService.
                    : ! $this->semanticGroups->hasDeclaredGroup($performance->modelVersion, (string) $performance->strategy_family)))
            ->sortByDesc('forward_score')
            ->first();
    }

    private function backtestGatesPass(ModelMarketPerformance $candidate, ?ModelMarketPerformance $champion, array $result): bool
    {
        $requiredWins = 3;
        $forwardGain = $champion ? $candidate->forward_score - $champion->forward_score : $candidate->forward_score;
        $strictStatisticalProtocol = (int) data_get($candidate->modelVersion?->metadata, 'statistical_gate_version', 0) >= 2;
        $strictRobustnessProtocol = (int) data_get($candidate->modelVersion?->metadata, 'robustness_gate_version', 0) >= 1;
        $selectionValidation = data_get($result, 'selection_validation', []);
        $deflatedSharpe = data_get($result, 'statistical_evidence.deflated_sharpe', []);
        // A new population may not paper-promote from an unavailable PBO/DSR
        // calculation. CSCV needs competing candidates; DSR needs enough
        // closed trade returns. Their absence is evidence still to gather,
        // not an exemption. Pre-protocol records remain legacy audit data.
        $pboPasses = $strictStatisticalProtocol
            ? data_get($selectionValidation, 'status') === 'assessed'
                && (float) data_get($selectionValidation, 'probability_of_backtest_overfitting', 1) <= 0.50
                && data_get($selectionValidation, 'protocol') === 'purged_embargoed_cscv_v1'
                && (bool) data_get($selectionValidation, 'purge_embargo_applied', false)
                && (bool) data_get($selectionValidation, 'promotion_evidence', false)
            : (data_get($selectionValidation, 'status') !== 'assessed'
                || (float) data_get($selectionValidation, 'probability_of_backtest_overfitting', 1) <= 0.50);
        $dsrPasses = $strictStatisticalProtocol
            ? data_get($deflatedSharpe, 'status') === 'assessed'
                && (float) data_get($deflatedSharpe, 'deflated_sharpe_probability', 0) >= 0.95
            : (data_get($deflatedSharpe, 'status') !== 'assessed'
                || (float) data_get($deflatedSharpe, 'deflated_sharpe_probability', 0) >= 0.95);
        $stressProfile = data_get($result, 'pf_attribution', []);
        $stressCostPasses = data_get($stressProfile, 'method') !== 'identical_replay_execution_profiles'
            || (float) data_get($stressProfile, 'stress_cost.profit_factor', 0) >= 1.05;
        $bootstrap = data_get($result, 'statistical_evidence.edge_quality.bootstrap_pf', []);
        $bootstrapPasses = $strictStatisticalProtocol
            ? data_get($bootstrap, 'status') === 'assessed'
                && (float) data_get($bootstrap, 'pf_5_percentile_lower_bound', 0) >= 1.1
            : (data_get($bootstrap, 'status') !== 'assessed'
                || (float) data_get($bootstrap, 'pf_5_percentile_lower_bound', 0) >= 1.1);
        $edgeQuality = data_get($result, 'statistical_evidence.edge_quality', []);
        $passportPasses = data_get($result, 'elite_agent_passport.status') === 'passed';
        $worstRegimePasses = $strictStatisticalProtocol
            ? (bool) data_get($edgeQuality, 'worst_regime_sampled', false)
                && (float) data_get($edgeQuality, 'worst_regime_pf', 0) >= 1.0
            : (! data_get($edgeQuality, 'worst_regime_sampled', false)
                || (float) data_get($edgeQuality, 'worst_regime_pf', 0) >= 1.0);
        $diverse = data_get($result, 'behavioral_diversity.status') === 'diverse';
        $noisePasses = ! $strictRobustnessProtocol
            || (data_get($result, 'noise_sanity.status') === 'assessed' && (bool) data_get($result, 'noise_sanity.pass', false));
        $executionStressPasses = ! $strictRobustnessProtocol
            || (data_get($result, 'execution_digital_twin.status') === 'assessed' && (bool) data_get($result, 'execution_digital_twin.pass', false));
        $parameterPlateauPasses = ! $strictRobustnessProtocol
            || (data_get($result, 'parameter_plateau.status') === 'assessed'
                && (bool) data_get($result, 'parameter_plateau.pass', false));
        $agent = LabAgent::query()->with('generation')->where('model_version_id', $candidate->model_version_id)->latest('id')->first();
        $freshReplayPasses = $this->gateDecisions->freshReplayGateReasons(
            $agent,
            $result,
            $candidate->symbol,
            $candidate->timeframe,
        ) === [];
        $parentBenefitPasses = $this->gateDecisions->parentBenefitGateReasons($agent, $result) === [];
        $dataQualityPasses = ! $strictRobustnessProtocol
            || (data_get($result, 'data_quality.status') === 'passed'
                && (int) data_get($result, 'data_quality.duplicate_timestamp_count', 0) === 0
                && (int) data_get($result, 'data_quality.non_monotonic_timestamp_pairs', 0) === 0
                && (int) data_get($result, 'data_quality.invalid_ohlc_rows', 0) === 0);
        $goldHoldoutPasses = ! $strictRobustnessProtocol
            || (data_get($result, 'gold_holdout.protocol') === 'gold_holdout_v1'
                && data_get($result, 'gold_holdout.used_for_training') === false
                && data_get($result, 'gold_holdout.used_for_evolution') === false
                && data_get($result, 'gold_holdout.one_time_release') === true);
        $forwardWindowIntegrity = ! $strictRobustnessProtocol
            || (data_get($result, 'forward_window_protocol.independence_verified') === true
                && data_get($result, 'forward_window_protocol.overlap_detected') !== true);
        $challengerProtocolPasses = ! $strictRobustnessProtocol
            || ((int) data_get($result, 'challenger_protocol.observed_forward_windows', 0) >= $requiredWins
                && (int) data_get($result, 'challenger_protocol.positive_forward_windows', 0) >= $requiredWins
                && $forwardWindowIntegrity);
        $candidate->loadMissing('modelVersion');
        $researchOnlySibling = in_array((string) data_get($candidate->modelVersion?->metadata, 'repair_anchor.sibling_kind', ''), ['frozen_control', 'architecture_escape'], true)
            || in_array((string) data_get($candidate->modelVersion?->metadata, 'repair_anchor_sibling.kind', ''), ['frozen_control', 'architecture_escape'], true);
        $repairLineage = (array) data_get($candidate->modelVersion?->metadata, 'repair_lineage', []);
        $repairAnchorId = (int) data_get($candidate->modelVersion?->metadata, 'repair_anchor.id', 0);
        $repairReplayPasses = (int) data_get($repairLineage, 'attempt', 0) === 0
            || ($repairAnchorId > 0
                ? data_get($result, 'repair_anchor_verification.status') === 'confirmed'
                    && data_get($result, 'no_regression_contract.status') === 'passed'
                : (count((array) ($this->agentParameterDiff($candidate) ?? [])) === 1
                    && data_get($result, 'paired_replay.status') === 'confirmed'
                    && data_get($result, 'no_regression_contract.status') === 'passed'));

        return $forwardGain >= ($champion ? 5 : 0)
            && (float) ($result['profit_factor'] ?? 0) >= 1.3
            && (float) ($result['max_drawdown_percent'] ?? $result['max_drawdown'] ?? 100) <= 15
            && (float) data_get($result, 'monte_carlo.risk_of_ruin_percent', 100) <= 10
            && ! (bool) ($result['is_overfit'] ?? true)
            && $candidate->sample_count >= 30
            && $candidate->rolling_windows_count >= $requiredWins
            && $candidate->rolling_forward_wins >= $requiredWins
            && $pboPasses
            && $dsrPasses
            && $stressCostPasses
            && $bootstrapPasses
            && $worstRegimePasses
            && $passportPasses
            && $diverse
            && $noisePasses
            && $executionStressPasses
            && $parameterPlateauPasses
            && $dataQualityPasses
            && $goldHoldoutPasses
            && $challengerProtocolPasses
            && $freshReplayPasses
            && $parentBenefitPasses
            && ! $researchOnlySibling
            && (! $strictRobustnessProtocol || $repairReplayPasses);
    }

    private function agentParameterDiff(ModelMarketPerformance $candidate): ?array
    {
        $agent = LabAgent::query()->where('model_version_id', $candidate->model_version_id)->latest('id')->first();
        return $agent?->parameter_diff;
    }

    private function applyRepairQuarantine(
        LabAgent $agent,
        ModelVersion $model,
        ModelMarketPerformance $performance,
        CandidateGateDecision $decision,
        array $result,
    ): bool {
        if ($decision->decision === 'passed') return false;
        $agent->loadMissing('modelVersion');
        $lineage = (array) data_get($model->metadata, 'repair_lineage', []);
        $attempt = (int) data_get($lineage, 'attempt', 0);
        $statisticalFalsifier = collect((array) $decision->reason_codes)->contains(
            fn ($reason): bool => in_array($reason, [
                'FAILED_OVERFIT', 'FAILED_NOISE_SANITY', 'FAILED_NOISE_SANITY_EVIDENCE',
                'QUARANTINED_PROOF_REPLAY_MISMATCH',
            ], true),
        );
        if ((! $statisticalFalsifier && $attempt < 2) || data_get($lineage, 'status') === 'quarantined') return false;

        $fromStatus = (string) $agent->lifecycle_status;
        $lineage['status'] = 'quarantined';
        $lineage['failed_forward_replays'] = $attempt;
        $lineage['quarantined_at'] = now()->utc()->toIso8601String();
        $lineage['quarantine_reason'] = $statisticalFalsifier
            ? 'Statistical falsifier (DSR/PBO/noise/proof) failed; no further tuning is allowed.'
            : 'Two bounded repair attempts failed the independent forward gate.';
        $metadata = $model->metadata ?? [];
        data_set($metadata, 'repair_lineage', $lineage);
        $model->update(['metadata' => $metadata]);
        $agent->update([
            'lifecycle_status' => 'quarantined',
            'decision_reason' => $statisticalFalsifier
                ? 'Repair lineage quarantined after a statistical falsifier; no further tuning is allowed.'
                : 'Repair lineage quarantined after two failed independent forward replays.',
        ]);
        $performance->update(['status' => 'rejected', 'champion_slot' => null]);
        app(LabImmutableEvidenceService::class)->recordLifecycle($agent, 'repair_lineage_quarantine', [
            'reason_code' => $statisticalFalsifier ? 'FAILED_STATISTICAL_FALSIFIER' : 'FAILED_TWO_REPAIR_REPLAYS',
            'candidate_gate_decision_id' => $decision->id,
            'performance_id' => $performance->id,
            'attempt' => $attempt,
            'result_hash' => hash('sha256', json_encode($result, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES)),
        ], 'statistical_forward_gate', data_get($result, 'evidence_run_id'), null, self::class, null, $fromStatus, 'quarantined');
        return true;
    }

    private function promote(ModelMarketPerformance $candidate, ?ModelMarketPerformance $champion, ModelVersion $model): void
    {
        if ((bool) config('services.promotion.freeze_champion', true)) {
            // Promotion is paused during evidence-pipeline repair. Keep the
            // candidate's forward/paper state and all gate evidence intact;
            // only the final champion mutation is withheld.
            return;
        }
        if (! $this->marketReadiness->promotionReady() || ! $this->paperEvidence->ready()) {
            return;
        }
        if ($candidate->evidence_status !== 'valid' || $model->evidence_status !== 'valid') {
            return;
        }
        if ($champion && $champion->id !== $candidate->id) {
            $champion->update(['status' => 'archived', 'champion_slot' => null, 'archived_at' => now()]);
            LabAgent::where('model_version_id', $champion->model_version_id)->get()->each(
                fn (LabAgent $agent) => $agent->update(['lifecycle_status' => 'archived'])
            );
        }
        $candidate->update(['status' => 'champion', 'champion_slot' => 'champion', 'promoted_at' => now(), 'consecutive_no_improvement' => 0]);
        $model->update(['status' => 'active', 'promoted_at' => now()]);
    }

    private function updateLabAgentAndMemory(ModelMarketPerformance $performance, ?ModelMarketPerformance $champion, array &$result): void
    {
        $agent = LabAgent::where('model_version_id', $performance->model_version_id)->latest()->first();
        if (! $agent) return;
        $agent->loadMissing('modelVersion', 'generation');
        $repairAnchor = (int) data_get($agent->modelVersion?->metadata, 'repair_anchor.id', 0) > 0;
        $skillTree = $this->skillTree($result);
        $delta = $champion ? $performance->forward_score - $champion->forward_score : $performance->forward_score;
        $reason = match ($performance->status) {
            'forward_validated' => 'Backtest gates passed; paper trading required.',
            'overfit' => 'Train-forward gap indicates overfit.',
            'rejected' => 'Risk, profitability or sample gate failed.',
            'stagnated' => 'Three evaluations without improvement.',
            default => 'Evaluation recorded.',
        };
        $agent->update([
            'lifecycle_status' => $performance->status, 'train_score' => $result['train_score'] ?? null,
            'validation_score' => $result['validation_score'] ?? null, 'forward_score' => $performance->forward_score,
            'champion_improvement' => $delta, 'rolling_wins' => $performance->rolling_forward_wins,
            'sample_count' => $performance->sample_count, 'profit_factor' => $result['profit_factor'] ?? null,
            'max_drawdown' => $result['max_drawdown_percent'] ?? $result['max_drawdown'] ?? null,
            'risk_of_ruin' => data_get($result, 'monte_carlo.risk_of_ruin_percent'), 'decision_reason' => $reason,
        ]);
        $observedWorstRegime = collect($result['regime_performance'] ?? [])->sortBy('profit_percent')->keys()->first();
        $regime = $observedWorstRegime ? 'market:'.$observedWorstRegime
            : (data_get($agent->modelVersion?->metadata, 'mutation_scope') ?: 'market:unknown');
        $hardFailure = (float) ($result['profit_factor'] ?? 0) < 1.0
            || (int) ($result['total_trades'] ?? 0) === 0
            || (bool) ($result['is_overfit'] ?? false)
            || (float) data_get($result, 'monte_carlo.risk_of_ruin_percent', 0) > 10
            || (float) ($result['max_drawdown_percent'] ?? $result['max_drawdown'] ?? 0) > 15;
        $parentModelIds = $this->parentGraphService->ids($agent);
        $parentPerformances = $parentModelIds === []
            ? collect()
            : ModelMarketPerformance::query()->with('modelVersion')
                ->whereIn('model_version_id', $parentModelIds)
                ->where('symbol', $agent->symbol)
                ->where('timeframe', $agent->timeframe)
                ->latest('id')
                ->get()
                ->filter(fn (ModelMarketPerformance $candidate): bool =>
                    $candidate->modelVersion !== null
                    && $this->semanticGroups->sameGroup(
                        $agent->modelVersion,
                        $agent->strategy_family,
                        $candidate->modelVersion,
                        (string) $candidate->strategy_family,
                    )
                )
                ->unique('model_version_id')
                ->sortBy(fn (ModelMarketPerformance $candidate): int =>
                    array_search((int) $candidate->model_version_id, $parentModelIds, true) === false
                        ? PHP_INT_MAX
                        : (int) array_search((int) $candidate->model_version_id, $parentModelIds, true)
                )
                ->values();
        // Old agents can retain a parent id that was valid under the loose
        // family-only protocol. The complete graph is resolved first, then
        // every contributor is re-checked at evaluation time so historical
        // metadata cannot create new genetic credit after the protocol
        // upgrade.
        $parentA = $parentPerformances->first(fn (ModelMarketPerformance $candidate): bool =>
            (int) $candidate->model_version_id === (int) ($agent->parent_a_model_version_id ?: 0)
        ) ?: $parentPerformances->first();
        $parentB = $parentPerformances->first(fn (ModelMarketPerformance $candidate): bool =>
            $parentA && (int) $candidate->model_version_id !== (int) $parentA->model_version_id
        );
        $baseline = $parentA
            ? ['type' => $parentPerformances->count() > 1 ? 'parent_contribution_graph' : 'parent_a', 'agent_ids' => $parentPerformances->pluck('model_version_id')->values()->all()]
            : ($parentB ? ['type' => 'parent_b', 'agent_ids' => [$parentB->model_version_id]] : []);
        // `geneticParentPerformance` is the only baseline that may award a
        // mutation skill. The broader frontier below remains useful for
        // ranking diagnostics, but it is never a parent/child replay claim.
        $geneticParentPerformance = $parentA ?: $parentB;
        $parentPerformance = $geneticParentPerformance;
        if ($repairAnchor) {
            // A source performance may be used for a diagnostic score, but it
            // must never open ordinary parent-benefit or genetic credit.
            $geneticParentPerformance = null;
            $geneticParentResult = null;
            $parentPerformance = null;
            $baseline = [
                'type' => 'failure_repair_anchor',
                'repair_anchor_id' => data_get($agent->modelVersion?->metadata, 'repair_anchor.id'),
                'agent_ids' => [],
            ];
        }
        $frontierBaseline = $champion ? [...($champion->metrics ?? []), 'forward_score' => $champion->forward_score] : null;
        if (! $parentPerformance && $champion && ! $repairAnchor) {
            $parentPerformance = $champion;
            $baseline = ['type' => 'family_frontier', 'agent_ids' => [$champion->model_version_id]];
        }
        if (! $parentPerformance && ! $repairAnchor) {
            $previous = ModelMarketPerformance::with('modelVersion')->where('symbol', $agent->symbol)->where('timeframe', $agent->timeframe)
                ->where('strategy_family', $agent->strategy_family)->whereHas('modelVersion', fn ($query) => $query->where('generation', '<', $agent->generation?->generation ?? PHP_INT_MAX))
                ->orderByDesc('forward_score')->first();
            if ($previous) {
                $parentPerformance = $previous;
                $baseline = ['type' => 'previous_generation_frontier', 'agent_ids' => [$previous->model_version_id]];
            }
        }
        $baseline = $baseline ?: ['type' => 'symbol_timeframe_benchmark', 'agent_ids' => []];
        $parentResult = $parentPerformance?->metrics;
        $geneticParentResult = $geneticParentPerformance?->metrics;
        $curriculum = $this->evolutionQuality->curriculum($result);
        $adaptiveParentCount = count((array) data_get(
            $agent->modelVersion?->metadata,
            'adaptive_parent_ecosystem.selected_parent_model_version_ids',
            [],
        ));
        $multiParent = $parentPerformances->count() > 1
            && ($adaptiveParentCount > 1 || in_array($agent->origin, [
                'robust_crossover', 'architecture', 'crossover',
            ], true) || $agent->strategy_family === 'regime_ensemble');
        $noRegression = $repairAnchor
            ? $this->evolutionQuality->noRegressionContract(
                app(FailureRepairAnchorService::class)->baselineResult($agent),
                $result,
            )
            : ($multiParent
            ? $this->evolutionQuality->noRegressionAcrossParents(
                $parentPerformances->map(fn (ModelMarketPerformance $candidate): array => [
                    'model_version_id' => $candidate->model_version_id,
                    'metrics' => (array) $candidate->metrics,
                ])->all(),
                $result,
            )
            : $this->evolutionQuality->noRegressionContract($geneticParentResult, $result));
        if ($repairAnchor) {
            // A repair child has no genetic parent by design. Keep the failed
            // source as an immutable comparison baseline only and publish the
            // explicit verification contract after no-regression is known.
            $result['no_regression_contract'] = $noRegression;
            $result['repair_anchor_verification'] = app(FailureRepairAnchorService::class)
                ->verifyRepairCandidate($agent, $result);
            $repairConfirmed = data_get($result, 'repair_anchor_verification.status') === 'confirmed';
            $repairMetadata = (array) $agent->modelVersion?->metadata;
            data_set($repairMetadata, 'repair_anchor.verification', $result['repair_anchor_verification']);
            data_set($repairMetadata, 'repair_anchor.parent_eligible_after_confirmation', $repairConfirmed);
            data_set($repairMetadata, 'repair_anchor.mutation_credit_status', $repairConfirmed ? 'independently_confirmed' : 'pending_paired_full_replay_forward');
            data_set($repairMetadata, 'repair_lineage.status', $repairConfirmed ? 'confirmed' : 'active');
            data_set($repairMetadata, 'repair_lineage.parent_eligible', $repairConfirmed);
            data_set($repairMetadata, 'repair_lineage.mutation_credit_status', $repairConfirmed ? 'independently_confirmed' : 'pending_paired_full_replay_forward');
            $agent->modelVersion?->update(['metadata' => $repairMetadata]);
        }
        $capabilityVector = $this->evolutionQuality->capabilityVector($result);
        $result['capability_vector'] = $capabilityVector;
        $operatingEnvelope = $this->evolutionQuality->operatingEnvelope($result);
        $pairedReplay = $repairAnchor
            ? [
                'protocol' => FailureRepairAnchorService::PROTOCOL,
                'status' => data_get($result, 'repair_anchor_verification.paired_screening.status') === 'confirmed'
                    ? 'repair_baseline_confirmed' : 'pending',
                'source_model_version_id' => data_get($result, 'repair_anchor_verification.source_model_version_id'),
                'promotion_evidence' => false,
            ]
            : $this->pairedReplayProjection(
            $agent,
            $geneticParentResult,
            $result,
            $parentPerformances->all(),
        );
        $pairedExperiment = (array) data_get($pairedReplay, 'experiment', []);
        $result['paired_replay'] = $pairedReplay;
        $result['no_regression_contract'] = $noRegression;
        $mutationSkillContract = $repairAnchor
            ? [
                'protocol' => FailureRepairAnchorService::PROTOCOL,
                'status' => data_get($result, 'repair_anchor_verification.status', 'not_confirmed'),
                'repair_anchor_id' => data_get($result, 'repair_anchor_verification.repair_anchor_id'),
                'parent_eligible_after_confirmation' => (bool) data_get($result, 'repair_anchor_verification.parent_eligible_after_confirmation', false),
                'promotion_evidence' => false,
            ]
            : $this->mutationSkills->verify(
            $agent,
            $geneticParentPerformance?->modelVersion,
            $geneticParentResult,
            $result,
            (array) $agent->parameter_diff,
            $noRegression,
        );
        $result['verified_mutation_skill'] = $mutationSkillContract;
        $selfKnowledge = $this->universalCapabilities->selfKnowledge($result);
        $retention = $this->universalCapabilities->retention(
            $parentResult,
            $result,
            $multiParent
                ? $parentPerformances->filter(fn (ModelMarketPerformance $candidate): bool =>
                    $candidate->model_version_id !== $geneticParentPerformance?->model_version_id
                )->map(fn (ModelMarketPerformance $candidate): array => [
                    'model_version_id' => $candidate->model_version_id,
                    'capability_vector' => data_get($candidate->metrics, 'capability_vector', []),
                ])->values()->all()
                : [],
        );
        $certification = $this->universalCapabilities->certification($result, $selfKnowledge, $retention);
        $skillAtlas = $this->universalCapabilities->skillAtlas($performance, $capabilityVector);
        $agent->modelVersion?->update(['metadata' => array_merge($agent->modelVersion->metadata ?? [], [
            'skill_tree' => $skillTree, 'capability_vector' => $capabilityVector,
            'operating_envelope' => $operatingEnvelope, 'gate_deficit_curriculum' => $curriculum,
            'no_regression_contract' => $noRegression,
            'epistemic_boundary' => $selfKnowledge, 'skill_retention_exam' => $retention,
            'universal_certification' => $certification, 'quality_diversity_skill_atlas' => $skillAtlas,
        ])]);
        $contractFailure = $noRegression['status'] === 'failed';
        $outcome = ($hardFailure || $contractFailure) ? 'harmful' : ($delta >= 5 ? 'beneficial' : ($delta <= -5 ? 'harmful' : 'neutral'));
        $learningDelta = ($hardFailure || $contractFailure) ? min($delta, -10) : $delta;
        $skillConfirmed = ! $repairAnchor && data_get($mutationSkillContract, 'status') === 'confirmed';
        $repairConfirmed = $repairAnchor && data_get($result, 'repair_anchor_verification.status') === 'confirmed';
        $skillWindowCount = (int) data_get($mutationSkillContract, 'independent_forward_windows.confirmed_windows', 0);
        $skillOutcome = $skillConfirmed && data_get($mutationSkillContract, 'target_gate.improved') === true
            ? 'beneficial'
            : ($hardFailure || $contractFailure ? 'harmful' : 'neutral');
        $executionContractHash = (string) data_get($result, 'execution_contract.execution_hash', '');
        $nonTargetRegressionStatus = (string) data_get(
            $mutationSkillContract,
            'non_target.status',
            data_get($result, 'differential_no_regression.status', 'not_applicable'),
        );
        $beforeGates = is_array($parentResult)
            ? $this->gateProgress->snapshot($parentResult, (int) $parentPerformance->rolling_forward_wins, $frontierBaseline)
            : null;
        $gateTransition = $this->gateProgress->transition(
            $beforeGates,
            // Parent A/B is the local mutation baseline; the same-family
            // champion is the frontier baseline for forward-gain evidence.
            $this->gateProgress->snapshot($result, (int) $performance->rolling_forward_wins, $frontierBaseline),
            $baseline,
        );
        $behavioralEffect = $this->behavioralEffect($parentResult, $result);
        $changedFields = array_keys($agent->parameter_diff ?? []);
        $changedGenes = (array) data_get($mutationSkillContract, 'changed_genes', $changedFields);
        $isSingleMutation = count($changedGenes) === 1;
        $pairedConfirmed = data_get($pairedReplay, 'status') === 'confirmed';
        $g98Lane = (array) data_get($agent->modelVersion?->metadata, 'g98_council_lane', []);
        $declaredContext = (array) data_get($agent->modelVersion?->metadata, 'portfolio_council_lane', []);
        $failureSignature = [
            'protocol' => 'failure_signature_bandit_v1',
            'regime' => data_get($declaredContext, 'regime', data_get($result, 'robustness_matrix.weakest_envelopes.0.regime')),
            'volatility' => data_get($declaredContext, 'volatility', data_get($result, 'robustness_matrix.weakest_envelopes.0.volatility')),
            'session_utc_hour' => data_get($declaredContext, 'session_utc_hour', data_get($result, 'robustness_matrix.weakest_envelopes.0.session')),
            'direction' => data_get($declaredContext, 'direction', data_get($result, 'robustness_matrix.weakest_envelopes.0.direction')),
            'lane' => data_get($g98Lane, 'lane'),
        ];
        $causalCredit = [
            'status' => $repairAnchor
                ? ($repairConfirmed ? 'independently_confirmed' : 'repair_exploratory_no_credit')
                : ($skillConfirmed ? 'independently_confirmed' : ($isSingleMutation ? 'awaiting_verified_skill_confirmation' : 'bundle_unattributed')),
            'parent_model_version_id' => $geneticParentPerformance?->model_version_id,
            'parent_model_version_ids' => $parentPerformances->pluck('model_version_id')->values()->all(),
            'changed_fields' => $changedFields,
            'changed_genes' => $changedGenes,
            'mutation_bundle_id' => hash('sha256', json_encode([$agent->id, $changedFields, data_get($agent->modelVersion?->metadata, 'generation_target')], JSON_PRESERVE_ZERO_FRACTION)),
            'counterfactual_replay_contract' => data_get($result, 'counterfactual_blame_graph'),
            'g98_failure_eliminator_lane' => $g98Lane ?: null,
            'verified_skill_contract' => $mutationSkillContract,
            'repair_anchor_id' => $repairAnchor ? data_get($agent->modelVersion?->metadata, 'repair_anchor.id') : null,
            'rule' => $repairAnchor
                ? 'Repair-anchor evidence becomes mutation credit and future-parent eligibility only after paired screening, full replay and independent forward confirmation all pass.'
                : 'Aggregate bundle outcome is never automatically credited to each changed parameter; G98 also requires all five counterfactual replays to be assessed.',
        ];
        $evidenceLedger = app(LabImmutableEvidenceService::class);
        if (! $repairAnchor && ! $isSingleMutation && $changedFields !== []) {
            $bundleMemory = MutationMemory::updateOrCreate(['lab_agent_id' => $agent->id, 'parameter_key' => '__bundle:'.substr($causalCredit['mutation_bundle_id'], 0, 16)], [
                'symbol' => $agent->symbol, 'timeframe' => $agent->timeframe, 'strategy_family' => $agent->strategy_family,
                'old_value' => ['fields' => $changedFields], 'new_value' => ['fields' => $changedFields], 'forward_delta' => $learningDelta,
                'market_regime' => $regime, 'outcome' => $outcome, 'confidence' => min(100, 50 + $performance->rolling_windows_count * 10),
                'execution_contract_hash' => $executionContractHash !== '' ? $executionContractHash : null,
                'independent_confirmation_count' => 0,
                'non_target_regression_status' => $nonTargetRegressionStatus,
                'evidence_scope_status' => 'historical_failure_memory',
                'decision' => 'Bundle evidence retained; individual causal credit withheld.', 'gate_transition' => $gateTransition,
                'behavioral_effect' => [...$behavioralEffect, 'causal_credit' => $causalCredit, 'verified_mutation_skill' => $mutationSkillContract, 'failure_signature' => $failureSignature],
            ]);
            $evidenceLedger->recordMutationCredit($bundleMemory, [
                'source' => 'full_replay_bundle_learning',
                'model_market_performance_id' => $performance->id,
                'mutation_bundle_id' => $causalCredit['mutation_bundle_id'],
                'parent_model_version_id' => $geneticParentPerformance?->model_version_id,
                'paired_experiment' => $pairedExperiment,
            ], data_get($result, 'evidence_run_id'));
        }
        foreach ($agent->parameter_diff ?? [] as $key => $change) {
            $mutationEffect = $this->parameterEffectiveness($agent, $key, $behavioralEffect);
            $memory = MutationMemory::updateOrCreate([
                'lab_agent_id' => $agent->id, 'parameter_key' => $key,
            ], [
                'symbol' => $agent->symbol, 'timeframe' => $agent->timeframe, 'strategy_family' => $agent->strategy_family,
                'old_value' => ['value' => $change['old'] ?? null], 'new_value' => ['value' => $change['new'] ?? null],
                'forward_delta' => $learningDelta, 'market_regime' => $regime,
                'execution_contract_hash' => $executionContractHash !== '' ? $executionContractHash : null,
                'independent_confirmation_count' => $repairConfirmed ? 2 : ($skillConfirmed ? min(2, $skillWindowCount) : 0),
                'non_target_regression_status' => $nonTargetRegressionStatus,
                'evidence_scope_status' => $repairConfirmed ? 'eligible_prior' : ($skillConfirmed ? 'eligible_prior' : 'historical_failure_memory'),
                'outcome' => $repairAnchor ? ($repairConfirmed && data_get($result, 'repair_anchor_verification.target_gate.improved') === true ? 'beneficial' : 'screen_inconclusive') : ($isSingleMutation ? $skillOutcome : 'neutral'),
                'confidence' => min(100, 50 + $performance->rolling_windows_count * 10),
                'gate_transition' => $gateTransition,
                'decision' => $repairAnchor
                    ? ($repairConfirmed ? 'Repair evidence independently confirmed; the child may qualify as a future parent after ordinary quality gates.' : 'Repair anchor child remains exploratory; no causal credit or parent link.')
                    : ($skillConfirmed
                    ? 'Verified beneficial skill; exact semantic descendants may reuse this gene.'
                    : ($delta <= -5 ? 'Observed harmful/failed mutation; no beneficial skill credit.' : 'Neutral mutation; complete verification contract is still missing.')),
                'behavioral_effect' => [...$behavioralEffect, 'causal_experiment' => $mutationEffect,
                    'gate_deficit_curriculum' => $curriculum, 'no_regression_contract' => $noRegression,
                    'capability_vector' => $capabilityVector, 'operating_envelope' => $operatingEnvelope,
                    'paired_experiment' => $pairedExperiment, 'causal_credit' => $causalCredit,
                    'verified_mutation_skill' => $mutationSkillContract, 'failure_signature' => $failureSignature],
            ]);
            if (! $repairAnchor || $repairConfirmed) {
                $evidenceLedger->recordMutationCredit($memory, [
                    'source' => $repairAnchor ? 'failure_repair_anchor_confirmation' : 'full_replay_parameter_learning',
                    'model_market_performance_id' => $performance->id,
                    'mutation_bundle_id' => $causalCredit['mutation_bundle_id'],
                    'parent_model_version_id' => $geneticParentPerformance?->model_version_id,
                    'control_model_version_id' => data_get($pairedExperiment, 'alternative_model_version_id'),
                    'paired_experiment' => $pairedExperiment,
                    'repair_anchor_id' => $causalCredit['repair_anchor_id'],
                ], data_get($result, 'evidence_run_id'));
            }
        }
        $architecture = data_get($agent->modelVersion?->metadata, 'strategy_architecture');
        $parentArchitecture = data_get($geneticParentPerformance?->modelVersion?->metadata, 'strategy_architecture');
        if (! $repairAnchor && $architecture && $architecture !== $parentArchitecture) {
            $memory = MutationMemory::updateOrCreate([
                'lab_agent_id' => $agent->id, 'parameter_key' => '__architecture',
            ], [
                'symbol' => $agent->symbol, 'timeframe' => $agent->timeframe, 'strategy_family' => $agent->strategy_family,
                'old_value' => ['value' => $parentArchitecture], 'new_value' => ['value' => $architecture],
                'forward_delta' => $learningDelta, 'market_regime' => $regime, 'outcome' => $outcome,
                'execution_contract_hash' => $executionContractHash !== '' ? $executionContractHash : null,
                'independent_confirmation_count' => $skillConfirmed ? min(2, $skillWindowCount) : 0,
                'non_target_regression_status' => $nonTargetRegressionStatus,
                'evidence_scope_status' => $skillConfirmed ? 'eligible_prior' : 'historical_failure_memory',
                'confidence' => min(100, 50 + $performance->rolling_windows_count * 10),
                'decision' => $outcome === 'beneficial' ? 'Architecture evidence improved; retain for this regime.' : ($outcome === 'harmful' ? 'Architecture falsified in this regime; de-prioritize.' : 'Architecture needs more evidence.'),
                'gate_transition' => $gateTransition,
                'behavioral_effect' => [...$behavioralEffect, 'gate_deficit_curriculum' => $curriculum,
                    'no_regression_contract' => $noRegression, 'capability_vector' => $capabilityVector,
                    'operating_envelope' => $operatingEnvelope, 'paired_experiment' => $pairedExperiment,
                    'verified_mutation_skill' => $mutationSkillContract],
            ]);
            $evidenceLedger->recordMutationCredit($memory, [
                'source' => 'full_replay_architecture_learning',
                'model_market_performance_id' => $performance->id,
                'parent_model_version_id' => $geneticParentPerformance?->model_version_id,
                'paired_experiment' => $pairedExperiment,
            ], data_get($result, 'evidence_run_id'));
        }
        // A later control replay can complete the paired experiment for an
        // earlier isolated child. Reconcile inside the same evidence flow so
        // pending credit does not remain pending forever.
        $this->causalMutationCredit->reconcileGeneration((int) $agent->lab_generation_id);
        $performance->update(['metrics' => [
            ...((array) $performance->metrics),
            'paired_replay' => $pairedReplay,
            'no_regression_contract' => $noRegression,
            'behavioral_effect' => $behavioralEffect,
            'causal_credit' => $causalCredit,
        ]]);
    }

    private function behavioralEffect(?array $parent, array $current): array
    {
        $metrics = ['total_trades', 'winrate', 'profit_factor', 'max_drawdown_percent'];
        $effect = [];
        foreach ($metrics as $key) {
            $effect[$key] = [
                'before' => $parent === null ? null : data_get($parent, $key),
                'after' => data_get($current, $key),
                'delta' => $parent === null ? null : round((float) data_get($current, $key, 0) - (float) data_get($parent, $key, 0), 4),
            ];
        }
        foreach (['flat_signal_opportunities', 'accepted_entries'] as $key) {
            $effect['entry_funnel'][$key] = [
                'before' => $parent === null ? null : (int) data_get($parent, "entry_funnel.{$key}", 0),
                'after' => (int) data_get($current, "entry_funnel.{$key}", 0),
                'delta' => $parent === null ? null : (int) data_get($current, "entry_funnel.{$key}", 0) - (int) data_get($parent, "entry_funnel.{$key}", 0),
            ];
        }
        foreach (['edge_density', 'coverage', 'rolling_consistency'] as $key) {
            $effect['opportunity_metrics'][$key] = [
                'before' => $parent === null ? null : data_get($parent, "opportunity_metrics.{$key}"),
                'after' => data_get($current, "opportunity_metrics.{$key}"),
                'delta' => $parent === null ? null : round((float) data_get($current, "opportunity_metrics.{$key}", 0) - (float) data_get($parent, "opportunity_metrics.{$key}", 0), 6),
            ];
        }
        foreach (['signal_count', 'entry_rejection_count', 'confirmation_rejection_count', 'news_veto_count', 'risk_veto_count', 'average_holding_time_hours', 'signal_coverage'] as $key) {
            $effect['diagnostic_telemetry'][$key] = [
                'before' => $parent === null ? null : data_get($parent, "diagnostic_telemetry.{$key}"),
                'after' => data_get($current, "diagnostic_telemetry.{$key}"),
                'delta' => $parent === null ? null : round((float) data_get($current, "diagnostic_telemetry.{$key}", 0) - (float) data_get($parent, "diagnostic_telemetry.{$key}", 0), 4),
            ];
        }
        $effect['exit_distribution'] = [
            'before' => $parent === null ? null : data_get($parent, 'diagnostic_telemetry.exit_distribution', []),
            'after' => data_get($current, 'diagnostic_telemetry.exit_distribution', []),
        ];
        return $effect;
    }

    private function parameterEffectiveness(LabAgent $agent, string $parameterKey, array $effect): array
    {
        $changed = abs((float) data_get($effect, 'profit_factor.delta', 0)) >= .01
            || abs((float) data_get($effect, 'total_trades.delta', 0)) >= 1
            || abs((float) data_get($effect, 'entry_funnel.accepted_entries.delta', 0)) >= 1
            || abs((float) data_get($effect, 'opportunity_metrics.coverage.delta', 0)) >= .01;
        $previous = MutationMemory::query()->where('symbol', $agent->symbol)->where('timeframe', $agent->timeframe)
            ->where('strategy_family', $agent->strategy_family)->where('parameter_key', $parameterKey)
            ->latest()->take(2)->get();
        $previousIneffective = $previous->count() === 2 && $previous->every(
            fn (MutationMemory $memory) => data_get($memory->behavioral_effect, 'causal_experiment.parameter_effective') === false,
        );
        return [
            'parameter_effective' => $changed ? true : ($previousIneffective ? false : null),
            'behavior_changed' => $changed, 'repeat_count_before' => $previous->count(),
            'rule' => 'three unchanged causal experiments temporarily remove this parameter from mutation search',
        ];
    }

    private function skillTree(array $result): array
    {
        $regimes = (array) data_get($result, 'regime_performance', []);
        $trend = collect($regimes)->only(['trend_up', 'trend_down'])->avg(fn ($item) => max(0, (float) data_get($item, 'profit_percent', 0))) ?: 0;
        $range = max(0, (float) data_get($regimes, 'range.profit_percent', 0));
        $coverage = (float) data_get($result, 'opportunity_metrics.coverage', 0);
        $pf = (float) data_get($result, 'profit_factor', 0);
        $drawdown = (float) data_get($result, 'max_drawdown_percent', data_get($result, 'max_drawdown', 100));
        $ruin = (float) data_get($result, 'monte_carlo.risk_of_ruin_percent', 100);
        $stress = (float) data_get($result, 'pf_attribution.stress_cost.profit_factor', 0);
        $news = data_get($result, 'red_team.scenarios.news_window.status') === 'assessed'
            ? (bool) data_get($result, 'red_team.scenarios.news_window.pass') : null;
        return [
            'trend_skill' => round(min(100, $trend * 20), 2),
            'range_skill' => round(min(100, $range * 20), 2),
            'entry_timing_skill' => round(min(100, $coverage * min(2, max(0, $pf)) * 50), 2),
            'exit_skill' => round(min(100, max(0, $pf) * 35), 2),
            'risk_skill' => round(max(0, min(100, 100 - ($drawdown * 3) - ($ruin * 2))), 2),
            'news_survival_skill' => $news === null ? null : ($news ? 100.0 : 0.0),
            'cost_robustness_skill' => round(min(100, max(0, $stress) * 50), 2),
            'evidence_status' => 'synthetic_forward_only',
        ];
    }
}
