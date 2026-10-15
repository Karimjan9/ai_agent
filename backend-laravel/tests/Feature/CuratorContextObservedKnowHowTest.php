<?php

namespace Tests\Feature;

use App\Services\CuratedInstrumentMethodCatalogService;
use App\Services\ResearchPaperEpochContractService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Curator instruction/parser correctness. Synthetic fixtures are not market observations. */
class CuratorContextObservedKnowHowTest extends TestCase
{
    use RefreshDatabase;

    private const FROZEN_V4_CURRICULUM = 'dc8b14ccf4d6e846d3517ceaaa3b8206fc51f15fddde6a5c132d97f977609f24';
    private const FROZEN_V4_SINGLE_PACK = '253ccdbfa5ae84b85a6cfcef77f4d8062f59a62f41fb69eb839821a2d4062b58';
    private const SELECTED_METHOD = 'curator_dmi_trend_up_london_normal_v1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }

    public function test_frozen_v4_curriculum_and_single_pack_hashes_remain_exact(): void
    {
        $owner = app(CuratedInstrumentMethodCatalogService::class);
        $curriculum = $owner->operatingCurriculum($owner::CONTEXT_KNOWHOW_CURRICULUM_KEY);
        $installed = $owner->installContextPractice();
        $this->assertSame('context_practice_installed', $installed['status'], json_encode($installed));
        $recipeHash = $installed['methods'][self::SELECTED_METHOD]['recipe_hash'];
        $pack = $owner->teachingPack([$recipeHash], $owner::CONTEXT_KNOWHOW_CURRICULUM_KEY);
        $this->assertSame('operating_rules_taught', $pack['status'], json_encode($pack));
        $this->assertSame(self::FROZEN_V4_CURRICULUM, $curriculum['curriculum_hash']);
        $this->assertSame(self::FROZEN_V4_SINGLE_PACK, $pack['pack_hash']);
        $this->assertNoOwnLearning();
    }

    public function test_synthetic_28_originals_preserve_24_bundle_contrasts_and_mixed_terminal_states(): void
    {
        [$owner, $panel, $practice, $bindings] = $this->fixture();
        $result = $this->parse($owner, $panel, $practice, $bindings);
        $this->assertCount(28, $result['results']);
        $this->assertCount(24, $result['bundle_contrasts']);
        $grades = array_column($result['bundle_contrasts'], 'grade');
        foreach (['bundle_local_positive', 'bundle_local_negative', 'bundle_local_null',
            'unactivated', 'unavailable', 'partially_unavailable', 'no_behavior_change', 'underpowered',
            'technical_or_incomplete'] as $grade) $this->assertContains($grade, $grades);
        foreach ($result['bundle_contrasts'] as $contrast) {
            $this->assertCount(4, $contrast['original_method_refs']);
            $this->assertSame('whole_continuous_account_original_four_method_intervention_bundle', $contrast['effect_scope']);
            foreach (['own_agent_outcome', 'independent_validation', 'skill_credit', 'qualification_granted',
                'economic_authority', 'paper_authority', 'live_authority'] as $flag) $this->assertFalse($contrast[$flag]);
        }
        foreach ($practice['method_profiles'] as $profile) {
            $this->assertArrayNotHasKey('grade', $profile);
            $this->assertArrayNotHasKey('net_cash_delta', $profile);
            $this->assertSame('awaiting_original_panel', $profile['evidence_state']);
        }
        $technical = array_values(array_filter($result['results'], fn ($row) => $row['status'] === 'timeout'))[0];
        $this->assertNull($technical['clock']); $this->assertNull($technical['net_cash']);
        $this->assertNull($technical['mature_trades']); $this->assertNull($technical['clock_receipt_sha256']);
        $this->assertNoOwnLearning();
    }

    public function test_roster_request_source_method_clock_and_control_substitutions_refuse(): void
    {
        [$owner, $panel, $practice, $bindings] = $this->fixture();
        $target = $this->firstIntervention($panel); $control = $this->controlFor($panel, $target);
        $mutations = [
            static function (&$p) { array_pop($p['results']); },
            static function (&$p) { $p['results'][] = $p['results'][0]; },
            static function (&$p) { $p['results'][0]['case_key'] = 'unplanned'; },
            function (&$p) use ($target) { $p['results'][$target]['method_refs'] = []; },
            function (&$p) use ($target) { $p['results'][$target]['source_keys'] = [$this->sha('substituted-source')]; },
            function (&$p) use ($target) { $p['results'][$target]['request_sha256'] = $this->sha('substituted-request'); },
            function (&$p) use ($target) { $p['results'][$target]['dataset_sha256'] = $this->sha('substituted-data'); },
            function (&$p) use ($target) { $p['results'][$target]['cost_sha256'] = $this->sha('substituted-cost'); },
            function (&$p) use ($target) { $p['results'][$target]['window_sha256'] = $this->sha('substituted-window'); },
            function (&$p) use ($target) { $p['results'][$target]['source_manifest_sha256'] = $this->sha('substituted-runtime'); },
            function (&$p) use ($target) { $p['results'][$target]['clock']['input_rows']++; $this->resealClock($p['results'][$target]); },
            function (&$p) use ($target) { $p['results'][$target]['clock']['schedule_hash'] = $this->sha('other-schedule'); $this->resealClock($p['results'][$target]); },
            function (&$p) use ($target) { $p['results'][$target]['clock']['index_set_hash'] = $this->sha('other-indices'); $this->resealClock($p['results'][$target]); },
            function (&$p) use ($control) { $p['results'][$control]['clock']['execution_start'] = '2025-01-01T00:00:00+00:00'; $this->resealClock($p['results'][$control]); },
        ];
        foreach ($mutations as $mutate) {
            $bad = $panel; $mutate($bad);
            $this->refused($owner, $bad, $practice, $bindings);
        }
        $this->assertNoOwnLearning();
    }

    public function test_original_actual_clock_hashes_and_explicit_utc_are_verified_but_technical_clock_is_null(): void
    {
        [$owner, $panel, $practice, $bindings] = $this->fixture();
        $target = $this->firstIntervention($panel);
        foreach (['response_sha256', 'trace_sha256', 'ledger_sha256', 'clock_receipt_sha256'] as $field) {
            $bad = $panel; $bad['results'][$target][$field] = null;
            $this->refused($owner, $bad, $practice, $bindings);
        }
        $bad = $panel; $bad['results'][$target]['clock']['schedule_hash'] = $this->sha('stale-unsealed-clock');
        $this->refused($owner, $bad, $practice, $bindings);
        $bad = $panel; $bad['results'][$target]['clock']['receipt_json'] = '{}';
        $this->refused($owner, $bad, $practice, $bindings);
        $bad = $panel; $bad['results'][$target]['clock']['execution_start'] = '2025-10-14T00:50:00';
        $this->resealClock($bad['results'][$target]); $this->refused($owner, $bad, $practice, $bindings);
        $same = $panel;
        $same['results'][$target]['clock']['execution_start'] = str_replace('+00:00', 'Z', $same['results'][$target]['clock']['execution_start']);
        $same['results'][$target]['clock']['execution_end'] = str_replace('+00:00', 'Z', $same['results'][$target]['clock']['execution_end']);
        $this->resealClock($same['results'][$target]);
        $this->assertCount(28, $this->parse($owner, $same, $practice, $bindings)['results']);
        $technical = array_key_first(array_filter($panel['results'], fn ($row) => $row['status'] === 'timeout'));
        $bad = $panel; $bad['results'][$technical]['net_cash'] = 0.;
        $this->refused($owner, $bad, $practice, $bindings);
        $bad = $panel; $bad['results'][$technical]['clock'] = $panel['results'][$target]['clock'];
        $this->refused($owner, $bad, $practice, $bindings);
        $bad = $panel; $bad['results'][$technical]['clock_receipt_sha256'] = $this->sha('invented-technical-clock');
        $this->refused($owner, $bad, $practice, $bindings);
        foreach (['response_sha256', 'trace_sha256', 'ledger_sha256'] as $field) {
            $bad = $panel; $bad['results'][$technical][$field] = $this->sha('unassessable-technical-'.$field);
            $this->refused($owner, $bad, $practice, $bindings);
        }
        $bad = $panel; $bad['results'][$technical]['context_cells'] = $panel['results'][$target]['context_cells'];
        $this->refused($owner, $bad, $practice, $bindings);
        $this->assertNoOwnLearning();
    }

    public function test_context_cells_remain_event_only_and_attempted_use_is_not_availability(): void
    {
        [$owner, $panel, $practice, $bindings] = $this->fixture();
        $target = $this->firstIntervention($panel);
        $panel['results'][$target]['method_uses'] = 100000;
        $result = $this->parse($owner, $panel, $practice, $bindings);
        $row = array_values(array_filter($result['results'], fn ($row) => $row['case_key'] === $panel['results'][$target]['case_key']))[0];
        $this->assertSame(100000, $row['method_uses']);
        $this->assertSame(20, $row['context_cells'][0]['available']);
        $this->assertSame('bundle_local_positive', $result['bundle_contrasts'][$row['case_key']]['grade']);
        foreach (['net_cash' => 0., 'mature_trades' => 0, 'grade' => 'confirmed', 'skill_credit' => true] as $field => $value) {
            $bad = $panel; $bad['results'][$target]['context_cells'][0][$field] = $value;
            $this->refused($owner, $bad, $practice, $bindings);
        }
        foreach (['own_agent_outcome', 'independent_validation', 'skill_credit', 'qualification_granted',
            'economic_authority', 'paper_authority', 'live_authority'] as $field) {
            $bad = $panel; $bad[$field] = true; $this->refused($owner, $bad, $practice, $bindings);
            $bad = $panel; $bad['results'][$target][$field] = true; $this->refused($owner, $bad, $practice, $bindings);
        }
        $this->assertNoOwnLearning();
    }

    public function test_selected_one_method_projects_its_four_original_bundles_and_controls_without_component_grades(): void
    {
        [$owner, $panel, $practice, $bindings] = $this->fixture();
        $observed = $this->parse($owner, $panel, $practice, $bindings);
        $input = $owner->contextPracticeCatalog()['methods'][self::SELECTED_METHOD]['method_input'];
        $projected = (new ReflectionMethod($owner, 'operatingContextObservedProjection'))->invoke($owner, $observed, [$input]);
        $this->assertCount(4, $projected['bundle_contrasts']);
        $this->assertCount(8, $projected['results']);
        $this->assertSame($observed['declaration'], $projected['declaration']);
        foreach (['original_spec_sha256', 'original_summary_sha256', 'source_manifest_sha256'] as $field)
            $this->assertSame($observed[$field], $projected[$field]);
        foreach ($projected['bundle_contrasts'] as $contrast) {
            $this->assertCount(4, $contrast['original_method_refs']);
            $this->assertSame([self::SELECTED_METHOD.'@1'], $contrast['selected_instruction_method_refs']);
        }
        foreach ($projected['results'] as $row) foreach ($row['context_cells'] as $cell) {
            $this->assertArrayNotHasKey('net_cash', $cell); $this->assertArrayNotHasKey('mature_trades', $cell);
        }
        $this->assertNoOwnLearning();
    }

    /** Actual published teaching metadata, not independent qualification or a market replay. */
    public function test_explicit_published_v5_does_not_change_v1_v4_and_roundtrips_actual_php_teaching_in_python(): void
    {
        $owner = app(CuratedInstrumentMethodCatalogService::class);
        $this->assertTrue(defined($owner::class.'::CONTEXT_OBSERVED_CURRICULUM_KEY'));
        $installed = $owner->installContextPractice();
        $hashes = [$installed['methods'][self::SELECTED_METHOD]['recipe_hash']];
        $expected = [
            $owner::OPERATING_CURRICULUM_KEY => '95a46db8c67bf9ab92cfe0a1a85d2e81e79ccf5e7858ae73bef86be4a4918314',
            $owner::SOURCE_PRACTICE_CURRICULUM_KEY => '0b95896dcb088eac676c3c888e9ab016ea4f57c24ca0e6fb19cbecf048a7cbb6',
            $owner::CURATOR_PRACTICE_CURRICULUM_KEY => '94fcbca88aa64530592b394f6b4a120cebb41b81b8606d23b2830cfec8f7464e',
            $owner::CONTEXT_KNOWHOW_CURRICULUM_KEY => self::FROZEN_V4_CURRICULUM,
        ];
        foreach ($expected as $key => $hash) $this->assertSame($hash, $owner->operatingCurriculum($key)['curriculum_hash']);
        $pack = $owner->teachingPack($hashes, $owner::CONTEXT_OBSERVED_CURRICULUM_KEY);
        $this->assertSame('operating_rules_taught', $pack['status'], json_encode($pack));
        $this->assertTrue($owner->verifyTeachingPack($pack, $hashes));
        $this->assertSame('awaiting_original_panel', $pack['curator_contextual_practice']['evidence_state']);
        $this->assertSame('observed_reused_archive_originals_not_independent_qualification',
            $pack['curator_context_observed_practice']['evidence_state']);
        $this->assertCount(4, $pack['curator_context_observed_practice']['bundle_contrasts']);
        $advice = $owner->curatorPracticeAdvice($pack, []);
        $this->assertSame('blocked', $advice['status']);
        $this->assertSame('OPERATING_CURATOR_ADVICE_PACK_REQUIRED', $advice['reason']);
        foreach ($expected as $key => $hash) $this->assertSame($hash, $owner->operatingCurriculum($key)['curriculum_hash']);
        $this->assertSame(self::FROZEN_V4_SINGLE_PACK,
            $owner->teachingPack($hashes, $owner::CONTEXT_KNOWHOW_CURRICULUM_KEY)['pack_hash']);
        $json = json_encode($pack, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $programme = <<<'PY'
import json,sys
from app.services.research_program_tasks import canonical_hash
pack=json.loads(sys.stdin.read())
body={key:value for key,value in pack.items() if key not in ('status','pack_hash')}
assert canonical_hash(body)==pack['pack_hash']
assert pack['curator_contextual_practice']['evidence_state']=='awaiting_original_panel'
observed=pack['curator_context_observed_practice']
assert observed['evidence_state']=='observed_reused_archive_originals_not_independent_qualification'
assert len(observed['bundle_contrasts'])==4
assert all(len(row['original_method_refs'])==4 for row in observed['bundle_contrasts'].values())
assert all(not observed[field] for field in ('own_agent_outcome','independent_validation','skill_credit'))
print(json.dumps(pack,separators=(',',':'),allow_nan=False))
PY;
        $process = new Process(['python', '-B', '-c', $programme], dirname(base_path()).'/ai-service-python');
        $process->setInput($json); $process->setTimeout(15); $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $transported = json_decode($process->getOutput(), true, 64, JSON_THROW_ON_ERROR);
        $this->assertTrue($owner->verifyTeachingPack($transported, $hashes));
        foreach ([$owner::OPERATING_CURRICULUM_KEY, $owner::SOURCE_PRACTICE_CURRICULUM_KEY,
            $owner::CURATOR_PRACTICE_CURRICULUM_KEY] as $key)
            $this->assertSame('unmapped', $owner->teachingPack($hashes, $key)['status']);
        $this->assertSame($owner::OPERATING_CURRICULUM_KEY, $owner->operatingCurriculum()['curriculum_key']);
        $this->assertNoOwnLearning();
    }

    private function fixture(): array
    {
        $owner = app(CuratedInstrumentMethodCatalogService::class);
        $catalog = $owner->contextPracticeCatalog();
        $practice = $owner->operatingCurriculum($owner::CONTEXT_KNOWHOW_CURRICULUM_KEY)['curator_contextual_practice'];
        $bindings = ['spec' => $this->sha('fixture-spec'), 'summary' => $this->sha('fixture-summary'), 'source' => $this->sha('fixture-runtime')];
        $flags = array_fill_keys(['own_agent_outcome', 'independent_validation', 'skill_credit', 'qualification_granted',
            'economic_authority', 'paper_authority', 'live_authority'], false);
        $rows = []; $roster = []; $case = 0;
        foreach ($catalog['design']['panel']['windows'] as $windowIndex => $window) {
            $start = new \DateTimeImmutable('2025-10-14T00:50:00Z');
            $start = $start->modify('+'.($windowIndex * 10).' days');
            $planned = ['loaded_rows' => 1024, 'warmup_rows' => 200, 'evaluated_rows' => 824,
                'evaluated_start' => $start->format('Y-m-d\TH:i:s\Z'),
                'evaluated_end' => $start->modify('+'.(823 * 5).' minutes')->format('Y-m-d\TH:i:s\Z')];
            foreach ($catalog['design']['panel']['arms_per_window'] as $arm) {
                $refs = []; $sourceKeys = []; $context = ['regime' => 'trend_up', 'session' => 'london',
                    'volatility' => 'normal', 'venue_phase' => 'london_interfix', 'direction' => 'buy'];
                if ($arm !== 'control') {
                    [$style, $session] = explode('_', $arm, 2);
                    foreach ($catalog['methods'] as $method) if ($method['style'] === $style
                        && $method['method_input']['context_predicate']['session'] === $session) {
                        $input = $method['method_input']; $refs[] = $input['method_key'].'@'.$input['revision'];
                        $sourceKeys = [...$sourceKeys, ...$input['source_keys']];
                        $context['session'] = $session;
                    }
                }
                sort($refs); $sourceKeys = array_values(array_unique($sourceKeys)); sort($sourceKeys);
                $key = $window.'__'.$arm;
                $declaration = ['window' => $window, 'arm' => $arm, 'method_refs' => $refs, 'source_keys' => $sourceKeys,
                    'request_sha256' => $this->sha('fixture-request-'.$key), 'dataset_sha256' => $this->sha('fixture-data-'.$window),
                    'cost_sha256' => $this->sha('fixture-fixed-cost'), 'window_sha256' => $this->sha('fixture-window-'.$window),
                    'source_manifest_sha256' => $bindings['source'], 'clock' => $planned];
                $clock = $this->clock($planned, $declaration, $window);
                $selected = $arm === 'control' ? 0 : 20;
                $cell = ['context' => $context, 'entry_method_events' => 20, 'selected' => $selected,
                    'reached' => $selected, 'available' => $selected, 'pass' => $selected ? 12 : 0,
                    'veto' => $selected ? 8 : 0, 'changed' => $selected ? 8 : 0,
                    'unavailable_reasons' => [], 'reason_counts' => ['recipe_evaluated' => 20]];
                $row = [...$declaration, 'case_key' => $key, 'status' => 'completed',
                    'response_sha256' => $this->sha('fixture-response-'.$key), 'clock_receipt_sha256' => $clock['receipt_hash'],
                    'trace_sha256' => $this->sha('fixture-trace-'.$key), 'ledger_sha256' => $this->sha('fixture-ledger-'.$key),
                    'clock' => $clock, 'net_cash' => -100., 'mature_trades' => 12,
                    'method_uses' => 20, 'context_cells' => [$cell], ...$flags];
                if ($arm !== 'control') {
                    $case++;
                    if ($case === 1) $row['net_cash'] = -75.;
                    if ($case === 2) $row['net_cash'] = -125.;
                    if ($case === 4) {
                        $row['context_cells'][0] = [...$cell, 'selected' => 0, 'reached' => 0, 'available' => 0,
                            'pass' => 0, 'veto' => 0, 'changed' => 0];
                    }
                    if ($case === 5) $row['context_cells'][0] = [...$cell, 'available' => 0, 'pass' => 0, 'veto' => 0,
                        'changed' => 20, 'unavailable_reasons' => ['method_context_underpowered' => 20],
                        'reason_counts' => ['method_context_underpowered' => 20]];
                    if ($case === 6) $row['context_cells'][0] = [...$cell, 'available' => 5, 'pass' => 3, 'veto' => 2,
                        'changed' => 17, 'unavailable_reasons' => ['method_context_underpowered' => 15],
                        'reason_counts' => ['recipe_evaluated' => 5, 'method_context_underpowered' => 15]];
                    if ($case === 7) $row['context_cells'][0] = [...$cell, 'pass' => 20, 'veto' => 0, 'changed' => 0];
                    if ($case === 8) $row['mature_trades'] = 2;
                    if ($case === 9) $row = [...$row, 'status' => 'timeout', 'clock' => null, 'clock_receipt_sha256' => null,
                        'response_sha256' => null, 'trace_sha256' => null, 'ledger_sha256' => null,
                        'net_cash' => null, 'mature_trades' => null, 'context_cells' => [], 'method_uses' => null];
                }
                $roster[$key] = $declaration; $rows[] = $row;
            }
        }
        $panel = ['protocol' => 'curator_context_observed_practice_v1',
            'curriculum_key' => $owner::CONTEXT_OBSERVED_CURRICULUM_KEY, 'attribution' => 'curator_observation',
            'design_sha256' => CuratedInstrumentMethodCatalogService::CONTEXT_PRACTICE_SHA256,
            'original_spec_sha256' => $bindings['spec'], 'original_summary_sha256' => $bindings['summary'],
            'source_manifest_sha256' => $bindings['source'], 'declaration' => ['case_roster' => $roster], 'results' => $rows,
            'fixture_scope' => 'synthetic_instruction_parser_not_market_evidence', ...$flags];
        return [$owner, $panel, $practice, $bindings];
    }

    private function clock(array $planned, array $declaration, string $window): array
    {
        // An internally canonical synthetic receipt, not a claimed producer market receipt.
        $body = ['protocol' => 'replay_executed_clock_v1', 'owner' => 'ordinary_single_position_v1',
            'semantics' => 'previous_closed_candle_next_open_v1', 'index_basis' => 'evaluated_frame_zero_based_v1',
            'input_rows' => $planned['loaded_rows'], 'evaluation_offset_rows' => 0, 'execution_timeframe' => 'M5',
            'duration_seconds' => 300, 'dataset_hash' => $declaration['dataset_sha256'], 'execution_hash' => $declaration['cost_sha256'],
            'policy_hash' => null, 'probe_contract_hash' => null, 'complete' => true,
            'decision_rows' => $planned['evaluated_rows'], 'first_evaluation_index' => $planned['warmup_rows'],
            'last_evaluation_index' => $planned['loaded_rows'] - 1,
            'execution_start' => str_replace('Z', '+00:00', $planned['evaluated_start']),
            'execution_end' => str_replace('Z', '+00:00', $planned['evaluated_end']),
            'signal_start' => (new \DateTimeImmutable($planned['evaluated_start']))->modify('-5 minutes')->format('c'),
            'signal_end' => (new \DateTimeImmutable($planned['evaluated_end']))->modify('-5 minutes')->format('c'),
            'schedule_hash' => $this->sha('synthetic-schedule-'.$window),
            'index_set_hash' => $this->sha('synthetic-indices-'.$window), 'promotion_evidence' => false];
        return [...$body, 'receipt_hash' => $this->hash($body), 'receipt_json' => $this->canonicalJson($body)];
    }

    private function resealClock(array &$row): void
    {
        $body = array_diff_key($row['clock'], ['receipt_hash' => true, 'receipt_json' => true]);
        $row['clock'] = [...$body, 'receipt_hash' => $this->hash($body), 'receipt_json' => $this->canonicalJson($body)];
        $row['clock_receipt_sha256'] = $row['clock']['receipt_hash'];
    }

    private function parse($owner, array $panel, array $practice, array $bindings): array
    {
        return (new ReflectionMethod($owner, 'operatingContextObservedPanel'))->invoke($owner, $panel, $practice, $bindings);
    }

    private function refused($owner, array $panel, array $practice, array $bindings): void
    {
        try { $this->parse($owner, $panel, $practice, $bindings); }
        catch (RuntimeException $error) {
            $this->assertSame('OPERATING_CONTEXT_OBSERVATION_BINDING_REQUIRED', $error->getMessage());
            return;
        }
        $this->fail('Synthetic original identity/evidence substitution must refuse.');
    }

    private function firstIntervention(array $panel): int
    {
        return array_key_first(array_filter($panel['results'], fn ($row) => $row['arm'] !== 'control'));
    }

    private function controlFor(array $panel, int $target): int
    {
        $window = $panel['results'][$target]['window'];
        return array_key_first(array_filter($panel['results'], fn ($row) => $row['window'] === $window && $row['arm'] === 'control'));
    }

    private function hash(array $value): string { return app(ResearchPaperEpochContractService::class)->parameterHash($value); }
    private function sha(string $value): string { return hash('sha256', $value); }
    private function canonicalJson(array $value): string
    {
        $sort = function ($item) use (&$sort) {
            if (! is_array($item)) return $item;
            if (! array_is_list($item)) ksort($item);
            foreach ($item as &$child) $child = $sort($child);
            return $item;
        };
        return json_encode($sort($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private function assertNoOwnLearning(): void
    {
        foreach (['agent_method_policy_revisions', 'agent_method_experiences', 'agent_method_owned_outcomes',
            'agent_knowledge_cards', 'agent_learning_lessons', 'mutation_memories', 'instrument_method_meta_models',
            'model_versions', 'lab_evaluation_runs', 'lab_generations', 'research_experiment_work_items',
            'lab_evolution_credit_events', 'jobs'] as $table) $this->assertDatabaseCount($table, 0);
    }
}
