<?php

namespace App\Services;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use Illuminate\Support\Facades\DB;

/** Materializes an Academy contract through the existing full-replay lane. */
class AcademyExperimentMaterializerService
{
    public const PROTOCOL = 'academy_foundry_materializer_v1';

    public function __construct(private AcademyExperimentContractCompilerService $compiler) {}

    public function materialize(int $trialId, int $baselineModelVersionId, array $identity, bool $apply = false): array
    {
        $trial = DB::table('edge_academy_trials')->find($trialId);
        $passport = $trial ? DB::table('edge_academy_passports')->find($trial->edge_academy_passport_id) : null;
        $baseline = ModelVersion::query()->find($baselineModelVersionId);
        $baselineAgent = LabAgent::query()->with('generation.laboratory')->where('model_version_id', $baselineModelVersionId)->latest('id')->first();
        if (! $trial || ! $passport || ! $baseline || ! $baselineAgent?->generation?->laboratory) return $this->blocked('ACADEMY_TRIAL_BASELINE_AND_LAB_REQUIRED');
        if ($trial->settled_at !== null) return $this->blocked('SETTLED_ACADEMY_TRIAL_CANNOT_BE_MATERIALIZED');
        if (($identity['pre_2026_only'] ?? false) !== true || ! filled($identity['data_hash'] ?? null)
            || ! filled($identity['execution_hash'] ?? null) || ! is_array($identity['canonical_dataset_snapshots'] ?? null)) return $this->blocked('PRE2026_DATA_EXECUTION_AND_SNAPSHOT_CONTRACT_REQUIRED');
        $compiled = $this->compiler->compile([
            'axis' => data_get(json_decode((string) $trial->outcome, true), 'axis', null) ?: $this->axisFromArms($trial->arms),
            'arms' => json_decode((string) $trial->arms, true) ?: [],
        ], (array) $baseline->parameters, ['symbol' => strtoupper($passport->symbol), 'laboratory_timeframe' => strtoupper($passport->timeframe), 'execution_timeframe' => 'M5']);
        if (($compiled['status'] ?? null) !== 'compiled') return [...$compiled, 'status' => 'blocked'];
        if (! $apply) return ['protocol' => self::PROTOCOL, 'status' => 'would_queue', 'trial_id' => $trialId,
            'baseline_model_version_id' => $baseline->id, 'seats' => count($compiled['arms']), 'compiled_contract' => $compiled, 'promotion_evidence' => false];

        $created = DB::transaction(function () use ($trial, $passport, $baseline, $baselineAgent, $identity, $compiled): array {
            $lab = $baselineAgent->generation->laboratory;
            $generation = LabGeneration::create(['ai_laboratory_id' => $lab->id, 'generation' => ((int) $lab->generations()->max('generation')) + 1,
                'trigger_type' => 'academy_experiment', 'trigger_context' => ['protocol' => self::PROTOCOL, 'academy_trial_id' => $trial->id,
                    'baseline_model_version_id' => $baseline->id, 'genetic_parent_model_version_id' => null,
                    'data_hash' => $identity['data_hash'], 'execution_hash' => $identity['execution_hash'],
                    'canonical_dataset_snapshots' => $identity['canonical_dataset_snapshots'], 'compiled_contract' => $compiled,
                    'pre_2026_only' => true, 'research_only' => true, 'promotion_evidence' => false],
                'data_fingerprint' => $identity['data_hash'], 'population_size' => count($compiled['arms']), 'status' => 'queued', 'started_at' => now()]);
            $agents = [];
            foreach ($compiled['arms'] as $index => $arm) {
                $label = 'academy_t'.$trial->id.'_g'.$generation->generation.'_a'.($index + 1);
                $metadata = [...((array) $baseline->metadata), 'base_strategy' => 'confirmation_entry_mtf_v1',
                    'academy_experiment' => ['protocol' => self::PROTOCOL, 'academy_trial_id' => $trial->id, 'arm_role' => $arm['role'],
                        'planner_value' => $arm['planner_value'], 'runtime_value' => $arm['runtime_value'], 'contract_hash' => hash('sha256', json_encode($compiled)),
                        'data_hash' => $identity['data_hash'], 'execution_hash' => $identity['execution_hash'],
                        'causal_baseline_model_version_id' => $baseline->id, 'genetic_parent_model_version_id' => null,
                        'research_only' => true, 'promotion_evidence' => false]];
                $model = ModelVersion::create(['name' => 'Academy trial '.$trial->id.' '.$arm['role'].' g'.$generation->generation,
                    'strategy' => $label, 'version' => 'academy-'.$trial->id.'-'.$generation->generation.'-'.($index + 1), 'generation' => $generation->generation,
                    'status' => 'testing', 'description' => 'Compiled Academy experiment; research-only.', 'change_log' => 'academy '.$arm['role'],
                    'parameters' => $arm['runtime_parameters'], 'metadata' => $metadata, 'evidence_status' => 'valid']);
                $agents[] = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $model->id, 'parent_a_model_version_id' => null,
                    'symbol' => $passport->symbol, 'timeframe' => $passport->timeframe, 'strategy_family' => 'confirmation_entry_mtf',
                    'origin' => 'academy_experiment', 'lifecycle_status' => 'full_queued', 'parameter_diff' => $this->diff((array) $baseline->parameters, $arm['runtime_parameters']),
                    'decision_reason' => 'Compiled Academy '.$arm['role'].' arm; causal baseline only; research-only.']);
            }
            DB::table('edge_academy_trials')->where('id', $trial->id)->update(['status' => 'materialized',
                'outcome' => json_encode(['protocol' => self::PROTOCOL, 'generation_id' => $generation->id, 'compiled_contract' => $compiled, 'promotion_evidence' => false]), 'updated_at' => now()]);
            return compact('generation', 'agents');
        });
        foreach ($created['agents'] as $agent) EvaluateLabAgentJob::dispatch($agent->id, $agent->symbol, 'full');
        return ['protocol' => self::PROTOCOL, 'status' => 'queued', 'generation_id' => $created['generation']->id, 'agent_ids' => collect($created['agents'])->pluck('id')->all(), 'promotion_evidence' => false];
    }

    private function axisFromArms(string $arms): ?string { $first = (json_decode($arms, true) ?: [])[0] ?? []; return $first['changed_axis'] ?? null; }
    private function diff(array $old, array $new): array { $out=[]; foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) if (($old[$key]??null) !== ($new[$key]??null)) $out[$key]=['old'=>$old[$key]??null,'new'=>$new[$key]??null]; return $out; }
    private function blocked(string $reason): array { return ['protocol'=>self::PROTOCOL,'status'=>'blocked','reason'=>$reason,'promotion_evidence'=>false]; }
}
