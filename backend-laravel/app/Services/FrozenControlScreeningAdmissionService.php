<?php

namespace App\Services;

use App\Models\AgentLearningCausalExperiment;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabMutationResponseMap;

/**
 * Holds mutation candidates behind their same-generation frozen control.
 * A control's strategy result may fail; only its immutable completed replay
 * is required before a candidate can be interpreted causally.
 */
class FrozenControlScreeningAdmissionService
{
    public const PROTOCOL = 'frozen_control_first_screening_v1';

    /** @param array<int, int> $agentIds */
    public function batchAdmission(array $agentIds): array
    {
        $agents = LabAgent::query()->with('modelVersion')->whereIn('id', $agentIds)->get();
        $waiting = [];
        $blocked = [];
        foreach ($agents as $agent) {
            $result = $this->admission($agent);
            if ($result['status'] === 'waiting') $waiting[] = $result;
            if ($result['status'] === 'blocked') $blocked[] = $result;
        }

        return [
            'protocol' => self::PROTOCOL,
            'allowed' => $waiting === [] && $blocked === [],
            'status' => $blocked !== [] ? 'blocked' : ($waiting !== [] ? 'waiting' : 'ready'),
            'waiting' => $waiting,
            'blocked' => $blocked,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function admission(LabAgent $agent): array
    {
        $agent->loadMissing('modelVersion');
        if ($this->isControl($agent)) {
            return ['agent_id' => $agent->id, 'status' => 'ready', 'reason' => 'CONTROL_SELF'];
        }

        $controlId = $this->declaredControlAgentId($agent);
        $controls = LabAgent::query()->with('modelVersion')
            ->where('lab_generation_id', $agent->lab_generation_id)
            ->where('strategy_family', $agent->strategy_family)
            ->when($controlId > 0, fn ($query) => $query->whereKey($controlId))
            ->get()
            ->filter(fn (LabAgent $row): bool => $this->isControl($row));
        if ($controls->isEmpty()) {
            return [
                'agent_id' => $agent->id,
                'status' => 'blocked',
                'reason' => $controlId > 0 ? 'FROZEN_CONTROL_IDENTITY_MISMATCH' : 'FROZEN_CONTROL_MISSING',
                'control_agent_id' => $controlId > 0 ? $controlId : null,
            ];
        }
        if ($controlId <= 0 && $controls->count() > 1) {
            return [
                'agent_id' => $agent->id,
                'status' => 'blocked',
                'reason' => 'FROZEN_CONTROL_AMBIGUOUS',
                'control_agent_ids' => $controls->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            ];
        }

        foreach ($controls as $control) {
            $run = LabEvaluationRun::query()
                ->where('lab_agent_id', $control->id)
                ->where('phase', 'screening')
                ->latest('id')
                ->first();
            if ($run === null || (string) $run->status === 'started') {
                return ['agent_id' => $agent->id, 'status' => 'waiting', 'reason' => 'FROZEN_CONTROL_REPLAY_PENDING', 'control_agent_id' => $control->id];
            }
            if ((string) $run->status !== 'completed') {
                return ['agent_id' => $agent->id, 'status' => 'blocked', 'reason' => 'FROZEN_CONTROL_REPLAY_INCOMPLETE', 'control_agent_id' => $control->id];
            }
            $map = LabMutationResponseMap::query()
                ->where('lab_agent_id', $control->id)
                ->where('stage', 'screening')->where('status', 'control')->latest('id')->first();
            // The immutable screening run and its learning projection are
            // written by different queues. A completed run without a map is
            // therefore unresolved evidence, not invalid evidence. Keep the
            // candidate waiting until lab-learning projects the control; only
            // a present map that breaches the frozen contract is terminal.
            if ($map === null) {
                return ['agent_id' => $agent->id, 'status' => 'waiting', 'reason' => 'FROZEN_CONTROL_LEARNING_PROJECTION_PENDING', 'control_agent_id' => $control->id];
            }
            if (! $this->controlMapMatchesContract($map, $agent->lab_generation_id)) {
                return ['agent_id' => $agent->id, 'status' => 'blocked', 'reason' => 'FROZEN_CONTROL_EVIDENCE_INVALID', 'control_agent_id' => $control->id];
            }
        }

        return ['agent_id' => $agent->id, 'status' => 'ready', 'reason' => 'FROZEN_CONTROL_REPLAY_COMPLETED'];
    }

    /** Resolve the pre-registered pair owner before considering family peers. */
    private function declaredControlAgentId(LabAgent $agent): int
    {
        foreach ([
            'control_pair_contract.control_agent_id',
            'learning_receipt.control_agent_id',
        ] as $path) {
            $id = (int) data_get($agent->modelVersion?->metadata, $path, 0);
            if ($id > 0) {
                return $id;
            }
        }

        $experiment = AgentLearningCausalExperiment::query()
            ->where('lab_generation_id', $agent->lab_generation_id)
            ->where(fn ($query) => $query
                ->where('guided_agent_id', $agent->id)
                ->orWhere('blinded_agent_id', $agent->id))
            ->latest('id')
            ->first(['control_agent_id']);

        return (int) ($experiment?->control_agent_id ?? 0);
    }

    /** Canonical control identity shared by admission and queue scheduling. */
    public function isControl(LabAgent $agent): bool
    {
        return data_get($agent->modelVersion?->metadata, 'control_contract.protocol') === 'frozen_control_v2'
            && data_get($agent->modelVersion?->metadata, 'control_contract.control_only') === true
            && data_get($agent->modelVersion?->metadata, 'control_contract.role') === 'control'
            && (int) data_get($agent->modelVersion?->metadata, 'control_contract.generation_id') === (int) $agent->lab_generation_id;
    }

    private function controlMapMatchesContract(LabMutationResponseMap $map, int $generationId): bool
    {
        $contract = (array) data_get($map->metadata, 'control_contract', []);
        return data_get($contract, 'protocol') === 'frozen_control_v2'
            && data_get($contract, 'control_only') === true
            && data_get($contract, 'role') === 'control'
            && (int) data_get($contract, 'generation_id') === $generationId
            && filled(data_get($contract, 'data_hash'))
            && filled(data_get($contract, 'execution_hash'));
    }
}
