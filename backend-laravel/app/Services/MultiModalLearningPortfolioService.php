<?php

namespace App\Services;

use App\Models\AiLaboratory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Turns the laboratory's existing learning mechanisms into one bounded
 * compute portfolio. A method assignment is a pre-registered research
 * question, never evidence that the answer is useful and never promotion
 * authority.
 */
class MultiModalLearningPortfolioService
{
    public const PROTOCOL = 'multi_modal_learning_portfolio_v1';

    private const METHODS = [
        'failure_directed_repair' => [
            'learns_from' => 'terminal_failure_or_pre_registered_gate_deficit',
            'mechanism' => 'root_cause_hypothesis_then_exact_repair',
            'authority_ceiling' => 'repair_credit_until_independently_replicated',
            'compatible_blocks' => ['exact_repair'],
        ],
        'positive_skill_replication' => [
            'learns_from' => 'positive_control_relative_causal_delta',
            'mechanism' => 'independent_chronological_replication',
            'authority_ceiling' => 'research_mentor',
            'compatible_blocks' => ['replication'],
        ],
        'bayesian_active_learning' => [
            'learns_from' => 'posterior_uncertainty_and_expected_information_gain',
            'mechanism' => 'select_high_value_uncertainty_not_random_novelty',
            'authority_ceiling' => 'information_credit',
            'compatible_blocks' => ['structural_novelty', 'replication'],
        ],
        'counterfactual_factorial' => [
            'learns_from' => 'component_main_effect_and_interaction_uncertainty',
            'mechanism' => 'paired_a_b_marginal_screen_then_dedicated_five_arm_interaction',
            'authority_ceiling' => 'information_credit_until_dedicated_interaction_settlement',
            'compatible_blocks' => ['factorial_interaction'],
            'minimum_pairs_per_experiment' => 2,
        ],
        'quality_diversity_novelty' => [
            'learns_from' => 'contextual_archive_coverage_gap',
            'mechanism' => 'contextual_map_elites_cell_discovery',
            'authority_ceiling' => 'information_credit',
            'compatible_blocks' => ['structural_novelty'],
        ],
        'context_transfer_validation' => [
            'learns_from' => 'confirmed_local_skill_and_target_context_deficit',
            'mechanism' => 'source_candidate_vs_target_novelty_then_frozen_transfer_matrix',
            'authority_ceiling' => 'information_credit_until_target_local_transfer_proof',
            'compatible_blocks' => ['descendant_challenge'],
            'minimum_pairs_per_experiment' => 2,
        ],
        'adversarial_robustness' => [
            'learns_from' => 'historically_bounded_stress_boundary',
            'mechanism' => 'sealed_red_team_replay',
            'authority_ceiling' => 'robustness_diagnostic',
            'compatible_blocks' => ['continuity_adversarial_guard'],
        ],
        'elite_rehearsal_guard' => [
            'learns_from' => 'confirmed_contextual_elite_archive',
            'mechanism' => 'shadow_replay_and_non_regression_rehearsal',
            'authority_ceiling' => 'continuity_only_no_replacement_authority',
            'compatible_blocks' => ['continuity_adversarial_guard'],
        ],
    ];

    /** @return array<string,mixed> */
    public function planForLab(AiLaboratory $lab, array $authorityBlocks, array $contextualEvidence = []): array
    {
        $plan = $this->allocate($authorityBlocks, $this->signals($lab, $contextualEvidence));
        $references = $this->sourceReferences($lab);
        $signalSnapshot = (array) $plan['signals'];
        $plan['source_references'] = $references;
        $plan['blocks'] = collect((array) $plan['blocks'])->map(function (array $block) use ($references, $signalSnapshot): array {
            $method = (string) $block['learning_method'];
            $source = match ($method) {
                'failure_directed_repair' => $references['failure'] ?? null,
                'positive_skill_replication', 'counterfactual_factorial', 'context_transfer_validation' => $references['causal_skill'] ?? null,
                'bayesian_active_learning' => $references['information'] ?? null,
                'quality_diversity_novelty' => $references['archive'] ?? null,
                'adversarial_robustness' => $references['adversarial'] ?? null,
                'elite_rehearsal_guard' => $references['economic_parent'] ?? null,
                default => null,
            };
            $historicalContextRequired = in_array($method, [
                'positive_skill_replication', 'counterfactual_factorial',
                'context_transfer_validation', 'elite_rehearsal_guard',
            ], true);
            $interventionRequired = in_array($method, [
                'positive_skill_replication', 'counterfactual_factorial', 'context_transfer_validation',
            ], true);
            if ($historicalContextRequired && ($source === null
                || ! $this->usableContextScope((array) data_get($source, 'context_scope', []))
                || ($interventionRequired && (array) data_get($source, 'intervention', []) === []))) {
                $deferredMethod = $method;
                $method = $deferredMethod === 'elite_rehearsal_guard'
                    ? 'adversarial_robustness'
                    : 'bayesian_active_learning';
                $block['deferred_learning_method'] = $deferredMethod;
                $block['deferred_reason'] = match (true) {
                    $source === null => 'EVIDENCE_SOURCE_MISSING',
                    ! $this->usableContextScope((array) data_get($source, 'context_scope', [])) => 'EVIDENCE_SOURCE_CONTEXT_NOT_RECONSTRUCTABLE',
                    default => 'EVIDENCE_SOURCE_INTERVENTION_NOT_EXECUTABLE',
                };
                $block['learning_method'] = $method;
                $block['learns_from'] = self::METHODS[$method]['learns_from'];
                $block['mechanism'] = 'recover_missing_source_scope_before_'.self::METHODS[$deferredMethod]['mechanism'];
                $block['authority_ceiling'] = 'information_credit';
                unset($block['experiment_topology']);
                $source = null;
            }
            $sourceRequired = in_array($method, [
                'positive_skill_replication', 'counterfactual_factorial',
                'context_transfer_validation', 'elite_rehearsal_guard',
            ], true);
            $sourceContextRequired = in_array($method, [
                'positive_skill_replication', 'counterfactual_factorial',
                'context_transfer_validation', 'elite_rehearsal_guard',
            ], true);
            $block['source_reference'] = $source;
            $block['source_reference_required'] = $sourceRequired;
            $block['source_context_required'] = $sourceContextRequired;
            $block['source_reference_status'] = $source !== null
                ? 'bound_before_mutation'
                : ($sourceRequired ? 'missing_fail_closed' : 'pre_registered_without_historical_source');
            $receipt = (array) $block['selection_receipt'];
            $receipt['receipt_hash'] = hash('sha256', json_encode([
                'protocol' => self::PROTOCOL,
                'block_index' => $block['block_index'],
                'authority_block_type' => $block['authority_block_type'],
                'learning_method' => $method,
                'source_reference' => $source,
                'experiment_topology' => data_get($block, 'experiment_topology'),
                'signals' => $signalSnapshot,
            ], JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
            $block['selection_receipt'] = $receipt;

            return $block;
        })->all();
        $plan['pair_allocations'] = collect(array_keys(self::METHODS))->mapWithKeys(fn (string $method): array => [
            $method => collect($plan['blocks'])->where('learning_method', $method)->count(),
        ])->all();
        $plan['active_methods'] = array_keys(array_filter($plan['pair_allocations'], fn (int $count): bool => $count > 0));
        $plan['method_diversity'] = count($plan['active_methods']);

        return $plan;
    }

    /**
     * Pure deterministic allocator used by generation construction and tests.
     *
     * @param  array<int,array<string,mixed>>  $authorityBlocks
     * @param  array<string,int|float>  $signals
     * @return array<string,mixed>
     */
    public function allocate(array $authorityBlocks, array $signals = []): array
    {
        $signals = $this->normalizeSignals($signals);
        $scores = $this->scores($signals);
        $allocations = array_fill_keys(array_keys(self::METHODS), 0);
        $blocks = [];

        foreach (array_values($authorityBlocks) as $index => $authorityBlock) {
            $blockType = (string) data_get($authorityBlock, 'block_type', 'structural_novelty');
            $eligible = collect(self::METHODS)
                ->filter(fn (array $definition, string $method): bool =>
                    in_array($blockType, (array) $definition['compatible_blocks'], true)
                    && $this->available($method, $signals)
                );
            if ($eligible->isEmpty()) {
                // Every known authority block has a safe method. This fallback
                // is intentionally diagnostic if a future block is introduced
                // without first extending this constitution.
                $method = 'bayesian_active_learning';
                $unsupportedBlock = true;
            } else {
                $method = (string) $eligible->keys()->sortByDesc(function (string $candidate) use ($scores, $allocations): float {
                    $coverageBoost = $allocations[$candidate] === 0 ? .35 : 0.0;

                    return (($scores[$candidate] ?? 0.0) + $coverageBoost) / (1 + (.55 * $allocations[$candidate]));
                })->first();
                $unsupportedBlock = false;
            }
            $allocations[$method]++;
            $definition = self::METHODS[$method];
            $receiptPayload = [
                'protocol' => self::PROTOCOL,
                'block_index' => (int) data_get($authorityBlock, 'block_index', $index + 1),
                'authority_block_type' => $blockType,
                'learning_method' => $method,
                'score' => round((float) ($scores[$method] ?? 0.0), 6),
                'signals' => $signals,
            ];
            $blocks[] = [
                'protocol' => self::PROTOCOL,
                'block_index' => $receiptPayload['block_index'],
                'authority_block_type' => $blockType,
                'learning_method' => $method,
                'learns_from' => $definition['learns_from'],
                'mechanism' => $definition['mechanism'],
                'authority_ceiling' => $definition['authority_ceiling'],
                'pair_roles' => ['exact_frozen_control', 'candidate'],
                'requires_exact_frozen_control' => true,
                'selection_must_precede_mutation' => true,
                'settlement_must_link_to_receipt' => true,
                'confirmed_elite_replacement_allowed' => false,
                'cross_context_authority_transfer_allowed' => false,
                'research_nursery_only' => true,
                'unsupported_authority_block' => $unsupportedBlock,
                'selection_receipt' => [
                    'receipt_hash' => hash('sha256', json_encode($receiptPayload, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)),
                    'status' => 'pre_registered',
                    'selected_before_mutation' => true,
                    'result_link_pending' => true,
                    'promotion_evidence' => false,
                ],
                'promotion_evidence' => false,
            ];
        }

        $blocks = $this->bindMultiPairTopologies($blocks);
        $activeMethods = array_keys(array_filter($allocations, fn (int $count): bool => $count > 0));

        return [
            'protocol' => self::PROTOCOL,
            'status' => $blocks === [] ? 'not_applicable' : 'allocated',
            'signals' => $signals,
            'method_definitions' => self::METHODS,
            'scores' => $scores,
            'pair_allocations' => $allocations,
            'active_methods' => $activeMethods,
            'method_diversity' => count($activeMethods),
            'blocks' => $blocks,
            'constitution' => [
                'failure_learning_is_one_method_not_the_only_method' => true,
                'positive_delta_creates_replication_pressure' => true,
                'uncertainty_buys_information_before_blind_search' => true,
                'interaction_claim_requires_factorial_counterfactual' => true,
                'local_skill_never_becomes_global_authority_by_transfer' => true,
                'best_known_specialist_is_monotonic' => true,
                'challengers_run_in_research_nursery' => true,
                'deployment_uses_confirmed_elites_only' => true,
                'all_methods_require_frozen_control' => true,
            ],
            'promotion_evidence' => false,
        ];
    }

    /** @return array<string,int|float> */
    private function signals(AiLaboratory $lab, array $contextualEvidence): array
    {
        $symbol = strtoupper((string) $lab->symbol);
        $timeframe = strtoupper((string) $lab->timeframe);
        $credits = array_fill_keys([
            'information_credit', 'repair_credit', 'causal_skill_credit',
            'performance_credit', 'inheritance_credit',
        ], 0);
        if (Schema::hasTable('lab_evolution_credit_events')) {
            $rows = DB::table('lab_evolution_credit_events')
                ->where('symbol', $symbol)->where('timeframe', $timeframe)
                ->where('amount', '>', 0)->selectRaw('event_type, COUNT(*) as aggregate')->groupBy('event_type')->get();
            foreach ($rows as $row) {
                $type = in_array((string) $row->event_type, ['learning', 'discovery'], true)
                    ? 'information_credit' : (string) $row->event_type;
                if (array_key_exists($type, $credits)) {
                    $credits[$type] += (int) $row->aggregate;
                }
            }
        }

        $authority = ['research_mentor_count' => 0, 'economic_parent_count' => 0];
        if (Schema::hasTable('evolutionary_authority_ledgers')) {
            $authority['research_mentor_count'] = DB::table('evolutionary_authority_ledgers')
                ->where('symbol', $symbol)->where('timeframe', $timeframe)
                ->whereIn('status', ['research_mentor_granted', 'passed'])->count();
            $authority['economic_parent_count'] = DB::table('evolutionary_authority_ledgers')
                ->where('symbol', $symbol)->where('timeframe', $timeframe)
                ->where('authority_stage', 'eligible_parent')->where('status', 'passed')->count();
        }

        $coverageDeficit = collect((array) data_get($contextualEvidence, '__sessions.__global', []))
            ->filter(fn ($row): bool => (int) data_get($row, 'observations', 0) === 0
                && (int) data_get($row, 'posterior_observations', 0) === 0)
            ->count();
        $posteriorCells = collect((array) data_get($contextualEvidence, '__sessions.__global', []));
        $posteriorEntropyMean = $posteriorCells->isEmpty() ? 0.0 : (float) $posteriorCells
            ->map(function ($row): float {
                $successes = max(0, (int) data_get($row, 'successes', 0));
                $failures = max(0, (int) data_get($row, 'failures', 0));
                // Beta(1,1) posterior predictive entropy. It is a bounded
                // acquisition signal, not replay evidence or promotion.
                $probability = ($successes + 1) / ($successes + $failures + 2);

                return $this->binaryEntropy($probability);
            })->avg();
        $informationGainProxy = $posteriorCells->sum(function ($row): float {
            $observations = max(0, (int) data_get($row, 'observations', 0));
            $posteriorObservations = max(0, (int) data_get($row, 'posterior_observations', 0));
            $successes = max(0, (int) data_get($row, 'successes', 0));
            $failures = max(0, (int) data_get($row, 'failures', 0));
            $probability = ($successes + 1) / ($successes + $failures + 2);

            return $this->binaryEntropy($probability) / sqrt($observations + $posteriorObservations + 1);
        });

        return [
            ...$credits,
            ...$authority,
            'open_failure_count' => $this->scopedCount('lab_failure_repair_anchors', $symbol, $timeframe, ['status' => 'open']),
            'confirmed_skill_count' => $this->scopedCount('lab_skill_zoo_entries', $symbol, $timeframe, ['status' => 'confirmed']),
            'archive_cell_count' => $this->scopedCount('lab_evolution_archive_entries', $symbol, $timeframe, ['status' => 'active']),
            'pending_adversarial_count' => $this->scopedCount('lab_adversarial_scenarios', $symbol, $timeframe, ['status' => 'planned']),
            // The current transfer table is keyed by model and markets. Its
            // waiting rows are global research debt, never local authority.
            'pending_transfer_count' => Schema::hasTable('transfer_matrix_entries')
                ? DB::table('transfer_matrix_entries')->where('status', 'waiting_for_frozen_replay')->count()
                : 0,
            'context_coverage_deficit' => $coverageDeficit,
            'posterior_entropy_mean' => round($posteriorEntropyMean, 6),
            'expected_information_gain_proxy' => round($informationGainProxy, 6),
        ];
    }

    /** @return array<string,array<string,mixed>|null> */
    private function sourceReferences(AiLaboratory $lab): array
    {
        $symbol = strtoupper((string) $lab->symbol);
        $timeframe = strtoupper((string) $lab->timeframe);
        $credit = function (array $types) use ($symbol, $timeframe): ?array {
            if (! Schema::hasTable('lab_evolution_credit_events')) {
                return null;
            }
            $row = DB::table('lab_evolution_credit_events')
                ->where('symbol', $symbol)->where('timeframe', $timeframe)
                ->whereIn('event_type', $types)->where('amount', '>', 0)->latest('id')->first();

            $payload = $row ? (array) json_decode((string) $row->payload, true) : [];
            $context = (array) data_get($payload, 'context', []);
            if ($context === [] && $row) {
                $context = $this->modelContext((int) $row->model_version_id);
            }

            return $row ? [
                'source_type' => 'evolution_credit', 'id' => (int) $row->id,
                'event_type' => (string) $row->event_type,
                'strategy_family' => (string) $row->strategy_family,
                'model_version_id' => $row->model_version_id,
                'lab_agent_id' => $row->lab_agent_id,
                'context_key' => (string) $row->context_key,
                'context_scope' => $context,
                'intervention' => $this->agentIntervention((int) $row->lab_agent_id),
                'evidence_fingerprint' => (string) $row->evidence_fingerprint,
            ] : null;
        };
        $failure = null;
        if (Schema::hasTable('lab_failure_repair_anchors')) {
            $row = DB::table('lab_failure_repair_anchors')->where('symbol', $symbol)
                ->where('timeframe', $timeframe)->where('status', 'open')->latest('id')->first();
            $failure = $row ? [
                'source_type' => 'failure_repair_anchor', 'id' => (int) $row->id,
                'anchor_key' => (string) $row->anchor_key,
                'failure_target' => (string) $row->failure_target,
            ] : null;
        }
        $causalSkill = $credit(['causal_skill_credit']);
        if ($causalSkill === null && Schema::hasTable('lab_skill_zoo_entries')) {
            $row = DB::table('lab_skill_zoo_entries')->where('symbol', $symbol)
                ->where('timeframe', $timeframe)->where('status', 'confirmed')->latest('id')->first();
            $causalSkill = $row ? [
                'source_type' => 'confirmed_skill_archive', 'id' => (int) $row->id,
                'skill_key' => (string) $row->skill_key,
                'strategy_family' => (string) $row->strategy_family,
                'gene_key' => $row->gene_key,
                'context_scope' => (array) data_get(json_decode((string) $row->evidence, true), 'context', [])
                    ?: $this->modelContext((int) $row->model_version_id),
                'intervention' => $this->skillIntervention((array) json_decode((string) $row->evidence, true), (string) $row->gene_key)
                    ?: $this->agentIntervention((int) $row->lab_agent_id),
            ] : null;
        }
        if ($causalSkill === null && Schema::hasTable('evolutionary_authority_ledgers')) {
            $row = DB::table('evolutionary_authority_ledgers')->where('symbol', $symbol)
                ->where('timeframe', $timeframe)->whereIn('status', ['research_mentor_granted', 'passed'])
                ->latest('id')->first();
            $causalSkill = $row ? [
                'source_type' => 'research_mentor_authority', 'id' => (int) $row->id,
                'authority_key' => (string) $row->authority_key,
                'strategy_family' => (string) $row->strategy_family,
                'model_version_id' => $row->model_version_id,
                'lab_agent_id' => $row->lab_agent_id,
                'context_scope' => (array) data_get(
                    json_decode((string) $row->evidence, true),
                    'research_mentor_authority.scope.context',
                    data_get(json_decode((string) $row->evidence, true), 'research_mentor_authority.scope', []),
                ) ?: $this->modelContext((int) $row->model_version_id),
                'intervention' => $this->agentIntervention((int) $row->lab_agent_id),
            ] : null;
        }
        $archive = null;
        if (Schema::hasTable('lab_evolution_archive_entries')) {
            $row = DB::table('lab_evolution_archive_entries')->where('symbol', $symbol)
                ->where('timeframe', $timeframe)->where('status', 'active')->latest('id')->first();
            $archive = $row ? [
                'source_type' => 'quality_diversity_archive', 'id' => (int) $row->id,
                'archive_type' => (string) $row->archive_type,
                'island_key' => (string) $row->island_key,
            ] : null;
        }
        $adversarial = null;
        if (Schema::hasTable('lab_adversarial_scenarios')) {
            $row = DB::table('lab_adversarial_scenarios')->where('symbol', $symbol)
                ->where('timeframe', $timeframe)->where('status', 'planned')->latest('id')->first();
            $adversarial = $row ? [
                'source_type' => 'adversarial_scenario', 'id' => (int) $row->id,
                'scenario_key' => (string) $row->scenario_key,
                'scenario_type' => (string) $row->scenario_type,
            ] : null;
        }
        $economicParent = null;
        if (Schema::hasTable('evolutionary_authority_ledgers')) {
            $row = DB::table('evolutionary_authority_ledgers')->where('symbol', $symbol)
                ->where('timeframe', $timeframe)->where('authority_stage', 'eligible_parent')
                ->where('status', 'passed')->latest('id')->first();
            $economicParent = $row ? [
                'source_type' => 'economic_parent_authority', 'id' => (int) $row->id,
                'authority_key' => (string) $row->authority_key,
                'model_version_id' => $row->model_version_id,
                'strategy_family' => (string) $row->strategy_family,
                'context_scope' => (array) data_get(
                    json_decode((string) $row->evidence, true),
                    'economic_parent_authority.scope.context',
                    data_get(json_decode((string) $row->evidence, true), 'economic_parent_authority.scope', []),
                ) ?: $this->modelContext((int) $row->model_version_id),
            ] : null;
        }

        return [
            'failure' => $failure,
            'information' => $credit(['information_credit', 'learning', 'discovery']),
            'causal_skill' => $causalSkill,
            'archive' => $archive,
            'adversarial' => $adversarial,
            'economic_parent' => $economicParent,
        ];
    }

    /** @param array<string,string|int|float|bool> $where */
    private function scopedCount(string $table, string $symbol, string $timeframe, array $where): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }
        $query = DB::table($table);
        if (Schema::hasColumn($table, 'symbol')) {
            $query->where('symbol', $symbol);
        }
        if (Schema::hasColumn($table, 'timeframe')) {
            $query->where('timeframe', $timeframe);
        }
        foreach ($where as $column => $value) {
            $query->where($column, $value);
        }

        return $query->count();
    }

    /** @return array<string,mixed> */
    private function modelContext(int $modelVersionId): array
    {
        if ($modelVersionId <= 0 || ! Schema::hasTable('model_versions')) {
            return [];
        }
        $raw = DB::table('model_versions')->where('id', $modelVersionId)->value('metadata');
        $metadata = is_string($raw) ? (array) json_decode($raw, true) : (array) $raw;
        foreach ([
            'contextual_trait_capsule.activation_context',
            'skill_mentor.activation_context',
            'portfolio_council_lane',
            'specialist_council_membership.contextual_cell',
            'semantic_group.context',
        ] as $path) {
            $context = (array) data_get($metadata, $path, []);
            if ($this->usableContextScope($context)) {
                return $context;
            }
        }

        return [];
    }

    private function usableContextScope(array $context): bool
    {
        return filled(data_get($context, 'regime'))
            || filled(data_get($context, 'session'))
            || filled(data_get($context, 'session_utc_hour'))
            || filled(data_get($context, 'volatility'))
            || filled(data_get($context, 'volatility_state'));
    }

    /** @return array<string,mixed> */
    private function agentIntervention(int $agentId): array
    {
        if ($agentId <= 0 || ! Schema::hasTable('lab_agents')) {
            return [];
        }
        $raw = DB::table('lab_agents')->where('id', $agentId)->value('parameter_diff');
        $diff = is_string($raw) ? (array) json_decode($raw, true) : (array) $raw;
        if (count($diff) !== 1) {
            return [];
        }
        $gene = (string) array_key_first($diff);

        return [
            'gene' => $gene,
            'old_value' => data_get($diff, $gene.'.old.value', data_get($diff, $gene.'.old')),
            'new_value' => data_get($diff, $gene.'.new.value', data_get($diff, $gene.'.new')),
        ];
    }

    /** @return array<string,mixed> */
    private function skillIntervention(array $evidence, string $fallbackGene): array
    {
        $intervention = (array) data_get($evidence, 'intervention', []);
        $gene = (string) data_get($intervention, 'gene', $fallbackGene);
        $old = data_get($intervention, 'old_value');
        $new = data_get($intervention, 'tested_value', data_get($intervention, 'new_value'));
        if ($gene === '' || $new === null) {
            return [];
        }

        return ['gene' => $gene, 'old_value' => $old, 'new_value' => $new];
    }

    /** @return array<string,int|float> */
    private function normalizeSignals(array $signals): array
    {
        $defaults = [
            'open_failure_count' => 0, 'information_credit' => 0, 'repair_credit' => 0,
            'causal_skill_credit' => 0, 'performance_credit' => 0, 'inheritance_credit' => 0,
            'research_mentor_count' => 0, 'economic_parent_count' => 0,
            'confirmed_skill_count' => 0, 'archive_cell_count' => 0,
            'pending_transfer_count' => 0, 'pending_adversarial_count' => 0,
            'context_coverage_deficit' => 0, 'posterior_entropy_mean' => 0,
            'expected_information_gain_proxy' => 0,
        ];

        return collect([...$defaults, ...$signals])->map(
            fn ($value): int|float => max(0, is_numeric($value) ? $value + 0 : 0),
        )->all();
    }

    /** @return array<string,float> */
    private function scores(array $signals): array
    {
        $log = fn (string $key): float => log(1 + (float) $signals[$key]);

        return [
            'failure_directed_repair' => 2.4 + $log('open_failure_count') + (.45 * $log('repair_credit')),
            'positive_skill_replication' => 2.0 + $log('causal_skill_credit') + (.4 * $log('confirmed_skill_count')),
            'bayesian_active_learning' => 2.1
                + (.5 * $log('information_credit'))
                + (.3 * (float) $signals['context_coverage_deficit'])
                + (.55 * (float) $signals['posterior_entropy_mean'])
                + (.35 * log(1 + (float) $signals['expected_information_gain_proxy'])),
            'counterfactual_factorial' => 2.0 + $log('causal_skill_credit') + (.45 * $log('repair_credit')),
            'quality_diversity_novelty' => 2.0 + (.45 * (float) $signals['context_coverage_deficit']) + (1 / sqrt(1 + (float) $signals['archive_cell_count'])),
            'context_transfer_validation' => 1.8 + $log('causal_skill_credit') + (.25 * $log('pending_transfer_count')),
            'adversarial_robustness' => 2.0 + (.3 * $log('pending_adversarial_count')),
            'elite_rehearsal_guard' => 2.2 + $log('economic_parent_count') + (.35 * $log('confirmed_skill_count')),
        ];
    }

    private function available(string $method, array $signals): bool
    {
        return match ($method) {
            'positive_skill_replication', 'counterfactual_factorial', 'context_transfer_validation' =>
                (int) $signals['causal_skill_credit'] > 0
                || (int) $signals['research_mentor_count'] > 0
                || (int) $signals['confirmed_skill_count'] > 0,
            'elite_rehearsal_guard' => (int) $signals['economic_parent_count'] > 0,
            default => true,
        };
    }

    private function binaryEntropy(float $probability): float
    {
        $probability = min(1 - 1.0e-12, max(1.0e-12, $probability));

        return -($probability * log($probability, 2))
            - ((1 - $probability) * log(1 - $probability, 2));
    }

    /** @param array<int,array<string,mixed>> $blocks @return array<int,array<string,mixed>> */
    private function bindMultiPairTopologies(array $blocks): array
    {
        foreach ([
            'counterfactual_factorial' => [
                ['exact_control', 'component_a'],
                ['exact_control', 'component_b'],
            ],
            'context_transfer_validation' => [
                ['exact_target_control', 'transferred_skill_candidate'],
                ['exact_target_control', 'from_scratch_novelty_candidate'],
            ],
        ] as $method => $pairArms) {
            $indexes = array_keys(array_filter($blocks, fn (array $block): bool => $block['learning_method'] === $method));
            foreach (array_chunk($indexes, 2) as $groupOrdinal => $groupIndexes) {
                $complete = count($groupIndexes) === 2;
                $groupKey = hash('sha256', implode('|', [
                    self::PROTOCOL, $method, $groupOrdinal + 1,
                    ...array_map(fn (int $index): int => (int) $blocks[$index]['block_index'], $groupIndexes),
                ]));
                foreach ($groupIndexes as $pairOrdinal => $blockIndex) {
                    $blocks[$blockIndex]['experiment_topology'] = [
                        'topology' => $method === 'counterfactual_factorial'
                            ? 'paired_marginal_screen_before_dedicated_control_a_b_a_plus_b'
                            : 'paired_transfer_screen_before_frozen_transfer_matrix',
                        'group_key' => $groupKey,
                        'required_pairs' => 2,
                        'required_seats' => 4,
                        'pair_ordinal' => $pairOrdinal + 1,
                        'control_arm' => $pairArms[$pairOrdinal][0],
                        'candidate_arm' => $pairArms[$pairOrdinal][1],
                        'same_context_cell_required' => true,
                        'same_data_and_execution_hash_required' => true,
                        'interaction_claim_allowed' => $method !== 'counterfactual_factorial',
                        'dedicated_interaction_cohort_required' => $method === 'counterfactual_factorial',
                        'dedicated_interaction_protocol' => $method === 'counterfactual_factorial'
                            ? CanonicalSkillCartridgeService::PROTOCOL
                            : null,
                        'transfer_claim_allowed' => $method !== 'context_transfer_validation',
                        'frozen_transfer_matrix_required' => $method === 'context_transfer_validation',
                        'frozen_transfer_matrix_protocol' => $method === 'context_transfer_validation'
                            ? 'transfer_matrix_v1'
                            : null,
                        'status' => $complete ? 'complete' : 'incomplete_fail_closed',
                        'promotion_evidence' => false,
                    ];
                }
            }
        }

        return $blocks;
    }
}
