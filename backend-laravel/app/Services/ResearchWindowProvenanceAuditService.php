<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/** Read-only exclusion evidence and draft planning; never a research authorization. */
class ResearchWindowProvenanceAuditService
{
    public const PROTOCOL = 'bounded_research_window_provenance_audit_v1';

    public const RESERVATION_PROTOCOL = 'research_window_preregistration_draft_v1';

    /** Collection planning only; actual bytes, original design and authorization remain absent. */
    public function futureSchedule(): array
    {
        $identity = ['protocol' => 'continuous_paper_policy_no_future_research_v2',
            'symbol' => 'XAUUSD', 'timezone' => 'UTC', 'windows' => [],
            'source_design_selected' => false, 'original_preregistration_persisted' => false,
            'paper_only_policy' => app(ResearchPaperEpochContractService::class)->paperOnlyPolicy(),
            'earlier_paper_warmup_eligible' => false, 'paper_2026_research_eligible' => false,
            'executable' => false, 'independent_evidence' => false, 'promotion_evidence' => false,
            'server_authorization_created' => false, 'data_writes' => false,
            'status' => 'blocked_by_continuous_paper_policy',
            'reason_code' => 'ALL_DATA_FROM_2026_RESERVED_FOR_PAPER'];
        return [...$identity, 'schedule_hash' => $this->hash($identity), 'observed_at' => now()->utc()->toIso8601String()];
    }

    public function audit(?string $candidateStart = null, ?string $candidateEnd = null): array
    {
        $candidate = $this->candidate($candidateStart, $candidateEnd);
        $missing = [];
        $phases = []; $ranges = []; $inputRanges = []; $inputSummary = []; $highWater = 0;
        if (! Schema::hasTable('lab_evaluation_runs')) {
            $missing[] = 'LAB_EVALUATION_RUN_INVENTORY_MISSING';
        } else {
            // A fixed high water makes this bounded read reproducible while workers progress.
            $highWater = (int) DB::table('lab_evaluation_runs')->max('id');
            $inventory = app(ResearchRequestReferenceInventoryService::class)->inventory($highWater, $candidate);
            $phases = $inventory['phases'];
            $ranges = $inventory['ranges'];
            $inputRanges = $inventory['input_ranges'];
            $inputSummary = $inventory['input_summary'];
            $missing = array_merge($missing, $inventory['missing']);
        }
        // Request references are exclusion evidence, not proof of all candles actually consumed.
        // Neither missing JSON keys nor an absent run demonstrates unused training/selection data.
        $missing[] = 'ORIGINAL_TRAINING_AND_SELECTION_EXPOSURE_INVENTORY_NOT_ATTESTED';
        $missing[] = 'ORIGINAL_MTF_CONTEXT_EXPOSURE_INVENTORY_NOT_ATTESTED';
        $missing[] = 'ORIGINAL_WARMUP_AND_HOLDING_EXPOSURE_NOT_ATTESTED';
        $missing[] = 'ORIGINAL_EXPOSURE_RECEIPT_BYTES_NOT_REVALIDATED';
        $owners = [];
        foreach (['research_experiment_work_items', 'edge_academy_trials'] as $table) {
            if (Schema::hasTable($table)) {
                $owners[$table] = DB::table($table)->selectRaw('status, COUNT(*) AS total')
                    ->groupBy('status')->orderBy('status')->get()->map(fn ($row): array => (array) $row)->all();
            }
        }
        $archives = Schema::hasTable('market_training_archives')
            ? DB::table('market_training_archives')->where('symbol', 'XAUUSD')->orderBy('id')->limit(21)
                ->get(['id', 'dataset_key', 'provider', 'timeframe', 'status', 'row_count', 'first_candle_at', 'last_candle_at'])
            : collect();
        if ($archives->count() > 20) $missing[] = 'ARCHIVE_OUTPUT_BOUND_REACHED';
        $identity = ['protocol' => self::PROTOCOL, 'target_symbol' => 'XAUUSD', 'run_high_water_id' => $highWater,
            'candidate_interval' => $candidate === null ? null : ['start_inclusive' => $candidate['start']->toIso8601String(),
                'end_exclusive' => $candidate['end']->toIso8601String()],
            'run_phase_summary' => $phases, 'research_request_reference_ranges' => $ranges,
            'research_input_reference_ranges' => $inputRanges, 'research_input_reference_summary' => $inputSummary,
            'archive_inventory' => $archives->take(20)->map(fn ($row): array => (array) $row)->all(),
            'archive_inventory_proves_consumption' => false, 'persisted_owner_status_counts' => $owners,
            'authorized_window_readiness' => app(InstrumentResearchWindowService::class)->readiness(),
            'unresolved_provenance' => array_values(array_unique($missing)),
            'candidate_unused_demonstrated' => false, 'candidate_unused_windows' => [],
            'dependency_status' => 'BLOCKED_DEPENDENCY',
            'reason_code' => collect(array_merge($ranges, $inputRanges))->contains('candidate_physical_time_overlap', true)
                ? 'CANDIDATE_INTERSECTS_RESEARCH_REFERENCED_EVENTS' : 'RESEARCH_TRAINING_SELECTION_PROVENANCE_UNVERIFIED',
            'physical_event_identity_rule' => 'UTC market events remain excluded across provider, dataset hash and timeframe labels',
            'scope' => 'bounded_request_reference_inventory; original training, selection and all context exposure are not exhaustively attested',
            'paper_2026_research_eligible' => false, 'independent_evidence' => false, 'promotion_evidence' => false,
            'data_writes' => false];

        return [...$identity, 'audit_hash' => $this->hash($identity), 'observed_at' => now()->utc()->toIso8601String()];
    }

    /**
     * Named original producer only. This never upgrades the global reference audit
     * or authorizes data; a copied caller report is not consumed by this path.
     */
    public function auditForCertificate(int $certificateId, ?string $candidateStart, ?string $candidateEnd,
        array $originalRunIds = [], array $originalFoldIds = []): array
    {
        $candidate = $this->candidate($candidateStart, $candidateEnd);
        if ($candidate === null) throw new InvalidArgumentException('CANDIDATE_REQUIRES_VALID_EXPLICIT_UTC_INTERVAL');
        $proof = app(ResearchWindowExposureInventoryService::class)->originalHistoryAttestation($certificateId,
            $candidate['start']->toIso8601String(), $candidate['end']->toIso8601String(), $originalRunIds, $originalFoldIds);
        $ready = ($proof['ready'] ?? false) === true;
        $identity = ['protocol' => self::PROTOCOL, 'certificate_id' => $certificateId,
            'candidate_interval' => ['start_inclusive' => $candidate['start']->toIso8601String(),
                'end_exclusive' => $candidate['end']->toIso8601String()],
            'original_history_attestation' => $proof, 'candidate_unused_demonstrated' => $ready,
            'candidate_unused_windows' => $ready ? [['start_inclusive' => $candidate['start']->toIso8601String(),
                'end_exclusive' => $candidate['end']->toIso8601String()]] : [],
            'unresolved_provenance' => $ready ? [] : [$proof['reason_code']],
            'dependency_status' => $ready ? 'READY' : 'BLOCKED_DEPENDENCY', 'reason_code' => $proof['reason_code'],
            'scope' => 'named_finite_original_candidate_history_not_global_archive_independence',
            'paper_2026_research_eligible' => false, 'independent_evidence' => false,
            'promotion_evidence' => false, 'server_authorization_created' => false, 'data_writes' => false];
        return [...$identity, 'audit_hash' => $this->hash($identity), 'observed_at' => now()->utc()->toIso8601String()];
    }

    public function preregistration(array $proposal, array $audit): array
    {
        $auditIdentity = $audit;
        unset($auditIdentity['audit_hash'], $auditIdentity['observed_at']);
        if (($audit['protocol'] ?? null) !== self::PROTOCOL || ! hash_equals(
            $this->hash($auditIdentity), (string) ($audit['audit_hash'] ?? ''),
        )) throw new InvalidArgumentException('PROVENANCE_AUDIT_HASH_INVALID');
        foreach (['source_data_hash', 'source_response_hash', 'source_execution_hash', 'source_mtf_bundle_hash',
            'frozen_control_parameter_hash', 'intervention_hash', 'context_hash', 'stopping_rule_hash'] as $field) {
            if (! is_string($proposal[$field] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $proposal[$field])) {
                throw new InvalidArgumentException('PREREGISTRATION_DESIGN_IDENTITY_INCOMPLETE:'.$field);
            }
        }
        if (! is_string($proposal['hypothesis_key'] ?? null) || trim($proposal['hypothesis_key']) === '') {
            throw new InvalidArgumentException('PREREGISTRATION_HYPOTHESIS_MISSING');
        }
        $plan = app(ActivationValidationPlanService::class)->reserve($proposal);

        $identity = ['protocol' => self::RESERVATION_PROTOCOL, 'validation_plan' => $plan,
            'provenance_audit_hash' => $audit['audit_hash'], 'physical_event_domain' => null,
            'status' => 'blocked_by_continuous_paper_policy', 'dependency_status' => 'BLOCKED_DEPENDENCY',
            'reason_code' => $plan['reason_code'], 'executable' => false,
            'validation_dataset_sha256' => null, 'paper_eligible' => false,
            'required_before_execution' => ['original_training_selection_and_context_exposure_proof',
                'genuinely_unexposed_authorized_pre_2026_original_windows',
                'immutable_owner_preregistration_before_first_validation_outcome'],
            'independent_evidence' => false, 'server_authorization_created' => false,
            'promotion_evidence' => false, 'data_writes' => false];
        return [...$identity, 'reservation_hash' => $this->hash($identity), 'draft_created_at' => now()->utc()->toIso8601String()];
    }

    private function candidate(?string $start, ?string $end): ?array
    {
        if ($start === null && $end === null) return null;
        $from = $this->utc($start); $until = $this->utc($end);
        if ($from === null || $until === null || ! $until->greaterThan($from)
            || ! preg_match('/(?:Z|[+-]\d{2}:\d{2})$/D', (string) $start)
            || ! preg_match('/(?:Z|[+-]\d{2}:\d{2})$/D', (string) $end)) {
            throw new InvalidArgumentException('CANDIDATE_REQUIRES_VALID_EXPLICIT_UTC_INTERVAL');
        }
        return ['start' => $from, 'end' => $until];
    }

    private function utc(?string $value): ?CarbonImmutable
    {
        if ($value === null || ! preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})?$/D', $value)) return null;
        if (! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))
            || (int) substr($value, 11, 2) > 23 || (int) substr($value, 14, 2) > 59 || (int) substr($value, 17, 2) > 59) return null;
        try { return CarbonImmutable::parse($value, 'UTC')->utc(); } catch (\Throwable) { return null; }
    }

    private function hash(array $value): string
    {
        return app(ResearchPaperEpochContractService::class)->parameterHash($value);
    }
}
