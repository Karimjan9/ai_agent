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
            $parts = []; $lessons = []; $sources = []; $curatorObservations = null;
            // Future versions add an entry, never overwrite these original parts.
            $versions = [self::OPERATING_CURRICULUM_KEY => self::OPERATING_PARTS,
                self::SOURCE_PRACTICE_CURRICULUM_KEY => self::OPERATING_PARTS];
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
            if ($curriculumKey === self::SOURCE_PRACTICE_CURRICULUM_KEY) {
                [$parts, $lessons, $sources, $curatorObservations] = $this->operatingPracticeOverlay($parts, $lessons, $sources);
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
            if ($curriculumKey === self::SOURCE_PRACTICE_CURRICULUM_KEY) {
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
                ...($curriculumKey === self::SOURCE_PRACTICE_CURRICULUM_KEY ? ['instruction_transport' => 'eloquent_metadata_json_numbers_v1'] : []),
                ...(isset($curriculum['curator_archive_observations']) ? ['curator_archive_observations' => $curriculum['curator_archive_observations']] : []),
                ...$this->operatingCeiling()];
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

    private function operatingDeclaredMethod(string $ref): array
    {
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

    private function operatingPinnedJson(string $relativePath, string $sha): array
    {
        $this->operatingNeed(preg_match('/^research\/[a-z0-9_\/-]+\.json$/D', $relativePath) === 1
            && ! str_contains($relativePath, '..') && preg_match('/^[a-f0-9]{64}$/D', $sha) === 1,
            'OPERATING_PRACTICE_RESOURCE_REQUIRED');
        $path = resource_path($relativePath);
        $this->operatingNeed(is_file($path) && ! is_link($path) && filesize($path) <= 262144
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
