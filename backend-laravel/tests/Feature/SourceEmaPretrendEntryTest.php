<?php

namespace Tests\Feature;

use App\Models\AiLaboratory;
use App\Models\LabGeneration;
use App\Services\LabAgentEvaluationService;
use App\Services\LabPopulationService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Conditional constructor/actual programme software evidence, not an economic certificate. */
class SourceEmaPretrendEntryTest extends TestCase
{
    use RefreshDatabase;

    private const VARIANT = 'source_ema_pretrend_pullback_v1';

    public function test_default_choice_pool_is_unchanged_but_explicit_fixed_trend_is_valid(): void
    {
        $schema = app(StrategyParameterSchemaService::class);
        $this->assertSame(['frozen', 'regime_consensus_v1', 'transition_hazard_v1', 'breakout_retest_v1',
            'trend_regime_confirmation_v1', 'range_reentry_confirmation_v1', 'volatility_persistence_v1'],
            $schema->schema('trend')['entry_topology_variant'][1]);
        $this->assertNotContains(self::VARIANT, $schema->schema('trend')['entry_topology_variant'][1]);
        $this->assertContains(self::VARIANT, $schema->schema('trend', true)['entry_topology_variant'][1]);
        $this->assertSame('frozen', $schema->defaults('trend')['entry_topology_variant']);
        $parameters = [...$schema->defaults('trend'), 'ema_fast' => 20, 'ema_slow' => 50,
            'entry_topology_variant' => self::VARIANT];
        $this->assertSame($parameters, $schema->validate('trend', $parameters));
        $canonical = $parameters;
        ksort($canonical);
        $this->assertSame($canonical, $schema->canonicalizeForIdentity('trend', $parameters));
    }

    public function test_explicit_fixed_programme_rejects_wrong_family_and_periods(): void
    {
        $schema = app(StrategyParameterSchemaService::class);
        foreach ([['trend', 21, 50], ['trend', 20, 51], ['ema_rsi', 20, 50]] as [$family, $fast, $slow]) {
            try {
                $schema->validate($family, [...array_intersect_key($schema->defaults($family), $schema->schema($family)),
                    'ema_fast' => $fast, 'ema_slow' => $slow, 'entry_topology_variant' => self::VARIANT]);
                $this->fail('An incompatible programme must not be silently repaired.');
            } catch (\InvalidArgumentException $error) {
                $this->assertSame('SOURCE_EMA_PRETREND_FIXED_TREND_20_50_REQUIRED', $error->getMessage());
            }
        }
    }

    public function test_actual_fresh_factory_and_native_compiler_preserve_explicit_three_gene_programme(): void
    {
        config()->set('services.mtf_pilot.enabled', false);
        $lab = AiLaboratory::create(['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'name' => 'Closed EMA source entry',
            'strategy_families' => ['trend'], 'is_active' => false]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1,
            'trigger_type' => 'historical_research', 'population_size' => 1, 'status' => 'draft', 'trigger_context' => []]);
        $values = ['ema_fast' => 20, 'ema_slow' => 50, 'entry_topology_variant' => self::VARIANT];
        $niche = ['structural_research' => true, 'hybrid_evolution_lane' => 'bold_structural',
            'structural_hypothesis_id' => 'fixed-source-ema-pretrend-entry-v1',
            'declared_genes' => array_keys($values), 'declared_values' => $values,
            'entry_topology_variant' => self::VARIANT];
        $population = app(LabPopulationService::class);
        $create = new \ReflectionMethod($population, 'createAgent');
        $failure = null;
        $arguments = [$generation, 'trend', 'architecture', 1, 'entry_topology', $niche, null, null, 0, &$failure];
        $this->assertTrue($create->invokeArgs($population, $arguments), (string) $failure);
        $agent = $generation->agents()->with('modelVersion')->sole();
        $model = $agent->modelVersion;
        $schema = app(StrategyParameterSchemaService::class);
        $this->assertSame($model->parameters, $schema->validate('trend', $model->parameters));
        $this->assertSame($values, array_intersect_key($model->parameters, $values));
        $this->assertEqualsCanonicalizing(array_keys($values), array_keys($agent->parameter_diff));
        $this->assertSame(3, data_get($model->metadata, 'mutation_constructor_invariant.parameter_diff_count'));
        $this->assertFalse(data_get($model->metadata, 'mutation_constructor_invariant.architecture_changed'));
        $this->assertSame(self::VARIANT, data_get($model->metadata, 'mutation_constructor_invariant.entry_topology_variant'));
        $fingerprint = new \ReflectionMethod($population, 'parameterFingerprint');
        $this->assertSame($fingerprint->invoke($population, 'trend', $model->parameters),
            data_get($model->metadata, 'parameter_fingerprint'));
        $this->assertNotSame($fingerprint->invoke($population, 'trend', $schema->defaults('trend')),
            data_get($model->metadata, 'parameter_fingerprint'));

        // The real normal member compiler reopens the freshly constructed model;
        // no hand-written Python-only parameters stand in for that boundary.
        $native = app(LabAgentEvaluationService::class)->specialistCouncilMemberPayload($model, 'M5', null, str_repeat('d', 64), 'XAUUSD');
        $this->assertSame($model->parameters, $native['parameters']);
        $this->assertSame('trend_v1', $native['base_strategy']);
        $member = [...$native, 'specialist_id' => 'source-hour', 'role' => 'hour', 'version' => $model->version,
            'strategy_version' => 'closed-ema-pretrend-entry-v1', 'tactic_version' => 'existing-adx-rsi',
            'management_version' => 'unchanged', 'capital_weight' => 1.0, 'risk_per_trade_percent' => .5,
            'horizon' => ['decision_interval_bars' => 1, 'reevaluation_interval_bars' => 1, 'max_holding_bars' => 12]];
        $request = ['strategy' => 'portfolio_v1', 'base_strategy' => 'portfolio', 'timeframe' => 'M5',
            'symbol' => 'XAUUSD', 'replay_dataset_hash' => str_repeat('d', 64)];
        $pythonRoot = dirname(base_path()).DIRECTORY_SEPARATOR.'ai-service-python';
        $process = new Process(['python', 'tests/test_source_ema_pretrend_entry.py', '--native-json'], $pythonRoot,
            ['PYTHONPATH' => $pythonRoot], json_encode(['request' => $request, 'member' => $member], JSON_THROW_ON_ERROR), 60);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        $actual = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($schema->canonicalizeForIdentity('trend', $model->parameters),
            $schema->canonicalizeForIdentity('trend', $actual['parameters']));
        $this->assertSame($fingerprint->invoke($population, 'trend', $model->parameters),
            $fingerprint->invoke($population, 'trend', $actual['parameters']));
        $this->assertSame('closed_ema_pretrend_entry_v1', $actual['entry_protocol']);
        $this->assertSame('BUY', $actual['entry_direction']);
        $this->assertSame('BUY', $actual['signal']);
        $this->assertTrue($actual['entry_available']);
        $this->assertFalse($actual['independent_qualification']);
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('paper_orders', 0);
        $this->assertSame('draft', $generation->fresh()->status);
    }
}
