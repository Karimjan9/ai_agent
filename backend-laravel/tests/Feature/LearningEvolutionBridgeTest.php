<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\EvolutionLearningReceipt;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\ClosedLoopGenerationAuditService;
use App\Services\ContextualLearningMemoryService;
use App\Services\EvolutionDirectorService;
use App\Services\InheritanceEnforcerService;
use App\Services\LearningCompilerService;
use App\Services\OpportunityFunnelLedgerService;
use App\Services\TradePathLaboratoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LearningEvolutionBridgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_raw_trade_path_and_wait_observations_become_non_promotable_receipts(): void
    {
        $path = app(TradePathLaboratoryService::class)->settle(['entry_price' => 2300], ['fixed_target' => ['net_r' => 1], 'm5_trailing' => ['net_r' => 1.6]], ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'strategy_family' => 'trend']);
        $ledger = app(OpportunityFunnelLedgerService::class);
        $ledger->record(['opportunity_key' => 'wait-bridge', 'symbol' => 'XAUUSD', 'timeframe' => 'M5', 'checks' => ['setup_detected' => false]]);
        $ledger->settleShadowOutcome('wait-bridge', ['net_r' => -0.4]);

        $this->assertSame('observed', $path['learning_receipt']['status']);
        $memory = app(ContextualLearningMemoryService::class)->retrieve('XAUUSD', 'M5', ['strategy_family' => 'trend'], ['observed']);
        $this->assertCount(2, $memory);
        $this->assertFalse($path['learning_receipt']['contract']['canonical_memory_eligible']);
    }

    public function test_confirmed_contextual_receipt_changes_director_from_explore_to_exploit(): void
    {
        $receipt = $this->confirmedReceipt('atr_stop_multiplier', 1.2, 1.4, [
            'strategy_family' => 'hybrid', 'regime' => 'trend_up', 'session' => 'london',
        ]);
        $plan = collect(range(1, 20))->map(fn (): array => ['family' => 'hybrid', 'niche' => [
            'regime' => 'trend_up', 'session' => 'london',
            'declared_gene' => 'atr_stop_multiplier', 'declared_value' => 1.4,
        ]])->all();
        $directed = app(EvolutionDirectorService::class)->materialize($plan, 'XAUUSD', 'H1', 103);

        $this->assertSame('confirmed', $receipt->status);
        $this->assertSame(['exploit' => 8, 'repair' => 5, 'explore' => 4, 'falsification' => 3], $directed['contract']['budget']);
        $this->assertSame('exploit', $directed['plan'][0]['niche']['learning_evolution']['experiment_role']);
        $this->assertSame([$receipt->id], $directed['plan'][0]['niche']['learning_evolution']['consumed_receipt_ids']);
        $this->assertSame('atr_stop_multiplier', $directed['plan'][0]['niche']['learning_evolution']['required_gene']);
    }

    public function test_unattested_array_cannot_manufacture_a_confirmed_receipt(): void
    {
        $receipt = app(LearningCompilerService::class)->compileCanonical([
            'source_key' => 'forged', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'parameter_key' => 'atr_stop_multiplier', 'causal_uplift_r' => .18,
            'independent_windows' => 3, 'positive_windows' => 2,
        ]);

        $this->assertSame('rejected', $receipt['status']);
        $this->assertContains('VERIFIED_CONTROL_PAIR_REQUIRED', $receipt['reason_codes']);
        $this->assertDatabaseCount('evolution_learning_receipts', 0);
    }

    public function test_inheritance_manifest_is_sealed_and_generation_cannot_close_before_evidence_loop(): void
    {
        $lab = AiLaboratory::create(['symbol' => 'XAUUSD', 'name' => 'Bridge', 'timeframe' => 'H1', 'strategy_families' => ['hybrid'], 'is_active' => true, 'lifecycle_mode' => 'lighthouse']);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test', 'population_size' => 1, 'status' => 'draft', 'data_fingerprint' => 'bridge-data', 'trigger_context' => []]);
        $model = ModelVersion::create(['name' => 'bridge-child', 'strategy' => 'bridge-child', 'version' => 'v1', 'generation' => 1, 'status' => 'testing', 'parameters' => ['high_volatility_risk_multiplier' => .8]]);
        $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'test', 'lifecycle_status' => 'draft', 'parameter_diff' => ['high_volatility_risk_multiplier' => ['old' => 1, 'new' => .8]]]);
        $receipt = $this->confirmedReceipt('high_volatility_risk_multiplier', 1, .8);
        $sealed = app(InheritanceEnforcerService::class)->seal($agent, ['experiment_role' => 'exploit', 'consumed_receipt_ids' => [$receipt->id], 'inherited_components' => ['risk'], 'required_component' => 'risk', 'required_gene' => 'high_volatility_risk_multiplier', 'mutation_from' => 1, 'mutation_to' => .8, 'mutation_reason' => 'risk multiplier improves after-cost expectancy', 'control_pair_required' => true], $model->id, $agent->parameter_diff);
        $audit = app(ClosedLoopGenerationAuditService::class)->assess($generation);

        $this->assertSame('sealed', $sealed['status']);
        $this->assertFalse($audit['generation_may_close']);
        $this->assertSame(1.0, $audit['inheritance_manifest_coverage']);
    }

    private function confirmedReceipt(string $gene, mixed $old, mixed $new, array $scope = ['strategy_family' => 'hybrid']): EvolutionLearningReceipt
    {
        $source = 'test-confirmed-'.$gene.'-'.md5(json_encode([$old, $new, $scope]));

        return EvolutionLearningReceipt::create([
            'receipt_key' => hash('sha256', 'receipt-'.$source),
            'claim_key' => hash('sha256', 'claim-'.$source),
            'source_type' => 'test_canonical_projection', 'source_key' => $source,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'component' => str_contains($gene, 'risk') || str_contains($gene, 'stop') ? 'risk' : 'strategy_parameter',
            'action' => 'prefer', 'status' => 'confirmed', 'claim' => $gene.' confirmed by canonical fixture',
            'causal_uplift_r' => .18, 'confidence' => .9, 'support' => 146,
            'scope' => $scope, 'source_experiments' => [$source],
            'evidence' => ['input' => ['parameter_key' => $gene, 'old_value' => $old, 'new_value' => $new], 'canonical_fixture' => true, 'promotion_evidence' => false],
            'expires_at' => now()->addDays(30), 'compiled_at' => now(),
        ]);
    }
}
