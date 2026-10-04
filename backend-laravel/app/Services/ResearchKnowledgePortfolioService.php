<?php

namespace App\Services;

use App\Models\ResearchExperimentReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence-bounded civilization memory and successor portfolio read-models.
 * Neither projection transfers authority nor changes a runtime policy.
 */
class ResearchKnowledgePortfolioService
{
    public const PROTOCOL = 'research_knowledge_portfolio_v1';
    public const KNOWLEDGE_TYPES = ['EPISODIC', 'SEMANTIC', 'PROCEDURAL', 'CAUSAL', 'NEGATIVE', 'COUNTERFACTUAL', 'CIVILIZATIONAL'];

    /** @return array<string,mixed> */
    public function recordReceipt(ResearchExperimentReceipt $receipt): array
    {
        if (! Schema::hasTable('research_knowledge_entries')) return $this->unavailable();
        $payload = (array) $receipt->payload;
        $classification = (string) $receipt->classification;
        $type = match ($classification) {
            'POSITIVE_CANDIDATE' => 'CAUSAL',
            'BEHAVIORAL_ACTIVATION_HYPOTHESIS' => 'SEMANTIC',
            'HARMFUL' => 'NEGATIVE',
            // Missing data or power describes the experiment, not a harmful skill.
            'UNDERPOWERED', 'INCONCLUSIVE' => 'EPISODIC',
            'TECHNICAL_QUARANTINE' => 'COUNTERFACTUAL',
            default => 'EPISODIC',
        };
        $scope = (array) data_get($payload, 'contract.scope', [
            'symbol' => $receipt->symbol, 'laboratory_timeframe' => $receipt->laboratory_timeframe,
        ]);
        $key = hash('sha256', implode('|', [self::PROTOCOL, 'receipt', $receipt->receipt_key, $type]));
        DB::table('research_knowledge_entries')->updateOrInsert(['knowledge_key' => $key], [
            'knowledge_type' => $type, 'subject_type' => ResearchExperimentReceipt::class, 'subject_key' => $receipt->receipt_key,
            'symbol' => $receipt->symbol, 'timeframe' => $receipt->laboratory_timeframe,
            'authority' => 'research_only', 'freshness' => 'active', 'status' => 'recorded', 'scope' => json_encode($scope),
            'claim' => json_encode(['classification' => $classification, 'hypothesis' => data_get($payload, 'contract.claim.hypothesis'),
                'target_stage' => data_get($payload, 'contract.claim.target_stage'), 'promotion_evidence' => false]),
            'evidence' => json_encode(['receipt_key' => $receipt->receipt_key, 'evidence_hash' => $receipt->evidence_hash,
                'receipt_payload_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES)), 'promotion_evidence' => false]),
            'dependencies' => json_encode(['receipt_id' => $receipt->id, 'contract_hash' => $receipt->contract_hash]),
            'recorded_at' => now(), 'updated_at' => now(), 'created_at' => now(),
        ]);
        return ['protocol' => self::PROTOCOL, 'status' => 'recorded', 'knowledge_type' => $type, 'knowledge_key' => $key, 'promotion_evidence' => false];
    }

    /**
     * Register a proposed inherited cartridge for a host. It remains blocked
     * until that host completes an independently paired transplant.
     *
     * @return array<string,mixed>
     */
    public function proposePortfolioEntry(int $hostModelVersionId, array $cartridge, array $contextualTrust, array $selfKnowledge = []): array
    {
        if (! Schema::hasTable('research_skill_portfolio_entries')) return $this->unavailable();
        $status = (string) ($cartridge['status'] ?? $cartridge['component_status'] ?? '');
        $cartridgeId = (int) ($cartridge['id'] ?? $cartridge['cartridge_id'] ?? 0);
        if ($cartridgeId <= 0 || ! in_array($status, ['confirmed', 'confirmed_component'], true)) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason' => 'CONFIRMED_CARTRIDGE_REQUIRED', 'promotion_evidence' => false];
        }
        $symbol = strtoupper((string) ($cartridge['symbol'] ?? 'XAUUSD'));
        $timeframe = strtoupper((string) ($cartridge['timeframe'] ?? 'H1'));
        $key = hash('sha256', implode('|', [self::PROTOCOL, 'portfolio', $hostModelVersionId, $cartridgeId, $symbol, $timeframe]));
        $outcomes = $this->outcomeVector($cartridge);
        DB::table('research_skill_portfolio_entries')->updateOrInsert(['portfolio_key' => $key], [
            'host_model_version_id' => $hostModelVersionId, 'skill_cartridge_id' => $cartridgeId, 'symbol' => $symbol, 'timeframe' => $timeframe,
            'status' => 'paired_transplant_required', 'contextual_trust' => json_encode($contextualTrust), 'outcome_vector' => json_encode($outcomes),
            'self_knowledge' => json_encode($selfKnowledge), 'evidence' => json_encode([
                'protocol' => self::PROTOCOL, 'cartridge_status' => $status, 'transfer_required' => true,
                'context_authority_transfers_automatically' => false, 'promotion_evidence' => false,
            ]), 'updated_at' => now(), 'created_at' => now(),
        ]);
        return ['protocol' => self::PROTOCOL, 'status' => 'paired_transplant_required', 'portfolio_key' => $key,
            'outcome_vector' => $outcomes, 'promotion_evidence' => false];
    }

    /** @return array<string,mixed> */
    public function recommend(int $hostModelVersionId, array $objective, string $symbol = 'XAUUSD', string $timeframe = 'H1'): array
    {
        if (! Schema::hasTable('research_skill_portfolio_entries')) return $this->unavailable();
        $rows = DB::table('research_skill_portfolio_entries')->where('host_model_version_id', $hostModelVersionId)
            ->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->get();
        $ranked = $rows->map(function (object $row) use ($objective): array {
            $vector = (array) json_decode((string) $row->outcome_vector, true);
            // Collection::sum() callbacks receive only the value in the
            // supported framework version. Preserve metric keys explicitly
            // so a portfolio is scored against the requested outcome axes.
            $distance = 0.0;
            foreach ($objective as $metric => $target) {
                if (is_numeric($target) && is_numeric($vector[$metric] ?? null)) {
                    $distance += abs((float) $target - (float) $vector[$metric]);
                }
            }
            return ['portfolio_key' => $row->portfolio_key, 'skill_cartridge_id' => $row->skill_cartridge_id,
                'status' => $row->status, 'outcome_vector' => $vector, 'objective_distance' => round($distance, 6)];
        })->sortBy('objective_distance')->values();
        return ['protocol' => self::PROTOCOL, 'status' => $ranked->isEmpty() ? 'no_eligible_portfolio' : 'research_recommendation',
            'recommendations' => $ranked->all(), 'live_router_allowed' => false, 'promotion_evidence' => false];
    }

    /** @return array<string,float|null> */
    private function outcomeVector(array $cartridge): array
    {
        $evidence = (array) ($cartridge['evidence'] ?? []);
        return [
            'setup_density' => $this->number($cartridge, $evidence, ['setup_density', 'effect_vector.setup_density']),
            'confirmation_precision' => $this->number($cartridge, $evidence, ['confirmation_precision', 'effect_vector.confirmation_precision']),
            'trade_density' => $this->number($cartridge, $evidence, ['trade_density', 'effect_vector.trade_density']),
            'mfe' => $this->number($cartridge, $evidence, ['mfe', 'secondary.mfe_capture_delta']),
            'mae' => $this->number($cartridge, $evidence, ['mae']),
            'cost_exposure' => $this->number($cartridge, $evidence, ['cost_exposure', 'secondary.cost_delta']),
            'holding_time' => $this->number($cartridge, $evidence, ['holding_time']),
            'drawdown' => $this->number($cartridge, $evidence, ['drawdown', 'secondary.drawdown_delta']),
            'abstention_rate' => $this->number($cartridge, $evidence, ['abstention_rate']),
        ];
    }

    private function number(array $cartridge, array $evidence, array $paths): ?float
    {
        foreach ($paths as $path) {
            $value = data_get($cartridge, $path, data_get($evidence, $path));
            if (is_numeric($value)) return (float) $value;
        }
        return null;
    }

    private function unavailable(): array { return ['protocol' => self::PROTOCOL, 'status' => 'migration_pending', 'promotion_evidence' => false]; }
}
