<?php

namespace App\Services;

use App\Models\InstrumentValuePosterior;
use App\Models\PlaybookValuePosterior;

/** Exact-value consumption is distinct from selecting a gene or reading a prior. */
class InstrumentPolicyConsumptionService
{
    public function receipt(array $policy, array $parameterDiff, array $parameters): array
    {
        $isolated = [];
        $bundles = [];
        $applied = [];
        $deltas = [];
        foreach ((array) ($policy['preferred_deltas'] ?? []) as $entry) {
            $delta = (array) ($entry['tested_intervention'] ?? []);
            $gene = (string) ($delta['gene'] ?? '');
            $source = (array) ($entry['source'] ?? []);
            if (! $this->deltaMatches($delta, $parameterDiff, $parameters)
                || ! $this->sourceVerified($source, false, 'confirmed')
                || ! $this->contextMatches((array) ($source['context'] ?? []), (array) ($policy['context'] ?? []), (string) ($policy['strategy_family'] ?? ''))) {
                continue;
            }
            $support = array_values(array_filter((array) ($entry['bundle_sources'] ?? []),
                fn (array $bundle): bool => $this->sourceVerified($bundle, true, 'confirmed')
                    && ($bundle['primary_instrument_key'] ?? null) === ($source['instrument_key'] ?? null)
                    && ($bundle['state_key'] ?? null) === ($source['state_key'] ?? null)
                    && ($bundle['validation_epoch_key'] ?? null) === ($source['validation_epoch_key'] ?? null)
                    && data_get($bundle, 'tested_intervention.intervention_hash') === ($delta['intervention_hash'] ?? null)));
            if ($support === []) {
                continue;
            }
            $isolated[] = $source;
            $bundles = [...$bundles, ...$support];
            $applied[] = $gene;
            $deltas[] = $delta;
        }
        $valid = $applied !== [];
        $identity = [
            'protocol' => 'instrument_successor_consumption_v2',
            'status' => $valid ? 'policy_aligned_mutation_observed' : 'not_applied',
            'requested_context' => (array) ($policy['context'] ?? []),
            'applied_genes' => array_values(array_unique($applied)), 'tested_interventions' => $deltas,
            'parameter_diff' => $valid ? array_intersect_key($parameterDiff, array_flip($applied)) : [],
            'isolated_sources' => $isolated, 'bundle_sources' => $bundles,
            'resulting_parameter_hash' => $this->hash($parameters),
            'paper_execution_authority' => false, 'promotion_evidence' => false,
        ];

        return [...$identity, 'receipt_hash' => $this->hash($identity)];
    }

    /** Respect the existing selected gene; never replace an isolated experiment. */
    public function applyPreferredDelta(array $policy, array $baseline, array $candidate, array $allowedGenes): array
    {
        $changed = [];
        foreach (array_unique([...array_keys($baseline), ...array_keys($candidate)]) as $gene) {
            if (! array_key_exists($gene, $baseline) || ! array_key_exists($gene, $candidate)
                || $this->hash(['value' => $baseline[$gene]]) !== $this->hash(['value' => $candidate[$gene]])) {
                $changed[] = $gene;
            }
        }
        if (count($changed) !== 1) {
            return $candidate;
        }
        $matches = [];
        foreach ((array) ($policy['preferred_deltas'] ?? []) as $entry) {
            $delta = (array) ($entry['tested_intervention'] ?? []);
            $gene = (string) ($delta['gene'] ?? '');
            if ($gene !== $changed[0] || ! in_array($gene, $allowedGenes, true)
                || ($delta['baseline_parameter_hash'] ?? null) !== $this->hash($baseline)
                || ! array_key_exists('old', $delta) || ! array_key_exists('new', $delta)
                || $this->hash(['value' => $baseline[$gene]]) !== $this->hash(['value' => $delta['old']])) {
                continue;
            }
            $next = [...$candidate, $gene => $delta['new']];
            if ($this->receipt($policy, [$gene => ['old' => $baseline[$gene], 'new' => $delta['new']]], $next)['status'] === 'policy_aligned_mutation_observed') {
                $matches[$this->hash(['value' => $delta['new']])] = $next;
            }
        }

        // Contradictory confirmed proposals need a fresh controlled comparison.
        return count($matches) === 1 ? array_values($matches)[0] : $candidate;
    }

    public function forbiddenDelta(array $policy, array $parameterDiff, array $parameters): bool
    {
        foreach ((array) ($policy['blocked_deltas'] ?? []) as $entry) {
            $delta = (array) ($entry['tested_intervention'] ?? []);
            $source = (array) ($entry['source'] ?? []);
            if (! $this->deltaMatches($delta, $parameterDiff, $parameters)
                || ! $this->contextMatches((array) ($source['context'] ?? []), (array) ($policy['context'] ?? []), (string) ($policy['strategy_family'] ?? ''))) {
                continue;
            }
            if ($this->sourceVerified($source, false, 'forbidden')) {
                return true;
            }
            if ($this->sourceVerified($source, false, 'confirmed')) {
                foreach ((array) ($entry['bundle_sources'] ?? []) as $bundle) {
                    if ($this->sourceVerified((array) $bundle, true, 'forbidden')
                        && ($bundle['validation_epoch_key'] ?? null) === ($source['validation_epoch_key'] ?? null)
                        && ($bundle['state_key'] ?? null) === ($source['state_key'] ?? null)
                        && ($bundle['primary_instrument_key'] ?? null) === ($source['instrument_key'] ?? null)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function deltaMatches(array $delta, array $diff, array $parameters): bool
    {
        $gene = (string) ($delta['gene'] ?? '');
        $baseline = $parameters;
        foreach ($diff as $key => $change) {
            if (! is_array($change) || ! array_key_exists('old', $change) || ! array_key_exists('new', $change)
                || ! array_key_exists($key, $parameters)
                || $this->hash(['value' => $parameters[$key]]) !== $this->hash(['value' => $change['new']])) {
                return false;
            }
            $baseline[$key] = $change['old'];
        }

        return app(InstrumentValidationEvidenceService::class)->deltaValid($delta, (string) ($delta['state_key'] ?? ''))
            && ($delta['baseline_parameter_hash'] ?? null) === $this->hash($baseline)
            && array_key_exists($gene, $parameters) && isset($diff[$gene])
            && array_key_exists('old', $diff[$gene]) && array_key_exists('new', $diff[$gene])
            && $this->hash(['value' => $delta['old']]) === $this->hash(['value' => $diff[$gene]['old']])
            && $this->hash(['value' => $delta['new']]) === $this->hash(['value' => $diff[$gene]['new']])
            && $this->hash(['value' => $delta['new']]) === $this->hash(['value' => $parameters[$gene]]);
    }

    private function sourceVerified(array $source, bool $bundle, string $state): bool
    {
        $posterior = $bundle ? PlaybookValuePosterior::query()->with('playbook')->find($source['posterior_id'] ?? 0)
            : InstrumentValuePosterior::query()->with('instrument')->find($source['posterior_id'] ?? 0);
        if ($posterior === null || ($source['state'] ?? null) !== $state || ($source['state_key'] ?? null) !== $posterior->state_key
            || $posterior->decay_state === 'decaying') {
            return false;
        }
        if ($bundle) {
            if (($source['playbook_key'] ?? null) !== $posterior->playbook?->playbook_key
                || data_get($posterior->playbook?->metadata, 'protocol') !== 'exact_instrument_research_bundle_v1'
                || ($source['primary_instrument_key'] ?? null) !== data_get($posterior->playbook?->metadata, 'primary_instrument_key')) {
                return false;
            }
        } elseif (($source['instrument_key'] ?? null) !== $posterior->instrument?->instrument_key) {
            return false;
        }
        foreach (app(InstrumentPosteriorAuthorityService::class)->validationEpochs($posterior) as $epoch) {
            $axes = array_filter(app(ContextContractV2Service::class)->canonicalAxes((array) data_get($epoch, 'tested_intervention.context', [])),
                static fn ($value): bool => $value !== null && $value !== '');
            $axes['strategy_family'] = (string) data_get($epoch, 'tested_intervention.strategy_family', '');
            if ($epoch['canonical_state'] === $state && ($source['validation_epoch_key'] ?? null) === $epoch['epoch_key']
                && $this->hash((array) ($source['context'] ?? [])) === $this->hash($axes)
                && $this->hash((array) ($source['tested_intervention'] ?? [])) === $this->hash($epoch['tested_intervention'])
                && ($source['window_evidence_digest'] ?? null) === $this->hash($epoch['window_evidence'])
                && $this->hash((array) ($source['source_receipts'] ?? [])) === $this->hash(array_column($epoch['window_evidence'], 'source_receipt'))) {
                return true;
            }
        }

        return false;
    }

    private function contextMatches(array $observed, array $requested, string $family): bool
    {
        $axes = ['regime', 'session', 'venue_phase', 'volatility', 'spread_liquidity_state', 'transition_state', 'direction'];
        if ($family === '' || ($observed['strategy_family'] ?? null) !== $family) {
            return false;
        }
        foreach ($axes as $axis) {
            if (! isset($requested[$axis], $observed[$axis]) || (string) $requested[$axis] !== (string) $observed[$axis]) {
                return false;
            }
        }

        return true;
    }

    private function hash(array $value): string
    {
        return app(ResearchPaperEpochContractService::class)->parameterHash($value);
    }
}
