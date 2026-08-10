<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

/**
 * Drains stale legacy Strategy Lab queue payloads through the canonical
 * dispatcher.  The old serialized constructor is intentionally preserved so
 * already-persisted database jobs can be consumed after deployment.
 */
class RunStrategyLabJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 2400;

    public int $tries = 0;

    public int $uniqueFor = 7200;

    public function __construct(public int $trainingSessionId, public array $payload, public string $requestHash)
    {
        $this->onQueue('strategy-lab');
    }

    public function uniqueId(): string
    {
        return 'canonical-strategy-lab:'.$this->requestHash;
    }

    public function handle(): void
    {
        $symbol = strtoupper(str_replace(['_', '/'], '', (string) ($this->payload['symbol'] ?? 'XAUUSD')));
        $timeframe = strtoupper((string) ($this->payload['timeframe'] ?? 'H1'));

        Artisan::call('trading:dispatch-lab', [
            $symbol,
            '--timeframe' => $timeframe,
            '--force-generation' => true,
        ]);
    }
}
