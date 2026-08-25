<?php

namespace App\Services;

/** Turns contextual receipts into a bounded cohort directive before composition freezes. */
class EvolutionDirectorService
{
    public const PROTOCOL = 'learning_driven_evolution_director_v1';

    public function __construct(private ContextualLearningMemoryService $memory) {}

    /** @return array{plan:array<int,array<string,mixed>>,contract:array<string,mixed>} */
    public function materialize(array $plan, string $symbol, string $timeframe, int $generation): array
    {
        $total = count($plan); $budget = $this->budget($total);
        $roles = array_merge(array_fill(0, $budget['exploit'], 'exploit'), array_fill(0, $budget['repair'], 'repair'), array_fill(0, $budget['explore'], 'explore'), array_fill(0, $budget['falsification'], 'falsification'));
        foreach ($plan as $index => $slot) {
            $niche = (array) data_get($slot, 'niche', []);
            $role = $roles[$index] ?? 'explore';
            $context = ['strategy_family' => data_get($slot, 'family'), 'regime' => data_get($niche, 'regime'), 'session' => data_get($niche, 'session'), 'volatility' => data_get($niche, 'volatility'), 'horizon' => data_get($niche, 'horizon_mode')];
            $receipts = $this->memory->retrieve($symbol, $timeframe, $context);
            $positive = array_values(array_filter($receipts, fn (array $row): bool => in_array($row['action'], ['prefer', 'keep'], true)
                && $row['status'] === 'confirmed' && $this->isExecutableBySlot($row, $slot)));
            $negative = array_values(array_filter($receipts, fn (array $row): bool => in_array($row['action'], ['avoid', 'relax'], true)
                && $row['status'] === 'confirmed' && $this->isExecutableBySlot($row, $slot)));
            $consumed = $role === 'exploit' ? $positive : ($role === 'repair' ? $negative : []);
            $fallback = null;
            if (($role === 'exploit' || $role === 'repair') && $consumed === []) { $fallback = 'NO_CONTEXTUAL_RECEIPT_AVAILABLE'; $role = 'explore'; }
            $source = $consumed[0] ?? null;
            $niche['learning_evolution'] = ['protocol' => self::PROTOCOL, 'generation' => $generation, 'experiment_role' => $role, 'requested_role' => $roles[$index] ?? 'explore', 'consumed_receipt_ids' => array_values(array_filter(array_map(fn (array $row) => $row['receipt_id'] ?? null, $consumed))), 'inherited_components' => array_values(array_unique(array_map(fn (array $row): string => (string) $row['component'], $consumed))), 'mutation_reason' => $source === null ? null : (string) $source['claim'], 'required_component' => $source === null ? null : (string) $source['component'], 'required_gene' => $source === null ? null : data_get($source, 'evidence.input.parameter_key'), 'mutation_from' => $source === null ? null : data_get($source, 'evidence.input.old_value'), 'mutation_to' => $source === null ? null : data_get($source, 'evidence.input.new_value'), 'control_pair_required' => $role !== 'explore', 'full_replay_required' => false, 'settlement_required' => $role !== 'explore', 'fallback_reason' => $fallback, 'promotion_evidence' => false];
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
        if ($gene === '' || $declaredGene === '' || $gene !== $declaredGene) return false;

        $receiptValue = data_get($receipt, 'evidence.input.new_value');
        if (is_array($receiptValue) && array_key_exists('value', $receiptValue)) $receiptValue = $receiptValue['value'];
        $declaredValue = data_get($slot, 'niche.declared_value', data_get($slot, 'niche.causal_learning_cohort.value'));

        return json_encode($receiptValue, JSON_PRESERVE_ZERO_FRACTION)
            === json_encode($declaredValue, JSON_PRESERVE_ZERO_FRACTION);
    }
}
