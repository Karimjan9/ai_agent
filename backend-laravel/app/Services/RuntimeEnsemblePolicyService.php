<?php

namespace App\Services;

use App\Models\ModelMarketPerformance;
use App\Models\CandidateGateDecision;
use App\Models\EliteAgentPortfolio;

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

        if ($portfolioProxy && $passportPassed && $deployableStatus && count($members) >= 3) {
            $portfolio = $this->activePortfolio($performance);
            if (! $portfolio) return $this->wait('PORTFOLIO_PASSPORT_NOT_ACTIVE');
            $sealedMembers = $this->validateSealedMembers($members, $performance, $portfolio);
            if (count($sealedMembers) !== count($members)) {
                return $this->wait('PORTFOLIO_MEMBER_PASSPORT_NOT_ACTIVE');
            }
            return $this->active($sealedMembers, 'sealed_portfolio_passport', [
                'portfolio_id' => $portfolio->id,
                'combined_passport' => true,
            ]);
        }

        // The adaptive genetic selector writes independent_members_validated=false.
        // Even if stale or hand-edited metadata says true, member passports
        // alone are not a combined replay. A multi-agent runtime contract is
        // executable only through the sealed portfolio branch above.
        $policy = (array) data_get($metadata, 'runtime_ensemble_policy', []);
        if (data_get($policy, 'independent_members_validated') === true) {
            return $this->wait('COMBINED_PORTFOLIO_PASSPORT_REQUIRED');
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
    private function activePortfolio(ModelMarketPerformance $owner): ?EliteAgentPortfolio
    {
        $portfolioId = (int) data_get(
            $owner->metrics,
            'elite_portfolio_id',
            data_get($owner->modelVersion?->metadata, 'elite_portfolio_id', 0),
        );
        if ($portfolioId <= 0) return null;
        if ((int) data_get($owner->metrics, 'portfolio_performance_id', $owner->id) !== (int) $owner->id) {
            return null;
        }

        $portfolio = EliteAgentPortfolio::query()->with('members.performance.modelVersion')
            ->whereKey($portfolioId)
            ->where('symbol', $owner->symbol)
            ->where('timeframe', $owner->timeframe)
            ->where('gate_status', 'passed')
            ->whereIn('status', ['forward_validated', 'paper'])
            ->first();
        if (! $portfolio || data_get($portfolio->evidence, 'gate.status') !== 'passed') return null;
        if ((int) data_get($portfolio->evidence, 'portfolio_performance_id', 0) !== (int) $owner->id) return null;

        $forward = CandidateGateDecision::query()
            ->where('model_market_performance_id', $owner->id)
            ->where('stage', 'statistical_forward_gate')
            ->latest('evaluated_at')
            ->first();
        return $forward?->decision === 'passed'
            && data_get($forward->metrics, 'portfolio_forward_identity.attribution_status') === 'portfolio_sealed'
            ? $portfolio
            : null;
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
    private function validateSealedMembers(
        array $members,
        ModelMarketPerformance $owner,
        EliteAgentPortfolio $portfolio,
    ): array
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

        $portfolioMembers = $portfolio->members->keyBy('model_market_performance_id');
        if ($portfolioMembers->count() !== count($members)) return [];

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
            $portfolioMember = $portfolioMembers->get((int) ($matches[1] ?? 0));
            if (! $performance || ! $model || ! $portfolioMember || ! $this->hasIndependentPassport($performance)) return [];
            if ((string) data_get($member, 'strategy') !== (string) $model->strategy
                || (filled(data_get($member, 'version')) && (string) data_get($member, 'version') !== (string) $model->version)
                || $this->parameterHash((array) data_get($member, 'parameters', []))
                    !== $this->parameterHash((array) ($model->parameters ?? []))
                || (string) data_get($member, 'role') !== (string) $portfolioMember->role
                || data_get($member, 'target_regime') !== $portfolioMember->target_regime
                || data_get($member, 'target_volatility') !== $portfolioMember->target_volatility
                || data_get($member, 'target_direction') !== $portfolioMember->target_direction
                || $this->sealedParameterHash($model) !== (string) $portfolioMember->parameter_hash) {
                return [];
            }
            $validated[] = $member;
        }
        return $validated;
    }

    private function sealedParameterHash(mixed $model): string
    {
        return (string) (data_get($model?->metadata, 'parameter_fingerprint')
            ?: $this->parameterHash((array) ($model?->parameters ?? [])));
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
