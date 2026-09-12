<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Protects settlement debt without globally locking other research lanes.
 * A bounded, cache-leased interleave slot preserves canonical replay safety
 * while allowing one independent prior to make progress between Edge waves.
 */
class CanonicalResearchLanePriorityService
{
    public function __construct(
        private DependencyAwareEdgeGenesisFoundryService $edge,
        private CausalStageMasteryDirectorService $stageMastery,
        private CausalProgressRatchetGovernorService $ratchetGovernor,
    ) {}

    /** @return array<string,mixed> */
    public function edgeGenesisOwnership(string $symbol = 'XAUUSD', string $timeframe = 'H1'): array
    {
        $symbol = strtoupper(str_replace(['/', '_', '-'], '', trim($symbol)));
        $timeframe = strtoupper(trim($timeframe));
        if ($symbol === strtoupper((string) config('services.xauusd_organism.symbol', 'XAUUSD'))) {
            $timeframe = strtoupper((string) config('services.xauusd_organism.laboratory_storage_timeframe', 'H1'));
        }

        if (! Schema::hasTable('edge_genesis_passports') || ! Schema::hasTable('edge_genesis_trials')) {
            return $this->result(false, $symbol, $timeframe, 0, 0);
        }

        $passports = DB::table('edge_genesis_passports')
            ->where('symbol', $symbol)
            ->where('timeframe', $timeframe)
            ->whereIn('status', ['queued', 'running']);
        $passportIds = (clone $passports)->pluck('id');
        $pendingTrials = $passportIds->isEmpty() ? 0 : DB::table('edge_genesis_trials')
            ->whereIn('edge_genesis_passport_id', $passportIds)
            ->whereIn('status', ['queued', 'running', 'edge_progressing'])
            ->count();

        if ($passportIds->isNotEmpty()) {
            $allocation = $this->ratchetGovernor->allocate($symbol, $timeframe, true);
            $debt = (array) ($allocation['debt'] ?? $this->stageMastery->promotionDebt($symbol, $timeframe));
            $cooldown = max(5, (int) config('services.edge_director.fair_interleave_cooldown_seconds', 30));
            $lease = "canonical-research-interleave:{$symbol}:{$timeframe}";
            $consolidationRequired = (bool) ($debt['high_value_debt'] ?? false) || (bool) ($debt['consolidation_required'] ?? false);
            if (! $consolidationRequired && Cache::add($lease, now()->utc()->toIso8601String(), now()->addSeconds($cooldown))) {
                return $this->result(false, $symbol, $timeframe, $passportIds->count(), $pendingTrials,
                    'fair_interleave_slot', $debt);
            }

            return $this->result(true, $symbol, $timeframe, $passportIds->count(), $pendingTrials,
                $consolidationRequired ? 'promotion_debt_consolidation' : 'active_edge_state_machine', [...$debt, 'allocation' => $allocation]);
        }

        if (! (bool) config('services.edge_director.autonomous_specialized_cohorts_enabled', false)) {
            return $this->result(false, $symbol, $timeframe, 0, 0, 'normal_twenty_generation_mode');
        }

        // Reserve the expensive lane during the short boundary between a
        // terminal discovery cohort and its evidence-admitted repair wave.
        // Otherwise another long prior replay can enter after settlement but
        // before the autonomous director materializes the repair generation.
        $repair = $this->edge->architectureRepairReadiness($symbol, $timeframe);
        if (($repair['admitted'] ?? false) === true) {
            return $this->result(true, $symbol, $timeframe, 0, 0, 'causally_admitted_edge_architecture_repair');
        }

        return $this->result(false, $symbol, $timeframe, 0, 0);
    }

    /** @return array<string,mixed> */
    private function result(bool $owned, string $symbol, string $timeframe, int $passports, int $trials, ?string $reservationReason = null, array $debt = []): array
    {
        return [
            'protocol' => 'canonical_research_lane_priority_v2',
            'owned' => $owned,
            'owner' => $owned ? 'edge_genesis' : null,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'active_passports' => $passports,
            'pending_trials' => $trials,
            'reservation_reason' => $reservationReason,
            'promotion_debt' => $debt,
            'promotion_evidence' => false,
        ];
    }
}
