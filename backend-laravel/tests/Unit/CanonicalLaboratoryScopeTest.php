<?php

namespace Tests\Unit;

use App\Console\Commands\Concerns\CanonicalLaboratoryScope;
use Tests\TestCase;

class CanonicalLaboratoryScopeTest extends TestCase
{
    public function test_xauusd_sensor_timeframes_resolve_to_the_single_organism_lab(): void
    {
        $probe = new class
        {
            use CanonicalLaboratoryScope;

            /** @return array{0: string, 1: string} */
            public function resolve(string $symbol, string $timeframe): array
            {
                return $this->canonicalLaboratoryScope($symbol, $timeframe);
            }
        };

        $this->assertSame(['XAUUSD', 'H1'], $probe->resolve('xau/usd', 'M5'));
        $this->assertSame(['XAUUSD', 'H1'], $probe->resolve('xau_usd', 'M15'));
        $this->assertSame(['XAUUSD', 'H1'], $probe->resolve('XAU-USD', 'H4'));
        $this->assertSame(['EURUSD', 'M15'], $probe->resolve('eur/usd', 'm15'));
    }
}
