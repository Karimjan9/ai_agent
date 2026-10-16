<?php

namespace Tests\Unit;

use App\Services\InstrumentMethodLibraryService;
use App\Services\StrategyParameterSchemaService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

require_once dirname(__DIR__, 2).'/app/Services/StrategyParameterSchemaService.php';
require_once dirname(__DIR__, 2).'/app/Services/InstrumentMethodLibraryService.php';

/** Pure parameter/type parity; no application boot, database or market outcomes. */
class BollingerBandVarianceParityTest extends TestCase
{
    public function test_variance_is_explicit_only_and_closed_midline_fields_are_boolean(): void
    {
        $owner = new StrategyParameterSchemaService();
        foreach (['session_v1', 'session_mean_reversion_v1', 'trend_v1'] as $family) {
            $this->assertArrayNotHasKey('session_band_variance', $owner->schema($family));
            $this->assertArrayNotHasKey('session_band_variance', $owner->defaults($family));
            $this->assertArrayNotHasKey('session_band_variance', $owner->defaults($family, true));
        }
        $this->assertSame(['string', ['sample', 'population']],
            $owner->schema('session_mean_reversion_v1', true)['session_band_variance']);
        foreach (['method_midline20_available', 'method_midline20_exit'] as $field) {
            $this->assertContains($field, InstrumentMethodLibraryService::FEATURE_BOOL_INPUTS);
            $this->assertNotContains($field, InstrumentMethodLibraryService::FEATURE_NUMBER_INPUTS);
        }
    }

    public function test_php_and_python_refuse_unowned_or_invalid_treatments_and_preserve_accepted_vectors(): void
    {
        $owner = new StrategyParameterSchemaService(); $rows = [];
        foreach (['sample', 'population'] as $mode) {
            foreach ([['session_mean_reversion_v1', null], ['session_mean_reversion_v1', 'session_mean_reversion'],
                ['session_v1', 'session_mean_reversion'], ['session_v1', null],
                ['session_mean_reversion_v1', 'session'], ['trend_v1', 'session_mean_reversion']] as [$strategy, $base]) {
                $rows[] = ['strategy' => $strategy, 'base' => $base, 'parameters' => ['session_band_variance' => $mode]];
            }
        }
        foreach (['', 'sample ', 'POPULATION', null, true, 0, []] as $mode) {
            $rows[] = ['strategy' => 'session_mean_reversion_v1', 'base' => null,
                'parameters' => ['session_band_variance' => $mode]];
        }
        $expected = [];
        foreach ($rows as $row) {
            try {
                $parameters = $owner->validate($row['strategy'], $row['parameters'], $row['base']);
                $this->assertSame($row['parameters'], $parameters);
                $expected[] = ['accepted' => true, 'parameters' => $parameters];
            } catch (\InvalidArgumentException) { $expected[] = ['accepted' => false]; }
        }
        $code = <<<'PY'
import json,sys
from app.services.parameter_schema import validate_strategy_parameters
from app.services.instrument_method_features import BOOL_FIELDS
answers=[]
for row in json.load(sys.stdin):
    try:
        result=validate_strategy_parameters(row['strategy'],row['parameters'],row['base'])
        answers.append({'accepted':True,'parameters':result})
    except ValueError:
        answers.append({'accepted':False})
assert {'method_midline20_available','method_midline20_exit'}.issubset(BOOL_FIELDS)
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
