<?php

namespace App\Services;

use App\Models\AgentLearningCausalExperiment;
use App\Models\AgentLearningSettlement;
use App\Models\CausalCapabilityEscrow;
use App\Models\LabAgent;
use App\Models\LabEvolutionCreditEvent;
use App\Models\LabLearningLanePair;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The sole post-v2 bridge from a settled three-arm causal claim to the
 * parent-aware credit ledger. A lesson or mentor projection is not credit.
 */
class CausalSkillCreditBridgeService
{
    public const PROTOCOL = 'confirmed_causal_skill_credit_bridge_v1';

    /** @return array<string, mixed> */
    public function settle(AgentLearningCausalExperiment $experiment): array
    {
        if (! Schema::hasTable('lab_evolution_credit_events') || ! Schema::hasTable('causal_capability_escrows')) {
            return $this->withheld('CREDIT_LEDGER_UNAVAILABLE');
        }

        $experiment = $experiment->fresh();
        if (data_get($experiment?->evidence, 'experiment_kind') === ProspectiveRepairExperimentService::KIND) {
            return $this->withheld('SCREENING_DISCOVERY_IS_NOT_INDEPENDENT_VALIDATION');
        }
        if (! $experiment || $experiment->status !== 'confirmed'
            || ! $experiment->confirmed_at
            || ! $experiment->guided_beats_control
            || ! $experiment->guided_beats_blinded
            || data_get($experiment->evidence, 'component_effect.passed') !== true
            || data_get($experiment->evidence, 'selector_effect.passed') !== true
            || (array) data_get($experiment->evidence, 'confirmation_blockers', []) !== []) {
            return $this->withheld('CAUSAL_EXPERIMENT_NOT_CONFIRMED');
        }

        $generation = $experiment->generation;
        if (app(LearningProtocolEpochService::class)->epochFor($generation) !== LearningProtocolEpochService::CURRENT_EPOCH) {
            return $this->withheld('POST_V2_EPOCH_REQUIRED');
        }

        $escrow = CausalCapabilityEscrow::query()
            ->where('agent_learning_causal_experiment_id', $experiment->id)
            ->where('protocol_epoch', LearningProtocolEpochService::CURRENT_EPOCH)
            ->first();
        $facts = (array) data_get($escrow?->evidence, 'facts', []);
        $evaluation = app(CausalCapabilityLatticeService::class)->evaluate($facts);
        if (! $escrow || ! $escrow->component_confirmed || ! $escrow->composition_eligible
            || data_get($escrow->evidence, 'evaluation.component_confirmed') !== true
            || data_get($evaluation, 'composition_eligible') !== true
            || (string) $escrow->context_hash === ''
            || strlen((string) $escrow->context_hash) !== 64) {
            return $this->withheld('COMPONENT_ESCROW_NOT_CONFIRMED');
        }

        $agent = LabAgent::query()->with('modelVersion')->find($experiment->guided_agent_id);
        $pair = LabLearningLanePair::query()->find($escrow->lab_learning_lane_pair_id);
        $settlement = AgentLearningSettlement::query()->find($escrow->agent_learning_settlement_id);
        $contextPredicate = (array) data_get($escrow->evidence, 'context_predicate', []);
        $contextHash = hash('sha256', json_encode($contextPredicate, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        if (! $agent || ! $agent->modelVersion || ! $pair || ! $settlement
            || (int) $agent->lab_generation_id !== (int) $experiment->lab_generation_id
            || (int) $pair->lab_generation_id !== (int) $experiment->lab_generation_id
            || (int) $pair->candidate_agent_id !== (int) $agent->id
            || (int) $pair->control_agent_id !== (int) $experiment->control_agent_id
            || ! $pair->isVerifiedControlPair()
            || $settlement->source_type !== LabLearningLanePair::class
            || (int) $settlement->source_id !== (int) $pair->id
            || $settlement->outcome_status !== 'settled'
            || strtoupper((string) $agent->symbol) !== strtoupper((string) $experiment->symbol)
            || strtoupper((string) $agent->timeframe) !== strtoupper((string) $experiment->timeframe)
            || (string) $agent->strategy_family !== (string) $experiment->strategy_family
            || (string) $pair->target !== (string) $experiment->target
            || (string) $escrow->gene_key !== (string) $experiment->gene_key
            || (string) $escrow->target !== (string) $experiment->target
            || (string) $escrow->symbol !== strtoupper((string) $experiment->symbol)
            || (string) $escrow->timeframe !== strtoupper((string) $experiment->timeframe)
            || (string) $escrow->strategy_family !== (string) $experiment->strategy_family
            || $contextPredicate === []
            || ! hash_equals((string) $escrow->context_hash, $contextHash)
            || ! hash_equals(
                (string) data_get($escrow->evidence, 'gene_or_program_hash', ''),
                hash('sha256', (string) $experiment->gene_key),
            )
            || ! hash_equals((string) data_get($escrow->evidence, 'data_hash', ''), (string) $pair->candidate_data_hash)
            || ! hash_equals((string) data_get($escrow->evidence, 'execution_hash', ''), (string) $pair->candidate_execution_hash)
            || (string) data_get($experiment->evidence, 'outcomes.'.$this->guidedRole($experiment).'.pair_id') !== (string) $pair->id) {
            return $this->withheld('CAUSAL_EVIDENCE_IDENTITY_MISMATCH');
        }

        $common = [
            'lab_agent_id' => $agent->id,
            'model_version_id' => $agent->model_version_id,
            'parent_model_version_id' => null,
            'symbol' => strtoupper((string) $agent->symbol),
            'timeframe' => strtoupper((string) $agent->timeframe),
            'strategy_family' => $agent->strategy_family,
            'context_key' => $escrow->context_hash,
            'amount' => 1.0,
            'payload' => [
                'protocol' => self::PROTOCOL,
                'causal_experiment_id' => (int) $experiment->id,
                'capability_escrow_id' => (int) $escrow->id,
                'pair_id' => (int) $pair->id,
                'settlement_id' => (int) $settlement->id,
                'protocol_epoch' => $escrow->protocol_epoch,
                'data_hash' => $pair->candidate_data_hash,
                'execution_hash' => $pair->candidate_execution_hash,
                'context_hash' => $escrow->context_hash,
                'independent_windows' => (int) $experiment->independent_window_count,
                'promotion_evidence' => false,
                'paper_or_live_authority' => false,
            ],
            'recorded_at' => now()->utc(),
        ];
        // A confirmed causal skill necessarily includes a verified bounded
        // repair. Record both rungs together or neither; neither grants paper
        // performance, inheritance or parent authority.
        [$repair, $skill] = DB::transaction(function () use ($experiment, $agent, $escrow, $common): array {
            $record = function (string $type, string $status) use ($experiment, $agent, $escrow, $common): LabEvolutionCreditEvent {
                $fingerprint = hash('sha256', implode('|', [
                    self::PROTOCOL, $type, $experiment->id, $agent->id, $escrow->id,
                ]));

                return LabEvolutionCreditEvent::query()->firstOrCreate(
                    ['evidence_fingerprint' => $fingerprint],
                    [...$common, 'event_type' => $type, 'status' => $status],
                );
            };

            return [
                $record('repair_credit', 'causal_repair_verified'),
                $record('causal_skill_credit', 'causal_skill_confirmed'),
            ];
        });

        return [
            'protocol' => self::PROTOCOL,
            'status' => 'credited',
            'repair_event_id' => (int) $repair->id,
            'event_id' => (int) $skill->id,
            'newly_recorded' => $skill->wasRecentlyCreated,
            'promotion_evidence' => false,
        ];
    }

    private function guidedRole(AgentLearningCausalExperiment $experiment): string
    {
        return match ((string) data_get($experiment->evidence, 'experiment_kind')) {
            'causal_repair', 'causal_architecture_escape', 'causal_architecture_interaction' => 'repair_guided',
            'legacy_hypothesis_reproduction' => 'hypothesis_guided',
            default => 'memory_guided',
        };
    }

    /** @return array<string, mixed> */
    private function withheld(string $reason): array
    {
        return [
            'protocol' => self::PROTOCOL,
            'status' => 'withheld',
            'reason_code' => $reason,
            'promotion_evidence' => false,
        ];
    }
}
