<?php

namespace App\Services;

use App\Models\MtfAgentValidationRun;
use App\Models\MtfPlaybookFrozenControlRun;
use Illuminate\Support\Facades\Schema;

/**
 * Exposes the practitioner playbook catalogue as research tools for agents.
 *
 * The catalogue is deliberately a curriculum and prior library, rather than
 * a signal router.  A result obtained by another model (or a global frozen
 * replay) is a useful prior, never evidence that the current agent has
 * mastered the playbook.  The agent earns that claim only through its own
 * paired replay and later paper-shadow outcomes.
 */
class AgentResearchPlaybookToolboxService
{
    public const PROTOCOL = 'agent_research_playbook_toolbox_v1';

    private ?bool $frozenRunTableExists = null;

    private ?bool $agentValidationTableExists = null;

    public function __construct(
        private StrategyResearchCatalogueService $catalogue,
        private ResearchPlaybookConflictResolutionService $conflicts,
        private MtfPlaybookFrozenControlService $frozenRunner,
        private TradingOperatingSystemScorecardService $operatingSystem,
    ) {}

    /** @return array<string,mixed> */
    public function forAgent(array $agent = [], string $symbol = 'XAUUSD', string $timeframe = 'M5', array $context = []): array
    {
        $symbol = strtoupper(str_replace(['/', '_', '-'], '', trim($symbol)));
        $timeframe = strtoupper(trim($timeframe));
        $models = array_values((array) data_get($this->catalogue->catalogue(), 'models', []));
        $evidence = $this->frozenEvidence($symbol);
        $tools = array_map(fn (array $model): array => $this->tool($model, $evidence[$model['id']] ?? null), $models);
        $stage = (string) ($agent['mastery_stage'] ?? 'apprentice');
        $activeCount = match ($stage) {
            'validated_specialist', 'strategy_master_candidate', 'master' => 3,
            'specialist' => 2,
            default => 1,
        };
        $studySet = array_slice($this->studyOrder($tools, $agent, $context), 0, $activeCount);
        $activeIds = array_column($studySet, 'id');
        $agentKey = filled($agent['id'] ?? null) ? 'lab-agent:'.$agent['id'] : 'unbound-agent';

        return [
            'protocol' => self::PROTOCOL,
            'status' => 'research_toolbox_available',
            'agent_evidence_owner' => $agentKey,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'catalogue_size' => count($tools),
            'tools' => $tools,
            'active_study_set' => $studySet,
            'rotation_queue' => array_values(array_filter($tools, fn (array $tool): bool => ! in_array($tool['id'], $activeIds, true))),
            'experience_contract' => [
                'global_frozen_control_is_prior_only' => true,
                'agent_owned_evidence_required' => ['paired_frozen_replay', 'independent_windows', 'paper_shadow_outcomes'],
                'results_may_refine_selection_not_rewrite_history' => true,
                'one_playbook_per_trial' => true,
                'promotion_evidence' => false,
            ],
            'model_dispatch_policy' => $this->conflicts->contract(),
            'creative_window' => $this->creativeWindow($agent, $activeIds),
            'execution_boundary' => [
                'toolbox_is_not_a_signal_router' => true,
                'no_risk_or_position_authority' => true,
                'risk_sentinel_veto_preserved' => true,
                'live_execution' => false,
                'promotion_evidence' => false,
            ],
        ];
    }

    /**
     * Select one missing frozen-prior experiment. This is intentionally a
     * scheduler hint, not a learning or execution decision.
     *
     * @return array<string,mixed>
     */
    public function nextFrozenPriorTrial(
        array $agent = [],
        string $symbol = 'XAUUSD',
        array $context = [],
        array $preferredIds = [],
    ): array {
        $toolbox = $this->forAgent($agent, $symbol, 'M5', $context);
        $ordered = [
            ...((array) data_get($toolbox, 'active_study_set', [])),
            ...((array) data_get($toolbox, 'rotation_queue', [])),
        ];
        $dependencyBlocked = collect($ordered)
            ->filter(fn (array $candidate): bool => in_array('related_market', (array) ($candidate['required_streams'] ?? []), true)
                && blank($context['related_symbol'] ?? null))
            ->pluck('id')->map('strval')->values()->all();
        $ordered = array_values(array_filter($ordered, fn (array $candidate): bool => ! in_array(
            (string) ($candidate['id'] ?? ''),
            $dependencyBlocked,
            true,
        )));
        $preferredIds = array_values(array_unique(array_filter(array_map('strval', $preferredIds))));
        if ($preferredIds !== []) {
            $rank = array_flip($preferredIds);
            $ordered = collect($ordered)
                ->map(fn (array $candidate, int $index): array => [
                    'candidate' => $candidate,
                    'rank' => $rank[(string) ($candidate['id'] ?? '')] ?? PHP_INT_MAX,
                    'original_index' => $index,
                ])
                ->sortBy(fn (array $entry): array => [$entry['rank'], $entry['original_index']])
                ->pluck('candidate')
                ->values()
                ->all();
        }
        $tool = collect($ordered)->first(
            fn (array $candidate): bool => data_get($candidate, 'frozen_control_prior.status') === 'not_yet_replayed',
        );

        return $tool ? [
            'protocol' => self::PROTOCOL,
            'status' => 'ready',
            'tool' => $tool,
            'dependency_blocked_model_ids' => $dependencyBlocked,
            'agent_owned_evidence' => false,
            'promotion_evidence' => false,
        ] : [
            'protocol' => self::PROTOCOL,
            'status' => $dependencyBlocked === [] ? 'catalogue_frozen_priors_complete' : 'catalogue_waiting_for_dependencies',
            'tool' => null,
            'dependency_blocked_model_ids' => $dependencyBlocked,
            'agent_owned_evidence' => false,
            'promotion_evidence' => false,
        ];
    }

    /** @param array<string,mixed> $model @param array<string,mixed>|null $run @return array<string,mixed> */
    private function tool(array $model, ?array $run): array
    {
        $comparison = (array) ($run['comparison'] ?? []);
        $agentValidation = (array) ($run['agent_owned_validation'] ?? []);
        $interpretation = (string) data_get($comparison, 'interpretation', '');
        // Older immutable prior rows predate the activity-power contract.
        // Correct their read projection without rewriting historical bytes:
        // zero/underpowered candidate activity is not a superior WAIT policy.
        $minimumTrades = (int) data_get($comparison, 'power.minimum_trades_per_arm', 8);
        if ((int) data_get($comparison, 'candidate.total_trades', 0) < max(1, $minimumTrades)) {
            $interpretation = 'insufficient_candidate_activity';
        }
        $learningValue = $this->learningValue($comparison, $interpretation, $minimumTrades, $agentValidation);

        return [
            'id' => $model['id'],
            'label' => $model['label'],
            'class' => $model['class'],
            'roles' => (array) ($model['roles'] ?? []),
            'required_streams' => (array) ($model['required_streams'] ?? []),
            'no_trade' => (array) ($model['no_trade'] ?? []),
            'use_mode' => 'one_named_hypothesis_per_paired_trial',
            'execution_authority' => 'none',
            'frozen_control_prior' => $run ? [
                'status' => $run['status'],
                'data_hash' => $run['data_hash'],
                'execution_hash' => $run['execution_hash'],
                'interpretation' => $interpretation,
                'delta' => (array) data_get($comparison, 'delta', []),
                'learning_value' => $learningValue,
                'agent_owned_validation' => $agentValidation ?: null,
                'trading_operating_system_scorecard' => (array) ($run['trading_operating_system_scorecard'] ?? []),
                'prior_only' => true,
            ] : [
                'status' => 'not_yet_replayed',
                'learning_value' => [
                    'status' => 'unknown',
                    'next_evidence' => 'frozen_default_pair',
                    'inheritance_authority' => false,
                    'promotion_evidence' => false,
                ],
                'prior_only' => true,
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function learningValue(
        array $comparison,
        string $interpretation,
        int $minimumTrades,
        array $agentValidation = [],
    ): array {
        $control = (array) data_get($comparison, 'control', []);
        $candidate = (array) data_get($comparison, 'candidate', []);
        $trades = (int) ($candidate['total_trades'] ?? 0);
        $promisingUnderpowered = $trades > 0
            && $trades < max(1, $minimumTrades)
            && (float) ($candidate['profit_factor'] ?? 0) > (float) ($control['profit_factor'] ?? 0)
            && (float) ($candidate['net_profit_percent'] ?? 0) > (float) ($control['net_profit_percent'] ?? 0)
            && (float) ($candidate['max_drawdown_percent'] ?? INF) <= (float) ($control['max_drawdown_percent'] ?? -INF);
        $status = match (true) {
            data_get($agentValidation, 'portability_diagnosis.classification') === 'period_regime_or_data_domain_conditioned_prior' => 'period_conditioned_prior_requires_router_ablation',
            data_get($agentValidation, 'verdict') === 'falsified_by_paired_historical_confirmation' => 'agent_owned_historical_falsification',
            $interpretation === 'candidate_improved_on_this_frozen_replay' => 'powered_frozen_prior',
            $promisingUnderpowered => 'promising_underpowered_observation',
            $trades === 0 => 'activity_bottleneck',
            default => 'weak_or_inconclusive_prior',
        };

        return [
            'status' => $status,
            'observed_trades' => $trades,
            'minimum_trades' => max(1, $minimumTrades),
            'next_evidence' => match ($status) {
                'period_conditioned_prior_requires_router_ablation' => 'single_axis_market_state_portability_trial_or_retire',
                'agent_owned_historical_falsification' => 'retire_or_reformulate_composition',
                'powered_frozen_prior' => 'agent_owned_paired_validation',
                'promising_underpowered_observation' => 'agent_owned_independent_window_pair',
                'activity_bottleneck' => 'bounded_funnel_repair_or_rotate',
                default => 'rotate_or_reformulate_hypothesis',
            },
            'preserve_observation' => $promisingUnderpowered,
            'canonical_agent_owned_validation_consumed' => $agentValidation !== [],
            'evolution_directive' => data_get($agentValidation, 'portability_diagnosis.evolution_directive'),
            'inheritance_authority' => false,
            'promotion_evidence' => false,
        ];
    }

    /** @param array<int,array<string,mixed>> $tools @return array<int,array<string,mixed>> */
    private function studyOrder(array $tools, array $agent, array $context): array
    {
        $identity = strtolower((string) ($agent['strategy_id'] ?? $agent['strategy_family'] ?? ''));
        $session = strtolower((string) ($context['session'] ?? ''));
        $regime = strtolower((string) ($context['regime'] ?? ''));
        $preferred = match (true) {
            str_contains($identity, 'session') || in_array($session, ['london', 'new_york', 'london_new_york_overlap'], true) => ['po3_amd_session', 'london_judas_swing', 'silver_bullet_window', 'orb_htf_bias', 'orb_vwap_reclaim'],
            str_contains($identity, 'liquidity') || str_contains($identity, 'choch') || str_contains($identity, 'fibonacci') => ['liquidity_trap_mtf', 'ict_2022_raid_mss_fvg', 'turtle_soup_mtf', 'wyckoff_spring_utad'],
            str_contains($identity, 'trend') || str_contains($identity, 'breakout') || str_contains($regime, 'trend') => ['elder_triple_screen_liquidity', 'orb_htf_bias', 'adaptive_timeframe_confirmation', 'ict_2022_raid_mss_fvg'],
            default => ['liquidity_trap_mtf', 'adaptive_timeframe_confirmation', 'ict_2022_raid_mss_fvg', 'po3_amd_session'],
        };
        $rank = array_flip($preferred);
        usort($tools, static fn (array $left, array $right): int => [
            self::evidenceRank($left),
            $rank[$left['id']] ?? PHP_INT_MAX,
            $left['id'],
        ] <=> [
            self::evidenceRank($right),
            $rank[$right['id']] ?? PHP_INT_MAX,
            $right['id'],
        ]);

        return $tools;
    }

    private static function evidenceRank(array $tool): int
    {
        $status = (string) data_get($tool, 'frozen_control_prior.status', 'not_yet_replayed');
        $interpretation = (string) data_get($tool, 'frozen_control_prior.interpretation', '');

        return match (true) {
            in_array((string) data_get($tool, 'frozen_control_prior.learning_value.status'), [
                'period_conditioned_prior_requires_router_ablation',
                'agent_owned_historical_falsification',
            ], true) => 4,
            $interpretation === 'candidate_improved_on_this_frozen_replay' => 0,
            data_get($tool, 'frozen_control_prior.learning_value.status') === 'promising_underpowered_observation' => 1,
            $status === 'not_yet_replayed' => 2,
            default => 3,
        };
    }

    /** @return array<string,mixed> */
    private function creativeWindow(array $agent, array $activeIds): array
    {
        $stage = (string) ($agent['mastery_stage'] ?? 'apprentice');
        $requested = (bool) ($agent['innovation_allowed'] ?? false);
        $eligible = $requested && in_array($stage, ['validated_specialist', 'strategy_master_candidate', 'master'], true);

        return [
            'status' => $eligible ? 'bounded_shadow_window' : 'curriculum_locked',
            'catalogue_is_exhaustive' => false,
            'activation_authority' => StrategyCurriculumService::class.'::proposeInnovation',
            'baseline_playbook_ids' => $activeIds,
            'max_new_playbooks' => 1,
            'max_new_relations' => 1,
            'max_changed_axis' => 1,
            'allowed_axes' => ['entry_topology', 'confirmation_order', 'state_filter', 'exit_policy', 'cost_filter'],
            'required_declaration' => ['novel_claim', 'causal_data_inputs', 'one_catalogue_or_existing_control', 'measurable_behavior_delta'],
            'forbidden' => ['unbounded_playbook_mixing', 'risk_override', 'future_data', 'direct_promotion'],
            'requires_paired_frozen_control' => true,
            'requires_independent_confirmation' => true,
            'live_execution' => false,
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function frozenEvidence(string $symbol): array
    {
        if (! $this->hasFrozenRunTable()) {
            return [];
        }
        $identity = $this->frozenRunner->currentIdentity();
        // Read only the small comparison identity first. Candidate/control
        // results contain large decision traces and trade ledgers; loading
        // every historical JSON blob on every strategy proposal makes the
        // toolbox slower as research accumulates. Resolve one current latest
        // id per model, then hydrate only those bounded candidate results.
        $latestIds = MtfPlaybookFrozenControlRun::query()
            ->where('symbol', $symbol)
            ->where('status', 'completed')
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->get(['id', 'research_model_id', 'comparison'])
            ->filter(fn (MtfPlaybookFrozenControlRun $run): bool => (string) data_get($run->comparison, 'python_runtime_hash') === $identity['python_runtime_hash']
                && (string) data_get($run->comparison, 'runner_contract_hash') === $identity['runner_contract_hash'])
            ->unique('research_model_id')
            ->pluck('id')
            ->values();
        if ($latestIds->isEmpty()) {
            return [];
        }

        $validations = collect();
        if ($this->hasAgentValidationTable()) {
            $validations = MtfAgentValidationRun::query()
                ->whereIn('source_run_id', $latestIds->all())
                ->where('status', 'completed')
                ->orderByDesc('id')
                ->get(['id', 'source_run_id', 'status', 'paired_summary', 'reason_codes', 'promotion_evidence'])
                ->unique('source_run_id')
                ->keyBy('source_run_id');
        }

        return MtfPlaybookFrozenControlRun::query()
            ->whereKey($latestIds->all())
            ->get([
                'id', 'research_model_id', 'status', 'data_hash',
                'execution_hash', 'comparison', 'candidate_result',
            ])
            ->mapWithKeys(function (MtfPlaybookFrozenControlRun $run) use ($validations): array {
                $validation = $validations->get($run->id);

                return [$run->research_model_id => [
                    'source_run_id' => $run->id,
                    'status' => $run->status,
                    'data_hash' => $run->data_hash,
                    'execution_hash' => $run->execution_hash,
                    'comparison' => (array) $run->comparison,
                    'agent_owned_validation' => $validation ? [
                        'run_id' => $validation->id,
                        'status' => $validation->status,
                        'verdict' => data_get($validation->paired_summary, 'verdict'),
                        'next_evidence' => data_get($validation->paired_summary, 'next_evidence'),
                        'portability_diagnosis' => data_get($validation->paired_summary, 'portability_diagnosis'),
                        'reason_codes' => (array) $validation->reason_codes,
                        'parent_authority' => false,
                        'promotion_evidence' => false,
                    ] : null,
                    'trading_operating_system_scorecard' => $this->operatingSystem->assess((array) $run->candidate_result),
                ]];
            })
            ->all();
    }

    private function hasFrozenRunTable(): bool
    {
        return $this->frozenRunTableExists ??= Schema::hasTable('mtf_playbook_frozen_control_runs');
    }

    private function hasAgentValidationTable(): bool
    {
        return $this->agentValidationTableExists ??= Schema::hasTable('mtf_agent_validation_runs');
    }
}
