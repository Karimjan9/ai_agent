<?php

namespace Tests\Support;

use App\Models\SpecialistCouncilVersion;
use App\Services\LabImmutableEvidenceService;
use App\Services\ResearchKnowledgePortfolioService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\SpecialistCouncilContractService;
use App\Services\SpecialistCouncilLifecycleService;
use Illuminate\Support\Facades\DB;
use LogicException;
use Mockery;

/**
 * Conditional ORIGINAL QUALIFICATION boundary for cheap native routing acceptance.
 * This creates no original exam/run, real qualification, skill or paper authority.
 * Target plans/specs, rank API/ranker, selector, order and unit owner stay real.
 */
trait ConditionalQualifiedNativePolicyFixture
{
    protected function installConditionalQualifiedNativePolicy(array $projection): array
    {
        $epochs = app(ResearchPaperEpochContractService::class);
        $contracts = app(SpecialistCouncilContractService::class);
        $lifecycle = app(SpecialistCouncilLifecycleService::class);
        $target = SpecialistCouncilVersion::findOrFail($projection['panel_version_id']);
        $targetRow = DB::table('specialist_council_evaluation_plans')->where('specialist_council_version_id', $target->id)->sole();
        $plan = json_decode($targetRow->plan, true, 512, JSON_THROW_ON_ERROR);
        $policy = app(ResearchKnowledgePortfolioService::class)->registerPolicy(['weights' => ['cost' => -1],
            'compute_cap_seconds' => 3600, 'max_candidates' => 16, 'exploration_fraction' => .05]);
        $id = $policy['knowledge_key'];
        $manifest = array_diff_key($target->manifest, array_flip(['manifest_hash', 'epoch_contract', 'promotion_evidence']));
        $manifest['council_id'] = 'conditional-native-policy-fixture';
        $manifest['version'] = 'conditional-'.substr($epochs->parameterHash([$target->id, $id]), 0, 20);
        $manifest['components'] = [...array_values(array_filter($manifest['components'], fn ($component) => $component['id'] !== $id)),
            ['id' => $id, 'version' => '1', 'role' => 'learning', 'input_type' => 'native_research_question_features',
                'output_type' => 'ranked_research_questions', 'consumer_roles' => ['learning'], 'as_of_only' => true,
                'max_compute_ms' => 1000, 'permissions' => [], 'fixture_not_original_qualification' => true]];
        $source = $lifecycle->registerDraft($manifest, 'conditional-policy-fixture-creator');
        $benchmark = ['challenge_key' => 'conditional-native-policy-boundary:'.$source->id,
            'challenge_hash' => $epochs->parameterHash(['conditional_benchmark_not_evidence', $source->id]),
            'policy_key' => $id, 'policy_hash' => $epochs->parameterHash($policy)];
        $plan['version_id'] = (int) $source->id; $plan['manifest_hash'] = $source->manifest_hash;
        $plan['support_role_trials'] = [['component_id' => $id, 'role' => 'learning', 'original_native_policy_benchmark' => $benchmark]];
        $planHash = $epochs->parameterHash($plan);
        DB::table('specialist_council_evaluation_plans')->insert(['specialist_council_version_id' => $source->id,
            'evaluator_id' => $targetRow->evaluator_id, 'plan' => json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
            'plan_hash' => $planHash, 'sealed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $assessment = ['fixture_not_original_qualification' => true, 'qualified' => false,
            'support_role_qualifications' => [$id => ['status' => 'research_role_qualified', 'role' => 'learning']]];
        $source->forceFill(['state' => 'evaluated', 'assessment' => $assessment,
            'assessment_hash' => $epochs->parameterHash($assessment)])->save();
        $component = collect($source->manifest['components'])->firstWhere('id', $id);
        $binding = ['protocol' => 'specialist_support_research_binding_v1', 'source_version_id' => (int) $source->id,
            'source_manifest_hash' => $source->manifest_hash, 'assessment_hash' => $source->assessment_hash,
            'component_id' => $id, 'component_contract_hash' => $component['contract_hash'],
            'qualification_hash' => $epochs->parameterHash(['conditional_qualification_not_evidence', $source->id]),
            'role' => 'learning', 'scope' => array_column($target->manifest['members'], 'scope'),
            'authority' => 'scoped_research_component_only', 'paper_authority_granted' => false, 'promotion_evidence' => false];
        $state = (object) ['revoked' => false, 'binding' => $binding, 'benchmark' => $benchmark];
        $contractMock = Mockery::mock(SpecialistCouncilContractService::class, [$epochs])->makePartial();
        $contractMock->shouldReceive('supportNativePolicyBenchmarkReference')
            ->withArgs(fn ($actual, $key) => ($actual['id'] ?? null) === $id && $key === $benchmark['challenge_key'])
            ->andReturnUsing(fn () => $state->benchmark);
        $this->app->instance(SpecialistCouncilContractService::class, $contractMock);
        $owner = Mockery::mock(SpecialistCouncilLifecycleService::class,
            [$contractMock, $epochs, app(LabImmutableEvidenceService::class)])->makePartial();
        $owner->shouldReceive('researchSupportBinding')->withArgs(fn ($actual, $key) => $actual->id === $source->id && $key === $id)
            ->andReturnUsing(function () use ($state): array {
                if ($state->revoked) throw new LogicException('CONDITIONAL_ORIGINAL_POLICY_QUALIFICATION_REVOKED');
                return $state->binding;
            });
        $this->app->instance(SpecialistCouncilLifecycleService::class, $owner);
        return ['source' => $source->fresh(), 'component_id' => $id, 'binding' => $binding,
            'source_plan_hash' => $planHash, 'native_benchmark_reference' => $benchmark, 'state' => $state,
            'qualification_is_conditional_not_market_evidence' => true];
    }
}
