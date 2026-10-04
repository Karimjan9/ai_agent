<?php

namespace App\Services;

use App\Models\InstrumentEvidence;
use App\Models\InstrumentInvocationLedger;
use App\Models\InstrumentValuePosterior;
use App\Models\LabAgent;
use App\Models\LabGeneration;
use App\Models\LabSkillZooEntry;
use App\Models\PlaybookComposition;
use App\Models\PlaybookValuePosterior;
use App\Models\RouterDecision;
use App\Models\TradingInstrument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only two-block funnel for a lightweight autonomous-mode monitor. */
class InstrumentLearningMonitorService
{
    public const PROTOCOL = 'instrument_learning_two_block_monitor_v2';

    /** @return array<string, mixed> */
    public function snapshot(string $symbol = 'XAUUSD'): array
    {
        $symbol = strtoupper(str_replace(['/', '_', '-'], '', $symbol));
        if (! Schema::hasTable('trading_instruments')) {
            return ['protocol' => self::PROTOCOL, 'status' => 'migration_pending'];
        }

        $roles = TradingInstrument::query()
            ->selectRaw('role, count(*) as aggregate')
            ->groupBy('role')
            ->pluck('aggregate', 'role')
            ->map(fn ($count): int => (int) $count)
            ->all();
        $verdicts = Schema::hasTable('instrument_invocation_ledger')
            ? InstrumentInvocationLedger::query()->where('symbol', $symbol)
                ->selectRaw('verdict, count(*) as aggregate')
                ->groupBy('verdict')->pluck('aggregate', 'verdict')
                ->map(fn ($count): int => (int) $count)->all()
            : [];
        $posteriorRows = Schema::hasTable('instrument_value_posteriors')
            ? InstrumentValuePosterior::query()->where('symbol', $symbol)->get()
            : collect();
        $posteriors = $posteriorRows->countBy('decay_state')->map(fn ($count): int => (int) $count)->all();
        $posteriorAuthority = $posteriorRows->map(fn (InstrumentValuePosterior $row): array => app(InstrumentPosteriorAuthorityService::class)->assess($row));
        $canonicalPosteriors = $posteriorAuthority->countBy('canonical_state')->map(fn ($count): int => (int) $count)->all();
        $bundleRows = Schema::hasTable('playbook_value_posteriors')
            ? PlaybookValuePosterior::query()->where('symbol', $symbol)
                ->whereHas('playbook', fn ($query) => $query->where(
                    'metadata->protocol',
                    'exact_instrument_research_bundle_v1',
                ))->get()
            : collect();
        $bundlePosteriors = $bundleRows->countBy('decay_state')->map(fn ($count): int => (int) $count)->all();
        $bundleAuthority = $bundleRows->map(fn (PlaybookValuePosterior $row): array => app(InstrumentPosteriorAuthorityService::class)->assess($row));
        $canonicalBundles = $bundleAuthority->countBy('canonical_state')->map(fn ($count): int => (int) $count)->all();
        $invocations = array_sum($verdicts);
        $settled = collect($verdicts)->only(['helped', 'harmed', 'neutral'])->sum();
        // JSON metadata is deliberately not indexed and a lifetime scan made
        // the lightweight status command take tens of seconds. Operational
        // monitoring needs the active/recent cohort window; immutable runtime
        // invocation/evidence totals below remain lifetime counters.
        $recentGenerationIds = LabGeneration::query()
            ->whereHas('laboratory', fn ($query) => $query->where('symbol', $symbol))
            ->latest('generation')->limit(5)->pluck('id');
        $sealedAssignments = LabAgent::query()->where('symbol', $symbol)
            ->whereIn('lab_generation_id', $recentGenerationIds)
            ->whereHas('modelVersion', fn ($query) => $query->where(
                'metadata->instrument_research_assignment->protocol',
                LabInstrumentResearchService::PROTOCOL,
            ))->count();
        $marketRouterDecisions = Schema::hasTable('router_decisions')
            ? RouterDecision::query()->where('symbol', $symbol)->count()
            : 0;
        $routerVerdicts = Schema::hasTable('router_decisions')
            ? RouterDecision::query()->where('symbol', $symbol)->selectRaw('decision, count(*) as aggregate')
                ->groupBy('decision')->pluck('aggregate', 'decision')->map(fn ($count): int => (int) $count)->all()
            : [];
        $strategyLibrary = app(StrategyLibraryCompilerService::class)->library();
        $compiler = app(StrategyLibraryCompilerService::class);
        $runtimeAliases = collect($strategyLibrary)->groupBy(fn (array $spec): string =>
            $compiler->runtimeBaseStrategy($spec['id']) ?: 'shadow_or_declarative')->map(fn ($rows) => $rows->pluck('id')->all())->all();
        $capabilities = app(TradingInstrumentOperatingSystemService::class)->runtimeCapabilities();
        $management = app(TradeManagementLibraryService::class);
        $managementCapabilities = collect(array_keys($management->library()))->mapWithKeys(fn (string $profile): array =>
            [$profile => $management->runtimeAdapter($profile)])->all();
        $latest = LabGeneration::whereIn('id', $recentGenerationIds)->latest('generation')->first();
        $researchPlaybooks = (array) data_get(app(StrategyResearchCatalogueService::class)->catalogue(), 'models', []);
        $instrumentPrograms = Schema::hasTable('research_instrument_programs')
            ? DB::table('research_instrument_programs')->where('symbol', $symbol)->count()
            : 0;
        $confirmedCartridges = Schema::hasTable('lab_skill_zoo_entries')
            ? LabSkillZooEntry::query()->where('symbol', $symbol)->where('status', 'confirmed')->get(['id', 'evidence'])
            : collect();
        $strictTransferredIds = Schema::hasTable('skill_cartridge_transplant_trials')
            ? DB::table('skill_cartridge_transplant_trials')
                ->where('symbol', $symbol)
                ->where('mode', 'exact_replication')
                ->where('status', 'passed')
                ->distinct()->pluck('lab_skill_zoo_entry_id')
            : collect();
        // One passed arm is not a successful transferable skill. The source
        // cartridge must itself be confirmed and its exact replication arm
        // must pass; independent/blinded/control arms cannot open invention.
        $successfulTransfers = $confirmedCartridges
            ->whereIn('id', $strictTransferredIds)
            ->filter(fn (LabSkillZooEntry $entry): bool => data_get($entry->evidence, 'authority.mentor_seed') === true)
            ->count();
        $inventionStatus = $instrumentPrograms > 0
            ? 'candidate_programs_available'
            : ($confirmedCartridges->isEmpty()
                ? 'gated_awaiting_confirmed_cartridge'
                : ($successfulTransfers < 1
                    ? 'gated_awaiting_successful_exact_transfer'
                    : 'ready_for_bounded_typed_dsl_synthesis'));

        return [
            'protocol' => self::PROTOCOL,
            'status' => $invocations > 0 ? 'runtime_connected' : 'awaiting_first_attested_replay',
            'organism' => $symbol,
            'block_1_candidate_inventory' => [
                'purpose' => 'human_curated_system_discovered_or_gated_improvised_candidates',
                'trading_instruments' => TradingInstrument::query()->count(),
                'roles' => $roles,
                'playbook_compositions' => Schema::hasTable('playbook_compositions') ? PlaybookComposition::query()->where('symbol', $symbol)->count() : 0,
                'strategy_library' => [
                    'total' => count($strategyLibrary),
                    'executable_research' => collect($strategyLibrary)->where('status', 'research')->count(),
                    'shadow_only' => collect($strategyLibrary)->where('status', 'shadow_only')->count(),
                    'distinct_runtime_keys' => count(array_diff(array_keys($runtimeAliases), ['shadow_or_declarative'])),
                    'runtime_aliases' => $runtimeAliases,
                    'catalogue_names_are_not_distinct_algorithms' => true,
                ],
                'tactic_library' => count(app(TacticCatalogueService::class)->catalogue()),
                'risk_library' => count(app(RiskManagementLibraryService::class)->library()),
                'trade_management_library' => count(app(TradeManagementLibraryService::class)->library()),
                'executable_capabilities' => ['protocol' => TradingInstrumentOperatingSystemService::CAPABILITY_PROTOCOL,
                    'by_status' => collect($capabilities)->countBy('implementation_status')->all(),
                    'instruments' => $capabilities, 'management_profiles' => $managementCapabilities,
                    'distinct_management_engines' => collect($managementCapabilities)->pluck('engine')->filter()->unique()->count(),
                    'standalone_algorithm_count_attested' => false],
                'professional_mtf_research_playbooks' => count($researchPlaybooks),
                'prior_blueprints' => count(app(PriorKnowledgeVaultService::class)->blueprints()),
                'research_instrument_programs' => $instrumentPrograms,
                'autonomous_invention' => [
                    'status' => $inventionStatus,
                    'confirmed_cartridges' => $confirmedCartridges->count(),
                    'successful_exact_transfers' => $successfulTransfers,
                    'gate' => 'confirmed_cartridge_and_successful_exact_transfer_required',
                    'raw_passed_arm_is_not_successful_transfer' => true,
                    'promotion_evidence' => false,
                ],
            ],
            'agent_runtime_funnel' => [
                'sealed_research_assignments' => $sealedAssignments,
                'sealed_assignment_window_generations' => 5,
                'attested_invocations' => $invocations,
                'distinct_agents' => Schema::hasTable('instrument_invocation_ledger')
                    ? InstrumentInvocationLedger::query()->where('symbol', $symbol)->whereNotNull('lab_agent_id')->distinct()->count('lab_agent_id')
                    : 0,
                'awaiting_paired_control' => (int) ($verdicts['awaiting_paired_control'] ?? 0),
                'support_consumed' => (int) ($verdicts['support_consumed'] ?? 0),
                'control_reference_consumed' => (int) ($verdicts['control_reference_consumed'] ?? 0),
                'settled_causal_invocations' => (int) $settled,
                'verdicts' => $verdicts,
            ],
            'market_state_router' => [
                'decisions' => $marketRouterDecisions,
                'verdicts' => $routerVerdicts,
                'status' => $marketRouterDecisions > 0 ? 'market_routes_recorded' : 'idle_no_market_route_requested',
                'scope' => 'paper/live or explicit stateful market-policy route only',
                'lab_replay_rule' => 'sealed research assignments are separate to prevent present-state leakage into historical replay',
                'selection_rule' => 'paper requires an exact verified bundle with a positive conservative lower bound; hierarchical evidence is research prior only',
            ],
            'block_2_verified_value' => [
                'instrument_evidence' => Schema::hasTable('instrument_evidence') ? InstrumentEvidence::query()->where('symbol', $symbol)->count() : 0,
                'context_slice_evidence' => Schema::hasTable('instrument_evidence') ? InstrumentEvidence::query()->where('symbol', $symbol)->where('source_type', 'like', '%context_slice%')->count() : 0,
                'declared_posteriors' => $posteriors,
                'canonical_posteriors' => $canonicalPosteriors,
                'confirmed_research_posteriors' => (int) ($canonicalPosteriors['confirmed'] ?? 0),
                'forbidden_research_posteriors' => (int) ($canonicalPosteriors['forbidden'] ?? 0),
                'status_only_quarantined_posteriors' => (int) ($canonicalPosteriors['status_only_quarantined'] ?? 0),
                'declared_exact_bundle_posteriors' => $bundlePosteriors,
                'canonical_exact_bundle_posteriors' => $canonicalBundles,
                'confirmed_exact_bundles' => (int) ($canonicalBundles['confirmed'] ?? 0),
                'forbidden_exact_bundles' => (int) ($canonicalBundles['forbidden'] ?? 0),
                'status_only_quarantined_bundles' => (int) ($canonicalBundles['status_only_quarantined'] ?? 0),
                'positive_reuse_rule' => 'isolated instrument and exact contextual bundle must agree',
                'interaction_claim_rule' => 'bundle value is not synergy until a controlled factorial ablation identifies interaction',
                'promotion_authority' => false,
            ],
            'truth_rule' => 'catalogue != invocation; invocation != causal value; causal value != promotion',
            'paired_projection_debt' => app(InstrumentInvocationLedgerService::class)->pendingResearchPairs($symbol, 'H1', 3),
            'independent_validation_dependency' => app(InstrumentResearchWindowService::class)->readiness(),
            'evolutionary_progress' => $latest ? app(ExperimentQualityProgressService::class)->snapshot($latest) : null,
            'promotion_evidence' => false,
        ];
    }
}
