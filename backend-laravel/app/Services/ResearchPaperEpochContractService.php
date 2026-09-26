<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/** Canonical, fail-closed boundary between historical research and 2026 paper evidence. */
class ResearchPaperEpochContractService
{
    public const PROTOCOL = 'research_paper_epoch_contract_v2';

    public const PAPER_WINDOW_KEY = 'paper_2026';

    /** @return array<string,mixed> */
    public function contract(): array
    {
        $cutoff = $this->cutoff();

        return [
            'protocol' => self::PROTOCOL,
            'research_epoch' => [
                'end_exclusive' => $cutoff->toIso8601String(),
                'allowed_uses' => ['discovery', 'mutation', 'screening', 'repair', 'causal_confirmation'],
            ],
            'paper_epoch' => [
                'window_key' => self::PAPER_WINDOW_KEY,
                'start_inclusive' => $cutoff->toIso8601String(),
                'end_exclusive' => $cutoff->addYear()->toIso8601String(),
                'candidate_must_be_frozen_before_observation' => true,
            ],
            'paper_used_for_screening' => false,
            'paper_used_for_mutation' => false,
            'paper_used_for_selection' => false,
            'paper_used_for_posterior_update' => false,
            'paper_used_for_forward_evidence' => true,
            'paper_result_may_rewrite_candidate' => false,
            'promotion_evidence' => false,
        ];
    }

    /** @param array<int,mixed> $timestamps */
    public function paperWindowValid(array $timestamps, ?string $windowKey = null): bool
    {
        if ($timestamps === [] || ($windowKey ?? '') !== self::PAPER_WINDOW_KEY) {
            return false;
        }
        $start = $this->cutoff();
        $end = $start->addYear();

        foreach ($timestamps as $timestamp) {
            try {
                $observed = CarbonImmutable::parse((string) $timestamp, 'UTC')->utc();
            } catch (\Throwable) {
                return false;
            }
            if ($observed->lessThan($start) || ! $observed->lessThan($end)) {
                return false;
            }
        }

        return true;
    }

    public function parameterHash(array $parameters): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($parameters),
            JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        ));
    }

    public function cutoff(): CarbonImmutable
    {
        return CarbonImmutable::parse(
            (string) config('services.lab_selection.training_end_exclusive', '2026-01-01 00:00:00'),
            'UTC',
        )->utc();
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
