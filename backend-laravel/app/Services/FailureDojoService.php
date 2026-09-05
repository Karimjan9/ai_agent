<?php

namespace App\Services;

use App\Models\LabFailureDojoRun;
use App\Models\LabLearningLanePair;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/** Keeps failed states executable as focused research curriculum, never as promotion evidence. */
class FailureDojoService
{
    public const PROTOCOL = 'failure_dojo_v1';

    public function available(): bool
    {
        return Schema::hasTable('lab_failure_dojo_runs');
    }

    public function recordPair(LabLearningLanePair $pair): ?LabFailureDojoRun
    {
        if (! $this->available() || ! $pair->failure_signature) return null;

        $pair->loadMissing('candidateAgent.modelVersion', 'controlResponseMap');
        $verifiedControl = $pair->isVerifiedControlPair();
        $signature = (array) $pair->failure_signature;
        $repairAnchorId = (int) data_get($pair->candidateAgent?->modelVersion?->metadata, 'repair_anchor.id', 0);
        $parameterDiff = (array) ($pair->candidateAgent?->parameter_diff ?? []);
        $compiler = app(CausalSkillCompilerService::class);
        $causalSkill = (array) data_get($pair->metadata, 'causal_skill_compiler', []);
        if ($causalSkill === []) {
            $causalSkill = $compiler->compile(
                $pair->candidateAgent,
                $signature,
                [
                    ...((array) $pair->candidate_metrics),
                    'control_pair_available' => $pair->control_agent_id !== null,
                    'same_snapshot' => (bool) data_get($pair->metadata, 'same_snapshot', false),
                    'same_execution_contract' => (bool) data_get($pair->metadata, 'same_execution_contract', false),
                    'mutation_observability' => ['behavioral_delta' => $pair->target_delta],
                ],
                (array) $pair->target_delta,
            );
        }
        $priority = $compiler->informationGainPriority($signature, [
            'novelty' => data_get($causalSkill, 'behavioral_delta.observable_effect', false) ? .75 : .35,
            'causal_leverage' => $pair->control_agent_id !== null ? .80 : .20,
            'replay_readiness' => data_get($causalSkill, 'prediction_contract.status') === 'declared' ? .80 : .35,
            'repeat_count' => (int) data_get($pair->metadata, 'repeat_count', 0),
        ]);
        $structuralEscape = $compiler->structuralEscapeContract([
            ...((array) data_get($pair->metadata, 'failure_history', [])),
            $signature,
        ]);
        $hybridLane = (string) data_get($pair->candidateAgent?->modelVersion?->metadata, 'hybrid_evolution.lane', '');
        $outcome = $this->outcomeFor($pair);
        $hybridAction = app(HybridEvolutionContractService::class)->failureAction($outcome, [
            'pair_id' => $pair->id,
            'target' => $pair->target,
            'hybrid_lane' => $hybridLane,
        ]);
        $contextFirewall = $this->contextFirewall($signature);
        $key = hash('sha256', json_encode([
            // Pair IDs and replay-window IDs are observations, not a causal
            // cell identity. Evidence for the same intervention/context is
            // aggregated below instead of creating a duplicate Dojo backlog.
            self::PROTOCOL, $pair->symbol, $pair->timeframe, $pair->strategy_family, $pair->target,
            data_get($signature, 'signature'), data_get($signature, 'changed_gene'),
            data_get($signature, 'old_value'), data_get($signature, 'new_value'), $contextFirewall['identity'],
        ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        $run = LabFailureDojoRun::query()->firstOrCreate(
            ['dojo_key' => $key],
            [
                'pair_id' => $pair->id,
                'candidate_agent_id' => $pair->candidate_agent_id,
                'repair_anchor_id' => $repairAnchorId > 0 ? $repairAnchorId : null,
                'symbol' => $pair->symbol,
                'timeframe' => $pair->timeframe,
                'family' => $pair->strategy_family,
                'target' => $pair->target,
                'state_signature' => data_get($signature, 'signature') ?: data_get($signature, 'failure_type'),
                'expected_action' => $this->expectedAction($pair),
                // A historical baseline remains useful diagnostic context,
                // but it must never turn into actionable replay backlog. New
                // rows therefore fail closed at creation time; legacy rows
                // remain immutable and are classified separately by summary().
                'status' => ! $verifiedControl ? 'diagnostic_only'
                    : (($contextFirewall['complete'] ?? false) ? 'pending' : 'context_incomplete_research_debt'),
                'failure_signature' => $signature,
                'evidence' => [
                    'protocol' => self::PROTOCOL,
                    'failure_state' => data_get($signature, 'state', []),
                    'counterfactual_status' => 'pending',
                    'frozen_control_required' => true,
                    'control_pair_status' => $verifiedControl ? 'verified' : 'diagnostic_only',
                    'diagnostic_reason' => ! $verifiedControl ? 'CONTROL_PAIR_INVALID'
                        : (($contextFirewall['complete'] ?? false) ? null : 'CONTEXT_INCOMPLETE'),
                    'context_firewall' => $contextFirewall,
                    'causal_skill_compiler' => $causalSkill,
                    'information_gain_priority' => $priority,
                    'structural_escape' => $structuralEscape,
                    'mutation_contract' => [
                        'protocol' => 'failure_to_mutation_v1',
                        'declared_gene' => count($parameterDiff) === 1 ? array_key_first($parameterDiff) : null,
                        'expected_action' => $this->expectedAction($pair),
                        'technical_failure_mutation_forbidden' => true,
                        'hybrid_evolution' => [
                            'lane' => $hybridLane !== '' ? $hybridLane : 'directed_repair',
                            'failure_action' => $hybridAction,
                            'learn_from_error' => true,
                        ],
                        'promotion_evidence' => false,
                    ],
                    'learning_action' => $hybridAction,
                    'promotion_evidence' => false,
                ],
            ],
        );

        $existingEvidence = (array) $run->evidence;
        $observationPairs = collect((array) data_get($existingEvidence, 'observation_pair_ids', []))
            ->push($pair->id)->filter()->unique()->sort()->values()->all();
        $run->update(['evidence' => [...$existingEvidence,
            'observation_pair_ids' => $observationPairs,
            'observation_support' => count($observationPairs),
            'context_firewall' => $contextFirewall,
            'promotion_evidence' => false,
        ]]);

        // Every failure now receives a strategic research plan, even when it
        // is still pending.  This is a ranking and lesson contract only; it
        // never dispatches a replay and never changes promotion state.
        $director = app(StrategicResearchDirectorService::class);
        $plan = $director->planFor($run);
        $run->update(['evidence' => [
            ...((array) $run->evidence),
            'strategic_research_director' => $plan,
            'promotion_evidence' => false,
        ]]);

        return $run->fresh();
    }

    /** Terminalize legacy pending rows that lack the mandatory context identity. */
    public function reconcileContextFirewall(string $symbol, string $timeframe, bool $apply = false): array
    {
        if (! $this->available()) return ['available' => false, 'status' => 'unavailable'];
        $rows = $this->scopedQuery($symbol, $timeframe)->where('status', 'pending')->get();
        $incomplete = $rows->filter(fn (LabFailureDojoRun $run): bool => ! $this->contextFirewall((array) $run->failure_signature)['complete']);
        if ($apply) {
            $incomplete->each(function (LabFailureDojoRun $run): void {
                $firewall = $this->contextFirewall((array) $run->failure_signature);
                $run->update(['status' => 'context_incomplete_research_debt', 'evidence' => [
                    ...((array) $run->evidence), 'context_firewall' => $firewall,
                    'diagnostic_reason' => 'CONTEXT_INCOMPLETE', 'promotion_evidence' => false,
                ]]);
            });
            Cache::forget('failure-dojo:summary:'.strtoupper($symbol).':'.strtoupper($timeframe));
        }
        return ['protocol' => self::PROTOCOL, 'status' => $apply ? 'reconciled' : 'would_reconcile',
            'pending_examined' => $rows->count(), 'context_incomplete' => $incomplete->count(),
            'promotion_evidence' => false];
    }

    /**
     * Versioned reprojection for legacy free-form context. It never erases
     * the former signature: the exact v1 state is retained under provenance
     * and no replay/promotion is opened by this reconciliation.
     */
    public function reprojectContextContractV2(string $symbol, string $timeframe, bool $apply = false): array
    {
        if (! $this->available()) return ['available' => false, 'status' => 'unavailable'];
        $rows = $this->scopedQuery($symbol, $timeframe)->get();
        $service = app(ContextContractV2Service::class);
        $changed = 0; $incomplete = 0;
        foreach ($rows as $run) {
            $signature = (array) $run->failure_signature;
            $state = (array) data_get($signature, 'state', []);
            $projection = $service->project($state);
            $already = data_get($state, 'context_contract.protocol') === ContextContractV2Service::PROTOCOL
                && data_get($state, 'context_contract.identity_hash') === data_get($projection, 'identity_hash');
            if (data_get($projection, 'status') !== 'valid') $incomplete++;
            if ($already) continue;
            $changed++;
            if (! $apply) continue;
            $prior = (array) data_get($run->evidence, 'context_contract_reprojections', []);
            $key = (string) data_get($projection, 'identity_hash');
            $prior[$key] = ['protocol' => ContextContractV2Service::PROTOCOL, 'source_state' => $state,
                'projection' => $projection, 'reprojected_at' => now()->utc()->toIso8601String(), 'promotion_evidence' => false];
            $newState = [...$state, 'regime' => data_get($projection, 'axes.regime'),
                'volatility' => data_get($projection, 'axes.volatility'), 'session' => data_get($projection, 'axes.session'),
                'context_contract' => $projection];
            $run->update(['failure_signature' => [...$signature, 'state' => $newState], 'evidence' => [
                ...((array) $run->evidence), 'context_contract_reprojections' => $prior,
                'promotion_evidence' => false,
            ]]);
        }
        if ($apply) Cache::forget('failure-dojo:summary:'.strtoupper($symbol).':'.strtoupper($timeframe));

        return ['protocol' => ContextContractV2Service::PROTOCOL, 'status' => $apply ? 'reprojected' : 'would_reproject',
            'examined' => $rows->count(), 'changed' => $changed, 'context_incomplete' => $incomplete, 'promotion_evidence' => false];
    }

    public function recordAssessment(LabLearningLanePair $pair, array $assessment): ?LabFailureDojoRun
    {
        $run = $this->recordPair($pair);
        if (! $run) return null;
        $run->update([
            'status' => (string) ($assessment['status'] ?? 'unresolved'),
            'score' => isset($assessment['score']) ? (float) $assessment['score'] : null,
            'evidence' => [
                ...((array) $run->evidence),
                'causal_skill_compiler' => app(CausalSkillCompilerService::class)->compile(
                    $pair->candidateAgent,
                    (array) $pair->failure_signature,
                    [
                        ...((array) ($assessment['result'] ?? [])),
                        'control_pair_available' => $pair->control_agent_id !== null,
                        'mutation_observability' => ['behavioral_delta' => $pair->target_delta],
                        'counterfactual_replay' => ['branches' => (array) ($assessment['branches'] ?? [])],
                    ],
                    (array) $pair->target_delta,
                ),
                'micro_protocol' => $assessment['protocol'] ?? 'micro_replay_v1',
                'windows' => $assessment['windows'] ?? [],
                'causal_probe' => $assessment['causal_probe'] ?? [],
                'reason' => $assessment['reason'] ?? null,
                'learning_action' => app(HybridEvolutionContractService::class)->failureAction(
                    (string) ($assessment['outcome'] ?? (($assessment['status'] ?? '') === 'failed' ? 'strategy_failure' : 'uncertainty')),
                    ['pair_id' => $pair->id, 'target' => $pair->target],
                ),
                'promotion_evidence' => false,
            ],
            'evaluated_at' => now(),
        ]);
        $plan = (array) data_get($run->fresh()->evidence, 'strategic_research_director', []);
        if ($plan !== []) {
            $run->update(['evidence' => [
                ...((array) $run->evidence),
                'strategic_research_director_prediction' => app(StrategicResearchDirectorService::class)->scorePrediction(
                    $plan,
                    (array) ($assessment['result'] ?? []),
                ),
                'promotion_evidence' => false,
            ]]);
        }
        return $run->fresh();
    }

    /** @return array<string, mixed> */
    public function progress(string $symbol, string $timeframe): array
    {
        if (! $this->available()) return ['available' => false];
        $scope = $this->summary($symbol, $timeframe);
        $frontier = $this->pendingFrontier($symbol, $timeframe, 5);
        return [
            ...$scope,
            'priority_protocol' => 'information_gain_priority_v1',
            'strategic_research_director' => [
                'protocol' => StrategicResearchDirectorService::PROTOCOL,
                'planned_runs' => $this->scopedQuery($symbol, $timeframe)->whereNotNull('evidence->strategic_research_director')->count(),
                'frontier' => collect($frontier)->map(fn (LabFailureDojoRun $run): array => [
                    'run_id' => $run->id,
                    'action' => data_get($run->evidence, 'strategic_research_director.decision_action'),
                    'experiment_value' => data_get($run->evidence, 'strategic_research_director.experiment_value.score'),
                    'promotion_evidence' => false,
                ])->all(),
                'promotion_evidence' => false,
            ],
            'promotion_evidence' => false,
        ];
    }

    /** One indexed aggregate for fast dashboards; evidence is never promoted. */
    public function summary(string $symbol, string $timeframe): array
    {
        if (! $this->available()) return ['available' => false];
        $symbol = strtoupper($symbol);
        $timeframe = strtoupper($timeframe);

        return Cache::remember("failure-dojo:summary:{$symbol}:{$timeframe}", now()->addSeconds(15), function () use ($symbol, $timeframe): array {
            $row = $this->scopedQuery($symbol, $timeframe)
                ->selectRaw('COUNT(*) AS total')
                ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending")
                ->selectRaw("SUM(CASE WHEN status = 'passed' THEN 1 ELSE 0 END) AS passed")
                ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed")
                ->first();
            $pendingRuns = $this->scopedQuery($symbol, $timeframe)
                ->where('status', 'pending')
                ->with('pair.controlResponseMap')
                ->get();
            $actionable = $pendingRuns->filter(fn (LabFailureDojoRun $run): bool => $run->pair !== null
                && $run->pair->isVerifiedControlPair()
                && filled($run->expected_action));

            return [
                'available' => true,
                'total' => (int) ($row->total ?? 0),
                'pending' => (int) ($row->pending ?? 0),
                'passed' => (int) ($row->passed ?? 0),
                'failed' => (int) ($row->failed ?? 0),
                'actionable_pending' => $actionable->count(),
                'legacy_invalid_pending' => $pendingRuns->count() - $actionable->count(),
                'cached_for_seconds' => 15,
            ];
        });
    }

    private function scopedQuery(string $symbol, string $timeframe)
    {
        return LabFailureDojoRun::query()
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe));
    }

    /**
     * Return pending dojo work by information gain instead of insertion
     * order. The returned rows are still diagnostic and cannot promote an
     * agent or grant mutation credit.
     *
     * @return array<int, LabFailureDojoRun>
     */
    public function pendingFrontier(string $symbol, string $timeframe, int $limit = 20): array
    {
        if (! $this->available()) return [];
        $runs = LabFailureDojoRun::query()
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->where('status', 'pending')
            ->latest('id')
            ->limit(max(1, min(500, $limit * 20)))
            ->with('pair.controlResponseMap')
            ->get()
            ->filter(fn (LabFailureDojoRun $run): bool => $run->pair !== null && $run->pair->isVerifiedControlPair())
            ->sortByDesc(fn (LabFailureDojoRun $run): array => [
                (float) data_get($run->evidence, 'strategic_research_director.experiment_value.score', 0),
                (float) data_get($run->evidence, 'information_gain_priority.score', 0),
                (bool) data_get($run->evidence, 'causal_skill_compiler.behavioral_delta.observable_effect', false) ? 1 : 0,
                -((int) $run->id),
            ])
            ->values();

        return app(FailurePortfolioSchedulerService::class)->schedule($runs, $limit);
    }

    private function outcomeFor(LabLearningLanePair $pair): string
    {
        if (! $pair->loadMissing('controlResponseMap')->isVerifiedControlPair()) {
            return 'diagnostic_only';
        }
        $signature = (array) $pair->failure_signature;
        $type = strtolower((string) data_get($signature, 'failure_type', data_get($signature, 'type', '')));
        if (str_contains($type, 'technical') || str_contains($type, 'evidence')) return 'technical_error';
        $repeat = (int) data_get($signature, 'repeat_count', 0);
        return $repeat > 1 ? 'repeated_failure' : 'strategy_failure';
    }

    private function expectedAction(LabLearningLanePair $pair): string
    {
        $target = strtolower((string) $pair->target);
        return match (true) {
            str_contains($target, 'drawdown'), str_contains($target, 'risk') => 'reduce_risk_or_veto',
            str_contains($target, 'stress'), str_contains($target, 'cost') => 'repair_cost_exit',
            str_contains($target, 'temporal'), str_contains($target, 'monthly') => 'repair_time_stability',
            default => 'repair_declared_gene',
        };
    }

    /** @return array<string,mixed> */
    private function contextFirewall(array $signature): array
    {
        $state = (array) data_get($signature, 'state', []);
        $contract = (array) data_get($state, 'context_contract', []);
        if (data_get($contract, 'protocol') === ContextContractV2Service::PROTOCOL) {
            return ['protocol' => 'failure_dojo_context_firewall_v1', 'context_contract' => $contract,
                'complete' => data_get($contract, 'status') === 'valid',
                'missing_axes' => (array) data_get($contract, 'invalid_axes', []),
                'identity' => data_get($contract, 'identity_hash'),
                'dispatch_allowed' => data_get($contract, 'status') === 'valid', 'promotion_evidence' => false];
        }
        $values = [
            'regime' => data_get($state, 'regime'), 'volatility' => data_get($state, 'volatility'),
            'session' => data_get($state, 'session'), 'volume_state' => data_get($state, 'volume_state'),
        ];
        $missing = collect($values)->filter(fn (mixed $value): bool => $value === null || $value === ''
            || in_array(strtolower((string) $value), ['unknown', 'unavailable'], true))->keys()->values()->all();
        return ['protocol' => 'failure_dojo_context_firewall_v1', 'complete' => $missing === [],
            'missing_axes' => $missing, 'identity' => hash('sha256', json_encode($values, JSON_UNESCAPED_SLASHES)),
            'dispatch_allowed' => $missing === [], 'promotion_evidence' => false];
    }
}
