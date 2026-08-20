<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabEvolutionArchiveEntry;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Services\EvolutionArchiveService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BehavioralMapElitesArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_behavior_is_detected_even_when_the_model_is_new(): void
    {
        $lab = AiLaboratory::create(['symbol' => 'XAUUSD', 'name' => 'Archive lab', 'timeframe' => 'H1', 'strategy_families' => ['hybrid'], 'is_active' => true]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'trigger_type' => 'test', 'population_size' => 2, 'status' => 'screening']);
        $service = app(EvolutionArchiveService::class);
        $result = [
            'profit_factor' => 1.1, 'total_trades' => 20, 'event_ledger_hash' => 'same-events', 'trade_ledger_hash' => 'same-trades',
            'stress_test' => ['profit_factor' => .9],
            'entry_funnel' => ['accepted_entries' => 20, 'dominant_rejection' => 'none'],
            'pf_attribution' => ['breakdown' => ['by_regime' => ['range' => ['trades' => 20]]]],
        ];
        $first = $service->recordScreeningBehavior($this->agent($generation, 'first'), $result);
        $second = $service->recordScreeningBehavior($this->agent($generation, 'second'), $result);

        $this->assertSame('novel_behavior_cell', $first['status']);
        $this->assertSame('repeated_behavior_cell', $second['status']);
        $this->assertSame(2, LabEvolutionArchiveEntry::where('archive_type', 'behavioral_map_elites')->count());
    }

    private function agent(LabGeneration $generation, string $name): LabAgent
    {
        $model = ModelVersion::create([
            'name' => $name, 'strategy' => 'xauusd_hybrid_'.$name, 'version' => 'v1', 'generation' => 1,
            'status' => 'testing', 'parameters' => ['minimum_confidence' => 1],
            'metadata' => ['entry_topology_variant' => 'regime_consensus_v1'], 'evidence_status' => 'valid',
        ]);
        return LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'origin' => 'test', 'lifecycle_status' => 'screening', 'parameter_diff' => [],
        ]);
    }
}
