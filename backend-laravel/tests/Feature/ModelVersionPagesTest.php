<?php

namespace Tests\Feature;

use App\Models\ModelVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelVersionPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_versions_index_lists_versions(): void
    {
        ModelVersion::create([
            'name' => 'macd_trend_v1',
            'strategy' => 'macd_trend_v1',
            'version' => 'v1',
            'generation' => 1,
            'status' => 'testing',
            'best_score' => 84,
            'best_winrate' => 61.2,
            'best_profit' => 22.4,
            'best_drawdown' => 6.2,
            'description' => 'MACD trend agent.',
            'change_log' => 'Initial version.',
            'parameters' => [],
            'metadata' => [],
        ]);

        $response = $this->get('/model-versions');

        $response->assertOk()
            ->assertSee('Model Versions')
            ->assertSee('MACD_TREND_V1')
            ->assertSee('v1')
            ->assertSee('84');
    }
}
