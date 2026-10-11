<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Historical research ends permanently at the continuous paper-only boundary. */
class ResearchPaperEpochContractService
{
    public const PROTOCOL = 'research_paper_epoch_contract_v2';

    public const PAPER_WINDOW_KEY = 'paper_2026';

    public const FUTURE_PAPER_PROTOCOL = 'authorized_prospective_paper_epoch_v1';

    public const CONFIRMATION_ROUTE_PROTOCOL = 'sealed_confirmation_route_v2';

    public const PAPER_ONLY_START = '2026-01-01T00:00:00Z';

    /** The finite paper_2026 window is a legacy observation window, not a research opening. */
    public function paperOnlyPolicy(): array
    {
        return ['protocol' => 'continuous_paper_only_epoch_v1',
            'start_inclusive' => self::PAPER_ONLY_START, 'end_exclusive' => null,
            'all_future_data_paper_only' => true, 'research_uses_forbidden' => true,
            'training_selection_warmup_and_context_included' => true];
    }

    /** @return array<string,mixed> */
    public function contract(): array
    {
        $cutoff = $this->cutoff();

        return [
            'protocol' => self::PROTOCOL,
            'paper_only_policy' => $this->paperOnlyPolicy(),
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
    public function paperWindowValid(array $timestamps, ?string $windowKey = null, ?array $sealedContract = null, ?string $frozenAt = null): bool
    {
        if ($timestamps === []) {
            return false;
        }
        if (($windowKey ?? '') !== self::PAPER_WINDOW_KEY) {
            if ($sealedContract === null || $frozenAt === null
                || ! $this->paperContractAuthorized($sealedContract, $frozenAt)
                || data_get($sealedContract, 'paper_epoch.window_key') !== $windowKey) return false;
            $start = $this->utc(data_get($sealedContract, 'paper_epoch.start_inclusive'));
            $end = $this->utc(data_get($sealedContract, 'paper_epoch.end_exclusive'));
            $freeze = $this->utc($frozenAt);
            if ($start === null || $end === null || $freeze === null) return false;
            foreach ($timestamps as $timestamp) {
                $observed = $this->utc($timestamp);
                if ($observed === null || $observed->lessThan($start) || ! $observed->lessThan($end)
                    || $observed->lessThan($freeze) || $observed->greaterThan(now())) return false;
            }
            return true;
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

    /** A future period exists only by explicit server authorization, never by rolling the cutoff. */
    public function paperContractForCandidate(string $windowKey, string $frozenAt): ?array
    {
        $freeze = $this->utc($frozenAt);
        if ($windowKey === self::PAPER_WINDOW_KEY) {
            return $freeze !== null && $freeze->lessThan($this->cutoff()->addYear())
                ? $this->contract() : null;
        }
        $epoch = $this->authorizedFuturePaperEpoch($windowKey);
        if ($freeze === null || $epoch === null
            || $freeze->lessThan($this->utc($epoch['authorized_at']))
            || ! $freeze->lessThan($this->utc($epoch['start_inclusive']))
            || $freeze->greaterThan(now())) return null;
        $identity = [
            ...$this->contract(),
            'prospective_paper_policy' => [
                'protocol' => self::FUTURE_PAPER_PROTOCOL,
                'authorization_id' => $epoch['authorization_id'],
                'authorization_hash' => $this->parameterHash($epoch),
                'candidate_frozen_at' => $freeze->toIso8601String(),
                'research_validation_disjoint_required' => true,
            ],
            'paper_epoch' => [
                'window_key' => $epoch['window_key'],
                'start_inclusive' => $epoch['start_inclusive'],
                'end_exclusive' => $epoch['end_exclusive'],
                'candidate_must_be_frozen_before_observation' => true,
            ],
        ];
        return [...$identity, 'epoch_contract_hash' => $this->parameterHash($identity)];
    }

    public function paperContractAuthorized(array $contract, string $frozenAt): bool
    {
        $key = (string) data_get($contract, 'paper_epoch.window_key', '');
        $expected = $this->paperContractForCandidate($key, $frozenAt);
        // Validate original finite seals without rewriting them. This does not
        // relax the separate continuous research prohibition.
        if ($expected !== null && ! array_key_exists('paper_only_policy', $contract)) {
            unset($expected['paper_only_policy']);
            if (isset($expected['epoch_contract_hash'])) {
                unset($expected['epoch_contract_hash']);
                $expected['epoch_contract_hash'] = $this->parameterHash($expected);
            }
        }
        return $expected !== null && hash_equals($this->parameterHash($expected), $this->parameterHash($contract));
    }

    /** Applied both when a paper period is sealed and when a research window is admitted. */
    public function researchIntervalDisjointFromPaper(string $start, string $end): bool
    {
        $from = $this->utc($start); $until = $this->utc($end);
        if ($from === null || $until === null || ! $until->greaterThan($from)) return false;
        // Year labels and server authorizations cannot reopen any paper data.
        return $until->lessThanOrEqualTo($this->cutoff());
    }

    /**
     * Seal the intended authority route before an experiment. This is a
     * dependency declaration, not a substitute for original untouched data.
     */
    public function confirmationRoute(string $purpose, array $sourceIdentity): array
    {
        $historical = $purpose === 'historical_causal_confirmation';
        $instrument = $purpose === 'instrument_independent_validation';
        $known = $historical || in_array($purpose, [
            'instrument_independent_validation', 'activation_independent_validation', 'academy_independent_confirmation',
        ], true);
        $identity = [
            'protocol' => self::CONFIRMATION_ROUTE_PROTOCOL,
            'purpose' => $purpose,
            'source_identity_hash' => $this->parameterHash($sourceIdentity),
            'authority_route' => $historical ? 'historical_causal_research' : 'authorized_untouched_pre_2026_independent_validation',
            'paper_only_policy' => $this->paperOnlyPolicy(),
            'research_end_exclusive' => $this->cutoff()->toIso8601String(),
            'paper_2026_research_eligible' => false,
            'historical_relabeling_independent_eligible' => false,
            'exact_frozen_control_required' => true,
            'blinded_comparator_required' => ! $instrument,
            'confirmation_owner' => $instrument ? 'InstrumentValidationEvidenceService' : 'CausalLearningConfirmationService',
            'cost_risk_parity_required' => true,
            'independence_requires_original_training_selection_provenance' => true,
            'independent_validation_status' => 'BLOCKED_DEPENDENCY',
            'reason_code' => $known ? 'NO_PREREGISTERED_UNUSED_AUTHORIZED_WINDOW' : 'UNKNOWN_CONFIRMATION_ROUTE',
            'historical_research_execution_policy' => $historical ? 'existing_frozen_causal_contract' : 'discovery_only_until_authorized_validation',
            'minimum_powered_windows' => $instrument
                ? max(3, (int) config('services.instrument_policy.minimum_independent_windows', 3))
                : (int) config('services.learning_lane.causal_minimum_powered_windows', 6),
            'minimum_positive_windows' => $instrument ? 2 : (int) config('services.learning_lane.causal_minimum_positive_windows', 4),
            'minimum_paired_context_trades' => $instrument ? 3 : (int) config('services.learning_lane.causal_minimum_trades_per_window', 8),
            'economic_or_instrument_authority_granted' => false,
            'promotion_evidence' => false,
        ];
        return [...$identity, 'route_hash' => $this->parameterHash($identity)];
    }

    private function authorizedFuturePaperEpoch(string $key): ?array
    {
        $matches = [];
        foreach ((array) config('services.research_paper_epochs.authorized_paper_epochs', []) as $manifest) {
            if (is_array($manifest) && ($manifest['window_key'] ?? null) === $key) $matches[] = $manifest;
        }
        if (count($matches) !== 1) return null;
        $epoch = $this->futurePaperManifest($matches[0]);
        if ($epoch === null || $this->utc($epoch['authorized_at'])->greaterThan(now())) return null;
        $authorizationMatches = array_filter((array) config('services.research_paper_epochs.authorized_paper_epochs', []),
            fn (mixed $row): bool => is_array($row) && ($row['authorization_id'] ?? null) === $epoch['authorization_id']);
        if (count($authorizationMatches) !== 1) return null;
        foreach ((array) config('services.instrument_policy.authorized_research_windows', []) as $window) {
            if (! is_array($window) || ($window['purpose'] ?? null) !== 'instrument_independent_validation') continue;
            $start = $this->utc($window['start_inclusive'] ?? null); $end = $this->utc($window['end_exclusive'] ?? null);
            if ($start === null || $end === null || ! $end->greaterThan($start)) return null;
            if ($start->lessThan($this->utc($epoch['end_exclusive']))
                && $end->greaterThan($this->utc($epoch['start_inclusive']))) return null;
        }
        foreach ((array) config('services.research_paper_epochs.authorized_paper_epochs', []) as $other) {
            $other = $this->futurePaperManifest($other);
            if ($other === null || $other['window_key'] === $key) continue;
            if ($this->utc($other['start_inclusive'])->lessThan($this->utc($epoch['end_exclusive']))
                && $this->utc($other['end_exclusive'])->greaterThan($this->utc($epoch['start_inclusive']))) return null;
        }
        // Removing a server authorization does not make its already-observed
        // research candles untouched again. Original pair chronology survives.
        if (Schema::hasTable('lab_learning_lane_pairs')) {
            foreach (DB::table('lab_learning_lane_pairs')->whereNotNull('independent_window_key')
                ->select(['id', 'metadata'])->lazyById(100) as $pair) {
                $window = (array) data_get(json_decode((string) $pair->metadata, true), 'instrument_research_window_receipt', []);
                if ($window === []) continue;
                $start = $this->utc($window['start_inclusive'] ?? null); $end = $this->utc($window['end_exclusive'] ?? null);
                if ($start === null || $end === null || ! $end->greaterThan($start)) return null;
                if ($start->lessThan($this->utc($epoch['end_exclusive']))
                    && $end->greaterThan($this->utc($epoch['start_inclusive']))) return null;
            }
        }
        return $epoch;
    }

    private function futurePaperManifest(mixed $manifest): ?array
    {
        if (! is_array($manifest) || ($manifest['protocol'] ?? null) !== self::FUTURE_PAPER_PROTOCOL
            || ($manifest['purpose'] ?? null) !== 'prospective_paper_forward'
            || ($manifest['approved'] ?? null) !== true
            || ($manifest['candidate_must_be_frozen_before_observation'] ?? null) !== true
            || ($manifest['research_uses_forbidden'] ?? null) !== true
            || ! is_string($manifest['authorization_id'] ?? null) || trim($manifest['authorization_id']) === ''
            || ! is_string($manifest['window_key'] ?? null) || trim($manifest['window_key']) === ''
            || $manifest['window_key'] === self::PAPER_WINDOW_KEY) return null;
        $start = $this->utc($manifest['start_inclusive'] ?? null);
        $end = $this->utc($manifest['end_exclusive'] ?? null);
        $approved = $this->utc($manifest['authorized_at'] ?? null);
        if ($start === null || $end === null || $approved === null
            || $start->lessThan($this->cutoff()->addYear()) || ! $end->greaterThan($start)
            || ! $approved->lessThan($start)) return null;
        return [
            'protocol' => self::FUTURE_PAPER_PROTOCOL, 'purpose' => 'prospective_paper_forward',
            'window_key' => $manifest['window_key'], 'authorization_id' => $manifest['authorization_id'],
            'start_inclusive' => $start->toIso8601String(), 'end_exclusive' => $end->toIso8601String(),
            'authorized_at' => $approved->toIso8601String(), 'approved' => true,
            'candidate_must_be_frozen_before_observation' => true, 'research_uses_forbidden' => true,
        ];
    }

    private function utc(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $value)) return null;
        try { return CarbonImmutable::parse($value, 'UTC')->utc(); } catch (\Throwable) { return null; }
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
        return CarbonImmutable::parse(self::PAPER_ONLY_START, 'UTC')->utc();
    }

    /** Known paper references exclude research use; absence never proves untouched provenance. */
    public function researchInputHasPaperData(array $request, array $datasetManifest = []): bool
    {
        $inputs = array_intersect_key($request, array_flip(['candles', 'regime_candles',
            'mtf_snapshot_manifest', 'mtf_streams', 'related_mtf_streams', 'mtf_dataset_tail_rows',
            'related_mtf_dataset_tail_rows', 'foundation_snapshot_manifest', 'foundation_dataset_snapshot']));
        $inputs['dataset_manifest'] = $datasetManifest;
        $clockKeys = ['time', 'date', 'datetime', 'candle_time', 'first_candle_at', 'last_candle_at',
            'first_candle_time', 'last_candle_time', 'first_time', 'last_time', 'source_first_at',
            'source_last_at', 'loaded_start', 'loaded_end', 'start_inclusive', 'end_exclusive'];
        $visit = function (array $items) use (&$visit, $clockKeys): bool {
            foreach ($items as $key => $value) {
                if (in_array($key, ['paper_only_policy', 'paper_exclusions', 'epoch_contract',
                    'research_release', 'paper_epoch', 'confirmation_route'], true)) continue;
                if (is_array($value) && $visit($value)) return true;
                if (! in_array($key, $clockKeys, true) || ! is_string($value)
                    || ! preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T].*)?$/D', $value)) continue;
                try { $time = CarbonImmutable::parse($value, 'UTC')->utc(); }
                catch (\Throwable) { continue; }
                if ($key === 'end_exclusive' ? $time->greaterThan($this->cutoff())
                    : ! $time->lessThan($this->cutoff())) return true;
            }
            return false;
        };
        return $visit($inputs);
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
