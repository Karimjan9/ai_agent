<?php

namespace App\Services\MarketData;

interface MarketDataProviderInterface
{
    public function fetchCandles(
        string $symbol,
        string $providerSymbol,
        string $timeframe,
        int $limit = 1000,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $to = null,
    ): array;
}
