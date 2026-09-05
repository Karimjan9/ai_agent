<?php

namespace App\Services;

/** Turns contextual receipts into a bounded cohort directive before composition freezes. */
class EvolutionDirectorService
{
    public const PROTOCOL = 'learning_driven_evolution_director_v1';

    public function __construct(
        private ContextualLearningMemoryService $memory,
        private StrategyParameterSchemaService $schemas,
    ) {}

    /** @return array{plan:array<int,array<string,mixed>>,contract:array<string,mixed>} */
    public function materialize(array $plan, string $symbol, string $timeframe, int $generation): array
    {
        $total = count($plan); $budget = $this->budget($total);
        $roles = array_merge(array_fill(0, $budget['exploit'], 'exploit'), array_fill(0, $budget['repair'], 'repair'), array_fill(0, $budget['explore'], 'explore'), array_fill(0, $budget['falsification'], 'falsification'));
        foreach ($plan as $index => $slot) {
            $niche = (array) data_get($slot, 'niche', []);
            $role = $roles[$index] ?? 'explore';
            $context = ['strategy_family' => data_get($slot, 'family'), 'regime' => data_get($niche, 'regime'), 'session' => data_get($niche, 'session'), 'volatility' => data_get($niche, 'volatility'), 'horizon' => data_get($niche, 'horizon_mode')];
            $receipts = $this->memory->retrieve($symbol, $timeframe, $context, ['confirmed']);
            $positive = array_values(array_filter($receipts, fn (array $row): bool => in_array($row['action'], ['prefer', 'keep'], true)
                && $this->isExecutableBySlot($row, $slot)));
            $negative = array_values(array_filter($receipts, fn (array $row): bool => in_array($row['action'], ['avoid', 'relax'], true)
                && $this->isExecutableBySlot($row, $slot)));
            // One seat tests one causal claim. Consuming every matching
            // receipt while executing only the first gene made the
            // inheritance manifest impossible to validate and turned memory
            // into passive telemetry. Rank order is already deterministic in
            // ContextualLearningMemoryService, so consume at most one.
            $source = $role === 'exploit'
                ? ($positive[0] ?? null)
                : ($role === 'repair' ? ($negative[0] ?? null) : null);
            $consumed = $source === null ? [] : [$source];
            $fallback = null;
            if (($role === 'exploit' || $role === 'repair') && $consumed === []) { $fallback = 'NO_CONTEXTUAL_RECEIPT_AVAILABLE'; $role = 'explore'; }
            $gene = $source === null ? null : (string) data_get($source, 'evidence.input.parameter_key');
            $observedOld = $source === null ? null : $this->scalarValue(data_get($source, 'evidence.input.old_value'));
            $observedNew = $source === null ? null : $this->scalarValue(data_get($source, 'evidence.input.new_value'));
            // A positive receipt reproduces old -> new. A negative receipt
            // repairs away from the harmful observation by testing new ->
            // old; repeating the rejected value would invert learning.
            $mutationFrom = $role === 'repair' ? $observedNew : $observedOld;
            $mutationTo = $role === 'repair' ? $observedOld : $observedNew;
            if ($source !== null && (string) data_get($niche, 'declared_gene', '') === '') {
                $niche['declared_gene'] = $gene;
                $niche['declared_value'] = $mutationTo;
                $niche['learning_receipt_injection'] = [
                    'protocol' => self::PROTOCOL,
                    'receipt_id' => (int) $source['receipt_id'],
                    'gene' => $gene,
                    'value' => $mutationTo,
                    'source_baseline_model_version_id' => data_get($source, 'evidence.input.canonical_authority.control_model_version_id'),
                    'promotion_evidence' => false,
                ];
            }
            $niche['learning_evolution'] = ['protocol' => self::PROTOCOL, 'generation' => $generation, 'experiment_role' => $role, 'requested_role' => $roles[$index] ?? 'explore', 'consumed_receipt_ids' => array_values(array_filter(array_map(fn (array $row) => $row['receipt_id'] ?? null, $consumed))), 'inherited_components' => array_values(array_unique(array_map(fn (array $row): string => (string) $row['component'], $consumed))), 'mutation_reason' => $source === null ? null : (string) $source['claim'], 'required_component' => $source === null ? null : (string) $source['component'], 'required_gene' => $gene, 'mutation_from' => $mutationFrom, 'mutation_to' => $mutationTo, 'source_baseline_model_version_id' => $source === null ? null : data_get($source, 'evidence.input.canonical_authority.control_model_version_id'), 'control_pair_required' => $role !== 'explore', 'full_replay_required' => $role !== 'explore', 'settlement_required' => $role !== 'explore', 'fallback_reason' => $fallback, 'promotion_evidence' => false];
            $plan[$index]['niche'] = $niche;
        }
        $actual = collect($plan)->map(fn (array $slot): string => (string) data_get($slot, 'niche.learning_evolution.experiment_role', 'explore'))->countBy()->all();
        return ['plan' => array_values($plan), 'contract' => ['protocol' => self::PROTOCOL, 'status' => 'materialized', 'generation' => $generation, 'budget' => $budget, 'requested_budget' => $budget, 'actual_budget' => $actual, 'rule' => 'exploit_or_repair_requires_a_confirmed_contextual_receipt_whose_exact_gene_and_value_are_already_executable_by_the_slot; otherwise_explore', 'promotion_evidence' => false]];
    }

    /** @return array<string,int> */
    private function budget(int $total): array
    {
        if ($total === 20) return ['exploit' => 8, 'repair' => 5, 'explore' => 4, 'falsification' => 3];
        $exploit = (int) floor($total * .40); $repair = (int) floor($total * .25); $explore = (int) floor($total * .20);
        return ['exploit' => $exploit, 'repair' => $repair, 'explore' => $explore, 'falsification' => max(0, $total - $exploit - $repair - $explore)];
    }

    private function isExecutableBySlot(array $receipt, array $slot): bool
    {
        $gene = (string) data_get($receipt, 'evidence.input.parameter_key', '');
        $declaredGene = (string) data_get($slot, 'niche.declared_gene', data_get($slot, 'niche.causal_learning_cohort.gene', ''));
        $family = (string) data_get($slot, 'family', '');
        if ($gene === '' || $family === '' || ! array_key_exists($gene, $this->schemas->schema($family))) return false;
        if ((bool) data_get($slot, 'niche.control_only', false)
            || (bool) data_get($slot, 'niche.structural_research', false)
            || filled(data_get($slot, 'niche.causal_learning_cohort.role'))) return false;
        // Never overwrite a pre-registered experiment. Empty ordinary seats
        // may be actively scheduled from confirmed memory; declared seats
        // must already match the same causal claim exactly.
        if ($declaredGene === '') return true;
        if ($gene !== $declaredGene) return false;

        $receiptValue = in_array((string) ($receipt['action'] ?? ''), ['avoid', 'relax'], true)
            ? data_get($receipt, 'evidence.input.old_value')
            : data_get($receipt, 'evidence.input.new_value');
        $receiptValue = $this->scalarValue($receiptValue);
        $declaredValue = data_get($slot, 'niche.declared_value', data_get($slot, 'niche.causal_learning_cohort.value'));

        return json_encode($receiptValue, JSON_PRESERVE_ZERO_FRACTION)
            === json_encode($declaredValue, JSON_PRESERVE_ZERO_FRACTION);
    }

    private function scalarValue(mixed $value): mixed
    {
        return is_array($value) && array_key_exists('value', $value) ? $value['value'] : $value;
    }
}
