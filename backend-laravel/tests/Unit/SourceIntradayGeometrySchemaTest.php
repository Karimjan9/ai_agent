<?php

namespace Tests\Unit;

use App\Services\StrategyParameterSchemaService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 2).'/app/Services/StrategyParameterSchemaService.php';

/** Pure PHP/Python declaration parity, not replay or earned qualification. */
class SourceIntradayGeometrySchemaTest extends TestCase
{
    public function test_new_variants_do_not_enter_ordinary_default_or_sampling_rosters(): void
    {
        $owner = new StrategyParameterSchemaService();
        foreach (['trend_v1', 'trend_retest_v1', 'session_mean_reversion_v1'] as $strategy) {
            $this->assertArrayNotHasKey('intraday_exit_variant', $owner->schema($strategy));
            $this->assertArrayNotHasKey('intraday_exit_variant', $owner->defaults($strategy));
            $this->assertArrayNotHasKey('intraday_exit_variant', $owner->defaults($strategy, true));
            $this->assertNotContains('source_frozen_retest_v1', $owner->schema($strategy)['entry_topology_variant'][1]);
        }
        $this->assertSame($owner->defaults('trend_v1'), $owner->defaults('trend_retest_v1'));
        $this->assertSame('trend', $owner->family('trend_retest_v1'));
        $this->assertSame(['string', ['closed_setup_2r_v1']], $owner->schema('trend_v1', true)['intraday_exit_variant']);
    }

    public function test_exact_source_runtime_and_fixed_periods_match_python_and_refuse_spoofed_bases(): void
    {
        $owner = new StrategyParameterSchemaService(); $rows = [];
        foreach ([['trend_v1', 'trend', 'source_ema_pretrend_pullback_v1'],
            ['trend_retest_v1', 'trend_retest', 'source_frozen_retest_v1']] as [$strategy, $base, $topology]) {
            $parameters = ['ema_fast' => 20, 'ema_slow' => 50, 'rsi_period' => 14,
                'trend_strength_min' => 20.0, 'entry_topology_variant' => $topology,
                'intraday_exit_variant' => 'closed_setup_2r_v1', 'time_stop_candles' => 6];
            $rows[] = compact('strategy', 'base', 'parameters');
            foreach ([['ema_fast' => 21], ['ema_slow' => 51], ['intraday_exit_variant' => 'unknown'],
                ['entry_topology_variant' => 'frozen'], ['lookback' => 21]] as $change) {
                $rows[] = ['strategy' => $strategy, 'base' => $base, 'parameters' => [...$parameters, ...$change]];
            }
            foreach (['mean_reversion', 'session', ' ', 'not_a_strategy', $base === 'trend' ? 'trend_retest' : 'trend'] as $wrong) {
                $rows[] = ['strategy' => $strategy, 'base' => $wrong, 'parameters' => $parameters];
            }
        }
        foreach (['', null, true, 1, []] as $invalid) {
            $rows[] = ['strategy' => 'trend_v1', 'base' => 'trend', 'parameters' => [
                'ema_fast' => 20, 'ema_slow' => 50, 'entry_topology_variant' => 'source_ema_pretrend_pullback_v1',
                'intraday_exit_variant' => $invalid]];
        }
        $expected = [];
        foreach ($rows as $row) {
            try {
                $value = $owner->validate($row['strategy'], $row['parameters'], $row['base']);
                $expected[] = ['accepted' => true, 'parameters' => $value];
            } catch (\InvalidArgumentException) { $expected[] = ['accepted' => false]; }
        }
        $this->assertCount(2, array_filter($expected, fn (array $row): bool => $row['accepted']));
        $code = <<<'PY'
import json,sys
from app.services.parameter_schema import validate_strategy_parameters
answers=[]
for row in json.load(sys.stdin):
    try:
        value=validate_strategy_parameters(row['strategy'],row['parameters'],row['base'])
        answers.append({'accepted':True,'parameters':value})
    except ValueError:
        answers.append({'accepted':False})
print(json.dumps(answers))
PY;
        $process = new Process([getenv('PYTHON_BINARY') ?: 'python', '-B', '-c', $code],
            dirname(__DIR__, 3).'/ai-service-python', ['PYTHONDONTWRITEBYTECODE' => '1'],
            json_encode($rows, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), 30);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
        $this->assertSame($expected, json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }
}
