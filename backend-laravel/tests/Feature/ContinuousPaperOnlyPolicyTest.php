<?php

namespace Tests\Feature;

use App\Services\ActivationValidationPlanService;
use App\Services\InstrumentResearchWindowService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\ResearchWindowProvenanceAuditService;
use Tests\TestCase;

class ContinuousPaperOnlyPolicyTest extends TestCase
{
    public function test_every_year_from_2026_remains_paper_even_after_configuration_changes(): void
    {
        config(['services.lab_selection.training_end_exclusive' => '2099-01-01',
            'services.instrument_policy.authorized_research_windows' => []]);
        $epochs = app(ResearchPaperEpochContractService::class);
        foreach ([2026, 2027, 2028, 2035, 2098] as $year) {
            $this->assertFalse($epochs->researchIntervalDisjointFromPaper("$year-01-01T00:00:00Z", "$year-02-01T00:00:00Z"));
        }
        $this->assertTrue($epochs->researchIntervalDisjointFromPaper('2025-01-01T00:00:00Z', '2026-01-01T00:00:00Z'));
        $this->assertFalse($epochs->researchIntervalDisjointFromPaper('2025-12-31T00:00:00Z', '2026-01-01T00:00:01Z'));
        $this->assertNull($epochs->paperOnlyPolicy()['end_exclusive']);
        $this->assertSame('2026-01-01T00:00:00Z', app(\App\Services\MarketData\MarketTrainingDataService::class)
            ->trainingCutoff()->toIso8601ZuluString());
    }

    public function test_future_research_authorization_cannot_override_the_continuous_boundary(): void
    {
        config(['services.instrument_policy.authorized_research_windows' => [[
            'authorization_id' => 'operator-approved', 'research_epoch_id' => '2027',
            'purpose' => 'instrument_independent_validation', 'dataset_sha256' => str_repeat('a', 64),
            'start_inclusive' => '2027-01-01T00:00:00Z', 'end_exclusive' => '2027-02-01T00:00:00Z',
        ]]]);
        $this->assertSame([], app(InstrumentResearchWindowService::class)->readiness()['eligible_windows']);
        $this->assertSame([], app(InstrumentResearchWindowService::class)->readiness()['future_windows']);
    }

    public function test_no_future_research_calendar_is_invented_and_the_plan_is_bound(): void
    {
        $schedule = app(ResearchWindowProvenanceAuditService::class)->futureSchedule();
        $this->assertSame([], $schedule['windows']);
        $this->assertSame('ALL_DATA_FROM_2026_RESERVED_FOR_PAPER', $schedule['reason_code']);
        $owner = app(ActivationValidationPlanService::class);
        $plan = $owner->reserve(['hypothesis_key' => 'test']);
        $this->assertNull($plan['validation_start_inclusive']);
        $this->assertFalse($plan['executable']);
        $this->assertTrue($owner->valid($plan, ['hypothesis_key' => 'test']));
        $this->assertFalse($owner->valid($plan, ['hypothesis_key' => 'different']));
    }

    public function test_original_paper_inputs_cannot_be_reused_as_research_learning(): void
    {
        $epochs = app(ResearchPaperEpochContractService::class);
        foreach ([2026, 2027, 2028, 2035] as $year) {
            $request = ['mtf_snapshot_manifest' => ['streams' => ['H1' => ['last_candle_at' => "$year-01-01T00:00:00Z"]]]];
            $run = new \App\Models\LabEvaluationRun(['phase' => 'full_validation', 'request_meta' => ['payload' => $request]]);
            $this->assertSame(['PAPER_ONLY_DATA_CANNOT_ENTER_RESEARCH_LEARNING'],
                app(\App\Services\LabImmutableEvidenceService::class)->learningEligibility($run)['reason_codes']);
        }
        $this->assertFalse($epochs->researchInputHasPaperData(['candles' => [['time' => '2025-12-31T23:55:00Z']],
            'research_release' => ['sealed_at' => '2028-01-01T00:00:00Z']]));
        $this->assertTrue($epochs->researchInputHasPaperData([], ['warmup' => ['last_candle_at' => '2027-01-01 00:00:00']]));
    }
}
