<?php

namespace App\Services;

use App\Models\AiLaboratory;
use App\Models\Candle;
use App\Models\InstrumentValuePosterior;
use App\Models\LabAgent;
use App\Models\Symbol;
use Illuminate\Support\Facades\Schema;

/**
 * Allocates one ordinary twenty-seat population as contextual causal pairs.
 * It decides where to spend research compute; it never grants promotion,
 * parent authority or a positive instrument posterior.
 */
class ContextualCouncilAllocatorService
{
    public const PROTOCOL = 'contextual_council_allocator_v1';

    private const GROUPS = [
        'monthly_survival' => ['axis' => 'temporal_robustness', 'prior' => 1.25],
        'regime_coverage' => ['axis' => 'regime_coverage', 'prior' => 1.20],
        'volatility_session_stability' => ['axis' => 'cost_stability', 'prior' => 1.35],
        'exit_topology' => ['axis' => 'risk_exit', 'prior' => 1.10],
        'portfolio_router' => ['axis' => 'portfolio_integrity', 'prior' => .80],
    ];

    public function __construct(private MarketSessionCalendarService $calendar) {}

    /** @return array{plan: array<int, array<string, mixed>>, contract: array<string, mixed>} */
    public function allocate(array $plan, AiLaboratory $lab, array $governorSnapshot = []): array
    {
        $plan = array_values($plan);
        if (count($plan) !== 20) {
            return [
                'plan' => $plan,
                'contract' => $this->contract($plan, [], [], 'not_applicable_non_twenty_seat_population'),
            ];
        }

        $proof = collect($plan)->filter(fn (array $slot): bool => $this->primaryProofSeat($slot))->values()->all();
        $freeSeats = count($plan) - count($proof);
        $pairBudget = intdiv($freeSeats, 2);
        $evidence = $this->evidence($lab);
        $pairQuotas = $this->pairQuotas($pairBudget, $evidence, $governorSnapshot);
        $buckets = $this->templateBuckets($plan);
        $reference = $this->referenceTimestamp($lab);
        $sessions = ['asia', 'london', 'new_york', 'overlap'];
        $sessionAllocations = array_fill_keys($sessions, 0);
        $researchFamilies = collect($plan)->pluck('family')->filter()->unique()->values()->all();
        $generationCursor = ((int) ($lab->generations()->max('generation') ?? 0)) + 1;
        $dynamic = [];
        $cells = [];
        $pairOrdinal = 0;

        foreach ($pairQuotas as $group => $pairs) {
            $templates = $buckets[$group] ?? $plan;
            for ($pair = 0; $pair < $pairs; $pair++) {
                $desiredFamily = $researchFamilies[$pairOrdinal % max(1, count($researchFamilies))] ?? null;
                $template = collect($templates)->first(
                    fn (array $candidate): bool => filled($desiredFamily) && ($candidate['family'] ?? null) === $desiredFamily,
                ) ?? collect($plan)->first(
                    fn (array $candidate): bool => filled($desiredFamily)
                        && ($candidate['family'] ?? null) === $desiredFamily
                        && ! $this->primaryProofSeat($candidate),
                ) ?? (array) $templates[$pair % max(1, count($templates))];
                $session = $this->selectSession(
                    $group,
                    $evidence,
                    $sessionAllocations,
                    $pairOrdinal,
                    $generationCursor,
                );
                $sessionAllocations[$session]++;
                $ownership = $this->calendar->specialistOwnership($session, $reference);
                $niche = (array) data_get($template, 'niche', []);
                $cell = [
                    'protocol' => self::PROTOCOL,
                    'group' => $group,
                    'regime' => data_get($niche, 'regime'),
                    'volatility' => data_get($niche, 'volatility'),
                    'direction' => data_get($niche, 'direction'),
                    'session' => $session,
                    'session_ownership' => $ownership,
                    'trait_gene' => data_get($niche, 'declared_gene'),
                    'instrument_assignment_bound_after_construction' => true,
                    'composition_assignment_bound_before_pair_seal' => true,
                    'spread_liquidity_state' => null,
                    'observed_spread_liquidity_required' => true,
                    'outside_scope_action' => 'WAIT',
                    'promotion_scope' => 'same_context_only',
                    'promotion_evidence' => false,
                ];
                $cell['cell_hash'] = hash('sha256', json_encode($cell, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
                $spec = [
                    ...$template,
                    'target' => $group,
                    'research_group' => $group,
                    'group_axis' => self::GROUPS[$group]['axis'],
                    'niche' => [
                        ...$niche,
                        'objective' => $group,
                        'mutation_target' => $group,
                        'session' => $session,
                        'owner_context' => [
                            ...(array) data_get($niche, 'owner_context', []),
                            'regime' => data_get($niche, 'regime'),
                            'volatility' => data_get($niche, 'volatility'),
                            'direction' => data_get($niche, 'direction'),
                            'session' => $session,
                            'session_instance_id' => data_get($ownership, 'session_instance_id'),
                            'calendar_version' => data_get($ownership, 'calendar_version'),
                        ],
                        'contextual_specialist_cell' => $cell,
                    ],
                ];
                // Two adjacent hosts are one scientific unit.  The normal
                // pairing compiler later turns them into an exact frozen
                // control and a one-intervention candidate.
                $dynamic[] = $spec;
                $dynamic[] = $spec;
                $cells[] = [
                    'group' => $group,
                    'session' => $session,
                    'cell_hash' => $cell['cell_hash'],
                    'seat_count' => 2,
                ];
                $pairOrdinal++;
            }
        }

        $dynamic = [...$proof, ...array_slice($dynamic, 0, $pairBudget * 2)];
        if (count($dynamic) < 20) {
            // An odd free seat is an explicit uncertainty abstention.  It is
            // preserved only so the population remains exactly twenty.
            $fallback = (array) ($plan[count($dynamic)] ?? $plan[0]);
            $fallback['evolution_mode'] = 'uncertainty_abstain';
            $fallback['niche'] = [
                ...(array) data_get($fallback, 'niche', []),
                'uncertainty_abstain' => true,
                'outside_scope_action' => 'WAIT',
            ];
            $dynamic[] = $fallback;
        }

        $seatCounts = [];
        foreach ($dynamic as &$spec) {
            $group = (string) ($spec['research_group'] ?? 'portfolio_router');
            $seat = ($seatCounts[$group] ?? 0) + 1;
            $seatCounts[$group] = $seat;
            $spec['group_seat'] = $seat;
            $spec['group_axis'] = self::GROUPS[$group]['axis'] ?? 'causal_proof';
            $spec['group_search_mode'] = $seat <= 2 ? 'depth' : 'evidence_weighted_breadth';
            $spec['group_search_role'] = $seat % 2 === 1 ? 'frozen_control_host' : 'contextual_candidate_host';
        }
        unset($spec);

        return [
            'plan' => array_values($dynamic),
            'contract' => $this->contract($dynamic, $pairQuotas, $cells, 'allocated', $evidence),
        ];
    }

    /** @return array<string, int> */
    private function pairQuotas(int $budget, array $evidence, array $snapshot): array
    {
        $quotas = array_fill_keys(array_keys(self::GROUPS), 0);
        $scores = [];
        foreach (self::GROUPS as $group => $definition) {
            $row = (array) ($evidence[$group] ?? []);
            $observations = (int) ($row['observations'] ?? 0);
            $failures = (int) ($row['failures'] ?? 0);
            $successes = (int) ($row['successes'] ?? 0);
            $failurePressure = $observations > 0 ? $failures / $observations : 1.0;
            $uncertainty = 1 / sqrt($observations + 1);
            $positive = $observations > 0 ? $successes / $observations : 0.0;
            $repeatPenalty = $observations >= 8 && $successes === 0 ? min(.80, $observations / 40) : 0.0;
            $scores[$group] = max(.05,
                (float) $definition['prior']
                + (.80 * $failurePressure)
                + (.70 * $uncertainty)
                + (1.40 * $positive)
                - $repeatPenalty
            );
        }

        for ($seat = 0; $seat < $budget; $seat++) {
            $eligible = array_filter($scores, fn (float $score, string $group): bool => $quotas[$group] < 3, ARRAY_FILTER_USE_BOTH);
            if ($eligible === []) {
                $eligible = $scores;
            }
            $selected = collect(array_keys($eligible))->sortByDesc(function (string $group) use ($scores, $quotas, $snapshot): float {
                $adaptivePressure = (float) data_get($snapshot, 'exploration_ratio', .35);

                return $scores[$group] / (1 + (.20 * $quotas[$group])) + ($adaptivePressure * .01);
            })->first();
            if ($selected === null) {
                break;
            }
            $quotas[$selected]++;
        }

        return $quotas;
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function templateBuckets(array $plan): array
    {
        $buckets = array_fill_keys(array_keys(self::GROUPS), []);
        foreach ($plan as $index => $slot) {
            $group = (string) ($slot['research_group'] ?? $slot['target'] ?? array_keys(self::GROUPS)[$index % count(self::GROUPS)]);
            if (! isset($buckets[$group])) {
                $group = array_keys(self::GROUPS)[$index % count(self::GROUPS)];
            }
            if (! $this->primaryProofSeat((array) $slot)) {
                $buckets[$group][] = (array) $slot;
            }
        }
        foreach ($buckets as $group => $templates) {
            if ($templates === []) {
                $buckets[$group] = [[
                    'origin' => 'g98_council',
                    'family' => 'hybrid',
                    'target' => $group,
                    'research_group' => $group,
                    'niche' => ['protocol' => 'portfolio_council_v1', 'objective' => $group],
                ]];
            }
        }

        return $buckets;
    }

    /** @return array<string, mixed> */
    private function evidence(AiLaboratory $lab): array
    {
        $result = array_fill_keys(array_keys(self::GROUPS), ['observations' => 0, 'failures' => 0, 'successes' => 0]);
        $sessions = ['asia', 'london', 'new_york', 'overlap'];
        $result['__sessions'] = [
            '__global' => array_fill_keys($sessions, [
                'observations' => 0,
                'failures' => 0,
                'successes' => 0,
                'near_passes' => 0,
                'posterior_observations' => 0,
                'posterior_utility_sum' => 0.0,
            ]),
        ];
        foreach (array_keys(self::GROUPS) as $group) {
            $result['__sessions'][$group] = array_fill_keys($sessions, [
                'observations' => 0,
                'failures' => 0,
                'successes' => 0,
                'near_passes' => 0,
                'posterior_observations' => 0,
                'posterior_utility_sum' => 0.0,
            ]);
        }
        $generationIds = $lab->generations()->whereIn('status', [
            'screened', 'completed', 'technical_quarantine', 'abandoned', 'failed',
        ])->latest('generation')->limit(5)->pluck('id');
        $agents = $generationIds->isEmpty()
            ? collect()
            : LabAgent::query()->with('modelVersion')->whereIn('lab_generation_id', $generationIds)->get();
        foreach ($agents as $agent) {
            $group = (string) data_get($agent->modelVersion?->metadata, 'specialist_council_membership.group_key', '');
            if (! isset($result[$group])) {
                continue;
            }
            $result[$group]['observations']++;
            $session = strtolower((string) data_get(
                $agent->modelVersion?->metadata,
                'specialist_council_membership.contextual_cell.session',
                '',
            ));
            $sessionScoped = in_array($session, $sessions, true);
            if ($sessionScoped) {
                $result['__sessions'][$group][$session]['observations']++;
            }
            if (in_array((string) $agent->lifecycle_status, ['challenger', 'forward_validated', 'paper', 'champion'], true)) {
                $result[$group]['successes']++;
                if ($sessionScoped) {
                    $result['__sessions'][$group][$session]['successes']++;
                }
            } elseif (in_array((string) $agent->lifecycle_status, ['rejected', 'failed', 'overfit', 'stagnated', 'technical_quarantine'], true)) {
                $result[$group]['failures']++;
                if ($sessionScoped) {
                    $result['__sessions'][$group][$session]['failures']++;
                }
            }
            if ($sessionScoped && (
                data_get($agent->modelVersion?->metadata, 'mutation_observability.control_relative_improved') === true
                || data_get($agent->modelVersion?->metadata, 'mutation_observability.gate_margin.target_gate_improved') === true
            )) {
                $result['__sessions'][$group][$session]['near_passes']++;
            }
        }

        if (Schema::hasTable('instrument_value_posteriors')) {
            $posteriors = InstrumentValuePosterior::query()
                ->where('symbol', strtoupper($lab->symbol))
                ->where('observations', '>', 0)
                ->latest('last_observed_at')
                ->limit(200)
                ->get(['state_key', 'observations', 'net_value']);
            foreach ($posteriors as $posterior) {
                $parts = explode('|', (string) $posterior->state_key);
                $session = strtolower((string) ($parts[1] ?? ''));
                if (! in_array($session, $sessions, true)) {
                    continue;
                }
                $observations = max(1, (int) $posterior->observations);
                $result['__sessions']['__global'][$session]['posterior_observations'] += $observations;
                $result['__sessions']['__global'][$session]['posterior_utility_sum'] += (float) $posterior->net_value * $observations;
            }
        }

        return $result;
    }

    /** @param array<string, int> $allocations */
    private function selectSession(
        string $group,
        array $evidence,
        array $allocations,
        int $pairOrdinal,
        int $generationCursor,
    ): string {
        $sessions = ['asia', 'london', 'new_york', 'overlap'];
        // A minimum one-pair coverage floor prevents an early noisy posterior
        // from starving whole market phases before they have any evidence.
        if ($pairOrdinal < count($sessions)) {
            return $sessions[($pairOrdinal + $generationCursor) % count($sessions)];
        }

        $scored = [];
        foreach ($sessions as $index => $session) {
            $local = (array) data_get($evidence, "__sessions.{$group}.{$session}", []);
            $global = (array) data_get($evidence, "__sessions.__global.{$session}", []);
            $observations = (int) ($local['observations'] ?? 0);
            $successes = (int) ($local['successes'] ?? 0);
            $failures = (int) ($local['failures'] ?? 0);
            $nearPasses = (int) ($local['near_passes'] ?? 0);
            $posteriorN = (int) ($global['posterior_observations'] ?? 0);
            $posteriorMean = $posteriorN > 0
                ? (float) ($global['posterior_utility_sum'] ?? 0) / $posteriorN
                : 0.0;
            $positiveRate = $observations > 0 ? ($successes + (.35 * $nearPasses)) / $observations : 0.0;
            $failurePressure = $observations > 0 ? $failures / $observations : .5;
            $uncertainty = 1 / sqrt($observations + $posteriorN + 1);
            $exhaustedPenalty = $observations >= 6 && ($successes + $nearPasses) === 0 ? .90 : 0.0;
            $allocationPenalty = .24 * (int) ($allocations[$session] ?? 0);
            $rotationTieBreak = ((($pairOrdinal + $generationCursor) % count($sessions)) === $index) ? .0001 : 0.0;
            $scored[$session] = 1.0
                + (1.8 * $positiveRate)
                + (.55 * $failurePressure)
                + (.85 * $uncertainty)
                + (1.2 * max(0.0, $posteriorMean))
                - (.65 * max(0.0, -$posteriorMean))
                - $exhaustedPenalty
                - $allocationPenalty
                + $rotationTieBreak;
        }

        return (string) collect($scored)->sortDesc()->keys()->first();
    }

    /** @return array<string, mixed> */
    private function contract(array $plan, array $quotas, array $cells, string $status, array $evidence = []): array
    {
        $counts = collect($plan)->countBy(fn (array $slot): string => (string) ($slot['research_group'] ?? 'proof'))->all();
        $sessionPairCounts = collect($cells)->countBy('session')->all();
        $pairIntegrity = collect($cells)->every(fn (array $cell): bool => (int) $cell['seat_count'] === 2);

        return [
            'protocol' => self::PROTOCOL,
            'status' => $status,
            'planned_population' => count($plan),
            'pair_budget' => array_sum($quotas),
            'pair_quotas' => $quotas,
            'seat_counts' => $counts,
            'session_pair_counts' => $sessionPairCounts,
            'evidence_snapshot' => $evidence,
            'cells' => $cells,
            'pair_integrity' => $pairIntegrity,
            'dynamic' => $status === 'allocated',
            'fixed_equal_quota_forbidden' => true,
            'session_selection_policy' => 'coverage_floor_then_contextual_ucb_with_local_success_failure_and_instrument_posterior',
            'decisions' => collect($quotas)->mapWithKeys(fn (int $pairs, string $group): array => [
                $group => $pairs === 0 ? 'retire_or_abstain' : ($pairs === 1 ? 'merge_depth_cell' : 'split_context_cells'),
            ])->all(),
            'local_evidence_grants_global_inheritance' => false,
            'outside_owned_context_action' => 'WAIT',
            'promotion_rule' => 'same contextual cell; exact frozen control; independent chronological windows; both applicable DST offset states; no non-target regression',
            'promotion_evidence' => false,
        ];
    }

    private function primaryProofSeat(array $slot): bool
    {
        return (array) data_get($slot, 'niche.causal_learning_cohort', []) !== []
            || (int) data_get($slot, 'niche.causal_confirmation_source_lesson_id', 0) > 0
            || (int) data_get($slot, 'niche.causal_repair_source_experiment_id', 0) > 0;
    }

    private function referenceTimestamp(AiLaboratory $lab): mixed
    {
        $symbolId = Symbol::query()->where('code', strtoupper($lab->symbol))->value('id');
        if (! $symbolId) {
            return now()->utc();
        }

        return Candle::query()->where('symbol_id', $symbolId)->latest('time')->value('time') ?: now()->utc();
    }
}
