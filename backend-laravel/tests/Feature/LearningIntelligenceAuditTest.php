<?php

namespace Tests\Feature;

use App\Models\AgentLearningCausalExperiment;
use App\Models\AgentLearningEpisode;
use App\Models\AgentLearningSettlement;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\LabLearningLanePair;
use App\Models\ModelVersion;
use App\Services\LearningIntelligenceAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LearningIntelligenceAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_separates_screening_diagnostics_from_canonical_and_causal_learning(): void
    {
        $lab = AiLaboratory::create([
            'name' => 'Learning intelligence XAUUSD organism',
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_families' => ['hybrid'],
            'lifecycle_mode' => 'lighthouse',
        ]);
        $generation = LabGeneration::create([
            'ai_laboratory_id' => $lab->id,
            'generation' => 1,
            'trigger_type' => 'test',
            'status' => 'screened',
        ]);
        $model = ModelVersion::create([
            'name' => 'learning-intelligence-model',
            'strategy' => 'hybrid',
            'version' => 'v1',
            'generation' => 1,
            'status' => 'testing',
            'parameters' => [],
            'metadata' => [],
        ]);
        $agent = LabAgent::create([
            'lab_generation_id' => $generation->id,
            'model_version_id' => $model->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'hybrid',
            'origin' => 'test',
            'lifecycle_status' => 'screened',
            'parameter_diff' => [],
        ]);

        $screeningEpisode = $this->episode($agent, 'audit-screening');
        AgentLearningSettlement::create([
            'settlement_id' => (string) Str::uuid(),
            'episode_id' => $screeningEpisode->id,
            'source_key' => 'audit-screening',
            'source_type' => LabAgent::class,
            'source_id' => $agent->id,
            'outcome_status' => 'settled',
            'evidence_state' => 'insufficient_evidence',
            'selection_reward' => .72,
            'hard_failure' => false,
            'outcome' => ['reward_stage' => 'screening_diagnostic'],
            'reward_components' => [
                'edge_quality' => .7,
                'cost_adjusted_return' => .8,
                'signal_authority' => 'diagnostic_partial_quality',
                'promotion_evidence' => false,
            ],
            'settled_at' => now(),
        ]);

        $canonicalEpisode = $this->episode($agent, 'audit-canonical');
        AgentLearningSettlement::create([
            'settlement_id' => (string) Str::uuid(),
            'episode_id' => $canonicalEpisode->id,
            'source_key' => 'audit-canonical',
            'source_type' => LabLearningLanePair::class,
            'source_id' => 77,
            'outcome_status' => 'settled',
            'evidence_state' => 'positive',
            'selection_reward' => .81,
            'hard_failure' => false,
            'outcome' => [],
            'reward_components' => ['promotion_evidence' => false],
            'settled_at' => now()->addSecond(),
        ]);

        AgentLearningCausalExperiment::create([
            'experiment_key' => hash('sha512', 'learning-intelligence-relative-winner'),
            'lab_generation_id' => $generation->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'hybrid',
            'target' => 'profit_factor',
            'gene_key' => 'entry_threshold',
            'status' => 'provisional',
            'guided_beats_blinded' => true,
            'guided_beats_control' => true,
            'evidence' => [
                'component_effect' => ['passed' => true, 'target_effect' => ['passed' => true]],
                'selector_effect' => ['passed' => true, 'target_effect' => ['passed' => true]],
                'confirmation_blockers' => ['GUIDED_ABSOLUTE_VIABILITY_FAILED'],
                'repair_frontier' => ['research_ratchet' => [
                    'allowed' => false,
                    'reason_codes' => ['NON_TARGET_EVIDENCE_NOT_EXPLICITLY_PASSED'],
                ]],
                'promotion_evidence' => false,
            ],
        ]);
        // A persisted status label without declared-target, absolute-viability
        // and explicit non-target proof must remain legacy audit evidence. It
        // cannot make the monitor report a causally confirmed experiment.
        AgentLearningCausalExperiment::create([
            'experiment_key' => hash('sha512', 'learning-intelligence-legacy-confirmed-label'),
            'lab_generation_id' => $generation->id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'hybrid',
            'target' => 'profit_factor',
            'gene_key' => 'legacy_entry_threshold',
            'status' => 'confirmed',
            'guided_beats_blinded' => true,
            'guided_beats_control' => true,
            'evidence' => [
                'component_effect' => ['passed' => true],
                'selector_effect' => ['passed' => true],
                'promotion_evidence' => false,
            ],
        ]);

        $snapshot = app(LearningIntelligenceAuditService::class)->snapshot('xauusd', 'h1', 90);

        $this->assertSame(LearningIntelligenceAuditService::PROTOCOL, $snapshot['protocol']);
        $this->assertSame(2, data_get($snapshot, 'recent_settlement_window.size'));
        $this->assertSame(1, data_get($snapshot, 'recent_settlement_window.screening_diagnostic'));
        $this->assertSame(1, data_get($snapshot, 'recent_settlement_window.canonical_paired'));
        $this->assertSame(1, data_get($snapshot, 'canonical_learning.positive_absolute_settlements'));
        $this->assertSame(0, data_get($snapshot, 'canonical_learning.confirmed_causal_experiments'));
        $this->assertSame(1, data_get($snapshot, 'canonical_learning.legacy_or_unverified_confirmed_experiment_labels'));
        $this->assertSame(1, data_get($snapshot, 'causal_progress.guided_beats_both_counterfactuals'));
        $this->assertSame(1, data_get($snapshot, 'causal_progress.target_aligned_counterfactual_wins'));
        $this->assertSame(2, data_get($snapshot, 'causal_progress.legacy_or_generic_window_wins'));
        $this->assertSame(0, data_get($snapshot, 'causal_progress.research_ratchet_eligible'));
        $this->assertSame('experiment_proposal_only', data_get($snapshot, 'knowledge_blocks.research_inbox.authority'));
        $this->assertSame(1, data_get($snapshot, 'knowledge_blocks.research_inbox.audit_only_false_confirmed'));
        $this->assertFalse(data_get($snapshot, 'knowledge_blocks.research_inbox.direct_inheritance_allowed'));
        $this->assertSame(0, data_get($snapshot, 'knowledge_blocks.proven_skill_registry.verified_skill_lessons'));
        $this->assertSame(0, data_get($snapshot, 'authority_state_machine.states.causally_confirmed'));
        $this->assertSame(1, data_get($snapshot, 'authority_state_machine.states.revoked'));
        $this->assertTrue(data_get($snapshot, 'authority_state_machine.status_labels_are_not_authority'));
        $this->assertSame(
            1,
            data_get($snapshot, 'causal_progress.research_ratchet_blockers.NON_TARGET_EVIDENCE_NOT_EXPLICITLY_PASSED'),
        );
        $this->assertSame('causal_relative_progress_not_yet_compounded', $snapshot['learning_status']);
        $this->assertTrue($snapshot['attention_required']);
        $this->assertFalse($snapshot['promotion_evidence']);
    }

    private function episode(LabAgent $agent, string $key): AgentLearningEpisode
    {
        return AgentLearningEpisode::create([
            'episode_id' => (string) Str::uuid(),
            'decision_key' => $key,
            'lab_agent_id' => $agent->id,
            'model_version_id' => $agent->model_version_id,
            'symbol' => 'XAUUSD',
            'timeframe' => 'H1',
            'strategy_family' => 'hybrid',
            'stage' => 'screening',
            'status' => 'settled',
            'context_hash' => hash('sha256', $key),
            'decision_context' => [],
            'opened_at' => now(),
            'settled_at' => now(),
        ]);
    }
}
