<?php

namespace Tests\Unit;

use App\Services\StrategyParameterSchemaService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 2).'/app/Services/StrategyParameterSchemaService.php';

/** Explicit laboratory API parity only; no replay, dispatch or application boot. */
class BollingerContextGateParityTest extends TestCase
{
    public function test_gate_is_explicit_only_and_never_enters_defaults_or_sampling(): void
    {
        $schema = new StrategyParameterSchemaService();
        foreach (['session_v1', 'session_mean_reversion_v1', 'trend_v1'] as $strategy) {
            $this->assertArrayNotHasKey('session_context_gate', $schema->schema($strategy));
            $this->assertArrayNotHasKey('session_context_gate', $schema->defaults($strategy));
            $this->assertArrayNotHasKey('session_context_gate', $schema->defaults($strategy, true));
        }
        $this->assertSame(['string', ['none', 'range_low_v1']],
            $schema->schema('session_mean_reversion_v1', true)['session_context_gate']);
        $this->assertSame($schema->defaults('session_v1'), $schema->defaults('session_mean_reversion_v1'));
        $this->assertSame($schema->schema('session_v1'), $schema->schema('session_mean_reversion_v1'));
        $this->assertSame([], $schema->validate('session_mean_reversion_v1', []));
    }

    public function test_direct_declared_request_validation_matches_the_actual_registered_function(): void
    {
        $schema = new StrategyParameterSchemaService();
        $rows = [];
        foreach ([['session_mean_reversion_v1', null],
            ['session_mean_reversion_v1', 'session_mean_reversion'],
            ['session_mean_reversion_v1', 'session_mean_reversion_v1'],
            ['session_mean_reversion_v2', null],
            ['session_v1', 'session_mean_reversion'],
            ['eurusd_session_mean_reversion_g12_a03', 'session_mean_reversion']] as [$strategy, $base]) {
            foreach (['none', 'range_low_v1'] as $gate) {
                $parameters = [...$schema->defaults($strategy), 'session_start' => 7, 'session_end' => 14,
                    'lookback' => 20, 'time_stop_candles' => 6, 'session_context_gate' => $gate];
                $this->assertSame($parameters, $schema->validate($strategy, $parameters, $base));
                $rows[] = compact('strategy', 'base', 'parameters');
            }
        }
        foreach ($this->pythonValidation($rows) as $index => $actual) {
            $this->assertTrue($actual['accepted']);
            $this->assertSame('apply_session_mean_reversion_strategy', $actual['function']);
            $this->assertSame($rows[$index]['parameters'], $actual['parameters']);
        }
    }

    public function test_wrong_family_base_breakout_and_invalid_enum_fail_closed_in_both_owners(): void
    {
        $schema = new StrategyParameterSchemaService();
        $rows = [];
        foreach ([['session_v1', null], ['session_v1', 'session'],
            ['session_mean_reversion_v1', 'session'], ['session_mean_reversion_v1', 'session_v1'],
            ['session_mean_reversion_v1', 'session_breakout'],
            ['session_mean_reversion_v1', 'mean_reversion'], ['session_mean_reversion_v1', ' '],
            ['session_mean_reversion_v1', 'not_a_strategy'],
            ['eurusd_session_mean_reversion_g12_a03', null],
            ['trend_v1', null], ['trend_v1', 'session_mean_reversion'],
            ['differential_router_v1', 'session_mean_reversion'],
            ['regime_ensemble_v1', 'session_mean_reversion']] as [$strategy, $base]) {
            foreach (['none', 'range_low_v1'] as $gate) {
                $rows[] = ['strategy' => $strategy, 'base' => $base,
                    'parameters' => ['session_context_gate' => $gate]];
            }
        }
        foreach (['', 'range_low_v2', 'RANGE_LOW_V1', 'none ', null, true, 1, [], ['mode' => 'range_low_v1']] as $gate) {
            $rows[] = ['strategy' => 'session_mean_reversion_v1', 'base' => null,
                'parameters' => ['session_context_gate' => $gate]];
        }
        foreach ($rows as $row) {
            try {
                $schema->validate($row['strategy'], $row['parameters'], $row['base']);
                $this->fail('An unowned or invalid context gate must not silently disappear.');
            } catch (\InvalidArgumentException $error) {
                $this->assertNotSame('', $error->getMessage());
            }
        }
        foreach ($this->pythonValidation($rows) as $actual) {
            $this->assertFalse($actual['accepted']);
        }
    }

    private function pythonValidation(array $rows): array
    {
        $code = <<<'PYTHON'
import json, sys
from app.services.parameter_schema import validate_strategy_parameters
from app.strategies.registry import get_strategy
results = []
for row in json.load(sys.stdin):
    try:
        parameters = validate_strategy_parameters(row['strategy'], row['parameters'], row['base'])
        results.append({'accepted': True, 'function': get_strategy(row['strategy'], row['base']).__name__,
                        'parameters': parameters})
    except ValueError:
        results.append({'accepted': False})
print(json.dumps(results))
PYTHON;
        $root = dirname(__DIR__, 3);
        $process = new Process([getenv('PYTHON_BINARY') ?: 'python', '-B', '-c', $code],
            $root.'/ai-service-python', ['PYTHONDONTWRITEBYTECODE' => '1'],
            json_encode($rows, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), 30);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
}
