<?php

namespace App\Services;

use App\Models\LabEvaluationRun;
use App\Models\LabLearningLanePair;
use Illuminate\Support\Arr;

/** Validation has its own exact-delta epoch; discovery is never retro-authorized. */
class InstrumentValidationEvidenceService
{
    public const PROTOCOL = 'instrument_validation_epoch_v2';

    public const DELTA_PROTOCOL = 'instrument_tested_delta_v1';

    public const SOURCE_PROTOCOL = 'instrument_pair_source_v1';

    public function sealDelta(string $gene, mixed $old, mixed $new, string $baselineHash, string $evaluatorHash, array $state): ?array
    {
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $gene) || ! $this->hashValid($baselineHash)
            || ! $this->hashValid($evaluatorHash) || ($state['state_key'] ?? '') === ''
            || in_array((string) ($state['strategy_family'] ?? ''), ['', 'unscoped'], true)
            || $this->hash(['value' => $old]) === $this->hash(['value' => $new])) {
            return null;
        }
        $identity = ['protocol' => self::DELTA_PROTOCOL, 'gene' => $gene, 'old' => $old, 'new' => $new,
            'baseline_parameter_hash' => $baselineHash, 'evaluator_hash' => $evaluatorHash,
            'strategy_family' => $state['strategy_family'], 'state_key' => $state['state_key'],
            'context' => $this->context($state)];

        return [...$identity, 'intervention_hash' => $this->hash($identity)];
    }

    public function sealSource(array $identity): array
    {
        unset($identity['source_receipt_hash']);

        return [...$identity, 'source_receipt_hash' => $this->hash($identity)];
    }

    /** Only newly written, fully attested validation observations enter this scope. */
    public function append(array $epochs, array $outcome, array $state, array $vector): array
    {
        $delta = (array) ($outcome['tested_intervention'] ?? []);
        $source = (array) ($outcome['source_receipt'] ?? []);
        $window = (array) ($outcome['instrument_research_window_receipt'] ?? []);
        $key = (string) ($outcome['evidence_key'] ?? '');
        if ($key === '' || ! $this->deltaValid($delta, (string) $state['state_key'])
            || ! $this->sourceValid($source, $delta, $window)
            || ! app(InstrumentResearchWindowService::class)->authorized($window, (string) data_get($outcome, 'control_contract.data_hash', ''))) {
            return $epochs;
        }
        $epochId = (string) $window['research_epoch_id'];
        $epochKey = $this->hash([self::PROTOCOL, $epochId, $delta['intervention_hash']]);
        $epoch = (array) ($epochs[$epochKey] ?? []);
        $evidence = (array) ($epoch['window_evidence'] ?? []);
        if (in_array($key, array_column($evidence, 'evidence_key'), true)) {
            return $epochs;
        }
        $utility = (float) $vector['conditional_net_utility'];
        if (! is_finite($utility)) {
            return $epochs;
        }
        $evidence[] = ['window' => $window, 'evidence_key' => $key,
            'outcome' => $utility > 0 ? 'positive' : ($utility < 0 ? 'negative' : 'neutral'),
            'net_utility' => $utility,
            'utility_uncertainty' => (float) ($vector['utility_uncertainty'] ?? 0),
            'non_target_regression' => (float) ($vector['non_target_regression'] ?? 0) > 0,
            'source_receipt' => $source];
        $epochs[$epochKey] = ['protocol' => self::PROTOCOL, 'epoch_key' => $epochKey,
            'research_epoch_id' => $epochId, 'tested_intervention' => $delta,
            ...$this->statistics($evidence), 'window_evidence' => $evidence];

        return $epochs;
    }

    /** Re-derive every fact, including current server authorization and exact delta. */
    public function assessEpoch(array $epoch, string $stateKey): array
    {
        $delta = (array) ($epoch['tested_intervention'] ?? []);
        $evidence = (array) ($epoch['window_evidence'] ?? []);
        $proof = app(InstrumentResearchWindowService::class)->analyze($evidence);
        $stats = $this->statistics($evidence);
        $identityValid = ($epoch['protocol'] ?? null) === self::PROTOCOL
            && $this->deltaValid($delta, $stateKey)
            && hash_equals((string) ($epoch['epoch_key'] ?? ''), $this->hash([
                self::PROTOCOL, (string) ($epoch['research_epoch_id'] ?? ''), (string) ($delta['intervention_hash'] ?? ''),
            ]));
        $coverage = $evidence !== [] && $proof['valid'] && $identityValid;
        foreach ($evidence as $row) {
            if (! is_array($row)) {
                $coverage = false;

                continue;
            }
            $window = (array) ($row['window'] ?? []);
            $value = $row['net_utility'] ?? null;
            $coverage = $coverage && is_numeric($value) && is_finite((float) $value)
                && ($row['outcome'] ?? null) === ((float) $value > 0 ? 'positive' : ((float) $value < 0 ? 'negative' : 'neutral'))
                && ($window['research_epoch_id'] ?? null) === ($epoch['research_epoch_id'] ?? null)
                && app(InstrumentResearchWindowService::class)->authorized($window, (string) ($window['dataset_sha256'] ?? ''))
                && $this->sourceValid((array) ($row['source_receipt'] ?? []), $delta, $window)
                && is_bool($row['non_target_regression'] ?? null);
        }
        foreach (['observations', 'positive_observations', 'negative_observations', 'non_target_regression_count', 'evidence_keys'] as $field) {
            $coverage = $coverage && ($epoch[$field] ?? null) === $stats[$field];
        }
        foreach (['net_value', 'uncertainty', 'utility_m2'] as $field) {
            $coverage = $coverage && isset($epoch[$field]) && is_numeric($epoch[$field])
                && is_finite((float) $epoch[$field]) && abs((float) $epoch[$field] - $stats[$field]) < 1e-10;
        }
        $checks = ['valid_context' => data_get(app(ContextContractV2Service::class)->project((array) ($delta['context'] ?? [])), 'status') === 'valid',
            'family_sealed' => ! in_array((string) ($delta['strategy_family'] ?? ''), ['', 'unscoped'], true),
            'state_identity_valid' => $identityValid,
            'observation_count_valid' => $stats['observations'] >= max(3, (int) config('services.instrument_policy.minimum_posterior_observations', 3)),
            'evidence_identity_coverage' => $coverage, 'sealed_window_evidence_complete' => $coverage,
            'non_target_safe' => $stats['non_target_regression_count'] === 0];
        $common = ! in_array(false, $checks, true);
        $confirmed = $common && $proof['windows'] >= max(3, (int) config('services.instrument_policy.minimum_independent_windows', 3))
            && $proof['positive_windows'] >= 2 && $stats['positive_observations'] >= 2
            && $stats['net_value'] >= max(.00001, (float) config('services.instrument_policy.minimum_confirmed_net_utility', .001));
        $forbidden = $coverage && $checks['valid_context'] && $checks['observation_count_valid']
            && $proof['windows'] >= 2 && $proof['negative_windows'] >= 2 && $stats['negative_observations'] >= 2
            && $stats['net_value'] <= min(-.00001, (float) config('services.instrument_policy.minimum_forbidden_net_utility', -.001));

        return ['canonical_state' => $confirmed ? 'confirmed' : ($forbidden ? 'forbidden' : 'provisional'),
            'verified_confirmed' => $confirmed, 'verified_forbidden' => $forbidden,
            'epoch_key' => $epoch['epoch_key'] ?? null, 'research_epoch_id' => $epoch['research_epoch_id'] ?? null,
            'tested_intervention' => $delta, 'strategy_family' => $delta['strategy_family'] ?? null,
            ...$stats, 'evidence_keys' => count($stats['evidence_keys']),
            'independent_windows' => $coverage ? $proof['windows'] : 0,
            'positive_independent_windows' => $coverage ? $proof['positive_windows'] : 0,
            'negative_independent_windows' => $coverage ? $proof['negative_windows'] : 0,
            'non_target_regressions' => $stats['non_target_regression_count'],
            'window_evidence' => $evidence, 'checks' => $checks, 'promotion_evidence' => false];
    }

    public function deltaValid(array $delta, string $stateKey): bool
    {
        if (($delta['protocol'] ?? null) !== self::DELTA_PROTOCOL || ! array_key_exists('old', $delta) || ! array_key_exists('new', $delta)) {
            return false;
        }
        $sealed = $this->sealDelta((string) ($delta['gene'] ?? ''), $delta['old'], $delta['new'],
            (string) ($delta['baseline_parameter_hash'] ?? ''), (string) ($delta['evaluator_hash'] ?? ''),
            [...(array) ($delta['context'] ?? []), 'strategy_family' => $delta['strategy_family'] ?? '', 'state_key' => $stateKey]);

        [$regime, $session, $volatility, $spread, $transition, $loss, $direction, $family, $phase] = array_pad(explode('|', $stateKey), 9, null);
        $expected = app(ContextContractV2Service::class)->canonicalAxes(['regime' => $regime, 'session' => $session,
            'volatility' => $volatility, 'spread_state' => $spread, 'transition' => $transition, 'direction' => $direction, 'venue_phase' => $phase]);
        $actual = app(ContextContractV2Service::class)->canonicalAxes((array) ($delta['context'] ?? []));
        foreach (['regime', 'session', 'volatility', 'spread_liquidity_state', 'transition_state', 'direction', 'venue_phase'] as $axis) {
            if (($expected[$axis] ?? null) !== ($actual[$axis] ?? null)) {
                return false;
            }
        }

        return $sealed !== null && $this->hash($sealed) === $this->hash($delta)
            && $family === ($delta['strategy_family'] ?? null)
            && (int) $loss === (int) data_get($delta, 'context.loss_streak', 0);
    }

    private function sourceValid(array $source, array $delta, array $window): bool
    {
        if (($source['protocol'] ?? null) !== self::SOURCE_PROTOCOL || ! $this->hashValid((string) ($source['source_receipt_hash'] ?? ''))
            || $this->hash($this->sealSource($source)) !== $this->hash($source)
            || ($source['control_parameter_hash'] ?? null) !== ($delta['baseline_parameter_hash'] ?? null)
            || ($source['evaluator_hash'] ?? null) !== ($delta['evaluator_hash'] ?? null)
            || ($source['data_hash'] ?? null) !== ($window['dataset_sha256'] ?? null)) {
            return false;
        }
        foreach (['pair_key', 'execution_hash', 'candidate_parameter_hash', 'control_parameter_hash', 'evaluator_hash', 'data_hash'] as $field) {
            if (! $this->hashValid((string) ($source[$field] ?? ''))) {
                return false;
            }
        }
        foreach (['candidate_agent_id', 'control_agent_id', 'candidate_model_version_id', 'control_model_version_id',
            'candidate_response_map_id', 'control_response_map_id', 'candidate_evidence_run_id', 'control_evidence_run_id'] as $field) {
            if (! is_int($source[$field] ?? null) || $source[$field] <= 0) {
                return false;
            }
        }

        if ($source['candidate_agent_id'] === $source['control_agent_id']
            || $source['candidate_parameter_hash'] === $source['control_parameter_hash']) {
            return false;
        }
        $pair = LabLearningLanePair::query()->with(['candidateAgent.modelVersion', 'controlAgent.modelVersion',
            'candidateResponseMap', 'controlResponseMap'])->where('pair_key', $source['pair_key'])->first();
        if ($pair === null || ! $pair->isVerifiedControlPair()
            || (string) $pair->candidate_data_hash !== $source['data_hash']
            || (string) $pair->candidate_execution_hash !== $source['execution_hash']
            || (string) $pair->independent_window_key !== (string) ($window['window_key'] ?? '')
            || $this->hash((array) data_get($pair->metadata, 'instrument_research_window_receipt', [])) !== $this->hash($window)) {
            return false;
        }
        $gene = (string) $delta['gene'];
        $diff = (array) $pair->candidateAgent->parameter_diff;
        if (count($diff) !== 1 || ! isset($diff[$gene])
            || $this->hash(\Illuminate\Support\Arr::only((array) $diff[$gene], ['old', 'new'])) !== $this->hash(['old' => $delta['old'], 'new' => $delta['new']])
            || (string) $pair->strategy_family !== (string) $delta['strategy_family']) {
            return false;
        }
        foreach (['candidate', 'control'] as $arm) {
            $agent = $arm === 'candidate' ? $pair->candidateAgent : $pair->controlAgent;
            $map = $arm === 'candidate' ? $pair->candidateResponseMap : $pair->controlResponseMap;
            $run = LabEvaluationRun::query()->find($source[$arm.'_evidence_run_id']);
            $wrappedIdentity = $run ? app(LabImmutableEvidenceService::class)->verifiedModelRuntimeIdentity($run) : null;
            $runParameterValid = $run && ((string) $run->parameter_hash === $source[$arm.'_parameter_hash']
                || ($wrappedIdentity !== null
                    && ($wrappedIdentity['parameter_hash'] ?? null) === $source[$arm.'_parameter_hash']
                    && ($wrappedIdentity['evidence_parameter_hash'] ?? null) === (string) $run->parameter_hash));
            if ($source[$arm.'_agent_id'] !== (int) $agent->id
                || $source[$arm.'_model_version_id'] !== (int) $agent->model_version_id
                || $source[$arm.'_response_map_id'] !== (int) $pair->{$arm.'_response_map_id'}
                || $map === null || (int) $map->lab_agent_id !== (int) $agent->id
                || (int) $map->model_version_id !== (int) $agent->model_version_id
                || (string) $map->evidence_run_id !== (string) $pair->{$arm.'_evidence_run_id'}
                || $source[$arm.'_parameter_hash'] !== $this->hash((array) $agent->modelVersion->parameters)
                || $run === null || $run->status !== 'completed'
                || (string) $run->run_id !== (string) $pair->{$arm.'_evidence_run_id'}
                || (int) $run->lab_agent_id !== (int) $agent->id || (int) $run->model_version_id !== (int) $agent->model_version_id
                || (int) $run->lab_generation_id !== (int) $pair->lab_generation_id
                || ! $runParameterValid
                || (string) $run->data_hash !== $source['data_hash'] || (string) $run->code_hash !== $source['evaluator_hash']) {
                return false;
            }
            foreach (['request_hash', 'response_hash'] as $field) {
                if (! $this->hashValid((string) $run->{$field})
                    || (string) $run->{$field} !== (string) ($source[$arm.'_'.$field] ?? '')) {
                    return false;
                }
            }
            $trace = (array) data_get($pair->{$arm.'_metrics'}, 'instrument_research_trace', []);
            $expectedAxes = app(ContextContractV2Service::class)->canonicalAxes((array) $delta['context']);
            $poweredScope = false;
            if (($trace['context_source'] ?? null) === 'decision_time_trade_ledger'
                && ($trace['context_slice_protocol'] ?? null) === 'venue_phase_v1') {
                foreach ((array) ($trace['exact_context_slices'] ?? []) as $slice) {
                    if (! is_array($slice) || ($slice['powered'] ?? null) !== true
                        || (int) data_get($slice, 'metrics.trades', 0) < 3) {
                        continue;
                    }
                    $actualAxes = app(ContextContractV2Service::class)->canonicalAxes((array) ($slice['context'] ?? []));
                    $matched = true;
                    foreach (['regime', 'volatility', 'session', 'venue_phase', 'direction'] as $axis) {
                        if (! isset($expectedAxes[$axis], $actualAxes[$axis]) || $expectedAxes[$axis] !== $actualAxes[$axis]) {
                            $matched = false;
                        }
                    }
                    if ($matched) {
                        $poweredScope = true;
                        break;
                    }
                }
            }
            if (! $poweredScope) {
                return false;
            }
        }

        return true;
    }

    private function statistics(array $rows): array
    {
        $n = 0;
        $mean = 0.0;
        $m2 = 0.0;
        $positive = 0;
        $negative = 0;
        $regressions = 0;
        $uncertainty = 0.0;
        $keys = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $value = (float) ($row['net_utility'] ?? 0);
            $n++;
            $delta = $value - $mean;
            $mean += $delta / $n;
            $m2 += $delta * ($value - $mean);
            $positive += $value > 0 ? 1 : 0;
            $negative += $value < 0 ? 1 : 0;
            $regressions += ($row['non_target_regression'] ?? false) ? 1 : 0;
            $uncertainty = max($uncertainty, (float) ($row['utility_uncertainty'] ?? 0));
            $keys[] = (string) ($row['evidence_key'] ?? '');
        }

        return ['observations' => $n, 'net_value' => $mean, 'utility_m2' => $m2,
            'uncertainty' => max(.002, $uncertainty, $n > 1 ? sqrt(max(0, $m2 / ($n - 1)) / $n) : max(.01, abs($mean))),
            'positive_observations' => $positive, 'negative_observations' => $negative,
            'non_target_regression_count' => $regressions, 'evidence_keys' => $keys];
    }

    private function context(array $state): array
    {
        return Arr::only($state, ['regime', 'session', 'volatility', 'spread_state',
            'transition', 'loss_streak', 'direction', 'strategy_family', 'venue_phase', 'state_key']);
    }

    private function hash(array $value): string
    {
        return app(ResearchPaperEpochContractService::class)->parameterHash($value);
    }

    private function hashValid(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    }
}
