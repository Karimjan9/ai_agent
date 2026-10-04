<?php

namespace App\Services;

use App\Models\InstrumentEvidence;
use App\Models\InstrumentValuePosterior;
use App\Models\MarketStateSnapshot;
use App\Models\PlaybookComposition;
use App\Models\PlaybookValuePosterior;
use App\Models\RouterDecision;
use App\Models\TradingInstrument;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Routes an observed market state to an executable playbook, including a
 * deliberate abstention.  This is a research router: it records decisions
 * and evidence but never promotes an instrument into live execution.
 */
class TradingInstrumentOperatingSystemService
{
    public const PROTOCOL = 'trading_instrument_operating_system_v2';

    public const CAPABILITY_PROTOCOL = 'instrument_executable_capability_v1';

    public const RESEARCH_READINESS_PROTOCOL = 'instrument_research_readiness_v2';

    /** Code-owned capability, not an assertion that a replay activated it. */
    public function runtimeCapability(string $key): array
    {
        $definition = $this->instrumentDefinitions()[$key] ?? null;
        $events = match ($key) {
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
            'dynamic_fibonacci_zone', 'confirmed_swing', 'support_resistance_zone' => ['structure_location_evaluated'],
            'bos_event' => ['bos_event_observed'], 'choch_event' => ['choch_event_observed'],
            'liquidity_sweep' => ['liquidity_sweep_observed'],
            'liquidity_pool' => ['liquidity_pool_proxy_evaluated'],
            default => [],
        };
        $proxy = in_array($key, ['dynamic_fibonacci_zone', 'confirmed_swing', 'support_resistance_zone',
            'bos_event', 'choch_event', 'liquidity_sweep', 'liquidity_pool'], true);
        $surface = (array) data_get($definition, 'contract.allowed_genes', []);
        $card = ['protocol' => self::CAPABILITY_PROTOCOL, 'instrument_key' => $key,
            'implementation_status' => ! $definition || $events === [] ? 'declarative_only'
                : ($proxy ? 'structural_proxy_adapter' : 'executable_parameter_adapter'),
            'runtime_owner' => 'ai-service-python/app/services/backtester.py',
            'runtime_events' => $events,
            'required_stage' => match ($key) {
                'cost_aware_exit' => 'position_management', 'atr_risk_envelope' => 'risk_sizing',
                'dynamic_cooldown', 'loss_streak_cooldown' => 'position_history',
                default => 'pre_entry_decision',
            },
            'parameter_surface' => $surface,
            'required_data' => array_values(array_unique(['closed_ohlc', ...match ($key) {
                'volume_confirmation' => ['canonical_volume'],
                'cost_firewall' => ['observed_bid_ask'],
                default => [],
            }])),
            'required_context' => (array) data_get($definition, 'contract.required_inputs', []),
            'compatible_regimes' => (array) data_get($definition, 'contract.compatible_regimes', []),
            'limitations' => $proxy ? ['OHLC structural proxy, not observed institutional order flow',
                'Shared feature hook does not establish an independent algorithm'] : [],
            'ablation_contract' => ['mode' => $surface === [] ? 'observation_only_no_isolated_credit' : 'exact_sealed_parameter_contrast',
                'keep_fixed' => ['strategy', 'tactic', 'risk_owner', 'execution_cost_model', 'dataset', 'context', 'unrelated_genes'],
                'safety_gate_disable_allowed' => false,
                'removal_effect_requires' => 'A+B versus B and A+B versus A in a complete controlled factorial'],
            'runtime_hook_is_not_causal_authority' => true, 'promotion_evidence' => false];
        $card['capability_hash'] = hash('sha256', json_encode($card, JSON_UNESCAPED_SLASHES));
        return $card;
    }

    /** Read-only; inventory reporting must not seed/update the registry. */
    public function runtimeCapabilities(): array
    {
        return array_map(fn (string $key): array => $this->runtimeCapability($key), array_keys($this->instrumentDefinitions()));
    }

    /** Research ranking from a sealed source observation, never current/live state or P/L. */
    public function researchReadiness(string $key, array $parameters, array $facts = []): array
    {
        $card = $this->runtimeCapability($key);
        $bound = array_values(array_intersect($card['parameter_surface'], array_keys($parameters)));
        $reason = null;
        if ($card['implementation_status'] === 'declarative_only') $reason = 'RUNTIME_ADAPTER_UNAVAILABLE';
        elseif ($bound === []) $reason = 'NO_EXECUTABLE_PARAMETER_SURFACE';
        $attested = ($facts['sealed_source'] ?? false) === true
            && strlen((string) ($facts['dataset_hash'] ?? '')) === 64
            && filled($facts['context_key'] ?? null);
        $missing = $attested ? array_values(array_filter($card['required_data'],
            fn (string $input): bool => data_get($facts, 'data_available.'.$input) !== true)) : [];
        $opportunities = $attested ? max(0, (int) ($facts['context_opportunities'] ?? 0)) : null;
        $stageReached = $attested ? data_get($facts, 'stages.'.$card['required_stage']) === true : null;
        $exactControl = $attested && ($facts['exact_control_available'] ?? false) === true;
        $prospectiveControl = $attested && ($facts['prospective_control_feasible'] ?? false) === true;
        $controlPath = $exactControl ? 'sealed_exact_control'
            : ($prospectiveControl ? 'prospective_exact_control_required' : 'missing_exact_control');
        $evaluations = $attested && is_numeric($facts['source_instrument_evaluations'] ?? null)
            ? max(0, (int) $facts['source_instrument_evaluations']) : null;
        // Evaluation count measures exploration uncertainty, not causal value.
        // Unknown counts must remain unknown rather than a fabricated 1.0.
        $uncertainty = $evaluations !== null ? round(1 / sqrt(1 + $evaluations), 6) : null;
        $status = $reason ? 'no_effect' : (! $attested ? 'unknown_data'
            : ($missing !== [] ? 'data_missing' : ($opportunities < 20 || ! $stageReached ? 'underpowered' : 'learnable')));
        $priority = match (true) {
            $status === 'learnable' && $exactControl => 4,
            $status === 'learnable' && $prospectiveControl => 3,
            $status === 'underpowered' && $stageReached && $opportunities > 0
                && ($exactControl || $prospectiveControl) => 1,
            default => 0,
        };
        $priorityReason = match (true) {
            $reason !== null => $reason,
            ! $attested => 'SEALED_SOURCE_REQUIRED',
            $missing !== [] => 'REQUIRED_DATA_MISSING',
            ! $stageReached => 'REQUIRED_STAGE_NOT_OBSERVED',
            $opportunities < 20 => 'CONTEXT_OPPORTUNITIES_UNDERPOWERED',
            ! $exactControl && ! $prospectiveControl => 'EXACT_CONTROL_PATH_MISSING',
            $exactControl => 'SEALED_CONTROL_AND_OBSERVABLE_STAGE',
            default => 'PROSPECTIVE_CONTROL_FREEZE_REQUIRED',
        };
        return ['protocol' => self::RESEARCH_READINESS_PROTOCOL, 'status' => $status,
            'reason_code' => $priorityReason, 'missing_data' => $missing, 'bound_genes' => $bound,
            'required_stage' => $card['required_stage'], 'stage_reached' => $stageReached,
            'context_opportunities' => $opportunities,
            'exact_control_available' => $exactControl, 'control_path' => $controlPath,
            'priority' => $priority, 'uncertainty' => $uncertainty,
            'source_instrument_evaluations' => $evaluations,
            'uncertainty_scope' => 'sealed_source_execution_exploration_only_not_economic_effect',
            'source_evidence' => $attested ? $facts : null,
            'negative_skill_allowed' => false, 'economic_credit_allowed' => false, 'promotion_evidence' => false];
    }

    public function seedDefaults(): array
    {
        return DB::transaction(function (): array {
            $instruments = collect($this->instrumentDefinitions())->mapWithKeys(function (array $definition, string $key): array {
                $definition['definition'] = $this->toolCard($key, $definition);
                $instrument = TradingInstrument::updateOrCreate(['instrument_key' => $key], Arr::only($definition, ['label', 'role', 'tactic_id', 'promotion_state', 'is_abstention', 'definition']));
                $instrument->contract()->updateOrCreate([], $definition['contract']);

                return [$key => $instrument];
            });

            foreach ($this->playbookDefinitions() as $playbook) {
                PlaybookComposition::updateOrCreate(['playbook_key' => $playbook['playbook_key']], $playbook);
            }

            return ['instruments' => $instruments->values(), 'playbooks' => PlaybookComposition::query()->orderBy('playbook_key')->get()];
        });
    }

    public function supports(string $symbol, string $timeframe): bool
    {
        $this->seedDefaults();
        $timeframe = $this->decisionTimeframe($symbol, $timeframe);

        return PlaybookComposition::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->exists();
    }

    /** @return array<string,mixed> */
    public function fingerprint(string $symbol, string $timeframe, array $context = []): array
    {
        $state = MarketStateSnapshot::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->latest('time')->first();
        $calendar = [];
        $timestamp = $context['time'] ?? $context['timestamp'] ?? null;
        if ($timestamp !== null && strtoupper(str_replace(['/', '_', '-'], '', $symbol)) === 'XAUUSD') {
            $calendar = app(MarketSessionCalendarService::class)->resolve($timestamp, $context);
        }
        $context = [...$calendar, ...$context];
        $rawRegime = $context['regime'] ?? $context['h1_regime'] ?? $state?->market_state ?? 'unknown';
        $rawSession = $context['session'] ?? $this->sessionFor((int) now()->format('H'));
        $rawVolatility = $context['volatility'] ?? $this->volatilityFor($state);
        $spread = array_key_exists('spread_atr_ratio', $context) && is_numeric($context['spread_atr_ratio'])
            ? (float) $context['spread_atr_ratio'] : null;
        $transition = (bool) ($context['transition'] ?? $rawRegime === 'transition');
        $lossStreak = max(0, (int) ($context['loss_streak'] ?? 0));
        $liquidity = (string) ($context['liquidity'] ?? $state?->liquidity_state ?? 'unknown');
        $axes = app(ContextContractV2Service::class)->canonicalAxes([
            ...$context,
            'regime' => $rawRegime,
            'session' => $rawSession,
            'volatility' => $rawVolatility,
            'transition' => $transition,
            'spread_liquidity_state' => $spread === null ? $liquidity : ($spread > .25 ? 'high' : 'normal'),
        ]);
        $regime = (string) ($axes['regime'] ?? $rawRegime);
        $session = (string) ($axes['session'] ?? $rawSession);
        $volatility = (string) ($axes['volatility'] ?? $rawVolatility);
        $direction = (string) ($axes['direction'] ?? 'both');
        $venuePhase = (string) ($axes['venue_phase'] ?? 'calendar_unresolved');
        $family = app(StrategyParameterSchemaService::class)->family((string) ($context['strategy_family'] ?? 'unscoped'));
        $family = preg_replace('/[^a-z0-9_]/', '_', strtolower($family)) ?: 'unscoped';

        $fingerprint = [
            'regime' => $regime, 'm15_regime' => (string) ($context['m15_regime'] ?? $regime), 'session' => $session,
            'volatility' => $volatility, 'spread_atr_ratio' => $spread, 'spread_state' => $spread === null ? 'unknown' : ($spread > .25 ? 'high' : 'normal'),
            'liquidity' => $liquidity, 'transition' => $transition, 'loss_streak' => $lossStreak,
            'direction' => $direction, 'strategy_family' => $family, 'venue_phase' => $venuePhase,
            'session_instance_id' => $context['session_instance_id'] ?? null,
            'calendar_version' => $context['calendar_version'] ?? null,
            'volume' => $context['volume'] ?? null, 'news_risk' => (bool) ($context['news_risk'] ?? false),
        ];
        $stateParts = [
            $regime, $session, $volatility, $fingerprint['spread_state'],
            $transition ? 'transition' : 'stable', min(9, $lossStreak), $direction, $family, $venuePhase,
        ];
        if ($venuePhase === 'calendar_unresolved') {
            array_pop($stateParts); // Exact compatibility for historical eight-axis posteriors.
        }
        $fingerprint['state_key'] = implode('|', $stateParts);

        return $fingerprint;
    }

    /** @return array<string,mixed> */
    public function route(string $symbol, string $timeframe, array $context = []): array
    {
        $this->seedDefaults();
        $requestedTimeframe = strtoupper($timeframe);
        $timeframe = $this->decisionTimeframe($symbol, $timeframe);
        $state = $this->fingerprint($symbol, $timeframe, $context);
        $playbooks = PlaybookComposition::query()->where(fn ($query) => $query->whereNull('symbol')->orWhere('symbol', $symbol))
            ->where(fn ($query) => $query->whereNull('timeframe')->orWhere('timeframe', $timeframe))->get();
        $instruments = TradingInstrument::query()->with('contract')->whereIn('instrument_key', $playbooks->flatMap(fn (PlaybookComposition $p) => $p->instrument_keys)->unique())->get()->keyBy('instrument_key');
        $routingMode = in_array((string) ($context['routing_mode'] ?? 'research'), ['research', 'paper'], true)
            ? (string) ($context['routing_mode'] ?? 'research') : 'research';
        $candidates = $playbooks->filter(fn (PlaybookComposition $playbook) => $this->eligible($playbook, $instruments, $state))
            ->map(fn (PlaybookComposition $playbook) => $this->candidate($playbook, $instruments, $symbol, $timeframe, $state))->sortByDesc('score')->values();
        $emergency = $candidates->first(fn (array $candidate) => $candidate['abstention']);
        $admissible = $candidates->filter(fn (array $candidate): bool => $routingMode === 'research' || $candidate['selection_eligible']);
        $selected = $emergency ?: $admissible->first();
        $decision = $selected && ! $selected['abstention'] ? 'TRADE' : 'ABSTAIN';
        $reason = $selected['reason_code'] ?? ($routingMode === 'paper' && $candidates->isNotEmpty()
            ? 'NO_CONFIRMED_CONTEXTUAL_PLAYBOOK' : 'NO_ELIGIBLE_PLAYBOOK');
        $decisionKey = (string) ($context['decision_key'] ?? Str::uuid());
        $row = RouterDecision::updateOrCreate(['decision_key' => $decisionKey], [
            'symbol' => $symbol, 'timeframe' => $timeframe, 'state_key' => $state['state_key'],
            'playbook_composition_id' => $selected['playbook']->id ?? null, 'decision' => $decision, 'reason_code' => $reason,
            'state_fingerprint' => $state, 'candidates' => $candidates->map(fn (array $candidate) => Arr::except($candidate, 'playbook'))->all(),
            'metadata' => [
                'protocol' => self::PROTOCOL,
                'routing_mode' => $routingMode,
                'requested_timeframe' => $requestedTimeframe,
                'decision_timeframe' => $timeframe,
                'organism_scope' => strtoupper(str_replace(['/', '_', '-'], '', $symbol)) === 'XAUUSD' ? 'xauusd_single_organism' : 'symbol_timeframe',
                'selection_rule' => 'contextual_lower_bound_with_explicit_abstention',
                'rejected' => $candidates->where('selection_eligible', false)->pluck('playbook_key')->values()->all(),
            ], 'decided_at' => now(),
        ]);

        return [
            'symbol' => strtoupper($symbol),
            'timeframe' => strtoupper($timeframe),
            'decision' => $decision,
            'reason_code' => $reason,
            'state' => $state,
            'playbook' => $selected['playbook'] ?? null,
            'candidate' => $selected,
            'candidates' => $candidates->map(fn (array $candidate): array => Arr::except($candidate, 'playbook'))->all(),
            'alternatives' => $candidates->filter(fn (array $candidate): bool => ! $selected || $candidate['playbook_key'] !== $selected['playbook_key'])
                ->take(5)->map(fn (array $candidate): array => Arr::except($candidate, 'playbook'))->values()->all(),
            'routing_mode' => $routingMode,
            'router_decision' => $row,
        ];
    }

    /** Persist paired-control outcome and update the conditional posterior. */
    public function recordEvidence(string $instrumentKey, string $symbol, string $timeframe, array $context, array $outcome): InstrumentValuePosterior
    {
        $this->assertPairedControl($outcome);
        $this->seedDefaults();
        $instrument = TradingInstrument::query()->where('instrument_key', $instrumentKey)->firstOrFail();
        $state = $this->fingerprint($symbol, $timeframe, $context);
        $metrics = (array) ($outcome['metrics'] ?? $outcome);
        $vector = $this->valueVector($metrics, $instrument->is_abstention);
        $vector = $this->withEvidenceIdentity($vector, $state, $outcome);

        return DB::transaction(function () use ($instrument, $symbol, $timeframe, $state, $outcome, $metrics, $vector): InstrumentValuePosterior {
            $evidence = InstrumentEvidence::firstOrCreate(['evidence_key' => (string) ($outcome['evidence_key'] ?? Str::uuid())], [
                'trading_instrument_id' => $instrument->id, 'symbol' => $symbol, 'timeframe' => $timeframe, 'state_key' => $state['state_key'],
                'outcome_state' => (string) ($outcome['outcome_state'] ?? 'observed'), 'source_type' => $outcome['source_type'] ?? null,
                'source_key' => $outcome['source_key'] ?? null, 'metrics' => $metrics, 'control_metrics' => $outcome['control_metrics'] ?? null,
                'metadata' => ['protocol' => self::PROTOCOL, 'state' => $state,
                    'tested_intervention' => (array) ($outcome['tested_intervention'] ?? []),
                    'source_receipt' => (array) ($outcome['source_receipt'] ?? [])], 'observed_at' => $outcome['observed_at'] ?? now(),
            ]);
            $scope = ['trading_instrument_id' => $instrument->id, 'symbol' => $symbol, 'timeframe' => $timeframe, 'state_key' => $state['state_key']];
            if (! $evidence->wasRecentlyCreated) {
                return InstrumentValuePosterior::firstOrCreate($scope, ['value_vector' => []]);
            }
            $posterior = InstrumentValuePosterior::query()->where($scope)->lockForUpdate()->first()
                ?: new InstrumentValuePosterior($scope);
            $vector['validation_epochs'] = app(InstrumentValidationEvidenceService::class)->append(
                (array) data_get($posterior->value_vector, 'validation_epochs', []), $outcome, $state, $vector,
            );
            [$n, $net, $uncertainty, $vector] = $this->updatePosterior($posterior, $vector);
            $posterior->fill(['observations' => $n, 'net_value' => $net, 'uncertainty' => $uncertainty, 'decay_state' => $this->decayState($n, $net, $vector), 'value_vector' => $vector, 'last_observed_at' => now()])->save();

            return $posterior;
        });
    }

    public function recordPlaybookEvidence(string $playbookKey, string $symbol, string $timeframe, array $context, array $outcome): PlaybookValuePosterior
    {
        $this->assertPairedControl($outcome);
        $playbook = PlaybookComposition::query()->where('playbook_key', $playbookKey)->firstOrFail();
        $state = $this->fingerprint($symbol, $timeframe, $context);
        $vector = $this->withEvidenceIdentity(
            $this->valueVector((array) ($outcome['metrics'] ?? $outcome), (bool) data_get($playbook->metadata, 'abstention', false)),
            $state,
            $outcome,
        );

        return DB::transaction(function () use ($playbook, $symbol, $timeframe, $state, $vector, $outcome): PlaybookValuePosterior {
            $scope = ['playbook_composition_id' => $playbook->id, 'symbol' => $symbol, 'timeframe' => $timeframe, 'state_key' => $state['state_key']];
            $posterior = PlaybookValuePosterior::query()->where($scope)->lockForUpdate()->first()
                ?: new PlaybookValuePosterior($scope);
            $evidenceKey = (string) ($outcome['evidence_key'] ?? '');
            $seen = array_values(array_filter(array_map('strval', (array) data_get($posterior->value_vector, 'evidence_keys', []))));
            if ($evidenceKey !== '' && in_array($evidenceKey, $seen, true)) {
                return $posterior;
            }
            if ($evidenceKey !== '') {
                $seen[] = $evidenceKey;
                $vector['evidence_keys'] = array_slice(array_values(array_unique($seen)), -256);
            }
            $vector['interaction_identified'] = (bool) ($outcome['interaction_identified'] ?? false);
            $vector['interpretation'] = $vector['interaction_identified']
                ? 'factorial_interaction_effect'
                : 'joint_bundle_value_not_component_synergy';
            $vector['validation_epochs'] = app(InstrumentValidationEvidenceService::class)->append(
                (array) data_get($posterior->value_vector, 'validation_epochs', []), $outcome, $state, $vector,
            );
            [$n, $net, $uncertainty, $vector] = $this->updatePosterior($posterior, $vector);
            $posterior->fill(['observations' => $n, 'net_value' => $net, 'uncertainty' => $uncertainty, 'decay_state' => $this->decayState($n, $net, $vector), 'value_vector' => $vector, 'last_observed_at' => now()])->save();

            return $posterior;
        });
    }

    private function eligible(PlaybookComposition $playbook, $instruments, array $state): bool
    {
        if (data_get($playbook->metadata, 'router_eligible') === false) {
            return false;
        }
        if (! (bool) data_get($playbook->metadata, 'abstention', false) && $state['spread_atr_ratio'] === null) {
            return false;
        }
        foreach ((array) $playbook->instrument_keys as $key) {
            $instrument = $instruments->get($key);
            if (! $instrument || ! $this->contractMatches($instrument, $state)) {
                return false;
            }
        }
        foreach ((array) ($playbook->preconditions ?? []) as $key => $expected) {
            if (! in_array($state[$key] ?? null, (array) $expected, true)) {
                return false;
            }
        }

        return true;
    }

    private function contractMatches(TradingInstrument $instrument, array $state): bool
    {
        $contract = $instrument->contract;
        if (! $contract) {
            return true;
        }
        foreach ((array) $contract->required_inputs as $input) {
            if (! array_key_exists($input, $state) || $state[$input] === null) {
                return false;
            }
        }
        if (in_array($state['regime'], (array) $contract->forbidden_regimes, true)) {
            return false;
        }
        $compatible = (array) $contract->compatible_regimes;

        $family = (string) ($state['strategy_family'] ?? 'unscoped');
        $allowedGenes = (array) $contract->allowed_genes;
        if ($family !== 'unscoped' && $allowedGenes !== []) {
            $familyGenes = array_keys(app(StrategyParameterSchemaService::class)->schema($family));
            if (array_intersect($allowedGenes, $familyGenes) === []) {
                return false;
            }
        }

        return ! $compatible || in_array($state['regime'], $compatible, true);
    }

    private function candidate(PlaybookComposition $playbook, $instruments, string $symbol, string $timeframe, array $state): array
    {
        $abstention = (bool) ($playbook->metadata['abstention'] ?? false);
        $bundle = $this->contextualEstimate(PlaybookValuePosterior::query()
            ->where('playbook_composition_id', $playbook->id)->where('symbol', $symbol)->where('timeframe', $timeframe)->get(), $state);
        $components = collect((array) $playbook->instrument_keys)->map(function (string $key) use ($instruments, $symbol, $timeframe, $state): array {
            $instrument = $instruments->get($key);
            $estimate = $instrument ? $this->contextualEstimate(InstrumentValuePosterior::query()
                ->where('trading_instrument_id', $instrument->id)->where('symbol', $symbol)->where('timeframe', $timeframe)->get(), $state) : null;

            return ['instrument_key' => $key, 'estimate' => $estimate];
        })->values();
        $known = $components->pluck('estimate')->filter()->values();
        $componentNet = $known->isNotEmpty() ? (float) $known->avg('net_value') : 0.0;
        $componentUncertainty = $known->isNotEmpty() ? (float) $known->avg('uncertainty') : 1.0;
        $missingComponents = max(0, count((array) $playbook->instrument_keys) - $known->count());
        // The exact bundle replay is the direct observation of executable
        // joint behaviour. Component posteriors refine it and can veto harm,
        // but an unablated support component is uncertainty—not a fabricated
        // zero-value measurement that overwhelms the actual bundle evidence.
        $net = $bundle
            ? ($known->isNotEmpty() ? (.8 * $bundle['net_value']) + (.2 * $componentNet) : $bundle['net_value'])
            : $componentNet;
        $uncertainty = $bundle
            ? (float) $bundle['uncertainty'] + (.005 * $missingComponents)
            : $componentUncertainty;
        $forbidden = $components->filter(fn (array $row): bool => (string) data_get($row, 'estimate.decay_state') === 'forbidden')->pluck('instrument_key')->values()->all();
        $lowerBound = $net - $uncertainty - (.25 * count($forbidden));
        $score = $bundle || $known->isNotEmpty() ? $lowerBound : ($abstention ? .1 : .05);
        if ($abstention) {
            $score += 10;
        } // safety instruments always dominate when their precondition matches

        $selectionEligible = $abstention || ($bundle !== null
            && $bundle['scope'] === 'exact'
            && $bundle['decay_state'] === 'confirmed'
            && $lowerBound > 0
            && $forbidden === []);
        $rejections = [];
        if (! $abstention && $bundle === null) {
            $rejections[] = 'EXACT_BUNDLE_EVIDENCE_MISSING';
        }
        if (! $abstention && $bundle !== null && $bundle['scope'] !== 'exact') {
            $rejections[] = 'HIERARCHICAL_PRIOR_NOT_EXECUTION_AUTHORITY';
        }
        if (! $abstention && $bundle !== null && $bundle['decay_state'] !== 'confirmed') {
            $rejections[] = 'BUNDLE_NOT_CONFIRMED';
        }
        if (! $abstention && $lowerBound <= 0) {
            $rejections[] = 'CONSERVATIVE_LOWER_BOUND_NOT_POSITIVE';
        }
        if ($forbidden !== []) {
            $rejections[] = 'FORBIDDEN_COMPONENT_CONFLICT';
        }

        return [
            'playbook' => $playbook, 'playbook_key' => $playbook->playbook_key,
            'instrument_keys' => $playbook->instrument_keys, 'score' => round($score, 6),
            'lower_bound' => round($lowerBound, 6), 'observations' => (int) ($bundle['observations'] ?? $known->sum('observations')),
            'abstention' => $abstention, 'selection_eligible' => $selectionEligible,
            'reason_code' => $abstention ? ($playbook->metadata['reason_code'] ?? 'RISK_ABSTENTION') : ($selectionEligible ? 'CONFIRMED_CONTEXTUAL_LOWER_BOUND' : 'RESEARCH_ONLY_CONTEXTUAL_HYPOTHESIS'),
            'bundle_posterior' => $bundle,
            'component_posteriors' => $components->all(),
            'forbidden_components' => $forbidden,
            'rejected_reasons' => array_values(array_unique($rejections)),
            'interaction_authority' => $bundle !== null && (bool) ($bundle['interaction_identified'] ?? false),
        ];
    }

    private function valueVector(array $metrics, bool $abstention): array
    {
        $edge = (float) ($metrics['net_edge'] ?? $metrics['cost_adjusted_return'] ?? 0);
        $cost = max(0, (float) ($metrics['cost_penalty'] ?? 0));
        $drawdown = max(0, (float) ($metrics['drawdown_penalty'] ?? $metrics['drawdown_percent'] ?? 0) / 100);
        $uncertainty = max(0, (float) ($metrics['uncertainty'] ?? 0));
        $survival = (float) ($metrics['survival_value'] ?? $metrics['temporal_survival'] ?? 0);
        $coverage = (float) ($metrics['regime_coverage_value'] ?? 0);
        $abstentionValue = $abstention ? (float) ($metrics['abstention_quality'] ?? 0) : 0;
        $lift = (float) ($metrics['incremental_lift'] ?? 0);

        return ['net_edge_after_cost' => $edge, 'profit_factor' => (float) ($metrics['profit_factor'] ?? 0), 'drawdown_penalty' => $drawdown, 'adverse_excursion' => (float) ($metrics['adverse_excursion'] ?? 0), 'trade_frequency' => (float) ($metrics['trade_frequency'] ?? 0), 'regime_coverage_value' => $coverage, 'survival_value' => $survival, 'temporal_decay' => (float) ($metrics['temporal_decay'] ?? 0), 'spread_sensitivity' => (float) ($metrics['spread_sensitivity'] ?? 0), 'non_target_regression' => (float) ($metrics['non_target_regression'] ?? 0), 'abstention_value' => $abstentionValue, 'uncertainty' => $uncertainty, 'incremental_lift' => $lift, 'conditional_net_utility' => $edge - $cost - $drawdown + $survival + $coverage + $abstentionValue + $lift];
    }

    /** @return array<string,mixed> */
    private function withEvidenceIdentity(array $vector, array $state, array $outcome): array
    {
        $evidenceKey = (string) ($outcome['evidence_key'] ?? '');
        $receipt = (array) ($outcome['instrument_research_window_receipt'] ?? []);
        $dataHash = (string) data_get($outcome, 'control_contract.data_hash', '');
        $authorized = $evidenceKey !== '' && app(InstrumentResearchWindowService::class)
            ->authorized($receipt, $dataHash);
        $window = $authorized ? (string) $receipt['window_key'] : '';
        $vector['context'] = Arr::only($state, [
            'regime', 'session', 'volatility', 'spread_state', 'transition',
            'loss_streak', 'direction', 'strategy_family', 'venue_phase', 'state_key',
        ]);
        $vector['strategy_family'] = (string) ($state['strategy_family'] ?? 'unscoped');
        $vector['independent_window_keys'] = $window !== '' ? [$window] : [];
        $utility = (float) $vector['conditional_net_utility'];
        $vector['window_evidence'] = $authorized ? [[
            'window' => $receipt,
            'evidence_key' => $evidenceKey,
            'outcome' => $utility > 0 ? 'positive' : ($utility < 0 ? 'negative' : 'neutral'),
        ]] : [];
        $vector['evidence_keys'] = $evidenceKey !== '' ? [$evidenceKey] : [];
        $vector['positive_observations'] = $vector['conditional_net_utility'] > 0 ? 1 : 0;
        $vector['negative_observations'] = $vector['conditional_net_utility'] < 0 ? 1 : 0;
        $vector['non_target_regression_count'] = (float) ($vector['non_target_regression'] ?? 0) > 0 ? 1 : 0;

        return $vector;
    }

    /** @return array{int,float,float,array<string,mixed>} */
    private function updatePosterior($posterior, array $observation): array
    {
        $previous = (array) ($posterior->value_vector ?? []);
        $oldN = (int) ($posterior->observations ?? 0);
        $n = $oldN + 1;
        $oldMean = (float) ($posterior->net_value ?? 0);
        $value = (float) $observation['conditional_net_utility'];
        $delta = $value - $oldMean;
        $net = $oldMean + ($delta / $n);
        $m2 = (float) ($previous['utility_m2'] ?? 0) + ($delta * ($value - $net));
        $sampleError = $n > 1
            ? sqrt(max(0, $m2 / ($n - 1)) / $n)
            : max(.01, abs($value));
        // Epistemic uncertainty belongs in the selector's lower bound, not
        // inside the observed economic mean. Only a dimensionally compatible
        // utility uncertainty may widen this interval.
        $uncertainty = max(.002, $sampleError, (float) ($observation['utility_uncertainty'] ?? 0));
        $windows = array_values(array_unique([
            ...array_map('strval', (array) ($previous['independent_window_keys'] ?? [])),
            ...array_map('strval', (array) ($observation['independent_window_keys'] ?? [])),
        ]));
        $windowEvidence = [
            ...(array) ($previous['window_evidence'] ?? []),
            ...(array) ($observation['window_evidence'] ?? []),
        ];
        $evidenceKeys = array_values(array_unique([
            ...array_map('strval', (array) ($previous['evidence_keys'] ?? [])),
            ...array_map('strval', (array) ($observation['evidence_keys'] ?? [])),
        ]));
        $vector = [
            ...$previous,
            ...$observation,
            'utility_m2' => $m2,
            'independent_window_keys' => array_values(array_filter($windows)),
            'window_evidence' => $windowEvidence,
            'evidence_keys' => array_values(array_filter($evidenceKeys)),
            'positive_observations' => (int) ($previous['positive_observations'] ?? 0) + (int) ($observation['positive_observations'] ?? 0),
            'negative_observations' => (int) ($previous['negative_observations'] ?? 0) + (int) ($observation['negative_observations'] ?? 0),
            'non_target_regression_count' => (int) ($previous['non_target_regression_count'] ?? 0) + (int) ($observation['non_target_regression_count'] ?? 0),
        ];

        return [$n, $net, $uncertainty, $vector];
    }

    /**
     * Select the narrowest powered context. Parent contexts are deliberately
     * shrunk toward zero and remain priors; only an exact confirmed row can
     * authorize paper selection.
     *
     * @return array<string,mixed>|null
     */
    private function contextualEstimate($rows, array $state): ?array
    {
        $desired = $this->decodeStateKey((string) $state['state_key']);
        $familyRows = collect($rows)->filter(function ($row) use ($desired): bool {
            $observed = $this->decodeStateKey((string) $row->state_key);

            return $observed['strategy_family'] === $desired['strategy_family'];
        })->values();
        $exact = $familyRows->first(fn ($row): bool => (string) $row->state_key === (string) $state['state_key']);
        if ($exact) {
            $authority = app(InstrumentPosteriorAuthorityService::class)->assess($exact);

            return [
                'scope' => 'exact', 'backoff_used' => false,
                'state_key' => (string) $exact->state_key,
                'observations' => (int) ($authority['observations'] ?: $exact->observations),
                'net_value' => (float) ($authority['net_value'] ?? $exact->net_value),
                'uncertainty' => (float) ($authority['uncertainty'] ?? $exact->uncertainty),
                'decay_state' => (string) $authority['canonical_state'],
                'authority' => $authority,
                'interaction_identified' => (bool) data_get($exact->value_vector, 'interaction_identified', false),
            ];
        }

        $scopes = [
            'regime_volatility_session_phase_direction' => ['regime', 'volatility', 'session', 'venue_phase', 'direction'],
            'regime_volatility_session_direction' => ['regime', 'volatility', 'session', 'direction'],
            'regime_volatility_direction' => ['regime', 'volatility', 'direction'],
            'regime_direction' => ['regime', 'direction'],
            'regime' => ['regime'],
        ];
        foreach ($scopes as $scope => $axes) {
            $matched = $familyRows->filter(function ($row) use ($desired, $axes): bool {
                $observed = $this->decodeStateKey((string) $row->state_key);
                foreach ($axes as $axis) {
                    if ($observed[$axis] !== $desired[$axis]) {
                        return false;
                    }
                }

                return true;
            });
            $observations = (int) $matched->sum('observations');
            if ($observations < 3) {
                continue;
            }
            $weightedNet = (float) ($matched->sum(fn ($row): float => (float) $row->net_value * (int) $row->observations) / $observations);
            $weightedUncertainty = (float) ($matched->sum(fn ($row): float => (float) $row->uncertainty * (int) $row->observations) / $observations);
            $shrinkage = $observations / ($observations + 5);

            return [
                'scope' => $scope, 'backoff_used' => true, 'state_key' => null,
                'observations' => $observations,
                'net_value' => $weightedNet * $shrinkage,
                'uncertainty' => max(.15, $weightedUncertainty + (1 - $shrinkage) * .25),
                'decay_state' => 'hierarchical_prior',
                'interaction_identified' => false,
            ];
        }

        return null;
    }

    /** @return array<string,string> */
    private function decodeStateKey(string $stateKey): array
    {
        [$regime, $session, $volatility, $spread, $transition, $lossStreak, $direction, $family, $venuePhase] = array_pad(explode('|', $stateKey), 9, null);
        $axes = app(ContextContractV2Service::class)->canonicalAxes([
            'regime' => $regime, 'session' => $session, 'volatility' => $volatility,
            'spread_liquidity_state' => $spread, 'transition_state' => $transition,
            'direction' => $direction, 'venue_phase' => $venuePhase,
        ]);

        return [
            'regime' => (string) ($axes['regime'] ?? $regime ?? 'unknown'),
            'session' => (string) ($axes['session'] ?? $session ?? 'unknown'),
            'volatility' => (string) ($axes['volatility'] ?? $volatility ?? 'unknown'),
            'spread_state' => (string) ($axes['spread_liquidity_state'] ?? $spread ?? 'unknown'),
            'transition' => (string) ($axes['transition_state'] ?? $transition ?? 'stable'),
            'loss_streak' => (string) ($lossStreak ?? '0'),
            'direction' => (string) ($axes['direction'] ?? 'both'),
            'strategy_family' => (string) ($family ?: 'unscoped'),
            'venue_phase' => (string) ($axes['venue_phase'] ?? $venuePhase ?? 'calendar_unresolved'),
        ];
    }

    private function assertPairedControl(array $outcome): void
    {
        $control = (array) ($outcome['control_metrics'] ?? []);
        if ($control === [] || ! (bool) data_get($outcome, 'control_contract.paired_isolated', false)) {
            throw new InvalidArgumentException('Instrument evidence faqat paired-isolated control metrics bilan yoziladi.');
        }
    }

    private function decayState(int $observations, float $net, array $vector): string
    {
        if ((float) $vector['temporal_decay'] >= .5) return 'decaying';
        $states = [];
        foreach ((array) ($vector['validation_epochs'] ?? []) as $epoch) {
            if (! is_array($epoch)) continue;
            $assessment = app(InstrumentValidationEvidenceService::class)->assessEpoch($epoch, (string) data_get($vector, 'context.state_key', ''));
            if (in_array($assessment['canonical_state'], ['confirmed', 'forbidden'], true)) $states[] = $assessment['canonical_state'];
        }

        return $states !== [] ? $states[count($states) - 1] : 'provisional';
    }

    private function volatilityFor(?MarketStateSnapshot $state): string
    {
        if (! $state) {
            return 'unknown';
        }
        if (max((float) $state->expansion_score, (float) $state->panic_score) >= 70) {
            return 'high';
        }

        return (float) $state->compression_score >= 70 ? 'low' : 'normal';
    }

    private function sessionFor(int $hour): string
    {
        return $hour >= 12 && $hour <= 16 ? 'overlap' : ($hour >= 7 && $hour < 12 ? 'london' : ($hour > 16 && $hour <= 21 ? 'new_york' : 'asia'));
    }

    private function decisionTimeframe(string $symbol, string $timeframe): string
    {
        $normalizedSymbol = strtoupper(str_replace(['/', '_', '-'], '', $symbol));
        $normalizedTimeframe = strtoupper($timeframe);
        if ($normalizedSymbol === 'XAUUSD' && in_array($normalizedTimeframe, ['H4', 'H1', 'M15', 'M5'], true)) {
            return 'M15';
        }

        return $normalizedTimeframe;
    }

    private function instrumentDefinitions(): array
    {
        $execution = ['compatible_regimes' => [], 'forbidden_regimes' => [], 'required_inputs' => [], 'allowed_genes' => [], 'cost_model' => ['spread_aware' => true], 'risk_model' => [], 'control_contract' => ['mode' => 'paired_isolated'], 'contract' => ['protocol' => self::PROTOCOL]];

        return [
            'trend_pullback' => ['label' => 'Trend Pullback', 'role' => 'tactic', 'tactic_id' => 'trend_following_pullback', 'promotion_state' => 'provisional', 'is_abstention' => false, 'definition' => ['hypothesis' => 'Closed H1 context with an M15 trend/pullback decision.', 'origin' => 'curated_registry'], 'contract' => [...$execution, 'compatible_regimes' => ['trend_up', 'trend_down'], 'forbidden_regimes' => ['transition'], 'required_inputs' => ['regime', 'session', 'volatility', 'spread_atr_ratio'], 'allowed_genes' => ['ema_fast', 'ema_slow', 'trend_strength_min', 'pullback_atr_fraction', 'trend_roc_period', 'trend_roc_threshold', 'trend_ema_period', 'trend_up_strength_min', 'trend_down_strength_min', 'trend_up_pullback_atr_fraction', 'trend_down_pullback_atr_fraction', 'trend_up_roc_period', 'trend_down_roc_period', 'trend_up_roc_threshold', 'trend_down_roc_threshold', 'trend_up_ema_period', 'trend_down_ema_period']]],
            'breakout_retest' => ['label' => 'Breakout Retest', 'role' => 'tactic', 'tactic_id' => 'donchian_atr_breakout_retest', 'promotion_state' => 'provisional', 'is_abstention' => false, 'definition' => ['hypothesis' => 'Range break, measured retest and cost gate.', 'origin' => 'curated_registry'], 'contract' => [...$execution, 'compatible_regimes' => ['trend_up', 'trend_down', 'high_volatility'], 'forbidden_regimes' => ['transition'], 'required_inputs' => ['regime', 'spread_atr_ratio'], 'allowed_genes' => ['lookback', 'atr_period', 'atr_multiplier', 'confirmation_candles', 'trend_strength_min', 'retest_required', 'breakout_atr_period', 'breakout_atr_threshold', 'breakout_lookback', 'breakout_compression_ratio', 'breakout_expansion_multiplier']]],
            'compression_expansion' => ['label' => 'Compression Expansion', 'role' => 'tactic', 'tactic_id' => 'atr_squeeze_expansion', 'promotion_state' => 'provisional', 'is_abstention' => false, 'definition' => ['hypothesis' => 'Low-volatility compression followed by measured expansion.', 'origin' => 'curated_registry'], 'contract' => [...$execution, 'compatible_regimes' => ['trend_up', 'trend_down'], 'forbidden_regimes' => ['transition'], 'required_inputs' => ['volatility'], 'allowed_genes' => ['lookback', 'atr_period', 'compression_ratio', 'expansion_multiplier', 'breakout_compression_ratio', 'breakout_expansion_multiplier', 'breakout_atr_period', 'breakout_atr_threshold']]],
            'session_breakout' => ['label' => 'London/NY Session Breakout', 'role' => 'tactic', 'tactic_id' => 'session_range_breakout', 'promotion_state' => 'provisional', 'is_abstention' => false, 'definition' => ['hypothesis' => 'Liquid-session range break.', 'origin' => 'curated_registry'], 'contract' => [...$execution, 'compatible_regimes' => ['trend_up', 'trend_down'], 'forbidden_regimes' => ['transition'], 'required_inputs' => ['session', 'spread_atr_ratio'], 'allowed_genes' => ['session_start', 'session_end', 'lookback', 'session_filter_enabled', 'differential_target_session_filter_enabled', 'differential_target_session_start', 'differential_target_session_end']]],
            'range_reentry' => ['label' => 'Range Re-entry', 'role' => 'tactic', 'tactic_id' => 'bollinger_zscore_reentry', 'promotion_state' => 'provisional', 'is_abstention' => false, 'definition' => ['hypothesis' => 'Band/z-score re-entry in a low ADX range.', 'origin' => 'curated_registry'], 'contract' => [...$execution, 'compatible_regimes' => ['range', 'low_volatility'], 'forbidden_regimes' => ['transition', 'high_volatility'], 'required_inputs' => ['regime', 'volatility'], 'allowed_genes' => ['lookback', 'deviation', 'adx_max', 'range_lookback', 'range_deviation', 'range_adx_max', 'range_low_volatility_only', 'range_reentry_required', 'range_signal_mode']]],
            'dynamic_fibonacci_zone' => ['label' => 'Dynamic Fibonacci Zone', 'role' => 'market_lens', 'tactic_id' => 'fibonacci_structure_pullback', 'promotion_state' => 'provisional', 'is_abstention' => false, 'definition' => ['values' => ['zone_width', 'distance_atr', 'swing_range']], 'contract' => [...$execution, 'compatible_regimes' => ['trend_up', 'trend_down'], 'forbidden_regimes' => ['transition'], 'required_inputs' => ['regime'], 'allowed_genes' => ['swing_lookback', 'equal_level_atr_fraction']]],
            'confirmed_swing' => ['label' => 'Confirmed Swing', 'role' => 'market_lens', 'tactic_id' => null, 'promotion_state' => 'provisional', 'is_abstention' => false, 'definition' => ['values' => ['swing_high', 'swing_low', 'distance_atr']], 'contract' => $execution],
            'bos_event' => ['label' => 'Break of Structure', 'role' => 'market_lens', 'tactic_id' => 'bos_retest_continuation', 'promotion_state' => 'provisional', 'is_abstention' => false, 'definition' => ['values' => ['break_displacement', 'retest_quality', 'false_break_probability']], 'contract' => [...$execution, 'compatible_regimes' => ['trend_up', 'trend_down'], 'forbidden_regimes' => ['transition'], 'required_inputs' => ['regime'], 'allowed_genes' => ['swing_lookback', 'minimum_displacement_atr', 'retest_atr_fraction']]],
            'choch_event' => ['label' => 'Change of Character', 'role' => 'market_lens', 'tactic_id' => 'choch_reversal', 'promotion_state' => 'provisional', 'is_abstention' => false, 'definition' => ['values' => ['transition_confidence', 'break_displacement']], 'contract' => [...$execution, 'compatible_regimes' => ['transition'], 'required_inputs' => ['regime'], 'allowed_genes' => ['swing_lookback', 'transition_confidence_min']]],
            'support_resistance_zone' => ['label' => 'Support/Resistance Zone', 'role' => 'market_lens', 'tactic_id' => null, 'promotion_state' => 'provisional', 'is_abstention' => false, 'definition' => ['values' => ['strength', 'zone_width', 'touch_count', 'zone_age']], 'contract' => [...$execution, 'compatible_regimes' => ['range'], 'required_inputs' => ['regime']]],
            'liquidity_pool' => ['label' => 'Liquidity Pool Proxy', 'role' => 'market_lens', 'tactic_id' => null, 'promotion_state' => 'provisional', 'is_abstention' => false, 'definition' => ['values' => ['equal_high_low', 'clustered_swing', 'liquidity_score']], 'contract' => $execution],
            'liquidity_sweep' => ['label' => 'Liquidity Sweep Proxy', 'role' => 'market_lens', 'tactic_id' => 'liquidity_sweep_reversion', 'promotion_state' => 'provisional', 'is_abstention' => false, 'definition' => ['values' => ['liquidity_score', 'failure_probability']], 'contract' => [...$execution, 'compatible_regimes' => ['range', 'trend_up', 'trend_down', 'transition'], 'required_inputs' => ['regime'], 'allowed_genes' => ['swing_lookback', 'equal_level_atr_fraction', 'zone_strength_min']]],
            'session_range' => ['label' => 'Session Range', 'role' => 'market_lens', 'tactic_id' => null, 'promotion_state' => 'provisional', 'is_abstention' => false, 'definition' => ['values' => ['range_width', 'break_displacement', 'session']], 'contract' => [...$execution, 'compatible_regimes' => [], 'required_inputs' => ['session'], 'allowed_genes' => ['session_filter_enabled', 'session_start', 'session_end', 'differential_target_session_filter_enabled', 'differential_target_session_start', 'differential_target_session_end']]],
            'volume_confirmation' => ['label' => 'Volume Confirmation', 'role' => 'market_lens', 'tactic_id' => null, 'promotion_state' => 'provisional', 'is_abstention' => false, 'definition' => ['values' => ['relative_volume', 'confirmation']], 'contract' => [...$execution, 'allowed_genes' => ['volume_lane']]],
            'atr_risk_envelope' => ['label' => 'ATR Risk Envelope', 'role' => 'execution', 'tactic_id' => null, 'promotion_state' => 'provisional', 'is_abstention' => false, 'definition' => ['stop' => 'ATR', 'target' => 'ATR', 'partial_take_profit' => true], 'contract' => [...$execution, 'allowed_genes' => ['atr_stop_multiplier', 'atr_target_multiplier', 'high_volatility_risk_multiplier', 'trend_up_risk_multiplier', 'trend_down_risk_multiplier', 'recovery_probe_risk_multiplier']]],
            'cost_firewall' => ['label' => 'Cost Firewall', 'role' => 'risk', 'tactic_id' => null, 'promotion_state' => 'confirmed', 'is_abstention' => true, 'definition' => ['action' => 'wait_when_spread_abnormal'], 'contract' => [...$execution, 'compatible_regimes' => [], 'required_inputs' => ['spread_atr_ratio'], 'allowed_genes' => ['max_spread_atr_ratio', 'temporal_spread_atr_ratio_max']]],
            'high_volatility_firewall' => ['label' => 'High-Volatility Firewall', 'role' => 'risk', 'tactic_id' => null, 'promotion_state' => 'confirmed', 'is_abstention' => true, 'definition' => ['action' => 'wait_or_reduce_risk'], 'contract' => [...$execution, 'compatible_regimes' => [], 'required_inputs' => ['volatility'], 'allowed_genes' => ['high_volatility_wait', 'high_volatility_risk_multiplier', 'avoid_high_volatility', 'temporal_volatility_ratio_max']]],
            'transition_protection' => ['label' => 'Transition Protection', 'role' => 'risk', 'tactic_id' => null, 'promotion_state' => 'confirmed', 'is_abstention' => true, 'definition' => ['action' => 'wait'], 'contract' => [...$execution, 'compatible_regimes' => ['transition'], 'required_inputs' => ['regime'], 'allowed_genes' => ['transition_firewall_enabled', 'transition_wait_candles']]],
            'loss_streak_cooldown' => ['label' => 'Loss-Streak Cooldown', 'role' => 'risk', 'tactic_id' => null, 'promotion_state' => 'confirmed', 'is_abstention' => true, 'definition' => ['action' => 'wait_after_loss_streak'], 'contract' => [...$execution, 'compatible_regimes' => [], 'required_inputs' => ['loss_streak'], 'allowed_genes' => ['max_loss_streak_before_wait', 'loss_cooldown_candles', 'loss_streak_wait_candles']]],
            'cost_aware_exit' => ['label' => 'Cost-Aware Exit', 'role' => 'execution', 'tactic_id' => null, 'promotion_state' => 'provisional', 'is_abstention' => false, 'definition' => ['target_stop_time_stop' => 'spread_and_volatility_aware'], 'contract' => [...$execution, 'allowed_genes' => ['atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles', 'partial_take_profit_fraction', 'partial_target_atr_multiplier']]],
            // These primitives already execute in the laboratory runtime but
            // were previously absent from the instrument inventory. Register
            // them in Block 1 as system-discovered candidates; no historical
            // outcome is upgraded and every promotion state starts provisional.
            'regime_router' => ['label' => 'Regime Specialist Router', 'role' => 'model', 'tactic_id' => 'regime_adaptive_router', 'promotion_state' => 'provisional', 'is_abstention' => false, 'definition' => ['origin' => 'runtime_discovery', 'hypothesis' => 'Route closed-state observations to an owned specialist lane.'], 'contract' => [...$execution, 'allowed_genes' => ['trend_weight', 'breakout_weight', 'mean_reversion_weight', 'minimum_confidence', 'differential_target_regime', 'differential_target_min_signal_confidence', 'differential_router_version', 'regime_classifier_variant', 'architecture_interaction_variant']]],
            'adaptive_entry_topology' => ['label' => 'Adaptive Entry Topology', 'role' => 'tactic', 'tactic_id' => 'adaptive_entry_topology', 'promotion_state' => 'provisional', 'is_abstention' => false, 'definition' => ['origin' => 'runtime_discovery', 'hypothesis' => 'Select one declared executable entry topology without changing the risk contract.'], 'contract' => [...$execution, 'allowed_genes' => ['entry_topology_variant']]],
            'confidence_firewall' => ['label' => 'Confidence/EV Firewall', 'role' => 'risk', 'tactic_id' => null, 'promotion_state' => 'provisional', 'is_abstention' => true, 'definition' => ['origin' => 'runtime_discovery', 'action' => 'wait_when_confidence_or_lower_bound_is_insufficient'], 'contract' => [...$execution, 'allowed_genes' => ['minimum_confidence', 'minimum_signal_confidence', 'differential_target_min_signal_confidence', 'confidence_calibration_enabled', 'confidence_calibration_min_samples', 'confidence_ev_lower_bound_enabled']]],
            'temporal_survival_filter' => ['label' => 'Temporal Survival Filter', 'role' => 'risk', 'tactic_id' => null, 'promotion_state' => 'provisional', 'is_abstention' => true, 'definition' => ['origin' => 'runtime_discovery', 'action' => 'expire_or_abstain_when_signal_survival_breaks'], 'contract' => [...$execution, 'allowed_genes' => ['state_machine_variant', 'temporal_survival_enabled', 'adaptive_signal_expiry_enabled', 'drift_abstention_enabled', 'signal_max_age_candles', 'signal_decay_half_life_candles', 'temporal_followthrough_window', 'temporal_followthrough_min_rate', 'temporal_followthrough_atr_fraction', 'temporal_volatility_ratio_max', 'temporal_spread_atr_ratio_max', 'temporal_drift_zscore_max', 'temporal_confidence_decay_floor', 'temporal_loss_streak_limit', 'temporal_min_history', 'temporal_drift_lookback_candles']]],
            'dynamic_cooldown' => ['label' => 'Dynamic Recovery Cooldown', 'role' => 'risk', 'tactic_id' => null, 'promotion_state' => 'provisional', 'is_abstention' => true, 'definition' => ['origin' => 'runtime_discovery', 'action' => 'bounded_wait_after_adverse_sequence'], 'contract' => [...$execution, 'allowed_genes' => ['dynamic_cooldown_enabled', 'cooldown_shadow_min_samples', 'cooldown_shadow_edge_pf', 'max_loss_streak_before_wait', 'loss_cooldown_candles', 'loss_streak_wait_candles', 'recovery_probe_risk_multiplier']]],
            'meta_label_filter' => ['label' => 'Meta-label Admission Filter', 'role' => 'model', 'tactic_id' => null, 'promotion_state' => 'provisional', 'is_abstention' => true, 'definition' => ['origin' => 'runtime_discovery', 'action' => 'conditional_entry_admission_from_past_context_only'], 'contract' => [...$execution, 'allowed_genes' => ['meta_label_enabled', 'meta_label_min_history', 'meta_label_min_pf', 'meta_label_risk_multiplier']]],
        ];
    }

    /**
     * Every persisted instrument exposes one complete, inspectable tool card.
     * The old definitions held only their local indicator fields, which made
     * a selected playbook impossible to audit as a decision instrument.
     *
     * @param  array<string,mixed>  $definition
     * @return array<string,mixed>
     */
    private function toolCard(string $key, array $definition): array
    {
        $contract = (array) ($definition['contract'] ?? []);
        $raw = (array) ($definition['definition'] ?? []);

        return [
            'instrument_id' => $key,
            'label' => (string) ($definition['label'] ?? $key),
            'role' => (string) ($definition['role'] ?? 'tactic'),
            'preconditions' => (array) ($contract['required_inputs'] ?? []),
            'outputs' => (array) ($raw['values'] ?? array_keys($raw)),
            'usable_by' => ['market_state_estimator', 'strategy_proposer', 'tactic_executor', 'execution_risk_sentinel'],
            'failure_conditions' => [
                'forbidden_regimes' => (array) ($contract['forbidden_regimes'] ?? []),
                'missing_inputs' => (array) ($contract['required_inputs'] ?? []),
                'paired_control_required' => data_get($contract, 'control_contract.mode') === 'paired_isolated',
            ],
            'mutation_surface' => (array) ($contract['allowed_genes'] ?? []),
            'runtime_capability' => $this->runtimeCapability($key),
            'usage_contract' => [
                'selection_is_not_invocation' => true,
                'activate_only_when' => 'contract context matches and the runtime decision path emits an instrument-specific event',
                'outside_context_action' => 'ABSTAIN',
                'no_activation_disposition' => 'NOT_INVOKED_NO_CREDIT',
                'causal_credit_requires' => 'pre-registered single intervention plus exact paired control and local activation evidence',
            ],
            'learning_question' => 'Does '.$key.' improve conditional net edge versus its sealed paired control?',
            'expected_edge' => (string) ($raw['hypothesis'] ?? 'Conditional decision quality improvement.'),
            'promotion_evidence' => false,
            ...$raw,
        ];
    }

    private function playbookDefinitions(): array
    {
        $trade = ['symbol' => 'XAUUSD', 'timeframe' => 'M15', 'promotion_state' => 'provisional', 'metadata' => ['protocol' => self::PROTOCOL, 'abstention' => false]];

        return [
            [...$trade, 'playbook_key' => 'xauusd_trend_pullback_v1', 'label' => 'XAUUSD Trend Pullback', 'instrument_keys' => ['trend_pullback', 'atr_risk_envelope', 'cost_aware_exit'], 'preconditions' => ['spread_state' => ['normal']]],
            [...$trade, 'playbook_key' => 'xauusd_breakout_retest_v1', 'label' => 'XAUUSD Breakout Retest', 'instrument_keys' => ['breakout_retest', 'atr_risk_envelope', 'cost_aware_exit'], 'preconditions' => ['spread_state' => ['normal']]],
            [...$trade, 'playbook_key' => 'xauusd_compression_expansion_v1', 'label' => 'XAUUSD Compression Expansion', 'instrument_keys' => ['compression_expansion', 'atr_risk_envelope'], 'preconditions' => ['volatility' => ['normal']]],
            [...$trade, 'playbook_key' => 'xauusd_london_ny_breakout_v1', 'label' => 'XAUUSD London/NY Breakout', 'instrument_keys' => ['session_breakout', 'atr_risk_envelope', 'cost_aware_exit'], 'preconditions' => ['session' => ['london', 'overlap'], 'spread_state' => ['normal']]],
            [...$trade, 'playbook_key' => 'xauusd_range_reentry_v1', 'label' => 'XAUUSD Range Re-entry', 'instrument_keys' => ['range_reentry', 'atr_risk_envelope', 'cost_aware_exit'], 'preconditions' => ['volatility' => ['low', 'normal']]],
            [...$trade, 'playbook_key' => 'xauusd_fibonacci_structure_pullback_v1', 'label' => 'XAUUSD Fibonacci Structure Pullback', 'instrument_keys' => ['dynamic_fibonacci_zone', 'confirmed_swing', 'liquidity_sweep', 'atr_risk_envelope'], 'preconditions' => ['spread_state' => ['normal']]],
            [...$trade, 'playbook_key' => 'xauusd_bos_retest_continuation_v1', 'label' => 'XAUUSD BOS Retest Continuation', 'instrument_keys' => ['bos_event', 'confirmed_swing', 'volume_confirmation', 'atr_risk_envelope'], 'preconditions' => ['spread_state' => ['normal']]],
            [...$trade, 'playbook_key' => 'xauusd_choch_reversal_v1', 'label' => 'XAUUSD CHOCH Reversal', 'instrument_keys' => ['choch_event', 'liquidity_sweep', 'atr_risk_envelope'], 'preconditions' => ['spread_state' => ['normal']]],
            [...$trade, 'playbook_key' => 'xauusd_liquidity_sweep_reversion_v1', 'label' => 'XAUUSD Liquidity Sweep Reversion', 'instrument_keys' => ['support_resistance_zone', 'liquidity_pool', 'liquidity_sweep', 'cost_aware_exit'], 'preconditions' => ['spread_state' => ['normal']]],
            ['playbook_key' => 'xauusd_high_volatility_wait_v1', 'label' => 'XAUUSD High-Volatility Wait', 'symbol' => 'XAUUSD', 'timeframe' => 'M15', 'promotion_state' => 'confirmed', 'instrument_keys' => ['high_volatility_firewall'], 'preconditions' => ['volatility' => ['high']], 'metadata' => ['protocol' => self::PROTOCOL, 'abstention' => true, 'reason_code' => 'HIGH_VOLATILITY_FIREWALL']],
            ['playbook_key' => 'xauusd_transition_wait_v1', 'label' => 'XAUUSD Transition Wait', 'symbol' => 'XAUUSD', 'timeframe' => 'M15', 'promotion_state' => 'confirmed', 'instrument_keys' => ['transition_protection'], 'preconditions' => ['regime' => ['transition']], 'metadata' => ['protocol' => self::PROTOCOL, 'abstention' => true, 'reason_code' => 'TRANSITION_FIREWALL']],
            ['playbook_key' => 'xauusd_cost_wait_v1', 'label' => 'XAUUSD Cost Wait', 'symbol' => 'XAUUSD', 'timeframe' => 'M15', 'promotion_state' => 'confirmed', 'instrument_keys' => ['cost_firewall'], 'preconditions' => ['spread_state' => ['high']], 'metadata' => ['protocol' => self::PROTOCOL, 'abstention' => true, 'reason_code' => 'COST_FIREWALL']],
            ['playbook_key' => 'xauusd_loss_streak_wait_v1', 'label' => 'XAUUSD Loss-Streak Wait', 'symbol' => 'XAUUSD', 'timeframe' => 'M15', 'promotion_state' => 'confirmed', 'instrument_keys' => ['loss_streak_cooldown'], 'preconditions' => ['loss_streak' => [4, 5, 6, 7, 8, 9]], 'metadata' => ['protocol' => self::PROTOCOL, 'abstention' => true, 'reason_code' => 'LOSS_STREAK_COOLDOWN']],
        ];
    }
}
