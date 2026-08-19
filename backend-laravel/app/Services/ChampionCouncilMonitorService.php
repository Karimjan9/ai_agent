<?php

namespace App\Services;

use App\Models\EliteAgentPortfolio;
use Illuminate\Support\Facades\Schema;

/** Read-only operational projection for the Champion Council lifecycle. */
class ChampionCouncilMonitorService
{
    public function __construct(private ChampionCouncilTransitionService $transition) {}

    /** @return array<string, mixed> */
    public function report(string $symbol, string $timeframe): array
    {
        $base = [
            'protocol' => 'champion_council_monitor_v1',
            'symbol' => strtoupper($symbol),
            'timeframe' => strtoupper($timeframe),
            'promotion_evidence' => false,
            'transition_policy' => $this->transition->policy(),
        ];
        try {
            if (! Schema::hasTable('elite_agent_portfolios')) {
                return [...$base, 'status' => 'migration_pending', 'councils' => []];
            }
        } catch (\Throwable) {
            return [...$base, 'status' => 'migration_pending', 'councils' => []];
        }

        $portfolios = EliteAgentPortfolio::query()
            ->with('members.performance.modelVersion')
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->latest('last_evaluated_at')
            ->get();

        $councils = $portfolios->map(function (EliteAgentPortfolio $portfolio): array {
            $members = $portfolio->members->map(function ($member): array {
                return [
                    'performance_id' => $member->model_market_performance_id,
                    'role' => $member->role,
                    'regime' => $member->target_regime,
                    'volatility' => $member->target_volatility,
                    'passport' => data_get($member->evidence, 'specialist_passport.status', 'unknown'),
                    'curriculum_stage' => data_get($member->evidence, 'next_curriculum.stage', 'unknown'),
                ];
            })->values()->all();
            $synergy = (array) data_get($portfolio->evidence, 'council_synergy', []);

            return [
                'id' => $portfolio->id,
                'status' => $portfolio->status,
                'gate_status' => $portfolio->gate_status,
                'member_count' => $portfolio->member_count,
                'roles' => collect($members)->pluck('role')->unique()->values()->all(),
                'members' => $members,
                'compatibility' => data_get($portfolio->route_policy, 'council_compatibility', []),
                'synergy' => $synergy,
                'gate_reasons' => $portfolio->gate_reasons ?? [],
                'last_evaluated_at' => $portfolio->last_evaluated_at?->toIso8601String(),
            ];
        })->values()->all();

        return [
            ...$base,
            'status' => $councils === [] ? 'waiting_for_council' : 'observed',
            'council_count' => count($councils),
            'active_count' => collect($councils)->where('gate_status', 'passed')->count(),
            'validated_member_count' => collect($councils)->sum(fn (array $council): int => collect($council['members'])->where('passport', 'passed')->count()),
            'councils' => $councils,
        ];
    }
}
