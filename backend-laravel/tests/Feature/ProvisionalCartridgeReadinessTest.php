<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\LabSkillZooEntry;
use App\Models\ModelVersion;
use App\Services\AutonomousLearningProgressDirectorService;
use App\Services\CausalStageMasteryDirectorService;
use App\Services\DependencyAwareEdgeGenesisFoundryService;
use App\Services\ProvisionalCartridgeReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProvisionalCartridgeReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_confident_but_unready_cartridges_do_not_create_debt_or_starve_exploration(): void
    {
        for ($index = 0; $index < 8; $index++) {
            LabSkillZooEntry::create(['skill_key' => 'unready-'.$index, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
                'strategy_family' => 'confirmation_entry_mtf', 'module_key' => 'entry', 'niche_key' => 'fixture',
                'gene_key' => 'minimum_confidence', 'status' => 'provisional', 'confidence' => .99, 'quality_score' => .9]);
        }
        $debt = app(CausalStageMasteryDirectorService::class)->promotionDebt('XAUUSD', 'H1');
        $this->assertSame(0, $debt['near_confirmable_cartridges']);
        $this->assertFalse($debt['consolidation_required']);
        $this->assertCount(0, app(ProvisionalCartridgeReadinessService::class)->readyEntries('XAUUSD', 'H1'));
        $selector = new \ReflectionMethod(AutonomousLearningProgressDirectorService::class, 'nextProvisionalCartridgeConfirmation');
        $this->assertNull($selector->invoke(app(AutonomousLearningProgressDirectorService::class), 'XAUUSD', 'H1'));
    }

    public function test_debt_and_director_share_exact_observation_delta_and_frozen_baseline_readiness(): void
    {
        $lab = AiLaboratory::create(['name' => 'readiness fixture', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_families' => ['confirmation_entry_mtf'], 'lifecycle_mode' => 'lighthouse']);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'fixture', 'status' => 'completed',
            'trigger_context' => ['canonical_dataset_snapshots' => ['price' => ['manifest' => ['sha256' => str_repeat('a', 64)]],
                'foundation' => ['manifest' => ['sha256' => str_repeat('a', 64)]]]]]);
        $model = ModelVersion::create(['name' => 'readiness baseline', 'strategy' => 'confirmation_entry_mtf', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => ['minimum_confidence' => .6]]);
        $baseline = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id, 'symbol' => 'XAUUSD',
            'timeframe' => 'H1', 'strategy_family' => 'confirmation_entry_mtf', 'origin' => 'fixture', 'lifecycle_status' => 'completed', 'parameter_diff' => []]);
        $entry = LabSkillZooEntry::create(['skill_key' => 'ready', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'confirmation_entry_mtf', 'module_key' => 'entry', 'niche_key' => 'fixture', 'gene_key' => 'minimum_confidence',
            'status' => 'provisional', 'confidence' => .8, 'quality_score' => .5, 'causal_baseline_agent_id' => $baseline->id,
            'evidence' => ['intervention' => ['old_value' => .6, 'tested_value' => .55],
                'provenance' => ['data_hashes' => [str_repeat('a', 64)], 'execution_hashes' => [str_repeat('b', 64)]]]]);
        foreach ([1, 2] as $index) DB::table('skill_cartridge_observations')->insert([
            'observation_key' => 'readiness-'.$index, 'lab_skill_zoo_entry_id' => $entry->id, 'outcome' => 'positive',
            'evidence' => '{}', 'observed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $readiness = app(ProvisionalCartridgeReadinessService::class);
        $this->assertTrue($readiness->inspect($entry)['ready']);
        $this->assertSame(1, app(CausalStageMasteryDirectorService::class)->promotionDebt('XAUUSD', 'H1')['near_confirmable_cartridges']);
        $selector = new \ReflectionMethod(AutonomousLearningProgressDirectorService::class, 'nextProvisionalCartridgeConfirmation');
        $this->assertSame($entry->id, $selector->invoke(app(AutonomousLearningProgressDirectorService::class), 'XAUUSD', 'H1')->id);
        $model->update(['parameters' => ['minimum_confidence' => .7]]);
        $this->assertFalse($readiness->inspect($entry->fresh())['ready']);
        $this->assertSame(0, app(CausalStageMasteryDirectorService::class)->promotionDebt('XAUUSD', 'H1')['near_confirmable_cartridges']);
        $this->assertNull($selector->invoke(app(AutonomousLearningProgressDirectorService::class), 'XAUUSD', 'H1'));
    }

    public function test_nested_requested_stage_is_not_mastery_without_a_controllable_identity_proof(): void
    {
        $method = new \ReflectionMethod(DependencyAwareEdgeGenesisFoundryService::class, 'academyStageFor');
        $foundry = app(DependencyAwareEdgeGenesisFoundryService::class);
        $failed = ['status' => 'assessed', 'assessment' => ['status' => 'non_controlling_axis', 'target_stage' => 'setup',
            'checks' => ['upstream_identity_preserved' => false, 'decision_identity_valid' => true]]];
        $this->assertSame('market_cartographer', $method->invoke($foundry, $failed));
        $passed = ['status' => 'assessed', 'assessment' => ['status' => 'controllable', 'target_stage' => 'setup',
            'checks' => ['upstream_identity_preserved' => true, 'decision_identity_valid' => true]]];
        $this->assertSame('confirmation_specialist', $method->invoke($foundry, $passed));
        unset($passed['assessment']['checks']['decision_identity_valid']);
        $this->assertSame('market_cartographer', $method->invoke($foundry, $passed));
    }

    public function test_prospective_foundry_packets_use_distinct_catalogue_backed_tactic_semantics(): void
    {
        $foundry = app(DependencyAwareEdgeGenesisFoundryService::class);
        $packets = (new \ReflectionMethod($foundry, 'packets'))->invoke($foundry);
        $variant = new \ReflectionMethod($foundry, 'tacticForArm');
        $tactics = app(\App\Services\TacticCatalogueService::class);
        $strategies = app(\App\Services\StrategyLibraryCompilerService::class);
        foreach ($packets as $packet) {
            $control = $tactics->for('hybrid', $packet['tactic_id']);
            $changed = $tactics->for('hybrid', $variant->invoke($foundry, $packet['tactic_id'], 'confirmation_tactic_change'));
            $this->assertNotEmpty($control['target_regimes']);
            $this->assertNotEmpty($changed['target_regimes']);
            $this->assertNotSame($control['tactic_id'], $changed['tactic_id']);
            $this->assertNotSame($control['entry_topology'], $changed['entry_topology']);
            $scope = $strategies->signalScope($strategies->runtimeBaseStrategy($packet['strategy_id']))['regimes'];
            $this->assertNotEmpty(array_intersect($scope, $control['target_regimes']));
            $this->assertNotEmpty(array_intersect($scope, $changed['target_regimes']));
        }
    }
}
