<?php

namespace App\Console\Commands;

use App\Services\CausalGoldenWorldHarnessService;
use Illuminate\Console\Command;

class RunCausalGoldenWorldAcceptance extends Command
{
    protected $signature = 'trading:causal-golden-worlds {--json}';

    protected $description = 'Prove learning-to-reproduction wiring in deterministic positive, null, poisoned and context-switch worlds.';

    public function handle(CausalGoldenWorldHarnessService $harness): int
    {
        $result = $harness->run();
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('Causal Golden Worlds: '.strtoupper((string) $result['status']));
            foreach ($result['worlds'] as $name => $world) {
                $this->line(sprintf(' - %s: %s', $name, ($world['passed'] ?? false) ? 'PASS' : 'FAIL'));
            }
        }

        return ($result['passed'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
