<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabGeneration;
use App\Services\AutonomousModeService;
use App\Services\LabDatasetExportService;
use App\Services\LabPopulationService;
use App\Services\LearningVelocityGateService;
use App\Services\ResearchPaperEpochContractService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class NativeSpecialistCouncilConstructorTest extends TestCase
{
    use RefreshDatabase;

    private function intent(): array
    {
        return ['protocol' => LabPopulationService::NATIVE_COUNCIL_INTENT_PROTOCOL,
            'purpose' => 'research', 'symbol' => 'XAUUSD', 'storage_timeframe' => 'H1',
            'population_size' => 6, 'creator_id' => 'native-constructor-test',
            'research_question' => 'Does the prospective four-horizon council change its original solo/ablation account outcomes?'];
    }

    private function ready(bool $technicalDebt = false): AiLaboratory
    {
        config()->set('services.xauusd_organism.historical_research_until_champion', true);
        config()->set('services.market_data.provider', 'csv');
        config()->set('services.lab_selection.constructor_initial_seat_budget', 6);
        app(AutonomousModeService::class)->start('XAUUSD', 'H1', 'test', 'native original references');
        $lab = AiLaboratory::create(['name' => 'native-reference-test', 'symbol' => 'XAUUSD',
            'timeframe' => 'H1', 'strategy_families' => ['hybrid'], 'is_active' => true,
            'lifecycle_mode' => 'lighthouse']);
        $lab->generations()->create(['generation' => 1, 'trigger_type' => 'historical_research',
            'status' => 'completed', 'population_size' => 0, 'trigger_context' => ['data_count' => 0],
            'completed_at' => now()]);
        $this->mock(LearningVelocityGateService::class, fn ($mock) => $mock->shouldReceive('inspect')
            ->andReturn(['status' => $technicalDebt ? 'blocked_technical_recovery' : 'healthy',
                'allowed' => ! $technicalDebt]));
        $this->mock(LabDatasetExportService::class, fn ($mock) => $mock->shouldReceive('ensureFoundationDataset')
            ->andReturn(['sha256' => str_repeat('a', 64), 'path' => 'verified-fixture-archive.csv',
                'manifest' => ['row_count' => 123000, 'first_candle_at' => '2005-01-03T00:00:00Z',
                    'last_candle_at' => '2025-12-31T23:00:00Z']]));

        return $lab;
    }

    public function test_actual_constructor_creates_six_clean_new_original_references_not_legacy_pairs(): void
    {
        $this->ready();
        config()->set('services.lab_selection.constructor_initial_seat_budget', 3);
        $population = app(LabPopulationService::class);
        $generation = $population->build('XAUUSD', 'historical_research', false, 'H1', [], false,
            false, null, null, false, null, $this->intent());
        $this->assertNotNull($generation, json_encode($population->lastBuildOutcome()));
        $this->assertSame(3, $generation->agents()->count());
        $firstIds = $generation->agents()->orderBy('id')->pluck('id')->all();
        $continuation = $population->continueInterruptedConstruction((int) $generation->id, 3);
        $this->assertSame([], $continuation['failures'] ?? null, json_encode($continuation));
        $this->assertCount(3, $continuation['created_slots'] ?? []);
        $generation->refresh();
        $diagnostics = json_encode($generation->trigger_context);
        $this->assertSame(6, (int) $generation->population_size);
        $agents = $generation->agents()->with('modelVersion')->orderBy('id')->get();
        $this->assertCount(6, $agents, $diagnostics);
        $this->assertSame($firstIds, $agents->take(3)->pluck('id')->all());
        $this->assertSame('draft', $generation->status, $diagnostics);
        $this->assertCount(6, $agents->pluck('model_version_id')->unique());
        $this->assertCount(6, (array) data_get($generation->trigger_context, 'generation_plan'));
        $intent = data_get($generation->trigger_context, 'native_specialist_council_intent');
        $hash = $intent['intent_hash'];
        unset($intent['intent_hash']);
        $this->assertSame(app(ResearchPaperEpochContractService::class)->parameterHash($intent), $hash);
        $this->assertSame('research_only', $intent['authority']);
        $this->assertTrue($intent['requires_atomic_preparation']);
        $this->assertFalse($intent['independent_evidence_claimed']);
        $this->assertFalse($intent['promotion_evidence']);
        $expectedRoles = ['source_scalp', 'source_hour', 'source_day', 'source_swing', 'candidate_carrier', 'ablation_carrier'];
        foreach ($agents as $index => $agent) {
            $this->assertSame('draft', $agent->lifecycle_status);
            $this->assertSame('native_council_root', $agent->origin);
            $this->assertNull($agent->parent_a_model_version_id);
            $this->assertNull($agent->parent_b_model_version_id);
            $this->assertSame([], $agent->parameter_diff);
            $metadata = (array) $agent->modelVersion->metadata;
            $this->assertSame($hash, data_get($metadata, 'native_specialist_council_seed.intent_hash'));
            $this->assertSame((int) $generation->id, data_get($metadata, 'native_specialist_council_seed.lab_generation_id'));
            $this->assertSame($expectedRoles[$index], data_get($metadata, 'native_specialist_council_seed.slot_role'));
            $this->assertFalse(data_get($metadata, 'native_specialist_council_seed.qualified_specialist'));
            $this->assertSame('schema_defaults', data_get($metadata, 'parent_mentor_broker.parameter_baseline_source'));
            $this->assertNotEmpty($agent->modelVersion->parameters);
            foreach (['control_pair_contract', 'cooperative_experiment_block', 'causal_learning_cohort',
                'prospective_repair', 'academy_experiment', 'activation_factorial', 'phase_scope_probe',
                'specialist_council', 'specialist_council_evaluation', 'last_result', 'last_screen_result'] as $owner) {
                $this->assertEmpty(data_get($metadata, $owner), $owner);
            }
        }
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('specialist_council_versions', 0);
        $this->assertDatabaseCount('lab_generations', 2);
    }

    public function test_bad_native_intents_fail_before_constructor_lock_or_authority_or_data_access(): void
    {
        $population = app(LabPopulationService::class);
        Cache::shouldReceive('lock')->never();
        $this->mock(AutonomousModeService::class, fn ($mock) => $mock->shouldReceive('enabled')->never());
        $declaration = ['specialist_id' => 'day', 'exact_context' => ['regime' => 'trend_up', 'volatility' => 'normal', 'session' => 'london',
            'venue_phase' => 'london_interfix', 'direction' => 'BUY'], 'spread_context_predicate' => 'normal'];
        $cases = [
            [array_replace($this->intent(), ['population_size' => 4]), 'XAUUSD', 'historical_research', false, 'H1'],
            [array_replace($this->intent(), ['purpose' => 'paper']), 'XAUUSD', 'historical_research', false, 'H1'],
            [[...$this->intent(), 'promotion_evidence' => true], 'XAUUSD', 'historical_research', false, 'H1'],
            [$this->intent(), 'XAUUSD', 'new_data', false, 'H1'],
            [$this->intent(), 'XAUUSD', 'historical_research', true, 'H1'],
            [$this->intent(), 'XAUUSD', 'historical_research', false, 'M15'],
            [$this->intent(), 'EURUSD', 'historical_research', false, 'H1'],
            [array_replace($this->intent(), ['research_question' => '']), 'XAUUSD', 'historical_research', false, 'H1'],
            [[...$this->intent(), 'research_purpose' => 'spread_context_study'], 'XAUUSD', 'historical_research', false, 'H1'],
            [[...$this->intent(), 'research_purpose' => 'unknown'], 'XAUUSD', 'historical_research', false, 'H1'],
            [[...$this->intent(), 'study_context_declaration' => []], 'XAUUSD', 'historical_research', false, 'H1'],
            [[...$this->intent(), 'research_purpose' => 'spread_context_study', 'study_context_declaration' => $declaration], 'XAUUSD', 'historical_research', false, 'H1'],
            [[...$this->intent(), 'research_purpose' => 'spread_context_study', 'study_context_declaration' => [...$declaration,
                'liquidity_atr_binding' => 'arbitrary_runtime_field']], 'XAUUSD', 'historical_research', false, 'H1'],
        ];
        foreach ($cases as [$intent, $symbol, $trigger, $force, $timeframe]) {
            $this->assertNull($population->build($symbol, $trigger, $force, $timeframe, [], false,
                false, null, null, false, null, $intent));
            $this->assertSame('NATIVE_COUNCIL_CONSTRUCTOR_INTENT_INVALID', $population->lastBuildOutcome()['reason_code']);
        }
        $this->assertDatabaseCount('lab_generations', 0);
        $this->assertDatabaseCount('model_versions', 0);
    }

    public function test_actual_study_constructor_and_continuation_preserve_six_original_roles_and_pristine_day_carriers(): void
    {
        $this->ready(); config()->set('services.lab_selection.constructor_initial_seat_budget', 3);
        $population = app(LabPopulationService::class);
        $intent = [...$this->intent(), 'research_purpose' => 'spread_context_study', 'study_context_declaration' => [
            'specialist_id' => 'day', 'exact_context' => ['regime' => 'trend_up', 'volatility' => 'normal', 'session' => 'london',
                'venue_phase' => 'london_interfix', 'direction' => 'BUY'], 'spread_context_predicate' => 'normal',
                'liquidity_atr_binding' => 'closed_m5_management_atr_v1']];
        $generation = $population->build('XAUUSD', 'historical_research', false, 'H1', [], false, false, null, null, false, null, $intent);
        $this->assertNotNull($generation, json_encode($population->lastBuildOutcome()));
        $this->assertSame(3, $generation->agents()->count());
        $continuation = $population->continueInterruptedConstruction($generation->id, 3);
        $this->assertSame([], $continuation['failures'] ?? null, json_encode($continuation));
        $generation->refresh();
        $agents = $generation->agents()->with('modelVersion')->orderBy('id')->get();
        $this->assertSame(['source_scalp', 'source_hour', 'source_day', 'source_swing', 'study_masked_carrier', 'study_unmasked_carrier'],
            $agents->map(fn ($agent) => data_get($agent->modelVersion->metadata, 'native_specialist_council_seed.slot_role'))->all());
        $proof = app(\App\Services\SpecialistCouncilPreparationService::class)->assertOriginalSpreadStudyCarriers($generation, $agents[4], $agents[5]);
        $this->assertSame('spread_context_study', $proof['research_purpose']);
        $this->assertCount(6, $proof['constructor_episode_ids']);
        $this->assertCount(4, $proof['source_ids']);
        $this->assertSame($agents[2]->modelVersion->parameters, $agents[4]->modelVersion->parameters);
        $this->assertSame($agents[2]->modelVersion->parameters, $agents[5]->modelVersion->parameters);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('specialist_council_evaluation_plans', 0);
    }

    public function test_native_intent_cannot_bypass_shared_technical_debt_or_existing_generation_owner(): void
    {
        $lab = $this->ready(true);
        $population = app(LabPopulationService::class);
        $this->assertNull($population->build('XAUUSD', 'historical_research', false, 'H1', [], false,
            false, null, null, false, null, $this->intent()));
        $this->assertSame('GENERATION_ADMISSION_RECOVER_TECHNICAL', $population->lastBuildOutcome()['reason_code']);
        $lab->generations()->first()->update(['status' => 'draft']);
        $this->assertNull($population->build('XAUUSD', 'historical_research', false, 'H1', [], false,
            false, null, null, false, null, $this->intent()));
        $this->assertDatabaseCount('lab_generations', 1);
        $this->assertDatabaseCount('model_versions', 0);
    }

    public function test_ordinary_bounded_constructor_still_uses_its_existing_pair_owner(): void
    {
        $this->ready();
        $population = app(LabPopulationService::class);
        $generation = $population->build('XAUUSD', 'historical_research', false, 'H1', [], false, false, 2);
        $this->assertNotNull($generation, json_encode($population->lastBuildOutcome()));
        $this->assertSame(2, (int) $generation->population_size);
        $this->assertNull(data_get($generation->trigger_context, 'native_specialist_council_intent'));
        $this->assertNotEmpty(data_get($generation->trigger_context, 'control_pairing_contract'));
        $this->assertNotEmpty($generation->agents()->with('modelVersion')->get()->first()->modelVersion->metadata['control_pair_contract'] ?? null);
    }
}
