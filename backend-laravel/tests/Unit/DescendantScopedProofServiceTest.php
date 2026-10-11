<?php

namespace Tests\Unit;

use App\Services\DescendantScopedProofService;
use Tests\TestCase;

class DescendantScopedProofServiceTest extends TestCase
{
    public function test_matched_four_arm_vectors_derive_disjoint_exact_t_and_u(): void
    {
        $proof = app(DescendantScopedProofService::class)->topology($this->arms(), 'minimum_confidence');

        $this->assertSame('topology_valid', $proof['status']);
        $this->assertSame(['gene' => 'minimum_confidence', 'old' => 1.0, 'new' => 1.1], $proof['trait_delta']);
        $this->assertSame(['gene' => 'rsi_threshold', 'old' => 30, 'new' => 32], $proof['other_delta']);
        $this->assertFalse($proof['inheritance_credit']);
    }

    public function test_ablated_sibling_cannot_retain_the_trait_or_change_another_parameter(): void
    {
        $arms = $this->arms();
        $arms['P+U']['minimum_confidence'] = 1.1;
        $this->assertSame('blocked', app(DescendantScopedProofService::class)->topology($arms, 'minimum_confidence')['status']);

        $arms = $this->arms();
        $arms['P+T+U']['risk'] = .5;
        $this->assertSame('blocked', app(DescendantScopedProofService::class)->topology($arms, 'minimum_confidence')['status']);
    }

    public function test_missing_parameter_is_not_a_sealed_old_value(): void
    {
        $arms = $this->arms();
        unset($arms['P']['minimum_confidence'], $arms['P+U']['minimum_confidence']);

        $this->assertSame('blocked', app(DescendantScopedProofService::class)->topology($arms, 'minimum_confidence')['status']);
    }

    public function test_bundle_improvement_does_not_credit_a_redundant_trait(): void
    {
        $proof = app(DescendantScopedProofService::class)->effects(['P' => 1, 'P+T' => 2, 'P+T+U' => 3, 'P+U' => 3]);

        $this->assertSame('bundle_only', $proof['attribution']);
        $this->assertSame(0.0, $proof['effects']['T_retained_under_U']);
        $this->assertFalse($proof['trait_retained']);
        $this->assertFalse($proof['component_credit']);
        $this->assertFalse($proof['inheritance_credit']);
        $this->assertFalse($proof['independent_market_evidence']);
    }

    public function test_supported_individual_ablations_remain_arithmetic_without_authority(): void
    {
        $proof = app(DescendantScopedProofService::class)->effects(['P' => 1, 'P+T' => 2, 'P+T+U' => 4, 'P+U' => 2]);

        $this->assertSame('individual_ablation_supported', $proof['attribution']);
        $this->assertSame(1.0, $proof['effects']['interaction']);
        $this->assertTrue($proof['trait_retained']);
        $this->assertFalse($proof['inheritance_credit']);
    }

    public function test_missing_nonfinite_or_renamed_arm_has_no_effect_result(): void
    {
        $owner = app(DescendantScopedProofService::class);
        $this->assertSame('blocked', $owner->effects(['P' => 1, 'P+T' => 2, 'P+T+U' => 4])['status']);
        $this->assertSame('blocked', $owner->effects(['P' => 1, 'P+T' => INF, 'P+T+U' => 4, 'P+U' => 2])['status']);
        $this->assertSame('blocked', $owner->effects(['P' => 1, 'P+T' => 2, 'P+T+U' => 4, 'renamed' => 2])['status']);
    }

    private function arms(): array
    {
        return [
            'P' => ['minimum_confidence' => 1.0, 'rsi_threshold' => 30, 'risk' => 1],
            'P+T' => ['minimum_confidence' => 1.1, 'rsi_threshold' => 30, 'risk' => 1],
            'P+T+U' => ['minimum_confidence' => 1.1, 'rsi_threshold' => 32, 'risk' => 1],
            'P+U' => ['minimum_confidence' => 1.0, 'rsi_threshold' => 32, 'risk' => 1],
        ];
    }
}
