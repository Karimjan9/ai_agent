<?php

namespace App\Services;

use App\Models\LabCausalControlPlan;
use App\Models\LabEvaluationRun;
use App\Models\LabLearningLanePair;
use Illuminate\Support\Facades\Schema;

/**
 * Plans exact control-first cohorts without manufacturing a causal pair.
 *
 * A plan is deliberately not a replay or a promotion decision. It records the
 * immutable contract a future control must satisfy; dispatch code may only
 * attach a control after the contract is verified again.
 */
class CausalControlCohortPlanner
{
    public const PROTOCOL = 'causal_control_first_cohort_v1';

    /** @return array<string, mixed> */
    public function plan(
        string $symbol,
        string $timeframe,
        ?string $family = null,
        int $limit = 50,
        bool $apply = false,
    ): array {
        if (! Schema::hasTable('lab_causal_control_plans')) {
            return ['available' => false, 'planned' => 0, 'blocked' => 0, 'groups' => []];
        }

        $pairs = LabLearningLanePair::query()
            ->with(['candidateResponseMap', 'candidateAgent.modelVersion', 'generation'])
            ->where('symbol', strtoupper($symbol))
            ->where('timeframe', strtoupper($timeframe))
            ->where('status', 'missing_control')
            ->when($family, fn ($query) => $query->where('strategy_family', $family))
            ->latest('id')
            ->limit(max(1, min(500, $limit)))
            ->get();

        $planned = 0;
        $blocked = 0;
        $groups = [];
        foreach ($pairs as $pair) {
            $contract = $this->contract($pair);
            $key = $this->planKey($pair, $contract);
            // A control is shared by every candidate with the same immutable
            // contract. The per-pair plan key remains unique for auditability,
            // while this group key defines the actual control-first cohort.
            $groupKey = $this->contractGroupKey($pair, $contract);
            $groups[$groupKey] ??= [
                'plan_key_prefix' => $groupKey,
                'candidate_pair_ids' => [],
                'status' => 'planned',
                'contract' => $contract,
            ];
            $groups[$groupKey]['candidate_pair_ids'][] = (int) $pair->id;

            if ($contract['status'] !== 'ready') {
                $blocked++;
                $groups[$groupKey]['status'] = 'blocked';
                continue;
            }
            $planned++;
            if (! $apply) continue;

            LabCausalControlPlan::query()->firstOrCreate(
                ['plan_key' => $key],
                [
                    'pair_id' => $pair->id,
                    'candidate_response_map_id' => $pair->candidate_response_map_id,
                    'symbol' => strtoupper($symbol),
                    'timeframe' => strtoupper($timeframe),
                    'strategy_family' => (string) $pair->strategy_family,
                    'target' => $pair->target,
                    'specialist_role' => $pair->specialist_role,
                    'dataset_hash' => $contract['dataset_hash'],
                    'execution_hash' => $contract['execution_hash'],
                    'temporal_window_key' => $contract['temporal_window_key'],
                    'status' => 'planned',
                    'contract' => $contract,
                    'metadata' => [
                        'protocol' => self::PROTOCOL,
                        'promotion_evidence' => false,
                        'requires_control_replay' => true,
                    ],
                    'promotion_evidence' => false,
                ],
            );
        }

        return [
            'available' => true,
            'protocol' => self::PROTOCOL,
            'symbol' => strtoupper($symbol),
            'timeframe' => strtoupper($timeframe),
            'limit' => max(1, min(500, $limit)),
            'apply' => $apply,
            'inspected' => $pairs->count(),
            'planned' => $planned,
            'blocked' => $blocked,
            'groups' => array_values($groups),
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string, string> */
    private function contract(LabLearningLanePair $pair): array
    {
        $mapMeta = (array) $pair->candidateResponseMap?->metadata;
        $mapMetrics = (array) $pair->candidateResponseMap?->observed_metrics;
        $modelMeta = (array) $pair->candidateAgent?->modelVersion?->metadata;
        $generationMeta = (array) $pair->generation?->trigger_context;
        $runMeta = $pair->candidateResponseMap?->evidence_run_id !== null
            ? (array) LabEvaluationRun::query()->where('run_id', $pair->candidateResponseMap->evidence_run_id)->first()?->request_meta
            : [];
        $datasetHash = (string) (
            data_get($mapMeta, 'data_manifest.sha256')
            ?: data_get($mapMeta, 'data_manifest.snapshot_sha256')
            ?: data_get($mapMeta, 'data_manifest_hash')
            ?: data_get($mapMetrics, 'data_manifest.sha256')
            ?: data_get($mapMetrics, 'data_manifest.snapshot_sha256')
            ?: data_get($mapMetrics, 'data_manifest_hash')
            ?: data_get($runMeta, 'dataset_manifest.snapshot_sha256')
            ?: data_get($runMeta, 'dataset_manifest.data_hash')
            ?: data_get($runMeta, 'dataset_hash')
            ?: data_get($modelMeta, 'data_manifest.sha256')
            ?: data_get($generationMeta, 'data_manifest.sha256')
            ?: $pair->generation?->data_fingerprint
        );
        $executionHash = (string) (
            data_get($mapMeta, 'execution_contract_hash')
            ?: data_get($mapMeta, 'execution_hash')
            ?: data_get($mapMeta, 'mutation_observability.execution_contract_hash')
            ?: data_get($mapMetrics, 'execution_contract.execution_hash')
            ?: data_get($mapMetrics, 'execution_hash')
            ?: data_get($runMeta, 'payload.execution_contract.execution_hash')
            ?: data_get($runMeta, 'execution_contract.execution_hash')
            ?: data_get($modelMeta, 'execution_contract_hash')
        );
        $window = (string) (
            $pair->independent_window_key
            ?: $pair->candidateResponseMap?->temporal_window_key
            ?: data_get($mapMeta, 'temporal_window_key')
            ?: data_get($mapMeta, 'window_key')
            ?: data_get($mapMeta, 'temporal_window.key')
            ?: data_get($mapMetrics, 'temporal_window_key')
            ?: data_get($generationMeta, 'temporal_window_key')
            ?: ''
        );
        $missing = [];
        if ($datasetHash === '') $missing[] = 'dataset_hash';
        if ($executionHash === '') $missing[] = 'execution_hash';
        if ($window === '') $missing[] = 'temporal_window_key';

        return [
            'status' => $missing === [] ? 'ready' : 'blocked',
            'missing' => $missing,
            'dataset_hash' => $datasetHash,
            'execution_hash' => $executionHash,
            'temporal_window_key' => $window,
            'target' => (string) ($pair->target ?: $pair->candidateResponseMap?->target ?: ''),
            'specialist_role' => (string) ($pair->specialist_role ?: data_get($modelMeta, 'specialist_council_membership.member_role', '')),
            'same_snapshot_required' => true,
            'same_execution_contract_required' => true,
            'parent_or_anchor_as_control' => false,
        ];
    }

    private function planKey(LabLearningLanePair $pair, array $contract): string
    {
        return hash('sha256', json_encode([
            self::PROTOCOL,
            $pair->id,
            $pair->candidate_response_map_id,
            $pair->strategy_family,
            $contract['dataset_hash'],
            $contract['execution_hash'],
            $contract['temporal_window_key'],
            $contract['target'],
            $contract['specialist_role'],
        ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function contractGroupKey(LabLearningLanePair $pair, array $contract): string
    {
        return substr(hash('sha256', json_encode([
            self::PROTOCOL,
            $pair->symbol,
            $pair->timeframe,
            $pair->strategy_family,
            $contract['dataset_hash'],
            $contract['execution_hash'],
            $contract['temporal_window_key'],
            $contract['target'],
            $contract['specialist_role'],
        ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)), 0, 24);
    }
}
