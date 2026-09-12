<?php

namespace App\Console\Commands\Concerns;

trait CanonicalLaboratoryScope
{
    /** @return array{0: string, 1: string} */
    protected function canonicalLaboratoryScope(string $symbol, string $timeframe): array
    {
        $canonicalSymbol = strtoupper(str_replace(['/', '_', '-'], '', trim($symbol)));
        $canonicalTimeframe = strtoupper(trim($timeframe));
        $organismSymbol = strtoupper((string) config('services.xauusd_organism.symbol', 'XAUUSD'));

        if ($canonicalSymbol === $organismSymbol) {
            $canonicalTimeframe = strtoupper((string) config(
                'services.xauusd_organism.laboratory_storage_timeframe',
                'H1',
            ));
        }

        return [$canonicalSymbol, $canonicalTimeframe];
    }
}
