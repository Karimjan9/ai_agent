<?php

namespace Tests\Feature;

use App\Models\InstrumentValuePosterior;
use App\Models\AgentLearningEpisode;
use App\Models\AgentLearningSettlement;
use App\Models\CanonicalLearningOutbox;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabLearningLanePair;
use App\Models\PlaybookComposition;
use App\Models\ResearchExperimentReceipt;
use App\Models\ResearchExperimentWorkItem;
use App\Services\InstrumentPolicyConsumptionService;
use App\Services\InstrumentPosteriorAuthorityService;
use App\Services\InstrumentResearchWindowService;
use App\Services\InstrumentValidationEvidenceService;
use App\Services\LabInstrumentResearchService;
use App\Services\ResearchExperimentConversionKernelService;
use App\Services\ResearchPaperEpochContractService;
use App\Services\StrategyParameterSchemaService;
use App\Services\TradingInstrumentOperatingSystemService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\InstrumentValidationFixture;
use Tests\TestCase;

class InstrumentValidationEpochTest extends TestCase
{
    use InstrumentValidationFixture;
    use RefreshDatabase;

    public function test_legacy_discovery_cannot_poison_new_exact_delta_validation_or_spill_to_other_genes(): void
    {
        [$operating, $bundle, $context, $baseline, $windows] = $this->setupProof();
        $discovery = ['evidence_key' => 'legacy-discovery', 'metrics' => ['net_edge' => -10],
            'control_metrics' => ['net_edge' => 0], 'control_contract' => ['paired_isolated' => true]];
        $old = $operating->recordEvidence('transition_protection', 'XAUUSD', 'M15', $context, $discovery);
        $operating->recordPlaybookEvidence($bundle->playbook_key, 'XAUUSD', 'M15', $context, [...$discovery, 'evidence_key' => 'legacy-bundle']);
        $this->assertSame('provisional', $old->decay_state);
        $this->assertSame([], data_get($old->value_vector, 'validation_epochs'));
        foreach ($windows as $index => $window) {
            $outcome = $this->outcome($context, $baseline, $window, 'new-proof-'.$index);
            $posterior = $operating->recordEvidence('transition_protection', 'XAUUSD', 'M15', $context, $outcome);
            $operating->recordPlaybookEvidence($bundle->playbook_key, 'XAUUSD', 'M15', $context,
                [...$outcome, 'evidence_key' => 'new-bundle-'.$index]);
        }
        $authority = app(InstrumentPosteriorAuthorityService::class)->assess($posterior);
        $this->assertSame(4, $posterior->observations);
        $this->assertLessThan(0, (float) $posterior->net_value);
        $this->assertSame('confirmed', $authority['canonical_state']);
        $this->assertSame(3, $authority['observations']);
        $this->assertSame(3, $authority['independent_windows']);
        $this->assertGreaterThan(0, $authority['net_value']);
        $policy = app(LabInstrumentResearchService::class)->mutationPolicy('XAUUSD', 'hybrid', $this->requested($context));
        $this->assertSame(['transition_wait_candles'], $policy['preferred_genes']);
        $this->assertSame([], $policy['blocked_genes']);
        $this->assertNotContains('transition_firewall_enabled', $policy['preferred_genes']);
        $consumer = app(InstrumentPolicyConsumptionService::class);
        $selected = [...$baseline, 'transition_wait_candles' => 6];
        $applied = $consumer->applyPreferredDelta($policy, $baseline, $selected, array_keys($baseline));
        $this->assertSame(5, $applied['transition_wait_candles']);
        $receipt = $consumer->receipt($policy, ['transition_wait_candles' => ['old' => 4, 'new' => 5]], $applied);
        $this->assertSame('policy_aligned_mutation_observed', $receipt['status']);
        $this->assertSame(4, $receipt['tested_interventions'][0]['old']);
        $this->assertSame(5, $receipt['tested_interventions'][0]['new']);
        $this->assertCount(3, $receipt['isolated_sources'][0]['source_receipts']);
        $this->assertSame($receipt['isolated_sources'][0]['source_receipts'], $receipt['source_to_parameter_lineage'][0]['source_receipts']);
        $this->assertSame(['transition_wait_candles' => ['old' => 4, 'new' => 5]], $receipt['source_to_parameter_lineage'][0]['actual_parameter_change']);
        $this->assertSame($receipt['resulting_parameter_hash'], $receipt['source_to_parameter_lineage'][0]['resulting_parameter_hash']);
        $this->assertTrue($receipt['source_to_parameter_lineage'][0]['comparison_and_ablation_still_required']);
        $this->assertFalse($receipt['paper_execution_authority']);
        $this->assertFalse($receipt['promotion_evidence']);
        $this->assertSame(4, $operating->recordEvidence('transition_protection', 'XAUUSD', 'M15', $context, $outcome)->observations);
    }

    public function test_same_gene_wrong_value_old_baseline_and_source_context_are_not_consumption(): void
    {
        [$operating, $bundle, $context, $baseline, $windows] = $this->setupProof();
        $this->recordProof($operating, $bundle, $context, $baseline, $windows);
        $policy = app(LabInstrumentResearchService::class)->mutationPolicy('XAUUSD', 'hybrid', $this->requested($context));
        $consumer = app(InstrumentPolicyConsumptionService::class);
        foreach ([[4, 6], [3, 5], [5, 4]] as [$old, $new]) {
            $this->assertSame('not_applied', $consumer->receipt($policy,
                ['transition_wait_candles' => compact('old', 'new')], [...$baseline, 'transition_wait_candles' => $new])['status']);
        }
        $other = [...$baseline, 'transition_firewall_enabled' => ! $baseline['transition_firewall_enabled']];
        $this->assertSame($other, $consumer->applyPreferredDelta($policy, $baseline, $other, array_keys($baseline)));
        $this->assertSame('not_applied', $consumer->receipt($policy,
            ['transition_firewall_enabled' => ['old' => $baseline['transition_firewall_enabled'], 'new' => $other['transition_firewall_enabled']]], $other)['status']);
        $poisoned = $policy;
        $poisoned['preferred_deltas'][0]['source']['context']['venue_phase'] = 'london_interfix';
        $poisoned['context']['venue_phase'] = 'london_interfix';
        $this->assertSame('not_applied', $consumer->receipt($poisoned,
            ['transition_wait_candles' => ['old' => 4, 'new' => 5]], [...$baseline, 'transition_wait_candles' => 5])['status']);
        $incomplete = $this->requested($context);
        unset($incomplete['direction']);
        $this->assertSame([], app(LabInstrumentResearchService::class)->mutationPolicy('XAUUSD', 'hybrid', $incomplete)['preferred_genes']);
    }

    public function test_different_delta_or_epoch_cannot_borrow_independent_windows(): void
    {
        [$operating, $bundle, $context, $baseline, $windows] = $this->setupProof();
        $this->recordProof($operating, $bundle, $context, $baseline, $windows);
        $new = $this->outcome($context, $baseline, $windows[0], 'different-delta', 6);
        $posterior = $operating->recordEvidence('transition_protection', 'XAUUSD', 'M15', $context, $new);
        $assessments = app(InstrumentPosteriorAuthorityService::class)->validationEpochs($posterior);
        $this->assertCount(2, $assessments);
        $this->assertSame('confirmed', $assessments[0]['canonical_state']);
        $this->assertSame('provisional', $assessments[1]['canonical_state']);
        $this->assertSame(1, $assessments[1]['independent_windows']);
        $policy = app(LabInstrumentResearchService::class)->mutationPolicy('XAUUSD', 'hybrid', $this->requested($context));
        $this->assertSame([5], array_column($policy['preferred_deltas'], 'new'));
        $manifests = config('services.instrument_policy.authorized_research_windows');
        $manifests[0]['research_epoch_id'] = 'another-validation-epoch';
        config()->set('services.instrument_policy.authorized_research_windows', $manifests);
        $window = app(InstrumentResearchWindowService::class)->seal($manifests[0]['authorization_id'], $manifests[0]['dataset_sha256']);
        $posterior = $operating->recordEvidence('transition_protection', 'XAUUSD', 'M15', $context,
            $this->outcome($context, $baseline, $window, 'different-epoch'));
        $this->assertCount(3, data_get($posterior->value_vector, 'validation_epochs'));
        $this->assertFalse(app(InstrumentPosteriorAuthorityService::class)->assess($posterior)['verified_confirmed']);
    }

    public function test_revocation_tampered_statistics_source_and_non_target_regression_fail_closed(): void
    {
        [$operating, $bundle, $context, $baseline, $windows] = $this->setupProof();
        $posterior = $this->recordProof($operating, $bundle, $context, $baseline, $windows);
        $original = $posterior->value_vector;
        $key = array_key_first($original['validation_epochs']);
        foreach (['net_value', 'observations'] as $field) {
            $poison = $original;
            $poison['validation_epochs'][$key][$field] += 10;
            $posterior->value_vector = $poison;
            $this->assertFalse(app(InstrumentPosteriorAuthorityService::class)->assess($posterior)['verified_confirmed']);
        }
        $poison = $original;
        $poison['validation_epochs'][$key]['window_evidence'][0]['source_receipt']['control_agent_id'] = 999;
        $posterior->value_vector = $poison;
        $this->assertFalse(app(InstrumentPosteriorAuthorityService::class)->assess($posterior)['verified_confirmed']);
        $posterior->value_vector = $original;
        $outcome = $this->outcome($context, $baseline, $windows[0], 'non-target-regression');
        $outcome['metrics']['non_target_regression'] = 1;
        $posterior = $operating->recordEvidence('transition_protection', 'XAUUSD', 'M15', $context, $outcome);
        $this->assertFalse(app(InstrumentPosteriorAuthorityService::class)->assess($posterior)['verified_confirmed']);
        $manifests = config('services.instrument_policy.authorized_research_windows');
        array_pop($manifests);
        config()->set('services.instrument_policy.authorized_research_windows', $manifests);
        $this->assertFalse(app(InstrumentPosteriorAuthorityService::class)->assess($posterior)['checks']['sealed_window_evidence_complete']);
    }

    public function test_legacy_complete_window_aggregate_is_not_retroactively_given_delta_authority(): void
    {
        [$operating, $bundle, $context, $baseline, $windows] = $this->setupProof();
        $state = $operating->fingerprint('XAUUSD', 'M15', $context);
        $posterior = new InstrumentValuePosterior(['state_key' => $state['state_key'], 'observations' => 3,
            'net_value' => .5, 'decay_state' => 'confirmed', 'value_vector' => ['context' => $state,
                'window_evidence' => array_map(fn (array $window, int $index): array => [
                    'window' => $window, 'evidence_key' => 'old-'.$index, 'outcome' => 'positive'], $windows, array_keys($windows)),
                'positive_observations' => 3, 'negative_observations' => 0, 'non_target_regression_count' => 0]]);
        $this->assertSame('status_only_quarantined', app(InstrumentPosteriorAuthorityService::class)->assess($posterior)['canonical_state']);
    }

    public function test_rehashed_sources_still_need_the_actual_verified_pair_and_completed_run(): void
    {
        [$operating, $bundle, $context, $baseline, $windows] = $this->setupProof();
        $validation = app(InstrumentValidationEvidenceService::class);
        foreach (['pair_key', 'candidate_agent_id', 'candidate_response_hash'] as $field) {
            $outcome = $this->outcome($context, $baseline, $windows[0], 'rehashed-'.$field);
            $source = $outcome['source_receipt'];
            $source[$field] = $field === 'candidate_agent_id' ? 999999 : str_repeat('f', 64);
            $outcome['source_receipt'] = $validation->sealSource($source);
            $posterior = $operating->recordEvidence('transition_protection', 'XAUUSD', 'M15', $context, $outcome);
            $this->assertSame([], data_get($posterior->value_vector, 'validation_epochs'));
            $this->assertSame('provisional', $posterior->decay_state);
        }
        $outcome = $this->outcome($context, $baseline, $windows[0], 'incomplete-run');
        LabEvaluationRun::findOrFail($outcome['source_receipt']['candidate_evidence_run_id'])->update(['status' => 'running']);
        $posterior = $operating->recordEvidence('transition_protection', 'XAUUSD', 'M15', $context, $outcome);
        $this->assertSame([], data_get($posterior->value_vector, 'validation_epochs'));
        $this->assertFalse(app(InstrumentPosteriorAuthorityService::class)->assess($posterior)['verified_confirmed']);
    }

    public function test_harmful_exact_delta_is_blocked_without_banning_other_values_or_genes(): void
    {
        [$operating, $bundle, $context, $baseline, $windows] = $this->setupProof();
        foreach ($windows as $index => $window) {
            $outcome = $this->outcome($context, $baseline, $window, 'negative-'.$index);
            $outcome['metrics']['net_edge'] = -.05;
            $posterior = $operating->recordEvidence('transition_protection', 'XAUUSD', 'M15', $context, $outcome);
            $operating->recordPlaybookEvidence($bundle->playbook_key, 'XAUUSD', 'M15', $context, [...$outcome, 'evidence_key' => 'negative-bundle-'.$index]);
        }
        $this->assertTrue(app(InstrumentPosteriorAuthorityService::class)->assess($posterior)['verified_forbidden']);
        $policy = app(LabInstrumentResearchService::class)->mutationPolicy('XAUUSD', 'hybrid', $this->requested($context));
        $this->assertSame([], $policy['blocked_genes']);
        $this->assertSame(['transition_wait_candles'], array_column($policy['blocked_deltas'], 'gene'));
        $consumer = app(InstrumentPolicyConsumptionService::class);
        $this->assertTrue($consumer->forbiddenDelta($policy, ['transition_wait_candles' => ['old' => 4, 'new' => 5]],
            [...$baseline, 'transition_wait_candles' => 5]));
        $this->assertFalse($consumer->forbiddenDelta($policy, ['transition_wait_candles' => ['old' => 4, 'new' => 6]],
            [...$baseline, 'transition_wait_candles' => 6]));
        $posterior->update(['value_vector' => [...$posterior->value_vector, 'temporal_decay' => .8]]);
        $this->assertSame([], app(LabInstrumentResearchService::class)->mutationPolicy('XAUUSD', 'hybrid', $this->requested($context))['blocked_deltas']);
    }

    public function test_changed_baseline_creates_only_a_verified_bounded_transfer_hypothesis(): void
    {
        [$operating, $bundle, $context, $baseline, $windows] = $this->setupProof();
        $this->recordProof($operating, $bundle, $context, $baseline, $windows);
        $policy = app(LabInstrumentResearchService::class)->mutationPolicy('XAUUSD', 'hybrid', $this->requested($context));
        $consumer = app(InstrumentPolicyConsumptionService::class);
        $this->assertSame([], $consumer->transferHypotheses($policy, $baseline));
        $this->assertSame([], $consumer->transferHypotheses($policy, [...$baseline, 'transition_wait_candles' => 5]));
        $changed = [...$baseline, 'atr_stop_multiplier' => $baseline['atr_stop_multiplier'] + .1];
        $candidate = [...$changed, 'transition_wait_candles' => 5];
        $this->assertSame('not_applied', $consumer->receipt($policy,
            ['transition_wait_candles' => ['old' => 4, 'new' => 5]], $candidate)['status']);
        $independentChoice = [...$changed, 'transition_wait_candles' => 6];
        $this->assertSame($independentChoice, $consumer->applyPreferredDelta($policy, $changed, $independentChoice, array_keys($baseline)));
        $hypotheses = $consumer->transferHypotheses($policy, $candidate);
        $this->assertCount(1, $hypotheses);
        $hypothesis = $hypotheses[0];
        $hashes = app(ResearchPaperEpochContractService::class);
        $this->assertSame('untested_changed_baseline', $hypothesis['status']);
        $this->assertSame($hashes->parameterHash($changed), $hypothesis['proposed_control_parameter_hash']);
        $this->assertSame($hashes->parameterHash($candidate), $hypothesis['proposed_candidate_parameter_hash']);
        $this->assertSame($candidate, $hypothesis['recipient_parameter_snapshot']);
        $this->assertSame($changed, $hypothesis['proposed_control_parameters']);
        $this->assertSame($candidate, $hypothesis['proposed_candidate_parameters']);
        $this->assertSame(['transition_wait_candles' => ['old' => 4, 'new' => 5]], $hypothesis['proposed_parameter_diff']);
        $this->assertSame($policy['preferred_deltas'][0]['source']['source_receipts'], $hypothesis['source']['source_receipts']);
        $this->assertTrue($hypothesis['trait_already_present']);
        $this->assertTrue($hypothesis['equal_compute_budget_required']);
        $this->assertTrue($hypothesis['authorized_unused_validation_window_required']);
        $this->assertContains('matched_trait_ablation', $hypothesis['required_comparisons']);
        $this->assertFalse($hypothesis['retained_benefit']);
        $this->assertFalse($hypothesis['independent_evidence']);
        $this->assertFalse($hypothesis['paper_execution_authority']);
        $this->assertSame('requires_canonical_transfer_admission', $hypothesis['canonical_transfer_admission']['status']);
        $this->assertSame(\App\Services\CanonicalSkillCartridgeService::class, $hypothesis['canonical_transfer_admission']['owner']);
        $this->assertFalse($hypothesis['canonical_transfer_admission']['admitted']);
        $this->assertSame($hypotheses, $consumer->transferHypotheses($policy, $candidate));
        $poison = $policy;
        $poison['preferred_deltas'][0]['source']['context']['venue_phase'] = 'london_interfix';
        $poison['context']['venue_phase'] = 'london_interfix';
        $this->assertSame([], $consumer->transferHypotheses($poison, $candidate));
        $poison = $policy;
        $poison['preferred_deltas'][0]['bundle_sources'] = [];
        $this->assertSame([], $consumer->transferHypotheses($poison, $candidate));
        config()->set('services.instrument_policy.authorized_research_windows', []);
        $this->assertSame([], $consumer->transferHypotheses($policy, $candidate));
    }

    public function test_committed_actual_recipient_projects_one_blocked_transfer_work_without_credit(): void
    {
        [$operating, $bundle, $context, $baseline, $windows] = $this->setupProof();
        $this->recordProof($operating, $bundle, $context, $baseline, $windows);
        $policy = app(LabInstrumentResearchService::class)->mutationPolicy('XAUUSD', 'hybrid', $this->requested($context));
        $changed = [...$baseline, 'atr_stop_multiplier' => $baseline['atr_stop_multiplier'] + .1];
        // Synthetic canonical facts exercise the handoff; not 2026 market/independence proof.
        $facts = $this->exactValidationFacts($context, $windows[0], 'transfer-recipient', 'transition_wait_candles', 4, 5, $changed);
        $pair = LabLearningLanePair::where('pair_key', $facts['source_receipt']['pair_key'])->firstOrFail();
        $agent = LabAgent::findOrFail($pair->candidate_agent_id);
        $agent->modelVersion->update(['metadata' => [...$agent->modelVersion->metadata, 'instrument_learning_policy' => $policy]]);
        $pair->update(['failure_signature' => ['state' => $this->requested($context)]]);
        $run = LabEvaluationRun::where('run_id', $pair->candidate_evidence_run_id)->firstOrFail();
        $run->update(['request_meta' => ['payload' => ['timeframe' => 'M15']]]);
        $episode = AgentLearningEpisode::create(['episode_id' => (string) Str::uuid(), 'decision_key' => 'transfer-recipient',
            'lab_agent_id' => $agent->id, 'model_version_id' => $agent->model_version_id,
            'symbol' => 'XAUUSD', 'timeframe' => 'H1', 'strategy_family' => 'hybrid', 'status' => 'settled',
            'context_hash' => hash('sha256', 'synthetic-context'), 'decision_context' => [], 'opened_at' => now()]);
        AgentLearningSettlement::create(['settlement_id' => (string) Str::uuid(), 'episode_id' => $episode->id,
            'source_key' => 'transfer-recipient-settlement', 'source_type' => LabLearningLanePair::class, 'source_id' => $pair->id,
            'outcome_status' => 'settled', 'evidence_state' => 'uncertain', 'outcome' => [], 'settled_at' => now()]);
        $outbox = CanonicalLearningOutbox::create(['idempotency_key' => hash('sha256', 'transfer-outbox'),
            'kind' => 'canonical_episode', 'status' => 'pending', 'pair_id' => $pair->id, 'evidence_run_id' => $run->run_id,
            'data_hash' => $pair->candidate_data_hash, 'execution_hash' => $pair->candidate_execution_hash,
            'payload' => ['result' => ['timeframe' => 'M15']]]);
        $consumer = app(InstrumentPolicyConsumptionService::class);
        $this->assertSame('blocked', $consumer->recordTransferNextWork($agent, $outbox)['status']);
        $this->assertDatabaseCount('research_experiment_work_items', 0);
        $outbox->update(['status' => 'completed']);
        $pair->update(['failure_signature' => ['state' => [...$this->requested($context), 'venue_phase' => 'london_interfix']]]);
        $this->assertSame('not_proposed', $consumer->recordTransferNextWork($agent, $outbox)['status']);
        $this->assertDatabaseCount('research_experiment_work_items', 0);
        $pair->update(['failure_signature' => ['state' => $this->requested($context)]]);
        $before = [$agent->fresh()->toArray(), $agent->modelVersion->fresh()->toArray(), $run->fresh()->toArray(), $outbox->fresh()->toArray()];
        $projected = $consumer->recordTransferNextWork($agent, $outbox);
        $this->assertSame('recorded', $projected['status']);
        $this->assertSame('blocked', $projected['work_status']);
        $work = ResearchExperimentWorkItem::findOrFail($projected['work_id']);
        $receipt = ResearchExperimentReceipt::findOrFail($projected['receipt_id']);
        $this->assertSame('instrument_exact_delta_transfer', $work->work_type);
        $this->assertFalse($work->payload['executable']);
        $this->assertSame(1, data_get($work->payload, 'retry_condition.max_experiments'));
        $this->assertSame('requires_canonical_transfer_admission', data_get($work->payload, 'canonical_transfer_admission.status'));
        $this->assertSame($agent->model_version_id, data_get($receipt->payload, 'evidence.recipient_model_version_id'));
        $this->assertSame($run->parameter_hash, data_get($receipt->payload, 'evidence.recipient_parameter_hash'));
        $this->assertTrue(data_get($receipt->payload, 'evidence.hypothesis_only'));
        $this->assertFalse(data_get($receipt->payload, 'evidence.answered_comparison'));
        $this->assertFalse(data_get($receipt->payload, 'evidence.retained_benefit'));
        $this->assertSame('M15', $receipt->execution_timeframe);
        $this->assertSame($projected, $consumer->recordTransferNextWork($agent, $outbox));
        $this->assertDatabaseCount('research_experiment_work_items', 1);
        $this->assertSame($before, [$agent->fresh()->toArray(), $agent->modelVersion->fresh()->toArray(), $run->fresh()->toArray(), $outbox->fresh()->toArray()]);
        $this->assertSame([], app(ResearchExperimentConversionKernelService::class)->claimForOwner(\App\Services\ResearchLoopArbiterService::class));
        $agent->modelVersion->update(['parameters' => [...$agent->modelVersion->parameters, 'transition_wait_candles' => 6]]);
        $this->assertSame('blocked', $consumer->recordTransferNextWork($agent, $outbox)['status']);
        $this->assertDatabaseCount('research_experiment_work_items', 1);
    }

    private function setupProof(): array
    {
        $this->travelTo(CarbonImmutable::parse('2028-01-01', 'UTC'));
        $manifests = [];
        foreach ([1, 2, 3] as $month) {
            $manifests[] = ['authorization_id' => 'delta-window-'.$month, 'research_epoch_id' => 'delta-validation-v2',
                'start_inclusive' => sprintf('2027-%02d-01T00:00:00Z', $month),
                'end_exclusive' => sprintf('2027-%02d-01T00:00:00Z', $month + 1),
                'dataset_sha256' => hash('sha256', 'delta-data-'.$month), 'purpose' => 'instrument_independent_validation'];
        }
        config()->set('services.instrument_policy.authorized_research_windows', $manifests);
        $windows = array_map(fn (array $row): array => app(InstrumentResearchWindowService::class)->seal($row['authorization_id'], $row['dataset_sha256']), $manifests);
        $operating = app(TradingInstrumentOperatingSystemService::class);
        $operating->seedDefaults();
        $bundle = PlaybookComposition::create(['playbook_key' => 'delta-proof-bundle', 'label' => 'Delta proof', 'symbol' => 'XAUUSD',
            'timeframe' => 'M15', 'promotion_state' => 'research_only', 'instrument_keys' => ['transition_protection', 'atr_risk_envelope'],
            'metadata' => ['protocol' => 'exact_instrument_research_bundle_v1', 'primary_instrument_key' => 'transition_protection', 'router_eligible' => false]]);
        $context = ['regime' => 'transition', 'session' => 'london', 'volatility' => 'normal', 'direction' => 'BUY',
            'venue_phase' => 'london_am_fix', 'strategy_family' => 'hybrid', 'spread_atr_ratio' => .1];
        // Match the model's persisted JSON representation, not a pre-save
        // floating/integer representation which was never the frozen source.
        $baseline = json_decode(json_encode([...app(StrategyParameterSchemaService::class)->defaults('hybrid'),
            'transition_wait_candles' => 4]), true);

        return [$operating, $bundle, $context, $baseline, $windows];
    }

    private function outcome(array $context, array $baseline, array $window, string $key, int $new = 5): array
    {
        return [...$this->exactValidationFacts($context, $window, $key, 'transition_wait_candles', 4, $new, $baseline),
            'evidence_key' => $key, 'instrument_research_window_receipt' => $window,
            'control_contract' => ['paired_isolated' => true, 'data_hash' => $window['dataset_sha256']],
            'control_metrics' => ['net_edge' => 0], 'metrics' => ['net_edge' => .05, 'non_target_regression' => 0]];
    }

    private function recordProof($operating, $bundle, array $context, array $baseline, array $windows): InstrumentValuePosterior
    {
        foreach ($windows as $index => $window) {
            $outcome = $this->outcome($context, $baseline, $window, 'proof-'.$index);
            $posterior = $operating->recordEvidence('transition_protection', 'XAUUSD', 'M15', $context, $outcome);
            $operating->recordPlaybookEvidence($bundle->playbook_key, 'XAUUSD', 'M15', $context, [...$outcome, 'evidence_key' => 'bundle-'.$index]);
        }

        return $posterior;
    }

    private function requested(array $context): array
    {
        return [...$context, 'spread_liquidity_state' => 'normal', 'transition_state' => 'transition', 'direction' => 'buy'];
    }
}
