<?php

namespace App\Services;

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
            $champion = ModelMarketPerformance::query()
                ->where(compact('symbol', 'timeframe'))
                ->where('strategy_family', $family)
                ->where('evidence_status', 'valid')
                ->where('status', 'champion')
                ->lockForUpdate()
                ->first();

            $windowScores = array_values($result['forward_window_scores'] ?? []);
            // A missing champion is not evidence that a checkpoint won. The
            // forward gate requires at least three genuinely positive replay
            // windows, independently of any champion comparison.
            $passportMonths = (array) data_get($result, 'monthly_passport.months', []);
            $wins = $passportMonths !== []
                ? (int) data_get($result, 'monthly_passport.rolling_forward_wins', 0)
                : collect($windowScores)->filter(fn ($score) => (float) $score > 0)->count();
            $forward = (float) ($result['forward_score'] ?? 0);
            $sampleCount = (int) ($result['total_trades'] ?? 0);
            // Keep the measured rolling result in the immutable gate ledger;
            // otherwise a later diagnostic would confuse an absent payload
            // field with zero rolling wins.
            $result['rolling_forward_wins'] = $wins;
            $agent = LabAgent::query()->with('mutationMemories')->where('model_version_id', $model->id)->latest()->first();
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
            // Official calendar alignment is joined only after the sealed
            // market replay. Missing historical events remain an explicit
            // passport failure; no provider gap is converted into a pass.
            $result = $this->calendarAlignment->enrich($symbol, $timeframe, $result);
            $elitePassport = $this->passport->build($model, $agent, $result);
            $elitePassport = $this->passport->freezeIfFinalist($model, $elitePassport, $result);
            $result['elite_agent_passport'] = $elitePassport;
            $model->update(['metadata' => array_merge($model->metadata ?? [], ['elite_agent_passport' => $elitePassport])]);

            $performance = ModelMarketPerformance::query()->updateOrCreate(
                ['model_version_id' => $model->id, 'symbol' => $symbol, 'timeframe' => $timeframe],
                [
                    'strategy_family' => $family, 'fitness' => $fitness, 'forward_score' => $forward,
                    'sample_count' => $sampleCount, 'rolling_windows_count' => count($windowScores),
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

            if ($champion?->id === $performance->id) {
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
            $forwardDecision = $this->gateDecisions->recordForward($performance->fresh(), $result);
            // The gate ledger is the authoritative evaluation record; mirror
            // its immutable decision into the operational handoff so the
            // screened -> ... -> forward_gate chain has no missing endpoint.
            if ($agent) {
                $this->handoffs->record($agent->generation, $agent, 'forward_gate', $forwardDecision->decision, null, [
                    'candidate_gate_decision_id' => $forwardDecision->id,
                    'performance_id' => $performance->id,
                    'reason_codes' => $forwardDecision->reason_codes,
                    'next_action' => $forwardDecision->decision === 'passed' ? 'paper_eligibility_review' : 'targeted_generation',
                ]);
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
            $this->gateDecisions->recordPaper($performance->fresh(), $metrics);
            return $performance->fresh();
        });
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
            if($passed && $this->marketReadiness->promotionReady() && $this->paperEvidence->ready()){$champion=ModelMarketPerformance::where('symbol',$performance->symbol)->where('timeframe',$performance->timeframe)
                ->where('evidence_status', 'valid')
                ->where('strategy_family',$performance->strategy_family)->where('status','champion')->lockForUpdate()->first();
                if($this->backtestGatesPass($performance,$champion,$performance->metrics??[]))$this->promote($performance,$champion,$performance->modelVersion);}
            LabAgent::where('model_version_id',$performance->model_version_id)->get()->each(fn (LabAgent $agent) => $agent->update([
                'lifecycle_status' => $performance->fresh()->status,
                'decision_reason' => $passed ? 'Sealed holdout and paper gates passed.' : 'Sealed holdout failed.',
            ]));
            $this->gateDecisions->recordHoldout($performance->fresh(), $holdout);
            return $performance->fresh();
        });
    }

    private function backtestGatesPass(ModelMarketPerformance $candidate, ?ModelMarketPerformance $champion, array $result): bool
    {
        $requiredWins = 3;
        $forwardGain = $champion ? $candidate->forward_score - $champion->forward_score : $candidate->forward_score;
        $strictStatisticalProtocol = (int) data_get($candidate->modelVersion?->metadata, 'statistical_gate_version', 0) >= 2;
        $selectionValidation = data_get($result, 'selection_validation', []);
        $deflatedSharpe = data_get($result, 'statistical_evidence.deflated_sharpe', []);
        // A new population may not paper-promote from an unavailable PBO/DSR
        // calculation. CSCV needs competing candidates; DSR needs enough
        // closed trade returns. Their absence is evidence still to gather,
        // not an exemption. Pre-protocol records remain legacy audit data.
        $pboPasses = $strictStatisticalProtocol
            ? data_get($selectionValidation, 'status') === 'assessed'
                && (float) data_get($selectionValidation, 'probability_of_backtest_overfitting', 1) <= 0.50
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
            && $diverse;
    }

    private function promote(ModelMarketPerformance $candidate, ?ModelMarketPerformance $champion, ModelVersion $model): void
    {
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

    private function updateLabAgentAndMemory(ModelMarketPerformance $performance, ?ModelMarketPerformance $champion, array $result): void
    {
        $agent = LabAgent::where('model_version_id', $performance->model_version_id)->latest()->first();
        if (! $agent) return;
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
        $parentA = $agent->parent_a_model_version_id ? ModelMarketPerformance::query()->where('model_version_id', $agent->parent_a_model_version_id)
            ->where('symbol', $agent->symbol)->where('timeframe', $agent->timeframe)->first() : null;
        $parentB = ! $parentA && $agent->parent_b_model_version_id ? ModelMarketPerformance::query()->where('model_version_id', $agent->parent_b_model_version_id)
            ->where('symbol', $agent->symbol)->where('timeframe', $agent->timeframe)->first() : null;
        $baseline = $parentA ? ['type' => 'parent_a', 'agent_ids' => [$agent->parent_a_model_version_id]]
            : ($parentB ? ['type' => 'parent_b', 'agent_ids' => [$agent->parent_b_model_version_id]] : []);
        $parentPerformance = $parentA ?: $parentB;
        $frontierBaseline = $champion ? [...($champion->metrics ?? []), 'forward_score' => $champion->forward_score] : null;
        if (! $parentPerformance && $champion) {
            $parentPerformance = $champion;
            $baseline = ['type' => 'family_frontier', 'agent_ids' => [$champion->model_version_id]];
        }
        if (! $parentPerformance) {
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
        $curriculum = $this->evolutionQuality->curriculum($result);
        $noRegression = $this->evolutionQuality->noRegressionContract($parentResult, $result);
        $capabilityVector = $this->evolutionQuality->capabilityVector($result);
        $result['capability_vector'] = $capabilityVector;
        $operatingEnvelope = $this->evolutionQuality->operatingEnvelope($result);
        $pairedExperiment = $this->evolutionQuality->pairedExperiment($agent, $parentResult, $result);
        $selfKnowledge = $this->universalCapabilities->selfKnowledge($result);
        $retention = $this->universalCapabilities->retention($parentResult, $result);
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
        $isSingleMutation = count($changedFields) === 1;
        $pairedConfirmed = data_get($pairedExperiment, 'status') === 'confirmed';
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
            'status' => $isSingleMutation && $pairedConfirmed ? 'independently_confirmed' : ($isSingleMutation ? 'awaiting_paired_confirmation' : 'bundle_unattributed'),
            'parent_model_version_id' => $parentPerformance?->model_version_id,
            'changed_fields' => $changedFields,
            'mutation_bundle_id' => hash('sha256', json_encode([$agent->id, $changedFields, data_get($agent->modelVersion?->metadata, 'generation_target')], JSON_PRESERVE_ZERO_FRACTION)),
            'counterfactual_replay_contract' => data_get($result, 'counterfactual_blame_graph'),
            'g98_failure_eliminator_lane' => $g98Lane ?: null,
            'rule' => 'Aggregate bundle outcome is never automatically credited to each changed parameter; G98 also requires all five counterfactual replays to be assessed.',
        ];
        $evidenceLedger = app(LabImmutableEvidenceService::class);
        if (! $isSingleMutation && $changedFields !== []) {
            $bundleMemory = MutationMemory::updateOrCreate(['lab_agent_id' => $agent->id, 'parameter_key' => '__bundle:'.substr($causalCredit['mutation_bundle_id'], 0, 16)], [
                'symbol' => $agent->symbol, 'timeframe' => $agent->timeframe, 'strategy_family' => $agent->strategy_family,
                'old_value' => ['fields' => $changedFields], 'new_value' => ['fields' => $changedFields], 'forward_delta' => $learningDelta,
                'market_regime' => $regime, 'outcome' => $outcome, 'confidence' => min(100, 50 + $performance->rolling_windows_count * 10),
                'decision' => 'Bundle evidence retained; individual causal credit withheld.', 'gate_transition' => $gateTransition,
                'behavioral_effect' => [...$behavioralEffect, 'causal_credit' => $causalCredit, 'failure_signature' => $failureSignature],
            ]);
            $evidenceLedger->recordMutationCredit($bundleMemory, [
                'source' => 'full_replay_bundle_learning',
                'model_market_performance_id' => $performance->id,
                'mutation_bundle_id' => $causalCredit['mutation_bundle_id'],
                'parent_model_version_id' => $parentPerformance?->model_version_id,
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
                'outcome' => $isSingleMutation && $pairedConfirmed ? $outcome : 'neutral',
                'confidence' => min(100, 50 + $performance->rolling_windows_count * 10),
                'decision' => $delta >= 5 ? 'Foydali mutation; keyingi generationda ustuvor.' : ($delta <= -5 ? 'Zararli mutation; shu yo‘nalishni cheklash.' : 'Neutral mutation; qo‘shimcha evidence kerak.'),
                'gate_transition' => $gateTransition,
                'behavioral_effect' => [...$behavioralEffect, 'causal_experiment' => $mutationEffect,
                    'gate_deficit_curriculum' => $curriculum, 'no_regression_contract' => $noRegression,
                    'capability_vector' => $capabilityVector, 'operating_envelope' => $operatingEnvelope,
                    'paired_experiment' => $pairedExperiment, 'causal_credit' => $causalCredit, 'failure_signature' => $failureSignature],
            ]);
            $evidenceLedger->recordMutationCredit($memory, [
                'source' => 'full_replay_parameter_learning',
                'model_market_performance_id' => $performance->id,
                'mutation_bundle_id' => $causalCredit['mutation_bundle_id'],
                'parent_model_version_id' => $parentPerformance?->model_version_id,
                'control_model_version_id' => data_get($pairedExperiment, 'alternative_model_version_id'),
                'paired_experiment' => $pairedExperiment,
            ], data_get($result, 'evidence_run_id'));
        }
        $architecture = data_get($agent->modelVersion?->metadata, 'strategy_architecture');
        $parentArchitecture = data_get($agent->parentA?->metadata, 'strategy_architecture');
        if ($architecture && $architecture !== $parentArchitecture) {
            $memory = MutationMemory::updateOrCreate([
                'lab_agent_id' => $agent->id, 'parameter_key' => '__architecture',
            ], [
                'symbol' => $agent->symbol, 'timeframe' => $agent->timeframe, 'strategy_family' => $agent->strategy_family,
                'old_value' => ['value' => $parentArchitecture], 'new_value' => ['value' => $architecture],
                'forward_delta' => $learningDelta, 'market_regime' => $regime, 'outcome' => $outcome,
                'confidence' => min(100, 50 + $performance->rolling_windows_count * 10),
                'decision' => $outcome === 'beneficial' ? 'Architecture evidence improved; retain for this regime.' : ($outcome === 'harmful' ? 'Architecture falsified in this regime; de-prioritize.' : 'Architecture needs more evidence.'),
                'gate_transition' => $gateTransition,
                'behavioral_effect' => [...$behavioralEffect, 'gate_deficit_curriculum' => $curriculum,
                    'no_regression_contract' => $noRegression, 'capability_vector' => $capabilityVector,
                    'operating_envelope' => $operatingEnvelope, 'paired_experiment' => $pairedExperiment],
            ]);
            $evidenceLedger->recordMutationCredit($memory, [
                'source' => 'full_replay_architecture_learning',
                'model_market_performance_id' => $performance->id,
                'parent_model_version_id' => $parentPerformance?->model_version_id,
                'paired_experiment' => $pairedExperiment,
            ], data_get($result, 'evidence_run_id'));
        }
        // A later control replay can complete the paired experiment for an
        // earlier isolated child. Reconcile inside the same evidence flow so
        // pending credit does not remain pending forever.
        $this->causalMutationCredit->reconcileGeneration((int) $agent->lab_generation_id);
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
