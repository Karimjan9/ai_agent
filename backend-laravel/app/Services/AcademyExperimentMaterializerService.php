<?php

namespace App\Services;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Materializes an Academy contract through the existing full-replay lane. */
class AcademyExperimentMaterializerService
{
    public const PROTOCOL = 'academy_foundry_materializer_v1';

    public function __construct(
        private AcademyExperimentContractCompilerService $compiler,
        private ResearchExperimentConversionKernelService $conversion,
        private CausalCompoundingKernelService $compoundingKernel,
    ) {}

    public function materialize(int $trialId, int $baselineModelVersionId, array $identity, bool $apply = false): array
    {
        $trial = DB::table('edge_academy_trials')->find($trialId);
        $passport = $trial ? DB::table('edge_academy_passports')->find($trial->edge_academy_passport_id) : null;
        $baseline = ModelVersion::query()->find($baselineModelVersionId);
        $baselineAgent = LabAgent::query()->with('generation.laboratory')->where('model_version_id', $baselineModelVersionId)->latest('id')->first();
        if (! $trial || ! $passport || ! $baseline || ! $baselineAgent?->generation?->laboratory) {
            return $this->blocked('ACADEMY_TRIAL_BASELINE_AND_LAB_REQUIRED');
        }
        if ($trial->settled_at !== null) {
            return $this->blocked('SETTLED_ACADEMY_TRIAL_CANNOT_BE_MATERIALIZED');
        }
        if (($identity['pre_2026_only'] ?? false) !== true || ! filled($identity['data_hash'] ?? null)
            || ! filled($identity['execution_hash'] ?? null) || ! is_array($identity['canonical_dataset_snapshots'] ?? null)) {
            return $this->blocked('PRE2026_DATA_EXECUTION_AND_SNAPSHOT_CONTRACT_REQUIRED');
        }
        $compiled = $this->compiler->compile([
            'axis' => data_get(json_decode((string) $trial->outcome, true), 'axis', null) ?: $this->axisFromArms($trial->arms),
            'arms' => json_decode((string) $trial->arms, true) ?: [],
        ], (array) $baseline->parameters, ['symbol' => strtoupper($passport->symbol), 'laboratory_timeframe' => strtoupper($passport->timeframe), 'execution_timeframe' => 'M5']);
        if (($compiled['status'] ?? null) !== 'compiled') {
            return [...$compiled, 'status' => 'blocked'];
        }
        $researchContract = $this->researchContract($trial, $passport, $baseline, $identity, $compiled);
        if (! $apply) {
            return ['protocol' => self::PROTOCOL, 'status' => 'would_queue', 'trial_id' => $trialId,
                'baseline_model_version_id' => $baseline->id, 'seats' => CausalCompoundingKernelService::POPULATION_SIZE,
                'primary_proof_seats' => count($compiled['arms']),
                'compiled_contract' => $compiled, 'research_experiment_contract' => $researchContract, 'promotion_evidence' => false];
        }

        $academyContext = (array) data_get(json_decode((string) $trial->frozen_contract, true) ?: [], 'context', []);
        $created = DB::transaction(function () use ($trial, $passport, $baseline, $baselineAgent, $identity, $compiled, $researchContract, $academyContext): array {
            $lab = $baselineAgent->generation->laboratory;
            $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => ((int) $lab->generations()->max('generation')) + 1,
                'trigger_type' => 'academy_experiment', 'trigger_context' => ['protocol' => self::PROTOCOL, 'academy_trial_id' => $trial->id,
                    'baseline_model_version_id' => $baseline->id, 'genetic_parent_model_version_id' => null,
                    'data_hash' => $identity['data_hash'], 'execution_hash' => $identity['execution_hash'],
                    'canonical_dataset_snapshots' => $identity['canonical_dataset_snapshots'], 'compiled_contract' => $compiled,
                    'research_experiment_contract' => $researchContract,
                    'pre_2026_only' => true, 'research_only' => true, 'promotion_evidence' => false],
                'data_fingerprint' => $identity['data_hash'], 'population_size' => CausalCompoundingKernelService::POPULATION_SIZE, 'status' => 'queued', 'started_at' => now()]);
            app(LearningProtocolEpochService::class)->openForNewGeneration($generation, $lab->symbol, $lab->timeframe);
            $agents = [];
            foreach ($compiled['arms'] as $index => $arm) {
                $label = 'academy_t'.$trial->id.'_g'.$generation->generation.'_a'.($index + 1);
                $metadata = [...((array) $baseline->metadata), 'base_strategy' => 'confirmation_entry_mtf_v1',
                    'causal_baseline_model_version_id' => $baseline->id,
                    'genetic_parent_model_version_id' => null,
                    'academy_experiment' => ['protocol' => self::PROTOCOL, 'academy_trial_id' => $trial->id, 'arm_role' => $arm['role'],
                        'planner_value' => $arm['planner_value'], 'runtime_value' => $arm['runtime_value'], 'contract_hash' => hash('sha256', json_encode($compiled)),
                        'research_experiment_contract' => $researchContract,
                        'context' => $academyContext,
                        'data_hash' => $identity['data_hash'], 'execution_hash' => $identity['execution_hash'],
                        'causal_baseline_model_version_id' => $baseline->id, 'genetic_parent_model_version_id' => null,
                        'research_only' => true, 'promotion_evidence' => false]];
                $model = ModelVersion::create(['name' => 'Academy trial '.$trial->id.' '.$arm['role'].' a'.($index + 1).' g'.$generation->generation,
                    'strategy' => $label, 'version' => 'academy-'.$trial->id.'-'.$generation->generation.'-'.($index + 1), 'generation' => $generation->generation,
                    'status' => 'testing', 'description' => 'Compiled Academy experiment; research-only.', 'change_log' => 'academy '.$arm['role'],
                    'parameters' => $arm['runtime_parameters'], 'metadata' => $metadata, 'evidence_status' => 'valid']);
                $agents[] = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id, 'parent_a_model_version_id' => null,
                    'symbol' => $passport->symbol, 'timeframe' => $passport->timeframe, 'strategy_family' => 'confirmation_entry_mtf',
                    'origin' => 'academy_experiment', 'lifecycle_status' => 'full_queued', 'parameter_diff' => $this->diff((array) $baseline->parameters, $arm['runtime_parameters']),
                    'decision_reason' => 'Compiled Academy '.$arm['role'].' arm; causal baseline only; research-only.']);
            }
            $protectedGenes = collect($agents)->flatMap(fn (LabAgent $agent): array => array_keys((array) $agent->parameter_diff))
                ->unique()->values()->all();
            $kernel = $this->compoundingKernel->complete(
                $generation,
                $baseline,
                $baselineAgent,
                (string) $identity['data_hash'],
                (string) $identity['execution_hash'],
                'selection_quality',
                $protectedGenes,
            );
            DB::table('edge_academy_trials')->where('id', $trial->id)->update(['status' => 'materialized',
                'outcome' => json_encode(['protocol' => self::PROTOCOL, 'generation_id' => $generation->id, 'compiled_contract' => $compiled, 'promotion_evidence' => false]), 'updated_at' => now()]);

            return compact('generation', 'agents', 'kernel');
        });
        foreach ($created['agents'] as $agent) {
            EvaluateLabAgentJob::dispatch($agent->id, $agent->symbol, 'full');
        }
        foreach ($created['kernel']['dispatches'] as $dispatch) {
            EvaluateLabAgentJob::dispatch($dispatch['agent']->id, $dispatch['agent']->symbol, $dispatch['mode']);
        }

        return ['protocol' => self::PROTOCOL, 'status' => 'queued', 'generation_id' => $created['generation']->id,
            'agent_ids' => collect([...$created['agents'], ...$created['kernel']['agents']])->pluck('id')->all(),
            'population_size' => CausalCompoundingKernelService::POPULATION_SIZE,
            'compounding_kernel' => $created['kernel']['contract'], 'promotion_evidence' => false];
    }

    /** Settle only after every explicit arm has immutable replay evidence. */
    public function settleOutcome(LabAgent $agent): array
    {
        $agent->loadMissing('modelVersion', 'generation.agents.modelVersion');
        $contract = (array) data_get($agent->modelVersion?->metadata, 'academy_experiment', []);
        if (($contract['protocol'] ?? null) !== self::PROTOCOL) {
            return ['status' => 'not_academy_experiment', 'promotion_evidence' => false];
        }
        $trial = DB::table('edge_academy_trials')->find((int) ($contract['academy_trial_id'] ?? 0));
        if (! $trial) {
            return $this->blocked('ACADEMY_TRIAL_MISSING_AT_SETTLEMENT');
        }
        if ($trial->settled_at !== null) {
            return ['protocol' => self::PROTOCOL, 'status' => (string) $trial->status, 'promotion_evidence' => false];
        }
        $rows = collect($agent->generation?->agents ?? [])->filter(fn (LabAgent $row): bool => data_get($row->modelVersion?->metadata, 'academy_experiment.academy_trial_id') === (int) $trial->id);
        if ($rows->isEmpty()) {
            return $this->blocked('ACADEMY_COHORT_MEMBERS_MISSING');
        }
        $observations = $rows->map(function (LabAgent $row): ?array {
            $metrics = $row->modelVersion?->marketPerformances()->where('symbol', $row->symbol)->where('timeframe', $row->timeframe)->latest('id')->value('metrics');
            if (is_string($metrics)) {
                $metrics = json_decode($metrics, true);
            }
            if (! is_array($metrics)) {
                return null;
            }

            return ['agent_id' => $row->id, 'model_version_id' => $row->model_version_id,
                'role' => data_get($row->modelVersion?->metadata, 'academy_experiment.arm_role'), 'metrics' => $metrics];
        });
        if ($observations->contains(null)) {
            return ['protocol' => self::PROTOCOL, 'status' => 'awaiting_all_arm_evidence', 'promotion_evidence' => false];
        }
        $expectedData = (string) ($contract['data_hash'] ?? '');
        $expectedExecution = (string) ($contract['execution_hash'] ?? '');
        foreach ($observations as $observation) {
            $metrics = $observation['metrics'];
            $data = (string) data_get($metrics, 'data_manifest.sha256', data_get($metrics, 'data_hash', ''));
            $execution = (string) data_get($metrics, 'execution_contract.execution_hash', data_get($metrics, 'execution_hash', ''));
            if ($data === '' || $execution === '' || ! hash_equals($expectedData, $data) || ! hash_equals($expectedExecution, $execution)) {
                return $this->settleTechnicalQuarantine($trial, $contract, $observations, 'ACADEMY_ARM_HASH_MISMATCH');
            }
        }
        $metrics = $observations->pluck('metrics');
        $perArm = $observations->map(fn (array $observation): array => [
            'agent_id' => $observation['agent_id'], 'model_version_id' => $observation['model_version_id'], 'role' => $observation['role'],
            'setup' => (int) data_get($observation['metrics'], 'entry_contract_funnel.stage_counts.setup', 0),
            'trigger' => (int) data_get($observation['metrics'], 'entry_contract_funnel.stage_counts.trigger', 0),
            'closed_trade' => (int) data_get($observation['metrics'], 'total_trades', 0),
            'false_entry_rate' => (float) data_get($observation['metrics'], 'false_entry_rate', 0),
            'opportunity_flood_ratio' => (float) data_get($observation['metrics'], 'opportunity_flood_ratio', 0),
            'after_cost_expectancy_r' => (float) data_get($observation['metrics'], 'after_cost_expectancy_r', data_get($observation['metrics'], 'net_r', 0)),
            'metrics_hash' => $this->hash($observation['metrics']),
        ]);
        // Power is an arm-level property. Summing five arms would let four
        // underpowered candidates masquerade as one adequately tested arm.
        $counts = ['setup' => (int) $perArm->min('setup'), 'trigger' => (int) $perArm->min('trigger'),
            'closed_trade' => (int) $perArm->min('closed_trade'), 'false_entry_rate' => (float) $perArm->max('false_entry_rate'),
            'opportunity_flood_ratio' => (float) $perArm->max('opportunity_flood_ratio'), 'per_arm' => $perArm->all()];
        $control = $perArm->where('role', 'frozen_control');
        $candidates = $perArm->where('role', 'candidate');
        $controlExpectation = $control->isEmpty() ? null : (float) $control->avg('after_cost_expectancy_r');
        $candidateExpectation = $candidates->isEmpty() ? null : (float) $candidates->avg('after_cost_expectancy_r');
        $outcome = ['after_cost_expectancy_r' => (float) ($metrics->avg(fn ($m) => data_get($m, 'after_cost_expectancy_r', data_get($m, 'net_r', 0))) ?? 0),
            'control_after_cost_expectancy_r' => $controlExpectation, 'candidate_after_cost_expectancy_r' => $candidateExpectation,
            'treatment_effect_after_cost_r' => $controlExpectation === null || $candidateExpectation === null ? null : $candidateExpectation - $controlExpectation,
            'arm_count' => $observations->count(), 'control_present' => ! $control->isEmpty(), 'arm_evidence' => $perArm->all()];

        return DB::transaction(function () use ($trial, $contract, $observations, $counts, $outcome): array {
            $locked = DB::table('edge_academy_trials')->lockForUpdate()->find($trial->id);
            if (! $locked) {
                throw new RuntimeException('ACADEMY_TRIAL_MISSING_AT_SETTLEMENT');
            }
            if ($locked->settled_at !== null) {
                return ['protocol' => self::PROTOCOL, 'status' => (string) $locked->status, 'promotion_evidence' => false];
            }
            $settlement = app(XauusdEdgeFormationAcademyService::class)->settleTrial((int) $locked->id, $counts, $outcome);
            $classification = $this->classification($settlement, $outcome, $counts);
            $receipt = $this->recordReceipt($contract, $locked, $observations, $settlement, $classification, $outcome, $counts);

            return [...$settlement, 'classification' => $classification, 'conversion_receipt' => $receipt, 'promotion_evidence' => false];
        });
    }

    /** Build the immutable contract before the generation id or attempt exists. */
    private function researchContract(object $trial, object $passport, ModelVersion $baseline, array $identity, array $compiled): array
    {
        $frozen = (array) (json_decode((string) $trial->frozen_contract, true) ?: []);
        $density = (array) (json_decode((string) $trial->density_contract, true) ?: []);
        $baselineEpoch = (string) ($identity['baseline_epoch_hash'] ?? $this->hash([
            'parameters' => (array) $baseline->parameters, 'metadata' => (array) $baseline->metadata, 'frozen_contract' => $frozen,
        ]));
        $dataAndMtf = (string) ($identity['data_and_mtf_hash'] ?? $this->hash([
            'data_hash' => $identity['data_hash'], 'canonical_dataset_snapshots' => $identity['canonical_dataset_snapshots'],
        ]));
        $runtime = (string) ($identity['runtime_and_contract_hash'] ?? $this->hash([
            'execution_hash' => $identity['execution_hash'], 'compiler_protocol' => $compiled['protocol'] ?? null,
            'compiled_arms' => collect($compiled['arms'] ?? [])->map(fn (array $arm): array => [
                'role' => $arm['role'] ?? null, 'parameter_hash' => $arm['parameter_hash'] ?? null,
            ])->all(),
        ]));
        $intervention = (string) ($identity['intervention_hash'] ?? $this->hash([
            'axis' => $compiled['axis'] ?? null, 'arms' => collect($compiled['arms'] ?? [])->map(fn (array $arm): array => [
                'role' => $arm['role'] ?? null, 'runtime_value' => $arm['runtime_value'] ?? null,
                'parameter_hash' => $arm['parameter_hash'] ?? null,
            ])->all(),
        ]));
        $windowPlan = (string) ($identity['window_plan_hash'] ?? $this->hash([
            'temporal_binding_hash' => $passport->temporal_binding_hash, 'density_contract' => $density,
            'window_plan' => (array) ($identity['window_plan'] ?? []),
        ]));

        return [
            'contract_version' => ResearchExperimentConversionKernelService::CONTRACT_VERSION,
            'source' => ['type' => 'edge_academy_trial', 'id' => (int) $trial->id],
            'scope' => ['symbol' => strtoupper((string) $passport->symbol), 'laboratory_timeframe' => strtoupper((string) $passport->timeframe), 'execution_timeframe' => 'M5'],
            'claim' => ['target_stage' => (string) ($compiled['axis'] ?? 'academy_stage'),
                'hypothesis' => (string) $trial->trial_type, 'minimum_meaningful_effect' => $density],
            'identity' => ['baseline_epoch_hash' => $baselineEpoch, 'data_and_mtf_hash' => $dataAndMtf,
                'runtime_and_contract_hash' => $runtime, 'intervention_hash' => $intervention, 'window_plan_hash' => $windowPlan,
                'evaluator_version' => (string) ($identity['evaluator_version'] ?? 'academy_full_replay_statistical_v1')],
            'arms' => collect($compiled['arms'] ?? [])->map(fn (array $arm): array => [
                'role' => $arm['role'] ?? null, 'runtime_value' => $arm['runtime_value'] ?? null,
                'parameter_hash' => $arm['parameter_hash'] ?? null,
            ])->all(),
            'revisions' => ['subject' => max(1, (int) ($identity['subject_revision'] ?? 1)),
                'evidence' => max(1, (int) ($identity['evidence_revision'] ?? 1))],
        ];
    }

    private function settleTechnicalQuarantine(object $trial, array $armContract, iterable $observations, string $reason): array
    {
        return DB::transaction(function () use ($trial, $armContract, $observations, $reason): array {
            $locked = DB::table('edge_academy_trials')->lockForUpdate()->find($trial->id);
            if (! $locked) {
                throw new RuntimeException('ACADEMY_TRIAL_MISSING_AT_SETTLEMENT');
            }
            if ($locked->settled_at !== null) {
                return ['protocol' => self::PROTOCOL, 'status' => (string) $locked->status, 'promotion_evidence' => false];
            }
            $settlement = ['protocol' => self::PROTOCOL, 'status' => 'technical_quarantine', 'reason' => $reason, 'promotion_evidence' => false];
            DB::table('edge_academy_trials')->where('id', $locked->id)->update(['status' => 'technical_quarantine',
                'outcome' => json_encode($settlement), 'settled_at' => now(), 'updated_at' => now()]);
            $receipt = $this->recordReceipt($armContract, $locked, $observations, $settlement, 'TECHNICAL_QUARANTINE', [], []);

            return [...$settlement, 'classification' => 'TECHNICAL_QUARANTINE', 'conversion_receipt' => $receipt];
        });
    }

    private function classification(array $settlement, array $outcome, array $counts): string
    {
        $density = (string) data_get($settlement, 'density.status');
        if ($density === 'powered_for_economic_settlement') {
            $effect = $outcome['treatment_effect_after_cost_r'] ?? null;

            return ! is_numeric($effect) ? 'INCONCLUSIVE' : ((float) $effect > 0 ? 'POSITIVE_CANDIDATE' : ((float) $effect < 0 ? 'HARMFUL' : 'INCONCLUSIVE'));
        }
        if ($density === 'topology_failure_insufficient_events') {
            return (int) ($counts['setup'] ?? 0) === 0 || (int) ($counts['trigger'] ?? 0) === 0 ? 'UNREACHABLE' : 'UNDERPOWERED';
        }

        return 'UNDERPOWERED';
    }

    /** Every terminal cohort either opens durable work or has a receipt-backed reason. */
    private function recordReceipt(array $armContract, object $trial, iterable $observations, array $settlement, string $classification, array $outcome, array $counts): array
    {
        $contract = (array) ($armContract['research_experiment_contract'] ?? []);
        if ($contract === []) {
            throw new RuntimeException('ACADEMY_RESEARCH_CONTRACT_MISSING_AT_SETTLEMENT');
        }
        $work = match ($classification) {
            'POSITIVE_CANDIDATE' => ['type' => 'academy_independent_replication', 'priority' => 8],
            'HARMFUL' => ['type' => 'academy_harmful_intervention_repair', 'priority' => 8],
            'UNREACHABLE' => ['type' => 'academy_upstream_repair', 'priority' => 9],
            'UNDERPOWERED' => ['type' => 'academy_power_extension', 'priority' => 7],
            'TECHNICAL_QUARANTINE' => ['type' => 'academy_technical_quarantine', 'priority' => 9],
            default => ['type' => 'academy_adversarial_ablation', 'priority' => 6],
        };
        $references = collect($observations)->map(fn (array $observation): array => [
            'agent_id' => $observation['agent_id'] ?? null, 'model_version_id' => $observation['model_version_id'] ?? null,
            'role' => $observation['role'] ?? null, 'metrics_hash' => isset($observation['metrics']) ? $this->hash($observation['metrics']) : null,
        ])->values()->all();
        $result = $this->conversion->record($contract, [
            'academy_trial_id' => (int) $trial->id, 'academy_settlement' => $settlement,
            'immutable_arm_evidence' => $references, 'outcome' => $outcome, 'counts' => $counts,
            'promotion_evidence' => false,
        ], $classification, [...$work, 'identity' => 'academy-trial:'.$trial->id], []);
        if (($result['status'] ?? null) !== 'recorded') {
            throw new RuntimeException('ACADEMY_CONVERSION_RECEIPT_FAILED:'.($result['reason'] ?? 'UNKNOWN'));
        }

        return $result;
    }

    private function axisFromArms(string $arms): ?string
    {
        $first = (json_decode($arms, true) ?: [])[0] ?? [];

        return $first['changed_axis'] ?? null;
    }

    private function diff(array $old, array $new): array
    {
        $out = [];
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) {
            if (($old[$key] ?? null) !== ($new[$key] ?? null)) {
                $out[$key] = ['old' => $old[$key] ?? null, 'new' => $new[$key] ?? null];
            }
        }

        return $out;
    }

    private function hash(mixed $value): string
    {
        return hash('sha256', json_encode($this->canonicalize($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        } if (! array_is_list($value)) {
            ksort($value);
        } foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    private function blocked(string $reason): array
    {
        return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason' => $reason, 'promotion_evidence' => false];
    }
}
