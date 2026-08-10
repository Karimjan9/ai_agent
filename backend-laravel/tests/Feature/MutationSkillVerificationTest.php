<?php

namespace Tests\Feature;

use App\Models\ModelVersion;
use App\Services\LabPopulationService;
use App\Services\MutationSkillVerificationService;
use App\Services\StrategyParameterSchemaService;
use App\Services\StrategySemanticGroupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MutationSkillVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_parent_is_not_a_genetic_parent(): void
    {
        $groups = app(StrategySemanticGroupService::class);
        $legacy = ModelVersion::create([
            'name' => 'legacy-parent', 'strategy' => 'legacy_parent', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => [], 'metadata' => [],
            'evidence_status' => 'valid',
        ]);

        $this->assertTrue($groups->parentCompatible($legacy, 'trend', ['role' => 'general']));
        $this->assertFalse($groups->exactParentCompatible($legacy, 'XAUUSD', 'H1', 'trend', ['role' => 'general']));

        $declared = ModelVersion::create([
            'name' => 'declared-parent', 'strategy' => 'declared_parent', 'version' => 'v1',
            'generation' => 1, 'status' => 'testing', 'parameters' => [],
            'metadata' => [
                'semantic_group' => $groups->descriptor('XAUUSD', 'H1', 'trend', ['role' => 'general']),
            ], 'evidence_status' => 'valid',
        ]);

        $this->assertTrue($groups->exactParentCompatible($declared, 'XAUUSD', 'H1', 'trend', ['role' => 'general']));
        $this->assertFalse($groups->exactParentCompatible($declared, 'XAUUSD', 'H1', 'trend', ['role' => 'trend_up_specialist']));
    }

    public function test_overlapping_forward_windows_do_not_create_two_skill_confirmations(): void
    {
        $evidence = app(MutationSkillVerificationService::class)->independentForwardWindows([
            'market_adaptive_replay' => [
                'checkpoint_windows' => [
                    ['window' => 1, 'start' => '2026-01-01T00:00:00Z', 'end' => '2026-01-10T00:00:00Z', 'score' => 10, 'trades' => 20],
                    ['window' => 2, 'start' => '2026-01-09T00:00:00Z', 'end' => '2026-01-20T00:00:00Z', 'score' => 11, 'trades' => 20],
                    ['window' => 3, 'start' => '2026-01-21T00:00:00Z', 'end' => '2026-01-30T00:00:00Z', 'score' => 12, 'trades' => 20],
                ],
                // This is the same replay projection and must not be merged
                // with checkpoints to manufacture another quorum.
                'monthly_walk_forward' => ['windows' => [
                    ['test_month' => '2026-02', 'score' => 15, 'trades' => 20],
                    ['test_month' => '2026-03', 'score' => 16, 'trades' => 20],
                ]],
            ],
        ]);

        $this->assertSame('checkpoint_windows', $evidence['source']);
        $this->assertTrue($evidence['overlap_detected']);
        $this->assertSame(0, $evidence['confirmed_windows']);
        $this->assertSame(2, $evidence['independent_windows']);
    }

    public function test_constructor_skips_a_zero_diff_repair_when_all_genes_are_blocked(): void
    {
        $service = app(LabPopulationService::class);
        $schemas = app(StrategyParameterSchemaService::class);
        $base = $schemas->defaults('trend');
        $method = new \ReflectionMethod($service, 'enforceConstructorMutationInvariant');
        $method->setAccessible(true);

        $candidate = $method->invoke(
            $service,
            'trend',
            $base,
            $base,
            1,
            'monthly_survival',
            array_keys($schemas->schema('trend')),
            true,
        );

        $this->assertNull($candidate);
    }
}
