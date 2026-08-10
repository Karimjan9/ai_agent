<?php

namespace App\Console\Commands;

use App\Models\EliteAgentPortfolio;
use App\Models\ModelMarketPerformance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Move stale operational projections out of the active elite/paper path.
 * Metrics, gate decisions and frozen forward payloads are preserved intact.
 */
class QuarantineStaleEliteEvidence extends Command
{
    protected $signature = 'trading:quarantine-stale-elite-evidence {--dry-run : Report stale rows without changing projections}';

    protected $description = 'Quarantine legacy council/passport projections before the role-complete council rebuild';

    public function handle(): int
    {
        $portfolioRows = EliteAgentPortfolio::query()->with('members.performance.modelVersion')->get();
        $stalePortfolios = $portfolioRows->filter(function (EliteAgentPortfolio $portfolio): bool {
            $roles = $portfolio->members->pluck('role')->filter()->values();
            $hasRouter = $roles->contains('transition_risk_router');
            $hasCombinedEvidence = data_get($portfolio->evidence, 'gate.status') === 'passed';
            $memberContractsComplete = $portfolio->members->isNotEmpty() && $portfolio->members->every(
                fn ($member): bool => data_get($member->performance?->modelVersion?->metadata, 'role_complete_council.protocol') === 'role_complete_council_v1',
            );
            $roleComplete = data_get($portfolio->route_policy, 'router') === 'sealed_regime_volatility_direction_ownership_v1'
                && $hasRouter && $memberContractsComplete;

            return $portfolio->status !== 'quarantined'
                && (! $roleComplete || ! $hasCombinedEvidence || $portfolio->last_evaluated_at === null);
        });

        $stalePassports = ModelMarketPerformance::query()
            ->with('modelVersion')
            ->where('evidence_status', 'valid')
            ->get()
            ->filter(function (ModelMarketPerformance $performance): bool {
                if (data_get($performance->metrics, 'elite_agent_passport.status') !== 'passed') return false;
                if ((bool) data_get($performance->metrics, 'portfolio_proxy', false)) return false;

                return data_get($performance->modelVersion?->metadata, 'role_complete_council.protocol') !== 'role_complete_council_v1';
            });

        // A legacy paper projection can remain visible as `paper/running`
        // even after its forward evidence was invalidated.  It is excluded
        // by the execution service, but leaving the projection active makes
        // observability and future integrations unsafe.  Quarantine the
        // projection while preserving every metric, gate decision and order
        // row for audit.
        $legacyPaperProjections = ModelMarketPerformance::query()
            ->with('modelVersion')
            ->where(function ($query): void {
                $query->where('status', 'paper')->orWhere('paper_status', 'running');
            })
            ->where(function ($query): void {
                $query->where('evidence_status', '!=', 'valid')->orWhereNotNull('invalidated_at');
            })
            ->get();

        $this->table(['projection', 'id', 'symbol', 'timeframe', 'reason'], [
            ...$stalePortfolios->map(fn (EliteAgentPortfolio $portfolio): array => [
                'portfolio', $portfolio->id, $portfolio->symbol, $portfolio->timeframe,
                'legacy_or_incomplete_council_requires_role_complete_rebuild',
            ])->all(),
            ...$stalePassports->map(fn (ModelMarketPerformance $performance): array => [
                'passport', $performance->id, $performance->symbol, $performance->timeframe,
                'legacy_passport_without_role_complete_council_contract',
            ])->all(),
            ...$legacyPaperProjections->map(fn (ModelMarketPerformance $performance): array => [
                'paper', $performance->id, $performance->symbol, $performance->timeframe,
                'invalidated_legacy_paper_projection',
            ])->all(),
        ]);

        if ($this->option('dry-run')) {
            $this->info('Dry-run: frozen metrics and forward decisions were not changed.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($stalePortfolios, $stalePassports, $legacyPaperProjections): void {
            foreach ($stalePortfolios as $portfolio) {
                $reasons = array_values(array_unique([
                    ...((array) $portfolio->gate_reasons),
                    'STALE_COUNCIL_REQUIRES_ROLE_COMPLETE_REBUILD',
                ]));
                $evidence = (array) $portfolio->evidence;
                $evidence['operational_quarantine'] = [
                    'protocol' => 'stale_elite_evidence_quarantine_v1',
                    'status' => 'quarantined',
                    'reason' => 'legacy_or_incomplete_council',
                    'frozen_forward_unchanged' => true,
                    'quarantined_at' => now()->utc()->toIso8601String(),
                ];
                $portfolio->update([
                    'status' => 'quarantined', 'gate_status' => 'quarantined',
                    'gate_reasons' => $reasons, 'evidence' => $evidence,
                    'last_evaluated_at' => now(),
                ]);
            }

            foreach ($stalePassports as $performance) {
                $model = $performance->modelVersion;
                $reason = 'legacy_passport_without_role_complete_council_contract';
                $performance->update([
                    'evidence_status' => 'stale_quarantine',
                    'invalidated_at' => now(),
                    'invalidation_reason' => $reason,
                ]);
                if ($model) {
                    $metadata = (array) $model->metadata;
                    $metadata['operational_quarantine'] = [
                        'protocol' => 'stale_elite_evidence_quarantine_v1',
                        'status' => 'quarantined', 'reason' => $reason,
                        'frozen_forward_unchanged' => true,
                        'quarantined_at' => now()->utc()->toIso8601String(),
                    ];
                    $model->update([
                        'evidence_status' => 'stale_quarantine',
                        'invalidated_at' => now(),
                        'invalidation_reason' => $reason,
                        'metadata' => $metadata,
                    ]);
                }
            }

            foreach ($legacyPaperProjections as $performance) {
                $reason = 'invalidated_legacy_paper_projection_without_valid_forward_passport';
                $performance->update([
                    'status' => 'stagnated',
                    'paper_status' => 'failed',
                    'evidence_status' => $performance->evidence_status ?: 'legacy_invalid',
                    'invalidated_at' => $performance->invalidated_at ?: now(),
                    'invalidation_reason' => $reason,
                ]);

                $model = $performance->modelVersion;
                if ($model) {
                    $metadata = (array) $model->metadata;
                    $metadata['operational_quarantine'] = [
                        'protocol' => 'stale_elite_evidence_quarantine_v1',
                        'status' => 'quarantined', 'reason' => $reason,
                        'frozen_forward_unchanged' => true,
                        'quarantined_at' => now()->utc()->toIso8601String(),
                    ];
                    $model->update([
                        'evidence_status' => $performance->evidence_status ?: 'legacy_invalid',
                        'invalidated_at' => $performance->invalidated_at ?: now(),
                        'invalidation_reason' => $reason,
                        'metadata' => $metadata,
                    ]);
                }

                DB::table('paper_orders')
                    ->where('model_market_performance_id', $performance->id)
                    ->where('evidence_status', 'valid')
                    ->update([
                        'status' => 'invalidated',
                        'evidence_status' => 'legacy_invalid',
                        'invalidated_at' => now(),
                        'invalidation_reason' => $reason,
                        'updated_at' => now(),
                    ]);
            }
        });

        $this->info("Quarantined {$stalePortfolios->count()} stale portfolio(s), {$stalePassports->count()} stale passport projection(s), and {$legacyPaperProjections->count()} legacy paper projection(s). Frozen forward payloads remained unchanged.");
        return self::SUCCESS;
    }
}
