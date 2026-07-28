<?php

namespace App\Console\Commands;

use App\Models\AiLaboratory;
use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Services\LabAgentEvaluationService;
use Illuminate\Console\Command;

class RunDiagnosticRescueReplay extends Command
{
    protected $signature = 'trading:diagnostic-rescue {symbol} {generation?} {--timeframe=H1}';
    protected $description = 'Run bounded learning-only diagnostic replays; results can never enter promotion or paper lanes';

    public function handle(LabAgentEvaluationService $evaluation): int
    {
        $symbol = strtoupper((string) $this->argument('symbol'));
        $lab = AiLaboratory::where('symbol', $symbol)->where('timeframe', strtoupper((string) $this->option('timeframe')))->firstOrFail();
        $generation = $this->argument('generation') ? $lab->generations()->where('generation', (int) $this->argument('generation'))->firstOrFail() : $lab->generations()->latest('generation')->firstOrFail();
        $agentIds = CandidateGateDecision::where('stage', 'diagnostic_rescue_replay')->where('decision', 'waiting')
            ->whereHas('labAgent', fn ($query) => $query->where('lab_generation_id', $generation->id))->pluck('lab_agent_id');
        foreach (LabAgent::whereIn('id', $agentIds)->orderBy('id')->get() as $agent) $evaluation->diagnosticReplay($agent);
        $this->info("{$symbol} G{$generation->generation}: {$agentIds->count()} diagnostic rescue replays completed.");
        return self::SUCCESS;
    }
}
