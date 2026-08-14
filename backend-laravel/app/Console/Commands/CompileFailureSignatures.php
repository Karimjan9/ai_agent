<?php

namespace App\Console\Commands;

use App\Models\LabFailureRepairAnchor;
use App\Models\LabLearningInsight;
use App\Services\FailureSignatureCompilerService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Build diagnostic signature clusters without mutating immutable anchors. */
class CompileFailureSignatures extends Command
{
    protected $signature = 'trading:compile-failure-signatures {symbol?} {--timeframe=} {--family=} {--limit=1000}';

    protected $description = 'Cluster immutable failure anchors into diagnostic mutation signatures';

    public function handle(FailureSignatureCompilerService $compiler): int
    {
        $anchors = LabFailureRepairAnchor::query()
            ->when($this->argument('symbol'), fn ($query, $symbol) => $query->where('symbol', strtoupper((string) $symbol)))
            ->when($this->option('timeframe'), fn ($query, $timeframe) => $query->where('timeframe', strtoupper((string) $timeframe)))
            ->when($this->option('family'), fn ($query, $family) => $query->where('strategy_family', (string) $family))
            ->latest('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get()
            ->groupBy(function (LabFailureRepairAnchor $anchor) use ($compiler): string {
                return (string) data_get($compiler->fromAnchor($anchor), 'signature');
            });

        $created = 0;
        foreach ($anchors as $signature => $rows) {
            $first = $rows->first();
            $scope = implode('|', [
                strtoupper((string) $first->symbol), strtoupper((string) $first->timeframe),
                (string) $first->strategy_family,
            ]);
            $sourceHash = hash('sha256', implode('|', [
                FailureSignatureCompilerService::PROTOCOL,
                $scope,
                $signature,
            ]));
            $insight = LabLearningInsight::query()->firstOrCreate(
                ['source_hash' => $sourceHash],
                [
                    'insight_id' => (string) Str::uuid(),
                    'symbol' => $first->symbol,
                    'timeframe' => $first->timeframe,
                    'strategy_family' => $first->strategy_family,
                    'scope_key' => $scope,
                    'insight_type' => 'failure_signature',
                    'evidence_quality' => 'diagnostic',
                    'causal_prior_allowed' => false,
                    'confidence' => 0,
                    'source_generation_ids' => $rows->pluck('source_lab_generation_id')->filter()->unique()->values()->all(),
                    'source_agent_ids' => $rows->pluck('source_lab_agent_id')->filter()->unique()->values()->all(),
                    'source_run_ids' => $rows->flatMap(fn (LabFailureRepairAnchor $anchor): array => array_values(array_filter([
                        data_get($anchor->evidence, 'observed.evidence_run_id'),
                        data_get($anchor->evidence, 'screening_result.evidence_run_id'),
                    ])))->unique()->values()->all(),
                    'failure_signature' => $compiler->fromAnchor($first),
                    'metrics' => ['anchor_count' => $rows->count(), 'promotion_evidence' => false],
                    'recommended_mutations' => [
                        'target' => $first->failure_target,
                        'parameter_keys' => $rows->flatMap(fn (LabFailureRepairAnchor $anchor): array => array_keys((array) $anchor->parameter_diff))->unique()->values()->all(),
                        'rule' => 'Use one declared gene and paired control before causal credit.',
                    ],
                    'blocked_mutations' => [],
                    'conclusion' => 'Diagnostic failure cluster only; no promotion or permanent global ban.',
                    'generated_at' => now(),
                ],
            );
            $created += $insight->wasRecentlyCreated ? 1 : 0;
        }

        $this->info('Compiled '.count($anchors).' failure signature cluster(s); created '.$created.' new diagnostic insight(s).');

        return self::SUCCESS;
    }
}
