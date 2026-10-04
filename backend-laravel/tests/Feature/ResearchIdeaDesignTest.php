<?php

namespace Tests\Feature;

use App\Services\ResearchIdeaInboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResearchIdeaDesignTest extends TestCase
{
    use RefreshDatabase;

    public function test_identity_includes_context_block_design_and_stopping_rule(): void
    {
        $service = app(ResearchIdeaInboxService::class);
        $base = ['title' => 'Bounded wait', 'hypothesis' => 'Wait changes activation in this phase.',
            'bounded_genes' => [['key' => 'time_stop_candles', 'minimum' => 3, 'maximum' => 12]],
            'context_scope' => ['venue_phase' => 'london_interfix', 'volatility' => 'normal']];
        $a = $service->submit($base);
        $same = $service->submit([...$base, 'context_scope' => ['volatility' => 'normal', 'venue_phase' => 'london_interfix']]);
        $this->assertSame($a['entry_id'], $same['entry_id']);
        $keys = [$a['idea_key'], $service->submit([...$base, 'context_scope' => ['venue_phase' => 'comex_open']])['idea_key'],
            $service->submit([...$base, 'required_block_type' => 'repair_pair'])['idea_key'],
            $service->submit([...$base, 'stopping_rule' => 'reserved_other_design'])['idea_key']];
        $this->assertCount(4, array_unique($keys));
        $this->assertDatabaseCount('research_idea_inbox_entries', 4);
    }

    public function test_design_match_requires_exact_type_context_and_real_bounded_intervention(): void
    {
        $service = app(ResearchIdeaInboxService::class);
        $service->submit(['title' => 'Repair wait', 'hypothesis' => 'Longer wait repairs this phase.',
            'bounded_genes' => [['key' => 'time_stop_candles', 'minimum' => 3, 'maximum' => 12]],
            'required_block_type' => 'repair_pair', 'context_scope' => ['volatility' => 'normal_volatility']]);
        $idea = $service->ready('XAUUSD', 'H1')[0];
        $this->assertTrue($service->designMatches($idea, 'repair_pair', ['volatility' => 'normal'], ['gene' => 'time_stop_candles', 'value' => 5]));
        $this->assertFalse($service->designMatches($idea, 'novelty_pair', ['volatility' => 'normal'], ['gene' => 'time_stop_candles', 'value' => 5]));
        $this->assertFalse($service->designMatches($idea, 'repair_pair', ['volatility' => 'high'], ['gene' => 'time_stop_candles', 'value' => 5]));
        $this->assertFalse($service->designMatches($idea, 'repair_pair', ['volatility' => 'normal'], ['gene' => 'lookback', 'value' => 5]));
        $this->assertFalse($service->designMatches($idea, 'repair_pair', ['volatility' => 'normal'], ['gene' => 'time_stop_candles', 'value' => 20]));
        $this->assertFalse($service->designMatches($idea, 'repair_pair', ['volatility' => 'normal'], ['gene' => 'time_stop_candles']));
        $this->assertTrue($service->assign($idea['id'], 'first-block'));
        $this->assertFalse($service->assign($idea['id'], 'other-block'));
    }
}
