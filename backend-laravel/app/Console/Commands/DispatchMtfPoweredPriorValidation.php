<?php

namespace App\Console\Commands;

use App\Jobs\ValidateMtfPoweredPriorJob;
use App\Services\CanonicalResearchLanePriorityService;
use App\Services\MtfPoweredPriorValidationService;
use Illuminate\Console\Command;

class DispatchMtfPoweredPriorValidation extends Command
{
    protected $signature = 'trading:dispatch-mtf-powered-prior-validation {symbol=XAUUSD}';

    protected $description = 'Queue one powered MTF prior for model-owned nine-fold paired validation';

    public function handle(
        MtfPoweredPriorValidationService $validation,
        CanonicalResearchLanePriorityService $priority,
    ): int
    {
        $symbol = strtoupper(str_replace(['/', '_', '-'], '', trim((string) $this->argument('symbol'))));
        if ($symbol !== 'XAUUSD') {
            $this->error('MTF powered-prior validation is sealed to XAUUSD.');
            return self::FAILURE;
        }
        if (($priority->edgeGenesisOwnership($symbol, 'H1')['owned'] ?? false) === true) {
            $this->info('Powered MTF prior deferred: canonical Edge Genesis currently owns the replay lane.');

            return self::SUCCESS;
        }
        $source = $validation->nextEligible($symbol);
        if (! $source) {
            $this->info('No current powered MTF prior is awaiting model-owned validation.');
            return self::SUCCESS;
        }
        ValidateMtfPoweredPriorJob::dispatch((int) $source->id);
        $this->info("Powered MTF prior #{$source->id} queued for model-owned nine-fold validation.");

        return self::SUCCESS;
    }
}
