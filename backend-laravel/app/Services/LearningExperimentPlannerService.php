<?php

namespace App\Services;

/** Plans a bounded hypothesis; it never supplies promotion evidence itself. */
class LearningExperimentPlannerService
{
    /** @return array<string,mixed> */
    public function propose(array $packet, string $target, array $legalGenes = []): array
    {
        $blocked = array_values(array_unique((array) ($packet['blocked_mutations'] ?? [])));
        $recommended = array_values(array_filter((array) ($packet['recommended_genes'] ?? []), fn ($gene) => ! in_array($gene, $blocked, true)));
        if ($legalGenes !== []) $recommended = array_values(array_filter($recommended, fn ($gene) => in_array($gene, $legalGenes, true)));
        $gene = $recommended[0] ?? null;
        return [
            'status' => $gene ? 'planned' : 'evidence_required', 'target' => $target, 'hypothesis' => $gene ? 'Change only '.$gene.' to improve '.$target.'.' : 'No context-compatible gene has enough evidence.',
            'parameter_key' => $gene, 'blocked_mutations' => $blocked,
            'required_experiment' => ['one_gene_only' => true, 'control_required' => true, 'independent_windows' => 3, 'positive_windows_required' => 2, 'same_execution_contract' => true, 'non_target_regression_allowed' => false],
            'promotion_evidence' => false,
        ];
    }
}
