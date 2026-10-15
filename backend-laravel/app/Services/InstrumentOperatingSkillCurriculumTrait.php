<?php

namespace App\Services;

use RuntimeException;
use Throwable;

/** Curator-taught operating knowledge, never an earned agent or economic skill. */
trait InstrumentOperatingSkillCurriculumTrait
{
    public const OPERATING_CURRICULUM_PROTOCOL = 'instrument_operating_skill_curriculum_v1';
    public const TEACHING_PACK_PROTOCOL = 'instrument_operating_teaching_pack_v1';
    public const OPERATING_CURRICULUM_KEY = 'source_taught_operating_skills_20261014_v1';
    public const SOURCE_PRACTICE_CURRICULUM_KEY = 'source_taught_operating_skills_20261015_v2';
    public const CURATOR_PRACTICE_CURRICULUM_KEY = 'curator_practical_knowhow_20261015_v3';
    public const CONTEXT_KNOWHOW_CURRICULUM_KEY = 'curator_context_knowhow_20261015_v4';
    public const CONTEXT_OBSERVED_CURRICULUM_KEY = 'curator_context_observed_knowhow_20261015_v5';
    private const CONTEXT_OBSERVED_PART_SHA256 = '3a62699181d30e0c549141e9099c0872366818b4831f31c80239e3f0fd15c231';
    private const CONTEXT_OBSERVED_SPEC_SHA256 = '5c3ac482449898e5c1d54012bc583b14125f289caa9059640558e5576e99a8c4';
    private const CONTEXT_OBSERVED_SUMMARY_SHA256 = 'd28d59edc9a18e22b0df9e3d8bf6fc65e63b6178855f61c2a7c7180cdae64384';
    private const CONTEXT_OBSERVED_SOURCE_SHA256 = 'b7d9ec9032ced8e4d05a38921e3f7de6757db80d12429dd79bceeba80a71a48a';
    // New parts are pinned separately: no old source, lesson, pack or outcome is revised.
    private const CURATOR_PRACTICE_PARTS = [
        'curator_price_practice_v3' => ['curator-price-practice-v3.json', 'abcf2388ff29d31c258aba1f54ed6d8ea2ef985adac78f32fb7340ee793e5f33', 14],
        'curator_risk_practice_v3' => ['curator-risk-practice-v3.json', 'c3fd59f128fea4042c58e52792bc3c63ecfd340618bb804a64d107df8ed1acd6', 12],
    ];
    private const CURATOR_PRACTICE_DECLARATION_SHA256 = '5b259901a8166ceabc6b6cbd64b4c88c533f240f7a0c40bd4dd9e48649b7be7b';
    private const CURATOR_PRACTICE_OBSERVATIONS_SHA256 = '0e71b8cc4876e70e3beac95a499b6dc50117aaec7a3f526186e15dc8dac4d058';
    private const CURATOR_PRACTICE_ORIGINAL_OBSERVATIONS_SHA256 = 'abf74de8865b37b42c0a6b8d4ad815245b405b2ae44a60a4c81f5567b774c710';
    private const CURATOR_PRACTICE_COMPLETION_SHA256 = '8a256f2a238131c91faf918fe71b64280ace614e24628d45fcec36d4bc0af0ea';
    private const CURATOR_PRACTICE_COMPLETION_DECLARATION_SHA256 = '7b0586fb12049a14cef9efd185906b87beea9de566cf67fedef1533902e421c9';
    private const CURATOR_PRACTICE_DIAGNOSTICS_SHA256 = 'cb4e2f0b7c2392cbb036feb7d3b338602ab00227e2bf2832a9029649e91a1f77';
    private const CURATOR_PRACTICE_METHOD_PATHS = [
        'internet_closed_bandwidth_squeeze_breakout_v1@1' => 'research/instrument-methods/closed-bandwidth-squeeze/method.json',
    ];
    private const SOURCE_PRACTICE_PART_SHA256 = '94b9a874ca0c3dbe8a1ea7a37045019274d2c550ac5fd50be6253ba802d79f5f';
    private const SOURCE_PRACTICE_OBSERVATIONS_SHA256 = '5f536f929d0f544765011cc1bdb88aa7fb7202276e15077796a8eaf5c3c397ab';
    private const SOURCE_PRACTICE_METHOD_PATHS = [
        'internet_closed_ema_pretrend_pullback_20_50_v1@1' => 'research/instrument-methods/closed-ema-slope-pullback/method.json',
        'internet_closed_sma_atr_percent_cap_v1@1' => 'research/instrument-methods/closed-atr-percent/method.json',
    ];
    private const OPERATING_PARTS = [
        'price_structure_v1' => ['price-structure-v1.json', '37bb5c0a802042cc295472c05262cec77e11c4d63217e2e85d554c28aa0fb68b'],
        'risk_execution_v1' => ['risk-execution-v1.json', '8a40606ecc667f86574642fd1a5e021f93c5493b0bbe00f3aee06023ef63ab97'],
        'model_v1' => ['model-v1.json', '4647ed5f6895757778e91d1eee6125234507bb92f79258d3d6eedcd7c9308ed4'],
    ];

    /** All 26 instructions are discoverable without registry writes or a new scheduler. */
    public function operatingCurriculum(string $curriculumKey = self::OPERATING_CURRICULUM_KEY): array
    {
        try {
            $inventory = $this->instruments->inventory();
            $parts = []; $lessons = []; $sources = []; $curatorObservations = null; $curatorKnowhow = null;
            $contextPractice = null; $contextObserved = null;
            // Future versions add an entry, never overwrite these original parts.
            $versions = [self::OPERATING_CURRICULUM_KEY => self::OPERATING_PARTS,
                self::SOURCE_PRACTICE_CURRICULUM_KEY => self::OPERATING_PARTS,
                self::CURATOR_PRACTICE_CURRICULUM_KEY => self::OPERATING_PARTS,
                self::CONTEXT_KNOWHOW_CURRICULUM_KEY => self::OPERATING_PARTS,
                self::CONTEXT_OBSERVED_CURRICULUM_KEY => self::OPERATING_PARTS];
            $this->operatingNeed(isset($versions[$curriculumKey]), 'OPERATING_CURRICULUM_VERSION_UNKNOWN');
            foreach ($versions[$curriculumKey] as $partKey => [$file, $sha]) {
                $path = resource_path('research/instrument-operating-skills/'.$file);
                $this->operatingNeed(is_file($path) && ! is_link($path) && filesize($path) <= 262144
                    && hash_file('sha256', $path) === $sha, 'OPERATING_CURRICULUM_BYTE_DRIFT');
                $part = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
                $this->operatingNeed(($part['protocol'] ?? null) === 'instrument_operating_skill_lessons_v1'
                    && ($part['part_key'] ?? null) === $partKey && ($part['revision'] ?? null) === 1
                    && is_array($part['sources'] ?? null) && is_array($part['lessons'] ?? null), 'OPERATING_CURRICULUM_SCHEMA_INVALID');
                $parts[$partKey] = $sha;
                foreach ($part['sources'] as $source) {
                    $id = $partKey.':'.($source['source_id'] ?? '');
                    $this->operatingNeed(! isset($sources[$id]) && preg_match('/^https:\/\//', $source['url'] ?? '') === 1
                        && is_string($source['reviewed_scope'] ?? null) && $source['reviewed_scope'] !== ''
                        && is_array($source['not_reviewed'] ?? null), 'OPERATING_SOURCE_REVIEW_REQUIRED');
                    $sources[$id] = $source;
                }
                foreach ($part['lessons'] as $lesson) {
                    $key = $lesson['instrument_key'] ?? '';
                    $this->operatingNeed(isset($inventory['instruments'][$key]) && ! isset($lessons[$key])
                        && ($lesson['group'] ?? null) === $inventory['instruments'][$key]['role'], 'OPERATING_INSTRUMENT_COVERAGE_DRIFT');
                    foreach (['definition', 'calculation_convention', 'source_vs_lab', 'primary_method_ref'] as $field) {
                        $this->operatingNeed(is_string($lesson[$field] ?? null) && $lesson[$field] !== '', 'OPERATING_INSTRUCTION_REQUIRED');
                    }
                    foreach (['established_uses', 'operating_steps', 'inputs', 'wait_when', 'common_mistakes', 'agent_practice', 'source_refs', 'additional_method_refs'] as $field) {
                        $this->operatingNeed(is_array($lesson[$field] ?? null) && array_is_list($lesson[$field]), 'OPERATING_INSTRUCTION_REQUIRED');
                        foreach ($lesson[$field] as $value) $this->operatingNeed(is_string($value) && $value !== '', 'OPERATING_INSTRUCTION_REQUIRED');
                    }
                    $this->operatingNeed(count($lesson['operating_steps']) >= 3 && count($lesson['established_uses']) >= 2
                        && count($lesson['source_refs']) > 0 && count($lesson['agent_practice']) > 0, 'OPERATING_INSTRUCTION_REQUIRED');
                    foreach ($lesson['source_refs'] as &$id) {
                        $id = $partKey.':'.$id;
                        $this->operatingNeed(isset($sources[$id]), 'OPERATING_ORIGINAL_REVIEW_REQUIRED');
                    }
                    unset($id);
                    $this->operatingNeed(is_array($lesson['exercises'] ?? null) && count($lesson['exercises']) >= 2
                        && count($lesson['exercises']) <= 16, 'OPERATING_CORRECTNESS_EXERCISES_REQUIRED');
                    foreach ($lesson['exercises'] as $exercise) {
                        $this->operatingNeed(($exercise['kind'] ?? null) === 'synthetic_operator_exercise'
                            && is_string($exercise['name'] ?? null) && is_array($exercise['inputs'] ?? null)
                            && (is_bool($exercise['expected'] ?? null) || is_int($exercise['expected'] ?? null) || is_float($exercise['expected'] ?? null)),
                            'OPERATING_CORRECTNESS_EXERCISES_REQUIRED');
                    }
                    $lesson['lesson_hash'] = $this->hashes->parameterHash($lesson);
                    $lessons[$key] = $lesson;
                }
            }
            if (in_array($curriculumKey, [self::SOURCE_PRACTICE_CURRICULUM_KEY, self::CURATOR_PRACTICE_CURRICULUM_KEY,
                self::CONTEXT_KNOWHOW_CURRICULUM_KEY, self::CONTEXT_OBSERVED_CURRICULUM_KEY], true)) {
                [$parts, $lessons, $sources, $curatorObservations] = $this->operatingPracticeOverlay($parts, $lessons, $sources);
            }
            if (in_array($curriculumKey, [self::CURATOR_PRACTICE_CURRICULUM_KEY, self::CONTEXT_KNOWHOW_CURRICULUM_KEY,
                self::CONTEXT_OBSERVED_CURRICULUM_KEY], true)) {
                [$parts, $lessons, $sources, $curatorKnowhow] = $this->operatingCuratorKnowhowOverlay($parts, $lessons, $sources);
            }
            if (in_array($curriculumKey, [self::CONTEXT_KNOWHOW_CURRICULUM_KEY, self::CONTEXT_OBSERVED_CURRICULUM_KEY], true)) {
                [$parts, $lessons, $sources, $contextPractice] = $this->operatingContextPracticeOverlay($parts, $lessons, $sources);
            }
            if ($curriculumKey === self::CONTEXT_OBSERVED_CURRICULUM_KEY) {
                [$parts, $contextObserved] = $this->operatingContextObservedOverlay($parts, $contextPractice);
            }
            $keys = array_keys($lessons); $expected = array_keys($inventory['instruments']); sort($keys); sort($expected);
            $this->operatingNeed($keys === $expected, 'OPERATING_INSTRUMENT_COVERAGE_DRIFT');
            ksort($lessons); ksort($sources); ksort($parts);
            $body = ['protocol' => self::OPERATING_CURRICULUM_PROTOCOL, 'curriculum_key' => $curriculumKey, 'manifest_parts' => $parts,
                'instrument_inventory_hash' => $this->hashes->parameterHash($inventory),
                'groups' => $inventory['groups'], 'lessons' => $lessons, 'sources' => $sources,
                'playbooks' => $inventory['playbooks'],
                'acceptance' => ['base_skill' => 'correct_declared_operation_and_refusal',
                    'agent_skill' => 'separate_original_contextual_practice', 'base_profit_required' => false,
                    'exercises_are_market_evidence' => false, 'all_2026_and_later_market_data' => 'paper_only'],
                ...($curatorObservations === null ? [] : ['curator_archive_observations' => $curatorObservations]),
                ...($curatorKnowhow === null ? [] : ['curator_practical_knowhow' => $curatorKnowhow]),
                ...($contextPractice === null ? [] : ['curator_contextual_practice' => $contextPractice]),
                ...($contextObserved === null ? [] : ['curator_context_observed_practice' => $contextObserved]),
                ...$this->operatingCeiling()];
            return [...$body, 'status' => 'operating_skill_curriculum', 'curriculum_hash' => $this->hashes->parameterHash($body)];
        } catch (Throwable $error) { return $this->operatingBlocked($error); }
    }

    /** Teach only actual pinned recipes; the knowledge corpus never chooses an agent's action. */
    public function teachingPack(array $recipeHashes, ?string $curriculumKey = null): array
    {
        try {
            $this->operatingNeed(array_is_list($recipeHashes) && count($recipeHashes) > 0 && count($recipeHashes) <= 32
                && count(array_unique($recipeHashes, SORT_REGULAR)) === count($recipeHashes), 'OPERATING_EXACT_RECIPE_ROSTER_REQUIRED');
            $curriculumKey ??= $this->operatingRecipeCurriculum($recipeHashes);
            $curriculum = $this->operatingCurriculum($curriculumKey);
            $this->operatingNeed(($curriculum['status'] ?? null) === 'operating_skill_curriculum', $curriculum['reason'] ?? 'OPERATING_CURRICULUM_REQUIRED');
            $methods = []; $recipes = []; $lessons = []; $sources = [];
            foreach ($recipeHashes as $recipeHash) {
                $this->operatingNeed(is_string($recipeHash) && preg_match('/^[a-f0-9]{64}$/D', $recipeHash) === 1,
                    'OPERATING_EXACT_RECIPE_ROSTER_REQUIRED');
                $recipe = $this->library->inspectRecipe($recipeHash);
                $this->operatingNeed(($recipe['status'] ?? null) === 'registered_research_only', 'OPERATING_ORIGINAL_RECIPE_REQUIRED');
                foreach ($recipe['contract']['method_contracts'] as $methodHash => $method) {
                    $original = $this->library->inspectMethod($methodHash);
                    $this->operatingNeed(($original['status'] ?? null) === 'registered_research_only'
                        && $this->hashes->parameterHash($original['contract']) === $this->hashes->parameterHash($method), 'OPERATING_ORIGINAL_METHOD_REQUIRED');
                    $lesson = $curriculum['lessons'][$method['instrument_key']] ?? null;
                    $ref = $method['method_key'].'@'.$method['revision'];
                    if ($lesson === null || ! in_array($ref, [$lesson['primary_method_ref'], ...$lesson['additional_method_refs']], true)) {
                        return ['status' => 'unmapped', 'reason' => 'OPERATING_RECIPE_NOT_IN_REVIEWED_LESSONS', ...$this->operatingCeiling()];
                    }
                    // The lesson cannot substitute a different implementation under a familiar name.
                    $expected = $this->operatingDeclaredMethod($ref);
                    sort($expected['source_keys']);
                    $expected['known_limitations'] = array_values(array_unique([...$expected['known_limitations'],
                        ...$this->instruments->inventory()['instruments'][$method['instrument_key']]['runtime_capability']['limitations']]));
                    foreach (['instrument_key', 'method_key', 'revision', 'implementation', 'context_predicate', 'horizon', 'source_keys', 'known_limitations'] as $field) {
                        $this->operatingNeed($this->hashes->parameterHash(['value' => $expected[$field]])
                            === $this->hashes->parameterHash(['value' => $method[$field]]), 'OPERATING_DECLARED_METHOD_DRIFT');
                    }
                    $methods[$methodHash] = $original['contract'];
                    $lessons[$method['instrument_key']] = $lesson;
                    foreach ($lesson['source_refs'] as $sourceRef) $sources[$sourceRef] = $curriculum['sources'][$sourceRef];
                }
                $recipes[$recipeHash] = $recipe['contract'];
            }
            sort($recipeHashes); ksort($methods); ksort($recipes); ksort($lessons); ksort($sources);
            $curatorKnowhow = $curriculum['curator_practical_knowhow'] ?? null;
            if (in_array($curriculumKey, [self::CURATOR_PRACTICE_CURRICULUM_KEY, self::CONTEXT_KNOWHOW_CURRICULUM_KEY,
                self::CONTEXT_OBSERVED_CURRICULUM_KEY], true)) {
                [$curatorKnowhow, $lessons] = $this->operatingCuratorProjection($curatorKnowhow, $lessons, $methods);
            }
            $contextPractice = $curriculum['curator_contextual_practice'] ?? null;
            if (in_array($curriculumKey, [self::CONTEXT_KNOWHOW_CURRICULUM_KEY, self::CONTEXT_OBSERVED_CURRICULUM_KEY], true)) {
                [$contextPractice, $lessons] = $this->operatingContextPracticeProjection($contextPractice, $lessons, $methods);
                $sources = [];
                foreach ($lessons as $lesson) foreach ($lesson['source_refs'] as $sourceRef)
                    $sources[$sourceRef] = $curriculum['sources'][$sourceRef];
                ksort($sources);
            }
            $contextObserved = $curriculum['curator_context_observed_practice'] ?? null;
            if ($curriculumKey === self::CONTEXT_OBSERVED_CURRICULUM_KEY) {
                $contextObserved = $this->operatingContextObservedProjection($contextObserved, $methods);
            }
            if (in_array($curriculumKey, [self::SOURCE_PRACTICE_CURRICULUM_KEY, self::CURATOR_PRACTICE_CURRICULUM_KEY,
                self::CONTEXT_KNOWHOW_CURRICULUM_KEY, self::CONTEXT_OBSERVED_CURRICULUM_KEY], true)) {
                // New instruction envelopes cross the existing Eloquent array
                // metadata transport. It serializes an integral JSON number
                // such as 30.0 as 30. Pin that representation BEFORE sealing
                // this v2 pack, not by weakening a hash or changing any v1
                // resource, curriculum, pack, method or generic JSON codec.
                foreach ($lessons as &$lesson) {
                    unset($lesson['lesson_hash']);
                    $lesson = json_decode(json_encode($lesson, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);
                    $lesson['lesson_hash'] = $this->hashes->parameterHash($lesson);
                }
                unset($lesson);
            }
            $body = ['protocol' => self::TEACHING_PACK_PROTOCOL, 'curriculum_key' => $curriculumKey, 'manifest_parts' => $curriculum['manifest_parts'],
                'curriculum_hash' => $curriculum['curriculum_hash'], 'instrument_inventory_hash' => $curriculum['instrument_inventory_hash'],
                'recipe_hashes' => $recipeHashes, 'lessons' => $lessons, 'sources' => $sources,
                'methods' => $methods, 'recipes' => $recipes,
                'acceptance' => 'rules_supplied_not_agent_mastery', 'exercise_evidence' => 'synthetic_operator_examples_not_market_outcomes',
                ...(in_array($curriculumKey, [self::SOURCE_PRACTICE_CURRICULUM_KEY, self::CURATOR_PRACTICE_CURRICULUM_KEY,
                    self::CONTEXT_KNOWHOW_CURRICULUM_KEY, self::CONTEXT_OBSERVED_CURRICULUM_KEY], true)
                    ? ['instruction_transport' => 'eloquent_metadata_json_numbers_v1'] : []),
                ...(isset($curriculum['curator_archive_observations']) ? ['curator_archive_observations' => $curriculum['curator_archive_observations']] : []),
                ...($curatorKnowhow === null ? [] : ['curator_practical_knowhow' => $curatorKnowhow]),
                ...($contextPractice === null ? [] : ['curator_contextual_practice' => $contextPractice]),
                ...($contextObserved === null ? [] : ['curator_context_observed_practice' => $contextObserved]),
                ...$this->operatingCeiling()];
            if (in_array($curriculumKey, [self::CURATOR_PRACTICE_CURRICULUM_KEY, self::CONTEXT_KNOWHOW_CURRICULUM_KEY,
                self::CONTEXT_OBSERVED_CURRICULUM_KEY], true)) {
                // Pin v3's complete observation envelope to its actual metadata
                // representation before sealing; this changes no earlier pack.
                $body = json_decode(json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);
            }
            return [...$body, 'status' => 'operating_rules_taught', 'pack_hash' => $this->hashes->parameterHash($body)];
        } catch (Throwable $error) { return $this->operatingBlocked($error); }
    }

    public function verifyTeachingPack(array $pack, array $recipeHashes): bool
    {
        if (! is_string($pack['curriculum_key'] ?? null)) return false;
        $original = $this->teachingPack($recipeHashes, $pack['curriculum_key']);
        return ($original['status'] ?? null) === 'operating_rules_taught'
            && $this->hashes->parameterHash($original) === $this->hashes->parameterHash($pack);
    }

    /** Optional prospective research preference, never an earned agent outcome. */
    public function curatorPracticeAdvice(array $pack, array $rules): array
    {
        try {
            $this->operatingNeed(($pack['curriculum_key'] ?? null) === self::CURATOR_PRACTICE_CURRICULUM_KEY
                && is_array($pack['recipe_hashes'] ?? null) && count($pack['recipe_hashes']) <= 3
                && $this->verifyTeachingPack($pack, $pack['recipe_hashes']), 'OPERATING_CURATOR_ADVICE_PACK_REQUIRED');
            return $this->operatingCuratorAdvice($pack, $rules);
        } catch (Throwable $error) { return $this->operatingBlocked($error); }
    }

    /** Re-derive votes from pinned local products, never from caller grade labels. */
    private function operatingCuratorAdvice(array $pack, array $rules): array
    {
        $pool = $pack['recipe_hashes'] ?? [];
        $this->operatingNeed(array_is_list($pool) && count($pool) > 0 && count($pool) <= 3
            && count(array_unique($pool)) === count($pool) && array_is_list($rules), 'OPERATING_CURATOR_ADVICE_POOL_REQUIRED');
        $before = $pack['curator_practical_knowhow']['before_outcome_declaration'] ?? [];
        $rows = $pack['curator_practical_knowhow']['curator_observations']['results'] ?? [];
        $initialOrder = []; $statistics = []; $advisedRules = [];
        foreach ($rules as $rule) {
            $preferred = $rule['preferred_recipes'] ?? [];
            $this->operatingNeed(is_array($preferred) && array_is_list($preferred) && count($preferred) > 0
                && array_diff(array_keys($rule), ['context_predicate', 'preferred_recipes']) === []
                && is_array($rule['context_predicate'] ?? null), 'OPERATING_CURATOR_ADVICE_POOL_REQUIRED');
            $groups = [];
            foreach ($preferred as $position => $hash) {
                $this->operatingNeed(in_array($hash, $pool, true) && ! in_array($hash, $initialOrder, true)
                    && isset($pack['recipes'][$hash]), 'OPERATING_CURATOR_ADVICE_POOL_REQUIRED');
                $recipe = $pack['recipes'][$hash];
                $this->operatingNeed($this->hashes->parameterHash($recipe['context_predicate'])
                    === $this->hashes->parameterHash($rule['context_predicate']), 'OPERATING_CURATOR_ADVICE_CONTEXT_REQUIRED');
                $initialOrder[] = $hash; $targets = [];
                foreach ($recipe['method_contracts'] as $method) {
                    $this->operatingNeed(($method['implementation']['kind'] ?? null) === 'typed_ast'
                        && is_string($method['implementation']['target'] ?? null), 'OPERATING_CURATOR_ADVICE_TYPED_ROLE_REQUIRED');
                    $targets[] = $method['implementation']['target'];
                }
                $targets = array_values(array_unique($targets)); sort($targets);
                $signs = []; $cases = []; $warnings = [];
                // A bundle is not credited from a component-only experiment.
                if (count($recipe['method_contracts']) === 1) {
                    $method = reset($recipe['method_contracts']); $ref = $method['method_key'].'@'.$method['revision'];
                    if ($this->hashes->parameterHash($method['context_predicate'])
                        === $this->hashes->parameterHash($recipe['context_predicate'])) foreach ($rows as $row) {
                        if (($row['method_ref'] ?? null) !== $ref) continue;
                        $case = $row['case_key'] ?? ''; $window = $row['window'] ?? explode('__', $case, 2)[0];
                        if (! is_string($window) || ! isset($before['windows'][$window], $before['case_roster'][$case])
                            || ! str_starts_with($case, $window.'__')
                            || ($row['dataset_sha256'] ?? null) !== ($before['windows'][$window]['dataset_sha256'] ?? null)) continue;
                        $evidence = $this->operatingCuratorEvidence($row, $method);
                        $vote = match ($evidence['grade']) {
                            'local_provisional_positive' => 1, 'local_null' => 0, 'local_negative' => -1, default => null,
                        };
                        if ($vote === null) { $warnings[] = $evidence['grade']; continue; }
                        $signs[$window][$vote] = true; $cases[$window][] = $case;
                    }
                } else $warnings[] = 'bundle_without_direct_bundle_comparison';
                $votes = []; $sourceCases = [];
                foreach ($signs as $window => $distinct) {
                    if (count($distinct) !== 1) { $warnings[] = 'conflicting_window_not_ranked'; continue; }
                    $votes[$window] = (int) array_key_first($distinct);
                    $sourceCases[$window] = array_values(array_unique($cases[$window])); sort($sourceCases[$window]);
                }
                ksort($votes); ksort($sourceCases);
                if ($votes === []) $warnings[] = 'unscored_recipe_kept_at_original_position';
                $warnings = array_values(array_unique($warnings)); sort($warnings);
                $statistics[$hash] = ['method_role_targets' => $targets, 'window_votes' => $votes,
                    'measured_windows' => count($votes), 'sign_vote_total' => array_sum($votes),
                    'source_cases' => $sourceCases, 'warnings' => $warnings];
                if ($votes !== []) {
                    // Only comparable measured recipes can exchange positions.
                    // Unknown/dependency/technical positions remain fixed.
                    $group = $this->hashes->parameterHash(['targets' => $targets, 'windows' => array_keys($votes)]);
                    $groups[$group][] = $position;
                }
            }
            $ordered = $preferred;
            foreach ($groups as $positions) {
                $ranked = $positions;
                usort($ranked, fn (int $a, int $b): int =>
                    ($statistics[$preferred[$b]]['sign_vote_total'] <=> $statistics[$preferred[$a]]['sign_vote_total']) ?: ($a <=> $b));
                foreach ($positions as $index => $position) $ordered[$position] = $preferred[$ranked[$index]];
            }
            $advisedRules[] = [...$rule, 'preferred_recipes' => $ordered];
        }
        $seen = $initialOrder; $expected = $pool; sort($seen); sort($expected);
        $this->operatingNeed($seen === $expected, 'OPERATING_CURATOR_ADVICE_POOL_REQUIRED'); ksort($statistics);
        $body = ['protocol' => 'curator_practical_preference_advice_v1', 'attribution' => 'curator_observation',
            'teaching_pack_hash' => $pack['pack_hash'], 'curriculum_hash' => $pack['curriculum_hash'],
            'manifest_parts' => $pack['manifest_parts'], 'initial_recipe_order' => $initialOrder,
            'selection' => 'stable_sign_votes_same_context_role_and_measured_windows',
            'rules' => $advisedRules, 'default_action' => 'BASELINE', 'recipe_statistics' => $statistics,
            'own_agent_outcome' => false, 'independent_validation' => false, 'skill_credit' => false,
            ...$this->operatingCeiling()];
        return [...$body, 'status' => 'curator_research_preference_advice', 'advice_hash' => $this->hashes->parameterHash($body)];
    }

    private function operatingDeclaredMethod(string $ref): array
    {
        if (str_starts_with($ref, 'curator_dmi_') || str_starts_with($ref, 'curator_strong_')) {
            $catalog = $this->contextPracticeCatalog();
            $this->operatingNeed(($catalog['status'] ?? null) === 'context_practice_catalog', 'OPERATING_CONTEXT_SOURCE_DRIFT');
            foreach ($catalog['methods'] as $row) {
                $method = $row['method_input'];
                if ($method['method_key'].'@'.$method['revision'] === $ref) return $method;
            }
            throw new RuntimeException('OPERATING_DECLARED_METHOD_MISSING');
        }
        foreach ([self::CATALOG_KEY, self::EXPANDED_CATALOG_KEY] as $catalogKey) {
            $data = $this->declaration($catalogKey);
            $sourceHashes = [];
            foreach ($data['sources'] as $source) $sourceHashes[$source['source_id']] = $this->expectedSourceHash($source);
            foreach ($data['methods'] as $method) if ($method['method_key'].'@'.$method['revision'] === $ref) {
                return $this->methodInput($method, $sourceHashes);
            }
        }
        foreach (['observed-quote-firewall', 'closed-band-reentry', 'closed-ema-pullback', 'closed-donchian-retest',
            'calibrated-meta-score', 'closed-macd-cross', 'closed-rsi-recovery', 'closed-chandelier-exit', 'native-single-partial-runner',
            'closed-ema-slope-pullback', 'closed-atr-percent'] as $folder) {
            $data = json_decode(file_get_contents(resource_path('research/instrument-methods/'.$folder.'/method.json')), true, 64, JSON_THROW_ON_ERROR);
            if ($data['method_key'].'@'.$data['revision'] === $ref) return $data;
        }
        if (isset(self::CURATOR_PRACTICE_METHOD_PATHS[$ref])) {
            $data = json_decode(file_get_contents(resource_path(self::CURATOR_PRACTICE_METHOD_PATHS[$ref])), true, 64, JSON_THROW_ON_ERROR);
            $this->operatingNeed(($data['method_key'] ?? '').'@'.($data['revision'] ?? '') === $ref, 'OPERATING_DECLARED_METHOD_DRIFT');
            return $data;
        }
        throw new RuntimeException('OPERATING_DECLARED_METHOD_MISSING');
    }

    /** Selecting one of these two exact prospective methods opts into v2 only.
     *  Main pack construction still reopens and compares every original field;
     *  a familiar name with substituted source/rules never produces a pack. */
    private function operatingRecipeCurriculum(array $recipeHashes): string
    {
        $selected = self::OPERATING_CURRICULUM_KEY;
        foreach ($recipeHashes as $hash) {
            $this->operatingNeed(is_string($hash) && preg_match('/^[a-f0-9]{64}$/D', $hash) === 1,
                'OPERATING_EXACT_RECIPE_ROSTER_REQUIRED');
            $recipe = $this->library->inspectRecipe($hash);
            $this->operatingNeed(($recipe['status'] ?? null) === 'registered_research_only', 'OPERATING_ORIGINAL_RECIPE_REQUIRED');
            foreach ($recipe['contract']['method_contracts'] as $method) {
                if (isset(self::SOURCE_PRACTICE_METHOD_PATHS[$method['method_key'].'@'.$method['revision']])) {
                    $selected = self::SOURCE_PRACTICE_CURRICULUM_KEY;
                }
            }
        }
        return $selected;
    }

    /** Source-scoped instruction pending original outcomes; it is not a vote or an agent experience. */
    private function operatingContextPracticeOverlay(array $parts, array $lessons, array $sources): array
    {
        $catalog = $this->contextPracticeCatalog();
        $this->operatingNeed(($catalog['status'] ?? null) === 'context_practice_catalog'
            && ($catalog['design_sha256'] ?? null) === self::CONTEXT_PRACTICE_SHA256
            && count($catalog['methods'] ?? []) === 24 && isset($lessons['regime_router']), 'OPERATING_CONTEXT_SOURCE_DRIFT');
        $partKey = 'curator_context_practice_v4'; $design = $catalog['design']; $sourceRefs = [];
        foreach ($catalog['sources'] as $sourceId => $source) {
            $id = $partKey.':'.$sourceId; $metadata = $source['metadata'] ?? [];
            $this->operatingNeed(! isset($sources[$id]) && is_string($source['reference'] ?? null)
                && preg_match('/^https:\/\//', $source['reference']) === 1
                && is_string($metadata['reviewed_scope'] ?? null) && $metadata['reviewed_scope'] !== ''
                && is_array($metadata['not_reviewed'] ?? null) && $metadata['not_reviewed'] !== []
                && is_string($catalog['source_keys'][$sourceId] ?? null), 'OPERATING_SOURCE_REVIEW_REQUIRED');
            $sources[$id] = ['source_id' => $sourceId, 'url' => $source['reference'],
                'reviewed_scope' => $metadata['reviewed_scope'], 'not_reviewed' => $metadata['not_reviewed'],
                'source_key' => $catalog['source_keys'][$sourceId], 'content_hash' => hash('sha256', $source['content']),
                'representation' => $metadata['representation'], 'reviewed_on' => $metadata['reviewed_on'],
                'publisher' => $metadata['publisher'], 'qualification_granted' => false];
            $sourceRefs[] = $id;
        }
        sort($sourceRefs);
        foreach (['operating_steps', 'wait_when', 'limitations'] as $field) {
            $this->operatingNeed(is_array($design[$field] ?? null) && array_is_list($design[$field])
                && $design[$field] !== [], 'OPERATING_INSTRUCTION_REQUIRED');
            foreach ($design[$field] as $instruction)
                $this->operatingNeed(is_string($instruction) && $instruction !== '', 'OPERATING_INSTRUCTION_REQUIRED');
        }
        $profiles = [];
        $sourceVsLab = 'Reviewed StockCharts Wilder DI/ADX calculation and strength interpretation inform the rule; '
            .'the separate Bollinger manual is contextual motivation, not a reproduced entry system. '
            .'Exact scalar scopes, 150-observation maturity, strict thresholds and comparative design are laboratory declarations, '
            .'not publisher qualification, calibrated probabilities or measured market superiority.';
        $unknown = ['No contextual performance has yet been measured by this design.',
            'Range/transition regimes, low volatility, Asian sessions, different horizons and actual order-book flow remain outside this scope.',
            'A session label is not independent replication; current pre-2026 archive is reused research, not an untouched validation window.'];
        foreach ($catalog['methods'] as $row) {
            $method = $row['method_input']; $ref = $method['method_key'].'@'.$method['revision'];
            $context = $method['context_predicate'];
            $this->operatingNeed(($method['instrument_key'] ?? null) === 'regime_router'
                && in_array($row['style'] ?? null, ['dmi', 'strong'], true)
                && count($context) === 3 && array_diff(array_keys($context), ['regime', 'session', 'volatility']) === []
                && count(array_filter($context, 'is_string')) === 3
                && ($method['implementation']['kind'] ?? null) === 'typed_ast'
                && ($method['implementation']['target'] ?? null) === 'confirmation', 'OPERATING_CONTEXT_METHOD_REQUIRED');
            $profiles[$ref] = ['method_ref' => $ref, 'instrument_key' => 'regime_router', 'style' => $row['style'],
                'context_predicate' => $context, 'horizon' => $method['horizon'], 'target' => 'confirmation',
                'operating_steps' => $design['operating_steps'], 'wait_when' => $design['wait_when'],
                'source_vs_lab' => $sourceVsLab, 'limitations' => $design['limitations'], 'unknown_scope' => $unknown,
                'source_refs' => $sourceRefs, 'method_binding' => $method,
                'evidence_state' => 'awaiting_original_panel', 'attribution' => 'curator_source_research',
                'own_agent_outcome' => false, 'independent_validation' => false, 'skill_credit' => false,
                ...$this->operatingCeiling()];
        }
        ksort($profiles);
        $lesson = $lessons['regime_router']; unset($lesson['lesson_hash']);
        $lesson['additional_method_refs'] = array_values(array_unique([...$lesson['additional_method_refs'], ...array_keys($profiles)]));
        $lesson['source_refs'] = array_values(array_unique([...$lesson['source_refs'], ...$sourceRefs]));
        $lesson['contextual_operating_practice'] = $profiles;
        $lesson['lesson_hash'] = $this->hashes->parameterHash($lesson); $lessons['regime_router'] = $lesson;
        $parts[$partKey] = self::CONTEXT_PRACTICE_SHA256;
        $practice = ['protocol' => 'curator_context_operating_practice_v1', 'curriculum_key' => self::CONTEXT_KNOWHOW_CURRICULUM_KEY,
            'design_key' => self::CONTEXT_PRACTICE_KEY, 'design_sha256' => self::CONTEXT_PRACTICE_SHA256,
            'catalog_hash' => $catalog['catalog_hash'], 'evidence_state' => 'awaiting_original_panel',
            'method_profiles' => $profiles, 'source_refs' => $sourceRefs, 'unknown_scope' => $unknown,
            'attribution' => 'curator_source_research', 'own_agent_outcome' => false,
            'independent_validation' => false, 'skill_credit' => false, ...$this->operatingCeiling()];
        return [$parts, $lessons, $sources, $practice];
    }

    /** The full design stays pinned; only selected exact contextual instructions cross into a pack. */
    private function operatingContextPracticeProjection(array $practice, array $lessons, array $methods): array
    {
        $selected = [];
        foreach ($methods as $method) {
            $ref = $method['method_key'].'@'.$method['revision'];
            if (isset($practice['method_profiles'][$ref])) $selected[$ref] = true;
        }
        $allRefs = array_keys($practice['method_profiles']); $allSources = $practice['source_refs'];
        $practice['method_profiles'] = array_intersect_key($practice['method_profiles'], $selected);
        $practice['source_refs'] = [];
        foreach ($practice['method_profiles'] as $profile)
            $practice['source_refs'] = array_values(array_unique([...$practice['source_refs'], ...$profile['source_refs']]));
        sort($practice['source_refs']);
        foreach ($lessons as &$lesson) {
            $profiles = array_intersect_key($lesson['contextual_operating_practice'] ?? [], $selected);
            $lesson['additional_method_refs'] = array_values(array_filter($lesson['additional_method_refs'],
                fn (string $ref): bool => ! in_array($ref, $allRefs, true) || isset($selected[$ref])));
            $lesson['source_refs'] = array_values(array_filter($lesson['source_refs'],
                fn (string $id): bool => ! in_array($id, $allSources, true) || in_array($id, $practice['source_refs'], true)));
            if ($profiles === []) unset($lesson['contextual_operating_practice']);
            else $lesson['contextual_operating_practice'] = $profiles;
            unset($lesson['lesson_hash']); $lesson['lesson_hash'] = $this->hashes->parameterHash($lesson);
        }
        unset($lesson);
        if ($selected === []) return [null, $lessons];
        $practice['instruction_projection'] = ['scope' => 'exact_selected_contextual_method_refs', 'method_refs' => array_keys($selected),
            'full_design_sha256' => self::CONTEXT_PRACTICE_SHA256];
        return [$practice, $lessons];
    }

    private function operatingContextObservedOverlay(array $parts, array $practice): array
    {
        $panel = $this->operatingPinnedJson(
            'research/instrument-operating-skills/curator-context-observations-v5.json',
            self::CONTEXT_OBSERVED_PART_SHA256, 524288);
        $observed = $this->operatingContextObservedPanel($panel, $practice, [
            'spec' => self::CONTEXT_OBSERVED_SPEC_SHA256,
            'summary' => self::CONTEXT_OBSERVED_SUMMARY_SHA256,
            'source' => self::CONTEXT_OBSERVED_SOURCE_SHA256,
        ]);
        $parts['curator_context_observations_v5'] = self::CONTEXT_OBSERVED_PART_SHA256;
        return [$parts, $observed];
    }

    /** Pure read-only contextual bundle parser; original event verification precedes export. */
    private function operatingContextObservedPanel(array $panel, array $practice, array $bindings): array
    {
        $need = fn (bool $ok) => $this->operatingNeed($ok, 'OPERATING_CONTEXT_OBSERVATION_BINDING_REQUIRED');
        $sha = fn ($value): bool => is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
        $hash = fn (array $value): string => $this->hashes->parameterHash($value);
        $utc = static fn (string $value): string => (new \DateTimeImmutable($value))
            ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $need(($panel['protocol'] ?? null) === 'curator_context_observed_practice_v1'
            && ($panel['curriculum_key'] ?? null) === self::CONTEXT_OBSERVED_CURRICULUM_KEY
            && ($panel['attribution'] ?? null) === 'curator_observation'
            && ($panel['design_sha256'] ?? null) === CuratedInstrumentMethodCatalogService::CONTEXT_PRACTICE_SHA256);
        foreach (['spec' => 'original_spec_sha256', 'summary' => 'original_summary_sha256',
            'source' => 'source_manifest_sha256'] as $key => $field)
            $need($sha($bindings[$key] ?? null) && ($panel[$field] ?? null) === $bindings[$key]);
        $flags = ['own_agent_outcome', 'independent_validation', 'skill_credit', 'qualification_granted',
            'economic_authority', 'paper_authority', 'live_authority'];
        foreach ($flags as $flag) $need(($panel[$flag] ?? null) === false);
        $catalog = app(CuratedInstrumentMethodCatalogService::class)->contextPracticeCatalog();
        $need(($catalog['status'] ?? null) === 'context_practice_catalog');
        $design = $catalog['design'];
        $expected = [];
        foreach ($design['panel']['windows'] as $window) foreach ($design['panel']['arms_per_window'] as $arm) {
            $refs = []; $sourceKeys = [];
            if ($arm !== 'control') {
                [$style, $session] = explode('_', $arm, 2);
                foreach ($catalog['methods'] as $method) if ($method['style'] === $style
                    && $method['method_input']['context_predicate']['session'] === $session) {
                    $input = $method['method_input'];
                    $refs[] = $input['method_key'].'@'.$input['revision'];
                    $sourceKeys = [...$sourceKeys, ...$input['source_keys']];
                }
            }
            sort($refs); $sourceKeys = array_values(array_unique($sourceKeys)); sort($sourceKeys);
            $need(count($refs) === ($arm === 'control' ? 0 : 4));
            $expected[$window.'__'.$arm] = ['window' => $window, 'arm' => $arm,
                'method_refs' => $refs, 'source_keys' => $sourceKeys];
        }
        $roster = $panel['declaration']['case_roster'] ?? null; $rows = $panel['results'] ?? null;
        $need(is_array($roster) && ! array_is_list($roster) && is_array($rows)
            && array_is_list($rows) && count($rows) === 28 && count($roster) === 28);
        $keys = array_keys($roster); $expectedKeys = array_keys($expected); sort($keys); sort($expectedKeys);
        $need($keys === $expectedKeys);
        $byKey = []; $entryAvailability = [];
        foreach ($rows as $row) {
            $key = $row['case_key'] ?? null;
            $need(is_string($key) && isset($expected[$key], $roster[$key]) && ! isset($byKey[$key]));
            $declared = $roster[$key];
            foreach (['window', 'arm', 'method_refs', 'source_keys'] as $field)
                $need(($row[$field] ?? null) === $expected[$key][$field]
                    && ($declared[$field] ?? null) === $expected[$key][$field]);
            foreach (['request_sha256', 'dataset_sha256', 'cost_sha256',
                'window_sha256', 'source_manifest_sha256'] as $field)
                $need($sha($row[$field] ?? null) && ($row[$field] ?? null) === ($declared[$field] ?? null));
            $need($row['source_manifest_sha256'] === $bindings['source']
                && in_array($row['status'] ?? null, ['completed', 'technical_error', 'timeout', 'cancelled'], true));
            foreach ($flags as $flag) $need(($row[$flag] ?? null) === false);
            $clock = $declared['clock'] ?? [];
            foreach (['loaded_rows', 'warmup_rows', 'evaluated_rows'] as $field)
                $need(is_int($clock[$field] ?? null) && $clock[$field] >= 0);
            $need($clock['warmup_rows'] === $design['panel']['warmup_rows']
                && $clock['evaluated_rows'] > 0
                && $clock['loaded_rows'] === $clock['warmup_rows'] + $clock['evaluated_rows']
                && array_diff(array_keys($clock), ['loaded_rows', 'warmup_rows', 'evaluated_rows',
                    'evaluated_start', 'evaluated_end']) === []);
            foreach (['evaluated_start', 'evaluated_end'] as $field)
                $need(is_string($clock[$field] ?? null)
                    && preg_match('/^20[0-9]{2}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D', $clock[$field]) === 1
                    && $clock[$field] < '2026-01-01T00:00:00Z');
            $need($clock['evaluated_start'] <= $clock['evaluated_end']);
            if ($row['status'] === 'completed') {
                foreach (['response_sha256', 'clock_receipt_sha256', 'trace_sha256', 'ledger_sha256'] as $field)
                    $need($sha($row[$field] ?? null));
                $actual = $row['clock'] ?? null;
                $need(is_array($actual) && ($actual['protocol'] ?? null) === 'replay_executed_clock_v1'
                    && ($actual['owner'] ?? null) === 'ordinary_single_position_v1'
                    && ($actual['complete'] ?? null) === true && ($actual['evaluation_offset_rows'] ?? null) === 0
                    && ($actual['input_rows'] ?? null) === $clock['loaded_rows']
                    && ($actual['decision_rows'] ?? null) === $clock['evaluated_rows']
                    && ($actual['first_evaluation_index'] ?? null) === $clock['warmup_rows']
                    && ($actual['last_evaluation_index'] ?? null) === $clock['loaded_rows'] - 1
                    && ($actual['dataset_hash'] ?? null) === $row['dataset_sha256']
                    && ($actual['execution_hash'] ?? null) === $row['cost_sha256']
                    && is_string($actual['execution_start'] ?? null) && is_string($actual['execution_end'] ?? null)
                    && (str_ends_with($actual['execution_start'], 'Z') || str_ends_with($actual['execution_start'], '+00:00'))
                    && (str_ends_with($actual['execution_end'], 'Z') || str_ends_with($actual['execution_end'], '+00:00'))
                    && $utc($actual['execution_start']) === $clock['evaluated_start']
                    && $utc($actual['execution_end']) === $clock['evaluated_end']);
                foreach (['schedule_hash', 'index_set_hash', 'receipt_hash'] as $field)
                    $need($sha($actual[$field] ?? null));
                $need($row['clock_receipt_sha256'] === $actual['receipt_hash']
                    && is_string($actual['receipt_json'] ?? null));
                $body = json_decode($actual['receipt_json'], true, 64, JSON_THROW_ON_ERROR);
                $actualBody = array_diff_key($actual, ['receipt_hash' => true, 'receipt_json' => true]);
                $need(is_array($body) && $hash($body) === $actual['receipt_hash'] && $hash($actualBody) === $hash($body));
                $need((is_int($row['net_cash'] ?? null) || is_float($row['net_cash'] ?? null))
                    && is_finite((float) $row['net_cash'])
                    && is_int($row['mature_trades'] ?? null) && $row['mature_trades'] >= 0);
            } else {
                // A technical row remains terminal history, never a synthetic zero response.
                $need(array_key_exists('clock', $row) && $row['clock'] === null
                    && ($row['clock_receipt_sha256'] ?? null) === null
                    && ($row['net_cash'] ?? null) === null && ($row['mature_trades'] ?? null) === null);
                foreach (['response_sha256', 'trace_sha256', 'ledger_sha256'] as $field)
                    $need(array_key_exists($field, $row) && $row[$field] === null);
                $need(array_key_exists('context_cells', $row) && $row['context_cells'] === []);
                // Genuine technical response/stderr file hashes may be kept
                // separately in raw_product_bindings, never as assessed products.
            }
            $cells = $row['context_cells'] ?? [];
            $need(is_array($cells) && array_is_list($cells) && count($cells) <= 128);
            $reach = ['reached' => 0, 'available' => 0, 'changed_decisions' => 0]; $seenContexts = [];
            foreach ($cells as $cell) {
                $need(is_array($cell) && array_diff(array_keys($cell),
                    ['context', 'entry_method_events', 'selected', 'reached', 'available', 'pass', 'veto',
                        'changed', 'unavailable_reasons', 'reason_counts']) === []
                    && is_array($cell['context'] ?? null));
                $need(array_diff(array_keys($cell['context']), ['session', 'regime', 'volatility', 'venue_phase', 'direction']) === []);
                foreach ($cell['context'] as $value) $need($value === null || is_string($value));
                $contextKey = $hash($cell['context']); $need(! isset($seenContexts[$contextKey])); $seenContexts[$contextKey] = true;
                foreach (['entry_method_events', 'selected', 'reached', 'available', 'pass', 'veto', 'changed'] as $field)
                    $need(is_int($cell[$field] ?? null) && $cell[$field] >= 0);
                $need($cell['selected'] <= $cell['entry_method_events'] && $cell['reached'] <= $cell['selected']
                    && $cell['available'] <= $cell['reached'] && $cell['changed'] <= $cell['reached']
                    && $cell['pass'] + $cell['veto'] === $cell['available']);
                foreach (['unavailable_reasons', 'reason_counts'] as $field) {
                    $counter = $cell[$field] ?? null;
                    $need(is_array($counter) && ($counter === [] || ! array_is_list($counter)) && count($counter) <= 96);
                    foreach ($counter as $reason => $count)
                        $need(is_string($reason) && $reason !== '' && is_int($count) && $count >= 0);
                }
                $need(array_sum($cell['reason_counts']) === $cell['entry_method_events']
                    && array_sum($cell['unavailable_reasons']) === $cell['reached'] - $cell['available']);
                $reach['reached'] += $cell['reached']; $reach['available'] += $cell['available'];
                $reach['changed_decisions'] += $cell['changed'];
            }
            $entryAvailability[$key] = $reach;
            $byKey[$key] = $row;
        }
        $need(count($byKey) === 28);
        $contrasts = [];
        foreach ($byKey as $key => $row) {
            if ($row['arm'] === 'control') continue;
            $controlKey = $row['window'].'__control'; $control = $byKey[$controlKey];
            foreach (['dataset_sha256', 'cost_sha256', 'window_sha256', 'source_manifest_sha256'] as $field)
                $need($row[$field] === $control[$field]);
            $need($hash($roster[$key]['clock']) === $hash($roster[$controlKey]['clock']));
            $grade = 'technical_or_incomplete'; $delta = null;
            if ($row['status'] === 'completed' && $control['status'] === 'completed') {
                $need($row['clock']['schedule_hash'] === $control['clock']['schedule_hash']
                    && $row['clock']['index_set_hash'] === $control['clock']['index_set_hash']);
                $delta = (float) $row['net_cash'] - (float) $control['net_cash'];
                $reach = $entryAvailability[$key];
                $grade = match (true) {
                    $reach['reached'] === 0 => 'unactivated',
                    $reach['available'] === 0 => 'unavailable',
                    $reach['available'] < $reach['reached'] => 'partially_unavailable',
                    $reach['changed_decisions'] === 0 => 'no_behavior_change',
                    min($row['mature_trades'], $control['mature_trades'])
                        < $design['panel']['minimum_mature_trades_for_descriptive_comparison'] => 'underpowered',
                    abs($delta) <= .00000001 => 'bundle_local_null',
                    $delta > 0 => 'bundle_local_positive',
                    default => 'bundle_local_negative',
                };
            }
            $contrasts[$key] = ['original_case_key' => $key, 'original_control_case_key' => $controlKey,
                'original_method_refs' => $row['method_refs'], 'grade' => $grade, 'net_cash_delta' => $delta,
                'effect_scope' => 'whole_continuous_account_original_four_method_intervention_bundle',
                'context_cells_role' => 'descriptive_original_entry_or_decision_context_not_isolated_causal_effect',
                'own_agent_outcome' => false, 'independent_validation' => false, 'skill_credit' => false,
                ...$this->operatingCeiling()];
        }
        ksort($byKey); ksort($contrasts);
        return [...$panel, 'results' => array_values($byKey), 'bundle_contrasts' => $contrasts,
            'evidence_state' => 'observed_reused_archive_originals_not_independent_qualification',
            'method_grade_assignment' => 'none_bundle_cash_is_not_individual_method_credit',
            ...$this->operatingCeiling()];
    }

    /** Project relevance, not the intervention's physical identity or causal scope. */
    private function operatingContextObservedProjection(array $observed, array $methods): ?array
    {
        $selected = [];
        foreach ($methods as $method) $selected[$method['method_key'].'@'.$method['revision']] = true;
        $rows = array_column($observed['results'], null, 'case_key');
        $keep = []; $contrasts = [];
        foreach ($observed['bundle_contrasts'] as $key => $contrast) {
            $relevant = array_values(array_filter($contrast['original_method_refs'], fn ($ref) => isset($selected[$ref])));
            if ($relevant === []) continue;
            $contrasts[$key] = [...$contrast, 'selected_instruction_method_refs' => $relevant];
            $keep[$key] = true; $keep[$contrast['original_control_case_key']] = true;
        }
        if ($contrasts === []) return null;
        $observed['results'] = array_values(array_intersect_key($rows, $keep));
        $observed['bundle_contrasts'] = $contrasts;
        $observed['observation_projection'] = ['scope' => 'selected_instruction_relevance_original_bundle_identity_preserved',
            'selected_method_refs' => array_keys($selected), 'full_observation_part_sha256' => self::CONTEXT_OBSERVED_PART_SHA256];
        // Keep the full original declaration and original summary SHA. Projection is not
        // a new panel, a new independent window or a replacement original receipt.
        return $observed;
    }

    /** Append method-specific practice, never replace any v1 primary/exercise. */
    private function operatingPracticeOverlay(array $parts, array $lessons, array $sources): array
    {
        $extension = $this->operatingPinnedJson('research/instrument-operating-skills/source-practice-v2.json',
            self::SOURCE_PRACTICE_PART_SHA256);
        $this->operatingNeed(($extension['protocol'] ?? null) === 'instrument_operating_practice_extension_v1'
            && ($extension['part_key'] ?? null) === 'source_practice_v2' && ($extension['revision'] ?? null) === 1
            && ($extension['curriculum_key'] ?? null) === self::SOURCE_PRACTICE_CURRICULUM_KEY
            && ($extension['parent_curriculum_key'] ?? null) === self::OPERATING_CURRICULUM_KEY
            && ($extension['parent_parts'] ?? null) === $parts
            && ($extension['acceptance']['mastery_proven'] ?? null) === false
            && ($extension['acceptance']['economic_authority'] ?? null) === false
            && ($extension['archive_practice']['qualification_granted'] ?? null) === false
            && ($extension['archive_practice']['independent_validation'] ?? null) === false,
            'OPERATING_PRACTICE_EXTENSION_INVALID');
        $this->operatingNeed(is_array($extension['sources'] ?? null) && array_is_list($extension['sources'])
            && count($extension['sources']) === 2 && is_array($extension['profiles'] ?? null)
            && array_is_list($extension['profiles']) && count($extension['profiles']) === 2, 'OPERATING_PRACTICE_EXTENSION_INVALID');
        $newSources = [];
        foreach ($extension['sources'] as $source) {
            $id = 'source_practice_v2:'.($source['source_id'] ?? '');
            $this->operatingNeed(! isset($sources[$id]) && ! isset($newSources[$id])
                && preg_match('/^https:\/\//', $source['url'] ?? '') === 1
                && is_string($source['reviewed_scope'] ?? null) && $source['reviewed_scope'] !== ''
                && is_array($source['not_reviewed'] ?? null) && count($source['not_reviewed']) > 0
                && preg_match('/^[a-f0-9]{64}$/D', $source['source_key'] ?? '') === 1, 'OPERATING_SOURCE_REVIEW_REQUIRED');
            $this->operatingPinnedJson($source['source_path'] ?? '', $source['source_file_sha256'] ?? '');
            $newSources[$id] = $source;
        }
        $refs = [];
        foreach ($extension['profiles'] as $profile) {
            $ref = $profile['method_ref'] ?? ''; $key = $profile['instrument_key'] ?? '';
            $this->operatingNeed(isset(self::SOURCE_PRACTICE_METHOD_PATHS[$ref]) && ! isset($refs[$ref])
                && isset($lessons[$key]) && ($profile['method_path'] ?? null) === self::SOURCE_PRACTICE_METHOD_PATHS[$ref],
                'OPERATING_PRACTICE_METHOD_REQUIRED');
            $method = $this->operatingPinnedJson($profile['method_path'], $profile['method_file_sha256'] ?? '');
            $this->operatingNeed(($method['method_key'] ?? '').'@'.($method['revision'] ?? '') === $ref
                && ($method['instrument_key'] ?? null) === $key
                && ($method['context_predicate'] ?? null) === ($profile['context_predicate'] ?? null), 'OPERATING_PRACTICE_METHOD_REQUIRED');
            foreach (['profile_key', 'reviewed_rule', 'calculation_convention', 'source_vs_lab'] as $field)
                $this->operatingNeed(is_string($profile[$field] ?? null) && $profile[$field] !== '', 'OPERATING_INSTRUCTION_REQUIRED');
            foreach (['operating_steps', 'inputs', 'wait_when', 'why_could_fail', 'agent_practice', 'source_refs'] as $field) {
                $this->operatingNeed(is_array($profile[$field] ?? null) && array_is_list($profile[$field]) && count($profile[$field]) > 0,
                    'OPERATING_INSTRUCTION_REQUIRED');
                foreach ($profile[$field] as $value) $this->operatingNeed(is_string($value) && $value !== '', 'OPERATING_INSTRUCTION_REQUIRED');
            }
            $sourceKeys = [];
            foreach ($profile['source_refs'] as &$sourceRef) {
                $sourceRef = 'source_practice_v2:'.$sourceRef;
                $this->operatingNeed(isset($newSources[$sourceRef]), 'OPERATING_ORIGINAL_REVIEW_REQUIRED');
                $sourceKeys[] = $newSources[$sourceRef]['source_key'];
            }
            unset($sourceRef);
            sort($sourceKeys); $declaredSourceKeys = $method['source_keys']; sort($declaredSourceKeys);
            $this->operatingNeed($sourceKeys === $declaredSourceKeys, 'OPERATING_ORIGINAL_REVIEW_REQUIRED');
            $this->operatingNeed(is_array($profile['exercises'] ?? null) && array_is_list($profile['exercises'])
                && count($profile['exercises']) >= 2 && count($profile['exercises']) <= 16, 'OPERATING_CORRECTNESS_EXERCISES_REQUIRED');
            foreach ($profile['exercises'] as $exercise) $this->operatingNeed(($exercise['kind'] ?? null) === 'synthetic_operator_exercise'
                && is_string($exercise['name'] ?? null) && is_array($exercise['inputs'] ?? null)
                && is_bool($exercise['expected'] ?? null), 'OPERATING_CORRECTNESS_EXERCISES_REQUIRED');
            $profile['archive_practice'] = $extension['archive_practice'];
            $lesson = $lessons[$key]; unset($lesson['lesson_hash']);
            $lesson['additional_method_refs'] = array_values(array_unique([...$lesson['additional_method_refs'], $ref]));
            $lesson['source_refs'] = array_values(array_unique([...$lesson['source_refs'], ...$profile['source_refs']]));
            $lesson['practice_profiles'][] = $profile;
            $lesson['lesson_hash'] = $this->hashes->parameterHash($lesson); $lessons[$key] = $lesson;
            $refs[$ref] = true;
        }
        $parts['source_practice_v2'] = self::SOURCE_PRACTICE_PART_SHA256;
        $observations = $this->operatingPinnedJson('research/instrument-operating-skills/curator-archive-observations-v2.json',
            self::SOURCE_PRACTICE_OBSERVATIONS_SHA256);
        $this->operatingNeed(($observations['protocol'] ?? null) === 'curator_archive_observations_v1'
            && ($observations['part_key'] ?? null) === 'curator_archive_observations_v2'
            && ($observations['revision'] ?? null) === 1
            && ($observations['attribution'] ?? null) === 'curator_backtest_observation_not_original_agent_experience'
            && ($observations['original_spec_sha256'] ?? null) === $extension['archive_practice']['spec_sha256']
            && ($observations['original_summary_sha256'] ?? null) === 'd2e813760297ed3900c921f16096641746b6112a39785227db7fcfa9e697ad16'
            && is_array($observations['results'] ?? null) && array_is_list($observations['results']) && count($observations['results']) === 10
            && is_array($observations['control_contrasts'] ?? null) && count($observations['control_contrasts']) === 8,
            'OPERATING_CURATOR_OBSERVATIONS_REQUIRED');
        foreach (['independent_validation', 'qualification_granted', 'mastery_proven', 'economic_authority', 'skill_credit',
            'own_agent_outcome', 'paper_authority', 'live_authority'] as $flag)
            $this->operatingNeed(($observations[$flag] ?? null) === false, 'OPERATING_CURATOR_AUTHORITY_FORBIDDEN');
        foreach ($observations['results'] as $result) $this->operatingNeed(($result['status'] ?? null) === 'completed'
            && ($result['timeout'] ?? null) === false && preg_match('/^[a-f0-9]{64}$/D', $result['request_sha256'] ?? '') === 1
            && preg_match('/^[a-f0-9]{64}$/D', $result['response_sha256'] ?? '') === 1, 'OPERATING_CURATOR_ORIGINAL_IDENTITIES_REQUIRED');
        $parts['curator_archive_observations_v2'] = self::SOURCE_PRACTICE_OBSERVATIONS_SHA256;
        return [$parts, $lessons, [...$sources, ...$newSources], $observations];
    }

    /** Explicit v3 retains useful curator research even when it earns no causal credit. */
    private function operatingCuratorKnowhowOverlay(array $parts, array $lessons, array $sources): array
    {
        $covered = []; $supplementRefs = [];
        foreach (self::CURATOR_PRACTICE_PARTS as $partKey => [$file, $sha, $count]) {
            $part = $this->operatingPinnedJson('research/instrument-operating-skills/'.$file, $sha);
            $this->operatingNeed(($part['protocol'] ?? null) === 'instrument_curator_practical_knowhow_v1'
                && ($part['part_key'] ?? null) === $partKey && ($part['revision'] ?? null) === 1
                && ($part['curriculum_key'] ?? null) === self::CURATOR_PRACTICE_CURRICULUM_KEY
                && is_array($part['sources'] ?? null) && array_is_list($part['sources'])
                && is_array($part['profiles'] ?? null) && array_is_list($part['profiles'])
                && count($part['profiles']) === $count, 'OPERATING_CURATOR_KNOWHOW_INVALID');
            foreach ($part['sources'] as $source) {
                $id = $partKey.':'.($source['source_id'] ?? '');
                $this->operatingNeed(! isset($sources[$id]) && ($source['source_id'] ?? '') !== ''
                    && preg_match('/^https:\/\//', $source['url'] ?? '') === 1
                    && is_string($source['reviewed_scope'] ?? null) && $source['reviewed_scope'] !== ''
                    && is_array($source['not_reviewed'] ?? null) && count($source['not_reviewed']) > 0,
                    'OPERATING_SOURCE_REVIEW_REQUIRED');
                $sources[$id] = $source;
                if (isset($source['source_path'])) $this->operatingPinnedJson($source['source_path'], $source['source_file_sha256'] ?? '');
            }
            $supplements = $part['supplemental_profiles'] ?? [];
            $this->operatingNeed(is_array($supplements) && array_is_list($supplements)
                && count($supplements) <= count(self::CURATOR_PRACTICE_METHOD_PATHS), 'OPERATING_CURATOR_KNOWHOW_INVALID');
            foreach ([...$part['profiles'], ...$supplements] as $profile) {
                $key = $profile['instrument_key'] ?? ''; $ref = $profile['method_ref'] ?? '';
                $supplement = isset(self::CURATOR_PRACTICE_METHOD_PATHS[$ref]);
                $this->operatingNeed(isset($lessons[$key]) && ($supplement ? ! isset($supplementRefs[$ref])
                    : (! isset($covered[$key]) && $ref === $lessons[$key]['primary_method_ref'])),
                    'OPERATING_CURATOR_METHOD_BINDING_REQUIRED');
                if ($supplement) {
                    $this->operatingNeed(($profile['method_path'] ?? null) === self::CURATOR_PRACTICE_METHOD_PATHS[$ref],
                        'OPERATING_CURATOR_METHOD_BINDING_REQUIRED');
                    $this->operatingPinnedJson($profile['method_path'], $profile['method_file_sha256'] ?? '');
                }
                $method = $this->operatingDeclaredMethod($ref);
                $target = $method['implementation']['target'] ?? 'construction';
                $this->operatingNeed(($method['instrument_key'] ?? null) === $key
                    && ($profile['target'] ?? null) === $target
                    && ($profile['context_predicate'] ?? null) === $method['context_predicate'],
                    'OPERATING_CURATOR_METHOD_BINDING_REQUIRED');
                foreach (['profile_key', 'source_vs_lab'] as $field)
                    $this->operatingNeed(is_string($profile[$field] ?? null) && $profile[$field] !== '', 'OPERATING_INSTRUCTION_REQUIRED');
                foreach (['operating_steps', 'inputs', 'wait_when', 'source_refs'] as $field) {
                    $this->operatingNeed(is_array($profile[$field] ?? null) && array_is_list($profile[$field])
                        && count($profile[$field]) > 0, 'OPERATING_INSTRUCTION_REQUIRED');
                    foreach ($profile[$field] as $value) $this->operatingNeed(is_string($value) && $value !== '', 'OPERATING_INSTRUCTION_REQUIRED');
                }
                // The pinned risk profiles retain source-qualified structured
                // alternatives, not executable instructions or replacement rules.
                $this->operatingNeed(is_array($profile['alternatives'] ?? null)
                    && array_is_list($profile['alternatives']) && count($profile['alternatives']) > 0,
                    'OPERATING_INSTRUCTION_REQUIRED');
                foreach ($profile['alternatives'] as $alternative) $this->operatingCuratorAlternative($alternative);
                $this->operatingNeed(count($profile['operating_steps']) >= 3
                    && is_array($profile['runtime_conditions'] ?? null) && count($profile['runtime_conditions']) > 0,
                    'OPERATING_CURATOR_RUNTIME_CONDITIONS_REQUIRED');
                if (isset($profile['runtime_conditions']['input_bindings']))
                    $this->operatingNeed($this->hashes->parameterHash($profile['runtime_conditions']['input_bindings'])
                        === $this->hashes->parameterHash($method['implementation']['input_bindings'] ?? []),
                        'OPERATING_CURATOR_METHOD_BINDING_REQUIRED');
                $reviewUrls = [];
                foreach ($profile['source_refs'] as &$sourceRef) {
                    $sourceRef = $partKey.':'.$sourceRef;
                    $this->operatingNeed(isset($sources[$sourceRef]), 'OPERATING_ORIGINAL_REVIEW_REQUIRED');
                    $reviewUrls[] = $sources[$sourceRef]['url'];
                }
                unset($sourceRef);
                $this->operatingNeed(is_array($profile['source_reviews'] ?? null) && array_is_list($profile['source_reviews'])
                    && count($profile['source_reviews']) > 0, 'OPERATING_SOURCE_REVIEW_REQUIRED');
                foreach ($profile['source_reviews'] as $review)
                    $this->operatingNeed(is_array($review) && in_array($review['url'] ?? null, $reviewUrls, true)
                        && is_string($review['reviewed_scope'] ?? null) && $review['reviewed_scope'] !== '', 'OPERATING_SOURCE_REVIEW_REQUIRED');
                $this->operatingNeed(is_array($profile['exercises'] ?? null) && array_is_list($profile['exercises'])
                    && count($profile['exercises']) >= 3 && count($profile['exercises']) <= 16,
                    'OPERATING_CORRECTNESS_EXERCISES_REQUIRED');
                foreach ($profile['exercises'] as $exercise)
                    $this->operatingNeed(($exercise['kind'] ?? null) === 'synthetic_operator_exercise'
                        && is_string($exercise['name'] ?? null) && is_array($exercise['inputs'] ?? null)
                        && (is_bool($exercise['expected'] ?? null) || is_int($exercise['expected'] ?? null) || is_float($exercise['expected'] ?? null)),
                        'OPERATING_CORRECTNESS_EXERCISES_REQUIRED');
                $profile['method_binding'] = ['method_ref' => $ref, 'instrument_key' => $key, 'target' => $target,
                    'context_predicate' => $method['context_predicate'], 'source_keys' => $method['source_keys'],
                    'declared_method_hash' => $this->hashes->parameterHash($method),
                    'implementation' => $method['implementation']];
                $profile['knowledge_state'] = 'curator_practical_research';
                $profile['instruction_authority'] = 'reviewed_bounded_instruction_no_online_execution';
                $profile['own_agent_outcome'] = false;
                $lesson = $lessons[$key]; unset($lesson['lesson_hash']);
                $lesson['curator_practical_profiles'][] = $profile;
                if ($supplement) $lesson['additional_method_refs'] = array_values(array_unique([...$lesson['additional_method_refs'], $ref]));
                $lesson['source_refs'] = array_values(array_unique([...$lesson['source_refs'], ...$profile['source_refs']]));
                $lesson['lesson_hash'] = $this->hashes->parameterHash($lesson); $lessons[$key] = $lesson;
                if ($supplement) $supplementRefs[$ref] = true; else $covered[$key] = true;
            }
            $parts[$partKey] = $sha;
        }
        $this->operatingNeed(count($covered) === count($lessons), 'OPERATING_INSTRUMENT_COVERAGE_DRIFT');
        $declaration = $this->operatingPinnedJson('research/instrument-operating-skills/curator-practical-declaration-v3.json',
            self::CURATOR_PRACTICE_DECLARATION_SHA256);
        $observations = $this->operatingPinnedJson('research/instrument-operating-skills/curator-practical-observations-v3.json',
            self::CURATOR_PRACTICE_OBSERVATIONS_SHA256, 524288);
        // The resource preserves the original JSON literals; only a terminal
        // newline was added by the source-file editor.
        $this->operatingNeed(hash('sha256', rtrim(file_get_contents(resource_path(
            'research/instrument-operating-skills/curator-practical-observations-v3.json')), "\r\n"))
            === self::CURATOR_PRACTICE_ORIGINAL_OBSERVATIONS_SHA256, 'OPERATING_CURATOR_ORIGINAL_OBSERVATIONS_DRIFT');
        $this->operatingNeed(($declaration['protocol'] ?? null) === 'curator_practical_declaration_v1'
            && ($observations['protocol'] ?? null) === 'curator_practical_observations_v1'
            && ($observations['attribution'] ?? null) === 'curator_observation'
            && ($observations['before_outcome_sha256'] ?? null) === self::CURATOR_PRACTICE_DECLARATION_SHA256
            && ($observations['archive_spec_sha256'] ?? null) === 'fa15e5411c3607bed4107aef7cef49f87180681270b83a9c9fddbd07ee739634'
            && ($observations['original_summary_sha256'] ?? null) === '1d2b7638e27ddcad4cb97fe3ad984743ac93eabe40dcbea5071a0bcfac90652e'
            && is_array($observations['results'] ?? null) && array_is_list($observations['results'])
            && count($observations['results']) > 0 && count($observations['results']) <= 128,
            'OPERATING_CURATOR_OBSERVATIONS_REQUIRED');
        foreach (['own_agent_outcome', 'independent_validation', 'skill_credit', 'qualification_granted',
            'agent_skill_earned', 'economic_authority', 'paper_authority', 'live_authority'] as $flag)
            $this->operatingNeed(! isset($observations[$flag]) || $observations[$flag] === false, 'OPERATING_CURATOR_AUTHORITY_FORBIDDEN');
        $this->operatingCuratorRoster($declaration, $observations['results']);
        foreach ($observations['results'] as &$observation) {
            $key = $observation['instrument_key'] ?? ''; $ref = $observation['method_ref'] ?? '';
            if (! isset($lessons[$key])) {
                $this->operatingNeed(($observation['kind'] ?? null) === 'control', 'OPERATING_CURATOR_METHOD_BINDING_REQUIRED');
                continue; // A labelled control stays in the panel, not a method outcome.
            }
            $this->operatingNeed(in_array($ref, [$lessons[$key]['primary_method_ref'], ...$lessons[$key]['additional_method_refs'],
                ...array_keys(self::CURATOR_PRACTICE_METHOD_PATHS)], true), 'OPERATING_CURATOR_METHOD_BINDING_REQUIRED');
            $method = $this->operatingDeclaredMethod($ref);
            $this->operatingNeed(($method['instrument_key'] ?? null) === $key, 'OPERATING_CURATOR_METHOD_BINDING_REQUIRED');
            foreach (['own_agent_outcome', 'independent_validation', 'skill_credit', 'qualification_granted',
                'agent_skill_earned', 'economic_authority', 'paper_authority', 'live_authority'] as $flag)
                $this->operatingNeed(! isset($observation[$flag]) || $observation[$flag] === false, 'OPERATING_CURATOR_AUTHORITY_FORBIDDEN');
            $observation['derived_evidence'] = $this->operatingCuratorEvidence($observation, $method);
            $lesson = $lessons[$key]; unset($lesson['lesson_hash']);
            $lesson['curator_observations'][] = $observation;
            $lesson['lesson_hash'] = $this->hashes->parameterHash($lesson); $lessons[$key] = $lesson;
        }
        unset($observation);
        $completion = $this->operatingCuratorTechnicalCompletion($observations);
        foreach ($completion['results'] as $row) {
            $key = $row['instrument_key']; $lesson = $lessons[$key]; unset($lesson['lesson_hash']);
            $lesson['curator_technical_completions'][] = $row;
            $lesson['lesson_hash'] = $this->hashes->parameterHash($lesson); $lessons[$key] = $lesson;
        }
        $diagnostics = $this->operatingCuratorDependencyDiagnostics();
        foreach ($diagnostics['studies'] as $study) foreach ($study['observations']['results'] as $row) {
            if ($row['kind'] !== 'method') continue;
            $key = $row['instrument_key']; $lesson = $lessons[$key]; unset($lesson['lesson_hash']);
            $lesson['curator_dependency_diagnostics'][] = [...$row, 'curator_notes' => $study['curator_notes']];
            $lesson['lesson_hash'] = $this->hashes->parameterHash($lesson); $lessons[$key] = $lesson;
        }
        $parts['curator_practical_dependency_diagnostics_v3'] = self::CURATOR_PRACTICE_DIAGNOSTICS_SHA256;
        $parts['curator_practical_declaration_v3'] = self::CURATOR_PRACTICE_DECLARATION_SHA256;
        $parts['curator_practical_observations_v3'] = self::CURATOR_PRACTICE_OBSERVATIONS_SHA256;
        $parts['curator_practical_technical_completion_v3'] = self::CURATOR_PRACTICE_COMPLETION_SHA256;
        $knowhow = ['protocol' => 'curator_practical_knowhow_retention_v1',
            'knowledge_state' => 'curator_practical_research', 'before_outcome_declaration' => $declaration,
            'curator_observations' => $observations,
            'original_observations_sha256' => self::CURATOR_PRACTICE_ORIGINAL_OBSERVATIONS_SHA256,
            'technical_completion' => $completion,
            'dependency_diagnostics' => $diagnostics,
            'retention' => 'positive_null_negative_unactivated_and_unavailable_remain_bounded_instruction',
            'qualification_state' => 'unqualified_curator_research',
            'internet_skill' => 'requires_its_separate_existing_verification_and_qualification',
            'agent_skill' => 'separate_independently_earned_agent_owned_original_outcomes',
            'own_agent_outcome' => false, 'independent_validation' => false, 'skill_credit' => false,
            ...$this->operatingCeiling()];
        return [$parts, $lessons, $sources, $knowhow];
    }

    /** V3 alternative documents never install or execute the described method. */
    private function operatingCuratorAlternative(mixed $alternative): void
    {
        if (is_string($alternative)) {
            $this->operatingNeed($alternative !== '', 'OPERATING_INSTRUCTION_REQUIRED');
            return;
        }
        $this->operatingNeed(is_array($alternative) && ! array_is_list($alternative)
            && array_diff(array_keys($alternative), ['use', 'status', 'difference', 'method_ref', 'rule_math', 'requirements']) === [],
            'OPERATING_INSTRUCTION_REQUIRED');
        foreach (['use', 'status', 'difference'] as $field)
            $this->operatingNeed(is_string($alternative[$field] ?? null) && $alternative[$field] !== '', 'OPERATING_INSTRUCTION_REQUIRED');
        $this->operatingNeed(in_array($alternative['status'], [
            'data_conditional_research', 'existing_construction_only_method', 'existing_distinct_method', 'existing_distinct_revision',
            'external_risk_guard_not_replaceable', 'research_gap_no_installed_depth_algorithm', 'research_gap_no_installed_hysteresis_method',
            'research_gap_no_installed_survival_asset', 'research_gap_no_installed_volatility_target_asset', 'research_gap_no_new_model',
            'research_gap_no_new_specialist_router', 'research_gap_no_observed_execution_model', 'research_gap_no_trained_recovery_model',
            'research_gap_not_installed_as_primary', 'research_gap_not_installed_as_this_method', 'research_gap_requires_new_immutable_scope',
            'research_protocol_not_independent_confirmation', 'research_protocol_not_installed_primary', 'separate_existing_native_heuristic',
            'separate_existing_native_policy', 'separate_existing_native_volume_lane', 'source_supported_analysis_not_new_primary',
            'sourced_manual_example_not_bar_algorithm', 'sourced_manual_example_not_signal_probability', 'stop_first_sizing_arithmetic',
        ], true), 'OPERATING_INSTRUCTION_REQUIRED');
        foreach (['method_ref', 'rule_math'] as $field)
            if (array_key_exists($field, $alternative)) $this->operatingNeed(is_string($alternative[$field])
                && $alternative[$field] !== '', 'OPERATING_INSTRUCTION_REQUIRED');
        if (array_key_exists('requirements', $alternative)) {
            $this->operatingNeed(is_array($alternative['requirements']) && array_is_list($alternative['requirements'])
                && count($alternative['requirements']) > 0, 'OPERATING_INSTRUCTION_REQUIRED');
            foreach ($alternative['requirements'] as $requirement)
                $this->operatingNeed(is_string($requirement) && $requirement !== '', 'OPERATING_INSTRUCTION_REQUIRED');
        }
    }

    /** Two approved completions stay separate from their immutable technical originals. */
    private function operatingCuratorTechnicalCompletion(array $originals): array
    {
        $completion = $this->operatingPinnedJson('research/instrument-operating-skills/curator-practical-technical-completion-v3.json',
            self::CURATOR_PRACTICE_COMPLETION_SHA256);
        $this->operatingNeed(($completion['protocol'] ?? null) === 'curator_practical_technical_completion_observations_v1'
            && ($completion['attribution'] ?? null) === 'curator_observation'
            && ($completion['before_outcome_sha256'] ?? null) === self::CURATOR_PRACTICE_COMPLETION_DECLARATION_SHA256
            && ($completion['original_before_outcome_sha256'] ?? null) === self::CURATOR_PRACTICE_DECLARATION_SHA256
            && ($completion['original_spec_sha256'] ?? null) === $originals['archive_spec_sha256']
            && ($completion['completion_summary_sha256'] ?? null) === 'ecd2af184ffd58c25c162e37e2f9ffe978a47a7df6a7c7eb431978e4e1cf98b9'
            && ($completion['original_rows_overwritten'] ?? null) === false
            && is_array($completion['results'] ?? null) && array_is_list($completion['results']) && count($completion['results']) === 2,
            'OPERATING_CURATOR_COMPLETION_REQUIRED');
        foreach (['independent_validation', 'skill_credit', 'qualification_granted', 'economic_authority', 'paper_authority', 'live_authority'] as $flag)
            $this->operatingNeed(($completion[$flag] ?? null) === false, 'OPERATING_CURATOR_AUTHORITY_FORBIDDEN');
        $failures = ['october_seen__atr_risk_envelope' => '888a3f722ee7f92bdadce208de2f62708f06ae33aa5a79d4925bd6656122a193',
            'december_seen__atr_risk_envelope' => 'e5b3ed3292f5cd8522bc2a274ccd779b8178d43a00533ebedcb27da7cebb8048'];
        $byKey = array_column($originals['results'], null, 'case_key'); $seen = [];
        foreach ($completion['results'] as &$row) {
            $key = $row['original_case_key'] ?? ''; $original = $byKey[$key] ?? [];
            $this->operatingNeed(isset($failures[$key]) && ! isset($seen[$key])
                && ($original['status'] ?? null) === 'technical_terminal'
                && ($row['case_key'] ?? null) === $key.'__technical_completion_1'
                && ($row['status'] ?? null) === 'completed' && ($row['timeout'] ?? null) === false
                && ($row['original_failure_process_sha256'] ?? null) === $failures[$key]
                && ($row['completion_before_outcome_sha256'] ?? null) === self::CURATOR_PRACTICE_COMPLETION_DECLARATION_SHA256
                && ($row['original_spec_sha256'] ?? null) === $originals['archive_spec_sha256']
                && ($row['original_request_sha256'] ?? null) === ($original['request_sha256'] ?? null)
                && ($row['request_sha256'] ?? null) === ($original['request_sha256'] ?? null), 'OPERATING_CURATOR_COMPLETION_BINDING_REQUIRED');
            foreach (['instrument_key', 'method_ref', 'target', 'source_keys', 'dataset_sha256', 'cost_sha256', 'window'] as $field)
                $this->operatingNeed(($row[$field] ?? null) === ($original[$field] ?? null), 'OPERATING_CURATOR_COMPLETION_BINDING_REQUIRED');
            $control = $row['control'] ?? []; $controlKey = ($row['window'] ?? '').'__control';
            $this->operatingNeed(($control['case_key'] ?? null) === $controlKey && isset($byKey[$controlKey]), 'OPERATING_CURATOR_COMPLETION_BINDING_REQUIRED');
            foreach (['request_sha256', 'response_sha256', 'dataset_sha256', 'cost_sha256', 'net_cash'] as $field)
                $this->operatingNeed(($control[$field] ?? null) === ($byKey[$controlKey][$field] ?? null), 'OPERATING_CURATOR_COMPLETION_BINDING_REQUIRED');
            $row['derived_evidence'] = [...$this->operatingCuratorEvidence($row, $this->operatingDeclaredMethod($row['method_ref'])),
                'evidence_origin' => 'technical_completion_same_original_request', 'additional_independent_windows' => 0,
                'eligible_as_additional_advice_vote' => false];
            $seen[$key] = true;
        }
        unset($row);
        return [...$completion, 'raw_observations_sha256' => '7435766a7937acc753b1ba3fd3ea703adf536e9e6387a702e9fdd038ca02a9a6',
            'own_agent_outcome' => false, 'additional_independent_windows' => 0, 'eligible_as_additional_advice_vote' => false];
    }

    /** Separate dependency studies teach readiness, never add advice votes or windows. */
    private function operatingCuratorDependencyDiagnostics(): array
    {
        $diagnostics = $this->operatingPinnedJson('research/instrument-operating-skills/curator-practical-dependency-diagnostics-v3.json',
            self::CURATOR_PRACTICE_DIAGNOSTICS_SHA256);
        $this->operatingNeed(($diagnostics['protocol'] ?? null) === 'curator_practical_dependency_diagnostics_v1'
            && ($diagnostics['attribution'] ?? null) === 'curator_observation'
            && ($diagnostics['original_rows_overwritten'] ?? null) === false
            && ($diagnostics['additional_independent_windows'] ?? null) === 0
            && ($diagnostics['eligible_as_additional_advice_vote'] ?? null) === false,
            'OPERATING_CURATOR_DIAGNOSTICS_REQUIRED');
        foreach (['own_agent_outcome', 'independent_validation', 'skill_credit', 'qualification_granted',
            'agent_skill_earned', 'economic_authority', 'paper_authority', 'live_authority'] as $flag)
            $this->operatingNeed(($diagnostics[$flag] ?? null) === false, 'OPERATING_CURATOR_AUTHORITY_FORBIDDEN');
        $plans = [
            'native_score' => ['spec' => 'e3083d7fb76e3adf69f7fb086e51d898649df3ca00f970306cbe2f6e940a6dbe',
                'archive' => '824f5e1deebc3a55673da1cf09074bd30a737e53e879995e58264d14067c825c',
                'summary' => '195c55106041f41406e5b67dd63873f4638ceff098343ce4f65ac5434193ca43',
                'raw' => '8a0a584c4bd345d80ffeea66a3bdcc1a8d3b887303f041afa6a5e0ac7f5487c0',
                'protocol' => 'native_score_practice_observations_v1', 'before_field' => 'before_outcome_spec_sha256',
                'summary_field' => 'original_summary_sha256', 'method' => 'internet_signal_score_veto_0_7_v1@1',
                'keys' => ['october_seen__scoreless_ema__control', 'october_seen__scoreless_ema__confidence_primary',
                    'october_seen__scored_trend__control', 'october_seen__scored_trend__confidence_primary']],
            'observed_quote' => ['spec' => 'f2c689a6c5cf0d4039be40ddffa37b51b9e6b17e195686a0a621d150b42bce79',
                'archive' => 'bb47789671c6292c7bfc727abb576116a40732fa043f71d761f65dd4ed1dcd4b',
                'summary' => '8aca6f5225ff2acd566e436b1b92307fb33a988a64d3524df4f4ba4eaf2789c8', 'raw' => '38f612c4745177b07a34beff1e8b899df50af4e5e2a8905fee36bd639804b124',
                'protocol' => 'curator_quote_practice_observations_v1', 'before_field' => 'before_outcome_sha256',
                'summary_field' => 'summary_sha256', 'method' => 'internet_observed_quote_cost_firewall@1',
                'keys' => ['october_seen__quote_control', 'october_seen__quote_treatment',
                    'december_seen__quote_control', 'december_seen__quote_treatment']],
        ];
        $actual = array_keys($diagnostics['studies'] ?? []); $expected = array_keys($plans); sort($actual); sort($expected);
        $this->operatingNeed($actual === $expected, 'OPERATING_CURATOR_DIAGNOSTICS_REQUIRED');
        foreach ($plans as $key => $plan)
            $diagnostics['studies'][$key] = $this->operatingCuratorDiagnosticStudy($diagnostics['studies'][$key], $plan, $key);
        return $diagnostics;
    }

    private function operatingCuratorDiagnosticStudy(array $study, array $plan, string $studyKey): array
    {
        $this->operatingNeed(($study['before_outcome_spec_sha256'] ?? null) === $plan['spec']
            && ($study['source_archive_sha256'] ?? null) === $plan['archive']
            && ($study['summary_sha256'] ?? null) === $plan['summary']
            && ($study['raw_observations_sha256'] ?? null) === $plan['raw'], 'OPERATING_CURATOR_DIAGNOSTIC_BINDING_REQUIRED');
        $observations = $study['observations'] ?? [];
        $this->operatingNeed(($observations['protocol'] ?? null) === $plan['protocol']
            && ($observations['attribution'] ?? null) === 'curator_observation'
            && ($observations[$plan['before_field']] ?? null) === $plan['spec']
            && ($observations[$plan['summary_field']] ?? null) === $plan['summary']
            && is_array($observations['results'] ?? null) && array_is_list($observations['results'])
            && count($observations['results']) === 4, 'OPERATING_CURATOR_DIAGNOSTICS_REQUIRED');
        foreach (['own_agent_outcome', 'independent_validation', 'skill_credit', 'qualification_granted',
            'agent_skill_earned', 'economic_authority', 'paper_authority', 'live_authority', 'credit', 'mastery'] as $flag)
            $this->operatingNeed(! isset($observations[$flag]) || $observations[$flag] === false, 'OPERATING_CURATOR_AUTHORITY_FORBIDDEN');
        if ($studyKey === 'observed_quote') $this->operatingNeed(($observations['quote_source_manifest_sha256'] ?? null)
            === 'f71f7563d1418c8de961981c9b9fa7fb1b160218b488eadf70ac52a7abc4fa18', 'OPERATING_CURATOR_DIAGNOSTIC_BINDING_REQUIRED');
        $this->operatingCuratorRoster($study, $observations['results']);
        $keys = array_keys($study['case_roster']); $expected = $plan['keys']; sort($keys); sort($expected);
        $this->operatingNeed($keys === $expected, 'OPERATING_CURATOR_CASE_ROSTER_DRIFT');
        $byKey = array_column($observations['results'], null, 'case_key');
        $method = $this->operatingDeclaredMethod($plan['method']); $methodRows = 0; $controls = 0;
        foreach ($observations['results'] as &$row) {
            $binding = $study['case_roster'][$row['case_key']];
            foreach (['request_sha256', 'dataset_sha256', 'cost_sha256', 'window'] as $field)
                $this->operatingNeed(($row[$field] ?? null) === ($binding[$field] ?? null), 'OPERATING_CURATOR_DIAGNOSTIC_BINDING_REQUIRED');
            $this->operatingNeed(($row['cost_sha256'] ?? null) === '387e25433c5b8405baa1287864b4f8b89f2d010ef147c58ef11e99073e4f6ce6',
                'OPERATING_CURATOR_DIAGNOSTIC_BINDING_REQUIRED');
            foreach (['own_agent_outcome', 'independent_validation', 'skill_credit', 'qualification_granted',
                'agent_skill_earned', 'economic_authority', 'paper_authority', 'live_authority'] as $flag)
                $this->operatingNeed(! isset($row[$flag]) || $row[$flag] === false, 'OPERATING_CURATOR_AUTHORITY_FORBIDDEN');
            if ($row['kind'] === 'control') { $controls++; continue; }
            $this->operatingNeed(($row['method_ref'] ?? null) === $plan['method']
                && ($row['instrument_key'] ?? null) === $method['instrument_key']
                && ($row['target'] ?? null) === ($method['implementation']['target'] ?? 'construction'),
                'OPERATING_CURATOR_DIAGNOSTIC_BINDING_REQUIRED');
            $actualSources = $row['source_keys']; $expectedSources = $method['source_keys']; sort($actualSources); sort($expectedSources);
            $this->operatingNeed($actualSources === $expectedSources, 'OPERATING_CURATOR_DIAGNOSTIC_BINDING_REQUIRED');
            $control = $byKey[$binding['baseline_key'] ?? ''] ?? null;
            $this->operatingNeed(is_array($control) && $control['kind'] === 'control', 'OPERATING_CURATOR_DIAGNOSTIC_BINDING_REQUIRED');
            foreach (['status', 'request_sha256', 'response_sha256', 'dataset_sha256', 'cost_sha256', 'net_cash', 'ledger_refs'] as $field)
                $this->operatingNeed(($row['control'][$field] ?? null) === ($control[$field] ?? null), 'OPERATING_CURATOR_DIAGNOSTIC_BINDING_REQUIRED');
            $row['diagnostic_study'] = $studyKey;
            $row['derived_evidence'] = [...$this->operatingCuratorEvidence($row, $method),
                'evidence_origin' => 'dependency_diagnostic_reused_seen_archive', 'additional_independent_windows' => 0,
                'eligible_as_additional_advice_vote' => false];
            $methodRows++;
        }
        unset($row);
        $this->operatingNeed($methodRows === 2 && $controls === 2, 'OPERATING_CURATOR_DIAGNOSTICS_REQUIRED');
        return [...$study, 'observations' => $observations, 'own_agent_outcome' => false,
            'additional_independent_windows' => 0, 'eligible_as_additional_advice_vote' => false];
    }

    /** Each teaching pack carries only its selected methods' observations. */
    private function operatingCuratorProjection(array $knowhow, array $lessons, array $methods): array
    {
        $refs = array_values(array_unique(array_map(fn (array $method): string =>
            $method['method_key'].'@'.$method['revision'], $methods))); sort($refs);
        $selected = fn (array $row): bool => in_array($row['method_ref'] ?? null, $refs, true);
        $knowhow['curator_observations']['results'] = array_values(array_filter($knowhow['curator_observations']['results'], $selected));
        if (isset($knowhow['technical_completion'])) {
            $knowhow['technical_completion']['results'] = array_values(array_filter($knowhow['technical_completion']['results'], $selected));
            if ($knowhow['technical_completion']['results'] === []) unset($knowhow['technical_completion']);
        }
        if (isset($knowhow['dependency_diagnostics'])) {
            foreach ($knowhow['dependency_diagnostics']['studies'] as $key => &$study) {
                $matches = array_values(array_filter($study['observations']['results'], fn (array $row): bool =>
                    ($row['kind'] ?? null) === 'method' && $selected($row)));
                if ($matches === []) { unset($knowhow['dependency_diagnostics']['studies'][$key]); continue; }
                $controls = array_map(fn (array $row): string => $study['case_roster'][$row['case_key']]['baseline_key'], $matches);
                $study['observations']['results'] = array_values(array_filter($study['observations']['results'],
                    fn (array $row): bool => $selected($row) || in_array($row['case_key'], $controls, true)));
            }
            unset($study);
            if ($knowhow['dependency_diagnostics']['studies'] === []) unset($knowhow['dependency_diagnostics']);
        }
        $knowhow['observation_projection'] = ['scope' => 'exact_selected_method_refs',
            'method_refs' => $refs, 'full_observation_part_sha256' => self::CURATOR_PRACTICE_OBSERVATIONS_SHA256];
        foreach ($lessons as &$lesson) if (isset($lesson['curator_observations']))
            $lesson['curator_observations'] = array_values(array_filter($lesson['curator_observations'], $selected));
        unset($lesson);
        foreach ($lessons as &$lesson) if (isset($lesson['curator_technical_completions']))
            $lesson['curator_technical_completions'] = array_values(array_filter($lesson['curator_technical_completions'], $selected));
        unset($lesson);
        foreach ($lessons as &$lesson) if (isset($lesson['curator_dependency_diagnostics']))
            $lesson['curator_dependency_diagnostics'] = array_values(array_filter($lesson['curator_dependency_diagnostics'], $selected));
        unset($lesson);
        return [$knowhow, $lessons];
    }

    /** Every planned case remains identifiable, including technical and control rows. */
    private function operatingCuratorRoster(array $declaration, array $rows): void
    {
        $roster = $declaration['case_roster'] ?? null;
        $this->operatingNeed(is_array($roster) && ! array_is_list($roster) && count($roster) > 0
            && count($roster) <= 128 && array_is_list($rows), 'OPERATING_CURATOR_CASE_ROSTER_REQUIRED');
        $seen = [];
        foreach ($rows as $row) {
            $key = $row['case_key'] ?? null;
            $this->operatingNeed(is_string($key) && isset($roster[$key]) && ! isset($seen[$key]),
                'OPERATING_CURATOR_CASE_ROSTER_DRIFT');
            foreach (['instrument_key', 'method_ref', 'target'] as $field)
                $this->operatingNeed(array_key_exists($field, $row) && array_key_exists($field, $roster[$key])
                    && $row[$field] === $roster[$key][$field], 'OPERATING_CURATOR_CASE_ROSTER_DRIFT');
            $sources = $row['source_keys'] ?? null; $expected = $roster[$key]['source_keys'] ?? null;
            $this->operatingNeed(is_array($sources) && array_is_list($sources)
                && is_array($expected) && array_is_list($expected), 'OPERATING_CURATOR_CASE_ROSTER_DRIFT');
            sort($sources); sort($expected);
            $this->operatingNeed($sources === $expected && ($row['kind'] ?? 'method') === ($roster[$key]['kind'] ?? 'method'),
                'OPERATING_CURATOR_CASE_ROSTER_DRIFT');
            $seen[$key] = true;
        }
        $actual = array_keys($seen); $planned = array_keys($roster); sort($actual); sort($planned);
        $this->operatingNeed($actual === $planned, 'OPERATING_CURATOR_CASE_ROSTER_DRIFT');
    }

    /** Pinned local comparisons are instruction; caller labels never award a grade. */
    private function operatingCuratorEvidence(array $row, array $method): array
    {
        $grade = 'unresolved_provenance'; $reason = 'matched_original_method_source_cost_and_ledger_references_required'; $delta = null;
        $sha = fn ($value): bool => is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
        $control = $row['control'] ?? []; $reach = $row['method_reach'] ?? [];
        $sourceKeys = $row['source_keys'] ?? []; $expectedSources = $method['source_keys'];
        if (is_array($sourceKeys)) sort($sourceKeys); sort($expectedSources);
        $bound = ($row['method_ref'] ?? null) === $method['method_key'].'@'.$method['revision']
            && ($row['instrument_key'] ?? null) === $method['instrument_key']
            && ($row['target'] ?? null) === ($method['implementation']['target'] ?? 'construction')
            && $sourceKeys === $expectedSources;
        $original = $bound;
        foreach (['request_sha256', 'response_sha256', 'dataset_sha256', 'cost_sha256'] as $field)
            $original = $original && $sha($row[$field] ?? null) && $sha($control[$field] ?? null);
        $matched = $original && $row['dataset_sha256'] === $control['dataset_sha256']
            && $row['cost_sha256'] === $control['cost_sha256'];
        $counts = true;
        foreach (['reached', 'available', 'activated', 'changed_decisions'] as $field)
            $counts = $counts && is_int($reach[$field] ?? null) && $reach[$field] >= 0;
        $counts = $counts && $reach['available'] <= $reach['reached']
            && $reach['activated'] <= $reach['available'] && $reach['changed_decisions'] <= $reach['reached'];
        if (($row['status'] ?? null) !== 'completed' || ($row['timeout'] ?? null) !== false) {
            $grade = 'technical_or_incomplete'; $reason = 'incomplete_execution_is_not_a_strategy_loss';
        } elseif ($original && $counts && $reach['reached'] === 0) {
            $grade = 'unactivated'; $reason = 'method_not_reached_is_not_tested_negative';
        } elseif ($original && $counts && $reach['available'] === 0) {
            $grade = 'unavailable'; $reason = 'missing_observations_are_not_a_bad_strategy';
        } elseif ($original && $counts && $reach['available'] > 0 && $reach['available'] < $reach['reached']) {
            $grade = 'partially_unavailable'; $reason = 'availability_wait_effects_cannot_be_ranked_as_complete_rule_utility';
        } elseif ($matched && $counts) {
            $references = is_array($row['trade_refs'] ?? null);
            foreach (['cost_refs', 'ledger_refs'] as $field)
                $references = $references && is_array($row[$field] ?? null) && count($row[$field]) > 0;
            if ($reach['activated'] === 0 || $reach['changed_decisions'] === 0) {
                $grade = 'no_behavior_change'; $reason = 'available_rule_without_changed_decisions_has_no_measured_incremental_utility';
            } elseif ($references && is_numeric($row['net_cash'] ?? null) && is_numeric($control['net_cash'] ?? null)
                && is_finite((float) $row['net_cash']) && is_finite((float) $control['net_cash'])) {
                $delta = (float) $row['net_cash'] - (float) $control['net_cash'];
                $grade = abs($delta) <= 0.00000001 ? 'local_null' : ($delta > 0 ? 'local_provisional_positive' : 'local_negative');
                $reason = 'matched_seen_archive_net_cash_difference_not_independent_confirmation';
            }
        }
        return ['grade' => $grade, 'reason' => $reason, 'attribution' => 'curator_observation',
            'net_cash_delta' => $delta,
            'usable_as_bounded_research_instruction' => true, 'own_agent_outcome' => false,
            'independent_validation' => false, 'skill_credit' => false, ...$this->operatingCeiling()];
    }

    private function operatingPinnedJson(string $relativePath, string $sha, int $maxBytes = 262144): array
    {
        $this->operatingNeed(preg_match('/^research\/[a-z0-9_\/-]+\.json$/D', $relativePath) === 1
            && ! str_contains($relativePath, '..') && preg_match('/^[a-f0-9]{64}$/D', $sha) === 1,
            'OPERATING_PRACTICE_RESOURCE_REQUIRED');
        $path = resource_path($relativePath);
        $this->operatingNeed(is_file($path) && ! is_link($path) && filesize($path) <= $maxBytes
            && hash_file('sha256', $path) === $sha, 'OPERATING_CURRICULUM_BYTE_DRIFT');
        return json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
    }

    private function operatingCeiling(): array
    {
        return ['research_only' => true, 'agent_skill_earned' => false, 'economic_authority' => false,
            'confirmation_proven' => false, 'qualification_granted' => false, 'paper_authority' => false, 'live_authority' => false];
    }

    private function operatingNeed(bool $condition, string $reason): void { if (! $condition) throw new RuntimeException($reason); }
    private function operatingBlocked(Throwable $error): array
    {
        $reason = preg_match('/^OPERATING_[A-Z0-9_]+$/D', $error->getMessage()) ? $error->getMessage() : 'OPERATING_CURRICULUM_INVALID';
        return ['status' => 'blocked', 'reason' => $reason, ...$this->operatingCeiling()];
    }
}
