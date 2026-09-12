<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\MutationMemory;

/** Converts a paired candidate/control delta into a reusable trait ledger row. */
class TraitEvidenceLedgerService
{
    public const PROTOCOL = 'trait_evidence_ledger_v1';

    /** @return array<string, mixed> */
    public function assess(LabAgent $agent, string $gene, array $observability): array
    {
        $delta = data_get($observability, 'control_delta', data_get($observability, 'gate_margin.normalized_delta'));
        $numericDelta = is_numeric($delta) ? (float) $delta : null;
        $observable = (bool) data_get($observability, 'observable_effect', data_get($observability, 'classification') === 'observable_effect');
        $control = data_get($observability, 'mutation_contract.control_pair_status') === 'available';
        $safe = ! (bool) data_get($observability, 'non_target_regression.failed', false)
            && (bool) data_get($observability, 'non_target_regression.safe', false);
        $status = match (true) {
            ! $control || $numericDelta === null => 'technical_incomplete',
            ! $observable => 'no_effect',
            ! $safe || $numericDelta < 0 => 'harmful',
            $numericDelta > 0 => 'locally_beneficial',
            default => 'context_mismatch',
        };
        $history = MutationMemory::query()
            ->where('symbol', $agent->symbol)->where('timeframe', $agent->timeframe)
            ->where('strategy_family', $agent->strategy_family)->where('parameter_key', $gene)
            ->get();
        $beneficial = $history->filter(fn (MutationMemory $row): bool => data_get($row->behavioral_effect, 'trait_ledger.status') === 'locally_beneficial')->count()
            + ($status === 'locally_beneficial' ? 1 : 0);
        $harmful = $history->filter(fn (MutationMemory $row): bool => data_get($row->behavioral_effect, 'trait_ledger.status') === 'harmful')->count()
            + ($status === 'harmful' ? 1 : 0);
        $trials = $beneficial + $harmful;
        $posterior = (1 + $beneficial) / (2 + $trials);
        $error = sqrt(max(.000001, $posterior * (1 - $posterior) / max(3, $trials + 3)));

        return [
            'protocol' => self::PROTOCOL,
            'status' => $status,
            'target_gate' => data_get($observability, 'declared_target', data_get($observability, 'target')),
            'target_delta' => $numericDelta,
            'applicable_context' => data_get($observability, 'contextual_bandit.cell_key'),
            'harmful_context' => $status === 'harmful' ? data_get($observability, 'contextual_bandit.cell_key') : null,
            'posterior_probability_improvement' => round($posterior, 6),
            'credible_lower_bound' => round(max(0, $posterior - (1.645 * $error)), 6),
            'non_target_harm_probability' => round((1 + $harmful) / (2 + $trials), 6),
            'independent_confirmations' => 0,
            'promotion_evidence' => false,
        ];
    }
}
