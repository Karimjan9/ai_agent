<?php

namespace App\Services;

use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\LabSkillZooEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Chooses the next causally useful research cohort without relaxing any
 * promotion, parent, paper or safety gate. One tick may open at most one
 * expensive cohort; all other work is reconciliation or a fail-closed wait.
 */
class AutonomousLearningProgressDirectorService
{
    public const PROTOCOL = 'autonomous_edge_to_mastery_director_v2';

    public function __construct(
        private EdgeToMasteryAdmissionService $admission,
        private LabDatasetExportService $datasets,
        private MultiTimeframeSnapshotService $mtf,
        private ExecutionContractService $execution,
        private DependencyAwareEdgeGenesisFoundryService $edge,
        private CanonicalSkillCartridgeService $cartridges,
        private EvolutionaryAuthorityFoundryService $authority,
        private SettlementWatermarkService $watermarks,
        private LegacyControlDebtFirewallService $legacyDebt,
        private EdgeHypothesisCompilerService $hypotheses,
        private CausalProgressRatchetGovernorService $ratchetGovernor,
    ) {}

    /** @return array<string,mixed> */
    public function advance(string $symbol = 'XAUUSD', string $timeframe = 'H1', bool $apply = false): array
    {
        $symbol = strtoupper(str_replace(['/', '_', '-'], '', trim($symbol)));
        $timeframe = strtoupper(trim($timeframe));
        if ($symbol !== 'XAUUSD' || $timeframe !== 'H1') {
            return $this->blocked('XAUUSD_H1_ORGANISM_SCOPE_REQUIRED');
        }

        $lock = Cache::lock('learning-progress-director:'.$symbol.':'.$timeframe, 300);
        if ($apply && ! $lock->get()) return $this->blocked('DIRECTOR_ALREADY_RUNNING');

        try {
            return $this->advanceLocked($symbol, $timeframe, $apply);
        } finally {
            if ($apply) $lock->release();
        }
    }

    /** @return array<string,mixed> */
    private function advanceLocked(string $symbol, string $timeframe, bool $apply): array
    {
        $lab = AiLaboratory::query()->where('symbol', $symbol)->where('timeframe', $timeframe)->where('is_active', true)->first();
        if (! $lab) return $this->blocked('ACTIVE_LABORATORY_NOT_FOUND');
        if (! $this->tablesReady()) return $this->blocked('EVOLUTION_ARCHITECTURE_MIGRATIONS_NOT_READY');

        $admission = $this->admission->assess($symbol, $timeframe, $apply);
        if (! ($admission['admitted'] ?? false)) {
            return $this->blocked((string) data_get($admission, 'blockers.0', 'EDGE_DIRECTOR_ADMISSION_FAILED'),
                ['admission' => $admission]);
        }
        $activeAgents = LabAgent::query()->where('symbol', $symbol)->where('timeframe', $timeframe)
            ->whereIn('lifecycle_status', ['draft', 'queued', 'screening', 'training', 'full_queued', 'full_validation'])
            // A constructor may preserve partial draft agents inside a
            // terminal technical-quarantine generation for audit. They have
            // no queue/replay authority and must not deadlock the canonical
            // Edge lane forever merely because their local status says draft.
            ->whereHas('generation', fn ($query) => $query->whereIn('status', [
                'draft', 'queued', 'training', 'screening', 'full_queued', 'full_validation',
            ]))
            ->count();
        if ($activeAgents > 0) return $this->blocked('ACTIVE_AGENT_WORK_EXISTS', ['active_agents' => $activeAgents]);

        // This is the central constitutional decision, not a side-channel
        // dashboard. It may stop new exploration, but never blocks exact
        // settlement/replication/attribution work already earned by evidence.
        $governor = $this->ratchetGovernor->allocate($symbol, $timeframe, $apply);

        // Reconcile derived projections before selecting the next cohort.
        // This never repeats a replay or edits immutable economic evidence.
        $reconciliation = [
            'edge_generation_coverage' => $this->edge->reconcileRepairGenerationCoverage($symbol, $timeframe, $apply),
            'edge_execution_timeframe' => $this->edge->reconcileExecutionTimeframeMetadata($symbol, $timeframe, $apply),
            'edge_discovery_verdicts' => $this->edge->reconcileDiscoveryOutcomes($symbol, $timeframe, $apply),
            'edge_authority_selection_evidence' => $this->edge->reconcileAuthoritySelectionEvidence($symbol, $timeframe, $apply),
            'edge_nine_fold_differential_authority' => $this->edge->reconcileNineFoldContextAuthority($symbol, $timeframe, $apply),
            'context_authority_effects' => $this->edge->reconcileContextAuthorityEffects($symbol, $timeframe, $apply),
            'compiled_axis_settlement' => $this->edge->reconcileCompiledHypothesisSettlements($symbol, $timeframe, $apply),
            ...($apply ? [
                'skill_cartridges' => [
                    ...$this->cartridges->reconcileLegacy($symbol, $timeframe),
                    'provisional_confirmation_state' => $this->cartridges->reconcileProvisionalConfirmationState($symbol, $timeframe),
                    'revision_backfill' => $this->cartridges->backfillImmutableRevisions($symbol, $timeframe),
                ],
                'settlement_watermark' => $this->watermarks->reconcile($symbol, $timeframe),
                'legacy_control_debt' => $this->legacyDebt->reconcile($symbol, $timeframe),
            ] : ['status' => 'dry_run_not_mutated']),
            'causal_progress_governor' => $governor,
        ];

        $pendingEdge = $this->edge->resumePendingTrials($symbol, $timeframe, $apply);
        if (in_array(($pendingEdge['status'] ?? null), ['queued', 'would_queue'], true)) {
            return $this->result('EDGE_DISCOVERY_RESUME', $pendingEdge, $reconciliation, $apply);
        }

        $replication = $this->edge->materializeIndependentReplication($symbol, $timeframe, $apply);
        if (in_array(($replication['status'] ?? null), ['queued', 'would_queue'], true)) {
            return $this->result('EDGE_INDEPENDENT_REPLICATION', $replication, $reconciliation, $apply);
        }

        $confirmation = $this->edge->materializeConfirmation($symbol, $timeframe, $apply);
        if (in_array(($confirmation['status'] ?? null), ['queued', 'would_queue'], true)) {
            return $this->result('EDGE_CONFIRMATION', $confirmation, $reconciliation, $apply);
        }

        $attributionAgent = $this->nextAttributionAgent($symbol, $timeframe);
        if ($attributionAgent) {
            $result = $apply
                ? $this->edge->materializeAttribution($attributionAgent)
                : ['status' => 'would_queue', 'agent_id' => $attributionAgent->id];
            return $this->result('EDGE_ATTRIBUTION', $result, $reconciliation, $apply);
        }

        // Do not make component confirmation wait for a whole-organism Edge
        // passport. A two-positive, no-negative cartridge is precisely the
        // research evidence that can rescue a useful categorical/topological
        // component from an otherwise non-viable baseline.
        $provisional = $this->nextProvisionalCartridgeConfirmation($symbol, $timeframe);
        if ($provisional) {
            $baselineModelId = (int) LabAgent::query()->find($provisional->causal_baseline_agent_id)?->model_version_id;
            $result = ! $apply
                ? ['status' => 'would_queue', 'cartridge_id' => $provisional->id, 'baseline_model_version_id' => $baselineModelId,
                    'protocol' => CanonicalSkillCartridgeService::PROTOCOL, 'research_only' => true, 'promotion_evidence' => false]
                : ($baselineModelId > 0
                    ? $this->cartridges->materializeTransplant($provisional, $baselineModelId, ['confirmation_lane' => 'two_positive_independent_five_arm'], true)
                    : ['status' => 'blocked', 'reason' => 'CAUSAL_BASELINE_MODEL_MISSING', 'promotion_evidence' => false]);
            return $this->result('PROVISIONAL_SKILL_CARTRIDGE_CONFIRMATION', $result, $reconciliation, $apply);
        }

        $passports = DB::table('edge_genesis_passports')->where('symbol', $symbol)->where('timeframe', $timeframe);
        $passportCount = (clone $passports)->count();
        $edgeEstablished = (clone $passports)->whereIn('phase', [
            'EDGE_ATTRIBUTION', 'RISK_SHAPING', 'MANAGEMENT_OPTIMIZATION', 'PAPER_VALIDATION',
        ])->exists();
        if ($edgeEstablished) {
            $mentor = $this->nextConfirmedMentor($symbol, $timeframe);
            if ($mentor) {
                $result = $apply
                    ? $this->authority->materializeIncubator($mentor)
                    : ['status' => 'would_queue', 'mentor_agent_id' => $mentor->id];
                if (($result['status'] ?? null) !== 'already_materialized') {
                    return $this->result('AUTHORITY_INCUBATOR', $result, $reconciliation, $apply);
                }
            }

            $cartridge = collect($this->cartridges->rankedForReplay($symbol, $timeframe, 20))
                ->first(fn (LabSkillZooEntry $entry): bool => ! $this->hasTransplant($entry));
            if ($cartridge) {
                $baselineModelId = (int) LabAgent::query()->find($cartridge->causal_baseline_agent_id)?->model_version_id;
                $result = ! $apply
                    ? ['status' => 'would_queue', 'cartridge_id' => $cartridge->id, 'baseline_model_version_id' => $baselineModelId]
                    : ($baselineModelId > 0
                        ? $this->cartridges->materializeTransplant($cartridge, $baselineModelId, [], true)
                        : ['status' => 'blocked', 'reason' => 'CAUSAL_BASELINE_MODEL_MISSING']);
                return $this->result('SKILL_CARTRIDGE_TRANSPLANT', $result, $reconciliation, $apply);
            }
        }

        if ($passportCount > 0) {
            $activePassport = (clone $passports)->whereIn('status', ['queued', 'running'])->exists();
            $hypothesis = null;
            if (! $activePassport && ! $edgeEstablished) {
                if ((bool) data_get($governor, 'debt.high_value_debt', false)) {
                    return $this->blocked('CAUSAL_GOVERNOR_CONSOLIDATION_REQUIRED', [
                        'governor' => $governor, 'reconciliation' => $reconciliation,
                        'consolidation' => $this->ratchetGovernor->consolidationPlan($symbol, $timeframe, $apply),
                    ]);
                }
                $repairReadiness = $this->edge->architectureRepairReadiness($symbol, $timeframe);
                if (($repairReadiness['admitted'] ?? false) === true) {
                    $result = $this->edge->materializeNextArchitectureRepair($lab, $apply);
                    return $this->result('EDGE_ARCHITECTURE_REPAIR', $result, $reconciliation, $apply);
                }
                $hypothesis = $this->hypotheses->compile($symbol, $timeframe);
                if (($hypothesis['admitted'] ?? false) === true) {
                    $hypothesis['director_admission_snapshot'] = $this->admissionSnapshot($admission);
                    $result = $this->edge->materializeCompiledHypothesis($lab, $hypothesis, $apply);
                    return $this->result('EDGE_HYPOTHESIS_COMPILED', $result, $reconciliation, $apply);
                }
            }
            $compilerReason = is_array($hypothesis)
                ? (string) ($hypothesis['reason'] ?? 'UNKNOWN_COMPILER_BLOCK')
                : null;
            $blockedReason = $activePassport
                ? 'EDGE_GENESIS_SETTLEMENT_INCOMPLETE'
                : ($compilerReason === 'COMPILED_HYPOTHESIS_ISLANDS_EXHAUSTED'
                    ? 'EDGE_HYPOTHESIS_ISLANDS_EXHAUSTED'
                    : 'EDGE_HYPOTHESIS_COMPILER_BLOCKED');

            return $this->blocked($blockedReason, [
                'passports' => $passportCount,
                'edge_established' => $edgeEstablished,
                'reconciliation' => $reconciliation,
                'architecture_repair' => $this->edge->architectureRepairReadiness($symbol, $timeframe),
                'compiler_reason' => $compilerReason,
                'hypothesis_compiler' => $hypothesis,
                'next_required' => $edgeEstablished
                    ? 'confirmed component or mentor evidence'
                    : ($compilerReason === 'COMPILED_HYPOTHESIS_ISLANDS_EXHAUSTED'
                        ? 'new professional strategy/tactic island or a versioned compiler policy'
                        : 'resolve the compiler admission reason, then issue one non-duplicate architecture packet'),
            ]);
        }

        $plateau = $this->hypotheses->initialGenesisReadiness($lab);
        if ((bool) data_get($governor, 'debt.high_value_debt', false)) {
            return $this->blocked('CAUSAL_GOVERNOR_CONSOLIDATION_REQUIRED', [
                'governor' => $governor, 'reconciliation' => $reconciliation,
                'consolidation' => $this->ratchetGovernor->consolidationPlan($symbol, $timeframe, $apply),
            ]);
        }
        if (! ($plateau['admitted'] ?? false)) {
            return $this->blocked('SCALAR_REPAIR_PLATEAU_NOT_PROVEN', [
                'plateau' => $plateau, 'reconciliation' => $reconciliation,
            ]);
        }

        $readiness = $this->mtf->agentValidationReadiness($symbol);
        if (($readiness['ready'] ?? false) !== true) {
            return $this->blocked('PRE_2026_MTF_FOUNDATION_NOT_READY', ['mtf_readiness' => $readiness, 'reconciliation' => $reconciliation]);
        }
        if (! $apply) {
            return $this->result('EDGE_GENESIS', [
                'status' => 'would_queue', 'packets' => 4, 'arms_per_packet' => 5,
                'execution_timeframe' => DependencyAwareEdgeGenesisFoundryService::EXECUTION_TIMEFRAME,
            ], $reconciliation, false);
        }

        // This call validates the immutable archive byte-for-byte. It reuses
        // an existing valid snapshot and only repairs/builds it when the
        // canonical foundation service itself can satisfy every data gate.
        $foundation = $this->datasets->ensureFoundationDataset($symbol, $timeframe);
        // Freeze the complete M5/M15/H1/H4 organism once for the whole
        // generation. Per-agent freezing while an archive is backfilling
        // destroys paired-control identity even when every file is valid.
        $mtfBundle = $this->mtf->forAgentOwnedConfirmationValidation($symbol);
        $execution = $this->execution->for($symbol, DependencyAwareEdgeGenesisFoundryService::EXECUTION_TIMEFRAME);
        $last = data_get($foundation, 'manifest.last_candle_at', data_get($foundation, 'manifest.foundation_end'));
        $cutoff = CarbonImmutable::parse((string) config('services.lab_selection.training_end_exclusive', '2026-01-01 00:00:00'), 'UTC');
        $pre2026 = filled($last) && CarbonImmutable::parse((string) $last, 'UTC')->lt($cutoff)
            && data_get($foundation, 'manifest.source_role') === 'foundation_training_only'
            && data_get($foundation, 'manifest.promotion_evidence') === false;
        if (! $pre2026) return $this->blocked('PRE_2026_FOUNDATION_ATTESTATION_FAILED', ['last_candle_at' => $last]);

        // Seal both sides of the temporal partition before any Edge job is
        // dispatched. Foundation is pre-2026 training evidence; the rolling
        // 2026 export is paper-only coverage and can never enter fitness.
        $paperPath = $this->datasets->exportPaper($symbol, $timeframe, false);
        $paperManifestPath = $paperPath.'.manifest.json';
        $paperManifest = is_file($paperManifestPath)
            ? (array) json_decode(File::get($paperManifestPath), true)
            : [];
        $paperSha = is_file($paperPath) ? hash_file('sha256', $paperPath) : false;
        if (! is_string($paperSha) || $paperManifest === []) {
            return $this->blocked('PAPER_ONLY_COVERAGE_SNAPSHOT_SEAL_FAILED');
        }
        $canonicalSnapshots = [
            'foundation' => [
                'protocol' => (string) ($foundation['protocol'] ?? 'foundation_training_archive_v1'),
                'path' => (string) ($foundation['path'] ?? ''),
                'manifest' => (array) ($foundation['manifest'] ?? []),
                'sha256' => (string) ($foundation['sha256'] ?? ''),
                'promotion_evidence' => false,
            ],
            'price' => [
                'protocol' => 'lab_generation_pre_dispatch_coverage_v1',
                'path' => $paperPath,
                'manifest_path' => $paperManifestPath,
                'manifest' => $paperManifest,
                'sha256' => $paperSha,
                'promotion_evidence' => false,
            ],
        ];
        $result = $this->edge->materialize(
            $lab,
            (string) $foundation['sha256'],
            (string) $execution['execution_hash'],
            true,
            $mtfBundle,
            DependencyAwareEdgeGenesisFoundryService::INITIAL_REVISION,
            $canonicalSnapshots,
        );
        return $this->result('EDGE_GENESIS', $result, $reconciliation, true);
    }

    private function nextAttributionAgent(string $symbol, string $timeframe): ?LabAgent
    {
        return LabAgent::query()->with(['modelVersion', 'generation.laboratory'])->where('symbol', $symbol)->where('timeframe', $timeframe)
            ->where('origin', 'edge_genesis')->latest('id')->get()
            ->first(function (LabAgent $agent): bool {
                if (data_get($agent->modelVersion?->metadata, 'edge_genesis.phase') !== 'EDGE_ATTRIBUTION') return false;
                $passportId = DB::table('edge_genesis_passports')
                    ->where('genesis_key', data_get($agent->modelVersion?->metadata, 'edge_genesis.genesis_key'))
                    ->value('id');
                return $passportId && ! DB::table('edge_genesis_trials')->where('edge_genesis_passport_id', $passportId)
                    ->where('packet_key', data_get($agent->modelVersion?->metadata, 'edge_genesis.packet_key').':attribution')->exists();
            });
    }

    private function nextConfirmedMentor(string $symbol, string $timeframe): ?LabAgent
    {
        return LabAgent::query()->with(['modelVersion', 'generation.laboratory', 'parentA'])
            ->where('symbol', $symbol)->where('timeframe', $timeframe)->latest('id')->limit(500)->get()
            ->first(function (LabAgent $agent): bool {
                if (data_get($agent->modelVersion?->metadata, 'skill_mentor.status') !== 'confirmed') return false;
                return ! DB::table('skill_incubation_trials')->where('mentor_model_version_id', $agent->model_version_id)
                    ->whereIn('status', ['queued', 'running', 'passed'])->exists();
            });
    }

    private function hasTransplant(LabSkillZooEntry $entry): bool
    {
        return DB::table('skill_cartridge_transplant_trials')->where('lab_skill_zoo_entry_id', $entry->id)
            ->whereIn('status', ['queued', 'running', 'settled_control', 'passed'])->exists();
    }

    /** A strict non-promoting admission for the missing third observation. */
    private function nextProvisionalCartridgeConfirmation(string $symbol, string $timeframe): ?LabSkillZooEntry
    {
        return LabSkillZooEntry::query()->where('symbol', $symbol)->where('timeframe', $timeframe)
            ->where('status', 'provisional')->orderByDesc('confidence')->orderByDesc('quality_score')->get()
            ->first(function (LabSkillZooEntry $entry): bool {
                if ($this->hasTransplant($entry)) return false;
                $observations = DB::table('skill_cartridge_observations')->where('lab_skill_zoo_entry_id', $entry->id);
                $positive = (clone $observations)->where('outcome', 'positive')->count();
                $negative = (clone $observations)->where('outcome', 'negative')->count();
                $intervention = (array) data_get($entry->evidence, 'intervention', []);

                // The independent window is deliberately created by the
                // pending confirmation cohort; requiring it before dispatch
                // would recreate the observed two-positive deadlock.
                return $positive >= 2 && $negative === 0
                    && ($intervention['tested_value'] ?? null) !== null
                    && json_encode($intervention['old_value'] ?? null) !== json_encode($intervention['tested_value'] ?? null)
                    && count((array) data_get($entry->evidence, 'contraindications', [])) === 0;
            });
    }

    private function tablesReady(): bool
    {
        return collect(['edge_genesis_passports', 'edge_genesis_trials', 'full_stack_playbook_passports',
            'skill_cartridge_transplant_trials', 'skill_incubation_trials', 'edge_hypothesis_packets',
            'edge_genesis_cohorts'])
            ->every(fn (string $table): bool => Schema::hasTable($table));
    }

    /** @return array<string,mixed> */
    private function admissionSnapshot(array $admission): array
    {
        return [
            'protocol' => data_get($admission, 'protocol'),
            'admitted' => data_get($admission, 'admitted'),
            'checked_at' => now()->utc()->toIso8601String(),
            'queue' => data_get($admission, 'queue'),
            'retry_storm' => data_get($admission, 'retry_storm'),
            'ai_replay_idle' => data_get($admission, 'ai_replay_idle'),
            'idle_stability' => data_get($admission, 'idle_stability'),
            'failure_dojo' => data_get($admission, 'failure_dojo'),
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string,mixed> */
    private function result(string $action, array $result, array $reconciliation, bool $apply): array
    {
        return ['protocol' => self::PROTOCOL, 'status' => $apply ? 'advanced' : 'dry_run', 'action' => $action,
            'expensive_cohorts_opened' => $apply && ($result['status'] ?? null) === 'queued' ? 1 : 0,
            'result' => $result, 'reconciliation' => $reconciliation, 'promotion_evidence' => false];
    }

    /** @return array<string,mixed> */
    private function blocked(string $reason, array $context = []): array
    {
        return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason' => $reason,
            'expensive_cohorts_opened' => 0, ...$context, 'promotion_evidence' => false];
    }
}
