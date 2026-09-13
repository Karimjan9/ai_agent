<?php

namespace App\Services;

use App\Models\AgentLearningLesson;
use App\Models\AgentLearningSettlement;
use App\Models\LabLearningLanePair;
use InvalidArgumentException;

/**
 * One fail-closed source-of-truth for admitting a historical lesson into a
 * fresh causal reproduction. Ranking may change; these proof requirements may
 * not be weakened or reimplemented by a caller.
 */
class CausalLessonAdmissionService
{
    public const PROTOCOL = 'causal_lesson_admission_v1';

    /** @return array<string,mixed> */
    public function assess(AgentLearningLesson $lesson): array
    {
        $gene = (string) $lesson->parameter_key;
        $old = $this->oldValue($lesson);
        $new = $this->newValue($lesson);
        $pairId = (int) data_get($lesson->evidence, 'pair_id', 0);
        $pair = $pairId > 0 ? LabLearningLanePair::query()->with([
            'controlResponseMap', 'candidateAgent.modelVersion', 'controlAgent.modelVersion',
        ])->find($pairId) : null;
        $schema = app(StrategyParameterSchemaService::class);
        $geneExecutable = $gene !== ''
            && array_key_exists($gene, $schema->schema((string) $lesson->strategy_family));
        $boundedValue = false;
        if ($geneExecutable && $new !== null) {
            try {
                $schema->validate((string) $lesson->strategy_family, [$gene => $new]);
                $boundedValue = true;
            } catch (InvalidArgumentException) {
                $boundedValue = false;
            }
        }

        $candidateDiff = (array) $pair?->candidateAgent?->parameter_diff;
        $change = (array) data_get($candidateDiff, $gene, []);
        $checks = [
            'pair_exists' => $pair !== null,
            'verified_control_pair' => $pair?->isVerifiedControlPair() === true,
            'gene_executable' => $geneExecutable,
            'single_gene_candidate_diff' => $pair !== null && count($candidateDiff) === 1 && $change !== [],
            'lesson_matches_candidate_diff' => $change !== []
                && $this->same($old, data_get($change, 'old'))
                && $this->same($new, data_get($change, 'new')),
            'control_model_matches_old' => $pair !== null
                && $this->same($old, data_get($pair->controlAgent?->modelVersion?->parameters, $gene)),
            'candidate_model_matches_new' => $pair !== null
                && $this->same($new, data_get($pair->candidateAgent?->modelVersion?->parameters, $gene)),
            'canonical_positive_settlement' => $pair !== null && AgentLearningSettlement::query()
                ->where('source_type', LabLearningLanePair::class)
                ->where('source_id', $pairId)
                ->where('evidence_state', 'positive')
                ->where('hard_failure', false)
                ->exists(),
            'bounded_value_present' => $boundedValue,
            'executable_cartridge' => $geneExecutable
                && (string) data_get(
                    app(CanonicalSkillCartridgeService::class)->retrieveForLesson($lesson),
                    'status',
                    'missing',
                ) === 'compatible_cartridge_found',
        ];
        $canonicalChecks = [
            'pair_exists', 'verified_control_pair', 'single_gene_candidate_diff',
            'lesson_matches_candidate_diff', 'control_model_matches_old',
            'candidate_model_matches_new', 'canonical_positive_settlement',
        ];
        $canonicalPositive = collect($canonicalChecks)->every(
            fn (string $check): bool => $checks[$check],
        );
        $canonicalSourceReady = ! in_array(false, $checks, true);
        // Historical controls that predate frozen_control_v2 may suggest a
        // bounded treatment but can never serve as evidence for it. They may
        // buy one fresh post-v2 triplet only when every reconstructability
        // check is exact; the new triplet starts with zero causal credit.
        $reproductionChecks = array_keys($checks);
        $reproductionChecks = array_values(array_diff($reproductionChecks, ['verified_control_pair']));
        $researchReproductionReady = collect($reproductionChecks)->every(
            fn (string $check): bool => $checks[$check],
        );
        $reproductionCheckResults = collect($checks)
            ->only($reproductionChecks)
            ->all();

        return [
            'protocol' => self::PROTOCOL,
            'lesson_id' => (int) $lesson->id,
            'pair_id' => $pairId ?: null,
            'gene_key' => $gene,
            'old_value' => $old,
            'new_value' => $new,
            'canonical_positive' => $canonicalPositive,
            'canonical_source_ready' => $canonicalSourceReady,
            'research_reproduction_ready' => $researchReproductionReady,
            // Compatibility alias: admission means permission to construct a
            // research reproduction, never authority to trust the old row.
            'source_ready' => $researchReproductionReady,
            'source_authority' => $canonicalSourceReady
                ? 'canonical_causal_source'
                : ($researchReproductionReady ? 'legacy_hypothesis_only' : 'quarantined'),
            'checks' => $checks,
            'reproduction_checks' => $reproductionCheckResults,
            'authority_checks' => $checks,
            'blockers' => array_values(array_filter(
                $reproductionChecks,
                fn (string $check): bool => ! $checks[$check],
            )),
            'authority_blockers' => array_keys(array_filter($checks, fn (bool $passed): bool => ! $passed)),
            'legacy_hypothesis_grants_credit' => false,
            'promotion_evidence' => false,
        ];
    }

    public function newValue(AgentLearningLesson $lesson): mixed
    {
        return $this->unbox(data_get(
            $lesson->evidence,
            'new_value',
            data_get($lesson->evidence, 'failure_signature.new_value'),
        ));
    }

    public function oldValue(AgentLearningLesson $lesson): mixed
    {
        return $this->unbox(data_get(
            $lesson->evidence,
            'old_value',
            data_get($lesson->evidence, 'failure_signature.old_value'),
        ));
    }

    private function unbox(mixed $value): mixed
    {
        return is_array($value) && array_key_exists('value', $value) ? $value['value'] : $value;
    }

    private function same(mixed $left, mixed $right): bool
    {
        if (is_numeric($left) && is_numeric($right)) {
            return abs((float) $left - (float) $right) < 0.000000001;
        }

        return json_encode($left, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)
            === json_encode($right, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
