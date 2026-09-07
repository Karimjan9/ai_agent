<?php

namespace Tests\Feature;

use App\Models\ResearchExperimentReceipt;
use App\Services\ResearchExperimentConversionKernelService;
use App\Services\ResearchKnowledgePortfolioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResearchKnowledgePortfolioServiceTest extends TestCase
{
    use RefreshDatabase;

    private function contract(): array
    {
        return [
            'contract_version' => ResearchExperimentConversionKernelService::CONTRACT_VERSION,
            'source' => ['type' => 'portfolio_fixture', 'id' => 801],
            'scope' => ['symbol' => 'XAUUSD', 'laboratory_timeframe' => 'H1', 'execution_timeframe' => 'M5'],
            'claim' => ['target_stage' => 'entry', 'hypothesis' => 'fixture hypothesis'],
            'identity' => ['baseline_epoch_hash' => 'baseline', 'data_and_mtf_hash' => 'data', 'runtime_and_contract_hash' => 'runtime', 'intervention_hash' => 'intervention', 'window_plan_hash' => 'window', 'evaluator_version' => 'v1'],
            'arms' => [['role' => 'frozen_control'], ['role' => 'candidate']],
            'revisions' => ['subject' => 1, 'evidence' => 1],
        ];
    }

    public function test_terminal_receipt_projects_into_research_only_civilization_memory(): void
    {
        app(ResearchExperimentConversionKernelService::class)->record(
            $this->contract(), ['settlement_id' => 1], 'POSITIVE_CANDIDATE', ['type' => 'replication', 'identity' => 'fixture'],
        );

        $receipt = ResearchExperimentReceipt::query()->sole();
        $this->assertDatabaseHas('research_knowledge_entries', [
            'subject_key' => $receipt->receipt_key,
            'knowledge_type' => 'CAUSAL',
            'authority' => 'research_only',
        ]);
    }

    public function test_portfolio_recommendations_require_confirmed_cartridge_and_never_enable_live_routing(): void
    {
        $service = app(ResearchKnowledgePortfolioService::class);
        $blocked = $service->proposePortfolioEntry(17, ['id' => 9, 'status' => 'candidate'], [], []);
        $planned = $service->proposePortfolioEntry(17, [
            'id' => 9, 'status' => 'confirmed', 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'evidence' => ['effect_vector' => ['setup_density' => 0.7], 'secondary' => ['mfe_capture_delta' => 0.2]],
        ], ['evidence_cells' => ['entry' => 'KNOWN']], ['known_limits' => ['thin data']]);
        $recommendation = $service->recommend(17, ['setup_density' => 0.6]);

        $this->assertSame('blocked', $blocked['status']);
        $this->assertSame('paired_transplant_required', $planned['status']);
        $this->assertSame('research_recommendation', $recommendation['status']);
        $this->assertFalse($recommendation['live_router_allowed']);
        $this->assertFalse($recommendation['promotion_evidence']);
    }
}
