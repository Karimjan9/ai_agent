<?php

namespace Tests\Feature;

use App\Services\CuratedInstrumentMethodCatalogService;
use App\Services\InstrumentMethodLibraryService;
use App\Services\ResearchPaperEpochContractService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Source-scoped instruction correctness, not original market outcomes. */
class CuratorContextKnowHowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }

    public function test_original_curriculum_hashes_are_preserved(): void
    {
        $owner = app(CuratedInstrumentMethodCatalogService::class);
        $originals = [];
        foreach ([$owner::OPERATING_CURRICULUM_KEY, $owner::SOURCE_PRACTICE_CURRICULUM_KEY,
            $owner::CURATOR_PRACTICE_CURRICULUM_KEY] as $key) {
            $curriculum = $owner->operatingCurriculum($key);
            $this->assertSame('operating_skill_curriculum', $curriculum['status'], json_encode($curriculum));
            $originals[$key] = $curriculum['curriculum_hash'];
        }
        $this->assertSame([
            'source_taught_operating_skills_20261014_v1' => '95a46db8c67bf9ab92cfe0a1a85d2e81e79ccf5e7858ae73bef86be4a4918314',
            'source_taught_operating_skills_20261015_v2' => '0b95896dcb088eac676c3c888e9ab016ea4f57c24ca0e6fb19cbecf048a7cbb6',
            'curator_practical_knowhow_20261015_v3' => '94fcbca88aa64530592b394f6b4a120cebb41b81b8606d23b2830cfec8f7464e',
        ], $originals);
        $new = $owner->operatingCurriculum($owner::CONTEXT_KNOWHOW_CURRICULUM_KEY);
        $this->assertSame('operating_skill_curriculum', $new['status'], json_encode($new));
        foreach ($originals as $key => $hash) $this->assertSame($hash, $owner->operatingCurriculum($key)['curriculum_hash']);
        $this->assertSame($owner::OPERATING_CURRICULUM_KEY, $owner->operatingCurriculum()['curriculum_key']);
        $this->assertNoOwnLearningOrAuthority();
    }

    public function test_explicit_v4_retains_twenty_six_instruments_and_adds_exact_pending_scopes_not_outcomes(): void
    {
        $owner = app(CuratedInstrumentMethodCatalogService::class);
        $v3 = $owner->operatingCurriculum($owner::CURATOR_PRACTICE_CURRICULUM_KEY);
        $v4 = $owner->operatingCurriculum($owner::CONTEXT_KNOWHOW_CURRICULUM_KEY);
        $this->assertSame('operating_skill_curriculum', $v4['status'], json_encode($v4));
        $this->assertCount(26, $v4['lessons']);
        $this->assertSame($owner::CONTEXT_PRACTICE_SHA256, $v4['manifest_parts']['curator_context_practice_v4']);
        $practice = $v4['curator_contextual_practice'];
        $this->assertCount(24, $practice['method_profiles']);
        $this->assertSame('awaiting_original_panel', $practice['evidence_state']);
        $this->assertSame('curator_source_research', $practice['attribution']);
        $this->assertArrayNotHasKey('results', $practice);
        $this->assertArrayNotHasKey('observations', $practice);
        $this->assertSame($v3['curator_practical_knowhow'], $v4['curator_practical_knowhow']);
        foreach ($practice['method_profiles'] as $ref => $profile) {
            $this->assertContains($ref, $v4['lessons']['regime_router']['additional_method_refs']);
            $this->assertSame(['regime', 'session', 'volatility'], array_keys($profile['context_predicate']));
            foreach ($profile['context_predicate'] as $axis) $this->assertIsString($axis);
            $this->assertContains($profile['style'], ['dmi', 'strong']);
            $this->assertSame('confirmation', $profile['target']);
            $this->assertSame('awaiting_original_panel', $profile['evidence_state']);
            $this->assertNotEmpty($profile['operating_steps']); $this->assertNotEmpty($profile['wait_when']);
            $this->assertNotEmpty($profile['source_vs_lab']); $this->assertNotEmpty($profile['unknown_scope']);
            foreach (['own_agent_outcome', 'independent_validation', 'skill_credit', 'agent_skill_earned',
                'confirmation_proven', 'qualification_granted', 'economic_authority', 'paper_authority', 'live_authority'] as $flag)
                $this->assertFalse($profile[$flag]);
        }
        $this->assertDatabaseCount('instrument_method_sources', 0);
        $this->assertDatabaseCount('instrument_method_revisions', 0);
        $this->assertNoOwnLearningOrAuthority();
    }

    public function test_selected_scoped_teaching_projects_only_its_method_context_and_reviewed_sources(): void
    {
        [$owner, $installed] = $this->installed();
        $keys = ['curator_dmi_trend_up_london_normal_v1', 'curator_strong_trend_down_new_york_high_v1'];
        $hashes = array_map(fn (string $key): string => $installed['methods'][$key]['recipe_hash'], $keys);
        $pack = $owner->teachingPack($hashes, $owner::CONTEXT_KNOWHOW_CURRICULUM_KEY);
        $this->assertSame('operating_rules_taught', $pack['status'], json_encode($pack));
        $expected = array_map(fn (string $key): string => $key.'@1', $keys); sort($expected);
        $practice = $pack['curator_contextual_practice'];
        $this->assertSame($expected, array_keys($practice['method_profiles']));
        $this->assertSame($expected, array_keys($pack['lessons']['regime_router']['contextual_operating_practice']));
        $this->assertSame($expected, array_values(array_intersect($expected, $pack['lessons']['regime_router']['additional_method_refs'])));
        foreach ($pack['lessons']['regime_router']['additional_method_refs'] as $ref)
            if (str_starts_with($ref, 'curator_')) $this->assertContains($ref, $expected);
        $this->assertSame('exact_selected_contextual_method_refs', $practice['instruction_projection']['scope']);
        $this->assertCount(2, $practice['source_refs']);
        foreach ($practice['source_refs'] as $sourceRef) {
            $this->assertArrayHasKey($sourceRef, $pack['sources']);
            $this->assertNotEmpty($pack['sources'][$sourceRef]['reviewed_scope']);
            $this->assertNotEmpty($pack['sources'][$sourceRef]['not_reviewed']);
            $this->assertFalse($pack['sources'][$sourceRef]['qualification_granted']);
        }
        $this->assertSame([], $pack['curator_practical_knowhow']['curator_observations']['results']);
        $this->assertArrayNotHasKey('curator_advice', $pack);
        $this->assertSame('eloquent_metadata_json_numbers_v1', $pack['instruction_transport']);
        $this->assertTrue($owner->verifyTeachingPack($pack, $hashes));
        $transported = json_decode(json_encode($pack, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);
        $this->assertTrue($owner->verifyTeachingPack($transported, $hashes));
        $advice = $owner->curatorPracticeAdvice($pack, []);
        $this->assertSame('blocked', $advice['status']);
        $this->assertSame('OPERATING_CURATOR_ADVICE_PACK_REQUIRED', $advice['reason']);
        $this->assertNoOwnLearningOrAuthority();
    }

    public function test_new_context_refs_never_implicitly_opt_into_v4_or_old_marginal_ranking(): void
    {
        [$owner, $installed] = $this->installed();
        $hashes = [$installed['methods']['curator_dmi_trend_up_london_normal_v1']['recipe_hash']];
        foreach ([null, $owner::OPERATING_CURRICULUM_KEY, $owner::SOURCE_PRACTICE_CURRICULUM_KEY,
            $owner::CURATOR_PRACTICE_CURRICULUM_KEY] as $key) {
            $old = $owner->teachingPack($hashes, $key);
            $this->assertSame('unmapped', $old['status'], json_encode($old));
            $this->assertSame('OPERATING_RECIPE_NOT_IN_REVIEWED_LESSONS', $old['reason']);
        }
        $this->assertNoOwnLearningOrAuthority();
    }

    public function test_context_source_instruction_and_authority_drift_in_a_teaching_pack_are_refused(): void
    {
        [$owner, $installed] = $this->installed();
        $ref = 'curator_dmi_trend_up_london_normal_v1@1';
        $hashes = [$installed['methods']['curator_dmi_trend_up_london_normal_v1']['recipe_hash']];
        $pack = $owner->teachingPack($hashes, $owner::CONTEXT_KNOWHOW_CURRICULUM_KEY);
        $this->assertSame('operating_rules_taught', $pack['status'], json_encode($pack));
        $bad = $pack; $bad['curator_contextual_practice']['method_profiles'][$ref]['context_predicate']['session'] = 'new_york';
        $this->assertFalse($owner->verifyTeachingPack($bad, $hashes));
        $bad = $pack; $source = $bad['curator_contextual_practice']['source_refs'][0];
        $bad['sources'][$source]['source_key'] = str_repeat('0', 64);
        $this->assertFalse($owner->verifyTeachingPack($bad, $hashes));
        $bad = $pack; $bad['lessons']['regime_router']['contextual_operating_practice'][$ref]['operating_steps'] = ['Caller substituted'];
        $this->assertFalse($owner->verifyTeachingPack($bad, $hashes));
        $bad = $pack; $bad['curator_contextual_practice']['evidence_state'] = 'confirmed';
        $this->assertFalse($owner->verifyTeachingPack($bad, $hashes));
        $bad = $pack; $bad['curator_contextual_practice']['skill_credit'] = true;
        $this->assertFalse($owner->verifyTeachingPack($bad, $hashes));
        $this->assertNoOwnLearningOrAuthority();
    }

    public function test_validly_registered_familiar_name_with_substituted_context_still_cannot_be_taught(): void
    {
        $this->assertRegisteredDriftRefused('context');
    }

    public function test_validly_registered_familiar_name_with_substituted_source_still_cannot_be_taught(): void
    {
        $this->assertRegisteredDriftRefused('source');
    }

    private function assertRegisteredDriftRefused(string $kind): void
    {
        $owner = app(CuratedInstrumentMethodCatalogService::class);
        $catalog = $owner->contextPracticeCatalog();
        $library = app(InstrumentMethodLibraryService::class);
        foreach ($catalog['sources'] as $source) {
            $registered = $library->registerSource($source);
            $this->assertSame('registered_reference_only', $registered['status'], json_encode($registered));
        }
        $method = $catalog['methods']['curator_dmi_trend_up_london_normal_v1']['method_input'];
        if ($kind === 'context') $method['context_predicate']['session'] = 'new_york';
        else {
            $different = $catalog['sources']['stockcharts_directional_movement'];
            $different['content'] .= ' Synthetic substituted research source for verification only.';
            $source = $library->registerSource($different);
            $this->assertSame('registered_reference_only', $source['status'], json_encode($source));
            $method['source_keys'] = [$source['source_key']];
        }
        $registered = $library->registerMethod($method);
        $this->assertSame('registered_research_only', $registered['status'], json_encode($registered));
        $recipe = $library->registerRecipe(['recipe_key' => 'context-drift-'.$kind, 'revision' => 1,
            'method_hashes' => [$registered['method_hash']], 'context_predicate' => $method['context_predicate']]);
        $this->assertSame('registered_research_only', $recipe['status'], json_encode($recipe));
        $pack = $owner->teachingPack([$recipe['recipe_hash']], $owner::CONTEXT_KNOWHOW_CURRICULUM_KEY);
        $this->assertSame('blocked', $pack['status'], json_encode($pack));
        $this->assertSame('OPERATING_DECLARED_METHOD_DRIFT', $pack['reason']);
        $this->assertNoOwnLearningOrAuthority();
    }

    private function installed(): array
    {
        $owner = app(CuratedInstrumentMethodCatalogService::class);
        $installed = $owner->installContextPractice();
        $this->assertSame('context_practice_installed', $installed['status'], json_encode($installed));
        $this->assertCount(24, $installed['methods']);
        return [$owner, $installed];
    }

    private function assertNoOwnLearningOrAuthority(): void
    {
        foreach (['agent_method_policy_revisions', 'agent_method_experiences', 'agent_method_owned_outcomes',
            'agent_knowledge_cards', 'agent_learning_lessons', 'mutation_memories', 'instrument_method_meta_models',
            'model_versions', 'lab_evaluation_runs', 'lab_generations', 'research_experiment_work_items',
            'lab_evolution_credit_events', 'jobs'] as $table) $this->assertDatabaseCount($table, 0);
    }
}
