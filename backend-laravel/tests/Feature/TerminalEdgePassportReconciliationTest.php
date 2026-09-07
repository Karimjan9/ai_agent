<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabGeneration;
use App\Services\DependencyAwareEdgeGenesisFoundryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TerminalEdgePassportReconciliationTest extends TestCase
{
    use RefreshDatabase;

    public function test_terminal_compiled_arms_close_only_the_stale_passport_projection(): void
    {
        $lab = AiLaboratory::create(['name' => 'terminal passport', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_families' => ['fixture']]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 18, 'trigger_type' => 'fixture', 'status' => 'completed']);
        $passportId = DB::table('edge_genesis_passports')->insertGetId([
            'genesis_key' => 'terminal-passport-fixture', 'lab_generation_id' => $generation->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'fixture', 'phase' => 'EDGE_DISCOVERY', 'status' => 'running',
            'data_hash' => 'data', 'execution_hash' => 'execution', 'context' => '{}', 'evidence' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (DependencyAwareEdgeGenesisFoundryService::COMPILED_HYPOTHESIS_ARMS as $index => $arm) {
            DB::table('edge_genesis_trials')->insert([
                'trial_key' => 'terminal-passport-fixture-'.$index, 'edge_genesis_passport_id' => $passportId,
                'packet_key' => 'fixture-packet', 'emitter' => 'fixture', 'arm' => $arm, 'stage' => 'two_fold_discovery',
                'status' => 'edge_not_found', 'evidence' => '{}', 'settled_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $edge = app(DependencyAwareEdgeGenesisFoundryService::class);
        $dryRun = $edge->reconcileTerminalPassportStates('XAUUSD', 'H1');
        $applied = $edge->reconcileTerminalPassportStates('XAUUSD', 'H1', true);

        $this->assertSame('would_reconcile', $dryRun['status']);
        $this->assertSame('reconciled', $applied['status']);
        $this->assertDatabaseHas('edge_genesis_passports', ['id' => $passportId, 'status' => 'edge_not_found']);
    }
}
