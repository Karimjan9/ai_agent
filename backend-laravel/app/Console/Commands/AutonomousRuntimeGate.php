<?php

namespace App\Console\Commands;

use App\Services\AutonomousModeService;
use Illuminate\Console\Command;

class AutonomousRuntimeGate extends Command
{
    protected $signature = 'ai:runtime-gate {--json : Print machine-readable control state}';

    protected $description = 'Read the durable operator control without requiring Redis or worker health';

    public function handle(AutonomousModeService $mode): int
    {
        $control = $mode->status('XAUUSD', 'H1');
        $result = [
            'protocol' => 'autonomous_runtime_gate_v1',
            'state' => $control['state'],
            'start_runtime' => ! in_array($control['state'], ['pausing', 'paused', 'safety_halt'], true),
            'generation_id' => data_get($control, 'latest_generation.id'),
            'promotion_evidence' => false,
        ];
        $this->line($this->option('json')
            ? (string) json_encode($result, JSON_UNESCAPED_SLASHES)
            : (string) $result['state']);

        return self::SUCCESS;
    }
}
