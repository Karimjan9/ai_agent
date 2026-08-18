<?php

namespace App\Services;

use App\Models\LabFailureDojoRun;
use App\Models\LabLearningLanePair;
use Illuminate\Support\Facades\Schema;

/** Keeps failed states executable as focused research curriculum, never as promotion evidence. */
class FailureDojoService
{
    public const PROTOCOL = 'failure_dojo_v1';

    public function available(): bool
    {
        return Schema::hasTable('lab_failure_dojo_runs');
    }

    public function recordPair(LabLearningLanePair $pair): ?LabFailureDojoRun
    {
        if (! $this->available() || ! $pair->failure_signature) return null;

        $pair->loadMissing('candidateAgent.modelVersion');
        $signature = (array) $pair->failure_signature;
        $repairAnchorId = (int) data_get($pair->candidateAgent?->modelVersion?->metadata, 'repair_anchor.id', 0);
        $parameterDiff = (array) ($pair->candidateAgent?->parameter_diff ?? []);
        $key = hash('sha256', json_encode([
            self::PROTOCOL, $pair->id, data_get($signature, 'signature'), $pair->independent_window_key,
        ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        return LabFailureDojoRun::query()->firstOrCreate(
            ['dojo_key' => $key],
            [
                'pair_id' => $pair->id,
                'candidate_agent_id' => $pair->candidate_agent_id,
                'repair_anchor_id' => $repairAnchorId > 0 ? $repairAnchorId : null,
                'symbol' => $pair->symbol,
                'timeframe' => $pair->timeframe,
                'family' => $pair->strategy_family,
                'target' => $pair->target,
                'state_signature' => data_get($signature, 'signature') ?: data_get($signature, 'failure_type'),
                'expected_action' => $this->expectedAction($pair),
                'status' => 'pending',
                'failure_signature' => $signature,
                'evidence' => [
                    'protocol' => self::PROTOCOL,
                    'failure_state' => data_get($signature, 'state', []),
                    'counterfactual_status' => 'pending',
                    'frozen_control_required' => true,
                    'mutation_contract' => [
                        'protocol' => 'failure_to_mutation_v1',
                        'declared_gene' => count($parameterDiff) === 1 ? array_key_first($parameterDiff) : null,
                        'expected_action' => $this->expectedAction($pair),
                        'technical_failure_mutation_forbidden' => true,
                        'promotion_evidence' => false,
                    ],
                    'promotion_evidence' => false,
                ],
            ],
        );
    }

    public function recordAssessment(LabLearningLanePair $pair, array $assessment): ?LabFailureDojoRun
    {
        $run = $this->recordPair($pair);
        if (! $run) return null;
        $run->update([
            'status' => (string) ($assessment['status'] ?? 'unresolved'),
            'score' => isset($assessment['score']) ? (float) $assessment['score'] : null,
            'evidence' => [
                ...((array) $run->evidence),
                'micro_protocol' => $assessment['protocol'] ?? 'micro_replay_v1',
                'windows' => $assessment['windows'] ?? [],
                'causal_probe' => $assessment['causal_probe'] ?? [],
                'reason' => $assessment['reason'] ?? null,
                'promotion_evidence' => false,
            ],
            'evaluated_at' => now(),
        ]);
        return $run->fresh();
    }

    /** @return array<string, mixed> */
    public function progress(string $symbol, string $timeframe): array
    {
        if (! $this->available()) return ['available' => false];
        $query = LabFailureDojoRun::query()->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe));
        return [
            'available' => true,
            'total' => (clone $query)->count(),
            'pending' => (clone $query)->where('status', 'pending')->count(),
            'passed' => (clone $query)->where('status', 'passed')->count(),
            'failed' => (clone $query)->where('status', 'failed')->count(),
        ];
    }

    private function expectedAction(LabLearningLanePair $pair): string
    {
        $target = strtolower((string) $pair->target);
        return match (true) {
            str_contains($target, 'drawdown'), str_contains($target, 'risk') => 'reduce_risk_or_veto',
            str_contains($target, 'stress'), str_contains($target, 'cost') => 'repair_cost_exit',
            str_contains($target, 'temporal'), str_contains($target, 'monthly') => 'repair_time_stability',
            default => 'repair_declared_gene',
        };
    }
}
