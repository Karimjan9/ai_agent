<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class RunHeadlessScheduler extends Command
{
    protected $signature = 'schedule:headless-work';

    protected $description = 'Run scheduled callbacks in this PHP process without spawning Windows console windows.';

    public function handle(): int
    {
        $lastMinute = null;

        while (true) {
            $minute = CarbonImmutable::now()->format('Y-m-d H:i');

            if ($minute !== $lastMinute) {
                $lastMinute = $minute;
                try {
                    Artisan::call('schedule:run', ['--whisper' => true]);
                } catch (Throwable $exception) {
                    // A transient MySQL deadlock must not kill the only
                    // scheduler process. Mark this minute consumed so the
                    // loop does not hammer the same lock; the next minute
                    // retries the normal schedule tick automatically.
                    Log::warning('Headless scheduler tick failed; retrying on the next minute.', [
                        'minute' => $minute,
                        'exception' => $exception,
                    ]);
                }
            }

            usleep(250_000);
        }
    }
}
