<?php

namespace Tests\Feature;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\LabSkillZooEntry;
use App\Models\ModelVersion;
use App\Services\CanonicalSkillCartridgeService;
use App\Services\CausalCompoundingKernelService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CanonicalSkillCartridgeScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_provisional_transplant_materializes_one_closed_proof_inside_a_twenty_seat_population(): void
    {
        Queue::fake();
        $dataHash = str_repeat('d', 64);
        $executionHash = str_repeat('e', 64);
        $lab = AiLaboratory::create([
            'name' => 'Cartridge Kernel', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test',
            'trigger_context' => [
                'canonical_dataset_snapshots' => [
                    'price' => ['manifest' => ['sha256' => $dataHash]],
                    'foundation' => ['manifest' => ['sha256' => str_repeat('f', 64)]],
                ],
            ],
            'population_size' => 1, 'status' => 'screened',
        ]);
        $parameters = app(StrategyParameterSchemaService::class)->defaults('hybrid');
        $old = $parameters['minimum_confidence'];
        $tested = min(6.0, (float) $old + 0.05);
        $baseline = ModelVersion::create([
            'name' => 'cartridge-kernel-baseline', 'strategy' => 'hybrid', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => $parameters,
            'metadata' => ['base_strategy' => 'hybrid'], 'evidence_status' => 'valid',
        ]);
        LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $baseline->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'test', 'lifecycle_status' => 'screened', 'parameter_diff' => [],
        ]);
        $entry = LabSkillZooEntry::create([
            'skill_key' => 'materialized-cartridge', 'cartridge_key' => hash('sha256', 'materialized-cartridge'),
            'revision' => 1, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'module_key' => 'entry', 'niche_key' => 'trend_up|normal|london', 'gene_key' => 'minimum_confidence',
            'quality_score' => .2, 'confidence' => .66, 'status' => 'provisional',
            'component_status' => 'paired_observed', 'organism_viability' => 'not_viable',
            'evidence' => [
                'target' => 'profit_factor',
                'intervention' => ['old_value' => $old, 'tested_value' => $tested, 'direction' => 'increase'],
                'provenance' => ['data_hashes' => [$dataHash], 'execution_hashes' => [$executionHash]],
            ],
        ]);

        $result = app(CanonicalSkillCartridgeService::class)->materializeTransplant($entry, $baseline->id);
        $this->assertSame('queued', $result['status'], json_encode($result));
        $proofGeneration = LabGeneration::with('agents.modelVersion')->findOrFail($result['generation_id']);
        $primary = $proofGeneration->agents->where('origin', 'skill_cartridge_transplant');

        $this->assertCount(20, $proofGeneration->agents);
        $this->assertCount(5, $primary);
        $this->assertSame(5, $primary->pluck('modelVersion.strategy')->unique()->count());
        $this->assertTrue($proofGeneration->agents->every(
            fn (LabAgent $agent): bool => $agent->parent_a_model_version_id === null
                && (int) data_get($agent->modelVersion->metadata, 'causal_baseline_model_version_id') === (int) $baseline->id,
        ));
        $this->assertSame(CausalCompoundingKernelService::PROTOCOL, data_get($proofGeneration->trigger_context, 'causal_compounding_kernel.protocol'));
        $this->assertSame(14, data_get($proofGeneration->trigger_context, 'causal_compounding_kernel.paired_discovery_seats'));
        $this->assertSame(1, data_get($proofGeneration->trigger_context, 'causal_compounding_kernel.uncertainty_abstain_seats'));
        Queue::assertPushed(EvaluateLabAgentJob::class, 20);
    }

    public function test_component_interaction_is_a_five_arm_proof_inside_one_twenty_seat_population(): void
    {
        Queue::fake();
        $dataHash = str_repeat('a', 64);
        $executionHash = str_repeat('b', 64);
        $lab = AiLaboratory::create([
            'name' => 'Cartridge Interaction Kernel', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse',
        ]);
        $source = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test',
            'trigger_context' => [], 'population_size' => 1, 'status' => 'screened',
        ]);
        $parameters = app(StrategyParameterSchemaService::class)->defaults('hybrid');
        $baseline = ModelVersion::create([
            'name' => 'interaction-kernel-baseline', 'strategy' => 'hybrid', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => $parameters,
            'metadata' => ['base_strategy' => 'hybrid'], 'evidence_status' => 'valid',
        ]);
        LabAgent::create([
            'lab_generation_id' => $source->id, 'model_version_id' => $baseline->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'test', 'lifecycle_status' => 'screened', 'parameter_diff' => [],
        ]);
        $entry = function (string $key, string $gene, float $tested) use ($parameters, $dataHash, $executionHash): LabSkillZooEntry {
            return LabSkillZooEntry::create([
                'skill_key' => $key, 'cartridge_key' => hash('sha256', $key), 'revision' => 1,
                'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
                'module_key' => 'entry', 'niche_key' => 'trend_up|normal|london', 'gene_key' => $gene,
                'quality_score' => .3, 'confidence' => .8, 'status' => 'confirmed',
                'component_status' => 'component_confirmed', 'organism_viability' => 'not_viable',
                'evidence' => [
                    'intervention' => ['old_value' => $parameters[$gene], 'tested_value' => $tested],
                    'provenance' => ['data_hashes' => [$dataHash], 'execution_hashes' => [$executionHash]],
                ],
            ]);
        };
        $a = $entry('interaction-a', 'minimum_confidence', 1.05);
        $b = $entry('interaction-b', 'trend_weight', 1.10);

        $result = app(CanonicalSkillCartridgeService::class)->materializeInteraction($a, $b, $baseline->id);
        $generation = LabGeneration::with('agents.modelVersion')->findOrFail($result['generation_id']);
        $primary = $generation->agents->where('origin', 'skill_cartridge_interaction');

        $this->assertSame('queued', $result['status']);
        $this->assertCount(20, $generation->agents);
        $this->assertCount(5, $primary);
        $this->assertSame(5, $primary->pluck('modelVersion.strategy')->unique()->count());
        $this->assertTrue($generation->agents->every(fn (LabAgent $agent): bool => $agent->parent_a_model_version_id === null));
        $this->assertSame(CausalCompoundingKernelService::PROTOCOL, data_get($generation->trigger_context, 'causal_compounding_kernel.protocol'));
        Queue::assertPushed(EvaluateLabAgentJob::class, 20);
    }

    public function test_retrieval_abstains_when_a_frozen_scope_field_is_missing_or_mismatched(): void
    {
        LabSkillZooEntry::create([
            'skill_key' => 'scope-cartridge', 'cartridge_key' => hash('sha256', 'scope-cartridge'), 'revision' => 1,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'module_key' => 'stress_cost', 'niche_key' => 'trend_up|normal|london',
            'gene_key' => 'atr_stop_multiplier', 'quality_score' => .1, 'confidence' => .8, 'status' => 'confirmed',
            'component_status' => 'component_confirmed', 'organism_viability' => 'not_viable',
            'evidence' => ['protocol' => CanonicalSkillCartridgeService::PROTOCOL,
                'context' => ['regime' => 'trend_up', 'volatility' => 'normal', 'session' => 'london', 'temporal_roles_hash' => 'temporal-a', 'confirmation_entry_hash' => 'entry-a', 'execution_contract_hash' => 'execution-a'],
                'intervention' => ['old_value' => 1.5, 'tested_value' => 1.25, 'direction' => 'decrease'],
                'effect' => ['target' => 'stress_cost', 'lower_bound' => .04]],
        ]);
        $service = app(CanonicalSkillCartridgeService::class);
        $missing = $service->retrieve('XAUUSD', 'H1', 'hybrid', ['regime' => 'trend_up', 'volatility' => 'normal', 'session' => 'london'], ['atr_stop_multiplier']);
        $this->assertSame('memory_abstained', $missing['status']);
        $this->assertSame('CONTEXT_SCOPE_INCOMPLETE', $missing['reason']);
        $wrongVolatility = $service->retrieve('XAUUSD', 'H1', 'hybrid', ['regime' => 'trend_up', 'volatility' => 'high', 'session' => 'london', 'temporal_roles_hash' => 'temporal-a', 'confirmation_entry_hash' => 'entry-a', 'execution_contract_hash' => 'execution-a'], ['atr_stop_multiplier']);
        $this->assertSame('VOLATILITY_SCOPE_MISMATCH', $wrongVolatility['reason']);
        $exact = $service->retrieve('XAUUSD', 'H1', 'hybrid', ['regime' => 'trend_up', 'volatility' => 'normal', 'session' => 'london', 'temporal_roles_hash' => 'temporal-a', 'confirmation_entry_hash' => 'entry-a', 'execution_contract_hash' => 'execution-a'], ['atr_stop_multiplier']);
        $this->assertSame('compatible_cartridge_found', $exact['status']);
        $this->assertSame(1.25, $exact['proposed_value']);
        $backfill = $service->backfillImmutableRevisions('XAUUSD', 'H1');
        $this->assertSame(1, $backfill['immutable_revisions_sealed']);
        $this->assertDatabaseCount('skill_cartridge_revisions', 1);
    }

    public function test_categorical_provisional_confirmation_has_a_real_five_arm_plan(): void
    {
        $entry = LabSkillZooEntry::create([
            'skill_key' => 'categorical-confirmation', 'cartridge_key' => hash('sha256', 'categorical-confirmation'), 'revision' => 1,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'module_key' => 'regime', 'niche_key' => 'trend_up|normal|london',
            'gene_key' => 'regime_classifier_variant', 'quality_score' => .1, 'confidence' => .66, 'status' => 'provisional',
            'component_status' => 'paired_observed', 'organism_viability' => 'not_viable',
            'evidence' => ['protocol' => CanonicalSkillCartridgeService::PROTOCOL,
                'intervention' => ['old_value' => 'frozen', 'tested_value' => 'adx_hysteresis_v1', 'direction' => 'replace']],
        ]);

        $baseline = ModelVersion::create(['name' => 'categorical-confirmation-baseline', 'status' => 'testing']);
        $plan = app(CanonicalSkillCartridgeService::class)->planTransplant($entry, $baseline->id, ['regime' => 'trend_up'], true);

        $this->assertSame([
            'frozen_baseline', 'exact_replication', 'independent_exact_replication', 'negative_control', 'memory_blinded_autonomous',
        ], $plan['modes']);
        $this->assertDatabaseCount('skill_cartridge_transplant_trials', 5);
        $this->assertDatabaseHas('skill_cartridge_transplant_trials', ['lab_skill_zoo_entry_id' => $entry->id, 'mode' => 'independent_exact_replication']);
    }

    public function test_retry_attempt_has_a_distinct_immutable_trial_identity(): void
    {
        $entry = LabSkillZooEntry::create([
            'skill_key' => 'retry-identity', 'cartridge_key' => hash('sha256', 'retry-identity'), 'revision' => 1,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'module_key' => 'stress_cost', 'niche_key' => 'trend_up',
            'gene_key' => 'atr_stop_multiplier', 'quality_score' => .1, 'confidence' => .6, 'status' => 'provisional',
            'component_status' => 'paired_observed', 'organism_viability' => 'not_viable',
            'evidence' => ['protocol' => CanonicalSkillCartridgeService::PROTOCOL,
                'intervention' => ['old_value' => 1.5, 'tested_value' => 1.25]],
        ]);
        $baseline = ModelVersion::create(['name' => 'retry-identity-baseline', 'status' => 'testing']);
        $service = app(CanonicalSkillCartridgeService::class);

        $service->planTransplant($entry, $baseline->id, ['regime' => 'trend_up'], true);
        $service->planTransplant($entry, $baseline->id, [
            'regime' => 'trend_up', 'transplant_retry_attempt' => 1, 'retry_of_generation_id' => 181,
        ], true);

        $this->assertDatabaseCount('skill_cartridge_transplant_trials', 10);
    }

    public function test_existing_two_positive_provisional_cartridge_gets_an_exactly_once_next_action(): void
    {
        $entry = LabSkillZooEntry::create([
            'skill_key' => 'legacy-near-confirmation', 'cartridge_key' => hash('sha256', 'legacy-near-confirmation'), 'revision' => 1,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'module_key' => 'regime', 'niche_key' => 'trend_up|normal|london',
            'gene_key' => 'regime_classifier_variant', 'quality_score' => .1, 'confidence' => .66, 'status' => 'provisional',
            'component_status' => 'paired_observed', 'organism_viability' => 'not_viable', 'evidence' => [],
        ]);
        foreach ([1, 2] as $number) {
            DB::table('skill_cartridge_observations')->insert([
                'observation_key' => hash('sha256', 'near-confirmation-'.$number), 'lab_skill_zoo_entry_id' => $entry->id,
                'outcome' => 'positive', 'target_delta' => .1, 'evidence' => json_encode([]), 'observed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $result = app(CanonicalSkillCartridgeService::class)->reconcileProvisionalConfirmationState('XAUUSD', 'H1');
        $entry->refresh();

        $this->assertSame(1, $result['reconciled_provisional_states']);
        $this->assertSame('awaiting_third_independent_replication', data_get($entry->evidence, 'confirmation.state'));
        $this->assertSame('dispatch_gene_type_aware_five_arm_confirmation', data_get($entry->evidence, 'confirmation.next_required_action'));
    }
}
