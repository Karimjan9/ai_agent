<?php

namespace Tests\Feature;

use App\Services\SpecialistLeagueChallengeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpecialistLeagueChallengeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_league_admission_requires_independent_and_counterfactual_council_gates(): void
    {
        $service = app(SpecialistLeagueChallengeService::class);
        $entry = $service->enroll(['model_version_id' => 3, 'role' => 'main_specialist', 'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'specialty_key' => 'london_entry'], ['sealed' => true]);
        $settled = $service->settleLeague($entry['league_key'], ['independently_viable' => true]);
        $retained = $service->councilCandidate($entry['league_key'], ['independently_viable' => true]);
        $candidate = $service->councilCandidate($entry['league_key'], [
            'independently_viable' => true, 'complementary' => true, 'low_correlated_failure' => true,
            'exploiter_challenge_passed' => true, 'member_removal_value' => true,
        ]);

        $this->assertSame('challenge_settled', $settled['status']);
        $this->assertSame('league_retained', $retained['status']);
        $this->assertSame('council_candidate', $candidate['status']);
        $this->assertFalse($candidate['promotion_evidence']);
    }

    public function test_open_ended_challenges_are_sealed_pre2026_and_record_transfer_solution_only_as_research(): void
    {
        $service = app(SpecialistLeagueChallengeService::class);
        $blocked = $service->planChallenge(['status' => 'learnable_now'], []);
        $planned = $service->planChallenge([
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'status' => 'learnable_now', 'niche_key' => 'quiet_london',
            'pre_2026_only' => true, 'snapshot_hash' => 'sealed-pre2026-snapshot',
        ], ['confirmed_cartridges' => 1, 'successful_transfers' => 1]);
        $settled = $service->settleChallenge($planned['challenge_key'], ['transfer_solved' => true]);
        $primitiveBound = $service->planChallenge([
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'status' => 'learnable_now', 'niche_key' => 'new_primitive',
            'pre_2026_only' => true, 'snapshot_hash' => 'sealed-pre2026-primitive-snapshot',
        ], ['confirmed_cartridges' => 1, 'successful_transfers' => 1]);
        $unlearnable = $service->settleChallenge($primitiveBound['challenge_key'], ['current_primitives_insufficient' => true]);

        $this->assertSame('blocked', $blocked['status']);
        $this->assertSame('planned', $planned['status']);
        $this->assertSame('solved_by_transfer', $settled['status']);
        $this->assertSame('open_transfer_receipt', $settled['next_action']);
        $this->assertSame('unlearnable_with_current_primitives', $unlearnable['status']);
        $this->assertFalse($settled['promotion_evidence']);
    }
}
