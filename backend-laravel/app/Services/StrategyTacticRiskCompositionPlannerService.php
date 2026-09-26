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
    public const PROTOCOL = 'xauusd_sovereign_adaptive_composition_foundry_v1';

    public function __construct(
        private StrategyLibraryCompilerService $strategies,
        private TacticCatalogueService $tactics,
        private RiskManagementLibraryService $risks,
        private CompositionAuthorityKernelService $authority,
        private PriorKnowledgeVaultService $priorVault,
    ) {}

    /**
     * Freeze the composition used by a bounded causal confirmation cohort.
     *
     * Historical learning pairs predate composition passports. Requiring a
     * twenty-seat portfolio merely to re-test one already settled gene would
     * strand that memory forever, so the confirmation lane freezes an exact
     * family runtime adapter and records the source baseline separately. When
     * the source names an architecture/tactic, those identities must also be
     * exact. This passport is research-only and grants no promotion authority.
     *
     * @return array<string, mixed>
     */
    public function freezeConfirmationBaseline(
        string $family,
        string $timeframe,
        string $dataHash = '',
        string $executionHash = '',
        ?string $sourceArchitecture = null,
        ?string $sourceTactic = null,
        array $existingPassport = [],
    ): array {
        $family = trim($family);
        $sourceArchitecture = trim((string) $sourceArchitecture);
        $sourceTactic = trim((string) $sourceTactic);
        if (in_array(strtolower($sourceArchitecture), ['', 'unknown', '*'], true)) {
            $sourceArchitecture = '';
        }
        if (in_array(strtolower($sourceTactic), ['', 'unknown', '*'], true)) {
            $sourceTactic = '';
        }
        if ($this->confirmationPassportMatches(
            $existingPassport,
            $family,
            $sourceArchitecture,
            $sourceTactic,
            $dataHash,
            $executionHash,
        )) {
            return $existingPassport;
        }

        $strategy = collect($this->strategies->library())
            ->map(fn (array $spec): array => [
                'id' => (string) $spec['id'],
                'runtime' => $this->strategies->runtime((string) $spec['id']),
            ])
            ->first(fn (array $entry): bool => is_array($entry['runtime'])
                && (string) data_get($entry, 'runtime.family') === $family
                && ($sourceArchitecture === ''
                    || (string) data_get($entry, 'runtime.architecture') === $sourceArchitecture));
        if (! is_array($strategy)) {
            // A causal control may never be coerced into another executable
            // family merely because the composition library lacks its exact
            // adapter. The caller must withhold the cohort before construction.
            return [];
        }
        $runtimeArchitecture = (string) data_get($strategy, 'runtime.architecture', '');
        $strategyId = (string) $strategy['id'];
        $tacticId = $sourceTactic !== ''
            ? $sourceTactic
            : $this->fallbackTacticId($family, $runtimeArchitecture);

        return $this->authority->freeze([
            'symbol' => 'XAUUSD',
            'timeframe' => strtoupper($timeframe),
            'strategy_id' => $strategyId,
            'tactic_id' => $tacticId,
            'risk_id' => 'atr_risk_envelope',
            'management_id' => $this->managementProfileFor($tacticId),
            'prior_ids' => [],
            'local_evidence_count' => 1,
            'market_state' => ['source' => 'canonical_learning_pair'],
            'data_hash' => $dataHash,
            'execution_hash' => $executionHash,
        ]);
    }

    /**
     * A historical passport is reusable only when it identifies the exact
     * source control that the causal triplet promises to replay.
     */
    private function confirmationPassportMatches(
        array $passport,
        string $family,
        string $architecture,
        string $tactic,
        string $dataHash,
        string $executionHash,
    ): bool {
        if ((string) data_get($passport, 'protocol') !== CompositionAuthorityKernelService::PROTOCOL) {
            return false;
        }
        $strategyId = (string) data_get($passport, 'components.strategy_id', '');
        $runtime = $strategyId !== '' ? $this->strategies->runtime($strategyId) : null;
        if (! is_array($runtime)
            || (string) data_get($runtime, 'family') !== $family
            || ($architecture !== '' && (string) data_get($runtime, 'architecture') !== $architecture)
            || ($tactic !== '' && (string) data_get($passport, 'components.tactic_id') !== $tactic)) {
            return false;
        }
        if ($dataHash !== '' && (string) data_get($passport, 'provenance.data_hash', '') !== $dataHash) {
            return false;
        }

        return $executionHash === ''
            || (string) data_get($passport, 'provenance.execution_hash', '') === $executionHash;
    }

    /**
     * @param  array<int, array<string, mixed>>  $plan
     * @return array{plan: array<int, array<string, mixed>>, contract: array<string, mixed>}
     */
    public function materialize(
        array $plan,
        int $generation = 0,
        array $enabledFamilies = [],
        array $lineageFamilies = [],
    ): array {
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
            $templateIndex = $available->first(fn (int $index): bool => (string) data_get($plan[$index], 'family', '') === $family
                && ! $lineageSeats->contains($index)
            );
            if ($templateIndex === null) {
                continue;
            }

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
            if ($donorIndex === null) {
                continue;
            }
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
            if ($lineageSeats->count() >= 4) {
                break;
            }
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
        // The tactic/structural lanes already guarantee the canonical trend
        // pullback. Anchor the strategy lane on the second executable trend
        // topology so exact-control pairing cannot collapse the final cohort
        // back to one trend architecture.
        $anchorIds = ['str_031_bos_retest', 'str_003_donchian_breakout', 'str_010_bollinger_squeeze', 'str_020_bb_rsi_reversion', 'str_040_asia_london_breakout'];
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
        $anchoredArchitectures = $anchorSelection
            ->map(fn (array $entry): string => (string) data_get($entry, 'runtime.family').'|'.(string) data_get($entry, 'runtime.architecture'))
            ->all();
        $rotationPool = collect($strategyRuntimes)
            ->reject(fn (array $entry): bool => in_array(
                (string) data_get($entry, 'runtime.family').'|'.(string) data_get($entry, 'runtime.architecture'),
                $anchoredArchitectures,
                true,
            ))
            ->values();
        if ($rotationPool->isEmpty()) {
            $rotationPool = collect($strategyRuntimes)->values();
        }
        // Spend the rotating seat on an architecture the five family anchors
        // do not already execute. This preserves topology diversity while the
        // passport remains the sole runtime owner.
        $rotating = $rotationPool[$generation % $rotationPool->count()];
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

        // A normal generation is four causal packets, not four unrelated
        // quota buckets. The legacy component lane remains as the concrete
        // constructor instruction, while packet/arm is the experiment's
        // actual causal identity. Every arm receives a frozen passport.
        $packets = array_chunk(array_keys($plan), 5);
        $blueprints = $this->priorVault->blueprints();
        $packetEmitters = ['prior_seed', 'local_exploitation', 'weakest_gate_repair', 'novelty_adversarial'];
        $arms = ['frozen_composite_parent', 'prior_memory_guided_single_axis', 'memory_blinded_single_axis', 'weakest_gate_deterministic_repair', 'novelty_negative_control'];
        foreach ($packets as $packetOffset => $indices) {
            $packetId = sprintf('xau-packet-g%04d-%02d', $generation, $packetOffset + 1);
            $prior = $blueprints[$packetOffset % count($blueprints)];
            foreach ($indices as $armOffset => $index) {
                $niche = (array) data_get($plan[$index], 'niche', []);
                $family = (string) data_get($plan[$index], 'family', 'hybrid');
                $strategyId = (string) data_get($niche, 'strategy_library_id', '');
                if ($strategyId === '') {
                    $desiredArchitecture = (string) (data_get($niche, 'composition_architecture')
                        ?: data_get($niche, 'architecture_variant', ''));
                    $architectureOwner = collect($strategyRuntimes)->first(
                        fn (array $entry): bool => $desiredArchitecture !== ''
                            && (string) data_get($entry, 'runtime.family') === $family
                            && (string) data_get($entry, 'runtime.architecture') === $desiredArchitecture,
                    );
                    $strategyId = (string) data_get(
                        $architectureOwner,
                        'id',
                        $this->fallbackStrategyId($family),
                    );
                }
                $tacticId = (string) data_get($niche, 'tactic_library_key', $this->fallbackTacticId($family));
                $riskId = (string) data_get($niche, 'risk_library_id', $riskProfiles[$packetOffset % count($riskProfiles)]['id']);
                $managementId = $this->managementProfileFor($tacticId);
                $arm = $arms[$armOffset];
                $priorIds = $arm === 'memory_blinded_single_axis' ? [] : [$prior['prior_id']];
                $changedComponent = match ($arm) {
                    'frozen_composite_parent' => 'none',
                    'prior_memory_guided_single_axis' => 'strategy_or_tactic',
                    'memory_blinded_single_axis' => 'selector_policy',
                    'weakest_gate_deterministic_repair' => 'weakest_gate_repair',
                    default => 'negative_control_or_novelty',
                };
                $passport = $this->authority->freeze([
                    'symbol' => 'XAUUSD',
                    'timeframe' => (string) data_get($plan[$index], 'timeframe', 'H1'),
                    'strategy_id' => $strategyId,
                    'tactic_id' => $tacticId,
                    'risk_id' => $riskId,
                    'management_id' => $managementId,
                    'prior_ids' => $priorIds,
                    'local_evidence_count' => 0,
                    'market_state' => (array) data_get($niche, 'market_state', []),
                    'learning_directive' => (array) data_get($niche, 'learning_evolution', []),
                    'data_hash' => (string) data_get($niche, 'data_hash', ''),
                    'execution_hash' => (string) data_get($niche, 'execution_hash', ''),
                ]);
                $plan[$index]['niche'] = [...$niche,
                    'causal_packet' => [
                        'protocol' => self::PROTOCOL,
                        'packet_id' => $packetId,
                        'packet_emitter' => $packetEmitters[$packetOffset],
                        'trial_family_id' => $packetId,
                        'arm' => $arm,
                        'changed_component' => $changedComponent,
                        'parent_or_denovo_reason' => $arm === 'frozen_composite_parent' ? 'frozen_baseline' : ($arm === 'memory_blinded_single_axis' ? 'cold_start_selector_control' : 'bounded_single_axis_candidate'),
                        'stopping_rule' => 'stratified_replay_then_paired_screen_then_independent_windows',
                        'prior_debt_control_id' => $packetId.':prior-debt',
                        'paired_control_required' => true,
                        'promotion_evidence' => false,
                    ],
                    'composition_passport' => $passport,
                ];
            }
        }

        return ['plan' => $plan, 'contract' => [
            'protocol' => self::PROTOCOL,
            'status' => 'admitted',
            'generation_structure' => ['hypothesis_packets' => 4, 'arms_per_packet' => 5, 'seats' => 20],
            'packet_arms' => $arms,
            'emitters' => $packetEmitters,
            'prior_budget_ceiling' => .40,
            'non_zero_emitter_floors' => ['local_exploitation' => .25, 'novelty_adversarial' => .15],
            'paired_control_required_for' => $arms,
            'strategy_library_rotation_offset' => $generation,
            'lineage_continuation_families' => $lineageSeats
                ->map(fn (int $index): string => (string) data_get($plan[$index], 'family'))
                ->unique()->values()->all(),
            'lineage_continuation_budget' => $lineageSeats->count(),
            'library_return_rule' => 'Only independently confirmed, paired-control winners may be consolidated into a library posterior; screening never promotes a composition.',
            'promotion_evidence' => false,
        ]];
    }

    /**
     * Re-assert frozen composition ownership after contextual allocation and
     * control pairing have copied/reordered seats. The passport is not
     * rewritten: its strategy/tactic identities become the final constructor
     * inputs. An unknown or non-executable strategy fails the cohort closed.
     *
     * @param  array<int,array<string,mixed>>  $plan
     * @return array{plan:array<int,array<string,mixed>>,contract:array<string,mixed>}
     */
    public function bindRuntimeOwnership(array $plan): array
    {
        $bound = [];
        $failures = [];
        foreach (array_values($plan) as $index => $slot) {
            $passport = (array) data_get($slot, 'niche.composition_passport', []);
            if ($passport === []) {
                $bound[] = $slot;

                continue;
            }
            $strategyId = (string) data_get($passport, 'components.strategy_id', '');
            $tacticId = (string) data_get($passport, 'components.tactic_id', '');
            $runtime = $strategyId !== '' ? $this->strategies->runtime($strategyId) : null;
            if ((string) data_get($passport, 'protocol') !== CompositionAuthorityKernelService::PROTOCOL
                || ! is_array($runtime)
                || $tacticId === '') {
                $failures[] = [
                    'slot' => $index + 1,
                    'strategy_id' => $strategyId,
                    'tactic_id' => $tacticId,
                    'reason' => 'passport_runtime_identity_not_executable',
                ];
                $bound[] = $slot;

                continue;
            }

            $niche = (array) data_get($slot, 'niche', []);
            $niche['strategy_library_id'] = $strategyId;
            $niche['strategy_library_contract'] = $this->strategies->compile($strategyId);
            $niche['composition_architecture'] = (string) $runtime['architecture'];
            $niche['tactic_library_key'] = $tacticId;
            $niche['composition_runtime_owner'] = [
                'protocol' => 'composition_constructor_binding_v1',
                'composition_id' => (string) data_get($passport, 'composition_id', ''),
                'family' => (string) $runtime['family'],
                'architecture' => (string) $runtime['architecture'],
                'tactic' => $tacticId,
                'status' => 'bound',
                'promotion_evidence' => false,
            ];
            $slot['family'] = (string) $runtime['family'];
            $slot['niche'] = $niche;
            $bound[] = $slot;
        }

        return ['plan' => $bound, 'contract' => [
            'protocol' => 'composition_constructor_binding_v1',
            'status' => $failures === [] ? 'bound' : 'not_admitted',
            'passport_seats' => collect($bound)->filter(
                fn (array $slot): bool => filled(data_get($slot, 'niche.composition_passport.composition_id')),
            )->count(),
            'failures' => $failures,
            'passport_owns_family_architecture_and_tactic' => true,
            'promotion_evidence' => false,
        ]];
    }

    private function fallbackStrategyId(string $family): string
    {
        return match ($family) {
            'trend' => 'str_001_ema_adx_pullback', 'breakout' => 'str_003_donchian_breakout',
            'volatility' => 'str_010_bollinger_squeeze', 'mean_reversion' => 'str_020_bb_rsi_reversion',
            'session' => 'str_040_asia_london_breakout',
            'regime_ensemble' => 'mix_010_regime_ensemble',
            'differential_router' => 'mix_011_differential_router',
            default => 'mix_001_trend_beast',
        };
    }

    private function fallbackTacticId(string $family, string $architecture = ''): string
    {
        return match ($family) {
            'breakout' => 'breakout_retest', 'volatility' => 'volatility_compression_expansion',
            'mean_reversion' => 'range_mean_reversion', 'session' => 'session_breakout',
            'regime_ensemble' => 'frozen_regime_specialist_ensemble',
            'differential_router' => 'frozen_parent_differential_router',
            'hybrid' => $architecture !== '' ? $architecture : 'trend_pullback',
            default => 'trend_pullback',
        };
    }

    private function managementProfileFor(string $tacticId): string
    {
        return match ($tacticId) {
            'range_mean_reversion' => 'range_fixed_target', 'session_breakout' => 'session_orb',
            'breakout_retest' => 'breakout_measured_move', 'volatility_compression_expansion' => 'structure_runner',
            default => 'balanced_professional',
        };
    }
}
