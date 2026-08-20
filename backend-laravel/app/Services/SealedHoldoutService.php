<?php

namespace App\Services;

use App\Models\CandidateGateDecision;
use App\Models\ModelMarketPerformance;
use App\Models\SealedHoldoutRelease;
use App\Services\MarketData\FrozenPaperWindowService;
use App\Services\MarketData\MarketTrainingDataService;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SealedHoldoutService
{
    public function __construct(
        private LabDatasetExportService $datasets,
        private MarketChampionService $champions,
        private StrategyParameterSchemaService $schemas,
        private RuntimeEnsemblePolicyService $runtimeEnsembles,
        private FrozenPaperWindowService $paperWindows,
    ) {}

    public function release(ModelMarketPerformance $performance): SealedHoldoutRelease
    {
        $performance = $performance->fresh(['modelVersion']);
        if ($performance->paper_status !== 'passed') {
            throw new RuntimeException('Paper gate has not passed.');
        }
        if ($performance->status !== 'paper'
            || $performance->evidence_status !== 'valid'
            || $performance->modelVersion?->evidence_status !== 'valid') {
            throw new RuntimeException('Holdout paper lifecycle or evidence identity is not valid.');
        }
        if ($performance->holdout_status !== 'sealed') {
            throw new RuntimeException('Holdout release can only be opened once from sealed state.');
        }

        $forward = CandidateGateDecision::query()
            ->where('model_market_performance_id', $performance->id)
            ->where('stage', 'statistical_forward_gate')
            ->latest('evaluated_at')
            ->first();
        if ($forward?->decision !== 'passed'
            || data_get($forward->metrics, 'elite_agent_passport.status') !== 'passed') {
            throw new RuntimeException('A passed independent forward passport is required for holdout.');
        }

        $runtime = $this->runtimeEnsembles->requestPayload($performance);
        if ((bool) data_get($performance->metrics, 'portfolio_proxy', false)
            && data_get($runtime, 'runtime_action') !== 'ROUTE') {
            throw new RuntimeException('Portfolio runtime passport is not ROUTE; holdout blocked.');
        }

        $existing = SealedHoldoutRelease::where('model_market_performance_id', $performance->id)->first();
        if ($existing) {
            $staleMinutes = max(30, (int) config('services.paper_observation.holdout_stale_minutes', 180));
            if ($existing->status === 'running'
                && $existing->opened_at?->lte(now()->subMinutes($staleMinutes))) {
                $existing->update([
                    'status' => 'failed',
                    'result' => [
                        ...((array) $existing->result),
                        'error' => 'STALE_HOLDOUT_RELEASE',
                        'promotion_evidence' => false,
                        'recovery_action' => 'operator_review_required',
                    ],
                    'completed_at' => now(),
                ]);
            }

            return $existing->fresh();
        }

        $paperWindow = $this->paperWindows->active(
            MarketTrainingDataService::DEFAULT_DATASET,
            MarketTrainingDataService::DEFAULT_PROVIDER,
            $performance->symbol,
            $performance->timeframe,
        );
        if (! $paperWindow) {
            throw new RuntimeException('Frozen six-month paper window is required before sealed holdout.');
        }
        $foundation = $this->datasets->ensureFoundationDataset($performance->symbol, $performance->timeframe);
        // Holdout is research evidence, not paper execution. It must use the
        // pre-2026 foundation lane; the frozen 2026 window belongs only to the
        // paper signal/ledger path.
        $datasetPath = $foundation['path'];
        $hash = (string) ($foundation['sha256'] ?? '');
        if ($hash === '') {
            throw new RuntimeException('Holdout dataset manifest hash is missing.');
        }

        $release = SealedHoldoutRelease::create([
            'model_market_performance_id' => $performance->id,
            'dataset_hash' => $hash,
            'status' => 'running',
            'opened_at' => now(),
        ]);
        $model = $performance->modelVersion;
        $executionContract = app(ExecutionContractService::class)->for($performance->symbol, $performance->timeframe);
        $portfolioMembers = (array) data_get($runtime, 'portfolio_members', []);
        $isPortfolio = count($portfolioMembers) >= 2;
        $response = Http::timeout(1200)->acceptJson()
            ->withHeaders(['X-Internal-Token' => (string) config('services.internal_api.token')])
            ->post(rtrim(config('services.ai_service.url'), '/').'/api/holdout/run', [
                'symbol' => $performance->symbol,
                'timeframe' => $performance->timeframe,
                'strategy' => $isPortfolio ? 'portfolio_v1' : $model->strategy,
                'base_strategy' => $isPortfolio
                    ? 'portfolio'
                    : $this->schemas->runtimeBaseStrategy($model->strategy, data_get($model->metadata, 'base_strategy'), $performance->strategy_family),
                'parameters' => $isPortfolio ? (array) data_get($runtime, 'parameters', []) : ($model->parameters ?? []),
                'portfolio_members' => $portfolioMembers,
                'runtime_ensemble_policy' => (array) data_get($runtime, 'runtime_ensemble_policy', []),
                'dataset_path' => $datasetPath,
                'foundation_dataset_path' => $foundation['path'],
                'policy_context' => [
                    'data_boundary' => [
                        'protocol' => 'pre_2026_training_paper_only_v1',
                        'training_end_exclusive' => '2026-01-01T00:00:00Z',
                        'paper_allowed_for_replay' => false,
                        'paper_allowed_for_mutation' => false,
                        'promotion_evidence' => false,
                    ],
                ],
                'initial_balance' => 10000,
                'risk_per_trade' => 1,
                'execution' => $executionContract['parameters'],
                'execution_contract' => $executionContract,
            ]);
        if ($response->failed()) {
            $this->failRelease($release, 'HOLDOUT_SERVICE_FAILED', ['response' => $response->body()]);
        }

        $payload = $response->json();
        if (! is_array($payload)
            || ! is_array(data_get($payload, 'result'))
            || ! is_numeric(data_get($payload, 'score'))) {
            $this->failRelease($release, 'HOLDOUT_RESPONSE_INCOMPLETE');
        }
        $returnedContract = (array) data_get($payload, 'result.execution_contract', data_get($payload, 'execution_contract', []));
        if ($returnedContract === []
            || ! app(ExecutionContractService::class)->matches($returnedContract, $performance->symbol, $performance->timeframe)) {
            $this->failRelease($release, 'EXECUTION_CONTRACT_MISMATCH', [
                'expected' => $executionContract['execution_hash'],
                'received' => data_get($returnedContract, 'execution_hash'),
            ]);
        }

        $payload['gold_holdout'] = [
            'protocol' => 'gold_holdout_v1',
            'status' => 'released_once',
            'dataset_hash' => $hash,
            'used_for_training' => false,
            'used_for_evolution' => false,
            'one_time_release' => true,
            'selection_excluded' => true,
        ];
        $release->update([
            'status' => 'completed',
            'score' => $payload['score'],
            'result' => $payload,
            'completed_at' => now(),
        ]);
        $this->champions->finalizeHoldout($performance, $payload);

        return $release->fresh();
    }

    private function failRelease(SealedHoldoutRelease $release, string $error, array $context = []): never
    {
        $release->update([
            'status' => 'failed',
            'result' => ['error' => $error, ...$context, 'promotion_evidence' => false],
            'completed_at' => now(),
        ]);

        throw new RuntimeException('Holdout release blocked: '.$error.'.');
    }
}
