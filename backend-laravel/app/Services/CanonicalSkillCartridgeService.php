<?php

namespace App\Services;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\AgentLearningLesson;
use App\Models\AgentLearningMutationIntent;
use App\Models\AgentLearningSettlement;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use App\Models\LabMutationResponseMap;
use App\Models\LabSkillZooEntry;
use App\Models\ModelVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Canonical, executable component intervention archive backed by settled paired evidence. */
class CanonicalSkillCartridgeService
{
    public const PROTOCOL = 'canonical_skill_cartridge_pipeline_v1';

    public const PER_FOLD_BUDGET_SECONDS = 240;

    public function __construct(
        private CausalCompoundingKernelService $compoundingKernel,
    ) {}

    /** Construction defects may receive one repaired retry; quality failures never do. */
    private const REPAIRABLE_TRANSPLANT_PREFLIGHT_ERRORS = [
        'PARAMETER_INVARIANT_METADATA_MISMATCH',
        'FULL_REPLAY_DATASET_COVERAGE_INSUFFICIENT',
    ];

    /** @return array<string,mixed>|null */
    public function project(LabLearningLanePair $pair, array $result, ?LabMutationResponseMap $map, AgentLearningSettlement $settlement): ?array
    {
        if (! $this->available() || ! $map || ! $pair->isVerifiedControlPair() || ! $pair->candidateAgent) {
            return null;
        }
        $agent = $pair->candidateAgent->fresh(['modelVersion']);
        if (! $agent || count((array) $agent->parameter_diff) !== 1 || ! filled($map->parameter_key)) {
            return null;
        }
        $changedGene = array_key_first((array) $agent->parameter_diff);
        $change = (array) data_get($agent->parameter_diff, $changedGene, []);
        $context = $this->context($pair, $result);
        $old = data_get($map->old_value, 'value', $map->old_value);
        $new = data_get($map->new_value, 'value', $map->new_value);
        // A response map can describe a screen observation, but it becomes a
        // reusable intervention only when it names exactly the change that
        // was actually replayed by this candidate.
        if ($changedGene === null || (string) $map->parameter_key !== (string) $changedGene
            || json_encode(data_get($change, 'old')) !== json_encode($old)
            || json_encode(data_get($change, 'new')) !== json_encode($new)) {
            return null;
        }
        if ($old === null || $new === null) {
            return null;
        }
        $component = app(SkillZooService::class)->moduleFor((string) $map->parameter_key);
        $intervention = [
            'old_value' => $old,
            'tested_value' => $new,
            'direction' => $this->direction($old, $new),
            'value_type' => get_debug_type($new),
            'refinement_range' => $this->range($new),
            'baseline_predicate_hash' => hash('sha256', json_encode([$pair->control_agent_id, $pair->candidate_data_hash, $pair->candidate_execution_hash])),
            'reversible' => true,
        ];
        // The capsule hash is part of cartridge identity. The same scalar
        // change under a different instrument bundle is a different causal
        // treatment and must never be pooled into one posterior.
        $capsulePreview = app(ContextualCausalTraitCapsuleService::class)->compile(
            $pair,
            $agent,
            $map,
            $result,
            $context,
            $intervention,
            ['target' => $pair->target, 'mean_delta' => (float) data_get($pair->target_delta, 'delta', 0), 'total_windows' => 1],
            $this->secondary($result, $pair),
            ['receipt_ids' => [$settlement->id], 'response_map_ids' => [$map->id]],
            'paired_observed',
            1,
        );
        $identity = ['symbol' => strtoupper($pair->symbol), 'timeframe' => strtoupper($pair->timeframe), 'strategy_family' => $pair->strategy_family,
            'component' => $component, 'gene' => $map->parameter_key, 'old_value' => $old, 'tested_value' => $new,
            // Receipt/run hashes belong to provenance, not treatment
            // identity. Only the canonical activation axes may group
            // independent observations into one cartridge.
            'context' => (array) data_get($capsulePreview, 'activation_context.predicate', []),
            // The capsule itself contains receipt, pair and source IDs. Those
            // fields must change across independent replications, so the full
            // capsule hash cannot be the cartridge identity or no cartridge
            // could ever accumulate the three confirmations it requires.
            'activation_context_hash' => data_get($capsulePreview, 'activation_context.context_hash'),
            'instrument_bundle_hash' => data_get($capsulePreview, 'instrument_bundle.bundle_hash'),
            'data_major' => substr((string) $pair->candidate_data_hash, 0, 16), 'execution_major' => substr((string) $pair->candidate_execution_hash, 0, 16)];
        $key = hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

        return DB::transaction(function () use ($pair, $result, $map, $settlement, $agent, $context, $component, $intervention, $identity, $key, $capsulePreview): array {
            $entry = LabSkillZooEntry::query()->where('cartridge_key', $key)->lockForUpdate()->first();
            $delta = (float) data_get($pair->target_delta, 'delta', 0);
            $positive = $settlement->evidence_state === 'positive';
            $observationKey = hash('sha256', implode('|', [self::PROTOCOL, $key, $settlement->id, $pair->id]));
            if (! $entry) {
                $entry = LabSkillZooEntry::create(['skill_key' => hash('sha256', self::PROTOCOL.'|'.$key), 'cartridge_key' => $key, 'revision' => 1,
                    'symbol' => $pair->symbol, 'timeframe' => $pair->timeframe, 'strategy_family' => $pair->strategy_family, 'module_key' => $component,
                    'niche_key' => $this->niche($context), 'gene_key' => $map->parameter_key, 'lab_agent_id' => $agent->id, 'model_version_id' => $agent->model_version_id,
                    'lab_mutation_response_map_id' => $map->id, 'causal_baseline_agent_id' => $pair->control_agent_id,
                    'genetic_parent_model_version_id' => $agent->parent_a_model_version_id, 'quality_score' => 0, 'confidence' => 0,
                    'status' => 'provisional', 'component_status' => 'paired_observed', 'organism_viability' => $this->viability($agent), 'revision' => 0, 'evidence' => []]);
            }
            $inserted = DB::table('skill_cartridge_observations')->insertOrIgnore(['observation_key' => $observationKey, 'lab_skill_zoo_entry_id' => $entry->id,
                'agent_learning_settlement_id' => $settlement->id, 'lab_learning_lane_pair_id' => $pair->id, 'lab_mutation_response_map_id' => $map->id,
                'outcome' => $positive ? 'positive' : ($settlement->evidence_state === 'negative' ? 'negative' : 'uncertain'), 'target_delta' => $delta,
                'evidence' => json_encode(['result' => $result, 'identity' => $identity, 'promotion_evidence' => false]), 'observed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $observations = DB::table('skill_cartridge_observations')->where('lab_skill_zoo_entry_id', $entry->id)->get();
            $positiveCount = $observations->where('outcome', 'positive')->count();
            $negativeCount = $observations->where('outcome', 'negative')->count();
            $total = $observations->count();
            $mean = (float) $observations->avg('target_delta');
            $pairs = LabLearningLanePair::query()->whereIn('id', $observations->pluck('lab_learning_lane_pair_id')->filter()->unique())->get();
            $positiveObservationIds = $observations->where('outcome', 'positive')->pluck('id')->all();
            $positivePairs = $pairs->whereIn('id', $observations->whereIn('id', $positiveObservationIds)
                ->pluck('lab_learning_lane_pair_id')->filter()->all());
            $independentWindowKeys = $observations->where('outcome', 'positive')->flatMap(function ($observation) use ($positivePairs): array {
                $evidence = json_decode((string) $observation->evidence, true) ?: [];
                $resultKeys = (array) data_get($evidence, 'result.forward_window_protocol.window_keys', []);
                $pairKey = $positivePairs->firstWhere('id', (int) $observation->lab_learning_lane_pair_id)?->independent_window_key;

                return array_values(array_filter([...$resultKeys, $pairKey]));
            })->map(fn ($value): string => (string) $value)->unique()->values();
            $poweredPositiveObservations = $observations->where('outcome', 'positive')->filter(function ($observation) use ($capsulePreview): bool {
                $evidence = json_decode((string) $observation->evidence, true) ?: [];
                $trace = (array) data_get($evidence, 'result.instrument_research_trace', []);
                $forward = (array) data_get($evidence, 'result.forward_window_protocol', []);
                if (data_get($trace, 'status') !== 'consumed'
                    || data_get($forward, 'independence_verified') !== true
                    || (bool) data_get($forward, 'overlap_detected', true)) {
                    return false;
                }

                return collect((array) data_get($trace, 'context_slices', []))->contains(fn ($slice): bool => is_array($slice)
                    && data_get($slice, 'powered') === true
                    && (bool) data_get(app(ContextualCausalTraitCapsuleService::class)->contextCompatibility(
                        $capsulePreview,
                        (array) data_get($slice, 'context', []),
                    ), 'compatible', false));
            })->count();
            $componentStatus = $positiveCount >= 3
                && $positiveCount > $negativeCount
                && $independentWindowKeys->count() >= 3
                && $poweredPositiveObservations >= 3
                    ? 'component_confirmed'
                    : 'paired_observed';
            $organism = $this->viability($agent);
            $authority = $componentStatus === 'component_confirmed' && $organism !== 'viable' ? 'stepping_stone_skill' : ($componentStatus === 'component_confirmed' && $organism === 'viable' ? 'breeder_skill_candidate' : 'research_only');
            $confirmationState = $componentStatus === 'component_confirmed'
                ? ['state' => 'component_confirmed', 'next_required_action' => 'transplant_or_incubate_under_separate_authority_gate']
                : ($positiveCount >= 2 && $negativeCount === 0
                    ? ['state' => 'awaiting_third_independent_replication', 'next_required_action' => 'dispatch_gene_type_aware_five_arm_confirmation']
                    : ['state' => 'collect_paired_observations', 'next_required_action' => 'settle_next_canonical_pair']);
            $prior = (array) data_get($entry->evidence, 'provenance', []);
            $provenance = [
                'causal_baseline_id' => $pair->control_agent_id,
                'causal_baseline_ids' => array_values(array_unique(array_filter([...((array) data_get($prior, 'causal_baseline_ids', [])), ...$pairs->pluck('control_agent_id')->all()]))),
                'genetic_parent_id' => $agent->parent_a_model_version_id,
                'receipt_ids' => array_values(array_unique(array_filter([...((array) data_get($prior, 'receipt_ids', [])), ...$observations->pluck('agent_learning_settlement_id')->all()]))),
                'response_map_ids' => array_values(array_unique(array_filter([...((array) data_get($prior, 'response_map_ids', [])), ...$observations->pluck('lab_mutation_response_map_id')->all()]))),
                'data_hashes' => array_values(array_unique(array_filter([...((array) data_get($prior, 'data_hashes', [])), ...$pairs->pluck('candidate_data_hash')->all()]))),
                'execution_hashes' => array_values(array_unique(array_filter([...((array) data_get($prior, 'execution_hashes', [])), ...$pairs->pluck('candidate_execution_hash')->all()]))),
            ];
            $effect = ['target' => $pair->target, 'mean_delta' => $mean, 'lower_bound' => data_get($result, 'statistical_evidence.edge_quality.lower_confidence_bound'),
                'positive_windows' => $independentWindowKeys->count(), 'total_windows' => $independentWindowKeys->count()];
            $secondary = $this->secondary($result, $pair);
            $revision = (int) $entry->revision + ($inserted ? 1 : 0);
            $traitCapsule = app(ContextualCausalTraitCapsuleService::class)->compile(
                $pair,
                $agent,
                $map,
                $result,
                $context,
                $intervention,
                $effect,
                $secondary,
                $provenance,
                $componentStatus,
                $revision,
            );
            if ($negativeCount > 0) {
                $traitCapsule['contraindicated_contexts'][] = [
                    'context_hash' => data_get($traitCapsule, 'activation_context.context_hash'),
                    'context' => data_get($traitCapsule, 'activation_context.predicate'),
                    'reason' => 'negative_or_non_target_regression',
                ];
            }
            $payload = ['protocol' => self::PROTOCOL, 'identity' => $identity, 'intervention' => $intervention,
                'context' => $context, 'effect' => $effect,
                'secondary_effects' => $secondary, 'contraindications' => $negativeCount ? [['context' => $context, 'reason' => 'negative_or_non_target_regression']] : [],
                'provenance' => $provenance,
                'confirmation' => [...$confirmationState, 'positive_observations' => $positiveCount, 'negative_observations' => $negativeCount,
                    'independent_window_keys' => $independentWindowKeys->all(),
                    'powered_context_positive_observations' => $poweredPositiveObservations,
                    'independent_confirmation_required' => $componentStatus !== 'component_confirmed', 'promotion_evidence' => false],
                'authority' => ['component_status' => $componentStatus, 'organism_viability' => $organism, 'research_executable' => true, 'mentor_seed' => false, 'breeder_eligible' => false, 'status' => $authority],
                'trait_capsule' => $traitCapsule, 'promotion_evidence' => false];
            $payload['revision'] = $revision;
            $entry->update(['revision' => $revision, 'quality_score' => $mean, 'confidence' => $total ? round($positiveCount / $total, 4) : 0,
                // Ownership follows the latest independent verifier while the
                // immutable provenance list keeps every earlier source. This
                // lets the model that actually earns mentor status resolve
                // the now-confirmed capsule without copying a status label.
                'lab_agent_id' => $agent->id, 'model_version_id' => $agent->model_version_id,
                'lab_mutation_response_map_id' => $map->id, 'causal_baseline_agent_id' => $pair->control_agent_id,
                'status' => $componentStatus === 'component_confirmed' ? 'confirmed' : 'provisional', 'component_status' => $componentStatus, 'organism_viability' => $organism, 'evidence' => $payload]);
            if ($inserted && Schema::hasTable('skill_cartridge_revisions')) {
                DB::table('skill_cartridge_revisions')->insertOrIgnore(['lab_skill_zoo_entry_id' => $entry->id, 'revision' => $revision,
                    'revision_key' => hash('sha256', implode('|', [self::PROTOCOL, $entry->cartridge_key, $revision])), 'payload' => json_encode($payload),
                    'sealed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            }

            return ['protocol' => self::PROTOCOL, 'cartridge_id' => $entry->id, 'cartridge_key' => $key, 'status' => $entry->status, 'authority' => $authority, 'observation_inserted' => (bool) $inserted, 'promotion_evidence' => false];
        });
    }

    /** @return array<string,mixed> */
    public function retrieve(string $symbol, string $timeframe, string $family, array $context, array $candidateGenes = []): array
    {
        if (! $this->available()) {
            return ['status' => 'memory_abstained', 'reason' => 'CARTRIDGE_TABLES_UNAVAILABLE'];
        }
        $rows = LabSkillZooEntry::query()->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->where('strategy_family', $family)
            ->whereIn('status', ['provisional', 'confirmed'])->when($candidateGenes !== [], fn ($q) => $q->whereIn('gene_key', $candidateGenes))->get();
        $mismatches = [];
        $entry = $rows->first(function (LabSkillZooEntry $row) use ($context, &$mismatches): bool {
            $reason = $this->scopeMismatch((array) data_get($row->evidence, 'context', []), $context);
            if ($reason !== null) {
                $mismatches[] = $reason;
            }

            return $reason === null;
        });
        if (! $entry) {
            return ['status' => 'memory_abstained', 'reason' => $mismatches[0] ?? 'NO_COMPATIBLE_CARTRIDGE', 'promotion_evidence' => false];
        }

        return $this->proposal($entry);
    }

    /**
     * Resolve the executable intervention produced by the exact canonical
     * pair behind a lesson. A merely beneficial lesson is descriptive
     * evidence; it cannot drive a memory-guided child until the paired
     * settlement has also produced this cartridge.
     *
     * @return array<string,mixed>
     */
    public function retrieveForLesson(AgentLearningLesson $lesson): array
    {
        if (! $this->available()) {
            return ['status' => 'memory_abstained', 'reason' => 'CARTRIDGE_TABLES_UNAVAILABLE', 'promotion_evidence' => false];
        }
        $pairId = (int) data_get($lesson->evidence, 'pair_id', 0);
        if ($pairId <= 0) {
            return ['status' => 'memory_abstained', 'reason' => 'LESSON_CANONICAL_PAIR_MISSING', 'promotion_evidence' => false];
        }
        $entryIds = DB::table('skill_cartridge_observations')
            ->where('lab_learning_lane_pair_id', $pairId)
            ->where('outcome', 'positive')
            ->pluck('lab_skill_zoo_entry_id');
        $entry = LabSkillZooEntry::query()
            ->whereIn('id', $entryIds)
            ->where('symbol', strtoupper((string) $lesson->symbol))
            ->where('timeframe', strtoupper((string) $lesson->timeframe))
            ->where('strategy_family', (string) $lesson->strategy_family)
            ->where('gene_key', (string) $lesson->parameter_key)
            ->whereIn('status', ['provisional', 'confirmed'])
            ->latest('id')
            ->first();
        if (! $entry) {
            return ['status' => 'memory_abstained', 'reason' => 'LESSON_EXECUTABLE_CARTRIDGE_MISSING', 'promotion_evidence' => false];
        }
        $intervention = (array) data_get($entry->evidence, 'intervention', []);
        $old = data_get($lesson->evidence, 'old_value', data_get($lesson->evidence, 'failure_signature.old_value'));
        $new = data_get($lesson->evidence, 'new_value', data_get($lesson->evidence, 'failure_signature.new_value'));
        $old = is_array($old) && array_key_exists('value', $old) ? $old['value'] : $old;
        $new = is_array($new) && array_key_exists('value', $new) ? $new['value'] : $new;
        if (! $this->same($old, data_get($intervention, 'old_value'))
            || ! $this->same($new, data_get($intervention, 'tested_value'))) {
            return ['status' => 'memory_abstained', 'reason' => 'LESSON_CARTRIDGE_INTERVENTION_MISMATCH', 'promotion_evidence' => false];
        }

        return [...$this->proposal($entry), 'source_lesson_id' => (int) $lesson->id, 'source_pair_id' => $pairId];
    }

    /**
     * Prioritizes unresolved learning value while preserving one seat per
     * contextual niche before taking a second from the same niche.
     *
     * @return array<int, LabSkillZooEntry>
     */
    public function rankedForReplay(string $symbol, string $timeframe, int $limit = 1, array $context = []): array
    {
        $rows = LabSkillZooEntry::query()->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))
            ->where('status', 'confirmed')->get()->map(function (LabSkillZooEntry $entry) use ($context): LabSkillZooEntry {
                $entry->setAttribute('_cartridge_priority', $this->replayPriority($entry, $context));

                return $entry;
            })->groupBy(fn (LabSkillZooEntry $entry): string => (string) $entry->niche_key)
            ->map(fn ($niche) => $niche->sortByDesc(fn (LabSkillZooEntry $entry): float => (float) data_get($entry->getAttribute('_cartridge_priority'), 'score', 0))->values());
        $ranked = [];
        $cursor = 0;
        while (count($ranked) < max(1, $limit)) {
            $added = false;
            foreach ($rows as $niche) {
                if (! isset($niche[$cursor])) {
                    continue;
                }
                $ranked[] = $niche[$cursor];
                $added = true;
                if (count($ranked) >= $limit) {
                    break;
                }
            }
            if (! $added) {
                break;
            }
            $cursor++;
        }

        return $ranked;
    }

    /** @return array<string, float|string> */
    public function replayPriority(LabSkillZooEntry $entry, array $context = []): array
    {
        $payload = (array) $entry->evidence;
        $uncertainty = max(.01, 1 - (float) $entry->confidence);
        $informationGain = max(.05, $uncertainty * (1 + abs((float) data_get($payload, 'effect.mean_delta', 0))));
        $transfer = data_get($payload, 'authority.component_status') === 'component_confirmed' ? .9 : .35;
        $failureCost = 1 + count((array) data_get($payload, 'contraindications', []));
        $relevance = $this->scopeMismatch((array) data_get($payload, 'context', []), $context) === null ? 1. : .15;
        $compute = max(.1, (float) data_get($payload, 'estimated_compute_cost', 1));

        return ['protocol' => self::PROTOCOL, 'score' => round(($uncertainty * $informationGain * $transfer * $failureCost * $relevance) / $compute, 8),
            'uncertainty' => $uncertainty, 'expected_information_gain' => $informationGain, 'transfer_potential' => $transfer,
            'failure_recurrence_cost' => $failureCost, 'context_relevance' => $relevance, 'compute_cost' => $compute];
    }

    public function bindIntent(AgentLearningMutationIntent $intent, array $proposal): void
    {
        $intent->update(['metadata' => [...((array) $intent->metadata), 'skill_cartridge' => $proposal, 'promotion_evidence' => false]]);
    }

    /** @return array<string,mixed> */
    public function planTransplant(LabSkillZooEntry $cartridge, int $baselineModelId, array $context, bool $reverse = false): array
    {
        $intervention = (array) data_get($cartridge->evidence, 'intervention', []);
        $modes = $this->transplantModes($intervention['old_value'] ?? null, $intervention['tested_value'] ?? null, $reverse);
        foreach ($modes as $mode) {
            DB::table('skill_cartridge_transplant_trials')->updateOrInsert(['trial_key' => $this->trialKey($cartridge, $baselineModelId, $mode, $context)], [
                'lab_skill_zoo_entry_id' => $cartridge->id, 'baseline_model_version_id' => $baselineModelId, 'child_model_version_id' => null,
                'symbol' => $cartridge->symbol, 'timeframe' => $cartridge->timeframe, 'mode' => $mode, 'status' => 'planned',
                'context' => json_encode($context), 'evidence' => json_encode(['protocol' => self::PROTOCOL, 'exact_replication_required' => $mode === 'exact_replication', 'promotion_evidence' => false]), 'updated_at' => now(), 'created_at' => now(),
            ]);
        }

        return ['protocol' => self::PROTOCOL, 'status' => 'planned', 'cartridge_id' => $cartridge->id, 'modes' => $modes, 'promotion_evidence' => false];
    }

    /**
     * Create an executable, frozen-baseline transplant cohort.  The source
     * cartridge is deliberately never used as a parent and every child is
     * marked research-only; the cohort can only update component evidence.
     *
     * @return array<string,mixed>
     */
    public function materializeTransplant(LabSkillZooEntry $cartridge, int $baselineModelId, array $context = [], bool $includeReverse = true): array
    {
        if (! $this->available() || ! Schema::hasTable('skill_cartridge_transplant_trials')) {
            return ['status' => 'unavailable', 'promotion_evidence' => false];
        }
        $baseline = ModelVersion::query()->find($baselineModelId);
        $baselineAgent = LabAgent::query()->with('generation.laboratory')->where('model_version_id', $baselineModelId)->latest('id')->first();
        $intervention = (array) data_get($cartridge->evidence, 'intervention', []);
        $gene = (string) $cartridge->gene_key;
        $old = $intervention['old_value'] ?? null;
        $tested = $intervention['tested_value'] ?? null;
        if (! $baseline || ! $baselineAgent?->generation?->laboratory || $gene === '' || ! array_key_exists($gene, (array) $baseline->parameters)
            || json_encode(data_get($baseline->parameters, $gene)) !== json_encode($old)) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason' => 'FROZEN_CAUSAL_BASELINE_DOES_NOT_MATCH_CARTRIDGE', 'promotion_evidence' => false];
        }
        if ($tested === null || json_encode($old) === json_encode($tested)) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason' => 'NON_INTERVENTION_CARTRIDGE_CANNOT_BE_CONFIRMED', 'promotion_evidence' => false];
        }
        $dataHash = (string) data_get($cartridge->evidence, 'provenance.data_hashes.0');
        $executionHash = (string) data_get($cartridge->evidence, 'provenance.execution_hashes.0');
        if ($dataHash === '' || $executionHash === '') {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason' => 'CARTRIDGE_FROZEN_HASHES_MISSING', 'promotion_evidence' => false];
        }
        $canonicalDatasetSnapshots = (array) data_get($baselineAgent->generation?->trigger_context, 'canonical_dataset_snapshots', []);
        if (! is_array(data_get($canonicalDatasetSnapshots, 'price.manifest'))
            || ! is_array(data_get($canonicalDatasetSnapshots, 'foundation.manifest'))) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason' => 'CARTRIDGE_BASELINE_DATASET_SNAPSHOTS_MISSING', 'promotion_evidence' => false];
        }
        // Every near-confirmable cartridge, including categorical, boolean
        // and structural interventions, gets the same falsifiable five-arm
        // cohort.  Numeric-only local refinement is deliberately *not* a
        // prerequisite for confirmation; that former restriction stranded
        // regime/topology knowledge in perpetual provisional status.
        $modes = $this->transplantModes($old, $tested, $includeReverse);
        $retry = $this->reconcileRepairableTechnicalPreflightCohort($cartridge, $baseline->id);
        // A model insert can still fail after the prior cohort has been
        // terminalized. On the next invocation there is no longer an active
        // row to reconcile, but retry identity must remain distinct.
        if (! isset($retry['context'])) {
            $retry = $this->pendingRepairableRetryContext($cartridge, $baseline->id) ?? $retry;
        }
        if (data_get($retry, 'blocked', false)) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason' => data_get($retry, 'reason'), 'promotion_evidence' => false];
        }
        if (isset($retry['context'])) {
            // This must alter trial identity: a retry can never overwrite the
            // failed cohort's child IDs or technical evidence.
            $context = [...$context, ...$retry['context']];
        }
        $existing = DB::table('skill_cartridge_transplant_trials')->where('lab_skill_zoo_entry_id', $cartridge->id)->where('baseline_model_version_id', $baseline->id)
            ->whereIn('status', ['queued', 'running', 'settled_control', 'passed'])->exists();
        if ($existing) {
            return ['protocol' => self::PROTOCOL, 'status' => 'already_materialized', 'promotion_evidence' => false];
        }
        $base = (array) $baseline->parameters;
        $blindGene = collect($base)->keys()->first(fn ($key): bool => $key !== $gene && (is_numeric($base[$key]) || is_bool($base[$key])));
        if (! $blindGene) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason' => 'BLINDED_AUTONOMOUS_ARM_NOT_SAFE_TO_MATERIALIZE', 'promotion_evidence' => false];
        }

        $created = DB::transaction(function () use ($cartridge, $baseline, $baselineAgent, $context, $gene, $old, $tested, $dataHash, $executionHash, $modes, $base, $blindGene, $canonicalDatasetSnapshots): array {
            $lab = $baselineAgent->generation->laboratory;
            $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => ((int) $lab->generations()->max('generation')) + 1,
                'trigger_type' => 'skill_cartridge_transplant', 'trigger_context' => ['protocol' => self::PROTOCOL, 'cartridge_id' => $cartridge->id,
                    'cartridge_key' => $cartridge->cartridge_key, 'causal_baseline_model_version_id' => $baseline->id,
                    'genetic_parent_model_version_id' => null, 'data_hash' => $dataHash, 'execution_hash' => $executionHash,
                    'canonical_dataset_snapshots' => $canonicalDatasetSnapshots,
                    'transplant_retry' => array_intersect_key($context, array_flip(['transplant_retry_attempt', 'retry_of_generation_id', 'retry_reason'])),
                    'research_only' => true, 'promotion_evidence' => false],
                'data_fingerprint' => $dataHash, 'population_size' => CausalCompoundingKernelService::POPULATION_SIZE, 'status' => 'queued', 'started_at' => now()]);
            app(LearningProtocolEpochService::class)->openForNewGeneration($generation, $lab->symbol, $lab->timeframe);
            $agents = [];
            foreach ($modes as $index => $mode) {
                $parameters = $this->transplantParameters($mode, $base, $gene, $old, $tested, (string) $blindGene, $cartridge->strategy_family);
                if ($parameters === null) {
                    throw new \RuntimeException('SKILL_CARTRIDGE_ARM_CANNOT_MATERIALIZE: '.$mode);
                }
                $parameterDiff = $this->parameterDiff($base, $parameters);
                $confirmation = $this->confirmationContract($mode, $dataHash, $executionHash);
                $metadata = [...((array) $baseline->metadata), 'skill_cartridge_transplant' => ['protocol' => self::PROTOCOL, 'mode' => $mode,
                    'cartridge_id' => $cartridge->id, 'cartridge_key' => $cartridge->cartridge_key, 'causal_baseline_model_version_id' => $baseline->id,
                    'genetic_parent_model_version_id' => null, 'context' => $context, 'data_hash' => $dataHash, 'execution_hash' => $executionHash,
                    'confirmation_contract' => $confirmation, 'research_only' => true, 'promotion_evidence' => false],
                    'causal_baseline_model_version_id' => $baseline->id,
                    'genetic_parent_model_version_id' => null,
                    'mutation_constructor_invariant' => [
                        ...((array) data_get($baseline->metadata, 'mutation_constructor_invariant', [])),
                        'parameter_diff_count' => count($parameterDiff),
                    ],
                    'base_strategy' => data_get($baseline->metadata, 'base_strategy', $baseline->strategy)];
                // run-all keys its result map by strategy.  A distinct public
                // label is therefore an evidence requirement, not cosmetics:
                // five equal labels silently collapse five causal arms.
                $runtimeLabel = 'cartridge_'.$cartridge->id.'_g'.$generation->generation.'_a'.($index + 1);
                // ModelVersion names are globally unique. The same frozen
                // baseline may be retried after a repaired constructor, so
                // generation must be part of the human-readable identity too.
                $child = ModelVersion::create(['name' => $baseline->name.' cartridge '.$mode.' g'.$generation->generation, 'strategy' => $runtimeLabel,
                    'version' => $baseline->version.'-cartridge-'.$generation->generation.'-'.$mode, 'generation' => $generation->generation, 'status' => 'testing',
                    'description' => 'Canonical cartridge transplant; research-only.', 'change_log' => 'skill cartridge '.$mode,
                    'parameters' => $parameters, 'metadata' => $metadata, 'evidence_status' => 'valid']);
                $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $child->id,
                    // This is the causal control, never a newly inferred
                    // genetic parent.  Keeping the FK null prevents a passed
                    // transplant from contaminating ParentFoundry lineage.
                    'parent_a_model_version_id' => null, 'symbol' => $cartridge->symbol, 'timeframe' => $cartridge->timeframe,
                    'strategy_family' => $cartridge->strategy_family, 'origin' => 'skill_cartridge_transplant', 'lifecycle_status' => 'full_queued',
                    'parameter_diff' => $parameterDiff, 'decision_reason' => 'Frozen-baseline canonical cartridge '.$mode.'; research-only.']);
                DB::table('skill_cartridge_transplant_trials')->updateOrInsert(['trial_key' => $this->trialKey($cartridge, $baseline->id, $mode, $context)], [
                    'lab_skill_zoo_entry_id' => $cartridge->id, 'baseline_model_version_id' => $baseline->id, 'child_model_version_id' => $child->id,
                    'symbol' => $cartridge->symbol, 'timeframe' => $cartridge->timeframe, 'mode' => $mode, 'status' => 'queued', 'context' => json_encode($context),
                    'evidence' => json_encode(['protocol' => self::PROTOCOL, 'exact_replication_required' => in_array($mode, ['exact_replication', 'independent_exact_replication'], true), 'confirmation_contract' => $confirmation, 'data_hash' => $dataHash,
                        'execution_hash' => $executionHash, 'research_only' => true, 'promotion_evidence' => false]), 'updated_at' => now(), 'created_at' => now()]);
                $agents[] = $agent;
            }

            $kernel = $this->compoundingKernel->complete(
                $generation,
                $baseline,
                $baselineAgent,
                $dataHash,
                $executionHash,
                (string) data_get($cartridge->evidence, 'target', 'profit_factor'),
                [$gene],
            );

            return ['generation' => $generation, 'agents' => $agents, 'kernel' => $kernel];
        });
        foreach ($created['agents'] as $agent) {
            EvaluateLabAgentJob::dispatch($agent->id, $agent->symbol, 'full');
        }
        foreach ($created['kernel']['dispatches'] as $dispatch) {
            EvaluateLabAgentJob::dispatch(
                $dispatch['agent']->id,
                $dispatch['agent']->symbol,
                $dispatch['mode'],
            );
        }

        return ['protocol' => self::PROTOCOL, 'status' => 'queued', 'generation_id' => $created['generation']->id,
            'agent_ids' => collect([...$created['agents'], ...$created['kernel']['agents']])->pluck('id')->all(),
            'population_size' => CausalCompoundingKernelService::POPULATION_SIZE,
            'compounding_kernel' => $created['kernel']['contract'],
            'modes' => $modes, 'promotion_evidence' => false];
    }

    /** Record a cohort only after the frozen control and exact hashes are visible. */
    public function settleTransplantOutcome(LabAgent $agent): array
    {
        $agent->loadMissing('modelVersion', 'generation.agents.modelVersion');
        $contract = (array) data_get($agent->modelVersion?->metadata, 'skill_cartridge_transplant', []);
        if (data_get($contract, 'protocol') !== self::PROTOCOL) {
            return ['status' => 'not_transplant', 'promotion_evidence' => false];
        }
        $expectedData = (string) data_get($contract, 'data_hash');
        $expectedExecution = (string) data_get($contract, 'execution_hash');
        $control = $agent->generation?->agents->first(fn (LabAgent $row): bool => data_get($row->modelVersion?->metadata, 'skill_cartridge_transplant.mode') === 'frozen_baseline');
        $controlMetrics = $control?->modelVersion?->marketPerformances()->where('symbol', $agent->symbol)->where('timeframe', $agent->timeframe)->latest('id')->value('metrics');
        if (! $controlMetrics) {
            return ['protocol' => self::PROTOCOL, 'status' => 'awaiting_frozen_control', 'promotion_evidence' => false];
        }
        $settled = 0;
        $pending = 0;
        $passedExact = false;
        $passedIndependentExact = false;
        $negativeControlHeld = false;
        $blindedScore = null;
        $exactScore = null;
        foreach (($agent->generation?->agents ?? collect()) as $cohortAgent) {
            $mode = (string) data_get($cohortAgent->modelVersion?->metadata, 'skill_cartridge_transplant.mode');
            if ($mode === '') {
                continue;
            }
            $metrics = $cohortAgent->modelVersion?->marketPerformances()->where('symbol', $agent->symbol)->where('timeframe', $agent->timeframe)->latest('id')->value('metrics');
            if (! $metrics) {
                $pending++;

                continue;
            }
            $data = (string) data_get($metrics, 'data_manifest.sha256', data_get($metrics, 'data_hash'));
            $execution = (string) data_get($metrics, 'execution_contract.execution_hash', data_get($metrics, 'execution_hash'));
            $hashesMatch = $expectedData !== '' && $expectedExecution !== '' && hash_equals($expectedData, $data) && hash_equals($expectedExecution, $execution);
            $improved = (float) data_get($metrics, 'profit_factor', 0) > (float) data_get($controlMetrics, 'profit_factor', 0)
                && (float) data_get($metrics, 'max_drawdown_percent', data_get($metrics, 'max_drawdown', 100)) <= (float) data_get($controlMetrics, 'max_drawdown_percent', data_get($controlMetrics, 'max_drawdown', 100));
            $score = (float) data_get($metrics, 'profit_factor', 0);
            if ($mode === 'frozen_baseline') {
                $status = 'settled_control';
            } elseif ($mode === 'negative_control') {
                // The counterfactual should not reproduce the exact arm's
                // improvement. A strong reverse result invalidates direction
                // evidence rather than becoming a second positive vote.
                $status = $hashesMatch && ! $improved ? 'counterfactual_held' : 'counterfactual_failed';
                $negativeControlHeld = $status === 'counterfactual_held';
            } elseif ($mode === 'memory_blinded_autonomous') {
                $status = $hashesMatch && (int) data_get($metrics, 'total_trades', 0) > 0 ? 'blinded_settled' : 'failed';
                $blindedScore = $score;
            } else {
                $status = $hashesMatch && (int) data_get($metrics, 'total_trades', 0) > 0 && $improved ? 'passed' : 'failed';
            }
            DB::table('skill_cartridge_transplant_trials')->where('child_model_version_id', $cohortAgent->model_version_id)->update(['status' => $status,
                'evidence' => json_encode(['protocol' => self::PROTOCOL, 'mode' => $mode, 'hashes_match' => $hashesMatch, 'improved_vs_frozen_control' => $improved,
                    'promotion_evidence' => false]), 'settled_at' => now(), 'updated_at' => now()]);
            $settled++;
            $passedExact = $passedExact || ($mode === 'exact_replication' && $status === 'passed');
            $passedIndependentExact = $passedIndependentExact || ($mode === 'independent_exact_replication' && $status === 'passed');
            if ($mode === 'exact_replication') {
                $exactScore = $score;
            }
        }
        $cartridge = LabSkillZooEntry::find((int) data_get($contract, 'cartridge_id'));
        if ($cartridge) {
            $evidence = (array) $cartridge->evidence;
            $blindedDidNotBeatExact = $exactScore === null || $blindedScore === null || $exactScore >= $blindedScore;
            $strictConfirmation = ! $pending && $passedExact && $passedIndependentExact && $negativeControlHeld && $blindedDidNotBeatExact;
            $wasConfirmed = data_get($evidence, 'authority.component_status') === 'component_confirmed';
            // This may establish *component* evidence from a two-positive
            // provisional cartridge, but cannot confer mentor, breeder,
            // parent, paper or capital authority. Those require later
            // transplant/descendant proof through their own gates.
            if ($strictConfirmation && ! $wasConfirmed) {
                data_set($evidence, 'authority.component_status', 'component_confirmed');
                data_set($evidence, 'authority.status', 'stepping_stone_skill');
                data_set($evidence, 'authority.confirmed_by', 'independent_five_arm_research_only');
                $cartridge->update(['status' => 'confirmed', 'component_status' => 'component_confirmed']);
            }
            $mentorSeed = ! $pending && $wasConfirmed && $passedExact;
            data_set($evidence, 'authority.transplant_exact_replication_passed', $passedExact);
            data_set($evidence, 'authority.independent_exact_replication_passed', $passedIndependentExact);
            data_set($evidence, 'authority.counterfactual_held', $negativeControlHeld);
            data_set($evidence, 'authority.blinded_did_not_beat_exact', $blindedDidNotBeatExact);
            data_set($evidence, 'authority.mentor_seed', $mentorSeed);
            data_set($evidence, 'authority.breeder_eligible', false);
            if ($mentorSeed) {
                data_set($evidence, 'authority.status', 'mentor_seed');
            }
            if (! $pending && ! $passedExact) {
                $contraindications = (array) data_get($evidence, 'contraindications', []);
                $contraindications[] = ['context' => (array) data_get($contract, 'context', []), 'reason' => 'transplant_exact_replication_failed'];
                data_set($evidence, 'contraindications', $contraindications);
            }
            data_set($evidence, 'transplant_settlement', ['protocol' => self::PROTOCOL, 'settled_arms' => $settled, 'pending_arms' => $pending,
                'exact_replication_passed' => $passedExact, 'independent_exact_replication_passed' => $passedIndependentExact,
                'counterfactual_held' => $negativeControlHeld, 'blinded_did_not_beat_exact' => $blindedDidNotBeatExact,
                'component_confirmation' => $strictConfirmation, 'terminal_reason' => ! $pending && ! $strictConfirmation ? 'INDEPENDENT_FIVE_ARM_CONFIRMATION_FAILED' : null, 'promotion_evidence' => false]);
            $cartridge->update(['evidence' => $evidence]);
        }

        return ['protocol' => self::PROTOCOL, 'status' => $pending ? 'partially_settled' : 'settled', 'settled_arms' => $settled, 'pending_arms' => $pending,
            'exact_replication_passed' => $passedExact, 'independent_exact_replication_passed' => $passedIndependentExact, 'promotion_evidence' => false];
    }

    /** @return array<string,mixed> */
    public function recordInteraction(LabSkillZooEntry $a, LabSkillZooEntry $b, array $evidence): array
    {
        $key = hash('sha256', implode('|', [self::PROTOCOL, min($a->id, $b->id), max($a->id, $b->id), $a->symbol, $a->timeframe]));
        $status = (string) ($evidence['status'] ?? 'planned');
        DB::table('skill_cartridge_interactions')->updateOrInsert(['interaction_key' => $key], ['skill_a_id' => $a->id, 'skill_b_id' => $b->id,
            'symbol' => $a->symbol, 'timeframe' => $a->timeframe, 'status' => $status, 'evidence' => json_encode(['protocol' => self::PROTOCOL,
                'required_arms' => ['control', 'a', 'b', 'a_plus_b', 'a_plus_b_minus_filter'], ...$evidence, 'promotion_evidence' => false]),
            'settled_at' => in_array($status, ['confirmed', 'antagonistic', 'synergistic'], true) ? now() : null, 'updated_at' => now(), 'created_at' => now()]);

        return ['protocol' => self::PROTOCOL, 'status' => $status, 'interaction_key' => $key, 'promotion_evidence' => false];
    }

    /** The five-arm interaction test has a common frozen baseline and is research-only. */
    public function materializeInteraction(LabSkillZooEntry $a, LabSkillZooEntry $b, int $baselineModelId, array $context = []): array
    {
        if (! $this->available() || ! Schema::hasTable('skill_cartridge_interactions') || $a->id === $b->id) {
            return ['status' => 'blocked', 'reason' => 'INTERACTION_COMPONENTS_INVALID', 'promotion_evidence' => false];
        }
        $baseline = ModelVersion::find($baselineModelId);
        $baselineAgent = LabAgent::query()->with('generation.laboratory')->where('model_version_id', $baselineModelId)->latest('id')->first();
        $ai = (array) data_get($a->evidence, 'intervention', []);
        $bi = (array) data_get($b->evidence, 'intervention', []);
        $aGene = (string) $a->gene_key;
        $bGene = (string) $b->gene_key;
        $base = (array) $baseline?->parameters;
        $aData = (string) data_get($a->evidence, 'provenance.data_hashes.0');
        $bData = (string) data_get($b->evidence, 'provenance.data_hashes.0');
        $aExec = (string) data_get($a->evidence, 'provenance.execution_hashes.0');
        $bExec = (string) data_get($b->evidence, 'provenance.execution_hashes.0');
        if (! $baseline || ! $baselineAgent?->generation?->laboratory || $aGene === '' || $bGene === '' || $aGene === $bGene
            || ! array_key_exists($aGene, $base) || ! array_key_exists($bGene, $base)
            || json_encode($base[$aGene]) !== json_encode($ai['old_value'] ?? null) || json_encode($base[$bGene]) !== json_encode($bi['old_value'] ?? null)
            || $aData === '' || $aExec === '' || ! hash_equals($aData, $bData) || ! hash_equals($aExec, $bExec)) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason' => 'COMMON_FROZEN_BASELINE_OR_HASH_CONTRACT_MISSING', 'promotion_evidence' => false];
        }
        $filterGene = collect($base)->keys()->first(fn ($key): bool => ! in_array($key, [$aGene, $bGene], true) && (is_numeric($base[$key]) || is_bool($base[$key])));
        if (! $filterGene) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason' => 'INTERACTION_FILTER_COUNTERFACTUAL_UNAVAILABLE', 'promotion_evidence' => false];
        }
        $key = $this->interactionKey($a, $b);
        $existing = DB::table('skill_cartridge_interactions')->where('interaction_key', $key)->whereIn('status', ['queued', 'running', 'confirmed', 'synergistic', 'antagonistic'])->exists();
        if ($existing) {
            return ['protocol' => self::PROTOCOL, 'status' => 'already_materialized', 'promotion_evidence' => false];
        }
        $arms = ['control', 'a', 'b', 'a_plus_b', 'a_plus_b_minus_filter'];
        $created = DB::transaction(function () use ($a, $b, $baseline, $baselineAgent, $aGene, $bGene, $ai, $bi, $aData, $aExec, $base, $filterGene, $key, $arms): array {
            $lab = $baselineAgent->generation->laboratory;
            $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => ((int) $lab->generations()->max('generation')) + 1,
                'trigger_type' => 'skill_cartridge_interaction', 'trigger_context' => ['protocol' => self::PROTOCOL, 'interaction_key' => $key,
                    'skill_a_id' => $a->id, 'skill_b_id' => $b->id, 'causal_baseline_model_version_id' => $baseline->id, 'data_hash' => $aData,
                    'execution_hash' => $aExec,
                    'canonical_dataset_snapshots' => data_get($baselineAgent->generation?->trigger_context, 'canonical_dataset_snapshots'),
                    'research_only' => true, 'promotion_evidence' => false], 'data_fingerprint' => $aData,
                'population_size' => CausalCompoundingKernelService::POPULATION_SIZE, 'status' => 'queued', 'started_at' => now()]);
            app(LearningProtocolEpochService::class)->openForNewGeneration($generation, $lab->symbol, $lab->timeframe);
            $agents = [];
            $armModels = [];
            foreach ($arms as $arm) {
                $parameters = $this->interactionParameters($arm, $base, $aGene, $ai['tested_value'], $bGene, $bi['tested_value'], (string) $filterGene);
                $metadata = [...((array) $baseline->metadata),
                    'causal_baseline_model_version_id' => $baseline->id,
                    'genetic_parent_model_version_id' => null,
                    'base_strategy' => data_get($baseline->metadata, 'base_strategy', $baseline->strategy),
                    'skill_cartridge_interaction' => ['protocol' => self::PROTOCOL, 'arm' => $arm,
                        'interaction_key' => $key, 'skill_a_id' => $a->id, 'skill_b_id' => $b->id, 'causal_baseline_model_version_id' => $baseline->id,
                        'data_hash' => $aData, 'execution_hash' => $aExec, 'research_only' => true, 'promotion_evidence' => false]];
                $runtimeLabel = 'cartridge_interaction_'.$key.'_g'.$generation->generation.'_a'.(count($agents) + 1);
                $child = ModelVersion::create(['name' => $baseline->name.' interaction '.$arm, 'strategy' => $runtimeLabel,
                    'version' => $baseline->version.'-interaction-'.$generation->generation.'-'.$arm, 'generation' => $generation->generation, 'status' => 'testing',
                    'description' => 'Canonical cartridge interaction arm; research-only.', 'change_log' => 'cartridge interaction '.$arm,
                    'parameters' => $parameters, 'metadata' => $metadata, 'evidence_status' => 'valid']);
                $agents[] = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $child->id, 'parent_a_model_version_id' => null,
                    'symbol' => $a->symbol, 'timeframe' => $a->timeframe, 'strategy_family' => $a->strategy_family, 'origin' => 'skill_cartridge_interaction',
                    'lifecycle_status' => 'full_queued', 'parameter_diff' => $this->parameterDiff($base, $parameters), 'decision_reason' => 'Five-arm cartridge interaction '.$arm.'; research-only.']);
                $armModels[$arm] = $child->id;
            }
            $kernel = $this->compoundingKernel->complete(
                $generation,
                $baseline,
                $baselineAgent,
                $aData,
                $aExec,
                'profit_factor',
                [$aGene, $bGene, (string) $filterGene],
            );
            DB::table('skill_cartridge_interactions')->updateOrInsert(['interaction_key' => $key], ['skill_a_id' => $a->id, 'skill_b_id' => $b->id,
                'symbol' => $a->symbol, 'timeframe' => $a->timeframe, 'status' => 'queued', 'evidence' => json_encode(['protocol' => self::PROTOCOL,
                    'required_arms' => $arms, 'generation_id' => $generation->id, 'baseline_model_version_id' => $baseline->id, 'arm_model_version_ids' => $armModels,
                    'data_hash' => $aData, 'execution_hash' => $aExec, 'promotion_evidence' => false]), 'updated_at' => now(), 'created_at' => now()]);

            return ['generation' => $generation, 'agents' => $agents, 'kernel' => $kernel];
        });
        foreach ($created['agents'] as $agent) {
            EvaluateLabAgentJob::dispatch($agent->id, $agent->symbol, 'full');
        }
        foreach ($created['kernel']['dispatches'] as $dispatch) {
            EvaluateLabAgentJob::dispatch($dispatch['agent']->id, $dispatch['agent']->symbol, $dispatch['mode']);
        }

        return ['protocol' => self::PROTOCOL, 'status' => 'queued', 'generation_id' => $created['generation']->id,
            'agent_ids' => collect([...$created['agents'], ...$created['kernel']['agents']])->pluck('id')->all(),
            'population_size' => CausalCompoundingKernelService::POPULATION_SIZE,
            'compounding_kernel' => $created['kernel']['contract'], 'promotion_evidence' => false];
    }

    /** Recompute an interaction as arms arrive; incomplete data remains explicitly pending. */
    public function settleInteractionOutcome(LabAgent $agent): array
    {
        $agent->loadMissing('modelVersion', 'generation.agents.modelVersion');
        $contract = (array) data_get($agent->modelVersion?->metadata, 'skill_cartridge_interaction', []);
        if (data_get($contract, 'protocol') !== self::PROTOCOL) {
            return ['status' => 'not_interaction', 'promotion_evidence' => false];
        }
        $row = DB::table('skill_cartridge_interactions')->where('interaction_key', data_get($contract, 'interaction_key'))->first();
        if (! $row) {
            return ['status' => 'blocked', 'reason' => 'INTERACTION_LEDGER_MISSING', 'promotion_evidence' => false];
        }
        $evidence = json_decode((string) $row->evidence, true) ?: [];
        $metrics = [];
        foreach (($agent->generation?->agents ?? collect()) as $cohortAgent) {
            $arm = (string) data_get($cohortAgent->modelVersion?->metadata, 'skill_cartridge_interaction.arm');
            $outcome = $cohortAgent->modelVersion?->marketPerformances()->where('symbol', $agent->symbol)->where('timeframe', $agent->timeframe)->latest('id')->value('metrics');
            if ($arm !== '' && $outcome) {
                $metrics[$arm] = $outcome;
            }
        }
        $required = (array) data_get($evidence, 'required_arms', []);
        if (array_diff($required, array_keys($metrics)) !== []) {
            data_set($evidence, 'settlement', ['status' => 'awaiting_arms', 'observed_arms' => array_keys($metrics), 'promotion_evidence' => false]);
            DB::table('skill_cartridge_interactions')->where('id', $row->id)->update(['status' => 'running', 'evidence' => json_encode($evidence), 'updated_at' => now()]);

            return ['protocol' => self::PROTOCOL, 'status' => 'awaiting_arms', 'promotion_evidence' => false];
        }
        $hashesMatch = collect($metrics)->every(fn ($m): bool => hash_equals((string) data_get($contract, 'data_hash'), (string) data_get($m, 'data_manifest.sha256', data_get($m, 'data_hash')))
            && hash_equals((string) data_get($contract, 'execution_hash'), (string) data_get($m, 'execution_contract.execution_hash', data_get($m, 'execution_hash'))));
        $pf = fn (string $arm): float => (float) data_get($metrics[$arm], 'profit_factor', 0);
        $control = $pf('control');
        $aDelta = $pf('a') - $control;
        $bDelta = $pf('b') - $control;
        $abDelta = $pf('a_plus_b') - $control;
        // Proper factorial interaction: (A+B) - A - B + C. Comparing AB to
        // only max(A,B) labels sub-additive combinations as synergy.
        $interaction = $this->factorialInteraction($control, $pf('a'), $pf('b'), $pf('a_plus_b'));
        $interactionDelta = $interaction['interaction_delta'];
        $robustnessPerturbationDelta = $pf('a_plus_b_minus_filter') - $pf('a_plus_b');
        $status = ! $hashesMatch
            ? 'invalid_hash_mismatch'
            : $interaction['status'];
        data_set($evidence, 'settlement', ['status' => $status, 'hashes_match' => $hashesMatch,
            'factorial_formula' => '(A+B)-A-B+C',
            'deltas' => compact('aDelta', 'bDelta', 'abDelta', 'interactionDelta', 'robustnessPerturbationDelta'),
            'fifth_arm_is_robustness_perturbation_not_interaction_credit' => true,
            'promotion_evidence' => false]);
        DB::table('skill_cartridge_interactions')->where('id', $row->id)->update(['status' => $status, 'evidence' => json_encode($evidence), 'settled_at' => now(), 'updated_at' => now()]);

        return ['protocol' => self::PROTOCOL, 'status' => $status, 'promotion_evidence' => false];
    }

    /** @return array<string,mixed> */
    public function reconcileLegacy(string $symbol, string $timeframe): array
    {
        if (! $this->available()) {
            return ['available' => false];
        }
        $rows = LabSkillZooEntry::query()->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->where('status', 'observed')->get();
        foreach ($rows as $row) {
            $row->update(['status' => 'retired_no_intervention', 'component_status' => 'legacy_unresolved',
                'evidence' => [...((array) $row->evidence), 'canonical_reconciliation' => ['protocol' => self::PROTOCOL, 'status' => 'retired_no_executable_intervention', 'reason' => 'MISSING_SETTLED_PAIRED_INTERVENTION', 'promotion_evidence' => false]]]);
        }

        return ['protocol' => self::PROTOCOL, 'available' => true, 'terminalized_observed' => $rows->count(), 'promotion_evidence' => false];
    }

    /** Rebuild the exactly-once next action for pre-existing provisional rows. */
    public function reconcileProvisionalConfirmationState(string $symbol, string $timeframe): array
    {
        if (! $this->available()) {
            return ['available' => false, 'promotion_evidence' => false];
        }
        $changed = 0;
        LabSkillZooEntry::query()->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))
            ->where('status', 'provisional')->each(function (LabSkillZooEntry $entry) use (&$changed): void {
                $observations = DB::table('skill_cartridge_observations')->where('lab_skill_zoo_entry_id', $entry->id);
                $positive = (clone $observations)->where('outcome', 'positive')->count();
                $negative = (clone $observations)->where('outcome', 'negative')->count();
                $state = $positive >= 2 && $negative === 0 ? 'awaiting_third_independent_replication' : 'collect_paired_observations';
                $next = $state === 'awaiting_third_independent_replication'
                    ? 'dispatch_gene_type_aware_five_arm_confirmation' : 'settle_next_canonical_pair';
                if (data_get($entry->evidence, 'confirmation.state') === $state
                    && data_get($entry->evidence, 'confirmation.next_required_action') === $next) {
                    return;
                }
                $evidence = (array) $entry->evidence;
                data_set($evidence, 'confirmation', ['state' => $state, 'next_required_action' => $next,
                    'positive_observations' => $positive, 'negative_observations' => $negative,
                    'independent_confirmation_required' => true, 'reconciled_at' => now()->utc()->toIso8601String(), 'promotion_evidence' => false]);
                $entry->update(['evidence' => $evidence]);
                $changed++;
            });

        return ['protocol' => self::PROTOCOL, 'available' => true, 'reconciled_provisional_states' => $changed, 'promotion_evidence' => false];
    }

    /** Seal one immutable snapshot for cartridges that existed before revisions were introduced. */
    public function backfillImmutableRevisions(string $symbol, string $timeframe): array
    {
        if (! $this->available() || ! Schema::hasTable('skill_cartridge_revisions')) {
            return ['protocol' => self::PROTOCOL, 'status' => 'unavailable', 'promotion_evidence' => false];
        }
        $sealed = 0;
        LabSkillZooEntry::query()->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->whereNotNull('cartridge_key')
            ->orderBy('id')->each(function (LabSkillZooEntry $entry) use (&$sealed): void {
                $revision = max(1, (int) $entry->revision);
                $payload = [...(array) $entry->evidence, 'revision' => $revision, 'backfilled_immutable_snapshot' => true];
                $inserted = DB::table('skill_cartridge_revisions')->insertOrIgnore(['lab_skill_zoo_entry_id' => $entry->id, 'revision' => $revision,
                    'revision_key' => hash('sha256', implode('|', [self::PROTOCOL, $entry->cartridge_key, $revision])), 'payload' => json_encode($payload),
                    'sealed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                $sealed += (int) $inserted;
            });

        return ['protocol' => self::PROTOCOL, 'status' => 'completed', 'immutable_revisions_sealed' => $sealed, 'promotion_evidence' => false];
    }

    private function available(): bool
    {
        return Schema::hasTable('skill_cartridge_observations') && Schema::hasTable('lab_skill_zoo_entries');
    }

    /**
     * Resolve the exact confirmed cartridge owned by a mentor.  Returning an
     * assessment instead of trusting model metadata prevents a copied
     * `skill_mentor.status` label from manufacturing inheritance authority.
     *
     * @return array<string,mixed>
     */
    public function traitCapsuleForMentor(ModelVersion $model, ?LabAgent $agent, string $gene, array $context = []): array
    {
        if (! $this->available() || $gene === '') {
            return ['status' => 'invalid', 'valid' => false, 'reason_codes' => ['capsule_registry_unavailable'], 'promotion_evidence' => false];
        }
        $entry = LabSkillZooEntry::query()
            ->where('status', 'confirmed')
            ->where('component_status', 'component_confirmed')
            ->where('gene_key', $gene)
            ->where(function ($query) use ($model, $agent): void {
                $query->where('model_version_id', $model->id);
                if ($agent) {
                    $query->orWhere('lab_agent_id', $agent->id);
                }
            })
            ->latest('revision')
            ->latest('id')
            ->first();
        if (! $entry) {
            return ['status' => 'invalid', 'valid' => false, 'reason_codes' => ['confirmed_capsule_missing'], 'promotion_evidence' => false];
        }
        $capsule = (array) data_get($entry->evidence, 'trait_capsule', []);
        $assessment = app(ContextualCausalTraitCapsuleService::class)->assess($capsule, $gene, $context);

        return [
            ...$assessment,
            'cartridge_id' => (int) $entry->id,
            'cartridge_key' => (string) $entry->cartridge_key,
            'cartridge_revision' => (int) $entry->revision,
            'capsule' => $capsule,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function proposal(LabSkillZooEntry $entry): array
    {
        $payload = (array) $entry->evidence;
        $intervention = (array) data_get($payload, 'intervention', []);

        return ['status' => 'compatible_cartridge_found', 'cartridge_id' => $entry->id, 'cartridge_key' => $entry->cartridge_key, 'mode' => 'exact_replication',
            'gene' => $entry->gene_key, 'old_value' => $intervention['old_value'] ?? null, 'proposed_value' => $intervention['tested_value'] ?? null,
            'direction' => $intervention['direction'] ?? null, 'expected_effect' => data_get($payload, 'effect'),
            'scope_match' => ['strategy_family' => 'exact', 'regime' => 'exact', 'volatility' => 'compatible', 'session' => 'exact', 'execution_contract' => 'exact'],
            'uncertainty' => $entry->confidence >= .7 ? 'low' : 'medium', 'contraindications' => data_get($payload, 'contraindications', []),
            'trait_capsule' => data_get($payload, 'trait_capsule'), 'promotion_evidence' => false];
    }

    private function same(mixed $left, mixed $right): bool
    {
        if (is_numeric($left) && is_numeric($right)) {
            return abs((float) $left - (float) $right) < 0.000000001;
        }

        return json_encode($left, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)
            === json_encode($right, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** A pair is resolved by either an executable observation or an explicit terminal abstention. */
    public function projectionResolvedForPair(int $pairId): bool
    {
        if (! $this->available()) {
            return false;
        }
        if (DB::table('skill_cartridge_observations')->where('lab_learning_lane_pair_id', $pairId)->exists()) {
            return true;
        }

        return in_array((string) data_get(LabLearningLanePair::find($pairId)?->metadata, 'canonical_skill_cartridge_projection.status'), [
            'terminal_no_executable_intervention', 'terminal_scope_rejected',
        ], true);
    }

    private function trialKey(LabSkillZooEntry $cartridge, int $baselineModelId, string $mode, array $context): string
    {
        return hash('sha256', implode('|', [self::PROTOCOL, $cartridge->id, $baselineModelId, $mode, json_encode($context)]));
    }

    /**
     * Reconcile just one fully terminal known-constructor failure into a new
     * immutable retry identity. Everything else stays fail-closed.
     *
     * @return array{context?:array<string,mixed>,blocked?:bool,reason?:string}
     */
    private function reconcileRepairableTechnicalPreflightCohort(LabSkillZooEntry $cartridge, int $baselineModelId): array
    {
        $active = DB::table('skill_cartridge_transplant_trials')
            ->where('lab_skill_zoo_entry_id', $cartridge->id)->where('baseline_model_version_id', $baselineModelId)
            ->whereIn('status', ['queued', 'running', 'settled_control'])->get();
        if ($active->isEmpty()) {
            return [];
        }

        $childIds = $active->pluck('child_model_version_id')->filter()->map(fn ($id): int => (int) $id)->values();
        if ($childIds->count() !== $active->count()) {
            return [];
        }
        $agents = LabAgent::query()->with(['generation', 'modelVersion'])->whereIn('model_version_id', $childIds)->get()->keyBy('model_version_id');
        if ($agents->count() !== $childIds->count()) {
            return [];
        }
        $generationIds = $agents->pluck('lab_generation_id')->unique()->values();
        if ($generationIds->count() !== 1) {
            return [];
        }
        $generationId = (int) $generationIds->first();
        $allErrors = [];
        foreach ($childIds as $childId) {
            /** @var LabAgent|null $agent */
            $agent = $agents->get($childId);
            if (! $agent || $agent->origin !== 'skill_cartridge_transplant' || $agent->lifecycle_status !== 'technical_quarantine'
                || $agent->generation?->status !== 'technical_quarantine') {
                return [];
            }
            $errors = array_values(array_filter((array) data_get($agent->modelVersion?->metadata, 'preflight_quarantine.errors', []), 'is_string'));
            if ($errors === []) {
                $message = (string) LabEvaluationRun::query()->where('lab_agent_id', $agent->id)->latest('id')->value('error_message');
                $errors = array_values(array_filter(self::REPAIRABLE_TRANSPLANT_PREFLIGHT_ERRORS, fn (string $code): bool => str_contains($message, $code)));
            }
            if ($errors === [] || array_diff($errors, self::REPAIRABLE_TRANSPLANT_PREFLIGHT_ERRORS) !== []) {
                return [];
            }
            $allErrors = [...$allErrors, ...$errors];
        }

        $previousAttempts = DB::table('skill_cartridge_transplant_trials')->where('lab_skill_zoo_entry_id', $cartridge->id)
            ->where('baseline_model_version_id', $baselineModelId)->get()->map(function ($row): int {
                $context = json_decode((string) $row->context, true);

                return (int) data_get(is_array($context) ? $context : [], 'transplant_retry_attempt', 0);
            })->max() ?? 0;
        if ($previousAttempts >= 1) {
            return ['blocked' => true, 'reason' => 'CARTRIDGE_TRANSPLANT_RETRY_BUDGET_EXHAUSTED'];
        }

        $errorCodes = array_values(array_unique($allErrors));
        foreach ($active as $trial) {
            $evidence = json_decode((string) $trial->evidence, true);
            $evidence = is_array($evidence) ? $evidence : [];
            $evidence['technical_preflight_closure'] = ['protocol' => self::PROTOCOL,
                'status' => 'repairable_constructor_failure_terminalized', 'generation_id' => $generationId,
                'error_codes' => $errorCodes, 'retry_budget' => 1, 'closed_at' => now()->utc()->toIso8601String()];
            DB::table('skill_cartridge_transplant_trials')->where('id', $trial->id)->update([
                'status' => 'technical_preflight_repaired', 'evidence' => json_encode($evidence),
                'settled_at' => now(), 'updated_at' => now(),
            ]);
        }

        return ['context' => ['transplant_retry_attempt' => $previousAttempts + 1, 'retry_of_generation_id' => $generationId,
            'retry_reason' => 'REPAIRED_CANONICAL_TRANSPLANT_PREFLIGHT_CONTRACT']];
    }

    /**
     * Read-only admission preview used by the director. A queued trial is
     * normally active work; this narrow exception is only for the terminal
     * repair case that materializeTransplant will reconcile atomically.
     */
    public function canRetryRepairableTechnicalPreflightCohort(LabSkillZooEntry $cartridge, int $baselineModelId): bool
    {
        $active = DB::table('skill_cartridge_transplant_trials')
            ->where('lab_skill_zoo_entry_id', $cartridge->id)->where('baseline_model_version_id', $baselineModelId)
            ->whereIn('status', ['queued', 'running', 'settled_control'])->get();
        if ($active->isEmpty()) {
            return false;
        }
        $childIds = $active->pluck('child_model_version_id')->filter()->map(fn ($id): int => (int) $id)->values();
        if ($childIds->count() !== $active->count()) {
            return false;
        }
        $agents = LabAgent::query()->with(['generation', 'modelVersion'])->whereIn('model_version_id', $childIds)->get()->keyBy('model_version_id');
        if ($agents->count() !== $childIds->count() || $agents->pluck('lab_generation_id')->unique()->count() !== 1) {
            return false;
        }
        foreach ($childIds as $childId) {
            /** @var LabAgent|null $agent */
            $agent = $agents->get($childId);
            if (! $agent || $agent->origin !== 'skill_cartridge_transplant' || $agent->lifecycle_status !== 'technical_quarantine'
                || $agent->generation?->status !== 'technical_quarantine') {
                return false;
            }
            $errors = array_values(array_filter((array) data_get($agent->modelVersion?->metadata, 'preflight_quarantine.errors', []), 'is_string'));
            if ($errors === []) {
                $message = (string) LabEvaluationRun::query()->where('lab_agent_id', $agent->id)->latest('id')->value('error_message');
                $errors = array_values(array_filter(self::REPAIRABLE_TRANSPLANT_PREFLIGHT_ERRORS, fn (string $code): bool => str_contains($message, $code)));
            }
            if ($errors === [] || array_diff($errors, self::REPAIRABLE_TRANSPLANT_PREFLIGHT_ERRORS) !== []) {
                return false;
            }
        }
        $attempts = DB::table('skill_cartridge_transplant_trials')->where('lab_skill_zoo_entry_id', $cartridge->id)
            ->where('baseline_model_version_id', $baselineModelId)->get()->map(function ($row): int {
                $context = json_decode((string) $row->context, true);

                return (int) data_get(is_array($context) ? $context : [], 'transplant_retry_attempt', 0);
            })->max() ?? 0;

        return $attempts < 1;
    }

    /** @return array{context:array<string,mixed>}|null */
    private function pendingRepairableRetryContext(LabSkillZooEntry $cartridge, int $baselineModelId): ?array
    {
        $rows = DB::table('skill_cartridge_transplant_trials')->where('lab_skill_zoo_entry_id', $cartridge->id)
            ->where('baseline_model_version_id', $baselineModelId)->where('status', 'technical_preflight_repaired')->get();
        if ($rows->isEmpty()) {
            return null;
        }
        $attempts = $rows->map(function ($row): int {
            $context = json_decode((string) $row->context, true);

            return (int) data_get(is_array($context) ? $context : [], 'transplant_retry_attempt', 0);
        })->max() ?? 0;
        if ($attempts >= 1) {
            return null;
        }
        $closures = $rows->map(function ($row): array {
            $evidence = json_decode((string) $row->evidence, true);

            return is_array($evidence) ? (array) data_get($evidence, 'technical_preflight_closure', []) : [];
        });
        $generationIds = $closures->pluck('generation_id')->filter()->unique()->values();
        $errors = $closures->flatMap(fn (array $closure): array => (array) data_get($closure, 'error_codes', []))->filter('is_string')->unique()->values()->all();
        if ($generationIds->count() !== 1 || $errors === [] || array_diff($errors, self::REPAIRABLE_TRANSPLANT_PREFLIGHT_ERRORS) !== []) {
            return null;
        }

        return ['context' => ['transplant_retry_attempt' => 1, 'retry_of_generation_id' => (int) $generationIds->first(),
            'retry_reason' => 'REPAIRED_CANONICAL_TRANSPLANT_PREFLIGHT_CONTRACT']];
    }

    private function interactionKey(LabSkillZooEntry $a, LabSkillZooEntry $b): string
    {
        return hash('sha256', implode('|', [self::PROTOCOL, min($a->id, $b->id), max($a->id, $b->id), $a->symbol, $a->timeframe]));
    }

    /** @return array<int,string> */
    private function transplantModes(mixed $old, mixed $tested, bool $includeNegative): array
    {
        $modes = ['frozen_baseline', 'exact_replication', 'independent_exact_replication'];
        if ($includeNegative && json_encode($old) !== json_encode($tested)) {
            $modes[] = 'negative_control';
        }
        $modes[] = 'memory_blinded_autonomous';

        return $modes;
    }

    /**
     * Contracts use one 9-fold universe. Exact replication is evaluated on
     * the first disjoint third and its independent replicate on the second;
     * frozen control spans the universe so every treatment has an immutable,
     * matching control slice. No contract is promotion evidence.
     *
     * @return array<string,mixed>
     */
    private function confirmationContract(string $mode, string $dataHash, string $executionHash): array
    {
        $offset = $mode === 'independent_exact_replication' ? 3 : 0;
        $folds = $mode === 'frozen_baseline' ? 9 : 3;

        return ['protocol' => 'bounded_skill_cartridge_confirmation_v1', 'mode' => $mode,
            'admitted' => true, 'maximum_holding_bars' => 240, 'purge_bars' => 240, 'embargo_bars' => 1,
            'fold_count' => $folds, 'fold_offset' => $offset, 'fold_universe_count' => 9,
            'window_stage' => $mode === 'independent_exact_replication' ? 'disjoint_exact_replication' : 'frozen_confirmation',
            'data_hash' => $dataHash, 'execution_hash' => $executionHash, 'max_rows_per_fold' => 4096,
            'audit_trace_rows' => 512, 'minimum_trades_per_window' => 8,
            'per_fold_budget_seconds' => self::PER_FOLD_BUDGET_SECONDS, 'promotion_evidence' => false];
    }

    private function transplantParameters(string $mode, array $base, string $gene, mixed $old, mixed $tested, string $blindGene, ?string $family = null): ?array
    {
        $parameters = $base;
        if ($mode === 'frozen_baseline') {
            return $parameters;
        }
        if (in_array($mode, ['exact_replication', 'independent_exact_replication'], true)) {
            $parameters[$gene] = $tested;

            return $parameters;
        }
        if ($mode === 'memory_blinded_autonomous') {
            $value = $parameters[$blindGene];
            $parameters[$blindGene] = is_bool($value) ? ! $value : round((float) $value * 1.02, 8);

            return $parameters;
        }
        if ($mode === 'negative_control') {
            if (is_numeric($old) && is_numeric($tested)) {
                $parameters[$gene] = round((float) $old - ((float) $tested - (float) $old), 8);

                return $parameters;
            }
            if (is_bool($old) && is_bool($tested)) {
                $parameters[$gene] = ! $tested;

                return $parameters;
            }
            $alternative = $this->alternativeCategoricalValue($gene, $old, $tested, $family);
            // A categorical negative control must be a real alternative, not
            // a duplicate of frozen control masquerading as an intervention.
            if ($alternative !== null) {
                $parameters[$gene] = $alternative;

                return $parameters;
            }
        }

        return null;
    }

    private function alternativeCategoricalValue(string $gene, mixed $old, mixed $tested, ?string $family): mixed
    {
        $schema = app(StrategyParameterSchemaService::class)->schema((string) $family);
        $choices = (array) data_get($schema, $gene.'.1', []);
        foreach ($choices as $choice) {
            if (json_encode($choice) !== json_encode($old) && json_encode($choice) !== json_encode($tested)) {
                return $choice;
            }
        }

        // For a binary categorical grammar the old value is the only valid
        // reverse intervention. It is still explicitly marked negative, not
        // silently treated as a second frozen arm.
        return json_encode($old) !== json_encode($tested) ? $old : null;
    }

    private function parameterDiff(array $old, array $new): array
    {
        $diff = [];
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) {
            if (($old[$key] ?? null) !== ($new[$key] ?? null)) {
                $diff[$key] = ['old' => $old[$key] ?? null, 'new' => $new[$key] ?? null];
            }
        }

        return $diff;
    }

    private function interactionParameters(string $arm, array $base, string $aGene, mixed $aValue, string $bGene, mixed $bValue, string $filterGene): array
    {
        $p = $base;
        if (in_array($arm, ['a', 'a_plus_b', 'a_plus_b_minus_filter'], true)) {
            $p[$aGene] = $aValue;
        } if (in_array($arm, ['b', 'a_plus_b', 'a_plus_b_minus_filter'], true)) {
            $p[$bGene] = $bValue;
        } if ($arm === 'a_plus_b_minus_filter') {
            $v = $p[$filterGene];
            $p[$filterGene] = is_bool($v) ? ! $v : round((float) $v * .98, 8);
        }

        return $p;
    }

    private function context(LabLearningLanePair $pair, array $result): array
    {
        $state = (array) data_get($pair->failure_signature, 'state', []);
        $raw = [
            ...$state,
            'regime' => data_get($state, 'regime', data_get($result, 'market_regime')),
            'volatility' => data_get($state, 'volatility', data_get($result, 'volatility_regime')),
            'session' => data_get($state, 'session', data_get($result, 'session')),
            'direction' => data_get($state, 'direction', data_get($result, 'direction')),
            'transition_state' => data_get($state, 'transition_state', data_get($result, 'transition_state')),
            'spread_liquidity_state' => data_get($state, 'spread_liquidity_state', data_get($result, 'spread_liquidity_state')),
            'volume_state' => data_get($state, 'volume_state', data_get($result, 'volume_state')),
        ];
        $contract = app(ContextContractV2Service::class)->project($raw);
        $axes = (array) data_get($contract, 'extended_axes', []);

        return [
            ...$axes,
            // Research Inbox may retain an explicit unknown cell so an
            // unscoped provisional observation can only be replayed by an
            // equally unscoped request. The capsule compiler re-projects
            // these markers to null, so they can never become authority.
            'regime' => $axes['regime'] ?? 'unknown',
            'volatility' => $axes['volatility'] ?? 'unknown',
            'session' => $axes['session'] ?? 'unknown',
            'context_contract' => $contract,
            'temporal_roles_hash' => data_get($result, 'temporal_roles_hash'),
            'confirmation_entry_hash' => data_get($result, 'confirmation_entry.contract_hash'),
            'execution_contract_hash' => data_get($result, 'execution_contract.execution_hash', data_get($result, 'execution_hash')),
        ];
    }

    private function niche(array $c): string
    {
        return implode('|', [$c['regime'] ?? 'unknown', $c['volatility'] ?? 'unknown', $c['session'] ?? 'unknown']);
    }

    private function direction(mixed $old, mixed $new): string
    {
        return is_numeric($old) && is_numeric($new) ? ((float) $new < (float) $old ? 'decrease' : ((float) $new > (float) $old ? 'increase' : 'unchanged')) : 'replace';
    }

    /** @return array{status:string,interaction_delta:float} */
    private function factorialInteraction(float $control, float $a, float $b, float $ab): array
    {
        $interaction = ($ab - $control) - ($a - $control) - ($b - $control);
        $epsilon = .000001;

        return [
            'status' => $interaction > $epsilon ? 'synergistic' : ($interaction < -$epsilon ? 'antagonistic' : 'confirmed'),
            'interaction_delta' => $interaction,
        ];
    }

    private function range(mixed $v): array
    {
        return is_numeric($v) ? [round((float) $v * .96, 8), round((float) $v * 1.08, 8)] : [$v, $v];
    }

    private function viability(LabAgent $agent): string
    {
        return in_array((string) $agent->lifecycle_status, ['forward_validated', 'paper', 'champion'], true) ? 'viable' : 'not_viable';
    }

    private function secondary(array $r, LabLearningLanePair $p): array
    {
        return ['drawdown_delta' => data_get($p->target_delta, 'drawdown_delta'), 'risk_of_ruin_delta' => data_get($r, 'monte_carlo.risk_of_ruin_delta'), 'trade_count_delta' => data_get($p->target_delta, 'trade_count_delta'), 'opportunity_recall_delta' => data_get($r, 'opportunity_recall.delta'), 'cost_delta' => data_get($r, 'pf_attribution.cost_delta'), 'entry_precision_delta' => data_get($r, 'entry_precision.delta'), 'mfe_capture_delta' => data_get($r, 'management.mfe_capture_delta')];
    }

    private function scopeMismatch(array $stored, array $requested): ?string
    {
        $codes = ['regime' => 'REGIME_SCOPE_MISMATCH', 'volatility' => 'VOLATILITY_SCOPE_MISMATCH', 'session' => 'SESSION_SCOPE_MISMATCH',
            'temporal_roles_hash' => 'TEMPORAL_ROLE_SCOPE_MISMATCH', 'confirmation_entry_hash' => 'CONFIRMATION_ENTRY_SCOPE_MISMATCH',
            'execution_contract_hash' => 'EXECUTION_CONTRACT_SCOPE_MISMATCH'];
        foreach ($codes as $key => $code) {
            if (! filled($stored[$key] ?? null)) {
                continue;
            }
            if (! filled($requested[$key] ?? null)) {
                return 'CONTEXT_SCOPE_INCOMPLETE';
            }
            if ((string) $stored[$key] !== (string) $requested[$key]) {
                return $code;
            }
        }

        return null;
    }
}
