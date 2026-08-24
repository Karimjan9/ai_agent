<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LabMutationAction;
use Illuminate\Support\Facades\Schema;

/** Immutable mutation action/reward ledger around the existing contextual bandit. */
class MutationBrainService
{
    public const PROTOCOL = 'learned_mutation_brain_v1';

    /** @return array<string, mixed>|null */
    public function issue(LabAgent $agent): ?array
    {
        if (! $this->available() || ! $agent->modelVersion) return null;
        $diff = (array) $agent->parameter_diff; if (count($diff) !== 1) return null;
        $gene = (string) array_key_first($diff); $change = (array) ($diff[$gene] ?? []);
        $old = data_get($change, 'old'); $new = data_get($change, 'new');
        $direction = is_numeric($old) && is_numeric($new) ? ((float) $new >= (float) $old ? 'increase' : 'decrease') : 'toggle';
        $magnitude = is_numeric($old) && is_numeric($new) ? abs((float) $new - (float) $old) : null;
        $metadata = (array) $agent->modelVersion->metadata;
        $context = ['target' => data_get($metadata, 'generation_target', 'unknown'), ...((array) data_get($metadata, 'mutation_scope', [])), 'origin' => $agent->origin];
        $key = hash('sha256', json_encode([self::PROTOCOL, $agent->id, $gene, $direction, $magnitude, $context], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        $row = LabMutationAction::query()->firstOrCreate(['action_key' => $key], [
            'lab_agent_id' => $agent->id, 'model_version_id' => $agent->model_version_id, 'symbol' => strtoupper($agent->symbol), 'timeframe' => strtoupper($agent->timeframe),
            'strategy_family' => $agent->strategy_family, 'gene_key' => $gene, 'direction' => $direction, 'magnitude' => $magnitude, 'context' => $context,
            'parent_state' => ['parent_model_version_ids' => app(ParentContributionGraphService::class)->ids($agent), 'semantic_group' => data_get($metadata, 'semantic_group.key')],
            'reward' => ['status' => 'pending_evidence', 'promotion_evidence' => false], 'status' => 'issued',
        ]);
        return ['protocol' => self::PROTOCOL, 'action_id' => $row->id, 'action_key' => $row->action_key, 'promotion_evidence' => false];
    }

    /** @return array<string, mixed> */
    public function settle(LabAgent $agent, array $result, array $descendantCredit = []): array
    {
        if (! $this->available()) return ['protocol' => self::PROTOCOL, 'status' => 'migration_pending', 'promotion_evidence' => false];
        $action = LabMutationAction::query()->where('lab_agent_id', $agent->id)->latest('id')->first();
        if (! $action || ! filled(data_get($result, 'evidence_run_id'))) return ['protocol' => self::PROTOCOL, 'status' => 'no_action_or_evidence', 'promotion_evidence' => false];
        $observable = (bool) data_get($result, 'mutation_observability.observable_effect', false);
        $delta = data_get($result, 'mutation_observability.control_delta', data_get($result, 'verified_mutation_skill.target_gate.normalized_delta', 0));
        $delta = is_numeric($delta) ? (float) $delta : 0.0;
        $regression = (bool) data_get($result, 'mutation_observability.non_target_regression.failed', false) ? 1.0 : 0.0;
        $duplicate = (bool) data_get($result, 'mutation_response_map.duplicate_of_response_map_id', false) ? 1.0 : 0.0;
        $compute = max(0.0, (float) data_get($result, 'runtime_seconds', 0) / 3600);
        $novelty = (float) data_get($result, 'behavioral_map_elites.novelty_score', 0);
        $descendant = collect((array) data_get($descendantCredit, 'events', []))->sum(fn (array $event): float => (float) data_get($event, 'amount', 0));
        $score = $observable ? ($delta - $regression - (.15 * $duplicate) - (.02 * $compute) + (.10 * $novelty) + (.20 * $descendant)) : 0.0;
        $reward = ['protocol' => self::PROTOCOL, 'causal_improvement' => $delta, 'regression_penalty' => $regression, 'duplicate_penalty' => $duplicate,
            'compute_cost' => $compute, 'novelty_value' => $novelty, 'descendant_value' => $descendant, 'score' => round($score, 6),
            'observable' => $observable, 'promotion_evidence' => false];
        $action->update(['reward' => $reward, 'status' => $observable ? 'settled' : 'inconclusive', 'evidence_run_id' => data_get($result, 'evidence_run_id')]);
        return ['protocol' => self::PROTOCOL, 'status' => $action->status, 'reward' => $reward, 'promotion_evidence' => false];
    }

    private function available(): bool { try { return Schema::hasTable('lab_mutation_actions'); } catch (\Throwable) { return false; } }
}
