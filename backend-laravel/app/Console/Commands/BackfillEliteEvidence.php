<?php

namespace App\Console\Commands;

use App\Models\LabAgent;
use App\Models\AgentSkillAtlasEntry;
use App\Models\ModelMarketPerformance;
use App\Services\AgentEvolutionQualityService;
use App\Services\CounterfactualBlameGraphService;
use App\Services\EliteEcosystemService;
use App\Services\FailureCurriculumService;
use App\Services\TransferMatrixService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillEliteEvidence extends Command
{
    protected $signature = 'trading:backfill-elite-evidence {symbol?} {--limit=200 : Maximum valid performance rows to process}';

    protected $description = 'Persist Atlas, blame graph, failure curriculum, Red-Queen and transfer evidence from existing immutable replays';

    public function handle(
        AgentEvolutionQualityService $evolution,
        EliteEcosystemService $ecosystem,
        CounterfactualBlameGraphService $blame,
        FailureCurriculumService $curriculum,
        TransferMatrixService $transfer,
    ): int {
        $query = ModelMarketPerformance::query()
            ->with('modelVersion')
            ->where('evidence_status', 'valid')
            ->whereNotNull('metrics')
            ->when($this->argument('symbol'), fn ($builder) => $builder->where('symbol', strtoupper((string) $this->argument('symbol'))))
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')));

        $rows = $query->get();
        $this->info('Immutable replay rows selected: '.$rows->count());
        $atlas = 0; $edges = 0; $curriculumRuns = 0; $transferEntries = 0; $failed = 0;

        foreach ($rows as $performance) {
            $result = (array) $performance->metrics;
            $agent = LabAgent::query()->where('model_version_id', $performance->model_version_id)->latest('id')->first();

            try {
                DB::transaction(function () use ($performance, $agent, $result, $evolution, $ecosystem, $blame, $curriculum, $transfer, &$atlas, &$edges, &$curriculumRuns, &$transferEntries): void {
                    $ecosystem->sync($performance, $result, $evolution->capabilityVector($result));
                    $blameResult = $blame->sync($performance, $agent, $result);
                    $curriculumResult = $curriculum->evaluate($performance, $result);
                    $transferResult = $transfer->sync($performance->modelVersion, $result);
                    $atlas += AgentSkillAtlasEntry::query()->where('model_market_performance_id', $performance->id)->count();
                    $edges += (int) ($blameResult['edge_count'] ?? 0);
                    $curriculumRuns += count($curriculumResult['runs'] ?? []);
                    $transferEntries += count($transferResult['entries'] ?? []);
                });
            } catch (\Throwable $exception) {
                $failed++;
                $this->warn("Performance {$performance->id} backfill failed: ".$exception->getMessage());
            }
        }

        $this->table(['metric', 'count'], [
            ['atlas_entries_estimated', $atlas],
            ['blame_edges', $edges],
            ['failure_case_runs', $curriculumRuns],
            ['transfer_matrix_entries_estimated', $transferEntries],
            ['failed_rows', $failed],
        ]);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
