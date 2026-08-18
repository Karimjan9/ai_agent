<?php

namespace App\Services;

use App\Models\LabAgent;

/** Screen-level skill memory; explicitly forbidden from parent/paper lanes. */
class ProvisionalSkillCartridgeService
{
    public const PROTOCOL = 'provisional_skill_cartridge_v1';

    /** @return array<string, mixed>|null */
    public function record(LabAgent $agent, array $result, array $observability, array $controlRelative = []): ?array
    {
        $agent->loadMissing('modelVersion');
        $model = $agent->modelVersion;
        if (! $model || data_get($observability, 'classification') !== 'observable_effect') return null;
        if (count((array) $agent->parameter_diff) !== 1) return null;
        if (! (bool) data_get($observability, 'gate_margin.target_gate_improved', false)) return null;
        if (data_get($controlRelative, 'non_target_regression.safe', true) !== true) return null;

        $gene = (string) array_key_first((array) $agent->parameter_diff);
        $lowerBound = data_get(
            $result,
            'statistical_evidence.edge_quality.bootstrap_pf.pf_5_percentile_lower_bound',
            data_get($result, 'statistical_evidence.edge_quality.lower_confidence_bound'),
        );
        $independentWindows = max(
            (int) data_get($result, 'forward_protocol.independent_windows', 0),
            (int) data_get($result, 'forward_validation.independent_windows', 0),
            (int) data_get($result, 'independent_forward_windows', 0),
            (int) data_get($result, 'market_adaptive_replay.independent_windows', 0),
        );
        $controlDelta = (float) data_get($controlRelative, 'control_delta', 0.0);
        $nonTargetSafe = data_get($controlRelative, 'non_target_regression.safe', true) === true;
        $independentConfirmation = $independentWindows >= 2
            && is_numeric($lowerBound)
            && (float) $lowerBound > 0
            && $controlDelta > 0
            && $nonTargetSafe;
        $context = app(ContextualMutationBanditService::class)->context($agent, $result, (string) data_get($observability, 'target', 'profit_factor'), $gene);
        $metadata = (array) $model->metadata;
        $history = array_values((array) data_get($metadata, 'provisional_skill_cartridge.history', []));
        $fingerprint = hash('sha256', json_encode([
            'gene' => $gene, 'target' => data_get($observability, 'target'), 'context' => $context['context_key'],
            'direction' => data_get($agent->parameter_diff, $gene.'.new'),
        ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        if (! collect($history)->contains(fn (array $row): bool => (string) data_get($row, 'fingerprint') === $fingerprint)) {
            $history[] = [
                'fingerprint' => $fingerprint,
                'agent_id' => (int) $agent->id,
                'generation_id' => (int) $agent->lab_generation_id,
                'parameter_key' => $gene,
                'target' => data_get($observability, 'target', data_get($metadata, 'generation_target')),
                'context' => $context,
                'anchor_delta' => data_get($observability, 'gate_margin.normalized_delta'),
                'control_delta' => $controlDelta,
                'independent_windows' => $independentWindows,
                'bootstrap_lower_bound' => is_numeric($lowerBound) ? (float) $lowerBound : null,
                'independent_confirmation' => $independentConfirmation,
                'parent_eligible' => false,
                'promotion_evidence' => false,
            ];
        }
        $history = array_slice($history, -16);
        $cartridge = [
            'protocol' => self::PROTOCOL,
            'status' => $independentConfirmation ? 'independently_confirmed_skill_candidate' : 'screen_provisional',
            'parameter_key' => $gene,
            'target' => data_get($observability, 'target', data_get($metadata, 'generation_target')),
            'context_key' => $context['context_key'],
            'observations' => count($history),
            'source_agent_ids' => collect($history)->pluck('agent_id')->unique()->values()->all(),
            'independent_confirmation' => $independentConfirmation,
            'independent_window_count' => $independentWindows,
            'bootstrap_lower_bound' => is_numeric($lowerBound) ? (float) $lowerBound : null,
            'control_relative_delta' => $controlDelta,
            'uncertainty_status' => is_numeric($lowerBound) ? ((float) $lowerBound > 0 ? 'positive' : 'non_positive') : 'unassessed',
            'parent_eligible' => false,
            'shadow_only' => true,
            'promotion_evidence' => false,
            'updated_at' => now()->utc()->toIso8601String(),
        ];
        data_set($metadata, 'provisional_skill_cartridge', [...$cartridge, 'history' => $history]);
        $model->update(['metadata' => $metadata]);
        return $cartridge;
    }

    public function isUsableForShadow(?array $cartridge, string $role = ''): bool
    {
        return is_array($cartridge)
            && in_array(data_get($cartridge, 'status'), ['screen_provisional', 'independently_confirmed_skill_candidate'], true)
            && data_get($cartridge, 'shadow_only') === true
            && data_get($cartridge, 'parent_eligible') !== true
            && ($role === '' || in_array($role, ['proven_gene_refinement', 'skill_mentor_refinement', 'shadow_specialist'], true));
    }
}
