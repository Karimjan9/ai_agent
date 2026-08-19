<?php

namespace App\Services;

use App\Models\AiLaboratory;
use App\Models\Candle;
use App\Models\CandidateGateDecision;
use App\Models\LabEvaluationRun;
use App\Models\LabGeneration;
use App\Models\Symbol;
use App\Models\SystemEvent;
use Illuminate\Support\Collection;

/**
 * Admission control for failure-directed research.
 *
 * A rescue is a bounded experiment, not a scheduler exception.  This service
 * keeps the bounded history at the laboratory boundary so a new trigger name,
 * profile hash, or repair-anchor row cannot reset the same failed temporal
 * hypothesis.
 */
class RescueCircuitBreakerService
{
    public const PROTOCOL = 'rescue_circuit_breaker_v1';
    public const BLOCKED_NEED_NEW_EVIDENCE = 'BLOCKED_NEED_NEW_EVIDENCE';
    public const ADMITTED = 'ADMITTED';

    /** @return array<string, mixed> */
    public function admission(
        AiLaboratory $lab,
        array $profile,
        ?LabGeneration $source = null,
        ?array $snapshot = null,
    ): array {
        $snapshot ??= $this->currentDataSnapshot($lab);
        $generations = $lab->generations()
            ->with('agents')
            ->orderByDesc('generation')
            ->get();
        $identity = $this->identity($profile, $source, $snapshot);
        $rescueGenerations = $generations
            ->filter(fn (LabGeneration $generation): bool => $this->isRescueGeneration($generation))
            ->values();
        $exact = $rescueGenerations
            ->filter(fn (LabGeneration $generation): bool => $this->identityMatches(
                $this->identity($this->profile($generation), $generation, $this->generationSnapshot($generation)),
                $identity,
                false,
            ))
            ->sortBy('generation')
            ->values();
        $family = $rescueGenerations
            ->filter(fn (LabGeneration $generation): bool => $this->identityMatches(
                $this->identity($this->profile($generation), $generation, $this->generationSnapshot($generation)),
                $identity,
                true,
            ))
            ->sortBy('generation')
            ->values();

        $cohortRows = $this->cohortRows($exact);
        $familyRows = $this->cohortRows($family);
        $latestFamily = $family->sortByDesc('generation')->first();
        $latestFamilySnapshot = $latestFamily instanceof LabGeneration
            ? $this->generationSnapshot($latestFamily)
            : null;
        $lastFamilyCount = $latestFamilySnapshot !== null
            ? (int) data_get($latestFamilySnapshot, 'data_count', 0)
            : 0;
        $currentCount = (int) data_get($snapshot, 'data_count', data_get($snapshot, 'count', 0));
        $freshCandles = max(0, $currentCount - $lastFamilyCount);
        $holdoutEvidence = $this->sealedIndependentHoldoutEvidence($profile, $source, $snapshot);
        $holdout = (bool) data_get($holdoutEvidence, 'allowed', false);
        $minimumFreshCandles = $this->minimumFreshCandles((string) $lab->timeframe);
        $independentNewEvidence = $holdout || (
            $latestFamily !== null
            && $freshCandles >= $minimumFreshCandles
            && (string) data_get($snapshot, 'data_fingerprint', '') !== (string) data_get($latestFamilySnapshot, 'data_fingerprint', '')
        );

        $cohortCount = $cohortRows->count();
        $siblingCount = (int) $cohortRows->sum('sibling_count');
        $familySiblingCount = (int) $familyRows->sum('sibling_count');
        $screening = $this->screeningOutcome($cohortRows);
        $margin = $this->marginOutcome($cohortRows, (string) data_get($identity, 'failure_target', ''));
        $hypothesisSiblingCap = (int) config('services.rescue_circuit_breaker.max_siblings_per_hypothesis', 12);
        $hypothesisFrozen = $hypothesisSiblingCap > 0
            && $familySiblingCount >= $hypothesisSiblingCap
            && $screening['pass_count'] === 0
            && ! $independentNewEvidence;
        $circuitTripped = (bool) config('services.rescue_circuit_breaker.enabled', true)
            && $cohortCount >= (int) config('services.rescue_circuit_breaker.consecutive_cohorts', 3)
            && $siblingCount >= (int) config('services.rescue_circuit_breaker.minimum_siblings', 12)
            && $screening['pass_count'] === 0
            && ! $independentNewEvidence
            && ! $margin['meaningful_progress'];

        // Once a rescue family has been admitted, an unchanged snapshot or a
        // tiny tail cannot open another cohort even before the larger sibling
        // circuit threshold is reached. This closes G39/G40-style audit loops.
        $sameDataGate = $latestFamily !== null && ! $independentNewEvidence;
        $blocked = $circuitTripped || $sameDataGate || $hypothesisFrozen;
        $reason = $circuitTripped
            ? self::BLOCKED_NEED_NEW_EVIDENCE
            : (($sameDataGate || $hypothesisFrozen) ? self::BLOCKED_NEED_NEW_EVIDENCE : self::ADMITTED);
        $familyDataHashes = $familyRows
            ->pluck('data_fingerprint')
            ->filter(fn (mixed $hash): bool => filled($hash))
            ->countBy();
        $duplicateDataHashes = $familyDataHashes
            ->filter(fn (int $count): bool => $count > 1)
            ->keys()
            ->values()
            ->all();
        $allocation = app(ResearchAllocationPolicyService::class)->contract($blocked);

        return [
            'protocol' => self::PROTOCOL,
            'decision' => $reason,
            'allowed' => ! $blocked,
            'reason_code' => $blocked ? self::BLOCKED_NEED_NEW_EVIDENCE : null,
            'identity' => $identity,
            'key' => $this->key($identity),
            'history' => [
                'exact_cohort_count' => $cohortCount,
                'exact_sibling_count' => $siblingCount,
                'family_cohort_count' => $familyRows->count(),
                'family_sibling_count' => (int) $familyRows->sum('sibling_count'),
                'hypothesis_sibling_cap' => $hypothesisSiblingCap,
                'hypothesis_frozen' => $hypothesisFrozen,
                'cohort_generations' => $cohortRows->pluck('generation')->values()->all(),
                'family_generations' => $familyRows->pluck('generation')->values()->all(),
                'family_repeated_dataset_hashes' => $duplicateDataHashes,
                'family_reused_dataset_history' => $duplicateDataHashes !== [],
                'pass_count' => $screening['pass_count'],
                'failed_count' => $screening['failed_count'],
                'decision_count' => $screening['decision_count'],
                'target_margins' => $margin['values'],
                'best_target_margin' => $margin['best'],
                'first_target_margin' => $margin['first'],
                'meaningful_target_margin_progress' => $margin['meaningful_progress'],
                'target_threshold_reached' => $margin['threshold_reached'],
                'independent_new_evidence' => $independentNewEvidence,
                'sealed_independent_holdout' => $holdout,
                'sealed_holdout_evidence' => $holdoutEvidence,
                'current_data_fingerprint' => data_get($snapshot, 'data_fingerprint'),
                'last_family_data_fingerprint' => data_get($latestFamilySnapshot, 'data_fingerprint'),
                'current_data_count' => $currentCount,
                'last_family_data_count' => $lastFamilyCount,
                'fresh_candles' => $freshCandles,
                'minimum_fresh_candles' => $minimumFreshCandles,
            ],
            'policy' => [
                'consecutive_cohorts' => (int) config('services.rescue_circuit_breaker.consecutive_cohorts', 3),
                'minimum_siblings' => (int) config('services.rescue_circuit_breaker.minimum_siblings', 12),
                'max_siblings_per_hypothesis' => $hypothesisSiblingCap,
                'minimum_fresh_candles' => $minimumFreshCandles,
                'target_margin_threshold' => (float) config('services.rescue_circuit_breaker.target_margin_threshold', 1.0),
                'minimum_margin_progress' => (float) config('services.rescue_circuit_breaker.minimum_margin_progress', .05),
                'same_dataset_rule' => 'new chronological window or sealed independent holdout is required after the first rescue cohort',
            ],
            'rule' => $blocked
                ? 'Do not create another targeted rescue until a new chronological market window, a new independent dataset hash, or a sealed independent holdout is admitted.'
                : 'Targeted rescue admission is bounded by the global circuit breaker and independent-evidence gate.',
            'research_allocation' => $allocation,
            'promotion_evidence' => false,
        ];
    }

    public function isRescueProfile(?array $profile, string $trigger = ''): bool
    {
        if (! is_array($profile) || $profile === []) return false;

        return (string) data_get($profile, 'protocol') === LabPopulationService::TARGETED_RESCUE_PROFILE_PROTOCOL
            && ((bool) data_get($profile, 'temporary', false)
                || (string) data_get($profile, 'rescue_protocol') === LearningProtocolSafetyService::CONTROLLED_RESCUE_PROTOCOL
                || filled(data_get($profile, 'repair_anchors.0.id')))
            && ($trigger === '' || in_array($trigger, ['candidate_handoff', 'data_edge_audit', 'coverage_rescue', 'shadow_research'], true));
    }

    public function blockedForLab(AiLaboratory $lab): bool
    {
        $latest = $lab->generations()
            ->orderByDesc('generation')
            ->get()
            ->first(fn (LabGeneration $generation): bool => $this->isRescueGeneration($generation));
        if (! $latest) return false;
        $profile = $this->profile($latest);

        return ! (bool) data_get($this->admission($lab, $profile, $latest), 'allowed', false);
    }

    /** @return array<string, mixed> */
    public function currentDataSnapshot(AiLaboratory $lab): array
    {
        $symbolId = Symbol::query()->where('code', $lab->symbol)->value('id');
        if (! $symbolId) {
            return [
                'data_fingerprint' => 'no-data',
                'data_count' => 0,
                'latest_candle' => null,
                'count' => 0,
                'latest' => null,
            ];
        }
        $query = Candle::query()->where('symbol_id', $symbolId)->where('timeframe', $lab->timeframe);
        $count = (int) $query->count();
        $latest = $query->max('time');

        return [
            'data_fingerprint' => sha1($count.'|'.($latest ?? 'none')),
            'data_count' => $count,
            'latest_candle' => $latest,
            'count' => $count,
            'latest' => $latest,
        ];
    }

    /**
     * Structural cohorts require genuinely new chronological evidence. This
     * is intentionally separate from the sibling circuit: a changed count
     * of one candle, or a parameter hash, must never be mistaken for an
     * independent validation window.
     *
     * @return array<string, mixed>
     */
    public function independentEvidenceAdmission(
        AiLaboratory $lab,
        ?LabGeneration $source,
        array $profile,
        ?array $snapshot = null,
    ): array {
        $snapshot ??= $this->currentDataSnapshot($lab);
        $minimum = $this->minimumFreshCandles((string) $lab->timeframe);
        $currentCount = (int) data_get($snapshot, 'data_count', data_get($snapshot, 'count', 0));
        $sourceCount = (int) data_get($source?->trigger_context, 'data_count', $source?->data_count ?? 0);
        $fresh = max(0, $currentCount - $sourceCount);
        $currentLatest = (string) data_get($snapshot, 'latest_candle', data_get($snapshot, 'latest', ''));
        $sourceLatest = (string) data_get($source?->trigger_context, 'latest_candle', '');
        $latestAdvanced = $currentLatest !== '' && ($sourceLatest === '' || $currentLatest > $sourceLatest);
        $fingerprintChanged = (string) data_get($snapshot, 'data_fingerprint', '') !== (string) ($source?->data_fingerprint ?: data_get($source?->trigger_context, 'data_fingerprint', ''));
        $holdoutEvidence = $this->sealedIndependentHoldoutEvidence($profile, $source, $snapshot);
        $holdout = (bool) data_get($holdoutEvidence, 'allowed', false);
        $allowed = $holdout || ($source !== null && $fresh >= $minimum && $latestAdvanced && $fingerprintChanged);

        return [
            'protocol' => self::PROTOCOL,
            'evidence_protocol' => StructuralResearchCohortService::INDEPENDENT_EVIDENCE_PROTOCOL,
            'allowed' => $allowed,
            'reason' => $allowed ? 'INDEPENDENT_CHRONOLOGICAL_EVIDENCE_READY' : 'INDEPENDENT_CHRONOLOGICAL_EVIDENCE_REQUIRED',
            'source_generation_id' => $source?->id,
            'current_data_count' => $currentCount,
            'source_data_count' => $sourceCount,
            'fresh_candles' => $fresh,
            'minimum_fresh_candles' => $minimum,
            'latest_advanced' => $latestAdvanced,
            'data_fingerprint_changed' => $fingerprintChanged,
            'sealed_independent_holdout' => $holdout,
            'sealed_holdout_evidence' => $holdoutEvidence,
            'one_candle_is_insufficient' => true,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function identity(array $profile, ?LabGeneration $generation = null, ?array $snapshot = null): array
    {
        $anchor = collect((array) data_get($profile, 'repair_anchors', []))
            ->filter(fn (mixed $row): bool => is_array($row))
            ->sortByDesc(fn (array $row): int => (int) data_get($row, 'id', 0))
            ->first() ?: [];
        $anchorFingerprint = (string) (
            data_get($profile, 'anchor_fingerprint')
            ?: data_get($anchor, 'anchor_fingerprint')
            ?: data_get($anchor, 'parameter_fingerprint')
            ?: ((int) data_get($anchor, 'id', 0) > 0 ? 'anchor:'.(int) data_get($anchor, 'id') : 'anchor:none')
        );
        $failureTarget = (string) (
            data_get($profile, 'failure_specific_lane')
            ?: data_get($profile, 'dominant_target')
            ?: data_get($profile, 'selected_near_miss.failure_specific_lane')
            ?: data_get($anchor, 'failure_target')
            ?: 'unknown'
        );
        $hypothesisHash = (string) data_get($profile, 'hypothesis_hash', '');
        if ($hypothesisHash === '') $hypothesisHash = $this->hypothesisHash($profile);
        $dataHash = (string) (
            data_get($snapshot, 'data_fingerprint')
            ?: $generation?->data_fingerprint
            ?: data_get($generation?->trigger_context, 'canonical_dataset_snapshots.price.manifest.snapshot_sha256')
            ?: data_get($generation?->trigger_context, 'canonical_dataset_snapshots.price.manifest.sha256')
            ?: 'unknown'
        );

        return [
            'symbol' => strtoupper((string) ($profile['symbol'] ?? $generation?->laboratory?->symbol ?? '')),
            'timeframe' => strtoupper((string) ($profile['timeframe'] ?? $generation?->laboratory?->timeframe ?? '')),
            'anchor_fingerprint' => $anchorFingerprint,
            'failure_target' => $failureTarget,
            'hypothesis_hash' => $hypothesisHash,
            'dataset_hash' => $dataHash,
        ];
    }

    /** @return array<string, mixed> */
    public function hypothesisDescriptor(array $profile): array
    {
        $temporal = (array) data_get($profile, 'temporal_mutation_hypothesis', []);
        $plan = (array) data_get($profile, 'failure_specific_plan.'.(string) data_get($profile, 'failure_specific_lane', ''), []);

        return [
            'target' => (string) (data_get($profile, 'failure_specific_lane') ?: data_get($profile, 'dominant_target', 'unknown')),
            'protocol' => (string) (data_get($temporal, 'hypothesis_protocol') ?: data_get($profile, 'rescue_curriculum', 'unknown')),
            'genes' => array_values((array) (data_get($temporal, 'genes') ?: data_get($plan, 'genes', []))),
            'direction_rule' => (array) data_get($temporal, 'direction_rule', []),
            'cohort_contract' => (string) data_get($profile, 'cohort_contract.protocol', 'unknown'),
            'structural_research_contract' => [
                'protocol' => (string) data_get($profile, 'structural_research_contract.protocol', 'none'),
                'hypothesis_protocol' => (string) data_get($profile, 'structural_research_contract.hypothesis_protocol', 'none'),
                'families' => array_values((array) data_get($profile, 'structural_research_contract.structural_families', [])),
                'causal_probe_protocol' => (string) data_get($profile, 'structural_research_contract.causal_micro_probe.protocol', 'none'),
                'independent_evidence_protocol' => (string) data_get($profile, 'structural_research_contract.independent_evidence.protocol', 'none'),
            ],
        ];
    }

    public function hypothesisHash(array $profile): string
    {
        return hash('sha256', json_encode($this->hypothesisDescriptor($profile), JSON_UNESCAPED_SLASHES));
    }

    /** Record one durable block event without creating a generation. */
    public function recordBlocked(AiLaboratory $lab, array $decision, ?LabGeneration $source = null): void
    {
        $key = 'learning_protocol:'.self::PROTOCOL.':'.hash('sha256', json_encode([
            data_get($decision, 'key'),
            data_get($decision, 'reason_code'),
        ], JSON_UNESCAPED_SLASHES));
        // The event key is intentionally stable for idempotency, but the
        // payload is an operational snapshot. Refresh it on every blocked
        // admission so newly added audit fields (repeated hashes and the
        // effective allocation) are not hidden behind an old first event.
        SystemEvent::updateOrCreate(
            ['event_key' => $key],
            [
                'event_type' => 'learning_protocol_rescue_blocked',
                'source_type' => self::class,
                'source_id' => $source?->id,
                'agent' => 'operations',
                'symbol' => $lab->symbol,
                'timeframe' => $lab->timeframe,
                'severity' => 'warning',
                'summary' => self::BLOCKED_NEED_NEW_EVIDENCE,
                'payload' => [
                    ...$decision,
                    'lab_id' => $lab->id,
                    'source_generation_id' => $source?->id,
                    'promotion_evidence' => false,
                ],
                'occurred_at' => now(),
            ],
        );
    }

    /** @return array<string, mixed> */
    private function profile(LabGeneration $generation): array
    {
        return (array) data_get($generation->trigger_context, 'targeted_failure_profile', []);
    }

    private function isRescueGeneration(LabGeneration $generation): bool
    {
        return $this->isRescueProfile($this->profile($generation), (string) $generation->trigger_type);
    }

    /** @return array<string, mixed> */
    private function generationSnapshot(LabGeneration $generation): array
    {
        $context = (array) $generation->trigger_context;
        $runHash = LabEvaluationRun::query()
            ->where('lab_generation_id', $generation->id)
            ->whereIn('phase', ['screening', 'full_validation'])
            ->whereNotNull('data_hash')
            ->where('data_hash', '!=', '')
            ->latest('id')
            ->value('data_hash');

        return [
            'data_fingerprint' => (string) ($runHash
                ?: data_get($context, 'canonical_dataset_snapshots.price.manifest.snapshot_sha256')
                ?: data_get($context, 'canonical_dataset_snapshots.price.manifest.sha256')
                ?: $generation->data_fingerprint
                ?: 'unknown'),
            'data_count' => (int) data_get($context, 'data_count', 0),
            'latest_candle' => data_get($context, 'latest_candle'),
            'new_candles' => (int) data_get($context, 'new_candles', 0),
        ];
    }

    private function identityMatches(array $left, array $right, bool $ignoreAnchor): bool
    {
        $keys = ['symbol', 'timeframe', 'failure_target', 'hypothesis_hash'];
        if (! $ignoreAnchor) $keys[] = 'anchor_fingerprint';
        if (! $ignoreAnchor) $keys[] = 'dataset_hash';

        foreach ($keys as $key) {
            if ((string) data_get($left, $key) !== (string) data_get($right, $key)) return false;
        }

        return true;
    }

    private function key(array $identity): string
    {
        return implode('|', [
            $identity['symbol'] ?? '',
            $identity['timeframe'] ?? '',
            $identity['anchor_fingerprint'] ?? '',
            $identity['failure_target'] ?? '',
            $identity['hypothesis_hash'] ?? '',
            $identity['dataset_hash'] ?? '',
        ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function cohortRows(Collection $generations): Collection
    {
        return $generations->map(function (LabGeneration $generation): array {
            $agents = $generation->relationLoaded('agents') ? $generation->agents : $generation->agents()->get();

            return [
                'generation_id' => (int) $generation->id,
                'generation' => (int) $generation->generation,
                'sibling_count' => max(0, $agents->count() ?: (int) $generation->population_size),
                'agent_ids' => $agents->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
                'data_fingerprint' => data_get($this->generationSnapshot($generation), 'data_fingerprint'),
                'data_count' => data_get($this->generationSnapshot($generation), 'data_count'),
            ];
        })->values();
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function screeningOutcome(Collection $rows): array
    {
        $agentIds = $rows->flatMap(fn (array $row): array => $row['agent_ids'])->filter()->unique()->values()->all();
        if ($agentIds === []) return ['pass_count' => 0, 'failed_count' => 0, 'decision_count' => 0];
        $decisions = CandidateGateDecision::query()
            ->whereIn('lab_agent_id', $agentIds)
            ->where('stage', 'screening')
            ->orderBy('id')
            ->get()
            ->groupBy('lab_agent_id')
            ->map(fn ($items) => $items->last());

        return [
            'pass_count' => $decisions->where('decision', 'passed')->count(),
            'failed_count' => $decisions->where('decision', 'failed')->count(),
            'decision_count' => $decisions->count(),
        ];
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function marginOutcome(Collection $rows, string $target): array
    {
        $agentIds = $rows->flatMap(fn (array $row): array => $row['agent_ids'])->filter()->unique()->values()->all();
        if ($agentIds === []) {
            return ['values' => [], 'first' => null, 'best' => null, 'meaningful_progress' => false, 'threshold_reached' => false];
        }
        $decisions = CandidateGateDecision::query()
            ->whereIn('lab_agent_id', $agentIds)
            ->where('stage', 'screening')
            ->orderBy('id')
            ->get()
            ->groupBy('lab_agent_id')
            ->map(fn ($items) => $items->last());
        $values = $decisions->map(function (CandidateGateDecision $decision) use ($target): ?float {
            $margin = (array) data_get($decision->metrics, 'gate_margin', []);
            $value = data_get($margin, 'target_margin');
            if (! is_numeric($value) && $target !== '') {
                $value = data_get($margin, 'gates.'.$target.'.normalized_margin');
            }

            return is_numeric($value) ? (float) $value : null;
        })->filter(fn (?float $value): bool => $value !== null)->values()->all();
        $first = $values[0] ?? null;
        $best = $values === [] ? null : max($values);
        $threshold = (float) config('services.rescue_circuit_breaker.target_margin_threshold', 1.0);
        $delta = (float) config('services.rescue_circuit_breaker.minimum_margin_progress', .05);
        $nearThreshold = $best !== null && $best >= ($threshold - max($delta, .10));

        return [
            'values' => array_values(array_map(fn (float $value): float => round($value, 6), $values)),
            'first' => $first,
            'best' => $best,
            'meaningful_progress' => $nearThreshold
                && $first !== null
                && $best !== null
                && ($best - $first) >= $delta,
            'threshold_reached' => $best !== null && $best >= $threshold,
        ];
    }

    private function minimumFreshCandles(string $timeframe): int
    {
        $configured = (int) config('services.rescue_circuit_breaker.minimum_fresh_candles', 24);
        if ($configured > 0) return $timeframe === 'M15' ? $configured * 4 : $configured;

        return $timeframe === 'M15' ? 96 : 24;
    }

    /** @return array<string, mixed> */
    private function sealedIndependentHoldoutEvidence(array $profile, ?LabGeneration $source, array $snapshot): array
    {
        $paths = [
            'evidence_admission.independent_holdout.status',
            'independent_holdout.status',
            'sealed_holdout.status',
        ];
        foreach ($paths as $path) {
            if (in_array((string) data_get($profile, $path), ['sealed', 'verified', 'ready'], true)) {
                return [
                    'allowed' => true,
                    'source' => 'profile_attestation',
                    'status' => (string) data_get($profile, $path),
                    'promotion_evidence' => false,
                ];
            }
        }
        $context = (array) ($source?->trigger_context ?? []);
        $status = (string) (data_get($context, 'sealed_holdout.status') ?: data_get($context, 'independent_holdout.status'));
        if (in_array($status, ['sealed', 'verified', 'ready'], true)
            && filled(data_get($snapshot, 'independent_data_hash'))) {
            return [
                'allowed' => true,
                'source' => 'source_generation_attestation',
                'status' => $status,
                'data_hash' => data_get($snapshot, 'independent_data_hash'),
                'promotion_evidence' => false,
            ];
        }

        // A valid temporal foundation is an already-sealed research artifact,
        // not a synthetic slice of the rolling canonical dataset.  Discover
        // it by identity so a structural rescue can use the independent
        // holdout without requiring the old generation profile to have
        // copied a future manifest path into its trigger context.
        if (! app(StructuralResearchCohortService::class)->isProfile($profile)) {
            return [
                'allowed' => false,
                'source' => 'none',
                'reason' => 'SEALED_HOLDOUT_NOT_DECLARED_FOR_PROFILE',
                'promotion_evidence' => false,
            ];
        }
        $symbol = strtoupper((string) ($profile['symbol'] ?? $source?->laboratory?->symbol ?? ''));
        $timeframe = strtoupper((string) ($profile['timeframe'] ?? $source?->laboratory?->timeframe ?? ''));
        $manifestPath = storage_path("app/temporal-ablation/{$symbol}_{$timeframe}_manifest.json");
        if (! is_file($manifestPath)) {
            return [
                'allowed' => false,
                'source' => 'none',
                'reason' => 'SEALED_HOLDOUT_MANIFEST_MISSING',
                'promotion_evidence' => false,
            ];
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (! is_array($manifest)
            || (string) data_get($manifest, 'protocol') !== 'temporal_clean_ablation_manifest_v2'
            || strtoupper((string) data_get($manifest, 'symbol')) !== $symbol
            || strtoupper((string) data_get($manifest, 'timeframe')) !== $timeframe
            || ! (bool) data_get($manifest, 'independent_attestation', false)
            || ! (bool) data_get($manifest, 'independent_holdout', false)
            || (string) data_get($manifest, 'data_identity_protocol') !== 'content_and_timestamp_disjoint_v1') {
            return [
                'allowed' => false,
                'source' => 'manifest',
                'reason' => 'SEALED_HOLDOUT_MANIFEST_INVALID',
                'manifest_path' => $manifestPath,
                'promotion_evidence' => false,
            ];
        }

        $holdout = collect((array) data_get($manifest, 'windows', []))
            ->first(fn (mixed $window): bool => is_array($window) && data_get($window, 'role') === 'sealed_holdout');
        $datasetPath = (string) data_get($holdout, 'dataset_path', '');
        $datasetHash = (string) data_get($holdout, 'data_hash', '');
        $storageRoot = realpath(storage_path('app'));
        $resolvedDatasetPath = $datasetPath !== '' ? realpath($datasetPath) : false;
        $insideStorage = $storageRoot !== false
            && $resolvedDatasetPath !== false
            && str_starts_with(strtolower($resolvedDatasetPath), strtolower($storageRoot.DIRECTORY_SEPARATOR));
        $fileMatchesManifest = $insideStorage
            && $datasetHash !== ''
            && hash_file('sha256', $resolvedDatasetPath) === $datasetHash;
        $valid = is_array($holdout)
            && (bool) data_get($holdout, 'sealed', false)
            && (bool) data_get($holdout, 'independent_from_rescue', false)
            && data_get($holdout, 'attestation') === 'derived_from_independent_source_non_overlap_v1'
            && (int) data_get($holdout, 'candle_count', 0) >= $this->minimumFreshCandles($timeframe)
            && $fileMatchesManifest;

        return [
            'allowed' => $valid,
            'source' => 'temporal_ablation_manifest',
            'manifest_path' => $manifestPath,
            'window_id' => data_get($holdout, 'window_id'),
            'data_hash' => $datasetHash !== '' ? $datasetHash : null,
            'candle_count' => (int) data_get($holdout, 'candle_count', 0),
            'sealed' => (bool) data_get($holdout, 'sealed', false),
            'independent_from_rescue' => (bool) data_get($holdout, 'independent_from_rescue', false),
            'file_hash_matches_manifest' => $fileMatchesManifest,
            'reason' => $valid ? 'SEALED_INDEPENDENT_HOLDOUT_READY' : 'SEALED_HOLDOUT_ARTIFACT_INVALID',
            'promotion_evidence' => false,
        ];
    }
}
