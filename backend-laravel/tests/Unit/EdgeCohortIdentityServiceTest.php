<?php

namespace Tests\Unit;

use App\Services\EdgeCohortIdentityService;
use Tests\TestCase;

class EdgeCohortIdentityServiceTest extends TestCase
{
    public function test_identity_is_canonical_and_window_stages_are_disjoint(): void
    {
        $identity = app(EdgeCohortIdentityService::class);
        $data = str_repeat('a', 64);
        $mtf = str_repeat('b', 64);
        $plan = $identity->windowPlan($data, $mtf);

        $this->assertSame(14, $plan['universe_folds']);
        $this->assertSame(['offset' => 0, 'fold_count' => 2, 'authority' => false],
            $plan['stages']['two_fold_discovery']);
        $this->assertSame(['offset' => 2, 'fold_count' => 3, 'authority' => false],
            $plan['stages']['three_fold_confirmation']);
        $this->assertSame(['offset' => 5, 'fold_count' => 9, 'authority' => true],
            $plan['stages']['nine_fold_authority']);
        $this->assertSame([], array_intersect(range(0, 1), range(2, 4)));
        $this->assertSame([], array_intersect(range(0, 4), range(5, 13)));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $plan['window_plan_hash']);

        $left = $identity->hash(['b' => ['y' => 2, 'x' => 1], 'a' => 3]);
        $right = $identity->hash(['a' => 3, 'b' => ['x' => 1, 'y' => 2]]);
        $this->assertSame($left, $right);

        $key = $identity->cohortKey('xauusd', $data, $mtf, str_repeat('c', 64),
            'revision_v1', str_repeat('d', 64), $plan['window_plan_hash']);
        $this->assertSame($key, $identity->cohortKey('XAUUSD', $data, $mtf, str_repeat('c', 64),
            'revision_v1', str_repeat('d', 64), $plan['window_plan_hash']));
        $this->assertNotSame($key, $identity->cohortKey('XAUUSD', $data, $mtf, str_repeat('c', 64),
            'revision_v2', str_repeat('d', 64), $plan['window_plan_hash']));
    }
}
