<?php

namespace Tests\Feature;

use App\Models\LabEvaluationRun;
use App\Services\ResearchWindowProvenanceAuditService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class ResearchWindowProvenanceAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-04T13:00:00Z'));
        config()->set('services.lab_selection.training_end_exclusive', '2026-01-01 00:00:00');
        config()->set('services.instrument_policy.authorized_research_windows', []);
        config()->set('services.research_paper_epochs.authorized_paper_epochs', []);
    }

    private function runReceipt(array $manifest, string $phase = 'screening', string $hash = 'a'): LabEvaluationRun
    {
        return LabEvaluationRun::create(['run_id' => (string) Str::uuid(), 'phase' => $phase,
            'mode' => 'diagnostic', 'status' => 'completed', 'started_at' => now(), 'attempt' => 1,
            'request_hash' => str_repeat($hash, 64), 'data_hash' => str_repeat($hash, 64),
            'request_meta' => ['dataset_manifest' => $manifest, 'payload' => ['symbol' => 'XAUUSD']]]);
    }

    private function proposal(): array
    {
        $proposal = ['hypothesis_key' => 'exact-context-future-replication'];
        foreach (['source_data_hash', 'source_response_hash', 'source_execution_hash', 'source_mtf_bundle_hash',
            'frozen_control_parameter_hash', 'intervention_hash', 'context_hash', 'stopping_rule_hash'] as $index => $key) {
            $proposal[$key] = hash('sha256', $key.':'.$index);
        }
        return $proposal;
    }

    public function test_physical_time_overlap_survives_new_hash_provider_and_timeframe_labels(): void
    {
        foreach (['a', 'b'] as $hash) $this->runReceipt(['first_candle_at' => '2005-01-02T23:00:00Z',
            'last_candle_at' => '2025-12-31T23:00:00Z', 'provider' => $hash, 'timeframe' => $hash === 'a' ? 'H1' : 'M5'], 'full_validation', $hash);
        $before = DB::table('lab_evaluation_runs')->count();

        $audit = app(ResearchWindowProvenanceAuditService::class)->audit('2010-01-01T00:00:00Z', '2011-01-01T00:00:00Z');

        $this->assertSame('CANDIDATE_INTERSECTS_RESEARCH_REFERENCED_EVENTS', $audit['reason_code']);
        $this->assertCount(1, $audit['research_request_reference_ranges']);
        $this->assertSame(2, $audit['research_request_reference_ranges'][0]['referenced_runs']);
        $this->assertSame(str_repeat('a', 64), $audit['research_request_reference_ranges'][0]['example_request_hash']);
        $this->assertSame(str_repeat('a', 64), $audit['research_request_reference_ranges'][0]['example_data_hash']);
        $this->assertFalse($audit['research_request_reference_ranges'][0]['original_receipt_bytes_revalidated']);
        $this->assertTrue($audit['research_request_reference_ranges'][0]['candidate_physical_time_overlap']);
        $this->assertFalse($audit['candidate_unused_demonstrated']);
        $this->assertSame($before, DB::table('lab_evaluation_runs')->count());
        $this->assertFalse($audit['data_writes']);
    }

    public function test_missing_legacy_chronology_never_proves_a_nonoverlapping_window_unused(): void
    {
        $this->runReceipt(['snapshot_sha256' => str_repeat('a', 64)]);
        $audit = app(ResearchWindowProvenanceAuditService::class)->audit('2010-01-01T00:00:00Z', '2011-01-01T00:00:00Z');

        $this->assertContains('LEGACY_RESEARCH_EXPOSURE_CHRONOLOGY_INCOMPLETE', $audit['unresolved_provenance']);
        $this->assertContains('ORIGINAL_TRAINING_AND_SELECTION_EXPOSURE_INVENTORY_NOT_ATTESTED', $audit['unresolved_provenance']);
        $this->assertSame('BLOCKED_DEPENDENCY', $audit['dependency_status']);
        $this->assertSame([], $audit['candidate_unused_windows']);
        $this->assertFalse($audit['independent_evidence']);
    }

    public function test_mtf_references_include_loaded_warmup_and_use_utc_without_outcome_payloads(): void
    {
        $this->runReceipt(['mtf_bundle_manifest' => ['streams' => ['M5' => [
            'first_candle_at' => '2025-09-11 00:00:00', 'last_candle_at' => '2025-11-28 08:10:00', 'sha256' => str_repeat('c', 64)]],
            'entry_first_candle_at' => '2025-09-12 19:40:00']]);
        $audit = app(ResearchWindowProvenanceAuditService::class)->audit('2025-09-11T00:00:00Z', '2025-09-12T00:00:00Z');

        $this->assertSame('2025-09-11T00:00:00+00:00', $audit['research_request_reference_ranges'][0]['first_candle_at']);
        $this->assertTrue($audit['research_request_reference_ranges'][0]['candidate_physical_time_overlap']);
        $this->assertSame(str_repeat('c', 64), $audit['research_request_reference_ranges'][0]['example_source_dataset_sha256']);
        $this->assertArrayNotHasKey('payload', $audit);
        $this->assertArrayNotHasKey('metrics', $audit);
    }

    public function test_draft_uses_existing_owner_and_stable_seal_without_fabricating_dataset_or_authority(): void
    {
        $service = app(ResearchWindowProvenanceAuditService::class);
        $audit = $service->audit();
        $first = $service->preregistration($this->proposal(), $audit);
        $this->travelTo(now()->addMinute());
        $second = $service->preregistration($this->proposal(), $service->audit());

        $this->assertSame($first['reservation_hash'], $second['reservation_hash']);
        $this->assertSame('2027-01-01T00:00:00+00:00', $first['physical_event_domain']['start_inclusive']);
        $this->assertSame('2027-07-01T00:00:00+00:00', $first['physical_event_domain']['end_exclusive']);
        $this->assertSame(6, $first['validation_plan']['window_count']);
        $this->assertSame('draft_preregistration_not_persisted', $first['status']);
        $this->assertNull($first['validation_dataset_sha256']);
        foreach (['executable', 'independent_evidence', 'paper_eligible', 'promotion_evidence', 'server_authorization_created', 'data_writes'] as $key) {
            $this->assertFalse($first[$key]);
        }
        $this->assertContains('original_training_selection_and_context_exposure_proof', $first['required_before_execution']);
        $this->assertSame(0, DB::table('research_experiment_work_items')->count());
    }

    public function test_draft_refuses_unbound_control_intervention_and_stopping_rule(): void
    {
        $service = app(ResearchWindowProvenanceAuditService::class);
        $proposal = $this->proposal();
        unset($proposal['frozen_control_parameter_hash']);
        $this->expectExceptionMessage('PREREGISTRATION_DESIGN_IDENTITY_INCOMPLETE:frozen_control_parameter_hash');
        $service->preregistration($proposal, $service->audit());
    }

    public function test_draft_refuses_tampered_audit_or_late_preregistration(): void
    {
        $service = app(ResearchWindowProvenanceAuditService::class);
        $audit = $service->audit();
        $audit['candidate_unused_demonstrated'] = true;
        try {
            $service->preregistration($this->proposal(), $audit);
            $this->fail('Tampered audit was accepted.');
        } catch (InvalidArgumentException $error) {
            $this->assertSame('PROVENANCE_AUDIT_HASH_INVALID', $error->getMessage());
        }
        $this->travelTo(CarbonImmutable::parse('2027-01-01T00:00:00Z'));
        $this->expectExceptionMessage('PREREGISTRATION_MUST_PRECEDE_FUTURE_RESEARCH_EVENTS');
        $service->preregistration($this->proposal(), $service->audit());
    }

    public function test_future_paper_overlap_stays_an_explicit_blocked_reservation(): void
    {
        config()->set('services.research_paper_epochs.authorized_paper_epochs', [[
            'protocol' => 'authorized_prospective_paper_epoch_v1', 'purpose' => 'prospective_paper_forward',
            'window_key' => 'paper_2027', 'authorization_id' => 'approved-paper-2027',
            'start_inclusive' => '2027-01-01T00:00:00Z', 'end_exclusive' => '2028-01-01T00:00:00Z',
            'authorized_at' => '2026-10-01T00:00:00Z', 'approved' => true,
            'candidate_must_be_frozen_before_observation' => true, 'research_uses_forbidden' => true]]);
        $service = app(ResearchWindowProvenanceAuditService::class);

        $draft = $service->preregistration($this->proposal(), $service->audit());

        $this->assertSame('RESEARCH_VALIDATION_OVERLAPS_PAPER_EPOCH', $draft['reason_code']);
        $this->assertFalse($draft['validation_plan']['validation_period_disjoint_from_paper']);
        $this->assertFalse($draft['executable']);
    }

    public function test_operator_command_prints_dependency_and_rejects_half_interval_without_writes(): void
    {
        $this->artisan('trading:audit-research-window-provenance', ['--json' => true])->assertSuccessful();
        $this->artisan('trading:audit-research-window-provenance', ['--candidate-start' => '2025-01-01T00:00:00Z'])
            ->expectsOutputToContain('CANDIDATE_REQUIRES_VALID_EXPLICIT_UTC_INTERVAL')->assertFailed();
        $this->assertSame(0, DB::table('lab_evaluation_runs')->count());
        $this->assertSame(0, DB::table('research_experiment_work_items')->count());
    }

    public function test_invalid_calendar_date_and_implicit_timezone_are_not_accepted_as_utc_candidates(): void
    {
        $service = app(ResearchWindowProvenanceAuditService::class);
        foreach (['2025-02-30T00:00:00Z', '2025-02-01 00:00:00', '2025-02-01T25:00:00Z'] as $bad) {
            try {
                $service->audit($bad, '2025-03-01T00:00:00Z');
                $this->fail('Malformed or implicit UTC candidate accepted.');
            } catch (InvalidArgumentException $error) {
                $this->assertSame('CANDIDATE_REQUIRES_VALID_EXPLICIT_UTC_INTERVAL', $error->getMessage());
            }
        }
    }

    public function test_a_different_instrument_at_the_same_time_is_not_a_known_xauusd_event_overlap(): void
    {
        $run = $this->runReceipt(['first_candle_at' => '2025-01-01T00:00:00Z', 'last_candle_at' => '2025-03-01T00:00:00Z']);
        $meta = $run->request_meta;
        $meta['payload']['symbol'] = 'EURUSD';
        $run->update(['request_meta' => $meta]);

        $audit = app(ResearchWindowProvenanceAuditService::class)->audit('2025-01-01T00:00:00Z', '2025-03-01T00:00:00Z');

        $this->assertSame('EURUSD', $audit['research_request_reference_ranges'][0]['source_symbol']);
        $this->assertFalse($audit['research_request_reference_ranges'][0]['candidate_physical_time_overlap']);
        $this->assertSame('RESEARCH_TRAINING_SELECTION_PROVENANCE_UNVERIFIED', $audit['reason_code']);
        $this->assertFalse($audit['candidate_unused_demonstrated']);
    }

    public function test_six_future_months_are_explicit_drafts_not_available_or_authorized_data(): void
    {
        $service = app(ResearchWindowProvenanceAuditService::class);
        $schedule = $service->futureSchedule();
        $this->assertCount(6, $schedule['windows']);
        $this->assertSame('2027-01-01T00:00:00+00:00', $schedule['windows'][0]['start_inclusive']);
        $this->assertSame('2027-07-01T00:00:00+00:00', $schedule['windows'][5]['end_exclusive']);
        foreach ($schedule['windows'] as $index => $window) {
            $this->assertNull($window['source_dataset_sha256']);
            $this->assertNull($window['evaluated_rows']);
            $this->assertFalse($window['executable']);
            $this->assertTrue($window['disjoint_from_paper']);
            $this->assertSame('actual_closed_rows_inside_this_reserved_window_only', $window['warmup_policy']);
            if ($index > 0) $this->assertSame($schedule['windows'][$index - 1]['end_exclusive'], $window['start_inclusive']);
        }
        foreach (['source_design_selected', 'original_preregistration_persisted', 'earlier_paper_warmup_eligible',
            'paper_2026_research_eligible', 'executable', 'independent_evidence', 'server_authorization_created', 'data_writes'] as $key) {
            $this->assertFalse($schedule[$key]);
        }
        $this->assertSame('draft_collection_schedule_not_authorization', $schedule['status']);
        $this->travelTo(now()->addMinute());
        $this->assertSame($schedule['schedule_hash'], $service->futureSchedule()['schedule_hash']);
        $this->assertSame(0, DB::table('research_experiment_work_items')->count());
        $this->artisan('trading:audit-research-window-provenance', ['--future-schedule' => true, '--json' => true])
            ->expectsOutputToContain('future_collection_schedule')->assertSuccessful();
    }

    public function test_future_schedule_cannot_ignore_paper_overlap_or_become_prospective_after_outcomes(): void
    {
        config()->set('services.research_paper_epochs.authorized_paper_epochs', [[
            'protocol' => 'authorized_prospective_paper_epoch_v1', 'purpose' => 'prospective_paper_forward',
            'window_key' => 'paper_2027', 'authorization_id' => 'approved-paper-2027',
            'start_inclusive' => '2027-01-01T00:00:00Z', 'end_exclusive' => '2028-01-01T00:00:00Z',
            'authorized_at' => '2026-10-01T00:00:00Z', 'approved' => true,
            'candidate_must_be_frozen_before_observation' => true, 'research_uses_forbidden' => true]]);
        $service = app(ResearchWindowProvenanceAuditService::class);
        $this->assertSame('RESEARCH_VALIDATION_OVERLAPS_PAPER_EPOCH', $service->futureSchedule()['reason_code']);
        $this->travelTo(CarbonImmutable::parse('2027-01-01T00:00:00Z'));
        $schedule = $service->futureSchedule();
        $this->assertSame('PROSPECTIVE_REGISTRATION_DEADLINE_PASSED', $schedule['reason_code']);
        $this->assertFalse($schedule['executable']);
    }

    public function test_context_candle_exposure_cannot_hide_behind_nonoverlapping_m5_dates(): void
    {
        $this->runReceipt(['mtf_bundle_manifest' => ['streams' => [
            'M5' => ['first_candle_at' => '2025-01-01T00:00:00Z', 'last_candle_at' => '2025-01-02T00:00:00Z',
                'sha256' => str_repeat('a', 64)],
            'H4' => ['first_candle_at' => '2010-01-01T00:00:00Z', 'last_candle_at' => '2010-01-01T00:00:00Z',
                'sha256' => str_repeat('b', 64)],
        ]]]);
        $audit = app(ResearchWindowProvenanceAuditService::class)->audit('2010-01-01T03:45:00Z', '2010-01-01T04:00:00Z');

        $context = collect($audit['research_input_reference_ranges'])->firstWhere('stream', 'H4');
        $this->assertFalse($audit['research_request_reference_ranges'][0]['candidate_physical_time_overlap']);
        $this->assertSame('2010-01-01T04:00:00+00:00', $context['referenced_end_exclusive']);
        $this->assertTrue($context['candidate_physical_time_overlap']);
        $this->assertSame('CANDIDATE_INTERSECTS_RESEARCH_REFERENCED_EVENTS', $audit['reason_code']);
        $this->assertFalse($context['original_receipt_bytes_revalidated']);
        $this->assertFalse($audit['candidate_unused_demonstrated']);
    }

    public function test_original_payload_context_and_inline_warmup_are_exclusion_references(): void
    {
        $run = $this->runReceipt(['first_candle_at' => '2025-01-01T00:00:00Z', 'last_candle_at' => '2025-01-02T00:00:00Z']);
        $meta = $run->request_meta;
        $meta['payload'] += ['timeframe' => 'M5', 'mtf_snapshot_manifest' => ['streams' => ['H1' => [
            'first_candle_at' => '2010-01-01T00:00:00Z', 'last_candle_at' => '2010-01-01T01:00:00Z',
            'sha256' => str_repeat('c', 64)]]],
            'regime_candles' => ['row_count' => 2, 'sha256' => str_repeat('d', 64),
                'first_row' => ['time' => '2009-12-31T23:00:00Z'], 'last_row' => ['time' => '2010-01-01T00:00:00Z']]];
        $run->update(['request_meta' => $meta]);
        $audit = app(ResearchWindowProvenanceAuditService::class)->audit('2010-01-01T00:00:00Z', '2010-01-01T00:30:00Z');

        foreach (['H1', 'INLINE_REGIME_H1'] as $stream) {
            $range = collect($audit['research_input_reference_ranges'])->firstWhere('stream', $stream);
            $this->assertTrue($range['candidate_physical_time_overlap']);
            $this->assertTrue($range['whole_source_including_warmup_referenced']);
            $this->assertFalse($range['holding_exposure_attested']);
        }
        $this->assertContains('ORIGINAL_WARMUP_AND_HOLDING_EXPOSURE_NOT_ATTESTED', $audit['unresolved_provenance']);
        $this->assertContains('ORIGINAL_MTF_CONTEXT_EXPOSURE_INVENTORY_NOT_ATTESTED', $audit['unresolved_provenance']);
        $this->assertArrayNotHasKey('first_row', $audit['research_input_reference_ranges'][0]);
    }

    public function test_related_market_requires_its_own_identity_and_missing_auxiliary_dates_do_not_prove_unused(): void
    {
        $run = $this->runReceipt(['mtf_bundle_manifest' => ['streams' => [
            'M5' => ['first_candle_at' => '2025-01-01T00:00:00Z', 'last_candle_at' => '2025-01-02T00:00:00Z', 'sha256' => str_repeat('a', 64)],
            'RELATED_M15' => ['symbol' => 'EURUSD', 'first_candle_at' => '2010-01-01T00:00:00Z',
                'last_candle_at' => '2010-01-01T01:00:00Z', 'sha256' => str_repeat('b', 64)],
        ]]]);
        $meta = $run->request_meta; $meta['payload']['foundation_dataset_path'] = 'unverified-foundation.csv';
        $run->update(['request_meta' => $meta]);
        $audit = app(ResearchWindowProvenanceAuditService::class)->audit('2010-01-01T00:00:00Z', '2010-01-01T00:15:00Z');

        $related = collect($audit['research_input_reference_ranges'])->firstWhere('stream', 'RELATED_M15');
        $this->assertSame('EURUSD', $related['source_symbol']);
        $this->assertFalse($related['candidate_physical_time_overlap']);
        $this->assertContains('RESEARCH_INPUT_REFERENCE_CHRONOLOGY_OR_IDENTITY_INCOMPLETE', $audit['unresolved_provenance']);
        $this->assertContains('RESEARCH_INPUT_REFERENCE_SCOPE_UNASSESSABLE', $audit['unresolved_provenance']);
        $this->assertFalse($audit['candidate_unused_demonstrated']);
    }

    public function test_hash_only_mtf_stream_is_unassessable_and_cannot_be_reported_as_unused(): void
    {
        $this->runReceipt(['mtf_bundle_manifest' => ['streams' => [
            'M5' => ['first_candle_at' => '2025-01-01T00:00:00Z', 'last_candle_at' => '2025-01-02T00:00:00Z', 'sha256' => str_repeat('a', 64)],
            'H4' => ['sha256' => str_repeat('b', 64)],
        ]]]);
        $audit = app(ResearchWindowProvenanceAuditService::class)->audit('2010-01-01T00:00:00Z', '2010-01-02T00:00:00Z');
        $this->assertContains('RESEARCH_INPUT_REFERENCE_CHRONOLOGY_OR_IDENTITY_INCOMPLETE', $audit['unresolved_provenance']);
        $this->assertContains('RESEARCH_INPUT_REFERENCE_SCOPE_UNASSESSABLE', $audit['unresolved_provenance']);
        $this->assertSame('BLOCKED_DEPENDENCY', $audit['dependency_status']);
        $this->assertFalse($audit['candidate_unused_demonstrated']);
        $this->assertSame([], $audit['candidate_unused_windows']);
    }
}
