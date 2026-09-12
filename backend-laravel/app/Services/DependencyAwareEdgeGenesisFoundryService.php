<?php

namespace App\Services;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enforces the causal order: find/prove/explain/transfer edge before risk,
 * management, paper or breeding. All records are research authority only.
 */
class DependencyAwareEdgeGenesisFoundryService
{
    public const PROTOCOL = 'dependency_aware_edge_genesis_foundry_v1';

    public const DISCOVERY_VERDICT_REVISION = 'context_specialist_power_v4';

    public const INITIAL_REVISION = 'professional_foundation_v1';

    public const CONFIRMATION_REPAIR_REVISION = 'confirmation_breadth_repair_v1';

    public const TRIGGER_REPAIR_REVISION = 'entry_trigger_topology_repair_v1';

    public const LATENT_HARVEST_REVISION = 'latent_excursion_edge_harvest_v1';

    public const CONTEXT_ROUTER_REPAIR_REVISION = 'context_authority_firewall_repair_v1';

    public const REGIME_ENTRY_SYNTHESIS_REVISION = 'regime_conditioned_entry_edge_synthesis_v1';

    public const FAILURE_CELL_FACTORIAL_REVISION = 'failure_cell_factorial_router_v1';

    public const SPECIALIST_DENSIFICATION_REVISION = 'professional_specialist_coverage_densification_v1';

    public const TEMPORAL_BREAKOUT_BINDING_REVISION = 'temporal_breakout_role_binding_v1';

    public const M15_SETUP_QUALITY_REVISION = 'm15_setup_confirmation_quality_repair_v1';

    public const EVIDENCE_COMPILED_REVISION = 'evidence_compiled_edge_hypothesis_v1';

    public const EXECUTION_TIMEFRAME = 'M5';

    public const PHASES = ['EDGE_DISCOVERY', 'EDGE_CONFIRMATION', 'EDGE_ATTRIBUTION', 'RISK_SHAPING', 'MANAGEMENT_OPTIMIZATION', 'PAPER_VALIDATION'];

    public const RISK_GENES = ['loss_cooldown_candles', 'high_volatility_risk_multiplier', 'trend_down_risk_multiplier', 'trend_up_risk_multiplier', 'risk_per_trade', 'position_size', 'lot_size', 'atr_stop_multiplier'];

    public const MANAGEMENT_GENES = ['atr_target_multiplier', 'trailing_atr_multiplier', 'time_stop_candles', 'partial_take_profit_fraction', 'partial_target_atr_multiplier', 'exit_topology_variant'];

    public const EMITTER_BUDGETS = ['prior_seed' => .25, 'local_recombination' => .20, 'temporal_binder' => .15, 'confirmation_entry' => .15, 'stepping_stone_transplant' => .10, 'novelty' => .10, 'adversarial' => .05, 'risk_mutation' => 0.0];

    // The first mastery cohort always learns a whole professional procedure.
    // These are causal arms, not generic strategy/risk slot permutations.
    public const GENESIS_ARMS = ['professional_reference', 'confirmation_floor_one', 'temporal_role_change', 'memory_blinded_autonomous', 'frozen_control'];

    public const TRIGGER_REPAIR_ARMS = ['confirmation_floor_control', 'internal_structure_trigger', 'aggressive_trigger', 'extended_retest_trigger', 'frozen_control'];

    public const LATENT_HARVEST_ARMS = ['latent_edge_control', 'partial_harvest', 'trailing_harvest', 'time_stop_harvest', 'target_harvest'];

    public const CONTEXT_ROUTER_REPAIR_ARMS = ['unfiltered_context_control', 'regime_compatibility_gate', 'session_liquidity_gate', 'regime_session_gate', 'strict_context_gate'];

    public const REGIME_ENTRY_SYNTHESIS_ARMS = ['regime_entry_control', 'retest_entry_gate', 'independent_confirmation_gate', 'reward_space_gate', 'chase_quality_gate'];

    public const FAILURE_CELL_FACTORIAL_ARMS = ['failure_cell_control', 'buy_direction_gate', 'high_volatility_gate', 'buy_high_volatility_interaction', 'sell_direction_negative_control'];

    public const SPECIALIST_DENSIFICATION_ARMS = ['specialist_interaction_control', 'trend_continuation_topology', 'false_break_reversal_topology', 'extended_retest_window', 'lower_displacement_gate'];

    public const TEMPORAL_BREAKOUT_BINDING_ARMS = ['h1_breakout_control', 'm15_setup_breakout', 'short_structure_horizon', 'long_structure_horizon', 'balanced_retest_confirmation'];

    public const M15_SETUP_QUALITY_ARMS = ['m15_aggressive_control', 'm15_balanced_confirmation', 'm15_conservative_confirmation', 'm15_two_family_confirmation', 'm15_three_family_confirmation'];

    public const COMPILED_HYPOTHESIS_ARMS = ['compiled_control', 'compiled_primary', 'compiled_refinement', 'compiled_counterfactual', 'compiled_negative_control'];

    public const TRAVELING_CONTROL_ARMS = ['frozen_control', 'latent_edge_control', 'unfiltered_context_control', 'regime_entry_control', 'failure_cell_control',
        'specialist_interaction_control', 'h1_breakout_control', 'm15_aggressive_control', 'compiled_control'];

    public function __construct(
        private CompositionAuthorityKernelService $composition,
        private StrategyParameterSchemaService $schemas,
        private FullStackPlaybookMasteryService $mastery,
        private StrategySemanticGroupService $semanticGroups,
        private ExecutionContractService $executionContracts,
        private EdgeCohortIdentityService $cohortIdentity,
        private CausalStageMasteryDirectorService $stageMastery,
        private CausalProgressRatchetGovernorService $ratchetGovernor,
        private XauusdEdgeFormationAcademyService $academy,
        private CausalCompoundingKernelService $compoundingKernel,
    ) {}

    /** A mutation admission is a hard dependency gate, never a promotion claim. */
    public function mutationAdmission(?ModelVersion $baseline, string $gene, array $evidence = []): array
    {
        $declaredPhase = (string) data_get($baseline?->metadata, 'edge_genesis.phase', '');
        $eligibleParent = $baseline !== null
            && data_get(app(EvolutionaryAuthorityFoundryService::class)->authorityFor($baseline), 'stage') === 'eligible_parent';
        // An eligible parent has already passed the independent descendant
        // authority ladder. Treating it as a fresh dead baseline permanently
        // locked every risk/management refinement even after Edge was proven.
        // This opens research shaping only; paper/champion authority remains
        // governed by its separate prospective admission contract.
        $phase = $declaredPhase !== ''
            ? $declaredPhase
            : ($eligibleParent ? 'MANAGEMENT_OPTIMIZATION' : 'EDGE_DISCOVERY');
        if (! in_array($phase, self::PHASES, true)) {
            $phase = 'EDGE_DISCOVERY';
        }
        $riskGene = in_array($gene, self::RISK_GENES, true) || str_contains(strtolower($gene), 'risk_') || str_contains(strtolower($gene), 'cooldown');
        $managementGene = in_array($gene, self::MANAGEMENT_GENES, true) || str_contains(strtolower($gene), 'trailing') || str_contains(strtolower($gene), 'take_profit');
        $ratchetMatrix = $this->ratchetGovernor->mutationAuthority(
            (string) data_get($baseline?->metadata, 'causal_progress_ratchet.deepest_stage', 'none'), $evidence,
        );
        $edgeViable = $eligibleParent || $this->edgeViable($evidence) || in_array($phase, ['RISK_SHAPING', 'MANAGEMENT_OPTIMIZATION', 'PAPER_VALIDATION'], true);
        if (! $edgeViable && $riskGene) {
            return $this->blocked('RISK_MUTATION_BEFORE_EDGE_CONFIRMATION', $phase, true);
        }
        if ($riskGene && ! in_array($phase, ['RISK_SHAPING', 'MANAGEMENT_OPTIMIZATION', 'PAPER_VALIDATION'], true)) {
            return $this->blocked('RISK_MUTATION_LOCKED_UNTIL_EDGE_ATTRIBUTED', $phase, true);
        }
        if ($managementGene && ! in_array($phase, ['MANAGEMENT_OPTIMIZATION', 'PAPER_VALIDATION'], true)) {
            return $this->blocked('MANAGEMENT_MUTATION_LOCKED_UNTIL_RISK_SHAPING', $phase, false);
        }
        // A model can reach RISK_SHAPING only through the preceding Edge
        // attribution state machine. Treat that sealed phase as the durable
        // authority when an older row predates the ratchet projection; do
        // not make a historical projection gap reopen a completed gate.
        $phaseCarriesRiskAuthority = in_array($phase, ['RISK_SHAPING', 'MANAGEMENT_OPTIMIZATION', 'PAPER_VALIDATION'], true);
        if ($riskGene && ! ($ratchetMatrix['risk_allowed'] ?? false) && ! $phaseCarriesRiskAuthority && ! $eligibleParent) {
            return $this->blocked('RISK_MUTATION_REQUIRES_POSITIVE_AFTER_COST_EDGE', $phase, true);
        }
        if ($managementGene && ! ($ratchetMatrix['management_allowed'] ?? false) && ! $eligibleParent) {
            return $this->blocked('MANAGEMENT_MUTATION_REQUIRES_MFE_CAPTURE_GAP', $phase, false);
        }

        return ['protocol' => self::PROTOCOL, 'allowed' => true, 'phase' => $phase, 'risk_gene' => $riskGene, 'management_gene' => $managementGene, 'promotion_evidence' => false];
    }

    /** Dead organisms can be controls/components, never genetic parents. */
    public function baselineAdmission(?ModelVersion $baseline, array $metrics = []): array
    {
        $viable = $this->edgeViable($metrics);

        return ['protocol' => self::PROTOCOL, 'baseline_edge_viable' => $viable, 'risk_mutation_authorized' => $viable,
            'genetic_parent_authorized' => $viable, 'architecture_genesis_required' => ! $viable,
            'permitted_roles' => $viable ? ['frozen_control', 'edge_comparator'] : ['failure_artifact', 'frozen_causal_control', 'stepping_stone_source', 'negative_control'],
            'promotion_evidence' => false];
    }

    /** Materialize the first four pre-registered architecture packets x five frozen-risk arms. */
    public function materialize(
        AiLaboratory $lab,
        string $dataHash,
        string $executionHash,
        bool $pre2026Attested,
        array $mtfBundle = [],
        string $architectureRevision = self::INITIAL_REVISION,
        array $canonicalDatasetSnapshots = [],
        array $compiledContract = [],
    ): array {
        if (! $this->available()) {
            return ['status' => 'unavailable', 'promotion_evidence' => false];
        }
        if (! in_array($architectureRevision, [self::INITIAL_REVISION, self::CONFIRMATION_REPAIR_REVISION, self::TRIGGER_REPAIR_REVISION, self::LATENT_HARVEST_REVISION, self::CONTEXT_ROUTER_REPAIR_REVISION, self::REGIME_ENTRY_SYNTHESIS_REVISION, self::FAILURE_CELL_FACTORIAL_REVISION, self::SPECIALIST_DENSIFICATION_REVISION, self::TEMPORAL_BREAKOUT_BINDING_REVISION, self::M15_SETUP_QUALITY_REVISION, self::EVIDENCE_COMPILED_REVISION], true)) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason' => 'UNKNOWN_EDGE_ARCHITECTURE_REVISION', 'promotion_evidence' => false];
        }
        $repairContract = [];
        if ($architectureRevision === self::EVIDENCE_COMPILED_REVISION) {
            if (data_get($compiledContract, 'protocol') !== EdgeHypothesisCompilerService::PROTOCOL
                || data_get($compiledContract, 'admitted') !== true
                || data_get($compiledContract, 'authority_contract.one_structural_axis_only') !== true
                || data_get($compiledContract, 'authority_contract.exact_control_required') !== true
                || data_get($compiledContract, 'authority_contract.negative_control_required') !== true) {
                return ['protocol' => self::PROTOCOL, 'status' => 'blocked',
                    'reason' => 'VALID_COMPILED_HYPOTHESIS_CONTRACT_REQUIRED', 'promotion_evidence' => false];
            }
            $repairContract = $compiledContract;
        } elseif ($architectureRevision !== self::INITIAL_REVISION) {
            $repair = $this->architectureRepairAssessment($lab->symbol, $lab->timeframe);
            if (! ($repair['admitted'] ?? false)
                || (string) ($repair['repair_revision'] ?? '') !== $architectureRevision) {
                return ['protocol' => self::PROTOCOL, 'status' => 'blocked',
                    'reason' => $repair['reason'] ?? 'ARCHITECTURE_REPAIR_NOT_CAUSALLY_ADMITTED',
                    'repair_readiness' => $this->withoutMaterializationContract($repair), 'promotion_evidence' => false];
            }
            $repairContract = (array) ($repair['materialization_contract'] ?? []);
            if (! hash_equals((string) ($repairContract['data_hash'] ?? ''), $dataHash)
                || ! hash_equals((string) ($repairContract['execution_hash'] ?? ''), $executionHash)
                || ! hash_equals((string) data_get($repairContract, 'mtf_bundle.bundle_hash', ''), (string) data_get($mtfBundle, 'bundle_hash', ''))) {
                return ['protocol' => self::PROTOCOL, 'status' => 'blocked',
                    'reason' => 'REPAIR_MUST_REUSE_SOURCE_COHORT_IDENTITY', 'promotion_evidence' => false];
            }
            // Never trust an operator-supplied replacement for repair data.
            // The source cohort's byte-validated passports are authoritative.
            $canonicalDatasetSnapshots = (array) ($repairContract['canonical_dataset_snapshots'] ?? []);
        } elseif (DB::table('edge_genesis_passports')->where('symbol', strtoupper($lab->symbol))
            ->where('timeframe', strtoupper($lab->timeframe))->exists()) {
            return ['protocol' => self::PROTOCOL, 'status' => 'already_materialized',
                'reason' => 'INITIAL_EDGE_ARCHITECTURE_IS_IMMUTABLE', 'promotion_evidence' => false];
        }
        // The laboratory identity remains H1, but this organism executes on
        // M5.  Keeping an H1 descriptive execution contract here allowed the
        // immutable passport and the actual AI request to tell two different
        // stories even though their cost-parameter hashes were identical.
        $canonicalExecution = $this->executionContracts->for($lab->symbol, self::EXECUTION_TIMEFRAME);
        $mtfManifest = (array) data_get($mtfBundle, 'manifest', []);
        $mtfBundleHash = (string) data_get($mtfBundle, 'bundle_hash', '');
        $mtfValid = preg_match('/^[a-f0-9]{64}$/', $mtfBundleHash) === 1
            && hash_equals($mtfBundleHash, (string) data_get($mtfManifest, 'bundle_hash', ''))
            && data_get($mtfManifest, 'validation_bundle_protocol') === 'agent_owned_mtf_foundation_bundle_v1'
            && data_get($mtfManifest, 'data_role') === 'pre_2026_foundation_training_only'
            && data_get($mtfManifest, 'promotion_evidence') === false;
        if (strtoupper($lab->symbol) !== 'XAUUSD' || $dataHash === '' || $executionHash === '' || ! $pre2026Attested || ! $mtfValid) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason' => 'XAUUSD_FROZEN_HASH_AND_PRE2026_ATTESTATION_REQUIRED', 'promotion_evidence' => false];
        }
        if (! hash_equals((string) $canonicalExecution['execution_hash'], $executionHash)) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason' => 'CANONICAL_EXECUTION_HASH_REQUIRED', 'promotion_evidence' => false];
        }
        // Coverage passports must exist before the first queue dispatch. A
        // generation may never rely on a later worker to lazily freeze its
        // training/paper split; that race previously quarantined a complete
        // 20-seat cohort after it had already been registered as queued.
        if (! $this->canonicalDatasetSnapshotsValid($canonicalDatasetSnapshots, $dataHash)) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked',
                'reason' => 'CANONICAL_GENERATION_COVERAGE_MUST_BE_SEALED_BEFORE_DISPATCH',
                'promotion_evidence' => false];
        }
        $existing = DB::table('edge_genesis_passports')->where('symbol', strtoupper($lab->symbol))->where('timeframe', strtoupper($lab->timeframe))
            ->whereIn('status', ['queued', 'running'])->exists();
        if ($existing) {
            return ['protocol' => self::PROTOCOL, 'status' => 'already_materialized', 'promotion_evidence' => false];
        }
        $governorAdmission = $this->ratchetGovernor->admitExpensivePacket([
            'structural_axis' => (string) data_get($repairContract, 'structural_axis', ''),
            'decisive_outcome_probability' => .5, 'causal_depth_gain' => 1,
            'transfer_potential' => $architectureRevision === self::EVIDENCE_COMPILED_REVISION ? .5 : .35,
            'novelty' => $architectureRevision === self::INITIAL_REVISION ? .5 : .4,
            'compute_cost' => $architectureRevision === self::EVIDENCE_COMPILED_REVISION ? 5 : count($this->packets()),
        ], $lab->symbol, $lab->timeframe);
        if (! ($governorAdmission['allowed'] ?? false)) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked',
                'reason' => $governorAdmission['reason'], 'governor' => $governorAdmission, 'promotion_evidence' => false];
        }
        $packetKeys = (array) ($repairContract['packet_keys'] ?? []);
        $packets = $architectureRevision === self::EVIDENCE_COMPILED_REVISION
            ? [(array) ($repairContract['packet'] ?? [])]
            : ($packetKeys === [] ? $this->packets() : array_values(array_filter(
                $this->packets(), fn (array $packet): bool => in_array($packet['key'], $packetKeys, true),
            )));
        if ($packets === []) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked',
                'reason' => 'REPAIR_PACKET_CONTRACT_EMPTY', 'promotion_evidence' => false];
        }
        $arms = $architectureRevision === self::EVIDENCE_COMPILED_REVISION
            ? array_values((array) ($repairContract['arms'] ?? []))
            : $this->armsForRevision($architectureRevision);
        if ($arms !== $this->armsForRevision($architectureRevision)) {
            return ['protocol' => self::PROTOCOL,
                'status' => 'blocked', 'reason' => 'COMPILED_HYPOTHESIS_ARM_CONTRACT_INVALID', 'promotion_evidence' => false];
        }
        $windowPlan = $this->cohortIdentity->windowPlan($dataHash, $mtfBundleHash);
        $compilerDefinition = $architectureRevision === self::EVIDENCE_COMPILED_REVISION
            ? (array) ($repairContract['definition'] ?? []) : [];
        $packetDefinitionHash = $architectureRevision === self::EVIDENCE_COMPILED_REVISION
            ? $this->cohortIdentity->hash($compilerDefinition)
            : $this->cohortIdentity->packetDefinitionHash($packets, $arms);
        if ($architectureRevision === self::EVIDENCE_COMPILED_REVISION
            && ! hash_equals((string) ($repairContract['packet_definition_hash'] ?? ''), $packetDefinitionHash)) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked',
                'reason' => 'COMPILED_PACKET_DEFINITION_HASH_MISMATCH', 'promotion_evidence' => false];
        }
        $cohortKey = $this->cohortIdentity->cohortKey(strtoupper($lab->symbol), $dataHash, $mtfBundleHash,
            $executionHash, $architectureRevision, $packetDefinitionHash, (string) $windowPlan['window_plan_hash']);
        $generation = null;
        $agents = [];
        $kernel = null;
        $cohortCreated = false;
        DB::transaction(function () use ($lab, $dataHash, $executionHash, $canonicalExecution, $mtfManifest, $mtfBundleHash, $packets, $arms, $repairContract, $architectureRevision, $canonicalDatasetSnapshots, $windowPlan, $packetDefinitionHash, $cohortKey, &$generation, &$agents, &$kernel, &$cohortCreated): void {
            $hypothesisId = null;
            if ($architectureRevision === self::EVIDENCE_COMPILED_REVISION) {
                DB::table('edge_hypothesis_packets')->insertOrIgnore([
                    'hypothesis_key' => (string) $repairContract['hypothesis_key'],
                    'symbol' => strtoupper($lab->symbol), 'timeframe' => strtoupper($lab->timeframe),
                    'source_generation_id' => (int) $repairContract['source_generation_id'],
                    'architecture_revision' => $architectureRevision,
                    'diagnosis' => (string) data_get($repairContract, 'diagnosis.code', 'unknown'),
                    'structural_axis' => (string) $repairContract['structural_axis'],
                    'packet_definition_hash' => $packetDefinitionHash, 'status' => 'registered',
                    'definition' => json_encode((array) $repairContract['definition'], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                    'evidence' => json_encode(['protocol' => EdgeHypothesisCompilerService::PROTOCOL,
                        'professional_prior' => data_get($repairContract, 'professional_prior'),
                        'failure_dojo' => data_get($repairContract, 'failure_dojo'),
                        'authority_contract' => data_get($repairContract, 'authority_contract'),
                        'promotion_evidence' => false], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $hypothesisId = DB::table('edge_hypothesis_packets')->where('hypothesis_key', $repairContract['hypothesis_key'])->value('id');
            }
            $cohortCreated = DB::table('edge_genesis_cohorts')->insertOrIgnore([
                'cohort_key' => $cohortKey, 'edge_hypothesis_packet_id' => $hypothesisId,
                'symbol' => strtoupper($lab->symbol), 'timeframe' => strtoupper($lab->timeframe),
                'architecture_revision' => $architectureRevision, 'data_hash' => $dataHash,
                'mtf_bundle_hash' => $mtfBundleHash, 'execution_hash' => $executionHash,
                'packet_definition_hash' => $packetDefinitionHash,
                'frozen_window_plan_hash' => (string) $windowPlan['window_plan_hash'],
                'status' => 'registered',
                'admission_snapshot' => json_encode((array) data_get($repairContract, 'director_admission_snapshot', []), JSON_UNESCAPED_SLASHES),
                'evidence' => json_encode(['protocol' => EdgeCohortIdentityService::PROTOCOL,
                    'window_plan' => $windowPlan, 'promotion_evidence' => false], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                'created_at' => now(), 'updated_at' => now(),
            ]) === 1;
            if (! $cohortCreated) {
                return;
            }
            $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => ((int) $lab->generations()->max('generation')) + 1,
                'trigger_type' => 'edge_genesis', 'trigger_context' => ['protocol' => self::PROTOCOL, 'phase' => 'EDGE_DISCOVERY', 'data_hash' => $dataHash,
                    'execution_hash' => $executionHash, 'mtf_bundle_hash' => $mtfBundleHash, 'mtf_bundle_manifest' => $mtfManifest,
                    'architecture_revision' => $architectureRevision, 'cohort_key' => $cohortKey,
                    'packet_definition_hash' => $packetDefinitionHash, 'frozen_window_plan' => $windowPlan,
                    ...($repairContract !== [] ? ['causal_repair_contract' => $this->withoutLargeRepairPayload($repairContract)] : []),
                    ...($canonicalDatasetSnapshots !== [] ? ['canonical_dataset_snapshots' => $canonicalDatasetSnapshots] : []),
                    'risk_mutation_budget' => 0, 'pre_2026_only' => true, 'research_only' => true, 'promotion_evidence' => false],
                'data_fingerprint' => $dataHash, 'population_size' => CausalCompoundingKernelService::POPULATION_SIZE, 'status' => 'queued', 'started_at' => now()]);
            DB::table('edge_genesis_cohorts')->where('cohort_key', $cohortKey)->update([
                'lab_generation_id' => $generation->id, 'status' => 'queued', 'updated_at' => now(),
            ]);
            if ($architectureRevision === self::EVIDENCE_COMPILED_REVISION) {
                DB::table('edge_hypothesis_packets')->where('id', $hypothesisId)->update([
                    'materialized_generation_id' => $generation->id, 'status' => 'materialized', 'updated_at' => now(),
                ]);
            }
            if ($architectureRevision !== self::INITIAL_REVISION) {
                $context = (array) $generation->trigger_context;
                foreach (['foundation', 'price'] as $snapshotKey) {
                    if (data_get($context, "canonical_dataset_snapshots.{$snapshotKey}.causal_reuse.protocol") === 'edge_generation_coverage_snapshot_reuse_v1') {
                        data_set($context, "canonical_dataset_snapshots.{$snapshotKey}.causal_reuse.target_generation_id", $generation->id);
                    }
                }
                $generation->update(['trigger_context' => $context]);
            }
            foreach ($packets as $packetIndex => $packet) {
                $packetAgents = [];
                $key = hash('sha256', implode('|', [self::PROTOCOL, $architectureRevision, $generation->id, $packet['key'], $dataHash, $executionHash]));
                $sourceModelId = $architectureRevision === self::EVIDENCE_COMPILED_REVISION
                    ? (int) data_get($repairContract, 'source_model_version_id', 0)
                    : (int) data_get($repairContract, 'source_model_version_ids.'.$packet['key'], 0);
                $passportId = DB::table('edge_genesis_passports')->insertGetId(['genesis_key' => $key, 'lab_generation_id' => $generation->id,
                    'baseline_model_version_id' => $sourceModelId > 0 ? $sourceModelId : null, 'symbol' => strtoupper($lab->symbol), 'timeframe' => strtoupper($lab->timeframe), 'strategy_family' => 'hybrid',
                    'phase' => 'EDGE_DISCOVERY', 'status' => 'queued', 'data_hash' => $dataHash, 'execution_hash' => $executionHash,
                    'context' => json_encode($packet['context']), 'evidence' => json_encode(['protocol' => self::PROTOCOL, 'packet' => $packet,
                        'architecture_revision' => $architectureRevision, 'cohort_key' => $cohortKey,
                        'packet_definition_hash' => $packetDefinitionHash, 'frozen_window_plan' => $windowPlan,
                        'mtf_bundle_hash' => $mtfBundleHash, 'context_declared_before_replay' => true, 'risk_governor_frozen' => true,
                        'gene_credit_withheld_until_attribution' => true, 'promotion_evidence' => false]),
                    'phase_changed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                foreach ($arms as $armIndex => $arm) {
                    $armContext = $this->contextForArm($packet, $arm, $architectureRevision);
                    $sourceParameters = $architectureRevision === self::EVIDENCE_COMPILED_REVISION
                        ? (array) data_get($repairContract, 'source_parameters', [])
                        : (array) data_get($repairContract, 'source_parameters.'.$packet['key'], []);
                    $runtime = $this->runtimeForArm($packet, $arm, $architectureRevision, $sourceParameters);
                    $parameters = $runtime['parameters'];
                    $passport = $this->composition->freeze(['symbol' => 'XAUUSD', 'timeframe' => $lab->timeframe,
                        'strategy_id' => $packet['strategy_id'], 'tactic_id' => $this->tacticForArm($packet['tactic_id'], $arm), 'risk_id' => 'atr_risk_envelope',
                        'management_id' => $packet['management_id'], 'market_state' => $armContext, 'data_contract' => $this->temporalContractForArm($arm), 'data_hash' => $dataHash, 'execution_hash' => $executionHash]);
                    $metadata = ['edge_genesis' => ['protocol' => self::PROTOCOL, 'genesis_key' => $key, 'packet_key' => $packet['key'], 'arm' => $arm,
                        'emitter' => $packet['emitter'], 'phase' => 'EDGE_DISCOVERY', 'data_hash' => $dataHash, 'execution_hash' => $executionHash,
                        'architecture_revision' => $architectureRevision, 'cohort_key' => $cohortKey,
                        'packet_definition_hash' => $packetDefinitionHash, 'frozen_window_plan' => $windowPlan,
                        'validation_contract' => $windowPlan['stages']['two_fold_discovery'] + [
                            'stage' => 'two_fold_discovery', 'window_plan_hash' => $windowPlan['window_plan_hash'],
                            'universe_folds' => $windowPlan['universe_folds'],
                        ],
                        'causal_baseline_model_version_id' => $sourceModelId > 0 ? $sourceModelId : null,
                        'mtf_bundle_hash' => $mtfBundleHash, 'mtf_bundle_manifest' => $mtfManifest,
                        'context' => $armContext, 'execution_timeframe' => self::EXECUTION_TIMEFRAME,
                        'runtime_strategy' => $runtime['base_strategy'], 'risk_governor_frozen' => true, 'pre_2026_only' => true, 'research_only' => true,
                        'intervention_attestation' => ['protocol' => 'edge_genesis_intervention_attestation_v1',
                            'control_identity' => $arm === 'compiled_control', 'source_parameter_hash' => $this->parameterHash($sourceParameters),
                            'consumed_parameter_hash' => $this->parameterHash($parameters), 'actual_parameter_diff' => $this->diff($sourceParameters, $parameters),
                            'genetic_parent_model_version_id' => null, 'causal_baseline_model_version_id' => $sourceModelId > 0 ? $sourceModelId : null,
                            'promotion_evidence' => false], 'promotion_evidence' => false],
                        'edge_observability_contract' => ['protocol' => self::PROTOCOL, 'required_fields' => ['opportunity_detected', 'setup_location_valid', 'context_bias_aligned', 'confirmation', 'entry', 'execution_price', 'invalidation_price', 'mfe_mae', 'exit_outcome'], 'must_exist_before_nine_fold' => true],
                        'causal_baseline_model_version_id' => $sourceModelId > 0 ? $sourceModelId : null,
                        'genetic_parent_model_version_id' => null,
                        'lab_symbol' => strtoupper($lab->symbol), 'lab_timeframe' => strtoupper($lab->timeframe),
                        'semantic_group' => $this->edgeSemanticGroup($lab->symbol, $lab->timeframe, $runtime['family'], $packet),
                        'execution_contract' => $canonicalExecution,
                        'base_strategy' => $runtime['base_strategy'],
                        'smart_composition' => ['protocol' => StrategyTacticRiskCompositionPlannerService::PROTOCOL, 'composition_passport' => $passport,
                            'causal_packet' => ['packet_emitter' => $packet['emitter'], 'packet_id' => $key, 'arm' => $arm, 'promotion_evidence' => false], 'promotion_evidence' => false]];
                    // Every seat needs a unique public identity. run-all keys
                    // results by strategy; reusing `hybrid` made 20 distinct
                    // passports collapse into one replay result.
                    $runtimeLabel = 'edge_'.$packet['key'].'_g'.$generation->generation.'_a'.($armIndex + 1);
                    $model = ModelVersion::create(['name' => $this->boundedModelName((int) $generation->generation,
                        (string) $packet['label'], (string) $arm, $packetDefinitionHash), 'strategy' => $runtimeLabel,
                        'version' => 'edge-g'.$generation->generation.'-p'.($packetIndex + 1).'-a'.($armIndex + 1), 'generation' => $generation->generation,
                        'status' => 'testing', 'description' => 'Dependency-aware Edge Genesis research arm.', 'change_log' => $packet['label'].' '.$arm,
                        'parameters' => $parameters, 'metadata' => $metadata, 'evidence_status' => 'valid']);
                    $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id, 'parent_a_model_version_id' => null,
                        'symbol' => $lab->symbol, 'timeframe' => $lab->timeframe, 'strategy_family' => $runtime['family'], 'origin' => 'edge_genesis',
                        'lifecycle_status' => 'full_queued', 'parameter_diff' => $this->diff($sourceParameters, $parameters),
                        'decision_reason' => 'Pre-registered Edge Genesis arm; frozen risk governor; genetic parent withheld.']);
                    DB::table('edge_genesis_trials')->insert(['trial_key' => hash('sha256', $key.'|'.$arm), 'edge_genesis_passport_id' => $passportId,
                        'lab_agent_id' => $agent->id, 'model_version_id' => $model->id, 'packet_key' => $packet['key'], 'emitter' => $packet['emitter'],
                        'arm' => $arm, 'stage' => 'two_fold_discovery', 'status' => 'queued', 'evidence' => json_encode(['protocol' => self::PROTOCOL,
                            'architecture_revision' => $architectureRevision, 'composition_passport' => $passport['composition_id'],
                            'cohort_key' => $cohortKey, 'packet_definition_hash' => $packetDefinitionHash,
                            'frozen_window_plan' => $windowPlan,
                            'risk_governor_frozen' => true, 'promotion_evidence' => false]), 'created_at' => now(), 'updated_at' => now()]);
                    $this->mastery->enroll($agent, $passport, ['packet_key' => $packet['key'], 'arm' => $arm, 'data_hash' => $dataHash,
                        'execution_hash' => $executionHash, 'edge_genesis_passport_id' => $passportId]);
                    $agents[] = $agent;
                    $packetAgents[] = $agent;
                }
                // A root packet has no genetic parent, but it still needs a
                // real scientific baseline. Bind every arm to the packet's
                // frozen control after all five immutable identities exist.
                if ($sourceModelId <= 0) {
                    $packetControl = collect($packetAgents)->first(fn (LabAgent $candidate): bool => in_array((string) data_get($candidate->modelVersion?->metadata, 'edge_genesis.arm'), self::TRAVELING_CONTROL_ARMS, true)
                    );
                    if (! $packetControl instanceof LabAgent) {
                        throw new \RuntimeException('EDGE_ROOT_FROZEN_CAUSAL_CONTROL_MISSING');
                    }
                    DB::table('edge_genesis_passports')->where('id', $passportId)->update([
                        'baseline_model_version_id' => $packetControl->model_version_id,
                        'updated_at' => now(),
                    ]);
                    foreach ($packetAgents as $packetAgent) {
                        $packetMetadata = (array) $packetAgent->modelVersion->metadata;
                        $packetMetadata['causal_baseline_model_version_id'] = (int) $packetControl->model_version_id;
                        $packetMetadata['genetic_parent_model_version_id'] = null;
                        data_set($packetMetadata, 'edge_genesis.causal_baseline_model_version_id', (int) $packetControl->model_version_id);
                        data_set($packetMetadata, 'edge_genesis.intervention_attestation.causal_baseline_model_version_id', (int) $packetControl->model_version_id);
                        $packetAgent->modelVersion->update(['metadata' => $packetMetadata]);
                    }
                }
                $this->recordComputeLedger($passportId, $lab->symbol, $lab->timeframe, 'EDGE_DISCOVERY');
            }
            if (count($agents) < CausalCompoundingKernelService::POPULATION_SIZE) {
                $kernelBaselineAgent = collect($agents)->first(fn (LabAgent $candidate): bool => in_array((string) data_get($candidate->modelVersion?->metadata, 'edge_genesis.arm'), self::TRAVELING_CONTROL_ARMS, true)
                ) ?? collect($agents)->first();
                if (! $kernelBaselineAgent instanceof LabAgent || ! $kernelBaselineAgent->modelVersion) {
                    throw new \RuntimeException('EDGE_COMPOUNDING_BASELINE_CONTROL_MISSING');
                }
                $protectedGenes = collect($agents)->flatMap(
                    fn (LabAgent $candidate): array => array_keys((array) $candidate->parameter_diff),
                )->unique()->values()->all();
                $kernel = $this->compoundingKernel->complete(
                    $generation,
                    $kernelBaselineAgent->modelVersion,
                    $kernelBaselineAgent,
                    $dataHash,
                    $executionHash,
                    'edge_quality',
                    $protectedGenes,
                );
            }
        });
        if (! $cohortCreated) {
            return ['protocol' => self::PROTOCOL, 'status' => 'already_materialized',
                'reason' => 'EXACT_COHORT_IDENTITY_ALREADY_REGISTERED', 'cohort_key' => $cohortKey,
                'packet_definition_hash' => $packetDefinitionHash, 'promotion_evidence' => false];
        }
        foreach ($agents as $agent) {
            EvaluateLabAgentJob::dispatch($agent->id, $agent->symbol, 'full');
        }
        foreach ((array) data_get($kernel, 'dispatches', []) as $dispatch) {
            EvaluateLabAgentJob::dispatch($dispatch['agent']->id, $dispatch['agent']->symbol, $dispatch['mode']);
        }

        return ['protocol' => self::PROTOCOL, 'status' => 'queued', 'generation_id' => $generation?->id,
            'architecture_revision' => $architectureRevision, 'cohort_key' => $cohortKey,
            'packet_definition_hash' => $packetDefinitionHash, 'frozen_window_plan_hash' => $windowPlan['window_plan_hash'],
            'seats' => CausalCompoundingKernelService::POPULATION_SIZE,
            'primary_proof_seats' => count($agents),
            'compounding_kernel' => data_get($kernel, 'contract'), 'promotion_evidence' => false];
    }

    private function boundedModelName(int $generation, string $label, string $arm, string $definitionHash): string
    {
        $prefix = 'Edge G'.$generation.' ';
        $suffix = ' '.substr($definitionHash, 0, 8).' '.$arm;
        $available = max(0, 96 - mb_strlen($prefix) - mb_strlen($suffix));

        return $prefix.mb_substr($label, 0, $available).$suffix;
    }

    /**
     * Open one bounded repair wave only when the previous immutable cohort
     * proves that opportunity/setup exists but confirmation breadth starves
     * every entry. The same data, execution and MTF identities are reused so
     * the new one-axis arm is causally comparable with the failed cohort.
     */
    public function materializeConfirmationBreadthRepair(AiLaboratory $lab, bool $apply = false): array
    {
        $assessment = $this->architectureRepairAssessment($lab->symbol, $lab->timeframe);
        $public = $this->withoutMaterializationContract($assessment);
        if (! ($assessment['admitted'] ?? false)
            || ($assessment['repair_revision'] ?? null) !== self::CONFIRMATION_REPAIR_REVISION) {
            return $public;
        }
        if (! $apply) {
            return [...$public, 'status' => 'would_queue', 'seats' => CausalCompoundingKernelService::POPULATION_SIZE,
                'primary_proof_seats' => count($this->packets()) * count(self::GENESIS_ARMS)];
        }

        $contract = (array) ($assessment['materialization_contract'] ?? []);

        return $this->materialize(
            $lab,
            (string) ($contract['data_hash'] ?? ''),
            (string) ($contract['execution_hash'] ?? ''),
            true,
            (array) ($contract['mtf_bundle'] ?? []),
            self::CONFIRMATION_REPAIR_REVISION,
            (array) ($contract['canonical_dataset_snapshots'] ?? []),
        );
    }

    /** Materialize the next evidence-admitted repair without skipping a dependency. */
    public function materializeNextArchitectureRepair(AiLaboratory $lab, bool $apply = false): array
    {
        $assessment = $this->architectureRepairAssessment($lab->symbol, $lab->timeframe);
        $public = $this->withoutMaterializationContract($assessment);
        if (! ($assessment['admitted'] ?? false)) {
            return $public;
        }

        $revision = (string) ($assessment['repair_revision'] ?? '');
        if (! in_array($revision, [self::CONFIRMATION_REPAIR_REVISION, self::TRIGGER_REPAIR_REVISION, self::LATENT_HARVEST_REVISION, self::CONTEXT_ROUTER_REPAIR_REVISION, self::REGIME_ENTRY_SYNTHESIS_REVISION, self::FAILURE_CELL_FACTORIAL_REVISION, self::SPECIALIST_DENSIFICATION_REVISION, self::TEMPORAL_BREAKOUT_BINDING_REVISION, self::M15_SETUP_QUALITY_REVISION], true)) {
            return [...$public, 'status' => 'blocked', 'reason' => 'UNKNOWN_ADMITTED_REPAIR_REVISION'];
        }
        if (! $apply) {
            return [...$public, 'status' => 'would_queue',
                'seats' => CausalCompoundingKernelService::POPULATION_SIZE,
                'primary_proof_seats' => ((string) ($assessment['selected_packet'] ?? '') !== '' ? 1 : count($this->packets()))
                    * count($this->armsForRevision($revision))];
        }

        $contract = (array) ($assessment['materialization_contract'] ?? []);

        return $this->materialize(
            $lab,
            (string) ($contract['data_hash'] ?? ''),
            (string) ($contract['execution_hash'] ?? ''),
            true,
            (array) ($contract['mtf_bundle'] ?? []),
            $revision,
            (array) ($contract['canonical_dataset_snapshots'] ?? []),
        );
    }

    /** @return array<string,mixed> */
    public function architectureRepairReadiness(string $symbol, string $timeframe): array
    {
        return $this->withoutMaterializationContract($this->architectureRepairAssessment($symbol, $timeframe));
    }

    /** Materialize one compiler-issued packet without reopening a fixed revision. */
    public function materializeCompiledHypothesis(AiLaboratory $lab, array $hypothesis, bool $apply = false): array
    {
        if (data_get($hypothesis, 'protocol') !== EdgeHypothesisCompilerService::PROTOCOL
            || data_get($hypothesis, 'admitted') !== true) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked',
                'reason' => 'ADMITTED_EDGE_HYPOTHESIS_REQUIRED', 'promotion_evidence' => false];
        }
        $governorAdmission = $this->ratchetGovernor->admitExpensivePacket([
            ...$hypothesis, 'causal_depth_gain' => 1, 'transfer_potential' => .5,
            'novelty' => .5, 'compute_cost' => max(1, count((array) ($hypothesis['arms'] ?? []))),
        ], $lab->symbol, $lab->timeframe);
        if (! ($governorAdmission['allowed'] ?? false)) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked',
                'reason' => $governorAdmission['reason'], 'governor' => $governorAdmission, 'promotion_evidence' => false];
        }
        if (! $apply) {
            return ['protocol' => self::PROTOCOL, 'status' => 'would_queue',
                'seats' => CausalCompoundingKernelService::POPULATION_SIZE,
                'primary_proof_seats' => count((array) ($hypothesis['arms'] ?? [])),
                'hypothesis_key' => $hypothesis['hypothesis_key'] ?? null,
                'packet_definition_hash' => $hypothesis['packet_definition_hash'] ?? null,
                'governor' => $governorAdmission,
                'promotion_evidence' => false];
        }

        return $this->materialize(
            $lab,
            (string) ($hypothesis['data_hash'] ?? ''),
            (string) ($hypothesis['execution_hash'] ?? ''),
            true,
            (array) ($hypothesis['mtf_bundle'] ?? []),
            self::EVIDENCE_COMPILED_REVISION,
            (array) ($hypothesis['canonical_dataset_snapshots'] ?? []),
            $hypothesis,
        );
    }

    /** Settle a full replay into the edge state machine; never grants promotion. */
    public function settleOutcome(LabAgent $agent, array $result): array
    {
        if (data_get($agent->modelVersion?->metadata, 'edge_genesis_attribution.protocol') === self::PROTOCOL) {
            return $this->settleAttributionOutcome($agent);
        }
        $contract = (array) data_get($agent->modelVersion?->metadata, 'edge_genesis', []);
        if (data_get($contract, 'protocol') !== self::PROTOCOL) {
            return ['status' => 'not_edge_genesis', 'promotion_evidence' => false];
        }
        $passport = DB::table('edge_genesis_passports')->where('genesis_key', data_get($contract, 'genesis_key'))->first();
        if (! $passport) {
            return ['status' => 'blocked', 'reason' => 'EDGE_GENESIS_PASSPORT_MISSING', 'promotion_evidence' => false];
        }
        $trial = DB::table('edge_genesis_trials')->where('lab_agent_id', $agent->id)->first();
        $stage = (string) ($trial->stage ?? 'two_fold_discovery');
        $replicationReplay = $stage === 'three_fold_confirmation';
        $confirmationReplay = $stage === 'nine_fold_authority';
        $arm = (string) ($trial->arm ?? '');
        $frozenControl = $arm === 'frozen_control';
        $travelingControl = in_array($arm, self::TRAVELING_CONTROL_ARMS, true);
        $pairedTravelingControl = $confirmationReplay && DB::table('edge_genesis_trials')
            ->where('edge_genesis_passport_id', $passport->id)
            ->whereIn('arm', self::TRAVELING_CONTROL_ARMS)
            ->where('stage', 'nine_fold_authority')->exists();
        $controlRole = $frozenControl || $travelingControl;
        $data = (string) data_get($result, 'data_manifest.sha256', data_get($result, 'data_hash'));
        $execution = (string) data_get($result, 'execution_contract.execution_hash', data_get($result, 'execution_hash'));
        $expectedMtfBundle = (string) data_get($contract, 'mtf_bundle_hash', '');
        $observedMtfBundle = (string) data_get($result, 'data_manifest.mtf_bundle_hash', data_get($result, 'mtf_snapshot_manifest.bundle_hash', ''));
        $mtfBundleMatch = $expectedMtfBundle !== '' && $observedMtfBundle !== '' && hash_equals($expectedMtfBundle, $observedMtfBundle);
        $observable = $this->observability($result);
        $hashesMatch = hash_equals((string) $passport->data_hash, $data)
            && hash_equals((string) $passport->execution_hash, $execution) && $mtfBundleMatch;
        $contextEnforcementRequired = data_get($contract, 'context.enforcement') === 'required';
        $discovery = $this->discoveryAdmission(
            $result,
            $contextEnforcementRequired,
            array_values((array) data_get($contract, 'context.admission_axes', [])),
            (bool) data_get($contract, 'context.m15_setup_quality_experiment', false),
        );
        $admission = $this->edgeAdmission($result, $contextEnforcementRequired);
        $admissionPassed = $this->edgeAdmissionPassed($result, $contextEnforcementRequired);
        $partition = $this->stagePartitionEvidence($agent, $result, $stage);
        $partitionRequired = data_get($contract, 'frozen_window_plan.protocol') === EdgeCohortIdentityService::WINDOW_PROTOCOL;
        $stageMastery = $this->stageMasteryAssessment($agent, $trial, $passport, $result, $stage, $controlRole);
        // Every settled Edge replay is registered as an Academy composition.
        // This only freezes the next curriculum axis; it does not submit an
        // Academy arm, use hindsight at runtime, or create promotion credit.
        $academyPassport = $this->academy->passport($agent->symbol, $agent->timeframe, [
            'composition_key' => (string) $passport->genesis_key,
            'strategy_family' => (string) ($agent->strategy_family ?? data_get($contract, 'packet.strategy_family', 'hybrid')),
            'context' => (array) data_get($contract, 'context', []),
            'temporal_roles' => (array) data_get($contract, 'packet.temporal_roles', data_get($contract, 'temporal_roles', [])),
            'risk_contract' => (array) data_get($contract, 'risk_contract', []),
            'management_contract' => (array) data_get($contract, 'management_contract', []),
            'deepest_stage' => $this->academyStageFor($stageMastery),
        ], ['edge_genesis_trial_id' => $trial?->id, 'data_hash' => $data, 'execution_hash' => $execution]);
        $academyDiagnostic = (array) data_get($result, 'edge_formation_academy_diagnostic', []);
        $academyOracle = ['status' => 'not_assessed_oracle_envelope_unavailable', 'promotion_evidence' => false];
        if (data_get($academyDiagnostic, 'status') === 'full_oracle_gap_observed'
            && is_numeric(data_get($academyDiagnostic, 'oracle_opportunity_edge_r'))) {
            $entryEnvelope = data_get($academyDiagnostic, 'entry_envelope_after_cost_r');
            $academyOracle = $this->academy->assessOracleGap((int) $academyPassport['passport_id'], $academyDiagnostic, [
                'data_hash' => $data, 'execution_hash' => $execution,
                // An absent entry envelope means no eligible entry path was
                // observed; it is an entry-mastery diagnosis, not a zero
                // loss fabricated for a separate component.
                'real_entry_after_cost_r' => is_numeric($entryEnvelope) ? (float) $entryEnvelope : -0.000001,
                'realized_after_cost_r' => data_get($academyDiagnostic, 'realized_after_cost_r', data_get($result, 'after_cost_expectancy_r', 0)),
                'management_capture_loss_r' => data_get($academyDiagnostic, 'management_capture_loss_r'),
            ]);
        }
        $academyNext = $this->academy->nextExperiment((int) $academyPassport['passport_id'], $academyOracle);
        $academyBeam = $this->academy->archiveBeam((int) $academyPassport['passport_id'], (string) $academyPassport['deepest_stage'], [
            'composition_key' => (string) $passport->genesis_key,
            'strategy' => data_get($contract, 'packet.strategy_id', $agent->strategy_family),
            'tactic' => data_get($contract, 'packet.tactic_id'), 'context' => data_get($contract, 'context'),
            'temporal_binding' => data_get($contract, 'packet.temporal_roles', data_get($contract, 'temporal_roles')),
            'quality_key' => hash('sha256', json_encode([$passport->genesis_key, $agent->model_version_id, $stage, $arm, $agent->modelVersion?->parameters], JSON_UNESCAPED_SLASHES)),
            'score' => (float) data_get($result, 'after_cost_expectancy_r', 0) + ((int) data_get($result, 'entry_contract_funnel.stage_counts.trigger', 0) / 1000),
        ]);

        if (! $observable) {
            $status = 'invalid_edge_observability';
            $next = ($confirmationReplay || $replicationReplay) ? 'EDGE_CONFIRMATION' : 'EDGE_DISCOVERY';
        } elseif (! $hashesMatch) {
            $status = 'invalid_hash_mismatch';
            $next = ($confirmationReplay || $replicationReplay) ? 'EDGE_CONFIRMATION' : 'EDGE_DISCOVERY';
        } elseif ($partitionRequired && ! ($partition['valid'] ?? false)) {
            $status = 'invalid_window_partition';
            $next = ($confirmationReplay || $replicationReplay) ? 'EDGE_CONFIRMATION' : 'EDGE_DISCOVERY';
        } elseif ($replicationReplay && $controlRole) {
            $status = 'replication_control_settled';
            $next = 'EDGE_CONFIRMATION';
        } elseif ($replicationReplay) {
            // Three folds prove a paired treatment effect, not standalone
            // profitability. Absolute economic authority belongs only to the
            // later nine-fold barrier.
            $status = 'replication_observed';
            $next = 'EDGE_CONFIRMATION';
        } elseif ($frozenControl || ($confirmationReplay && $travelingControl)) {
            // A frozen control is valuable causal reference evidence, but it
            // can never establish the professional composition's edge.  The
            // latent-harvest source composition is also a causal control at
            // nine-fold authority; it may pass discovery so it can accompany
            // a promising management treatment, but cannot itself advance.
            $status = 'control_settled';
            $next = $confirmationReplay ? 'EDGE_CONFIRMATION' : 'EDGE_DISCOVERY';
        } elseif ($confirmationReplay && $pairedTravelingControl) {
            // Context/management specialists receive no causal authority in
            // isolation. Their absolute edge result waits behind the complete
            // exact-control cohort barrier below.
            $status = $admissionPassed ? 'authority_observed' : 'edge_not_confirmed';
            $next = 'EDGE_CONFIRMATION';
        } elseif ($confirmationReplay) {
            $status = $admissionPassed ? 'edge_progressing' : 'edge_not_confirmed';
            $next = $admissionPassed ? 'EDGE_ATTRIBUTION' : 'EDGE_CONFIRMATION';
        } else {
            // Discovery is an information-gain screen.  Requiring a bootstrap
            // PF lower bound here made advancement impossible because that
            // authority statistic belongs to the later nine-fold replay.
            $status = $discovery['passed'] ? 'edge_progressing' : 'edge_not_found';
            $next = $discovery['passed'] ? 'EDGE_CONFIRMATION' : 'EDGE_DISCOVERY';
        }
        // The pair's selection barrier owns its trial verdict. A local no-op
        // is recorded immediately in the Ratchet, but it cannot overwrite a
        // later comparative decision (for example `discovery_dominated`).
        // The compiler subsequently consumes that durable retirement before
        // it can fund the same scalar axis again.
        $ratchetId = data_get($agent->modelVersion?->metadata, 'causal_progress_ratchet.ratchet_id');
        $ratchetProgress = null;
        if ($ratchetId) {
            $progressPhase = $stage === 'three_fold_confirmation' ? 'CONFIRMATION_SETTLED'
                : ($stage === 'nine_fold_authority' ? 'REPLICATION_SETTLED' : 'DISCOVERED');
            $ratchetProgress = $this->ratchetGovernor->progress([
                'ratchet_id' => $ratchetId, 'symbol' => $agent->symbol, 'timeframe' => $agent->timeframe,
                'composition_key' => (string) $passport->genesis_key, 'causal_baseline_id' => data_get($agent->modelVersion?->metadata, 'causal_progress_ratchet.causal_baseline_id', 0),
                'baseline_epoch_hash' => (string) data_get($agent->modelVersion?->metadata, 'causal_progress_ratchet.baseline_epoch_hash', 'unknown'),
                'data_hash' => (string) $passport->data_hash, 'execution_hash' => (string) $passport->execution_hash,
                'window_plan_hash' => (string) data_get($contract, 'frozen_window_plan.window_plan_hash', ''),
                'intervention_hash' => $this->parameterHash((array) $agent->modelVersion->parameters),
            ], $progressPhase, $status, ['edge_stage' => $stage, 'trial_id' => $trial?->id]);
            if ($confirmationReplay && $admissionPassed && ! $controlRole) {
                $economic = $this->ratchetGovernor->recordPositiveAfterCostEdge([
                    'symbol' => $agent->symbol, 'timeframe' => $agent->timeframe, 'composition_key' => (string) $passport->genesis_key,
                    'causal_baseline_id' => data_get($agent->modelVersion?->metadata, 'causal_progress_ratchet.causal_baseline_id', 0),
                    'baseline_epoch_hash' => (string) data_get($agent->modelVersion?->metadata, 'causal_progress_ratchet.baseline_epoch_hash', 'unknown'),
                    'data_hash' => (string) $passport->data_hash, 'execution_hash' => (string) $passport->execution_hash,
                ], $result);
                $ratchetProgress = [...$ratchetProgress, 'economic_edge' => $economic];
                if (($economic['status'] ?? '') === 'economic_edge_recorded') {
                    $metadata = (array) $agent->modelVersion->metadata;
                    data_set($metadata, 'causal_progress_ratchet.deepest_stage', 'positive_after_cost_edge');
                    data_set($metadata, 'causal_progress_ratchet.authority', 'economic_edge_confirmed');
                    data_set($metadata, 'causal_progress_ratchet.parent_authority', false);
                    data_set($metadata, 'causal_progress_ratchet.paper_authority', false);
                    $agent->modelVersion->update(['metadata' => $metadata]);
                    $agent->setRelation('modelVersion', $agent->modelVersion->fresh());
                }
            }
        }

        // Settlement is an append-only projection.  Comparative authority
        // decisions and causal effects may already have been attached by a
        // later state-machine pass; never erase them while rebuilding the
        // economic verdict from immutable replay metrics.
        $existingEvidence = (array) json_decode((string) ($trial->evidence ?? '{}'), true);
        $stageHistory = (array) ($existingEvidence['stage_history'] ?? []);
        // A valid stage partition becomes immutable causal evidence. Invalid
        // attempts remain visible in stage_partition, but do not poison a
        // bounded technical retry with a permanently frozen bad identity.
        if (($partition['valid'] ?? false) === true && ! isset($stageHistory[$stage])) {
            $stageHistory[$stage] = [
                'window_identity' => (array) ($partition['window_identity'] ?? []),
                'window_plan_hash' => $partition['window_plan_hash'] ?? null,
                'fold_count' => $partition['observed_fold_count'] ?? null,
                'fold_offset' => $partition['declared_offset'] ?? null,
                'recorded_at' => now()->utc()->toIso8601String(),
                'promotion_evidence' => false,
            ];
        }
        $settlementEvidence = [...$existingEvidence,
            'protocol' => self::PROTOCOL, 'observability' => $observable, 'hashes_match' => $hashesMatch,
            'discovery_verdict_revision' => self::DISCOVERY_VERDICT_REVISION,
            'mtf_bundle_match' => $mtfBundleMatch, 'expected_mtf_bundle_hash' => $expectedMtfBundle,
            'observed_mtf_bundle_hash' => $observedMtfBundle, 'control_role' => $controlRole,
            'discovery_admission' => $discovery, 'admission' => $admission,
            'stage_partition' => $partition, 'stage_history' => $stageHistory,
            'causal_stage_mastery' => $stageMastery,
            'edge_formation_academy' => ['passport' => $academyPassport,
                'replay_diagnostic' => $academyDiagnostic, 'oracle_settlement' => $academyOracle,
                'next_experiment' => $academyNext, 'beam_archive' => $academyBeam,
                'promotion_evidence' => false],
            'causal_progress_ratchet' => $ratchetProgress,
            'promotion_evidence' => false,
        ];
        DB::table('edge_genesis_trials')->where('lab_agent_id', $agent->id)->update(['status' => $status,
            'stage' => $stage,
            'evidence' => json_encode($settlementEvidence, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
            'settled_at' => now(), 'updated_at' => now()]);
        $current = (string) $passport->phase;
        if ($hashesMatch && $observable && array_search($next, self::PHASES, true) > array_search($current, self::PHASES, true)) {
            DB::table('edge_genesis_passports')->where('id', $passport->id)->update(['phase' => $next, 'status' => 'running', 'phase_changed_at' => now(), 'updated_at' => now()]);
            ModelVersion::query()->where('id', $agent->model_version_id)->update(['metadata' => [...((array) $agent->modelVersion->metadata), 'edge_genesis' => [...$contract, 'phase' => $next]]]);
        }
        $terminal = ['invalid_edge_observability', 'invalid_hash_mismatch', 'invalid_window_partition',
            'control_settled', 'replication_control_settled', 'replication_observed', 'authority_observed', 'edge_progressing',
            'edge_replication_passed', 'edge_not_found', 'edge_not_confirmed', 'non_controlling_axis'];
        $packetTrials = DB::table('edge_genesis_trials')->where('edge_genesis_passport_id', $passport->id)
            ->where('packet_key', 'not like', '%:attribution');
        if (! (clone $packetTrials)->whereNotIn('status', $terminal)->exists()
            && ! (clone $packetTrials)->where('status', 'edge_progressing')->exists()) {
            // A completed non-viable packet is terminal research evidence,
            // not active work. Leaving it `queued` made idempotent directors
            // wait forever and hid a genuine architecture verdict.
            DB::table('edge_genesis_passports')->where('id', $passport->id)->update([
                'status' => ($confirmationReplay || $replicationReplay) ? 'edge_not_confirmed' : 'edge_not_found',
                'phase_changed_at' => now(), 'updated_at' => now(),
            ]);
        }
        if ($confirmationReplay && $pairedTravelingControl) {
            $this->reconcileNineFoldContextAuthority($agent->symbol, $agent->timeframe, true);
            $freshTrial = DB::table('edge_genesis_trials')->where('id', $trial->id)->first();
            $freshPassport = DB::table('edge_genesis_passports')->where('id', $passport->id)->first();
            $status = (string) ($freshTrial->status ?? $status);
            $next = (string) ($freshPassport->phase ?? $next);
        }
        if ($replicationReplay) {
            $this->reconcileThreeFoldReplicationAuthority($agent->symbol, $agent->timeframe, true);
            $freshTrial = DB::table('edge_genesis_trials')->where('id', $trial->id)->first();
            $freshPassport = DB::table('edge_genesis_passports')->where('id', $passport->id)->first();
            $status = (string) ($freshTrial->status ?? $status);
            $next = (string) ($freshPassport->phase ?? $next);
        }

        return ['protocol' => self::PROTOCOL, 'status' => $status, 'phase' => $next,
            'discovery_admission' => $discovery, 'edge_admission' => $admission, 'academy_passport' => $academyPassport,
            'academy_oracle' => $academyOracle, 'academy_next_experiment' => $academyNext, 'promotion_evidence' => false];
    }

    /**
     * Re-evaluate already persisted discovery verdicts after a state-machine
     * correction.  No replay is repeated and immutable economic metrics are
     * not edited; only the derived Edge verdict/passport projection changes.
     */
    public function reconcileDiscoveryOutcomes(string $symbol, string $timeframe, bool $apply = false): array
    {
        $rows = DB::table('edge_genesis_trials as t')
            ->join('edge_genesis_passports as p', 'p.id', '=', 't.edge_genesis_passport_id')
            ->where('p.symbol', strtoupper($symbol))->where('p.timeframe', strtoupper($timeframe))
            ->whereIn('t.stage', ['two_fold_discovery', 'three_fold_confirmation'])
            ->whereIn('t.status', ['edge_not_found', 'edge_progressing', 'control_settled'])
            ->select(['t.id', 't.lab_agent_id', 't.evidence'])->get();
        $reconcilable = $rows->filter(function ($row): bool {
            $evidence = (array) json_decode((string) ($row->evidence ?? '{}'), true);
            // These are final comparative decisions, not stale scalar
            // discovery verdicts.  Replaying settleOutcome here would turn a
            // dominated positive arm back into edge_progressing and reopen a
            // packet whose causal budget was already closed.
            if (data_get($evidence, 'authority_selection.decision') !== null
                || data_get($evidence, 'causal_context_effect.effect_hash') !== null
                || data_get($evidence, 'discovery_verdict_revision') === self::DISCOVERY_VERDICT_REVISION) {
                return false;
            }
            $agent = LabAgent::query()->find($row->lab_agent_id);

            return $agent?->modelVersion?->marketPerformances()->latest('id')->exists() === true;
        })->values();
        if (! $apply) {
            return ['protocol' => self::PROTOCOL, 'status' => $reconcilable->isEmpty() ? 'none' : 'would_reconcile',
                'trials' => $reconcilable->count(), 'replays_repeated' => 0, 'promotion_evidence' => false];
        }

        $verdicts = [];
        foreach ($reconcilable as $row) {
            $agent = LabAgent::query()->with('modelVersion')->find($row->lab_agent_id);
            $result = $agent?->modelVersion?->marketPerformances()->latest('id')->value('metrics');
            if (! $agent || ! is_array($result)) {
                continue;
            }
            $verdict = $this->settleOutcome($agent, $result);
            $verdicts[(string) ($verdict['status'] ?? 'unknown')] = ($verdicts[(string) ($verdict['status'] ?? 'unknown')] ?? 0) + 1;
        }

        return ['protocol' => self::PROTOCOL, 'status' => 'reconciled', 'trials' => array_sum($verdicts),
            'verdicts' => $verdicts, 'replays_repeated' => 0, 'promotion_evidence' => false];
    }

    /**
     * Close only a stale passport projection after every immutable packet arm
     * is already terminal. It cannot reopen a trial, change its evidence, or
     * dispatch a replay. This repairs legacy cohorts that were settled arm by
     * arm before the aggregate passport close was introduced.
     *
     * @return array<string,mixed>
     */
    public function reconcileTerminalPassportStates(string $symbol, string $timeframe, bool $apply = false): array
    {
        $terminal = ['invalid_edge_observability', 'invalid_hash_mismatch', 'invalid_window_partition',
            'control_settled', 'replication_control_settled', 'replication_observed', 'authority_observed',
            'edge_replication_passed', 'edge_not_found', 'edge_not_confirmed', 'non_controlling_axis',
            'technical_quarantine', 'quarantined'];
        $passports = DB::table('edge_genesis_passports')->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))->whereIn('status', ['queued', 'running'])->orderBy('id')->get();
        $ready = $passports->filter(function ($passport) use ($terminal): bool {
            $trials = DB::table('edge_genesis_trials')->where('edge_genesis_passport_id', $passport->id)
                ->where('packet_key', 'not like', '%:attribution')->get(['status', 'stage']);

            return $trials->isNotEmpty()
                && $trials->every(fn ($trial): bool => in_array((string) $trial->status, $terminal, true))
                && ! $trials->contains(fn ($trial): bool => (string) $trial->status === 'edge_progressing');
        })->values();
        if (! $apply) {
            return ['protocol' => self::PROTOCOL, 'status' => $ready->isEmpty() ? 'none' : 'would_reconcile',
                'passport_ids' => $ready->pluck('id')->map(fn ($id): int => (int) $id)->all(), 'replays_repeated' => 0,
                'promotion_evidence' => false];
        }

        foreach ($ready as $passport) {
            $hasAuthorityStage = DB::table('edge_genesis_trials')->where('edge_genesis_passport_id', $passport->id)
                ->whereIn('stage', ['three_fold_confirmation', 'nine_fold_authority'])->exists();
            DB::table('edge_genesis_passports')->where('id', $passport->id)->whereIn('status', ['queued', 'running'])->update([
                'status' => $hasAuthorityStage ? 'edge_not_confirmed' : 'edge_not_found',
                'phase_changed_at' => now(), 'updated_at' => now(),
            ]);
        }

        return ['protocol' => self::PROTOCOL, 'status' => 'reconciled', 'passports' => $ready->count(),
            'replays_repeated' => 0, 'promotion_evidence' => false];
    }

    /** Backfill Academy projections from immutable historical Edge evidence; never reruns a replay or changes its verdict. */
    public function reconcileAcademyProjections(string $symbol, string $timeframe, bool $apply = false): array
    {
        $rows = DB::table('edge_genesis_trials as t')->join('edge_genesis_passports as p', 'p.id', '=', 't.edge_genesis_passport_id')
            ->where('p.symbol', strtoupper($symbol))->where('p.timeframe', strtoupper($timeframe))
            ->whereNotNull('t.lab_agent_id')->select(['t.id as trial_id', 't.lab_agent_id', 't.stage', 't.arm', 't.evidence', 'p.id as passport_id', 'p.genesis_key', 'p.data_hash', 'p.execution_hash', 'p.evidence as passport_evidence'])->get();
        $projected = 0;
        $skipped = [];
        foreach ($rows as $row) {
            $agent = LabAgent::query()->with('modelVersion')->find((int) $row->lab_agent_id);
            $metrics = $this->latestValidMetrics($agent?->modelVersion, strtoupper($symbol), strtoupper($timeframe));
            if (! $agent || $metrics === []) {
                $skipped[] = ['trial_id' => $row->trial_id, 'reason' => 'IMMUTABLE_REPLAY_METRICS_MISSING'];

                continue;
            }
            if (! $apply) {
                $projected++;

                continue;
            }
            $trialEvidence = (array) json_decode((string) $row->evidence, true);
            $passportEvidence = (array) json_decode((string) $row->passport_evidence, true);
            $contract = (array) data_get($agent->modelVersion?->metadata, 'edge_genesis', []);
            $assessment = (array) data_get($trialEvidence, 'causal_stage_mastery', []);
            $academy = $this->academy->passport($agent->symbol, $agent->timeframe, [
                'composition_key' => (string) $row->genesis_key, 'strategy_family' => $agent->strategy_family,
                'context' => (array) data_get($contract, 'context', data_get($passportEvidence, 'packet.context', [])),
                'temporal_roles' => (array) data_get($contract, 'packet.temporal_roles', []),
                'risk_contract' => (array) data_get($contract, 'risk_contract', []), 'management_contract' => (array) data_get($contract, 'management_contract', []),
                'deepest_stage' => $this->academyStageFor($assessment),
            ], ['reconciled_edge_genesis_trial_id' => $row->trial_id, 'data_hash' => $row->data_hash, 'execution_hash' => $row->execution_hash]);
            $diagnostic = (array) data_get($metrics, 'edge_formation_academy_diagnostic', []);
            if (data_get($diagnostic, 'status') === 'full_oracle_gap_observed' && is_numeric(data_get($diagnostic, 'oracle_opportunity_edge_r'))) {
                $entry = data_get($diagnostic, 'entry_envelope_after_cost_r');
                $this->academy->assessOracleGap((int) $academy['passport_id'], $diagnostic, ['data_hash' => $row->data_hash, 'execution_hash' => $row->execution_hash,
                    'real_entry_after_cost_r' => is_numeric($entry) ? (float) $entry : -0.000001,
                    'realized_after_cost_r' => data_get($diagnostic, 'realized_after_cost_r', data_get($metrics, 'after_cost_expectancy_r', 0)),
                    'management_capture_loss_r' => data_get($diagnostic, 'management_capture_loss_r')]);
            }
            $this->academy->archiveBeam((int) $academy['passport_id'], (string) $academy['deepest_stage'], [
                'composition_key' => (string) $row->genesis_key, 'strategy' => $agent->strategy_family,
                'quality_key' => hash('sha256', implode('|', [$row->trial_id, $row->stage, $row->arm, $agent->model_version_id])),
                'score' => (float) data_get($metrics, 'after_cost_expectancy_r', 0),
            ]);
            $projected++;
        }

        return ['protocol' => self::PROTOCOL, 'status' => $apply ? 'reconciled' : 'would_reconcile', 'eligible_trials' => $rows->count(),
            'projected' => $projected, 'skipped' => $skipped, 'replay_dispatched' => false, 'trial_verdicts_changed' => false, 'promotion_evidence' => false];
    }

    /**
     * Repair derived authority-budget flags emitted by an older selector.
     * Economic metrics and immutable replay identity are untouched.
     */
    public function reconcileAuthoritySelectionEvidence(string $symbol, string $timeframe, bool $apply = false): array
    {
        $rows = DB::table('edge_genesis_trials as t')
            ->join('edge_genesis_passports as p', 'p.id', '=', 't.edge_genesis_passport_id')
            ->where('p.symbol', strtoupper($symbol))->where('p.timeframe', strtoupper($timeframe))
            ->whereNotNull('t.evidence')
            ->select(['t.id', 't.edge_genesis_passport_id', 't.lab_agent_id', 't.model_version_id',
                't.arm', 't.stage', 't.evidence'])->get();
        $travelingControlArms = self::TRAVELING_CONTROL_ARMS;
        $repairs = $rows->map(function ($row) use ($rows, $travelingControlArms): ?array {
            $evidence = (array) json_decode((string) $row->evidence, true);
            $decision = (string) data_get($evidence, 'authority_selection.decision', '');
            $admitted = (bool) data_get($evidence, 'authority_selection.nine_fold_replay_admitted', false);
            if ($decision === 'discovery_dominated' && $admitted) {
                data_set($evidence, 'authority_selection.nine_fold_replay_admitted', false);

                return ['id' => (int) $row->id, 'evidence' => $evidence, 'repair' => 'dominated_budget_flag'];
            }
            // Earlier confirmation materialization updated the control's
            // single performance projection from two to nine folds. A sparse
            // treatment admitted later could therefore see a null control
            // and be labelled as a window mismatch even though the immutable
            // two-fold evaluation run still proves identical windows. Recover
            // that comparison without changing either economic observation.
            if ($decision === 'discovery_dominated'
                && data_get($evidence, 'authority_selection.reason') === 'PAIRED_DISCOVERY_WINDOW_IDENTITY_MISMATCH'
                && (bool) data_get($evidence, 'discovery_admission.passed', false)) {
                $control = $rows->first(fn ($candidate): bool => (int) $candidate->edge_genesis_passport_id === (int) $row->edge_genesis_passport_id
                    && in_array((string) $candidate->arm, $travelingControlArms, true));
                $controlMetrics = $control ? $this->discoveryMetricsForModel((int) $control->model_version_id) : null;
                $candidateMetrics = $this->discoveryMetricsForModel((int) $row->model_version_id);
                $sameWindows = $controlMetrics !== null && $candidateMetrics !== null
                    && $this->discoveryWindowIdentity($controlMetrics) !== []
                    && $this->discoveryWindowIdentity($controlMetrics) === $this->discoveryWindowIdentity($candidateMetrics);
                $enforced = data_get($candidateMetrics, 'edge_context_enforcement.protocol') === 'edge_context_authority_firewall_v1'
                    && data_get($candidateMetrics, 'edge_context_enforcement.enforced') === true
                    && data_get($candidateMetrics, 'edge_context_enforcement.outside_scope_action') === 'WAIT';
                $delta = $controlMetrics !== null && $candidateMetrics !== null
                    ? round($this->afterCost($candidateMetrics) - $this->afterCost($controlMetrics), 6)
                    : null;
                if ($sameWindows && $enforced && $delta !== null && $delta > 0) {
                    $evidence['authority_selection'] = [
                        'protocol' => 'paired_discovery_authority_budget_v1',
                        'decision' => 'discovery_outperformed_control',
                        'reason' => 'PAIRED_DISCOVERY_OUTPERFORMED_EXACT_CONTROL',
                        'control_trial_id' => (int) $control->id,
                        'control_expectancy_r' => $this->afterCost($controlMetrics),
                        'treatment_expectancy_r' => $this->afterCost($candidateMetrics),
                        'expectancy_delta_r' => $delta,
                        'same_frozen_windows' => true,
                        'minimum_delta_r' => 0.0,
                        'authority_granted' => false,
                        'nine_fold_replay_admitted' => true,
                        'recovered_from_immutable_discovery_run' => true,
                        'promotion_evidence' => false,
                    ];

                    return ['id' => (int) $row->id, 'evidence' => $evidence,
                        'status' => 'edge_progressing', 'repair' => 'immutable_discovery_control_recovery'];
                }
            }
            if (! in_array((string) $row->arm, $travelingControlArms, true)
                || (string) $row->stage !== 'nine_fold_authority') {
                return null;
            }
            $pairedTreatmentExists = $rows->contains(function ($candidate) use ($row): bool {
                if ((int) $candidate->edge_genesis_passport_id !== (int) $row->edge_genesis_passport_id
                    || (int) $candidate->id === (int) $row->id
                    || (string) $candidate->stage !== 'nine_fold_authority') {
                    return false;
                }
                $candidateEvidence = (array) json_decode((string) $candidate->evidence, true);

                return data_get($candidateEvidence, 'authority_selection.decision') === 'discovery_outperformed_control';
            });
            if (! $pairedTreatmentExists || ($decision === 'exact_control_replayed_for_paired_authority' && $admitted)) {
                return null;
            }
            $evidence['authority_selection'] = [
                'protocol' => 'paired_discovery_authority_budget_v1',
                'decision' => 'exact_control_replayed_for_paired_authority',
                'reason' => 'EXACT_CONTROL_REQUIRED_FOR_PAIRED_NINE_FOLD_CAUSALITY',
                'authority_granted' => false,
                'nine_fold_replay_admitted' => true,
                'promotion_evidence' => false,
            ];

            return ['id' => (int) $row->id, 'evidence' => $evidence, 'repair' => 'traveling_control_budget_flag'];
        })->filter()->values();
        if (! $apply) {
            return ['protocol' => self::PROTOCOL,
                'status' => $repairs->isEmpty() ? 'none' : 'would_reconcile',
                'trials' => $repairs->count(), 'replays_repeated' => 0, 'promotion_evidence' => false];
        }
        foreach ($repairs as $repair) {
            DB::table('edge_genesis_trials')->where('id', $repair['id'])->update(array_filter([
                'evidence' => json_encode($repair['evidence'], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                'status' => $repair['status'] ?? null,
                'updated_at' => now(),
            ], fn ($value): bool => $value !== null));
        }

        return ['protocol' => self::PROTOCOL, 'status' => 'reconciled',
            'trials' => $repairs->count(), 'repairs' => $repairs->countBy('repair')->all(),
            'replays_repeated' => 0, 'promotion_evidence' => false];
    }

    /**
     * Three-fold confirmation is a paired replication barrier. It may admit
     * a repeatable improvement over the exact control even while both arms
     * remain economically negative; neither arm receives Edge, parent, or
     * promotion authority at this stage.
     */
    public function reconcileThreeFoldReplicationAuthority(string $symbol, string $timeframe, bool $apply = false): array
    {
        $passports = DB::table('edge_genesis_passports')->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))->get();
        $ready = collect();
        $awaiting = 0;
        foreach ($passports as $passport) {
            $trials = DB::table('edge_genesis_trials')->where('edge_genesis_passport_id', $passport->id)
                ->where('stage', 'three_fold_confirmation')->where('packet_key', 'not like', '%:attribution')->get();
            $control = $trials->first(fn ($trial): bool => in_array((string) $trial->arm, self::TRAVELING_CONTROL_ARMS, true));
            $treatments = $trials->reject(fn ($trial): bool => in_array((string) $trial->arm, self::TRAVELING_CONTROL_ARMS, true))->values();
            if (! $control || $treatments->isEmpty()) {
                continue;
            }
            if ($trials->contains(fn ($trial): bool => in_array((string) $trial->status, ['queued', 'running'], true))) {
                $awaiting++;

                continue;
            }
            $undecided = $treatments->filter(function ($trial): bool {
                $evidence = (array) json_decode((string) ($trial->evidence ?? '{}'), true);

                return data_get($evidence, 'three_fold_causal_replication.protocol') !== 'three_fold_differential_replication_v1';
            });
            if ($undecided->isEmpty()) {
                continue;
            }
            $metrics = $trials->mapWithKeys(function ($trial): array {
                $row = DB::table('model_market_performance')->where('model_version_id', $trial->model_version_id)
                    ->where('evidence_status', 'valid')->where('rolling_windows_count', 3)->latest('id')->first(['metrics']);
                $value = $row ? (is_string($row->metrics) ? json_decode($row->metrics, true) : $row->metrics) : null;

                return [(int) $trial->id => is_array($value) ? $value : null];
            });
            if ($metrics->contains(fn ($value): bool => ! is_array($value))) {
                $awaiting++;

                continue;
            }
            $controlMetrics = (array) $metrics->get((int) $control->id);
            $controlWindows = $this->discoveryWindowIdentity($controlMetrics);
            $decisions = $treatments->map(function ($trial) use ($control, $controlMetrics, $controlWindows, $metrics): array {
                $result = (array) $metrics->get((int) $trial->id);
                $model = ModelVersion::query()->find((int) $trial->model_version_id);
                $evidence = (array) json_decode((string) ($trial->evidence ?? '{}'), true);
                $controlEvidence = (array) json_decode((string) ($control->evidence ?? '{}'), true);
                $paired = $this->pairedWindowEffect($result, $controlMetrics);
                $sameWindows = $controlWindows !== [] && $this->discoveryWindowIdentity($result) === $controlWindows;
                $partitionsValid = data_get($evidence, 'stage_partition.valid') === true
                    && data_get($controlEvidence, 'stage_partition.valid') === true;
                $contextRequired = data_get($model?->metadata, 'edge_genesis.context.enforcement') === 'required';
                $contextValid = ! $contextRequired || (
                    data_get($result, 'edge_context_enforcement.protocol') === 'edge_context_authority_firewall_v1'
                    && data_get($result, 'edge_context_enforcement.enforced') === true
                    && data_get($result, 'edge_context_enforcement.outside_scope_action') === 'WAIT'
                );
                $delta = round($this->afterCost($result) - $this->afterCost($controlMetrics), 6);
                $replicated = $sameWindows && $partitionsValid && $contextValid && $delta > 0
                    && (int) data_get($result, 'forward_window_protocol.powered_windows', 0) >= 2
                    && (int) data_get($controlMetrics, 'forward_window_protocol.powered_windows', 0) >= 2
                    && (int) $paired['comparable_windows'] === 3 && (int) $paired['positive_windows'] >= 2;

                return ['trial' => $trial, 'replicated' => $replicated, 'delta' => $delta,
                    'same_windows' => $sameWindows, 'partitions_valid' => $partitionsValid,
                    'context_valid' => $contextValid, 'paired' => $paired];
            })->values();
            $ready->push(['passport' => $passport, 'control' => $control, 'decisions' => $decisions]);
        }
        if (! $apply) {
            return ['protocol' => 'three_fold_differential_replication_v1',
                'status' => $ready->isEmpty() ? ($awaiting > 0 ? 'awaiting_complete_cohort' : 'none') : 'would_reconcile',
                'passports' => $ready->count(), 'awaiting' => $awaiting, 'promotion_evidence' => false];
        }

        $passed = 0;
        foreach ($ready as $cohort) {
            DB::transaction(function () use ($cohort, &$passed): void {
                foreach ($cohort['decisions'] as $decision) {
                    $trial = $decision['trial'];
                    $evidence = (array) json_decode((string) ($trial->evidence ?? '{}'), true);
                    $evidence['three_fold_causal_replication'] = [
                        'protocol' => 'three_fold_differential_replication_v1',
                        'decision' => $decision['replicated'] ? 'paired_effect_replicated' : 'paired_effect_not_replicated',
                        'control_trial_id' => (int) $cohort['control']->id,
                        'same_frozen_windows' => $decision['same_windows'],
                        'stage_partitions_valid' => $decision['partitions_valid'],
                        'context_firewall_valid' => $decision['context_valid'],
                        'expectancy_delta_r' => $decision['delta'],
                        'paired_window_effect' => $decision['paired'],
                        'edge_authority' => false, 'parent_authority' => false, 'promotion_evidence' => false,
                    ];
                    DB::table('edge_genesis_trials')->where('id', $trial->id)->update([
                        'status' => $decision['replicated'] ? 'edge_replication_passed' : 'edge_not_confirmed',
                        'settled_at' => now(),
                        'evidence' => json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                        'updated_at' => now(),
                    ]);
                    if ($decision['replicated']) {
                        $passed++;
                    }
                }
                $hasPassed = $cohort['decisions']->contains(fn (array $decision): bool => $decision['replicated']);
                DB::table('edge_genesis_passports')->where('id', $cohort['passport']->id)->update([
                    'phase' => 'EDGE_CONFIRMATION',
                    'status' => $hasPassed ? 'running' : 'edge_not_confirmed',
                    'phase_changed_at' => now(), 'updated_at' => now(),
                ]);
            });
        }

        return ['protocol' => 'three_fold_differential_replication_v1', 'status' => 'reconciled',
            'passports' => $ready->count(), 'passed_treatments' => $passed, 'awaiting' => $awaiting,
            'promotion_evidence' => false];
    }

    /**
     * A nine-fold specialist is authoritative only as a paired differential,
     * never because its standalone score happened to be positive. The cohort
     * settles behind one barrier and emits at most one attribution source.
     */
    public function reconcileNineFoldContextAuthority(string $symbol, string $timeframe, bool $apply = false): array
    {
        $travelingControlArms = self::TRAVELING_CONTROL_ARMS;
        $passports = DB::table('edge_genesis_passports')->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))->get();
        $ready = collect();
        $awaiting = 0;
        foreach ($passports as $passport) {
            $passportEvidence = (array) json_decode((string) ($passport->evidence ?? '{}'), true);
            $trials = DB::table('edge_genesis_trials')->where('edge_genesis_passport_id', $passport->id)
                ->where('stage', 'nine_fold_authority')->where('packet_key', 'not like', '%:attribution')->get();
            $control = $trials->first(fn ($trial): bool => in_array((string) $trial->arm, $travelingControlArms, true));
            $treatments = $trials->reject(fn ($trial): bool => in_array((string) $trial->arm, $travelingControlArms, true))->values();
            if (! $control || $treatments->isEmpty()) {
                continue;
            }
            $unsettledDecisionExists = $treatments->contains(function ($trial): bool {
                $evidence = (array) json_decode((string) ($trial->evidence ?? '{}'), true);

                return data_get($evidence, 'nine_fold_causal_authority.protocol') !== 'nine_fold_differential_authority_v1';
            });
            // A passport decision covers the treatments observed at that
            // barrier, not a sparse treatment admitted later. Re-open only
            // the derived comparison when a new immutable nine-fold result
            // lacks a decision; no old replay is repeated.
            if (data_get($passportEvidence, 'nine_fold_causal_authority.protocol') === 'nine_fold_differential_authority_v1'
                && ! $unsettledDecisionExists) {
                continue;
            }
            if ($trials->contains(fn ($trial): bool => in_array((string) $trial->status, ['queued', 'running'], true))) {
                $awaiting++;

                continue;
            }
            $metrics = $trials->mapWithKeys(function ($trial): array {
                $row = DB::table('model_market_performance')->where('model_version_id', $trial->model_version_id)
                    ->where('evidence_status', 'valid')->where('rolling_windows_count', '>=', 9)->latest('id')->first(['metrics']);
                $value = $row ? (is_string($row->metrics) ? json_decode($row->metrics, true) : $row->metrics) : null;

                return [(int) $trial->id => is_array($value) ? $value : null];
            });
            if ($metrics->contains(fn ($value): bool => ! is_array($value))) {
                $awaiting++;

                continue;
            }
            $controlMetrics = (array) $metrics->get((int) $control->id);
            $controlWindows = $this->discoveryWindowIdentity($controlMetrics);
            $expectedMtf = (string) data_get($controlMetrics, 'data_manifest.mtf_bundle_hash',
                data_get($controlMetrics, 'mtf_snapshot_manifest.bundle_hash', ''));
            $decisions = $treatments->map(function ($trial) use ($passport, $control, $controlMetrics, $controlWindows, $expectedMtf, $metrics): array {
                $result = (array) $metrics->get((int) $trial->id);
                $model = ModelVersion::query()->find((int) $trial->model_version_id);
                $observedData = (string) data_get($result, 'data_manifest.sha256', data_get($result, 'data_hash', ''));
                $observedExecution = (string) data_get($result, 'execution_contract.execution_hash', data_get($result, 'execution_hash', ''));
                $observedMtf = (string) data_get($result, 'data_manifest.mtf_bundle_hash', data_get($result, 'mtf_snapshot_manifest.bundle_hash', ''));
                $sameIdentity = $observedData !== '' && hash_equals((string) $passport->data_hash, $observedData)
                    && $observedExecution !== '' && hash_equals((string) $passport->execution_hash, $observedExecution)
                    && $expectedMtf !== '' && $observedMtf !== '' && hash_equals($expectedMtf, $observedMtf)
                    && $controlWindows !== [] && $this->discoveryWindowIdentity($result) === $controlWindows;
                $paired = $this->pairedWindowEffect($result, $controlMetrics);
                $delta = round($this->afterCost($result) - $this->afterCost($controlMetrics), 6);
                $contextRequired = data_get($model?->metadata, 'edge_genesis.context.enforcement') === 'required';
                $absoluteEdge = $this->edgeAdmissionPassed($result, $contextRequired);
                $causal = $sameIdentity && $absoluteEdge && $delta > 0
                    && (int) $paired['comparable_windows'] >= 9 && (int) $paired['positive_windows'] >= 5;

                return ['trial' => $trial, 'model' => $model, 'metrics' => $result,
                    'same_identity' => $sameIdentity, 'absolute_edge' => $absoluteEdge,
                    'expectancy_delta_r' => $delta, 'paired_window_effect' => $paired,
                    'context_complexity' => count((array) data_get($model?->metadata, 'edge_genesis.context.admission_axes', [])),
                    'causal' => $causal, 'control_trial_id' => (int) $control->id];
            })->values();
            $winner = $decisions->where('causal', true)->sort(function (array $a, array $b): int {
                $delta = $b['expectancy_delta_r'] <=> $a['expectancy_delta_r'];
                if ($delta !== 0) {
                    return $delta;
                }
                $complexity = $a['context_complexity'] <=> $b['context_complexity'];

                return $complexity !== 0 ? $complexity : ((int) $a['trial']->id <=> (int) $b['trial']->id);
            })->first();
            $ready->push(['passport' => $passport, 'passport_evidence' => $passportEvidence,
                'control' => $control, 'control_metrics' => $controlMetrics,
                'decisions' => $decisions, 'winner_trial_id' => (int) data_get($winner, 'trial.id', 0)]);
        }
        if (! $apply) {
            return ['protocol' => 'nine_fold_differential_authority_v1',
                'status' => $ready->isEmpty() ? ($awaiting > 0 ? 'awaiting_complete_cohort' : 'none') : 'would_reconcile',
                'passports' => $ready->count(), 'awaiting' => $awaiting,
                'promotion_evidence' => false];
        }

        $winners = 0;
        foreach ($ready as $cohort) {
            DB::transaction(function () use ($cohort, &$winners): void {
                $passport = $cohort['passport'];
                $winnerTrialId = (int) $cohort['winner_trial_id'];
                $controlEvidence = (array) json_decode((string) $cohort['control']->evidence, true);
                $controlEvidence['nine_fold_causal_authority'] = [
                    'protocol' => 'nine_fold_differential_authority_v1', 'role' => 'exact_control',
                    'selected_treatment_trial_id' => $winnerTrialId ?: null,
                    'authority_granted' => false, 'parent_authority' => false, 'promotion_evidence' => false,
                ];
                DB::table('edge_genesis_trials')->where('id', $cohort['control']->id)->update([
                    'evidence' => json_encode($controlEvidence, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                    'updated_at' => now(),
                ]);
                foreach ($cohort['decisions'] as $decision) {
                    $trial = $decision['trial'];
                    $selected = (int) $trial->id === $winnerTrialId;
                    $evidence = (array) json_decode((string) $trial->evidence, true);
                    $evidence['nine_fold_causal_authority'] = [
                        'protocol' => 'nine_fold_differential_authority_v1',
                        'decision' => $selected ? 'causal_edge_selected'
                            : ($decision['causal'] ? 'causal_edge_redundant' : 'causal_edge_not_confirmed'),
                        'control_trial_id' => $decision['control_trial_id'],
                        'same_data_execution_mtf_and_windows' => $decision['same_identity'],
                        'absolute_edge_reconfirmed' => $decision['absolute_edge'],
                        'expectancy_delta_r' => $decision['expectancy_delta_r'],
                        'paired_window_effect' => $decision['paired_window_effect'],
                        'selection_tiebreak' => 'expectancy_delta_desc_then_context_complexity_asc',
                        'edge_attribution_authority' => $selected,
                        'parent_authority' => false, 'promotion_evidence' => false,
                    ];
                    DB::table('edge_genesis_trials')->where('id', $trial->id)->update([
                        'status' => $selected ? 'edge_progressing' : 'edge_not_confirmed',
                        'settled_at' => now(),
                        'evidence' => json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                        'updated_at' => now(),
                    ]);
                    if ($decision['model']) {
                        $metadata = (array) $decision['model']->metadata;
                        data_set($metadata, 'edge_genesis.phase', $selected ? 'EDGE_ATTRIBUTION' : 'EDGE_CONFIRMATION');
                        data_set($metadata, 'edge_genesis.nine_fold_causal_authority', $evidence['nine_fold_causal_authority']);
                        $decision['model']->update(['metadata' => $metadata]);
                    }
                }
                $passportEvidence = $cohort['passport_evidence'];
                $passportEvidence['nine_fold_causal_authority'] = [
                    'protocol' => 'nine_fold_differential_authority_v1',
                    'complete_cohort_barrier' => true,
                    'selected_treatment_trial_id' => $winnerTrialId ?: null,
                    'candidate_count' => $cohort['decisions']->count(),
                    'parent_authority' => false, 'promotion_evidence' => false,
                ];
                DB::table('edge_genesis_passports')->where('id', $passport->id)->update([
                    'phase' => $winnerTrialId ? 'EDGE_ATTRIBUTION' : 'EDGE_CONFIRMATION',
                    'status' => $winnerTrialId ? 'running' : 'edge_not_confirmed',
                    'evidence' => json_encode($passportEvidence, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                    'phase_changed_at' => now(), 'updated_at' => now(),
                ]);
                if ($winnerTrialId) {
                    $winners++;
                }
            });
        }

        return ['protocol' => 'nine_fold_differential_authority_v1', 'status' => 'reconciled',
            'passports' => $ready->count(), 'winners' => $winners, 'awaiting' => $awaiting,
            'promotion_evidence' => false];
    }

    /** Align descriptive execution-timeframe provenance without changing a parameter or hash. */
    public function reconcileExecutionTimeframeMetadata(string $symbol, string $timeframe, bool $apply = false): array
    {
        $agents = LabAgent::query()->with('modelVersion')->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))
            ->whereIn('origin', ['edge_genesis', 'edge_component_attribution'])->get()
            ->filter(fn (LabAgent $agent): bool => data_get($agent->modelVersion?->metadata, 'edge_genesis.protocol') === self::PROTOCOL
                && strtoupper((string) data_get($agent->modelVersion?->metadata, 'execution_contract.timeframe', '')) !== self::EXECUTION_TIMEFRAME)
            ->values();
        if (! $apply) {
            return ['protocol' => self::PROTOCOL, 'status' => $agents->isEmpty() ? 'none' : 'would_reconcile',
                'models' => $agents->count(), 'parameters_unchanged' => true, 'promotion_evidence' => false];
        }

        $updated = 0;
        foreach ($agents as $agent) {
            $metadata = (array) $agent->modelVersion?->metadata;
            $observed = (array) data_get($metadata, 'execution_contract', []);
            $expected = $this->executionContracts->for($agent->symbol, self::EXECUTION_TIMEFRAME);
            $passport = DB::table('edge_genesis_passports')->where('genesis_key', data_get($metadata, 'edge_genesis.genesis_key'))->first();
            if (! $passport || ! hash_equals((string) $passport->execution_hash, (string) $expected['execution_hash'])
                || $this->executionContracts->hashParameters((array) data_get($observed, 'parameters', [])) !== $expected['execution_hash']) {
                continue;
            }
            data_set($metadata, 'execution_contract', $expected);
            data_set($metadata, 'edge_execution_timeframe_alignment', [
                'protocol' => 'edge_execution_timeframe_alignment_v1', 'from' => data_get($observed, 'timeframe'),
                'to' => self::EXECUTION_TIMEFRAME, 'parameters_unchanged' => true, 'execution_hash_unchanged' => true,
                'recorded_at' => now()->utc()->toIso8601String(), 'promotion_evidence' => false,
            ]);
            $agent->modelVersion->update(['metadata' => $metadata]);
            $updated++;
        }

        return ['protocol' => self::PROTOCOL, 'status' => 'reconciled', 'models' => $updated,
            'parameters_unchanged' => true, 'promotion_evidence' => false];
    }

    /**
     * Distil terminal compiled cohorts into an explicit paired axis effect.
     * The Python flag describes an observed decision funnel; mutation credit
     * requires a real control-relative event, signal or funnel difference.
     */
    public function reconcileCompiledHypothesisSettlements(string $symbol, string $timeframe, bool $apply = false): array
    {
        if (! Schema::hasTable('edge_hypothesis_packets') || ! Schema::hasTable('edge_genesis_cohorts')) {
            return ['protocol' => 'compiled_axis_settlement_v2', 'status' => 'unavailable',
                'settled' => 0, 'promotion_evidence' => false];
        }
        $packets = DB::table('edge_hypothesis_packets as h')->join('edge_genesis_cohorts as c',
            'c.edge_hypothesis_packet_id', '=', 'h.id')
            ->where('h.symbol', strtoupper($symbol))->where('h.timeframe', strtoupper($timeframe))
            // v1 settlement omitted full-fold observability. Re-open only
            // that derived projection once so old economic evidence remains
            // immutable while its behavior classification is corrected.
            ->whereIn('h.status', ['registered', 'materialized', 'settled_no_behavior_change',
                'settled_behavior_changed_no_edge'])
            ->select(['h.id', 'h.structural_axis', 'h.evidence', 'c.id as cohort_id', 'c.lab_generation_id'])
            ->get();
        $ready = [];
        foreach ($packets as $packet) {
            $packetEvidence = is_string($packet->evidence) ? json_decode($packet->evidence, true) : $packet->evidence;
            if (data_get($packetEvidence, 'axis_settlement.protocol') === 'compiled_axis_settlement_v2') {
                continue;
            }
            $passportIds = DB::table('edge_genesis_passports')->where('lab_generation_id', $packet->lab_generation_id)->pluck('id');
            $trials = DB::table('edge_genesis_trials')->whereIn('edge_genesis_passport_id', $passportIds)->get();
            $terminalNoEdge = ['edge_not_found', 'edge_not_confirmed', 'control_settled', 'replication_control_settled'];
            if ($trials->count() !== count(self::COMPILED_HYPOTHESIS_ARMS)
                || $trials->contains(fn ($trial): bool => ! in_array((string) $trial->status, $terminalNoEdge, true))) {
                continue;
            }
            $observations = $trials->map(fn ($trial) => $this->compiledAxisObservation($trial,
                (string) $packet->structural_axis))->filter()->values();
            $control = $observations->firstWhere('arm', 'compiled_control');
            if (! is_array($control) || $observations->count() !== count(self::COMPILED_HYPOTHESIS_ARMS)) {
                continue;
            }
            $effects = $observations->where('arm', '!=', 'compiled_control')->map(function (array $candidate) use ($control): array {
                $parameterChanged = $this->cohortIdentity->hash(['value' => $candidate['value']])
                    !== $this->cohortIdentity->hash(['value' => $control['value']]);
                $behaviorChanged = $parameterChanged && $this->axisBehaviorChanged($control, $candidate);

                return [
                    'arm' => $candidate['arm'], 'tested_value' => $candidate['value'],
                    'parameter_changed' => $parameterChanged, 'behavior_changed' => $behaviorChanged,
                    'event_hash_match' => $candidate['event_hash'] !== '' && $candidate['event_hash'] === $control['event_hash'],
                    'signal_hash_match' => $candidate['signal_hash'] !== '' && $candidate['signal_hash'] === $control['signal_hash'],
                    'funnel_hash_match' => $candidate['funnel_hash'] === $control['funnel_hash'],
                    'observability_hash_match' => $candidate['observability_hash'] === $control['observability_hash'],
                    'stage_counts' => $candidate['stage_counts'],
                    'observability_counts' => $candidate['observability_counts'],
                    'after_cost_expectancy_r' => $candidate['after_cost_expectancy_r'],
                    'promotion_evidence' => false,
                ];
            })->values();
            $changedArms = $effects->where('behavior_changed', true)->pluck('arm')->values()->all();
            $ready[] = [
                'packet' => $packet, 'status' => $changedArms === []
                    ? 'settled_no_behavior_change' : 'settled_behavior_changed_no_edge',
                'settlement' => [
                    'protocol' => 'compiled_axis_settlement_v2',
                    'structural_axis' => (string) $packet->structural_axis,
                    'control' => ['arm' => 'compiled_control', 'value' => $control['value'],
                        'stage_counts' => $control['stage_counts']],
                    'effects' => $effects->all(),
                    'behavior_changed_arms' => $changedArms,
                    'economic_edge_found' => false,
                    'exactly_once' => true,
                    'promotion_evidence' => false,
                ],
            ];
        }
        if (! $apply) {
            return ['protocol' => 'compiled_axis_settlement_v2',
                'status' => $ready === [] ? 'none' : 'would_settle', 'settled' => count($ready),
                'promotion_evidence' => false];
        }

        foreach ($ready as $item) {
            $packet = $item['packet'];
            $evidence = is_string($packet->evidence) ? json_decode($packet->evidence, true) : $packet->evidence;
            $settlement = [...$item['settlement'], 'settled_at' => now()->utc()->toIso8601String()];
            DB::transaction(function () use ($packet, $item, $evidence, $settlement): void {
                DB::table('edge_hypothesis_packets')->where('id', $packet->id)
                    ->whereIn('status', ['registered', 'materialized', 'settled_no_behavior_change',
                        'settled_behavior_changed_no_edge'])->update([
                            'status' => $item['status'],
                            'evidence' => json_encode([...((array) $evidence), 'axis_settlement' => $settlement],
                                JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                            'updated_at' => now(),
                        ]);
                DB::table('edge_genesis_cohorts')->where('id', $packet->cohort_id)->update([
                    'status' => $item['status'], 'updated_at' => now(),
                ]);
            });
        }

        return ['protocol' => 'compiled_axis_settlement_v2', 'status' => 'settled',
            'settled' => count($ready), 'statuses' => collect($ready)->countBy('status')->all(),
            'promotion_evidence' => false];
    }

    /** @return array<string,mixed>|null */
    private function compiledAxisObservation(object $trial, string $axis): ?array
    {
        $model = ModelVersion::query()->find((int) $trial->model_version_id);
        $performance = $model?->marketPerformances()->where('evidence_status', 'valid')->latest('id')->first();
        if (! $model || ! $performance || ! is_array($performance->metrics)) {
            return null;
        }
        $metrics = $performance->metrics;
        $funnel = [
            'stage_counts' => (array) data_get($metrics, 'entry_contract_funnel.stage_counts', []),
            'trigger_topology' => (array) data_get($metrics, 'entry_contract_funnel.trigger_topology', []),
            'no_trade_reasons' => (array) data_get($metrics, 'entry_contract_funnel.no_trade_reasons', []),
        ];
        $observability = [
            'opportunities' => (int) data_get($metrics, 'edge_observability.opportunity_detected.count', 0),
            'locations' => (int) data_get($metrics, 'edge_observability.setup_location_valid.location_count', 0),
            'setups' => (int) data_get($metrics, 'edge_observability.setup_location_valid.setup_count', 0),
            'confirmations' => (int) data_get($metrics, 'edge_observability.confirmation.count', 0),
            'entries' => (int) data_get($metrics, 'edge_observability.entry.count', 0),
            'executions' => (int) data_get($metrics, 'edge_observability.execution_price.closed_trade_count', 0),
            'invalidations' => (int) data_get($metrics, 'edge_observability.invalidation_price.count', 0),
            'closed_trades' => (int) data_get($metrics, 'edge_observability.exit_outcome.closed_trade_count', 0),
            'wins' => (int) data_get($metrics, 'edge_observability.exit_outcome.wins', 0),
            'losses' => (int) data_get($metrics, 'edge_observability.exit_outcome.losses', 0),
        ];

        return [
            'arm' => (string) $trial->arm,
            'value' => data_get($model->parameters, $axis),
            'event_hash' => (string) data_get($metrics, 'event_ledger_hash', ''),
            'signal_hash' => (string) data_get($metrics, 'signal_decision_hash', ''),
            'funnel_hash' => $this->cohortIdentity->hash($funnel),
            'observability_hash' => $this->cohortIdentity->hash($observability),
            'observability_counts' => $observability,
            'stage_counts' => $funnel['stage_counts'],
            'after_cost_expectancy_r' => (float) data_get($metrics, 'after_cost_expectancy_r', 0),
        ];
    }

    /** @param array<string,mixed> $control @param array<string,mixed> $candidate */
    private function axisBehaviorChanged(array $control, array $candidate): bool
    {
        foreach (['event_hash', 'signal_hash', 'funnel_hash', 'observability_hash'] as $key) {
            if (($control[$key] ?? '') !== '' && ($candidate[$key] ?? '') !== ''
                && $control[$key] !== $candidate[$key]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bind a partially executed discovery cohort to one immutable MTF bundle.
     * Existing runs remain audit evidence but receive no authority; only arms
     * without an already queued payload are dispatched again.
     */
    public function rebindDiscoveryCohortToFrozenMtfBundle(int $generationId, array $mtfBundle, bool $apply = false): array
    {
        $generation = LabGeneration::query()->find($generationId);
        $manifest = (array) data_get($mtfBundle, 'manifest', []);
        $bundleHash = (string) data_get($mtfBundle, 'bundle_hash', '');
        $valid = $generation?->trigger_type === 'edge_genesis'
            && preg_match('/^[a-f0-9]{64}$/', $bundleHash) === 1
            && hash_equals($bundleHash, (string) data_get($manifest, 'bundle_hash', ''))
            && data_get($manifest, 'validation_bundle_protocol') === 'agent_owned_mtf_foundation_bundle_v1'
            && data_get($manifest, 'data_role') === 'pre_2026_foundation_training_only'
            && data_get($manifest, 'promotion_evidence') === false;
        if (! $valid) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason' => 'FROZEN_MTF_COHORT_CONTRACT_INVALID', 'promotion_evidence' => false];
        }
        $agents = LabAgent::query()->with('modelVersion')->where('lab_generation_id', $generationId)->where('origin', 'edge_genesis')->get();
        $passports = DB::table('edge_genesis_passports')->where('lab_generation_id', $generationId)->get();
        $authorityReplayStarted = DB::table('edge_genesis_trials')->whereIn('edge_genesis_passport_id', $passports->pluck('id'))
            ->whereIn('stage', ['nine_fold_authority', 'component_attribution'])->exists();
        if ($agents->isEmpty() || $passports->isEmpty() || $authorityReplayStarted
            || $passports->contains(fn ($passport): bool => ! in_array((string) $passport->phase, ['EDGE_DISCOVERY', 'EDGE_CONFIRMATION'], true))) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason' => 'ONLY_UNCONFIRMED_DISCOVERY_COHORT_CAN_BE_REBOUND', 'promotion_evidence' => false];
        }
        if ($agents->contains(fn (LabAgent $agent): bool => in_array($agent->lifecycle_status, ['training', 'full_validation'], true))) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason' => 'ACTIVE_EDGE_REPLAY_MUST_SETTLE_BEFORE_REBIND', 'promotion_evidence' => false];
        }
        $alreadyBound = $agents->every(fn (LabAgent $agent): bool => data_get($agent->modelVersion?->metadata, 'edge_genesis.mtf_bundle_hash') === $bundleHash);
        if ($alreadyBound) {
            return ['protocol' => self::PROTOCOL, 'status' => 'already_bound', 'models' => $agents->count(), 'promotion_evidence' => false];
        }
        if (! $apply) {
            return ['protocol' => self::PROTOCOL, 'status' => 'would_rebind', 'models' => $agents->count(),
                'bundle_hash' => $bundleHash, 'promotion_evidence' => false];
        }

        $dispatch = [];
        DB::transaction(function () use ($generation, $agents, $passports, $manifest, $bundleHash, &$dispatch): void {
            foreach ($agents as $agent) {
                $fromStatus = (string) $agent->lifecycle_status;
                $metadata = (array) $agent->modelVersion?->metadata;
                $previousBundle = data_get($metadata, 'edge_genesis.mtf_bundle_hash');
                data_set($metadata, 'edge_genesis.mtf_bundle_hash', $bundleHash);
                data_set($metadata, 'edge_genesis.mtf_bundle_manifest', $manifest);
                data_set($metadata, 'edge_genesis.phase', 'EDGE_DISCOVERY');
                data_set($metadata, 'mtf_cohort_rebind', [
                    'protocol' => 'edge_genesis_single_bundle_rebind_v1', 'previous_bundle_hash' => $previousBundle,
                    'frozen_bundle_hash' => $bundleHash, 'prior_result_authority' => false,
                    'parameters_unchanged' => true, 'recorded_at' => now()->utc()->toIso8601String(), 'promotion_evidence' => false,
                ]);
                $agent->modelVersion?->update(['metadata' => $metadata]);
                $performance = $agent->modelVersion?->marketPerformances()->latest('id')->first();
                if ($performance) {
                    $performance->update(['evidence_status' => 'stale_quarantine', 'invalidated_at' => now(),
                        'invalidation_reason' => 'EDGE_MTF_BUNDLE_NOT_FROZEN_AT_COHORT_REGISTRATION']);
                }
                if ($fromStatus !== 'full_queued') {
                    $dispatch[] = $agent->id;
                }
                $agent->update(['lifecycle_status' => 'full_queued', 'decision_reason' => 'Edge discovery rebound to one cohort-frozen MTF bundle; prior per-agent bundle result has no authority.']);
                app(LabImmutableEvidenceService::class)->recordLifecycle($agent->fresh(), 'edge_genesis_mtf_cohort_rebound', [
                    'protocol' => 'edge_genesis_single_bundle_rebind_v1', 'frozen_bundle_hash' => $bundleHash,
                    'prior_result_authority' => false, 'parameters_unchanged' => true, 'promotion_evidence' => false,
                ], 'preflight', null, null, self::class, null, $fromStatus, 'full_queued');
            }
            DB::table('edge_genesis_trials')->whereIn('edge_genesis_passport_id', $passports->pluck('id'))->update([
                'stage' => 'two_fold_discovery', 'status' => 'queued', 'settled_at' => null,
                'evidence' => json_encode(['protocol' => self::PROTOCOL, 'mtf_bundle_hash' => $bundleHash,
                    'cohort_rebound' => true, 'prior_result_authority' => false, 'promotion_evidence' => false]),
                'updated_at' => now(),
            ]);
            foreach ($passports as $passport) {
                $evidence = (array) json_decode((string) $passport->evidence, true);
                DB::table('edge_genesis_passports')->where('id', $passport->id)->update([
                    'phase' => 'EDGE_DISCOVERY', 'status' => 'queued', 'evidence' => json_encode([...$evidence,
                        'mtf_bundle_hash' => $bundleHash, 'cohort_rebound' => true, 'prior_result_authority' => false]),
                    'phase_changed_at' => now(), 'updated_at' => now(),
                ]);
            }
            $context = (array) $generation->trigger_context;
            $generation->update(['trigger_context' => [...$context, 'mtf_bundle_hash' => $bundleHash, 'mtf_bundle_manifest' => $manifest,
                'mtf_cohort_rebind' => ['protocol' => 'edge_genesis_single_bundle_rebind_v1', 'prior_result_authority' => false]],
                'status' => 'queued', 'completed_at' => null]);
        });
        foreach (LabAgent::query()->whereIn('id', $dispatch)->get() as $agent) {
            EvaluateLabAgentJob::dispatch($agent->id, $agent->symbol, 'full');
        }

        return ['protocol' => self::PROTOCOL, 'status' => 'rebound', 'models' => $agents->count(), 'jobs_dispatched' => count($dispatch),
            'existing_queued_jobs_reused' => $agents->count() - count($dispatch), 'bundle_hash' => $bundleHash, 'promotion_evidence' => false];
    }

    /** Resume trials that were registered but rejected by the former generic screening gate. */
    public function resumePendingTrials(string $symbol, string $timeframe, bool $apply = false): array
    {
        $pending = DB::table('edge_genesis_trials as t')->join('edge_genesis_passports as p', 'p.id', '=', 't.edge_genesis_passport_id')
            ->join('lab_agents as a', 'a.id', '=', 't.lab_agent_id')
            ->where('p.symbol', strtoupper($symbol))->where('p.timeframe', strtoupper($timeframe))
            ->where('t.status', 'queued')->whereIn('a.lifecycle_status', ['screened', 'technical_quarantine', 'evaluation_error'])
            ->select(['t.id as trial_id', 't.edge_genesis_passport_id as passport_id', 't.arm', 't.stage',
                'a.id as agent_id', 'a.model_version_id'])->get();
        // A queued trial has already received authority from its constructor
        // or the preceding stage transition. Re-running the post-discovery
        // selector before its replay exists falsely classified every fresh
        // treatment as dominated and resumed controls only.
        $agentIds = $pending->pluck('agent_id')->map(fn ($id): int => (int) $id)->all();
        if ($agentIds === []) {
            return ['protocol' => self::PROTOCOL, 'status' => 'none', 'seats' => 0, 'promotion_evidence' => false];
        }
        $agents = LabAgent::query()->whereIn('id', $agentIds)->with('modelVersion')->get();
        $agents = $agents->filter(fn (LabAgent $agent): bool => $agent->lifecycle_status === 'screened'
            || $this->repairableAdmissionQuarantine($agent)
            || $this->repairableEdgeRuntimeFailure($agent))->values();
        if ($agents->isEmpty()) {
            return ['protocol' => self::PROTOCOL, 'status' => 'none', 'seats' => 0, 'promotion_evidence' => false];
        }
        if (! $apply) {
            return ['protocol' => self::PROTOCOL, 'status' => 'would_queue', 'seats' => $agents->count(), 'promotion_evidence' => false];
        }
        foreach ($agents as $agent) {
            $fromStatus = (string) $agent->lifecycle_status;
            $runtimeReason = $this->edgeRuntimeFailureReason($agent);
            $runtimeRecovery = $runtimeReason !== null;
            $numericControlRepair = $this->isCompiledControlNumericRepresentationQuarantine($agent);
            if ($fromStatus === 'technical_quarantine' && ! $runtimeRecovery) {
                $this->repairAdmissionMetadata($agent);
                $agent->refresh()->load('modelVersion');
            }
            $agent->update(['lifecycle_status' => 'full_queued', 'decision_reason' => $runtimeRecovery
                ? 'Direct research replay restored after an exact bounded-runtime infrastructure repair; prior partial output has no authority.'
                : ($numericControlRepair
                    ? 'Direct research replay restored after numeric control identity normalization; strategy parameters remain unchanged.'
                    : 'Direct research replay restored after immutable Foundry admission repair.')]);
            app(LabImmutableEvidenceService::class)->recordLifecycle($agent->fresh(), $runtimeRecovery
                ? 'edge_genesis_runtime_recovered' : 'edge_genesis_admission_recovered', [
                    'protocol' => $runtimeRecovery ? 'edge_genesis_bounded_runtime_recovery_v1'
                        : ($numericControlRepair ? 'edge_genesis_numeric_control_identity_recovery_v1' : 'edge_genesis_admission_metadata_recovery_v1'),
                    'reason_code' => $runtimeRecovery ? $runtimeReason
                        : ($numericControlRepair ? 'EDGE_COMPILED_CONTROL_NUMERIC_REPRESENTATION_NORMALIZED' : 'EDGE_CANONICAL_METADATA_MAPPED'),
                    'parameters_unchanged' => true,
                    'passport_hashes_unchanged' => true,
                    'prior_partial_result_authority' => false,
                    'quality_verdict' => 'withheld',
                    'promotion_evidence' => false,
                ], 'preflight', null, null, self::class, null, $fromStatus, 'full_queued');
            EvaluateLabAgentJob::dispatch($agent->id, $agent->symbol, 'full');
        }
        LabGeneration::query()->whereIn('id', $agents->pluck('lab_generation_id')->unique())->update([
            'status' => 'queued', 'completed_at' => null, 'updated_at' => now(),
        ]);

        return ['protocol' => self::PROTOCOL, 'status' => 'queued', 'seats' => $agents->count(), 'promotion_evidence' => false];
    }

    /**
     * Replicate a selected two-fold discovery on three different partitions.
     * These folds are compute admission only and are disjoint from both the
     * discovery and the later nine-fold authority windows.
     */
    public function materializeIndependentReplication(string $symbol, string $timeframe, bool $apply = false): array
    {
        $ratchetReconciliation = $apply ? $this->reconcileDiscoveryStageMastery($symbol, $timeframe) : ['assessed' => 0, 'retired' => 0];
        $progressing = DB::table('edge_genesis_trials as t')->join('edge_genesis_passports as p', 'p.id', '=', 't.edge_genesis_passport_id')
            ->join('lab_agents as a', 'a.id', '=', 't.lab_agent_id')
            ->where('p.symbol', strtoupper($symbol))->where('p.timeframe', strtoupper($timeframe))
            ->where('p.phase', 'EDGE_CONFIRMATION')->where('t.status', 'edge_progressing')
            ->where('t.stage', 'two_fold_discovery')->whereIn('a.lifecycle_status', ['screened', 'rejected'])
            ->select(['t.id as trial_id', 't.edge_genesis_passport_id as passport_id', 't.arm', 't.stage',
                'a.id as agent_id', 'a.model_version_id'])->get()
            ->filter(function ($trial): bool {
                $agent = LabAgent::query()->with('modelVersion')->find((int) $trial->agent_id);

                return data_get($agent?->modelVersion?->metadata, 'edge_genesis.frozen_window_plan.protocol')
                    === EdgeCohortIdentityService::WINDOW_PROTOCOL;
            })->values();
        if ($progressing->isEmpty()) {
            return ['protocol' => self::PROTOCOL, 'status' => 'none',
                'seats' => 0, 'folds' => 0, 'ratchet_reconciliation' => $ratchetReconciliation, 'promotion_evidence' => false];
        }

        $controls = DB::table('edge_genesis_trials as t')->join('lab_agents as a', 'a.id', '=', 't.lab_agent_id')
            ->whereIn('t.edge_genesis_passport_id', $progressing->pluck('passport_id')->unique())
            ->whereIn('t.arm', self::TRAVELING_CONTROL_ARMS)->where('t.stage', 'two_fold_discovery')
            ->whereIn('t.status', ['edge_progressing', 'control_settled', 'edge_not_found'])
            ->whereIn('a.lifecycle_status', ['screened', 'rejected'])
            ->select(['t.id as trial_id', 't.edge_genesis_passport_id as passport_id', 't.arm', 't.stage',
                'a.id as agent_id', 'a.model_version_id'])->get();
        $selection = $this->contextAuthoritySelection($progressing->concat($controls)->unique('trial_id')->values());
        if ($apply && $selection['dominated']->isNotEmpty()) {
            $this->retireDominatedContextTrials($selection['dominated']);
        }
        if ($apply && $selection['approved']->isNotEmpty()) {
            $this->recordApprovedContextTrials($selection['approved']);
        }
        $treatments = $selection['selected']->whereNotIn('arm', self::TRAVELING_CONTROL_ARMS)->values();
        if ($treatments->isEmpty()) {
            $orphanControls = $selection['selected']->whereIn('arm', self::TRAVELING_CONTROL_ARMS)->values();
            if ($apply && $orphanControls->isNotEmpty()) {
                $this->settleDiscoveryControlsWithoutTreatment($orphanControls);
            }

            return ['protocol' => self::PROTOCOL,
                'status' => $orphanControls->isEmpty() ? 'none' : ($apply ? 'controls_settled' : 'would_settle_controls'),
                'seats' => 0, 'folds' => 0, 'controls' => $orphanControls->count(),
                'discovery_approved' => 0, 'discovery_dominated' => $selection['dominated']->count(),
                'promotion_evidence' => false];
        }
        $traveling = $controls->whereIn('passport_id', $treatments->pluck('passport_id')->unique())->values();
        $trials = $treatments->concat($traveling)->unique('trial_id')->values();
        if (! $apply) {
            return ['protocol' => self::PROTOCOL, 'status' => 'would_queue',
                'seats' => $trials->count(), 'folds' => 3,
                'discovery_approved' => $selection['approved']->count(),
                'discovery_dominated' => $selection['dominated']->count(), 'promotion_evidence' => false];
        }

        DB::transaction(function () use ($trials): void {
            foreach ($trials as $trial) {
                $row = DB::table('edge_genesis_trials')->where('id', $trial->trial_id)->lockForUpdate()->first();
                if (! $row || (string) $row->stage !== 'two_fold_discovery') {
                    continue;
                }
                $agent = LabAgent::query()->with('modelVersion')->lockForUpdate()->findOrFail($trial->agent_id);
                $metadata = (array) $agent->modelVersion?->metadata;
                $plan = (array) data_get($metadata, 'edge_genesis.frozen_window_plan', []);
                $stage = (array) data_get($plan, 'stages.three_fold_confirmation', []);
                if (($plan['protocol'] ?? null) !== EdgeCohortIdentityService::WINDOW_PROTOCOL
                    || (int) ($stage['fold_count'] ?? 0) !== 3 || (int) ($stage['offset'] ?? -1) !== 2) {
                    continue;
                }
                $contract = [...$stage, 'stage' => 'three_fold_confirmation',
                    'window_plan_hash' => $plan['window_plan_hash'], 'universe_folds' => $plan['universe_folds']];
                $evidence = (array) json_decode((string) $row->evidence, true);
                $evidence['replication_dispatch'] = ['protocol' => 'edge_three_fold_replication_dispatch_v1',
                    ...$contract, 'discovery_result_authority' => false, 'promotion_evidence' => false];
                DB::table('edge_genesis_trials')->where('id', $row->id)->update([
                    'stage' => 'three_fold_confirmation', 'status' => 'queued', 'settled_at' => null,
                    'evidence' => json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                    'updated_at' => now(),
                ]);
                data_set($metadata, 'edge_genesis.phase', 'EDGE_CONFIRMATION');
                data_set($metadata, 'edge_genesis.validation_contract', $contract);
                unset($metadata['full_validation_batch']);
                $agent->modelVersion?->update(['metadata' => $metadata]);
                $agent->update(['lifecycle_status' => 'full_queued',
                    'decision_reason' => 'Three-fold independent Edge replication queued; discovery evidence remains compute-admission only.']);
            }
            DB::table('edge_genesis_passports')->whereIn('id', $trials->pluck('passport_id')->unique())->update([
                'status' => 'running', 'phase_changed_at' => now(), 'updated_at' => now(),
            ]);
            LabGeneration::query()->whereIn('id', LabAgent::query()->whereIn('id', $trials->pluck('agent_id'))
                ->pluck('lab_generation_id')->unique())->update(['status' => 'queued', 'completed_at' => null, 'updated_at' => now()]);
        });
        foreach ($trials as $trial) {
            EvaluateLabAgentJob::dispatch((int) $trial->agent_id, strtoupper($symbol), 'full');
        }

        return ['protocol' => self::PROTOCOL, 'status' => 'queued', 'seats' => $trials->count(),
            'folds' => 3, 'discovery_approved' => $selection['approved']->count(),
            'discovery_dominated' => $selection['dominated']->count(), 'promotion_evidence' => false];
    }

    /** Promote independently replicated candidates into one bounded nine-fold authority cohort. */
    public function materializeConfirmation(string $symbol, string $timeframe, bool $apply = false): array
    {
        $progressing = DB::table('edge_genesis_trials as t')->join('edge_genesis_passports as p', 'p.id', '=', 't.edge_genesis_passport_id')
            ->join('lab_agents as a', 'a.id', '=', 't.lab_agent_id')
            ->where('p.symbol', strtoupper($symbol))->where('p.timeframe', strtoupper($timeframe))
            ->where('p.phase', 'EDGE_CONFIRMATION')->where('t.status', 'edge_replication_passed')
            ->where('t.stage', 'three_fold_confirmation')->whereIn('a.lifecycle_status', ['screened', 'rejected'])
            ->select(['t.id as trial_id', 't.edge_genesis_passport_id as passport_id', 't.arm', 't.stage',
                'a.id as agent_id', 'a.model_version_id'])->get();
        // In the management-harvest revision, absolute authority without the
        // exact source composition is not causal evidence. Promote the
        // latent control alongside a promising treatment even when the
        // control itself missed the cheap discovery screen. Conversely, a
        // control alone can never spend a nine-fold authority budget.
        $travelingControlArms = self::TRAVELING_CONTROL_ARMS;
        if ($progressing->isEmpty()) {
            return ['protocol' => self::PROTOCOL, 'status' => 'none', 'seats' => 0, 'promotion_evidence' => false];
        }
        // A sparse specialist may pass after its exact control has already
        // been terminalized. Reload only the controls belonging to passports
        // that currently have a viable treatment so comparative selection
        // remains local and old cohorts cannot be reopened.
        $pairedControls = DB::table('edge_genesis_trials as t')
            ->join('lab_agents as a', 'a.id', '=', 't.lab_agent_id')
            ->whereIn('t.edge_genesis_passport_id', $progressing->pluck('passport_id')->unique())
            // A newly admitted sparse treatment may arrive after another
            // treatment already paid for the control's nine-fold replay.
            // Reuse that immutable control for discovery comparison instead
            // of spending the same replay budget a second time.
            ->whereIn('t.arm', $travelingControlArms)
            ->whereIn('t.stage', ['three_fold_confirmation', 'nine_fold_authority'])
            ->whereIn('t.status', ['edge_replication_passed', 'replication_control_settled', 'control_settled', 'edge_not_found'])
            ->whereIn('a.lifecycle_status', ['screened', 'rejected'])
            ->select(['t.id as trial_id', 't.edge_genesis_passport_id as passport_id', 't.arm', 't.stage',
                'a.id as agent_id', 'a.model_version_id'])->get();
        // Discovery selection was frozen before the independent replay. At
        // this boundary only the paired three-fold verdict is authoritative;
        // rerunning discovery against a mutable projection can reverse a
        // settled decision after the 3-fold row replaces the 2-fold row.
        $progressing = $progressing->concat($pairedControls)->unique('trial_id')->values();
        $treatments = $progressing->whereNotIn('arm', $travelingControlArms)->values();
        $orphanControls = $progressing->whereIn('arm', $travelingControlArms)->values();
        if ($treatments->isEmpty() && $orphanControls->isNotEmpty()) {
            if ($apply) {
                $this->settleDiscoveryControlsWithoutTreatment($orphanControls);
            }

            return ['protocol' => self::PROTOCOL,
                'status' => $apply ? 'controls_settled' : 'would_settle_controls',
                'seats' => 0, 'folds' => 0, 'controls' => $orphanControls->count(),
                'replication_approved' => 0, 'discovery_dominated' => 0,
                'promotion_evidence' => false];
        }
        $travelingControls = $treatments->isEmpty() ? collect() : DB::table('edge_genesis_trials as t')
            ->join('edge_genesis_passports as p', 'p.id', '=', 't.edge_genesis_passport_id')
            ->join('lab_agents as a', 'a.id', '=', 't.lab_agent_id')
            ->whereIn('t.edge_genesis_passport_id', $treatments->pluck('passport_id')->unique())
            ->where('p.phase', 'EDGE_CONFIRMATION')->whereIn('t.arm', $travelingControlArms)
            ->where('t.stage', 'three_fold_confirmation')
            ->whereIn('t.status', ['edge_replication_passed', 'replication_control_settled', 'edge_not_found', 'control_settled'])
            ->whereIn('a.lifecycle_status', ['screened', 'rejected'])
            ->select(['t.id as trial_id', 't.edge_genesis_passport_id as passport_id', 't.arm', 't.stage',
                'a.id as agent_id', 'a.model_version_id'])->get();
        $trials = $treatments->concat($travelingControls)->unique('trial_id')->values();
        if ($trials->isEmpty()) {
            return ['protocol' => self::PROTOCOL, 'status' => 'none', 'seats' => 0, 'promotion_evidence' => false];
        }
        if (! $apply) {
            return ['protocol' => self::PROTOCOL, 'status' => 'would_queue', 'seats' => $trials->count(),
                'folds' => 9, 'replication_approved' => $treatments->count(),
                'discovery_dominated' => 0, 'promotion_evidence' => false];
        }
        DB::transaction(function () use ($trials, $travelingControlArms): void {
            foreach ($trials as $trial) {
                $row = DB::table('edge_genesis_trials')->where('id', $trial->trial_id)->lockForUpdate()->first();
                $evidence = (array) json_decode((string) $row->evidence, true);
                if (in_array((string) $trial->arm, $travelingControlArms, true)) {
                    $evidence['authority_selection'] = [
                        'protocol' => 'paired_discovery_authority_budget_v1',
                        'decision' => 'exact_control_replayed_for_paired_authority',
                        'reason' => 'EXACT_CONTROL_REQUIRED_FOR_PAIRED_NINE_FOLD_CAUSALITY',
                        'authority_granted' => false,
                        'nine_fold_replay_admitted' => true,
                        'promotion_evidence' => false,
                    ];
                }
                $agent = LabAgent::query()->with('modelVersion')->lockForUpdate()->findOrFail($trial->agent_id);
                $metadata = (array) $agent->modelVersion?->metadata;
                $plan = (array) data_get($metadata, 'edge_genesis.frozen_window_plan', []);
                $authorityStage = (array) data_get($plan, 'stages.nine_fold_authority', []);
                if (($plan['protocol'] ?? null) !== EdgeCohortIdentityService::WINDOW_PROTOCOL
                    || (int) ($authorityStage['fold_count'] ?? 0) !== 9 || (int) ($authorityStage['offset'] ?? -1) !== 5) {
                    continue;
                }
                $validationContract = [...$authorityStage, 'stage' => 'nine_fold_authority',
                    'window_plan_hash' => $plan['window_plan_hash'], 'universe_folds' => $plan['universe_folds']];
                $evidence['authority_dispatch'] = [
                    'protocol' => 'edge_nine_fold_authority_dispatch_v1',
                    'requested_folds' => 9, ...$validationContract,
                    'discovery_result_authority' => false,
                    'promotion_evidence' => false,
                ];
                DB::table('edge_genesis_trials')->where('id', $row->id)->update([
                    'stage' => 'nine_fold_authority', 'status' => 'queued', 'settled_at' => null,
                    'evidence' => json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                    'updated_at' => now(),
                ]);
                data_set($metadata, 'edge_genesis.phase', 'EDGE_CONFIRMATION');
                data_set($metadata, 'edge_genesis.validation_contract', $validationContract);
                data_set($metadata, 'edge_genesis.authority_contract', [
                    'protocol' => 'edge_nine_fold_authority_dispatch_v1',
                    'requested_folds' => 9, ...$validationContract,
                    'discovery_result_authority' => false,
                    'risk_governor_frozen' => true,
                    'promotion_evidence' => false,
                ]);
                // A discovery response is valid audit evidence, but it can
                // never satisfy a nine-fold request through a model cache.
                unset($metadata['full_validation_batch']);
                $agent->modelVersion?->update(['metadata' => $metadata]);
                $agent->update([
                    'lifecycle_status' => 'full_queued',
                    'decision_reason' => 'Nine-fold Edge confirmation queued; discovery cache retired and risk remains frozen.',
                ]);
            }
            DB::table('edge_genesis_passports')->whereIn('id', $trials->pluck('passport_id')->unique())->update([
                'status' => 'running', 'phase_changed_at' => now(), 'updated_at' => now(),
            ]);
            LabGeneration::query()->whereIn('id', LabAgent::query()->whereIn('id', $trials->pluck('agent_id'))
                ->pluck('lab_generation_id')->unique())->update([
                    'status' => 'queued', 'completed_at' => null, 'updated_at' => now(),
                ]);
        });
        $agents = LabAgent::query()->whereIn('id', $trials->pluck('agent_id'))->get();
        foreach ($agents as $agent) {
            EvaluateLabAgentJob::dispatch($agent->id, $agent->symbol, 'full');
        }

        return ['protocol' => self::PROTOCOL, 'status' => 'queued', 'seats' => $agents->count(), 'folds' => 9,
            'replication_approved' => $treatments->count(),
            'discovery_dominated' => 0, 'promotion_evidence' => false];
    }

    /**
     * Edge attribution is a distinct experiment from Genesis: full composition
     * versus confirmation/tactic/temporal ablations and a frozen minimal arm.
     */
    public function materializeAttribution(LabAgent $edgeAgent): array
    {
        $edgeAgent->loadMissing('modelVersion', 'generation.laboratory');
        $genesis = (array) data_get($edgeAgent->modelVersion?->metadata, 'edge_genesis', []);
        if (data_get($genesis, 'protocol') !== self::PROTOCOL || data_get($genesis, 'phase') !== 'EDGE_ATTRIBUTION') {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason' => 'EDGE_ATTRIBUTION_PHASE_REQUIRED', 'promotion_evidence' => false];
        }
        $passport = DB::table('edge_genesis_passports')->where('genesis_key', data_get($genesis, 'genesis_key'))->first();
        $lab = $edgeAgent->generation?->laboratory;
        if (! $passport || ! $lab) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason' => 'EDGE_GENESIS_CONTEXT_MISSING', 'promotion_evidence' => false];
        }
        $sourceTrial = DB::table('edge_genesis_trials')->where('lab_agent_id', $edgeAgent->id)->first();
        $sourceMetrics = $this->latestValidMetrics($edgeAgent->modelVersion, $edgeAgent->symbol, $edgeAgent->timeframe);
        $sourceContextRequired = data_get($genesis, 'context.enforcement') === 'required';
        if (! $sourceTrial || (string) $sourceTrial->stage !== 'nine_fold_authority'
            || (string) $sourceTrial->status !== 'edge_progressing'
            || ! $this->edgeAdmissionPassed($sourceMetrics, $sourceContextRequired)) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked',
                'reason' => 'NINE_FOLD_EDGE_SOURCE_MUST_RECONFIRM_BEFORE_ATTRIBUTION',
                'promotion_evidence' => false];
        }
        $sourceWindowIdentity = $this->discoveryWindowIdentity($sourceMetrics);
        $sourceParameterHash = $this->parameterHash((array) $edgeAgent->modelVersion->parameters);
        $arms = ['full_composition', 'no_confirmation', 'alternate_tactic', 'alternate_temporal_binding', 'frozen_minimal_control'];
        $existing = DB::table('edge_genesis_trials')->where('edge_genesis_passport_id', $passport->id)->where('packet_key', data_get($genesis, 'packet_key').':attribution')
            ->whereIn('status', ['queued', 'running', 'settled'])->exists();
        if ($existing) {
            return ['protocol' => self::PROTOCOL, 'status' => 'already_materialized', 'promotion_evidence' => false];
        }
        $generation = null;
        $agents = [];
        $kernel = null;
        DB::transaction(function () use ($edgeAgent, $genesis, $passport, $lab, $sourceTrial, $sourceWindowIdentity, $sourceParameterHash, $arms, &$generation, &$agents, &$kernel): void {
            $sourceContext = (array) $edgeAgent->generation?->trigger_context;
            $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => ((int) $lab->generations()->max('generation')) + 1,
                'trigger_type' => 'edge_component_attribution', 'trigger_context' => ['protocol' => self::PROTOCOL, 'genesis_key' => $genesis['genesis_key'],
                    'source_nine_fold_trial_id' => (int) $sourceTrial->id,
                    'phase' => 'EDGE_ATTRIBUTION', 'data_hash' => $passport->data_hash, 'execution_hash' => $passport->execution_hash,
                    'mtf_bundle_hash' => data_get($sourceContext, 'mtf_bundle_hash'),
                    'mtf_bundle_manifest' => data_get($sourceContext, 'mtf_bundle_manifest'),
                    'canonical_dataset_snapshots' => data_get($sourceContext, 'canonical_dataset_snapshots'),
                    'risk_governor_frozen' => true, 'research_only' => true, 'promotion_evidence' => false],
                'data_fingerprint' => $passport->data_hash, 'population_size' => CausalCompoundingKernelService::POPULATION_SIZE, 'status' => 'queued', 'started_at' => now()]);
            foreach ($arms as $index => $arm) {
                $baseStrategy = (string) data_get($edgeAgent->modelVersion->metadata, 'base_strategy', 'confirmation_entry_mtf_v1');
                $parameters = $this->attributionParameters($arm, (array) $edgeAgent->modelVersion->parameters, $baseStrategy);
                $metadata = [...((array) $edgeAgent->modelVersion->metadata), 'edge_genesis' => [...$genesis, 'phase' => 'EDGE_ATTRIBUTION'],
                    'edge_genesis_attribution' => ['protocol' => self::PROTOCOL, 'genesis_key' => $genesis['genesis_key'], 'packet_key' => $genesis['packet_key'],
                        'arm' => $arm, 'source_model_version_id' => (int) $edgeAgent->model_version_id,
                        'source_trial_id' => (int) $sourceTrial->id, 'source_parameter_hash' => $sourceParameterHash,
                        'source_window_identity' => $sourceWindowIdentity, 'requested_folds' => 9,
                        'data_hash' => $passport->data_hash, 'execution_hash' => $passport->execution_hash,
                        'mtf_bundle_hash' => data_get($genesis, 'mtf_bundle_hash'),
                        'risk_governor_frozen' => true, 'research_only' => true, 'promotion_evidence' => false]];
                $runtimeLabel = 'edge_'.$genesis['packet_key'].'_attr_g'.$generation->generation.'_a'.($index + 1);
                $model = ModelVersion::create(['name' => $edgeAgent->modelVersion->name.' attribution '.$arm, 'strategy' => $runtimeLabel,
                    'version' => $edgeAgent->modelVersion->version.'-attr-'.$index, 'generation' => $generation->generation, 'status' => 'testing',
                    'description' => 'Post-edge component attribution; research-only.', 'change_log' => 'edge attribution '.$arm, 'parameters' => $parameters, 'metadata' => $metadata, 'evidence_status' => 'valid']);
                $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id, 'parent_a_model_version_id' => null,
                    'symbol' => $edgeAgent->symbol, 'timeframe' => $edgeAgent->timeframe, 'strategy_family' => $edgeAgent->strategy_family, 'origin' => 'edge_component_attribution',
                    'lifecycle_status' => 'full_queued', 'parameter_diff' => $this->diff((array) $edgeAgent->modelVersion->parameters, $parameters), 'decision_reason' => 'Frozen-risk Edge Genesis component attribution '.$arm.'.']);
                DB::table('edge_genesis_trials')->insert(['trial_key' => hash('sha256', $genesis['genesis_key'].'|attribution|'.$arm), 'edge_genesis_passport_id' => $passport->id,
                    'lab_agent_id' => $agent->id, 'model_version_id' => $model->id, 'packet_key' => $genesis['packet_key'].':attribution', 'emitter' => 'adversarial', 'arm' => $arm,
                    'stage' => 'component_attribution', 'status' => 'queued', 'evidence' => json_encode(['protocol' => self::PROTOCOL, 'whole_organism_credit_withheld' => true, 'risk_governor_frozen' => true, 'promotion_evidence' => false]), 'created_at' => now(), 'updated_at' => now()]);
                $compositionPassport = (array) data_get($metadata, 'smart_composition.composition_passport', []);
                $this->mastery->enroll($agent, $compositionPassport, [
                    'packet_key' => $genesis['packet_key'].':attribution', 'arm' => $arm,
                    'data_hash' => (string) $passport->data_hash, 'execution_hash' => (string) $passport->execution_hash,
                    'edge_genesis_passport_id' => (int) $passport->id,
                ]);
                $agents[] = $agent;
            }
            $protectedGenes = collect($agents)->flatMap(
                fn (LabAgent $candidate): array => array_keys((array) $candidate->parameter_diff),
            )->unique()->values()->all();
            $kernel = $this->compoundingKernel->complete(
                $generation,
                $edgeAgent->modelVersion,
                $edgeAgent,
                (string) $passport->data_hash,
                (string) $passport->execution_hash,
                'edge_quality',
                $protectedGenes,
            );
        });
        foreach ($agents as $agent) {
            EvaluateLabAgentJob::dispatch($agent->id, $agent->symbol, 'full');
        }
        foreach ((array) data_get($kernel, 'dispatches', []) as $dispatch) {
            EvaluateLabAgentJob::dispatch($dispatch['agent']->id, $dispatch['agent']->symbol, $dispatch['mode']);
        }

        return ['protocol' => self::PROTOCOL, 'status' => 'queued', 'generation_id' => $generation?->id,
            'agent_ids' => collect([...$agents, ...((array) data_get($kernel, 'agents', []))])->pluck('id')->all(),
            'population_size' => CausalCompoundingKernelService::POPULATION_SIZE,
            'primary_proof_seats' => count($agents),
            'compounding_kernel' => data_get($kernel, 'contract'), 'promotion_evidence' => false];
    }

    private function settleAttributionOutcome(LabAgent $agent): array
    {
        $contract = (array) data_get($agent->modelVersion?->metadata, 'edge_genesis_attribution', []);
        $passport = DB::table('edge_genesis_passports')->where('genesis_key', data_get($contract, 'genesis_key'))->first();
        if (! $passport) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason' => 'EDGE_GENESIS_PASSPORT_MISSING', 'promotion_evidence' => false];
        }
        if ((string) $passport->status === 'attribution_settled') {
            return ['protocol' => self::PROTOCOL, 'status' => 'already_settled', 'phase' => 'RISK_SHAPING', 'promotion_evidence' => false];
        }
        $trials = DB::table('edge_genesis_trials')->where('edge_genesis_passport_id', $passport->id)->where('packet_key', data_get($contract, 'packet_key').':attribution')->get();
        $expectedArms = ['full_composition', 'no_confirmation', 'alternate_tactic', 'alternate_temporal_binding', 'frozen_minimal_control'];
        if ($trials->count() !== count($expectedArms)
            || collect($expectedArms)->diff($trials->pluck('arm'))->isNotEmpty()) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked',
                'reason' => 'COMPLETE_ATTRIBUTION_ARM_SET_REQUIRED', 'promotion_evidence' => false];
        }
        $metrics = [];
        $models = [];
        foreach ($trials as $trial) {
            $model = ModelVersion::query()->find($trial->model_version_id);
            $performance = $model?->marketPerformances()->where('symbol', $agent->symbol)
                ->where('timeframe', $agent->timeframe)->where('evidence_status', 'valid')->latest('id')->first();
            if (! $model || ! $performance || ! is_array($performance->metrics)) {
                return ['protocol' => self::PROTOCOL, 'status' => 'awaiting_attribution_arms', 'promotion_evidence' => false];
            }
            $models[$trial->arm] = $model;
            $metrics[$trial->arm] = (array) $performance->metrics;
        }

        $expectedWindows = array_values((array) data_get($contract, 'source_window_identity', []));
        $expectedMtf = (string) data_get($contract, 'mtf_bundle_hash', '');
        $identityViolations = [];
        foreach ($metrics as $arm => $result) {
            $observedData = (string) data_get($result, 'data_manifest.sha256', data_get($result, 'data_hash', ''));
            $observedExecution = (string) data_get($result, 'execution_contract.execution_hash', data_get($result, 'execution_hash', ''));
            $observedMtf = (string) data_get($result, 'data_manifest.mtf_bundle_hash', data_get($result, 'mtf_snapshot_manifest.bundle_hash', ''));
            $observedWindows = $this->discoveryWindowIdentity($result);
            if ($observedData === '' || ! hash_equals((string) $passport->data_hash, $observedData)
                || $observedExecution === '' || ! hash_equals((string) $passport->execution_hash, $observedExecution)
                || $expectedMtf === '' || $observedMtf === '' || ! hash_equals($expectedMtf, $observedMtf)
                || $expectedWindows === [] || $observedWindows !== $expectedWindows
                || (int) data_get($result, 'forward_window_protocol.powered_windows', 0) < 9
                || ! $this->observability($result)) {
                $identityViolations[] = $arm;
            }
        }
        $fullMetrics = (array) ($metrics['full_composition'] ?? []);
        $fullModel = $models['full_composition'] ?? null;
        $sourceParameterHash = (string) data_get($contract, 'source_parameter_hash', '');
        if (! $fullModel || $sourceParameterHash === ''
            || ! hash_equals($sourceParameterHash, $this->parameterHash((array) $fullModel->parameters))) {
            $identityViolations[] = 'full_composition_parameter_identity';
        }
        $contextRequired = data_get($fullModel?->metadata, 'edge_genesis.context.enforcement') === 'required';
        if (! $this->edgeAdmissionPassed($fullMetrics, $contextRequired)) {
            $identityViolations[] = 'full_composition_edge_not_reconfirmed';
        }
        if ($identityViolations !== []) {
            $evidence = (array) json_decode((string) $passport->evidence, true);
            $evidence['attribution_settlement'] = ['protocol' => 'paired_edge_component_attribution_v2',
                'status' => 'invalid_identity', 'violations' => array_values(array_unique($identityViolations)),
                'parent_authority' => false, 'promotion_evidence' => false];
            DB::table('edge_genesis_passports')->where('id', $passport->id)->update([
                'status' => 'attribution_invalid',
                'evidence' => json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                'updated_at' => now(),
            ]);

            return ['protocol' => self::PROTOCOL, 'status' => 'blocked',
                'reason' => 'ATTRIBUTION_IDENTITY_OR_FULL_EDGE_RECONFIRMATION_FAILED',
                'violations' => array_values(array_unique($identityViolations)), 'promotion_evidence' => false];
        }

        $full = $this->afterCost($fullMetrics);
        $fullParameterHash = $this->parameterHash((array) $fullModel->parameters);
        $axes = ['confirmation' => 'no_confirmation', 'tactic' => 'alternate_tactic',
            'temporal_binding' => 'alternate_temporal_binding', 'minimal_control' => 'frozen_minimal_control'];
        $effectStatuses = [];
        foreach ($axes as $axis => $arm) {
            $alternative = (array) ($metrics[$arm] ?? []);
            $delta = round($full - $this->afterCost($alternative), 6);
            $paired = $this->pairedWindowEffect($fullMetrics, $alternative);
            $parameterChanged = ! hash_equals($fullParameterHash, $this->parameterHash((array) $models[$arm]->parameters));
            $behaviorChanged = $parameterChanged && (
                (string) data_get($fullMetrics, 'trade_ledger_hash', '') !== (string) data_get($alternative, 'trade_ledger_hash', '')
                || (string) data_get($fullMetrics, 'signal_decision_hash', '') !== (string) data_get($alternative, 'signal_decision_hash', '')
                || (int) data_get($fullMetrics, 'total_trades', 0) !== (int) data_get($alternative, 'total_trades', 0)
            );
            $status = ! $behaviorChanged ? 'invalid_no_behavior_change'
                : (($delta > 0 && (int) $paired['positive_windows'] >= 5) ? 'supported'
                    : (($delta < 0 && (int) $paired['negative_windows'] >= 5) ? 'contraindicated' : 'uncertain'));
            $effectStatuses[$axis] = $status;
            DB::table('edge_genesis_component_attributions')->updateOrInsert(['attribution_key' => hash('sha256', $passport->genesis_key.'|'.$axis)], ['edge_genesis_passport_id' => $passport->id,
                'packet_key' => data_get($contract, 'packet_key'), 'component_axis' => $axis, 'status' => $status, 'after_cost_delta' => $delta,
                'evidence' => json_encode(['protocol' => 'paired_edge_component_attribution_v2',
                    'full_after_cost_r' => $full, 'alternative_after_cost_r' => $this->afterCost($alternative),
                    'parameter_changed' => $parameterChanged, 'behavior_changed' => $behaviorChanged,
                    'same_data_execution_mtf_and_windows' => true, 'paired_window_effect' => $paired,
                    'parent_authority' => false, 'promotion_evidence' => false], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                'settled_at' => now(), 'updated_at' => now(), 'created_at' => now()]);
        }
        $resolved = ! collect($effectStatuses)->contains(fn (string $status): bool => in_array($status, ['uncertain', 'invalid_no_behavior_change'], true));
        DB::transaction(function () use ($trials, $passport, $effectStatuses, $resolved): void {
            foreach ($trials as $trial) {
                $evidence = (array) json_decode((string) $trial->evidence, true);
                $evidence['attribution_settlement'] = ['protocol' => 'paired_edge_component_attribution_v2',
                    'effect_statuses' => $effectStatuses, 'resolved' => $resolved,
                    'exactly_once' => true, 'promotion_evidence' => false];
                DB::table('edge_genesis_trials')->where('id', $trial->id)->update([
                    'status' => 'settled', 'settled_at' => now(),
                    'evidence' => json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                    'updated_at' => now(),
                ]);
            }
            $passportEvidence = (array) json_decode((string) $passport->evidence, true);
            $passportEvidence['attribution_settlement'] = ['protocol' => 'paired_edge_component_attribution_v2',
                'effect_statuses' => $effectStatuses, 'resolved' => $resolved,
                'full_edge_reconfirmed' => true, 'parent_authority' => false, 'promotion_evidence' => false];
            DB::table('edge_genesis_passports')->where('id', $passport->id)->update([
                'phase' => $resolved ? 'RISK_SHAPING' : 'EDGE_ATTRIBUTION',
                'status' => $resolved ? 'attribution_settled' : 'attribution_inconclusive',
                'evidence' => json_encode($passportEvidence, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                'phase_changed_at' => now(), 'updated_at' => now(),
            ]);
        });

        return ['protocol' => self::PROTOCOL,
            'status' => $resolved ? 'attribution_settled' : 'attribution_inconclusive',
            'phase' => $resolved ? 'RISK_SHAPING' : 'EDGE_ATTRIBUTION',
            'effect_statuses' => $effectStatuses, 'promotion_evidence' => false];
    }

    /** Fail closed when a replay cannot expose the decision-to-outcome chain. */
    public function observability(array $result): bool
    {
        $ledger = (array) data_get($result, 'edge_observability', data_get($result, 'confirmation_entry_ledger', []));
        $required = ['opportunity_detected', 'setup_location_valid', 'context_bias_aligned', 'confirmation', 'entry', 'execution_price', 'invalidation_price', 'mfe_mae', 'exit_outcome'];
        if ($ledger !== []) {
            return collect($required)->every(fn (string $key): bool => array_key_exists($key, $ledger));
        }

        return (bool) data_get($result, 'confirmation_entry_observed', false) && (bool) data_get($result, 'behavior_delta_observed', false);
    }

    /** Reject a Genesis replay before its expensive authority stage if it cannot emit the required ledger. */
    public function preflight(LabAgent $agent): array
    {
        $contract = (array) data_get($agent->modelVersion?->metadata, 'edge_genesis', []);
        if (data_get($contract, 'protocol') !== self::PROTOCOL) {
            return ['allowed' => true, 'status' => 'not_edge_genesis', 'promotion_evidence' => false];
        }
        $ledger = (array) data_get($agent->modelVersion?->metadata, 'edge_observability_contract', []);
        $required = (array) data_get($ledger, 'required_fields', []);
        $executionContract = (array) data_get($agent->modelVersion?->metadata, 'execution_contract', []);
        $mtfManifest = (array) data_get($contract, 'mtf_bundle_manifest', []);
        $mtfBundleHash = (string) data_get($contract, 'mtf_bundle_hash', '');
        $context = (array) data_get($contract, 'context', []);
        $attributionArm = (string) data_get($agent->modelVersion?->metadata, 'edge_genesis_attribution.arm', '');
        $confirmationBypass = (bool) data_get($agent->modelVersion?->parameters, 'attribution_confirmation_bypass', false);
        $attributionAblationValid = $confirmationBypass
            ? $attributionArm === 'no_confirmation'
            : $attributionArm !== 'no_confirmation';
        $contextAxes = array_values((array) data_get($context, 'admission_axes', []));
        $contextValid = $context !== [] && (data_get($context, 'enforcement') !== 'required'
            || (data_get($context, 'protocol') === 'edge_context_authority_firewall_v1'
                && data_get($context, 'pre_entry_only') === true
                && data_get($context, 'outside_scope') === 'WAIT'
                && $contextAxes !== []
                && collect($contextAxes)->every(function (string $axis) use ($context): bool {
                    $key = match ($axis) {
                        'regime' => 'allowed_regimes',
                        'session' => 'allowed_sessions',
                        'volatility' => 'allowed_volatility',
                        'direction' => 'allowed_directions',
                        default => null,
                    };

                    return $key !== null && (array) data_get($context, $key, []) !== [];
                })));
        $allowed = data_get($ledger, 'protocol') === self::PROTOCOL && count($required) === 9
            && (bool) data_get($ledger, 'must_exist_before_nine_fold')
            && (bool) data_get($contract, 'risk_governor_frozen')
            && (bool) data_get($contract, 'pre_2026_only')
            && $attributionAblationValid
            && $contextValid
            && strtoupper((string) data_get($contract, 'execution_timeframe', '')) === self::EXECUTION_TIMEFRAME
            && strtoupper((string) data_get($executionContract, 'timeframe', '')) === self::EXECUTION_TIMEFRAME
            && $this->executionContracts->matches($executionContract, $agent->symbol, self::EXECUTION_TIMEFRAME)
            && preg_match('/^[a-f0-9]{64}$/', $mtfBundleHash) === 1
            && hash_equals($mtfBundleHash, (string) data_get($mtfManifest, 'bundle_hash', ''))
            && data_get($mtfManifest, 'validation_bundle_protocol') === 'agent_owned_mtf_foundation_bundle_v1'
            && data_get($mtfManifest, 'data_role') === 'pre_2026_foundation_training_only'
            && data_get($mtfManifest, 'promotion_evidence') === false;

        return ['protocol' => self::PROTOCOL, 'allowed' => $allowed, 'status' => $allowed ? 'admitted' : 'INVALID_EDGE_OBSERVABILITY',
            'required_fields' => $required, 'attribution_ablation_valid' => $attributionAblationValid,
            'promotion_evidence' => false];
    }

    /** Read-only progress dashboard: discovery progress is not mistaken for promotion progress. */
    public function dashboard(string $symbol, string $timeframe): array
    {
        if (! $this->available()) {
            return ['protocol' => self::PROTOCOL, 'status' => 'unavailable', 'promotion_evidence' => false];
        }
        $symbol = strtoupper($symbol);
        $timeframe = strtoupper($timeframe);
        $passports = DB::table('edge_genesis_passports')->where('symbol', $symbol)->where('timeframe', $timeframe);
        $ids = (clone $passports)->pluck('id');
        $trials = DB::table('edge_genesis_trials')->whereIn('edge_genesis_passport_id', $ids);
        $transplants = Schema::hasTable('skill_cartridge_transplant_trials')
            ? DB::table('skill_cartridge_transplant_trials')->where('symbol', $symbol)->where('timeframe', $timeframe)
            : null;
        $settledTransplantArms = $transplants ? (clone $transplants)->whereIn('status', ['passed', 'failed'])->count() : 0;
        $passedTransplantArms = $transplants ? (clone $transplants)->where('status', 'passed')->count() : 0;
        $allEdgeTrials = (clone $trials)->count();
        $observableEdgeTrials = (clone $trials)->whereNotIn('status', ['invalid_edge_observability'])->count();
        $scope = ['symbol' => $symbol, 'laboratory_timeframe' => $timeframe];
        $period = ['kind' => 'all_time', 'through' => now()->utc()->toIso8601String()];

        return ['protocol' => self::PROTOCOL, 'status' => 'research_monitoring',
            'edge_bearing_compositions' => (clone $passports)->whereIn('phase', ['EDGE_ATTRIBUTION', 'RISK_SHAPING', 'MANAGEMENT_OPTIMIZATION', 'PAPER_VALIDATION'])->count(),
            // EDGE_CONFIRMATION is still a question, not a viable specialist.
            // A composition becomes edge-bearing only after its authority
            // replay advances the passport into attribution.
            'contextual_viable_specialists' => (clone $passports)->whereIn('phase', ['EDGE_ATTRIBUTION', 'RISK_SHAPING', 'MANAGEMENT_OPTIMIZATION', 'PAPER_VALIDATION'])->count(),
            'positive_powered_folds' => (clone $trials)->where('status', 'edge_progressing')->count(),
            'confirmed_component_effects' => DB::table('edge_genesis_component_attributions')->whereIn('edge_genesis_passport_id', $ids)->where('status', 'supported')->count(),
            'transplant_arm_pass_rate' => $this->ratioMetric($passedTransplantArms, $settledTransplantArms,
                'passed_transplant_arm_trial', 'settled_pass_or_fail_transplant_arm_trial', $scope, $period,
                'Research arm outcomes only; this is not confirmed transfer, mentor, parent, paper, or deployment authority.'),
            'retired_dead_baselines' => (clone $trials)->where('status', 'edge_not_found')->count(),
            'valid_edge_observability_trial_share' => $this->ratioMetric($observableEdgeTrials, $allEdgeTrials,
                'edge_trial_without_invalid_observability_status', 'scoped_edge_trial', $scope, $period,
                'Engineering observability coverage only; it is not economic or promotion evidence.'),
            'compute_wasted_on_invalid_trials' => (clone $trials)->whereIn('status', ['invalid_edge_observability', 'invalid_hash_mismatch'])->count(),
            'full_stack_passports' => Schema::hasTable('full_stack_playbook_passports') ? DB::table('full_stack_playbook_passports')->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->count() : null,
            'procedural_mastery_only' => Schema::hasTable('full_stack_playbook_passports') ? DB::table('full_stack_playbook_passports')->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->where('status', 'procedural_mastery_only')->count() : null,
            'master_candidates' => Schema::hasTable('full_stack_playbook_passports') ? DB::table('full_stack_playbook_passports')->where('symbol', strtoupper($symbol))->where('timeframe', strtoupper($timeframe))->where('status', 'master_candidate')->count() : null,
            'architecture_repair' => $this->architectureRepairReadiness($symbol, $timeframe),
            'promotion_evidence' => false];
    }

    /** @return array<string,mixed> */
    private function ratioMetric(
        int $numerator,
        int $denominator,
        string $numeratorSubject,
        string $denominatorSubject,
        array $scope,
        array $period,
        string $interpretation,
    ): array {
        return [
            'value' => $denominator > 0 ? round($numerator / $denominator, 6) : null,
            'unit' => 'ratio',
            'status' => $denominator > 0 ? 'measured' : 'no_denominator',
            'scope' => $scope,
            'period' => $period,
            'numerator' => ['value' => $numerator, 'unique_subject_type' => $numeratorSubject],
            'denominator' => ['value' => $denominator, 'unique_subject_type' => $denominatorSubject],
            'interpretation' => $interpretation,
        ];
    }

    /**
     * Append the paired nine-fold router effect after both arms settle. This
     * is the durable bridge between a useful-but-insufficient filter and the
     * next architecture packet; it never upgrades the treatment to edge.
     */
    public function reconcileContextAuthorityEffects(string $symbol, string $timeframe, bool $apply = false): array
    {
        if (! $this->available()) {
            return ['protocol' => 'context_authority_effect_settlement_v1',
                'status' => 'unavailable', 'effects' => 0, 'promotion_evidence' => false];
        }
        $passports = DB::table('edge_genesis_passports as p')->join('lab_generations as g', 'g.id', '=', 'p.lab_generation_id')
            ->where('p.symbol', strtoupper($symbol))->where('p.timeframe', strtoupper($timeframe))
            ->where('g.trigger_context->architecture_revision', self::CONTEXT_ROUTER_REPAIR_REVISION)
            ->select(['p.id', 'p.genesis_key', 'p.evidence'])->get();
        $effects = collect();
        foreach ($passports as $passport) {
            $trials = DB::table('edge_genesis_trials')->where('edge_genesis_passport_id', $passport->id)
                ->whereIn('arm', self::CONTEXT_ROUTER_REPAIR_ARMS)->get()->keyBy('arm');
            $controlTrial = $trials->get('unfiltered_context_control');
            if (! $controlTrial || (string) $controlTrial->stage !== 'nine_fold_authority'
                || (string) $controlTrial->status !== 'control_settled') {
                continue;
            }
            $controlModel = ModelVersion::query()->find((int) $controlTrial->model_version_id);
            $control = $this->latestValidMetrics($controlModel, $symbol, $timeframe);
            $controlExpectancy = $this->afterCost($control);
            $axesByArm = [
                'regime_compatibility_gate' => ['regime'],
                'session_liquidity_gate' => ['session'],
                'regime_session_gate' => ['regime', 'session'],
                'strict_context_gate' => ['regime', 'session', 'volatility'],
            ];
            foreach ($axesByArm as $arm => $expectedAxes) {
                $treatmentTrial = $trials->get($arm);
                if (! $treatmentTrial || (string) $treatmentTrial->stage !== 'nine_fold_authority'
                    || ! in_array((string) $treatmentTrial->status, ['edge_not_confirmed', 'edge_progressing'], true)) {
                    continue;
                }
                $treatmentModel = ModelVersion::query()->find((int) $treatmentTrial->model_version_id);
                $treatment = $this->latestValidMetrics($treatmentModel, $symbol, $timeframe);
                $sameParameters = $controlModel && $treatmentModel
                    && hash_equals($this->parameterHash((array) $controlModel->parameters), $this->parameterHash((array) $treatmentModel->parameters));
                $sameWindows = $this->discoveryWindowIdentity($control) !== []
                    && $this->discoveryWindowIdentity($control) === $this->discoveryWindowIdentity($treatment);
                $identityValid = $sameParameters && $sameWindows
                    && (int) data_get($control, 'forward_window_protocol.powered_windows', 0) >= 9
                    && (int) data_get($treatment, 'forward_window_protocol.powered_windows', 0) >= 9
                    && data_get($treatment, 'edge_context_enforcement.protocol') === 'edge_context_authority_firewall_v1'
                    && data_get($treatment, 'edge_context_enforcement.enforced') === true
                    && data_get($treatment, 'edge_context_enforcement.admission_axes') === $expectedAxes;
                if (! $identityValid) {
                    continue;
                }
                $treatmentExpectancy = $this->afterCost($treatment);
                $delta = round($treatmentExpectancy - $controlExpectancy, 6);
                $effect = [
                    'protocol' => 'context_authority_effect_settlement_v1',
                    'arm' => $arm, 'admission_axes' => $expectedAxes,
                    'status' => $treatmentExpectancy > 0 && $delta > 0
                        ? 'beneficial_edge_candidate'
                        : ($delta > 0 ? 'beneficial_stepping_stone' : ($delta < 0 ? 'harmful_filter' : 'no_effect')),
                    'control_trial_id' => (int) $controlTrial->id,
                    'treatment_trial_id' => (int) $treatmentTrial->id,
                    'control_model_version_id' => (int) $controlTrial->model_version_id,
                    'treatment_model_version_id' => (int) $treatmentTrial->model_version_id,
                    'control_expectancy_r' => $controlExpectancy,
                    'treatment_expectancy_r' => $treatmentExpectancy,
                    'expectancy_delta_r' => $delta,
                    'control_trades' => (int) data_get($control, 'total_trades', 0),
                    'treatment_trades' => (int) data_get($treatment, 'total_trades', 0),
                    'trades_removed' => max(0, (int) data_get($control, 'total_trades', 0) - (int) data_get($treatment, 'total_trades', 0)),
                    'same_parameter_hash' => true, 'same_nine_frozen_windows' => true,
                    'absolute_edge_confirmed' => $treatmentExpectancy > 0 && (string) $treatmentTrial->status === 'edge_progressing',
                    'selection_bias_adjustment_required' => true,
                    'parent_authority' => false, 'promotion_evidence' => false,
                ];
                $effect['effect_hash'] = hash('sha256', json_encode($effect, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
                if (data_get(json_decode((string) $treatmentTrial->evidence, true), 'causal_context_effect.effect_hash') === $effect['effect_hash']) {
                    continue;
                }
                $effects->push(['passport' => $passport, 'control' => $controlTrial, 'treatment' => $treatmentTrial, 'effect' => $effect]);
            }
        }
        if ($effects->isEmpty()) {
            return ['protocol' => 'context_authority_effect_settlement_v1',
                'status' => 'none', 'effects' => 0, 'promotion_evidence' => false];
        }
        if (! $apply) {
            return ['protocol' => 'context_authority_effect_settlement_v1',
                'status' => 'would_reconcile', 'effects' => $effects->count(), 'promotion_evidence' => false];
        }
        DB::transaction(function () use ($effects): void {
            foreach ($effects as $row) {
                $arm = (string) $row['effect']['arm'];
                $treatmentEvidence = (array) json_decode((string) DB::table('edge_genesis_trials')
                    ->where('id', $row['treatment']->id)->value('evidence'), true);
                $treatmentEvidence['causal_context_effect'] = $row['effect'];
                DB::table('edge_genesis_trials')->where('id', $row['treatment']->id)->update([
                    'evidence' => json_encode($treatmentEvidence, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                    'updated_at' => now(),
                ]);
                $controlEvidence = (array) json_decode((string) DB::table('edge_genesis_trials')
                    ->where('id', $row['control']->id)->value('evidence'), true);
                data_set($controlEvidence, 'causal_context_effects.'.$arm, $row['effect']);
                DB::table('edge_genesis_trials')->where('id', $row['control']->id)->update([
                    'evidence' => json_encode($controlEvidence, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                    'updated_at' => now(),
                ]);
                $passportEvidence = (array) json_decode((string) DB::table('edge_genesis_passports')
                    ->where('id', $row['passport']->id)->value('evidence'), true);
                data_set($passportEvidence, 'causal_context_effects.'.$arm, $row['effect']);
                $selected = (array) data_get($passportEvidence, 'causal_context_effect', []);
                if ($selected === [] || (float) ($row['effect']['expectancy_delta_r'] ?? 0) > (float) ($selected['expectancy_delta_r'] ?? -INF)) {
                    $passportEvidence['causal_context_effect'] = $row['effect'];
                }
                DB::table('edge_genesis_passports')->where('id', $row['passport']->id)->update([
                    'evidence' => json_encode($passportEvidence, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                    'updated_at' => now(),
                ]);
            }
        });

        return ['protocol' => 'context_authority_effect_settlement_v1',
            'status' => 'reconciled', 'effects' => $effects->count(), 'promotion_evidence' => false];
    }

    /**
     * Restore only the missing generation-level coverage passports for a
     * repair cohort that was operationally quarantined before any replay.
     * Economic evidence, parameters and trial verdicts are never edited.
     */
    public function reconcileRepairGenerationCoverage(string $symbol, string $timeframe, bool $apply = false): array
    {
        $symbol = strtoupper($symbol);
        $timeframe = strtoupper($timeframe);
        $generation = LabGeneration::query()->with('agents.modelVersion')->where('trigger_type', 'edge_genesis')
            ->latest('id')->get()->first(fn (LabGeneration $candidate): bool => in_array(data_get($candidate->trigger_context, 'architecture_revision'), [self::CONFIRMATION_REPAIR_REVISION, self::TRIGGER_REPAIR_REVISION, self::LATENT_HARVEST_REVISION, self::CONTEXT_ROUTER_REPAIR_REVISION, self::REGIME_ENTRY_SYNTHESIS_REVISION, self::FAILURE_CELL_FACTORIAL_REVISION, self::SPECIALIST_DENSIFICATION_REVISION, self::TEMPORAL_BREAKOUT_BINDING_REVISION, self::M15_SETUP_QUALITY_REVISION], true)
                && strtoupper((string) $candidate->laboratory?->symbol) === $symbol
                && strtoupper((string) $candidate->laboratory?->timeframe) === $timeframe);
        if (! $generation) {
            return ['protocol' => self::PROTOCOL, 'status' => 'none', 'promotion_evidence' => false];
        }
        $dataHash = (string) data_get($generation->trigger_context, 'data_hash', $generation->data_fingerprint);
        $current = (array) data_get($generation->trigger_context, 'canonical_dataset_snapshots', []);
        if ($this->canonicalDatasetSnapshotsValid($current, $dataHash)) {
            return ['protocol' => self::PROTOCOL, 'status' => 'already_valid', 'generation_id' => $generation->id, 'promotion_evidence' => false];
        }

        $agents = $generation->agents->filter(fn (LabAgent $candidate): bool => data_get($candidate->modelVersion?->metadata, 'edge_genesis.protocol') === self::PROTOCOL
        )->values();
        $revision = (string) data_get($generation->trigger_context, 'architecture_revision', '');
        $expectedPackets = count((array) data_get($generation->trigger_context, 'causal_repair_contract.packet_keys', []));
        if ($expectedPackets <= 0) {
            $expectedPackets = count($this->packets());
        }
        $exactOperationalFailure = $agents->count() === $expectedPackets * count($this->armsForRevision($revision))
            && $agents->every(function (LabAgent $agent): bool {
                $errors = array_values((array) data_get($agent->modelVersion?->metadata, 'preflight_quarantine.errors', []));
                sort($errors);

                return $agent->lifecycle_status === 'technical_quarantine'
                    && $errors === ['FULL_REPLAY_DATASET_COVERAGE_INSUFFICIENT'];
            });
        $modelIds = $agents->pluck('model_version_id')->filter()->all();
        $openRuns = DB::table('lab_evaluation_runs')->whereIn('lab_agent_id', $agents->pluck('id'))->whereNull('finished_at')->exists();
        $performanceExists = DB::table('model_market_performance')->whereIn('model_version_id', $modelIds)->exists();
        $queue = app(LabQueueJobInspector::class)->generationQueueBacklog($agents->pluck('id')->map(fn ($id): int => (int) $id)->all());
        if (! $exactOperationalFailure || $openRuns || $performanceExists || ($queue['available'] ?? true) === false || (int) ($queue['total'] ?? 0) > 0) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason' => 'ONLY_UNEXECUTED_COVERAGE_QUARANTINE_IS_REPAIRABLE',
                'generation_id' => $generation->id, 'promotion_evidence' => false];
        }

        $targetBundleHash = (string) data_get($generation->trigger_context, 'mtf_bundle_hash', '');
        $source = LabGeneration::query()->where('id', '<', $generation->id)->where('trigger_type', 'edge_genesis')
            ->latest('id')->get()->first(function (LabGeneration $candidate) use ($dataHash, $targetBundleHash): bool {
                $snapshots = (array) data_get($candidate->trigger_context, 'canonical_dataset_snapshots', []);

                return hash_equals($dataHash, (string) data_get($candidate->trigger_context, 'data_hash', $candidate->data_fingerprint))
                    && hash_equals($targetBundleHash, (string) data_get($candidate->trigger_context, 'mtf_bundle_hash', ''))
                    && $this->canonicalDatasetSnapshotsValid($snapshots, $dataHash);
            });
        if (! $source) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked',
                'reason' => 'BYTE_VALID_SOURCE_COVERAGE_SNAPSHOTS_NOT_FOUND', 'generation_id' => $generation->id, 'promotion_evidence' => false];
        }

        if (! $apply) {
            return ['protocol' => self::PROTOCOL, 'status' => 'would_repair', 'generation_id' => $generation->id,
                'source_generation_id' => $source->id, 'agents' => $agents->count(), 'promotion_evidence' => false];
        }
        $context = (array) $generation->trigger_context;
        $context['canonical_dataset_snapshots'] = $this->reusedCanonicalDatasetSnapshots(
            (array) data_get($source->trigger_context, 'canonical_dataset_snapshots', []),
            (int) $source->id,
            (int) $generation->id,
        );
        $context['edge_coverage_snapshot_recovery'] = [
            'protocol' => 'edge_generation_coverage_snapshot_reuse_v1', 'source_generation_id' => $source->id,
            'target_generation_id' => $generation->id, 'source_data_hash' => $dataHash,
            'parameters_unchanged' => true, 'economic_evidence_created' => false,
            'recorded_at' => now()->utc()->toIso8601String(), 'promotion_evidence' => false,
        ];
        $generation->update(['trigger_context' => $context]);

        return ['protocol' => self::PROTOCOL, 'status' => 'repaired', 'generation_id' => $generation->id,
            'source_generation_id' => $source->id, 'agents' => $agents->count(),
            'parameters_unchanged' => true, 'replays_repeated' => 0, 'promotion_evidence' => false];
    }

    private function edgeAdmission(array $result, bool $contextEnforcementRequired = false): array
    {
        $pfLower = (float) data_get($result, 'statistical_evidence.edge_quality.bootstrap_pf.pf_5_percentile_lower_bound', data_get($result, 'pf_lower_confidence_bound', 0));
        $expectancy = (float) data_get($result, 'after_cost_expectancy_r', data_get($result, 'pf_attribution.after_cost_expectancy_r', data_get($result, 'after_cost_expectancy', 0)));
        $folds = (int) data_get($result, 'forward_window_protocol.powered_windows', data_get($result, 'walk_forward.forward_window_protocol.powered_windows', 0));
        $positive = (int) data_get($result, 'forward_window_protocol.positive_windows', data_get($result, 'walk_forward.forward_window_protocol.positive_windows', 0));
        $firewall = (array) data_get($result, 'edge_context_enforcement', []);
        $observedSignals = (int) data_get($firewall, 'observed_signals', 0);
        $matchedSignals = (int) data_get($firewall, 'matched_signals', 0);
        $rejectedSignals = (int) data_get($firewall, 'rejected_signals', 0);
        $foldContextAuthority = ! $contextEnforcementRequired || $folds < 9 || (
            data_get($firewall, 'fold_telemetry_complete') === true
            && data_get($firewall, 'fold_contract_identity_consistent') === true
            && data_get($firewall, 'trade_admission_consistent') === true
            && $observedSignals === $matchedSignals + $rejectedSignals
            && $matchedSignals >= (int) data_get($result, 'total_trades', 0)
        );

        return ['financial' => $expectancy > 0 && $pfLower > 1 && (float) data_get($result, 'net_profit_percent', 0) > 0,
            'temporal' => $folds >= 9 && $positive >= 3 && ! (bool) data_get($result, 'temporal_leakage', data_get($result, 'is_overfit', false)),
            'behavior' => $this->observability($result) && (bool) data_get($result, 'behavior_delta_observed', false),
            'context' => (bool) data_get($result, 'context_declared_before_replay', false)
                && (int) data_get($result, 'context_occurrences', 0) > 0
                && (! $contextEnforcementRequired
                    || (data_get($result, 'edge_context_enforcement.protocol') === 'edge_context_authority_firewall_v1'
                        && data_get($result, 'edge_context_enforcement.enforced') === true
                        && data_get($result, 'edge_context_enforcement.outside_scope_action') === 'WAIT'
                        && $foldContextAuthority)),
            'safety' => (bool) data_get($result, 'risk_governor_compliant', true) && ! (bool) data_get($result, 'forbidden_risk_bypass', false),
            'fold_context_authority' => $foldContextAuthority,
            'pf_lower_confidence_bound' => $pfLower, 'after_cost_expectancy_r' => $expectancy, 'powered_folds' => $folds, 'positive_folds' => $positive, 'promotion_evidence' => false];
    }

    private function edgeAdmissionPassed(array $result, bool $contextEnforcementRequired = false): bool
    {
        return collect($this->edgeAdmission($result, $contextEnforcementRequired))->only(['financial', 'temporal', 'behavior', 'context', 'safety'])->every(fn ($v): bool => $v === true);
    }

    /** @return array<string,mixed> */
    private function discoveryAdmission(
        array $result,
        bool $contextEnforcementRequired = false,
        array $contextAxes = [],
        bool $m15SetupQualityExperiment = false,
    ): array {
        $expectancy = (float) data_get($result, 'after_cost_expectancy_r', data_get($result, 'after_cost_expectancy', 0));
        $trades = (int) data_get($result, 'total_trades', 0);
        $folds = (int) data_get($result, 'forward_window_protocol.powered_windows', data_get($result, 'walk_forward.forward_window_protocol.powered_windows', 0));
        $positive = (int) data_get($result, 'forward_window_protocol.positive_windows', data_get($result, 'walk_forward.forward_window_protocol.positive_windows', 0));
        // Specialist filters intentionally reduce opportunity count. Requiring
        // both cheap folds to remain powered systematically favored the broad
        // control and killed direction specialists before a paired comparison.
        // One powered positive fold is enough only for a pre-registered,
        // enforced direction boundary with at least four real trades. The
        // pre-registered M15 confirmation-quality curriculum may use three:
        // this is only admission to the paired nine-fold test, never edge or
        // promotion authority. The exact-control comparison remains intact.
        $sparseMinimumTrades = $m15SetupQualityExperiment ? 3 : 4;
        $sparseContextPower = in_array('direction', $contextAxes, true)
            && $trades >= $sparseMinimumTrades && $folds >= 1 && $positive >= 1;
        $contract = [
            'financial_signal' => $expectancy > 0 && (float) data_get($result, 'net_profit_percent', 0) > 0 && $trades >= 2,
            'temporal_signal' => ($folds >= 2 && $positive >= 1) || $sparseContextPower,
            'specialist_power_adjustment' => $sparseContextPower,
            'specialist_minimum_trades' => $sparseMinimumTrades,
            'm15_setup_quality_experiment' => $m15SetupQualityExperiment,
            'context_axes' => array_values($contextAxes),
            'decision_path_activated' => $this->decisionPathActivated($result),
            'context' => (bool) data_get($result, 'context_declared_before_replay', false)
                && (int) data_get($result, 'context_occurrences', 0) > 0
                && (! $contextEnforcementRequired
                    || (data_get($result, 'edge_context_enforcement.protocol') === 'edge_context_authority_firewall_v1'
                        && data_get($result, 'edge_context_enforcement.enforced') === true
                        && data_get($result, 'edge_context_enforcement.outside_scope_action') === 'WAIT')),
            'safety' => (bool) data_get($result, 'risk_governor_compliant', true)
                && ! (bool) data_get($result, 'forbidden_risk_bypass', false),
            'after_cost_expectancy_r' => $expectancy, 'total_trades' => $trades,
            'powered_folds' => $folds, 'positive_folds' => $positive, 'promotion_evidence' => false,
        ];
        $contract['passed'] = collect($contract)->only(['financial_signal', 'temporal_signal', 'decision_path_activated', 'context', 'safety'])
            ->every(fn ($value): bool => $value === true);

        return $contract;
    }

    private function decisionPathActivated(array $result): bool
    {
        $ledger = (array) data_get($result, 'edge_observability', data_get($result, 'confirmation_entry_ledger', []));
        $trades = (int) data_get($result, 'total_trades', 0);
        $count = static function ($value, array $keys, int $fallback = 0): int {
            if (is_bool($value)) {
                return $value ? $fallback : 0;
            }
            if (! is_array($value)) {
                return 0;
            }
            foreach ($keys as $key) {
                if (array_key_exists($key, $value)) {
                    return (int) $value[$key];
                }
            }

            return 0;
        };
        $setup = $count($ledger['setup_location_valid'] ?? null, ['setup_count', 'location_count', 'count'], $trades);
        $confirmation = $count($ledger['confirmation'] ?? null, ['count'], $trades);
        $entry = $count($ledger['entry'] ?? null, ['count'], $trades);
        $closed = $count($ledger['exit_outcome'] ?? null, ['closed_trade_count', 'count'], $trades);

        return $trades > 0 && $setup > 0 && $confirmation > 0 && $entry > 0 && $closed > 0;
    }

    private function edgeViable(array $result): bool
    {
        return (float) data_get($result, 'after_cost_expectancy_r', data_get($result, 'after_cost_expectancy', 0)) > 0 && (float) data_get($result, 'statistical_evidence.edge_quality.bootstrap_pf.pf_5_percentile_lower_bound', data_get($result, 'pf_lower_confidence_bound', 0)) > 1;
    }

    private function blocked(string $reason, string $phase, bool $risk): array
    {
        return ['protocol' => self::PROTOCOL, 'allowed' => false, 'reason' => $reason, 'phase' => $phase, 'risk_gene' => $risk, 'architecture_genesis_required' => $phase === 'EDGE_DISCOVERY', 'promotion_evidence' => false];
    }

    private function available(): bool
    {
        return Schema::hasTable('edge_genesis_passports') && Schema::hasTable('edge_genesis_trials');
    }

    /** @return array<string,mixed> */
    private function architectureRepairAssessment(string $symbol, string $timeframe): array
    {
        $symbol = strtoupper($symbol);
        $timeframe = strtoupper($timeframe);
        $base = ['protocol' => self::PROTOCOL, 'repair_revision' => self::CONFIRMATION_REPAIR_REVISION,
            'admitted' => false, 'promotion_evidence' => false];
        if (! $this->available()) {
            return [...$base, 'status' => 'blocked', 'reason' => 'EDGE_GENESIS_TABLES_UNAVAILABLE'];
        }

        $scope = DB::table('edge_genesis_passports')->where('symbol', $symbol)->where('timeframe', $timeframe);
        $generationId = (int) ((clone $scope)->max('lab_generation_id') ?? 0);
        if ($generationId <= 0) {
            return [...$base, 'status' => 'not_applicable', 'reason' => 'NO_COMPLETED_EDGE_COHORT'];
        }
        $edgeEstablished = (clone $scope)->whereIn('phase', [
            'EDGE_ATTRIBUTION', 'RISK_SHAPING', 'MANAGEMENT_OPTIMIZATION', 'PAPER_VALIDATION',
        ])->exists();
        if ($edgeEstablished) {
            return [...$base, 'status' => 'blocked', 'reason' => 'EDGE_ALREADY_ESTABLISHED'];
        }

        $passports = (clone $scope)->where('lab_generation_id', $generationId)->get();
        $trials = DB::table('edge_genesis_trials')->whereIn('edge_genesis_passport_id', $passports->pluck('id'))->get();
        $sourceGeneration = LabGeneration::query()->find($generationId);
        // Some immutable legacy compiled cohorts predate the explicit
        // `architecture_revision` trigger context. Their exact five-arm
        // contract is sufficient to classify them as a compiled packet; do
        // not misread that cohort as an incomplete four-packet initial wave.
        $declaredSourceRevision = (string) data_get($sourceGeneration?->trigger_context, 'architecture_revision', '');
        $sourceRevision = $declaredSourceRevision !== '' ? $declaredSourceRevision : $this->inferLegacySourceRevision($trials);
        $repairRevision = match ($sourceRevision) {
            self::INITIAL_REVISION => self::CONFIRMATION_REPAIR_REVISION,
            self::CONFIRMATION_REPAIR_REVISION => self::TRIGGER_REPAIR_REVISION,
            self::TRIGGER_REPAIR_REVISION => self::LATENT_HARVEST_REVISION,
            self::LATENT_HARVEST_REVISION => self::CONTEXT_ROUTER_REPAIR_REVISION,
            self::CONTEXT_ROUTER_REPAIR_REVISION => self::REGIME_ENTRY_SYNTHESIS_REVISION,
            self::REGIME_ENTRY_SYNTHESIS_REVISION => self::FAILURE_CELL_FACTORIAL_REVISION,
            self::FAILURE_CELL_FACTORIAL_REVISION => self::SPECIALIST_DENSIFICATION_REVISION,
            self::SPECIALIST_DENSIFICATION_REVISION => self::TEMPORAL_BREAKOUT_BINDING_REVISION,
            self::TEMPORAL_BREAKOUT_BINDING_REVISION => self::M15_SETUP_QUALITY_REVISION,
            default => null,
        };
        $base['repair_revision'] = $repairRevision;
        // A failed nine-fold authority replay is stronger terminal evidence
        // than a two-fold miss.  It must not be mistaken for an established
        // edge, and it must remain available to the next bounded diagnostic
        // (for example conservative pre-exit excursion harvesting).  Risk
        // authority stays locked because the status is still a failure.
        $terminal = ['edge_not_found', 'edge_not_confirmed', 'control_settled'];
        $expectedPackets = in_array($sourceRevision, [self::LATENT_HARVEST_REVISION, self::CONTEXT_ROUTER_REPAIR_REVISION, self::REGIME_ENTRY_SYNTHESIS_REVISION, self::FAILURE_CELL_FACTORIAL_REVISION, self::SPECIALIST_DENSIFICATION_REVISION, self::TEMPORAL_BREAKOUT_BINDING_REVISION, self::M15_SETUP_QUALITY_REVISION, self::EVIDENCE_COMPILED_REVISION], true)
            ? 1 : count($this->packets());
        $expectedTrials = $expectedPackets * count($this->armsForRevision($sourceRevision));
        if ($passports->count() !== $expectedPackets || $trials->count() !== $expectedTrials
            || $trials->contains(fn ($trial): bool => ! in_array((string) $trial->status, $terminal, true))) {
            return [...$base, 'status' => 'blocked', 'reason' => 'SOURCE_EDGE_COHORT_NOT_COMPLETELY_SETTLED',
                'source_generation_id' => $generationId, 'passports' => $passports->count(), 'trials' => $trials->count()];
        }
        $models = ModelVersion::query()->with('marketPerformances')->whereIn('id', $trials->pluck('model_version_id')->filter())->get()->keyBy('id');
        $identity = $this->repairIdentityContract($passports, $models, $sourceGeneration, $generationId);
        if (! ($identity['valid'] ?? false)) {
            return [...$base, 'status' => 'blocked', 'reason' => 'SOURCE_COHORT_FROZEN_IDENTITY_NOT_UNIQUE',
                'source_generation_id' => $generationId];
        }

        if ($sourceRevision === self::CONFIRMATION_REPAIR_REVISION) {
            return $this->triggerTopologyRepairAssessment(
                $base, $trials, $models, $identity, $generationId, $symbol, $timeframe,
            );
        }
        if ($sourceRevision === self::TRIGGER_REPAIR_REVISION) {
            return $this->latentHarvestAssessment(
                $base, $trials, $models, $identity, $generationId, $symbol, $timeframe,
            );
        }
        if ($sourceRevision === self::LATENT_HARVEST_REVISION) {
            return $this->contextRouterRepairAssessment(
                $base, $trials, $models, $identity, $generationId, $symbol, $timeframe,
            );
        }
        if ($sourceRevision === self::CONTEXT_ROUTER_REPAIR_REVISION) {
            return $this->regimeEntrySynthesisAssessment(
                $base, $trials, $models, $identity, $generationId, $symbol, $timeframe,
            );
        }
        if ($sourceRevision === self::REGIME_ENTRY_SYNTHESIS_REVISION) {
            return $this->failureCellFactorialAssessment(
                $base, $trials, $models, $identity, $generationId, $symbol, $timeframe,
            );
        }
        if ($sourceRevision === self::FAILURE_CELL_FACTORIAL_REVISION) {
            return $this->specialistDensificationAssessment(
                $base, $trials, $models, $identity, $generationId, $symbol, $timeframe,
            );
        }
        if ($sourceRevision === self::SPECIALIST_DENSIFICATION_REVISION) {
            return $this->temporalBreakoutBindingAssessment(
                $base, $trials, $models, $identity, $generationId, $symbol, $timeframe,
            );
        }
        if ($sourceRevision === self::TEMPORAL_BREAKOUT_BINDING_REVISION) {
            return $this->m15SetupQualityAssessment(
                $base, $trials, $models, $identity, $generationId, $symbol, $timeframe,
            );
        }
        if ($sourceRevision !== self::INITIAL_REVISION || $repairRevision === null) {
            return [...$base, 'status' => 'blocked', 'reason' => 'REGISTERED_TRIGGER_REPAIR_PACKET_EXHAUSTED',
                'source_generation_id' => $generationId, 'source_revision' => $sourceRevision];
        }
        if ($trials->contains(fn ($trial): bool => (string) $trial->arm === 'confirmation_floor_one')) {
            return [...$base, 'status' => 'blocked', 'reason' => 'CONFIRMATION_FLOOR_ALREADY_TESTED',
                'source_generation_id' => $generationId];
        }

        $diagnostic = [];
        foreach ($trials->where('arm', '!=', 'frozen_control') as $trial) {
            $metrics = $this->latestValidMetrics($models->get((int) $trial->model_version_id), $symbol, $timeframe);
            $setups = (int) data_get($metrics, 'edge_observability.setup_location_valid.setup_count', 0);
            $confirmations = (int) data_get($metrics, 'edge_observability.confirmation.count', 0);
            $entries = (int) data_get($metrics, 'edge_observability.entry.count', 0);
            if ($setups > 0 && $confirmations === 0 && $entries === 0) {
                $diagnostic[] = ['trial_id' => (int) $trial->id, 'packet_key' => (string) $trial->packet_key,
                    'arm' => (string) $trial->arm, 'setups' => $setups];
            }
        }
        $diagnosticPackets = collect($diagnostic)->pluck('packet_key')->unique()->values();
        if (count($diagnostic) < 2 || $diagnosticPackets->count() < 2) {
            return [...$base, 'status' => 'blocked', 'reason' => 'CONFIRMATION_BREADTH_BOTTLENECK_NOT_REPLICATED',
                'source_generation_id' => $generationId, 'diagnostic_trials' => count($diagnostic),
                'diagnostic_packets' => $diagnosticPackets->all()];
        }

        return [...$base, 'status' => 'admitted', 'admitted' => true, 'reason' => 'REPLICATED_CONFIRMATION_BREADTH_STARVATION',
            'source_generation_id' => $generationId, 'source_bundle_hash' => $identity['bundle_hash'],
            'diagnostic_trials' => count($diagnostic), 'diagnostic_packets' => $diagnosticPackets->all(),
            'changed_axis' => 'minimum_independent_confirmations:3->1',
            'risk_governor_frozen' => true, 'two_fold_discovery_required' => true, 'nine_fold_authority_unchanged' => true,
            'materialization_contract' => $identity['materialization_contract']];
    }

    /** Infer only the unambiguous legacy compiled five-arm contract. */
    private function inferLegacySourceRevision($trials): string
    {
        $observed = collect($trials)->pluck('arm')->filter()->map(fn ($arm): string => (string) $arm)->unique()->sort()->values()->all();
        $compiled = self::COMPILED_HYPOTHESIS_ARMS;
        sort($compiled);

        return $observed === $compiled ? self::EVIDENCE_COMPILED_REVISION : self::INITIAL_REVISION;
    }

    /** The second repair inherits proven confirmation and isolates trigger topology. */
    private function triggerTopologyRepairAssessment(array $base, $trials, $models, array $identity, int $generationId, string $symbol, string $timeframe): array
    {
        $diagnostic = [];
        foreach ($trials->where('arm', 'confirmation_floor_one') as $trial) {
            $metrics = $this->latestValidMetrics($models->get((int) $trial->model_version_id), $symbol, $timeframe);
            $setups = (int) data_get($metrics, 'edge_observability.setup_location_valid.setup_count', 0);
            $confirmations = (int) data_get($metrics, 'edge_observability.confirmation.count', 0);
            $triggers = (int) data_get($metrics, 'entry_contract_funnel.stage_counts.trigger', 0);
            $entries = (int) data_get($metrics, 'edge_observability.entry.count', 0);
            if ($setups > 0 && $confirmations > 0 && $triggers === 0 && $entries === 0) {
                $diagnostic[] = ['trial_id' => (int) $trial->id, 'packet_key' => (string) $trial->packet_key,
                    'arm' => (string) $trial->arm, 'setups' => $setups, 'confirmations' => $confirmations,
                    'triggers' => $triggers, 'entries' => $entries];
            }
        }
        $packets = collect($diagnostic)->pluck('packet_key')->unique()->values();
        if (count($diagnostic) < 2 || $packets->count() < 2) {
            return [...$base, 'status' => 'blocked', 'reason' => 'ENTRY_TRIGGER_BOTTLENECK_NOT_REPLICATED',
                'source_generation_id' => $generationId, 'diagnostic_trials' => count($diagnostic),
                'diagnostic_packets' => $packets->all()];
        }

        return [...$base, 'status' => 'admitted', 'admitted' => true,
            'reason' => 'REPLICATED_ENTRY_TRIGGER_STARVATION_AFTER_CONFIRMATION',
            'source_generation_id' => $generationId, 'source_bundle_hash' => $identity['bundle_hash'],
            'diagnostic_trials' => count($diagnostic), 'diagnostic_packets' => $packets->all(),
            'inherited_fact' => 'minimum_independent_confirmations:1',
            'paired_single_axis_candidates' => [
                'swing_lookback:40->10',
                'entry_mode:balanced->aggressive',
                'm5_retest_expiry_minutes:20->60',
            ],
            'risk_governor_frozen' => true, 'two_fold_discovery_required' => true,
            'nine_fold_authority_unchanged' => true,
            'materialization_contract' => $identity['materialization_contract']];
    }

    /**
     * Admit management research only when conservative pre-exit-bar excursion
     * proves that entries repeatedly reached useful R before realizing a loss.
     * Exit-candle high/low is deliberately ignored because its intrabar order
     * relative to a stop is unknowable from OHLC.
     */
    private function latentHarvestAssessment(array $base, $trials, $models, array $identity, int $generationId, string $symbol, string $timeframe): array
    {
        $diagnostic = [];
        foreach ($trials->where('arm', '!=', 'frozen_control') as $trial) {
            $model = $models->get((int) $trial->model_version_id);
            $metrics = $this->latestValidMetrics($model, $symbol, $timeframe);
            $management = (array) data_get($metrics, 'management_evidence', []);
            $trades = (int) data_get($metrics, 'total_trades', 0);
            $observed = (int) data_get($management, 'observed_trades', 0);
            $preExitMfe = data_get($management, 'average_mfe_r_before_exit_bar');
            $realized = (float) data_get($management, 'average_realized_r', 0);
            $precision = (string) data_get($management, 'path_precision', '');
            if ($trades >= 2 && $observed >= 2 && is_numeric($preExitMfe)
                && (float) $preExitMfe >= 1.0 && $realized <= 0
                && $precision === 'dual_bound_with_conservative_pre_exit_bar_excursion') {
                $diagnostic[] = [
                    'trial_id' => (int) $trial->id,
                    'packet_key' => (string) $trial->packet_key,
                    'arm' => (string) $trial->arm,
                    'model_version_id' => (int) $trial->model_version_id,
                    'observed_trades' => $observed,
                    'average_mfe_r_before_exit_bar' => (float) $preExitMfe,
                    'average_realized_r' => $realized,
                    'parameters' => (array) $model?->parameters,
                ];
            }
        }

        $eligible = collect($diagnostic)->groupBy('packet_key')->map(function ($rows, string $packetKey): array {
            $best = $rows->sortByDesc(fn (array $row): float => ((int) $row['observed_trades'] * 10) + (float) $row['average_mfe_r_before_exit_bar'])->first();

            return ['packet_key' => $packetKey, 'trials' => $rows->count(),
                'observed_trades' => $rows->sum('observed_trades'), 'best' => $best];
        })->filter(fn (array $row): bool => $row['trials'] >= 2 && $row['observed_trades'] >= 8)
            ->sortByDesc('observed_trades')->values();
        $selected = $eligible->first();
        if (! is_array($selected)) {
            return [...$base, 'status' => 'blocked', 'reason' => 'CONSERVATIVE_LATENT_EXCURSION_EDGE_NOT_REPLICATED',
                'source_generation_id' => $generationId, 'diagnostic_trials' => count($diagnostic),
                'diagnostic_packets' => collect($diagnostic)->pluck('packet_key')->unique()->values()->all()];
        }

        $packetKey = (string) $selected['packet_key'];
        $best = (array) $selected['best'];

        return [...$base, 'status' => 'admitted', 'admitted' => true,
            'reason' => 'REPLICATED_CONSERVATIVE_PRE_EXIT_EXCURSION_NOT_HARVESTED',
            'source_generation_id' => $generationId, 'source_bundle_hash' => $identity['bundle_hash'],
            'diagnostic_trials' => count($diagnostic), 'selected_packet' => $packetKey,
            'selected_packet_observed_trades' => (int) $selected['observed_trades'],
            'source_arm' => $best['arm'] ?? null,
            'causal_boundary' => 'management_only_after_entry_excursion; risk_and_entry_frozen',
            'pre_exit_bar_only' => true, 'two_fold_discovery_required' => true,
            'nine_fold_authority_unchanged' => true, 'promotion_evidence' => false,
            'materialization_contract' => [...$identity['materialization_contract'],
                'packet_keys' => [$packetKey],
                'source_parameters' => [$packetKey => (array) ($best['parameters'] ?? [])],
                'source_model_version_ids' => [$packetKey => (int) ($best['model_version_id'] ?? 0)],
                'latent_excursion_evidence' => collect($diagnostic)->map(fn (array $row): array => collect($row)->except('parameters')->all())->all(),
            ]];
    }

    /**
     * A declared market-state passport is not an execution policy unless the
     * replay proves that every out-of-scope signal became WAIT. The first
     * cohorts only reported that a context existed, so a trend/London packet
     * could still trade range or another session. Open one paired firewall
     * experiment around the exact failed source composition; never reinterpret
     * its historical result as context-filtered evidence.
     */
    private function contextRouterRepairAssessment(array $base, $trials, $models, array $identity, int $generationId, string $symbol, string $timeframe): array
    {
        $controlTrial = $trials->first(fn ($trial): bool => (string) $trial->arm === 'latent_edge_control'
            && (string) $trial->stage === 'nine_fold_authority'
            && (string) $trial->status === 'control_settled');
        $model = $controlTrial ? $models->get((int) $controlTrial->model_version_id) : null;
        $metrics = $this->latestValidMetrics($model, $symbol, $timeframe);
        $declared = (array) data_get($model?->metadata, 'edge_genesis.context', []);
        $declaredRegimes = array_values(array_filter((array) ($declared['allowed_regimes'] ?? ($declared['regime'] ?? []))));
        $observedRegimes = collect((array) data_get($metrics, 'walk_forward.windows', []))
            ->flatMap(fn (array $window): array => array_keys((array) data_get($window, 'results.forward.regime_performance', [])))
            ->merge(array_keys((array) data_get($metrics, 'regime_performance', [])))
            ->filter()->unique()->values();
        $outside = $declaredRegimes === [] ? collect() : $observedRegimes->diff($declaredRegimes)->values();
        $enforcement = (array) data_get($metrics, 'edge_context_enforcement', []);

        if (! $controlTrial || ! $model || (int) data_get($metrics, 'total_trades', 0) < 2
            || $declared === [] || $outside->isEmpty()
            || data_get($enforcement, 'protocol') === 'edge_context_authority_firewall_v1') {
            return [...$base, 'status' => 'blocked', 'reason' => 'CONTEXT_EXECUTION_LEAKAGE_NOT_PROVEN',
                'source_generation_id' => $generationId, 'observed_regimes' => $observedRegimes->all(),
                'declared_regimes' => $declaredRegimes, 'outside_regimes' => $outside->all()];
        }

        $packetKey = (string) $controlTrial->packet_key;

        return [...$base, 'status' => 'admitted', 'admitted' => true,
            'repair_revision' => self::CONTEXT_ROUTER_REPAIR_REVISION,
            'reason' => 'DECLARED_CONTEXT_ROUTER_NOT_EXECUTION_ENFORCED',
            'source_generation_id' => $generationId, 'source_bundle_hash' => $identity['bundle_hash'],
            'selected_packet' => $packetKey, 'source_arm' => 'latent_edge_control',
            'observed_regimes' => $observedRegimes->all(), 'declared_regimes' => $declaredRegimes,
            'outside_regimes' => $outside->all(),
            'causal_boundary' => 'entry_admission_context_only; strategy_parameters_risk_and_management_frozen',
            'context_ladder' => self::CONTEXT_ROUTER_REPAIR_ARMS,
            'two_fold_discovery_required' => true, 'nine_fold_authority_unchanged' => true,
            'promotion_evidence' => false,
            'materialization_contract' => [...$identity['materialization_contract'],
                'packet_keys' => [$packetKey],
                'source_parameters' => [$packetKey => (array) $model->parameters],
                'source_model_version_ids' => [$packetKey => (int) $model->id],
            ]];
    }

    /**
     * Preserve a beneficial regime filter as a stepping stone without
     * pretending that a still-negative composition has edge. The next packet
     * keeps that router frozen and isolates four professional entry-quality
     * hypotheses, one axis per arm.
     */
    private function regimeEntrySynthesisAssessment(array $base, $trials, $models, array $identity, int $generationId, string $symbol, string $timeframe): array
    {
        $controlTrial = $trials->firstWhere('arm', 'unfiltered_context_control');
        $regimeTrial = $trials->firstWhere('arm', 'regime_compatibility_gate');
        $controlModel = $controlTrial ? $models->get((int) $controlTrial->model_version_id) : null;
        $regimeModel = $regimeTrial ? $models->get((int) $regimeTrial->model_version_id) : null;
        $control = $this->latestValidMetrics($controlModel, $symbol, $timeframe);
        $regime = $this->latestValidMetrics($regimeModel, $symbol, $timeframe);
        $controlExpectancy = $this->afterCost($control);
        $regimeExpectancy = $this->afterCost($regime);
        $delta = round($regimeExpectancy - $controlExpectancy, 6);
        $sameParameters = $controlModel && $regimeModel
            && hash_equals($this->parameterHash((array) $controlModel->parameters), $this->parameterHash((array) $regimeModel->parameters));
        $sameWindows = $this->discoveryWindowIdentity($control) !== []
            && $this->discoveryWindowIdentity($control) === $this->discoveryWindowIdentity($regime);
        $firewall = (array) data_get($regime, 'edge_context_enforcement', []);
        $validFirewall = data_get($firewall, 'protocol') === 'edge_context_authority_firewall_v1'
            && data_get($firewall, 'enforced') === true
            && data_get($firewall, 'admission_axes') === ['regime']
            && data_get($firewall, 'outside_scope_action') === 'WAIT';
        $authorityComplete = $controlTrial && $regimeTrial
            && (string) $controlTrial->stage === 'nine_fold_authority'
            && (string) $controlTrial->status === 'control_settled'
            && (string) $regimeTrial->stage === 'nine_fold_authority'
            && (string) $regimeTrial->status === 'edge_not_confirmed'
            && (int) data_get($control, 'forward_window_protocol.powered_windows', 0) >= 9
            && (int) data_get($regime, 'forward_window_protocol.powered_windows', 0) >= 9;
        $beneficialSteppingStone = $delta > 0
            && $regimeExpectancy <= 0
            && (int) data_get($regime, 'total_trades', 0) >= 20
            && (int) data_get($regime, 'forward_window_protocol.positive_windows', 0)
                > (int) data_get($control, 'forward_window_protocol.positive_windows', 0);

        if (! $authorityComplete || ! $sameParameters || ! $sameWindows || ! $validFirewall || ! $beneficialSteppingStone) {
            return [...$base, 'repair_revision' => self::REGIME_ENTRY_SYNTHESIS_REVISION,
                'status' => 'blocked', 'reason' => 'REGIME_ROUTER_STEPPING_STONE_NOT_CAUSALLY_PROVEN',
                'source_generation_id' => $generationId, 'same_parameters' => (bool) $sameParameters,
                'same_windows' => $sameWindows, 'firewall_valid' => $validFirewall,
                'control_expectancy_r' => $controlExpectancy, 'regime_expectancy_r' => $regimeExpectancy,
                'expectancy_delta_r' => $delta];
        }

        $packetKey = (string) $regimeTrial->packet_key;

        return [...$base, 'repair_revision' => self::REGIME_ENTRY_SYNTHESIS_REVISION,
            'status' => 'admitted', 'admitted' => true,
            'reason' => 'REGIME_ROUTER_BENEFICIAL_BUT_ENTRY_EDGE_ABSENT',
            'source_generation_id' => $generationId, 'source_bundle_hash' => $identity['bundle_hash'],
            'selected_packet' => $packetKey, 'source_arm' => 'regime_compatibility_gate',
            'control_expectancy_r' => $controlExpectancy, 'regime_expectancy_r' => $regimeExpectancy,
            'expectancy_delta_r' => $delta,
            'trades_removed' => max(0, (int) data_get($control, 'total_trades', 0) - (int) data_get($regime, 'total_trades', 0)),
            'inherited_fact' => 'regime compatibility WAIT gate improves the exact composition but is not standalone edge',
            'paired_single_axis_candidates' => [
                'entry_mode:aggressive->balanced',
                'minimum_independent_confirmations:1->2',
                'minimum_reward_space_r:1.5->2.0',
                'max_chase_atr:1.25->0.75',
            ],
            'causal_boundary' => 'regime_router_frozen; one_entry_quality_axis_per_treatment; risk_and_management_frozen',
            'two_fold_discovery_required' => true, 'nine_fold_authority_unchanged' => true,
            'promotion_evidence' => false,
            'materialization_contract' => [...$identity['materialization_contract'],
                'packet_keys' => [$packetKey],
                'source_parameters' => [$packetKey => (array) $regimeModel->parameters],
                'source_model_version_ids' => [$packetKey => (int) $regimeModel->id],
                'context_stepping_stone' => [
                    'protocol' => 'regime_router_stepping_stone_v1',
                    'control_trial_id' => (int) $controlTrial->id,
                    'treatment_trial_id' => (int) $regimeTrial->id,
                    'expectancy_delta_r' => $delta,
                    'absolute_edge_confirmed' => false,
                    'promotion_evidence' => false,
                ],
            ]];
    }

    /**
     * Convert retrospective failure-cell attribution into a prospective,
     * executable factorial router experiment.  The source nine-fold replay
     * may suggest that BUY and high-volatility cells contain the useful edge,
     * but that subset analysis has zero authority until these boundaries are
     * frozen before entry and compared with the exact regime control.
     */
    private function failureCellFactorialAssessment(array $base, $trials, $models, array $identity, int $generationId, string $symbol, string $timeframe): array
    {
        $controlTrial = $trials->firstWhere('arm', 'regime_entry_control');
        $controlModel = $controlTrial ? $models->get((int) $controlTrial->model_version_id) : null;
        $selection = (array) json_decode((string) ($controlTrial->evidence ?? '{}'), true);
        $sourceModelId = (int) data_get($controlModel?->metadata, 'edge_genesis.causal_baseline_model_version_id', 0);
        $sourceModel = $sourceModelId > 0
            ? ModelVersion::query()->with('marketPerformances')->find($sourceModelId)
            : null;
        $sourceTrial = $sourceModel
            ? DB::table('edge_genesis_trials')->where('model_version_id', $sourceModel->id)
                ->where('stage', 'nine_fold_authority')->latest('id')->first()
            : null;
        $metrics = $this->latestValidMetrics($sourceModel, $symbol, $timeframe);
        $buy = (array) data_get($metrics, 'pf_attribution.by_direction.BUY', []);
        $sell = (array) data_get($metrics, 'pf_attribution.by_direction.SELL', []);
        $high = (array) data_get($metrics, 'pf_attribution.by_volatility.high_volatility', []);
        $normal = (array) data_get($metrics, 'pf_attribution.by_volatility.normal_volatility', []);
        $firewall = (array) data_get($metrics, 'edge_context_enforcement', []);

        $controlClosed = $controlTrial && $controlModel
            && (string) $controlTrial->stage === 'two_fold_discovery'
            && (string) $controlTrial->status === 'control_settled'
            && data_get($selection, 'authority_selection.reason') === 'NO_TREATMENT_OUTPERFORMED_EXACT_CONTROL';
        $sourceAuthorityComplete = $sourceTrial
            && (string) $sourceTrial->status === 'edge_not_confirmed'
            && (int) data_get($metrics, 'forward_window_protocol.powered_windows', 0) >= 9
            && data_get($firewall, 'protocol') === 'edge_context_authority_firewall_v1'
            && data_get($firewall, 'enforced') === true
            && data_get($firewall, 'admission_axes') === ['regime']
            && data_get($firewall, 'outside_scope_action') === 'WAIT';
        $sameParameters = $controlModel && $sourceModel
            && hash_equals($this->parameterHash((array) $controlModel->parameters), $this->parameterHash((array) $sourceModel->parameters));
        $directionSplit = (int) ($buy['trades'] ?? 0) >= 10
            && (float) ($buy['net_pf'] ?? 0) > 1.0
            && (float) ($buy['net_profit_percent'] ?? 0) > 0
            && (int) ($sell['trades'] ?? 0) >= 5
            && (float) ($sell['net_pf'] ?? 0) < 1.0
            && (float) ($sell['net_profit_percent'] ?? 0) < 0;
        $volatilitySplit = (int) ($high['trades'] ?? 0) >= 10
            && (float) ($high['net_pf'] ?? 0) > 1.0
            && (float) ($high['net_profit_percent'] ?? 0) > 0
            && (int) ($normal['trades'] ?? 0) >= 5
            && (float) ($normal['net_pf'] ?? 0) < 1.0
            && (float) ($normal['net_profit_percent'] ?? 0) < 0;

        if (! $controlClosed || ! $sourceAuthorityComplete || ! $sameParameters || ! $directionSplit || ! $volatilitySplit) {
            return [...$base, 'repair_revision' => self::FAILURE_CELL_FACTORIAL_REVISION,
                'status' => 'blocked', 'reason' => 'FAILURE_CELL_FACTORIAL_HYPOTHESIS_NOT_CAUSALLY_ADMISSIBLE',
                'source_generation_id' => $generationId, 'control_closed' => (bool) $controlClosed,
                'source_authority_complete' => (bool) $sourceAuthorityComplete,
                'same_parameters' => (bool) $sameParameters, 'direction_split' => $directionSplit,
                'volatility_split' => $volatilitySplit, 'promotion_evidence' => false];
        }

        $packetKey = (string) $controlTrial->packet_key;

        return [...$base, 'repair_revision' => self::FAILURE_CELL_FACTORIAL_REVISION,
            'status' => 'admitted', 'admitted' => true,
            'reason' => 'NINE_FOLD_FAILURE_CELLS_REQUIRE_PROSPECTIVE_FACTORIAL_ROUTER_TEST',
            'source_generation_id' => $generationId, 'source_bundle_hash' => $identity['bundle_hash'],
            'selected_packet' => $packetKey, 'source_arm' => 'regime_entry_control',
            'evidence_source_model_version_id' => (int) $sourceModel->id,
            'retrospective_only' => true, 'authority_from_subset_analysis' => false,
            'direction_diagnostic' => ['BUY' => $buy, 'SELL' => $sell],
            'volatility_diagnostic' => ['high_volatility' => $high, 'normal_volatility' => $normal],
            'factorial_arms' => self::FAILURE_CELL_FACTORIAL_ARMS,
            'causal_boundary' => 'regime_router_frozen; direction_and_volatility_are_pre_entry_WAIT_axes; parameters_risk_management_and_data_frozen',
            'two_fold_discovery_required' => true, 'nine_fold_authority_unchanged' => true,
            'promotion_evidence' => false,
            'materialization_contract' => [...$identity['materialization_contract'],
                'packet_keys' => [$packetKey],
                'source_parameters' => [$packetKey => (array) $controlModel->parameters],
                'source_model_version_ids' => [$packetKey => (int) $controlModel->id],
                'failure_cell_hypothesis' => [
                    'protocol' => 'prospective_failure_cell_factorial_v1',
                    'evidence_source_model_version_id' => (int) $sourceModel->id,
                    'evidence_source_trial_id' => (int) $sourceTrial->id,
                    'retrospective_selection_authority' => false,
                    'paired_prospective_replay_required' => true,
                    'promotion_evidence' => false,
                ],
            ]];
    }

    /**
     * Preserve a strong but sparse BUY/high-volatility interaction as a
     * research stepping stone. The next curriculum changes entry topology or
     * opportunity density, never risk, so it learns to use the professional
     * toolbox instead of polishing a losing baseline.
     */
    private function specialistDensificationAssessment(array $base, $trials, $models, array $identity, int $generationId, string $symbol, string $timeframe): array
    {
        $controlTrial = $trials->firstWhere('arm', 'failure_cell_control');
        $specialistTrial = $trials->firstWhere('arm', 'buy_high_volatility_interaction');
        $controlModel = $controlTrial ? $models->get((int) $controlTrial->model_version_id) : null;
        $specialistModel = $specialistTrial ? $models->get((int) $specialistTrial->model_version_id) : null;
        $metrics = $this->latestValidMetrics($specialistModel, $symbol, $timeframe);
        $evidence = (array) json_decode((string) ($specialistTrial->evidence ?? '{}'), true);
        $differential = (array) data_get($evidence, 'nine_fold_causal_authority', []);
        $paired = (array) data_get($differential, 'paired_window_effect', []);
        $context = (array) data_get($specialistModel?->metadata, 'edge_genesis.context', []);
        $sameParameters = $controlModel && $specialistModel
            && hash_equals($this->parameterHash((array) $controlModel->parameters), $this->parameterHash((array) $specialistModel->parameters));
        $complete = $controlTrial && $specialistTrial
            && (string) $controlTrial->stage === 'nine_fold_authority'
            && (string) $controlTrial->status === 'control_settled'
            && (string) $specialistTrial->stage === 'nine_fold_authority'
            && (string) $specialistTrial->status === 'edge_not_confirmed'
            && count($this->discoveryWindowIdentity($metrics)) === 9;
        $diagnosticValue = data_get($differential, 'protocol') === 'nine_fold_differential_authority_v1'
            && data_get($differential, 'decision') === 'causal_edge_not_confirmed'
            && data_get($differential, 'absolute_edge_reconfirmed') === false
            && (float) data_get($differential, 'expectancy_delta_r', 0) > 0
            && (int) data_get($paired, 'comparable_windows', 0) === 9
            && (int) data_get($paired, 'positive_windows', 0) >= 5
            && (int) data_get($paired, 'negative_windows', 9) === 0
            && (float) data_get($metrics, 'after_cost_expectancy_r', 0) > 0
            && (float) data_get($metrics, 'profit_factor', 0) > 1
            && (int) data_get($metrics, 'total_trades', 0) >= 10;
        $contextFrozen = data_get($context, 'enforcement') === 'required'
            && data_get($context, 'admission_axes') === ['regime', 'direction', 'volatility']
            && data_get($context, 'allowed_directions') === ['BUY']
            && data_get($context, 'allowed_volatility') === ['high_volatility']
            && data_get($context, 'outside_scope') === 'WAIT';

        if (! $complete || ! $sameParameters || ! $diagnosticValue || ! $contextFrozen) {
            return [...$base, 'repair_revision' => self::SPECIALIST_DENSIFICATION_REVISION,
                'status' => 'blocked', 'reason' => 'SPARSE_SPECIALIST_DIFFERENTIAL_NOT_REPLICATED',
                'source_generation_id' => $generationId, 'complete' => (bool) $complete,
                'same_parameters' => (bool) $sameParameters, 'diagnostic_value' => (bool) $diagnosticValue,
                'context_frozen' => (bool) $contextFrozen, 'promotion_evidence' => false];
        }

        $packetKey = (string) $specialistTrial->packet_key;

        return [...$base, 'repair_revision' => self::SPECIALIST_DENSIFICATION_REVISION,
            'status' => 'admitted', 'admitted' => true,
            'reason' => 'BUY_HIGH_VOLATILITY_EDGE_DIFFERENTIAL_REQUIRES_PROFESSIONAL_COVERAGE_CURRICULUM',
            'source_generation_id' => $generationId, 'source_bundle_hash' => $identity['bundle_hash'],
            'selected_packet' => $packetKey, 'source_arm' => 'buy_high_volatility_interaction',
            'source_expectancy_r' => (float) data_get($metrics, 'after_cost_expectancy_r', 0),
            'source_profit_factor' => (float) data_get($metrics, 'profit_factor', 0),
            'source_trades' => (int) data_get($metrics, 'total_trades', 0),
            'differential' => $differential,
            'inherited_fact' => 'BUY plus high-volatility WAIT boundary improves the exact composition across most paired windows but lacks standalone temporal authority',
            'causal_boundary' => 'direction_volatility_regime_router_frozen; one_professional_entry_or_density_axis_per_treatment; risk_and_management_frozen',
            'legacy_fold_context_projection' => data_get($metrics, 'edge_context_enforcement.fold_telemetry_complete') !== true,
            'two_fold_discovery_required' => true, 'nine_fold_authority_unchanged' => true,
            'parent_authority' => false, 'promotion_evidence' => false,
            'materialization_contract' => [...$identity['materialization_contract'],
                'packet_keys' => [$packetKey],
                'source_parameters' => [$packetKey => (array) $specialistModel->parameters],
                'source_model_version_ids' => [$packetKey => (int) $specialistModel->id],
                'specialist_coverage_curriculum' => [
                    'protocol' => 'professional_specialist_coverage_curriculum_v1',
                    'source_trial_id' => (int) $specialistTrial->id,
                    'arms' => self::SPECIALIST_DENSIFICATION_ARMS,
                    'source_has_no_authority' => true,
                    'promotion_evidence' => false,
                ],
            ]];
    }

    /**
     * Turn a sparse positive professional breakout into a real temporal-role
     * experiment. G151 proved that expiry/displacement were non-owning under
     * aggressive breakout and that swapping the entire tactic killed every
     * trade. The next packet therefore keeps H1 bias and the specialist
     * context frozen while testing who owns setup (H1 or M15), the structural
     * horizon, and retest confirmation one axis at a time.
     */
    private function temporalBreakoutBindingAssessment(array $base, $trials, $models, array $identity, int $generationId, string $symbol, string $timeframe): array
    {
        $controlTrial = $trials->firstWhere('arm', 'specialist_interaction_control');
        $controlModel = $controlTrial ? $models->get((int) $controlTrial->model_version_id) : null;
        $control = $this->latestValidMetrics($controlModel, $symbol, $timeframe);
        $controlExpectancy = $this->afterCost($control);
        $controlTrades = (int) data_get($control, 'total_trades', 0);
        $controlPowered = (int) data_get($control, 'forward_window_protocol.powered_windows', 0);
        $controlPositive = (int) data_get($control, 'forward_window_protocol.positive_windows', 0);
        $setupCount = (int) data_get($control, 'entry_contract_funnel.stage_counts.setup', 0);
        $triggerCount = (int) data_get($control, 'entry_contract_funnel.stage_counts.trigger', 0);
        $context = (array) data_get($controlModel?->metadata, 'edge_genesis.context', []);

        $complete = $controlTrial && $controlModel
            && (string) $controlTrial->stage === 'two_fold_discovery'
            && (string) $controlTrial->status === 'control_settled'
            && $trials->count() === count(self::SPECIALIST_DENSIFICATION_ARMS)
            && $trials->every(fn ($trial): bool => in_array((string) $trial->status, ['control_settled', 'edge_not_found'], true));
        $sparsePositive = $controlTrades >= 4
            && (float) data_get($control, 'profit_factor', 0) > 1
            && $controlExpectancy > 0
            && $controlPositive >= 1
            && $controlPowered < 2;
        $professionalBottleneck = data_get($controlModel?->parameters, 'entry_model') === 'breakout_retest'
            && data_get($controlModel?->parameters, 'entry_mode') === 'aggressive'
            && $setupCount > $triggerCount
            && $triggerCount >= $controlTrades;
        $contextFrozen = data_get($context, 'enforcement') === 'required'
            && data_get($context, 'admission_axes') === ['regime', 'direction', 'volatility']
            && data_get($context, 'allowed_directions') === ['BUY']
            && data_get($context, 'allowed_volatility') === ['high_volatility']
            && data_get($context, 'outside_scope') === 'WAIT';
        $nonImprovingTreatments = $trials
            ->where('arm', '!=', 'specialist_interaction_control')
            ->every(function ($trial) use ($models, $symbol, $timeframe, $controlExpectancy): bool {
                $metrics = $this->latestValidMetrics($models->get((int) $trial->model_version_id), $symbol, $timeframe);

                return (string) $trial->status === 'edge_not_found'
                    && $this->afterCost($metrics) <= $controlExpectancy;
            });

        if (! $complete || ! $sparsePositive || ! $professionalBottleneck || ! $contextFrozen || ! $nonImprovingTreatments) {
            return [...$base, 'repair_revision' => self::TEMPORAL_BREAKOUT_BINDING_REVISION,
                'status' => 'blocked', 'reason' => 'TEMPORAL_BREAKOUT_ROLE_HYPOTHESIS_NOT_CAUSALLY_ADMISSIBLE',
                'source_generation_id' => $generationId, 'complete' => (bool) $complete,
                'sparse_positive' => $sparsePositive, 'professional_bottleneck' => $professionalBottleneck,
                'context_frozen' => $contextFrozen, 'non_improving_treatments' => $nonImprovingTreatments,
                'control_trades' => $controlTrades, 'control_powered_windows' => $controlPowered,
                'setup_count' => $setupCount, 'trigger_count' => $triggerCount,
                'promotion_evidence' => false];
        }

        $packetKey = (string) $controlTrial->packet_key;

        return [...$base, 'repair_revision' => self::TEMPORAL_BREAKOUT_BINDING_REVISION,
            'status' => 'admitted', 'admitted' => true,
            'reason' => 'SPARSE_BREAKOUT_CONTROL_REQUIRES_TEMPORAL_ROLE_BINDING',
            'source_generation_id' => $generationId, 'source_bundle_hash' => $identity['bundle_hash'],
            'selected_packet' => $packetKey, 'source_arm' => 'specialist_interaction_control',
            'source_expectancy_r' => $controlExpectancy,
            'source_profit_factor' => (float) data_get($control, 'profit_factor', 0),
            'source_trades' => $controlTrades, 'source_powered_windows' => $controlPowered,
            'source_positive_windows' => $controlPositive,
            'entry_funnel' => ['setup' => $setupCount, 'trigger' => $triggerCount, 'trades' => $controlTrades],
            'inherited_fact' => 'BUY/high-volatility breakout is positive but temporally sparse; expiry and displacement do not own behavior under aggressive entry',
            'causal_boundary' => 'H1_bias_and_specialist_context_frozen; one_temporal_setup_or_trigger_axis_per_treatment; risk_management_data_unchanged',
            'temporal_roles' => ['bias' => 'H1', 'candidate_setup' => ['H1', 'M15'], 'trigger' => 'M5', 'execution' => 'M5'],
            'two_fold_discovery_required' => true, 'nine_fold_authority_unchanged' => true,
            'parent_authority' => false, 'promotion_evidence' => false,
            'materialization_contract' => [...$identity['materialization_contract'],
                'packet_keys' => [$packetKey],
                'source_parameters' => [$packetKey => (array) $controlModel->parameters],
                'source_model_version_ids' => [$packetKey => (int) $controlModel->id],
                'temporal_breakout_role_curriculum' => [
                    'protocol' => 'temporal_breakout_role_curriculum_v1',
                    'source_trial_id' => (int) $controlTrial->id,
                    'arms' => self::TEMPORAL_BREAKOUT_BINDING_ARMS,
                    'one_behavior_owning_axis_per_treatment' => true,
                    'source_has_no_authority' => true,
                    'promotion_evidence' => false,
                ],
            ]];
    }

    /**
     * M15 increased setup coverage but admitted a losing false-entry cluster.
     * Preserve that exact non-parent composition as a causal baseline and
     * learn whether trigger timing or confirmation breadth can recover
     * quality. Risk, management, H1 bias, context and data stay frozen.
     */
    private function m15SetupQualityAssessment(array $base, $trials, $models, array $identity, int $generationId, string $symbol, string $timeframe): array
    {
        $h1Trial = $trials->firstWhere('arm', 'h1_breakout_control');
        $m15Trial = $trials->firstWhere('arm', 'm15_setup_breakout');
        $h1Model = $h1Trial ? $models->get((int) $h1Trial->model_version_id) : null;
        $m15Model = $m15Trial ? $models->get((int) $m15Trial->model_version_id) : null;
        $h1 = $this->latestValidMetrics($h1Model, $symbol, $timeframe);
        $m15 = $this->latestValidMetrics($m15Model, $symbol, $timeframe);
        $h1Trades = (int) data_get($h1, 'total_trades', 0);
        $m15Trades = (int) data_get($m15, 'total_trades', 0);
        $counterfactualModes = (array) data_get($m15, 'entry_contract_funnel.trigger_topology.counterfactual_mode_counts', []);
        $context = (array) data_get($m15Model?->metadata, 'edge_genesis.context', []);

        $complete = $h1Trial && $m15Trial && $h1Model && $m15Model
            && (string) $h1Trial->status === 'control_settled'
            && (string) $m15Trial->status === 'edge_not_found'
            && $trials->count() === count(self::TEMPORAL_BREAKOUT_BINDING_ARMS)
            && $trials->every(fn ($trial): bool => in_array((string) $trial->status, ['control_settled', 'edge_not_found'], true));
        $singleTemporalAxis = $h1Model && $m15Model
            && hash_equals(
                $this->parameterHash(collect((array) $h1Model->parameters)->except('breakout_setup_timeframe')->all()),
                $this->parameterHash(collect((array) $m15Model->parameters)->except('breakout_setup_timeframe')->all()),
            )
            && data_get($m15Model->parameters, 'breakout_setup_timeframe') === 'M15'
            && data_get($m15Model->parameters, 'entry_mode') === 'aggressive'
            && (int) data_get($m15Model->parameters, 'minimum_independent_confirmations', 0) === 1;
        $coverageExpanded = $h1Trades >= 4 && $m15Trades > $h1Trades;
        $qualityFailure = (float) data_get($m15, 'profit_factor', 0) < 1
            && (float) data_get($m15, 'net_profit_percent', 0) < 0
            && (int) data_get($m15, 'forward_window_protocol.positive_windows', 0) === 0;
        $counterfactualExecutable = (int) ($counterfactualModes['balanced'] ?? 0) > 0
            && (int) ($counterfactualModes['conservative'] ?? 0) > 0;
        $contextFrozen = data_get($context, 'enforcement') === 'required'
            && data_get($context, 'admission_axes') === ['regime', 'direction', 'volatility']
            && data_get($context, 'allowed_directions') === ['BUY']
            && data_get($context, 'allowed_volatility') === ['high_volatility']
            && data_get($context, 'outside_scope') === 'WAIT';

        if (! $complete || ! $singleTemporalAxis || ! $coverageExpanded || ! $qualityFailure
            || ! $counterfactualExecutable || ! $contextFrozen) {
            return [...$base, 'repair_revision' => self::M15_SETUP_QUALITY_REVISION,
                'status' => 'blocked', 'reason' => 'M15_FALSE_ENTRY_QUALITY_REPAIR_NOT_CAUSALLY_ADMISSIBLE',
                'source_generation_id' => $generationId, 'complete' => (bool) $complete,
                'single_temporal_axis' => (bool) $singleTemporalAxis,
                'coverage_expanded' => $coverageExpanded, 'quality_failure' => $qualityFailure,
                'counterfactual_executable' => $counterfactualExecutable,
                'context_frozen' => $contextFrozen, 'h1_trades' => $h1Trades,
                'm15_trades' => $m15Trades, 'promotion_evidence' => false];
        }

        $packetKey = (string) $m15Trial->packet_key;

        return [...$base, 'repair_revision' => self::M15_SETUP_QUALITY_REVISION,
            'status' => 'admitted', 'admitted' => true,
            'reason' => 'M15_SETUP_EXPANDED_COVERAGE_BUT_REQUIRES_CONFIRMATION_QUALITY_MASTERY',
            'source_generation_id' => $generationId, 'source_bundle_hash' => $identity['bundle_hash'],
            'selected_packet' => $packetKey, 'source_arm' => 'm15_setup_breakout',
            'source_model_version_id' => (int) $m15Model->id,
            'source_trades' => $m15Trades,
            'source_profit_factor' => (float) data_get($m15, 'profit_factor', 0),
            'source_net_profit_percent' => (float) data_get($m15, 'net_profit_percent', 0),
            'control_trades' => $h1Trades,
            'counterfactual_mode_counts' => $counterfactualModes,
            'inherited_fact' => 'M15 setup owns additional opportunity coverage, but aggressive/minimum-one confirmation admits a losing false-entry cluster',
            'causal_boundary' => 'M15_setup_and_H1_bias_frozen; one_confirmation_quality_axis_per_treatment; risk_management_context_data_unchanged',
            'temporal_roles' => ['bias' => 'H1', 'setup' => 'M15', 'trigger' => 'M5', 'execution' => 'M5'],
            'two_fold_discovery_required' => true, 'nine_fold_authority_unchanged' => true,
            'parent_authority' => false, 'promotion_evidence' => false,
            'materialization_contract' => [...$identity['materialization_contract'],
                'packet_keys' => [$packetKey],
                'source_parameters' => [$packetKey => (array) $m15Model->parameters],
                'source_model_version_ids' => [$packetKey => (int) $m15Model->id],
                'm15_setup_quality_curriculum' => [
                    'protocol' => 'm15_setup_confirmation_quality_curriculum_v1',
                    'source_trial_id' => (int) $m15Trial->id,
                    'arms' => self::M15_SETUP_QUALITY_ARMS,
                    'genetic_parent_authority' => false,
                    'causal_baseline_authority' => true,
                    'one_confirmation_axis_per_treatment' => true,
                    'promotion_evidence' => false,
                ],
            ]];
    }

    /** @return array<string,mixed> */
    private function repairIdentityContract($passports, $models, ?LabGeneration $sourceGeneration, int $generationId): array
    {
        $dataHashes = $passports->pluck('data_hash')->filter()->unique()->values();
        $executionHashes = $passports->pluck('execution_hash')->filter()->unique()->values();
        $edgeContracts = $models->map(fn (ModelVersion $model): array => (array) data_get($model->metadata, 'edge_genesis', []));
        $bundleHashes = $edgeContracts->pluck('mtf_bundle_hash')->filter()->unique()->values();
        $manifest = (array) data_get((array) $edgeContracts->first(), 'mtf_bundle_manifest', []);
        $bundleHash = (string) ($bundleHashes->first() ?? '');
        $canonicalSnapshots = (array) data_get($sourceGeneration?->trigger_context, 'canonical_dataset_snapshots', []);
        $valid = $dataHashes->count() === 1 && $executionHashes->count() === 1 && $bundleHashes->count() === 1
            && preg_match('/^[a-f0-9]{64}$/', $bundleHash) === 1
            && hash_equals($bundleHash, (string) data_get($manifest, 'bundle_hash', ''))
            && data_get($manifest, 'validation_bundle_protocol') === 'agent_owned_mtf_foundation_bundle_v1'
            && data_get($manifest, 'data_role') === 'pre_2026_foundation_training_only'
            && data_get($manifest, 'promotion_evidence') === false
            && $this->canonicalDatasetSnapshotsValid($canonicalSnapshots, (string) $dataHashes->first());

        return ['valid' => $valid, 'bundle_hash' => $bundleHash,
            'materialization_contract' => ['data_hash' => (string) $dataHashes->first(),
                'execution_hash' => (string) $executionHashes->first(),
                'mtf_bundle' => ['bundle_hash' => $bundleHash, 'manifest' => $manifest],
                'canonical_dataset_snapshots' => $this->reusedCanonicalDatasetSnapshots($canonicalSnapshots, $generationId)]];
    }

    /** @return array<string,mixed> */
    private function latestValidMetrics(?ModelVersion $model, string $symbol, string $timeframe): array
    {
        $performance = $model?->marketPerformances->where('symbol', $symbol)->where('timeframe', $timeframe)
            ->where('evidence_status', 'valid')->sortByDesc('id')->first();

        return (array) $performance?->metrics;
    }

    /**
     * Pair a discovery treatment only with its frozen/traveling control and
     * only when the materialized parameter vector has exactly one declared
     * stage owner. Multi-axis historical packets remain diagnostic, rather
     * than receiving fabricated causal credit or a false retirement.
     *
     * @return array<string,mixed>
     */
    private function stageMasteryAssessment(LabAgent $agent, ?object $trial, object $passport, array $result, string $stage, bool $controlRole): array
    {
        $base = ['protocol' => CausalStageMasteryDirectorService::PROTOCOL, 'status' => 'not_applicable',
            'enforce' => false, 'promotion_evidence' => false];
        if ($stage !== 'two_fold_discovery' || $controlRole || ! $trial || ! $agent->modelVersion) {
            return $base;
        }
        $controlTrial = DB::table('edge_genesis_trials')->where('edge_genesis_passport_id', $passport->id)
            ->where('stage', 'two_fold_discovery')->whereIn('arm', self::TRAVELING_CONTROL_ARMS)
            ->whereNotNull('settled_at')->orderBy('id')->first();
        if (! $controlTrial) {
            return [...$base, 'status' => 'awaiting_paired_control'];
        }
        $controlModel = ModelVersion::query()->find((int) $controlTrial->model_version_id);
        $controlMetrics = $this->latestValidMetrics($controlModel, $agent->symbol, $agent->timeframe);
        if (! $controlModel || $controlMetrics === []) {
            return [...$base, 'status' => 'awaiting_valid_control_metrics'];
        }
        $axis = $this->stageMastery->inferAxis((array) $controlModel->parameters, (array) $agent->modelVersion->parameters);
        if (($axis['status'] ?? '') !== 'single_axis') {
            return [...$base, 'axis' => $axis];
        }
        $owner = (array) ($axis['owner'] ?? []);
        if (($owner['declared'] ?? false) !== true) {
            return [...$base, 'axis' => $axis];
        }
        $gene = (string) $axis['gene'];
        $control = [...$controlMetrics, 'value' => data_get($controlModel->parameters, $gene)];
        $candidate = [...$result, 'value' => data_get($agent->modelVersion->parameters, $gene)];
        $assessment = $this->stageMastery->record($trial, $controlTrial, $agent->modelVersion, $gene, $control, $candidate,
            $agent->symbol, $agent->timeframe);
        $ratchetContext = [
            'symbol' => $agent->symbol, 'timeframe' => $agent->timeframe, 'composition_key' => (string) $passport->genesis_key,
            'causal_baseline_id' => $controlModel->id, 'baseline_epoch_hash' => hash('sha256', json_encode((array) $controlModel->parameters)),
            'data_hash' => (string) $passport->data_hash, 'execution_hash' => (string) $passport->execution_hash,
            'window_plan_hash' => (string) data_get($agent->modelVersion->metadata, 'edge_genesis.frozen_window_plan.window_plan_hash', ''),
            'intervention_hash' => hash('sha256', json_encode([$gene, data_get($controlModel->parameters, $gene), data_get($agent->modelVersion->parameters, $gene)])),
            'axis' => $gene,
        ];
        $ratchet = $this->ratchetGovernor->recordAssessment($assessment, $ratchetContext, $control, $candidate);
        $this->ratchetGovernor->progress([...$ratchetContext, 'ratchet_id' => $ratchet['ratchet_id'] ?? null], 'DISCOVERED', 'DISCOVERED', [
            'assessment_status' => $assessment['status'], 'classification' => $ratchet['classification'] ?? [],
        ]);
        if (($ratchet['authority'] ?? '') === 'path_activating_scaffold') {
            $metadata = (array) $agent->modelVersion->metadata;
            $metadata['causal_progress_ratchet'] = [
                'protocol' => CausalProgressRatchetGovernorService::PROTOCOL,
                'ratchet_id' => $ratchet['ratchet_id'] ?? null, 'deepest_stage' => $ratchet['deepest_stage'] ?? 'none',
                'causal_baseline_id' => $controlModel->id, 'baseline_epoch_hash' => $ratchetContext['baseline_epoch_hash'],
                'authority' => 'path_activating_scaffold', 'parent_authority' => false, 'paper_authority' => false,
                'next_baseline_authority' => true, 'promotion_evidence' => false,
            ];
            $agent->modelVersion->update(['metadata' => $metadata]);
            $agent->setRelation('modelVersion', $agent->modelVersion->fresh());
        }

        return ['protocol' => CausalStageMasteryDirectorService::PROTOCOL, 'status' => 'assessed',
            'enforce' => true, 'axis' => $axis, 'assessment' => $assessment,
            'scaffold' => $this->stageMastery->scaffold($assessment), 'ratchet' => $ratchet, 'promotion_evidence' => false];
    }

    /**
     * Settlement order is deliberately unconstrained. When the frozen control
     * lands after a treatment, reconcile that already immutable pair before a
     * three-fold cohort is admitted, instead of losing its ratchet evidence.
     *
     * @return array<string,int>
     */
    private function reconcileDiscoveryStageMastery(string $symbol, string $timeframe): array
    {
        $trials = DB::table('edge_genesis_trials as t')->join('edge_genesis_passports as p', 'p.id', '=', 't.edge_genesis_passport_id')
            ->where('p.symbol', strtoupper($symbol))->where('p.timeframe', strtoupper($timeframe))
            ->where('t.stage', 'two_fold_discovery')->where('t.status', 'edge_progressing')
            ->whereNotIn('t.arm', self::TRAVELING_CONTROL_ARMS)
            ->select(['t.*', 'p.genesis_key', 'p.data_hash', 'p.execution_hash'])->get();
        $assessed = 0;
        $retired = 0;
        foreach ($trials as $trial) {
            $priorEvidence = is_string($trial->evidence) ? (array) json_decode($trial->evidence, true) : (array) $trial->evidence;
            // A comparative selector has already made the bounded cohort's
            // terminal decision. Ratchet reconciliation must not reopen or
            // overwrite that decision with an earlier local preflight.
            if (data_get($priorEvidence, 'authority_selection.decision') !== null) {
                continue;
            }
            $agent = LabAgent::query()->with('modelVersion')->find((int) $trial->lab_agent_id);
            $metrics = $agent?->modelVersion?->marketPerformances()->where('evidence_status', 'valid')->latest('id')->value('metrics');
            if (! $agent || ! is_array($metrics)) {
                continue;
            }
            $passport = (object) ['id' => $trial->edge_genesis_passport_id, 'genesis_key' => $trial->genesis_key,
                'data_hash' => $trial->data_hash, 'execution_hash' => $trial->execution_hash];
            $outcome = $this->stageMasteryAssessment($agent, $trial, $passport, $metrics, 'two_fold_discovery', false);
            if (($outcome['status'] ?? '') !== 'assessed') {
                continue;
            }
            $assessed++;
            $evidence = $priorEvidence;
            if (data_get($outcome, 'assessment.status') === 'non_controlling_axis') {
                $retired++;
            }
            DB::table('edge_genesis_trials')->where('id', $trial->id)->update([
                'evidence' => json_encode([...$evidence, 'causal_stage_mastery' => $outcome, 'promotion_evidence' => false]), 'updated_at' => now()]);
        }

        return compact('assessed', 'retired');
    }

    /** @param array<string,mixed> $assessment @return array<string,mixed> */
    private function withoutMaterializationContract(array $assessment): array
    {
        unset($assessment['materialization_contract']);

        return $assessment;
    }

    /** Keep generation audit useful without duplicating large parameter/evidence payloads. */
    private function withoutLargeRepairPayload(array $contract): array
    {
        $sourceParameters = (array) ($contract['source_parameters'] ?? []);
        // Fixed repair contracts store packet_key => parameter_map, whereas
        // an evidence-compiled packet owns one direct parameter_map. Preserve
        // both historical formats without serializing the large vector into
        // the generation audit and without treating scalar gene values as
        // nested parameter arrays.
        $parameterSets = $sourceParameters !== []
            && collect($sourceParameters)->every(fn (mixed $value): bool => is_array($value))
                ? $sourceParameters
                : ($sourceParameters === [] ? [] : ['compiled_source' => $sourceParameters]);

        return collect($contract)->except(['canonical_dataset_snapshots', 'source_parameters', 'latent_excursion_evidence'])->all()
            + ['source_parameter_hashes' => collect($parameterSets)
                ->map(fn (array $parameters): string => $this->cohortIdentity->hash($parameters))->all(),
                'latent_excursion_trial_ids' => collect((array) ($contract['latent_excursion_evidence'] ?? []))->pluck('trial_id')->all()];
    }

    /** @param array<string,mixed> $snapshots */
    private function canonicalDatasetSnapshotsValid(array $snapshots, string $dataHash): bool
    {
        $foundation = (array) ($snapshots['foundation'] ?? []);
        $price = (array) ($snapshots['price'] ?? []);
        foreach ([$foundation, $price] as $snapshot) {
            $path = (string) ($snapshot['path'] ?? '');
            $sha = (string) ($snapshot['sha256'] ?? '');
            $actual = $path !== '' && is_file($path) ? hash_file('sha256', $path) : false;
            if ($sha === '' || ! is_string($actual) || ! hash_equals($sha, $actual)) {
                return false;
            }
        }

        return hash_equals($dataHash, (string) ($foundation['sha256'] ?? ''))
            && data_get($foundation, 'manifest.source_role') === 'foundation_training_only'
            && data_get($foundation, 'manifest.promotion_evidence') === false
            && data_get($foundation, 'manifest.continuity.status') === 'ready'
            && (int) data_get($foundation, 'manifest.continuity.unexpected_gap_count', 1) === 0
            && data_get($price, 'manifest.data_role') === 'paper_only'
            && (string) data_get($price, 'manifest.training_end_exclusive') === '2026-01-01T00:00:00+00:00';
    }

    /** @param array<string,mixed> $snapshots @return array<string,mixed> */
    private function reusedCanonicalDatasetSnapshots(array $snapshots, int $sourceGenerationId, ?int $targetGenerationId = null): array
    {
        return collect(['foundation', 'price'])->mapWithKeys(function (string $key) use ($snapshots, $sourceGenerationId, $targetGenerationId): array {
            $snapshot = (array) ($snapshots[$key] ?? []);

            return [$key => [...$snapshot, 'causal_reuse' => [
                'protocol' => 'edge_generation_coverage_snapshot_reuse_v1',
                'source_generation_id' => $sourceGenerationId,
                'target_generation_id' => $targetGenerationId,
                'immutable_file_reused' => true,
                'promotion_evidence' => false,
            ]]];
        })->all();
    }

    /** @return list<string> */
    private function armsForRevision(string $architectureRevision): array
    {
        return match ($architectureRevision) {
            self::TRIGGER_REPAIR_REVISION => self::TRIGGER_REPAIR_ARMS,
            self::LATENT_HARVEST_REVISION => self::LATENT_HARVEST_ARMS,
            self::CONTEXT_ROUTER_REPAIR_REVISION => self::CONTEXT_ROUTER_REPAIR_ARMS,
            self::REGIME_ENTRY_SYNTHESIS_REVISION => self::REGIME_ENTRY_SYNTHESIS_ARMS,
            self::FAILURE_CELL_FACTORIAL_REVISION => self::FAILURE_CELL_FACTORIAL_ARMS,
            self::SPECIALIST_DENSIFICATION_REVISION => self::SPECIALIST_DENSIFICATION_ARMS,
            self::TEMPORAL_BREAKOUT_BINDING_REVISION => self::TEMPORAL_BREAKOUT_BINDING_ARMS,
            self::M15_SETUP_QUALITY_REVISION => self::M15_SETUP_QUALITY_ARMS,
            self::EVIDENCE_COMPILED_REVISION => self::COMPILED_HYPOTHESIS_ARMS,
            default => self::GENESIS_ARMS,
        };
    }

    /** @return array{base_strategy:string,family:string,parameters:array<string,mixed>} */
    private function runtimeForArm(array $packet, string $arm, string $architectureRevision, array $sourceParameters = []): array
    {
        if ($arm === 'frozen_control') {
            return [
                'base_strategy' => 'mtf_research_control_v1',
                'family' => 'mtf_research_control',
                'parameters' => $this->schemas->validate('mtf_research_control', $this->schemas->defaults('mtf_research_control')),
            ];
        }

        $p = in_array($architectureRevision, [self::LATENT_HARVEST_REVISION, self::CONTEXT_ROUTER_REPAIR_REVISION, self::REGIME_ENTRY_SYNTHESIS_REVISION, self::FAILURE_CELL_FACTORIAL_REVISION, self::SPECIALIST_DENSIFICATION_REVISION, self::TEMPORAL_BREAKOUT_BINDING_REVISION, self::M15_SETUP_QUALITY_REVISION, self::EVIDENCE_COMPILED_REVISION], true) && $sourceParameters !== []
            ? $this->schemas->normalizeForGeneration('confirmation_entry_mtf', $sourceParameters)
            : $this->schemas->defaults('confirmation_entry_mtf');
        // A compiled hypothesis inherits the complete causal baseline and
        // changes only its declared axis below. Re-deriving entry_model from
        // a hash-suffixed packet key silently reset descendant compositions
        // to trend_continuation and broke executable skill inheritance.
        if ($architectureRevision !== self::EVIDENCE_COMPILED_REVISION) {
            $p['entry_model'] = match ((string) ($packet['key'] ?? '')) {
                'liquidity_reversal' => 'false_break_reversal',
                'break_retest' => 'breakout_retest',
                'range_session_specialist' => 'range_sweep',
                default => 'trend_continuation',
            };
        }
        if ($architectureRevision === self::TRIGGER_REPAIR_REVISION) {
            // The previous repair established that one independent evidence
            // family activates confirmation. Every treatment below inherits
            // that fact, then changes exactly one trigger-topology axis
            // against confirmation_floor_control. Risk and management stay
            // frozen, so a new trade cannot be credited to sizing or exits.
            $p['minimum_independent_confirmations'] = 1;
            if ($arm === 'internal_structure_trigger') {
                $p['swing_lookback'] = 10;
            }
            if ($arm === 'aggressive_trigger') {
                $p['entry_mode'] = 'aggressive';
            }
            if ($arm === 'extended_retest_trigger') {
                $p['m5_retest_expiry_minutes'] = 60;
            }
        }
        if ($architectureRevision === self::LATENT_HARVEST_REVISION) {
            // Each treatment changes one management axis around the exact
            // source composition that produced conservative pre-exit-bar MFE.
            // Entry, invalidation, position risk and temporal roles remain
            // byte-identical to the latent_edge_control arm.
            if ($arm === 'partial_harvest') {
                $p['partial_take_profit_fraction'] = .5;
            }
            if ($arm === 'trailing_harvest') {
                $p['trailing_atr_multiplier'] = 1.0;
            }
            if ($arm === 'time_stop_harvest') {
                $p['time_stop_candles'] = 12;
            }
            if ($arm === 'target_harvest') {
                $p['atr_target_multiplier'] = 1.5;
            }
        }
        if ($architectureRevision === self::REGIME_ENTRY_SYNTHESIS_REVISION) {
            // The regime firewall is inherited as a proven stepping stone.
            // Every treatment changes one entry-quality axis only; none may
            // alter risk, management, data, or the temporal role binding.
            if ($arm === 'retest_entry_gate') {
                $p['entry_mode'] = 'balanced';
            }
            if ($arm === 'independent_confirmation_gate') {
                $p['minimum_independent_confirmations'] = 2;
            }
            if ($arm === 'reward_space_gate') {
                $p['minimum_reward_space_r'] = 2.0;
            }
            if ($arm === 'chase_quality_gate') {
                $p['max_chase_atr'] = .75;
            }
        }
        if ($architectureRevision === self::FAILURE_CELL_FACTORIAL_REVISION) {
            // This cohort changes no strategy/risk/management gene.  Its only
            // interventions live in the pre-entry context firewall so a
            // retrospective failure-cell pattern becomes a real experiment.
        }
        if ($architectureRevision === self::SPECIALIST_DENSIFICATION_REVISION) {
            // The professional BUY/high-volatility organism is frozen. Each
            // treatment changes one tactic/topology or opportunity-density
            // axis; risk sizing and trade management remain byte-identical.
            if ($arm === 'trend_continuation_topology') {
                $p['entry_model'] = 'trend_continuation';
            }
            if ($arm === 'false_break_reversal_topology') {
                $p['entry_model'] = 'false_break_reversal';
            }
            if ($arm === 'extended_retest_window') {
                $p['m5_retest_expiry_minutes'] = 40;
            }
            if ($arm === 'lower_displacement_gate') {
                $p['m5_minimum_displacement_atr'] = .35;
            }
        }
        if ($architectureRevision === self::TEMPORAL_BREAKOUT_BINDING_REVISION) {
            // H1 remains the bias authority. Treatments move exactly one
            // executable setup/trigger axis so M15/M5 can be learned as
            // temporal roles rather than separate tradable instruments.
            $p['breakout_setup_timeframe'] = 'H1';
            if ($arm === 'm15_setup_breakout') {
                $p['breakout_setup_timeframe'] = 'M15';
            }
            if ($arm === 'short_structure_horizon') {
                $p['swing_lookback'] = 20;
            }
            if ($arm === 'long_structure_horizon') {
                $p['swing_lookback'] = 60;
            }
            if ($arm === 'balanced_retest_confirmation') {
                $p['entry_mode'] = 'balanced';
            }
        }
        if ($architectureRevision === self::M15_SETUP_QUALITY_REVISION) {
            // The losing but behavior-owning M15 composition is a causal
            // baseline, never a parent. Each treatment changes one entry
            // quality axis and leaves the complete temporal organism frozen.
            $p['breakout_setup_timeframe'] = 'M15';
            $p['entry_mode'] = 'aggressive';
            $p['minimum_independent_confirmations'] = 1;
            if ($arm === 'm15_balanced_confirmation') {
                $p['entry_mode'] = 'balanced';
            }
            if ($arm === 'm15_conservative_confirmation') {
                $p['entry_mode'] = 'conservative';
            }
            if ($arm === 'm15_two_family_confirmation') {
                $p['minimum_independent_confirmations'] = 2;
            }
            if ($arm === 'm15_three_family_confirmation') {
                $p['minimum_independent_confirmations'] = 3;
            }
        }
        if ($architectureRevision === self::EVIDENCE_COMPILED_REVISION) {
            $axis = (string) ($packet['compiled_axis'] ?? '');
            $values = (array) ($packet['compiled_arm_values'] ?? []);
            if ($axis === '' || ! array_key_exists($arm, $values)) {
                throw new \InvalidArgumentException('Compiled Edge arm is missing its one-axis value.');
            }
            $p[$axis] = $values[$arm];
        }
        if ($arm === 'confirmation_floor_one') {
            // Test the marginal value of confirmation breadth directly. The
            // professional reference keeps three independent evidence
            // families; this arm still requires real confirmation, but asks
            // whether one independent family is enough after location/setup.
            // Changing entry mode at the same time confounded trigger timing
            // with confirmation and left most packets permanently trade-less.
            $p['minimum_independent_confirmations'] = 1;
        }
        if ($arm === 'temporal_role_change') {
            $p['h4_context_max_age_bars'] = 3.0;
            $p['h1_context_max_age_bars'] = 3.0;
            $p['m15_context_max_age_bars'] = 4.0;
        }
        if ($arm === 'memory_blinded_autonomous') {
            // Deterministic cold-start novelty changes one structural axis;
            // no memory or outcome is consulted before registration.
            $p['rejection_wick_ratio'] = .45;
        }

        return [
            'base_strategy' => 'confirmation_entry_mtf_v1',
            'family' => 'confirmation_entry_mtf',
            'parameters' => $this->schemas->validate('confirmation_entry_mtf', $p),
        ];
    }

    /** @return array<string,mixed> */
    private function contextForArm(array $packet, string $arm, string $architectureRevision): array
    {
        $context = [...((array) ($packet['context'] ?? [])),
            'protocol' => 'edge_context_authority_firewall_v1',
            'pre_entry_only' => true,
            'calendar_identity_forbidden' => true,
            'promotion_evidence' => false,
        ];
        if ($architectureRevision === self::FAILURE_CELL_FACTORIAL_REVISION) {
            $axes = match ($arm) {
                'buy_direction_gate', 'sell_direction_negative_control' => ['regime', 'direction'],
                'high_volatility_gate' => ['regime', 'volatility'],
                'buy_high_volatility_interaction' => ['regime', 'direction', 'volatility'],
                default => ['regime'],
            };
            $direction = match ($arm) {
                'buy_direction_gate', 'buy_high_volatility_interaction' => ['BUY'],
                'sell_direction_negative_control' => ['SELL'],
                default => [],
            };

            return [...$context,
                'enforcement' => 'required',
                'admission_axes' => $axes,
                ...($direction !== [] ? ['direction' => $direction[0], 'allowed_directions' => $direction] : []),
                ...(in_array('volatility', $axes, true)
                    ? ['volatility' => 'high_volatility', 'allowed_volatility' => ['high_volatility']]
                    : []),
                'outside_scope' => 'WAIT',
                'pre_entry_only' => true,
                'calendar_identity_forbidden' => true,
                'retrospective_subset_authority' => false,
                'promotion_evidence' => false,
            ];
        }
        if ($architectureRevision === self::SPECIALIST_DENSIFICATION_REVISION) {
            return [...$context,
                'regime' => 'trend', 'allowed_regimes' => ['trend_up', 'trend_down'],
                'volatility' => 'high_volatility', 'allowed_volatility' => ['high_volatility'],
                'direction' => 'BUY', 'allowed_directions' => ['BUY'],
                'enforcement' => 'required',
                'admission_axes' => ['regime', 'direction', 'volatility'],
                'outside_scope' => 'WAIT', 'pre_entry_only' => true,
                'calendar_identity_forbidden' => true,
                'source_specialist_authority' => false,
                'promotion_evidence' => false,
            ];
        }
        if (in_array($architectureRevision, [self::TEMPORAL_BREAKOUT_BINDING_REVISION, self::M15_SETUP_QUALITY_REVISION], true)) {
            return [...$context,
                'regime' => 'trend', 'allowed_regimes' => ['trend_up', 'trend_down'],
                'volatility' => 'high_volatility', 'allowed_volatility' => ['high_volatility'],
                'direction' => 'BUY', 'allowed_directions' => ['BUY'],
                'enforcement' => 'required',
                'admission_axes' => ['regime', 'direction', 'volatility'],
                'outside_scope' => 'WAIT', 'pre_entry_only' => true,
                'calendar_identity_forbidden' => true,
                'source_specialist_authority' => false,
                'temporal_role_experiment' => true,
                ...($architectureRevision === self::M15_SETUP_QUALITY_REVISION
                    ? ['m15_setup_quality_experiment' => true, 'causal_baseline_parent_authority' => false]
                    : []),
                'promotion_evidence' => false,
            ];
        }
        if ($architectureRevision === self::REGIME_ENTRY_SYNTHESIS_REVISION) {
            return [...$context,
                'enforcement' => 'required',
                'admission_axes' => ['regime'],
                'outside_scope' => 'WAIT',
                'pre_entry_only' => true,
                'calendar_identity_forbidden' => true,
                'promotion_evidence' => false,
            ];
        }
        if ($architectureRevision !== self::CONTEXT_ROUTER_REPAIR_REVISION) {
            return $context;
        }

        $axes = match ($arm) {
            'regime_compatibility_gate' => ['regime'],
            'session_liquidity_gate' => ['session'],
            'regime_session_gate' => ['regime', 'session'],
            'strict_context_gate' => ['regime', 'session', 'volatility'],
            default => [],
        };

        return [...$context,
            'enforcement' => $axes === [] ? 'telemetry_only_control' : 'required',
            'admission_axes' => $axes,
            'outside_scope' => 'WAIT',
            'pre_entry_only' => true,
            'calendar_identity_forbidden' => true,
            'promotion_evidence' => false,
        ];
    }

    private function attributionParameters(string $arm, array $base, string $baseStrategy): array
    {
        $family = str_contains($baseStrategy, 'mtf_research_control') ? 'mtf_research_control' : 'confirmation_entry_mtf';
        $p = $this->schemas->normalizeForGeneration($family, $base);
        if ($family === 'confirmation_entry_mtf') {
            if ($arm === 'no_confirmation') {
                $p['attribution_confirmation_bypass'] = true;
            }
            if ($arm === 'alternate_tactic') {
                $p['entry_model'] = match ((string) ($p['entry_model'] ?? 'trend_continuation')) {
                    'breakout_retest' => 'false_break_reversal',
                    'false_break_reversal' => 'breakout_retest',
                    'range_sweep' => 'htf_reversal',
                    default => 'breakout_retest',
                };
            }
            if ($arm === 'alternate_temporal_binding') {
                $p['h4_context_max_age_bars'] = 4.;
                $p['h1_context_max_age_bars'] = 4.;
                $p['m15_context_max_age_bars'] = 6.;
            }
            if ($arm === 'frozen_minimal_control') {
                // This is an explicit low-selectivity negative control, not a
                // copy of the professional defaults.  Returning defaults made
                // the arm byte-identical whenever the source itself used the
                // default blueprint, so attribution could never resolve.
                $p = [...$this->schemas->defaults('confirmation_entry_mtf'),
                    'entry_mode' => 'aggressive',
                    'm5_minimum_displacement_atr' => .1,
                    'breakout_minimum_expansion_atr' => .05,
                    'location_tolerance_atr' => 2.0,
                    'rejection_wick_ratio' => .1,
                    'minimum_independent_confirmations' => 1,
                    'minimum_reward_space_r' => .5,
                    'max_chase_atr' => 5.0,
                ];
            }
        }

        return $this->schemas->validate($family, $p);
    }

    private function afterCost(array $result): float
    {
        return (float) data_get($result, 'after_cost_expectancy_r', data_get($result, 'pf_attribution.after_cost_expectancy_r', data_get($result, 'net_r', 0)));
    }

    private function parameterHash(array $parameters): string
    {
        return hash('sha256', json_encode($this->canonicalParameterValue($parameters), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function canonicalParameterValue(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalParameterValue($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalParameterValue($item);
        }

        return $value;
    }

    private function parameterValuesEquivalent(mixed $old, mixed $new): bool
    {
        return json_encode($this->canonicalParameterValue($old), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)
            === json_encode($this->canonicalParameterValue($new), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function tacticForArm(string $reference, string $arm): string
    {
        if ($arm !== 'confirmation_tactic_change') {
            return $reference;
        }

        return match ($reference) {
            'trend_pullback' => 'trend_breakout_retest',
            'liquidity_reversal' => 'liquidity_sweep_reversal',
            'break_retest' => 'breakout_continuation',
            'range_reversion' => 'range_rsi_reversion',
            default => $reference.'_confirmation_variant',
        };
    }

    private function temporalContractForArm(string $arm): array
    {
        // A role ablation is explicit in the frozen passport. It cannot turn
        // into M1 precision simply because an execution engine happens to
        // have an M1 feed available.
        return $arm === 'temporal_role_change'
            ? ['m5_canonical' => false, 'm1_execution' => false, 'closed_at_available_at' => true, 'backward_only_alignment' => true]
            : ['m5_canonical' => true, 'm1_execution' => false, 'closed_at_available_at' => true, 'backward_only_alignment' => true];
    }

    /** @return array<string,mixed> */
    private function edgeSemanticGroup(string $symbol, string $timeframe, string $family, array $packet): array
    {
        return $this->semanticGroups->descriptor($symbol, $timeframe, $family, [
            'specialist_role' => 'edge_'.$packet['key'].'_specialist',
            'regime' => data_get($packet, 'context.regime', '*'),
            'volatility' => data_get($packet, 'context.volatility', '*'),
            'direction' => '*',
        ], 'edge_'.$packet['key']);
    }

    /**
     * A positive discovery is not enough to spend an authority replay.  A
     * context gate must beat its exact unfiltered composition on the same
     * frozen windows; otherwise the filter has only removed opportunity.
     *
     * @return array{selected:Collection,approved:Collection,dominated:Collection}
     */
    private function contextAuthoritySelection($rows): array
    {
        $rows = collect($rows)->values();
        $approved = collect();
        $dominated = collect();
        foreach ($rows->groupBy('passport_id') as $passportRows) {
            $control = $passportRows->firstWhere('arm', 'compiled_control')
                ?: $passportRows->firstWhere('arm', 'frozen_control')
                ?: $passportRows->firstWhere('arm', 'unfiltered_context_control')
                ?: $passportRows->firstWhere('arm', 'regime_entry_control')
                ?: $passportRows->firstWhere('arm', 'failure_cell_control')
                ?: $passportRows->firstWhere('arm', 'specialist_interaction_control')
                ?: $passportRows->firstWhere('arm', 'h1_breakout_control')
                ?: $passportRows->firstWhere('arm', 'm15_aggressive_control')
                ?: $passportRows->firstWhere('arm', 'compiled_control');
            if (! $control) {
                continue;
            }
            $eligibleArms = match ((string) $control->arm) {
                'regime_entry_control' => self::REGIME_ENTRY_SYNTHESIS_ARMS,
                'failure_cell_control' => self::FAILURE_CELL_FACTORIAL_ARMS,
                'specialist_interaction_control' => self::SPECIALIST_DENSIFICATION_ARMS,
                'h1_breakout_control' => self::TEMPORAL_BREAKOUT_BINDING_ARMS,
                'm15_aggressive_control' => self::M15_SETUP_QUALITY_ARMS,
                'compiled_control' => self::COMPILED_HYPOTHESIS_ARMS,
                'frozen_control' => self::GENESIS_ARMS,
                default => self::CONTEXT_ROUTER_REPAIR_ARMS,
            };
            $controlMetrics = $this->discoveryMetricsForModel((int) $control->model_version_id);
            foreach ($passportRows as $candidate) {
                if ($candidate->arm === $control->arm
                    || ! in_array((string) $candidate->arm, $eligibleArms, true)) {
                    continue;
                }
                $candidateMetrics = $this->discoveryMetricsForModel((int) $candidate->model_version_id);
                $controlExpectancy = $controlMetrics === null ? null : $this->afterCost($controlMetrics);
                $candidateExpectancy = $candidateMetrics === null ? null : $this->afterCost($candidateMetrics);
                $windowIdentity = $controlMetrics !== null && $candidateMetrics !== null
                    && $this->discoveryWindowIdentity($controlMetrics) !== []
                    && $this->discoveryWindowIdentity($controlMetrics) === $this->discoveryWindowIdentity($candidateMetrics);
                $enforced = data_get($candidateMetrics, 'edge_context_enforcement.protocol') === 'edge_context_authority_firewall_v1'
                    && data_get($candidateMetrics, 'edge_context_enforcement.enforced') === true
                    && data_get($candidateMetrics, 'edge_context_enforcement.outside_scope_action') === 'WAIT';
                $delta = $controlExpectancy !== null && $candidateExpectancy !== null
                    ? round($candidateExpectancy - $controlExpectancy, 6) : null;
                $decision = [
                    'protocol' => 'paired_discovery_authority_budget_v1',
                    'decision' => 'discovery_outperformed_control',
                    'reason' => 'PAIRED_DISCOVERY_OUTPERFORMED_EXACT_CONTROL',
                    'control_trial_id' => (int) $control->trial_id,
                    'control_expectancy_r' => $controlExpectancy,
                    'treatment_expectancy_r' => $candidateExpectancy,
                    'expectancy_delta_r' => $delta,
                    'same_frozen_windows' => $windowIdentity,
                    'minimum_delta_r' => 0.0,
                    'authority_granted' => false,
                    'nine_fold_replay_admitted' => true,
                    'promotion_evidence' => false,
                ];
                if ($windowIdentity && $enforced && $delta !== null && $delta > 0) {
                    $approved->push((object) [...((array) $candidate), 'authority_selection' => $decision]);

                    continue;
                }
                $dominated->push((object) [
                    ...((array) $candidate),
                    'authority_selection' => [
                        ...$decision,
                        'decision' => 'discovery_dominated',
                        'reason' => $windowIdentity
                            ? ($enforced ? 'PAIRED_DISCOVERY_DID_NOT_OUTPERFORM_CONTROL' : 'CONTEXT_FIREWALL_EVIDENCE_INVALID')
                            : 'PAIRED_DISCOVERY_WINDOW_IDENTITY_MISMATCH',
                        'control_trial_id' => (int) $control->trial_id,
                        'control_expectancy_r' => $controlExpectancy,
                        'treatment_expectancy_r' => $candidateExpectancy,
                        'expectancy_delta_r' => $delta,
                        'same_frozen_windows' => $windowIdentity,
                        'minimum_delta_r' => 0.0,
                        'authority_granted' => false,
                        'nine_fold_replay_admitted' => false,
                        'promotion_evidence' => false,
                    ],
                ]);
            }
        }

        return [
            'selected' => $rows->reject(fn ($row): bool => $dominated->contains(
                fn ($item): bool => (int) $item->trial_id === (int) $row->trial_id,
            ))->values(),
            'approved' => $approved->values(),
            'dominated' => $dominated->values(),
        ];
    }

    /** @return array<string,mixed>|null */
    private function discoveryMetricsForModel(int $modelVersionId): ?array
    {
        return $this->metricsForModelAndFold($modelVersionId, 2);
    }

    /** @return array<string,mixed> */
    private function stagePartitionEvidence(LabAgent $agent, array $metrics, string $stage): array
    {
        $plan = (array) data_get($agent->modelVersion?->metadata, 'edge_genesis.frozen_window_plan', []);
        if (($plan['protocol'] ?? null) !== EdgeCohortIdentityService::WINDOW_PROTOCOL) {
            return ['protocol' => 'edge_stage_partition_evidence_v1', 'valid' => true,
                'legacy_contract' => true, 'promotion_evidence' => false];
        }
        $expected = (array) data_get($plan, 'stages.'.$stage, []);
        $declared = (array) data_get($metrics, 'edge_genesis_replay', []);
        $windows = $this->discoveryWindowIdentity($metrics);
        $foldCount = (int) ($expected['fold_count'] ?? 0);
        $observed = (int) data_get($metrics, 'forward_window_protocol.observed_windows', count($windows));
        $withinStageIndependent = data_get($metrics, 'forward_window_protocol.independence_verified') === true
            && data_get($metrics, 'forward_window_protocol.overlap_detected') === false
            && count($windows) === $foldCount && $observed === $foldCount;
        $declaredMatch = (string) ($declared['window_plan_hash'] ?? '') !== ''
            && hash_equals((string) $plan['window_plan_hash'], (string) $declared['window_plan_hash'])
            && (int) ($declared['fold_count'] ?? 0) === $foldCount
            && (int) ($declared['fold_offset'] ?? -1) === (int) ($expected['offset'] ?? -2)
            && (int) ($declared['fold_universe_count'] ?? 0) === (int) ($plan['universe_folds'] ?? 0);
        $priorStages = match ($stage) {
            'three_fold_confirmation' => ['two_fold_discovery' => 2],
            'nine_fold_authority' => ['two_fold_discovery' => 2, 'three_fold_confirmation' => 3],
            default => [],
        };
        $trialEvidence = (array) json_decode((string) (DB::table('edge_genesis_trials')
            ->where('lab_agent_id', $agent->id)->value('evidence') ?? '{}'), true);
        $priorWindows = [];
        $priorComplete = true;
        foreach ($priorStages as $priorStage => $priorFoldCount) {
            $historyWindows = array_values(array_filter((array) data_get(
                $trialEvidence, 'stage_history.'.$priorStage.'.window_identity', []
            ), fn ($identity): bool => is_string($identity) && $identity !== '|'));
            if ($historyWindows !== []) {
                $priorWindows = [...$priorWindows, ...$historyWindows];

                continue;
            }
            $priorMetrics = $this->metricsForModelAndFold((int) $agent->model_version_id, $priorFoldCount);
            if (! $priorMetrics) {
                $priorComplete = false;

                continue;
            }
            $priorWindows = [...$priorWindows, ...$this->discoveryWindowIdentity($priorMetrics)];
        }
        $crossStageOverlap = array_values(array_intersect($windows, array_values(array_unique($priorWindows))));
        $crossStageIndependent = $priorStages === [] || ($priorComplete && $crossStageOverlap === []);

        return [
            'protocol' => 'edge_stage_partition_evidence_v1',
            'stage' => $stage,
            'valid' => $declaredMatch && $withinStageIndependent && $crossStageIndependent,
            'window_plan_hash' => $plan['window_plan_hash'] ?? null,
            'expected_fold_count' => $foldCount,
            'observed_fold_count' => $observed,
            'expected_offset' => $expected['offset'] ?? null,
            'declared_offset' => $declared['fold_offset'] ?? null,
            'window_identity' => $windows,
            'within_stage_independent' => $withinStageIndependent,
            'prior_stage_evidence_complete' => $priorComplete,
            'cross_stage_overlap_count' => count($crossStageOverlap),
            'cross_stage_independent' => $crossStageIndependent,
            'discovery_and_replication_authority' => false,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string,mixed>|null */
    private function metricsForModelAndFold(int $modelVersionId, int $foldCount): ?array
    {
        $row = DB::table('model_market_performance')->where('model_version_id', $modelVersionId)
            ->where('evidence_status', 'valid')->where('rolling_windows_count', $foldCount)->latest('id')->first(['metrics']);
        if ($row) {
            $metrics = is_string($row->metrics) ? json_decode($row->metrics, true) : $row->metrics;
            if (is_array($metrics)) {
                return $metrics;
            }
        }

        // The market-performance row is a current projection and may be
        // updated by a later nine-fold replay. LabEvaluationRun is immutable,
        // so it remains the canonical source for the original discovery
        // comparison and prevents a late sparse arm from losing its control.
        $runs = DB::table('lab_evaluation_runs')->where('model_version_id', $modelVersionId)
            ->where('phase', 'full_validation')->where('status', 'completed')
            ->latest('id')->limit(20)->get(['metrics']);
        foreach ($runs as $run) {
            $envelope = is_string($run->metrics) ? json_decode($run->metrics, true) : $run->metrics;
            $metrics = (array) data_get($envelope, 'agent_result', $envelope);
            if ((int) data_get($metrics, 'rolling_windows_count', 0) === $foldCount
                && $this->discoveryWindowIdentity($metrics) !== []) {
                return $metrics;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function discoveryWindowIdentity(array $metrics): array
    {
        $windows = (array) data_get($metrics, 'forward_window_protocol.windows',
            data_get($metrics, 'walk_forward.forward_window_protocol.windows',
                data_get($metrics, 'walk_forward.windows', [])));

        return collect($windows)->map(function ($window): string {
            $start = data_get($window, 'start', data_get($window, 'periods.forward.0', ''));
            $end = data_get($window, 'end', data_get($window, 'periods.forward.1', ''));

            return (string) $start.'|'.(string) $end;
        })->filter(fn (string $identity): bool => $identity !== '|')->values()->all();
    }

    /** @return array<string,mixed> */
    private function pairedWindowEffect(array $full, array $alternative): array
    {
        $extract = function (array $metrics): array {
            $windows = (array) data_get($metrics, 'forward_window_protocol.windows',
                data_get($metrics, 'walk_forward.forward_window_protocol.windows',
                    data_get($metrics, 'walk_forward.windows', [])));

            return collect($windows)->mapWithKeys(function ($window): array {
                $start = (string) data_get($window, 'start', data_get($window, 'periods.forward.0', ''));
                $end = (string) data_get($window, 'end', data_get($window, 'periods.forward.1', ''));
                if ($start === '' || $end === '') {
                    return [];
                }

                return [$start.'|'.$end => (float) data_get($window, 'net_profit_percent',
                    data_get($window, 'results.forward.net_profit_percent', 0))];
            })->all();
        };
        $fullWindows = $extract($full);
        $alternativeWindows = $extract($alternative);
        $identities = array_values(array_intersect(array_keys($fullWindows), array_keys($alternativeWindows)));
        $deltas = collect($identities)->map(fn (string $identity): float => round((float) $fullWindows[$identity] - (float) $alternativeWindows[$identity], 6));

        return [
            'comparable_windows' => count($identities),
            'positive_windows' => $deltas->filter(fn (float $delta): bool => $delta > 0)->count(),
            'negative_windows' => $deltas->filter(fn (float $delta): bool => $delta < 0)->count(),
            'tie_windows' => $deltas->filter(fn (float $delta): bool => $delta === 0.0)->count(),
            'mean_delta_percent' => $deltas->isEmpty() ? null : round((float) $deltas->avg(), 6),
            'window_identity_hash' => hash('sha256', json_encode($identities, JSON_UNESCAPED_SLASHES)),
            'promotion_evidence' => false,
        ];
    }

    private function retireDominatedContextTrials($rows): void
    {
        DB::transaction(function () use ($rows): void {
            foreach ($rows as $candidate) {
                $trial = DB::table('edge_genesis_trials')->where('id', $candidate->trial_id)->lockForUpdate()->first();
                if (! $trial || ! in_array((string) $trial->status, ['edge_progressing', 'queued'], true)) {
                    continue;
                }
                $evidence = (array) json_decode((string) $trial->evidence, true);
                $evidence['authority_selection'] = $candidate->authority_selection;
                DB::table('edge_genesis_trials')->where('id', $trial->id)->update([
                    'stage' => 'two_fold_discovery', 'status' => 'edge_not_found', 'settled_at' => now(),
                    'evidence' => json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                    'updated_at' => now(),
                ]);
                $agent = LabAgent::query()->find((int) $candidate->agent_id);
                if (! $agent) {
                    continue;
                }
                $fromStatus = (string) $agent->lifecycle_status;
                $agent->update([
                    'lifecycle_status' => 'rejected',
                    'decision_reason' => 'Context treatment retired: paired frozen-window discovery did not improve its exact unfiltered control.',
                ]);
                app(LabImmutableEvidenceService::class)->recordLifecycle(
                    $agent->fresh(), 'edge_context_discovery_dominated', $candidate->authority_selection,
                    'two_fold_discovery', null, null, self::class, null, $fromStatus, 'rejected',
                );
            }
        });
    }

    private function recordApprovedContextTrials($rows): void
    {
        foreach ($rows as $candidate) {
            $trial = DB::table('edge_genesis_trials')->where('id', $candidate->trial_id)->first();
            if (! $trial) {
                continue;
            }
            $evidence = (array) json_decode((string) $trial->evidence, true);
            $evidence['authority_selection'] = $candidate->authority_selection;
            DB::table('edge_genesis_trials')->where('id', $trial->id)->update([
                'evidence' => json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                'updated_at' => now(),
            ]);
        }
    }

    private function settleDiscoveryControlsWithoutTreatment($rows): void
    {
        DB::transaction(function () use ($rows): void {
            $passportIds = collect();
            foreach ($rows as $candidate) {
                $trial = DB::table('edge_genesis_trials')->where('id', $candidate->trial_id)->lockForUpdate()->first();
                if (! $trial || (string) $trial->status !== 'edge_progressing'
                    || (string) $trial->stage !== 'two_fold_discovery') {
                    continue;
                }
                $evidence = (array) json_decode((string) $trial->evidence, true);
                $evidence['authority_selection'] = [
                    'protocol' => 'paired_discovery_authority_budget_v1',
                    'decision' => 'control_settled_without_authority',
                    'reason' => 'NO_TREATMENT_OUTPERFORMED_EXACT_CONTROL',
                    'authority_granted' => false,
                    'nine_fold_replay_admitted' => false,
                    'promotion_evidence' => false,
                ];
                DB::table('edge_genesis_trials')->where('id', $trial->id)->update([
                    'status' => 'control_settled', 'settled_at' => now(),
                    'evidence' => json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                    'updated_at' => now(),
                ]);
                $passportIds->push((int) $trial->edge_genesis_passport_id);
                $agent = LabAgent::query()->find((int) $candidate->agent_id);
                if ($agent && $agent->lifecycle_status !== 'rejected') {
                    $agent->update(['lifecycle_status' => 'rejected',
                        'decision_reason' => 'Discovery control settled without authority because no paired treatment outperformed it.']);
                }
            }
            if ($passportIds->isNotEmpty()) {
                DB::table('edge_genesis_passports')->whereIn('id', $passportIds->unique())->update([
                    'status' => 'edge_not_found', 'phase_changed_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    private function repairableAdmissionQuarantine(LabAgent $agent): bool
    {
        if (data_get($agent->modelVersion?->metadata, 'edge_genesis.protocol') !== self::PROTOCOL) {
            return false;
        }
        $errors = array_values(array_unique((array) data_get($agent->modelVersion?->metadata, 'preflight_quarantine.errors', [])));
        sort($errors);
        if ($errors === ['ZERO_DIFF_INVARIANT_FAILED']) {
            return $this->isCompiledControlNumericRepresentationQuarantine($agent);
        }
        if ($errors === ['FULL_REPLAY_DATASET_COVERAGE_INSUFFICIENT']) {
            $generation = $agent->generation;

            return in_array(data_get($agent->modelVersion?->metadata, 'edge_genesis.architecture_revision'), [self::CONFIRMATION_REPAIR_REVISION, self::TRIGGER_REPAIR_REVISION, self::LATENT_HARVEST_REVISION, self::CONTEXT_ROUTER_REPAIR_REVISION, self::REGIME_ENTRY_SYNTHESIS_REVISION, self::FAILURE_CELL_FACTORIAL_REVISION, self::SPECIALIST_DENSIFICATION_REVISION, self::TEMPORAL_BREAKOUT_BINDING_REVISION, self::M15_SETUP_QUALITY_REVISION], true)
                && $generation !== null
                && $this->canonicalDatasetSnapshotsValid(
                    (array) data_get($generation->trigger_context, 'canonical_dataset_snapshots', []),
                    (string) data_get($agent->modelVersion?->metadata, 'edge_genesis.data_hash', ''),
                )
                && ! $agent->modelVersion?->marketPerformances()->exists();
        }
        $known = ['FULL_REPLAY_EXECUTION_HASH_MISSING_OR_INVALID', 'SEMANTIC_GROUP_NOT_DECLARED'];
        sort($known);
        if ($errors !== $known) {
            return false;
        }
        $contract = (array) data_get($agent->modelVersion?->metadata, 'edge_genesis', []);
        $passport = DB::table('edge_genesis_passports')->where('genesis_key', data_get($contract, 'genesis_key'))->first();
        $expected = $this->executionContracts->for($agent->symbol, self::EXECUTION_TIMEFRAME);

        return $passport
            && hash_equals((string) $passport->execution_hash, (string) data_get($contract, 'execution_hash', ''))
            && hash_equals((string) $passport->execution_hash, (string) $expected['execution_hash'])
            && hash_equals((string) $passport->data_hash, (string) data_get($contract, 'data_hash', ''));
    }

    private function isCompiledControlNumericRepresentationQuarantine(LabAgent $agent): bool
    {
        $model = $agent->modelVersion;
        $contract = (array) data_get($model?->metadata, 'edge_genesis', []);
        $attestation = (array) data_get($contract, 'intervention_attestation', []);
        $errors = array_values(array_unique((array) data_get($model?->metadata, 'preflight_quarantine.errors', [])));
        sort($errors);
        if (! $model
            || $agent->lifecycle_status !== 'technical_quarantine'
            || $model->evidence_status !== 'stale_quarantine'
            || $model->invalidation_reason !== 'strict_lab_agent_preflight_failed'
            || $errors !== ['ZERO_DIFF_INVARIANT_FAILED']
            || data_get($contract, 'architecture_revision') !== self::EVIDENCE_COMPILED_REVISION
            || data_get($contract, 'arm') !== 'compiled_control'
            || data_get($attestation, 'protocol') !== 'edge_genesis_intervention_attestation_v1'
            || data_get($attestation, 'control_identity') !== true
            || $model->marketPerformances()->exists()) {
            return false;
        }
        $baselineId = (int) data_get($contract, 'causal_baseline_model_version_id', 0);
        $baseline = $baselineId > 0 ? ModelVersion::query()->find($baselineId) : null;
        if (! $baseline || $this->diff((array) $baseline->parameters, (array) $model->parameters) !== []) {
            return false;
        }
        $declaredDiff = (array) $agent->parameter_diff;
        if ($declaredDiff === [] || ! collect($declaredDiff)->every(fn (mixed $change): bool => is_array($change)
            && array_key_exists('old', $change) && array_key_exists('new', $change)
            && $this->parameterValuesEquivalent($change['old'], $change['new']))) {
            return false;
        }
        $passport = DB::table('edge_genesis_passports')->where('genesis_key', data_get($contract, 'genesis_key'))->first();

        return $passport
            && hash_equals((string) $passport->data_hash, (string) data_get($contract, 'data_hash', ''))
            && hash_equals((string) $passport->execution_hash, (string) data_get($contract, 'execution_hash', ''));
    }

    private function repairableEdgeRuntimeFailure(LabAgent $agent): bool
    {
        if (! in_array($agent->lifecycle_status, ['evaluation_error', 'technical_quarantine'], true)
            || data_get($agent->modelVersion?->metadata, 'edge_genesis.protocol') !== self::PROTOCOL) {
            return false;
        }
        $reason = $this->edgeRuntimeFailureReason($agent);
        if ($reason === null) {
            return false;
        }
        $performances = $agent->modelVersion?->marketPerformances()->where('evidence_status', 'valid')->get() ?? collect();
        if ($performances->isEmpty()) {
            return true;
        }
        $authorityQueued = DB::table('edge_genesis_trials')->where('lab_agent_id', $agent->id)
            ->where('stage', 'nine_fold_authority')->where('status', 'queued')->exists();

        return $reason === 'EDGE_CONTEXT_TELEMETRY_SCOPE_INITIALIZATION_REPAIRED'
            && $authorityQueued
            // v2 replaces the discovery projection with its independent
            // three-fold confirmation before authority dispatch. Legacy
            // queued cohorts may still carry the former two-fold projection.
            && $performances->every(fn ($performance): bool => in_array(
                (int) $performance->rolling_windows_count, [2, 3], true
            ));
    }

    private function edgeRuntimeFailureReason(LabAgent $agent): ?string
    {
        $run = DB::table('lab_evaluation_runs')->where('lab_agent_id', $agent->id)
            ->latest('id')->first(['status', 'error_message']);
        if (! $run || $run->status !== 'technical_error') {
            return null;
        }
        $message = (string) $run->error_message;
        if (str_contains($message, 'Causal confirmation fold')
            && preg_match('/exceeded its \d+s budget/', $message) === 1
            && str_contains($message, 'no learning credit was emitted')) {
            // Fold budgets are intentionally configurable and have evolved
            // from 90s to 180s. Recovery is keyed to the fail-closed protocol,
            // not one historical duration. No performance row may exist.
            return 'EDGE_DISCOVERY_BOUNDED_FOLD_TIMEOUT_RETRY';
        }
        if (str_contains($message, 'Rolling walk-forward uchun kamida 3 ta oyna')) {
            return 'EDGE_ROW_WINDOW_PARTITION_OFF_BY_ONE_REPAIRED';
        }
        if (str_contains($message, "UnboundLocalError: cannot access local variable 'context_declared'")) {
            return 'EDGE_CONTEXT_TELEMETRY_SCOPE_INITIALIZATION_REPAIRED';
        }

        return null;
    }

    private function repairAdmissionMetadata(LabAgent $agent): void
    {
        $model = $agent->modelVersion;
        if (! $model || ! $this->repairableAdmissionQuarantine($agent)) {
            return;
        }
        $metadata = (array) $model->metadata;
        $errors = array_values(array_unique((array) data_get($metadata, 'preflight_quarantine.errors', [])));
        sort($errors);
        $contract = (array) data_get($metadata, 'edge_genesis', []);
        if ($this->isCompiledControlNumericRepresentationQuarantine($agent)) {
            $baseline = ModelVersion::query()->findOrFail((int) data_get($contract, 'causal_baseline_model_version_id'));
            $priorAttestation = (array) data_get($contract, 'intervention_attestation', []);
            $canonicalHash = $this->parameterHash((array) $baseline->parameters);
            $history = (array) data_get($metadata, 'admission_metadata_recovery_history', []);
            $history[] = [
                'protocol' => 'edge_genesis_numeric_control_identity_recovery_v1',
                'source_errors' => $errors,
                'prior_parameter_diff' => (array) $agent->parameter_diff,
                'prior_source_parameter_hash' => data_get($priorAttestation, 'source_parameter_hash'),
                'prior_consumed_parameter_hash' => data_get($priorAttestation, 'consumed_parameter_hash'),
                'parameters_unchanged' => true,
                'passport_hashes_unchanged' => true,
                'recorded_at' => now()->utc()->toIso8601String(),
                'promotion_evidence' => false,
            ];
            data_set($metadata, 'admission_metadata_recovery_history', $history);
            data_set($metadata, 'edge_genesis.intervention_attestation.source_parameter_hash', $canonicalHash);
            data_set($metadata, 'edge_genesis.intervention_attestation.consumed_parameter_hash', $canonicalHash);
            data_set($metadata, 'edge_genesis.intervention_attestation.actual_parameter_diff', []);
            data_set($metadata, 'preflight_quarantine.classification', 'numeric_representation');
            data_set($metadata, 'preflight_quarantine.restored_at', now()->utc()->toIso8601String());
            data_set($metadata, 'preflight_quarantine.restoration_protocol', 'edge_genesis_numeric_control_identity_recovery_v1');
            $model->update([
                'metadata' => $metadata,
                'evidence_status' => 'valid',
                'invalidated_at' => null,
                'invalidation_reason' => null,
            ]);
            $agent->update(['parameter_diff' => []]);
            $agent->setRelation('modelVersion', $model->fresh());

            return;
        }
        $packet = collect($this->packets())->firstWhere('key', data_get($contract, 'packet_key'));
        if (! is_array($packet)) {
            return;
        }
        $metadata['lab_symbol'] = strtoupper($agent->symbol);
        $metadata['lab_timeframe'] = strtoupper($agent->timeframe);
        $metadata['semantic_group'] = $this->edgeSemanticGroup($agent->symbol, $agent->timeframe, $agent->strategy_family, $packet);
        $metadata['execution_contract'] = $this->executionContracts->for($agent->symbol, self::EXECUTION_TIMEFRAME);
        $metadata['admission_metadata_recovery'] = [
            'protocol' => 'edge_genesis_admission_metadata_recovery_v1',
            'repaired_fields' => $errors === ['FULL_REPLAY_DATASET_COVERAGE_INSUFFICIENT']
                ? ['generation.canonical_dataset_snapshots']
                : ['semantic_group', 'execution_contract'],
            'source_errors' => (array) data_get($metadata, 'preflight_quarantine.errors', []),
            'parameters_unchanged' => true,
            'passport_hashes_unchanged' => true,
            'recorded_at' => now()->utc()->toIso8601String(),
            'promotion_evidence' => false,
        ];
        $model->update([
            'metadata' => $metadata,
            'evidence_status' => 'valid',
            'invalidated_at' => null,
            'invalidation_reason' => null,
        ]);
        $agent->setRelation('modelVersion', $model->fresh());
    }

    /** Translate existing causal transition evidence into Academy curriculum depth. */
    private function academyStageFor(array $assessment): string
    {
        return match ((string) data_get($assessment, 'target_stage', '')) {
            'location' => 'setup_apprentice', 'setup' => 'confirmation_specialist',
            'confirmation', 'trigger' => 'trigger_entry_specialist', 'entry' => 'execution_specialist',
            'closed_trade' => 'management_specialist', default => 'market_cartographer',
        };
    }

    private function diff(array $old, array $new): array
    {
        $out = [];
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) {
            if (! $this->parameterValuesEquivalent($old[$key] ?? null, $new[$key] ?? null)) {
                $out[$key] = ['old' => $old[$key] ?? null, 'new' => $new[$key] ?? null];
            }
        }

return $out;
    }

    private function packets(): array
    {
        return [
            ['key' => 'trend_pullback', 'label' => 'Trend Pullback', 'strategy_id' => 'str_001_ema_adx_pullback', 'tactic_id' => 'trend_pullback', 'management_id' => 'balanced_professional', 'emitter' => 'prior_seed', 'context' => ['regime' => 'trend', 'allowed_regimes' => ['trend_up', 'trend_down'], 'session' => 'liquid_session', 'allowed_sessions' => ['london', 'london_new_york_overlap'], 'volatility' => 'normal', 'allowed_volatility' => ['normal_volatility'], 'enforcement' => 'required', 'admission_axes' => ['regime', 'session', 'volatility'], 'outside_scope' => 'WAIT']],
            ['key' => 'liquidity_reversal', 'label' => 'Liquidity Reversal', 'strategy_id' => 'str_032_choch_reversal', 'tactic_id' => 'liquidity_reversal', 'management_id' => 'balanced_professional', 'emitter' => 'local_recombination', 'context' => ['regime' => 'transition', 'allowed_regimes' => ['transition'], 'session' => 'liquid_session', 'allowed_sessions' => ['london', 'new_york'], 'volatility' => 'normal', 'allowed_volatility' => ['normal_volatility'], 'enforcement' => 'required', 'admission_axes' => ['regime', 'session', 'volatility'], 'outside_scope' => 'WAIT']],
            ['key' => 'break_retest', 'label' => 'Break and Retest', 'strategy_id' => 'str_031_bos_retest', 'tactic_id' => 'break_retest', 'management_id' => 'balanced_professional', 'emitter' => 'temporal_binder', 'context' => ['regime' => 'trend', 'allowed_regimes' => ['trend_up', 'trend_down'], 'session' => 'liquid_session', 'allowed_sessions' => ['london', 'london_new_york_overlap'], 'volatility' => 'normal', 'allowed_volatility' => ['normal_volatility'], 'enforcement' => 'required', 'admission_axes' => ['regime', 'session', 'volatility'], 'outside_scope' => 'WAIT']],
            ['key' => 'range_session_specialist', 'label' => 'Range Session Specialist', 'strategy_id' => 'str_020_bb_rsi_reversion', 'tactic_id' => 'range_reversion', 'management_id' => 'balanced_professional', 'emitter' => 'confirmation_entry', 'context' => ['regime' => 'range', 'allowed_regimes' => ['range'], 'session' => 'liquid_session', 'allowed_sessions' => ['london', 'new_york'], 'volatility' => 'normal', 'allowed_volatility' => ['normal_volatility'], 'enforcement' => 'required', 'admission_axes' => ['regime', 'session', 'volatility'], 'outside_scope' => 'WAIT']],
        ];
    }

    private function recordComputeLedger(int $passportId, string $symbol, string $timeframe, string $phase): void
    {
        foreach (self::EMITTER_BUDGETS as $emitter => $budget) {
            DB::table('edge_genesis_compute_ledgers')->updateOrInsert(['ledger_key' => hash('sha256', implode('|', [self::PROTOCOL, $passportId, $emitter, $phase]))], ['edge_genesis_passport_id' => $passportId, 'symbol' => strtoupper($symbol), 'timeframe' => strtoupper($timeframe), 'emitter' => $emitter, 'phase' => $phase, 'budget_share' => $budget, 'priority' => $budget * ($emitter === 'risk_mutation' && $phase === 'EDGE_DISCOVERY' ? 0 : 1), 'status' => $budget > 0 ? 'allocated' : 'locked', 'evidence' => json_encode(['protocol' => self::PROTOCOL, 'quality_diversity_required' => true, 'information_gain_adaptive_after_settlement' => true, 'promotion_evidence' => false]), 'updated_at' => now(), 'created_at' => now()]);
        }
    }
}
