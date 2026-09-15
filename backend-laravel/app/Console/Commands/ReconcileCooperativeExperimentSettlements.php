<?php

namespace App\Console\Commands;

use App\Models\CandidateGateDecision;
use App\Models\CooperativeExperimentSettlement;
use App\Models\LabAgent;
use App\Services\CooperativeExperimentSettlementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/** Rebuild derived block credit only from terminal immutable arm evidence. */
class ReconcileCooperativeExperimentSettlements extends Command
{
    protected $signature = 'trading:reconcile-cooperative-settlements
        {symbol? : Optional market symbol}
        {--timeframe=H1}
        {--generation= : Explicit generation number; re-audits complete historical settlements too}
        {--limit=25}
        {--json}';

    protected $description = 'Reconcile cooperative block settlements from completed immutable runs without replay or promotion';

    public function handle(CooperativeExperimentSettlementService $settlements): int
    {
        if (! Schema::hasTable('cooperative_experiment_settlements')) {
            $this->warn('Cooperative settlement schema is not installed.');

            return self::SUCCESS;
        }
        $symbol = $this->argument('symbol') ? strtoupper((string) $this->argument('symbol')) : null;
        $timeframe = strtoupper((string) $this->option('timeframe'));
        $generation = $this->option('generation') !== null ? (int) $this->option('generation') : null;
        $limit = max(1, min(100, (int) $this->option('limit')));

        $query = CooperativeExperimentSettlement::query()
            ->with('generation.laboratory')
            ->whereHas('generation.laboratory', function ($query) use ($symbol, $timeframe): void {
                $query->where('timeframe', $timeframe);
                if ($symbol !== null) $query->where('symbol', $symbol);
            });
        if ($generation !== null) {
            $query->whereHas('generation', fn ($query) => $query->where('generation', $generation));
        } else {
            // A terminal invalid settlement is reconsidered by the exact
            // retry's post-screen projection (or an explicit generation
            // audit). Re-scanning it every minute would starve newer waiting
            // blocks and repeatedly rewrite the same invalidation receipt.
            $query->where('outcome_status', 'waiting_for_arms');
        }

        $rows = $query->orderBy('id')->limit($limit)->get();
        $results = [];
        foreach ($rows as $settlement) {
            $agents = LabAgent::query()->with('modelVersion', 'generation')
                ->where('lab_generation_id', $settlement->lab_generation_id)
                ->get()
                ->filter(fn (LabAgent $agent): bool => (string) data_get(
                    $agent->modelVersion?->metadata,
                    'cooperative_experiment_block.block_key',
                    '',
                ) === (string) $settlement->block_key)
                ->values();
            $representative = $agents->last();
            if (! $representative) {
                $results[] = [
                    'settlement_id' => (int) $settlement->id,
                    'block_key' => (string) $settlement->block_key,
                    'status' => 'block_agents_missing',
                    'evidence_complete' => false,
                ];
                continue;
            }

            $result = $settlements->observe($representative);
            foreach ($agents as $agent) {
                $decision = CandidateGateDecision::query()
                    ->where('lab_agent_id', $agent->id)
                    ->where('stage', 'screening')
                    ->latest('id')
                    ->first();
                if (! $decision) continue;
                $metrics = (array) $decision->metrics;
                $metrics['cooperative_experiment_settlement'] = $result;
                $decision->update(['metrics' => $metrics]);
            }
            $results[] = [
                'settlement_id' => (int) data_get($result, 'settlement_id', $settlement->id),
                'block_key' => (string) $settlement->block_key,
                'status' => (string) data_get($result, 'status', 'unknown'),
                'evidence_complete' => (bool) data_get($result, 'evidence_complete', false),
            ];
        }

        $payload = [
            'protocol' => 'cooperative_experiment_settlement_reconciliation_v1',
            'scope' => ['symbol' => $symbol, 'timeframe' => $timeframe, 'generation' => $generation],
            'scanned' => $rows->count(),
            'by_status' => collect($results)->countBy('status')->all(),
            'results' => $results,
            'replay_dispatched' => false,
            'promotion_evidence' => false,
        ];
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('Reconciled '.$rows->count().' cooperative experiment settlement(s); no replay or promotion was created.');
        }

        return self::SUCCESS;
    }
}
