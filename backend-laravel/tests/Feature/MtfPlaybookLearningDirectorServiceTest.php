<?php

namespace Tests\Feature;

use App\Models\MtfPlaybookFrozenControlRun;
use App\Services\AgentResearchPlaybookToolboxService;
use App\Services\MtfPlaybookFrozenControlService;
use App\Services\MtfPlaybookLearningDirectorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery as m;
use Tests\TestCase;

class MtfPlaybookLearningDirectorServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_promising_small_sample_gets_more_data_before_any_gate_relaxation(): void
    {
        $identity = ['python_runtime_hash' => str_repeat('a', 64), 'runner_contract_hash' => str_repeat('b', 64)];
        $runner = m::mock(MtfPlaybookFrozenControlService::class);
        $runner->shouldReceive('currentIdentity')->once()->andReturn($identity);
        $toolbox = m::mock(AgentResearchPlaybookToolboxService::class);
        $toolbox->shouldNotReceive('nextFrozenPriorTrial');
        $director = new MtfPlaybookLearningDirectorService($toolbox, $runner);
        $base = $this->runRow('promising', 'confirmation_breakout_retest', [
            ...$identity,
            'candidate_variant' => ['id' => 'frozen_default', 'variant_class' => 'scout'],
            'control' => ['total_trades' => 10, 'profit_factor' => .61, 'net_profit_percent' => -1.38, 'max_drawdown_percent' => 2.28],
            'candidate' => ['total_trades' => 3, 'profit_factor' => 4.2, 'net_profit_percent' => 2.5, 'max_drawdown_percent' => .8],
            'power' => ['minimum_trades_per_arm' => 8],
            'learning_directive' => ['status' => 'ready'],
        ], ['bounded_entry_rows' => 10000]);

        $next = $director->nextTrial('XAUUSD', ['confirmation_breakout_retest']);

        $this->assertSame('promising_underpowered_evidence_expansion', $next['trial_type']);
        $this->assertSame([], $next['candidate_overrides']);
        $this->assertSame($base->id, data_get($next, 'trial_context.source_run_id'));
        $this->assertSame(20000, data_get($next, 'trial_context.evidence_budget_rows'));
        $this->assertStringContainsString('without_gate_relaxation', (string) data_get($next, 'trial_context.reason'));
        $this->assertFalse($next['promotion_evidence']);
    }

    public function test_measured_confirmation_bottleneck_yields_one_single_gene_repair_then_advances(): void
    {
        $identity = ['python_runtime_hash' => str_repeat('a', 64), 'runner_contract_hash' => str_repeat('b', 64)];
        $runner = m::mock(MtfPlaybookFrozenControlService::class);
        $runner->shouldReceive('currentIdentity')->twice()->andReturn($identity);
        $toolbox = m::mock(AgentResearchPlaybookToolboxService::class);
        $toolbox->shouldReceive('nextFrozenPriorTrial')->once()->andReturn([
            'status' => 'ready', 'tool' => ['id' => 'confirmation_breakout_retest'],
        ]);
        $director = new MtfPlaybookLearningDirectorService($toolbox, $runner);
        $base = $this->runRow('base', 'confirmation_trend_continuation', [
            ...$identity,
            'candidate_variant' => ['id' => 'frozen_default'],
            'learning_directive' => ['status' => 'ready'],
        ]);

        $repair = $director->nextTrial('XAUUSD', [
            'confirmation_trend_continuation', 'confirmation_breakout_retest',
        ]);

        $this->assertSame('bounded_confirmation_activity_repair', $repair['trial_type']);
        $this->assertSame(['minimum_independent_confirmations' => 2], $repair['candidate_overrides']);
        $this->assertSame($base->id, data_get($repair, 'trial_context.source_run_id'));
        $this->assertSame('one_repair_only_then_advance_model', data_get($repair, 'trial_context.stopping_rule'));
        $this->assertFalse($repair['promotion_evidence']);

        $this->runRow('repair', 'confirmation_trend_continuation', [
            ...$identity,
            'candidate_variant' => [
                'id' => 'min_independent_confirmations_2', 'source_run_id' => $base->id,
            ],
            'learning_directive' => ['status' => 'bounded_repair_terminal'],
        ]);
        $next = $director->nextTrial('XAUUSD', [
            'confirmation_trend_continuation', 'confirmation_breakout_retest',
        ]);

        $this->assertSame('frozen_default', $next['trial_type']);
        $this->assertSame('confirmation_breakout_retest', $next['model_id']);
        $this->assertSame([], $next['candidate_overrides']);
    }

    private function runRow(string $key, string $modelId, array $comparison, array $manifest = []): MtfPlaybookFrozenControlRun
    {
        return MtfPlaybookFrozenControlRun::create([
            'run_key' => hash('sha256', $key),
            'protocol' => MtfPlaybookFrozenControlService::PROTOCOL,
            'research_model_id' => $modelId,
            'symbol' => 'XAUUSD', 'entry_timeframe' => 'M5',
            'data_hash' => str_repeat('c', 64), 'execution_hash' => str_repeat('d', 64),
            'control_parameter_hash' => str_repeat('e', 64), 'candidate_parameter_hash' => str_repeat('f', 64),
            'status' => 'completed', 'required_streams' => ['H4', 'H1', 'M15', 'M5'],
            'dataset_manifest' => $manifest, 'comparison' => $comparison, 'reason_codes' => [],
            'promotion_evidence' => false, 'completed_at' => now(),
        ]);
    }
}
