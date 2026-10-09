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
        $plan = app(ActivationValidationPlanService::class)->reserve([]);
        $start = CarbonImmutable::parse($plan['validation_start_inclusive'])->utc();
        $windows = [];
        for ($index = 0; $index < 6; $index++) {
            $from = $start->addMonths($index); $until = $start->addMonths($index + 1);
            $windows[] = [
                'ordinal' => $index + 1, 'start_inclusive' => $from->toIso8601String(),
                'end_exclusive' => $until->toIso8601String(),
                'disjoint_from_paper' => app(ResearchPaperEpochContractService::class)->researchIntervalDisjointFromPaper(
                    $from->toIso8601String(), $until->toIso8601String()),
                'source_dataset_sha256' => null, 'mtf_bundle_hash' => null,
                'evaluated_start_inclusive' => null, 'evaluated_rows' => null,
                'warmup_policy' => 'actual_closed_rows_inside_this_reserved_window_only',
                'holding_policy' => 'entry_cutoff_and_outcome_maturity_must_fit_the_declared_window',
                'executable' => false,
            ];
        }
        $before = $start->greaterThan(now()->utc());
        $disjoint = ! in_array(false, array_column($windows, 'disjoint_from_paper'), true);
        $identity = ['protocol' => 'future_research_collection_schedule_draft_v1',
            'symbol' => 'XAUUSD', 'timezone' => 'UTC', 'windows' => $windows,
            'execution_timeframe' => 'M5', 'context_timeframes' => ['H4', 'H1', 'M15'],
            'source_design_selected' => false, 'original_preregistration_persisted' => false,
            'calendar_months_are_not_powered_windows' => true,
            'required_before_first_outcome' => ['persisted_original_control_intervention_context_and_stopping_rule',
                'prospective_server_data_use_policy_and_authorization', 'sealed_selection_and_validation_separation'],
            'required_before_execution' => ['actual_provider_bytes_and_immutable_SHA256_for_all_streams',
                'verified_training_selection_context_and_holding_exposure', 'actual_warmup_and_evaluated_row_receipts',
                'completed_authorized_research_window_and_existing_cost_risk_power_gates'],
            'earlier_paper_warmup_eligible' => false, 'paper_2026_research_eligible' => false,
            'executable' => false, 'independent_evidence' => false, 'promotion_evidence' => false,
            'server_authorization_created' => false, 'data_writes' => false,
            'status' => 'draft_collection_schedule_not_authorization',
            'reason_code' => ! $before ? 'PROSPECTIVE_REGISTRATION_DEADLINE_PASSED'
                : (! $disjoint ? 'RESEARCH_VALIDATION_OVERLAPS_PAPER_EPOCH' : 'AWAITING_ORIGINAL_DESIGN_AND_ACTUAL_AUTHORIZED_DATA')];
        return [...$identity, 'schedule_hash' => $this->hash($identity), 'observed_at' => now()->utc()->toIso8601String()];
    }

    public function audit(?string $candidateStart = null, ?string $candidateEnd = null): array
    {
        $candidate = $this->candidate($candidateStart, $candidateEnd);
        $missing = [];
        $phases = []; $ranges = []; $highWater = 0;
        if (! Schema::hasTable('lab_evaluation_runs')) {
            $missing[] = 'LAB_EVALUATION_RUN_INVENTORY_MISSING';
        } else {
            // A fixed high water makes this bounded read reproducible while workers progress.
            $highWater = (int) DB::table('lab_evaluation_runs')->max('id');
            $grammar = DB::connection()->getQueryGrammar();
            $json = fn (string $path): string => $grammar->wrap('request_meta->'.$path);
            $first = 'COALESCE('.implode(',', array_map($json, [
                'dataset_manifest->mtf_bundle_manifest->streams->M5->first_candle_at',
                'dataset_manifest->mtf_bundle_manifest->entry_first_candle_at',
                'dataset_manifest->first_candle_at',
            ])).')';
            $last = 'COALESCE('.implode(',', array_map($json, [
                'dataset_manifest->mtf_bundle_manifest->streams->M5->last_candle_at',
                'dataset_manifest->mtf_bundle_manifest->entry_last_candle_at',
                'dataset_manifest->last_candle_at',
            ])).')';
            $symbol = 'COALESCE('.$json('payload->symbol').','.$json('dataset_manifest->symbol').')';
            $query = fn () => DB::table('lab_evaluation_runs')->where('id', '<=', $highWater);
            $phases = $query()->selectRaw('phase, COUNT(*) AS total, MIN(id) AS first_run_id, MAX(id) AS last_run_id')
                ->selectRaw("SUM(CASE WHEN $first IS NULL OR $last IS NULL OR request_hash IS NULL THEN 1 ELSE 0 END) AS incomplete_chronology_or_request")
                ->selectRaw("SUM(CASE WHEN $symbol IS NULL THEN 1 ELSE 0 END) AS unknown_market_identity")
                ->groupBy('phase')->orderBy('phase')->get()->map(fn ($row): array => (array) $row)->all();
            // Return compact UTC ranges, never large request payloads or outcome metrics.
            $groups = $query()->whereNotNull('request_hash')->whereRaw("$first IS NOT NULL AND $last IS NOT NULL")
                ->selectRaw("phase, $symbol AS source_symbol, $first AS first_candle_at, $last AS last_candle_at, COUNT(*) AS referenced_runs, MIN(id) AS example_run_id")
                ->groupBy('phase')->groupByRaw("$symbol, $first, $last")
                ->orderBy('example_run_id')->limit(101)->get();
            if ($groups->count() > 100) $missing[] = 'EXPOSURE_RANGE_OUTPUT_BOUND_REACHED';
            $datasetSha = 'COALESCE('.implode(',', array_map($json, [
                'dataset_manifest->mtf_bundle_manifest->streams->M5->sha256',
                'dataset_manifest->sha256', 'dataset_manifest->snapshot_sha256',
            ])).')';
            $examples = $query()->whereIn('id', $groups->take(100)->pluck('example_run_id'))
                ->select(['id', 'request_hash', 'data_hash'])->selectRaw("$datasetSha AS source_dataset_sha256")
                ->get()->keyBy('id');
            foreach ($groups->take(100) as $row) {
                $from = $this->utc((string) $row->first_candle_at);
                $through = $this->utc((string) $row->last_candle_at);
                if ($from === null || $through === null || $through->lessThan($from)) {
                    $missing[] = 'EXPOSURE_CHRONOLOGY_UNASSESSABLE';
                    continue;
                }
                $ranges[] = ['phase' => $row->phase, 'source_symbol' => $row->source_symbol, 'first_candle_at' => $from->toIso8601String(),
                    'last_candle_at' => $through->toIso8601String(), 'referenced_runs' => (int) $row->referenced_runs,
                    'example_run_id' => (int) $row->example_run_id,
                    'example_request_hash' => $examples[$row->example_run_id]->request_hash ?? null,
                    'example_data_hash' => $examples[$row->example_run_id]->data_hash ?? null,
                    'example_source_dataset_sha256' => $examples[$row->example_run_id]->source_dataset_sha256 ?? null,
                    'original_receipt_bytes_revalidated' => false,
                    'candidate_physical_time_overlap' => $candidate !== null
                        && $row->source_symbol === 'XAUUSD'
                        && $from->lessThan($candidate['end']) && $through->greaterThanOrEqualTo($candidate['start'])];
            }
            if (array_sum(array_column($phases, 'incomplete_chronology_or_request')) > 0) {
                $missing[] = 'LEGACY_RESEARCH_EXPOSURE_CHRONOLOGY_INCOMPLETE';
            }
            if (array_sum(array_column($phases, 'unknown_market_identity')) > 0) {
                $missing[] = 'RESEARCH_MARKET_IDENTITY_INCOMPLETE';
            }
        }
        // Request references are exclusion evidence, not proof of all candles actually consumed.
        // Neither missing JSON keys nor an absent run demonstrates unused training/selection data.
        $missing[] = 'ORIGINAL_TRAINING_AND_SELECTION_EXPOSURE_INVENTORY_NOT_ATTESTED';
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
            'archive_inventory' => $archives->take(20)->map(fn ($row): array => (array) $row)->all(),
            'archive_inventory_proves_consumption' => false, 'persisted_owner_status_counts' => $owners,
            'authorized_window_readiness' => app(InstrumentResearchWindowService::class)->readiness(),
            'unresolved_provenance' => array_values(array_unique($missing)),
            'candidate_unused_demonstrated' => false, 'candidate_unused_windows' => [],
            'dependency_status' => 'BLOCKED_DEPENDENCY',
            'reason_code' => collect($ranges)->contains('candidate_physical_time_overlap', true)
                ? 'CANDIDATE_INTERSECTS_RESEARCH_REFERENCED_EVENTS' : 'RESEARCH_TRAINING_SELECTION_PROVENANCE_UNVERIFIED',
            'physical_event_identity_rule' => 'UTC market events remain excluded across provider, dataset hash and timeframe labels',
            'scope' => 'bounded_request_reference_inventory; original training, selection and all context exposure are not exhaustively attested',
            'paper_2026_research_eligible' => false, 'independent_evidence' => false, 'promotion_evidence' => false,
            'data_writes' => false];

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
        $start = CarbonImmutable::parse($plan['validation_start_inclusive'])->utc();
        if ($start->lessThan('2027-01-01T00:00:00Z') || ! $start->greaterThan(now())) {
            throw new InvalidArgumentException('PREREGISTRATION_MUST_PRECEDE_FUTURE_RESEARCH_EVENTS');
        }
        $design = array_intersect_key($proposal, array_flip(['hypothesis_key', 'source_data_hash', 'source_response_hash',
            'source_execution_hash', 'source_mtf_bundle_hash', 'frozen_control_parameter_hash', 'intervention_hash',
            'context_hash', 'stopping_rule_hash']));
        $identity = ['protocol' => self::RESERVATION_PROTOCOL, 'design' => $design,
            'reservation_owner' => ActivationValidationPlanService::class, 'validation_plan' => $plan,
            'physical_event_domain' => ['symbol' => 'XAUUSD', 'timezone' => 'UTC',
                'start_inclusive' => $plan['validation_start_inclusive'], 'end_exclusive' => $plan['validation_end_exclusive']],
            'exclusions' => ['all_events_before' => $start->toIso8601String(), 'all_previously_exposed_events' => true,
                'paper_2026_all_uses' => true, 'provider_hash_or_timeframe_relabeling_changes_identity' => false],
            'provenance_audit_hash' => $audit['audit_hash'],
            'required_before_execution' => ['immutable_owner_preregistration_before_first_validation_outcome',
                'completed_authorized_distinct_research_datasets_with_actual_SHA256_and_UTC_bounds',
                'original_training_selection_and_context_exposure_proof', 'exact_control_intervention_context_and_stopping_rule_parity',
                'existing_powered_window_and_cost_risk_gates'],
            'validation_dataset_sha256' => null, 'executable' => false, 'independent_evidence' => false,
            'paper_eligible' => false, 'promotion_evidence' => false, 'server_authorization_created' => false,
            'status' => 'draft_preregistration_not_persisted', 'dependency_status' => 'BLOCKED_DEPENDENCY',
            'reason_code' => $plan['reason_code'], 'data_writes' => false];

        return [...$identity, 'reservation_hash' => $this->hash($identity),
            'draft_created_at' => now()->utc()->toIso8601String()];
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
