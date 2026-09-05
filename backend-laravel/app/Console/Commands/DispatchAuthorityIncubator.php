<?php

namespace App\Console\Commands;

use App\Models\LabAgent;
use App\Services\EvolutionaryAuthorityFoundryService;
use Illuminate\Console\Command;

class DispatchAuthorityIncubator extends Command
{
    protected $signature = 'trading:dispatch-authority-incubator {symbol?} {--timeframe=H1} {--mentor=} {--limit=6} {--dry-run} {--json}';
    protected $description = 'Materialize clean five-arm cohorts for confirmed skills; no promotion authority is granted.';

    public function handle(EvolutionaryAuthorityFoundryService $foundry): int
    {
        $symbol = strtoupper((string) ($this->argument('symbol') ?: 'XAUUSD'));
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $rows = LabAgent::query()->with(['modelVersion', 'generation.laboratory', 'parentA'])
            ->where('symbol', $symbol)->where('timeframe', $timeframe)
            ->when($this->option('mentor'), fn ($q, $id) => $q->whereKey((int) $id))
            ->whereHas('modelVersion', fn ($q) => $q->where('metadata->skill_mentor->status', 'confirmed'))
            ->latest('id')->limit(max(1, min(20, (int) $this->option('limit'))))->get();
        $results = $rows->map(function (LabAgent $agent) use ($foundry): array {
            if ($this->option('dry-run')) return ['mentor_agent_id' => $agent->id, 'status' => 'would_materialize', 'promotion_evidence' => false];
            return ['mentor_agent_id' => $agent->id, ...$foundry->materializeIncubator($agent)];
        })->values()->all();
        $payload = ['protocol' => EvolutionaryAuthorityFoundryService::PROTOCOL, 'symbol' => $symbol, 'timeframe' => $timeframe,
            'confirmed_skills_seen' => count($results), 'results' => $results, 'promotion_evidence' => false];
        if ($this->option('json')) $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        else $this->info('Authority incubator: '.count($results).' confirmed skill(s) processed.');
        return self::SUCCESS;
    }
}
