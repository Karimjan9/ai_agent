<?php

namespace App\Services;

use App\Models\ModelMarketPerformance;
use App\Models\SealedHoldoutRelease;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SealedHoldoutService
{
    public function __construct(
        private LabDatasetExportService $datasets,
        private MarketChampionService $champions,
        private StrategyParameterSchemaService $schemas,
        private RuntimeEnsemblePolicyService $runtimeEnsembles,
    ) {}

    public function release(ModelMarketPerformance $performance): SealedHoldoutRelease
    {
        if ($performance->paper_status !== 'passed') {
            throw new RuntimeException('Paper gate hali o‘tilmagan.');
        }
        $existing = SealedHoldoutRelease::where('model_market_performance_id', $performance->id)->first();
        if ($existing) {
            return $existing;
        }
        $datasetPath = $this->datasets->export($performance->symbol, $performance->timeframe);
        $manifest = is_file($datasetPath.'.manifest.json')
            ? (array) json_decode((string) file_get_contents($datasetPath.'.manifest.json'), true)
            : [];
        $hash = (string) data_get($manifest, 'sha256', hash_file('sha256', $datasetPath));
        $release = SealedHoldoutRelease::create(['model_market_performance_id' => $performance->id, 'dataset_hash' => $hash, 'status' => 'running', 'opened_at' => now()]);
        $model = $performance->modelVersion;
        $executionContract = app(ExecutionContractService::class)->for($performance->symbol, $performance->timeframe);
        $runtime = $this->runtimeEnsembles->requestPayload($performance);
        $portfolioMembers = (array) data_get($runtime, 'portfolio_members', []);
        $isPortfolio = count($portfolioMembers) >= 2;
        $response = Http::timeout(1200)->acceptJson()->withHeaders(['X-Internal-Token' => (string) config('services.internal_api.token')])->post(rtrim(config('services.ai_service.url'), '/').'/api/holdout/run', [
            'symbol' => $performance->symbol, 'timeframe' => $performance->timeframe,
            'strategy' => $isPortfolio ? 'portfolio_v1' : $model->strategy,
            'base_strategy' => $isPortfolio ? 'portfolio' : $this->schemas->runtimeBaseStrategy($model->strategy, data_get($model->metadata, 'base_strategy'), $performance->strategy_family),
            'parameters' => $isPortfolio ? (array) data_get($runtime, 'parameters', []) : ($model->parameters ?? []),
            'portfolio_members' => $portfolioMembers,
            'runtime_ensemble_policy' => (array) data_get($runtime, 'runtime_ensemble_policy', []),
            'dataset_path' => $datasetPath, 'initial_balance' => 10000, 'risk_per_trade' => 1,
            'execution' => $executionContract['parameters'], 'execution_contract' => $executionContract,
        ]);
        if ($response->failed()) {
            $release->update(['status' => 'failed', 'result' => ['error' => $response->body()], 'completed_at' => now()]);
            throw new RuntimeException('Holdout service failed: '.$response->body());
        }
        $payload = (array) $response->json();
        $returnedContract = (array) data_get($payload, 'result.execution_contract', data_get($payload, 'execution_contract', []));
        if ($returnedContract !== [] && ! app(ExecutionContractService::class)->matches($returnedContract, $performance->symbol, $performance->timeframe)) {
            $release->update(['status' => 'failed', 'result' => ['error' => 'EXECUTION_CONTRACT_MISMATCH', 'expected' => $executionContract['execution_hash'], 'received' => data_get($returnedContract, 'execution_hash')], 'completed_at' => now()]);
            throw new RuntimeException('Holdout execution contract mismatch; release blocked.');
        }
        $payload['gold_holdout'] = [
            'protocol' => 'gold_holdout_v1', 'status' => 'released_once', 'dataset_hash' => $hash,
            'used_for_training' => false, 'used_for_evolution' => false, 'one_time_release' => true,
            'selection_excluded' => true,
        ];
        $release->update(['status' => 'completed', 'score' => $payload['score'] ?? 0, 'result' => $payload, 'completed_at' => now()]);
        $this->champions->finalizeHoldout($performance, $payload);

        return $release->fresh();
    }
}
