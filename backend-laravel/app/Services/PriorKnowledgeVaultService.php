<?php

namespace App\Services;

use App\Models\KnowledgePrior;
use Illuminate\Support\Facades\Schema;

/**
 * Maintains externally sourced hypotheses without confusing them for local
 * evidence. The vault is deliberately absent from the runtime trade path.
 */
class PriorKnowledgeVaultService
{
    public const PROTOCOL = 'xauusd_prior_knowledge_vault_v1';

    /** @return array<int, array<string, mixed>> */
    public function blueprints(): array
    {
        return [
            $this->blueprint('prior_trend_pullback_001', 'trend_pullback', 'trend', 'trend_pullback', 'balanced_professional'),
            $this->blueprint('prior_liquidity_reversal_001', 'liquidity_reversal', 'transition', 'trend_pullback', 'reversal_reduced_risk'),
            $this->blueprint('prior_break_retest_001', 'break_retest', 'breakout_compression', 'breakout_retest', 'breakout_measured_move'),
            $this->blueprint('prior_smc_continuation_001', 'smc_continuation', 'trend', 'trend_pullback', 'structure_runner'),
            $this->blueprint('prior_orb_001', 'opening_range_breakout', 'breakout_compression', 'session_breakout', 'session_orb'),
            $this->blueprint('prior_range_reversion_001', 'range_mean_reversion', 'range', 'range_mean_reversion', 'range_fixed_target'),
            $this->blueprint('prior_session_sweep_001', 'session_sweep', 'transition', 'session_breakout', 'session_orb'),
            $this->blueprint('prior_momentum_expansion_001', 'momentum_expansion', 'breakout_compression', 'volatility_compression_expansion', 'structure_runner'),
        ];
    }

    /** @return array<string, mixed> */
    public function seed(): array
    {
        if (! Schema::hasTable('knowledge_priors')) return ['status' => 'unavailable', 'seeded' => 0];

        foreach ($this->blueprints() as $blueprint) {
            KnowledgePrior::query()->updateOrCreate(['prior_id' => $blueprint['prior_id']], $blueprint);
        }

        return ['status' => 'seeded', 'seeded' => count($this->blueprints()), 'protocol' => self::PROTOCOL];
    }

    /**
     * The influence decays as local observations accumulate. At no point can
     * it become a runtime or promotion permission.
     *
     * @return array<string, mixed>
     */
    public function proposalBias(string $priorId, int $localEvidenceCount = 0): array
    {
        $prior = collect($this->blueprints())->firstWhere('prior_id', $priorId);
        if ($prior === null && Schema::hasTable('knowledge_priors')) {
            $prior = KnowledgePrior::query()->where('prior_id', $priorId)->first()?->toArray();
        }
        if (! is_array($prior)) throw new \InvalidArgumentException("Unknown knowledge prior: {$priorId}");

        $base = (float) ($prior['influence_weight'] ?? .40);
        $weight = round($base * exp(-max(0, $localEvidenceCount) / 12), 6);

        return [
            'protocol' => self::PROTOCOL,
            'prior_id' => $priorId,
            'proposal_bias_weight' => $weight,
            'local_evidence_count' => max(0, $localEvidenceCount),
            'decay_rule' => 'external_weight * exp(-local_evidence_count / 12)',
            'authority' => ['runtime_trade' => false, 'promotion' => false, 'local_evidence_tier' => 'none'],
            'prior_debt' => [
                'required_control_types' => ['frozen_baseline', 'priorless_control', 'temporal_ablation', 'negative_control'],
                'must_be_paid_before_library_credit' => true,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function blueprint(string $priorId, string $strategy, string $regime, string $tactic, string $management): array
    {
        return [
            'prior_id' => $priorId,
            'source_class' => 'practitioner_plus_academic_mechanism',
            'symbol_scope' => 'XAUUSD',
            'knowledge_tier' => 'K1',
            'hypothesis' => ['strategy' => $strategy, 'regime' => $regime, 'tactic' => $tactic],
            'temporal_roles' => ['bias' => 'H1', 'setup' => 'M15', 'trigger' => 'M5', 'execution' => 'M1', 'invalidation' => 'M5'],
            'risk_contract' => ['sizing' => 'fixed_R_over_executable_stop_distance', 'martingale' => 'forbidden'],
            'management_contract' => ['profile' => $management, 'partial' => 'allowed', 'runner' => 'allowed'],
            'falsification_contract' => ['no_incremental_edge_after_cost', 'false_entry_rate_not_reduced', 'drawdown_regression', 'temporal_role_redundant'],
            'authority_contract' => ['local_evidence_tier' => 'none', 'runtime_trade' => false, 'promotion_allowed' => false],
            'external_score' => .50,
            'influence_weight' => .40,
            'status' => 'research_only',
        ];
    }
}
