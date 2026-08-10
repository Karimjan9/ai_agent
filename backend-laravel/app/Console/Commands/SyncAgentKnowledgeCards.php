<?php

namespace App\Console\Commands;

use App\Models\LabAgent;
use App\Models\ModelMarketPerformance;
use App\Services\AgentKnowledgeService;
use App\Services\AgentProgressCardService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncAgentKnowledgeCards extends Command
{
    protected $signature = 'trading:sync-agent-knowledge
        {--symbol= : Restrict the projection to one symbol}
        {--timeframe= : Restrict the projection to one timeframe}
        {--generation= : Restrict the projection to one lab generation number}
        {--limit=0 : Maximum number of agents; zero means all missing cards}
        {--refresh : Rebuild existing cards as well as missing cards}
        {--progress-only : Build progress cards without rewriting knowledge cards}
        {--missing-progress : Restrict the projection to agents without a progress card}
        {--missing-tactic : Restrict the projection to cards without a tactic contract}
        {--tactic-only : Fill tactic projection columns without recomputing lifecycle evidence}';

    protected $description = 'Backfill agent knowledge cards and append-only lessons from existing immutable lab evidence';

    public function handle(AgentKnowledgeService $knowledge, AgentProgressCardService $progress, \App\Services\TacticCatalogueService $tactics): int
    {
        $query = LabAgent::query()->with(['modelVersion', 'generation', 'progressCard']);
        if ($symbol = $this->option('symbol')) $query->where('symbol', strtoupper((string) $symbol));
        if ($timeframe = $this->option('timeframe')) $query->where('timeframe', strtoupper((string) $timeframe));
        if ($generation = $this->option('generation')) {
            $query->whereHas('generation', fn ($builder) => $builder->where('generation', (int) $generation));
        }
        if (! $this->option('refresh') && ! $this->option('missing-tactic') && ! $this->option('missing-progress') && ! $this->option('tactic-only')) {
            $query->where(function ($builder): void {
                $builder->whereDoesntHave('knowledgeCard')
                    ->orWhereDoesntHave('progressCard');
            });
        }
        if ($this->option('missing-progress')) $query->whereDoesntHave('progressCard');
        if ($this->option('missing-tactic')) {
            $query->whereHas('progressCard', fn ($builder) => $builder->whereNull('tactic_id'));
        }
        if ($this->option('tactic-only')) {
            $query->whereHas('progressCard', fn ($builder) => $builder->whereNull('tactic_id'));
            $query->orderBy('id')->chunkById(100, function ($agents) use ($tactics): void {
                foreach ($agents as $agent) {
                    $metadata = (array) ($agent->modelVersion?->metadata ?? []);
                    $contract = data_get($metadata, 'tactic_contract');
                    if (! is_array($contract) || $contract === []) {
                        $contract = $tactics->for(
                            (string) $agent->strategy_family,
                            (string) data_get($metadata, 'strategy_architecture', $agent->strategy_family),
                            data_get($metadata, 'generation_target'),
                        );
                    }
                    $agent->progressCard?->update([
                        'tactic_id' => data_get($contract, 'tactic_id'),
                        'tactic_contract' => $contract,
                    ]);
                }
            });
            $this->info('Tactic projection completed without replay/lifecycle recomputation.');
            return self::SUCCESS;
        }
        $limit = max(0, (int) $this->option('limit'));
        if ($limit > 0) $query->limit($limit);

        $screened = 0;
        $full = 0;
        $baseline = 0;
        $skipped = 0;
        $failed = 0;
        $processed = 0;

        $progressOnly = (bool) $this->option('progress-only');
        $query->orderBy('id')->chunkById(100, function ($agents) use ($knowledge, $progress, $progressOnly, &$screened, &$full, &$baseline, &$skipped, &$failed, &$processed): void {
            foreach ($agents as $agent) {
                $processed++;
                try {
                    $performance = ModelMarketPerformance::query()
                        ->where('model_version_id', $agent->model_version_id)
                        ->where('symbol', $agent->symbol)
                        ->where('timeframe', $agent->timeframe)
                        ->where('evidence_status', 'valid')
                        ->whereNotNull('metrics')
                        ->latest('id')
                        ->first();

                    if ($progressOnly) {
                        $result = $performance?->metrics;
                        if (! is_array($result) || $result === []) {
                            $result = data_get($agent->modelVersion?->metadata, 'last_screen_result', []);
                        }
                        $progress->sync($agent->fresh(['modelVersion', 'generation']), $performance, is_array($result) ? $result : []);
                        if ($performance && is_array($performance->metrics) && $performance->metrics !== []) $full++;
                        elseif (is_array($result) && $result !== []) $screened++;
                        else $baseline++;
                        continue;
                    }

                    if ($performance && is_array($performance->metrics) && $performance->metrics !== []) {
                        $knowledge->recordFullReplay(
                            $agent,
                            $performance,
                            $performance->metrics,
                            data_get($performance->metrics, 'evidence_run_id'),
                        );
                        $progress->sync($agent->fresh(['modelVersion', 'generation']), $performance, $performance->metrics);
                        $full++;
                        continue;
                    }

                    $screen = data_get($agent->modelVersion?->metadata, 'last_screen_result');
                    if (is_array($screen) && $screen !== []) {
                        $knowledge->recordScreening($agent, $screen, data_get($screen, 'evidence_run_id'));
                        $progress->sync($agent->fresh(['modelVersion', 'generation']), null, $screen);
                        $screened++;
                        continue;
                    }

                    $knowledge->recordBaseline($agent);
                    $progress->sync($agent->fresh(['modelVersion', 'generation']));
                    $baseline++;
                } catch (Throwable $exception) {
                    $failed++;
                    Log::warning('Agent knowledge card backfill failed; continuing with the next agent.', [
                        'lab_agent_id' => $agent->id,
                        'model_version_id' => $agent->model_version_id,
                        'exception_class' => get_class($exception),
                        'message' => $exception->getMessage(),
                    ]);
                }
            }
        });

        $this->info("Knowledge projection processed={$processed}, full={$full}, screening={$screened}, baseline_no_evidence={$baseline}, skipped={$skipped}, failed={$failed}.");
        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
