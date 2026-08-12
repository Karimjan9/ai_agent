<?php

namespace App\Console\Commands;

use App\Jobs\EvaluateLabAgentJob;
use App\Models\AiLaboratory;
use App\Models\LabAgent;
use App\Models\ModelVersion;
use App\Models\VolumeShadowExperiment;
use App\Services\ExecutionContractService;
use App\Services\LabDatasetExportService;
use App\Services\LabPopulationService;
use App\Services\LearningProtocolSafetyService;
use App\Services\StrategyParameterSchemaService;
use App\Services\StrategySemanticGroupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

/**
 * Opens the volume council only after canonical quality and shadow evidence.
 * It creates a no-volume control plus three one-gene context specialists in
 * the same generation, so every old gate compares them under one replay.
 */
class DispatchVolumeResearch extends Command
{
    private const LANES = [
        'breakout_volume_confirmation',
        'transition_volume_router',
        'low_volume_risk_firewall',
    ];

    protected $signature = 'trading:dispatch-volume-research {sourceAgent : Screened/challenger parent id}';

    protected $description = 'Dispatch canonical-volume control and bounded context-specialist child agents';

    public function handle(
        LabDatasetExportService $datasets,
        StrategyParameterSchemaService $schemas,
        StrategySemanticGroupService $semanticGroups,
        LearningProtocolSafetyService $protocolSafety,
    ): int {
        if ($protocolSafety->generationCreationPaused()) {
            $this->info('Learning protocol paused: volume research dispatch deferred.');

            return self::SUCCESS;
        }
        $source = LabAgent::query()->with(['modelVersion', 'generation.laboratory'])->find((int) $this->argument('sourceAgent'));
        if (! $source || ! $source->modelVersion || ! $source->generation?->laboratory) {
            $this->error('Source agent/model/laboratory topilmadi.');

            return self::FAILURE;
        }
        if ((string) $source->generation->laboratory->lifecycle_mode !== 'lighthouse') {
            $this->info('Source laboratory shadow rejimida; volume research dispatch qilinmadi.');

            return self::SUCCESS;
        }
        if (! in_array($source->lifecycle_status, ['screened', 'challenger', 'stagnated', 'rejected'], true)) {
            $this->error('Source standalone screen/challenger candidate bo‘lishi kerak.');

            return self::FAILURE;
        }

        $shadow = VolumeShadowExperiment::query()
            ->where('model_version_id', $source->model_version_id)
            ->where('symbol', strtoupper((string) $source->symbol))
            ->where('timeframe', strtoupper((string) $source->timeframe))
            ->where('status', 'assessed')
            ->where('promotion_evidence', false)
            ->latest('id')
            ->first();
        if (! $shadow || data_get($shadow->metrics, 'quality.status') !== 'passed') {
            $this->error('Avval volume quality gate va no-volume shadow control tugashi kerak.');

            return self::FAILURE;
        }

        $lab = $source->generation->laboratory;
        $latest = $lab->generations()->latest('generation')->first();
        if ($latest && in_array($latest->status, LabPopulationService::ACTIVE_GENERATION_STATUSES, true)) {
            $this->warn('Faol generation mavjud; volume council G109/frozen forward tugamaguncha ochilmadi.');

            return self::SUCCESS;
        }

        $family = (string) $source->strategy_family;
        $parent = $source->modelVersion;
        $sourceGroup = $semanticGroups->fromModel($parent, $family);
        $sourceNiche = [
            'role' => data_get($sourceGroup, 'role'),
            'regime' => data_get($sourceGroup, 'regime'),
            'volatility' => data_get($sourceGroup, 'volatility'),
            'direction' => data_get($sourceGroup, 'direction'),
        ];
        $geneticParent = $semanticGroups->exactParentCompatible(
            $parent,
            $lab->symbol,
            $lab->timeframe,
            $family,
            $sourceNiche,
        ) ? $parent : null;
        $parentGroup = $geneticParent
            ? $semanticGroups->descriptor($lab->symbol, $lab->timeframe, $family, $sourceNiche)
            : $semanticGroups->descriptor($lab->symbol, $lab->timeframe, $family, ['role' => 'general']);
        $baseStrategy = (string) data_get($geneticParent?->metadata, 'base_strategy', $family.'_v1');
        $baseParameters = [...$schemas->defaults($family), ...($geneticParent?->parameters ?? [])];
        // A control is explicit rather than an omitted key, so the cohort's
        // no-volume comparison is auditable and deterministic.
        $baseParameters['volume_lane'] = 'none';

        try {
            $baseParameters = $schemas->validate($family, $baseParameters);
            foreach (self::LANES as $lane) {
                $schemas->validate($family, [...$baseParameters, 'volume_lane' => $lane]);
            }

            [$generation, $agents] = DB::transaction(function () use (
                $lab,
                $source,
                $parent,
                $geneticParent,
                $parentGroup,
                $family,
                $baseStrategy,
                $baseParameters,
                $shadow,
            ): array {
                $lockedLab = AiLaboratory::query()->whereKey($lab->id)->lockForUpdate()->firstOrFail();
                $latestGeneration = $lockedLab->generations()->latest('generation')->lockForUpdate()->first();
                if ($latestGeneration && in_array($latestGeneration->status, LabPopulationService::ACTIVE_GENERATION_STATUSES, true)) {
                    return [null, collect()];
                }
                $number = (int) ($latestGeneration?->generation ?? 0) + 1;
                $generation = $lockedLab->generations()->create([
                    'generation' => $number,
                    'trigger_type' => 'volume_context_council',
                    'trigger_context' => [
                        'source_agent_id' => $source->id,
                        'source_model_version_id' => $parent->id,
                        'shadow_experiment_id' => $shadow->id,
                        'protocol' => 'volume_council_v1',
                        'source_contract' => 'dukascopy_jetta_bid_tick_volume_millions_v1',
                        'promotion_evidence' => false,
                        'frozen_forward_unchanged' => true,
                        'old_elite_gates_unchanged' => true,
                    ],
                    'data_fingerprint' => $source->generation->data_fingerprint,
                    'population_size' => 4,
                    'status' => 'screening',
                    'started_at' => now(),
                ]);

                $agents = collect();
                $variants = array_merge(['none'], self::LANES);
                foreach ($variants as $slot => $lane) {
                    $parameters = [...$baseParameters, 'volume_lane' => $lane];
                    $suffix = str_replace('_', '-', $lane);
                    $inheritedMetadata = $this->volumeParentMetadata($geneticParent?->metadata ?? []);
                    $owner = self::LANE_OWNERS[$lane] ?? self::LANE_OWNERS['none'];
                    $model = ModelVersion::create([
                        'name' => strtolower($lockedLab->symbol).'_volume_g'.$number.'_'.$suffix,
                        'strategy' => strtolower($lockedLab->symbol).'_volume_g'.$number.'_'.$suffix,
                        'version' => 'v'.$number.'-volume-'.$lane,
                        'generation' => $number,
                        'status' => 'testing',
                        'parameters' => $parameters,
                        'description' => $lane === 'none'
                            ? "Frozen no-volume control for volume council from {$source->id}."
                            : "One-gene volume context specialist {$lane} from {$source->id}.",
                        'metadata' => [
                            ...$inheritedMetadata,
                            'semantic_group' => $parentGroup,
                            'lab_symbol' => $lockedLab->symbol, 'lab_timeframe' => $lockedLab->timeframe,
                            'semantic_lineage' => [
                                'protocol' => 'strict_semantic_lineage_v2',
                                'mode' => $geneticParent ? 'exact_semantic_parent' : 'semantic_group_root_default_seed',
                                'genetic_parent_model_version_id' => $geneticParent?->id,
                                'diagnostic_source_model_version_id' => $parent->id,
                                'promotion_evidence' => false,
                            ],
                            'parent_inheritance_protocol' => [
                                'protocol' => 'exact_semantic_parent_or_group_root_v1',
                                'parent_selection' => $geneticParent ? 'exact_semantic_parent' : 'exact_group_root_default',
                                'cross_cell_parent_forbidden' => true,
                                'legacy_parent_genetic_material' => false,
                                'promotion_evidence' => false,
                            ],
                            'mutation_constructor_invariant' => [
                                'protocol' => 'agent_constructor_invariant_v1',
                                'status' => 'passed',
                                'single_gene_required' => $lane !== 'none',
                                'changed_parameter_keys' => $lane === 'none' ? [] : ['volume_lane'],
                                'parameter_diff_count' => $lane === 'none' ? 0 : 1,
                                'parent_model_version_id' => $geneticParent?->id,
                                'control_only' => $lane === 'none',
                                'promotion_evidence' => false,
                            ],
                            'execution_contract' => app(ExecutionContractService::class)->for($lockedLab->symbol, $lockedLab->timeframe),
                            'base_strategy' => $baseStrategy,
                            'origin' => 'volume_context_specialist',
                            'generation_target' => 'volume_context',
                            'volume_specialist_owner' => $owner,
                            'volume_research_contract' => [
                                'protocol' => 'volume_council_v1',
                                'lane' => $lane,
                                'owner' => $owner['owner'],
                                'applies_to_specialists' => $owner['applies_to_specialists'],
                                'enabled' => $lane !== 'none',
                                'control' => $lane === 'none',
                                'parent_model_version_id' => $geneticParent?->id,
                                'source_agent_id' => $source->id,
                                'shadow_experiment_id' => $shadow->id,
                                'source_contract' => 'dukascopy_jetta_bid_tick_volume_millions_v1',
                                'normalization_protocol' => 'relative_volume_session_v2',
                                'single_gene' => true,
                                'frozen_parent' => true,
                                'promotion_evidence' => false,
                                'old_elite_gates_unchanged' => true,
                                'standalone_forward_required' => true,
                                'combined_replay_only_after_individual_passports' => true,
                            ],
                        ],
                    ]);
                    $agents->push($generation->agents()->create([
                        'model_version_id' => $model->id,
                        'parent_a_model_version_id' => $geneticParent?->id,
                        'symbol' => $lockedLab->symbol,
                        'timeframe' => $lockedLab->timeframe,
                        'strategy_family' => $family,
                        'origin' => 'volume_shadow_control',
                        'lifecycle_status' => 'queued',
                        'parameter_diff' => ['volume_lane' => ['old' => 'none', 'new' => $lane]],
                        'decision_reason' => $lane === 'none'
                            ? 'No-volume control; same parent/data/costs and unchanged elite gate.'
                            : "Single-gene {$lane} volume context experiment; standalone passport required.",
                    ]));
                }

                return [$generation, $agents];
            });
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $generation || $agents->isEmpty()) {
            $this->warn('Generation lock sababli volume council yaratilmadi.');

            return self::SUCCESS;
        }

        // Freeze the volume snapshot before the first control/child worker
        // starts. The control uses volume_lane=none, but it must share the
        // exact same canonical volume dataset hash with every child.
        $datasets->ensureGenerationSnapshot($generation, true);
        $batch = Bus::batch(
            $agents->map(fn (LabAgent $agent) => new EvaluateLabAgentJob($agent->id, $lab->symbol, 'screen'))->all()
        )->name("{$lab->symbol} volume council G{$generation->generation}")
            ->allowFailures()
            ->onConnection((string) config('queue.default', 'redis'))
            ->onQueue((string) config('services.lab_queue.screening_queue', 'lab-screening'))
            ->dispatch();

        $this->info("{$lab->symbol} G{$generation->generation}: {$batch->id}; control + ".count(self::LANES).' volume child queued for screening. No promotion/full shortcut used.');

        return self::SUCCESS;
    }

    /**
     * Parent models may be G98/portfolio research children.  Their historical
     * lane metadata is not volume evidence and must not be inherited by a
     * volume child, otherwise selection can misclassify the experiment as an
     * old council lane.  Runtime identity and immutable execution contracts
     * are retained; result/projection and admission metadata are rebuilt.
     */
    private function volumeParentMetadata(array $metadata): array
    {
        foreach ([
            'last_screen_result', 'last_result', 'last_forward_result',
            'g98_council_lane', 'portfolio_council_lane',
            'portfolio_research_contract', 'causal_experiment_lane',
            'hypothesis_contract', 'generation_target', 'origin',
            'screening_seed_only', 'parent_provenance', 'mutation_scope',
            'mutation_bundle', 'skill_crossover_sources', 'elite_candidate_fork',
        ] as $key) {
            unset($metadata[$key]);
        }

        return $metadata;
    }

    private const LANE_OWNERS = [
        'none' => [
            'owner' => 'no_volume_control',
            'applies_to_specialists' => ['parent_control'],
        ],
        'breakout_volume_confirmation' => [
            'owner' => 'breakout_specialist',
            'applies_to_specialists' => ['breakout', 'volatility'],
        ],
        'transition_volume_router' => [
            'owner' => 'transition_router',
            'applies_to_specialists' => ['trend_up', 'trend_down', 'range', 'breakout', 'session', 'consensus'],
        ],
        'low_volume_risk_firewall' => [
            'owner' => 'risk_layer',
            'applies_to_specialists' => ['all_actionable_signals'],
        ],
    ];
}
