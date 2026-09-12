<?php

namespace App\Services;

use App\Models\InstrumentValuePosterior;
use App\Models\LabAgent;
use App\Models\PlaybookComposition;
use App\Models\PlaybookValuePosterior;
use App\Models\TradingInstrument;

/**
 * Bridges the laboratory organism to the instrument operating system.
 *
 * The assignment is sealed before replay.  It describes only executable
 * parameter surfaces already present in the model; it never changes the
 * strategy, grants execution authority, or turns a catalogue prior into
 * evidence.  Python must independently attest consumption before a ledger row
 * can be opened, and a verified frozen pair is still required for settlement.
 */
class LabInstrumentResearchService
{
    public const PROTOCOL = 'lab_instrument_research_assignment_v2';

    public const HASH_PROTOCOL = 'numeric_canonical_json_v1';

    public const ACTIVATION_PROTOCOL = 'instrument_runtime_activation_contract_v1';

    public const RUNTIME_TRACE_PROTOCOL = 'lab_instrument_runtime_trace_v2';

    public const DECISION_DOCTRINE_PROTOCOL = 'contextual_instrument_decision_doctrine_v1';

    public function __construct(
        private TradingInstrumentOperatingSystemService $instruments,
        private ExactCausalBaselineService $baselines,
    ) {}

    /** @return array<string, mixed> */
    public function assignment(LabAgent $agent): array
    {
        $agent->loadMissing('modelVersion', 'generation');
        $model = $agent->modelVersion;
        if (! $model) {
            return $this->unavailable('MODEL_VERSION_REQUIRED');
        }

        $parameters = (array) ($model->parameters ?? []);
        $parameterHash = $this->hash($parameters);
        $existing = (array) data_get($model->metadata, 'instrument_research_assignment', []);
        $existingWithoutHash = $existing;
        unset($existingWithoutHash['assignment_hash']);
        if ((string) data_get($existing, 'protocol') === self::PROTOCOL
            && (string) data_get($existing, 'hash_protocol') === self::HASH_PROTOCOL
            && (string) data_get($existing, 'activation_policy.protocol') === self::ACTIVATION_PROTOCOL
            && (string) data_get($existing, 'decision_doctrine.protocol') === self::DECISION_DOCTRINE_PROTOCOL
            && (string) data_get($existing, 'parameter_hash') === $parameterHash
            && filled(data_get($existing, 'assignment_hash'))
            && hash_equals((string) data_get($existing, 'assignment_hash'), $this->hash($existingWithoutHash))) {
            return $existing;
        }

        $this->instruments->seedDefaults();
        $changedGene = count((array) $agent->parameter_diff) === 1
            ? (string) array_key_first((array) $agent->parameter_diff)
            : null;
        $experimentRole = $this->experimentRole($agent, $changedGene);
        $pairReservation = $this->pairReservation($agent, $experimentRole);
        // A one-gene instrument candidate without its already-persisted exact
        // control must not even reach Python. This prevents an ever-growing
        // awaiting_paired_control vitrine from masquerading as learning.
        $pairReady = $experimentRole !== 'candidate'
            || (string) ($pairReservation['status'] ?? '') === 'reserved';
        $capsuleKeys = array_values(array_unique(array_filter(array_map(
            'strval',
            (array) data_get($pairReservation, 'trait_capsule.instrument_bundle.instrument_keys', []),
        ))));
        $keys = $pairReady
            ? ($capsuleKeys !== [] ? $capsuleKeys : $this->instrumentKeys($agent, $changedGene))
            : [];
        $records = TradingInstrument::query()->with('contract')
            ->whereIn('instrument_key', $keys)
            ->get()->keyBy('instrument_key');
        $selected = [];
        foreach ($keys as $key) {
            $instrument = $records->get($key);
            if (! $instrument) {
                continue;
            }
            $allowed = array_values((array) ($instrument->contract?->allowed_genes ?? []));
            $bindings = [];
            foreach ($allowed as $gene) {
                if (array_key_exists($gene, $parameters)) {
                    $bindings[$gene] = $parameters[$gene];
                }
            }
            $selected[] = [
                'instrument_key' => (string) $instrument->instrument_key,
                'role' => (string) $instrument->role,
                'tactic_id' => $instrument->tactic_id,
                'source_block' => 'candidate_inventory',
                'source_type' => str_starts_with((string) data_get($instrument->definition, 'origin', ''), 'runtime_')
                    ? 'system_discovered_runtime_primitive'
                    : 'curated_registry',
                'promotion_state' => (string) $instrument->promotion_state,
                'parameter_bindings' => $bindings,
                'causal_candidate' => $changedGene !== null && in_array($changedGene, $allowed, true),
                'selection_reason' => $changedGene !== null && in_array($changedGene, $allowed, true)
                    ? 'changed_gene_causal_surface'
                    : ($experimentRole === 'frozen_control' ? 'frozen_control_observation' : 'frozen_support_component'),
                'learning_authority' => $changedGene !== null && in_array($changedGene, $allowed, true)
                    ? 'eligible_for_local_paired_delta_only_after_runtime_activation'
                    : 'support_observation_only_without_factorial_attribution',
                'activation_contract' => $this->activationContract($instrument, $agent, $pairReservation),
                'tool_card_hash' => $this->hash((array) $instrument->definition),
                'promotion_evidence' => false,
            ];
        }

        $primaryKey = data_get($pairReservation, 'trait_capsule.instrument_bundle.primary_instrument_key')
            ?: data_get(
                collect($selected)->firstWhere('causal_candidate', true),
                'instrument_key',
                data_get($selected, '0.instrument_key')
            );
        $playbook = $primaryKey ? $this->researchBundle(
            strtoupper((string) $agent->symbol),
            array_values(array_column($selected, 'instrument_key')),
            (string) $primaryKey,
        ) : null;
        $posterior = $primaryKey ? InstrumentValuePosterior::query()
            ->whereHas('instrument', fn ($query) => $query->where('instrument_key', $primaryKey))
            ->orderByDesc('observations')
            ->orderByDesc('net_value')
            ->first() : null;

        $assignment = [
            'protocol' => self::PROTOCOL,
            'hash_protocol' => self::HASH_PROTOCOL,
            'status' => ! $pairReady
                ? 'blocked_exact_pair_reservation_missing'
                : ($selected === [] ? 'no_executable_instrument_match' : 'assigned'),
            'organism' => strtoupper((string) $agent->symbol),
            'temporal_roles' => [
                'context' => 'H1',
                'decision' => 'M15',
                'execution' => 'M5',
                'ledger_storage' => strtoupper((string) $agent->timeframe),
            ],
            'lab_agent_id' => (int) $agent->id,
            'lab_generation_id' => (int) $agent->lab_generation_id,
            'model_version_id' => (int) $agent->model_version_id,
            'strategy_family' => (string) $agent->strategy_family,
            'strategy' => (string) $model->strategy,
            'parameter_hash' => $parameterHash,
            'changed_gene' => $changedGene,
            'experiment_role' => $experimentRole,
            'pair_reservation' => $pairReservation,
            'selection_mode' => $changedGene ? 'causal_changed_surface_plus_frozen_support' : 'frozen_baseline_bundle',
            'activation_policy' => [
                'protocol' => self::ACTIVATION_PROTOCOL,
                'selection_is_not_invocation' => true,
                'parameter_binding_is_not_activation' => true,
                'runtime_decision_path_required' => true,
                'outside_context_action' => 'ABSTAIN',
                'inactive_disposition' => 'NOT_INVOKED_NO_CREDIT',
                'credit_rule' => 'only the activated changed surface may receive isolated causal credit; support and bundle value require their own exact activation evidence',
                'paper_execution_authority' => false,
                'promotion_evidence' => false,
            ],
            'decision_doctrine' => [
                'protocol' => self::DECISION_DOCTRINE_PROTOCOL,
                'objective' => $changedGene
                    ? "isolate whether {$changedGene} improves its pre-declared local context"
                    : 'observe the frozen baseline bundle without assigning causal credit',
                'changed_gene' => $changedGene,
                'causal_candidate_limit' => 1,
                'rules' => [
                    'invoke_only_when_runtime_preconditions_and_context_contract_match',
                    'abstain_outside_declared_context_even_when_the_tool_is_globally_available',
                    'do_not_add_unregistered_instruments_during_replay',
                    'do_not_credit_frozen_support_as_the_changed_cause',
                    'negative_evidence_forbids_only_the_tested_context_and_gene_surface',
                    'positive_evidence_remains_local_until_exact_pair_replication_and_factorial_attribution',
                ],
                'success_receipt' => 'instrument_runtime_observation + exact frozen control + local control delta',
                'failure_receipt' => 'NOT_INVOKED_NO_CREDIT or context-local negative posterior',
                'paper_execution_authority' => false,
                'promotion_evidence' => false,
            ],
            'playbook_key' => $playbook?->playbook_key,
            'bundle_identity' => $playbook ? [
                'playbook_key' => $playbook->playbook_key,
                'instrument_keys' => (array) $playbook->instrument_keys,
                'bundle_hash' => data_get($playbook->metadata, 'bundle_hash'),
                'primary_instrument_key' => (string) $primaryKey,
                'interaction_identified' => false,
                'interpretation' => 'joint_bundle_value_only_until_factorial_ablation',
            ] : null,
            'selected' => $selected,
            'selected_keys' => array_values(array_column($selected, 'instrument_key')),
            'source_components' => [
                'strategy_library_id' => data_get($model->metadata, 'smart_composition.strategy_library_id'),
                'tactic_library_key' => data_get($model->metadata, 'smart_composition.tactic_library_key'),
                'risk_library_id' => data_get($model->metadata, 'smart_composition.risk_library_id'),
                'management_id' => data_get($model->metadata, 'smart_composition.composition_passport.components.management_id'),
                'tactic_contract_id' => data_get($model->metadata, 'tactic_contract.tactic_id'),
                'composition_id' => data_get($model->metadata, 'smart_composition.composition_passport.composition_id'),
            ],
            'prior_value' => $posterior ? [
                'observations' => (int) $posterior->observations,
                'net_value' => (float) $posterior->net_value,
                'uncertainty' => (float) $posterior->uncertainty,
                'state' => (string) $posterior->decay_state,
                'used_for' => 'research_priority_only',
            ] : [
                'observations' => 0,
                'state' => 'unmeasured',
                'used_for' => 'exploration',
            ],
            'evidence_contract' => [
                'python_consumption_attestation_required' => true,
                'same_generation_frozen_control_required' => true,
                'same_data_hash_required' => true,
                'same_execution_hash_required' => true,
                'paper_execution_authority' => false,
                'promotion_evidence' => false,
            ],
            'promotion_evidence' => false,
        ];
        $assignment['assignment_hash'] = $this->hash($assignment);

        $metadata = (array) $model->metadata;
        $metadata['instrument_research_assignment'] = $assignment;
        $model->update(['metadata' => $metadata]);

        return $assignment;
    }

    /**
     * Convert only mature Block-2 posteriors into bounded next-generation
     * mutation guidance. Positive evidence is a preference, never a forced
     * value; forbidden evidence joins the existing harmful-gene firewall.
     *
     * @return array<string, mixed>
     */
    public function mutationPolicy(string $symbol, string $family, array $context = []): array
    {
        $symbol = strtoupper(str_replace(['/', '_', '-'], '', $symbol));
        $family = app(StrategyParameterSchemaService::class)->family($family);
        $familyGenes = array_keys(app(StrategyParameterSchemaService::class)->schema($family));
        $requestedContext = array_filter(
            array_intersect_key(
                app(ContextContractV2Service::class)->canonicalAxes($context),
                array_flip(['regime', 'session', 'volatility', 'spread_liquidity_state', 'transition_state', 'direction']),
            ),
            static fn ($value): bool => $value !== null && $value !== '',
        );
        $rows = InstrumentValuePosterior::query()
            ->with('instrument.contract')
            ->where('symbol', $symbol)
            ->where('timeframe', 'M15')
            ->whereIn('decay_state', ['confirmed', 'forbidden'])
            ->orderByDesc('net_value')
            ->get()
            ->filter(function (InstrumentValuePosterior $posterior) use ($requestedContext, $family): bool {
                $authority = app(InstrumentPosteriorAuthorityService::class)->assess($posterior);
                if (! in_array((string) $authority['canonical_state'], ['confirmed', 'forbidden'], true)) {
                    return false;
                }
                $observed = $this->posteriorContext((string) $posterior->state_key);
                if (($observed['strategy_family'] ?? null) !== $family) {
                    return false;
                }
                if ($requestedContext === []) {
                    // A family prior may open an experiment but can never
                    // directly select or block a mutation.
                    return false;
                }

                foreach ($requestedContext as $axis => $value) {
                    if (! array_key_exists($axis, $observed) || (string) $observed[$axis] !== (string) $value) {
                        return false;
                    }
                }

                return true;
            })->values();
        $bundleRows = PlaybookValuePosterior::query()
            ->with('playbook')
            ->where('symbol', $symbol)
            ->where('timeframe', 'M15')
            ->whereIn('decay_state', ['confirmed', 'forbidden'])
            ->orderByDesc('net_value')
            ->get()
            ->filter(function (PlaybookValuePosterior $posterior) use ($requestedContext, $family): bool {
                $authority = app(InstrumentPosteriorAuthorityService::class)->assess($posterior);
                if (! in_array((string) $authority['canonical_state'], ['confirmed', 'forbidden'], true)) {
                    return false;
                }
                if ((string) data_get($posterior->playbook?->metadata, 'protocol') !== 'exact_instrument_research_bundle_v1') {
                    return false;
                }
                $observed = $this->posteriorContext((string) $posterior->state_key);
                if (($observed['strategy_family'] ?? null) !== $family) {
                    return false;
                }
                if ($requestedContext === []) {
                    return false;
                }
                foreach ($requestedContext as $axis => $value) {
                    if (! array_key_exists($axis, $observed) || (string) $observed[$axis] !== (string) $value) {
                        return false;
                    }
                }

                return true;
            })->values();
        $bundleEvidence = [];
        $bundleSources = [];
        foreach ($bundleRows as $posterior) {
            $primary = (string) data_get($posterior->playbook?->metadata, 'primary_instrument_key', '');
            if ($primary === '') {
                continue;
            }
            $bundleEvidence[$primary] ??= ['positive_weight' => 0.0, 'negative_weight' => 0.0];
            $weight = max(.05, 1 - (float) $posterior->uncertainty) * log(1 + max(1, (int) $posterior->observations));
            $bucket = (string) $posterior->decay_state === 'confirmed' ? 'positive_weight' : 'negative_weight';
            $bundleEvidence[$primary][$bucket] += $weight;
            $bundleSources[] = [
                'source_type' => 'exact_instrument_bundle',
                'playbook_key' => $posterior->playbook?->playbook_key,
                'bundle_hash' => data_get($posterior->playbook?->metadata, 'bundle_hash'),
                'primary_instrument_key' => $primary,
                'instrument_keys' => (array) $posterior->playbook?->instrument_keys,
                'state' => (string) $posterior->decay_state,
                'observations' => (int) $posterior->observations,
                'net_value' => (float) $posterior->net_value,
                'state_key' => (string) $posterior->state_key,
                'interaction_identified' => (bool) data_get($posterior->value_vector, 'interaction_identified', false),
            ];
        }
        $geneEvidence = [];
        $sources = [];
        foreach ($rows as $posterior) {
            $genes = array_values(array_intersect(
                (array) ($posterior->instrument?->contract?->allowed_genes ?? []),
                $familyGenes,
            ));
            if ($genes === []) {
                continue;
            }
            $weight = max(.05, 1 - (float) $posterior->uncertainty) * log(1 + max(1, (int) $posterior->observations));
            foreach ($genes as $gene) {
                $gene = (string) $gene;
                $geneEvidence[$gene] ??= ['score' => 0.0, 'positive_weight' => 0.0, 'negative_weight' => 0.0, 'sources' => 0];
                $geneEvidence[$gene]['score'] += (float) $posterior->net_value * $weight;
                $geneEvidence[$gene][(string) $posterior->decay_state === 'confirmed' ? 'positive_weight' : 'negative_weight'] += $weight;
                $geneEvidence[$gene]['sources']++;
            }
            $sources[] = [
                'instrument_key' => $posterior->instrument?->instrument_key,
                'state' => (string) $posterior->decay_state,
                'observations' => (int) $posterior->observations,
                'net_value' => (float) $posterior->net_value,
                'genes' => $genes,
                'state_key' => (string) $posterior->state_key,
                'context' => $this->posteriorContext((string) $posterior->state_key),
                'source_type' => 'isolated_instrument',
            ];
        }
        $preferred = [];
        $blocked = [];
        foreach ($geneEvidence as $gene => $evidence) {
            $instrumentKeys = collect($rows)
                ->filter(fn (InstrumentValuePosterior $posterior): bool => in_array($gene, (array) ($posterior->instrument?->contract?->allowed_genes ?? []), true))
                ->pluck('instrument.instrument_key')
                ->filter()
                ->unique();
            $bundlePositive = $instrumentKeys->sum(fn (string $key): float => (float) data_get($bundleEvidence, $key.'.positive_weight', 0));
            $bundleNegative = $instrumentKeys->sum(fn (string $key): float => (float) data_get($bundleEvidence, $key.'.negative_weight', 0));
            $geneEvidence[$gene]['bundle_positive_weight'] = $bundlePositive;
            $geneEvidence[$gene]['bundle_negative_weight'] = $bundleNegative;
            // Block B requires agreement: an isolated component and at least
            // one exact observed support bundle must both be positive. A bad
            // bundle can veto reuse even when the component looked useful in
            // isolation, because the executable organism consumes the pair.
            if ((float) $evidence['score'] > 0
                && (float) $evidence['positive_weight'] > (float) $evidence['negative_weight']
                && $bundlePositive > $bundleNegative) {
                $preferred[] = $gene;
            } elseif (((float) $evidence['score'] < 0 && (float) $evidence['negative_weight'] >= (float) $evidence['positive_weight'])
                || $bundleNegative > $bundlePositive) {
                $blocked[] = $gene;
            }
        }

        return [
            'protocol' => 'instrument_posterior_mutation_policy_v2',
            'symbol' => $symbol,
            'strategy_family' => $family,
            'context' => $requestedContext,
            'preferred_genes' => array_values(array_unique($preferred)),
            'blocked_genes' => array_values(array_unique($blocked)),
            'sources' => [...$sources, ...$bundleSources],
            'bundle_sources' => $bundleSources,
            'gene_posteriors' => $geneEvidence,
            'research_inbox' => $this->familyPriorInbox($symbol, $family, $familyGenes),
            'rule' => 'only exact family-and-context isolated evidence plus exact-bundle agreement can guide mutation; hierarchical/global priors may open controlled experiments but never inherit or block directly',
            'paper_execution_authority' => false,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string,string> */
    private function posteriorContext(string $stateKey): array
    {
        [$regime, $session, $volatility, $spread, $transition, $lossStreak, $direction, $family] = array_pad(explode('|', $stateKey), 8, null);
        $axes = app(ContextContractV2Service::class)->canonicalAxes([
            'regime' => $regime,
            'session' => $session,
            'volatility' => $volatility,
            'spread_liquidity_state' => $spread,
            'transition_state' => $transition,
            'direction' => $direction,
        ]);

        if (filled($family)) {
            $axes['strategy_family'] = (string) $family;
        }

        return array_filter($axes, static fn ($value): bool => $value !== null && $value !== '');
    }

    /** @return list<array<string,mixed>> */
    private function familyPriorInbox(string $symbol, string $family, array $familyGenes): array
    {
        return InstrumentValuePosterior::query()
            ->with('instrument.contract')
            ->where('symbol', $symbol)
            ->where('timeframe', 'M15')
            ->whereIn('decay_state', ['confirmed', 'forbidden', 'provisional'])
            ->latest('last_observed_at')
            ->limit(200)
            ->get()
            ->filter(function (InstrumentValuePosterior $posterior) use ($family, $familyGenes): bool {
                $observed = $this->posteriorContext((string) $posterior->state_key);
                $genes = array_intersect((array) ($posterior->instrument?->contract?->allowed_genes ?? []), $familyGenes);

                return ($observed['strategy_family'] ?? null) === $family && $genes !== [];
            })
            ->map(fn (InstrumentValuePosterior $posterior): array => [
                'instrument_key' => $posterior->instrument?->instrument_key,
                'genes' => array_values(array_intersect((array) ($posterior->instrument?->contract?->allowed_genes ?? []), $familyGenes)),
                'context' => $this->posteriorContext((string) $posterior->state_key),
                'state' => (string) $posterior->decay_state,
                'observations' => (int) $posterior->observations,
                'net_value' => (float) $posterior->net_value,
                'authority' => 'controlled_experiment_proposal_only',
            ])->values()->all();
    }

    /** @return array<int, string> */
    private function instrumentKeys(LabAgent $agent, ?string $changedGene): array
    {
        $primary = $changedGene ? $this->instrumentForGene($changedGene) : $this->baselineInstrument($agent);
        $keys = array_values(array_unique(array_filter([$primary, 'atr_risk_envelope', 'cost_aware_exit'])));

        return array_slice($keys, 0, 6);
    }

    /**
     * Tell the replay runtime what observable event constitutes use. Merely
     * carrying a compatible parameter is inventory, not a decision. The
     * context boundary is copied from the instrument contract so the runtime
     * can expose local activation/abstention without inventing authority.
     *
     * @return array<string,mixed>
     */
    private function activationContract(TradingInstrument $instrument, LabAgent $agent, array $pairReservation): array
    {
        $key = (string) $instrument->instrument_key;
        $runtimeEvents = match ($key) {
            'trend_pullback' => ['trend_decision:*'],
            'breakout_retest' => ['breakout_decision:*'],
            'compression_expansion' => ['compression_decision:*'],
            'range_reentry' => ['range_decision:*'],
            'session_breakout' => ['session_breakout_signal_evaluated'],
            'session_range' => ['session_range_evaluated'],
            'volume_confirmation' => ['volume_policy_evaluated'],
            'transition_protection' => ['transition_boundary_wait_started', 'transition_entry_veto'],
            'cost_firewall' => ['entry_cost_gate_evaluated'],
            'high_volatility_firewall' => ['high_volatility_gate_evaluated'],
            'loss_streak_cooldown' => ['loss_streak_wait', 'loss_cooldown'],
            'dynamic_cooldown' => ['loss_streak_wait', 'loss_cooldown', 'loss_cooldown_scheduled'],
            'atr_risk_envelope' => ['entry_stop_target_sized'],
            'cost_aware_exit' => ['position_exit:*'],
            'regime_router' => ['router_selected:*'],
            'adaptive_entry_topology' => ['entry_topology_selected:*'],
            'confidence_firewall' => ['confidence_gate_evaluated'],
            'temporal_survival_filter' => ['temporal_survival_evaluated', 'state_machine_transition:*'],
            'meta_label_filter' => ['meta_label_gate_evaluated'],
            'dynamic_fibonacci_zone', 'confirmed_swing' => ['structure_location_evaluated'],
            'support_resistance_zone' => ['structure_location_evaluated'],
            'bos_event' => ['bos_event_observed'],
            'choch_event' => ['choch_event_observed'],
            'liquidity_sweep' => ['liquidity_sweep_observed'],
            'liquidity_pool' => ['liquidity_pool_proxy_evaluated'],
            default => ['runtime_hook_unavailable_no_credit'],
        };
        $contract = $instrument->contract;
        $capsuleContext = (array) data_get($pairReservation, 'trait_capsule.activation_context.predicate', []);
        $semantic = (array) data_get($agent->modelVersion?->metadata, 'semantic_group', []);
        $lane = (array) data_get($agent->modelVersion?->metadata, 'portfolio_council_lane', []);
        $specialistCell = (array) data_get(
            $agent->modelVersion?->metadata,
            'specialist_council_membership.contextual_cell',
            [],
        );
        $declaredContext = array_filter([
            'regime' => data_get($capsuleContext, 'regime', data_get($specialistCell, 'regime', data_get($semantic, 'regime', data_get($lane, 'regime')))),
            'session' => data_get($capsuleContext, 'session', data_get($specialistCell, 'session', data_get($lane, 'session', data_get($lane, 'owner_context.session')))),
            'volatility' => data_get($capsuleContext, 'volatility', data_get($specialistCell, 'volatility', data_get($semantic, 'volatility', data_get($lane, 'volatility')))),
            'spread_liquidity_state' => data_get($capsuleContext, 'spread_liquidity_state', data_get($specialistCell, 'spread_liquidity_state', data_get($lane, 'spread_liquidity_state'))),
            'transition_state' => data_get($capsuleContext, 'transition_state', data_get($specialistCell, 'transition_state', data_get($lane, 'transition_state'))),
            'direction' => data_get($capsuleContext, 'direction', data_get($specialistCell, 'direction', data_get($semantic, 'direction', data_get($lane, 'direction')))),
        ], static fn ($value): bool => ! in_array($value, [null, '', '*', 'both', 'unknown'], true));

        return [
            'protocol' => self::ACTIVATION_PROTOCOL,
            'mode' => 'instrument_specific_runtime_event',
            'required_runtime_events' => $runtimeEvents,
            'aggregate_metric_fallback_allowed' => false,
            'context' => [
                'compatible_regimes' => array_values((array) ($contract?->compatible_regimes ?? [])),
                'forbidden_regimes' => array_values((array) ($contract?->forbidden_regimes ?? [])),
                'required_inputs' => array_values((array) ($contract?->required_inputs ?? [])),
                'declared_context' => $declaredContext,
                'session_ownership' => data_get($specialistCell, 'session_ownership'),
                'contextual_cell_hash' => data_get($specialistCell, 'cell_hash'),
                'outside_scope_action' => 'ABSTAIN',
            ],
            'no_signal_status' => 'not_activated',
            'assignment_or_binding_alone_is_evidence' => false,
            'promotion_evidence' => false,
        ];
    }

    private function instrumentForGene(string $gene): string
    {
        return match (true) {
            $gene === 'volume_lane' => 'volume_confirmation',
            str_starts_with($gene, 'meta_label_') => 'meta_label_filter',
            $gene === 'entry_topology_variant' => 'adaptive_entry_topology',
            $gene === 'state_machine_variant', str_starts_with($gene, 'signal_'), str_starts_with($gene, 'temporal_'), str_contains($gene, 'drift_') => 'temporal_survival_filter',
            str_starts_with($gene, 'session_') => 'session_range',
            str_starts_with($gene, 'range_') => 'range_reentry',
            str_starts_with($gene, 'breakout_') => 'breakout_retest',
            str_starts_with($gene, 'atr_stop_'), str_contains($gene, 'risk_multiplier') => 'atr_risk_envelope',
            str_starts_with($gene, 'atr_target_'), str_starts_with($gene, 'trailing_'), str_starts_with($gene, 'time_stop_'), str_starts_with($gene, 'partial_') => 'cost_aware_exit',
            str_contains($gene, 'spread') => 'cost_firewall',
            str_contains($gene, 'loss_streak'), str_contains($gene, 'cooldown') => 'dynamic_cooldown',
            str_starts_with($gene, 'transition_') => 'transition_protection',
            str_contains($gene, 'high_volatility'), str_starts_with($gene, 'avoid_high_') => 'high_volatility_firewall',
            str_contains($gene, 'confidence') => 'confidence_firewall',
            str_contains($gene, 'regime'), str_contains($gene, 'weight'), str_starts_with($gene, 'differential_'), str_starts_with($gene, 'architecture_') => 'regime_router',
            str_starts_with($gene, 'trend_') => 'trend_pullback',
            default => 'adaptive_entry_topology',
        };
    }

    private function baselineInstrument(LabAgent $agent): string
    {
        return match ((string) $agent->strategy_family) {
            'trend' => 'trend_pullback',
            'breakout' => 'breakout_retest',
            'volatility' => 'compression_expansion',
            'mean_reversion' => 'range_reentry',
            'session' => 'session_breakout',
            default => 'regime_router',
        };
    }

    private function experimentRole(LabAgent $agent, ?string $changedGene): string
    {
        $metadata = (array) ($agent->modelVersion?->metadata ?? []);
        if ((string) data_get($metadata, 'control_contract.protocol') === 'frozen_control_v2'
            && data_get($metadata, 'control_contract.control_only') === true) {
            return 'frozen_control';
        }

        return $changedGene ? 'candidate' : 'baseline_observation';
    }

    /** @return array<string,mixed> */
    private function pairReservation(LabAgent $agent, string $role): array
    {
        $authority = $this->authorityCapsuleReservation($agent);
        if ($authority !== null) {
            return $authority;
        }
        $inherited = $this->inheritedCapsuleReservation($agent);
        if ($inherited !== null && (string) data_get($inherited, 'status') !== 'reserved') {
            return $inherited;
        }
        if ($role !== 'candidate') {
            return [
                'status' => $role === 'frozen_control' ? 'control_role' : 'not_required',
                'required' => false,
                'promotion_evidence' => false,
            ];
        }

        $contract = (array) data_get($agent->modelVersion?->metadata, 'control_pair_contract', []);
        $pairKey = (string) data_get($contract, 'pair_key', '');
        if ($pairKey === '' || (string) data_get($contract, 'role', '') !== 'candidate') {
            return [
                'status' => 'missing', 'required' => true,
                'reason_code' => 'EXACT_CONTROL_PAIR_CONTRACT_MISSING',
                'promotion_evidence' => false,
            ];
        }

        $declaredControlId = (int) data_get($contract, 'control_agent_id', 0);
        $control = $agent->generation?->agents()
            ->with('modelVersion')
            ->get()
            ->first(function (LabAgent $peer) use ($agent, $pairKey, $declaredControlId): bool {
                if ((int) $peer->id === (int) $agent->id || ! $peer->modelVersion) {
                    return false;
                }
                if ($declaredControlId > 0 && (int) $peer->id !== $declaredControlId) {
                    return false;
                }
                $peerContract = (array) data_get($peer->modelVersion->metadata, 'control_pair_contract', []);

                return (string) data_get($peerContract, 'role', '') === 'control'
                    && hash_equals($pairKey, (string) data_get($peerContract, 'pair_key', ''));
            });
        if (! $control || ! $this->baselines->matches($agent, $control)) {
            return [
                'status' => 'missing', 'required' => true, 'pair_key' => $pairKey,
                'reason_code' => 'EXACT_SAME_GENERATION_CONTROL_NOT_RESERVED',
                'promotion_evidence' => false,
            ];
        }

        return [
            'protocol' => 'instrument_exact_pair_reservation_v1',
            'status' => 'reserved',
            'required' => true,
            'pair_key' => $pairKey,
            'candidate_agent_id' => (int) $agent->id,
            'control_agent_id' => (int) $control->id,
            'same_generation' => true,
            'single_intervention' => count((array) $agent->parameter_diff) === 1,
            'exact_parameter_baseline' => true,
            'trait_capsule' => data_get($inherited, 'trait_capsule'),
            'capsule_hash_valid' => data_get($inherited, 'capsule_hash_valid'),
            'promotion_evidence' => false,
        ];
    }

    /**
     * An ordinary child may inherit exactly one contextual component from an
     * eligible parent. Multiple capsules remain a crossover hypothesis and
     * cannot silently choose a bundle. The inherited tested value and broad
     * routing cell must still match before the exact paired replay is opened.
     *
     * @return array<string,mixed>|null
     */
    private function inheritedCapsuleReservation(LabAgent $agent): ?array
    {
        $capsules = (array) data_get(
            $agent->modelVersion?->metadata,
            'adaptive_parent_ecosystem.selected_contextual_trait_capsules',
            [],
        );
        if ($capsules === []) {
            return null;
        }
        if (count($capsules) !== 1) {
            return [
                'protocol' => ContextualCausalTraitCapsuleService::PROTOCOL,
                'status' => 'missing', 'required' => true,
                'reason_code' => 'MULTIPLE_CONTEXTUAL_CAPSULES_REQUIRE_FACTORIAL_CROSSOVER_PROOF',
                'promotion_evidence' => false,
            ];
        }
        $capsule = (array) collect($capsules)->first();
        $gene = (string) data_get($capsule, 'trait.gene', '');
        $semantic = (array) data_get($agent->modelVersion?->metadata, 'semantic_group', []);
        $assessment = app(ContextualCausalTraitCapsuleService::class)->assess($capsule, $gene, [
            'regime' => data_get($semantic, 'regime'),
            'volatility' => data_get($semantic, 'volatility'),
            'direction' => data_get($semantic, 'direction'),
        ]);
        $tested = data_get($capsule, 'trait.tested_value');
        $actual = data_get($agent->modelVersion?->parameters, $gene);
        $valueMatches = is_numeric($tested) && is_numeric($actual)
            ? abs((float) $tested - (float) $actual) < .000000001
            : json_encode($tested, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)
                === json_encode($actual, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $valid = (bool) data_get($assessment, 'valid', false) && $gene !== '' && $valueMatches;

        return [
            'protocol' => ContextualCausalTraitCapsuleService::PROTOCOL,
            'status' => $valid ? 'reserved' : 'missing', 'required' => true,
            'reason_code' => $valid ? null : 'INHERITED_CONTEXTUAL_TRAIT_CAPSULE_INVALID',
            'trait_capsule' => $capsule,
            'capsule_hash_valid' => (bool) data_get($assessment, 'checks.capsule_hash_valid', false),
            'tested_value_matches' => $valueMatches,
            'runtime_activation_required' => true,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string,mixed>|null */
    private function authorityCapsuleReservation(LabAgent $agent): ?array
    {
        $metadata = (array) ($agent->modelVersion?->metadata ?? []);
        $contract = data_get($metadata, 'authority_incubator.protocol') === EvolutionaryAuthorityFoundryService::PROTOCOL
            ? (array) data_get($metadata, 'authority_incubator', [])
            : (data_get($metadata, 'authority_descendant.protocol') === EvolutionaryAuthorityFoundryService::PROTOCOL
                ? (array) data_get($metadata, 'authority_descendant', [])
                : []);
        if ($contract === []) {
            return null;
        }
        $capsule = (array) data_get($contract, 'trait_capsule', []);
        $gene = (string) data_get($contract, 'confirmed_gene', '');
        $assessment = app(ContextualCausalTraitCapsuleService::class)->assess($capsule, $gene);
        $sameHash = filled(data_get($contract, 'trait_capsule_hash'))
            && hash_equals((string) data_get($contract, 'trait_capsule_hash'), (string) data_get($capsule, 'capsule_hash', ''));

        return [
            'protocol' => ContextualCausalTraitCapsuleService::PROTOCOL,
            'status' => (bool) data_get($assessment, 'valid', false) && $sameHash ? 'reserved' : 'missing',
            'required' => true,
            'reason_code' => (bool) data_get($assessment, 'valid', false) && $sameHash
                ? null
                : 'AUTHORITY_TRAIT_CAPSULE_INVALID',
            'trait_capsule' => $capsule,
            'capsule_hash_valid' => $sameHash,
            'same_generation' => true,
            'single_intervention' => count((array) $agent->parameter_diff) <= 1,
            'exact_parameter_baseline' => true,
            'promotion_evidence' => false,
        ];
    }

    /** @param list<string> $keys */
    private function researchBundle(string $symbol, array $keys, string $primaryKey): PlaybookComposition
    {
        $keys = array_values(array_unique(array_map('strval', $keys)));
        $bundleHash = $this->hash(['symbol' => $symbol, 'timeframe' => 'M15', 'instrument_keys' => $keys]);

        return PlaybookComposition::firstOrCreate(
            ['playbook_key' => 'research_bundle_'.substr($bundleHash, 0, 32)],
            [
                'label' => 'Research bundle '.substr($bundleHash, 0, 8),
                'symbol' => $symbol,
                'timeframe' => 'M15',
                'promotion_state' => 'research_only',
                'instrument_keys' => $keys,
                'preconditions' => [],
                'metadata' => [
                    'protocol' => 'exact_instrument_research_bundle_v1',
                    'bundle_hash' => $bundleHash,
                    'primary_instrument_key' => $primaryKey,
                    'router_eligible' => false,
                    'research_block' => 'research_inbox',
                    'interaction_identified' => false,
                    'interaction_requires' => 'controlled_factorial_ablation',
                    'promotion_evidence' => false,
                ],
            ],
        );
    }

    private function unavailable(string $reason): array
    {
        return [
            'protocol' => self::PROTOCOL,
            'status' => 'unavailable',
            'reason' => $reason,
            'selected' => [],
            'selected_keys' => [],
            'promotion_evidence' => false,
        ];
    }

    private function hash(mixed $value): string
    {
        return hash('sha256', json_encode($this->canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) {
            $number = rtrim(rtrim(sprintf('%.14F', (float) $value), '0'), '.');

            return 'number:'.($number === '-0' || $number === '' ? '0' : $number);
        }
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
