<?php

namespace Tests\Unit;

use App\Services\StrategyParameterSchemaService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 2).'/app/Services/StrategyParameterSchemaService.php';

class SessionMeanReversionSchemaParityTest extends TestCase
{
    public function test_registered_reentry_uses_the_unchanged_session_schema_and_defaults(): void
    {
        $schema = new StrategyParameterSchemaService();
        foreach (['session_mean_reversion_v1', 'eurusd_session_mean_reversion_g12_a03'] as $strategy) {
            $this->assertSame('session', $schema->family($strategy));
            $this->assertSame($schema->schema('session_v1'), $schema->schema($strategy));
            $this->assertSame($schema->defaults('session_v1'), $schema->defaults($strategy));
            $this->assertSame($schema->defaults($strategy), $schema->validate($strategy, $schema->defaults($strategy)));
            $this->assertSame('session_mean_reversion_v1', $schema->runtimeBaseStrategy($strategy));
        }
        $this->assertSame('session_v1', $schema->runtimeBaseStrategy('session_v1'));
        $this->assertSame('session', $schema->runtimeBaseStrategy('session_mean_reversion_v1', 'session'));
        $this->assertArrayNotHasKey('trailing_activation_r', $schema->defaults('session_v1'));
    }

    public function test_actual_python_registry_and_parameter_owner_preserve_the_selected_function(): void
    {
        $schema = new StrategyParameterSchemaService();
        $requests = [];
        foreach ([['session_v1', 'session', 6, 'apply_session_strategy'],
            ['session_mean_reversion_v1', 'session_mean_reversion', 6, 'apply_session_mean_reversion_strategy'],
            ['session_v1', 'session', 24, 'apply_session_strategy'],
            ['session_mean_reversion_v1', 'session_mean_reversion', 24, 'apply_session_mean_reversion_strategy']] as [$strategy, $base, $bars, $function]) {
            $parameters = [...$schema->defaults($strategy), 'session_start' => 7, 'session_end' => 14,
                'lookback' => 20, 'time_stop_candles' => $bars];
            $this->assertSame($parameters, $schema->validate($strategy, $parameters));
            $requests[] = compact('strategy', 'base', 'parameters', 'function');
        }
        $python = <<<'PYTHON'
import json, sys
from app.services.parameter_schema import strategy_family, validate_strategy_parameters
from app.strategies.registry import get_strategy
rows = json.load(sys.stdin)
print(json.dumps([{'family':strategy_family(row['strategy'], row['base']),
    'function':get_strategy(row['strategy'], row['base']).__name__,
    'parameters':validate_strategy_parameters(row['strategy'], row['parameters'], row['base'])} for row in rows]))
PYTHON;
        $root = dirname(__DIR__, 3);
        $process = new Process([getenv('PYTHON_BINARY') ?: 'python', '-B', '-c', $python], $root.'/ai-service-python',
            ['PYTHONDONTWRITEBYTECODE' => '1'], json_encode($requests, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), 30);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        $actual = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        foreach ($requests as $index => $request) {
            $this->assertSame('session', $actual[$index]['family']);
            $this->assertSame($request['function'], $actual[$index]['function']);
            $this->assertSame($request['parameters'], $actual[$index]['parameters']);
        }
    }

    public function test_alias_does_not_admit_unowned_range_topology_parameters(): void
    {
        $schema = new StrategyParameterSchemaService();
        $this->expectException(\InvalidArgumentException::class);
        $schema->validate('session_mean_reversion_v1', ['range_signal_mode' => 'reentry']);
    }
}
