<?php

namespace App\Services;

use App\Models\ContextualInstrumentBundleEffect;
use App\Models\CooperativeExperimentSettlement;
use App\Models\InstrumentInvocationLedger;
use App\Models\LabAgent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/** Persists local main, interaction and removal effects without granting authority. */
class ContextualInstrumentBundleGraphService
{
    public const PROTOCOL = 'contextual_instrument_bundle_graph_v1';

    /**
     * @param  Collection<int,LabAgent>  $agents
     * @param  array<string,mixed>  $effects
     * @return array<string,mixed>
     */
    public function record(CooperativeExperimentSettlement $settlement, Collection $agents, array $effects): array
    {
        if (in_array((string) $settlement->block_type, ['activation_factorial', 'phase_scope_probe'], true)) {
            return ['protocol' => self::PROTOCOL, 'status' => 'activation_discovery_no_economic_attribution',
                'recorded' => 0, 'promotion_evidence' => false];
        }
        if (! $settlement->evidence_complete || ! Schema::hasTable('contextual_instrument_bundle_effects')) {
            return ['protocol' => self::PROTOCOL, 'status' => 'incomplete_or_migration_pending', 'recorded' => 0,
                'promotion_evidence' => false];
        }
        $agents = $agents->values();
        /** @var LabAgent|null $first */
        $first = $agents->first();
        if (! $first) {
            return ['protocol' => self::PROTOCOL, 'status' => 'no_agents', 'recorded' => 0, 'promotion_evidence' => false];
        }
        $generation = $first->generation;
        $epoch = app(LearningProtocolEpochService::class)->epochFor($generation) ?: 'legacy_unscoped';
        $byArm = $agents->keyBy(fn (LabAgent $agent): string => (string) data_get(
            $agent->modelVersion?->metadata,
            'cooperative_experiment_block.arm',
            '',
        ));
        // Planned assignment is only a hypothesis. Attribution begins only
        // after Python attests a real decision-path activation and the runtime
        // invocation ledger has persisted that receipt.
        $bundles = $byArm->map(fn (LabAgent $agent): array => $this->activatedCausalBundle($agent));
        $block = (array) data_get($first->modelVersion?->metadata, 'cooperative_experiment_block', []);
        $context = (string) ($settlement->context_cell_key ?: 'unknown_context_quarantine');
        $rows = [];

        if ((string) $settlement->block_type === 'factorial') {
            $contrast = $this->controlledEffects((array) $settlement->arm_results);
            if ($contrast['status'] !== 'controlled_contrast') {
                return ['protocol' => self::PROTOCOL, 'status' => $contrast['status'],
                    'recorded' => 0, 'promotion_evidence' => false];
            }
            // Recompute from four sealed arm observations, never trust a
            // caller-supplied marginal/interaction summary as proof.
            $effects = $contrast['effects'];
            $control = (array) $bundles->get('control', []);
            $aBundle = (array) $bundles->get('a_only', []);
            $bBundle = (array) $bundles->get('b_only', []);
            $abBundle = (array) $bundles->get('a_plus_b', []);
            if ($aBundle === [] || $bBundle === [] || $abBundle === []) {
                return ['protocol' => self::PROTOCOL, 'status' => 'no_runtime_activation_no_credit',
                    'recorded' => 0, 'promotion_evidence' => false];
            }
            $instrumentA = $this->introduced($aBundle, $control)
                ?: $this->componentName(data_get($block, 'component_a'), 'component_a');
            $instrumentB = $this->introduced($bBundle, $control)
                ?: $this->componentName(data_get($block, 'component_b'), 'component_b');
            if ($instrumentA === $instrumentB
                || ! in_array($instrumentA, $abBundle, true)
                || ! in_array($instrumentB, $abBundle, true)) {
                return ['protocol' => self::PROTOCOL, 'status' => 'factorial_activation_identity_incomplete',
                    'recorded' => 0, 'promotion_evidence' => false];
            }
            $a = $this->number(data_get($effects, 'component_a_marginal_effect'));
            $b = $this->number(data_get($effects, 'component_b_marginal_effect'));
            $interaction = $this->number(data_get($effects, 'interaction_effect'));
            $whole = $this->number(data_get($effects, 'whole_capsule_effect'));
            $armResults = (array) $settlement->arm_results;
            $abValue = $this->number(data_get($armResults, 'a_plus_b.after_cost_value'));
            $aValue = $this->number(data_get($armResults, 'a_only.after_cost_value'));
            $bValue = $this->number(data_get($armResults, 'b_only.after_cost_value'));
            $rows = [
                $this->row('standalone_marginal', $instrumentA, null, $aBundle, $a, null, null),
                $this->row('standalone_marginal', $instrumentB, null, $bBundle, $b, null, null),
                $this->row('bundle_marginal', $instrumentA, $instrumentB, $abBundle, $whole, $interaction, null),
                $this->row('interaction', $instrumentA, $instrumentB, $abBundle, null, $interaction, null),
                $this->row('leave_one_out', $instrumentA, $instrumentB, $abBundle, null, $interaction,
                    $abValue !== null && $bValue !== null ? round($abValue - $bValue, 8) : null),
                $this->row('leave_one_out', $instrumentB, $instrumentA, $abBundle, null, $interaction,
                    $abValue !== null && $aValue !== null ? round($abValue - $aValue, 8) : null),
            ];
            $bundleOnly = $a !== null && $a <= 0 && $b !== null && $b <= 0 && $whole !== null && $whole > 0;
            foreach ($rows as &$row) {
                $row['evidence']['bundle_only_candidate'] = $bundleOnly;
                $row['evidence']['individual_credit_suppressed'] = $bundleOnly && $row['effect_type'] === 'standalone_marginal';
                $row['evidence']['interaction_interpretation'] = $interaction > 0 ? 'reinforcing'
                    : ($interaction < 0 ? 'interfering' : 'additive');
                $row['evidence']['removal_interpretation'] = $row['leave_one_out_effect'] === null ? null
                    : ($row['leave_one_out_effect'] > 0 ? 'incremental_contribution' : 'redundant_or_harmful_in_bundle');
                $row['evidence']['controlled_arm_receipt_digest'] = $contrast['receipt_digest'];
                $row['evidence']['independent_confirmation_required'] = true;
            }
            unset($row);
        } else {
            $candidateArm = $byArm->has('candidate') ? 'candidate' : ($byArm->has('guard_challenge') ? 'guard_challenge' : null);
            if ($candidateArm !== null) {
                $bundle = (array) $bundles->get($candidateArm, []);
                if ($bundle === []) {
                    return ['protocol' => self::PROTOCOL, 'status' => 'no_runtime_activation_no_credit',
                        'recorded' => 0, 'promotion_evidence' => false];
                }
                $rows[] = $this->row('bundle_marginal', $bundle[0] ?? 'bundle_unresolved', null, $bundle,
                    $this->number(data_get($effects, 'candidate_delta', data_get($effects, 'whole_capsule_effect'))), null, null);
            }
        }

        $persisted = [];
        foreach ($rows as $index => $effect) {
            $value = $effect['marginal_effect'] ?? $effect['interaction_effect'] ?? $effect['leave_one_out_effect'];
            $contraindicated = is_numeric($value) && (float) $value < 0;
            $key = hash('sha256', implode('|', [self::PROTOCOL, (string) $settlement->id, (string) $index,
                $effect['effect_type'], (string) $effect['instrument_a'], (string) $effect['instrument_b']]));
            $persisted[] = ContextualInstrumentBundleEffect::query()->updateOrCreate(
                ['effect_key' => $key],
                [
                    'cooperative_experiment_settlement_id' => $settlement->id,
                    'lab_generation_id' => $settlement->lab_generation_id,
                    'protocol_epoch' => $epoch,
                    'symbol' => strtoupper($first->symbol),
                    'timeframe' => strtoupper($first->timeframe),
                    'context_cell_key' => $context,
                    'instrument_a' => $effect['instrument_a'],
                    'instrument_b' => $effect['instrument_b'],
                    'bundle_hash' => hash('sha256', json_encode($effect['bundle'], JSON_UNESCAPED_SLASHES)),
                    'effect_type' => $effect['effect_type'],
                    'marginal_effect' => $effect['marginal_effect'],
                    'interaction_effect' => $effect['interaction_effect'],
                    'leave_one_out_effect' => $effect['leave_one_out_effect'],
                    'observations' => 1,
                    'authority_level' => 'research_only',
                    'contraindicated' => $contraindicated,
                    'evidence' => [...$effect['evidence'],
                        'settlement_id' => $settlement->id,
                        'local_context_only' => true,
                        'global_inheritance_allowed' => false,
                        'invocation_or_assignment_alone_grants_credit' => false,
                        'promotion_evidence' => false],
                    'observed_at' => now(),
                ],
            )->id;
        }

        return ['protocol' => self::PROTOCOL, 'status' => 'research_effects_recorded',
            'recorded' => count($persisted), 'effect_ids' => $persisted,
            'authority_level' => 'research_only', 'promotion_evidence' => false];
    }

    /** Main, interaction and removal effects require the same sealed experiment universe. */
    public function controlledEffects(array $arms): array
    {
        $required = ['control', 'a_only', 'b_only', 'a_plus_b'];
        $rows = collect($required)->map(fn (string $arm): array => (array) ($arms[$arm] ?? []));
        $valid = $rows->every(fn (array $row): bool => ($row['evidence_status'] ?? '') === 'eligible'
            && filled($row['evidence_run_id'] ?? null)
            && is_numeric($row['after_cost_value'] ?? null) && is_finite((float) $row['after_cost_value']));
        foreach (['data_hash', 'execution_hash', 'mtf_bundle_hash', 'context_cell_key', 'session_instance_id'] as $key) {
            $values = $rows->pluck($key);
            $valid = $valid && $values->filter(fn ($value): bool => is_string($value) && $value !== '')->count() === 4
                && $values->unique()->count() === 1;
            if (str_ends_with($key, '_hash')) $valid = $valid && strlen((string) $values->first()) === 64;
        }
        $valid = $valid && $rows->pluck('lab_agent_id')->filter()->unique()->count() === 4
            && $rows->pluck('evidence_run_id')->unique()->count() === 4;
        if (! $valid) return ['status' => 'factorial_controlled_evidence_incomplete', 'effects' => [], 'promotion_evidence' => false];
        $v = fn (string $arm): float => (float) $arms[$arm]['after_cost_value'];
        return ['status' => 'controlled_contrast', 'effects' => [
            'component_a_marginal_effect' => round($v('a_only') - $v('control'), 6),
            'component_b_marginal_effect' => round($v('b_only') - $v('control'), 6),
            'interaction_effect' => round($v('a_plus_b') - $v('a_only') - $v('b_only') + $v('control'), 6),
            'whole_capsule_effect' => round($v('a_plus_b') - $v('control'), 6)],
            'receipt_digest' => hash('sha256', json_encode($rows->all(), JSON_UNESCAPED_SLASHES)),
            'independent_confirmation_required' => true, 'promotion_evidence' => false];
    }

    /** @return array<int,string> */
    private function activatedCausalBundle(LabAgent $agent): array
    {
        return InstrumentInvocationLedger::query()
            ->where('lab_agent_id', $agent->id)
            ->whereNull('paper_signal_id')
            ->where('used_in_decision', true)
            ->get()
            ->filter(fn (InstrumentInvocationLedger $row): bool => data_get($row->metadata, 'declaration.causal_candidate') === true
                && data_get($row->metadata, 'runtime_trace.decision_path_activated') === true
                && (string) data_get($row->metadata, 'runtime_trace.status') === 'consumed'
            )
            ->pluck('instrument_key')
            ->map(fn ($item): string => trim((string) $item))
            ->filter()->unique()->sort()->values()->all();
    }

    private function introduced(array $candidate, array $control): ?string
    {
        $introduced = array_values(array_diff($candidate, $control));

        return $introduced[0] ?? null;
    }

    private function componentName(mixed $component, string $fallback): string
    {
        if (is_array($component)) {
            return (string) (data_get($component, 'instrument') ?: data_get($component, 'key') ?: data_get($component, 'gene') ?: $fallback);
        }

        return trim((string) $component) ?: $fallback;
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 8) : null;
    }

    /** @return array<string,mixed> */
    private function row(string $type, string $a, ?string $b, array $bundle, ?float $marginal, ?float $interaction, ?float $loo): array
    {
        return ['effect_type' => $type, 'instrument_a' => $a, 'instrument_b' => $b,
            'bundle' => array_values(array_unique($bundle)), 'marginal_effect' => $marginal,
            'interaction_effect' => $interaction, 'leave_one_out_effect' => $loo,
            'evidence' => ['protocol' => self::PROTOCOL]];
    }
}
