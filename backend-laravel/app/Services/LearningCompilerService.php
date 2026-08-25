<?php

namespace App\Services;

use App\Models\AgentLearningCausalExperiment;
use App\Models\AgentLearningSettlement;
use App\Models\EvolutionLearningReceipt;
use App\Models\LabLearningLanePair;
use Illuminate\Support\Facades\Schema;

/**
 * Converts a settled causal observation into a scoped, expiring learning
 * receipt. A receipt is not a promotion signal; only CONFIRMED receipts may
 * guide exploitation in a future research cohort.
 */
class LearningCompilerService
{
    public const PROTOCOL = 'causal_learning_compiler_v1';
    public const STATES = ['observed', 'hypothesis', 'provisional', 'replicated', 'confirmed', 'decaying', 'deprecated'];

    /** @return array<string,mixed> */
    public function compile(array $input): array
    {
        $symbol = strtoupper((string) ($input['symbol'] ?? 'XAUUSD'));
        $timeframe = strtoupper((string) ($input['timeframe'] ?? 'H1'));
        $component = (string) ($input['component'] ?? 'unknown');
        $action = (string) ($input['action'] ?? 'observe');
        $uplift = isset($input['causal_uplift_r']) ? (float) $input['causal_uplift_r'] : null;
        $scope = $this->scope((array) ($input['scope'] ?? []));
        $sourceType = (string) ($input['source_type'] ?? self::class);
        $sourceKey = (string) ($input['source_key'] ?? hash('sha256', json_encode($input, JSON_UNESCAPED_SLASHES)));
        $claim = (string) ($input['claim'] ?? "{$component} {$action} in contextual scope");
        $claimKey = hash('sha256', json_encode([$symbol, $timeframe, $component, $action, $scope, $input['replacement'] ?? null], JSON_UNESCAPED_SLASHES));
        $support = max(0, (int) ($input['support'] ?? 1));
        $status = $this->state($input, $support);
        $confidence = max(0, min(1, (float) ($input['confidence'] ?? $this->confidence($status, $support, $uplift))));
        $contract = ['protocol' => self::PROTOCOL, 'claim_key' => $claimKey, 'scope_required_for_retrieval' => true, 'expires_after_days' => max(1, (int) ($input['expires_after'] ?? 60)), 'canonical_memory_eligible' => $status === 'confirmed', 'promotion_evidence' => false];
        $receipt = ['protocol' => self::PROTOCOL, 'claim_key' => $claimKey, 'claim' => $claim, 'symbol' => $symbol, 'timeframe' => $timeframe, 'component' => $component, 'action' => $action, 'replacement' => $input['replacement'] ?? null, 'causal_uplift_r' => $uplift, 'confidence' => $confidence, 'support' => $support, 'status' => $status, 'scope' => $scope, 'source_experiments' => array_values(array_unique(array_map('strval', (array) ($input['source_experiments'] ?? [$sourceKey])))), 'contract' => $contract, 'promotion_evidence' => false];
        if (! Schema::hasTable('evolution_learning_receipts')) return $receipt;

        $key = hash('sha256', implode('|', [self::PROTOCOL, $sourceType, $sourceKey]));
        $row = EvolutionLearningReceipt::query()->updateOrCreate(['receipt_key' => $key], [
            'claim_key' => $claimKey, 'lab_agent_id' => $input['lab_agent_id'] ?? null, 'lab_generation_id' => $input['lab_generation_id'] ?? null,
            'source_type' => $sourceType, 'source_key' => $sourceKey, 'symbol' => $symbol, 'timeframe' => $timeframe,
            'component' => $component, 'action' => $action, 'status' => $status, 'claim' => $claim, 'causal_uplift_r' => $uplift,
            'confidence' => $confidence, 'support' => $support, 'scope' => $scope, 'source_experiments' => $receipt['source_experiments'],
            'evidence' => ['input' => $input, 'contract' => $contract, 'promotion_evidence' => false], 'expires_at' => now()->addDays(max(1, (int) ($input['expires_after'] ?? 60))), 'compiled_at' => now(),
        ]);
        return [...$receipt, 'receipt_id' => $row->id, 'receipt_key' => $row->receipt_key];
    }

    /** @return array<string,mixed> */
    public function compileTradePath(array $settlement, array $scope = []): array
    {
        $attribution = (array) ($settlement['attribution'] ?? []);
        $uplift = (float) ($attribution['management_value'] ?? 0);
        $best = (string) ($attribution['best_path'] ?? 'fixed_target');
        return $this->compile([
            'source_type' => 'trade_path_laboratory', 'source_key' => (string) ($settlement['entry_hash'] ?? hash('sha256', json_encode($settlement))),
            'symbol' => $scope['symbol'] ?? 'XAUUSD', 'timeframe' => $scope['timeframe'] ?? 'H1', 'component' => 'trade_management',
            'action' => $uplift > 0 ? 'prefer' : 'avoid', 'replacement' => $best, 'causal_uplift_r' => $uplift,
            'claim' => $uplift > 0 ? "{$best} adds management value against fixed target" : "{$best} does not add management value against fixed target",
            'scope' => $scope, 'source_experiments' => [$scope['experiment_key'] ?? $settlement['entry_hash'] ?? 'trade-path'], 'support' => 1,
        ]);
    }

    /** @return array<string,mixed> */
    public function compileWait(array $entry, array $outcome): array
    {
        $uplift = -1 * (float) ($outcome['net_r'] ?? 0);
        $reason = (string) ($entry['rejected_reason'] ?? 'unknown_filter');
        return $this->compile([
            'source_type' => 'opportunity_funnel_wait', 'source_key' => (string) ($entry['opportunity_key'] ?? hash('sha256', json_encode($entry))),
            'symbol' => $entry['symbol'] ?? 'XAUUSD', 'timeframe' => $entry['timeframe'] ?? 'H1', 'component' => 'filter',
            'action' => $uplift >= 0 ? 'prefer' : 'relax', 'causal_uplift_r' => $uplift,
            'claim' => $uplift >= 0 ? "WAIT filter {$reason} avoided loss/noise" : "WAIT filter {$reason} missed positive opportunity",
            'scope' => (array) data_get($entry, 'evidence_snapshot.market_state', []), 'source_experiments' => [(string) ($entry['opportunity_key'] ?? 'wait')], 'support' => 1,
        ]);
    }

    /** @return array<string,mixed> */
    public function compileCanonical(array $input): array
    {
        $authority = $this->canonicalAuthority($input);
        if (! $authority['valid']) {
            return [
                'protocol' => self::PROTOCOL,
                'status' => 'rejected',
                'reason_codes' => $authority['reason_codes'],
                'canonical_memory_eligible' => false,
                'promotion_evidence' => false,
            ];
        }
        /** @var LabLearningLanePair $pair */
        $pair = $authority['pair'];
        /** @var AgentLearningSettlement $settlement */
        $settlement = $authority['settlement'];
        $gene = (string) ($pair->candidateResponseMap?->parameter_key ?: ($input['parameter_key'] ?? 'unknown'));
        $uplift = (float) ($input['causal_uplift_r'] ?? 0);
        return $this->compile([
            ...$input,
            'source_type' => $input['source_type'] ?? 'canonical_controlled_experiment',
            'component' => $input['component'] ?? $this->componentForGene($gene),
            'parameter_key' => $gene,
            'pair_id' => (int) $pair->id,
            'settlement_id' => (int) $settlement->id,
            'action' => $input['action'] ?? ($uplift > 0 ? 'prefer' : ($uplift < 0 ? 'avoid' : 'observe')),
            'claim' => $input['claim'] ?? "{$gene} has a controlled causal effect",
            'controlled' => true,
            'canonical_authority' => [
                'pair_id' => (int) $pair->id,
                'settlement_id' => (int) $settlement->id,
                'verified_control' => true,
                'causal_experiment_id' => $authority['causal_experiment_id'],
                'independent_confirmation' => $authority['independent_confirmation'],
            ],
        ]);
    }

    /** @return array<string,mixed> */
    private function canonicalAuthority(array &$input): array
    {
        if (! Schema::hasTable('lab_learning_lane_pairs') || ! Schema::hasTable('agent_learning_settlements')) {
            return ['valid' => false, 'reason_codes' => ['CANONICAL_AUTHORITY_TABLES_UNAVAILABLE']];
        }
        $pair = LabLearningLanePair::query()->with(['candidateResponseMap', 'controlResponseMap'])
            ->find((int) ($input['pair_id'] ?? 0));
        $settlement = AgentLearningSettlement::query()->find((int) ($input['settlement_id'] ?? 0));
        $reasons = [];
        if (! $pair || ! $pair->isVerifiedControlPair()) $reasons[] = 'VERIFIED_CONTROL_PAIR_REQUIRED';
        if (! $settlement
            || (string) $settlement->source_type !== LabLearningLanePair::class
            || (int) $settlement->source_id !== (int) ($pair?->id ?? 0)
            || ! in_array((string) $settlement->evidence_state, ['positive', 'negative'], true)) {
            $reasons[] = 'CANONICAL_SETTLEMENT_REQUIRED';
        }
        if (! filled($pair?->candidateResponseMap?->parameter_key)) $reasons[] = 'EXECUTED_PARAMETER_KEY_REQUIRED';

        $windows = max(0, (int) ($input['independent_windows'] ?? 0));
        $positive = max(0, (int) ($input['positive_windows'] ?? 0));
        $requiresConfirmation = $windows >= 3 || $positive >= 2;
        $experimentId = (int) ($input['causal_experiment_id'] ?? 0);
        $experiment = $experimentId > 0 && Schema::hasTable('agent_learning_causal_experiments')
            ? AgentLearningCausalExperiment::query()->find($experimentId)
            : null;
        $independentConfirmation = $experiment
            && (string) $experiment->status === 'confirmed'
            && (int) $experiment->guided_agent_id === (int) ($pair?->candidate_agent_id ?? 0)
            && (int) $experiment->independent_window_count >= 3;
        if ($requiresConfirmation && ! $independentConfirmation) {
            // A stateful replay is useful provisional evidence; counters
            // supplied by a caller cannot manufacture confirmation.
            $input['independent_windows'] = 0;
            $input['positive_windows'] = 0;
        }

        return [
            'valid' => $reasons === [],
            'reason_codes' => $reasons,
            'pair' => $pair,
            'settlement' => $settlement,
            'causal_experiment_id' => $experimentId ?: null,
            'independent_confirmation' => (bool) $independentConfirmation,
        ];
    }

    private function state(array $input, int $support): string
    {
        if ((bool) ($input['deprecated'] ?? false)) return 'deprecated';
        if ((bool) ($input['decaying'] ?? false)) return 'decaying';
        if (! (bool) ($input['controlled'] ?? false)) return $support > 1 ? 'hypothesis' : 'observed';
        $windows = max(0, (int) ($input['independent_windows'] ?? 0));
        $positive = max(0, (int) ($input['positive_windows'] ?? 0));
        if ($windows >= 3 && $positive >= 2 && ! (bool) ($input['non_target_regression'] ?? false)) return 'confirmed';
        if ($windows >= 2) return 'replicated';
        return 'provisional';
    }

    private function confidence(string $status, int $support, ?float $uplift): float
    {
        $base = match ($status) { 'confirmed' => .85, 'replicated' => .70, 'provisional' => .60, 'hypothesis' => .40, 'observed' => .20, 'decaying' => .25, default => .05 };
        return min(.99, $base + min(.10, $support / 1000) + (abs((float) $uplift) > .1 ? .03 : 0));
    }

    /** @return array<string,string> */
    private function scope(array $scope): array
    {
        return array_filter([
            'strategy_family' => $scope['strategy_family'] ?? $scope['family'] ?? null, 'regime' => $scope['regime'] ?? null,
            'session' => $scope['session'] ?? null, 'volatility' => $scope['volatility'] ?? null,
            'horizon' => $scope['horizon'] ?? $scope['horizon_mode'] ?? null, 'state_key' => $scope['state_key'] ?? null,
        ], fn ($value): bool => $value !== null && $value !== '');
    }

    private function componentForGene(string $gene): string
    {
        $gene = strtolower($gene);
        return match (true) {
            str_contains($gene, 'risk') || str_contains($gene, 'stop') => 'risk', str_contains($gene, 'target') || str_contains($gene, 'trail') || str_contains($gene, 'break_even') => 'trade_management', str_contains($gene, 'session') => 'session', str_contains($gene, 'entry') || str_contains($gene, 'trigger') => 'execution', default => 'strategy_parameter',
        };
    }
}
