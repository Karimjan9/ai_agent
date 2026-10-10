<?php

namespace App\Services;

use App\Models\AgentLearningCausalExperiment;
use App\Models\InstrumentValuePosterior;
use App\Models\LabAgent;
use App\Models\ModelVersion;
use App\Models\PlaybookComposition;
use App\Models\PlaybookValuePosterior;
use App\Models\TradingInstrument;
use Illuminate\Support\Facades\DB;

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

    public const PAIR_SURFACE_PROTOCOL = 'paired_instrument_surface_v3';

    public const ACTIVATION_PROTOCOL = 'instrument_runtime_activation_contract_v1';

    public const RUNTIME_TRACE_PROTOCOL = 'lab_instrument_runtime_trace_v2';

    public const DECISION_DOCTRINE_PROTOCOL = 'contextual_instrument_decision_doctrine_v1';

    public const ACADEMY_RESERVATION_PROTOCOL = 'academy_primary_instrument_surface_reservation_v1';

    public function __construct(
        private TradingInstrumentOperatingSystemService $instruments,
        private ExactCausalBaselineService $baselines,
    ) {}

    /** Pure binding of an already chosen original surface; no selection or persistence. */
    public function bindScopedOriginalAssignment(\App\Models\ModelVersion $original,
        \App\Models\ModelVersion $transient, array $passport, array $recipe): array
    {
        $assignment = (array) data_get($original->metadata, 'instrument_research_assignment', []);
        if ($assignment === []) {
            if ($passport !== []) throw new \LogicException('SCOPED_ORIGINAL_COMPOSITION_ASSIGNMENT_REQUIRED');
            return [];
        }
        $unsigned = array_diff_key($assignment, ['assignment_hash' => true]);
        if (($assignment['protocol'] ?? null) !== self::PROTOCOL
            || ($assignment['assignment_hash'] ?? null) !== $this->hash($unsigned)) {
            throw new \LogicException('SCOPED_ORIGINAL_INSTRUMENT_ASSIGNMENT_INVALID');
        }
        // The source selection and activation doctrine remain exact. Only
        // derived parameter and data-bound programme identities are rebound.
        $assignment['parameter_hash'] = $this->hash((array) $transient->parameters);
        if ($passport !== []) {
            $components = (array) ($passport['components'] ?? []);
            $source = (array) ($assignment['source_components'] ?? []);
            foreach (['strategy_library_id' => 'strategy_id', 'tactic_library_key' => 'tactic_id',
                'risk_library_id' => 'risk_id', 'management_id' => 'management_id'] as $field => $component) {
                if (($source[$field] ?? null) !== null && ($source[$field] ?? null) !== ($components[$component] ?? null)) {
                    throw new \LogicException('SCOPED_ORIGINAL_INSTRUMENT_PROGRAMME_CHANGED');
                }
                $source[$field] = $components[$component] ?? null;
            }
            $source['composition_id'] = $passport['composition_id'] ?? null;
            $assignment['source_components'] = $source;
        }
        $assignment['scoped_prospective_binding'] = ['protocol' => 'scoped_original_assignment_binding_v1',
            'recipe_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($recipe),
            'original_assignment_hash' => $unsigned['assignment_hash'] ?? data_get($original->metadata, 'instrument_research_assignment.assignment_hash'),
            'original_model_version_id' => (int) $original->id, 'selection_unchanged' => true, 'promotion_evidence' => false];
        unset($assignment['assignment_hash']);
        $assignment['assignment_hash'] = $this->hash($assignment);
        return $assignment;
    }

    /** @return array<string, mixed> */
    public function assignment(LabAgent $agent): array
    {
        $agent->loadMissing('modelVersion', 'generation');
        // Primary Academy arms are one sealed experimental unit. Re-read its
        // current roster even when this arm already has a cached assignment.
        $academyReservation = $this->academyPrimaryReservation($agent);
        if ($academyReservation !== null) {
            $agent = $agent->fresh(['modelVersion', 'generation']) ?? $agent;
        }
        $model = $agent->modelVersion;
        if (! $model) {
            return $this->unavailable('MODEL_VERSION_REQUIRED');
        }

        $parameters = (array) ($model->parameters ?? []);
        $parameterHash = $this->hash($parameters);
        $passportComponents = (array) data_get($model->metadata, 'smart_composition.composition_passport.components', []);
        $sourceComponents = [
            'strategy_library_id' => $passportComponents['strategy_id'] ?? data_get($model->metadata, 'smart_composition.strategy_library_id'),
            'tactic_library_key' => $passportComponents['tactic_id'] ?? data_get($model->metadata, 'smart_composition.tactic_library_key'),
            'risk_library_id' => $passportComponents['risk_id'] ?? data_get($model->metadata, 'smart_composition.risk_library_id'),
            'management_id' => $passportComponents['management_id'] ?? null,
            'tactic_contract_id' => data_get($model->metadata, 'tactic_contract.tactic_id'),
            'composition_id' => data_get($model->metadata, 'smart_composition.composition_passport.composition_id'),
        ];
        $existing = (array) data_get($model->metadata, 'instrument_research_assignment', []);
        $existingWithoutHash = $existing;
        unset($existingWithoutHash['assignment_hash']);
        if ((string) data_get($existing, 'protocol') === self::PROTOCOL
            && ($academyReservation === null || (data_get($academyReservation, 'status') === 'reserved'
                && $this->hash((array) data_get($existing, 'pair_reservation', [])) === $this->hash($academyReservation)
                && $this->academyCachedSurfaceMatches($existing, $agent, $academyReservation)))
            && (string) data_get($existing, 'pair_surface_protocol') === self::PAIR_SURFACE_PROTOCOL
            && ! str_starts_with((string) data_get($existing, 'status', ''), 'blocked_')
            && (string) data_get($existing, 'hash_protocol') === self::HASH_PROTOCOL
            && (string) data_get($existing, 'activation_policy.protocol') === self::ACTIVATION_PROTOCOL
            && (string) data_get($existing, 'decision_doctrine.protocol') === self::DECISION_DOCTRINE_PROTOCOL
            && (string) data_get($existing, 'capability_protocol') === TradingInstrumentOperatingSystemService::CAPABILITY_PROTOCOL
            && collect((array) data_get($existing, 'selected', []))->every(fn (array $row): bool =>
                data_get($row, 'runtime_capability.capability_hash') ===
                    $this->instruments->runtimeCapability((string) data_get($row, 'instrument_key', ''))['capability_hash'])
            && (string) data_get($existing, 'parameter_hash') === $parameterHash
            && filled(data_get($existing, 'instrument_key_role_hash'))
            && filled(data_get($existing, 'activation_context_hash'))
            && (array) data_get($existing, 'source_components', []) === $sourceComponents
            && array_key_exists('sealed_treatment_gene', $existing)
            && filled(data_get($existing, 'assignment_hash'))
            && hash_equals((string) data_get($existing, 'assignment_hash'), $this->hash($existingWithoutHash))) {
            return $existing;
        }

        $this->instruments->seedDefaults();
        $changedGene = count((array) $agent->parameter_diff) === 1
            ? (string) array_key_first((array) $agent->parameter_diff)
            : null;
        $experimentRole = $this->experimentRole($agent, $changedGene);
        $pairReservation = $academyReservation ?? $this->pairReservation($agent, $experimentRole);
        // A frozen control has no parameter_diff by design.  Its treatment
        // surface must nevertheless be the same surface pre-registered for
        // the guided/blinded arms; otherwise a family fallback instrument can
        // silently turn an exact control into a different policy.
        $tripletGene = (string) data_get($pairReservation, 'protocol') === 'causal_triplet_instrument_reservation_v1'
            ? (string) data_get($pairReservation, 'gene_key', '')
            : '';
        $treatmentGene = $tripletGene !== '' ? $tripletGene : ($changedGene ?: (string) data_get($pairReservation, 'gene_key', ''));
        $treatmentGene = $treatmentGene !== '' ? $treatmentGene : null;
        // A one-gene instrument candidate without its already-persisted exact
        // control must not even reach Python. This prevents an ever-growing
        // awaiting_paired_control vitrine from masquerading as learning.
        $pairReady = ($pairReservation['required'] ?? false) !== true
            || (string) ($pairReservation['status'] ?? '') === 'reserved';
        $capsuleKeys = array_values(array_unique(array_filter(array_map(
            'strval',
            (array) data_get($pairReservation, 'trait_capsule.instrument_bundle.instrument_keys', []),
        ))));
        $keys = $pairReady
            ? ($capsuleKeys !== [] ? $capsuleKeys : $this->instrumentKeys($agent, $treatmentGene))
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
                'causal_candidate' => $treatmentGene !== null && in_array($treatmentGene, $allowed, true),
                'selection_reason' => $treatmentGene !== null && in_array($treatmentGene, $allowed, true)
                    ? ($changedGene !== null ? 'changed_gene_causal_surface' : 'frozen_treatment_surface')
                    : ($experimentRole === 'frozen_control' ? 'frozen_control_observation' : 'frozen_support_component'),
                'learning_authority' => $academyReservation !== null
                    ? 'academy_stage_observation_only_no_isolated_instrument_credit'
                    : ($changedGene !== null && in_array($changedGene, $allowed, true)
                    ? 'eligible_for_local_paired_delta_only_after_runtime_activation'
                    : 'support_observation_only_without_factorial_attribution'),
                'activation_contract' => $this->activationContract($instrument, $agent, $pairReservation),
                'runtime_capability' => $this->instruments->runtimeCapability($key),
                'research_readiness' => $this->instruments->researchReadiness($key, $parameters),
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
            'pair_surface_protocol' => self::PAIR_SURFACE_PROTOCOL,
            'capability_protocol' => TradingInstrumentOperatingSystemService::CAPABILITY_PROTOCOL,
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
            'sealed_treatment_gene' => $treatmentGene,
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
            'instrument_key_role_hash' => $this->hash(collect($selected)
                ->map(fn (array $item): array => [
                    'instrument_key' => (string) ($item['instrument_key'] ?? ''),
                    'role' => (string) ($item['role'] ?? ''),
                ])->values()->all()),
            'activation_context_hash' => $this->hash(collect($selected)
                ->mapWithKeys(fn (array $item): array => [
                    (string) ($item['instrument_key'] ?? '') => (array) data_get($item, 'activation_contract.context', []),
                ])->all()),
            'source_components' => $sourceComponents,
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
        $axes = ['regime', 'session', 'venue_phase', 'volatility', 'spread_liquidity_state', 'transition_state', 'direction'];
        $requested = array_filter(array_intersect_key(app(ContextContractV2Service::class)->canonicalAxes($context), array_flip($axes)),
            static fn ($value): bool => $value !== null && $value !== '');
        $authority = app(InstrumentPosteriorAuthorityService::class);
        $sources = []; $bundles = []; $preferred = []; $blocked = []; $evidence = [];
        $matches = function (string $stateKey) use ($family, $requested, $axes): bool {
            $observed = $this->posteriorContext($stateKey);
            if (($observed['strategy_family'] ?? null) !== $family || count($requested) !== count($axes)) return false;
            foreach ($axes as $axis) {
                if (! isset($observed[$axis]) || (string) $observed[$axis] !== (string) $requested[$axis]) return false;
            }

            return true;
        };
        foreach (PlaybookValuePosterior::query()->with('playbook')->where('symbol', $symbol)->where('timeframe', 'M15')
            ->whereIn('decay_state', ['confirmed', 'forbidden'])->get() as $posterior) {
            if (! $matches((string) $posterior->state_key)
                || data_get($posterior->playbook?->metadata, 'protocol') !== 'exact_instrument_research_bundle_v1') continue;
            $primary = (string) data_get($posterior->playbook?->metadata, 'primary_instrument_key', '');
            if ($primary === '') continue;
            foreach ($authority->validationEpochs($posterior) as $epoch) {
                if (! in_array($epoch['canonical_state'], ['confirmed', 'forbidden'], true)) continue;
                $bundles[] = [
                    'source_type' => 'exact_instrument_bundle', 'posterior_id' => (int) $posterior->id,
                    'validation_epoch_key' => $epoch['epoch_key'], 'tested_intervention' => $epoch['tested_intervention'],
                    'window_evidence_digest' => app(ResearchPaperEpochContractService::class)->parameterHash($epoch['window_evidence']),
                    'source_receipts' => array_column($epoch['window_evidence'], 'source_receipt'),
                    'playbook_key' => $posterior->playbook?->playbook_key, 'bundle_hash' => data_get($posterior->playbook?->metadata, 'bundle_hash'),
                    'primary_instrument_key' => $primary, 'instrument_keys' => (array) $posterior->playbook?->instrument_keys,
                    'state' => $epoch['canonical_state'], 'observations' => $epoch['observations'], 'net_value' => $epoch['net_value'],
                    'state_key' => (string) $posterior->state_key, 'context' => $this->posteriorContext((string) $posterior->state_key),
                    'interaction_identified' => (bool) data_get($posterior->value_vector, 'interaction_identified', false),
                ];
            }
        }
        foreach (InstrumentValuePosterior::query()->with('instrument.contract')->where('symbol', $symbol)->where('timeframe', 'M15')
            ->whereIn('decay_state', ['confirmed', 'forbidden'])->get() as $posterior) {
            if (! $matches((string) $posterior->state_key)) continue;
            foreach ($authority->validationEpochs($posterior) as $epoch) {
                if (! in_array($epoch['canonical_state'], ['confirmed', 'forbidden'], true)) continue;
                $delta = $epoch['tested_intervention']; $gene = (string) $delta['gene'];
                // Only the actually isolated delta owns this utility; allowed
                // adapter genes are not a list of experimentally proven effects.
                if (! in_array($gene, $familyGenes, true)
                    || ! in_array($gene, (array) $posterior->instrument?->contract?->allowed_genes, true)) continue;
                $source = [
                    'source_type' => 'isolated_instrument', 'instrument_key' => $posterior->instrument?->instrument_key,
                    'posterior_id' => (int) $posterior->id, 'validation_epoch_key' => $epoch['epoch_key'],
                    'window_evidence_digest' => app(ResearchPaperEpochContractService::class)->parameterHash($epoch['window_evidence']),
                    'source_receipts' => array_column($epoch['window_evidence'], 'source_receipt'),
                    'tested_intervention' => $delta, 'state' => $epoch['canonical_state'],
                    'observations' => $epoch['observations'], 'net_value' => $epoch['net_value'], 'genes' => [$gene],
                    'state_key' => (string) $posterior->state_key, 'context' => $this->posteriorContext((string) $posterior->state_key),
                ];
                $sources[] = $source;
                $agree = array_values(array_filter($bundles, static fn (array $bundle): bool =>
                    $bundle['primary_instrument_key'] === $source['instrument_key']
                    && $bundle['state_key'] === $source['state_key']
                    && $bundle['validation_epoch_key'] === $source['validation_epoch_key']
                    && $bundle['tested_intervention']['intervention_hash'] === $delta['intervention_hash']));
                $positive = array_filter($agree, static fn (array $bundle): bool => $bundle['state'] === 'confirmed');
                $negative = array_filter($agree, static fn (array $bundle): bool => $bundle['state'] === 'forbidden');
                $entry = ['gene' => $gene, 'old' => $delta['old'], 'new' => $delta['new'],
                    'tested_intervention' => $delta, 'source' => $source, 'bundle_sources' => $agree];
                $evidence[$delta['intervention_hash']] = ['gene' => $gene, 'net_value' => $epoch['net_value'],
                    'observations' => $epoch['observations'], 'state' => $epoch['canonical_state']];
                if ($source['state'] === 'confirmed' && $positive !== [] && $negative === []) {
                    $preferred[$delta['intervention_hash']] = $entry;
                } elseif ($source['state'] === 'forbidden' || $negative !== []) {
                    $blocked[$delta['intervention_hash']] = $entry;
                }
            }
        }
        foreach (array_keys($blocked) as $key) unset($preferred[$key]);

        return [
            'protocol' => 'instrument_posterior_mutation_policy_v3', 'symbol' => $symbol, 'strategy_family' => $family,
            'context' => $requested, 'preferred_genes' => array_values(array_unique(array_column($preferred, 'gene'))),
            // A harmful exact value is not a gene-wide mutation prohibition.
            'blocked_genes' => [], 'preferred_deltas' => array_values($preferred), 'blocked_deltas' => array_values($blocked),
            'sources' => [...$sources, ...$bundles], 'bundle_sources' => $bundles, 'gene_posteriors' => $evidence,
            'research_inbox' => $this->familyPriorInbox($symbol, $family, $familyGenes),
            'rule' => 'exact tested delta, validation epoch and full context only; no utility spill or gene-wide ban',
            'paper_execution_authority' => false, 'promotion_evidence' => false,
        ];
    }

    /** @return array<string,string> */
    private function posteriorContext(string $stateKey): array
    {
        [$regime, $session, $volatility, $spread, $transition, $lossStreak, $direction, $family, $venuePhase] = array_pad(explode('|', $stateKey), 9, null);
        $axes = app(ContextContractV2Service::class)->canonicalAxes([
            'regime' => $regime,
            'session' => $session,
            'volatility' => $volatility,
            'spread_liquidity_state' => $spread,
            'transition_state' => $transition,
            'direction' => $direction,
            'venue_phase' => $venuePhase,
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
        $primary = $changedGene ? $this->instrumentForGene($changedGene, (string) $agent->strategy_family)
            : $this->baselineInstrument($agent);
        // The treatment owner is fixed by the exact pair, never chosen from
        // a more flattering posterior. Add only support surfaces this model
        // actually exposes; safety/risk ownership is unchanged by telemetry.
        $parameters = (array) $agent->modelVersion?->parameters;
        $supports = array_filter(['atr_risk_envelope', 'cost_aware_exit'], function (string $key) use ($parameters): bool {
            $card = $this->instruments->runtimeCapability($key);
            return array_intersect($card['parameter_surface'], array_keys($parameters)) !== [];
        });
        $keys = array_values(array_unique([$primary, ...$supports]));

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
        $runtimeEvents = $this->instruments->runtimeCapability($key)['runtime_events']
            ?: ['runtime_hook_unavailable_no_credit'];
        $contract = $instrument->contract;
        $capsuleContext = (array) data_get($pairReservation, 'trait_capsule.activation_context.predicate', []);
        $semantic = (array) data_get($agent->modelVersion?->metadata, 'semantic_group', []);
        $lane = (array) data_get($agent->modelVersion?->metadata, 'portfolio_council_lane', []);
        $specialistCell = (array) data_get(
            $agent->modelVersion?->metadata,
            'specialist_council_membership.contextual_cell',
            [],
        );
        $rawDeclaredContext = [
            'regime' => data_get($capsuleContext, 'regime', data_get($specialistCell, 'regime', data_get($semantic, 'regime', data_get($lane, 'regime')))),
            'session' => data_get($capsuleContext, 'session', data_get($specialistCell, 'session', data_get($lane, 'session', data_get($lane, 'owner_context.session')))),
            'venue_phase' => data_get($capsuleContext, 'venue_phase', data_get($specialistCell, 'venue_phase', data_get($lane, 'venue_phase'))),
            'volatility' => data_get($capsuleContext, 'volatility', data_get($specialistCell, 'volatility', data_get($semantic, 'volatility', data_get($lane, 'volatility')))),
            'spread_liquidity_state' => data_get($capsuleContext, 'spread_liquidity_state', data_get($specialistCell, 'spread_liquidity_state', data_get($lane, 'spread_liquidity_state'))),
            // The portfolio lane's transition_state is a historical state-
            // cluster/homework label (e.g. transition_observed), not a
            // pre-registered condition of every future entry. Copying it
            // beside a trend_up semantic regime creates an impossible scope:
            // runtime transition_state is transition only in a transition
            // regime. Only an explicit capsule or specialist cell may own a
            // live activation boundary on this axis.
            'transition_state' => data_get($capsuleContext, 'transition_state', data_get($specialistCell, 'transition_state')),
            'direction' => data_get($capsuleContext, 'direction', data_get($specialistCell, 'direction', data_get($semantic, 'direction', data_get($lane, 'direction')))),
            'session_instance_id' => data_get($capsuleContext, 'session_instance_id', data_get($specialistCell, 'session_instance_id')),
            'calendar_version' => data_get($capsuleContext, 'calendar_version', data_get($specialistCell, 'calendar_version')),
        ];
        $declaredContext = app(ContextContractV2Service::class)->canonicalDeclaredAxes($rawDeclaredContext);

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

    public function instrumentForGene(string $gene, ?string $family = null): string
    {
        // Generic genes such as lookback have different executable owners in
        // different strategy families. A global name-only fallback can attach
        // a real breakout mutation to an unrelated entry-topology instrument.
        $family = strtolower(trim((string) $family));
        return match (true) {
            $gene === 'lookback' => match ($family) {
                'breakout' => 'breakout_retest',
                'volatility' => 'compression_expansion',
                'mean_reversion' => 'range_reentry',
                'session' => 'session_breakout',
                default => 'adaptive_entry_topology',
            },
            $family === 'breakout' && in_array($gene,
                ['atr_period', 'atr_multiplier', 'confirmation_candles', 'retest_required', 'trend_strength_min'], true)
                => 'breakout_retest',
            $family === 'volatility' && $gene === 'atr_period' => 'compression_expansion',
            $family === 'mean_reversion' && in_array($gene, ['deviation', 'adx_max'], true)
                => 'range_reentry',
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
        if ($agent->origin === 'academy_experiment'
            && data_get($agent->modelVersion?->metadata, 'academy_experiment.protocol') === AcademyExperimentMaterializerService::PROTOCOL) {
            return (string) data_get($agent->modelVersion->metadata, 'academy_experiment.arm_role', 'unrecognized_academy_arm');
        }
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
        $academy = $this->academyPrimaryReservation($agent);
        if ($academy !== null) return $academy;
        $authority = $this->authorityCapsuleReservation($agent);
        if ($authority !== null) {
            return $authority;
        }
        $inherited = $this->inheritedCapsuleReservation($agent);
        if ($inherited !== null && (string) data_get($inherited, 'status') !== 'reserved') {
            return $inherited;
        }
        $causalTriplet = $this->causalTripletReservation($agent, $role);
        if ($causalTriplet !== null) {
            return $causalTriplet;
        }
        // Ordinary controls also need the treatment surface. Otherwise a
        // transition-cooldown candidate gets a firewall while its zero-diff
        // control silently gets a regime router with a different veto path.
        if ($role === 'frozen_control'
            && data_get($agent->modelVersion?->metadata, 'control_pair_contract.role') === 'control') {
            $pairKey = (string) data_get($agent->modelVersion?->metadata, 'control_pair_contract.pair_key', '');
            $candidates = $agent->generation?->agents()->with('modelVersion')->get()
                ->filter(fn (LabAgent $peer): bool => $peer->id !== $agent->id
                    && data_get($peer->modelVersion?->metadata, 'control_pair_contract.role') === 'candidate'
                    && $pairKey !== ''
                    && data_get($peer->modelVersion?->metadata, 'control_pair_contract.pair_key') === $pairKey)
                ->values();
            if ($candidates?->count() === 1
                && count((array) $candidates[0]->parameter_diff) === 1
                && $this->baselines->matches($candidates[0], $agent)) {
                return [
                    'protocol' => 'instrument_exact_pair_reservation_v1',
                    'status' => 'reserved', 'required' => true, 'pair_key' => $pairKey,
                    'candidate_agent_id' => (int) $candidates[0]->id,
                    'control_agent_id' => (int) $agent->id,
                    'gene_key' => (string) array_key_first((array) $candidates[0]->parameter_diff),
                    'same_generation' => true, 'single_intervention' => true,
                    'exact_parameter_baseline' => true, 'promotion_evidence' => false,
                ];
            }
            return ['status' => 'missing', 'required' => true,
                'reason_code' => 'EXACT_CONTROL_TREATMENT_SURFACE_UNRESOLVED',
                'promotion_evidence' => false];
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
            'gene_key' => count((array) $agent->parameter_diff) === 1
                ? (string) array_key_first((array) $agent->parameter_diff) : null,
            'same_generation' => true,
            'single_intervention' => count((array) $agent->parameter_diff) === 1,
            'exact_parameter_baseline' => true,
            'trait_capsule' => data_get($inherited, 'trait_capsule'),
            'capsule_hash_valid' => data_get($inherited, 'capsule_hash_valid'),
            'promotion_evidence' => false,
        ];
    }

    /**
     * Academy stage proof is not an ordinary instrument economic pair. Bind
     * every primary role to the same actual treatment/veto surface, without
     * inventing a constructor pair key or promoting an instrument posterior.
     * Kernel/ordinary cohorts retain their existing reservation owners.
     */
    private function academyPrimaryReservation(LabAgent $agent): ?array
    {
        if ($agent->origin !== 'academy_experiment') return null;
        $missing = static fn (string $reason): array => [
            'protocol' => self::ACADEMY_RESERVATION_PROTOCOL, 'status' => 'missing', 'required' => true,
            'reason_code' => $reason, 'research_only' => true, 'isolated_instrument_credit' => false,
            'promotion_evidence' => false,
        ];
        $current = LabAgent::query()->with('modelVersion', 'generation')->find($agent->id);
        $generation = $current?->generation;
        $metadata = (array) ($current?->modelVersion?->metadata ?? []);
        $contract = (array) data_get($metadata, 'academy_experiment', []);
        $trialId = (int) ($contract['academy_trial_id'] ?? 0);
        if (! $current || ! $generation || $generation->trigger_type !== 'academy_experiment'
            || data_get($generation->trigger_context, 'protocol') !== AcademyExperimentMaterializerService::PROTOCOL
            || ($contract['protocol'] ?? null) !== AcademyExperimentMaterializerService::PROTOCOL
            || $trialId < 1 || (int) data_get($generation->trigger_context, 'academy_trial_id') !== $trialId) {
            return $missing('ACADEMY_PRIMARY_RESERVATION_IDENTITY_REQUIRED');
        }
        $trial = DB::table('edge_academy_trials')->find($trialId);
        $outcome = $trial ? (json_decode((string) $trial->outcome, true) ?: []) : [];
        $frozen = $trial ? (json_decode((string) $trial->frozen_contract, true) ?: []) : [];
        $stored = (array) data_get($generation->trigger_context, 'compiled_contract', []);
        $axis = (string) ($stored['axis'] ?? '');
        $baselineId = (int) data_get($generation->trigger_context, 'baseline_model_version_id', 0);
        $baseline = $baselineId > 0 ? ModelVersion::query()->find($baselineId) : null;
        $baselineParameters = (array) ($frozen['baseline_parameters'] ?? []);
        if (! $trial || $trial->status !== 'materialized' || $trial->settled_at !== null
            || (int) ($outcome['generation_id'] ?? 0) !== (int) $generation->id
            || ($outcome['protocol'] ?? null) !== AcademyExperimentMaterializerService::PROTOCOL
            || ($stored['protocol'] ?? null) !== AcademyExperimentContractCompilerService::PROTOCOL
            || ($stored['status'] ?? null) !== 'compiled' || $axis === ''
            || ! $baseline || $baselineParameters === []
            || (int) ($frozen['baseline_model_version_id'] ?? 0) !== $baselineId
            || $this->hash((array) $baseline->parameters) !== $this->hash($baselineParameters)) {
            return $missing('ACADEMY_PRIMARY_ORIGINAL_TRIAL_AND_BASELINE_REQUIRED');
        }
        $compiler = app(AcademyExperimentContractCompilerService::class);
        $identity = (array) ($frozen['prospective_source_identity'] ?? []);
        $generationIdentity = (array) data_get($generation->trigger_context, 'prospective_source_identity', []);
        foreach (['data_hash', 'execution_hash', 'source_evaluator_hash', 'python_source_hash', 'mtf_bundle_hash'] as $key) {
            if (! is_string($identity[$key] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $identity[$key]) !== 1
                || ($generationIdentity[$key] ?? null) !== $identity[$key]
                || data_get($generation->trigger_context, $key) !== $identity[$key]) {
                return $missing('ACADEMY_PRIMARY_FROZEN_RUNTIME_IDENTITY_CHANGED');
            }
        }
        if (($identity['source_identity_protocol'] ?? null) !== AcademyExperimentMaterializerService::SOURCE_IDENTITY_PROTOCOL
            || ($generationIdentity['source_identity_protocol'] ?? null) !== $identity['source_identity_protocol']
            || data_get($generation->trigger_context, 'source_identity_protocol') !== $identity['source_identity_protocol']) {
            return $missing('ACADEMY_PRIMARY_FROZEN_RUNTIME_IDENTITY_CHANGED');
        }
        $compiled = $compiler->compile(['axis' => $axis, 'arms' => json_decode((string) $trial->arms, true) ?: []],
            $baselineParameters, ['symbol' => 'XAUUSD', 'laboratory_timeframe' => 'H1', 'execution_timeframe' => 'M5']);
        if (($compiled['status'] ?? null) !== 'compiled'
            || $this->hash($compiled) !== $this->hash($stored)
            || $this->hash($compiled) !== $this->hash((array) ($outcome['compiled_contract'] ?? []))
            || ! hash_equals($compiler->parameterHash($baselineParameters), (string) ($frozen['baseline_parameter_hash'] ?? ''))) {
            return $missing('ACADEMY_PRIMARY_COMPILED_CONTRACT_CHANGED');
        }
        $arms = (array) $compiled['arms'];
        $peers = $generation->agents()->with('modelVersion')->get()->filter(fn (LabAgent $peer): bool =>
            $peer->origin === 'academy_experiment' || data_get($peer->modelVersion?->metadata, 'academy_experiment') !== null)->values();
        if ($peers->count() !== count($arms) || ! in_array(count($arms), [3, 4], true)) {
            return $missing('ACADEMY_PRIMARY_COMPLETE_ROSTER_REQUIRED');
        }
        $expectedContractHash = hash('sha256', json_encode($compiled));
        $roster = [];
        $control = null;
        $contextHash = null;
        $componentHash = null;
        foreach ($peers as $peer) {
            $meta = (array) ($peer->modelVersion?->metadata ?? []);
            $armMeta = (array) data_get($meta, 'academy_experiment', []);
            foreach (['data_hash', 'execution_hash', 'source_evaluator_hash', 'python_source_hash', 'source_identity_protocol'] as $key) {
                if (($armMeta[$key] ?? null) !== $identity[$key]) {
                    return $missing('ACADEMY_PRIMARY_FROZEN_RUNTIME_IDENTITY_CHANGED');
                }
            }
            $index = $armMeta['arm_index'] ?? null;
            $arm = is_int($index) ? ($arms[$index] ?? null) : null;
            $context = array_intersect_key($meta, array_flip(['semantic_group', 'portfolio_council_lane', 'specialist_council_membership']));
            $components = [data_get($meta, 'smart_composition.composition_passport.components'), data_get($meta, 'tactic_contract'),
                data_get($meta, 'base_strategy'), data_get($meta, 'architecture'), data_get($meta, 'strategy_architecture')];
            if (! $arm || isset($roster[$index]) || ! $peer->modelVersion
                || $peer->origin !== 'academy_experiment' || $peer->strategy_family !== 'confirmation_entry_mtf'
                || strtoupper($peer->symbol) !== 'XAUUSD' || strtoupper($peer->timeframe) !== 'H1'
                || ($armMeta['protocol'] ?? null) !== AcademyExperimentMaterializerService::PROTOCOL
                || (int) ($armMeta['academy_trial_id'] ?? 0) !== $trialId
                || ($armMeta['arm_role'] ?? null) !== $arm['role']
                || (int) ($armMeta['causal_baseline_model_version_id'] ?? 0) !== $baselineId
                || (int) data_get($meta, 'causal_baseline_model_version_id', 0) !== $baselineId
                || $peer->parent_a_model_version_id !== null || $peer->parent_b_model_version_id !== null
                || ($armMeta['parameter_hash'] ?? null) !== $arm['parameter_hash']
                || ! hash_equals($expectedContractHash, (string) ($armMeta['contract_hash'] ?? ''))
                || $this->hash((array) $peer->modelVersion->parameters) !== $this->hash($arm['runtime_parameters'])
                || $this->hash((array) ($armMeta['context'] ?? [])) !== $this->hash((array) ($frozen['context'] ?? []))
                || ($contextHash !== null && $contextHash !== $this->hash($context))
                || ($componentHash !== null && $componentHash !== $this->hash($components))) {
                return $missing('ACADEMY_PRIMARY_ARM_OR_POLICY_SEAL_MISMATCH');
            }
            $contextHash ??= $this->hash($context);
            $componentHash ??= $this->hash($components);
            $roster[$index] = ['arm_index' => $index, 'arm_role' => $arm['role'], 'agent_id' => (int) $peer->id,
                'model_version_id' => (int) $peer->model_version_id, 'parameter_hash' => $arm['parameter_hash']];
            if ($arm['role'] === 'frozen_control') {
                if ($control !== null || (array) $peer->parameter_diff !== []
                    || $this->hash((array) $peer->modelVersion->parameters) !== $this->hash($baselineParameters)) {
                    return $missing('ACADEMY_PRIMARY_FROZEN_CONTROL_REQUIRED');
                }
                $control = $peer;
            }
        }
        ksort($roster);
        if (! $control || ! array_key_exists((int) ($contract['arm_index'] ?? -1), $roster)
            || (int) $roster[(int) $contract['arm_index']]['agent_id'] !== (int) $current->id) {
            return $missing('ACADEMY_PRIMARY_FROZEN_CONTROL_REQUIRED');
        }
        foreach ($peers as $peer) {
            $role = (string) data_get($peer->modelVersion->metadata, 'academy_experiment.arm_role');
            if ($role === 'candidate') {
                if (array_keys((array) $peer->parameter_diff) !== [$axis] || ! $this->baselines->matches($peer, $control)) {
                    return $missing('ACADEMY_PRIMARY_EXACT_SINGLE_AXIS_REQUIRED');
                }
            } elseif (! in_array($role, ['frozen_control', 'blinded_control'], true)
                || (array) $peer->parameter_diff !== []
                || $this->hash((array) $peer->modelVersion->parameters) !== $this->hash($baselineParameters)) {
                return $missing('ACADEMY_PRIMARY_EXACT_CONTROL_ROLE_REQUIRED');
            }
        }
        return [
            'protocol' => self::ACADEMY_RESERVATION_PROTOCOL, 'status' => 'reserved', 'required' => true,
            'academy_trial_id' => $trialId, 'lab_generation_id' => (int) $generation->id,
            'arm_role' => (string) $contract['arm_role'], 'arm_index' => (int) $contract['arm_index'],
            'control_agent_id' => (int) $control->id,
            'candidate_agent_id' => $contract['arm_role'] === 'candidate' ? (int) $current->id : null,
            'gene_key' => $axis, 'same_generation' => true, 'single_intervention' => true,
            'exact_parameter_baseline' => true, 'roster' => array_values($roster),
            'roster_hash' => $this->hash(array_values($roster)), 'compiled_contract_hash' => $this->hash($compiled),
            'common_context_hash' => $contextHash, 'common_component_hash' => $componentHash,
            'frozen_runtime_identity_hash' => $this->hash(array_intersect_key($identity, array_flip([
                'data_hash', 'execution_hash', 'source_evaluator_hash', 'python_source_hash', 'mtf_bundle_hash', 'source_identity_protocol']))),
            'research_only' => true, 'isolated_instrument_credit' => false, 'promotion_evidence' => false,
        ];
    }

    private function academyCachedSurfaceMatches(array $assignment, LabAgent $agent, array $reservation): bool
    {
        $keys = $this->instrumentKeys($agent, (string) $reservation['gene_key']);
        if ((array) ($assignment['selected_keys'] ?? []) !== $keys) return false;
        $records = TradingInstrument::query()->with('contract')->whereIn('instrument_key', $keys)->get()->keyBy('instrument_key');
        foreach ((array) ($assignment['selected'] ?? []) as $selected) {
            $instrument = $records->get((string) ($selected['instrument_key'] ?? ''));
            if (! $instrument || ($selected['role'] ?? null) !== $instrument->role
                || ($selected['tool_card_hash'] ?? null) !== $this->hash((array) $instrument->definition)
                || $this->hash((array) ($selected['activation_contract'] ?? [])) !== $this->hash($this->activationContract($instrument, $agent, $reservation))) {
                return false;
            }
        }
        return count((array) ($assignment['selected'] ?? [])) === count($keys);
    }

    /** @return array<string,mixed>|null */
    private function causalTripletReservation(LabAgent $agent, string $role): ?array
    {
        $contract = (array) data_get($agent->modelVersion?->metadata, 'causal_learning_cohort', []);
        if ((string) data_get($contract, 'protocol') !== CausalLearningCohortPlannerService::PROTOCOL) {
            return null;
        }
        $armRole = (string) data_get($contract, 'role', '');
        if (! in_array($armRole, [
            'memory_guided', 'hypothesis_guided', 'repair_guided', 'blinded', 'frozen_control',
        ], true)) {
            return null;
        }
        $experimentKey = (string) data_get($contract, 'experiment_key', '');
        $experiment = $experimentKey !== ''
            ? AgentLearningCausalExperiment::query()->where('experiment_key', $experimentKey)->first()
            : null;
        $candidateField = match ($armRole) {
            'blinded' => 'blinded_agent_id',
            'frozen_control' => 'control_agent_id',
            default => 'guided_agent_id',
        };
        $identityValid = $experiment
            && (int) $experiment->lab_generation_id === (int) $agent->lab_generation_id
            && (int) $experiment->{$candidateField} === (int) $agent->id;
        $control = $identityValid
            ? $agent->generation?->agents()->with('modelVersion')->find((int) $experiment->control_agent_id)
            : null;
        $treatment = $armRole === 'frozen_control' && $identityValid
            ? $agent->generation?->agents()->with('modelVersion')->find((int) $experiment->guided_agent_id)
            : $agent;
        $exact = $control && $treatment && $this->baselines->matches($treatment, $control);
        if (! $identityValid || ! $exact) {
            return [
                'protocol' => 'causal_triplet_instrument_reservation_v1',
                'status' => 'missing',
                'required' => true,
                'reason_code' => ! $identityValid
                    ? 'CAUSAL_TRIPLET_ARM_IDENTITY_MISMATCH'
                    : 'CAUSAL_TRIPLET_EXACT_CONTROL_MISMATCH',
                'causal_experiment_id' => $experiment?->id,
                'experiment_key' => $experimentKey ?: null,
                'arm_role' => $armRole,
                'promotion_evidence' => false,
            ];
        }

        return [
            'protocol' => 'causal_triplet_instrument_reservation_v1',
            'status' => 'reserved',
            'required' => true,
            'causal_experiment_id' => (int) $experiment->id,
            'experiment_key' => (string) $experiment->experiment_key,
            'arm_role' => $armRole,
            'candidate_agent_id' => (int) $treatment->id,
            'control_agent_id' => (int) $control->id,
            'gene_key' => (string) $experiment->gene_key,
            'same_generation' => true,
            'single_intervention' => count((array) $treatment->parameter_diff) === 1,
            'exact_parameter_baseline' => true,
            'runtime_activation_required' => true,
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
