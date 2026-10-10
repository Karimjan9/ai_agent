<?php

namespace App\Services;

use App\Models\AgentLearningCausalExperiment;
use App\Models\CausalFoldReceipt;
use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\LabEvidenceArtifact;
use App\Models\ScopedResearchCertificate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Original producer for a fixed, prospective, equal-cap selector panel. */
class ScopedSelectorPanelService
{
    public const PROTOCOL = 'scoped_equal_budget_selector_panel_v1';

    public const WALL_METRIC = 'selector_and_economic_replay_wall_seconds';

    public const CPU_METRIC = 'selector_and_economic_replay_cpu_seconds';

    public function __construct(private TypedInstrumentFoundryService $foundry,
        private ResearchPaperEpochContractService $epochs, private LabImmutableEvidenceService $immutable) {}

    /** Reservation is permanent research purpose, even if its signed declaration later becomes invalid. */
    public function reservesOrdinaryEvaluation(LabAgent $agent): bool
    {
        if (! Schema::hasTable('research_compounding_benchmarks')) {
            return false;
        }
        // This key is an operational withholding reservation, never scientific evidence or permission.
        if (DB::table('research_compounding_benchmarks')->where('benchmark_key',
            $this->purposeKey((int) $agent->id))->exists()) {
            return true;
        }
        $rows = DB::table('research_compounding_benchmarks')->where('status', 'like', 'selector_panel_%')
            ->where(function ($query) use ($agent) {
                $id = (int) $agent->id;
                foreach (['%"agent_id":'.$id.',%', '%"agent_id":'.$id.'}%',
                    '%"agent_id": '.$id.',%', '%"agent_id": '.$id.'}%'] as $pattern) {
                    $query->orWhere('sealed_contract', 'like', $pattern);
                }
            })->orderBy('id')->limit(100)->get();
        foreach ($rows as $row) {
            $contract = $this->readContract($row);
            if ($contract === null) {
                // An invalid original purpose seal cannot be treated as permission to execute historical data.
                if (preg_match('/"agent_id"\s*:\s*"?'.(int) $agent->id.'"?(?:\D|$)/', $row->sealed_contract)) {
                    return true;
                }

                continue;
            }
            if (($contract['data_binding_required'] ?? false) !== true) {
                continue;
            }
            foreach ((array) ($contract['members'] ?? []) as $member) {
                foreach ((array) ($member['arms'] ?? []) as $arm) {
                    if ((int) ($arm['agent_id'] ?? 0) === (int) $agent->id) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /** Existing durable folds/arbiter execute members; this owner creates no scheduler. */
    public function preregister(AgentLearningCausalExperiment $anchor, array $experimentIds, string $seed, array $rules = []): array
    {
        if (! Schema::hasTable('research_compounding_benchmarks')) {
            return $this->blocked('SELECTOR_PANEL_BENCHMARK_OWNER_UNAVAILABLE');
        }
        $ids = array_map('intval', $experimentIds);
        sort($ids);
        if (count($ids) < 3 || count($ids) > 24 || count(array_unique($ids)) !== count($ids)
            || ! in_array((int) $anchor->id, $ids, true) || $seed === '' || strlen($seed) > 200) {
            return $this->blocked('SELECTOR_PANEL_DISTINCT_MULTI_QUESTION_MEMBERSHIP_REQUIRED');
        }
        if (array_diff(array_keys($rules), ['resource_metric', 'minimum_trades', 'minimum_positive_questions', 'minimum_effect', 'planned_authorization_ids']) !== []) {
            return $this->blocked('SELECTOR_PANEL_ASSERTED_OUTCOMES_OR_CAPS_FORBIDDEN');
        }
        $plans = (array) ($rules['planned_authorization_ids'] ?? []);
        if ($plans !== [] && (count($plans) !== count($ids) || array_diff($ids, array_map('intval', array_keys($plans))) !== [])) {
            return $this->blocked('SELECTOR_PANEL_COMPLETE_PLANNED_WINDOW_ROSTER_REQUIRED');
        }
        $rules = ['resource_metric' => $rules['resource_metric'] ?? self::WALL_METRIC,
            'minimum_trades' => $rules['minimum_trades'] ?? 8,
            'minimum_positive_questions' => $rules['minimum_positive_questions'] ?? 3,
            'minimum_effect' => $rules['minimum_effect'] ?? .000001];
        $rules['risk_guard'] = ['max_drawdown_percent' => min(15.0, (float) config('services.dual_track.max_drawdown_percent', 15)),
            'max_risk_of_ruin_percent' => min(10.0, (float) config('services.dual_track.max_risk_of_ruin_percent', 10))];
        if (! in_array($rules['resource_metric'], [self::WALL_METRIC, self::CPU_METRIC], true)
            || ! is_int($rules['minimum_trades']) || $rules['minimum_trades'] < 3
            || ! is_int($rules['minimum_positive_questions']) || $rules['minimum_positive_questions'] < 3
            || $rules['minimum_positive_questions'] > count($ids)
            || ! $this->finite($rules['minimum_effect']) || $rules['minimum_effect'] <= 0) {
            return $this->blocked('SELECTOR_PANEL_BOUNDED_PREREGISTERED_METRIC_AND_POWER_REQUIRED');
        }
        $members = [];
        $common = null;
        foreach ($ids as $id) {
            $experiment = AgentLearningCausalExperiment::find($id);
            if (! $experiment) {
                return $this->blocked('SELECTOR_PANEL_ORIGINAL_EXPERIMENT_REQUIRED');
            }
            $window = $plans === [] ? null : $this->plannedWindow((string) ($plans[$id] ?? ''));
            if (isset($window['reason_code'])) {
                return $window;
            }
            $member = $this->member($experiment, $window);
            if (isset($member['reason_code'])) {
                return $member;
            }
            $equal = array_intersect_key($member, array_flip(['baseline_parameter_hash', 'legal_mutation_space_hash', 'caps', 'target']));
            if ($common !== null && $this->hash($equal) !== $this->hash($common)) {
                return $this->blocked('SELECTOR_PANEL_EQUAL_BASELINE_LEGAL_SPACE_AND_CAPS_REQUIRED');
            }
            $common = $equal;
            $members[(string) $id] = $member;
        }
        foreach (['question_key', 'physical_question_hash', 'seed_hash', 'experiment_key'] as $identity) {
            if (count(array_unique(array_column($members, $identity))) !== count($ids)) {
                return $this->blocked('SELECTOR_PANEL_REPEATED_PHYSICAL_QUESTION_OR_SEED');
            }
        }
        $originalAgents = [];
        foreach ($members as $member) {
            $originalAgents = [...$originalAgents, ...array_column($member['arms'], 'agent_id')];
        }
        if (count(array_unique($originalAgents)) !== count($ids) * 3) {
            return $this->blocked('SELECTOR_PANEL_DISTINCT_ORIGINAL_ARM_ROSTER_REQUIRED');
        }
        if (count($ids) < max(array_column(array_column($members, 'selector_receipt'), 'minimum_distinct_questions'))) {
            return $this->blocked('SELECTOR_PANEL_ORIGINAL_MINIMUM_QUESTION_COUNT_NOT_MET');
        }
        $metric = $this->metricPath($common['target']);
        if ($metric === null) {
            return $this->blocked('SELECTOR_PANEL_EXPLICIT_SUPPORTED_UTILITY_REQUIRED');
        }
        $physicalQuestions = array_column($members, 'physical_question_hash');
        sort($physicalQuestions);
        $identity = ['protocol' => self::PROTOCOL, 'anchor_experiment_id' => (int) $anchor->id,
            'experiment_ids' => $ids, 'seed_hash' => hash('sha256', $seed), 'members' => $members, 'rules' => $rules,
            'physical_panel_key' => $this->hash(['questions' => $physicalQuestions, 'caps' => $common['caps']]),
            'data_binding_required' => $plans !== [],
            'utility_metric' => $common['target'], 'utility_path' => $metric, 'caps' => $common['caps'],
            'stopping_rule' => 'all_original_questions_and_folds_complete_then_fixed_paired_mean',
            'selection_policy' => 'all_preregistered_questions_no_outcome_ranking_or_prefix_resampling'];
        $key = $this->hash($identity);

        return DB::transaction(function () use ($key, $identity, $anchor, $ids): array {
            $old = DB::table('research_compounding_benchmarks')->where('benchmark_key', $key)->lockForUpdate()->first();
            if ($old) {
                $contract = $this->readContract($old);

                return $contract === null ? $this->blocked('SELECTOR_PANEL_ORIGINAL_SEAL_INVALID') : $this->registration($key, $contract);
            }
            foreach ($ids as $id) {
                $experiment = AgentLearningCausalExperiment::whereKey($id)->lockForUpdate()->first();
                if (! $experiment || $this->observed($experiment)) {
                    return $this->blocked('SELECTOR_PANEL_MEMBERSHIP_MUST_PRECEDE_ORIGINAL_EXECUTION');
                }
            }
            if ($identity['data_binding_required']) {
                foreach ($identity['members'] as $member) {
                    foreach ($member['arms'] as $arm) {
                        if (DB::table('research_compounding_benchmarks')->where('benchmark_key', $this->purposeKey($arm['agent_id']))->exists()) {
                            return $this->blocked('SELECTOR_PANEL_ORIGINAL_ARM_PURPOSE_ALREADY_RESERVED');
                        }
                    }
                }
            }
            // Relabelled seeds/rules/roots cannot buy a second comparison on the same physical questions.
            $labId = $anchor->generation?->ai_laboratory_id;
            if ($labId) {
                DB::table('ai_laboratories')->where('id', $labId)->lockForUpdate()->first();
            }
            if (DB::table('research_compounding_benchmarks')
                ->where('sealed_contract->body->physical_panel_key', $identity['physical_panel_key'])->exists()) {
                return $this->blocked('SELECTOR_PANEL_PHYSICAL_QUESTION_BUDGET_ALREADY_SEALED');
            }
            $body = [...$identity, 'preregistered_at' => now()->utc()->toIso8601String()];
            $envelope = ['body' => $body, 'body_hash' => $this->hash($body), 'seal' => $this->seal($body)];
            DB::table('research_compounding_benchmarks')->insert(['benchmark_key' => $key, 'symbol' => $anchor->symbol,
                'timeframe' => $anchor->timeframe, 'status' => 'selector_panel_preregistered',
                'sealed_contract' => $this->encode($envelope), 'created_at' => now(), 'updated_at' => now()]);
            if ($body['data_binding_required']) {
                foreach ($body['members'] as $member) {
                    foreach ($member['arms'] as $arm) {
                        $purpose = ['protocol' => 'scoped_selector_original_purpose_v1', 'purpose' => 'independent_scoped_selector_research',
                            'panel_key' => $key, 'agent_id' => $arm['agent_id'], 'model_version_id' => $arm['model_version_id'],
                            'preregistered_at' => $body['preregistered_at']];
                        DB::table('research_compounding_benchmarks')->insert(['benchmark_key' => $this->purposeKey($arm['agent_id']),
                            'symbol' => $anchor->symbol, 'timeframe' => $anchor->timeframe, 'status' => 'scoped_selector_purpose_reserved',
                            'sealed_contract' => $this->encode(['body' => $purpose, 'body_hash' => $this->hash($purpose), 'seal' => $this->seal($purpose)]),
                            'created_at' => now(), 'updated_at' => now()]);
                    }
                }
            }

            return $this->registration($key, $body);
        });
    }

    /** Seal known physical UTC questions while the future provider SHA is still unknown. */
    public function preregisterFuturePanel(AgentLearningCausalExperiment $anchor, array $experimentIds,
        array $authorizationIdsByExperiment, string $seed, array $rules = []): array
    {
        return $this->preregister($anchor, $experimentIds, $seed, [...$rules, 'planned_authorization_ids' => $authorizationIdsByExperiment]);
    }

    /** Append actual provider bytes from server-owned original benchmarks before any execution. */
    public function bindOriginalData(string $panelKey): array
    {
        return DB::transaction(function () use ($panelKey): array {
            $row = DB::table('research_compounding_benchmarks')->where('benchmark_key', $panelKey)->lockForUpdate()->first();
            $contract = $row ? $this->readContract($row) : null;
            if ($contract === null || ($contract['data_binding_required'] ?? false) !== true) {
                return $this->blocked('SELECTOR_PANEL_PROSPECTIVE_DATA_BINDING_REQUIRED');
            }
            $certificate = ScopedResearchCertificate::where('scope', 'selector')->where('record_type', 'preregistration')
                ->where('source_id', $contract['anchor_experiment_id'])->get()->first(fn ($candidate) => data_get($candidate->payload, 'design.subject.selector_panel_key') === $panelKey
                    && data_get($candidate->payload, 'design.authority_policy') === ScopedResearchCertificateService::AUTHORITY_POLICY);
            if (! $certificate) {
                return $this->blocked('SELECTOR_PANEL_SEALED_SCOPE_BINDING_REQUIRED');
            }
            $key = $this->hash([self::PROTOCOL, 'original_data_binding', $panelKey]);
            $old = DB::table('research_compounding_benchmarks')->where('benchmark_key', $key)->first();
            if ($old) {
                if ($this->readBinding($old, $contract, $panelKey) === null) {
                    return $this->blocked('SELECTOR_PANEL_ORIGINAL_DATA_BINDING_DRIFT');
                }
                foreach ($contract['experiment_ids'] as $id) {
                    $experiment = AgentLearningCausalExperiment::find($id);
                    $current = $experiment ? $this->prospectiveExecutionScope($experiment) : $this->blocked('SELECTOR_PANEL_ORIGINAL_EXPERIMENT_REQUIRED');
                    if (($current['executable'] ?? false) !== true) {
                        return $current;
                    }
                }

                return ['protocol' => self::PROTOCOL, 'status' => 'selector_panel_data_bound', 'valid' => true, 'panel_key' => $panelKey];
            }
            $members = [];
            foreach ($contract['members'] as $id => $sealed) {
                $experiment = AgentLearningCausalExperiment::whereKey($id)->lockForUpdate()->first();
                if (! $experiment || $this->observed($experiment)) {
                    return $this->blocked('SELECTOR_PANEL_BINDING_MUST_PRECEDE_ANY_ORIGINAL_EXECUTION');
                }
                $pure = $this->member($experiment, $sealed['planned_window']);
                if (isset($pure['reason_code']) || $this->hash($pure) !== $this->hash($sealed)) {
                    return $this->blocked('SELECTOR_PANEL_ORIGINAL_MEMBER_DRIFT');
                }
                $actual = $this->member($experiment);
                if (isset($actual['reason_code'])) {
                    return $actual;
                }
                $intrinsicArms = $sealed['arms'];
                foreach ($intrinsicArms as &$arm) {
                    unset($arm['prospective_recipe_hash']);
                }
                unset($arm);
                if ($this->hash($actual['arms']) !== $this->hash($intrinsicArms)) {
                    return $this->blocked('SELECTOR_PANEL_ORIGINAL_BINDING_CONTRACT_DRIFT');
                }
                foreach (['caps', 'selector_receipt', 'target', 'baseline_parameter_hash', 'legal_mutation_space_hash', 'execution_hash'] as $field) {
                    if ($this->hash([$actual[$field]]) !== $this->hash([$sealed[$field]])) {
                        return $this->blocked('SELECTOR_PANEL_ORIGINAL_BINDING_CONTRACT_DRIFT');
                    }
                }
                $records = array_values(array_filter((array) config('services.instrument_policy.authorized_research_windows', []),
                    fn ($record) => is_array($record) && ($record['authorization_id'] ?? null) === $sealed['planned_window']['authorization_id']));
                if (count($records) !== 1) {
                    return $this->blocked('SELECTOR_PANEL_PLANNED_AUTHORIZATION_CHANGED');
                }
                $record = $records[0];
                foreach (['research_epoch_id', 'start_inclusive', 'end_exclusive', 'purpose'] as $field) {
                    $value = in_array($field, ['start_inclusive', 'end_exclusive'], true)
                        ? CarbonImmutable::parse($record[$field])->utc()->toIso8601String() : ($record[$field] ?? null);
                    if ($value !== $sealed['planned_window'][$field]) {
                        return $this->blocked('SELECTOR_PANEL_PLANNED_AUTHORIZATION_CHANGED');
                    }
                }
                $window = app(InstrumentResearchWindowService::class)->seal($record['authorization_id'], $actual['dataset_hash']);
                $manifest = (array) ($record['mtf_bundle_manifest'] ?? []);
                if (! $window || ($manifest['bundle_hash'] ?? null) !== $actual['dataset_hash']) {
                    return $this->blocked('SELECTOR_PANEL_ACTUAL_AUTHORIZED_FOUR_STREAM_DATA_REQUIRED');
                }
                try {
                    $manifest = app(InstrumentResearchWindowService::class)->canonicalScopedManifest($window, $manifest);
                } catch (\RuntimeException $error) {
                    return $this->blocked($error->getMessage());
                }
                $proof = app(ResearchWindowExposureInventoryService::class)->assessForCertificate((int) $certificate->id, $window, $manifest, []);
                if (($proof['ready'] ?? false) !== true) {
                    return $this->blocked($proof['reason_code'] ?? 'SELECTOR_PANEL_ORIGINAL_UNUSED_DATA_REQUIRED');
                }
                $members[$id] = ['member' => $actual, 'window' => $window, 'manifest' => $manifest,
                    'manifest_hash' => $this->hash($manifest), 'original_readiness_hash' => $proof['readiness_hash']];
            }
            $body = ['protocol' => 'scoped_selector_original_data_binding_v1', 'panel_key' => $panelKey,
                'contract_hash' => $this->hash($contract), 'certificate_id' => (int) $certificate->id, 'members' => $members,
                'bound_at' => now()->utc()->toIso8601String()];
            DB::table('research_compounding_benchmarks')->insert(['benchmark_key' => $key, 'symbol' => $row->symbol,
                'timeframe' => $row->timeframe, 'status' => 'selector_panel_data_bound',
                'sealed_contract' => $this->encode(['body' => $body, 'body_hash' => $this->hash($body), 'seal' => $this->seal($body)]),
                'created_at' => now(), 'updated_at' => now()]);

            return ['protocol' => self::PROTOCOL, 'status' => 'selector_panel_data_bound', 'valid' => true, 'panel_key' => $panelKey];
        });
    }

    /** Existing dispatch checks this before leasing a fold, so dependency waits do not spend attempts. */
    public function executionReadiness(AgentLearningCausalExperiment $experiment): array
    {
        if (! Schema::hasTable('research_compounding_benchmarks')) {
            return ['status' => 'ready', 'ready' => true];
        }
        $declared = false;
        foreach (DB::table('research_compounding_benchmarks')->where('status', 'like', 'selector_panel_%')->orderBy('id')->limit(100)->get() as $row) {
            $contract = $this->readContract($row);
            if ($contract === null || ! in_array((int) $experiment->id, $contract['experiment_ids'], true)
                || ($contract['data_binding_required'] ?? false) !== true) {
                continue;
            }
            $declared = true;
            $binding = DB::table('research_compounding_benchmarks')->where('benchmark_key', $this->hash([self::PROTOCOL, 'original_data_binding', $row->benchmark_key]))->first();
            if (! $binding || $this->readBinding($binding, $contract, $row->benchmark_key) === null) {
                return $this->blocked('SELECTOR_PANEL_ORIGINAL_DATA_BINDING_REQUIRED');
            }
            $scope = $this->prospectiveExecutionScope($experiment);
            if (($scope['executable'] ?? false) !== true) {
                return $scope;
            }
        }
        if (! $declared && $this->reservedExperiment($experiment)) {
            return $this->blocked('SELECTOR_PANEL_ORIGINAL_SEAL_INVALID');
        }

        return ['protocol' => self::PROTOCOL, 'status' => 'ready', 'ready' => true];
    }

    /** Prepare concrete original bytes for the existing compiler; dispatch still requires the later signed binding. */
    public function prospectiveExecutionScope(AgentLearningCausalExperiment $experiment): array
    {
        if (! Schema::hasTable('research_compounding_benchmarks')) {
            return ['status' => 'not_declared'];
        }
        $matches = [];
        foreach (DB::table('research_compounding_benchmarks')->where('status', 'like', 'selector_panel_%')->orderBy('id')->limit(100)->get() as $row) {
            $contract = $this->readContract($row);
            if ($contract !== null && ($contract['data_binding_required'] ?? false) === true
                && isset($contract['members'][(string) $experiment->id])) {
                $matches[] = [$row, $contract];
            }
        }
        if ($matches === []) {
            return $this->reservedExperiment($experiment)
                ? $this->blocked('SELECTOR_PANEL_ORIGINAL_SEAL_INVALID') : ['status' => 'not_declared'];
        }
        if (count($matches) !== 1) {
            return $this->blocked('SELECTOR_PANEL_ORIGINAL_EXECUTION_SCOPE_AMBIGUOUS');
        }
        [$row, $contract] = $matches[0];
        $member = $contract['members'][(string) $experiment->id];
        $current = $this->member($experiment, $member['planned_window']);
        if (isset($current['reason_code']) || $this->hash($current) !== $this->hash($member)) {
            return $this->blocked('SELECTOR_PANEL_ORIGINAL_MEMBER_DRIFT');
        }
        $records = array_values(array_filter((array) config('services.instrument_policy.authorized_research_windows', []),
            fn ($record) => is_array($record) && ($record['authorization_id'] ?? null) === $member['planned_window']['authorization_id']));
        if (count($records) !== 1) {
            return $this->blocked('SELECTOR_PANEL_PLANNED_AUTHORIZATION_CHANGED');
        }
        $record = $records[0];
        foreach (['start_inclusive', 'end_exclusive'] as $field) {
            if (CarbonImmutable::parse($record[$field])->utc()->toIso8601String() !== $member['planned_window'][$field]) {
                return $this->blocked('SELECTOR_PANEL_PLANNED_AUTHORIZATION_CHANGED');
            }
        }
        $window = app(InstrumentResearchWindowService::class)->seal($record['authorization_id'], (string) ($record['dataset_sha256'] ?? ''));
        $manifest = (array) ($record['mtf_bundle_manifest'] ?? []);
        if (! $window || ($manifest['bundle_hash'] ?? null) !== $window['dataset_sha256']) {
            return $this->blocked('SELECTOR_PANEL_ACTUAL_AUTHORIZED_FOUR_STREAM_DATA_REQUIRED');
        }
        try {
            $manifest = app(InstrumentResearchWindowService::class)->canonicalScopedManifest($window, $manifest);
        } catch (\RuntimeException $error) {
            return $this->blocked($error->getMessage());
        }
        // Native 200-row warmup plus one actual decision per frozen stratum; never spend a lease on an impossible source.
        $input = app(InstrumentResearchWindowService::class)->verifySealedReplayWindow($window, $manifest);
        $semantics = $member['fold_semantics'];
        $minimumRows = (int) $member['caps']['fold_count'] * (201 + max((int) $semantics['maximum_holding_bars'],
            (int) $semantics['purge_bars']) + max(1, (int) $semantics['embargo_bars']));
        $actualRows = data_get($input, 'files.M5.rows');
        if (! is_int($actualRows) || $actualRows < $minimumRows) {
            return [...$this->blocked('SELECTOR_PANEL_ORIGINAL_SOURCE_ROWS_INSUFFICIENT_FOR_SEALED_FOLDS'),
                'actual_source_rows' => $actualRows, 'minimum_source_rows' => $minimumRows,
                'sealed_fold_universe_count' => $member['caps']['fold_count']];
        }
        $certificate = ScopedResearchCertificate::where('scope', 'selector')->where('record_type', 'preregistration')->where('source_id', $contract['anchor_experiment_id'])
            ->get()->first(fn ($candidate) => data_get($candidate->payload, 'design.subject.selector_panel_key') === $row->benchmark_key);
        if (! $certificate) {
            return $this->blocked('SELECTOR_PANEL_SEALED_SCOPE_BINDING_REQUIRED');
        }
        $bindingRow = DB::table('research_compounding_benchmarks')->where('benchmark_key',
            $this->hash([self::PROTOCOL, 'original_data_binding', $row->benchmark_key]))->first();
        $binding = $bindingRow ? $this->readBinding($bindingRow, $contract, $row->benchmark_key) : null;
        if ($bindingRow && $binding === null) {
            return $this->blocked('SELECTOR_PANEL_ORIGINAL_DATA_BINDING_DRIFT');
        }
        if ($binding !== null) {
            $bound = $binding['members'][(string) $experiment->id];
            if ($this->hash($window) !== $this->hash($bound['window']) || $this->hash($manifest) !== $bound['manifest_hash']) {
                return $this->blocked('SELECTOR_PANEL_ORIGINAL_DATA_BINDING_DRIFT');
            }
            try {
                app(InstrumentResearchWindowService::class)->verifySealedReplayWindow($window, $manifest);
            } catch (\RuntimeException $error) {
                return $this->blocked('SELECTOR_PANEL_ACTUAL_AUTHORIZED_FOUR_STREAM_DATA_REQUIRED');
            }
        } else {
            if ($this->observed($experiment)) {
                return $this->blocked('SELECTOR_PANEL_BINDING_MUST_PRECEDE_ANY_ORIGINAL_EXECUTION');
            }
            $proof = app(ResearchWindowExposureInventoryService::class)->assessForCertificate((int) $certificate->id, $window, $manifest, []);
            if (($proof['ready'] ?? false) !== true) {
                return $this->blocked($proof['reason_code'] ?? 'SELECTOR_PANEL_ORIGINAL_UNUSED_DATA_REQUIRED');
            }
        }

        return ['protocol' => self::PROTOCOL, 'status' => 'prospective_original_scope_prepared', 'panel_key' => $row->benchmark_key,
            'window' => $window, 'manifest' => $manifest, 'caps' => $member['caps'], 'member' => $member,
            'certificate_id' => (int) $certificate->id, 'exposure_policy' => (array) data_get($certificate->payload, 'design.exposure_policy', []),
            'risk_guard' => $contract['rules']['risk_guard'], 'executable' => $binding !== null, 'promotion_evidence' => false];
    }

    /** Current originals are rechecked even after a durable settlement exists. */
    public function inspectPanel(string $panelKey): array
    {
        $row = DB::table('research_compounding_benchmarks')->where('benchmark_key', $panelKey)->first();
        $contract = $row ? $this->readContract($row) : null;
        if ($contract === null) {
            return $this->blocked('SELECTOR_PANEL_ORIGINAL_SEAL_INVALID');
        }
        $binding = null;
        if (($contract['data_binding_required'] ?? false) === true) {
            $bound = DB::table('research_compounding_benchmarks')->where('benchmark_key', $this->hash([self::PROTOCOL, 'original_data_binding', $panelKey]))->first();
            $binding = $bound ? $this->readBinding($bound, $contract, $panelKey) : null;
            if ($binding === null) {
                return $this->blocked('SELECTOR_PANEL_ORIGINAL_DATA_BINDING_REQUIRED');
            }
        }
        $products = [];
        $questions = [];
        $effort = ['memory_enabled' => 0.0, 'memory_blinded' => 0.0];
        foreach ($contract['members'] as $id => $sealed) {
            $experiment = AgentLearningCausalExperiment::find($id);
            if (! $experiment) {
                return $this->blocked('SELECTOR_PANEL_ORIGINAL_EXPERIMENT_REQUIRED');
            }
            $current = $this->member($experiment, $sealed['planned_window'] ?? null);
            if (isset($current['reason_code'])) {
                return $current;
            }
            if ($this->hash($current) !== $this->hash($sealed)) {
                return $this->blocked('SELECTOR_PANEL_ORIGINAL_MEMBER_DRIFT');
            }
            if ($binding !== null) {
                $actual = $this->member($experiment);
                $boundMember = $binding['members'][$id]['member'];
                if (isset($actual['reason_code']) || $this->hash($actual) !== $this->hash($boundMember)) {
                    return $this->blocked('SELECTOR_PANEL_ORIGINAL_DATA_BINDING_DRIFT');
                }
                $sealed = [...$boundMember, 'bound_window' => $binding['members'][$id]['window'],
                    'fold_semantics' => $sealed['fold_semantics'] ?? []];
            }
            $proof = $this->originalQuestion($experiment, $sealed, $contract);
            if (isset($proof['reason_code'])) {
                return $proof;
            }
            $products[] = $proof['product'];
            $questions[] = $proof['question'];
            foreach ($effort as $role => $value) {
                $effort[$role] += $proof['product']['effort'][$role]['primary_seconds'];
            }
        }
        $powered = array_values(array_filter($questions, fn ($question) => $question['powered']));
        $mean = count($powered) === count($questions) ? array_sum(array_column($powered, 'guided_minus_blinded')) / count($powered) : null;
        $positive = count(array_filter($powered, fn ($question) => $question['guided_minus_blinded'] > $contract['rules']['minimum_effect']));
        $negative = count(array_filter($powered, fn ($question) => $question['guided_minus_blinded'] < -$contract['rules']['minimum_effect']));
        $verdict = 'inconclusive';
        $safe = collect($questions)->every(fn ($question) => $question['safe']);
        $nonTargetProven = collect($questions)->every(fn ($question) => $question['non_target_evidence_complete']);
        if (! $safe) {
            $verdict = 'negative';
        } elseif ($mean !== null && $positive >= $contract['rules']['minimum_positive_questions'] && $negative === 0
            && $mean > $contract['rules']['minimum_effect']) {
            $verdict = 'positive';
        } elseif ($mean !== null && $negative >= $contract['rules']['minimum_positive_questions']
        && $mean < -$contract['rules']['minimum_effect']) {
            $verdict = 'negative';
        }

        return ['protocol' => self::PROTOCOL, 'status' => 'original_selector_panel_observed', 'panel_key' => $panelKey,
            'panel_contract_hash' => $this->hash($contract), 'valid' => true, 'terminal' => true, 'verdict' => $verdict,
            'checks' => ['prospective_membership' => true, 'multi_question_panel' => true, 'distinct_question_seeds' => true,
                'equal_baseline_and_legal_space' => true, 'equal_preregistered_compute_caps' => true,
                'original_hash_valid_products' => true, 'actual_primary_compute_measured' => true,
                'all_primary_compute_within_caps' => true, 'all_questions_safe' => $safe && $nonTargetProven,
                'all_questions_non_target_metrics_proven' => $nonTargetProven,
                'all_questions_powered' => count($powered) === count($questions)],
            'reason_codes' => $verdict === 'positive' ? [] : [$verdict === 'negative' ? 'SELECTOR_PANEL_MEASURED_NEGATIVE' : 'SELECTOR_PANEL_MEASURED_INCONCLUSIVE'],
            'question_count' => count($questions), 'powered_question_count' => count($powered), 'positive_question_count' => $positive,
            'paired_mean_utility_difference' => $mean, 'questions' => $questions, 'original_products' => $products,
            'resource_metric' => $contract['rules']['resource_metric'], 'measured_primary_effort' => $effort,
            'resource_scope' => 'selector_and_economic_replay_excludes_shared_preparation_features_audit_and_scoring',
            'utility_per_primary_second' => ['memory_enabled' => $mean !== null && $effort['memory_enabled'] > 0
                ? array_sum(array_column($questions, 'guided_control_utility')) / $effort['memory_enabled'] : null,
                'memory_blinded' => $mean !== null && $effort['memory_blinded'] > 0
                ? array_sum(array_column($questions, 'blinded_control_utility')) / $effort['memory_blinded'] : null],
            'selector_cpu_seconds' => null, 'scope_authority_confirmed' => false, 'promotion_evidence' => false,
            'component_credit' => false, 'parent_eligible' => false, 'paper_authority' => false];
    }

    /** Certificate issuer invokes the named original producer with only the sealed certificate ID. */
    public function assessOriginalPanel(int $certificateId): array
    {
        try {
            $certificate = app(ScopedResearchCertificateService::class)->verifiedRegistration($certificateId);
        } catch (\Throwable) {
            return $this->blocked('SELECTOR_PANEL_SEALED_SCOPE_BINDING_REQUIRED');
        }
        $subject = (array) data_get($certificate, 'design.subject', []);
        if (($certificate['scope'] ?? null) !== 'selector'
            || ! is_string($subject['selector_panel_key'] ?? null)) {
            return $this->blocked('SELECTOR_PANEL_SEALED_SCOPE_BINDING_REQUIRED');
        }
        $panel = $this->inspectPanel($subject['selector_panel_key']);
        if (($panel['valid'] ?? false) !== true) {
            return $panel;
        }
        $row = DB::table('research_compounding_benchmarks')->where('benchmark_key', $subject['selector_panel_key'])->first();
        $contract = $this->readContract($row);
        $ids = array_map('intval', (array) ($subject['experiment_ids'] ?? []));
        sort($ids);
        if ($ids !== $contract['experiment_ids'] || (int) $certificate['source_id'] !== $contract['anchor_experiment_id']
            || data_get($certificate, 'design.metric') !== $contract['utility_metric']
            || data_get($certificate, 'design.stopping_rule') !== $contract['stopping_rule']) {
            return $this->blocked('SELECTOR_PANEL_CERTIFICATE_MEMBERSHIP_METRIC_OR_STOPPING_DRIFT');
        }
        foreach ($panel['original_products'] as $product) {
            if ($product['first_started_at'] <= $certificate['preregistered_at']) {
                return $this->blocked('SELECTOR_PANEL_CERTIFICATE_MUST_PRECEDE_ORIGINAL_PRODUCTS');
            }
        }

        return [...$panel, 'certificate_id' => $certificateId];
    }

    /** Append one result into the existing benchmark owner; issuance remains the registry's boundary. */
    public function settlePanel(string $panelKey, int $certificateId): array
    {
        $result = $this->assessOriginalPanel($certificateId);
        if (($result['valid'] ?? false) !== true) {
            return $result;
        }
        if (($result['panel_key'] ?? null) !== $panelKey) {
            return $this->blocked('SELECTOR_PANEL_SETTLEMENT_OWNER_MISMATCH');
        }

        return DB::transaction(function () use ($panelKey, $result): array {
            $row = DB::table('research_compounding_benchmarks')->where('benchmark_key', $panelKey)->lockForUpdate()->first();
            $old = $row->assessment === null ? null : json_decode($row->assessment, true);
            if ($old !== null && $this->hash($old) !== $this->hash($result)) {
                return $this->blocked('SELECTOR_PANEL_TERMINAL_ASSESSMENT_IMMUTABLE');
            }
            if ($old === null) {
                DB::table('research_compounding_benchmarks')->where('id', $row->id)->update([
                    'status' => 'selector_panel_'.$result['verdict'], 'assessment' => $this->encode($result), 'updated_at' => now()]);
            }

            return $result;
        });
    }

    /** Existing original-fold settlement may close only its already sealed panel. */
    public function reconcileForExperiment(AgentLearningCausalExperiment $experiment): array
    {
        $results = [];
        foreach (DB::table('research_compounding_benchmarks')->whereIn('status', ['selector_panel_preregistered',
            'selector_panel_positive', 'selector_panel_negative', 'selector_panel_inconclusive'])
            ->orderBy('id')->limit(100)->get() as $row) {
            $contract = $this->readContract($row);
            if ($contract === null || ! in_array((int) $experiment->id, $contract['experiment_ids'], true)) {
                continue;
            }
            $certificates = ScopedResearchCertificate::where('scope', 'selector')->where('record_type', 'preregistration')
                ->where('source_type', AgentLearningCausalExperiment::class)->where('source_id', $contract['anchor_experiment_id'])->get();
            $certificate = $certificates->first(fn ($certificate) => data_get($certificate->payload,
                'design.subject.selector_panel_key') === $row->benchmark_key
                && data_get($certificate->payload, 'design.authority_policy') === ScopedResearchCertificateService::AUTHORITY_POLICY);
            if (! $certificate) {
                $results[] = $this->blocked('SELECTOR_PANEL_SEALED_SCOPE_BINDING_REQUIRED');

                continue;
            }
            $result = $this->settlePanel($row->benchmark_key, (int) $certificate->id);
            if (($result['valid'] ?? false) === true) {
                $result['issuance'] = app(ScopedResearchCertificateService::class)->issueIndependent((int) $certificate->id,
                    ['selector_panel_key' => $row->benchmark_key]);
            }
            $results[] = $result;
        }

        return ['protocol' => self::PROTOCOL, 'status' => $results === [] ? 'no_preregistered_selector_panel' : 'selector_panels_reconciled',
            'panels' => $results, 'promotion_evidence' => false];
    }

    private function member(AgentLearningCausalExperiment $experiment, ?array $plannedWindow = null): array
    {
        $observation = $this->foundry->selectorObservationForExperiment($experiment);
        if (($observation['status'] ?? null) !== 'measured_constructor_observation') {
            return $this->blocked('SELECTOR_PANEL_ORIGINAL_BLINDED_SELECTOR_RECEIPT_REQUIRED');
        }
        $receipt = $observation['receipt'];
        $release = (array) data_get($experiment->generation?->trigger_context, 'research_release', []);
        $key = $this->hash(['causal_equal_budget_observation_v1', $experiment->id, $release['release_hash'] ?? null]);
        $row = DB::table('research_compounding_benchmarks')->where('benchmark_key', $key)->first();
        $benchmark = $row ? json_decode($row->sealed_contract, true) : null;
        if ($plannedWindow !== null) {
            $benchmark = ['arm_ids' => ['memory_enabled' => $experiment->guided_agent_id,
                'memory_blinded' => $experiment->blinded_agent_id, 'frozen_control' => $experiment->control_agent_id],
                'fold_count' => max(1, min(12, (int) config('services.learning_lane.causal_fold_count', 9))),
                'per_arm_fold_seconds_limit' => max(45, min(240, (int) config('services.learning_lane.causal_per_fold_budget_seconds', 180))),
                'max_rows_per_fold' => (int) config('services.learning_lane.causal_max_rows_per_fold', 4096),
                'execution_hash' => app(ExecutionContractService::class)->for($experiment->symbol, 'M5')['execution_hash'], 'dataset_hash' => null];
        }
        if ($plannedWindow === null && (! is_array($benchmark) || ($benchmark['protocol'] ?? null) !== 'causal_equal_budget_observation_v1'
            || ($benchmark['selector_observation'] ?? null) !== $observation
            || ($benchmark['release_hash'] ?? null) !== ($release['release_hash'] ?? null)
            || ! $this->sha($benchmark['dataset_hash'] ?? null) || ! $this->sha($benchmark['execution_hash'] ?? null))) {
            return $this->blocked('SELECTOR_PANEL_ORIGINAL_PROSPECTIVE_FOUNDRY_CONTRACT_REQUIRED');
        }
        $arms = [];
        $agentIds = [];
        foreach (['memory_enabled' => $experiment->guided_agent_id, 'memory_blinded' => $experiment->blinded_agent_id,
            'frozen_control' => $experiment->control_agent_id] as $role => $id) {
            $agent = LabAgent::with('modelVersion')->find($id);
            if (! $agent?->modelVersion || (int) $agent->lab_generation_id !== (int) $experiment->lab_generation_id
                || (int) data_get($benchmark, 'arm_ids.'.$role) !== (int) $id) {
                return $this->blocked('SELECTOR_PANEL_ORIGINAL_ARM_OWNER_REQUIRED');
            }
            $arms[$role] = ['agent_id' => (int) $id, 'model_version_id' => (int) $agent->model_version_id,
                'parameter_hash' => $this->hash((array) $agent->modelVersion->parameters),
                'runtime_basis_hash' => $this->hash($this->immutable->modelRuntimeBasis($agent->modelVersion)),
                'evidence_parameter_hash' => $this->immutable->parameterHash($agent)];
            $agentIds[] = (int) $id;
            if ($plannedWindow !== null) {
                try {
                    $arms[$role]['prospective_recipe_hash'] = $this->hash(app(CompositionAuthorityKernelService::class)
                        ->prospectiveRecipeFromMetadata($agent->modelVersion));
                } catch (\LogicException|\RuntimeException $error) {
                    return $this->blocked('SELECTOR_PANEL_ORIGINAL_PROSPECTIVE_RECIPE_INVALID');
                }
            }
        }
        if (count(array_unique($agentIds)) !== 3 || ! is_string($receipt['seed'] ?? null) || $receipt['seed'] === '') {
            return $this->blocked('SELECTOR_PANEL_ORIGINAL_ARM_AND_SEED_REQUIRED');
        }
        $context = (array) data_get($experiment->evidence, 'source_context_scope', []);
        if ($context === []) {
            return $this->blocked('SELECTOR_PANEL_ORIGINAL_QUESTION_CONTEXT_REQUIRED');
        }
        $caps = ['selector_seconds' => (float) $receipt['per_selector_admission_seconds_limit'],
            'fold_count' => (int) $benchmark['fold_count'], 'per_fold_seconds' => (int) $benchmark['per_arm_fold_seconds_limit'],
            'max_rows_per_fold' => (int) $benchmark['max_rows_per_fold'],
            'selector_cpu_seconds' => $receipt['per_selector_cpu_seconds_limit'] ?? null];
        if ($caps['fold_count'] < 1 || $caps['per_fold_seconds'] < 1 || $caps['max_rows_per_fold'] < 1) {
            return $this->blocked('SELECTOR_PANEL_EQUAL_BOUNDED_COMPUTE_CONTRACT_REQUIRED');
        }
        if ($plannedWindow !== null && ($caps['max_rows_per_fold'] < 512 || $caps['max_rows_per_fold'] > 8192)) {
            return $this->blocked('SELECTOR_PANEL_NATIVE_CANONICAL_ROW_CAP_REQUIRED');
        }
        $physical = ['context' => $context, 'target' => $experiment->target, 'dataset_hash' => $benchmark['dataset_hash'], 'planned_window' => $plannedWindow,
            'execution_hash' => $benchmark['execution_hash'], 'baseline' => $receipt['baseline_parameter_hash'],
            'legal_mutation_space' => $receipt['legal_mutation_space_hash']];

        return ['experiment_key' => $experiment->experiment_key, 'question_key' => $receipt['question_key'],
            'physical_question_hash' => $this->hash($physical), 'seed_hash' => hash('sha256', $receipt['seed']),
            'target' => $experiment->target, 'baseline_parameter_hash' => $receipt['baseline_parameter_hash'],
            'legal_mutation_space_hash' => $receipt['legal_mutation_space_hash'], 'caps' => $caps,
            'arms' => $arms, 'selector_receipt' => $receipt, 'benchmark_key' => $plannedWindow === null ? $key : null,
            'benchmark' => $plannedWindow === null ? $benchmark : null, 'planned_window' => $plannedWindow,
            'release_hash' => $plannedWindow === null ? $release['release_hash'] : null, 'dataset_hash' => $benchmark['dataset_hash'],
            'execution_hash' => $benchmark['execution_hash'], ...($plannedWindow === null ? [] : ['fold_semantics' => [
                'maximum_holding_bars' => max(1, (int) config('services.learning_lane.confirmation_maximum_holding_bars', 240)),
                'purge_bars' => max(1, (int) config('services.learning_lane.confirmation_maximum_holding_bars', 240)),
                'embargo_bars' => 1, 'audit_trace_rows' => (int) config('services.learning_lane.causal_audit_trace_rows', 512),
                'minimum_trades_per_window' => (int) config('services.learning_lane.causal_minimum_trades_per_window', 8),
                'minimum_powered_windows' => (int) config('services.learning_lane.causal_minimum_powered_windows', 6),
                'minimum_positive_windows' => (int) config('services.learning_lane.causal_minimum_positive_windows', 4),
                'execution_overlay' => 'unchanged_original_parameters_scoped_maturity_entry_fence',
            ]])];
    }

    private function originalQuestion(AgentLearningCausalExperiment $experiment, array $member, array $contract): array
    {
        $folds = CausalFoldReceipt::where('agent_learning_causal_experiment_id', $experiment->id)->orderBy('fold_index')->get();
        if ($folds->count() !== $member['caps']['fold_count'] || $folds->where('status', 'completed')->count() !== $folds->count()
            || $folds->pluck('fold_index')->map(fn ($index) => (int) $index)->all() !== range(1, $folds->count())) {
            return $this->blocked('SELECTOR_PANEL_COMPLETE_ORIGINAL_FOLD_SET_REQUIRED');
        }
        $resources = [];
        $utilities = [];
        $powered = true;
        $safe = true;
        $nonTargetProven = true;
        $safety = [];
        $first = null;
        foreach ($folds as $fold) {
            if ((int) $fold->attempt_count !== 1) {
                return $this->blocked('SELECTOR_PANEL_ALL_ORIGINAL_ATTEMPT_EFFORT_REQUIRED');
            }
            if (! $fold->started_at || $fold->started_at->toIso8601String() <= $contract['preregistered_at']
                || ! $fold->completed_at || $fold->dataset_hash !== $member['dataset_hash'] || $fold->execution_hash !== $member['execution_hash']
                || $fold->request_hash !== $this->hash((array) $fold->request_payload)
                || $fold->response_hash !== $this->hash((array) $fold->response_payload)
                || data_get($fold->request_payload, 'research_release.release_hash') !== $member['release_hash']
                || $this->hash((array) data_get($fold->request_payload, 'strategies', [])) !== $member['benchmark']['arm_program_hash']) {
                return $this->blocked('SELECTOR_PANEL_ORIGINAL_FOLD_IDENTITY_INVALID');
            }
            foreach ($member['arms'] as $arm) {
                $cap = (array) data_get($fold->request_payload, 'policy_context.learning_confirmation_contracts.'.$arm['agent_id'], []);
                if ((int) ($cap['fold_universe_count'] ?? 0) !== $member['caps']['fold_count']
                    || (int) ($cap['per_fold_budget_seconds'] ?? 0) !== $member['caps']['per_fold_seconds']
                    || (int) ($cap['max_rows_per_fold'] ?? 0) !== $member['caps']['max_rows_per_fold']) {
                    return $this->blocked('SELECTOR_PANEL_ORIGINAL_REQUEST_EQUAL_CAP_DRIFT');
                }
                foreach ((array) ($member['fold_semantics'] ?? []) as $field => $value) {
                    if (($cap[$field] ?? null) !== $value) {
                        return $this->blocked('SELECTOR_PANEL_ORIGINAL_REQUEST_EQUAL_CAP_DRIFT');
                    }
                }
            }
            $first ??= $fold->started_at->toIso8601String();
            $items = collect((array) data_get($fold->response_payload, 'leaderboard', []))->keyBy('lab_agent_id');
            if ($items->count() !== 3 || $items->keys()->map(fn ($id) => (int) $id)->sort()->values()->all()
                !== collect($member['arms'])->pluck('agent_id')->sort()->values()->all()) {
                return $this->blocked('SELECTOR_PANEL_ORIGINAL_FOLD_ARM_IDENTITY_INVALID');
            }
            $calendar = null;
            $foldResults = [];
            $foldPowered = true;
            foreach ($member['arms'] as $role => $arm) {
                $result = (array) data_get($items->get($arm['agent_id']), 'result', []);
                if ((int) data_get($items->get($arm['agent_id']), 'rolling_windows_count') !== 1
                    || data_get($result, 'learning_confirmation.execution_mode') !== 'durable_single_fold_job'
                    || data_get($result, 'learning_confirmation.status') !== 'completed'
                    || data_get($result, 'learning_confirmation.fold_count') !== 1
                    || data_get($result, 'learning_confirmation.fold_offset') !== (int) $fold->fold_index - 1
                    || data_get($result, 'learning_confirmation.fold_universe_count') !== $member['caps']['fold_count']) {
                    return $this->blocked('SELECTOR_PANEL_ORIGINAL_NATIVE_FOLD_SCOPE_INVALID');
                }
                $clock = $this->foldClock($result, (array) $fold->request_payload, $member);
                if (isset($clock['reason_code'])) {
                    return $clock;
                }
                if ($calendar !== null && $this->hash($calendar) !== $this->hash($clock)) {
                    return $this->blocked('SELECTOR_PANEL_ORIGINAL_ARM_CLOCK_MISMATCH');
                }
                $calendar = $clock;
                $trace = ($contract['data_binding_required'] ?? false) === true
                    ? $this->immutable->decisionTraceCompletenessForOriginalFold($fold, $arm['agent_id'])
                    : $this->immutable->decisionTraceCompleteness($result);
                if (($trace['complete'] ?? false) !== true
                    || data_get($result, 'data_quality.decision_trace.audit_slice') !== true
                    || data_get($result, 'data_quality.decision_trace.economic_score_input') !== false) {
                    return $this->blocked('SELECTOR_PANEL_ORIGINAL_AUDIT_TRACE_INCOMPLETE');
                }
                $release = (array) data_get($fold->request_payload, 'research_release', []);
                $ledger = $result['trade_ledger'] ?? null;
                if (! is_array($ledger) || count($ledger) !== ($result['total_trades'] ?? null)
                    || ! app(ResearchReleaseSealService::class)->responseValid($release,
                        (array) data_get($result, 'data_quality.research_release_receipt', []))) {
                    return $this->blocked('SELECTOR_PANEL_ORIGINAL_COMPLETE_LEDGER_AND_WORKER_REQUIRED');
                }
                $resource = (array) data_get($result, 'benchmark.arm_replay_resources', []);
                if (($resource['protocol'] ?? null) !== 'arm_replay_resources_v1'
                    || ($resource['scope'] ?? null) !== 'economic_replay_only_excludes_shared_features_and_audit'
                    || ($resource['measured_segments'] ?? null) !== 1 || ($resource['promotion_evidence'] ?? null) !== false
                    || ! $this->finite($resource['wall_seconds'] ?? null) || $resource['wall_seconds'] <= 0) {
                    return $this->blocked('SELECTOR_PANEL_PRIMARY_COMPUTE_NOT_MEASURED');
                }
                if ($resource['wall_seconds'] > $member['caps']['per_fold_seconds']) {
                    return $this->blocked('SELECTOR_PANEL_ORIGINAL_EFFORT_EXCEEDS_EQUAL_CAP');
                }
                $resources[$role][] = $resource;
                $window = $member['bound_window'] ?? app(InstrumentResearchWindowService::class)->sealForDataset($member['dataset_hash'],
                    (array) ($result['replay_manifest'] ?? []));
                // Diagnostic-only inspection can establish maturity on the actual observed economic clock;
                // the issuer still requires the separately authorized physical window/exposure proof.
                $window ??= ['start_inclusive' => $clock['signal_start'], 'end_exclusive' => CarbonImmutable::parse($clock['execution_end'])
                    ->addSeconds($clock['duration_seconds'] + 1)->toIso8601String()];
                try {
                    $guard = app(ScopedResearchAuthorityService::class)->verifyOriginalSafety($result,
                        ['design' => ['risk_guard' => $contract['rules']['risk_guard']]], $window);
                } catch (\LogicException $error) {
                    return $this->blocked($error->getMessage());
                }
                $safe = $safe && $guard['hard_risk_passed'];
                $safety[$fold->fold_index][$role] = $guard;
                $foldResults[$role] = $result;
                $utility = data_get($result, $contract['utility_path']);
                if ((int) $result['total_trades'] < $contract['rules']['minimum_trades']) {
                    $foldPowered = false;
                } elseif (! $this->finite($utility)) {
                    return $this->blocked('SELECTOR_PANEL_ORIGINAL_UTILITY_NOT_MEASURED');
                }
                $utilities[$role][] = $this->finite($utility) ? (float) $utility : null;
            }
            $powered = $powered && $foldPowered;
            foreach (['frozen_control', 'memory_blinded'] as $reference) {
                $contrast = app(CausalLearningConfirmationService::class)->compareOriginalComponent($experiment->target,
                    $foldResults['memory_enabled'], $foldResults[$reference]);
                if (data_get($contrast, 'non_target.status') === 'incomplete') {
                    if ($foldPowered) {
                        return $this->blocked('SELECTOR_PANEL_ORIGINAL_NON_TARGET_EVIDENCE_INCOMPLETE');
                    }
                    // An actual mature but underpowered history cannot prove absent statistical groups.
                    // Retain their unknown state; all measured hard-risk and regression checks still apply.
                    $nonTargetProven = false;
                    $safe = $safe && (array) data_get($contrast, 'non_target.regressed_metrics', []) === [];
                    $contrast['non_target']['authority_proven'] = false;
                    $contrast['non_target']['underpowered_original_history'] = true;
                } else {
                    $safe = $safe && data_get($contrast, 'non_target.safe') === true;
                }
                $safety[$fold->fold_index]['guided_vs_'.$reference] = $contrast['non_target'];
            }
        }
        $product = ['question_key' => $member['question_key'], 'seed' => $member['selector_receipt']['seed'],
            'experiment_id' => (int) $experiment->id, 'window_key' => $member['dataset_hash'], 'first_started_at' => $first,
            'fold_receipt_ids' => $folds->pluck('id')->all(), 'fold_request_hashes' => $folds->pluck('request_hash')->all(),
            'fold_response_hashes' => $folds->pluck('response_hash')->all(), 'effort' => []];
        foreach ($member['arms'] as $role => $arm) {
            $original = $this->originalRun($experiment, $arm, $member, $folds->pluck('response_hash')->all(), $contract['preregistered_at']);
            if (isset($original['reason_code'])) {
                return $original;
            }
            $field = match ($role) {
                'memory_enabled' => 'guided_run_id', 'memory_blinded' => 'blinded_run_id', default => 'control_run_id'
            };
            $product[$field] = $original['run_id'];
            $product['run_hashes'][$role] = $original;
            $product[str_replace('_run_id', '_run_record_id', $field)] = $original['run_record_id'];
            $selection = $role === 'frozen_control' ? 0.0 : data_get($member, 'selector_receipt.arms.'.$role.'.wall_seconds');
            if (! $this->finite($selection) || $selection < 0 || $selection > $member['caps']['selector_seconds']) {
                return $this->blocked('SELECTOR_PANEL_ORIGINAL_SELECTOR_EFFORT_INVALID');
            }
            $replayWall = array_sum(array_column($resources[$role], 'wall_seconds'));
            $cpus = array_column($resources[$role], 'cpu_seconds');
            $cpu = count($cpus) === count($resources[$role]) && collect($cpus)->every(fn ($value) => $this->finite($value) && $value >= 0) ? array_sum($cpus) : null;
            $selectorCpu = $role === 'frozen_control' ? 0.0 : data_get($member, 'selector_receipt.arms.'.$role.'.cpu_seconds');
            if ($contract['rules']['resource_metric'] === self::CPU_METRIC
                && ($cpu === null || ! $this->finite($selectorCpu) || ! $this->finite($member['caps']['selector_cpu_seconds'])
                    || $selectorCpu < 0 || $member['caps']['selector_cpu_seconds'] <= 0
                    || $selectorCpu > $member['caps']['selector_cpu_seconds'])) {
                return $this->blocked('SELECTOR_PANEL_PRIMARY_COMPUTE_NOT_MEASURED');
            }
            $product['effort'][$role] = ['selector_wall_seconds' => (float) $selection, 'replay_wall_seconds' => $replayWall,
                'selector_cpu_seconds' => $this->finite($selectorCpu) ? (float) $selectorCpu : null,
                'replay_cpu_seconds' => $cpu, 'measured_fold_count' => count($resources[$role]),
                'primary_seconds' => $contract['rules']['resource_metric'] === self::CPU_METRIC ? $selectorCpu + $cpu : $selection + $replayWall];
        }
        $means = [];
        foreach ($utilities as $role => $values) {
            $means[$role] = in_array(null, $values, true) ? null : array_sum($values) / count($values);
        }

        return ['product' => $product, 'question' => ['question_key' => $member['question_key'], 'powered' => $powered, 'safe' => $safe,
            'non_target_evidence_complete' => $nonTargetProven, 'safety' => $safety,
            'guided_minus_blinded' => $powered ? $means['memory_enabled'] - $means['memory_blinded'] : null,
            'guided_control_utility' => $powered ? $means['memory_enabled'] - $means['frozen_control'] : null,
            'blinded_control_utility' => $powered ? $means['memory_blinded'] - $means['frozen_control'] : null]];
    }

    private function originalRun(AgentLearningCausalExperiment $experiment, array $arm, array $member, array $foldHashes, string $preregisteredAt): array
    {
        $runs = LabEvaluationRun::where('lab_agent_id', $arm['agent_id'])->where('phase', 'full_validation')->where('status', 'completed')->get();
        $run = $runs->first(fn ($run) => data_get($run->metadata, 'source') === 'causal_fold_aggregate');
        if ($runs->filter(fn ($run) => data_get($run->metadata, 'source') === 'causal_fold_aggregate')->count() !== 1
            || ($run && (int) $run->attempt !== 1)) {
            return $this->blocked('SELECTOR_PANEL_ALL_ORIGINAL_ATTEMPT_EFFORT_REQUIRED');
        }
        if (! $run || ! $run->started_at || $run->started_at->toIso8601String() <= $preregisteredAt
            || (int) $run->lab_generation_id !== (int) $experiment->lab_generation_id || (int) $run->model_version_id !== $arm['model_version_id']
            || $run->parameter_hash !== $arm['evidence_parameter_hash'] || $run->data_hash !== $member['dataset_hash']) {
            return $this->blocked('SELECTOR_PANEL_ORIGINAL_AGGREGATE_RUN_REQUIRED');
        }
        try {
            $identity = $this->immutable->verifiedModelRuntimeIdentity($run);
            $artifact = $identity ? LabEvidenceArtifact::where('run_id', $run->run_id)->where('artifact_type', 'evaluation_request')
                ->where('sha256', $identity['request_artifact_hash'])->oldest('id')->first() : null;
            $request = $artifact ? $this->immutable->readArtifactPayload($artifact) : null;
            $response = $this->immutable->latestArtifactPayload($run);
            if (! $identity || ($identity['parameter_hash'] ?? null) !== $arm['parameter_hash']
                || $this->hash((array) ($identity['runtime_basis'] ?? [])) !== $arm['runtime_basis_hash']
                || ! is_array($request) || ! is_array($response) || ($this->immutable->learningEligibility($run)['complete'] ?? false) !== true
                || $this->immutable->hash($response) !== $run->response_hash
                || data_get($request, 'policy_context.causal_fold_aggregate.protocol') !== 'causal_fold_aggregate_receipt_v1'
                || (int) data_get($request, 'policy_context.causal_fold_aggregate.experiment_id') !== (int) $experiment->id
                || data_get($request, 'policy_context.causal_fold_aggregate.receipt_hashes') !== $foldHashes
                || data_get($request, 'execution_contract.execution_hash') !== $member['execution_hash']
                || data_get($request, 'research_release.release_hash') !== $member['release_hash']) {
                return $this->blocked('SELECTOR_PANEL_ORIGINAL_RUN_ARTIFACT_OR_FOLD_BINDING_INVALID');
            }
        } catch (\Throwable) {
            return $this->blocked('SELECTOR_PANEL_ORIGINAL_RUN_ARTIFACT_OR_FOLD_BINDING_INVALID');
        }

        return ['run_id' => $run->run_id, 'run_record_id' => (int) $run->id,
            'request_hash' => $run->request_hash, 'response_hash' => $run->response_hash,
            'request_artifact_hash' => $artifact->sha256, 'runtime_identity_artifact_hash' => $identity['artifact_hash']];
    }

    private function observed(AgentLearningCausalExperiment $experiment): bool
    {
        return data_get($experiment->evidence, 'outcomes', []) !== []
            || CausalFoldReceipt::where('agent_learning_causal_experiment_id', $experiment->id)
                ->where(fn ($query) => $query->whereNotNull('request_hash')->orWhereNotNull('started_at'))->exists()
            || LabEvaluationRun::whereIn('lab_agent_id', [$experiment->guided_agent_id, $experiment->blinded_agent_id, $experiment->control_agent_id])->exists();
    }

    private function reservedExperiment(AgentLearningCausalExperiment $experiment): bool
    {
        foreach (LabAgent::whereIn('id', [$experiment->guided_agent_id, $experiment->blinded_agent_id, $experiment->control_agent_id])->get() as $agent) {
            if ($this->reservesOrdinaryEvaluation($agent)) {
                return true;
            }
        }

        return false;
    }

    private function registration(string $key, array $body): array
    {
        return ['protocol' => self::PROTOCOL, 'status' => 'selector_panel_preregistered', 'valid' => true, 'panel_key' => $key,
            'subject' => ['selector_panel_key' => $key, 'experiment_ids' => $body['experiment_ids'],
                'question_keys' => array_column($body['members'], 'question_key')], 'metric' => $body['utility_metric'],
            'stopping_rule' => $body['stopping_rule'], 'resource_metric' => $body['rules']['resource_metric'],
            'caps' => $body['caps'], 'preregistered_at' => $body['preregistered_at'], 'promotion_evidence' => false];
    }

    private function readContract(object $row): ?array
    {
        $envelope = json_decode($row->sealed_contract, true);
        $body = $envelope['body'] ?? null;
        if (! is_array($body) || ($body['protocol'] ?? null) !== self::PROTOCOL
            || ($envelope['body_hash'] ?? null) !== $this->hash($body) || ($envelope['seal'] ?? null) !== $this->seal($body)
            || $row->benchmark_key !== $this->hash(array_diff_key($body, ['preregistered_at' => true]))) {
            return null;
        }

        return $body;
    }

    private function plannedWindow(string $authorizationId): array
    {
        $matches = array_values(array_filter((array) config('services.instrument_policy.authorized_research_windows', []),
            fn ($record) => is_array($record) && ($record['authorization_id'] ?? null) === $authorizationId));
        if ($authorizationId === '' || count($matches) !== 1) {
            return $this->blocked('SELECTOR_PANEL_SERVER_PLANNED_AUTHORIZATION_REQUIRED');
        }
        $record = $matches[0];
        try {
            $from = CarbonImmutable::parse($record['start_inclusive'])->utc();
            $until = CarbonImmutable::parse($record['end_exclusive'])->utc();
            if (! $from->greaterThan(now()) || $from->lt('2027-01-01T00:00:00Z') || ! $until->greaterThan($from)
                || ($record['purpose'] ?? null) !== 'instrument_independent_validation'
                || ! is_string($record['research_epoch_id'] ?? null) || $record['research_epoch_id'] === '') {
                throw new \LogicException;
            }
        } catch (\Throwable) {
            return $this->blocked('SELECTOR_PANEL_PROSPECTIVE_PHYSICAL_WINDOW_REQUIRED');
        }

        return ['authorization_id' => $authorizationId, 'research_epoch_id' => $record['research_epoch_id'], 'purpose' => $record['purpose'],
            'start_inclusive' => $from->toIso8601String(), 'end_exclusive' => $until->toIso8601String()];
    }

    private function readBinding(object $row, array $contract, string $panelKey): ?array
    {
        $value = json_decode($row->sealed_contract, true);
        $body = $value['body'] ?? null;
        if (! is_array($body) || ($body['protocol'] ?? null) !== 'scoped_selector_original_data_binding_v1'
            || ($body['panel_key'] ?? null) !== $panelKey || ($body['contract_hash'] ?? null) !== $this->hash($contract)
            || ($value['body_hash'] ?? null) !== $this->hash($body) || ($value['seal'] ?? null) !== $this->seal($body)
            || array_map('intval', array_keys($body['members'] ?? [])) !== $contract['experiment_ids']) {
            return null;
        }

        return $body;
    }

    private function foldClock(array $result, array $request, array $member): array
    {
        $clock = (array) data_get($result, 'data_quality.replay_executed_clock', []);
        $core = array_diff_key($clock, ['receipt_json' => true, 'receipt_hash' => true]);
        try {
            $serialized = json_decode($clock['receipt_json'] ?? '', true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $this->blocked('SELECTOR_PANEL_ORIGINAL_EXECUTED_CLOCK_REQUIRED');
        }
        $rows = $clock['input_rows'] ?? null;
        if (($clock['protocol'] ?? null) !== 'replay_executed_clock_v1' || ($clock['owner'] ?? null) !== 'ordinary_single_position_v1'
            || ($clock['semantics'] ?? null) !== 'previous_closed_candle_next_open_v1' || ($clock['index_basis'] ?? null) !== 'evaluated_frame_zero_based_v1'
            || ($clock['evaluation_offset_rows'] ?? null) !== 0 || ! is_int($rows) || $rows <= 200 || $rows > $member['caps']['max_rows_per_fold']
            || ($clock['first_evaluation_index'] ?? null) !== 200 || ($clock['last_evaluation_index'] ?? null) !== $rows - 1
            || ($clock['decision_rows'] ?? null) !== $rows - 200 || ($clock['complete'] ?? null) !== true
            || ($clock['dataset_hash'] ?? null) !== $member['dataset_hash'] || ($clock['execution_hash'] ?? null) !== $member['execution_hash']
            || ($clock['execution_timeframe'] ?? null) !== ($request['timeframe'] ?? null)
            || ($clock['duration_seconds'] ?? null) !== 300 || ($request['timeframe'] ?? null) !== 'M5'
            || data_get($result, 'data_quality.dataset_attestation.consumed_rows') !== $rows
            || ! is_array($serialized) || $this->hash($serialized) !== $this->hash($core)
            || hash('sha256', $clock['receipt_json']) !== ($clock['receipt_hash'] ?? null)
            || ! $this->sha($clock['index_set_hash'] ?? null) || ! $this->sha($clock['schedule_hash'] ?? null)) {
            return $this->blocked('SELECTOR_PANEL_ORIGINAL_EXECUTED_CLOCK_INCOMPLETE');
        }
        $indices = hash_init('sha256');
        hash_update($indices, "replay-executed-clock-v1:indices\n");
        foreach (range(200, $rows - 1) as $index) {
            hash_update($indices, $index."\n");
        }
        if (hash_final($indices) !== $clock['index_set_hash']) {
            return $this->blocked('SELECTOR_PANEL_ORIGINAL_EXECUTED_CLOCK_INCOMPLETE');
        }
        foreach (['signal_start', 'signal_end', 'execution_start', 'execution_end'] as $time) {
            if (! is_string($clock[$time] ?? null) || ! preg_match('/(?:Z|\+00:00)$/D', $clock[$time])) {
                return $this->blocked('SELECTOR_PANEL_ORIGINAL_EXECUTED_CLOCK_INCOMPLETE');
            }
        }

        return $core;
    }

    private function metricPath(string $target): ?string
    {
        return match ($target) {
            'profit_factor' => 'profit_factor', 'expectancy', 'expectancy_margin' => 'expectancy',
            'net_profit' => 'net_profit', 'after_cost_expectancy_r' => 'after_cost_expectancy_r', default => null
        };
    }

    private function purposeKey(int $agentId): string
    {
        return $this->hash(['scoped_selector_original_purpose_v1', $agentId]);
    }

    private function finite(mixed $value): bool
    {
        return is_numeric($value) && is_finite((float) $value);
    }

    private function sha(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private function hash(array $value): string
    {
        return $this->epochs->parameterHash($value);
    }

    private function seal(array $value): string
    {
        return hash_hmac('sha256', self::PROTOCOL.'|'.$this->hash($value), (string) config('app.key'));
    }

    private function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private function blocked(string $reason): array
    {
        return ['protocol' => self::PROTOCOL, 'status' => 'blocked', 'valid' => false,
            'terminal' => false, 'reason_code' => $reason, 'reason_codes' => [$reason], 'checks' => [], 'original_products' => [], 'promotion_evidence' => false];
    }
}
