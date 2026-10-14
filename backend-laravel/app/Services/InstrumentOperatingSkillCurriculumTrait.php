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
            $parts = []; $lessons = []; $sources = [];
            // Future versions add an entry, never overwrite these original parts.
            $versions = [self::OPERATING_CURRICULUM_KEY => self::OPERATING_PARTS];
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
                ...$this->operatingCeiling()];
            return [...$body, 'status' => 'operating_skill_curriculum', 'curriculum_hash' => $this->hashes->parameterHash($body)];
        } catch (Throwable $error) { return $this->operatingBlocked($error); }
    }

    /** Teach only actual pinned recipes; the knowledge corpus never chooses an agent's action. */
    public function teachingPack(array $recipeHashes, string $curriculumKey = self::OPERATING_CURRICULUM_KEY): array
    {
        try {
            $this->operatingNeed(array_is_list($recipeHashes) && count($recipeHashes) > 0 && count($recipeHashes) <= 32
                && count(array_unique($recipeHashes, SORT_REGULAR)) === count($recipeHashes), 'OPERATING_EXACT_RECIPE_ROSTER_REQUIRED');
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
            $body = ['protocol' => self::TEACHING_PACK_PROTOCOL, 'curriculum_key' => $curriculumKey, 'manifest_parts' => $curriculum['manifest_parts'],
                'curriculum_hash' => $curriculum['curriculum_hash'], 'instrument_inventory_hash' => $curriculum['instrument_inventory_hash'],
                'recipe_hashes' => $recipeHashes, 'lessons' => $lessons, 'sources' => $sources,
                'methods' => $methods, 'recipes' => $recipes,
                'acceptance' => 'rules_supplied_not_agent_mastery', 'exercise_evidence' => 'synthetic_operator_examples_not_market_outcomes',
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
            'calibrated-meta-score', 'closed-macd-cross', 'closed-rsi-recovery', 'closed-chandelier-exit', 'native-single-partial-runner'] as $folder) {
            $data = json_decode(file_get_contents(resource_path('research/instrument-methods/'.$folder.'/method.json')), true, 64, JSON_THROW_ON_ERROR);
            if ($data['method_key'].'@'.$data['revision'] === $ref) return $data;
        }
        throw new RuntimeException('OPERATING_DECLARED_METHOD_MISSING');
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
