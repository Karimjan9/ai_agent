<?php

namespace Tests\Feature;

use App\Models\ModelVersion;
use App\Services\PaperAuthorityAdmissionService;
use App\Services\ResearchPaperEpochContractService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResearchPaperEpochContractTest extends TestCase
{
    use RefreshDatabase;

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
