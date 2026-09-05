<?php

namespace Tests\Unit;

use App\Services\ResearchPlaybookConflictResolutionService;
use App\Services\StrategyResearchCatalogueService;
use PHPUnit\Framework\TestCase;

class ResearchPlaybookConflictResolutionServiceTest extends TestCase
{
    public function test_opposite_playbook_directions_fail_closed_to_wait(): void
    {
        $result = $this->service()->resolve([
            $this->proposal('liquidity_trap_mtf', 'BUY'),
            $this->proposal('turtle_soup_mtf', 'SELL'),
        ], ['selected_model_id' => 'liquidity_trap_mtf']);

        $this->assertSame('wait', $result['status']);
        $this->assertSame('WAIT', $result['selected_decision']);
        $this->assertSame('OPPOSITE_PLAYBOOK_DIRECTIONS', $result['reason_code']);
        $this->assertFalse($result['execution_authority']);
    }

    public function test_same_direction_from_multiple_models_is_not_an_implicit_ensemble(): void
    {
        $result = $this->service()->resolve([
            $this->proposal('liquidity_trap_mtf', 'BUY'),
            $this->proposal('ict_2022_raid_mss_fvg', 'BUY'),
        ], ['selected_model_id' => 'liquidity_trap_mtf']);

        $this->assertSame('wait', $result['status']);
        $this->assertSame('UNDECLARED_MODEL_BLEND', $result['reason_code']);
    }

    public function test_one_prerouted_playbook_can_create_only_a_candidate_setup(): void
    {
        $result = $this->service()->resolve([
            $this->proposal('london_judas_swing', 'SELL'),
        ], ['selected_model_id' => 'london_judas_swing']);

        $this->assertSame('candidate_setup', $result['status']);
        $this->assertSame('SELL', $result['selected_decision']);
        $this->assertSame('london_judas_swing', $result['selected_model_id']);
        $this->assertFalse($result['execution_authority']);
        $this->assertFalse($result['promotion_evidence']);
    }

    public function test_outputs_with_different_frozen_inputs_are_not_comparable(): void
    {
        $left = $this->proposal('liquidity_trap_mtf', 'BUY');
        $right = $this->proposal('turtle_soup_mtf', 'SELL');
        $right['data_hash'] = str_repeat('z', 64);
        $result = $this->service()->resolve([$left, $right], ['selected_model_id' => 'liquidity_trap_mtf']);

        $this->assertSame('wait', $result['status']);
        $this->assertSame('NON_COMPARABLE_MODEL_OUTPUTS', $result['reason_code']);
    }

    private function service(): ResearchPlaybookConflictResolutionService
    {
        return new ResearchPlaybookConflictResolutionService(new StrategyResearchCatalogueService());
    }

    /** @return array<string,string> */
    private function proposal(string $modelId, string $signal): array
    {
        return [
            'model_id' => $modelId,
            'signal' => $signal,
            'data_hash' => str_repeat('a', 64),
            'execution_hash' => str_repeat('b', 64),
        ];
    }
}
