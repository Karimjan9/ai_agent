<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Models\ParentCandidatePreparation;
use App\Services\ParentCandidatePreparationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParentCandidatePreparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_strict_council_pre_pass_candidates_receive_bounded_ideas(): void
    {
        [$model, $agent] = $this->candidate();
        $performance = ModelMarketPerformance::create([
            'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'trend',
            'status' => 'challenger', 'evidence_status' => 'valid',
            'sample_count' => 40, 'rolling_windows_count' => 3, 'rolling_forward_wins' => 3,
            'metrics' => [
                'profit_factor' => 1.4, 'max_drawdown_percent' => 10, 'is_overfit' => false,
                'monte_carlo' => ['risk_of_ruin_percent' => 5],
                'behavioral_diversity' => ['status' => 'distinct'],
            ],
        ]);

        $result = app(ParentCandidatePreparationService::class)->prepare('XAUUSD', 'H1', 20, true);

        $this->assertSame(1, $result['candidate_count']);
        $this->assertSame(2, $result['ideas']);
        $this->assertSame(2, ParentCandidatePreparation::count());
        $this->assertSame($model->id, $agent->fresh()->model_version_id);
        $this->assertFalse((bool) ParentCandidatePreparation::query()->where('model_version_id', $model->id)->value('promotion_evidence'));
        $this->assertDatabaseHas('lab_parent_candidate_preparations', [
            'model_version_id' => $model->id,
            'status' => 'planned',
            'idea_type' => 'parent_counterfactual_reproduction',
            'promotion_evidence' => false,
        ]);
        $this->assertNotNull($performance->fresh());
    }

    public function test_non_council_or_weak_agent_is_not_prepared(): void
    {
        $model = ModelVersion::create([
            'name' => 'non-council', 'strategy' => 'trend', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'metadata' => [],
        ]);
        ModelMarketPerformance::create([
            'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'trend',
            'status' => 'challenger', 'evidence_status' => 'valid',
            'sample_count' => 100, 'rolling_windows_count' => 5, 'rolling_forward_wins' => 5,
            'metrics' => ['profit_factor' => 1.8, 'max_drawdown_percent' => 5, 'is_overfit' => false],
        ]);

        $result = app(ParentCandidatePreparationService::class)->prepare('XAUUSD', 'H1', 20, true);

        $this->assertSame(0, $result['candidate_count']);
        $this->assertSame(0, $result['ideas']);
        $this->assertSame(0, ParentCandidatePreparation::count());
    }

    /** @return array{0: ModelVersion, 1: LabAgent} */
    private function candidate(): array
    {
        $lab = AiLaboratory::create([
            'symbol' => 'XAUUSD', 'name' => 'XAUUSD Lab', 'timeframe' => 'H1',
            'strategy_families' => ['trend'], 'is_active' => true,
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test',
            'data_fingerprint' => str_repeat('a', 64), 'population_size' => 1, 'status' => 'screened',
        ]);
        $model = ModelVersion::create([
            'name' => 'council-candidate', 'strategy' => 'trend', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing',
            'metadata' => ['role_complete_council' => ['role' => 'trend_specialist']],
            'evidence_status' => 'valid',
        ]);
        $agent = LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'trend',
            'origin' => 'council', 'lifecycle_status' => 'screened',
            'parameter_diff' => ['minimum_confidence' => ['old' => .8, 'new' => .9]],
        ]);

        return [$model, $agent];
    }
}
