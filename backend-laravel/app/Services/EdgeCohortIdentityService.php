<?php

namespace App\Services;

/**
 * Produces deterministic Edge cohort identities without using a generation
 * id.  The database unique key therefore protects the research budget even
 * when two schedulers race before either generation has been materialized.
 */
class EdgeCohortIdentityService
{
    public const PROTOCOL = 'edge_cohort_identity_v2';
    public const WINDOW_PROTOCOL = 'edge_disjoint_2_3_9_window_plan_v1';

    /** @return array<string,mixed> */
    public function windowPlan(string $dataHash, string $mtfBundleHash): array
    {
        $plan = [
            'protocol' => self::WINDOW_PROTOCOL,
            'universe_folds' => 14,
            'stages' => [
                'two_fold_discovery' => ['offset' => 0, 'fold_count' => 2, 'authority' => false],
                'three_fold_confirmation' => ['offset' => 2, 'fold_count' => 3, 'authority' => false],
                'nine_fold_authority' => ['offset' => 5, 'fold_count' => 9, 'authority' => true],
            ],
            'data_hash' => $dataHash,
            'mtf_bundle_hash' => $mtfBundleHash,
            'overlap_allowed' => false,
            'discovery_and_confirmation_are_compute_admission_only' => true,
            'promotion_evidence' => false,
        ];
        $plan['window_plan_hash'] = $this->hash($plan);

        return $plan;
    }

    /** @param array<int,array<string,mixed>> $packets @param array<int,string> $arms */
    public function packetDefinitionHash(array $packets, array $arms, array $compilerContract = []): string
    {
        return $this->hash([
            'packets' => $packets,
            'arms' => array_values($arms),
            'compiler_contract' => $compilerContract,
        ]);
    }

    public function cohortKey(
        string $symbol,
        string $dataHash,
        string $mtfBundleHash,
        string $executionHash,
        string $architectureRevision,
        string $packetDefinitionHash,
        string $windowPlanHash,
    ): string {
        return hash('sha256', implode('|', [
            self::PROTOCOL,
            strtoupper($symbol),
            $dataHash,
            $mtfBundleHash,
            $executionHash,
            $architectureRevision,
            $packetDefinitionHash,
            $windowPlanHash,
        ]));
    }

    /** @param array<string,mixed> $value */
    public function hash(array $value): string
    {
        return hash('sha256', json_encode($this->canonical($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) return $value;
        if (array_is_list($value)) return array_map(fn (mixed $item): mixed => $this->canonical($item), $value);
        ksort($value);
        foreach ($value as $key => $item) $value[$key] = $this->canonical($item);

        return $value;
    }
}
