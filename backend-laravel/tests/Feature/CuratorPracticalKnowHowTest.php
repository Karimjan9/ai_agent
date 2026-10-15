<?php

namespace Tests\Feature;

use App\Models\ModelVersion;
use App\Models\AiLaboratory;
use App\Models\LabGeneration;
use App\Services\AgentMethodGenerationService;
use App\Services\AgentMethodLearningService;
use App\Services\CuratedInstrumentMethodCatalogService;
use App\Services\InstrumentMethodLibraryService;
use App\Services\InstrumentMethodSourceService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\StrategyParameterSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Software fixtures below are explicitly synthetic; they establish no market benefit. */
class CuratorPracticalKnowHowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }

    public function test_labelled_synthetic_local_positive_null_and_negative_are_retained_without_any_authority(): void
    {
        [$owner, $method, $row] = $this->syntheticObservation();
        $grade = new ReflectionMethod($owner, 'operatingCuratorEvidence');
        foreach (['local_provisional_positive' => 11.25, 'local_null' => 10.0, 'local_negative' => 8.75] as $expected => $cash) {
            $case = [...$row, 'net_cash' => $cash, 'fixture_scope' => 'synthetic_software_example_not_market_evidence',
                'caller_grade' => 'independently_confirmed'];
            $result = $grade->invoke($owner, $case, $method);
            $this->assertSame($expected, $result['grade']);
            $this->assertSame('curator_observation', $result['attribution']);
            $this->assertTrue($result['usable_as_bounded_research_instruction']);
            foreach (['own_agent_outcome', 'independent_validation', 'agent_skill_earned', 'qualification_granted',
                'skill_credit', 'economic_authority', 'paper_authority', 'live_authority'] as $flag) $this->assertFalse($result[$flag]);
        }
        $this->assertNoLearningOrAuthority();
    }

    public function test_unactivated_missing_data_unmatched_provenance_and_incomplete_execution_never_become_strategy_losses(): void
    {
        [$owner, $method, $row] = $this->syntheticObservation();
        $grade = new ReflectionMethod($owner, 'operatingCuratorEvidence');
        $cases = [
            'unactivated' => ['method_reach' => ['reached' => 0, 'available' => 0, 'activated' => 0, 'changed_decisions' => 0]],
            'unavailable' => ['method_reach' => ['reached' => 4, 'available' => 0, 'activated' => 0, 'changed_decisions' => 0]],
            'partially_unavailable' => ['method_reach' => ['reached' => 4, 'available' => 1, 'activated' => 1, 'changed_decisions' => 4]],
            'no_behavior_change' => ['method_reach' => ['reached' => 4, 'available' => 4, 'activated' => 4, 'changed_decisions' => 0]],
            'technical_or_incomplete' => ['status' => 'timeout', 'timeout' => true],
            'unresolved_provenance' => ['ledger_refs' => []],
        ];
        foreach ($cases as $expected => $patch) {
            $result = $grade->invoke($owner, [...$row, ...$patch], $method);
            $this->assertSame($expected, $result['grade']);
            $this->assertTrue($result['usable_as_bounded_research_instruction']);
            $this->assertFalse($result['qualification_granted']);
        }
        foreach (['source_keys' => [str_repeat('0', 64)], 'target' => 'exit', 'method_ref' => 'substituted@1',
            'control' => [...$row['control'], 'dataset_sha256' => str_repeat('0', 64)]] as $field => $value)
            $this->assertSame('unresolved_provenance', $grade->invoke($owner, [...$row, $field => $value], $method)['grade']);
        foreach ([['reached' => 4, 'available' => 5, 'activated' => 4, 'changed_decisions' => 1],
            ['reached' => 4, 'available' => 2, 'activated' => 3, 'changed_decisions' => 1],
            ['reached' => 4, 'available' => 4, 'activated' => 4, 'changed_decisions' => 5],
            ['reached' => 4, 'available' => 4, 'activated' => -1, 'changed_decisions' => 1]] as $counts)
            $this->assertSame('unresolved_provenance', $grade->invoke($owner,
                [...$row, 'method_reach' => $counts, 'caller_grade' => 'local_provisional_positive'], $method)['grade']);
        $this->assertNoLearningOrAuthority();
    }

    public function test_labelled_synthetic_case_roster_refuses_extra_missing_duplicate_and_substituted_cases(): void
    {
        [$owner, $method, $row] = $this->syntheticObservation();
        $binding = array_intersect_key($row, array_flip(['instrument_key', 'method_ref', 'target', 'source_keys']));
        $declaration = ['fixture_scope' => 'synthetic_roster_not_market_evidence',
            'case_roster' => ['synthetic_atr_fixture' => $binding]];
        $check = new ReflectionMethod($owner, 'operatingCuratorRoster');
        $check->invoke($owner, $declaration, [$row]); $this->addToAssertionCount(1);
        foreach ([[], [$row, $row], [[...$row, 'case_key' => 'unplanned']],
            [[...$row, 'method_ref' => 'substitution@1']], [[...$row, 'source_keys' => []]]] as $rows) {
            try { $check->invoke($owner, $declaration, $rows); $this->fail('Case roster drift must refuse.'); }
            catch (\RuntimeException $error) { $this->assertSame('OPERATING_CURATOR_CASE_ROSTER_DRIFT', $error->getMessage()); }
        }
        $this->assertNoLearningOrAuthority();
    }

    public function test_labelled_synthetic_one_method_projection_retains_only_its_cases_and_keeps_the_full_declaration(): void
    {
        [$owner, $method, $row] = $this->syntheticObservation();
        $other = [...$row, 'case_key' => 'synthetic_other', 'method_ref' => 'internet_signal_score_veto_0_7_v1@1',
            'instrument_key' => 'confidence_firewall'];
        $before = ['fixture_scope' => 'synthetic_projection_not_market_evidence', 'case_roster' => ['selected' => [], 'other' => []]];
        $knowhow = ['before_outcome_declaration' => $before, 'own_agent_outcome' => false,
            'curator_observations' => ['results' => [$row, $other], 'full_panel_fixture' => true]];
        $lessons = [$method['instrument_key'] => ['curator_observations' => [$row, $other]]];
        [$projected, $selectedLessons] = (new ReflectionMethod($owner, 'operatingCuratorProjection'))->invoke(
            $owner, $knowhow, $lessons, [$method]);
        $this->assertSame([$row], $projected['curator_observations']['results']);
        $this->assertSame([$row], $selectedLessons[$method['instrument_key']]['curator_observations']);
        $this->assertSame($before, $projected['before_outcome_declaration']);
        $this->assertSame('exact_selected_method_refs', $projected['observation_projection']['scope']);
        $this->assertFalse($projected['own_agent_outcome']);
        $this->assertCount(2, $knowhow['curator_observations']['results']);
        $this->assertNoLearningOrAuthority();
    }

    public function test_v3_structured_alternatives_are_bounded_documents_not_install_or_execution_authority(): void
    {
        $owner = app(CuratedInstrumentMethodCatalogService::class);
        $check = new ReflectionMethod($owner, 'operatingCuratorAlternative');
        $valid = ['use' => 'Synthetic research alternative, not a market outcome.', 'status' => 'data_conditional_research',
            'difference' => 'No replacement of the installed primary.', 'method_ref' => 'synthetic_document_only@1',
            'rule_math' => 'Documented formula, not executable code.', 'requirements' => ['Separate immutable method registration.']];
        $check->invoke($owner, $valid); $check->invoke($owner, 'Synthetic prose alternative.');
        $this->addToAssertionCount(2);
        foreach ([null, [], '', [...$valid, 'use' => ''], [...$valid, 'difference' => false],
            [...$valid, 'status' => 'qualified_auto_install'], [...$valid, 'execute' => 'arbitrary-code'],
            [...$valid, 'requirements' => []], [...$valid, 'requirements' => ['']],
            [...$valid, 'method_ref' => []], [...$valid, 'rule_math' => null]] as $malformed) {
            try { $check->invoke($owner, $malformed); $this->fail('Malformed alternative document must refuse.'); }
            catch (\RuntimeException $error) { $this->assertSame('OPERATING_INSTRUCTION_REQUIRED', $error->getMessage()); }
        }
        $this->assertDatabaseCount('instrument_method_revisions', 0); $this->assertNoLearningOrAuthority();
    }

    public function test_prospective_curriculum_is_explicit_strict_and_frozen_before_ambient_configuration_changes(): void
    {
        $owner = app(AgentMethodGenerationService::class); $catalog = app(CuratedInstrumentMethodCatalogService::class);
        config()->set('services.instrument_methods.generation_curriculum_key', null);
        $this->assertSame([], $owner->prospectiveCurriculumDeclaration());
        config()->set('services.instrument_methods.generation_curriculum_key', $catalog::CURATOR_PRACTICE_CURRICULUM_KEY);
        $declaration = $owner->prospectiveCurriculumDeclaration();
        $this->assertSame(['curriculum_key' => $catalog::CURATOR_PRACTICE_CURRICULUM_KEY], $declaration);
        $lab = AiLaboratory::create(['name' => 'curator frozen declaration fixture', 'symbol' => 'XAUUSD',
            'timeframe' => 'M5', 'strategy_families' => ['trend']]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'status' => 'draft',
            'trigger_context' => ['instrument_method_generation_learning' => ['enabled' => true, ...$declaration]]]);
        config()->set('services.instrument_methods.generation_curriculum_key', null);
        $this->assertSame([], $owner->prospectiveCurriculumDeclaration());
        $this->assertSame($declaration['curriculum_key'], data_get($generation->fresh()->trigger_context,
            'instrument_method_generation_learning.curriculum_key'));
        config()->set('services.instrument_methods.generation_curriculum_key', 'unknown_latest');
        try { $owner->prospectiveCurriculumDeclaration(); $this->fail('An unknown curriculum must refuse before a new generation seal.'); }
        catch (\LogicException $error) { $this->assertSame('METHOD_GENERATION_CURRICULUM_KEY_REQUIRED', $error->getMessage()); }
        $this->assertSame($declaration['curriculum_key'], data_get($generation->fresh()->trigger_context,
            'instrument_method_generation_learning.curriculum_key'));
        $this->assertDatabaseCount('agent_method_policy_revisions', 0); $this->assertDatabaseCount('jobs', 0);
    }

    public function test_explicit_v3_keeps_all_original_lessons_and_default_packs_and_teaches_the_exact_new_cases(): void
    {
        $owner = app(CuratedInstrumentMethodCatalogService::class);
        $old = $owner->operatingCurriculum();
        $v2 = $owner->operatingCurriculum($owner::SOURCE_PRACTICE_CURRICULUM_KEY);
        $new = $owner->operatingCurriculum($owner::CURATOR_PRACTICE_CURRICULUM_KEY);
        $this->assertSame('operating_skill_curriculum', $new['status'], json_encode($new));
        $this->assertCount(26, $new['lessons']);
        $this->assertSame('curator_practical_research', $new['curator_practical_knowhow']['knowledge_state']);
        $this->assertFalse($new['curator_practical_knowhow']['own_agent_outcome']);
        $this->assertFalse($new['curator_practical_knowhow']['independent_validation']);
        $this->assertNotEmpty($new['curator_practical_knowhow']['before_outcome_declaration']);
        $this->assertNotEmpty($new['curator_practical_knowhow']['curator_observations']['results']);
        foreach ($old['lessons'] as $key => $lesson) {
            $this->assertSame($lesson['primary_method_ref'], $new['lessons'][$key]['primary_method_ref']);
            $this->assertSame($lesson['exercises'], $new['lessons'][$key]['exercises']);
            $profile = $new['lessons'][$key]['curator_practical_profiles'][0];
            $this->assertSame($lesson['primary_method_ref'], $profile['method_ref']);
            $this->assertSame($profile['method_ref'], $profile['method_binding']['method_ref']);
            $this->assertNotEmpty($profile['source_reviews']); $this->assertNotEmpty($profile['runtime_conditions']);
            $this->assertNotEmpty($profile['alternatives']); $this->assertFalse($profile['own_agent_outcome']);
            $this->assertGreaterThanOrEqual(3, count($profile['exercises']));
        }
        $this->assertSame($old, $owner->operatingCurriculum());
        $this->assertSame($v2, $owner->operatingCurriculum($owner::SOURCE_PRACTICE_CURRICULUM_KEY));
        $this->installOriginals();
        $row = DB::table('instrument_method_revisions')->where('method_key', 'internet_closed_regime_alignment_v2')->first();
        $this->assertNotNull($row);
        $library = app(InstrumentMethodLibraryService::class);
        $recipe = $library->registerRecipe(['recipe_key' => 'curator_practical_explicit_fixture', 'revision' => 1,
            'method_hashes' => [$row->method_hash], 'context_predicate' => json_decode($row->contract, true)['context_predicate']]);
        $this->assertSame('registered_research_only', $recipe['status'], json_encode($recipe));
        $hashes = [$recipe['recipe_hash']]; $default = $owner->teachingPack($hashes);
        $pack = $owner->teachingPack($hashes, $owner::CURATOR_PRACTICE_CURRICULUM_KEY);
        $this->assertSame('operating_rules_taught', $pack['status'], json_encode($pack));
        $this->assertSame($owner::OPERATING_CURRICULUM_KEY, $default['curriculum_key']);
        $this->assertArrayNotHasKey('curator_practical_knowhow', $default);
        $this->assertSame($default, $owner->teachingPack($hashes));
        $this->assertTrue($owner->verifyTeachingPack($pack, $hashes));
        $this->assertTrue($owner->verifyTeachingPack(json_decode(json_encode($pack, JSON_THROW_ON_ERROR), true), $hashes));
        $this->assertSame('exact_selected_method_refs', $pack['curator_practical_knowhow']['observation_projection']['scope']);
        $this->assertSame(['internet_closed_regime_alignment_v2@1'], array_values(array_unique(array_column(
            $pack['curator_practical_knowhow']['curator_observations']['results'], 'method_ref'))));
        $this->assertLessThan(count($new['curator_practical_knowhow']['curator_observations']['results']),
            count($pack['curator_practical_knowhow']['curator_observations']['results']));
        $tampered = $pack;
        $tampered['lessons']['regime_router']['curator_practical_profiles'][0]['source_vs_lab'] = 'Publisher proved profit';
        $this->assertFalse($owner->verifyTeachingPack($tampered, $hashes));
        $tampered = $pack; $tampered['curator_practical_knowhow']['own_agent_outcome'] = true;
        $this->assertFalse($owner->verifyTeachingPack($tampered, $hashes));
        $this->assertNoLearningOrAuthority();
    }

    public function test_actual_fifty_six_original_rows_stay_immutable_and_two_technical_completions_are_separate_nonvoting_instruction(): void
    {
        $owner = app(CuratedInstrumentMethodCatalogService::class);
        $file = resource_path('research/instrument-operating-skills/curator-practical-observations-v3.json');
        $this->assertSame('abf74de8865b37b42c0a6b8d4ad815245b405b2ae44a60a4c81f5567b774c710',
            hash('sha256', rtrim(file_get_contents($file), "\r\n")));
        $original = json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        $curriculum = $owner->operatingCurriculum($owner::CURATOR_PRACTICE_CURRICULUM_KEY);
        $this->assertSame('operating_skill_curriculum', $curriculum['status'], json_encode($curriculum));
        $knowhow = $curriculum['curator_practical_knowhow']; $rows = $knowhow['curator_observations']['results'];
        $this->assertCount(56, $rows); $counts = array_count_values(array_column($rows, 'status'));
        $this->assertSame(52, $counts['completed']); $this->assertSame(4, $counts['technical_terminal']);
        $byKey = array_column($rows, null, 'case_key');
        foreach ($original['results'] as $row) {
            $retained = $byKey[$row['case_key']]; unset($retained['derived_evidence']);
            $this->assertSame($row, $retained);
        }
        $this->assertContains('partially_unavailable', array_column(array_column(array_filter($rows,
            fn ($row) => isset($row['derived_evidence'])), 'derived_evidence'), 'grade'));
        $completion = $knowhow['technical_completion']; $this->assertCount(2, $completion['results']);
        $this->assertSame(0, $completion['additional_independent_windows']);
        $this->assertFalse($completion['eligible_as_additional_advice_vote']);
        foreach ($completion['results'] as $row) {
            $old = $byKey[$row['original_case_key']];
            $this->assertSame('technical_terminal', $old['status']);
            $this->assertSame('technical_or_incomplete', $old['derived_evidence']['grade']);
            $this->assertSame('completed', $row['status']);
            $this->assertSame($old['request_sha256'], $row['request_sha256']);
            $this->assertSame('local_provisional_positive', $row['derived_evidence']['grade']);
            $this->assertFalse($row['derived_evidence']['own_agent_outcome']);
            $this->assertFalse($row['derived_evidence']['independent_validation']);
            $this->assertFalse($row['derived_evidence']['eligible_as_additional_advice_vote']);
        }
        $this->assertNoLearningOrAuthority();
    }

    public function test_actual_eight_dependency_diagnostics_retain_readiness_and_mixed_local_results_without_new_windows(): void
    {
        $owner = app(CuratedInstrumentMethodCatalogService::class);
        $raw = json_decode(file_get_contents(resource_path('research/instrument-operating-skills/curator-practical-dependency-diagnostics-v3.json')),
            true, 64, JSON_THROW_ON_ERROR);
        $curriculum = $owner->operatingCurriculum($owner::CURATOR_PRACTICE_CURRICULUM_KEY);
        $this->assertSame('operating_skill_curriculum', $curriculum['status'], json_encode($curriculum));
        $diagnostics = $curriculum['curator_practical_knowhow']['dependency_diagnostics'];
        $this->assertCount(2, $diagnostics['studies']); $this->assertSame(0, $diagnostics['additional_independent_windows']);
        $this->assertFalse($diagnostics['eligible_as_additional_advice_vote']); $all = [];
        foreach ($diagnostics['studies'] as $key => $study) {
            $this->assertCount(4, $study['observations']['results']);
            $this->assertSame(0, $study['additional_independent_windows']); $this->assertFalse($study['eligible_as_additional_advice_vote']);
            $expected = array_column($raw['studies'][$key]['observations']['results'], null, 'case_key');
            foreach ($study['observations']['results'] as $row) {
                $all[$row['case_key']] = $row;
                $retained = $row; unset($retained['derived_evidence'], $retained['diagnostic_study']);
                $this->assertSame($expected[$row['case_key']], $retained);
                if ($row['kind'] !== 'method') continue;
                foreach (['own_agent_outcome', 'independent_validation', 'skill_credit', 'agent_skill_earned',
                    'qualification_granted', 'economic_authority', 'paper_authority', 'live_authority', 'eligible_as_additional_advice_vote'] as $flag)
                    $this->assertFalse($row['derived_evidence'][$flag]);
            }
        }
        $this->assertSame('unavailable', $all['october_seen__scoreless_ema__confidence_primary']['derived_evidence']['grade']);
        $this->assertSame(['method_native_score_unavailable' => 6],
            $all['october_seen__scoreless_ema__confidence_primary']['method_reach']['unavailable_reasons']);
        $this->assertSame('unactivated', $all['october_seen__scored_trend__confidence_primary']['derived_evidence']['grade']);
        $this->assertSame(0, $all['october_seen__scored_trend__confidence_primary']['method_reach']['reached']);
        $this->assertSame('local_negative', $all['october_seen__quote_treatment']['derived_evidence']['grade']);
        $this->assertSame('local_provisional_positive', $all['december_seen__quote_treatment']['derived_evidence']['grade']);
        $this->assertEqualsWithDelta(-2.87, $all['october_seen__quote_treatment']['derived_evidence']['net_cash_delta'], 0.000001);
        $this->assertEqualsWithDelta(145.73, $all['december_seen__quote_treatment']['derived_evidence']['net_cash_delta'], 0.000001);
        $this->assertSame(9, $all['october_seen__quote_treatment']['method_reach']['veto'] + $all['december_seen__quote_treatment']['method_reach']['veto']);
        $notes = implode(' ', $diagnostics['studies']['observed_quote']['curator_notes']);
        $this->assertStringContainsString('arithmetic mean of fourteen closed true ranges', $notes);
        $this->assertStringContainsString('not Wilder ATR', $notes); $this->assertStringContainsString('No pass was observed', $notes);
        $this->assertCount(56, $curriculum['curator_practical_knowhow']['curator_observations']['results']);
        $this->assertCount(2, $curriculum['curator_practical_knowhow']['technical_completion']['results']);
        $this->assertNoLearningOrAuthority();
    }

    public function test_selected_diagnostic_packs_project_exact_methods_keep_bounded_notes_and_add_no_advice_votes(): void
    {
        $this->installOriginals(); $owner = app(CuratedInstrumentMethodCatalogService::class);
        $library = app(InstrumentMethodLibraryService::class);
        foreach (['native_score' => 'internet_signal_score_veto_0_7_v1', 'observed_quote' => 'internet_observed_quote_cost_firewall'] as $studyKey => $methodKey) {
            $method = DB::table('instrument_method_revisions')->where('method_key', $methodKey)->where('revision', 1)->first();
            $this->assertNotNull($method); $contract = json_decode($method->contract, true);
            $recipe = $library->registerRecipe(['recipe_key' => 'curator_dependency_'.$studyKey, 'revision' => 1,
                'method_hashes' => [$method->method_hash], 'context_predicate' => $contract['context_predicate']]);
            $this->assertSame('registered_research_only', $recipe['status'], json_encode($recipe));
            $recipes = [$recipe['recipe_hash']]; $pack = $owner->teachingPack($recipes, $owner::CURATOR_PRACTICE_CURRICULUM_KEY);
            $this->assertSame('operating_rules_taught', $pack['status'], json_encode($pack));
            $studies = $pack['curator_practical_knowhow']['dependency_diagnostics']['studies'];
            $this->assertSame([$studyKey], array_keys($studies)); $this->assertCount(4, $studies[$studyKey]['observations']['results']);
            $lesson = $pack['lessons'][$contract['instrument_key']]; $this->assertCount(2, $lesson['curator_dependency_diagnostics']);
            $this->assertNotEmpty($lesson['curator_dependency_diagnostics'][0]['curator_notes']);
            $this->assertTrue($owner->verifyTeachingPack($pack, $recipes));
            $default = (new ReflectionMethod(app(AgentMethodGenerationService::class), 'curatedDefault'))->invoke(app(AgentMethodGenerationService::class), $recipes);
            $advice = $owner->curatorPracticeAdvice($pack, $default['rules']);
            $this->assertSame(0, $advice['recipe_statistics'][$recipes[0]]['measured_windows']);
            $tampered = $pack; $tampered['curator_practical_knowhow']['dependency_diagnostics']['eligible_as_additional_advice_vote'] = true;
            $this->assertFalse($owner->verifyTeachingPack($tampered, $recipes));
        }
        $this->assertNoLearningOrAuthority();
    }

    public function test_diagnostic_parser_refuses_case_source_control_and_authority_substitution(): void
    {
        $owner = app(CuratedInstrumentMethodCatalogService::class);
        $raw = json_decode(file_get_contents(resource_path('research/instrument-operating-skills/curator-practical-dependency-diagnostics-v3.json')),
            true, 64, JSON_THROW_ON_ERROR); $study = $raw['studies']['native_score'];
        $plan = ['spec' => $study['before_outcome_spec_sha256'], 'archive' => $study['source_archive_sha256'],
            'summary' => $study['summary_sha256'], 'raw' => $study['raw_observations_sha256'],
            'protocol' => 'native_score_practice_observations_v1', 'before_field' => 'before_outcome_spec_sha256',
            'summary_field' => 'original_summary_sha256', 'method' => 'internet_signal_score_veto_0_7_v1@1',
            'keys' => array_keys($study['case_roster'])];
        $check = new ReflectionMethod($owner, 'operatingCuratorDiagnosticStudy');
        $this->assertCount(4, $check->invoke($owner, $study, $plan, 'native_score')['observations']['results']);
        $badRows = $study; array_pop($badRows['observations']['results']);
        $badSource = $study; $key = $badSource['observations']['results'][1]['case_key'];
        $badSource['observations']['results'][1]['source_keys'] = $badSource['case_roster'][$key]['source_keys'] = [str_repeat('0', 64)];
        $badControl = $study; $badControl['observations']['results'][1]['control']['request_sha256'] = str_repeat('0', 64);
        $badAuthority = $study; $badAuthority['observations']['independent_validation'] = true;
        foreach ([$badRows, $badSource, $badControl, $badAuthority] as $bad) {
            try { $check->invoke($owner, $bad, $plan, 'native_score'); $this->fail('Diagnostic provenance substitution must refuse.'); }
            catch (\RuntimeException $error) { $this->assertStringStartsWith('OPERATING_CURATOR_', $error->getMessage()); }
        }
        $this->assertNoLearningOrAuthority();
    }

    public function test_selected_risk_pack_retains_completion_facts_without_counting_them_as_new_advice_votes(): void
    {
        $this->installOriginals(); $owner = app(CuratedInstrumentMethodCatalogService::class);
        $method = DB::table('instrument_method_revisions')->where('method_key', 'internet_fixed_half_risk_v2')->where('revision', 1)->first();
        $this->assertNotNull($method); $context = json_decode($method->contract, true)['context_predicate'];
        $recipe = app(InstrumentMethodLibraryService::class)->registerRecipe(['recipe_key' => 'curator_completion_retention_fixture',
            'revision' => 1, 'method_hashes' => [$method->method_hash], 'context_predicate' => $context]);
        $this->assertSame('registered_research_only', $recipe['status'], json_encode($recipe));
        $pack = $owner->teachingPack([$recipe['recipe_hash']], $owner::CURATOR_PRACTICE_CURRICULUM_KEY);
        $this->assertSame('operating_rules_taught', $pack['status'], json_encode($pack));
        $this->assertCount(2, $pack['curator_practical_knowhow']['technical_completion']['results']);
        $this->assertCount(2, $pack['lessons']['atr_risk_envelope']['curator_observations']);
        $this->assertCount(2, $pack['lessons']['atr_risk_envelope']['curator_technical_completions']);
        $this->assertTrue($owner->verifyTeachingPack($pack, [$recipe['recipe_hash']]));
        $rules = [['context_predicate' => $context, 'preferred_recipes' => [$recipe['recipe_hash']]]];
        $advice = $owner->curatorPracticeAdvice($pack, $rules);
        $this->assertSame('curator_research_preference_advice', $advice['status'], json_encode($advice));
        $this->assertSame(0, $advice['recipe_statistics'][$recipe['recipe_hash']]['measured_windows']);
        $this->assertSame($rules, $advice['rules']);
        $tampered = $pack; $tampered['curator_practical_knowhow']['technical_completion']['results'][0]['request_sha256'] = str_repeat('0', 64);
        $this->assertFalse($owner->verifyTeachingPack($tampered, [$recipe['recipe_hash']]));
        $this->assertNoLearningOrAuthority();
    }

    public function test_twenty_six_practical_profiles_execute_their_actual_php_compiled_operators(): void
    {
        $this->installOriginals(); $owner = app(CuratedInstrumentMethodCatalogService::class);
        // Exercise code is checked before any archive outcomes exist. Reading
        // these labelled examples cannot substitute a synthetic market panel.
        $curriculum = $owner->operatingCurriculum(); $profiles = []; $supplements = [];
        $this->assertSame('operating_skill_curriculum', $curriculum['status'], json_encode($curriculum));
        foreach (['curator-price-practice-v3.json', 'curator-risk-practice-v3.json'] as $file) {
            $part = json_decode(file_get_contents(resource_path('research/instrument-operating-skills/'.$file)), true, 64, JSON_THROW_ON_ERROR);
            $supplements = [...$supplements, ...($part['supplemental_profiles'] ?? [])];
            foreach ($part['profiles'] as $profile) {
                $this->assertArrayNotHasKey($profile['instrument_key'], $profiles);
                $profiles[$profile['instrument_key']] = $profile;
            }
        }
        $this->assertCount(26, $profiles);
        $library = app(InstrumentMethodLibraryService::class); $schema = app(StrategyParameterSchemaService::class);
        $parameters = array_intersect_key($schema->defaults('differential_router'), $schema->schema('differential_router'));
        $cases = [];
        foreach ($curriculum['lessons'] as $instrument => $lesson)
            $this->assertSame($lesson['primary_method_ref'], $profiles[$instrument]['method_ref']);
        foreach ([...array_values($profiles), ...$supplements] as $profile) {
            $instrument = $profile['instrument_key'];
            [$methodKey, $revision] = explode('@', $profile['method_ref']);
            $row = DB::table('instrument_method_revisions')->where('method_key', $methodKey)->where('revision', (int) $revision)->first();
            $this->assertNotNull($row, $profile['method_ref']);
            $recipe = $library->registerRecipe(['recipe_key' => 'curator_practical_exercise_'.count($cases).'_'.$instrument, 'revision' => 1,
                'method_hashes' => [$row->method_hash], 'context_predicate' => $profile['context_predicate']]);
            $this->assertSame('registered_research_only', $recipe['status'], json_encode($recipe));
            $compiled = $library->compileRecipe($recipe['recipe_hash'], ['strategy_family' => 'differential_router', 'parameters' => $parameters],
                ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'pre_2026_only' => true, 'data_hash' => str_repeat('a', 64),
                    'execution_hash' => str_repeat('b', 64), 'context_predicate' => $profile['context_predicate']]);
            $this->assertSame('compiled_research_recipe', $compiled['status'], json_encode($compiled));
            $this->assertSame($parameters, $compiled['parameters']);
            $cases[$profile['method_ref']] = ['operator' => $compiled['runtime_recipe']['operators'][0], 'exercises' => $profile['exercises']];
        }
        $script = <<<'PY'
import json,sys
from app.services.research_program_tasks import BoundedDecisionProgram
cases=json.load(sys.stdin);checked=0
for key,case in cases.items():
    for exercise in case['exercises']:
        actual=BoundedDecisionProgram(case['operator'],allow_method_features=True).evaluate(exercise['inputs'],decision_at='2025-12-01T12:00:00Z',observed_at='2025-12-01T11:55:00Z')
        assert type(actual)==type(exercise['expected']) and actual==exercise['expected'], (key,exercise['name'],actual,exercise['expected'])
        checked+=1
print(json.dumps(dict(instruments=len(cases),exercises=checked,synthetic_correctness=True,market_qualification=False)))
PY;
        $process = new Process([env('PYTHON_BINARY', 'python'), '-c', $script], dirname(base_path()).'/ai-service-python');
        $process->setInput(json_encode($cases, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)); $process->setTimeout(30); $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        $result = json_decode($process->getOutput(), true);
        $this->assertSame(27, $result['instruments']); $this->assertGreaterThanOrEqual(85, $result['exercises']);
        $this->assertFalse($result['market_qualification']); $this->assertNoLearningOrAuthority();
    }

    public function test_original_v2_production_recipe_pack_hash_remains_exact(): void
    {
        $library = app(InstrumentMethodLibraryService::class); $recipes = [];
        foreach (['closed-ema-slope-pullback', 'closed-atr-percent'] as $folder) {
            $prefix = resource_path('research/instrument-methods/'.$folder.'/');
            $source = $library->registerSource(json_decode(file_get_contents($prefix.'source.json'), true));
            $this->assertSame('registered_reference_only', $source['status'], json_encode($source));
            $method = $library->registerMethod(json_decode(file_get_contents($prefix.'method.json'), true));
            $this->assertSame('registered_research_only', $method['status'], json_encode($method));
            $recipe = $library->registerRecipe(json_decode(file_get_contents($prefix.'recipe.json'), true));
            $this->assertSame('registered_research_only', $recipe['status'], json_encode($recipe));
            $recipes[] = $recipe['recipe_hash'];
        }
        sort($recipes);
        $this->assertSame(['53078acbc7f1919ca144605c3b7cf77172f9599be748b388ed96e7814983e312',
            '68b12b7ca939a132d31b439988b7e8166651d8cd691272ec49ac2c05bb44c640'], $recipes);
        $owner = app(CuratedInstrumentMethodCatalogService::class); $pack = $owner->teachingPack($recipes);
        $this->assertSame('operating_rules_taught', $pack['status'], json_encode($pack));
        $this->assertSame('5319affbfbaa479ff9303fb5eb9a46da44504d42343b30a0168510e3e10bee82', $pack['pack_hash']);
        $this->assertNoLearningOrAuthority();
    }

    public function test_real_unused_child_explicitly_receives_v3_with_immutable_proof_and_no_original_outcomes(): void
    {
        $this->installOriginals(); $library = app(InstrumentMethodLibraryService::class);
        $row = DB::table('instrument_method_revisions')->where('method_key', 'internet_closed_regime_alignment_v2')->first();
        $this->assertNotNull($row);
        $recipe = $library->registerRecipe(['recipe_key' => 'curator_practical_child_fixture', 'revision' => 1,
            'method_hashes' => [$row->method_hash], 'context_predicate' => json_decode($row->contract, true)['context_predicate']]);
        $this->assertSame('registered_research_only', $recipe['status'], json_encode($recipe));
        $recipes = [$recipe['recipe_hash']]; $schema = app(StrategyParameterSchemaService::class);
        $parameters = array_intersect_key($schema->defaults('trend'), $schema->schema('trend'));
        $child = ModelVersion::create(['name' => 'curator-practice-child-'.Str::uuid(), 'strategy' => 'trend_v1',
            'status' => 'draft', 'parameters' => $parameters, 'metadata' => ['base_strategy' => 'trend_v1',
                'risk_governor' => ['risk_per_trade' => 1.0], 'trade_management' => ['time_stop_candles' => 2],
                'lab_symbol' => 'XAUUSD', 'lab_timeframe' => 'M5']])->fresh();
        $parametersBefore = $child->parameters; $metadataBefore = $child->metadata;
        $libraryBefore = array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(),
            ['instrument_method_sources', 'instrument_method_revisions', 'instrument_recipe_revisions']);
        $owner = app(AgentMethodGenerationService::class);
        foreach ([['malformed_key'], 'curator_practical_knowhow_latest'] as $key) {
            $blocked = $owner->prepareFreshChild($child->fresh(), null, ['curated_recipe_hashes' => $recipes, 'curriculum_key' => $key]);
            $this->assertNotSame('prospective_child_policy_attached', $blocked['status'], json_encode($blocked));
            $this->assertDatabaseCount('agent_method_policy_revisions', 0);
            $this->assertSame($metadataBefore, $child->fresh()->metadata);
        }
        $catalog = app(CuratedInstrumentMethodCatalogService::class);
        config()->set('services.instrument_methods.generation_curriculum_key', $catalog::CURATOR_PRACTICE_CURRICULUM_KEY);
        $declaration = $owner->prospectiveCurriculumDeclaration();
        $lab = AiLaboratory::create(['name' => 'curator child generation fixture', 'symbol' => 'XAUUSD',
            'timeframe' => 'M5', 'strategy_families' => ['trend']]);
        $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => 1, 'status' => 'draft',
            'trigger_context' => ['instrument_method_generation_learning' => ['enabled' => true,
                'recipe_hashes' => $recipes, ...$declaration]]]);
        config()->set('services.instrument_methods.generation_curriculum_key', null);
        $created = $owner->prepareConstructorChild($child->fresh(), null, $generation->fresh(), 1, []);
        $this->assertSame('prospective_child_policy_attached', $created['status'], json_encode($created));
        $policy = app(AgentMethodLearningService::class)->policy($created['policy_hash']);
        $proof = $policy['learning_provenance']; $pack = $proof['teaching_pack'];
        $this->assertSame($catalog::CURATOR_PRACTICE_CURRICULUM_KEY, $pack['curriculum_key']);
        $this->assertSame($pack['curriculum_key'], $proof['constructor']['curriculum_key']);
        $this->assertSame('curator_observation', $proof['curator_advice']['attribution']);
        $this->assertSame($pack['pack_hash'], $proof['curator_advice']['teaching_pack_hash']);
        $this->assertSame($policy['rules'], $proof['curator_advice']['rules']);
        $forged = $proof; $forged['curator_advice']['selection'] = 'caller_reward';
        $this->assertFalse($owner->verifyProvenance($forged, $policy));
        $this->assertSame('model:'.$child->id, $policy['agent_key']);
        $this->assertTrue($catalog->verifyTeachingPack($pack, $recipes));
        $this->assertTrue($owner->verifyProvenance($proof, $policy));
        $hashes = app(ResearchPaperEpochContractService::class);
        $this->assertSame($hashes->parameterHash($proof), $hashes->parameterHash($child->fresh()->metadata[AgentMethodGenerationService::METADATA]));
        $this->assertSame($parametersBefore, $child->fresh()->parameters);
        foreach (['risk_governor', 'trade_management'] as $key) $this->assertSame($metadataBefore[$key], $child->fresh()->metadata[$key]);
        $this->assertSame($libraryBefore, array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(),
            ['instrument_method_sources', 'instrument_method_revisions', 'instrument_recipe_revisions']));
        $this->assertFalse($pack['curator_practical_knowhow']['own_agent_outcome']);
        $this->assertFalse($pack['agent_skill_earned']); $this->assertFalse($pack['economic_authority']);
        $this->assertDatabaseCount('model_versions', 1); $this->assertDatabaseCount('agent_method_policy_revisions', 1);
        $this->assertDatabaseCount('lab_generations', 1);
        foreach (['agent_method_experiences', 'agent_method_owned_outcomes', 'agent_knowledge_cards', 'agent_learning_lessons',
            'mutation_memories', 'instrument_method_meta_models', 'lab_evaluation_runs',
            'research_experiment_work_items', 'lab_evolution_credit_events', 'jobs'] as $table) $this->assertDatabaseCount($table, 0);
    }

    public function test_labelled_synthetic_curator_advice_keeps_unscored_positions_and_deduplicates_seen_windows(): void
    {
        [$owner, $pack, $initial, $recipes] = $this->syntheticAdviceFixture(true);
        $derive = new ReflectionMethod($owner, 'operatingCuratorAdvice');
        $advice = $derive->invoke($owner, $pack, $initial['rules']);
        $this->assertSame([$recipes['confidence_firewall'], $recipes['cost_firewall'], $recipes['high_volatility_firewall']],
            $advice['rules'][0]['preferred_recipes']);
        $this->assertSame(1, $advice['recipe_statistics'][$recipes['confidence_firewall']]['sign_vote_total']);
        $this->assertSame(1, $advice['recipe_statistics'][$recipes['confidence_firewall']]['measured_windows']);
        $this->assertSame(0, $advice['recipe_statistics'][$recipes['cost_firewall']]['measured_windows']);
        $this->assertContains('unscored_recipe_kept_at_original_position',
            $advice['recipe_statistics'][$recipes['cost_firewall']]['warnings']);
        $this->assertSame('BASELINE', $advice['default_action']);
        foreach (['own_agent_outcome', 'independent_validation', 'skill_credit', 'agent_skill_earned',
            'qualification_granted', 'economic_authority', 'paper_authority', 'live_authority'] as $flag) $this->assertFalse($advice[$flag]);
        // Missing observations do not implicitly score higher than a measured negative.
        $unknownOnly = $pack; $unknownOnly['curator_practical_knowhow']['curator_observations']['results'] =
            array_values(array_filter($pack['curator_practical_knowhow']['curator_observations']['results'],
                fn ($row) => $row['instrument_key'] !== 'confidence_firewall'));
        $same = $derive->invoke($owner, $unknownOnly, $initial['rules']);
        $this->assertSame($initial['rules'], $same['rules']);
        $partial = $pack;
        foreach ($partial['curator_practical_knowhow']['curator_observations']['results'] as &$row)
            if ($row['instrument_key'] === 'confidence_firewall')
                $row['method_reach'] = ['reached' => 4, 'available' => 1, 'activated' => 1, 'changed_decisions' => 4];
        unset($row);
        $partialAdvice = $derive->invoke($owner, $partial, $initial['rules']);
        $this->assertSame($initial['rules'], $partialAdvice['rules']);
        $this->assertContains('partially_unavailable',
            $partialAdvice['recipe_statistics'][$recipes['confidence_firewall']]['warnings']);
        $this->assertSame(0, $partialAdvice['recipe_statistics'][$recipes['confidence_firewall']]['measured_windows']);
        $this->assertNoLearningOrAuthority();
    }

    public function test_labelled_synthetic_advice_changes_actual_compiled_policy_recipe_and_veto_without_claiming_market_benefit(): void
    {
        [$owner, $pack, $initial, $recipes] = $this->syntheticAdviceFixture(false);
        $advice = (new ReflectionMethod($owner, 'operatingCuratorAdvice'))->invoke($owner, $pack, $initial['rules']);
        $schemas = app(StrategyParameterSchemaService::class);
        $parameters = array_intersect_key($schemas->defaults('trend'), $schemas->schema('trend'));
        $scope = ['symbol' => 'XAUUSD', 'timeframe' => 'M5', 'data_hash' => str_repeat('a', 64),
            'execution_hash' => str_repeat('b', 64), 'evaluated_start' => '2025-01-01T12:00:00Z',
            'evaluated_end' => '2025-01-01T12:05:00Z', 'context' => $initial['rules'][0]['context_predicate']];
        $contracts = [];
        foreach (['original' => $initial['rules'], 'advised' => $advice['rules']] as $key => $rules) {
            $agent = 'curator-synthetic-'.$key;
            $policy = app(AgentMethodLearningService::class)->registerPolicy(['agent_key' => $agent, 'revision' => 1,
                'library_hash' => $initial['library_hash'], 'library_contract' => $initial['library_contract'],
                'rules' => $rules, 'default_action' => 'BASELINE']);
            $this->assertSame('registered_research_only', $policy['status'], json_encode($policy));
            $contracts[$key] = app(\App\Services\InstrumentMethodResearchBindingService::class)->compilePolicy(
                $policy['policy_hash'], ['strategy_family' => 'trend', 'parameters' => $parameters], $scope, $agent);
        }
        $script = <<<'PY'
import json,sys
from app.services.instrument_method_learning import InstrumentMethodLearning
contracts=json.load(sys.stdin);events={}
for key,contract in contracts.items():
    engine=InstrumentMethodLearning(contract,dataset_hash=contract['data_hash'],execution_hash=contract['execution_hash'],expected_agent_key=contract['expected_agent_key'])
    events[key]=engine.evaluate(dict(close=100.,market_regime='trend_up',market_session='london',volatility_regime='normal_volatility',_management_atr=40.,signal_confidence=.9),
        decision_id='synthetic-'+key,phase='entry',decision_at='2025-01-01T12:05:00Z',observed_at='2025-01-01T12:00:00Z',direction='BUY')
assert events['original']['recipe_hash']!=events['advised']['recipe_hash']
assert events['original']['outputs']['confirmation'] is False
assert events['advised']['outputs']['confirmation'] is True
print(json.dumps(dict(policy_order_changes_execution=True,market_benefit_proven=False)))
PY;
        $process = new Process([env('PYTHON_BINARY', 'python'), '-c', $script], dirname(base_path()).'/ai-service-python');
        $process->setInput(json_encode($contracts, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)); $process->setTimeout(30); $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        $result = json_decode($process->getOutput(), true);
        $this->assertTrue($result['policy_order_changes_execution']); $this->assertFalse($result['market_benefit_proven']);
        foreach (['agent_method_experiences', 'agent_method_owned_outcomes', 'agent_knowledge_cards', 'agent_learning_lessons',
            'mutation_memories', 'lab_evaluation_runs', 'lab_evolution_credit_events', 'jobs'] as $table) $this->assertDatabaseCount($table, 0);
    }

    private function syntheticAdviceFixture(bool $includeUnknown): array
    {
        $this->installOriginals(); $owner = app(CuratedInstrumentMethodCatalogService::class);
        $library = app(InstrumentMethodLibraryService::class); $recipes = [];
        $keys = ['high_volatility_firewall' => 'internet_closed_atr_absolute_ceiling_v1',
            'cost_firewall' => 'internet_observed_quote_cost_firewall', 'confidence_firewall' => 'internet_signal_score_veto_0_7_v1'];
        foreach ($keys as $instrument => $methodKey) {
            if (! $includeUnknown && $instrument === 'cost_firewall') continue;
            $row = DB::table('instrument_method_revisions')->where('method_key', $methodKey)->where('revision', 1)->first();
            $this->assertNotNull($row, $methodKey);
            $method = json_decode($row->contract, true);
            $recipe = $library->registerRecipe(['recipe_key' => 'curator_synthetic_advice_'.$instrument, 'revision' => 1,
                'method_hashes' => [$row->method_hash], 'context_predicate' => $method['context_predicate']]);
            $this->assertSame('registered_research_only', $recipe['status'], json_encode($recipe));
            $recipes[$instrument] = $recipe['recipe_hash'];
        }
        $pool = array_values($recipes);
        $initial = (new ReflectionMethod(app(AgentMethodGenerationService::class), 'curatedDefault'))->invoke(
            app(AgentMethodGenerationService::class), $pool);
        $pack = $owner->teachingPack($pool); $this->assertSame('operating_rules_taught', $pack['status'], json_encode($pack));
        // Private pure derivation is tested with labelled synthetic rows. This
        // fixture is not passed through the public pinned-pack acceptance API.
        $pack['curriculum_key'] = $owner::CURATOR_PRACTICE_CURRICULUM_KEY;
        $pack['fixture_scope'] = 'synthetic_advice_software_example_not_market_evidence';
        [, , $template] = $this->syntheticObservation(); $rows = []; $roster = [];
        foreach ($pack['methods'] as $method) {
            $instrument = $method['instrument_key']; $ref = $method['method_key'].'@'.$method['revision'];
            $row = [...$template, 'case_key' => 'synthetic_seen__'.$instrument, 'window' => 'synthetic_seen', 'kind' => 'method',
                'instrument_key' => $instrument, 'method_ref' => $ref, 'target' => $method['implementation']['target'],
                'source_keys' => $method['source_keys'], 'net_cash' => $instrument === 'confidence_firewall' ? 12.0 : 9.0,
                'derived_evidence' => ['grade' => $instrument === 'confidence_firewall' ? 'local_negative' : 'local_provisional_positive']];
            if ($instrument === 'cost_firewall') $row['method_reach'] = ['reached' => 4, 'available' => 0, 'activated' => 0, 'changed_decisions' => 0];
            $rows[] = $row;
            $roster[$row['case_key']] = array_intersect_key($row, array_flip(['kind', 'instrument_key', 'method_ref', 'target', 'source_keys']));
            if ($instrument === 'confidence_firewall') {
                $alias = [...$row, 'case_key' => 'synthetic_seen__confidence_alias']; $rows[] = $alias;
                $roster[$alias['case_key']] = $roster[$row['case_key']];
            }
        }
        $pack['curator_practical_knowhow'] = ['before_outcome_declaration' => ['windows' => [
            'synthetic_seen' => ['dataset_sha256' => str_repeat('c', 64)]], 'case_roster' => $roster],
            'curator_observations' => ['results' => $rows]];
        return [$owner, $pack, $initial, $recipes];
    }

    private function syntheticObservation(): array
    {
        $owner = app(CuratedInstrumentMethodCatalogService::class);
        $method = (new ReflectionMethod($owner, 'operatingDeclaredMethod'))->invoke($owner, 'internet_closed_atr_absolute_ceiling_v1@1');
        $row = ['fixture_scope' => 'synthetic_software_example_not_market_evidence', 'case_key' => 'synthetic_atr_fixture',
            'instrument_key' => $method['instrument_key'], 'method_ref' => $method['method_key'].'@'.$method['revision'],
            'target' => $method['implementation']['target'], 'source_keys' => $method['source_keys'],
            'request_sha256' => str_repeat('a', 64), 'response_sha256' => str_repeat('b', 64),
            'dataset_sha256' => str_repeat('c', 64), 'cost_sha256' => str_repeat('d', 64),
            'status' => 'completed', 'timeout' => false, 'net_cash' => 11.25,
            'method_reach' => ['reached' => 4, 'available' => 4, 'activated' => 4, 'changed_decisions' => 1],
            'trade_refs' => ['synthetic_trade_1'], 'cost_refs' => ['synthetic_cost_1'], 'ledger_refs' => ['synthetic_ledger_1'],
            'control' => ['request_sha256' => str_repeat('e', 64), 'response_sha256' => str_repeat('f', 64),
                'dataset_sha256' => str_repeat('c', 64), 'cost_sha256' => str_repeat('d', 64), 'net_cash' => 10.0]];
        return [$owner, $method, $row];
    }

    private function installOriginals(): void
    {
        $owner = app(CuratedInstrumentMethodCatalogService::class);
        $result = $owner->install(['catalog_key' => $owner::EXPANDED_CATALOG_KEY, 'catalog_sha256' => $owner::EXPANDED_CATALOG_SHA256]);
        $this->assertSame('curated_research_catalog_installed', $result['status'], json_encode($result));
        foreach (['observed-quote-firewall', 'closed-band-reentry', 'closed-ema-pullback', 'closed-donchian-retest',
            'calibrated-meta-score', 'closed-macd-cross', 'closed-rsi-recovery', 'closed-chandelier-exit', 'native-single-partial-runner',
            'closed-bandwidth-squeeze'] as $folder) {
            foreach (glob(resource_path('research/instrument-methods/'.$folder.'/*source.json')) as $path) {
                $source = app(InstrumentMethodSourceService::class)->registerSource(json_decode(file_get_contents($path), true));
                $this->assertSame('registered_reference_only', $source['status'], json_encode($source));
            }
            $method = app(InstrumentMethodLibraryService::class)->registerMethod(json_decode(file_get_contents(resource_path('research/instrument-methods/'.$folder.'/method.json')), true));
            $this->assertSame('registered_research_only', $method['status'], json_encode($method));
        }
    }

    private function assertNoLearningOrAuthority(): void
    {
        foreach (['agent_method_policy_revisions', 'agent_method_experiences', 'agent_method_owned_outcomes', 'agent_knowledge_cards',
            'agent_learning_lessons', 'mutation_memories', 'instrument_method_meta_models', 'model_versions', 'lab_evaluation_runs',
            'lab_generations', 'research_experiment_work_items', 'lab_evolution_credit_events', 'jobs'] as $table) $this->assertDatabaseCount($table, 0);
    }
}
