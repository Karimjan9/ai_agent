<?php

namespace App\Services;

use App\Models\ModelMarketPerformance;
use App\Models\CandidateGateDecision;

/**
 * Converts a sealed portfolio passport into the executable portfolio_members
 * payload. Genetic parent IDs are intentionally not accepted as runtime
 * members: a parent is a research hypothesis until its own independent
 * forward/passport evidence and the combined portfolio passport exist.
 */
class RuntimeEnsemblePolicyService
{
    public const PROTOCOL = 'runtime_ensemble_activation_v1';

    public function __construct(private StrategyParameterSchemaService $schemas)
    {
    }

    /** @return array<string, mixed> */
    public function forPerformance(ModelMarketPerformance $performance): array
    {
        $performance->loadMissing('modelVersion');
        $model = $performance->modelVersion;
        if (! $model) return $this->wait('MODEL_VERSION_MISSING');

        $metadata = (array) ($model->metadata ?? []);
        $members = array_values(array_filter((array) data_get($metadata, 'portfolio_members', []), 'is_array'));
        $passportPassed = data_get($performance->metrics, 'elite_agent_passport.status') === 'passed';
        $portfolioProxy = (bool) data_get($performance->metrics, 'portfolio_proxy', data_get($metadata, 'portfolio_proxy', false));
        $deployableStatus = in_array((string) $performance->status, ['forward_validated', 'paper'], true)
            && $performance->evidence_status === 'valid'
            && $model->evidence_status === 'valid';

        if ($portfolioProxy && $passportPassed && $deployableStatus && count($members) >= 2) {
            $sealedMembers = $this->validateSealedMembers($members, $performance);
            if (count($sealedMembers) !== count($members)) {
                return $this->wait('PORTFOLIO_MEMBER_PASSPORT_NOT_ACTIVE');
            }
            return $this->active($sealedMembers, 'sealed_portfolio_passport', [
                'portfolio_id' => data_get($metadata, 'elite_portfolio_id', data_get($performance->metrics, 'elite_portfolio_id')),
                'combined_passport' => true,
            ]);
        }

        // This branch is deliberately opt-in. The adaptive genetic selector
        // writes independent_members_validated=false, so raw contributors
        // cannot leak into paper/runtime by merely appearing in metadata.
        $policy = (array) data_get($metadata, 'runtime_ensemble_policy', []);
        if (data_get($policy, 'independent_members_validated') === true) {
            $resolved = $this->resolveValidatedMembers(
                (array) data_get($policy, 'member_model_version_ids', []),
                $performance,
            );
            if (count($resolved) >= max(2, (int) data_get($policy, 'minimum_independent_members', 2))) {
                return $this->active($resolved, 'independent_specialist_passports', [
                    'policy_protocol' => data_get($policy, 'protocol'),
                    'combined_passport' => true,
                ]);
            }
        }

        return $this->wait($portfolioProxy ? 'PORTFOLIO_PASSPORT_NOT_ACTIVE' : 'GENETIC_PARENTS_NOT_RUNTIME_MEMBERS');
    }

    /** @return array<string, mixed> */
    public function requestPayload(ModelMarketPerformance $performance): array
    {
        $policy = $this->forPerformance($performance);
        if (data_get($policy, 'status') !== 'active') {
            return [
                'portfolio_members' => [],
                'runtime_ensemble_policy' => $policy,
                'runtime_action' => 'WAIT',
            ];
        }

        $model = $performance->modelVersion;
        return [
            'portfolio_members' => (array) data_get($policy, 'members', []),
            'parameters' => (array) data_get($model?->metadata, 'portfolio_parameters', $model?->parameters ?? []),
            'runtime_ensemble_policy' => $policy,
            'runtime_action' => 'ROUTE',
        ];
    }

    /** @param array<int, mixed> $modelIds */
    private function resolveValidatedMembers(array $modelIds, ModelMarketPerformance $owner): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $modelIds), fn (int $id): bool => $id > 0)));
        if ($ids === []) return [];

        return ModelMarketPerformance::with('modelVersion')
            ->whereIn('model_version_id', $ids)
            ->where('symbol', $owner->symbol)
            ->where('timeframe', $owner->timeframe)
            ->whereIn('status', ['forward_validated', 'paper'])
            ->where('evidence_status', 'valid')
            ->get()
            ->filter(fn (ModelMarketPerformance $member): bool => $this->hasIndependentPassport($member))
            ->map(function (ModelMarketPerformance $member): array {
                $model = $member->modelVersion;
                $contract = (array) data_get($model?->metadata, 'portfolio_research_contract', []);
                return [
                    'strategy' => $model?->strategy,
                    'base_strategy' => $model?->strategy
                        ? $this->schemas->runtimeBaseStrategy($model->strategy, data_get($model->metadata, 'base_strategy'), $member->strategy_family)
                        : null,
                    'version' => $model?->version,
                    'parameters' => $model?->parameters ?? [],
                    'member_key' => 'performance:'.$member->id,
                    'role' => data_get($contract, 'role', data_get($model?->metadata, 'council_specialist_contract.role', 'specialist')),
                    'target_regime' => data_get($contract, 'target_regime'),
                    'target_volatility' => data_get($contract, 'target_volatility'),
                    'target_direction' => data_get($contract, 'target_direction'),
                ];
            })
            ->filter(fn (array $member): bool => filled($member['strategy']))
            ->values()->all();
    }

    /**
     * A combined passport is not enough to activate arbitrary metadata. The
     * sealed portfolio member key must resolve to the same independent
     * forward/paper performance row, and that row must have its own passed
     * statistical passport. This prevents stale members or raw genetic IDs
     * from entering paper/holdout through an old portfolio proxy record.
     *
     * @param array<int, array<string, mixed>> $members
     * @return array<int, array<string, mixed>>
     */
    private function validateSealedMembers(array $members, ModelMarketPerformance $owner): array
    {
        $performanceIds = [];
        foreach ($members as $member) {
            if (! is_array($member)
                || ! preg_match('/^performance:(\d+)$/', (string) data_get($member, 'member_key'), $matches)) {
                return [];
            }
            $performanceIds[] = (int) $matches[1];
        }
        $performanceIds = array_values(array_unique($performanceIds));
        if (count($performanceIds) !== count($members)) return [];

        $performances = ModelMarketPerformance::with('modelVersion')
            ->whereIn('id', $performanceIds)
            ->where('symbol', $owner->symbol)
            ->where('timeframe', $owner->timeframe)
            ->whereIn('status', ['forward_validated', 'paper'])
            ->where('evidence_status', 'valid')
            ->get()
            ->keyBy('id');

        $validated = [];
        foreach ($members as $member) {
            preg_match('/^performance:(\d+)$/', (string) data_get($member, 'member_key'), $matches);
            $performance = $performances->get((int) ($matches[1] ?? 0));
            $model = $performance?->modelVersion;
            if (! $performance || ! $model || ! $this->hasIndependentPassport($performance)) return [];
            if ((string) data_get($member, 'strategy') !== (string) $model->strategy
                || (filled(data_get($member, 'version')) && (string) data_get($member, 'version') !== (string) $model->version)
                || $this->parameterHash((array) data_get($member, 'parameters', []))
                    !== $this->parameterHash((array) ($model->parameters ?? []))) {
                return [];
            }
            $validated[] = $member;
        }
        return $validated;
    }

    private function hasIndependentPassport(ModelMarketPerformance $performance): bool
    {
        if ($performance->modelVersion?->evidence_status !== 'valid'
            || ! in_array((string) $performance->status, ['forward_validated', 'paper'], true)
            || $performance->evidence_status !== 'valid') {
            return false;
        }
        $decision = CandidateGateDecision::query()
            ->where('model_market_performance_id', $performance->id)
            ->where('stage', 'statistical_forward_gate')
            ->latest('evaluated_at')
            ->first();
        return $decision?->decision === 'passed'
            && data_get($decision->metrics, 'elite_agent_passport.status') === 'passed';
    }

    private function parameterHash(array $parameters): string
    {
        $normalize = function (array $value) use (&$normalize): array {
            foreach ($value as $key => $item) {
                if (is_array($item)) $value[$key] = $normalize($item);
            }
            ksort($value);
            return $value;
        };
        return hash('sha256', json_encode($normalize($parameters), JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<int, array<string, mixed>> $members */
    private function active(array $members, string $source, array $extra = []): array
    {
        return [
            'protocol' => self::PROTOCOL,
            'status' => 'active',
            'source' => $source,
            'members' => $members,
            'member_count' => count($members),
            'unknown_regime_action' => 'WAIT',
            'specialist_disagreement_action' => 'WAIT',
            'missing_member_action' => 'WAIT',
            'paper_and_holdout_required' => true,
            'promotion_evidence' => false,
            ...$extra,
        ];
    }

    /** @return array<string, mixed> */
    private function wait(string $reason): array
    {
        return [
            'protocol' => self::PROTOCOL,
            'status' => 'waiting',
            'action' => 'WAIT',
            'reason' => $reason,
            'members' => [],
            'member_count' => 0,
            'unknown_regime_action' => 'WAIT',
            'specialist_disagreement_action' => 'WAIT',
            'missing_member_action' => 'WAIT',
            'paper_and_holdout_required' => true,
            'promotion_evidence' => false,
        ];
    }
}
