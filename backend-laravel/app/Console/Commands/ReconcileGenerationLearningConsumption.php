<?php

namespace App\Console\Commands;

use App\Models\LabGeneration;
use App\Services\GenerationLearningConsumptionReconciliationService;
use Illuminate\Console\Command;

class ReconcileGenerationLearningConsumption extends Command
{
    protected $signature = 'trading:reconcile-generation-learning {generationId : Lab generation database ID}';

    protected $description = 'Append canonical retrieval provenance to a pre-outcome generation without changing its mutations';

    public function handle(GenerationLearningConsumptionReconciliationService $service): int
    {
        $generation = LabGeneration::query()->findOrFail((int) $this->argument('generationId'));
        $result = $service->reconcile($generation);
        $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return data_get($result, 'status') === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
