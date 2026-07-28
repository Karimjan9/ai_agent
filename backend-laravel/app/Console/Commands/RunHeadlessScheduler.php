<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

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
                Artisan::call('schedule:run', ['--whisper' => true]);
                $lastMinute = $minute;
            }

            usleep(250_000);
        }
    }
}
