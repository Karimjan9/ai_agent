<?php

namespace App\Services;

use App\Models\ResearchExperimentReceipt;
use App\Models\AgentLearningCausalExperiment;
use App\Models\CausalFoldReceipt;
use App\Models\LabAgent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence-bounded civilization memory and successor portfolio read-models.
 * Neither projection transfers authority nor changes a runtime policy.
 */
class ResearchKnowledgePortfolioService
{
    public const PROTOCOL = 'research_knowledge_portfolio_v1';
    public const KNOWLEDGE_TYPES = ['EPISODIC', 'SEMANTIC', 'PROCEDURAL', 'CAUSAL', 'NEGATIVE', 'COUNTERFACTUAL', 'CIVILIZATIONAL'];
    public const META_PROTOCOL = 'prospective_research_meta_learning_v1';
    public const POLICY_EVALUATOR = 'bounded_research_policy_evaluator_v1';

    /** A forecast is advice, never evidence of its own correctness. */
    public function predictExperiment(array $spec): array
    {
        $scope = $this->predictionScope($spec);
        if ($scope === null) return $this->metaBlocked('PREDICTION_SCOPE_INCOMPLETE');
        $observations = $this->verifiedCalibrations($scope);
        $powered = array_values(array_filter($observations, fn ($row) => is_numeric($row['outcome']['success'] ?? null)));
        $n = count($powered);
        $successes = array_sum(array_column(array_column($powered, 'outcome'), 'success'));
        $probability = (1 + $successes) / (2 + $n);
        $deltas = array_column(array_column($powered, 'outcome'), 'target_delta');
        $costs = array_values(array_filter(array_column(array_column($observations, 'outcome'), 'replay_cpu_seconds'), 'is_numeric'));
        sort($costs);
        $stageForecast = [];
        foreach (['strategy', 'tactic', 'risk', 'management'] as $stage) {
            $measured = array_values(array_filter(array_map(fn ($row) => $row['outcome']['stage_deltas'][$stage] ?? null, $observations), 'is_numeric'));
            $stageForecast[$stage] = ['measured_questions' => count($measured),
                'expected_count_delta' => $measured ? array_sum($measured) / count($measured) : null];
        }
        $blockedStages = array_filter(array_column(array_column($observations, 'outcome'), 'first_measured_blocked_stage'), 'is_string');
        $frequencies = array_count_values($blockedStages); arsort($frequencies);
        return ['protocol' => self::META_PROTOCOL, 'status' => $n ? 'empirical_forecast' : 'prior_only',
            'scope' => $scope, 'scope_hash' => $this->metaHash($scope), 'powered_question_count' => $n,
            'success_probability' => $probability,
            'probability_standard_deviation' => sqrt($probability * (1 - $probability) / (3 + $n)),
            'expected_target_delta' => $n ? array_sum($deltas) / $n : null,
            'expected_direction' => ! $n ? 'unknown' : (array_sum($deltas) > 0 ? 'positive' : (array_sum($deltas) < 0 ? 'negative' : 'null')),
            'predicted_replay_cpu_seconds' => $costs ? $costs[(int) floor((count($costs) - 1) / 2)] : null,
            'compute_ceiling_seconds' => $spec['compute_ceiling_seconds'] ?? null,
            'required_observations' => ['minimum_trades_per_arm_per_fold' => 3, 'unknown_market_opportunity' => true],
            'expected_failure_stage' => $frequencies ? array_key_first($frequencies) : 'unknown',
            'stage_effect_forecast' => $stageForecast,
            'training_receipts' => array_column($observations, 'knowledge_key'),
            'calibrated' => false, 'calibration_sample_ready' => $n >= 5,
            'mean_observed_brier_loss' => $n ? array_sum(array_column($powered, 'brier_loss')) / $n : null,
            'out_of_sample_accuracy_improvement_proven' => false, 'confirmed_skill' => false, 'promotion_evidence' => false];
    }

    /** Native causal-fold hook. Old observed experiments are never backfilled. */
    public function preregisterExperiment(AgentLearningCausalExperiment $experiment, array $request): array
    {
        if (! Schema::hasTable('research_knowledge_entries')) return $this->unavailable();
        $control = LabAgent::with('modelVersion')->find($experiment->control_agent_id);
        $scope = ['symbol' => $experiment->symbol, 'timeframe' => $experiment->timeframe,
            'family' => $experiment->strategy_family, 'target' => $experiment->target, 'gene' => $experiment->gene_key,
            'baseline_hash' => $control?->modelVersion ? $this->metaHash((array) $control->modelVersion->parameters) : null,
            'data_hash' => $request['replay_dataset_hash'] ?? null,
            'execution_hash' => data_get($request, 'execution_contract.execution_hash'),
            'runtime_hash' => $this->runtimeFingerprint($request),
            'context' => (array) data_get($experiment->evidence, 'context', [])];
        if ($this->predictionScope($scope) === null || ! $control) return $this->metaBlocked('PREDICTION_SCOPE_INCOMPLETE');
        $spec = ['scope' => $scope, 'arm_ids' => array_map('intval', [$experiment->guided_agent_id, $experiment->blinded_agent_id, $experiment->control_agent_id]),
            'arm_parameter_hashes' => LabAgent::with('modelVersion')->whereIn('id', [$experiment->guided_agent_id, $experiment->blinded_agent_id, $experiment->control_agent_id])
                ->orderBy('id')->get()->mapWithKeys(fn ($agent) => [(string) $agent->id => $this->metaHash((array) $agent->modelVersion?->parameters)])->all(),
            'fold_count' => (int) data_get($request, 'policy_context.causal_fold_job.fold_count', 0),
            'contract_hash' => $this->metaHash((array) data_get($request, 'policy_context.learning_confirmation_contracts', [])),
            'compute_ceiling_seconds' => data_get($request, 'policy_context.causal_fold_job.per_fold_budget_seconds',
                collect((array) data_get($request, 'policy_context.learning_confirmation_contracts', []))->max('per_fold_budget_seconds'))];
        $spec['question_hash'] = $this->metaHash(['scope' => $scope, 'fold_count' => $spec['fold_count'], 'contract_hash' => $spec['contract_hash'],
            'arm_vectors' => array_map(fn ($id) => $spec['arm_parameter_hashes'][$id] ?? null, $spec['arm_ids'])]);
        $key = $this->metaKey('prediction', $experiment->experiment_key);
        $existing = $this->journalRead($key);
        if ($existing) return hash_equals($existing['spec_hash'], $this->metaHash($spec))
            ? $existing : $this->metaBlocked('PREDICTION_PREREGISTERED_CONTRACT_DRIFT');
        if (CausalFoldReceipt::where('agent_learning_causal_experiment_id', $experiment->id)
            ->where(fn ($q) => $q->whereNotNull('request_hash')->orWhereNotNull('response_hash')->orWhere('status', 'completed'))->exists()) {
            return $this->metaBlocked('OBSERVED_EXPERIMENT_CANNOT_BE_PREREGISTERED');
        }
        return $this->journalWrite($key, 'prediction', (string) $experiment->id, $scope,
            ['spec' => $spec, 'spec_hash' => $this->metaHash($spec), 'forecast' => $this->predictExperiment($spec),
                'preregistered_at' => now()->utc()->toIso8601String(), 'status' => 'preregistered']);
    }

    /** Local target-delta calibration is explicitly not causal confirmation. */
    public function settleExperimentPrediction(AgentLearningCausalExperiment $experiment): array
    {
        $predictionKey = $this->metaKey('prediction', $experiment->experiment_key);
        $prediction = $this->journalRead($predictionKey);
        if (! $prediction) return $this->metaBlocked('PROSPECTIVE_PREDICTION_REQUIRED');
        $outcome = $this->verifiedFoldOutcome($experiment, $prediction);
        if ($outcome === null) return $this->metaBlocked('COMPLETE_MATCHING_ORIGINAL_FOLDS_REQUIRED');
        $probability = (float) data_get($prediction, 'forecast.success_probability', .5);
        $loss = $outcome['success'] === null ? null : ($probability - $outcome['success']) ** 2;
        return $this->journalWrite($this->metaKey('calibration', $experiment->experiment_key), 'calibration',
            (string) $experiment->id, $prediction['spec']['scope'], ['prediction_key' => $predictionKey,
                'prediction_hash' => $this->metaHash($prediction), 'outcome' => $outcome, 'brier_loss' => $loss,
                'status' => $outcome['success'] === null ? 'underpowered' : 'local_target_calibrated',
                'confirmed_skill' => false, 'independent_market_evidence' => false]);
    }

    /** Bounded entropy-reduction heuristic; distributions are hypotheses, not facts. */
    public function chooseDiscriminatingProbe(array $hypotheses, array $probes, array $context = []): array
    {
        if (count($hypotheses) < 2 || count($hypotheses) > 8 || count($probes) > 16) return $this->metaBlocked('BOUNDED_HYPOTHESES_AND_PROBES_REQUIRED');
        $priors = [];
        foreach ($hypotheses as $h) {
            if (! filled($h['id'] ?? null) || ! is_numeric($h['prior'] ?? null) || $h['prior'] <= 0 || isset($priors[$h['id']])) return $this->metaBlocked('HYPOTHESIS_PRIORS_INVALID');
            $priors[$h['id']] = (float) $h['prior'];
        }
        $total = array_sum($priors);
        foreach ($priors as &$prior) $prior /= $total;
        unset($prior);
        $ranked = [];
        foreach ($probes as $probe) {
            if (($probe['legal'] ?? false) !== true || ($probe['safety_preserved'] ?? false) !== true
                || ! filled($probe['id'] ?? null) || ! is_numeric($probe['cost_ceiling_seconds'] ?? null) || $probe['cost_ceiling_seconds'] <= 0) continue;
            $distributions = (array) ($probe['predictions'] ?? []);
            if (array_diff(array_keys($priors), array_keys($distributions))) continue;
            $mixture = []; $conditional = 0.0; $valid = true;
            foreach ($priors as $id => $prior) {
                $distribution = $distributions[$id];
                if (! is_array($distribution) || count($distribution) > 8 || abs(array_sum($distribution) - 1) > .000001
                    || collect($distribution)->contains(fn ($p) => ! is_numeric($p) || ! is_finite((float) $p) || $p < 0 || $p > 1)) { $valid = false; break; }
                $conditional += $prior * $this->entropy($distribution);
                foreach ($distribution as $outcome => $p) $mixture[$outcome] = ($mixture[$outcome] ?? 0) + $prior * $p;
            }
            if (! $valid) continue;
            $gain = max(0.0, $this->entropy($mixture) - $conditional);
            $ranked[] = ['probe_id' => $probe['id'], 'expected_information_bits' => $gain,
                'information_per_ceiling_second' => $gain / $probe['cost_ceiling_seconds'], 'cost_ceiling_seconds' => $probe['cost_ceiling_seconds']];
        }
        usort($ranked, fn ($a, $b) => ($b['information_per_ceiling_second'] <=> $a['information_per_ceiling_second']) ?: strcmp($a['probe_id'], $b['probe_id']));
        $plan = ['protocol' => self::META_PROTOCOL, 'hypotheses' => $hypotheses, 'probes' => $probes, 'context' => $context,
            'ranking' => $ranked, 'selected_probe' => $ranked[0]['probe_id'] ?? null,
            'status' => $ranked ? 'prospective_probe_recommendation' : 'no_legal_discriminating_probe',
            'full_market_causality_proven' => false, 'promotion_evidence' => false];
        $plan['plan_hash'] = $this->metaHash($plan);
        return $plan;
    }

    /** Prospectively sealed explanations, deliberately not asserted causal facts. */
    public function sealCompetingHypotheses(array $hypotheses, array $context): array
    {
        if (! Schema::hasTable('research_knowledge_entries')) return $this->unavailable();
        if (count($hypotheses) < 2 || count($hypotheses) > 8 || ! filled($context['question_key'] ?? null)
            || ! filled($context['data_hash'] ?? null) || ! filled($context['baseline_hash'] ?? null)) return $this->metaBlocked('SEALED_HYPOTHESIS_CONTEXT_REQUIRED');
        $refs = [];
        foreach ($hypotheses as $h) {
            if (! filled($h['id'] ?? null) || ! is_numeric($h['prior'] ?? null) || $h['prior'] <= 0
                || ! is_array($h['predictions'] ?? null)) return $this->metaBlocked('HYPOTHESIS_PREDICTIONS_REQUIRED');
            foreach ($h['predictions'] as $distribution) {
                if (! is_array($distribution) || ! $distribution || abs(array_sum($distribution) - 1) > .000001
                    || collect($distribution)->contains(fn ($v) => ! is_numeric($v) || ! is_finite((float) $v) || $v < 0 || $v > 1)) return $this->metaBlocked('HYPOTHESIS_DISTRIBUTION_INVALID');
            }
        }
        foreach ($hypotheses as $h) {
            $spec = ['hypothesis_id' => $h['id'], 'prior' => (float) $h['prior'], 'predictions' => $h['predictions'], 'context' => $context];
            $key = $this->metaKey('hypothesis', $this->metaHash($spec));
            $claim = ['protocol' => self::META_PROTOCOL, 'kind' => 'competing_hypotheses', 'promotion_evidence' => false];
            DB::table('research_knowledge_entries')->insertOrIgnore(['knowledge_key' => $key, 'knowledge_type' => 'PROCEDURAL',
                'subject_type' => self::META_PROTOCOL.':hypothesis', 'subject_key' => $context['question_key'],
                'symbol' => $context['symbol'] ?? null, 'timeframe' => $context['timeframe'] ?? null,
                'authority' => 'research_only', 'freshness' => 'active', 'status' => 'sealed',
                'scope' => json_encode($context, JSON_PRESERVE_ZERO_FRACTION), 'claim' => json_encode($claim),
                'evidence' => json_encode(['spec' => $spec, 'spec_hash' => $this->metaHash($spec)], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                'dependencies' => json_encode([]), 'recorded_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $refs[] = ['knowledge_key' => $key, 'spec_hash' => $this->metaHash($spec)];
        }
        return ['protocol' => self::META_PROTOCOL, 'status' => 'sealed', 'hypothesis_refs' => $refs, 'promotion_evidence' => false];
    }

    /** Research-only behavior boundaries; absence of proof stays unknown. */
    public function applicabilityBoundary(array $scope): array
    {
        $exact = $this->predictionScope($scope);
        if ($exact === null) return $this->metaBlocked('BOUNDARY_SCOPE_INCOMPLETE');
        $rows = $this->verifiedCalibrations($exact);
        $positive = count(array_filter($rows, fn ($r) => ($r['outcome']['success'] ?? null) === 1));
        $negative = count(array_filter($rows, fn ($r) => is_numeric($r['outcome']['target_delta'] ?? null) && $r['outcome']['target_delta'] < 0));
        $neutral = count(array_filter($rows, fn ($r) => is_numeric($r['outcome']['target_delta'] ?? null) && (float) $r['outcome']['target_delta'] === 0.0));
        return ['protocol' => self::META_PROTOCOL, 'scope' => $exact,
            'status' => ! ($positive + $negative + $neutral) ? 'unknown' : ($positive && $negative ? 'mixed_requires_boundary_probe' : ($positive ? 'positive_research_region' : ($negative ? 'negative_research_region' : 'null_research_region'))),
            'positive_questions' => $positive, 'negative_questions' => $negative, 'neutral_questions' => $neutral,
            'unpowered_questions' => count($rows) - $positive - $negative - $neutral,
            'evidence_keys' => array_column($rows, 'knowledge_key'), 'extrapolation_allowed' => false,
            'near_boundary_action' => 'prospectively_seal_adjacent_context_probe', 'global_ban_allowed' => false, 'promotion_evidence' => false];
    }

    public function applicabilityMap(array $scope, array $candidateContexts = []): array
    {
        $base = $this->predictionScope($scope);
        if ($base === null || count($candidateContexts) > 16) return $this->metaBlocked('BOUNDED_BOUNDARY_SCOPE_REQUIRED');
        $contexts = $candidateContexts;
        if (Schema::hasTable('research_knowledge_entries')) {
            foreach (DB::table('research_knowledge_entries')->where('subject_type', self::META_PROTOCOL.':calibration')
                ->where('symbol', $base['symbol'])->where('timeframe', $base['timeframe'])->orderByDesc('id')->limit(128)->get() as $row) {
                $observed = json_decode($row->scope, true);
                if (is_array($observed) && $this->metaHash(array_diff_key($observed, ['context' => true])) === $this->metaHash(array_diff_key($base, ['context' => true]))) $contexts[] = $observed['context'];
            }
        }
        $cells = [];
        foreach (array_slice($contexts, 0, 32) as $context) {
            if (! is_array($context)) continue;
            $cells[$this->metaHash($context)] = $this->applicabilityBoundary([...$base, 'context' => $context]);
        }
        return ['protocol' => self::META_PROTOCOL, 'status' => 'exact_context_research_boundary_map', 'cells' => array_values($cells),
            'unknown_context_hashes' => array_keys(array_filter($cells, fn ($cell) => $cell['status'] === 'unknown')),
            'next_action' => 'seal_one_adjacent_unknown_context_probe_before_replay',
            'inferred_regions_confirmed' => false, 'promotion_evidence' => false];
    }

    /** Only finite declarative weights, never executable generated code. */
    public function registerPolicy(array $definition): array
    {
        $allowed = ['expected_value', 'information_gain', 'learning_progress', 'cost', 'diversity'];
        $weights = (array) ($definition['weights'] ?? []);
        if (! $weights || array_diff(array_keys($weights), $allowed)
            || collect($weights)->contains(fn ($v) => ! is_numeric($v) || ! is_finite((float) $v) || abs($v) > 4)
            || array_diff(array_keys($definition), ['weights', 'exploration_fraction', 'max_candidates', 'compute_cap_seconds'])) return $this->metaBlocked('DECLARATIVE_POLICY_INVALID');
        if (($definition['exploration_fraction'] ?? .1) < .05 || ($definition['exploration_fraction'] ?? .1) > .25
            || ($definition['max_candidates'] ?? 16) < 2 || ($definition['max_candidates'] ?? 16) > 32
            || ! is_numeric($definition['compute_cap_seconds'] ?? null) || $definition['compute_cap_seconds'] <= 0 || $definition['compute_cap_seconds'] > 3600) return $this->metaBlocked('POLICY_BOUNDS_INVALID');
        $definition += ['exploration_fraction' => .1, 'max_candidates' => 16];
        $key = $this->metaKey('policy', $this->metaHash($definition));
        return $this->journalWrite($key, 'policy', $key, [], ['definition' => $definition,
            'evaluator' => self::POLICY_EVALUATOR, 'status' => 'candidate_requires_fixed_challenge',
            'self_grading_allowed' => false, 'live_market_replay_proven' => false]);
    }

    /** Equal-budget fixed evaluator. Synthetic success never activates a policy. */
    public function evaluatePolicy(string $policyKey): array
    {
        $policy = $this->journalRead($policyKey);
        if (! $policy || ($policy['evaluator'] ?? null) !== self::POLICY_EVALUATOR) return $this->metaBlocked('SEALED_POLICY_REQUIRED');
        $worlds = app(CausalGoldenWorldHarnessService::class)->researchPolicyWorlds();
        $scores = []; $regret = 0.0;
        foreach ($worlds as $name => $world) {
            $rank = $this->rankResearchQuestions($world['candidates'], 'fixed-world:'.$name, $policyKey, false);
            $selected = $rank['ranking'][0]['question_id'] ?? null;
            $utility = (float) ($world['outcomes'][$selected] ?? -1);
            $best = max($world['outcomes']); $regret += $best - $utility;
            $scores[$name] = ['selected' => $selected, 'utility' => $utility, 'regret' => $best - $utility];
        }
        return $this->journalWrite($this->metaKey('policy_evaluation', $policyKey), 'policy_evaluation', $policyKey, [],
            ['status' => 'synthetic_benchmarked_awaiting_heldout_real_tasks', 'policy_key' => $policyKey,
                'evaluator' => self::POLICY_EVALUATOR, 'fixed_world_digest' => $this->metaHash($worlds),
                'worlds' => $scores, 'total_regret' => $regret, 'policy_activated' => false,
                'live_market_replay_proven' => false, 'economic_authority' => false]);
    }

    /** Bounded mutation of declarations; evaluator/caps are not mutable. */
    public function proposePolicyVariants(string $parentKey): array
    {
        $parent = $this->journalRead($parentKey);
        if (! isset($parent['definition'])) return $this->metaBlocked('SEALED_POLICY_REQUIRED');
        $variants = [];
        foreach (['information_gain', 'learning_progress', 'diversity'] as $feature) {
            $definition = $parent['definition'];
            $definition['weights'][$feature] = min(4, (float) ($definition['weights'][$feature] ?? 0) + .25);
            $variant = $this->registerPolicy($definition);
            $variants[] = ['parent_policy_key' => $parentKey, 'changed_feature' => $feature, 'policy' => $variant];
        }
        return ['protocol' => self::META_PROTOCOL, 'status' => 'bounded_policy_challengers', 'variants' => $variants,
            'evaluator' => self::POLICY_EVALUATOR, 'self_grading_allowed' => false, 'promotion_evidence' => false];
    }

    /** Freeze policy choices on genuine not-yet-executed research questions. */
    public function preregisterPolicyChallenge(array $policyKeys, array $experimentIds, string $seed): array
    {
        if (count($policyKeys) < 2 || count($policyKeys) > 4 || count(array_unique($policyKeys)) !== count($policyKeys)
            || count($experimentIds) < 3 || count($experimentIds) > 16 || count(array_unique($experimentIds)) !== count($experimentIds) || $seed === '') return $this->metaBlocked('BOUNDED_FIXED_POLICY_CHALLENGE_REQUIRED');
        $cases = []; $candidates = []; $caps = [];
        foreach ($policyKeys as $key) {
            $policy = $this->journalRead($key);
            if (! isset($policy['definition'])) return $this->metaBlocked('SEALED_POLICY_REQUIRED');
            $caps[] = array_intersect_key($policy['definition'], array_flip(['compute_cap_seconds', 'max_candidates', 'exploration_fraction']));
        }
        if (count(array_unique(array_map(fn ($cap) => $this->metaHash($cap), $caps))) !== 1) return $this->metaBlocked('EQUAL_POLICY_CHALLENGE_CAPS_REQUIRED');
        foreach ($experimentIds as $id) {
            $experiment = AgentLearningCausalExperiment::find((int) $id);
            $prediction = $experiment ? $this->journalRead($this->metaKey('prediction', $experiment->experiment_key)) : null;
            if (! $prediction || CausalFoldReceipt::where('agent_learning_causal_experiment_id', $id)->where(fn ($q) => $q->whereNotNull('request_hash')->orWhereNotNull('response_hash'))->exists()) return $this->metaBlocked('UNOBSERVED_PREREGISTERED_REAL_QUESTIONS_REQUIRED');
            if (in_array($prediction['spec']['question_hash'], array_column($cases, 'question_hash'), true)) return $this->metaBlocked('DISTINCT_CONSUMED_POLICY_QUESTIONS_REQUIRED');
            $cases[(string) $id] = ['experiment_key' => $experiment->experiment_key, 'prediction_hash' => $this->metaHash($prediction),
                'question_hash' => $prediction['spec']['question_hash'], 'scope' => $prediction['spec']['scope']];
            $ceiling = $prediction['spec']['compute_ceiling_seconds'] ?? null;
            if (! is_numeric($ceiling) || $ceiling <= 0) return $this->metaBlocked('PREREGISTERED_COMPUTE_CEILING_REQUIRED');
            $candidates[] = ['question_id' => (string) $id, 'ready' => true, 'safety_preserved' => true, 'cost_ceiling_seconds' => $ceiling,
                'features' => ['expected_value' => $prediction['forecast']['success_probability'],
                    'information_gain' => $prediction['forecast']['probability_standard_deviation']]];
        }
        $choices = [];
        foreach ($policyKeys as $key) $choices[$key] = $this->rankResearchQuestions($candidates, $seed, $key)['ranking'];
        $seal = ['policy_keys' => $policyKeys, 'cases' => $cases, 'choices' => $choices, 'seed_hash' => hash('sha256', $seed),
            'caps' => $caps[0], 'evaluator' => self::POLICY_EVALUATOR, 'status' => 'awaiting_fixed_real_question_outcomes'];
        return $this->journalWrite($this->metaKey('policy_challenge', $this->metaHash($seal)), 'policy_challenge', $this->metaHash($seal), [], $seal);
    }

    public function settlePolicyChallenge(string $challengeKey): array
    {
        $challenge = $this->journalRead($challengeKey);
        if (! isset($challenge['cases'], $challenge['choices']) || $challenge['evaluator'] !== self::POLICY_EVALUATOR) return $this->metaBlocked('FIXED_POLICY_CHALLENGE_REQUIRED');
        $outcomes = [];
        foreach ($challenge['cases'] as $id => $case) {
            $experiment = AgentLearningCausalExperiment::find((int) $id);
            $prediction = $this->journalRead($this->metaKey('prediction', $case['experiment_key']));
            if (! $experiment || ! $prediction || $this->metaHash($prediction) !== $case['prediction_hash']) return $this->metaBlocked('FIXED_POLICY_CHALLENGE_CASE_DRIFT');
            $outcomes[$id] = $this->verifiedFoldOutcome($experiment, $prediction);
            if ($outcomes[$id] === null) return $this->metaBlocked('ALL_FIXED_REAL_QUESTION_OUTCOMES_REQUIRED');
        }
        $scores = [];
        foreach ($challenge['choices'] as $policy => $ranking) {
            $measuredCost = 0.0; $answers = 0; $beneficial = 0; $first = null; $completeCost = true;
            foreach ($ranking as $position => $choice) {
                $outcome = $outcomes[$choice['question_id']];
                if ($outcome['replay_cpu_seconds'] === null) $completeCost = false;
                else $measuredCost += $outcome['replay_cpu_seconds'];
                if ($outcome['success'] !== null) $answers++;
                if ($outcome['success'] === 1) { $beneficial++; $first ??= $position + 1; }
            }
            $scores[$policy] = ['assessable_local_questions' => $answers, 'local_positive_questions' => $beneficial,
                'first_local_positive_rank' => $first, 'actual_replay_cpu_seconds' => $completeCost ? $measuredCost : null,
                'selector_cpu_seconds' => null, 'compute_advantage_proven' => false];
        }
        return $this->journalWrite($this->metaKey('policy_challenge_result', $challengeKey), 'policy_challenge_result', $challengeKey, [],
            ['status' => 'fixed_real_question_comparison', 'challenge_key' => $challengeKey, 'outcomes' => $outcomes, 'scores' => $scores,
                'policy_activated' => false, 'market_edge_proven' => false, 'economic_authority' => false,
                'independent_window_owner_required_for_activation' => true]);
    }

    /** Forecast-guided portfolio with a deterministic, prospectively seeded exploration share. */
    public function rankResearchQuestions(array $candidates, string $seed, ?string $policyKey = null, bool $explore = true): array
    {
        $policy = $policyKey ? $this->journalRead($policyKey) : null;
        if ($policyKey && (! $policy || ! isset($policy['definition']))) return $this->metaBlocked('SEALED_POLICY_REQUIRED');
        $definition = (array) ($policy['definition'] ?? ['weights' => ['expected_value' => 1, 'information_gain' => 1, 'cost' => -1],
            'exploration_fraction' => .1, 'max_candidates' => 16, 'compute_cap_seconds' => 3600]);
        $rank = [];
        foreach (array_slice($candidates, 0, (int) $definition['max_candidates']) as $candidate) {
            if (($candidate['ready'] ?? false) !== true || ($candidate['safety_preserved'] ?? false) !== true || ! filled($candidate['question_id'] ?? null)) continue;
            $cost = $candidate['cost_ceiling_seconds'] ?? null;
            if (! is_numeric($cost) || ! is_finite((float) $cost) || $cost <= 0 || $cost > $definition['compute_cap_seconds']) continue;
            $score = 0.0;
            foreach ($definition['weights'] as $feature => $weight) {
                $value = $feature === 'cost' ? $cost / $definition['compute_cap_seconds'] : data_get($candidate, 'features.'.$feature, 0);
                if (! is_numeric($value) || ! is_finite((float) $value)) { $score = null; break; }
                $score += $weight * max(-1, min(1, (float) $value));
            }
            if ($score === null) continue;
            $rank[] = ['question_id' => $candidate['question_id'], 'score' => $score,
                'cost_ceiling_seconds' => $cost, 'selection_hash' => hash('sha256', $seed.'|'.$candidate['question_id'])];
        }
        $random = $explore && hexdec(substr(hash('sha256', $seed), 0, 6)) / 16777216 < $definition['exploration_fraction'];
        usort($rank, fn ($a, $b) => $random ? strcmp($a['selection_hash'], $b['selection_hash']) : (($b['score'] <=> $a['score']) ?: strcmp($a['question_id'], $b['question_id'])));
        return ['protocol' => self::META_PROTOCOL, 'status' => 'research_ranking', 'selection_mode' => $random ? 'random_control_share' : 'bounded_value_information_cost',
            'seed_hash' => hash('sha256', $seed), 'policy_key' => $policyKey, 'ranking' => $rank,
            'new_dispatcher' => false, 'promotion_evidence' => false];
    }

    private function predictionScope(array $spec): ?array
    {
        $scope = (array) ($spec['scope'] ?? $spec);
        $required = ['symbol', 'timeframe', 'family', 'target', 'gene', 'baseline_hash', 'data_hash', 'execution_hash', 'runtime_hash'];
        foreach ($required as $key) if (! is_string($scope[$key] ?? null) || trim($scope[$key]) === '') return null;
        return [...array_intersect_key($scope, array_flip($required)), 'context' => (array) ($scope['context'] ?? [])];
    }

    private function verifiedCalibrations(array $scope): array
    {
        if (! Schema::hasTable('research_knowledge_entries')) return [];
        $rows = DB::table('research_knowledge_entries')->where('subject_type', self::META_PROTOCOL.':calibration')
            ->where('symbol', $scope['symbol'])->where('timeframe', $scope['timeframe'])
            ->where('scope->baseline_hash', $scope['baseline_hash'])->where('scope->data_hash', $scope['data_hash'])
            ->where('scope->runtime_hash', $scope['runtime_hash'])->where('recorded_at', '<=', now())->orderByDesc('id')->limit(32)->get();
        $verified = []; $questionHashes = [];
        foreach ($rows as $row) {
            $claim = $this->journalRead($row->knowledge_key);
            if (! $claim || $this->metaHash(json_decode($row->scope, true)) !== $this->metaHash($scope)) continue;
            $prediction = $this->journalRead($claim['prediction_key'] ?? '');
            $experiment = AgentLearningCausalExperiment::find((int) $row->subject_key);
            if (! $prediction || ! $experiment || ! hash_equals($claim['prediction_hash'], $this->metaHash($prediction))) continue;
            $outcome = $this->verifiedFoldOutcome($experiment, $prediction);
            if ($outcome === null || $this->metaHash($outcome) !== $this->metaHash($claim['outcome'])) continue;
            $questionHash = $prediction['spec']['question_hash'] ?? null;
            if (! $questionHash || isset($questionHashes[$questionHash])) continue;
            $questionHashes[$questionHash] = true;
            $verified[] = [...$claim, 'knowledge_key' => $row->knowledge_key];
        }
        return $verified;
    }

    private function verifiedFoldOutcome(AgentLearningCausalExperiment $experiment, array $prediction): ?array
    {
        if (! app(ExperimentQualityProgressService::class)->technicallyComplete($experiment)) return null;
        $scope = $prediction['spec']['scope'];
        foreach (['symbol', 'timeframe', 'target', 'gene_key' => 'gene', 'strategy_family' => 'family'] as $attribute => $key) {
            if (is_int($attribute)) $attribute = $key;
            if ($experiment->{$attribute} !== $scope[$key]) return null;
        }
        if ($prediction['spec']['arm_ids'] !== array_map('intval', [$experiment->guided_agent_id, $experiment->blinded_agent_id, $experiment->control_agent_id])) return null;
        $currentHashes = LabAgent::with('modelVersion')->whereIn('id', $prediction['spec']['arm_ids'])->orderBy('id')->get()
            ->mapWithKeys(fn ($agent) => [(string) $agent->id => $this->metaHash((array) $agent->modelVersion?->parameters)])->all();
        if ($this->metaHash($currentHashes) !== $this->metaHash($prediction['spec']['arm_parameter_hashes'])) return null;
        $control = LabAgent::with('modelVersion')->find($experiment->control_agent_id);
        if (! $control?->modelVersion || $scope['baseline_hash'] !== $this->metaHash((array) $control->modelVersion->parameters)) return null;
        $paths = ['profit_factor' => 'profit_factor', 'expectancy' => 'expectancy', 'net_profit' => 'net_profit', 'expectancy_margin' => 'expectancy'];
        $path = $paths[$experiment->target] ?? null;
        $folds = CausalFoldReceipt::where('agent_learning_causal_experiment_id', $experiment->id)->orderBy('fold_index')->get();
        if ($folds->count() !== $prediction['spec']['fold_count']) return null;
        $deltas = []; $cpu = 0.0; $cpuComplete = true; $powered = true; $stageDeltas = []; $blockedStages = [];
        foreach ($folds as $fold) {
            // A lease may predate the seal; a completed observation may not.
            if (! $fold->completed_at || $fold->completed_at->lt(\Carbon\CarbonImmutable::parse($prediction['preregistered_at']))) return null;
            $request = (array) $fold->request_payload;
            if ($scope['data_hash'] !== $fold->dataset_hash || $scope['execution_hash'] !== $fold->execution_hash
                || $prediction['spec']['contract_hash'] !== $this->metaHash((array) data_get($request, 'policy_context.learning_confirmation_contracts', []))
                || $scope['runtime_hash'] !== $this->runtimeFingerprint($request)) return null;
            $items = collect(data_get($fold->response_payload, 'leaderboard', []))->keyBy('lab_agent_id');
            $guided = (array) data_get($items->get($experiment->guided_agent_id), 'result', []);
            $baseline = (array) data_get($items->get($experiment->control_agent_id), 'result', []);
            $blinded = (array) data_get($items->get($experiment->blinded_agent_id), 'result', []);
            $guidedTrace = (array) ($guided['composition_runtime_trace'] ?? []);
            $controlTrace = (array) ($baseline['composition_runtime_trace'] ?? []);
            if (($guidedTrace['execution_receipt_valid'] ?? false) === true && ($controlTrace['execution_receipt_valid'] ?? false) === true) {
                foreach (['strategy' => 'observations.strategy_signals_before_tactic', 'tactic' => 'component_execution.tactic.accepted_count',
                    'risk' => 'component_execution.risk.evaluation_count', 'management' => 'component_execution.management.evaluation_count'] as $stage => $stagePath) {
                    $g = data_get($guidedTrace, $stagePath); $c = data_get($controlTrace, $stagePath);
                    if (is_int($g) && is_int($c) && min($g, $c) >= 0) $stageDeltas[$stage][] = $g - $c;
                }
                if ((int) data_get($guidedTrace, 'observations.strategy_signals_before_tactic', 0) > 0
                    && data_get($guidedTrace, 'component_execution.tactic.accepted_count') === 0) $blockedStages[] = 'tactic';
            }
            if (! $path || ! is_numeric(data_get($guided, $path)) || ! is_numeric(data_get($baseline, $path))
                || min((int) ($guided['total_trades'] ?? 0), (int) ($baseline['total_trades'] ?? 0), (int) ($blinded['total_trades'] ?? 0)) < 3) $powered = false;
            elseif (is_finite((float) data_get($guided, $path)) && is_finite((float) data_get($baseline, $path))) $deltas[] = (float) data_get($guided, $path) - (float) data_get($baseline, $path);
            else $powered = false;
            foreach ($items as $item) {
                $measurement = data_get($item, 'result.benchmark.arm_replay_resources', []);
                $seconds = $measurement['cpu_seconds'] ?? null;
                if (($measurement['protocol'] ?? null) !== 'arm_replay_resources_v1'
                    || ($measurement['scope'] ?? null) !== 'economic_replay_only_excludes_shared_features_and_audit'
                    || ! is_numeric($seconds) || $seconds < 0 || ! is_finite((float) $seconds)) $cpuComplete = false;
                else $cpu += $seconds;
            }
        }
        $delta = $powered && count($deltas) === $folds->count() ? array_sum($deltas) / count($deltas) : null;
        $stageMeans = [];
        foreach ($stageDeltas as $stage => $values) $stageMeans[$stage] = count($values) === $folds->count() ? array_sum($values) / count($values) : null;
        return ['success' => $delta === null ? null : (int) ($delta > 0), 'target_delta' => $delta,
            'stage_deltas' => $stageMeans, 'first_measured_blocked_stage' => count($blockedStages) === $folds->count() ? 'tactic' : null,
            'status' => $delta === null ? 'underpowered_or_unmeasured_target' : 'powered_local_fold_delta',
            'receipt_ids' => $folds->pluck('id')->all(), 'receipt_hashes' => $folds->pluck('response_hash')->all(),
            'replay_cpu_seconds' => $cpuComplete ? $cpu : null, 'confirmation_gate_passed' => false];
    }

    private function journalWrite(string $key, string $kind, string $subject, array $scope, array $claim): array
    {
        if (! Schema::hasTable('research_knowledge_entries')) return $this->unavailable();
        $claim += ['protocol' => self::META_PROTOCOL, 'promotion_evidence' => false];
        $hash = $this->metaHash($claim);
        DB::table('research_knowledge_entries')->insertOrIgnore(['knowledge_key' => $key, 'knowledge_type' => 'PROCEDURAL',
            'subject_type' => self::META_PROTOCOL.':'.$kind, 'subject_key' => $subject,
            'symbol' => $scope['symbol'] ?? null, 'timeframe' => $scope['timeframe'] ?? null,
            'authority' => 'research_only', 'freshness' => 'active', 'status' => 'recorded',
            'scope' => json_encode($scope, JSON_PRESERVE_ZERO_FRACTION), 'claim' => json_encode($claim, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION), 'evidence' => json_encode(['claim_hash' => $hash]),
            'dependencies' => json_encode([]), 'recorded_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $stored = $this->journalRead($key);
        if (! $stored || ! hash_equals($hash, $this->metaHash($stored))) return $this->metaBlocked('IMMUTABLE_META_JOURNAL_CONFLICT');
        return [...$stored, 'knowledge_key' => $key];
    }

    private function journalRead(string $key): ?array
    {
        if (! Schema::hasTable('research_knowledge_entries')) return null;
        $row = DB::table('research_knowledge_entries')->where('knowledge_key', $key)->first();
        if (! $row || $row->authority !== 'research_only') return null;
        $claim = json_decode($row->claim, true); $evidence = json_decode($row->evidence, true);
        return is_array($claim) && isset($evidence['claim_hash']) && hash_equals($evidence['claim_hash'], $this->metaHash($claim)) ? $claim : null;
    }

    private function metaKey(string $kind, string $subject): string { return hash('sha256', self::META_PROTOCOL.'|'.$kind.'|'.$subject); }
    private function runtimeFingerprint(array $request): ?string
    {
        $release = (array) data_get($request, 'research_release', data_get($request, 'policy_context.research_release', []));
        if (! filled($release['source_hash'] ?? null)) return null;
        // Cohort IDs and seal timestamps are not different evaluator code.
        return $this->metaHash(array_intersect_key($release, array_flip(['protocol', 'source_hash', 'python_source_hash'])));
    }
    private function metaBlocked(string $reason): array { return ['protocol' => self::META_PROTOCOL, 'status' => 'blocked', 'reason' => $reason, 'promotion_evidence' => false]; }
    private function entropy(array $distribution): float { return -array_sum(array_map(fn ($p) => $p > 0 ? $p * log($p, 2) : 0, $distribution)); }
    private function metaHash(array $value): string
    {
        $canonical = function (array $items) use (&$canonical): array {
            foreach ($items as $key => $item) if (is_array($item)) $items[$key] = $canonical($item);
            if (! array_is_list($items)) ksort($items);
            return $items;
        };
        return hash('sha256', json_encode($canonical($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** @return array<string,mixed> */
    public function recordReceipt(ResearchExperimentReceipt $receipt): array
    {
        if (! Schema::hasTable('research_knowledge_entries')) return $this->unavailable();
        $payload = (array) $receipt->payload;
        $classification = (string) $receipt->classification;
        $type = match ($classification) {
            'POSITIVE_CANDIDATE' => 'CAUSAL',
            'BEHAVIORAL_ACTIVATION_HYPOTHESIS' => 'SEMANTIC',
            'HARMFUL' => 'NEGATIVE',
            // Missing data or power describes the experiment, not a harmful skill.
            'UNDERPOWERED', 'INCONCLUSIVE' => 'EPISODIC',
            'TECHNICAL_QUARANTINE' => 'COUNTERFACTUAL',
            default => 'EPISODIC',
        };
        $scope = (array) data_get($payload, 'contract.scope', [
            'symbol' => $receipt->symbol, 'laboratory_timeframe' => $receipt->laboratory_timeframe,
        ]);
        $key = hash('sha256', implode('|', [self::PROTOCOL, 'receipt', $receipt->receipt_key, $type]));
        DB::table('research_knowledge_entries')->updateOrInsert(['knowledge_key' => $key], [
            'knowledge_type' => $type, 'subject_type' => ResearchExperimentReceipt::class, 'subject_key' => $receipt->receipt_key,
            'symbol' => $receipt->symbol, 'timeframe' => $receipt->laboratory_timeframe,
            'authority' => 'research_only', 'freshness' => 'active', 'status' => 'recorded', 'scope' => json_encode($scope),
            'claim' => json_encode(['classification' => $classification, 'hypothesis' => data_get($payload, 'contract.claim.hypothesis'),
                'target_stage' => data_get($payload, 'contract.claim.target_stage'), 'promotion_evidence' => false]),
            'evidence' => json_encode(['receipt_key' => $receipt->receipt_key, 'evidence_hash' => $receipt->evidence_hash,
                'receipt_payload_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES)), 'promotion_evidence' => false]),
            'dependencies' => json_encode(['receipt_id' => $receipt->id, 'contract_hash' => $receipt->contract_hash]),
            'recorded_at' => now(), 'updated_at' => now(), 'created_at' => now(),
        ]);
        return ['protocol' => self::PROTOCOL, 'status' => 'recorded', 'knowledge_type' => $type, 'knowledge_key' => $key, 'promotion_evidence' => false];
    }

    /**
     * Register a proposed inherited cartridge for a host. It remains blocked
     * until that host completes an independently paired transplant.
     *
     * @return array<string,mixed>
     */
    public function proposePortfolioEntry(int $hostModelVersionId, array $cartridge, array $contextualTrust, array $selfKnowledge = []): array
    {
        if (! Schema::hasTable('research_skill_portfolio_entries')) return $this->unavailable();
        $status = (string) ($cartridge['status'] ?? $cartridge['component_status'] ?? '');
        $cartridgeId = (int) ($cartridge['id'] ?? $cartridge['cartridge_id'] ?? 0);
        if ($cartridgeId <= 0 || ! in_array($status, ['confirmed', 'confirmed_component'], true)) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason' => 'CONFIRMED_CARTRIDGE_REQUIRED', 'promotion_evidence' => false];
        }
        $symbol = strtoupper((string) ($cartridge['symbol'] ?? 'XAUUSD'));
        $timeframe = strtoupper((string) ($cartridge['timeframe'] ?? 'H1'));
        $key = hash('sha256', implode('|', [self::PROTOCOL, 'portfolio', $hostModelVersionId, $cartridgeId, $symbol, $timeframe]));
        $outcomes = $this->outcomeVector($cartridge);
        DB::table('research_skill_portfolio_entries')->updateOrInsert(['portfolio_key' => $key], [
            'host_model_version_id' => $hostModelVersionId, 'skill_cartridge_id' => $cartridgeId, 'symbol' => $symbol, 'timeframe' => $timeframe,
            'status' => 'paired_transplant_required', 'contextual_trust' => json_encode($contextualTrust), 'outcome_vector' => json_encode($outcomes),
            'self_knowledge' => json_encode($selfKnowledge), 'evidence' => json_encode([
                'protocol' => self::PROTOCOL, 'cartridge_status' => $status, 'transfer_required' => true,
                'context_authority_transfers_automatically' => false, 'promotion_evidence' => false,
            ]), 'updated_at' => now(), 'created_at' => now(),
        ]);
        return ['protocol' => self::PROTOCOL, 'status' => 'paired_transplant_required', 'portfolio_key' => $key,
            'outcome_vector' => $outcomes, 'promotion_evidence' => false];
    }

    /** @return array<string,mixed> */
    public function recommend(int $hostModelVersionId, array $objective, string $symbol = 'XAUUSD', string $timeframe = 'H1'): array
    {
        if (! Schema::hasTable('research_skill_portfolio_entries')) return $this->unavailable();
        $rows = DB::table('research_skill_portfolio_entries')->where('host_model_version_id', $hostModelVersionId)
            ->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->get();
        $ranked = $rows->map(function (object $row) use ($objective): array {
            $vector = (array) json_decode((string) $row->outcome_vector, true);
            // Collection::sum() callbacks receive only the value in the
            // supported framework version. Preserve metric keys explicitly
            // so a portfolio is scored against the requested outcome axes.
            $distance = 0.0;
            foreach ($objective as $metric => $target) {
                if (is_numeric($target) && is_numeric($vector[$metric] ?? null)) {
                    $distance += abs((float) $target - (float) $vector[$metric]);
                }
            }
            return ['portfolio_key' => $row->portfolio_key, 'skill_cartridge_id' => $row->skill_cartridge_id,
                'status' => $row->status, 'outcome_vector' => $vector, 'objective_distance' => round($distance, 6)];
        })->sortBy('objective_distance')->values();
        return ['protocol' => self::PROTOCOL, 'status' => $ranked->isEmpty() ? 'no_eligible_portfolio' : 'research_recommendation',
            'recommendations' => $ranked->all(), 'live_router_allowed' => false, 'promotion_evidence' => false];
    }

    /** @return array<string,float|null> */
    private function outcomeVector(array $cartridge): array
    {
        $evidence = (array) ($cartridge['evidence'] ?? []);
        return [
            'setup_density' => $this->number($cartridge, $evidence, ['setup_density', 'effect_vector.setup_density']),
            'confirmation_precision' => $this->number($cartridge, $evidence, ['confirmation_precision', 'effect_vector.confirmation_precision']),
            'trade_density' => $this->number($cartridge, $evidence, ['trade_density', 'effect_vector.trade_density']),
            'mfe' => $this->number($cartridge, $evidence, ['mfe', 'secondary.mfe_capture_delta']),
            'mae' => $this->number($cartridge, $evidence, ['mae']),
            'cost_exposure' => $this->number($cartridge, $evidence, ['cost_exposure', 'secondary.cost_delta']),
            'holding_time' => $this->number($cartridge, $evidence, ['holding_time']),
            'drawdown' => $this->number($cartridge, $evidence, ['drawdown', 'secondary.drawdown_delta']),
            'abstention_rate' => $this->number($cartridge, $evidence, ['abstention_rate']),
        ];
    }

    private function number(array $cartridge, array $evidence, array $paths): ?float
    {
        foreach ($paths as $path) {
            $value = data_get($cartridge, $path, data_get($evidence, $path));
            if (is_numeric($value)) return (float) $value;
        }
        return null;
    }

    private function unavailable(): array { return ['protocol' => self::PROTOCOL, 'status' => 'migration_pending', 'promotion_evidence' => false]; }
}
