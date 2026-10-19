<?php

namespace App\Console\Commands;

use App\Services\FiniteOriginalComponentPreparationService;
use Illuminate\Console\Command;
use LogicException;

class RegisterFiniteOriginalComponent extends Command
{
    protected $signature = 'trading:register-original-component {questionKey} {designFile}';
    protected $description = 'Register one prospective finite cold-source question; does not start research or create a generation';

    public function handle(FiniteOriginalComponentPreparationService $owner): int
    {
        $path = realpath((string) $this->argument('designFile'));
        if (! $path || ! is_file($path) || filesize($path) > 131072) throw new LogicException('FINITE_COMPONENT_BOUNDED_LOCAL_DESIGN_REQUIRED');
        $design = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($design) || array_is_list($design)) throw new LogicException('FINITE_COMPONENT_OBJECT_DESIGN_REQUIRED');
        $this->line(json_encode($owner->register((string) $this->argument('questionKey'), $design), JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
