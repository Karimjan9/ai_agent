<?php

namespace Tests\Feature;

use App\Models\ModelVersion;
use App\Services\PaperAuthorityAdmissionService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\InstrumentResearchWindowService;
use App\Services\ActivationValidationPlanService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResearchPaperEpochContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_future_epochs_are_explicit_default_deny_and_preserve_2026_cutoff(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-01-15T00:00:00Z'));
        $epochs = app(ResearchPaperEpochContractService::class);
        $legacy = $epochs->contract();
        $this->assertNull($epochs->paperContractForCandidate('paper_2028', now()->toIso8601String()));
        foreach ([['approved' => false], ['protocol' => 'unknown'], ['purpose' => 'research'],
            ['research_uses_forbidden' => false], ['authorized_at' => ''],
            ['candidate_must_be_frozen_before_observation' => false],
            ['start_inclusive' => '2026-12-01T00:00:00Z']] as $invalid) {
            config()->set('services.research_paper_epochs.authorized_paper_epochs', [$this->futureEpoch($invalid)]);
            $this->assertNull($epochs->paperContractForCandidate('paper_2028', now()->toIso8601String()));
        }
        config()->set('services.research_paper_epochs.authorized_paper_epochs', [$this->futureEpoch()]);
        $contract = $epochs->paperContractForCandidate('paper_2028', now()->toIso8601String());
        $this->assertSame($legacy, $epochs->contract());
        $this->assertSame('2026-01-01T00:00:00+00:00', data_get($contract, 'research_epoch.end_exclusive'));
        $this->assertSame('paper_2028', data_get($contract, 'paper_epoch.window_key'));
        $this->assertFalse($contract['paper_used_for_selection']);
        $this->assertNull($epochs->paperContractForCandidate('paper_2026', now()->toIso8601String()));
    }

    public function test_future_paper_seal_binds_approval_freeze_and_observation_chronology(): void
    {
        $this->travelTo(CarbonImmutable::parse('2028-01-15T00:00:00Z'));
        config()->set('services.research_paper_epochs.authorized_paper_epochs', [$this->futureEpoch()]);
        $epochs = app(ResearchPaperEpochContractService::class);
        $freeze = '2027-01-15T00:00:00Z';
        $seal = $epochs->paperContractForCandidate('paper_2028', $freeze);
        $this->assertNotNull($seal);
        $this->assertTrue($epochs->paperWindowValid(['2028-01-02T00:00:00Z'], 'paper_2028', $seal, $freeze));
        foreach (['2027-12-31T23:59:59Z', '2028-01-16T00:00:00Z', '2029-01-01T00:00:00Z', '', 'not-a-time'] as $bad) {
            $this->assertFalse($epochs->paperWindowValid([$bad], 'paper_2028', $seal, $freeze));
        }
        $this->assertFalse($epochs->paperWindowValid(['2028-01-02T00:00:00Z'], 'paper_2028'));
        $this->assertFalse($epochs->paperContractAuthorized($seal, '2027-01-16T00:00:00Z'));
        $this->assertNull($epochs->paperContractForCandidate('paper_2028', '2026-12-01T00:00:00Z'));
        $this->assertNull($epochs->paperContractForCandidate('paper_2028', '2028-01-01T00:00:00Z'));
        $altered = $seal; $altered['paper_epoch']['end_exclusive'] = '2030-01-01T00:00:00+00:00';
        $altered['epoch_contract_hash'] = $epochs->parameterHash(array_diff_key($altered, ['epoch_contract_hash' => true]));
        $this->assertFalse($epochs->paperContractAuthorized($altered, $freeze));
        config()->set('services.research_paper_epochs.authorized_paper_epochs', []);
        $this->assertFalse($epochs->paperContractAuthorized($seal, $freeze));
    }

    public function test_future_paper_and_authorized_instrument_research_must_be_disjoint_both_ways(): void
    {
        $this->travelTo(CarbonImmutable::parse('2029-01-01T00:00:00Z'));
        config()->set('services.research_paper_epochs.authorized_paper_epochs', [$this->futureEpoch()]);
        $window = ['authorization_id' => 'research-2028', 'research_epoch_id' => 'independent-2028',
            'start_inclusive' => '2028-02-01T00:00:00Z', 'end_exclusive' => '2028-03-01T00:00:00Z',
            'dataset_sha256' => str_repeat('a', 64), 'purpose' => 'instrument_independent_validation'];
        config()->set('services.instrument_policy.authorized_research_windows', [$window]);
        $this->assertNull(app(InstrumentResearchWindowService::class)->seal('research-2028', str_repeat('a', 64)));
        $this->assertNull(app(ResearchPaperEpochContractService::class)->paperContractForCandidate('paper_2028', '2027-01-15T00:00:00Z'));
        $window['start_inclusive'] = '2027-02-01T00:00:00Z'; $window['end_exclusive'] = '2027-03-01T00:00:00Z';
        config()->set('services.instrument_policy.authorized_research_windows', [$window]);
        $this->assertNotNull(app(InstrumentResearchWindowService::class)->seal('research-2028', str_repeat('a', 64)));
        $this->assertNotNull(app(ResearchPaperEpochContractService::class)->paperContractForCandidate('paper_2028', '2027-01-15T00:00:00Z'));
        config()->set('services.research_paper_epochs.authorized_paper_epochs', [$this->futureEpoch(), $this->futureEpoch()]);
        $this->assertNull(app(ResearchPaperEpochContractService::class)->paperContractForCandidate('paper_2028', '2027-01-15T00:00:00Z'));
    }

    public function test_future_candidate_uses_persisted_epoch_and_blocks_transport_until_it_opens(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-01-15T00:00:00Z'));
        config()->set('services.research_paper_epochs.authorized_paper_epochs', [$this->futureEpoch()]);
        $model = $this->model('future-paper');
        $passport = [...$this->passport(), 'paper_window_key' => 'paper_2028'];
        $passport['execution_hash'] = data_get(app(\App\Services\ExecutionContractService::class)->for('XAUUSD', 'M15'), 'execution_hash');
        $model->update(['metadata' => ['paper_window_key' => 'paper_2028',
            'elite_agent_passport' => ['passport_hash' => $passport['passport_hash']],
            'confirmation_entry' => ['contract_hash' => $passport['confirmation_entry_hash']],
            'risk_governor' => ['hash' => $passport['risk_governor_hash']],
            'trade_management' => ['hash' => $passport['trade_management_hash']]]]);
        $this->prePaperAuthority($model);
        // Transport-only fixture: real original-source proof is covered by
        // PostPaperConfirmedTraitAdmissionTest, not manufactured by this stub.
        $service = \Mockery::mock(PaperAuthorityAdmissionService::class, [app(ResearchPaperEpochContractService::class)])->makePartial();
        $service->shouldReceive('archivePaperProvenance')->andReturn(['allowed' => true,
            'provenance_kind' => 'unchanged_archive_baseline', 'provenance_hash' => hash('sha256', 'synthetic-transport-proof-not-authority')]);
        app()->instance(PaperAuthorityAdmissionService::class, $service);
        $this->assertSame('e3_paper_candidate', $service->admit($model, 'XAUUSD', 'H1', $passport)['status']);
        $this->assertTrue($service->verifyFrozenCandidate($model, 'XAUUSD', 'H1', $passport)['allowed']);
        $this->assertSame('PAPER_EPOCH_NOT_OPEN_FOR_OBSERVATION', $service->observationReadiness($model, 'XAUUSD', 'H1')['reason_code']);
        $candidate = \App\Models\ModelMarketPerformance::create(['model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'status' => 'forward_validated', 'evidence_status' => 'valid',
            'metrics' => ['execution_contract' => ['execution_hash' => $passport['execution_hash']],
                'training_boundary' => ['used_for_training' => false], 'gold_holdout' => ['used_for_training' => false]]]);
        $candidate->setRelation('modelVersion', $model);
        \Illuminate\Support\Facades\Http::fake();
        $trading = app(\App\Services\PaperTradingExecutionService::class);
        $this->assertSame('paper_2028', (new \ReflectionMethod($trading, 'candidatePassport'))->invoke($trading, $candidate, $model)['paper_window_key']);
        $this->assertSame(0, (new \ReflectionMethod($trading, 'captureLatestSignal'))->invoke($trading, $candidate, collect([$candidate])));
        \Illuminate\Support\Facades\Http::assertNothingSent();
        $this->travelTo(CarbonImmutable::parse('2028-01-15T00:00:00Z'));
        $order = new \App\Models\PaperOrder(['opened_at' => '2028-01-02T00:00:00Z',
            'signal_context' => ['smart_discipline' => ['approved' => true]]]);
        $order->created_at = CarbonImmutable::parse('2028-01-02T00:00:00Z');
        $outcome = $service->prospectiveOutcomeContract($model, 'XAUUSD', 'H1', [$order]);
        $this->assertSame('paper_2028', $outcome['paper_window_key']);
        $this->assertSame('paper_2028', data_get($outcome, 'epoch_contract.paper_epoch.window_key'));
        $this->assertTrue($service->observationReadiness($model, 'XAUUSD', 'H1')['allowed']);
        $wrong = [...$outcome, 'paper_window_key' => 'paper_2026', 'epoch_contract' => app(ResearchPaperEpochContractService::class)->contract(), 'paper_gate_passed' => true];
        $this->assertSame('e3_paper_candidate', $service->recordProspectiveOutcome($model, 'XAUUSD', 'H1', $wrong)['status']);
        $order->closed_at = CarbonImmutable::parse('2029-01-01T00:00:00Z');
        $lateClose = $service->prospectiveOutcomeContract($model, 'XAUUSD', 'H1', [$order]);
        $this->assertContains('2029-01-01T00:00:00+00:00', $lateClose['paper_observation_times']);
        $this->assertSame('e3_paper_candidate', $service->recordProspectiveOutcome($model, 'XAUUSD', 'H1', [...$lateClose, 'paper_gate_passed' => true])['status']);
        $order->closed_at = CarbonImmutable::parse('2028-01-03T00:00:00Z');
        $outcome = $service->prospectiveOutcomeContract($model, 'XAUUSD', 'H1', [$order]);
        $paperAuthority = $service->recordProspectiveOutcome($model, 'XAUUSD', 'H1', [...$outcome, 'paper_gate_passed' => true]);
        $this->assertSame('e4_evidence_ready', $paperAuthority['status']);
        $this->assertTrue($service->championEligible($model, 'XAUUSD', 'H1'));
        $laboratory = \App\Models\AiLaboratory::create(['symbol' => 'XAUUSD', 'name' => 'future-paper-fixture',
            'timeframe' => 'H1', 'strategy_families' => ['hybrid']]);
        $generation = \App\Models\LabGeneration::create(['ai_laboratory_id' => $laboratory->id, 'generation' => 1]);
        $agent = \App\Models\LabAgent::withoutEvents(fn () => \App\Models\LabAgent::create([
            'lab_generation_id' => $generation->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'origin' => 'fixture']));
        $creditService = app(\App\Services\ParentAwareCreditService::class);
        $creditMetrics = ['net_profit_percent' => .1, 'sample_count' => 1, 'paper_window' => $outcome];
        $credit = $creditService->recordPaperPerformance($agent, $candidate, $creditMetrics, $paperAuthority);
        $this->assertSame('positive_absolute_prospective_paper_settlement', $credit['status']);
        $this->assertSame($credit, $creditService->recordPaperPerformance($agent, $candidate, $creditMetrics, $paperAuthority));
        $this->assertDatabaseCount('lab_evolution_credit_events', 1);
        $this->travelTo(CarbonImmutable::parse('2029-01-01T00:00:00Z'));
        $this->assertFalse($service->observationReadiness($model, 'XAUUSD', 'H1')['allowed']);
        $this->assertTrue($service->championEligible($model, 'XAUUSD', 'H1'));
        $guard = new \ReflectionMethod($trading, 'frozenPaperGuard');
        $this->assertTrue($guard->invoke($trading, $candidate, null, false)['allowed']);
        $this->assertFalse($guard->invoke($trading, $candidate)['allowed']);
        $this->assertDatabaseCount('paper_orders', 0);
    }

    public function test_withdrawing_a_research_authorization_cannot_reuse_observed_window_for_paper(): void
    {
        $this->travelTo(CarbonImmutable::parse('2029-01-01T00:00:00Z'));
        config()->set('services.research_paper_epochs.authorized_paper_epochs', [$this->futureEpoch()]);
        config()->set('services.instrument_policy.authorized_research_windows', []);
        $pair = \App\Models\LabLearningLanePair::create(['pair_key' => 'original-observed-window',
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid',
            'independent_window_key' => 'observed-original-window',
            'metadata' => ['instrument_research_window_receipt' => [
                'start_inclusive' => '2028-02-01T00:00:00Z', 'end_exclusive' => '2028-03-01T00:00:00Z']]]);
        $before = $pair->fresh()->metadata;
        $this->assertNull(app(ResearchPaperEpochContractService::class)->paperContractForCandidate('paper_2028', '2027-01-15T00:00:00Z'));
        $this->assertSame($before, $pair->fresh()->metadata);
    }

    public function test_future_epoch_does_not_authorize_unverified_post_paper_training_provenance(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-01-15T00:00:00Z'));
        config()->set('services.research_paper_epochs.authorized_paper_epochs', [$this->futureEpoch()]);
        $model = $this->model('post-paper-trained'); $this->prePaperAuthority($model);
        $service = app(PaperAuthorityAdmissionService::class);
        $result = $service->admit($model, 'XAUUSD', 'H1', [...$this->passport(),
            'paper_window_key' => 'paper_2028', 'training_pre_2026' => false,
            'training_selection_provenance_verified' => true]);
        $this->assertSame('withheld', $result['status']);
        $this->assertSame('BLOCKED_DEPENDENCY', $result['dependency_status']);
        $this->assertSame('RESEARCH_TRAINING_SELECTION_PROVENANCE_UNVERIFIED', $result['reason_code']);
        $this->assertNull(DB::table('paper_authority_admissions')->value('frozen_at'));
    }

    public function test_route_seals_distinguish_historical_research_from_missing_independent_authority(): void
    {
        $epochs = app(ResearchPaperEpochContractService::class);
        $source = ['source_data_hash' => str_repeat('a', 64), 'source_execution_hash' => str_repeat('b', 64), 'hypothesis_key' => 'scope'];
        $historical = $epochs->confirmationRoute('historical_causal_confirmation', $source);
        $instrument = $epochs->confirmationRoute('instrument_independent_validation', $source);
        $this->assertSame('historical_causal_research', $historical['authority_route']);
        $this->assertSame('existing_frozen_causal_contract', $historical['historical_research_execution_policy']);
        $this->assertSame('authorized_post_paper_independent_validation', $instrument['authority_route']);
        $this->assertSame('BLOCKED_DEPENDENCY', $instrument['independent_validation_status']);
        $this->assertSame(3, $instrument['minimum_powered_windows']);
        $this->assertSame(2, $instrument['minimum_positive_windows']);
        $this->assertFalse($instrument['blinded_comparator_required']);
        $this->assertSame('InstrumentValidationEvidenceService', $instrument['confirmation_owner']);
        $this->assertSame(3, $instrument['minimum_paired_context_trades']);
        $this->assertSame(6, $historical['minimum_powered_windows']);
        $this->assertSame(4, $historical['minimum_positive_windows']);
        $this->assertSame($historical, $epochs->confirmationRoute('historical_causal_confirmation', $source));
        $this->assertNotSame($historical['route_hash'], $epochs->confirmationRoute('historical_causal_confirmation', [...$source, 'hypothesis_key' => 'other'])['route_hash']);
        $this->assertSame('UNKNOWN_CONFIRMATION_ROUTE', $epochs->confirmationRoute('arbitrary', $source)['reason_code']);
        $plan = app(ActivationValidationPlanService::class)->reserve($source);
        $this->assertTrue(app(ActivationValidationPlanService::class)->valid($plan, $plan));
        $this->assertSame('BLOCKED_DEPENDENCY', $plan['dependency_status']);
        $this->assertSame('activation_independent_validation', data_get($plan, 'confirmation_route.purpose'));
        $this->assertFalse($plan['executable']);
    }

    public function test_activation_period_cannot_overlap_explicit_future_paper(): void
    {
        config()->set('services.research_paper_epochs.authorized_paper_epochs', [$this->futureEpoch([
            'window_key' => 'paper_2027', 'start_inclusive' => '2027-01-01T00:00:00Z',
            'end_exclusive' => '2028-01-01T00:00:00Z'])]);
        $plan = app(ActivationValidationPlanService::class)->reserve(['hypothesis_key' => 'overlap']);
        $this->assertFalse($plan['validation_period_disjoint_from_paper']);
        $this->assertSame('RESEARCH_VALIDATION_OVERLAPS_PAPER_EPOCH', $plan['reason_code']);
        $this->assertFalse($plan['executable']);
    }

    public function test_legacy_activation_reservation_is_valid_but_not_rewritten_or_upgraded(): void
    {
        $service = app(ActivationValidationPlanService::class);
        $modern = $service->reserve(['hypothesis_key' => 'legacy-source']);
        $legacy = array_diff_key($modern, array_flip(['validation_period_disjoint_from_paper',
            'confirmation_route', 'dependency_status', 'reason_code']));
        $legacy['protocol'] = ActivationValidationPlanService::LEGACY_PROTOCOL;
        $identity = array_diff_key($legacy, array_flip(['plan_hash', 'status', 'executable', 'promotion_evidence']));
        $legacy['plan_hash'] = hash('sha256', json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
        $before = $legacy;
        $this->assertTrue($service->valid($legacy, $legacy));
        $this->assertSame($before, $legacy);
        $this->assertFalse($legacy['executable']);
        $this->assertArrayNotHasKey('confirmation_route', $legacy);
        $this->assertSame(ActivationValidationPlanService::PROTOCOL, $service->reserve($legacy)['protocol']);
        $this->assertNotSame($legacy, $service->reserve($legacy));
    }

    private function futureEpoch(array $overrides = []): array
    {
        return [...[
            'protocol' => ResearchPaperEpochContractService::FUTURE_PAPER_PROTOCOL,
            'window_key' => 'paper_2028', 'authorization_id' => 'approved-forward-2028',
            'purpose' => 'prospective_paper_forward', 'approved' => true,
            'authorized_at' => '2026-12-15T00:00:00Z',
            'start_inclusive' => '2028-01-01T00:00:00Z', 'end_exclusive' => '2029-01-01T00:00:00Z',
            'candidate_must_be_frozen_before_observation' => true, 'research_uses_forbidden' => true,
        ], ...$overrides];
    }

    public function test_2026_is_paper_only_and_outside_dates_are_rejected(): void
    {
        $epochs = app(ResearchPaperEpochContractService::class);
        $contract = $epochs->contract();

        $this->assertSame('2026-01-01T00:00:00+00:00', data_get($contract, 'research_epoch.end_exclusive'));
        $this->assertSame('paper_2026', data_get($contract, 'paper_epoch.window_key'));
        $this->assertFalse($contract['paper_used_for_screening']);
        $this->assertFalse($contract['paper_used_for_mutation']);
        $this->assertFalse($contract['paper_used_for_selection']);
        $this->assertFalse($contract['paper_used_for_posterior_update']);
        $this->assertTrue($contract['paper_used_for_forward_evidence']);
        $this->assertTrue($epochs->paperWindowValid([
            '2026-01-01T00:00:00Z',
            '2026-12-31T23:59:59Z',
        ], 'paper_2026'));
        $this->assertFalse($epochs->paperWindowValid(['2025-12-31T23:59:59Z'], 'paper_2026'));
        $this->assertFalse($epochs->paperWindowValid(['2027-01-01T00:00:00Z'], 'paper_2026'));
    }

    public function test_pre_paper_candidate_freezes_once_and_only_valid_2026_evidence_opens_e4(): void
    {
        $model = $this->model('epoch-candidate');
        $this->prePaperAuthority($model);
        $service = app(PaperAuthorityAdmissionService::class);
        $passport = $this->passport();

        $first = $service->admit($model, 'XAUUSD', 'H1', $passport);
        $this->assertSame('e3_paper_candidate', $first['status']);
        $frozenAt = DB::table('paper_authority_admissions')->value('frozen_at');

        $second = $service->admit($model->fresh(), 'XAUUSD', 'H1', $passport);
        $this->assertSame('e3_paper_candidate', $second['status']);
        $this->assertTrue($second['idempotent']);
        $this->assertSame($frozenAt, DB::table('paper_authority_admissions')->value('frozen_at'));

        $epochs = app(ResearchPaperEpochContractService::class);
        $outcome = $service->recordProspectiveOutcome($model->fresh(), 'XAUUSD', 'H1', [
            'prospective_after_freeze' => true,
            'parameter_hash_matches_passport' => true,
            'discipline_audit_passed' => true,
            'paper_gate_passed' => true,
            'paper_window_key' => 'paper_2026',
            'paper_observation_times' => ['2026-02-01T00:00:00Z', '2026-09-01T00:00:00Z'],
            'paper_used_for_screening' => false,
            'paper_used_for_mutation' => false,
            'paper_used_for_selection' => false,
            'paper_used_for_posterior_update' => false,
            'epoch_contract' => $epochs->contract(),
        ]);

        $this->assertSame('e4_evidence_ready', $outcome['status']);
        $this->assertTrue($service->championEligible($model, 'XAUUSD', 'H1'));
    }

    public function test_invalid_epoch_cannot_become_forward_or_parent_evidence(): void
    {
        $model = $this->model('invalid-epoch-candidate');
        $this->prePaperAuthority($model);
        $service = app(PaperAuthorityAdmissionService::class);
        $service->admit($model, 'XAUUSD', 'H1', $this->passport());

        $outcome = $service->recordProspectiveOutcome($model, 'XAUUSD', 'H1', [
            'prospective_after_freeze' => true,
            'parameter_hash_matches_passport' => true,
            'discipline_audit_passed' => true,
            'paper_gate_passed' => true,
            'paper_window_key' => 'paper_2026',
            'paper_observation_times' => ['2027-01-01T00:00:00Z'],
            'paper_used_for_screening' => false,
            'paper_used_for_mutation' => false,
            'paper_used_for_selection' => false,
            'paper_used_for_posterior_update' => false,
            'epoch_contract' => app(ResearchPaperEpochContractService::class)->contract(),
        ]);

        $this->assertSame('e3_paper_candidate', $outcome['status']);
        $this->assertFalse($service->championEligible($model, 'XAUUSD', 'H1'));
        $this->assertFalse((bool) data_get(
            json_decode((string) DB::table('paper_authority_admissions')->value('evidence'), true),
            'e4_conditions.epochValid',
        ));
    }

    public function test_cached_e3_admission_cannot_authorize_changed_parameters_or_a_new_passport(): void
    {
        $model = $this->model('drift-candidate');
        $this->prePaperAuthority($model);
        $service = app(PaperAuthorityAdmissionService::class);
        $passport = $this->passport();
        $service->admit($model, 'XAUUSD', 'H1', $passport);
        $frozen = DB::table('paper_authority_admissions')->first();
        $model->fresh()->update(['parameters' => ['entry_threshold' => 9, 'risk_multiplier' => .5]]);
        $this->assertFalse($service->verifyFrozenCandidate($model, 'XAUUSD', 'H1')['allowed']);
        $result = $service->admit($model, 'XAUUSD', 'H1', [...$passport, 'passport_hash' => str_repeat('9', 64)]);
        $this->assertSame('withheld', $result['status']);
        $this->assertSame('PAPER_FROZEN_CANDIDATE_DRIFT', $result['reason_code']);
        $this->assertSame(1, DB::table('paper_authority_admissions')->count());
        $this->assertSame($frozen->evidence, DB::table('paper_authority_admissions')->value('evidence'));
        $this->assertSame($frozen->frozen_at, DB::table('paper_authority_admissions')->value('frozen_at'));
    }

    public function test_component_and_execution_drift_are_not_hidden_by_equal_parameters(): void
    {
        $model = $this->model('component-candidate');
        $this->prePaperAuthority($model);
        $service = app(PaperAuthorityAdmissionService::class);
        $service->admit($model, 'XAUUSD', 'H1', $this->passport());
        $this->assertFalse($service->verifyFrozenCandidate($model, 'XAUUSD', 'H1', [
            ...$this->passport(), 'execution_hash' => str_repeat('2', 64),
        ])['allowed']);
        $model->fresh()->update(['metadata' => ['trade_management' => ['hash' => 'changed', 'profile' => 'different']]]);
        $this->assertFalse($service->verifyFrozenCandidate($model, 'XAUUSD', 'H1')['allowed']);
    }

    public function test_legacy_admission_without_identity_is_not_retroactively_refrozen(): void
    {
        $model = $this->model('legacy-candidate');
        $this->prePaperAuthority($model);
        $service = app(PaperAuthorityAdmissionService::class);
        $service->admit($model, 'XAUUSD', 'H1', $this->passport());
        DB::table('paper_authority_admissions')->update(['evidence' => json_encode(['parameter_hash' => 'legacy'])]);
        $this->assertSame('PAPER_FROZEN_IDENTITY_MISSING',
            $service->admit($model, 'XAUUSD', 'H1', $this->passport())['reason_code']);
        $this->assertSame(json_encode(['parameter_hash' => 'legacy']), DB::table('paper_authority_admissions')->value('evidence'));
    }

    public function test_capture_and_pending_execution_block_before_http_on_parameter_drift(): void
    {
        $model = $this->model('transport-drift');
        $this->prePaperAuthority($model);
        app(PaperAuthorityAdmissionService::class)->admit($model, 'XAUUSD', 'H1', $this->passport());
        $candidate = \App\Models\ModelMarketPerformance::create([
            'model_version_id' => $model->id, 'symbol' => 'XAUUSD', 'timeframe' => 'H1',
            'strategy_family' => 'hybrid', 'status' => 'forward_validated', 'evidence_status' => 'valid',
            'metrics' => ['execution_contract' => ['execution_hash' => $this->passport()['execution_hash']]],
        ]);
        $candidate->setRelation('modelVersion', $model);
        $signal = \App\Models\PaperSignal::create([
            'model_market_performance_id' => $candidate->id, 'model_version_id' => $model->id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'candle_time' => '2026-09-01T12:00:00Z',
            'decision' => 'BUY', 'price' => 2500, 'confidence' => 80, 'market_regime' => 'trend_up',
            'volatility_regime' => 'normal', 'payload' => [], 'payload_hash' => str_repeat('a', 64),
        ]);
        $model->fresh()->update(['parameters' => ['entry_threshold' => 5]]);
        \Illuminate\Support\Facades\Http::fake();
        $service = app(\App\Services\PaperTradingExecutionService::class);
        $this->assertSame(0, (new \ReflectionMethod($service, 'captureLatestSignal'))->invoke($service, $candidate, collect([$candidate])));
        $this->assertSame(0, (new \ReflectionMethod($service, 'executePendingSignal'))->invoke($service, $candidate));
        \Illuminate\Support\Facades\Http::assertNothingSent();
        $this->assertSame(0, \App\Models\PaperOrder::count());
        $this->assertSame(1, \App\Models\PaperSignal::count());
    }

    private function model(string $name): ModelVersion
    {
        return ModelVersion::create([
            'name' => $name,
            'strategy' => $name,
            'version' => 'v1',
            'generation' => 1,
            'status' => 'testing',
            'parameters' => ['entry_threshold' => 1.25, 'risk_multiplier' => .5],
            'metadata' => [],
            'evidence_status' => 'valid',
        ]);
    }

    private function prePaperAuthority(ModelVersion $model): void
    {
        $checks = [
            'research_mentor_authority' => true,
            'screening_passed' => true,
            'full_replay_passed' => true,
            'positive_absolute_settlement' => true,
            'forward_or_paper_evidence' => false,
            'performance_credit_earned' => false,
            'two_improving_descendants' => true,
            'two_inheritance_credits_earned' => true,
            'context_trust_confirmed' => true,
        ];
        DB::table('evolutionary_authority_ledgers')->insert([
            'authority_key' => hash('sha256', 'pre-paper-'.$model->id),
            'model_version_id' => $model->id,
            'lab_agent_id' => null,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'hybrid',
            'authority_stage' => 'breeder_candidate',
            'status' => 'research_mentor_granted',
            'data_hash' => str_repeat('a', 64),
            'execution_hash' => str_repeat('b', 64),
            'evidence' => json_encode([
                'incubation_passed' => true,
                'passport' => ['passed' => true],
                'research_mentor_authority' => ['eligible' => true],
                'economic_parent_authority' => ['eligible' => false, 'checks' => $checks],
                'promotion_evidence' => false,
            ]),
            'evaluated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    private function passport(): array
    {
        return [
            'passport_hash' => str_repeat('c', 64),
            'execution_hash' => str_repeat('d', 64),
            'confirmation_entry_hash' => str_repeat('e', 64),
            'risk_governor_hash' => str_repeat('f', 64),
            'trade_management_hash' => str_repeat('1', 64),
            'training_pre_2026' => true,
        ];
    }
}
