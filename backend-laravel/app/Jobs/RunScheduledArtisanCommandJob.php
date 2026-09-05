<?php

namespace App\Jobs;

use App\Services\ScheduledCommandOutcomeClassifierService;
use App\Services\CanonicalResearchLanePriorityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** Isolates every scheduled Artisan command from the singleton clock. */
class RunScheduledArtisanCommandJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout;

    public int $uniqueFor;

    public bool $failOnTimeout = true;

    /** @param array<string,mixed> $arguments */
    public function __construct(
        public string $command,
        public array $arguments = [],
        public string $lane = 'scheduler-ops',
    ) {
        $this->lane = in_array($lane, ['scheduler-critical', 'scheduler-research'], true)
            ? $lane
            : 'scheduler-ops';
        $this->timeout = $this->lane === 'scheduler-critical' ? 180 : 900;
        $this->uniqueFor = $this->timeout + 300;
        $this->onConnection((string) config('queue.default', 'redis'));
        $this->onQueue($this->lane);
    }

    public function uniqueId(): string
    {
        return hash('sha256', $this->command.'|'.$this->canonicalArguments());
    }

    public function handle(
        ScheduledCommandOutcomeClassifierService $outcomes,
        ?CanonicalResearchLanePriorityService $priority = null,
    ): void
    {
        $priority ??= app(CanonicalResearchLanePriorityService::class);
        $started = microtime(true);
        $key = 'system:scheduled-command:'.$this->uniqueId();
        Cache::put($key, $this->status('running', $started), now()->addDay());

        try {
            // Database-heavy research compilers and ordinary lifecycle work
            // must not compete with an active Edge state machine for CPU,
            // memory or generation authority. Their source rows are durable,
            // so completing this tick as deferred is lossless; cadence will
            // schedule them again after the canonical owner settles.
            if ($this->lane === 'scheduler-research') {
                $ownership = $priority->edgeGenesisOwnership('XAUUSD', 'H1');
                if (($ownership['owned'] ?? false) === true) {
                    $detail = 'Deferred to canonical Edge Genesis; immutable research source preserved for the next scheduler tick.';
                    Cache::put($key, $this->status('deferred', $started, $detail, [
                        'protocol' => 'scheduled_research_priority_arbitration_v1',
                        'reason' => 'EDGE_GENESIS_OWNS_RESEARCH_RUNTIME',
                        'ownership' => $ownership,
                        'promotion_evidence' => false,
                    ]), now()->addDay());
                    Log::info('Scheduled research yielded to canonical Edge Genesis.', [
                        'command' => $this->command,
                        'arguments' => $this->arguments,
                        'lane' => $this->lane,
                        'ownership' => $ownership,
                        'promotion_evidence' => false,
                    ]);

                    return;
                }
            }
            $exitCode = Artisan::call($this->command, $this->arguments);
            $output = trim(Artisan::output());
            $outcome = $outcomes->classify($this->command, $this->arguments, $exitCode, $output);
            if ((bool) ($outcome['throw'] ?? true)) {
                throw new RuntimeException("Scheduled command {$this->command} returned exit code {$exitCode}: ".substr($output, 0, 1000));
            }
            Cache::put($key, $this->status((string) $outcome['status'], $started, $output, $outcome), now()->addDay());
            if ($exitCode !== 0) {
                Log::notice('Isolated scheduled Artisan command reached an expected fail-closed outcome.', [
                    'command' => $this->command,
                    'arguments' => $this->arguments,
                    'lane' => $this->lane,
                    'outcome' => $outcome,
                    'output' => substr($output, 0, 1000),
                ]);
            }
        } catch (\Throwable $exception) {
            Cache::put($key, $this->status('failed', $started, $exception->getMessage()), now()->addDay());
            Log::error('Isolated scheduled Artisan command failed.', [
                'command' => $this->command,
                'arguments' => $this->arguments,
                'lane' => $this->lane,
                'exception' => $exception,
            ]);

            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    private function status(string $status, float $started, string $detail = '', array $outcome = []): array
    {
        return [
            'protocol' => 'isolated_scheduled_artisan_command_v1',
            'command' => $this->command,
            'lane' => $this->lane,
            'status' => $status,
            'updated_at' => now()->toIso8601String(),
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'detail' => substr($detail, 0, 1500),
            'outcome' => $outcome,
            'promotion_evidence' => false,
        ];
    }

    private function canonicalArguments(): string
    {
        $arguments = $this->arguments;
        ksort($arguments);

        return (string) json_encode($arguments, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
