<?php

namespace App\Services;

/**
 * Turns the three research libraries into a bounded, auditable cohort plan.
 *
 * This is intentionally a planner, rather than a selector at execution time:
 * each child has one immutable strategy/tactic/risk composition before the
 * paired control is materialised and before any replay can start.
 */
class StrategyTacticRiskCompositionPlannerService
{
    public const PROTOCOL = 'smart_composition_cohort_v1';

    public function __construct(
        private StrategyLibraryCompilerService $strategies,
        private TacticCatalogueService $tactics,
        private RiskManagementLibraryService $risks,
    ) {}

    /**
     * @param array<int, array<string, mixed>> $plan
     * @return array{plan: array<int, array<string, mixed>>, contract: array<string, mixed>}
     */
    public function materialize(
        array $plan,
        int $generation = 0,
        array $enabledFamilies = [],
        array $lineageFamilies = [],
    ): array
    {
        // The contract is meaningful only for a normal full cohort. Smaller
        // recovery and operator-approved rescue cohorts retain their sealed
        // curriculum rather than being silently repurposed.
        if (count($plan) < 20) {
            return ['plan' => $plan, 'contract' => [
                'protocol' => self::PROTOCOL,
                'status' => 'not_applicable_bounded_cohort',
                'reason' => 'A full 20-seat cohort is required for the 6/6/5/3 composition budget.',
                'promotion_evidence' => false,
            ]];
        }

        $strategyRuntimes = collect($this->strategies->library())
            ->map(fn (array $spec): array => ['id' => $spec['id'], 'runtime' => $this->strategies->runtime($spec['id'])])
            ->filter(fn (array $entry): bool => $entry['runtime'] !== null)
            ->filter(fn (array $entry): bool => $enabledFamilies === [] || in_array($entry['runtime']['family'], $enabledFamilies, true))
            ->values()->all();
        $riskProfiles = $this->risks->library();
        $tacticKeys = ['trend_pullback', 'breakout_retest', 'volatility_compression_expansion', 'session_breakout', 'range_mean_reversion'];

        $structural = collect($plan)->keys()
            ->filter(fn (int $index): bool => (bool) data_get($plan[$index], 'niche.structural_research', false))
            ->take(3)->values()->all();
        $available = collect($plan)->keys()->reject(fn (int $index): bool => in_array($index, $structural, true))->values();
        // A validated frontier must be allowed to reproduce. Preserve at
        // most half of the strategy-composition budget for exact families
        // that already have reusable parent evidence; the remaining seats
        // continue exploring the compiled strategy library.
        $lineageFamilies = collect($lineageFamilies)
            ->map(fn ($family): string => trim((string) $family))
            ->filter(fn (string $family): bool => $family !== '' && in_array($family, $enabledFamilies, true))
            ->unique()->values();
        $lineageSeats = collect();
        foreach ($lineageFamilies as $family) {
            $templateIndex = $available->first(fn (int $index): bool =>
                (string) data_get($plan[$index], 'family', '') === $family
                && ! $lineageSeats->contains($index)
            );
            if ($templateIndex === null) continue;

            // Learning credit requires an exact same-generation frozen
            // control. Reserve a pair, not a lone child: one of these seats
            // becomes the immutable control and the other remains the
            // lineage mutation candidate.
            $familyCounts = collect($plan)->countBy(fn (array $slot): string => (string) data_get($slot, 'family', ''));
            $donorIndex = $available
                ->reject(fn (int $index): bool => $index === $templateIndex || $lineageSeats->contains($index))
                // Never consume the second and last seat of another family:
                // doing so would leave its frozen control without a candidate.
                ->filter(fn (int $index): bool => (int) $familyCounts->get((string) data_get($plan[$index], 'family', ''), 0) >= 3)
                ->sortByDesc(fn (int $index): int => (int) $familyCounts->get((string) data_get($plan[$index], 'family', ''), 0))
                ->first();
            if ($donorIndex === null) continue;
            $lineageSeats->push($templateIndex, $donorIndex);
            $plan[$donorIndex]['family'] = $family;
            $templateNiche = (array) data_get($plan[$templateIndex], 'niche', []);
            $donorNiche = (array) data_get($plan[$donorIndex], 'niche', []);
            foreach (['role', 'specialist_role', 'regime', 'volatility', 'direction', 'data_lane', 'volume_shadow'] as $key) {
                if (array_key_exists($key, $templateNiche)) {
                    $donorNiche[$key] = $templateNiche[$key];
                } else {
                    unset($donorNiche[$key]);
                }
            }
            $plan[$donorIndex]['niche'] = $donorNiche;
            if ($lineageSeats->count() >= 4) break;
        }
        $strategySeats = $lineageSeats
            ->concat($available->reject(fn (int $index): bool => $lineageSeats->contains($index)))
            ->take(6)->values()->all();
        $remaining = $available->reject(fn (int $index): bool => in_array($index, $strategySeats, true))->values()->all();
        $tacticSeats = array_slice($remaining, 0, 6);
        $riskSeats = array_slice($remaining, 6, 5);

        // A structural floor can leave fewer than three explicitly selected
        // topology seats only in a malformed normal plan. Record it fail
        // closed in the audit instead of inventing topology metadata.
        $valid = $strategyRuntimes !== [] && count($structural) === 3 && count($strategySeats) === 6 && count($tacticSeats) === 6 && count($riskSeats) === 5;
        if (! $valid) {
            return ['plan' => $plan, 'contract' => [
                'protocol' => self::PROTOCOL, 'status' => 'not_admitted',
                'reason' => 'The final plan does not contain enough independent seats for the 6/6/5/3 contract.',
                'promotion_evidence' => false,
            ]];
        }

        // Five anchors guarantee a same-family candidate for every tactic
        // control. The sixth seat rotates through all executable specs, so
        // the library is explored across generations without leaving a lone
        // family that cannot receive its required frozen paired baseline.
        $anchorIds = ['str_001_ema_adx_pullback', 'str_003_donchian_breakout', 'str_010_bollinger_squeeze', 'str_020_bb_rsi_reversion', 'str_040_asia_london_breakout'];
        $byId = collect($strategyRuntimes)->keyBy('id');
        $nonLineageStrategySeats = collect($strategySeats)
            ->reject(fn (int $index): bool => $lineageSeats->contains($index))
            ->values();
        $familyFloor = collect($plan)
            ->reject(fn (array $_slot, int $index): bool => $nonLineageStrategySeats->contains($index))
            ->countBy(fn (array $slot): string => (string) data_get($slot, 'family', ''));
        $anchorSelection = collect($anchorIds)
            ->map(fn (string $id): ?array => $byId->get($id))
            ->filter()
            // Fill families that would otherwise have only a control before
            // spending a seat on a third same-family strategy.
            ->sortBy(fn (array $entry): array => [
                (int) $familyFloor->get($entry['runtime']['family'], 0),
                array_search($entry['id'], $anchorIds, true),
            ])
            ->values();
        $rotating = $strategyRuntimes[$generation % count($strategyRuntimes)];
        $strategySelection = $anchorSelection
            ->push($rotating)
            ->concat($strategyRuntimes)
            ->unique('id')
            ->take($nonLineageStrategySeats->count())
            ->values()->all();
        $libraryOffset = 0;
        foreach ($strategySeats as $index) {
            if ($lineageSeats->contains($index)) {
                $plan[$index]['niche'] = [...(array) data_get($plan[$index], 'niche', []),
                    'composition_lane' => 'validated_lineage_continuation',
                    'lineage_family' => (string) data_get($plan[$index], 'family'),
                    'validated_parent_required' => true,
                ];
                continue;
            }
            $entry = $strategySelection[$libraryOffset++];
            $id = $entry['id'];
            $plan[$index]['niche'] = [...(array) data_get($plan[$index], 'niche', []),
                'composition_lane' => 'strategy_composition',
                'strategy_library_id' => $id,
                'strategy_library_contract' => $this->strategies->compile($id),
                'composition_architecture' => $entry['runtime']['architecture'],
            ];
            $plan[$index]['family'] = $entry['runtime']['family'];
        }
        foreach ($tacticSeats as $offset => $index) {
            $key = $tacticKeys[$offset % count($tacticKeys)];
            $runtime = match ($key) {
                'trend_pullback' => ['family' => 'trend', 'architecture' => 'trend_pullback'],
                'breakout_retest' => ['family' => 'breakout', 'architecture' => 'breakout_retest'],
                'volatility_compression_expansion' => ['family' => 'volatility', 'architecture' => 'volatility_compression_expansion'],
                'session_breakout' => ['family' => 'session', 'architecture' => 'session_breakout'],
                default => ['family' => 'mean_reversion', 'architecture' => 'range_mean_reversion'],
            };
            $plan[$index]['niche'] = [...(array) data_get($plan[$index], 'niche', []),
                'composition_lane' => 'tactic_mutation',
                'tactic_library_key' => $key,
                'composition_architecture' => $runtime['architecture'],
            ];
            $plan[$index]['family'] = $runtime['family'];
        }
        foreach ($riskSeats as $offset => $index) {
            $profile = $riskProfiles[$offset];
            $plan[$index]['niche'] = [...(array) data_get($plan[$index], 'niche', []),
                'composition_lane' => 'risk_management_mutation',
                'risk_library_id' => $profile['id'],
                'risk_library_contract' => $this->risks->compile($profile['id']),
                // The agent constructor makes this exact gene the only
                // executable difference from its frozen paired baseline.
                'risk_mutation_gene' => $profile['gene'],
            ];
            // Keep risk as a true specialist contrast: the five profiles
            // share one executable strategy baseline and differ only in the
            // profile-selected risk gene.
            $plan[$index]['family'] = in_array('hybrid', $enabledFamilies, true) ? 'hybrid' : $plan[$index]['family'];
        }
        foreach ($structural as $index) {
            $plan[$index]['niche'] = [...(array) data_get($plan[$index], 'niche', []), 'composition_lane' => 'structural_topology_experiment'];
        }

        return ['plan' => $plan, 'contract' => [
            'protocol' => self::PROTOCOL,
            'status' => 'admitted',
            'budget' => ['strategy_composition' => 6, 'tactic_mutation' => 6, 'risk_management_mutation' => 5, 'structural_topology_experiment' => 3],
            'paired_control_required_for' => ['strategy_composition', 'tactic_mutation', 'risk_management_mutation', 'structural_topology_experiment'],
            'strategy_library_rotation_offset' => $generation,
            'lineage_continuation_families' => $lineageSeats
                ->map(fn (int $index): string => (string) data_get($plan[$index], 'family'))
                ->unique()->values()->all(),
            'lineage_continuation_budget' => $lineageSeats->count(),
            'library_return_rule' => 'Only independently confirmed, paired-control winners may be consolidated into a library posterior; screening never promotes a composition.',
            'promotion_evidence' => false,
        ]];
    }
}
