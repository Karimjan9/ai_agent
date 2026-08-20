<?php

namespace App\Console\Commands;

use App\Models\LabAgent;
use App\Services\StrategyCurriculumService;
use Illuminate\Console\Command;

class SyncStrategyCurricula extends Command
{
    protected $signature = 'instruments:curriculum-sync {--limit=1000}';

    protected $description = 'Bind existing laboratory agents to bounded strategy mastery curricula';

    public function handle(StrategyCurriculumService $curricula): int
    {
        $count = 0;
        LabAgent::query()->with('modelVersion')->whereNotNull('model_version_id')->limit((int) $this->option('limit'))->get()
            ->each(function (LabAgent $agent) use ($curricula, &$count): void {
                if ($agent->modelVersion) {
                    $curricula->enroll($agent->modelVersion, $agent);
                    $count++;
                }
            });
        $this->info("Strategy curricula synced: {$count} agents.");

        return self::SUCCESS;
    }
}
