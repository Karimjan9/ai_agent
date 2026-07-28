<?php

namespace App\Services;

use App\Models\AiLaboratory;
use App\Models\Candle;
use App\Models\LabGeneration;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Models\MutationMemory;
use App\Models\AgentDiagnosis;
use App\Models\CandidateGateDecision;
use App\Models\Symbol;
use App\Services\MarketData\MarketDataContinuityService;
use App\Services\MarketData\HistoricalDataQualityService;
use Illuminate\Support\Facades\DB;

class LabPopulationService
{
    private const LABS = [
        'XAUUSD' => ['name' => 'XAUUSD Lab', 'families' => ['trend', 'breakout', 'volatility', 'hybrid', 'regime_ensemble']],
        'EURUSD' => ['name' => 'EURUSD Lab', 'families' => ['trend', 'mean_reversion', 'session', 'hybrid', 'regime_ensemble']],
        'GBPUSD' => ['name' => 'GBPUSD Lab', 'families' => ['breakout', 'momentum', 'volatility', 'hybrid', 'regime_ensemble']],
    ];

    // Architecture is a first-class gene.  Parameters tune an implementation;
    // this gene chooses the decision topology that is allowed to compete.
    private const ARCHITECTURES = [
        'trend' => ['trend_pullback', 'trend_breakout_retest'],
        'breakout' => ['breakout_retest', 'breakout_continuation'],
        'volatility' => ['volatility_compression_expansion', 'volatility_breakout'],
        'mean_reversion' => ['range_mean_reversion', 'range_rsi_reversion'],
        'session' => ['session_breakout', 'session_mean_reversion'],
        'momentum' => ['momentum_continuation', 'momentum_pullback'],
        'hybrid' => ['regime_router', 'regime_consensus'],
        'regime_ensemble' => ['frozen_regime_specialist_ensemble'],
    ];

    public function __construct(
        private StrategyParameterSchemaService $schemas,
        private MarketDataContinuityService $continuity,
        private HistoricalDataQualityService $historicalData,
        private DecisionLearningService $decisionLearning,
    ) {}

    public function ensureLaboratories(): void
    {
        foreach (self::LABS as $symbol => $config) {
            AiLaboratory::updateOrCreate(['symbol' => $symbol, 'timeframe' => 'H1'], [
                'name' => $config['name'], 'timeframe' => 'H1',
                'strategy_families' => $config['families'], 'is_active' => true,
            ]);
        }

        // EURUSD uses a closed H1 regime as context and M15 only for entries.
        // It is a separate evidence stream, never a replacement for the H1 lab.
        AiLaboratory::updateOrCreate(['symbol' => 'EURUSD', 'timeframe' => 'M15'], [
            'name' => 'EURUSD M15 Specialist Lab', 'timeframe' => 'M15',
            'strategy_families' => ['trend', 'mean_reversion', 'session', 'breakout', 'regime_ensemble'], 'is_active' => true,
        ]);
    }

    public function build(string $symbol, string $trigger = 'new_data', bool $force = false, string $timeframe = 'H1'): ?LabGeneration
    {
        $this->ensureLaboratories();
        $timeframe = strtoupper($timeframe);
        $lab = AiLaboratory::where('symbol', strtoupper($symbol))->where('timeframe', $timeframe)->firstOrFail();
        $provider = (string) config('services.market_data.provider', 'csv');
        if (! $force && $provider !== 'csv' && ! $this->continuity->isReady($provider, $lab->symbol, $lab->timeframe)) {
            return null;
        }
        // A forced protocol activation is an explicit operator action after a
        // successful market-data audit.  Normal scheduled populations remain
        // blocked by both continuity and historical-data readiness gates.
        if (! $force && ! app()->environment('testing') && ! $this->historicalData->ready($lab->symbol, $lab->timeframe)) {
            return null;
        }
        $snapshot = $this->dataSnapshot($lab);
        $fingerprint = $snapshot['fingerprint'];
        $latest = $lab->generations()->latest('generation')->first();
        if ($trigger === 'new_data' && ModelMarketPerformance::where('symbol', $lab->symbol)
            ->where('timeframe', $lab->timeframe)->where('status', 'champion')->where('evidence_status', 'valid')
            ->where('consecutive_no_improvement', '>=', 3)->exists()) {
            $trigger = 'degradation';
        }
        if (! $force && $latest && in_array($latest->status, ['draft', 'queued', 'training'], true)) {
            return null;
        }
        $newCandles = $snapshot['count'] - (int) data_get($latest?->trigger_context, 'data_count', 0);
        // H1 learning cadence: a new population needs a full day of fresh
        // evidence.  Degradation is an immediate safety exception; market
        // drift is confirmed separately and still needs new candles so the
        // hourly detector cannot create a 20-agent generation storm.
        if (! $force && $latest && $newCandles < 24 && $trigger !== 'degradation') {
            return null;
        }

        return DB::transaction(function () use ($lab, $latest, $trigger, $fingerprint, $snapshot, $newCandles): LabGeneration {
            $number = (int) ($latest?->generation ?? 0) + 1;
            $generation = $lab->generations()->create([
                'generation' => $number, 'trigger_type' => $trigger,
                'trigger_context' => ['previous_generation' => $latest?->generation, 'created_by' => 'learning_trigger',
                    'data_count' => $snapshot['count'], 'latest_candle' => $snapshot['latest'], 'new_candles' => $newCandles],
                'data_fingerprint' => $fingerprint, 'population_size' => 20,
                'status' => 'draft', 'started_at' => now(),
            ]);

            // Fixed, auditable experiment budget.  A slot is assigned for the
            // gate it is meant to move; it is not an undifferentiated "more
            // agents" budget.
            $plan = $this->generationPlan($lab);
            $generation->update(['trigger_context' => [...($generation->trigger_context ?? []), 'generation_plan' => $plan]]);
            foreach ($plan as $index => $spec) {
                $this->createAgent($generation, $spec['family'], $spec['origin'], $index + 1, $spec['target']);
            }
            return $generation->load('agents.modelVersion');
        });
    }

    /**
     * 20 slots are intentionally stable across generations so observed gate
     * transitions can be compared.  Family selection is deficit-weighted:
     * the greatest unsatisfied forward gate gets the earliest experiments.
     */
    private function generationPlan(AiLaboratory $lab): array
    {
        $families = $this->prioritizedFamilies($lab);
        $slots = [
            ...array_fill(0, 8, ['origin' => 'gate_targeted']),
            ...array_fill(0, 4, ['origin' => 'risk_exit']),
            ...array_fill(0, 3, ['origin' => 'architecture']),
            ...array_fill(0, 3, ['origin' => 'robust_crossover']),
            ...array_fill(0, 2, ['origin' => 'random_explorer']),
        ];

        return collect($slots)->map(function (array $slot, int $index) use ($families): array {
            $familyEvidence = $families[$index % count($families)];
            $origin = $slot['origin'];
            return [
                'origin' => $origin,
                'family' => $familyEvidence['family'],
                'target' => match ($origin) {
                    'gate_targeted' => $familyEvidence['target'],
                    'risk_exit' => 'risk_exit',
                    'architecture' => 'architecture',
                    'robust_crossover' => 'robustness',
                    default => 'exploration',
                },
            ];
        })->all();
    }

    private function prioritizedFamilies(AiLaboratory $lab): array
    {
        $evidence = collect($lab->strategy_families)->reject(fn (string $family) => $this->familyPaused($lab, $family))->map(function (string $family) use ($lab): array {
            $diagnosis = AgentDiagnosis::query()->whereHas('modelMarketPerformance', fn ($query) => $query
                ->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)->where('strategy_family', $family))
                ->latest()->first();
            $rescue = CandidateGateDecision::query()->where('stage', 'diagnostic_rescue_replay')
                ->whereHas('labAgent', fn ($agent) => $agent->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)->where('strategy_family', $family))
                ->latest('evaluated_at')->first();
            $vetoTarget = $this->shadowVetoTarget($lab->symbol, $lab->timeframe, $family);
            $deficits = (array) data_get($diagnosis?->evidence, 'deficits', []);
            $doctorBundle = data_get($diagnosis?->evidence, 'gate_doctor.recommended_bundle');
            $target = $vetoTarget ?: ($doctorBundle ? $this->targetFromBundle($doctorBundle) : ($diagnosis ? $this->dominantTarget($diagnosis->primary_failure, $deficits) : $this->rescueTarget($rescue?->reason_codes ?? [])));
            $severity = (float) ($deficits['trade_deficit'] ?? 0) / 30
                + (float) ($deficits['pf_deficit'] ?? 0) / 1.3
                + (float) ($deficits['rolling_deficit'] ?? 0) / 3
                + (float) ($deficits['drawdown_excess'] ?? 0) / 15
                + (float) ($deficits['ruin_excess'] ?? 0) / 10
                + ($rescue ? 1.0 : 0.0);
            return ['family' => $family, 'target' => $target, 'severity' => $severity];
        })->sortByDesc('severity')->values();

        // No prior evidence is an exploration case, not an implicit claim of
        // quality. Keep the configured family order deterministic on G1.
        return $evidence->isEmpty() ? collect($lab->strategy_families)->map(fn ($family) => ['family' => $family, 'target' => 'trade_frequency', 'severity' => 0])->all() : $evidence->all();
    }

    private function familyPaused(AiLaboratory $lab, string $family): bool
    {
        $records = MutationMemory::with('labAgent.generation')
            ->where('symbol', $lab->symbol)->where('timeframe', $lab->timeframe)
            ->where('strategy_family', $family)->whereNotNull('gate_transition')->get();
        return $this->noGateProgressAcrossThreeGenerations($records);
    }

    private function pausedArchitectures(string $symbol, string $timeframe, string $family): array
    {
        return MutationMemory::with('labAgent.generation')->where(compact('symbol', 'timeframe'))
            ->where('strategy_family', $family)->where('parameter_key', '__architecture')->whereNotNull('gate_transition')->get()
            ->groupBy(fn (MutationMemory $memory) => (string) data_get($memory->new_value, 'value'))
            ->filter(fn ($records) => $this->noGateProgressAcrossThreeGenerations($records))
            ->keys()->filter()->values()->all();
    }

    private function noGateProgressAcrossThreeGenerations($records): bool
    {
        $generations = collect($records)->filter(fn (MutationMemory $memory) => $memory->labAgent?->generation?->status === 'completed')
            ->groupBy(fn (MutationMemory $memory) => $memory->labAgent?->lab_generation_id)
            ->sortByDesc(fn ($items) => (int) $items->first()?->labAgent?->generation?->generation)
            ->take(3);
        if ($generations->count() < 3) return false;
        return $generations->every(fn ($items) => $items->every(fn (MutationMemory $memory) => empty(data_get($memory->gate_transition, 'improved', []))));
    }

    private function dominantTarget(?string $failure, array $deficits): string
    {
        return match ($failure) {
            'signal_starvation', 'over_filtering', 'insufficient_trades', 'no_trade' => 'trade_frequency',
            'negative_edge', 'weak_profit_factor', 'stop_too_tight' => 'profit_factor',
            'excessive_drawdown', 'ruin_risk' => 'drawdown_risk',
            'overfit' => 'architecture',
            default => ((int) ($deficits['rolling_deficit'] ?? 0) > 0 ? 'rolling_regime' : 'profit_factor'),
        };
    }

    private function rescueTarget(array $reasons): string
    {
        if (in_array('FAILED_TRADE_COUNT', $reasons, true)) return 'trade_frequency';
        if (in_array('FAILED_DRAWDOWN', $reasons, true) || in_array('FAILED_RUIN_RISK', $reasons, true)) return 'drawdown_risk';
        if (in_array('FAILED_PROFIT_FACTOR', $reasons, true) || in_array('FAILED_STRESS_COST', $reasons, true)) return 'profit_factor';
        return 'rolling_regime';
    }

    /** A shadow result may relax exactly one veto; it never weakens promotion gates. */
    private function shadowVetoTarget(string $symbol, string $timeframe, string $family): ?string
    {
        $decisions = CandidateGateDecision::query()->where('stage', 'screening')
            ->whereHas('labAgent', fn ($agent) => $agent->where('symbol', $symbol)->where('timeframe', $timeframe)->where('strategy_family', $family))
            ->latest('evaluated_at')->take(12)->get();
        foreach ($decisions as $decision) {
            foreach ((array) data_get($decision->metrics, 'veto_regret.by_veto_reason', []) as $reason => $metrics) {
                if ((int) data_get($metrics, 'shadow_trades', 0) < 5 || data_get($metrics, 'recommended_action') !== 'relax_bounded_veto') continue;
                return match ($reason) {
                    'loss_cooldown' => 'shadow_veto_loss_cooldown',
                    'minimum_confidence' => 'shadow_veto_confidence',
                    'high_volatility_veto' => 'shadow_veto_volatility',
                    default => null,
                };
            }
        }
        return null;
    }

    private function targetFromBundle(string $bundle): string
    {
        return match ($bundle) {
            'trade_frequency_bundle' => 'trade_frequency', 'profit_factor_bundle' => 'profit_factor',
            'drawdown_bundle' => 'drawdown_risk', 'architecture_bundle' => 'architecture',
            default => 'rolling_regime',
        };
    }

    private function createAgent(LabGeneration $generation, string $family, string $origin, int $slot, string $target): void
    {
        $lab = $generation->laboratory;
        // Forward score alone is insufficient evidence for a reusable parent.
        // Weak-PF or high-ruin candidates remain explorers, not gene sources.
        $parents = $this->qualityParents($lab->symbol, $lab->timeframe, $family);
        // A dynamic parent frontier must actually be used. Rotate through all
        // proven parents instead of permanently cloning the first two ranked
        // records into every new generation.
        $parentCount = $parents->count();
        $parentA = $parentCount ? $parents->get(($slot - 1) % $parentCount) : null;
        $parentB = $parentCount > 1 ? $parents->get($slot % $parentCount) : null;
        $base = $parentA?->parameters ?: $this->schemas->defaults($family);
        $mutationScope = in_array($origin, ['gate_targeted', 'risk_exit', 'architecture'], true)
            ? $this->mutationScope($lab->symbol, $lab->timeframe, $family, $slot)
            : null;
        // Slots are interleaved by family (1, 5, 9...).  Use a family-local
        // experiment index so every architecture receives representation;
        // using the raw slot would give a family the same modulo forever.
        $architectureSeed = intdiv($slot - 1, max(1, count($lab->strategy_families))) + 1;
        $architecture = $this->selectArchitecture($lab->symbol, $lab->timeframe, $family, $origin, $architectureSeed, $mutationScope, $parentA);

        $skillCrossoverSources = [];
        if ($origin === 'robust_crossover') {
            [$parameters, $skillCrossoverSources] = $this->skillCrossover($family, $parents, $base, $slot);
        } else {
            $parameters = match ($origin) {
                'gate_targeted', 'risk_exit', 'architecture' => $this->mutate($lab->symbol, $lab->timeframe, $family, $base, $slot, $mutationScope, $target),
                default => $this->randomParameters($family, $slot),
            };
        }
        $parameters = $this->schemas->validate($family, $parameters);
        // A generation is an experiment set, not a collection of parameter
        // clones.  Reject exact fingerprints and near neighbours before they
        // consume an expensive full validation slot.
        $parameters = $this->ensureNovelParameters($generation, $family, $parameters, $slot, $origin === 'gate_targeted');
        // Model versions are globally unique. H1 keeps its established name;
        // additional execution timeframes receive an explicit namespace so an
        // EURUSD M15 G1 specialist cannot collide with EURUSD H1 G1.
        $timeframePrefix = strtoupper($lab->timeframe) === 'H1' ? '' : '_'.strtolower($lab->timeframe);
        $strategy = strtolower($lab->symbol).$timeframePrefix.'_'.$family.'_g'.$generation->generation.'_a'.str_pad((string) $slot, 2, '0', STR_PAD_LEFT);
        $model = ModelVersion::create([
            'name' => $strategy, 'strategy' => $strategy, 'version' => 'v'.$generation->generation,
            'generation' => $generation->generation, 'status' => 'testing', 'parameters' => $parameters,
            'description' => "{$lab->name} generation {$generation->generation} {$origin} agent",
            'metadata' => array_filter([
                'base_strategy' => $this->architectureBaseStrategy($architecture), 'strategy_architecture' => $architecture,
                'lab_symbol' => $lab->symbol, 'origin' => $origin,
                'generation_target' => $target,
                'mutation_scope' => $mutationScope,
                'skill_crossover_sources' => $skillCrossoverSources ?: null,
                'parameter_fingerprint' => $this->parameterFingerprint($family, $parameters),
                // New generations must produce actual CSCV/PBO, DSR and
                // bootstrap evidence before paper promotion. Legacy records
                // remain auditable but cannot silently define this protocol.
                'statistical_gate_version' => 2,
            ]),
        ]);
        $generation->agents()->create([
            'model_version_id' => $model->id, 'parent_a_model_version_id' => $parentA?->id,
            'parent_b_model_version_id' => $origin === 'crossover' ? $parentB?->id : null,
            'symbol' => $lab->symbol, 'timeframe' => $lab->timeframe, 'strategy_family' => $family,
            'origin' => $origin, 'lifecycle_status' => 'draft',
            'parameter_diff' => $this->diff($base, $parameters),
        ]);
    }

    private function ensureNovelParameters(LabGeneration $generation, string $family, array $parameters, int $seed, bool $preserveDirectedMutation = false): array
    {
        $existing = $generation->agents()->with('modelVersion')->get()
            ->filter(fn ($agent) => $agent->strategy_family === $family)
            ->map(fn ($agent) => $agent->modelVersion?->parameters ?? [])
            ->values();

        for ($attempt = 0; $attempt < 12; $attempt++) {
            $duplicate = $existing->contains(fn (array $other) =>
                $this->parameterFingerprint($family, $other) === $this->parameterFingerprint($family, $parameters)
                || (! $preserveDirectedMutation && $this->parameterDistance($family, $other, $parameters) < 0.08)
            );
            if (! $duplicate) return $parameters;
            $parameters = $this->randomParameters($family, $seed + (($attempt + 1) * 37));
        }
        // The final fallback remains schema-valid and deterministic.  Its
        // fingerprint records that the family search space was exhausted.
        return $parameters;
    }

    private function selectArchitecture(string $symbol, string $timeframe, string $family, string $origin, int $seed, ?string $scope, ?ModelVersion $parent): string
    {
        $architectures = self::ARCHITECTURES[$family] ?? [$family];
        $memory = MutationMemory::query()->where(compact('symbol', 'timeframe'))->where('strategy_family', $family)
            ->where('parameter_key', '__architecture')->when($scope, fn ($query) => $query->where('market_regime', $scope));
        $harmful = (clone $memory)->where('outcome', 'harmful')->where('confidence', '>=', 70)
            ->pluck('new_value')->map(fn ($value) => data_get($value, 'value'))->filter()->unique()->all();
        $paused = $this->pausedArchitectures($symbol, $timeframe, $family);
        $available = array_values(array_diff($architectures, $harmful, $paused));
        if ($available === []) $available = $architectures; // search space is exhausted; re-test rather than silently stop learning.
        $parentArchitecture = data_get($parent?->metadata, 'strategy_architecture');
        if ($origin === 'elite' && in_array($parentArchitecture, $available, true)) return $parentArchitecture;
        $beneficial = (clone $memory)->where('outcome', 'beneficial')->orderByDesc('confidence')->first();
        $preferred = data_get($beneficial?->new_value, 'value');
        // Mutation exploits an evidenced topology on two thirds of slots and
        // keeps one third exploratory so a formerly weak architecture can
        // recover under a different regime rather than becoming permanently extinct.
        if ($origin === 'mutation' && in_array($preferred, $available, true) && $seed % 3 !== 0) return $preferred;
        return $available[$seed % count($available)];
    }

    private function architectureBaseStrategy(string $architecture): string
    {
        return match ($architecture) {
            'trend_pullback' => 'trend_v1', 'trend_breakout_retest' => 'trend_retest_v1',
            'breakout_retest', 'breakout_continuation' => 'breakout_v1',
            'volatility_compression_expansion', 'volatility_breakout' => 'volatility_v1',
            'range_mean_reversion', 'range_rsi_reversion' => 'mean_reversion_v1',
            'session_breakout', 'session_mean_reversion' => 'session_v1',
            'momentum_continuation', 'momentum_pullback' => 'momentum_v1',
            'regime_router', 'regime_consensus' => 'hybrid_v1',
            'frozen_regime_specialist_ensemble' => 'regime_ensemble_v1',
            default => $architecture.'_v1',
        };
    }

    private function parameterFingerprint(string $family, array $parameters): string
    {
        ksort($parameters);
        return hash('sha256', $family.'|'.json_encode($parameters, JSON_PRESERVE_ZERO_FRACTION));
    }

    private function parameterDistance(string $family, array $left, array $right): float
    {
        $schema = $this->schemas->schema($family);
        $distance = 0.0;
        $count = 0;
        foreach ($schema as $key => $definition) {
            [$type, $min, $max] = array_pad($definition, 3, null);
            if (! array_key_exists($key, $left) || ! array_key_exists($key, $right)) continue;
            $count++;
            if ($type === 'boolean') {
                $distance += (bool) $left[$key] === (bool) $right[$key] ? 0.0 : 1.0;
                continue;
            }
            $distance += abs((float) $left[$key] - (float) $right[$key]) / max(0.000001, (float) $max - (float) $min);
        }
        return $count ? $distance / $count : 1.0;
    }

    private function mutate(string $symbol, string $timeframe, string $family, array $base, int $seed, ?string $scope, string $target = 'profit_factor'): array
    {
        $schema = $this->schemas->schema($family);
        $beneficial = MutationMemory::where(compact('symbol', 'timeframe'))->where('strategy_family', $family)
            ->when($scope, fn ($query) => $query->where('market_regime', $scope))
            ->where('outcome', 'beneficial')->orderByDesc('confidence')->first();
        $harmful = MutationMemory::where(compact('symbol', 'timeframe'))->where('strategy_family', $family)
            ->when($scope, fn ($query) => $query->where('market_regime', $scope))
            ->where('outcome', 'harmful')->orderByDesc('confidence')->first();
        $keys = array_keys($schema);
        $targetKeys = match ($target) {
            // Trade deficit: relax only the filter proven to be preventing
            // entries. Do not solve starvation by silently increasing risk.
            'trade_frequency' => ['lookback', 'confirmation_candles', 'minimum_signal_confidence', 'trend_strength_min', 'roc_threshold', 'deviation', 'atr_threshold', 'compression_ratio'],
            // Shadow regret pointed to this exact guard. Keep the rescue
            // bounded to the guard and its online evidence threshold.
            'shadow_veto_loss_cooldown' => ['loss_cooldown_candles', 'cooldown_shadow_edge_pf', 'cooldown_shadow_min_samples'],
            'shadow_veto_confidence' => ['minimum_signal_confidence'],
            'shadow_veto_volatility' => ['high_volatility_risk_multiplier', 'avoid_high_volatility'],
            // PF is an exit-topology experiment: stop/target/trailing/time
            // exits and partials are changed as a coherent family.
            'profit_factor', 'risk_exit' => ['atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles', 'partial_take_profit_fraction', 'partial_target_atr_multiplier'],
            'drawdown_risk' => ['high_volatility_risk_multiplier', 'max_loss_streak_before_wait', 'loss_cooldown_candles', 'atr_stop_multiplier', 'avoid_high_volatility'],
            'rolling_regime', 'architecture' => ['lookback', 'session_start', 'session_end', 'trend_strength_min', 'minimum_signal_confidence', 'high_volatility_risk_multiplier'],
            default => $keys,
        };
        $targetKeys = array_values(array_intersect($keys, $targetKeys));
        if ($targetKeys !== []) $keys = $targetKeys;
        // A high-confidence harmful mutation is evidence, not a tie-breaker.
        // Keep it out of the next search unless every available gene has been
        // falsified; otherwise a single historic beneficial result could keep
        // resurrecting a known-bad direction.
        $harmfulKeys = MutationMemory::where(compact('symbol', 'timeframe'))->where('strategy_family', $family)
            ->when($scope, fn ($query) => $query->where('market_regime', $scope))
            ->where('outcome', 'harmful')->where('confidence', '>=', 70)
            ->pluck('parameter_key')->unique()->all();
        $safeKeys = array_values(array_diff($keys, $harmfulKeys));
        if ($safeKeys !== []) $keys = $safeKeys;
        // Three repeated changes with no observable behavioural movement are
        // not treated as an optimisation signal. Temporarily park the gene so
        // the generation budget can test a materially different mechanism.
        $ineffectiveKeys = MutationMemory::where(compact('symbol', 'timeframe'))->where('strategy_family', $family)
            ->whereIn('parameter_key', $keys)->get()
            ->filter(fn (MutationMemory $memory) => data_get($memory->behavioral_effect, 'causal_experiment.parameter_effective') === false)
            ->pluck('parameter_key')->unique()->all();
        $effectiveKeys = array_values(array_diff($keys, $ineffectiveKeys));
        if ($effectiveKeys !== []) $keys = $effectiveKeys;
        $diagnosedKey = AgentDiagnosis::whereHas('modelMarketPerformance', fn($q)=>$q->where('symbol',$symbol)->where('timeframe',$timeframe)->where('strategy_family',$family))
            ->latest()->get()->flatMap(fn($item)=>$item->recommended_mutations??[])->first(fn($key)=>isset($schema[$key]) && in_array($key, $keys, true));
        $decisionAdvice = $this->decisionLearning->advice($symbol, $timeframe, $family, $scope);
        $decisionKey = collect($decisionAdvice['prioritize'])->first(fn ($key) => isset($schema[$key]) && in_array($key, $keys, true));
        if (! $beneficial && $harmful && count($keys) > 1) {
            $keys = array_values(array_diff($keys, [$harmful->parameter_key]));
        }
        $key = $beneficial?->parameter_key && isset($schema[$beneficial->parameter_key]) && in_array($beneficial->parameter_key, $keys, true)
            ? $beneficial->parameter_key : ($decisionKey ?: ($diagnosedKey ?: $keys[$seed % count($keys)]));
        [$type, $min, $max] = array_pad($schema[$key], 3, null);
        if ($type === 'boolean') { $base[$key] = ! (bool) ($base[$key] ?? false); return $base; }
        $current = (float) ($base[$key] ?? (($min + $max) / 2));
        $learnedDirection = static fn (MutationMemory $memory): int => data_get($memory->new_value, 'value') >= data_get($memory->old_value, 'value') ? 1 : -1;
        $direction = $beneficial && is_numeric(data_get($beneficial->new_value, 'value')) && is_numeric(data_get($beneficial->old_value, 'value'))
            ? $learnedDirection($beneficial)
            : ($harmful && is_numeric(data_get($harmful->new_value, 'value')) && is_numeric(data_get($harmful->old_value, 'value'))
                ? -$learnedDirection($harmful)
                : ($seed % 2 === 0 ? 1 : -1));
        if ($target === 'trade_frequency' && in_array($key, ['lookback', 'confirmation_candles', 'minimum_signal_confidence', 'trend_strength_min', 'roc_threshold', 'deviation', 'atr_threshold'], true)) $direction = -1;
        if ($target === 'shadow_veto_loss_cooldown' && in_array($key, ['loss_cooldown_candles', 'cooldown_shadow_edge_pf', 'cooldown_shadow_min_samples'], true)) $direction = -1;
        if ($target === 'shadow_veto_confidence') $direction = -1;
        if ($target === 'shadow_veto_volatility' && $key === 'high_volatility_risk_multiplier') $direction = 1;
        if ($target === 'drawdown_risk' && in_array($key, ['high_volatility_risk_multiplier'], true)) $direction = -1;
        if ($target === 'drawdown_risk' && in_array($key, ['max_loss_streak_before_wait', 'loss_cooldown_candles', 'atr_stop_multiplier'], true)) $direction = 1;
        $step = ($beneficial || $harmful) ? 0.05 : 0.1;
        $value = max($min, min($max, $current + $direction * (($max - $min) * $step)));
        $base[$key] = $type === 'integer' ? (int) round($value) : round($value, 4);
        return $this->applyBoundedBundle($schema, $base, $key, $target, $direction);
    }

    /** A mutation is a small coherent experiment, never an unbounded re-fit. */
    private function applyBoundedBundle(array $schema, array $parameters, string $primary, string $target, int $direction): array
    {
        $bundle = match ($target) {
            'trade_frequency' => ['lookback', 'confirmation_candles', 'minimum_signal_confidence', 'trend_strength_min'],
            'shadow_veto_loss_cooldown' => ['loss_cooldown_candles', 'cooldown_shadow_edge_pf', 'cooldown_shadow_min_samples'],
            'shadow_veto_confidence' => ['minimum_signal_confidence'],
            'shadow_veto_volatility' => ['high_volatility_risk_multiplier', 'avoid_high_volatility'],
            'profit_factor', 'risk_exit' => ['atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles', 'partial_take_profit_fraction'],
            'drawdown_risk' => ['high_volatility_risk_multiplier', 'max_loss_streak_before_wait', 'loss_cooldown_candles', 'avoid_high_volatility'],
            'rolling_regime', 'architecture' => ['lookback', 'session_start', 'session_end', 'minimum_signal_confidence'],
            default => [],
        };
        // Primary plus two related controls is enough to test topology while
        // retaining a causal attribution path for each bundle.
        foreach (array_slice(array_values(array_diff($bundle, [$primary])), 0, 2) as $key) {
            if (! isset($schema[$key])) continue;
            [$type, $min, $max] = array_pad($schema[$key], 3, null);
            if ($type === 'boolean') {
                if ($target === 'drawdown_risk') $parameters[$key] = true;
                continue;
            }
            $current = (float) ($parameters[$key] ?? (($min + $max) / 2));
            $secondaryDirection = $target === 'trade_frequency' ? -1 : $direction;
            if ($target === 'drawdown_risk' && $key === 'high_volatility_risk_multiplier') $secondaryDirection = -1;
            $value = max($min, min($max, $current + $secondaryDirection * (($max - $min) * .03)));
            $parameters[$key] = $type === 'integer' ? (int) round($value) : round($value, 4);
        }
        return $parameters;
    }

    private function qualityParents(string $symbol, string $timeframe, string $family)
    {
        return ModelMarketPerformance::with('modelVersion')
            ->where(compact('symbol', 'timeframe'))
            ->where('evidence_status', 'valid')
            ->whereHas('modelVersion', fn ($query) => $query->where('evidence_status', 'valid'))
            ->where('strategy_family', $family)
            ->whereIn('status', ['champion', 'challenger', 'forward_validated', 'paper'])
            ->get()
            ->filter(fn (ModelMarketPerformance $performance) => $this->parentEligible($performance))
            ->sortByDesc(fn (ModelMarketPerformance $performance) => $this->parentQualityScore($performance))
            ->pluck('modelVersion')
            ->filter()
            ->values();
    }

    private function parentEligible(ModelMarketPerformance $performance): bool
    {
        $metrics = $performance->metrics ?? [];
        $bootstrap = data_get($metrics, 'statistical_evidence.edge_quality.bootstrap_pf', []);
        $bootstrapPasses = data_get($bootstrap, 'status') !== 'assessed'
            || (float) data_get($bootstrap, 'pf_5_percentile_lower_bound', 0) >= 1.1;
        $worstRegime = data_get($metrics, 'statistical_evidence.edge_quality');
        $regimePasses = ! data_get($worstRegime, 'worst_regime_sampled', false)
            || (float) data_get($worstRegime, 'worst_regime_pf', 0) >= 1.0;

        return (float) data_get($metrics, 'profit_factor', 0) >= 1.3
            && (float) data_get($metrics, 'max_drawdown_percent', data_get($metrics, 'max_drawdown', 100)) <= 15
            && (float) data_get($metrics, 'monte_carlo.risk_of_ruin_percent', 100) <= 10
            && ! (bool) data_get($metrics, 'is_overfit', true)
            && (int) $performance->sample_count >= 30
            && (int) $performance->rolling_windows_count >= 3
            && (int) $performance->rolling_forward_wins >= 3
            && $bootstrapPasses && $regimePasses
            && data_get($metrics, 'behavioral_diversity.status') !== 'near_duplicate';
    }

    private function parentQualityScore(ModelMarketPerformance $performance): float
    {
        $metrics = $performance->metrics ?? [];

        return ((float) $performance->forward_score * 2)
            + ((float) data_get($metrics, 'profit_factor', 0) * 25)
            - ((float) data_get($metrics, 'monte_carlo.risk_of_ruin_percent', 100) * 2)
            - (float) data_get($metrics, 'max_drawdown_percent', data_get($metrics, 'max_drawdown', 100));
    }

    private function mutationScope(string $symbol, string $timeframe, string $family, int $seed): string
    {
        $scopes = [];
        ModelMarketPerformance::query()
            ->where(compact('symbol', 'timeframe'))
            ->where('strategy_family', $family)
            ->where('evidence_status', 'valid')
            ->latest('updated_at')
            ->take(20)
            ->get()
            ->each(function (ModelMarketPerformance $performance) use (&$scopes): void {
                foreach (['market' => 'regime_performance', 'volatility' => 'volatility_performance'] as $prefix => $key) {
                    foreach (data_get($performance->metrics, $key, []) as $name => $metric) {
                        if ((int) data_get($metric, 'trades', 0) < 20) {
                            continue;
                        }
                        $scope = "{$prefix}:{$name}";
                        $score = (float) data_get($metric, 'profit_percent', 0) / max(1, (int) data_get($metric, 'trades', 1));
                        $scopes[$scope] = min($scopes[$scope] ?? INF, $score);
                    }
                }
            });

        if ($scopes !== []) {
            asort($scopes);
            return (string) array_key_first($scopes);
        }

        $fallbacks = ['market:trend_up', 'market:trend_down', 'market:range', 'volatility:high_volatility', 'volatility:normal_volatility'];
        return $fallbacks[$seed % count($fallbacks)];
    }

    private function crossover(string $family, array $a, array $b): array
    {
        $child = [];
        foreach (array_keys($this->schemas->schema($family)) as $index => $key) $child[$key] = $index % 2 === 0 ? ($a[$key] ?? $b[$key]) : ($b[$key] ?? $a[$key]);
        return $child;
    }

    /** Blend entry, exit and risk genes from the parent with demonstrated skill in that domain. */
    private function skillCrossover(string $family, $parents, array $fallback, int $seed): array
    {
        $parents = collect($parents)->filter()->values();
        if ($parents->isEmpty()) return [$this->randomParameters($family, $seed), []];
        $child = $fallback;
        $sources = [];
        foreach (array_keys($this->schemas->schema($family)) as $key) {
            $skill = match (true) {
                in_array($key, ['atr_stop_multiplier', 'atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles', 'partial_take_profit_fraction', 'partial_target_atr_multiplier'], true) => 'exit_skill',
                in_array($key, ['high_volatility_risk_multiplier', 'max_loss_streak_before_wait', 'loss_cooldown_candles', 'avoid_high_volatility'], true) => 'risk_skill',
                in_array($key, ['lookback', 'confirmation_candles', 'minimum_signal_confidence', 'trend_strength_min', 'pullback_atr_fraction', 'roc_threshold', 'deviation'], true) => 'entry_timing_skill',
                default => 'cost_robustness_skill',
            };
            $parent = $parents->sortByDesc(fn (ModelVersion $model) => (float) data_get($model->metadata, "skill_tree.{$skill}", 0))->first();
            if ($parent && array_key_exists($key, $parent->parameters ?? [])) {
                $child[$key] = $parent->parameters[$key];
                $sources[$skill] = $parent->id;
            }
        }
        return [$child, $sources];
    }

    private function randomParameters(string $family, int $seed): array
    {
        $values = [];
        $index = 0;
        foreach ($this->schemas->schema($family) as $key => $rule) {
            [$type, $min, $max] = array_pad($rule, 3, null);
            if ($type === 'boolean') { $values[$key] = ($seed + $index) % 2 === 0; $index++; continue; }
            $ratio = (($seed * 37 + $index * 17) % 101) / 100;
            $value = $min + ($max - $min) * $ratio;
            $values[$key] = $type === 'integer' ? (int) round($value) : round($value, 4);
            $index++;
        }
        return $values;
    }

    private function dataSnapshot(AiLaboratory $lab): array
    {
        $symbolId = Symbol::where('code', $lab->symbol)->value('id');
        if (! $symbolId) return ['fingerprint' => 'no-data', 'count' => 0, 'latest' => null];
        $query = Candle::where('symbol_id', $symbolId)->where('timeframe', $lab->timeframe);
        $count = $query->count();
        $latest = $query->max('time');
        return ['fingerprint' => sha1($count.'|'.($latest ?? 'none')), 'count' => $count, 'latest' => $latest];
    }

    private function diff(array $old, array $new): array
    {
        return collect($new)->filter(fn ($value, $key) => ! array_key_exists($key, $old) || $old[$key] !== $value)
            ->map(fn ($value, $key) => ['old' => $old[$key] ?? null, 'new' => $value])->all();
    }
}
