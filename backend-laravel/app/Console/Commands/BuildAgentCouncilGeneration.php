<?php

namespace App\Console\Commands;

use App\Services\LabPopulationService;
use Illuminate\Console\Command;

class BuildAgentCouncilGeneration extends Command
{
    protected $signature = 'trading:build-agent-council {symbol?} {--timeframe=H1} {--population=4 : Initial role-complete cohort size; minimum four roles} {--force : Explicitly open the role-complete research population}';

    protected $description = 'Build a four-role council generation without changing frozen forward evidence';

    public function handle(LabPopulationService $population): int
    {
        $symbols = $this->argument('symbol')
            ? [strtoupper((string) $this->argument('symbol'))]
            : ['XAUUSD', 'EURUSD', 'GBPUSD'];
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $populationSize = max(4, min(20, (int) $this->option('population')));
        $built = 0;

        foreach ($symbols as $symbol) {
            $startedAt = microtime(true);
            try {
                $generation = $population->build(
                    $symbol,
                    // The role-complete population is an explicit child of a
                    // completed data/edge audit. This keeps the normal
                    // scheduler handoff and the council curriculum on the
                    // same immutable audit boundary.
                    'data_edge_audit',
                    (bool) $this->option('force'),
                    $timeframe,
                    [],
                    true,
                    true,
                    $populationSize,
                );
            } catch (\Throwable $exception) {
                $this->error(sprintf(
                    '%s %s: council build failed after %.1fs: %s',
                    $symbol,
                    $timeframe,
                    microtime(true) - $startedAt,
                    $exception->getMessage(),
                ));
                report($exception);
                continue;
            }
            if (! $generation) {
                $this->warn("{$symbol} {$timeframe}: canonical data/active-generation gate blocked council build.");
                continue;
            }

            $built++;
            $roles = collect($generation->agents)
                ->map(fn ($agent): ?string => data_get($agent->modelVersion?->metadata, 'role_complete_council.role'))
                ->filter()->countBy()->all();
            $this->info(sprintf(
                '%s %s: G%s role-complete council created in %.1fs; roles=%s',
                $symbol,
                $timeframe,
                $generation->generation,
                microtime(true) - $startedAt,
                json_encode($roles),
            ));
        }

        return $built > 0 ? self::SUCCESS : self::FAILURE;
    }
}
