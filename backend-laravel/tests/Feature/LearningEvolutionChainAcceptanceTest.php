<?php

namespace Tests\Feature;

use App\Models\AgentLearningEpisode;
use App\Models\AgentLearningSettlement;
use App\Models\AiLaboratory;
use App\Models\InstrumentInvocationLedger;
use App\Models\InstrumentValuePosterior;
use App\Models\LabAgent;
use App\Models\LabEvolutionCreditEvent;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use App\Models\LabMutationResponseMap;
use App\Models\ModelVersion;
use App\Models\PlaybookComposition;
use App\Models\PlaybookValuePosterior;
use App\Models\TradingInstrument;
use App\Services\LearningIntelligenceAuditService;
use App\Services\InstrumentResearchWindowService;
use App\Services\TradingInstrumentOperatingSystemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\InstrumentValidationFixture;
use Tests\TestCase;

class LearningEvolutionChainAcceptanceTest extends TestCase
{
    use RefreshDatabase;
    use InstrumentValidationFixture;

    public function test_later_parent_artifacts_cannot_hide_a_missing_bundle_and_complete_chain_is_observable(): void
    {
        [$candidate, $pair] = $this->verifiedPair();
        $episode = AgentLearningEpisode::create([
            'episode_id' => (string) Str::uuid(), 'decision_key' => 'golden-chain',
            'lab_agent_id' => $candidate->id, 'model_version_id' => $candidate->model_version_id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'stage' => 'full', 'status' => 'settled', 'context_hash' => hash('sha256', 'golden'),
            'decision_context' => [], 'opened_at' => now(), 'settled_at' => now(),
        ]);
        AgentLearningSettlement::create([
            'settlement_id' => (string) Str::uuid(), 'episode_id' => $episode->id,
            'source_key' => $pair->pair_key, 'source_type' => LabLearningLanePair::class,
            'source_id' => $pair->id, 'outcome_status' => 'settled', 'evidence_state' => 'positive',
            'selection_reward' => .5, 'hard_failure' => false, 'outcome' => [],
            'reward_components' => ['promotion_evidence' => false], 'settled_at' => now(),
        ]);

        app(TradingInstrumentOperatingSystemService::class)->seedDefaults();
        $instrument = TradingInstrument::query()->where('instrument_key', 'volume_confirmation')->firstOrFail();
        InstrumentInvocationLedger::create([
            'invocation_key' => hash('sha256', 'golden-invocation'),
            'lab_agent_id' => $candidate->id, 'lab_generation_id' => $candidate->lab_generation_id,
            'trading_instrument_id' => $instrument->id, 'instrument_key' => $instrument->instrument_key,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'state_key' => 'trend_up|london|normal|normal|stable|0',
            'input_hash' => str_repeat('1', 64), 'output_hash' => str_repeat('2', 64),
            'used_in_decision' => true, 'used_in_execution' => false, 'verdict' => 'helped',
            'causal_contribution' => .2, 'metadata' => ['promotion_evidence' => false],
            'invoked_at' => now(), 'settled_at' => now(),
        ]);
        InstrumentValuePosterior::create([
            'trading_instrument_id' => $instrument->id, 'symbol' => 'XAUUSD', 'timeframe' => 'M15',
            'state_key' => 'trend_up|london|normal|normal|stable|0|buy|hybrid|london_am_fix', 'observations' => 5,
            'net_value' => .2, 'uncertainty' => .1, 'decay_state' => 'confirmed',
            'value_vector' => $this->posteriorVector('trend_up|london|normal|normal|stable|0|buy|hybrid|london_am_fix'),
        ]);

        DB::table('evolutionary_authority_ledgers')->insert([
            'authority_key' => hash('sha256', 'golden-authority'),
            'model_version_id' => $candidate->model_version_id, 'lab_agent_id' => $candidate->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'authority_stage' => 'eligible_parent', 'status' => 'passed',
            'data_hash' => str_repeat('a', 64), 'execution_hash' => str_repeat('b', 64),
            'evidence' => json_encode([
                'protocol' => 'evolutionary_authority_foundry_v1', 'incubation_passed' => true,
                'research_mentor_authority' => ['tier' => 'research_mentor', 'eligible' => true, 'parent_eligible' => false],
                'economic_parent_authority' => [
                    'tier' => 'economic_parent', 'eligible' => true, 'parent_eligible' => true,
                    'checks' => ['performance_credit_earned' => true, 'two_inheritance_credits_earned' => true],
                ],
                'promotion_evidence' => false,
            ]),
            'evaluated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        LabEvolutionCreditEvent::create([
            'lab_agent_id' => $candidate->id, 'model_version_id' => $candidate->model_version_id,
            'parent_model_version_id' => $candidate->model_version_id, 'symbol' => 'XAUUSD',
            'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'event_type' => 'performance',
            'context_key' => 'trend_up', 'amount' => .2, 'status' => 'confirmed',
            'evidence_fingerprint' => hash('sha256', 'golden-credit'), 'payload' => [], 'recorded_at' => now(),
        ]);

        $blocked = app(LearningIntelligenceAuditService::class)->snapshot('XAUUSD', 'H1');
        $this->assertFalse(data_get($blocked, 'learning_evolution_chain.complete'));
        $this->assertSame('confirmed_contextual_bundle', data_get($blocked, 'learning_evolution_chain.first_missing_stage'));
        $this->assertTrue(data_get($blocked, 'learning_evolution_chain.stages.strong_parent'));
        $this->assertTrue(data_get($blocked, 'learning_evolution_chain.stages.rewarded_evolution'));

        $playbook = PlaybookComposition::create([
            'playbook_key' => 'golden-exact-bundle', 'label' => 'Golden exact bundle',
            'symbol' => 'XAUUSD', 'timeframe' => 'M15', 'promotion_state' => 'research_only',
            'instrument_keys' => ['volume_confirmation', 'atr_risk_envelope', 'cost_aware_exit'],
            'preconditions' => [], 'metadata' => [
                'protocol' => 'exact_instrument_research_bundle_v1',
                'primary_instrument_key' => 'volume_confirmation', 'router_eligible' => false,
            ],
        ]);
        PlaybookValuePosterior::create([
            'playbook_composition_id' => $playbook->id, 'symbol' => 'XAUUSD', 'timeframe' => 'M15',
            'state_key' => 'trend_up|london|normal|normal|stable|0|buy|hybrid|london_am_fix', 'observations' => 5,
            'net_value' => .18, 'uncertainty' => .1, 'decay_state' => 'confirmed',
            'value_vector' => $this->posteriorVector('trend_up|london|normal|normal|stable|0|buy|hybrid|london_am_fix'),
        ]);

        $complete = app(LearningIntelligenceAuditService::class)->snapshot('XAUUSD', 'H1');
        $this->assertTrue(data_get($complete, 'learning_evolution_chain.complete'));
        $this->assertNull(data_get($complete, 'learning_evolution_chain.first_missing_stage'));
        $this->assertTrue(collect(data_get($complete, 'learning_evolution_chain.stages'))
            ->every(fn ($stage): bool => $stage === true));
    }

    /** @return array{LabAgent,LabLearningLanePair} */
    private function verifiedPair(): array
    {
        $lab = AiLaboratory::create([
            // Reuse the shared proof fixture's laboratory; symbol/timeframe is
            // unique even when the learning-chain and proof generations differ.
            'symbol' => 'XAUUSD', 'name' => 'Synthetic instrument validation fixture', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test',
            'population_size' => 2, 'status' => 'screened',
        ]);
        $pairKey = hash('sha256', 'golden-pair');
        $base = ['volume_lane' => 'none', 'risk' => 1];
        $controlModel = $this->model('golden-control', $base, $pairKey, 'control');
        $candidateModel = $this->model('golden-candidate', [...$base, 'volume_lane' => 'breakout_volume_confirmation'], $pairKey, 'candidate');
        $control = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $controlModel->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'test', 'lifecycle_status' => 'screened', 'parameter_diff' => [],
        ]);
        $candidate = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $candidateModel->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'test', 'lifecycle_status' => 'screened',
            'parameter_diff' => ['volume_lane' => ['old' => 'none', 'new' => 'breakout_volume_confirmation']],
        ]);
        $data = str_repeat('a', 64);
        $execution = str_repeat('b', 64);
        $controlMap = LabMutationResponseMap::create([
            'response_key' => hash('sha256', 'golden-control-map'), 'stage' => 'full', 'status' => 'control',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'lab_agent_id' => $control->id, 'model_version_id' => $control->model_version_id,
            'observed_metrics' => ['profit_factor' => 1.1], 'metadata' => ['control_contract' => [
                'protocol' => 'frozen_control_v2', 'control_only' => true, 'role' => 'control',
                'generation_id' => $generation->id, 'data_hash' => $data, 'execution_hash' => $execution,
            ]],
        ]);
        $pair = LabLearningLanePair::create([
            'pair_key' => $pairKey, 'lab_generation_id' => $generation->id,
            'candidate_agent_id' => $candidate->id, 'control_agent_id' => $control->id,
            'control_response_map_id' => $controlMap->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'baseline_source' => 'control', 'status' => 'learning_observed',
            'candidate_data_hash' => $data, 'control_data_hash' => $data,
            'candidate_execution_hash' => $execution, 'control_execution_hash' => $execution,
            'pair_integrity_status' => 'verified', 'same_generation' => true,
            'candidate_metrics' => ['profit_factor' => 1.3], 'control_metrics' => ['profit_factor' => 1.1],
            'non_target_regression' => ['safe' => true, 'status' => 'passed'],
        ]);

        return [$candidate, $pair];
    }

    private function model(string $name, array $parameters, string $pairKey, string $role): ModelVersion
    {
        return ModelVersion::create([
            'name' => $name, 'strategy' => 'hybrid', 'version' => 'v1', 'generation' => 1,
            'status' => 'testing', 'parameters' => $parameters, 'evidence_status' => 'valid',
            'metadata' => ['control_pair_contract' => [
                'protocol' => 'exact_frozen_control_pair_v2', 'pair_key' => $pairKey,
                'role' => $role, 'required_for_candidate' => $role === 'candidate',
            ]],
        ]);
    }

    private function posteriorVector(string $stateKey): array
    {
        $this->travelTo(\Carbon\CarbonImmutable::parse('2028-01-01 00:00:00', 'UTC'));
        $manifests = [];
        foreach ([1, 2, 3] as $month) {
            $manifests[] = [
                'authorization_id' => 'golden-window-'.$month,
                'research_epoch_id' => 'synthetic-post-paper-research',
                'start_inclusive' => sprintf('2027-%02d-01T00:00:00Z', $month),
                'end_exclusive' => sprintf('2027-%02d-01T00:00:00Z', $month + 1),
                'dataset_sha256' => hash('sha256', 'golden-dataset-'.$month),
                'purpose' => 'instrument_independent_validation',
            ];
        }
        config()->set('services.instrument_policy.authorized_research_windows', $manifests);
        $windows = array_map(fn (array $manifest): array => app(InstrumentResearchWindowService::class)
            ->seal($manifest['authorization_id'], $manifest['dataset_sha256']), $manifests);
        $evidenceKeys = ['evidence-1', 'evidence-2', 'evidence-3', 'evidence-4', 'evidence-5'];

        // The chain must consume current exact-delta proof from original completed
        // candidate/control runs, not trust legacy posterior aggregate labels.
        return $this->exactValidationVector($stateKey, array_map(fn (string $key, int $index): array => [
                'window' => $windows[$index % 3], 'evidence_key' => $key, 'outcome' => 'positive',
            ], $evidenceKeys, array_keys($evidenceKeys)));
    }
}
