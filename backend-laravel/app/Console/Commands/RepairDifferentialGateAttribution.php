<?php

namespace App\Console\Commands;

use App\Models\CandidateGateDecision;
use App\Models\LabAgent;
use App\Services\CandidateGateDecisionService;
use Illuminate\Console\Command;

/** Corrects historical false non-target failures created by an empty contract. */
class RepairDifferentialGateAttribution extends Command
{
    protected $signature = 'trading:repair-differential-gate-attribution {symbol?} {generation?} {--timeframe=H1}';

    protected $description = 'Recompute screening ledgers for ordinary agents that were falsely marked as differential regressions';

    public function handle(CandidateGateDecisionService $decisions): int
    {
        $symbol = $this->argument('symbol') ? strtoupper((string) $this->argument('symbol')) : null;
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $generation = $this->argument('generation') !== null ? (int) $this->argument('generation') : null;
        $repaired = 0;
        $skipped = 0;

        $agents = LabAgent::query()->with(['modelVersion', 'generation'])
            ->where('lifecycle_status', 'screened')
            ->where('timeframe', $timeframe)
            ->when($symbol, fn ($query) => $query->where('symbol', $symbol))
            ->when($generation !== null, fn ($query) => $query->whereHas('generation', fn ($generationQuery) => $generationQuery->where('generation', $generation)))
            ->orderBy('id')->get();

        foreach ($agents as $agent) {
            $model = $agent->modelVersion;
            $result = (array) data_get($model?->metadata, 'last_screen_result', []);
            if (! array_key_exists('differential_no_regression', $result)) continue;

            $contract = (array) data_get($model?->metadata, 'differential_router_contract', []);
            $router = (array) data_get($result, 'differential_router', []);
            $isDifferential = $contract !== []
                || data_get($router, 'enabled') === true
                || str_contains((string) data_get($model?->metadata, 'base_strategy', ''), 'differential_router');
            if ($isDifferential) {
                $skipped++;
                continue;
            }

            $oldDecision = CandidateGateDecision::query()
                ->where('lab_agent_id', $agent->id)->where('stage', 'screening')->first();
            $oldResultHash = hash('sha256', json_encode($result, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));
            unset($result['differential_no_regression']);
            $newResultHash = hash('sha256', json_encode($result, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));
            $metadata = $model->metadata ?? [];
            $repairs = (array) data_get($metadata, 'differential_gate_attribution_repairs', []);
            $repairs[] = [
                'repaired_at' => now()->utc()->toIso8601String(),
                'reason' => 'EMPTY_DIFFERENTIAL_CONTRACT_FALSELY_DEFAULTED_TO_TREND_DOWN',
                'old_result_hash' => $oldResultHash,
                'new_result_hash' => $newResultHash,
                'old_decision' => $oldDecision?->decision,
                'old_reason_codes' => $oldDecision?->reason_codes ?? [],
            ];
            data_set($metadata, 'differential_gate_attribution_repairs', array_slice($repairs, -3));
            data_set($metadata, 'last_screen_result', $result);
            $model->update(['metadata' => $metadata]);

            $decision = $decisions->recordScreening($agent->fresh(['modelVersion']), $result);
            $agent->update([
                'decision_reason' => $decision->decision === 'passed'
                    ? 'Screening ledger corrected after differential attribution repair; awaiting full-validation selection.'
                    : 'Screening ledger corrected after differential attribution repair; strict quality gates still apply.',
            ]);
            $repaired++;
        }

        $this->info("Repaired {$repaired} ordinary screening ledgers; {$skipped} genuine differential ledgers left unchanged.");
        return self::SUCCESS;
    }
}
