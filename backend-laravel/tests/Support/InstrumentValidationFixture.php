<?php

namespace Tests\Support;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use App\Models\LabMutationResponseMap;
use App\Models\ModelVersion;
use App\Services\InstrumentValidationEvidenceService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\TradingInstrumentOperatingSystemService;
use Illuminate\Support\Str;

/** Explicit synthetic proof facts; this fixture is never live-market evidence. */
trait InstrumentValidationFixture
{
    private function exactValidationFacts(array $context, array $window, string $key, string $gene = 'volume_lane', mixed $old = 'none', mixed $new = 'confirmed', ?array $baseline = null): array
    {
        $baseline ??= [$gene => $old];
        $hashes = app(ResearchPaperEpochContractService::class);
        $validation = app(InstrumentValidationEvidenceService::class);
        $state = app(TradingInstrumentOperatingSystemService::class)->fingerprint('XAUUSD', 'M15', $context);
        $evaluator = hash('sha256', 'synthetic-evaluator-v2');
        $delta = $validation->sealDelta($gene, $old, $new, $hashes->parameterHash($baseline), $evaluator, $state);

        $pairKey = hash('sha256', 'fixture-pair-'.$key);
        $pair = LabLearningLanePair::query()->where('pair_key', $pairKey)->first();
        if ($pair === null) {
            $lab = AiLaboratory::firstOrCreate(['name' => 'Synthetic instrument validation fixture'],
                ['symbol' => 'XAUUSD', 'timeframe' => 'H1', 'is_active' => false, 'strategy_families' => ['hybrid']]);
            $generation = LabGeneration::firstOrCreate(['ai_laboratory_id' => $lab->id, 'generation' => 7001],
                ['trigger_type' => 'synthetic_test', 'status' => 'screened', 'population_size' => 2]);
            $agents = [];
            $runs = [];
            $maps = [];
            foreach (['candidate', 'control'] as $arm) {
                $parameters = $arm === 'candidate' ? [...$baseline, $gene => $new] : $baseline;
                $model = ModelVersion::create(['name' => 'fixture-'.$arm.'-'.$key, 'strategy' => 'regime_router',
                    'version' => 'fixture-v2', 'generation' => 1, 'status' => 'testing', 'parameters' => $parameters,
                    'metadata' => ['control_pair_contract' => ['protocol' => 'exact_frozen_control_pair_v2',
                        'pair_key' => $pairKey, 'role' => $arm]]]);
                $agents[$arm] = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
                    'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => $state['strategy_family'],
                    'origin' => 'synthetic_test', 'lifecycle_status' => 'screened',
                    'parameter_diff' => $arm === 'candidate' ? [$gene => ['old' => $old, 'new' => $new]] : []]);
                $runs[$arm] = LabEvaluationRun::create(['run_id' => (string) Str::uuid(),
                    'lab_generation_id' => $generation->id, 'lab_agent_id' => $agents[$arm]->id, 'model_version_id' => $model->id,
                    'phase' => 'screening', 'mode' => 'synthetic_test', 'attempt' => 1, 'status' => 'completed',
                    'code_hash' => $evaluator, 'parameter_hash' => $hashes->parameterHash($parameters),
                    'data_hash' => $window['dataset_sha256'], 'request_hash' => hash('sha256', $key.'-'.$arm.'-request'),
                    'response_hash' => hash('sha256', $key.'-'.$arm.'-response')]);
                $maps[$arm] = LabMutationResponseMap::create(['response_key' => hash('sha256', $key.'-'.$arm.'-map'),
                    'stage' => 'screening', 'status' => $arm === 'control' ? 'control' : 'screen_observed',
                    'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => $state['strategy_family'],
                    'lab_agent_id' => $agents[$arm]->id, 'model_version_id' => $model->id, 'evidence_run_id' => $runs[$arm]->run_id,
                    'metadata' => ['control_contract' => ['protocol' => 'frozen_control_v2', 'control_only' => true,
                        'role' => 'control', 'generation_id' => $generation->id,
                        'data_hash' => $window['dataset_sha256'], 'execution_hash' => hash('sha256', 'synthetic-execution')]]]);
            }
            $pair = LabLearningLanePair::create(['pair_key' => $pairKey, 'lab_generation_id' => $generation->id,
                'candidate_agent_id' => $agents['candidate']->id, 'control_agent_id' => $agents['control']->id,
                'candidate_response_map_id' => $maps['candidate']->id, 'control_response_map_id' => $maps['control']->id,
                'candidate_evidence_run_id' => $runs['candidate']->run_id, 'control_evidence_run_id' => $runs['control']->run_id,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => $state['strategy_family'],
                'status' => 'screen_paired', 'baseline_source' => 'control', 'pair_integrity_status' => 'verified', 'same_generation' => true,
                'candidate_data_hash' => $window['dataset_sha256'], 'control_data_hash' => $window['dataset_sha256'],
                'candidate_execution_hash' => hash('sha256', 'synthetic-execution'), 'control_execution_hash' => hash('sha256', 'synthetic-execution'),
                'candidate_metrics' => ['instrument_research_trace' => ['context_source' => 'decision_time_trade_ledger',
                    'context_slice_protocol' => 'venue_phase_v1', 'exact_context_slices' => [['context' => $context,
                        'powered' => true, 'metrics' => ['trades' => 4]]]]],
                'control_metrics' => ['instrument_research_trace' => ['context_source' => 'decision_time_trade_ledger',
                    'context_slice_protocol' => 'venue_phase_v1', 'exact_context_slices' => [['context' => $context,
                        'powered' => true, 'metrics' => ['trades' => 4]]]]],
                'independent_window_key' => $window['window_key'], 'metadata' => ['instrument_research_window_receipt' => $window]]);
        }
        $pair->loadMissing(['candidateAgent.modelVersion', 'controlAgent.modelVersion']);
        $this->assertTrue($pair->isVerifiedControlPair(), 'Synthetic fixture must have a real exact frozen control pair.');
        $candidateRun = LabEvaluationRun::where('run_id', $pair->candidate_evidence_run_id)->firstOrFail();
        $controlRun = LabEvaluationRun::where('run_id', $pair->control_evidence_run_id)->firstOrFail();

        return ['tested_intervention' => $delta, 'source_receipt' => $validation->sealSource([
            'protocol' => InstrumentValidationEvidenceService::SOURCE_PROTOCOL, 'pair_key' => $pairKey,
            'candidate_agent_id' => (int) $pair->candidate_agent_id, 'control_agent_id' => (int) $pair->control_agent_id,
            'candidate_model_version_id' => (int) $pair->candidateAgent->model_version_id, 'control_model_version_id' => (int) $pair->controlAgent->model_version_id,
            'candidate_response_map_id' => (int) $pair->candidate_response_map_id, 'control_response_map_id' => (int) $pair->control_response_map_id,
            'candidate_evidence_run_id' => (int) $candidateRun->id, 'control_evidence_run_id' => (int) $controlRun->id,
            'candidate_run_key' => $candidateRun->run_id, 'control_run_key' => $controlRun->run_id,
            'candidate_request_hash' => $candidateRun->request_hash, 'control_request_hash' => $controlRun->request_hash,
            'candidate_response_hash' => $candidateRun->response_hash, 'control_response_hash' => $controlRun->response_hash,
            'data_hash' => $window['dataset_sha256'], 'execution_hash' => hash('sha256', 'synthetic-execution'),
            'candidate_parameter_hash' => $hashes->parameterHash([...$baseline, $gene => $new]),
            'control_parameter_hash' => $hashes->parameterHash($baseline), 'evaluator_hash' => $evaluator,
        ])];
    }

    private function exactValidationVector(string $stateKey, array $rows): array
    {
        [$regime, $session, $volatility, $spread, $transition, $loss, $direction, $family, $phase] = array_pad(explode('|', $stateKey), 9, null);
        $context = ['regime' => $regime, 'session' => $session, 'volatility' => $volatility,
            'spread_atr_ratio' => $spread === 'high' ? .4 : .1, 'transition' => $transition === 'transition',
            'loss_streak' => (int) $loss, 'direction' => $direction, 'strategy_family' => $family,
            ...($phase ? ['venue_phase' => $phase] : [])];
        $state = app(TradingInstrumentOperatingSystemService::class)->fingerprint('XAUUSD', 'M15', $context);
        $epochs = [];
        foreach ($rows as $row) {
            $value = $row['outcome'] === 'positive' ? .2 : ($row['outcome'] === 'negative' ? -.2 : 0.0);
            $outcome = [...$this->exactValidationFacts($context, $row['window'], $row['evidence_key']),
                'evidence_key' => $row['evidence_key'], 'instrument_research_window_receipt' => $row['window'],
                'control_contract' => ['data_hash' => $row['window']['dataset_sha256']]];
            $epochs = app(InstrumentValidationEvidenceService::class)->append($epochs, $outcome, $state,
                ['conditional_net_utility' => $value, 'non_target_regression' => false]);
        }

        return ['context' => [...$context, 'state_key' => $stateKey], 'strategy_family' => $family,
            'validation_epochs' => $epochs];
    }
}
