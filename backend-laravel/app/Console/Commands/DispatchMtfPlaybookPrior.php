<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateMtfPlaybookPriorJob;
use App\Services\AutonomousModeService;
use App\Services\CanonicalResearchLanePriorityService;
use Illuminate\Console\Command;

class DispatchMtfPlaybookPrior extends Command
{
    /** @var array<int,string> */
    public const CONFIRMATION_MODELS = [
        'confirmation_trend_continuation',
        'confirmation_breakout_retest',
        'confirmation_false_break_reversal',
        'confirmation_range_sweep',
        'confirmation_htf_reversal',
    ];

    protected $signature = 'trading:dispatch-mtf-playbook-prior
        {symbol=XAUUSD : Primary market symbol}
        {--confirmation-first : Complete the five Confirmation & Entry hypotheses before the general catalogue}
        {--related-symbol= : Optional related market required by SMT research}';

    protected $description = 'Queue one unique, bounded MTF frozen-control prior without blocking the scheduler';

    public function handle(CanonicalResearchLanePriorityService $priority, AutonomousModeService $autonomy): int
    {
        $symbol = strtoupper(str_replace(['/', '_', '-'], '', trim((string) $this->argument('symbol'))));
        if ($symbol !== 'XAUUSD') {
            $this->error('Confirmation/toolbox autonomous research is currently sealed to XAUUSD.');

            return self::FAILURE;
        }
        if (! $autonomy->enabled($symbol, 'H1')) {
            $this->info('MTF prior deferred: autonomous mode is stopped; monitoring remains available.');

            return self::SUCCESS;
        }
        $ownership = $priority->edgeGenesisOwnership($symbol, 'H1');
        if (($ownership['owned'] ?? false) === true) {
            $this->info('MTF prior deferred: canonical Edge Genesis currently owns the replay lane.');

            return self::SUCCESS;
        }
        $preferred = (bool) $this->option('confirmation-first') ? self::CONFIRMATION_MODELS : [];
        $related = trim((string) $this->option('related-symbol')) ?: null;

        EvaluateMtfPlaybookPriorJob::dispatch($symbol, $preferred, $related);
        $this->info('One unique MTF prior job queued behind full validation on lab-frontier.');

        return self::SUCCESS;
    }
}
