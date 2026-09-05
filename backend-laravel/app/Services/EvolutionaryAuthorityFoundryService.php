<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Jobs\EvaluateLabAgentJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The only bridge from a confirmed causal component to evolutionary authority.
 * Research evidence remains useful, but it is never silently upgraded to a
 * parent, paper candidate, or champion claim.
 */
class EvolutionaryAuthorityFoundryService
{
    public const PROTOCOL = 'evolutionary_authority_foundry_v1';
    public const INCUBATOR_ARMS = ['frozen_control', 'single_gene_child', 'memory_blinded_child', 'skill_ablation', 'weakest_gate_repair'];

    /**
     * Materialize the clean five-arm cohort from a confirmed skill. The
     * original research arm is never reused as a genetic parent; all rows are
     * explicitly research-only until the full Foundry ladder is earned.
     *
     * @return array<string,mixed>
     */
    public function materializeIncubator(LabAgent $mentor): array
    {
        if (! Schema::hasTable('skill_incubation_trials')) return $this->unavailable();
        $mentor->loadMissing('modelVersion', 'generation.laboratory', 'parentA');
        $model = $mentor->modelVersion;
        $baseline = $mentor->parentA;
        $gene = (string) data_get($model?->metadata, 'skill_mentor.parameter_key');
        if (! $model || ! $baseline || data_get($model->metadata, 'skill_mentor.status') !== 'confirmed' || $gene === '') {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason_code' => 'CONFIRMED_SKILL_OR_FROZEN_BASELINE_MISSING', 'promotion_evidence' => false];
        }
        $existing = DB::table('skill_incubation_trials')->where('mentor_model_version_id', $model->id)
            ->whereIn('status', ['queued', 'running', 'passed'])->exists();
        if ($existing) return ['protocol' => self::PROTOCOL, 'status' => 'already_materialized', 'promotion_evidence' => false];
        $lab = $mentor->generation?->laboratory;
        if (! $lab) return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason_code' => 'LABORATORY_MISSING', 'promotion_evidence' => false];
        $baseParameters = (array) $baseline->parameters;
        $skillParameters = (array) $model->parameters;
        if (! array_key_exists($gene, $baseParameters) || ! array_key_exists($gene, $skillParameters)) {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason_code' => 'CONFIRMED_GENE_NOT_REPRODUCIBLE', 'promotion_evidence' => false];
        }
        $dataHash = (string) data_get($model->metadata, 'learning_receipt.data_hash', data_get($mentor->generation, 'data_fingerprint', ''));
        $executionHash = (string) data_get($model->metadata, 'learning_receipt.execution_hash', '');
        if ($dataHash === '' || $executionHash === '') {
            return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'reason_code' => 'FROZEN_DATA_OR_EXECUTION_HASH_MISSING', 'promotion_evidence' => false];
        }
        return DB::transaction(function () use ($mentor, $model, $baseline, $lab, $gene, $baseParameters, $skillParameters, $dataHash, $executionHash): array {
            $generation = LabGeneration::create([
                'ai_laboratory_id' => $lab->id, 'generation' => ((int) $lab->generations()->max('generation')) + 1,
                'trigger_type' => 'authority_incubator', 'trigger_context' => ['protocol' => self::PROTOCOL, 'mentor_model_version_id' => $model->id,
                    'data_hash' => $dataHash, 'execution_hash' => $executionHash, 'promotion_evidence' => false],
                'data_fingerprint' => $dataHash, 'population_size' => count(self::INCUBATOR_ARMS), 'status' => 'queued', 'started_at' => now(),
            ]);
            $agents = [];
            foreach (self::INCUBATOR_ARMS as $arm) {
                $parameters = $this->parametersForArm($arm, $baseParameters, $skillParameters, $gene);
                if ($parameters === null) throw new \RuntimeException('INCUBATOR_ARM_CANNOT_MATERIALIZE: '.$arm);
                $diff = $this->diff($baseParameters, $parameters);
                $metadata = [
                    ...((array) $model->metadata),
                    'authority_incubator' => ['protocol' => self::PROTOCOL, 'arm' => $arm, 'fold_stage' => 'preflight',
                        'mentor_model_version_id' => $model->id, 'baseline_model_version_id' => $baseline->id, 'confirmed_gene' => $gene,
                        'data_hash' => $dataHash, 'execution_hash' => $executionHash, 'research_only' => true, 'promotion_evidence' => false],
                ];
                $child = ModelVersion::create(['name' => $model->name.' authority '.$arm, 'strategy' => $model->strategy,
                    'version' => $model->version.'-authority-'.$arm, 'generation' => $generation->generation, 'status' => 'testing',
                    'description' => 'Clean authority-incubator child; research-only.', 'change_log' => 'authority incubator '.$arm,
                    'parameters' => $parameters, 'metadata' => $metadata, 'evidence_status' => 'valid']);
                $agent = LabAgent::create(['lab_generation_id' => $generation->id, 'model_version_id' => $child->id,
                    'parent_a_model_version_id' => $baseline->id, 'symbol' => $mentor->symbol, 'timeframe' => $mentor->timeframe,
                    'strategy_family' => $mentor->strategy_family, 'origin' => 'authority_incubator', 'lifecycle_status' => 'full_queued',
                    'parameter_diff' => $diff, 'decision_reason' => 'Five-arm authority incubator preflight; research-only.']);
                $this->recordIncubationArm($mentor, ['arm' => $arm, 'child_model_version_id' => $child->id, 'fold_stage' => 'preflight',
                    'status' => 'queued', 'data_hash' => $dataHash, 'execution_hash' => $executionHash,
                    'single_component_change' => $arm === 'frozen_control' ? $diff === [] : count($diff) === 1,
                    'target_gate_improved' => false, 'non_target_regression' => false, 'window_keys' => []]);
                $agents[] = $agent;
            }
            foreach ($agents as $agent) EvaluateLabAgentJob::dispatch($agent->id, $agent->symbol, 'full');
            return ['protocol' => self::PROTOCOL, 'status' => 'queued', 'generation_id' => $generation->id,
                'agent_ids' => collect($agents)->pluck('id')->all(), 'promotion_evidence' => false];
        });
    }

    /** @return array<string,mixed> */
    public function recordIncubationArm(LabAgent $mentor, array $trial, ?ModelVersion $authoritySource = null): array
    {
        if (! Schema::hasTable('skill_incubation_trials')) return $this->unavailable();
        $mentor->loadMissing('modelVersion');
        $model = $authoritySource ?: $mentor->modelVersion;
        $arm = (string) ($trial['arm'] ?? '');
        if (! $model || ! in_array($arm, self::INCUBATOR_ARMS, true)) {
            return ['protocol' => self::PROTOCOL, 'status' => 'rejected', 'reason_code' => 'INCUBATOR_ARM_INVALID', 'promotion_evidence' => false];
        }
        $dataHash = (string) ($trial['data_hash'] ?? ''); $executionHash = (string) ($trial['execution_hash'] ?? '');
        $fold = (string) ($trial['fold_stage'] ?? 'preflight');
        $status = (string) ($trial['status'] ?? 'pending');
        $windowKeys = array_values(array_unique(array_filter(array_map('strval', (array) ($trial['window_keys'] ?? [])))));
        $evidence = [
            'protocol' => self::PROTOCOL, 'control_hashes_bound' => $dataHash !== '' && $executionHash !== '',
            'single_component_change' => (bool) ($trial['single_component_change'] ?? false),
            'target_gate_improved' => (bool) ($trial['target_gate_improved'] ?? false),
            'non_target_regression' => (bool) ($trial['non_target_regression'] ?? true),
            'window_keys' => $windowKeys, 'settlement_id' => $trial['settlement_id'] ?? null,
            'source_confirmed' => data_get($model->metadata, 'skill_mentor.status') === 'confirmed',
            'promotion_evidence' => false,
        ];
        $key = hash('sha256', json_encode([self::PROTOCOL, $model->id, $arm, $fold, $dataHash, $executionHash, $windowKeys], JSON_UNESCAPED_SLASHES));
        DB::table('skill_incubation_trials')->updateOrInsert(['trial_key' => $key], [
            'mentor_model_version_id' => $model->id, 'child_model_version_id' => $trial['child_model_version_id'] ?? null, 'lab_agent_id' => $mentor->id,
            'symbol' => strtoupper($mentor->symbol), 'timeframe' => strtoupper($mentor->timeframe), 'strategy_family' => $mentor->strategy_family,
            'arm' => $arm, 'fold_stage' => $fold, 'status' => $status, 'data_hash' => $dataHash ?: null, 'execution_hash' => $executionHash ?: null,
            'evidence' => json_encode($evidence), 'settled_at' => $status === 'passed' ? now() : null, 'updated_at' => now(), 'created_at' => now(),
        ]);
        return $this->refreshAuthority($model, $mentor);
    }

    /** @return array<string,mixed> */
    public function recordDescendantTrial(ModelVersion $mentor, ModelVersion $child, string $symbol, string $timeframe, array $trial): array
    {
        if (! Schema::hasTable('descendant_value_trials')) return $this->unavailable();
        $window = (string) ($trial['window_key'] ?? '');
        if ($window === '') return ['protocol' => self::PROTOCOL, 'status' => 'rejected', 'reason_code' => 'DESCENDANT_WINDOW_REQUIRED', 'promotion_evidence' => false];
        $evidence = [
            'protocol' => self::PROTOCOL,
            'confirmed_component_only' => (bool) ($trial['confirmed_component_only'] ?? false),
            'other_gene_mutated' => (bool) ($trial['other_gene_mutated'] ?? false),
            'improved_over_mentor' => (bool) ($trial['improved_over_mentor'] ?? false),
            'inherited_failure' => (bool) ($trial['inherited_failure'] ?? true),
            'regime_coverage' => (float) ($trial['regime_coverage'] ?? 0),
            'diversity_contribution' => (float) ($trial['diversity_contribution'] ?? 0),
            'uncertainty' => (float) ($trial['uncertainty'] ?? 1),
            'constraint_violations' => (int) ($trial['constraint_violations'] ?? 1),
            'promotion_evidence' => false,
        ];
        $status = $evidence['confirmed_component_only'] && $evidence['other_gene_mutated'] ? (string) ($trial['status'] ?? 'settled') : 'invalid';
        DB::table('descendant_value_trials')->updateOrInsert([
            'mentor_model_version_id' => $mentor->id, 'child_model_version_id' => $child->id, 'window_key' => $window,
        ], [
            'trial_key' => hash('sha256', implode('|', [self::PROTOCOL, $mentor->id, $child->id, $window])),
            'symbol' => strtoupper($symbol), 'timeframe' => strtoupper($timeframe), 'strategy_family' => (string) ($trial['strategy_family'] ?? $mentor->strategy),
            'status' => $status, 'evidence' => json_encode($evidence), 'settled_at' => $status === 'settled' ? now() : null, 'updated_at' => now(), 'created_at' => now(),
        ]);
        return $this->refreshAuthority($mentor);
    }

    /** Advance one incubator child only after its immutable replay outcome is available. */
    public function settleIncubatorOutcome(LabAgent $agent, array $result): array
    {
        $agent->loadMissing('modelVersion', 'generation.agents.modelVersion');
        $contract = (array) data_get($agent->modelVersion?->metadata, 'authority_incubator', []);
        if (data_get($contract, 'protocol') !== self::PROTOCOL) return ['status' => 'not_incubator', 'promotion_evidence' => false];
        $mentor = ModelVersion::find((int) data_get($contract, 'mentor_model_version_id'));
        if (! $mentor) return ['status' => 'blocked', 'reason_code' => 'INCUBATOR_MENTOR_MISSING', 'promotion_evidence' => false];
        $expectedData = (string) data_get($contract, 'data_hash'); $expectedExecution = (string) data_get($contract, 'execution_hash');
        $dataHash = (string) data_get($result, 'data_manifest.sha256', data_get($result, 'data_hash'));
        $executionHash = (string) data_get($result, 'execution_contract.execution_hash', data_get($result, 'execution_hash'));
        $stage = (string) data_get($contract, 'fold_stage', 'preflight');
        $arm = (string) data_get($contract, 'arm');
        $windows = array_values(array_unique(array_filter(array_map('strval', (array) data_get($result, 'forward_window_protocol.window_keys', [])))));
        if ($windows === [] && filled(data_get($result, 'evidence_run_id'))) $windows = [(string) data_get($result, 'evidence_run_id')];
        $control = $agent->generation?->agents->first(fn (LabAgent $candidate): bool => data_get($candidate->modelVersion?->metadata, 'authority_incubator.arm') === 'frozen_control');
        $controlMetrics = (array) $control?->modelVersion?->marketPerformances()->where('symbol', $agent->symbol)->where('timeframe', $agent->timeframe)->latest('id')->value('metrics');
        $target = (string) data_get($mentor->metadata, 'skill_mentor.target', 'profit_factor');
        $targetImproved = $this->targetImproved($target, $result, $controlMetrics);
        $nonTargetRegression = $this->nonTargetRegression($result, $controlMetrics);
        $controlArm = in_array($arm, ['frozen_control', 'skill_ablation'], true);
        $passed = $expectedData !== '' && $expectedExecution !== '' && hash_equals($expectedData, $dataHash) && hash_equals($expectedExecution, $executionHash)
            && (int) data_get($result, 'total_trades', 0) > 0 && ! (bool) data_get($result, 'is_overfit', false)
            && ($controlArm || ($targetImproved && ! $nonTargetRegression));
        $authority = $this->recordIncubationArm($agent, [
            'arm' => $arm, 'child_model_version_id' => $agent->model_version_id, 'fold_stage' => $stage, 'status' => $passed ? 'passed' : 'failed',
            'data_hash' => $dataHash, 'execution_hash' => $executionHash, 'single_component_change' => count((array) $agent->parameter_diff) === 1,
            'target_gate_improved' => $targetImproved, 'non_target_regression' => $nonTargetRegression, 'window_keys' => $windows,
            'settlement_id' => data_get($result, 'evidence_run_id'),
        ], $mentor);
        if (! $passed || $stage === 'final') return [...$authority, 'status' => $passed ? 'final_settled' : 'failed', 'promotion_evidence' => false];
        $next = $stage === 'preflight' ? 'interim' : 'final';
        $metadata = (array) $agent->modelVersion->metadata;
        data_set($metadata, 'authority_incubator.fold_stage', $next);
        $agent->modelVersion->update(['metadata' => $metadata]);
        $agent->update(['lifecycle_status' => 'full_queued', 'decision_reason' => 'Authority incubator '.$next.' replay queued; research-only.']);
        EvaluateLabAgentJob::dispatch($agent->id, $agent->symbol, 'full');
        return [...$authority, 'status' => 'advanced_'.$next, 'promotion_evidence' => false];
    }

    /** Immutable promotion decision; callers may persist it but must not bypass it. */
    public function refreshAuthority(ModelVersion $model, ?LabAgent $agent = null, array $passport = []): array
    {
        if (! Schema::hasTable('evolutionary_authority_ledgers')) return $this->unavailable();
        $scope = $agent ? [strtoupper($agent->symbol), strtoupper($agent->timeframe), $agent->strategy_family] : ['', '', null];
        $incubation = Schema::hasTable('skill_incubation_trials') ? DB::table('skill_incubation_trials')->where('mentor_model_version_id', $model->id)->get() : collect();
        $required = collect(self::INCUBATOR_ARMS);
        $finalPassed = $required->every(fn (string $arm): bool => $incubation->contains(function ($row) use ($arm): bool {
            $evidence = (array) json_decode($row->evidence, true);
            $controlArm = in_array($arm, ['frozen_control', 'skill_ablation'], true);
            return $row->arm === $arm && $row->fold_stage === 'final' && $row->status === 'passed'
                && (bool) data_get($evidence, 'control_hashes_bound')
                && ($controlArm ? ! (bool) data_get($evidence, 'single_component_change') : (bool) data_get($evidence, 'single_component_change'))
                && ($controlArm || (bool) data_get($evidence, 'target_gate_improved'))
                && ! (bool) data_get($evidence, 'non_target_regression');
        }));
        $windows = $incubation->flatMap(fn ($row) => (array) data_get(json_decode($row->evidence, true), 'window_keys', []))->unique()->count();
        $descendants = Schema::hasTable('descendant_value_trials') ? DB::table('descendant_value_trials')->where('mentor_model_version_id', $model->id)->where('status', 'settled')->get() : collect();
        $validChildren = $descendants->filter(fn ($row): bool => (bool) data_get(json_decode($row->evidence, true), 'confirmed_component_only')
            && (bool) data_get(json_decode($row->evidence, true), 'other_gene_mutated')
            && (bool) data_get(json_decode($row->evidence, true), 'improved_over_mentor')
            && ! (bool) data_get(json_decode($row->evidence, true), 'inherited_failure')
            && (int) data_get(json_decode($row->evidence, true), 'constraint_violations', 1) === 0)->pluck('child_model_version_id')->unique()->count();
        $sourceConfirmed = data_get($model->metadata, 'skill_mentor.status') === 'confirmed';
        $incubated = $sourceConfirmed && $finalPassed && $windows >= 3;
        $breeder = $incubated && $validChildren >= 2;
        $passportPassed = (bool) ($passport['passed'] ?? false);
        $stage = $passportPassed && $breeder ? 'eligible_parent' : ($breeder ? 'breeder_candidate' : ($incubated ? 'skill_mentor' : ($sourceConfirmed ? 'confirmed_skill' : 'research_only')));
        $evidence = ['protocol' => self::PROTOCOL, 'source_confirmed' => $sourceConfirmed, 'incubation_passed' => $incubated,
            'incubation_final_arms' => $required->values()->all(), 'independent_windows' => $windows, 'descendant_improving_children' => $validChildren,
            'passport' => $passport, 'authority_is_prospective_only' => true, 'promotion_evidence' => false];
        $key = hash('sha256', implode('|', [self::PROTOCOL, $model->id, $scope[0], $scope[1]]));
        DB::table('evolutionary_authority_ledgers')->updateOrInsert(['authority_key' => $key], [
            'model_version_id' => $model->id, 'lab_agent_id' => $agent?->id, 'symbol' => $scope[0] ?: strtoupper((string) data_get($model->metadata, 'symbol', 'GLOBAL')),
            'timeframe' => $scope[1] ?: strtoupper((string) data_get($model->metadata, 'timeframe', 'GLOBAL')), 'strategy_family' => $scope[2],
            'authority_stage' => $stage, 'status' => $stage === 'eligible_parent' ? 'passed' : 'withheld',
            'data_hash' => $incubation->first()?->data_hash, 'execution_hash' => $incubation->first()?->execution_hash,
            'evidence' => json_encode($evidence), 'evaluated_at' => now(), 'updated_at' => now(), 'created_at' => now(),
        ]);
        return ['protocol' => self::PROTOCOL, 'stage' => $stage, 'parent_eligible' => $stage === 'eligible_parent', 'evidence' => $evidence, 'promotion_evidence' => false];
    }

    /** @return array<string,mixed> */
    public function authorityFor(ModelVersion $model): array
    {
        if (! Schema::hasTable('evolutionary_authority_ledgers')) return $this->unavailable();
        $row = DB::table('evolutionary_authority_ledgers')->where('model_version_id', $model->id)->latest('id')->first();
        return $row ? ['protocol' => self::PROTOCOL, 'stage' => $row->authority_stage, 'status' => $row->status, 'evidence' => json_decode($row->evidence, true), 'promotion_evidence' => false]
            : ['protocol' => self::PROTOCOL, 'stage' => 'research_only', 'status' => 'missing_authority_evidence', 'promotion_evidence' => false];
    }

    private function unavailable(): array { return ['protocol' => self::PROTOCOL, 'status' => 'unavailable', 'promotion_evidence' => false]; }

    /** @return array<string,mixed>|null */
    private function parametersForArm(string $arm, array $base, array $skill, string $gene): ?array
    {
        $parameters = $base;
        if ($arm === 'frozen_control' || $arm === 'skill_ablation') return $parameters;
        if ($arm === 'single_gene_child') {
            $parameters[$gene] = $skill[$gene];
            return $parameters;
        }
        $other = collect($base)->keys()->first(fn (string $key): bool => $key !== $gene && (is_numeric($base[$key]) || is_bool($base[$key])));
        if (! $other) return null;
        $value = $parameters[$other];
        $parameters[$other] = is_bool($value) ? ! $value : ((float) $value * ($arm === 'memory_blinded_child' ? 1.05 : .95));
        return $parameters;
    }

    /** @return array<string,array{old:mixed,new:mixed}> */
    private function diff(array $old, array $new): array
    {
        $diff = []; foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) {
            if (($old[$key] ?? null) !== ($new[$key] ?? null)) $diff[$key] = ['old' => $old[$key] ?? null, 'new' => $new[$key] ?? null];
        } return $diff;
    }

    private function targetImproved(string $target, array $current, array $control): bool
    {
        if ($control === []) return false;
        if (str_contains(strtolower($target), 'drawdown') || str_contains(strtolower($target), 'risk')) {
            return (float) data_get($current, 'max_drawdown_percent', data_get($current, 'max_drawdown', 100))
                < (float) data_get($control, 'max_drawdown_percent', data_get($control, 'max_drawdown', 100));
        }
        return (float) data_get($current, 'profit_factor', 0) > (float) data_get($control, 'profit_factor', 0);
    }

    private function nonTargetRegression(array $current, array $control): bool
    {
        if ($control === []) return true;
        return (float) data_get($current, 'max_drawdown_percent', data_get($current, 'max_drawdown', 100))
            > (float) data_get($control, 'max_drawdown_percent', data_get($control, 'max_drawdown', 100))
            || (float) data_get($current, 'profit_factor', 0) + .000001 < (float) data_get($control, 'profit_factor', 0);
    }
}
