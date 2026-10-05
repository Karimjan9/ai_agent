<?php

namespace Tests\Feature;

use App\Services\SpecialistCouncilResearchFeedbackService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SpecialistCouncilFollowupCommandTest extends TestCase
{
    public function test_followup_registration_delivers_bounded_prospective_contract_and_actor_to_original_owner(): void
    {
        Storage::fake('local');
        $proposal = ['protocol' => 'specialist_council_followup_v1', 'research_only' => true];
        Storage::disk('local')->put('council-followup/proposal.json', json_encode($proposal, JSON_THROW_ON_ERROR));
        $owner = $this->mock(SpecialistCouncilResearchFeedbackService::class);
        $owner->shouldReceive('registerFollowupProof')->once()->with(7, $proposal, 'original-research-creator')
            ->andReturn(['status' => 'prerequisite_registered', 'allowed' => true, 'promotion_evidence' => false]);
        $this->artisan('trading:specialist-council', ['action' => 'register-followup', '--work-id' => '7',
            '--followup-plan' => Storage::disk('local')->path('council-followup/proposal.json'),
            '--actor' => 'original-research-creator'])->assertSuccessful();
    }

    public function test_followup_registration_refuses_missing_actor_before_owner_mutation(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('council-followup/proposal.json', '{"research_only":true}');
        $owner = $this->mock(SpecialistCouncilResearchFeedbackService::class);
        $owner->shouldNotReceive('registerFollowupProof');
        $this->artisan('trading:specialist-council', ['action' => 'register-followup', '--work-id' => '7',
            '--followup-plan' => Storage::disk('local')->path('council-followup/proposal.json')])
            ->expectsOutputToContain('SPECIALIST_COUNCIL_OPTION_REQUIRED:actor')->assertFailed();
    }

    public function test_followup_registration_refuses_non_workspace_proof_before_owner_mutation(): void
    {
        $owner = $this->mock(SpecialistCouncilResearchFeedbackService::class);
        $owner->shouldNotReceive('registerFollowupProof');
        $this->artisan('trading:specialist-council', ['action' => 'register-followup', '--work-id' => '7',
            '--followup-plan' => 'C:/unregistered-council-proof.json', '--actor' => 'original-research-creator'])
            ->expectsOutputToContain('SPECIALIST_COUNCIL_JSON_REQUIRES_BOUNDED_WORKSPACE_FILE')->assertFailed();
    }

    public function test_followup_registration_refuses_nonpositive_identity_before_owner_mutation(): void
    {
        $owner = $this->mock(SpecialistCouncilResearchFeedbackService::class);
        $owner->shouldNotReceive('registerFollowupProof');
        $this->artisan('trading:specialist-council', ['action' => 'register-followup', '--work-id' => '0'])
            ->expectsOutputToContain('POSITIVE_NATIVE_ID_REQUIRED:work-id')->assertFailed();
    }
}
