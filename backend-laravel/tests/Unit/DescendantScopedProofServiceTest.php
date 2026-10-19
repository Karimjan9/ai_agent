<?php

namespace Tests\Unit;

use App\Services\DescendantScopedProofService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\ResearchWindowExposureInventoryService;
use App\Services\ScopedResearchCertificateService;
use Illuminate\Support\Facades\File;
use Mockery\MockInterface;
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

    public function test_six_window_parent_scope_derives_only_entry_and_fresh_exit_then_clears(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'descendant-parent-scope-');
        $this->assertNotFalse($path);
        File::put($path, 'synthetic original parent bytes');
        $prior = app(ResearchWindowExposureInventoryService::class);
        $this->app->instance(ResearchWindowExposureInventoryService::class, $prior);
        $owner = $this->parentScopeOwner($path);
        try {
            $result = $owner->withOriginalDescendantParentProof(41, function () use ($owner): int {
                foreach (range(1, 6) as $window) {
                    $this->assertSame($owner, app(ResearchWindowExposureInventoryService::class));
                    $parent = app(ResearchWindowExposureInventoryService::class)->inspectOriginalDescendantParent(41);
                    $this->assertSame(41, $parent['certificate_id']);
                    $this->assertSame(1, $owner->proofCalls);
                    $owner->withOriginalDescendantParentProof(41,
                        fn () => $this->assertSame($parent, $owner->inspectOriginalDescendantParent(41)));
                }

                return 6;
            });
            $this->assertSame(6, $result);
            $this->assertSame(2, $owner->proofCalls);
            $this->assertSame($prior, app(ResearchWindowExposureInventoryService::class));
            $owner->withOriginalDescendantParentProof(41,
                fn () => $this->assertSame(3, $owner->proofCalls));
            $this->assertSame(4, $owner->proofCalls); // A new invocation reopens the parent.
            $this->mock(ScopedResearchCertificateService::class, function (MockInterface $mock): void {
                $mock->shouldReceive('inspect')->once()->with(41)->andReturn(['fresh_after_scope' => true]);
            });
            $this->assertSame(['fresh_after_scope' => true], $owner->inspectOriginalDescendantParent(41));
        } finally {
            File::delete($path);
        }
    }

    public function test_parent_scope_detects_original_byte_change_before_return_and_does_not_keep_failed_proof(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'descendant-parent-tamper-');
        $this->assertNotFalse($path);
        File::put($path, 'synthetic original parent bytes');
        $prior = app(ResearchWindowExposureInventoryService::class);
        $this->app->instance(ResearchWindowExposureInventoryService::class, $prior);
        $owner = $this->parentScopeOwner($path);
        try {
            try {
                $owner->withOriginalDescendantParentProof(41, function () use ($path, $owner): void {
                    $this->assertSame(41, $owner->inspectOriginalDescendantParent(41)['certificate_id']);
                    File::put($path, 'damaged original parent bytes');
                });
                $this->fail('Changed original bytes escaped the fresh operation-exit check.');
            } catch (\LogicException $error) {
                $this->assertSame('DESCENDANT_ORIGINAL_OPERATION_PARENT_DRIFT', $error->getMessage());
            }
            $this->assertSame(2, $owner->proofCalls);
            $this->assertSame($prior, app(ResearchWindowExposureInventoryService::class));
            $owner->withOriginalDescendantParentProof(41, function () use ($path, $owner): void {
                $this->assertSame(hash_file('sha256', $path), $owner->inspectOriginalDescendantParent(41)['raw_original_sha256']);
                $this->assertSame(3, $owner->proofCalls);
            });
            $this->assertSame(4, $owner->proofCalls);
        } finally {
            File::delete($path);
        }
    }

    public function test_parent_scope_exception_and_wrong_parent_restore_the_previous_owner(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'descendant-parent-exception-');
        $this->assertNotFalse($path);
        File::put($path, 'synthetic original parent bytes');
        $prior = app(ResearchWindowExposureInventoryService::class);
        $this->app->instance(ResearchWindowExposureInventoryService::class, $prior);
        $owner = $this->parentScopeOwner($path);
        try {
            try {
                $owner->withOriginalDescendantParentProof(41,
                    fn () => $owner->inspectOriginalDescendantParent(42));
                $this->fail('A different parent borrowed the active lexical proof.');
            } catch (\LogicException $error) {
                $this->assertSame('DESCENDANT_ORIGINAL_OPERATION_PARENT_SCOPE_MISMATCH', $error->getMessage());
            }
            $this->assertSame(1, $owner->proofCalls);
            $this->assertSame($prior, app(ResearchWindowExposureInventoryService::class));
            $owner->withOriginalDescendantParentProof(41,
                fn () => $this->assertSame(2, $owner->proofCalls));
            $this->assertSame(3, $owner->proofCalls);
        } finally {
            File::delete($path);
        }
    }

    /** A controlled original-owner seam tests scope lifetime, not market authority. */
    private function parentScopeOwner(string $path): ResearchWindowExposureInventoryService
    {
        return new class(app(ResearchPaperEpochContractService::class), $path) extends ResearchWindowExposureInventoryService
        {
            public int $proofCalls = 0;

            public function __construct(ResearchPaperEpochContractService $epochs, private readonly string $path)
            {
                parent::__construct($epochs);
            }

            protected function rederiveOriginalDescendantParent(int $certificateId): array
            {
                $this->proofCalls++;
                $prefix = ['certificate_id' => $certificateId, 'source_hash' => str_repeat('a', 64),
                    'design_hash' => str_repeat('b', 64), 'authority_record_id' => 71,
                    'raw_original_sha256' => hash_file('sha256', $this->path)];

                return ['certificate' => $prefix, 'prefix' => $prefix];
            }
        };
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
