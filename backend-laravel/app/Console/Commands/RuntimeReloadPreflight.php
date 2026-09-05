<?php

namespace App\Console\Commands;

use App\Services\RuntimeReloadPreflightService;
use Illuminate\Console\Command;

class RuntimeReloadPreflight extends Command
{
    protected $signature = 'system:runtime-reload-preflight {--json}';

    protected $description = 'Refuse a rolling runtime reload while durable replay work can be interrupted.';

    public function handle(RuntimeReloadPreflightService $preflight): int
    {
        $result = $preflight->inspect();
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line(($result['safe'] ? 'SAFE' : 'REFUSED').': '.$result['reason']);
        }

        return $result['safe'] ? self::SUCCESS : self::FAILURE;
    }
}
