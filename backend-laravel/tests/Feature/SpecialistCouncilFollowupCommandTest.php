<?php

namespace Tests\Feature;

use App\Services\SpecialistCouncilResearchFeedbackService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SpecialistCouncilFollowupCommandTest extends TestCase
{
    public function test_existing_generation_source_amendment_keeps_its_original_feedback_owner(): void
    {
        Queue::fake(); Http::fake();
        $owner = $this->mock(SpecialistCouncilResearchFeedbackService::class);
        $owner->shouldNotReceive('amendUnbuiltFollowupSource');
        $owner->shouldReceive('amendUnobservedFollowupSource')->once()
            ->with(7, 259, 'original-research-creator', 'Repair same-question constructor source only.')
            ->andReturn(['status' => 'registered', 'allowed' => true, 'promotion_evidence' => false]);
        $this->artisan('trading:specialist-council', ['action' => 'amend-followup-source', '--work-id' => '7',
            '--generation-id' => '259', '--actor' => 'original-research-creator',
            '--reason' => 'Repair same-question constructor source only.'])->assertSuccessful();
        Queue::assertNothingPushed(); Http::assertNothingSent();
    }

    public function test_explicit_unbuilt_amendment_delivers_only_original_work_and_attribution_to_same_owner(): void
    {
        Queue::fake(); Http::fake();
        $owner = $this->mock(SpecialistCouncilResearchFeedbackService::class);
        $owner->shouldNotReceive('amendUnobservedFollowupSource');
        $owner->shouldNotReceive('registerFollowupProof');
        $owner->shouldReceive('amendUnbuiltFollowupSource')->once()
            ->with(9, 'original-research-creator', 'Repair pre-persistence source guard only.')
            ->andReturn(['status' => 'registered', 'allowed' => true, 'generation_id' => null,
                'new_scientific_attempt' => false, 'promotion_evidence' => false]);
        $this->artisan('trading:specialist-council', ['action' => 'amend-followup-source', '--unbuilt' => true,
            '--work-id' => '9', '--actor' => 'original-research-creator',
            '--reason' => 'Repair pre-persistence source guard only.'])->assertSuccessful();
        Queue::assertNothingPushed(); Http::assertNothingSent();
    }

    public function test_unbuilt_amendment_refuses_contradictory_mode_and_all_file_proposals_before_owner(): void
    {
        $owner = $this->mock(SpecialistCouncilResearchFeedbackService::class);
        $owner->shouldNotReceive('amendUnbuiltFollowupSource');
        $owner->shouldNotReceive('amendUnobservedFollowupSource');
        $base = ['action' => 'amend-followup-source', '--unbuilt' => true, '--work-id' => '9',
            '--actor' => 'original-research-creator', '--reason' => 'Repair pre-persistence source guard only.'];
        foreach (['259', '0', 'not-a-native-id'] as $id) {
            $this->artisan('trading:specialist-council', [...$base, '--generation-id' => $id])
                ->expectsOutputToContain('COUNCIL_SOURCE_AMENDMENT_GENERATION_MODE_CONFLICT')->assertFailed();
        }
        foreach (['--preparation', '--followup-plan', '--manifest', '--evaluation-plan'] as $option) {
            $this->artisan('trading:specialist-council', [...$base, $option => 'C:/unregistered-council-proof.json'])
                ->expectsOutputToContain('COUNCIL_SOURCE_AMENDMENT_FILE_INPUT_FORBIDDEN')->assertFailed();
        }
        $this->artisan('trading:specialist-council', [...$base, 'action' => 'register-followup'])
            ->expectsOutputToContain('COUNCIL_SOURCE_AMENDMENT_MODE_INVALID')->assertFailed();
    }

    public function test_source_amendment_refuses_bad_identity_or_attribution_before_owner(): void
    {
        $owner = $this->mock(SpecialistCouncilResearchFeedbackService::class);
        $owner->shouldNotReceive('amendUnbuiltFollowupSource');
        $owner->shouldNotReceive('amendUnobservedFollowupSource');
        $base = ['action' => 'amend-followup-source', '--unbuilt' => true, '--work-id' => '9',
            '--actor' => 'original-research-creator', '--reason' => 'Repair pre-persistence source guard only.'];
        foreach (['0', '-1', 'not-a-native-id'] as $id) {
            $this->artisan('trading:specialist-council', [...$base, '--work-id' => $id])
                ->expectsOutputToContain('POSITIVE_NATIVE_ID_REQUIRED:work-id')->assertFailed();
        }
        foreach ([['--actor', ''], ['--reason', '']] as [$option, $value]) {
            $this->artisan('trading:specialist-council', [...$base, $option => $value])
                ->expectsOutputToContain('SPECIALIST_COUNCIL_OPTION_REQUIRED:'.substr($option, 2))->assertFailed();
        }
        foreach ([['--actor', 'unattributable actor'], ['--actor', str_repeat('a', 121)],
            ['--reason', '   '], ['--reason', ' padded reason '], ['--reason', str_repeat('a', 501)]] as [$option, $value]) {
            $this->artisan('trading:specialist-council', [...$base, $option => $value])
                ->expectsOutputToContain('COUNCIL_SOURCE_AMENDMENT_ATTRIBUTION_REQUIRED')->assertFailed();
        }
        $this->artisan('trading:specialist-council', [...$base, '--unbuilt' => false, '--generation-id' => '0'])
            ->expectsOutputToContain('POSITIVE_NATIVE_ID_REQUIRED:generation-id')->assertFailed();
    }

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
