<?php

namespace App\Console\Commands;

use App\Models\LabEvaluationRun;
use App\Models\ModelMarketPerformance;
use App\Services\LabImmutableEvidenceService;
use App\Services\MutationResponseMapService;
use Illuminate\Console\Command;

/**
 * Replays no strategy and changes no gate. It only projects already-complete
 * immutable evaluation artifacts into the append-only mutation response map.
 * This is needed when the map migration is deployed after historical runs.
 */
class BackfillMutationResponseMap extends Command
{
    protected $signature = 'trading:backfill-mutation-response-map
        {symbol? : Market symbol, for example XAUUSD}
        {--timeframe= : H1 or M15}
        {--generation= : Laboratory generation number}
        {--limit=1000 : Maximum immutable runs to inspect}
        {--apply : Persist the idempotent response-map projections}
        {--json : Print a machine-readable summary}';

    protected $description = 'Backfill immutable completed lab runs into the mutation response map without changing gates';

    public function handle(
        LabImmutableEvidenceService $evidence,
        MutationResponseMapService $responseMaps,
    ): int {
        $symbol = $this->argument('symbol') ? strtoupper((string) $this->argument('symbol')) : null;
        $timeframe = $this->option('timeframe') ? strtoupper((string) $this->option('timeframe')) : null;
        $generation = $this->option('generation') !== null ? (int) $this->option('generation') : null;
        $limit = max(1, min(5000, (int) $this->option('limit')));

        if ($symbol === null && $generation === null) {
            $this->error('Scope belgilang: symbol yoki --generation.');
            return self::INVALID;
        }

        $runs = LabEvaluationRun::query()
            ->with(['agent.modelVersion', 'generation.laboratory'])
            ->whereIn('phase', ['screening', 'full_validation'])
            ->where('status', 'completed')
            ->whereNotNull('lab_agent_id')
            ->when($symbol !== null, fn ($builder) => $builder->whereHas(
                'agent', fn ($agent) => $agent->where('symbol', $symbol),
            ))
            ->when($timeframe !== null, fn ($builder) => $builder->whereHas(
                'agent', fn ($agent) => $agent->where('timeframe', $timeframe),
            ))
            ->when($generation !== null, fn ($builder) => $builder->whereHas(
                'generation', fn ($labGeneration) => $labGeneration->where('generation', $generation),
            ))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $rows = [];
        $eligible = 0;
        $applied = 0;
        $skipped = 0;

        foreach ($runs as $run) {
            $agent = $run->agent;
            if (! $agent) {
                $skipped++;
                continue;
            }

            $eligibility = $evidence->learningEligibility($run);
            if (! (bool) data_get($eligibility, 'complete', false)) {
                $skipped++;
                $rows[] = [
                    'run_id' => $run->run_id,
                    'agent_id' => $agent->id,
                    'phase' => $run->phase,
                    'action' => 'skipped_incomplete_evidence',
                    'reason_codes' => data_get($eligibility, 'reason_codes', []),
                ];
                continue;
            }

            $payload = $evidence->latestArtifactPayload($run, 'evaluation_response');
            if (! is_array($payload) || $payload === []) {
                $skipped++;
                $rows[] = [
                    'run_id' => $run->run_id,
                    'agent_id' => $agent->id,
                    'phase' => $run->phase,
                    'action' => 'skipped_missing_response_artifact',
                ];
                continue;
            }

            $payload['evidence_run_id'] = $run->run_id;
            $performance = null;
            $verification = null;
            if ($run->phase === 'full_validation') {
                $performance = ModelMarketPerformance::query()
                    ->where('model_version_id', $agent->model_version_id)
                    ->where('symbol', $agent->symbol)
                    ->where('timeframe', $agent->timeframe)
                    ->latest('id')
                    ->first();
                // Immutable response is authoritative. Performance metrics
                // are only a fallback for post-replay verification contracts
                // written after the response artifact; they must not replace
                // the immutable response's economic metrics.
                $payload = [
                    ...array_replace_recursive((array) ($performance?->metrics ?? []), $payload),
                    'evidence_run_id' => $run->run_id,
                ];
                $verification = (array) data_get(
                    $payload,
                    'repair_anchor_verification',
                    data_get($payload, 'verified_mutation_skill', []),
                );
            }

            $eligible++;
            $row = [
                'run_id' => $run->run_id,
                'agent_id' => $agent->id,
                'phase' => $run->phase,
                'action' => $this->option('apply') ? 'applied' : 'would_apply',
            ];
            if ($this->option('apply')) {
                $map = $run->phase === 'screening'
                    ? $responseMaps->recordScreening($agent->fresh(['modelVersion']), $payload)
                    : $responseMaps->recordFullReplay(
                        $agent->fresh(['modelVersion']),
                        $payload,
                        $performance,
                        $verification,
                    );
                if ($map !== null) {
                    $applied++;
                    $row['response_map_id'] = $map['id'] ?? null;
                    $row['status'] = $map['status'] ?? null;
                } else {
                    $skipped++;
                    $row['action'] = 'skipped_projection_failure';
                }
            }
            $rows[] = $row;
        }

        $summary = [
            'protocol' => MutationResponseMapService::PROTOCOL,
            'scope' => [
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'generation' => $generation,
                'limit' => $limit,
            ],
            'dry_run' => ! (bool) $this->option('apply'),
            'inspected_runs' => $runs->count(),
            'eligible_runs' => $eligible,
            'applied_rows' => $applied,
            'skipped_runs' => $skipped,
            'promotion_evidence' => false,
            'rows' => $rows,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(($this->option('apply') ? 'Response-map backfill applied' : 'Response-map backfill dry-run').'.');
            $this->line("Inspected: {$summary['inspected_runs']}; eligible: {$eligible}; applied: {$applied}; skipped: {$skipped}.");
            $this->warn('Gate, lifecycle, parent and promotion projections were not changed.');
        }

        return self::SUCCESS;
    }
}
