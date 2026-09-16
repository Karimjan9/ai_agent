<?php

namespace App\Services;

use App\Models\AgentLearningEpisode;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use Illuminate\Support\Facades\Schema;

/**
 * Closes a deliberate WAIT decision without manufacturing learning credit.
 *
 * An uncertainty guard is a terminal research outcome, but it has no replay,
 * economic or causal evidence.  Its canonical episode therefore needs an
 * explicit zero-credit settlement instead of remaining open forever.
 */
class UncertaintyAbstentionSettlementService
{
    public const PROTOCOL = 'uncertainty_abstention_settlement_v1';

    public function __construct(private LearningKernelService $learning) {}

    /** @return array<string,mixed> */
    public function settle(LabAgent $agent, ?AgentLearningEpisode $episode = null): array
    {
        if (! Schema::hasTable('agent_learning_episodes')
            || ! Schema::hasTable('agent_learning_settlements')) {
            return ['status' => 'unavailable', 'promotion_evidence' => false];
        }

        $agent->loadMissing('modelVersion');
        $model = $agent->modelVersion;
        $contract = (array) data_get($model?->metadata, 'uncertainty_abstain_contract', []);
        if ((string) data_get($contract, 'protocol')
                !== CooperativeContextualEvolutionCouncilService::UNCERTAINTY_ABSTAIN_PROTOCOL
            || (string) data_get($contract, 'terminal_status') !== 'correctly_abstained'
            || (string) $agent->lifecycle_status !== 'screened') {
            return ['status' => 'not_applicable', 'promotion_evidence' => false];
        }

        $runId = (string) data_get($contract, 'terminal_evidence_run_id', '');
        $run = $runId !== ''
            ? LabEvaluationRun::query()
                ->where('run_id', $runId)
                ->where('lab_agent_id', $agent->id)
                ->where('status', 'completed')
                ->first()
            : null;
        if (! $run || data_get($run->metadata, 'correctly_abstained') !== true) {
            return ['status' => 'awaiting_terminal_evidence', 'promotion_evidence' => false];
        }

        $episodeId = (int) data_get($model?->metadata, 'learning_decision.episode_id', 0);
        $episode ??= $episodeId > 0
            ? AgentLearningEpisode::query()
                ->whereKey($episodeId)
                ->where('lab_agent_id', $agent->id)
                ->first()
            : null;
        if (! $episode || (int) $episode->lab_agent_id !== (int) $agent->id) {
            return ['status' => 'no_episode', 'promotion_evidence' => false];
        }

        $existing = $episode->settlement()->first();
        if ($existing) {
            return [
                'status' => 'already_settled',
                'settlement_id' => $existing->settlement_id,
                'selection_reward' => (float) $existing->selection_reward,
                'promotion_evidence' => false,
            ];
        }

        $result = $this->learning->settleOutcome($episode, [
            'protocol' => self::PROTOCOL,
            'source_key' => 'uncertainty-abstain:'.$agent->id.':'.$run->run_id,
            'source_type' => LabAgent::class,
            'source_id' => $agent->id,
            'outcome_status' => 'abstained',
            'failure_class' => 'deliberate_abstain',
            'evidence_state' => 'neutral',
            // No normalized quality metric is projected. This deliberately
            // remains an uninformative zero reward rather than converting a
            // correct safety WAIT into performance or causal evidence.
            'metrics' => [
                'total_trades' => 0,
                'replay_performed' => false,
                'deliberate_abstain' => true,
            ],
            'reward_stage' => 'terminal_policy_guard',
            'causal_credit_allowed' => false,
            'economic_credit_allowed' => false,
            'promotion_evidence' => false,
        ]);
        $settlement = $result['settlement'] ?? null;

        $metadata = (array) $model->metadata;
        $metadata['learning_decision'] = [
            ...((array) data_get($metadata, 'learning_decision', [])),
            'outcome_status' => 'deliberate_abstain_settled',
            'settled_evidence_run_id' => $run->run_id,
            'settlement_protocol' => self::PROTOCOL,
            'promotion_evidence' => false,
        ];
        $model->update(['metadata' => $metadata]);

        return [
            'status' => 'settled_zero_credit',
            'episode_id' => $episode->id,
            'settlement_id' => $settlement?->settlement_id,
            'selection_reward' => (float) ($settlement?->selection_reward ?? 0.0),
            'promotion_evidence' => false,
        ];
    }
}
