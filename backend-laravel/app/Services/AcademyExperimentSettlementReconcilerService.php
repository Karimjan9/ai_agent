<?php

namespace App\Services;

use App\Models\LabAgent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Settles already-materialized Academy cohorts as their immutable full-replay
 * evidence arrives. It never creates a trial, dispatches a replay, or grants
 * authority; the materializer owns all economic classification rules.
 */
class AcademyExperimentSettlementReconcilerService
{
    public const PROTOCOL = 'academy_experiment_settlement_reconciler_v1';

    public function __construct(private AcademyExperimentMaterializerService $materializer) {}

    /** @return array<string,mixed> */
    public function reconcile(string $symbol = 'XAUUSD', string $timeframe = 'H1', bool $apply = false, int $limit = 25): array
    {
        $symbol = strtoupper($symbol);
        $timeframe = strtoupper($timeframe);
        if (! Schema::hasTable('edge_academy_trials') || ! Schema::hasTable('model_market_performance')) {
            return ['protocol' => self::PROTOCOL, 'status' => 'migration_pending', 'promotion_evidence' => false];
        }

        // Do not let historical settled trials consume the per-tick budget:
        // only a trial without its terminal settlement can be reconciled.
        $openTrialIds = DB::table('edge_academy_trials')->whereNull('settled_at')->orderBy('id')
            ->limit(max(1, min(100, $limit)))->pluck('id')->map(fn ($id): int => (int) $id)->all();
        if ($openTrialIds === []) {
            return ['protocol' => self::PROTOCOL, 'status' => 'idle', 'trial_ids' => [],
                'new_replay_allowed' => false, 'promotion_evidence' => false];
        }
        $representatives = LabAgent::query()->with('modelVersion')
            ->where('origin', 'academy_experiment')->where('symbol', $symbol)->where('timeframe', $timeframe)
            ->orderBy('id')->get()->filter(function (LabAgent $agent): bool {
                return data_get($agent->modelVersion?->metadata, 'academy_experiment.protocol') === AcademyExperimentMaterializerService::PROTOCOL
                    && (int) data_get($agent->modelVersion?->metadata, 'academy_experiment.academy_trial_id', 0) > 0;
            })->filter(fn (LabAgent $agent): bool => in_array((int) data_get($agent->modelVersion?->metadata, 'academy_experiment.academy_trial_id'), $openTrialIds, true))
            ->groupBy(fn (LabAgent $agent): int => (int) data_get($agent->modelVersion?->metadata, 'academy_experiment.academy_trial_id'))
            ->map(fn ($agents): LabAgent => $agents->first())->take(max(1, min(100, $limit)))->values();

        if (! $apply) {
            return ['protocol' => self::PROTOCOL, 'status' => $representatives->isEmpty() ? 'idle' : 'would_reconcile',
                'trial_ids' => $representatives->map(fn (LabAgent $agent): int => (int) data_get($agent->modelVersion?->metadata, 'academy_experiment.academy_trial_id'))->all(),
                'new_replay_allowed' => false, 'promotion_evidence' => false];
        }

        $outcomes = $representatives->map(function (LabAgent $agent): array {
            return ['academy_trial_id' => (int) data_get($agent->modelVersion?->metadata, 'academy_experiment.academy_trial_id'),
                'result' => $this->materializer->settleOutcome($agent)];
        })->all();
        $settled = collect($outcomes)->filter(fn (array $outcome): bool => in_array(data_get($outcome, 'result.status'), [
            'settled_powered', 'settled_without_economic_claim', 'technical_quarantine',
        ], true))->count();

        return ['protocol' => self::PROTOCOL, 'status' => $outcomes === [] ? 'idle' : 'reconciled',
            'outcomes' => $outcomes, 'settled_count' => $settled, 'new_replay_allowed' => false,
            'promotion_evidence' => false];
    }
}
