<?php

namespace Tests\Feature;

use App\Models\CandidateGateDecision;
use App\Models\ModelMarketPerformance;
use App\Models\ModelVersion;
use App\Services\RuntimeEnsemblePolicyService;
use App\Services\StrategyParameterSchemaService;
use App\Services\StrategySemanticGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RuntimeEnsemblePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_raw_genetic_parent_policy_can_never_activate_runtime_members(): void
    {
        $model = $this->model('raw-parent');
        $performance = ModelMarketPerformance::create([
            'model_version_id' => $model->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'trend',
            'status' => 'forward_validated',
            'evidence_status' => 'valid',
            'metrics' => [
                'runtime_ensemble_policy' => [
                    'independent_members_validated' => false,
                    'member_model_version_ids' => [$model->id],
                ],
            ],
        ]);

        $policy = app(RuntimeEnsemblePolicyService::class)->forPerformance($performance);

        $this->assertSame('waiting', $policy['status']);
        $this->assertSame('GENETIC_PARENTS_NOT_RUNTIME_MEMBERS', $policy['reason']);
    }

    public function test_sealed_portfolio_requires_independent_member_passports_and_exact_specs(): void
    {
        $memberOne = $this->model('sealed-member-one');
        $memberTwo = $this->model('sealed-member-two');
        $this->performanceWithPassport($memberOne, 1);
        $memberTwoPerformance = $this->performanceWithPassport($memberTwo, 2);
        $owner = $this->model('portfolio-owner', 3, [
            'base_strategy' => 'portfolio',
            'portfolio_proxy' => true,
            'portfolio_members' => [
                [
                    'strategy' => $memberOne->strategy,
                    'base_strategy' => 'trend',
                    'version' => $memberOne->version,
                    'parameters' => $memberOne->parameters,
                    'member_key' => 'performance:999999',
                    'target_regime' => 'trend_up',
                ],
                [
                    'strategy' => $memberTwo->strategy,
                    'base_strategy' => 'trend',
                    'version' => $memberTwo->version,
                    'parameters' => $memberTwo->parameters,
                    'member_key' => 'performance:'.$memberTwoPerformance->id,
                    'target_regime' => 'range',
                ],
            ],
        ]);
        $ownerPerformance = ModelMarketPerformance::create([
            'model_version_id' => $owner->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'portfolio',
            'status' => 'forward_validated',
            'evidence_status' => 'valid',
            'metrics' => [
                'portfolio_proxy' => true,
                'elite_agent_passport' => ['status' => 'passed'],
            ],
        ]);

        $policy = app(RuntimeEnsemblePolicyService::class)->forPerformance($ownerPerformance);

        $this->assertSame('waiting', $policy['status']);
        $this->assertSame('PORTFOLIO_MEMBER_PASSPORT_NOT_ACTIVE', $policy['reason']);
    }

    public function test_passed_combined_portfolio_activates_only_matching_independent_members(): void
    {
        $memberOne = $this->model('active-member-one', 1);
        $memberTwo = $this->model('active-member-two', 2);
        $performanceOne = $this->performanceWithPassport($memberOne, 1);
        $performanceTwo = $this->performanceWithPassport($memberTwo, 2);
        $owner = $this->model('active-portfolio', 3, [
            'base_strategy' => 'portfolio',
            'portfolio_proxy' => true,
            'elite_portfolio_id' => 99,
            'portfolio_members' => [
                [
                    'strategy' => $memberOne->strategy,
                    'base_strategy' => 'trend',
                    'version' => $memberOne->version,
                    'parameters' => $memberOne->parameters,
                    'member_key' => 'performance:'.$performanceOne->id,
                    'target_regime' => 'trend_up',
                ],
                [
                    'strategy' => $memberTwo->strategy,
                    'base_strategy' => 'trend',
                    'version' => $memberTwo->version,
                    'parameters' => $memberTwo->parameters,
                    'member_key' => 'performance:'.$performanceTwo->id,
                    'target_regime' => 'range',
                ],
            ],
        ]);
        $ownerPerformance = ModelMarketPerformance::create([
            'model_version_id' => $owner->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'portfolio',
            'status' => 'forward_validated',
            'evidence_status' => 'valid',
            'metrics' => [
                'portfolio_proxy' => true,
                'elite_portfolio_id' => 99,
                'elite_agent_passport' => ['status' => 'passed'],
            ],
        ]);

        $service = app(RuntimeEnsemblePolicyService::class);
        $policy = $service->forPerformance($ownerPerformance);
        $payload = $service->requestPayload($ownerPerformance);

        $this->assertSame('active', $policy['status']);
        $this->assertSame('sealed_portfolio_passport', $policy['source']);
        $this->assertCount(2, $policy['members']);
        $this->assertSame('ROUTE', $payload['runtime_action']);
        $this->assertCount(2, $payload['portfolio_members']);
        $this->assertTrue($payload['runtime_ensemble_policy']['combined_passport']);
    }

    private function model(string $name, int $variant = 1, array $metadata = []): ModelVersion
    {
        $parameters = app(StrategyParameterSchemaService::class)->defaults('trend');
        $parameters['ema_fast'] = 20 + $variant;
        $groups = app(StrategySemanticGroupService::class);

        return ModelVersion::create([
            'name' => $name,
            'strategy' => 'xauusd_'.$name,
            'version' => 'v1',
            'generation' => 1,
            'status' => 'testing',
            'best_score' => 60 + $variant,
            'parameters' => $parameters,
            'metadata' => [
                'lab_symbol' => 'XAUUSD',
                'lab_timeframe' => 'H1',
                'strategy_architecture' => 'trend_pullback',
                'semantic_group' => $groups->descriptor('XAUUSD', 'H1', 'trend', ['role' => 'general']),
                ...$metadata,
            ],
            'evidence_status' => 'valid',
        ]);
    }

    private function performanceWithPassport(ModelVersion $model, int $variant): ModelMarketPerformance
    {
        $performance = ModelMarketPerformance::create([
            'model_version_id' => $model->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'trend',
            'status' => 'forward_validated',
            'evidence_status' => 'valid',
            'sample_count' => 40,
            'metrics' => ['profit_factor' => 1.5],
        ]);
        CandidateGateDecision::create([
            'model_market_performance_id' => $performance->id,
            'stage' => 'statistical_forward_gate',
            'decision' => 'passed',
            'reason_codes' => [],
            'metrics' => ['elite_agent_passport' => ['status' => 'passed']],
            'evaluated_at' => now()->addSeconds($variant),
        ]);
        return $performance;
    }
}
