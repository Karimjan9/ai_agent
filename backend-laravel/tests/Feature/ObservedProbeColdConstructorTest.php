<?php

namespace Tests\Feature;

use App\Models\LabGeneration;
use App\Models\ModelVersion;
use App\Models\ResearchExperimentWorkItem;
use App\Services\LabImmutableEvidenceService;
use App\Services\LabPopulationService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\SpecialistCouncilContractService;
use App\Services\SpecialistCouncilFollowupExecutionService;
use App\Services\SpecialistCouncilResearchFeedbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Portable reproduction of Work10's first-builder inputs, not market evidence.
 * Only parameter/family/architecture/base/cost/horizon vectors were retained.
 * Readiness/archive fixtures are conditional; the real cold constructor,
 * original-source byte/vector fence, pristine snapshot and checkpoint execute.
 */
class ObservedProbeColdConstructorTest extends TestCase
{
    use RefreshDatabase;

    public function test_observed_completion_cold_build_and_checkpoint_copy_all_six_original_vectors(): void
    {
        [$work, $body, $owner, $models, $actual] = $this->conditionalOwner();
        $oldSources = array_map(fn ($model) => $model->fresh()->getRawOriginal(), $models);
        $hold = $work->result;
        $snapshot = $owner->pristineUnbuiltFollowupSnapshot($work);
        $this->assertArrayHasKey('original_observed_source_hash', $snapshot);
        $this->assertNull($snapshot['original_observed_source_hash']);
        $this->assertSame(app(ResearchPaperEpochContractService::class)->parameterHash($body['original_observed_probe_completion_proof']),
            $snapshot['original_observed_probe_completion_hash']);
        $this->assertFalse($snapshot['original_auxiliary_outcome_still_observed']);

        $intent = ['protocol' => LabPopulationService::NATIVE_COUNCIL_INTENT_PROTOCOL, 'purpose' => 'research',
            'symbol' => 'XAUUSD', 'storage_timeframe' => 'H1', 'population_size' => 6,
            'research_question' => $body['research_question'], 'creator_id' => $body['creator_id'],
            'followup_work_item_id' => $work->id, 'followup_resolution_hash' => $body['resolution_hash']];
        $ready = ['protocol' => SpecialistCouncilResearchFeedbackService::FOLLOWUP_PROTOCOL,
            'status' => 'ready', 'executable' => true, ...$body, 'owned_generation_id' => null, 'native_intent' => $intent];
        $conditional = $this->partialMock(SpecialistCouncilResearchFeedbackService::class, function ($mock) use ($ready, $body, $work) {
            $mock->shouldReceive('inspectFollowupReadiness')->andReturnUsing(fn () => [...$ready,
                'owned_generation_id' => LabGeneration::where('trigger_context->native_specialist_council_intent->followup_work_item_id', $work->id)->value('id')]);
            $mock->shouldReceive('inspectFollowupSourceBinding')->andReturn(['source_hash' => str_repeat('f', 64),
                'python_source_hash' => str_repeat('a', 64), 'amendment_hash' => null,
                'resolution_body_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($body)]);
        });
        // Mockery partials skip promoted-property construction; retain the real owners.
        foreach (['conversion', 'epochs', 'contracts'] as $field) {
            $property = new \ReflectionProperty(SpecialistCouncilResearchFeedbackService::class, $field);
            $property->setValue($conditional, $property->getValue($owner));
        }
        config(['services.xauusd_organism.historical_research_until_champion' => true,
            'services.market_data.provider' => 'csv', 'services.lab_selection.constructor_initial_seat_budget' => 4]);
        app(\App\Services\AutonomousModeService::class)->start('XAUUSD', 'H1', 'conditional-test', 'SQLite cold constructor, no production side effects');
        $this->mock(\App\Services\LearningVelocityGateService::class)->shouldReceive('inspect')->andReturn(['status' => 'healthy', 'allowed' => true]);
        $this->mock(\App\Services\LabDatasetExportService::class)->shouldReceive('ensureFoundationDataset')->andReturn([
            'sha256' => str_repeat('a', 64), 'path' => 'conditional-frozen-foundation.csv',
            'manifest' => ['row_count' => 123000, 'first_candle_at' => '2005-01-03T00:00:00Z', 'last_candle_at' => '2025-12-31T23:00:00Z']]);
        ModelVersion::creating(function ($model) {
            if (data_get($model->metadata, 'native_specialist_council_seed') !== null) {
                $generation = LabGeneration::findOrFail(data_get($model->metadata, 'native_specialist_council_seed.lab_generation_id'));
                $this->assertCount(6, data_get($generation->trigger_context, 'generation_plan'), 'Complete frozen plan must precede every model.');
            }
        });
        $population = app(LabPopulationService::class);
        $generation = $population->build('XAUUSD', 'historical_research', false, 'H1', [], false, false, 6, null, false, null, $intent);
        $this->assertNotNull($generation, json_encode($population->lastBuildOutcome()));
        $this->assertSame(4, $generation->agents()->count(), json_encode(data_get($generation->fresh()->trigger_context, 'constructor_audit')));
        $firstIds = $generation->agents()->orderBy('id')->pluck('id')->all();
        $this->assertSame($hold, $work->fresh()->result);
        // The real executor's fenced checkpoint, not a forged success/learning receipt.
        (new \ReflectionMethod(SpecialistCouncilFollowupExecutionService::class, 'checkpoint'))
            ->invoke(app(SpecialistCouncilFollowupExecutionService::class), $work->fresh(), $generation, $ready, 'constructed');
        $continued = $population->continueInterruptedConstruction($generation->id, 2);
        $this->assertSame([], $continued['failures'] ?? null, json_encode($continued));
        $this->assertCount(2, $continued['created_slots'] ?? []);
        $this->assertSame(6, $generation->agents()->count());
        $this->assertSame($firstIds, $generation->agents()->orderBy('id')->limit(4)->pluck('id')->all());
        $this->assertSame(['source_scalp', 'source_hour', 'source_day', 'source_swing', 'candidate_carrier', 'ablation_carrier'],
            $generation->agents()->with('modelVersion')->orderBy('id')->get()
                ->map(fn ($agent) => data_get($agent->modelVersion->metadata, 'native_specialist_council_seed.slot_role'))->all());
        foreach ($generation->agents()->with('modelVersion')->get() as $agent) {
            $slot = data_get($agent->modelVersion->metadata, 'native_specialist_council_seed.slot_role');
            $role = str_starts_with($slot, 'source_') ? substr($slot, 7) : 'day';
            $descriptor = $actual[$role];
            $this->assertTrue(app(LabImmutableEvidenceService::class)->equivalentJsonValue($descriptor['parameters'], $agent->modelVersion->parameters));
            $this->assertSame($descriptor['family'], $agent->strategy_family);
            $this->assertSame($descriptor['architecture'], data_get($agent->modelVersion->metadata, 'strategy_architecture'));
            $this->assertSame($descriptor['base_strategy'], data_get($agent->modelVersion->metadata, 'base_strategy'));
            $this->assertTrue(app(LabImmutableEvidenceService::class)->equivalentJsonValue($descriptor['declared_execution'],
                data_get($agent->modelVersion->metadata, 'execution_contract')));
        }
        $this->assertSame($oldSources, array_map(fn ($model) => $model->fresh()->getRawOriginal(), $models));
        $this->assertSame($hold['dependency_hold'], data_get($work->fresh()->result, 'dependency_hold'));
        $this->assertDatabaseCount('lab_evaluation_runs', 0);
        $this->assertDatabaseCount('lab_evolution_credit_events', 0);
        $this->assertFalse($body['independent_evidence_claimed']);
        $this->assertFalse($body['scientific_budget_renewed']);
        $this->assertFalse($body['promotion_evidence']);
    }

    #[DataProvider('snapshotPoisons')]
    public function test_even_resigned_snapshot_cannot_drop_alias_or_poison_either_proof_domain(string $poison): void
    {
        [$work, $body, $owner] = $this->conditionalOwner();
        $snapshot = $owner->pristineUnbuiltFollowupSnapshot($work);
        unset($snapshot['server_seal']);
        if ($poison === 'missing_probe') unset($snapshot['original_observed_probe_completion_hash']);
        if ($poison === 'changed_probe') $snapshot['original_observed_probe_completion_hash'] = str_repeat('0', 64);
        if ($poison === 'missing_nullable_auxiliary') unset($snapshot['original_observed_source_hash']);
        if ($poison === 'aliased_auxiliary') $snapshot['original_observed_source_hash'] = $snapshot['original_observed_probe_completion_hash'];
        $snapshot['server_seal'] = (new \ReflectionMethod($owner, 'sourceAmendmentSeal'))->invoke($owner, $snapshot);
        $this->expectExceptionMessage('COUNCIL_SOURCE_AMENDMENT_PRISTINE_START_PROOF_INVALID');
        (new \ReflectionMethod($owner, 'assertPristineStartProof'))->invoke($owner, $work, $body, $snapshot);
    }

    public static function snapshotPoisons(): array
    {
        return [['missing_probe'], ['changed_probe'], ['missing_nullable_auxiliary'], ['aliased_auxiliary']];
    }

    public function test_historical_nonnull_auxiliary_snapshot_hash_and_seal_keep_the_original_shape(): void
    {
        [$work, $body, $owner] = $this->conditionalOwner();
        $body['scientific_question_kind'] = 'new_discovery';
        $body['original_observed_source_proof'] = ['protocol' => 'conditional_original_auxiliary_v1', 'immutable_original_hash' => str_repeat('8', 64)];
        unset($body['original_observed_probe_completion_proof']);
        $body = $this->sealBody($owner, $body);
        $work->update(['payload' => [...$work->payload, 'followup_resolution' => $body]]);
        $snapshot = $owner->pristineUnbuiltFollowupSnapshot($work->fresh());
        $this->assertArrayNotHasKey('original_observed_probe_completion_hash', $snapshot);
        $this->assertTrue($snapshot['original_auxiliary_outcome_still_observed']);
        $this->assertSame(app(ResearchPaperEpochContractService::class)->parameterHash($body['original_observed_source_proof']),
            $snapshot['original_observed_source_hash']);
        // Byte-shape of the historical issuer body: do not append a null second proof.
        $historical = ['protocol' => 'specialist_council_pristine_unbuilt_target_v1', 'work_item_id' => (int) $work->id,
            'work_key' => $work->work_key, 'resolution_hash' => $body['resolution_hash'],
            'resolution_body_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($body),
            'result_hash' => app(ResearchPaperEpochContractService::class)->parameterHash((array) $work->result),
            'nonconstructive_result' => (array) $work->result,
            'native_vectors_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($body['native_source_models']),
            'original_observed_source_hash' => app(ResearchPaperEpochContractService::class)->parameterHash($body['original_observed_source_proof']),
            'owned_generations' => 0, 'owned_model_markers' => 0, 'target_outcomes' => 0,
            'captured_at' => $snapshot['captured_at'], 'target_unbuilt' => true,
            'original_auxiliary_outcome_still_observed' => true, 'promotion_evidence' => false];
        $historical['server_seal'] = (new \ReflectionMethod($owner, 'sourceAmendmentSeal'))->invoke($owner, $historical);
        $this->assertSame($historical, $snapshot);
        (new \ReflectionMethod($owner, 'assertPristineStartProof'))->invoke($owner, $work, $body, $snapshot);
        $this->addToAssertionCount(1);
        $snapshot['original_observed_probe_completion_hash'] = str_repeat('8', 64);
        unset($snapshot['server_seal']);
        $snapshot['server_seal'] = (new \ReflectionMethod($owner, 'sourceAmendmentSeal'))->invoke($owner, $snapshot);
        $this->expectExceptionMessage('COUNCIL_SOURCE_AMENDMENT_PRISTINE_START_PROOF_INVALID');
        (new \ReflectionMethod($owner, 'assertPristineStartProof'))->invoke($owner, $work, $body, $snapshot);
    }

    /** SQLite only: shared canonical fixture supplies unrelated archive/dependency owners. */
    private function conditionalOwner(): array
    {
        $legacy = new SpecialistCouncilFollowupReadinessTest('test_ready_work_is_not_executable_without_original_owner_proof');
        $property = new \ReflectionProperty(TestCase::class, 'app');
        $property->setValue($legacy, $property->getValue($this));
        [$work, $proposal, $version, $models] = (new \ReflectionMethod($legacy, 'fixture'))->invoke($legacy, true, 'technical_unassessable');
        $epochs = app(ResearchPaperEpochContractService::class);
        $contracts = app(SpecialistCouncilContractService::class);
        $actual = $this->actualNativeVectors();
        $specs = [];
        foreach ($actual as $role => $descriptor) {
            $model = $models[$role];
            $model->update(['name' => 'Portable actual '.$role, 'strategy' => 'portable_'.$role.'_native',
                'version' => 'conditional-v1', 'parameters' => $descriptor['parameters'],
                'metadata' => ['base_strategy' => $descriptor['base_strategy'], 'strategy_architecture' => $descriptor['architecture'],
                    'execution_contract' => $descriptor['declared_execution']]]);
            $model->refresh();
            $specs[$role] = ['model_version_id' => $model->id, 'model_hash' => $contracts->modelHash($model),
                'family' => $descriptor['family'], 'strategy' => $model->strategy,
                'strategy_architecture' => $descriptor['architecture'], 'base_strategy' => $descriptor['base_strategy'],
                'original_parameters_hash' => $epochs->parameterHash($descriptor['parameters']), 'parameters' => $descriptor['parameters'],
                'parameter_hash' => $epochs->parameterHash($descriptor['parameters']), 'parameter_deltas' => []];
            $this->assertSame(in_array($role, ['scalp', 'hour'], true)
                ? '005d8ea8d466fea7bd4965020a589b907c6e51dbaf8dbe12fcaf73f4407b93a8'
                : '54c8d5b81ff9ea82354bdfa83f2339e2433272911a746264be69695c7eb28974',
                $specs[$role]['parameter_hash'], 'Actual original parameters must not drift during fixture materialization.');
        }
        $manifest = $version->manifest;
        foreach ($manifest['members'] as &$member) {
            if (isset($actual[$member['role']])) $member['horizon'] = $actual[$member['role']]['horizon'];
        }
        unset($member);
        $body = ['protocol' => SpecialistCouncilResearchFeedbackService::FOLLOWUP_PROTOCOL,
            'work_item_id' => $work->id, 'work_key' => $work->work_key,
            'source_receipt_id' => $work->research_experiment_receipt_id, 'work_type' => $work->work_type,
            'authority' => 'research_only', 'max_experiments' => 1, 'independent_evidence_claimed' => false,
            'promotion_evidence' => false, 'native_source_models' => $specs, 'manifest_template' => $manifest,
            'evaluation_plan' => $proposal['evaluation_plan'], 'current_source_hash' => str_repeat('f', 64),
            'current_python_source_hash' => str_repeat('a', 64), 'creator_id' => 'conditional-native-creator',
            'evaluator_id' => 'conditional-native-examiner', 'research_question' => 'net_return_at_equal_risk',
            'scientific_question_kind' => 'observed_probe_attestation_completion',
            'scientific_outcomes_may_have_been_observed' => true, 'original_observed_source_proof' => null,
            'original_observed_probe_completion_proof' => ['protocol' => 'specialist_council_observed_probe_completion_proof_v1',
                'max_completions_per_root' => 1, 'root' => ['root_version_id' => $version->id]],
            'scientific_novelty_claimed' => false, 'scientific_budget_renewed' => false];
        $owner = app(SpecialistCouncilResearchFeedbackService::class);
        $body = $this->sealBody($owner, $body);
        $work->update(['status' => 'leased', 'attempts' => 1, 'lease_token' => 'conditional-local-lease', 'fence_version' => 1,
            'lease_expires_at' => now()->addMinutes(45), 'payload' => [...$work->payload, 'followup_resolution' => $body],
            'result' => ['dependency_hold' => ['reason' => 'COUNCIL_FOLLOWUP_EXECUTOR_TECHNICAL_FAILURE',
                'prerequisite_hash' => str_repeat('9', 64), 'promotion_evidence' => false]]]);
        return [$work->fresh(), $body, $owner, $models, $actual];
    }

    private function sealBody(SpecialistCouncilResearchFeedbackService $owner, array $body): array
    {
        unset($body['resolution_hash'], $body['server_seal']);
        $body['resolution_hash'] = app(ResearchPaperEpochContractService::class)->parameterHash($body);
        $body['server_seal'] = (new \ReflectionMethod($owner, 'followupServerSeal'))->invoke($owner, $body);
        return $body;
    }

    /** No source IDs, cache/outcomes, provider data, keys or production admission flags. */
    private function actualNativeVectors(): array
    {
        $input = json_decode(<<<'JSON'
{
  "differential_router": {
    "trend_weight": 1,
    "breakout_weight": 1,
    "mean_reversion_weight": 1,
    "minimum_confidence": 1,
    "high_volatility_wait": true,
    "differential_target_min_signal_confidence": 0.34,
    "trend_down_strength_min": 20,
    "trend_down_pullback_atr_fraction": 0.75,
    "trend_down_risk_multiplier": 0.5,
    "trend_up_risk_multiplier": 1,
    "trend_up_strength_min": 20,
    "trend_up_pullback_atr_fraction": 0.75,
    "trend_up_roc_period": 12,
    "trend_up_roc_threshold": 0.2,
    "trend_up_ema_period": 50,
    "trend_down_roc_period": 12,
    "trend_down_roc_threshold": 0.2,
    "trend_down_ema_period": 50,
    "range_lookback": 20,
    "range_deviation": 2,
    "range_adx_max": 20,
    "range_low_volatility_only": false,
    "range_reentry_required": true,
    "range_signal_mode": "reentry",
    "trend_roc_period": 12,
    "trend_roc_threshold": 0.2,
    "trend_ema_period": 50,
    "breakout_atr_period": 14,
    "breakout_atr_threshold": 1.2,
    "breakout_lookback": 20,
    "breakout_compression_ratio": 0.75,
    "breakout_expansion_multiplier": 1.2,
    "session_filter_enabled": false,
    "session_start": 0,
    "session_end": 24,
    "differential_target_session_filter_enabled": false,
    "differential_target_session_start": 7,
    "differential_target_session_end": 16,
    "differential_target_regime": "trend_down",
    "differential_replay_mode": "paired_isolated",
    "differential_router_version": "v2",
    "volume_lane": "none",
    "atr_stop_multiplier": 1.5,
    "atr_target_multiplier": 2.5,
    "trailing_atr_multiplier": 0,
    "time_stop_candles": 0,
    "high_volatility_risk_multiplier": 0.5,
    "max_spread_atr_ratio": 0.25,
    "avoid_high_volatility": false,
    "minimum_signal_confidence": 0.35,
    "max_loss_streak_before_wait": 4,
    "loss_cooldown_candles": 4,
    "loss_streak_wait_candles": 4,
    "recovery_probe_risk_multiplier": 0.5,
    "weak_regime_min_samples": 15,
    "weak_regime_wait_candles": 4,
    "transition_firewall_enabled": false,
    "transition_wait_candles": 2,
    "state_machine_variant": "none",
    "entry_topology_variant": "frozen",
    "regime_classifier_variant": "frozen",
    "architecture_interaction_variant": "frozen",
    "confidence_calibration_enabled": true,
    "confidence_calibration_min_samples": 15,
    "confidence_ev_lower_bound_enabled": true,
    "temporal_survival_enabled": false,
    "adaptive_signal_expiry_enabled": false,
    "drift_abstention_enabled": false,
    "signal_max_age_candles": 2,
    "signal_decay_half_life_candles": 3,
    "temporal_followthrough_window": 3,
    "temporal_followthrough_min_rate": 0.4,
    "temporal_followthrough_atr_fraction": 0.25,
    "temporal_volatility_ratio_max": 2.5,
    "temporal_spread_atr_ratio_max": 0.25,
    "temporal_drift_zscore_max": 2.5,
    "temporal_confidence_decay_floor": 0.35,
    "temporal_loss_streak_limit": 4,
    "temporal_min_history": 12,
    "temporal_drift_lookback_candles": 48,
    "dynamic_cooldown_enabled": true,
    "cooldown_shadow_min_samples": 5,
    "cooldown_shadow_edge_pf": 1.1,
    "meta_label_enabled": false,
    "meta_label_min_history": 10,
    "meta_label_min_pf": 1,
    "meta_label_risk_multiplier": 0.5,
    "partial_take_profit_fraction": 0,
    "partial_target_atr_multiplier": 1
  },
  "regime_consensus": {
    "trend_weight": 1,
    "breakout_weight": 1,
    "mean_reversion_weight": 1,
    "minimum_confidence": 1,
    "high_volatility_wait": true,
    "trend_roc_period": 12,
    "trend_roc_threshold": 0.2,
    "trend_ema_period": 50,
    "breakout_atr_period": 14,
    "breakout_atr_threshold": 1.2,
    "breakout_lookback": 20,
    "breakout_compression_ratio": 0.75,
    "breakout_expansion_multiplier": 1.2,
    "range_lookback": 20,
    "range_deviation": 2,
    "range_adx_max": 20,
    "range_low_volatility_only": true,
    "range_reentry_required": true,
    "range_signal_mode": "reentry",
    "session_filter_enabled": false,
    "session_start": 0,
    "session_end": 24,
    "volume_lane": "none",
    "atr_stop_multiplier": 1.5,
    "atr_target_multiplier": 2.5,
    "trailing_atr_multiplier": 0,
    "time_stop_candles": 0,
    "high_volatility_risk_multiplier": 0.5,
    "max_spread_atr_ratio": 0.25,
    "avoid_high_volatility": false,
    "minimum_signal_confidence": 0.35,
    "max_loss_streak_before_wait": 4,
    "loss_cooldown_candles": 4,
    "loss_streak_wait_candles": 4,
    "recovery_probe_risk_multiplier": 0.5,
    "weak_regime_min_samples": 15,
    "weak_regime_wait_candles": 4,
    "transition_firewall_enabled": false,
    "transition_wait_candles": 2,
    "state_machine_variant": "none",
    "entry_topology_variant": "frozen",
    "regime_classifier_variant": "frozen",
    "architecture_interaction_variant": "frozen",
    "confidence_calibration_enabled": true,
    "confidence_calibration_min_samples": 15,
    "confidence_ev_lower_bound_enabled": true,
    "temporal_survival_enabled": false,
    "adaptive_signal_expiry_enabled": false,
    "drift_abstention_enabled": false,
    "signal_max_age_candles": 2,
    "signal_decay_half_life_candles": 3,
    "temporal_followthrough_window": 3,
    "temporal_followthrough_min_rate": 0.4,
    "temporal_followthrough_atr_fraction": 0.25,
    "temporal_volatility_ratio_max": 2.5,
    "temporal_spread_atr_ratio_max": 0.25,
    "temporal_drift_zscore_max": 2.5,
    "temporal_confidence_decay_floor": 0.35,
    "temporal_loss_streak_limit": 4,
    "temporal_min_history": 12,
    "temporal_drift_lookback_candles": 48,
    "dynamic_cooldown_enabled": true,
    "cooldown_shadow_min_samples": 5,
    "cooldown_shadow_edge_pf": 1.1,
    "meta_label_enabled": false,
    "meta_label_min_history": 10,
    "meta_label_min_pf": 1,
    "meta_label_risk_multiplier": 0.5,
    "partial_take_profit_fraction": 0,
    "partial_target_atr_multiplier": 1
  },
  "declared_execution": {
    "protocol": "canonical_market_execution_v1",
    "version": "canonical_market_execution_v1",
    "symbol": "XAUUSD",
    "timeframe": "M5",
    "parameters": {
      "spread_points": 35,
      "point_size": 0.01,
      "commission_percent": 0.01,
      "slippage_points": 2,
      "swap_per_day_percent": 0.002,
      "allowed_sessions_utc": [
        "1-22"
      ],
      "min_volume": null,
      "intrabar_policy": "conservative",
      "max_gap_multiple": 96,
      "reject_unexpected_gaps": true,
      "stop_loss_percent": 0.5,
      "take_profit_percent": 1,
      "max_leverage": 5
    },
    "execution_hash": "04403537588afd0e22efffc3e210b572ca660e8d751ab8d4dbdc7225a8fed7b5",
    "status": "sealed",
    "promotion_evidence": true,
    "rule": "Lab, full replay, paper and sealed holdout must use this exact parameter map."
  },
  "horizons": {
    "scalp": {
      "kind": "scalp",
      "decision_interval_seconds": 300,
      "reevaluation_interval_seconds": 300,
      "max_holding_seconds": 1800,
      "execution_precision": "candle"
    },
    "hour": {
      "kind": "hour",
      "decision_interval_seconds": 3600,
      "reevaluation_interval_seconds": 300,
      "max_holding_seconds": 14400,
      "execution_precision": "candle"
    },
    "day": {
      "kind": "day",
      "decision_interval_seconds": 14400,
      "reevaluation_interval_seconds": 3600,
      "max_holding_seconds": 86400,
      "execution_precision": "candle"
    },
    "swing": {
      "kind": "swing",
      "decision_interval_seconds": 86400,
      "reevaluation_interval_seconds": 14400,
      "max_holding_seconds": 604800,
      "execution_precision": "candle"
    }
  }
}
JSON, true, 512, JSON_THROW_ON_ERROR);
        $roles = [];
        foreach (['scalp', 'hour', 'day', 'swing'] as $role) {
            $router = in_array($role, ['scalp', 'hour'], true);
            $roles[$role] = ['parameters' => $input[$router ? 'differential_router' : 'regime_consensus'],
                'family' => $router ? 'differential_router' : 'hybrid',
                'architecture' => $router ? 'frozen_parent_differential_router' : 'regime_consensus',
                'base_strategy' => $router ? 'differential_router_v1' : 'regime_consensus_v1',
                'declared_execution' => $input['declared_execution'], 'horizon' => $input['horizons'][$role]];
        }
        return $roles;
    }
}
