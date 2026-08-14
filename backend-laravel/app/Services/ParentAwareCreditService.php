<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LabCouncilAblationRun;
use App\Models\LabEvolutionCreditEvent;
use App\Models\LabParentCounterfactual;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use Illuminate\Support\Facades\Schema;

/**
 * Records parent-aware outcomes without allowing a parent to claim a child's
 * success. Performance, learning and discovery are separate credit types.
 */
class ParentAwareCreditService
{
    public const PROTOCOL = 'parent_aware_evolution_credit_v1';

    public function __construct(private ParentContextTrustService $trust)
    {
    }

    /** @return array<string, mixed> */
    public function registerCandidate(LabAgent $agent): array
    {
        if (! $this->available()) return ['status' => 'migration_pending', 'promotion_evidence' => false];
        $agent->loadMissing('modelVersion');
        $broker = (array) data_get($agent->modelVersion?->metadata, 'parent_mentor_broker', []);
        $proposal = (array) data_get($broker, 'parent_suggestion', []);
        $parentId = (int) data_get($proposal, 'parent_model_version_id', 0);
        $lane = (string) data_get($broker, 'lane', 'autonomous');
        if ($parentId <= 0 || ! in_array($lane, ['mentor_assisted', 'cross_skill_composition'], true)) {
            return [
                'protocol' => self::PROTOCOL,
                'status' => 'autonomous_lane',
                'counterfactual_required' => false,
                'promotion_evidence' => false,
            ];
        }

        $context = $this->contextFromAgent($agent, $broker);
        $autonomousSibling = $agent->generation?->agents()
            ->with('modelVersion')
            ->whereKeyNot($agent->id)
            ->where('strategy_family', $agent->strategy_family)
            ->get()
            ->first(function (LabAgent $candidate) use ($agent): bool {
                $metadata = (array) ($candidate->modelVersion?->metadata ?? []);
                return (string) data_get($metadata, 'parent_mentor_broker.lane', '') === 'autonomous'
                    && (string) data_get($metadata, 'generation_target', '') === (string) data_get($agent->modelVersion?->metadata, 'generation_target', '');
            });
        $initialStatus = $autonomousSibling ? 'awaiting_ablated_branch' : 'awaiting_branches';
        $row = LabParentCounterfactual::query()->firstOrCreate(
            [
                'candidate_agent_id' => $agent->id,
                'context_key' => $context['context_key'],
            ],
            [
                'candidate_model_version_id' => $agent->model_version_id,
                'parent_model_version_id' => $parentId,
                'autonomous_model_version_id' => $autonomousSibling?->model_version_id,
                'symbol' => strtoupper($agent->symbol),
                'timeframe' => strtoupper($agent->timeframe),
                'strategy_family' => $agent->strategy_family,
                'snapshot_hash' => data_get($agent->modelVersion?->metadata, 'execution_contract.snapshot_hash'),
                'execution_hash' => data_get($agent->modelVersion?->metadata, 'execution_contract.execution_hash'),
                'status' => $initialStatus,
                'payload' => [
                    'protocol' => self::PROTOCOL,
                    'required_branches' => config('services.lab_selection.parent_counterfactual_branches', ['autonomous', 'mentored', 'ablated']),
                    'parent_suggestion' => $proposal,
                    'context' => $context,
                    'same_snapshot_required' => true,
                    'same_execution_contract_required' => true,
                    'parent_credit_blocked_until_evaluated' => true,
                    'autonomous_branch_model_version_id' => $autonomousSibling?->model_version_id,
                    'ablated_branch' => 'required_same_snapshot_skill_ablation',
                    'promotion_evidence' => false,
                ],
            ],
        );
        if ($autonomousSibling && ! $row->autonomous_model_version_id) {
            $row->update([
                'autonomous_model_version_id' => $autonomousSibling->model_version_id,
                'status' => $row->status === 'awaiting_branches' ? 'awaiting_ablated_branch' : $row->status,
            ]);
        }

        return [
            'protocol' => self::PROTOCOL,
            'status' => $row->status,
            'counterfactual_id' => (int) $row->id,
            'parent_model_version_id' => $parentId,
            'context' => $context,
            'required_branches' => ['autonomous', 'mentored', 'ablated'],
            'parent_credit_blocked_until_evaluated' => true,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function recordScreening(LabAgent $agent, array $result, string $decision): array
    {
        if (! $this->available() || $this->technical($result)) {
            return ['protocol' => self::PROTOCOL, 'status' => 'technical_or_migration_blocked', 'promotion_evidence' => false];
        }
        $agent->loadMissing('modelVersion');
        $context = $this->contextFromAgent($agent, (array) data_get($agent->modelVersion?->metadata, 'parent_mentor_broker', []));
        $events = [];
        if ($decision === 'passed') {
            $events[] = $this->credit($agent, 'performance', 1.0, 'screen_pass_observed', $context, $result);
        } else {
            // A clean strategy failure is useful negative knowledge, but it is
            // never the same as technical success and never opens promotion.
            $events[] = $this->credit($agent, 'learning', .50, 'strategy_failure_observed', $context, $result);
        }
        if ($this->discoveryLane($agent)) {
            $events[] = $this->credit($agent, 'discovery', .50, 'research_hypothesis_observed', $context, $result);
        }
        return [
            'protocol' => self::PROTOCOL,
            'status' => 'recorded',
            'events' => $events,
            'context' => $context,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function recordFullReplay(
        LabAgent $agent,
        array $result,
        ?ModelMarketPerformance $performance = null,
        ?object $forwardDecision = null,
    ): array {
        if (! $this->available() || $this->technical($result)) {
            return ['protocol' => self::PROTOCOL, 'status' => 'technical_or_migration_blocked', 'promotion_evidence' => false];
        }
        $agent->loadMissing('modelVersion');
        $broker = (array) data_get($agent->modelVersion?->metadata, 'parent_mentor_broker', []);
        $context = $this->contextFromAgent($agent, $broker);
        $events = [];
        $forwardPassed = data_get($forwardDecision, 'decision') === 'passed';
        $targetImproved = (bool) data_get($result, 'target_delta.improved', false)
            || (bool) data_get($result, 'verified_mutation_skill.target_gate.improved', false);
        if ($forwardPassed || $targetImproved) {
            $events[] = $this->credit($agent, 'performance', 1.0, $forwardPassed ? 'independent_forward_pass' : 'target_gate_improved', $context, $result);
        }
        if (data_get($result, 'failure_signature') !== null || data_get($result, 'learning_lane_projection') !== null) {
            $events[] = $this->credit($agent, 'learning', 1.0, 'full_replay_learning_observed', $context, $result);
        }
        if ($this->discoveryLane($agent) || data_get($result, 'discovery_credit') !== null) {
            $events[] = $this->credit($agent, 'discovery', 1.0, 'full_replay_discovery_observed', $context, $result);
        }

        $counterfactual = $this->recordCounterfactual($agent, $result, $broker, $context);
        $model = $agent->modelVersion;
        if ($model) {
            $metadata = (array) $model->metadata;
            $metadata['parent_counterfactual'] = [
                ...((array) data_get($metadata, 'parent_counterfactual', [])),
                ...$counterfactual,
                'context' => $context,
                'promotion_evidence' => false,
            ];
            $metadata['parent_aware_evolution']['last_full_replay_credit'] = [
                'protocol' => self::PROTOCOL,
                'events' => $events,
                'counterfactual_status' => data_get($counterfactual, 'status'),
                'promotion_evidence' => false,
            ];
            $model->update(['metadata' => $metadata]);
        }
        return [
            'protocol' => self::PROTOCOL,
            'status' => $counterfactual['status'] ?? 'recorded',
            'events' => $events,
            'counterfactual' => $counterfactual,
            'context' => $context,
            'parent_incremental_value' => data_get($counterfactual, 'parent_incremental_value'),
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function monitor(string $symbol, string $timeframe = 'H1'): array
    {
        if (! $this->available()) return ['protocol' => self::PROTOCOL, 'status' => 'migration_pending', 'promotion_evidence' => false];
        $scope = fn ($query) => $query->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe));
        $credits = LabEvolutionCreditEvent::query()->where($scope)->get();
        $counterfactuals = LabParentCounterfactual::query()->where($scope)->get();
        $trust = \App\Models\LabParentContextScore::query()->where($scope)->get();
        $ablations = LabCouncilAblationRun::query()->where($scope)->get();
        return [
            'protocol' => self::PROTOCOL,
            'status' => 'available',
            'symbol' => strtoupper($symbol),
            'timeframe' => strtoupper($timeframe),
            'credit_events' => $credits->count(),
            'performance_credit' => $credits->where('event_type', 'performance')->count(),
            'learning_credit' => $credits->where('event_type', 'learning')->count(),
            'discovery_credit' => $credits->where('event_type', 'discovery')->count(),
            'counterfactuals' => $counterfactuals->count(),
            'counterfactual_statuses' => $counterfactuals->countBy('status')->all(),
            'parent_trust_rows' => $trust->count(),
            'context_confirmed_parents' => $trust->where('status', 'context_confirmed')->count(),
            'context_downranked_parents' => $trust->where('status', 'context_downranked')->count(),
            'council_ablation_runs' => $ablations->count(),
            'council_ablation_completed' => $ablations->where('status', 'completed')->count(),
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function recordCounterfactual(LabAgent $agent, array $result, array $broker, array $context): array
    {
        $proposal = (array) data_get($broker, 'parent_suggestion', []);
        $parentId = (int) data_get($proposal, 'parent_model_version_id', 0);
        if ($parentId <= 0 || ! in_array((string) data_get($broker, 'lane'), ['mentor_assisted', 'cross_skill_composition'], true)) {
            return ['status' => 'not_required', 'promotion_evidence' => false];
        }
        $row = LabParentCounterfactual::query()
            ->where('candidate_agent_id', $agent->id)
            ->where('context_key', $context['context_key'])
            ->first();
        if (! $row) {
            $this->registerCandidate($agent);
            $row = LabParentCounterfactual::query()
                ->where('candidate_agent_id', $agent->id)
                ->where('context_key', $context['context_key'])
                ->first();
        }
        if (! $row) return ['status' => 'counterfactual_registration_failed', 'promotion_evidence' => false];

        $branches = (array) data_get($result, 'parent_counterfactual', data_get($result, 'parent_counterfactual_branches', []));
        $metrics = [];
        foreach (['autonomous', 'mentored', 'ablated'] as $branch) {
            if (! array_key_exists($branch, $branches)) continue;
            $metrics[$branch] = $this->score((array) $branches[$branch]);
        }
        if (count($metrics) < 3 || in_array(null, $metrics, true)) {
            $row->update(['status' => 'awaiting_branches', 'payload' => [
                ...((array) $row->payload),
                'last_result_evidence_run_id' => data_get($result, 'evidence_run_id'),
                'missing_branches' => array_values(array_diff(['autonomous', 'mentored', 'ablated'], array_keys($metrics))),
                'promotion_evidence' => false,
            ]]);
            return [
                'status' => 'awaiting_branches',
                'counterfactual_id' => (int) $row->id,
                'required_branches' => ['autonomous', 'mentored', 'ablated'],
                'promotion_evidence' => false,
            ];
        }

        $incremental = $metrics['mentored'] - $metrics['autonomous'];
        $minimum = (float) config('services.lab_selection.parent_credit_min_incremental_value', .0001);
        $status = $incremental > $minimum && $metrics['mentored'] >= $metrics['ablated']
            ? 'parent_helpful'
            : ($incremental < -$minimum ? 'parent_harmful' : 'child_independent');
        $trustOutcome = $status === 'parent_helpful' ? 'positive' : ($status === 'parent_harmful' ? 'negative' : 'uncertainty');
        $parentModel = ModelVersion::find($parentId);
        if (! $parentModel) {
            return [
                'status' => 'parent_model_missing',
                'counterfactual_id' => (int) $row->id,
                'promotion_evidence' => false,
            ];
        }
        $trust = $this->trust->record(
            $parentModel,
            $agent->symbol,
            $agent->timeframe,
            $agent->strategy_family,
            (string) data_get($proposal, 'skill_key', 'unknown_skill'),
            $context,
            $trustOutcome,
            $incremental,
            ['evidence_run_id' => data_get($result, 'evidence_run_id'), 'counterfactual_status' => $status],
        );
        $row->update([
            'status' => $status,
            'autonomous_score' => $metrics['autonomous'],
            'mentored_score' => $metrics['mentored'],
            'ablated_score' => $metrics['ablated'],
            'parent_incremental_value' => $incremental,
            'evidence_run_ids' => array_values(array_filter([data_get($result, 'evidence_run_id')])),
            'payload' => [
                ...((array) $row->payload),
                'branch_metrics' => $metrics,
                'parent_suggestion' => $proposal,
                'trust_update' => $trust,
                'promotion_evidence' => false,
            ],
            'evaluated_at' => now()->utc(),
        ]);

        return [
            'status' => $status,
            'counterfactual_id' => (int) $row->id,
            'autonomous_score' => $metrics['autonomous'],
            'mentored_score' => $metrics['mentored'],
            'ablated_score' => $metrics['ablated'],
            'parent_incremental_value' => $incremental,
            'trust_update' => $trust,
            'promotion_evidence' => false,
        ];
    }

    private function credit(LabAgent $agent, string $type, float $amount, string $status, array $context, array $evidence): array
    {
        $fingerprint = hash('sha256', json_encode([
            'protocol' => self::PROTOCOL,
            'agent' => $agent->id,
            'model' => $agent->model_version_id,
            'type' => $type,
            'status' => $status,
            'evidence_run_id' => data_get($evidence, 'evidence_run_id'),
            'context_key' => $context['context_key'],
        ], JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));
        $parentId = (int) data_get($agent->modelVersion?->metadata, 'parent_mentor_broker.parent_suggestion.parent_model_version_id', 0);
        $event = LabEvolutionCreditEvent::firstOrCreate(
            ['evidence_fingerprint' => $fingerprint],
            [
                'lab_agent_id' => $agent->id,
                'model_version_id' => $agent->model_version_id,
                'parent_model_version_id' => $parentId > 0 ? $parentId : null,
                'symbol' => strtoupper($agent->symbol),
                'timeframe' => strtoupper($agent->timeframe),
                'strategy_family' => $agent->strategy_family,
                'event_type' => $type,
                'context_key' => $context['context_key'],
                'amount' => $amount,
                'status' => $status,
                'payload' => [
                    'protocol' => self::PROTOCOL,
                    'context' => $context,
                    'evidence_run_id' => data_get($evidence, 'evidence_run_id'),
                    'failure_signature' => data_get($evidence, 'failure_signature'),
                    'promotion_evidence' => false,
                ],
                'recorded_at' => now()->utc(),
            ],
        );
        return [
            'id' => (int) $event->id,
            'event_type' => $type,
            'amount' => (float) $event->amount,
            'status' => $event->status,
            'promotion_evidence' => false,
        ];
    }

    private function contextFromAgent(LabAgent $agent, array $broker = []): array
    {
        $niche = (array) data_get($agent->modelVersion?->metadata, 'portfolio_council_lane', []);
        return $this->trust->context([
            ...$niche,
            ...((array) data_get($broker, 'context', [])),
            'regime' => data_get($niche, 'regime', data_get($broker, 'context.regime')),
            'volume_state' => data_get($niche, 'volume_state', data_get($broker, 'context.volume_state')),
            'cost_stress' => data_get($niche, 'cost_stress', data_get($broker, 'context.cost_stress', 'normal')),
        ]);
    }

    private function discoveryLane(LabAgent $agent): bool
    {
        $metadata = (array) ($agent->modelVersion?->metadata ?? []);
        return (bool) data_get($metadata, 'risk_bounded_evolution.adversarial_red_team', false)
            || (bool) data_get($metadata, 'risk_bounded_evolution.volume_shadow', false)
            || in_array((string) data_get($metadata, 'risk_bounded_evolution.mode'), ['bold_explorer', 'regime_volume_explorer', 'adversarial_red_team'], true);
    }

    private function score(array $metrics): ?float
    {
        foreach (['forward_score', 'profit_factor', 'net_profit_percent'] as $key) {
            if (is_numeric($metrics[$key] ?? null)) return (float) $metrics[$key];
        }
        return null;
    }

    private function technical(array $result): bool
    {
        return in_array((string) data_get($result, 'quality_verdict', ''), ['withheld', 'technical_error', 'technical_quarantine'], true)
            || in_array((string) data_get($result, 'status', ''), ['technical_error', 'technical_quarantine'], true)
            || (bool) data_get($result, 'technical_error', false);
    }

    private function available(): bool
    {
        try {
            return (bool) config('services.lab_selection.evolution_credit_enabled', true)
                && Schema::hasTable('lab_evolution_credit_events')
                && Schema::hasTable('lab_parent_counterfactuals');
        } catch (\Throwable) {
            return false;
        }
    }
}
