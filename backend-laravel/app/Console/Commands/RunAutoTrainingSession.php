<?php

namespace App\Console\Commands;

use App\Services\LabPopulationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Compatibility entry point for old operators and queued scheduler configs.
 *
 * The command name is retained so an old deployment cannot accidentally fail
 * with an "unknown command" error, but it is no longer an evaluator.  All
 * work is handed to the canonical LabGeneration -> LabAgent ->
 * LabEvaluationRun pipeline; this command never writes TrainingSession,
 * StrategyScore, EvolutionProposal, or genome rows.
 */
class RunAutoTrainingSession extends Command
{
    protected $signature = 'trading:auto-train
                            {--symbol=XAUUSD}
                            {--timeframe=H1}
                            {--balance=10000}
                            {--risk=1}
                            {--evaluation=full}
                            {--include-lab-agents : Compatibility option; canonical Lab agents are always used}
                            {--max-strategies=1 : Compatibility option; canonical Lab population owns the budget}';

    protected $description = 'Deprecated compatibility alias for canonical laboratory dispatch';

    public function handle(LabPopulationService $populations): int
    {
        $symbol = strtoupper(str_replace(['_', '/'], '', trim((string) $this->option('symbol'))));
        $timeframe = strtoupper((string) $this->option('timeframe'));

        if (! preg_match('/^(XAU|EUR|GBP)USD$/', $symbol) || ! in_array($timeframe, ['M15', 'H1'], true)) {
            $this->error('Canonical Lab uchun symbol/timeframe noto\'g\'ri.');

            return self::FAILURE;
        }

        try {
            $populations->ensureLaboratories();
            $this->warn('trading:auto-train deprecated: eski TrainingSession/StrategyScore/evolution yozuvlari o\'chirildi.');

            $dispatchCode = Artisan::call('trading:dispatch-lab', [
                $symbol,
                '--timeframe' => $timeframe,
                '--force-generation' => true,
            ]);
            $this->line(Artisan::output());

            if ($dispatchCode !== self::SUCCESS) {
                throw new \RuntimeException('Canonical Lab dispatch failed.');
            }

            $this->info("{$symbol} {$timeframe}: canonical Lab pipelinega yuborildi.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
