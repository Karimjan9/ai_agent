<?php

namespace App\Console\Commands;

use App\Models\MarketSymbol;
use App\Models\TrainingLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

class RunDailyTrainingWorkflow extends Command
{
    protected $signature = 'trading:daily-workflow';

    protected $description = 'Run full daily AI trading training workflow';

    public function handle(): int
    {
        $log = TrainingLog::create([
            'type' => 'daily_workflow',
            'status' => 'running',
            'message' => 'Daily AI workflow boshlandi.',
            'started_at' => now(),
        ]);

        try {
            $symbols = MarketSymbol::query()->where('is_active', true)->pluck('symbol');

            $this->info('1/3 Market data yangilanmoqda...');
            foreach ($symbols as $symbol) {
                $marketDataCode = Artisan::call('market-data:update', [
                    '--symbol' => $symbol,
                    '--timeframe' => 'H1',
                    '--limit' => 1000,
                ]);

                if ($marketDataCode !== self::SUCCESS) {
                    throw new \RuntimeException("Market data update failed for {$symbol}.");
                }

                $this->line(Artisan::output());
            }

            $this->info('2/3 Auto training boshlanmoqda...');
            foreach ($symbols as $symbol) {
                $autoTrainCode = Artisan::call('trading:auto-train', [
                    '--symbol' => $symbol,
                    '--timeframe' => 'H1',
                    '--evaluation' => 'incremental',
                ]);

                if ($autoTrainCode !== self::SUCCESS) {
                    throw new \RuntimeException("Auto training failed for {$symbol}.");
                }

                $this->line(Artisan::output());
            }

            $this->info('3/3 Daily report yaratilmoqda...');
            $reportCode = Artisan::call('trading:daily-report');

            if ($reportCode !== self::SUCCESS) {
                throw new \RuntimeException('Daily report command failed.');
            }

            $this->line(Artisan::output());

            $log->update([
                'status' => 'success',
                'message' => 'Daily AI workflow muvaffaqiyatli yakunlandi.',
                'finished_at' => now(),
            ]);

            $this->info('Daily AI workflow yakunlandi.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $log->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
