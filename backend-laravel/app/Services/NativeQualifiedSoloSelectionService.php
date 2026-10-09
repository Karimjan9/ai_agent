<?php

namespace App\Services;

use App\Models\LabEvaluationRun;
use App\Models\ModelVersion;
use App\Models\NativeQualifiedSoloSelection;
use App\Models\ResearchExperimentWorkItem;
use App\Models\SpecialistCouncilVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Original standalone exams -> complete scoped eligible roster -> sealed ranking -> disjoint final comparator. */
class NativeQualifiedSoloSelectionService
{
    public const PROTOCOL = 'native_qualified_solo_selection_v1';
    public const QUALIFICATION_PROTOCOL = 'native_standalone_qualification_v1';
    public const MAX_ROSTER = 4;
    private const MAX_REGISTRY = 64;

    public function __construct(private ResearchPaperEpochContractService $epochs,
        private SpecialistCouncilContractService $contracts, private LabImmutableEvidenceService $evidence) {}

    /** No outcome or eligibility claim: only original source programmes and a prospective fixed exam. */
    public function sealQualificationSources(SpecialistCouncilVersion $parent, array $original, string $asOf): array
    {
        $time = CarbonImmutable::parse($asOf)->utc();
        if ($time->isFuture() || ! $this->contracts->manifestValid($parent->manifest)) throw new LogicException('SOLO_ORIGINAL_SOURCE_AND_AS_OF_REQUIRED');
        if ($parent->manifest['evaluation_policy']['minimum_independent_windows'] > SpecialistCouncilIndependentPanelService::MAX_WINDOWS) {
            throw new LogicException('SOLO_ORIGINAL_CANONICAL_WINDOW_BUDGET_INCOMPATIBLE');
        }
        $sources = [];
        foreach ($parent->manifest['members'] as $member) {
            if (! in_array($member['role'], SpecialistCouncilContractService::TRADING_ROLES, true)) continue;
            $model = ModelVersion::findOrFail($member['model_version_id']);
            if ($this->contracts->modelHash($model) !== $member['source_model_hash']) throw new LogicException('SOLO_ORIGINAL_MEMBER_MODEL_DRIFT');
            $source = ['source_version_id' => (int) $parent->id, 'source_manifest_hash' => $parent->manifest_hash,
                'source_model_version_id' => (int) $model->id, 'source_model_hash' => $member['source_model_hash'],
                'specialist_id' => $member['specialist_id'], 'role' => $member['role'], 'scope' => $member['scope'],
                'horizon' => $member['horizon'], 'passport_hash' => $member['passport_hash'],
                'source_capital_weight' => $member['capital_weight'], 'capital_weight' => 1];
            $source = $this->persistedValue($source);
            $sources[] = [...$source, 'source_projection_hash' => $this->epochs->parameterHash($source)];
        }
        if ($sources === [] || count($sources) > self::MAX_ROSTER) throw new LogicException('SOLO_ORIGINAL_SOURCE_ROSTER_BUDGET_EXCEEDED');
        $body = ['protocol' => self::QUALIFICATION_PROTOCOL, 'parent_version_id' => (int) $parent->id,
            'parent_manifest_hash' => $parent->manifest_hash, 'as_of' => $time->toIso8601String(), 'sources' => $sources,
            'symbols' => array_values(array_unique(array_merge(...array_column(array_column($sources, 'scope'), 'symbols')))),
            'account_policy' => $this->accountPolicy($parent, $original), 'objective' => $parent->manifest['evaluation_policy']['objective'],
            'criteria' => $this->criteria($parent), 'authority' => 'scoped_standalone_research_exam_only', 'promotion_evidence' => false];
        $body = $this->persistedValue($body);
        return [...$body, 'source_panel_hash' => $this->epochs->parameterHash($body)];
    }

    public function assertQualificationSources(array $panel, SpecialistCouncilVersion $parent, array $original): void
    {
        if (! $this->evidence->equivalentJsonValue($panel, $this->sealQualificationSources($parent, $original, $panel['as_of'] ?? ''))) {
            throw new LogicException('SOLO_ORIGINAL_QUALIFICATION_SOURCE_OR_CRITERIA_DRIFT');
        }
    }

    /** This scoped research qualification is not whole-council, causal skill, economic parent or paper authority. */
    public function criteria(SpecialistCouncilVersion $parent): array
    {
        return ['minimum_windows' => max(3, $parent->manifest['evaluation_policy']['minimum_independent_windows']),
            'minimum_positive_windows' => 2, 'minimum_paired_trades' => max(20, $parent->manifest['evaluation_policy']['minimum_paired_trades']),
            'aggregate_net_positive' => true, 'no_censored_outcomes' => true, 'bootstrap_method' => 'bootstrap_profit_factor',
            'bootstrap_simulations' => 500, 'bootstrap_seed' => 42, 'bootstrap_pf_5_percentile_minimum' => 1.10];
    }

    /** Only the server reservation may declare any new standalone/selection/final comparator purpose. */
    public function assertDeclaredPlan(array $plan): void
    {
        $fields = array_values(array_filter(['standalone_qualification_panel', 'solo_selection_panel', 'best_qualified_solo_selection'],
            fn ($field) => array_key_exists($field, $plan)));
        if ($fields === []) {
            if (collect($plan['arms'] ?? [])->contains(fn ($arm) => isset($arm['standalone_source']))) throw new LogicException('SOLO_NATIVE_PROJECTION_ORIGINAL_PANEL_REQUIRED');
            return;
        }
        if (count($fields) !== 1 || ($plan['purpose'] ?? null) !== 'independent') throw new LogicException('SOLO_ORIGINAL_TYPED_PANEL_REQUIRED');
        $work = ResearchExperimentWorkItem::findOrFail($plan['panel_work_item_id'] ?? 0);
        if ($work->status !== 'leased' || ! $work->lease_expires_at?->isFuture()) throw new LogicException('SOLO_ORIGINAL_PANEL_LEASE_REQUIRED');
        $body = app(SpecialistCouncilPanelReservationService::class)->body($work);
        $field = $fields[0];
        if (($plan['panel_reservation_hash'] ?? null) !== $body['reservation_hash']
            || ! $this->evidence->equivalentJsonValue($plan[$field], $body[$field] ?? null)) throw new LogicException('SOLO_ORIGINAL_PANEL_DECLARATION_DRIFT');
    }

    /** Server inventories all original qualification receipts in scope, not an arbitrary caller shortlist. */
    public function sealRoster(SpecialistCouncilVersion $parent, array $original, string $asOf): array
    {
        $time = CarbonImmutable::parse($asOf)->utc();
        if ($time->isFuture() || ! $this->contracts->manifestValid($parent->manifest)) throw new LogicException('SOLO_SELECTION_ORIGINAL_AS_OF_AND_PARENT_REQUIRED');
        $objective = $parent->manifest['evaluation_policy']['objective'];
        $returnFloor = $parent->manifest['evaluation_policy']['solo_return_floor'] ?? null;
        if ($objective === 'lower_risk_at_equal_return' && (! is_numeric($returnFloor) || ! is_finite((float) $returnFloor) || $returnFloor < 0)) {
            throw new LogicException('SOLO_SELECTION_PREREGISTERED_EQUAL_RETURN_FLOOR_REQUIRED');
        }
        $symbols = array_values(array_unique(array_merge(...array_column(array_column($parent->manifest['members'], 'scope'), 'symbols'))));
        $inventory = NativeQualifiedSoloSelection::where('panel_kind', 'qualification')->where('created_at', '<=', $time)
            ->where(function ($query) use ($symbols): void {
                foreach ($symbols as $symbol) $query->orWhereJsonContains('receipt->source_panel->symbols', $symbol);
            })
            ->orderBy('id')->limit(self::MAX_REGISTRY + 1)->get();
        if ($inventory->count() > self::MAX_REGISTRY) throw new LogicException('SOLO_SELECTION_REGISTRY_LOOKUP_BUDGET_EXCEEDED');
        $roster = []; $excluded = [];
        foreach ($inventory as $row) {
            $body = $this->reinspect($row);
            foreach ($body['sources'] as $source) {
                $compatible = array_filter($parent->manifest['members'], fn ($member) => $this->matchesOriginalTask($member, $source));
                if (($source['research_qualified'] ?? false) !== true || $compatible === []
                    || ! $this->evidence->equivalentJsonValue($body['source_panel']['account_policy'], $this->accountPolicy($parent, $original))) {
                    $excluded[] = ['qualification_id' => (int) $row->id, 'specialist_id' => $source['specialist_id'],
                        'reason' => 'SOLO_ORIGINAL_QUALIFICATION_OR_SCOPED_POLICY_INELIGIBLE'];
                    continue;
                }
                $projection = $source['projection'];
                $key = $projection['source_projection_hash'];
                // Identical physical programme exams do not create additional competitors or independent claims.
                $roster[$key] ??= ['qualification_id' => (int) $row->id, 'qualification_hash' => $row->selection_hash,
                    'projection' => $projection, 'specialist_id' => $source['specialist_id'], 'role' => $source['role'],
                    'scope' => $source['scope'], 'horizon' => $source['horizon'], 'original_observations' => $source['observations']];
            }
        }
        if ($roster === []) throw new LogicException('NO_ORIGINAL_QUALIFIED_STANDALONE_ROSTER');
        if (count($roster) > self::MAX_ROSTER) throw new LogicException('SOLO_SELECTION_ELIGIBLE_ROSTER_BUDGET_EXCEEDED');
        $body = ['protocol' => self::PROTOCOL, 'as_of' => $time->toIso8601String(), 'parent_version_id' => (int) $parent->id,
            'parent_manifest_hash' => $parent->manifest_hash, 'objective' => $objective,
            ...($objective === 'lower_risk_at_equal_return' ? ['return_floor' => $returnFloor] : []),
            'account_policy' => $this->accountPolicy($parent, $original), 'registry_high_water_id' => (int) ($inventory->last()?->id ?? 0),
            'roster' => array_values($roster), 'excluded' => $excluded, 'tie_break' => 'ascending_original_qualification_id_then_specialist_id',
            'rank_on_final_validation' => false, 'risk_comparability' => 'equal_external_capital_and_risk_budget_not_equal_realized_drawdown',
            'authority' => 'research_selection_only', 'promotion_evidence' => false];
        $body = $this->persistedValue($body);
        return [...$body, 'roster_hash' => $this->epochs->parameterHash($body)];
    }

    /** Frozen as-of roster, never a later registry ranking or a newly invented qualification flag. */
    public function assertRoster(array $body, SpecialistCouncilVersion $parent, array $original): void
    {
        if (! $this->evidence->equivalentJsonValue($body, $this->sealRoster($parent, $original, $body['as_of'] ?? ''))) {
            throw new LogicException('SOLO_SELECTION_ORIGINAL_ROSTER_OR_QUALIFICATION_DRIFT');
        }
    }

    /** Qualification for another trading horizon is not the requested SOLO task. */
    private function matchesOriginalTask(array $member, array $source): bool
    {
        return ($member['role'] ?? null) === ($source['role'] ?? null)
            && $this->evidence->equivalentJsonValue($member['scope'] ?? null, $source['scope'] ?? null)
            && $this->evidence->equivalentJsonValue($member['horizon'] ?? null, $source['horizon'] ?? null);
    }

    /** Called only after the canonical original panel's entire immutable run set exists. */
    private function deriveOriginalPanelReceipt(SpecialistCouncilVersion $version, array $runIds): array
    {
        $row = DB::table('specialist_council_evaluation_plans')->where('specialist_council_version_id', $version->id)->sole();
        $plan = json_decode($row->plan, true, 512, JSON_THROW_ON_ERROR);
        $qualification = $plan['standalone_qualification_panel'] ?? null; $selection = $plan['solo_selection_panel'] ?? null;
        if ($row->plan_hash !== $this->epochs->parameterHash($plan) || (is_array($qualification) === is_array($selection))) {
            throw new LogicException('SOLO_ORIGINAL_TYPED_PANEL_REQUIRED');
        }
        $panel = $qualification ?? $selection; $parent = SpecialistCouncilVersion::findOrFail($panel['parent_version_id']);
        app(SpecialistCouncilLifecycleService::class)->verifiedOriginalPanelAssessment($version, $runIds);
        $original = $this->originalPlan($parent);
        $qualification ? $this->assertQualificationSources($panel, $parent, $original) : $this->assertRoster($panel, $parent, $original);
        $expected = $qualification ? $panel['sources'] : array_column($panel['roster'], 'projection');
        $products = []; $keys = []; $clocks = [];
        foreach ($runIds as $id) {
            $run = LabEvaluationRun::where('run_id', $id)->sole();
            $ownedArms = array_values(array_filter($plan['arms'], fn ($arm) => (int) $arm['model_version_id'] === (int) $run->model_version_id));
            if (count($ownedArms) !== 1) throw new LogicException('SOLO_SELECTION_ORIGINAL_ARM_OWNER_AMBIGUOUS');
            if ($ownedArms[0]['kind'] !== 'solo') { $keys[$ownedArms[0]['arm_key']] = true; continue; }
            $outcome = app(SpecialistCouncilLifecycleService::class)->originalNativePanelOutcome($version, $run);
            $arm = $outcome['arm']; $key = $arm['arm_key'];
            if (isset($keys[$key])) throw new LogicException('SOLO_SELECTION_DUPLICATE_ORIGINAL_ARM');
            $keys[$key] = true;
            if ($arm['kind'] !== 'solo') continue;
            $projection = $arm['standalone_source'] ?? null;
            if (! is_array($projection) || ! collect($expected)->contains(fn ($source) => $this->evidence->equivalentJsonValue($source, $projection))) {
                throw new LogicException('SOLO_SELECTION_ORIGINAL_SCOPED_SOURCE_REQUIRED');
            }
            $receipt = $outcome['native_receipt']; $member = $receipt['members'][0] ?? null;
            if (($receipt['status'] ?? null) !== 'computed' || count($receipt['members'] ?? []) !== 1
                || ($member['specialist_id'] ?? null) !== $projection['specialist_id'] || (int) data_get($member, 'stages.decision:observed', 0) < 1) {
                throw new LogicException('SOLO_SELECTION_ACTUAL_SINGLETON_ACCOUNT_REQUIRED');
            }
            $window = $arm['window_key']; $clockHash = $this->epochs->parameterHash($outcome['executed_clock']);
            if (isset($clocks[$window]) && $clocks[$window] !== $clockHash) throw new LogicException('SOLO_SELECTION_EXECUTED_CLOCK_PARITY_REQUIRED');
            $clocks[$window] = $clockHash; $projectionHash = $projection['source_projection_hash'];
            if (isset($products[$projectionHash][$window])) throw new LogicException('SOLO_SELECTION_DUPLICATE_ROSTER_WINDOW');
            $products[$projectionHash][$window] = ['run_id' => $id, 'arm_key' => $key, 'window_key' => $window,
                'request_hash' => $run->request_hash, 'response_hash' => $run->response_hash, 'metrics' => $outcome['metrics'],
                'statistics' => $receipt['standalone_qualification_statistics'] ?? null, 'physical_intervals' => $outcome['physical_intervals']];
        }
        if (count($keys) !== count($plan['arms']) || count($products) !== count($expected)
            || collect($products)->contains(fn ($rows) => count($rows) !== count($plan['windows']))) throw new LogicException('SOLO_SELECTION_ALL_ORIGINAL_ARMS_REQUIRED');
        $sources = []; $ranking = [];
        foreach ($expected as $ordinal => $projection) {
            $observations = array_values($products[$projection['source_projection_hash']]);
            $reasons = $this->sourceReasons($observations, $version, $qualification ? $panel['criteria'] : $this->criteria($parent), (bool) $qualification);
            $net = array_sum(array_column(array_column($observations, 'metrics'), 'net_profit'));
            $maxDrawdown = max(array_column(array_column($observations, 'metrics'), 'max_drawdown_percent'));
            $sources[] = ['projection' => $projection, 'specialist_id' => $projection['specialist_id'], 'role' => $projection['role'],
                'scope' => $projection['scope'], 'horizon' => $projection['horizon'], 'observations' => $observations,
                'reason_codes' => $reasons, 'research_qualified' => $qualification && $reasons === [], 'promotion_evidence' => false];
            $ranking[] = ['source_projection_hash' => $projection['source_projection_hash'], 'qualification_id' => $selection['roster'][$ordinal]['qualification_id'] ?? 0,
                'specialist_id' => $projection['specialist_id'], 'net_profit' => $net, 'max_drawdown_percent' => $maxDrawdown, 'reason_codes' => $reasons];
        }
        $eligibleRanking = ! $qualification && ! collect($ranking)->contains(fn ($r) => $r['reason_codes'] !== []);
        if ($eligibleRanking) {
            $ranking = $this->rank($panel['objective'], $ranking, isset($panel['return_floor']) ? (float) $panel['return_floor'] : null);
            $eligibleRanking = $ranking !== [];
        }
        $body = ['protocol' => $qualification ? self::QUALIFICATION_PROTOCOL : self::PROTOCOL,
            'panel_kind' => $qualification ? 'qualification' : 'selection', 'version_id' => (int) $version->id, 'plan_hash' => $row->plan_hash,
            'source_panel' => $panel, 'sources' => $sources, 'ranking' => $qualification ? [] : $ranking,
            'selected_source_projection_hash' => $eligibleRanking ? $ranking[0]['source_projection_hash'] : null,
            'status' => $qualification ? 'original_standalone_research_exam' : ($eligibleRanking ? 'ranked_original_qualified_roster' : 'original_selection_unassessable'),
            'original_run_ids' => array_values($runIds), 'best_within_preregistered_scoped_roster' => $eligibleRanking,
            'rank_on_final_validation' => false, 'independent_evidence' => false, 'paper_authority_granted' => false, 'promotion_evidence' => false];
        return $body;
    }

    public function settleOriginalPanel(SpecialistCouncilVersion $version, array $runIds): NativeQualifiedSoloSelection
    {
        $body = $this->deriveOriginalPanelReceipt($version, $runIds); $hash = $this->epochs->parameterHash($body);
        return DB::transaction(function () use ($version, $body, $hash): NativeQualifiedSoloSelection {
            $existing = NativeQualifiedSoloSelection::where('specialist_council_version_id', $version->id)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->selection_hash !== $hash) throw new LogicException('ORIGINAL_SOLO_SELECTION_ALREADY_SEALED');
                $this->recordSelectionUse($version, $existing);
                return $existing;
            }
            $created = NativeQualifiedSoloSelection::create(['specialist_council_version_id' => (int) $version->id, 'panel_kind' => $body['panel_kind'],
                'plan_hash' => $body['plan_hash'], 'selection_hash' => $hash, 'receipt' => $body, 'created_at' => now()->utc()]);
            $this->recordSelectionUse($version, $created);
            return $created;
        });
    }

    /** Projection follows the whole original exam; no individual arm can poison its unobserved peers. */
    private function recordSelectionUse(SpecialistCouncilVersion $version, NativeQualifiedSoloSelection $row): void
    {
        foreach ($row->receipt['sources'] as $source) foreach ($source['observations'] as $observation) {
            $events = array_map(fn ($interval) => ['symbol' => $interval['symbol'], 'event_start' => $interval['start_inclusive'],
                'event_end' => $interval['observed_end_exclusive'], 'available_at' => $interval['observed_end_exclusive'],
                'matured_at' => $interval['end_exclusive'], 'provenance' => ['protocol' => self::PROTOCOL,
                    'original_run_id' => $observation['run_id'], 'selection_hash' => $row->selection_hash,
                    'source_sha256' => $interval['source_sha256'], 'stream' => $interval['stream'], 'event_count' => $interval['event_count']]],
                $observation['physical_intervals']['intervals']);
            app(SpecialistCouncilDataUseService::class)->recordUse($version, $events, 'selection', 'native-solo-panel:'.$row->id,
                now()->utc()->toIso8601String(), $observation['run_id']);
        }
    }

    /** Only this sealed panel's later own projection is ignored; an outside training/selection use is never hidden. */
    public function hasForeignExposure(SpecialistCouncilVersion $version, string $symbol, string $start, string $end): bool
    {
        $row = NativeQualifiedSoloSelection::where('specialist_council_version_id', $version->id)->first();
        if (! $row || $row->selection_hash !== $this->epochs->parameterHash($row->receipt)) {
            return app(SpecialistCouncilDataUseService::class)->intervalExposed($version, $symbol, $start, $end);
        }
        $original = $this->originalPlan($version);
        $field = $row->panel_kind === 'qualification' ? 'standalone_qualification_panel' : 'solo_selection_panel';
        $exam = DB::table('specialist_council_evaluations')->where('specialist_council_version_id', $version->id)->first();
        if (! $exam || ! isset($original[$field]) || $row->plan_hash !== $this->epochs->parameterHash($original)
            || ($row->receipt['plan_hash'] ?? null) !== $row->plan_hash
            || ! $this->evidence->equivalentJsonValue($row->receipt['source_panel'] ?? null, $original[$field])
            || json_decode($exam->original_run_ids, true, 512, JSON_THROW_ON_ERROR) !== $row->receipt['original_run_ids']) {
            throw new LogicException('SOLO_ORIGINAL_SELECTION_PROJECTION_OWNER_INVALID');
        }
        $uses = DB::table('specialist_council_data_uses as uses')->join('specialist_council_data_events as events', 'events.id', '=', 'uses.event_id')
            ->whereIn('uses.use', ['training', 'selection'])->where('events.symbol', strtoupper($symbol))
            ->where('events.event_start', '<', CarbonImmutable::parse($end)->utc())
            ->where('events.event_end', '>', CarbonImmutable::parse($start)->utc())->select('uses.*', 'events.event_start', 'events.event_end')->limit(1025)->get();
        if ($uses->count() > 1024) throw new LogicException('SOLO_ORIGINAL_EXPOSURE_LOOKUP_BUDGET_EXCEEDED');
        foreach ($uses as $use) {
            if ((int) $use->specialist_council_version_id !== (int) $version->id || $use->use !== 'selection'
                || $use->consumer_id !== 'native-solo-panel:'.$row->id || ! in_array($use->run_id, $row->receipt['original_run_ids'], true)
                || CarbonImmutable::parse($use->as_of)->utc()->lt($row->created_at)) return true;
            $observations = array_merge(...array_column($row->receipt['sources'], 'observations'));
            $observation = collect($observations)->firstWhere('run_id', $use->run_id);
            $matches = $observation ? array_filter($observation['physical_intervals']['intervals'], fn ($i) => $i['symbol'] === strtoupper($symbol)
                && CarbonImmutable::parse($i['start_inclusive'])->equalTo(CarbonImmutable::parse($use->event_start, 'UTC'))
                && CarbonImmutable::parse($i['observed_end_exclusive'])->equalTo(CarbonImmutable::parse($use->event_end, 'UTC'))) : [];
            if ($matches === []) return true;
        }
        return false;
    }

    private function sourceReasons(array $rows, SpecialistCouncilVersion $version, array $criteria, bool $qualifying): array
    {
        $reasons = []; $positive = 0; $total = 0;
        if (count($rows) < $criteria['minimum_windows']) $reasons[] = 'SOLO_ORIGINAL_POWERED_WINDOWS_INSUFFICIENT';
        foreach ($rows as $row) {
            $metrics = $row['metrics']; $total += $metrics['net_profit']; $positive += $metrics['net_profit'] > 0 ? 1 : 0;
            if ($metrics['matured_trades'] < $criteria['minimum_paired_trades']) $reasons[] = 'SOLO_ORIGINAL_MATURE_HORIZON_POWER_INSUFFICIENT';
            if ($metrics['censored_trades'] !== 0.0) $reasons[] = 'SOLO_ORIGINAL_OUTCOME_MATURITY_INCOMPLETE';
            foreach (['max_drawdown_percent', 'max_daily_loss_percent', 'max_gross_exposure_percent', 'max_total_risk_percent'] as $field) {
                if ($metrics[$field] > $version->manifest['execution'][$field]) $reasons[] = 'SOLO_ORIGINAL_EXTERNAL_RISK_LIMIT_EXCEEDED';
            }
            if ($qualifying) {
                $stats = $row['statistics'];
                if (! is_array($stats) || ($stats['protocol'] ?? null) !== 'native_standalone_qualification_statistics_v1'
                    || ($stats['criteria_hash'] ?? null) !== $this->epochs->parameterHash($criteria)
                    || ($stats['status'] ?? null) !== 'complete' || ($stats['censored_trade_count'] ?? null) !== 0
                    || ($stats['unknown_maturity_count'] ?? null) !== 0
                    || data_get($stats, 'bootstrap.status') !== 'assessed'
                    || data_get($stats, 'bootstrap.method') !== 'bootstrap_profit_factor'
                    || data_get($stats, 'bootstrap.simulations') !== 500
                    || data_get($stats, 'bootstrap.seed') !== 42
                    || (int) data_get($stats, 'bootstrap.trade_count', -1) !== (int) $metrics['matured_trades']
                    || ! is_numeric(data_get($stats, 'bootstrap.pf_5_percentile_lower_bound'))
                    || data_get($stats, 'bootstrap.pf_5_percentile_lower_bound') < 1.10) $reasons[] = 'SOLO_ORIGINAL_BOOTSTRAP_EVIDENCE_INSUFFICIENT';
            }
        }
        if ($qualifying && ($positive < 2 || $total <= 0)) $reasons[] = 'SOLO_ORIGINAL_POSITIVE_VALUE_NOT_REPLICATED';
        return array_values(array_unique($reasons));
    }

    /** Fixed objective and stable original identities; never use final evaluation to select the winner. */
    public function rank(string $objective, array $rows, ?float $returnFloor = null): array
    {
        if (! in_array($objective, ['net_return_at_equal_risk', 'lower_risk_at_equal_return'], true) || $rows === [] || count($rows) > self::MAX_ROSTER) throw new LogicException('SOLO_SELECTION_FIXED_OBJECTIVE_REQUIRED');
        foreach ($rows as $row) foreach (['net_profit', 'max_drawdown_percent'] as $field) {
            if (! is_numeric($row[$field] ?? null) || ! is_finite((float) $row[$field])) throw new LogicException('SOLO_SELECTION_FINITE_ORIGINAL_METRIC_REQUIRED');
        }
        if ($objective === 'lower_risk_at_equal_return') {
            if ($returnFloor === null || ! is_finite($returnFloor) || $returnFloor < 0) throw new LogicException('SOLO_SELECTION_PREREGISTERED_EQUAL_RETURN_FLOOR_REQUIRED');
            $rows = array_values(array_filter($rows, fn ($row) => $row['net_profit'] >= $returnFloor));
        }
        usort($rows, fn ($a, $b) => ($objective === 'net_return_at_equal_risk'
            ? (($b['net_profit'] <=> $a['net_profit']) ?: ($a['max_drawdown_percent'] <=> $b['max_drawdown_percent']))
            : (($a['max_drawdown_percent'] <=> $b['max_drawdown_percent']) ?: ($b['net_profit'] <=> $a['net_profit'])))
            ?: (($a['qualification_id'] <=> $b['qualification_id']) ?: strcmp($a['specialist_id'], $b['specialist_id'])));
        return $rows;
    }

    private function reinspect(NativeQualifiedSoloSelection $row): array
    {
        if ($row->selection_hash !== $this->epochs->parameterHash($row->receipt)) throw new LogicException('SOLO_SELECTION_ORIGINAL_RECEIPT_DRIFT');
        $fresh = $this->deriveOriginalPanelReceipt(SpecialistCouncilVersion::findOrFail($row->specialist_council_version_id), $row->receipt['original_run_ids']);
        if ($this->epochs->parameterHash($fresh) !== $row->selection_hash) throw new LogicException('SOLO_SELECTION_ORIGINAL_PRODUCER_DRIFT');
        foreach ($row->receipt['sources'] as $source) foreach ($source['observations'] as $observation) {
            if (! DB::table('specialist_council_data_uses')->where('specialist_council_version_id', $row->specialist_council_version_id)
                ->where('consumer_id', 'native-solo-panel:'.$row->id)->where('run_id', $observation['run_id'])->where('use', 'selection')->exists()) {
                throw new LogicException('SOLO_ORIGINAL_SELECTION_CONSUMPTION_RECEIPT_REQUIRED');
            }
        }
        return $fresh;
    }

    /** Selection, qualification, all context/warmup and possible holding events are excluded from final validation. */
    public function selectedOriginal(int $id, SpecialistCouncilVersion $parent, array $original, array $windows): array
    {
        $row = NativeQualifiedSoloSelection::findOrFail($id); $body = $this->reinspect($row);
        if ($row->panel_kind !== 'selection' || $body['status'] !== 'ranked_original_qualified_roster'
            || ! in_array((int) $parent->id, [$body['source_panel']['parent_version_id'], $body['version_id']], true) || $body['selected_source_projection_hash'] === null) {
            throw new LogicException('SOLO_SELECTION_ORIGINAL_RANKED_RECEIPT_REQUIRED');
        }
        $originalParent = SpecialistCouncilVersion::findOrFail($body['source_panel']['parent_version_id']);
        $this->assertRoster($body['source_panel'], $originalParent, $this->originalPlan($originalParent));
        foreach (['members', 'components', 'execution', 'allocation', 'risk', 'routing'] as $field) {
            if (! $this->evidence->equivalentJsonValue($parent->manifest[$field], $originalParent->manifest[$field])) {
                throw new LogicException('SOLO_SELECTION_FINAL_CANDIDATE_PROGRAMME_OR_EXTERNAL_POLICY_CHANGED');
            }
        }
        if (! $this->evidence->equivalentJsonValue($body['source_panel']['account_policy'], $this->accountPolicy($parent, $original))) {
            throw new LogicException('SOLO_SELECTION_FINAL_ACCOUNT_COST_RISK_CLOCK_CHANGED');
        }
        $source = collect($body['sources'])->first(fn ($s) => $s['projection']['source_projection_hash'] === $body['selected_source_projection_hash']);
        $intervals = [];
        foreach ($body['sources'] as $s) foreach ($s['observations'] as $observation) $intervals = [...$intervals, ...$observation['physical_intervals']['intervals']];
        foreach ($body['source_panel']['roster'] as $s) foreach ($s['original_observations'] as $observation) $intervals = [...$intervals, ...$observation['physical_intervals']['intervals']];
        foreach ($windows as $record) {
            $window = $record['window'] ?? $record; $finalIntervals = [['start_inclusive' => $window['start_inclusive'], 'end_exclusive' => $window['end_exclusive']]];
            if (isset($record['transport_proof'])) {
                $holding = max(array_map(fn ($member) => (int) data_get($member, 'horizon.max_holding_seconds', 0), $parent->manifest['members']));
                foreach ($record['transport_proof']['files'] as $stream => $file) $finalIntervals[] = [
                    'start_inclusive' => $file['start_inclusive'], 'end_exclusive' => CarbonImmutable::parse($file['last_candle_at'])->utc()
                        ->addSeconds($this->contracts->timeframeSeconds($stream) + $holding)->toIso8601String()];
            }
            foreach ($intervals as $interval) foreach ($finalIntervals as $final) {
                if (CarbonImmutable::parse($interval['start_inclusive'])->utc()->lt(CarbonImmutable::parse($final['end_exclusive'])->utc())
                    && CarbonImmutable::parse($interval['end_exclusive'])->utc()->gt(CarbonImmutable::parse($final['start_inclusive'])->utc())) {
                    throw new LogicException('SOLO_SELECTION_FINAL_EVENTS_OVERLAP_ORIGINAL_SELECTION_OR_QUALIFICATION');
                }
                if (CarbonImmutable::parse($final['start_inclusive'])->utc()->lt(CarbonImmutable::parse($interval['end_exclusive'])->utc())) {
                    throw new LogicException('SOLO_SELECTION_FINAL_EVENTS_MUST_FOLLOW_ORIGINAL_SELECTION_AND_MATURITY');
                }
            }
        }
        return ['selection_id' => (int) $row->id, 'selection_hash' => $row->selection_hash, 'source' => $source['projection'],
            'selection_observation_intervals' => $intervals, 'best_within_preregistered_scoped_roster' => true,
            'independent_evidence' => false, 'promotion_evidence' => false];
    }

    public function originalPlan(SpecialistCouncilVersion $version): array
    {
        $row = DB::table('specialist_council_evaluation_plans')->where('specialist_council_version_id', $version->id)->sole();
        $plan = json_decode($row->plan, true, 512, JSON_THROW_ON_ERROR);
        if ($row->plan_hash !== $this->epochs->parameterHash($plan)) throw new LogicException('SOLO_ORIGINAL_PLAN_HASH_DRIFT');
        return $plan;
    }

    private function accountPolicy(SpecialistCouncilVersion $version, array $plan): array
    {
        return ['initial_capital' => $plan['initial_capital'], 'cost_model' => $plan['cost_model'], 'risk_policy' => $plan['risk_policy'],
            'execution_hash' => $plan['execution_hash'], 'execution_timeframe' => $plan['execution_timeframe'], 'external_execution' => $version->manifest['execution']];
    }

    /** The existing durable work item's JSON cast normalizes integral floats before persistence. */
    private function persistedValue(array $value): array
    {
        return json_decode(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }
}
