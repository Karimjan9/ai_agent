<?php

namespace App\Services;

use App\Models\LabAgent;
use App\Models\LabEvaluationRun;
use App\Models\ResearchExperimentReceipt;
use App\Models\SpecialistCouncilVersion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

/** Converts original council comparisons to scoped research knowledge, never authority. */
class SpecialistCouncilResearchFeedbackService
{
    public const PROTOCOL = 'specialist_council_research_feedback_v1';

    public function __construct(
        private ResearchExperimentConversionKernelService $conversion,
        private ResearchPaperEpochContractService $epochs,
        private SpecialistCouncilContractService $contracts,
    ) {}

    /** Called inside original evaluation publication. Redelivery reuses the same receipt/work. */
    public function recordAssessment(SpecialistCouncilVersion $version): array
    {
        $version = $version->fresh() ?? $version;
        $assessment = (array) $version->assessment;
        $exam = DB::table('specialist_council_evaluations')->where('specialist_council_version_id', $version->id)->first();
        $planRow = DB::table('specialist_council_evaluation_plans')->where('specialist_council_version_id', $version->id)->first();
        $plan = $planRow ? json_decode($planRow->plan, true, 512, JSON_THROW_ON_ERROR) : [];
        if (! in_array($version->state, ['evaluated', 'approved', 'scheduled', 'active', 'retired', 'rolled_back'], true)
            || ! $exam || ! $planRow || ! $this->contracts->manifestValid($version->manifest)
            || $this->epochs->parameterHash($assessment) !== $version->assessment_hash
            || $exam->assessment_hash !== $version->assessment_hash
            || $this->epochs->parameterHash(json_decode($exam->assessment, true, 512, JSON_THROW_ON_ERROR)) !== $exam->assessment_hash
            || $this->epochs->parameterHash($plan) !== $planRow->plan_hash
            || ($assessment['manifest_hash'] ?? '') !== $version->manifest_hash
            || ($assessment['plan_hash'] ?? '') !== $planRow->plan_hash
            || ($assessment['version_id'] ?? null) !== $version->id
            || ($assessment['protocol'] ?? '') !== SpecialistCouncilLifecycleService::ASSESSMENT_PROTOCOL
            || json_decode($exam->original_run_ids, true, 512, JSON_THROW_ON_ERROR) !== ($assessment['original_run_ids'] ?? null)
            || $exam->evaluator_id !== $planRow->evaluator_id || $exam->evaluator_id === $version->creator_id) {
            throw new LogicException('COUNCIL_RESEARCH_FEEDBACK_ORIGINAL_ASSESSMENT_INVALID');
        }
        foreach ((array) ($assessment['original_sources'] ?? []) as $source) {
            $run = LabEvaluationRun::where('run_id', $source['run_id'] ?? '')->first();
            if (! $run || $run->status !== 'completed' || ! $run->finished_at
                || ! in_array($run->run_id, $assessment['original_run_ids'], true)) {
                throw new LogicException('COUNCIL_RESEARCH_FEEDBACK_ORIGINAL_RUN_MISSING');
            }
            foreach (['request_hash', 'response_hash', 'data_hash', 'parameter_hash', 'code_hash'] as $hash) {
                if (! is_string($source[$hash] ?? null) || ! preg_match('/^[a-f0-9]{64}$/', $source[$hash])
                    || ! hash_equals((string) $run->{$hash}, $source[$hash])) {
                    throw new LogicException('COUNCIL_RESEARCH_FEEDBACK_ORIGINAL_RUN_HASH_CHANGED');
                }
            }
        }
        foreach ($plan['windows'] as $window) {
            $start = CarbonImmutable::parse($window['start_inclusive'])->utc();
            $end = CarbonImmutable::parse($window['end_exclusive'])->utc();
            if (! $end->greaterThan($start) || $end->greaterThan(now()->utc())
                || ! $this->epochs->researchIntervalDisjointFromPaper($start->toIso8601String(), $end->toIso8601String())) {
                throw new LogicException('COUNCIL_RESEARCH_FEEDBACK_UNAVAILABLE_OR_PAPER_EVENTS');
            }
        }

        $status = (string) ($assessment['research_observation_status'] ?? 'technical_unassessable');
        [$classification, $next, $terminal] = $this->closure($version, $assessment, $status);
        $symbols = array_values(array_unique(array_merge(...array_map(fn (array $member): array =>
            (array) ($member['scope']['symbols'] ?? []), $version->manifest['members']))));
        if (count($symbols) !== 1) throw new LogicException('COUNCIL_RESEARCH_FEEDBACK_REQUIRES_ONE_CANONICAL_MARKET');
        $carrier = LabAgent::whereIn('model_version_id', array_column($plan['arms'], 'model_version_id'))->orderBy('id')->first();
        $contract = [
            'contract_version' => ResearchExperimentConversionKernelService::CONTRACT_VERSION,
            'source' => ['type' => SpecialistCouncilVersion::class, 'id' => $version->id],
            'scope' => ['symbol' => $symbols[0], 'laboratory_timeframe' => $carrier?->timeframe ?? 'H1',
                'execution_timeframe' => $plan['execution_timeframe'], 'council_id' => $version->council_id,
                'council_version' => $version->version, 'manifest_hash' => $version->manifest_hash,
                'contexts' => array_map(fn (array $member): array => ['specialist_id' => $member['specialist_id'],
                    'role' => $member['role'], 'scope' => $member['scope']], $version->manifest['members'])],
            'claim' => ['target_stage' => 'shared_account_research_comparison',
                'hypothesis' => $plan['objective'], 'minimum_meaningful_effect' => [
                    'minimum_paired_trades' => $version->manifest['evaluation_policy']['minimum_paired_trades'],
                    'external_risk_limits_unchanged' => true], 'scope_local_only' => true,
                'individual_component_causal_effect_proven' => false, 'global_harmful_ban' => false],
            'identity' => ['baseline_epoch_hash' => $version->manifest_hash,
                'data_and_mtf_hash' => $this->epochs->parameterHash($plan['windows']),
                'runtime_and_contract_hash' => $plan['execution_hash'], 'intervention_hash' => $version->manifest_hash,
                'window_plan_hash' => $planRow->plan_hash, 'evaluator_version' => self::PROTOCOL,
                'research_question_fingerprint' => $this->questionFingerprint($version->manifest, $plan)],
            'arms' => array_values(array_map(fn (array $arm): array => ['role' => $arm['kind'],
                'model_version_id' => $arm['model_version_id'], 'model_hash' => $arm['model_hash'],
                'window_key' => $arm['window_key'], 'removed_id' => $arm['removed_id'] ?? null], $plan['arms'])),
            'revisions' => ['subject' => 1, 'evidence' => (int) $exam->id],
        ];
        $evidence = ['protocol' => self::PROTOCOL, 'assessment_id' => (int) $exam->id,
            'assessment_hash' => $version->assessment_hash, 'plan_hash' => $planRow->plan_hash,
            'original_run_ids' => $assessment['original_run_ids'], 'original_sources' => $assessment['original_sources'],
            'research_observation_status' => $status, 'comparisons' => $assessment['comparisons'],
            'reason_codes' => $assessment['reason_codes'], 'economic_direction' => $this->economicDirection($assessment),
            'original_independent_assessment_qualified' => $plan['purpose'] === 'independent' && ($assessment['qualified'] ?? false),
            'qualified' => false, 'confirmed_skill_credit' => false, 'promotion_evidence' => false];
        $receipt = $this->conversion->record($contract, $evidence, $classification, $next, $terminal);
        if (($receipt['status'] ?? '') !== 'recorded') throw new LogicException('COUNCIL_RESEARCH_FEEDBACK_NOT_PUBLISHED:'.($receipt['reason'] ?? 'UNKNOWN'));
        return ['protocol' => self::PROTOCOL, ...$receipt, 'economic_direction' => $evidence['economic_direction'],
            'knowledge_authority' => 'research_only', 'global_harmful_ban' => false, 'promotion_evidence' => false];
    }

    /** Bounded prior scoped observations may guide a new question, not a champion or risk gate. */
    public function priorObservations(string $councilId, int $limit = 8): array
    {
        if (! Schema::hasTable('research_experiment_receipts')) return [];
        return ResearchExperimentReceipt::where('source_type', SpecialistCouncilVersion::class)
            ->where('payload->contract->scope->council_id', $councilId)->orderByDesc('id')->limit(max(1, min(32, $limit)))
            ->get()->filter(fn (ResearchExperimentReceipt $receipt): bool => $this->priorReceiptValid($receipt))
            ->map(fn (ResearchExperimentReceipt $receipt): array => $this->observationFromReceipt($receipt))->values()->all();
    }

    /** Revalidate a sealed snapshot by exact IDs, not today's possibly changed top-eight ranking. */
    public function assertPriorObservations(string $councilId, array $snapshot): void
    {
        if (! array_is_list($snapshot) || count($snapshot) > 8
            || count(array_unique(array_column($snapshot, 'receipt_id'))) !== count($snapshot)) {
            throw new LogicException('COUNCIL_PRIOR_RESEARCH_SNAPSHOT_UNBOUNDED_OR_DUPLICATED');
        }
        foreach ($snapshot as $observation) {
            $receipt = is_array($observation) ? ResearchExperimentReceipt::find($observation['receipt_id'] ?? 0) : null;
            if (! $receipt || $receipt->source_type !== SpecialistCouncilVersion::class
                || data_get($receipt->payload, 'contract.scope.council_id') !== $councilId
                || ! $this->priorReceiptValid($receipt)
                || $this->epochs->parameterHash($this->observationFromReceipt($receipt)) !== $this->epochs->parameterHash($observation)) {
                throw new LogicException('COUNCIL_PRIOR_RESEARCH_SNAPSHOT_ORIGINAL_RECEIPT_INVALID');
            }
        }
    }

    /** A new council label cannot respend a completed same-release physical question. */
    public function completedQuestionForSource(string $fingerprint, string $currentCodeHash): ?array
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $fingerprint) || ! preg_match('/^[a-f0-9]{64}$/', $currentCodeHash)) {
            throw new LogicException('COUNCIL_COMPLETED_QUESTION_SOURCE_IDENTITY_INVALID');
        }
        if (! Schema::hasTable('research_experiment_receipts')) return null;
        $receipts = ResearchExperimentReceipt::where('source_type', SpecialistCouncilVersion::class)
            ->where('payload->contract->identity->research_question_fingerprint', $fingerprint)
            ->orderByDesc('id')->limit(32)->get();
        foreach ($receipts as $receipt) {
            if (! $this->priorReceiptValid($receipt)
                || data_get($receipt->payload, 'evidence.research_observation_status') !== 'research_compared') continue;
            $planRow = DB::table('specialist_council_evaluation_plans')->where('specialist_council_version_id', $receipt->source_id)->first();
            $plan = $planRow ? json_decode($planRow->plan, true, 512, JSON_THROW_ON_ERROR) : [];
            $sources = (array) data_get($receipt->payload, 'evidence.original_sources', []);
            $runIds = (array) data_get($receipt->payload, 'evidence.original_run_ids', []);
            if (count($sources) !== count($plan['arms'] ?? []) || count($runIds) !== count($sources)
                || count((array) data_get($receipt->payload, 'evidence.comparisons', [])) !== count($plan['windows'] ?? [])
                || ! collect($sources)->every(fn (array $source): bool => ($source['code_hash'] ?? '') === $currentCodeHash)) continue;
            $covered = true;
            foreach ($plan['windows'] as $key => $window) {
                $windowArms = array_values(array_filter($plan['arms'], fn (array $arm): bool => $arm['window_key'] === $key));
                $kinds = array_column($windowArms, 'kind');
                if (count(array_filter($kinds, fn (string $kind): bool => $kind === 'candidate')) !== 1
                    || count(array_filter($kinds, fn (string $kind): bool => $kind === 'solo')) !== 1
                    || ! in_array('ablation', $kinds, true)) $covered = false;
            }
            if ($covered) return [...$this->observationFromReceipt($receipt), 'completed_original_arm_count' => count($sources),
                'current_code_hash' => $currentCodeHash, 'same_release_completed_question' => true];
        }
        return null;
    }

    private function observationFromReceipt(ResearchExperimentReceipt $receipt): array
    {
        return ['receipt_id' => $receipt->id,
                'receipt_key' => $receipt->receipt_key, 'classification' => $receipt->classification,
                'contract_hash' => $receipt->contract_hash, 'evidence_hash' => $receipt->evidence_hash,
                'research_question_fingerprint' => data_get($receipt->payload, 'contract.identity.research_question_fingerprint'),
                'original_run_ids' => data_get($receipt->payload, 'evidence.original_run_ids'),
                'plan_hash' => data_get($receipt->payload, 'evidence.plan_hash'),
                'assessment_hash' => data_get($receipt->payload, 'evidence.assessment_hash'),
                'economic_direction' => data_get($receipt->payload, 'evidence.economic_direction'),
                'scope' => data_get($receipt->payload, 'contract.scope'), 'selection_authority' => 'research_only',
                'promotion_evidence' => false];
    }

    /** Fresh row/version labels do not renew the same physical research question. */
    public function questionFingerprint(array $manifest, array $plan): string
    {
        $members = array_map(fn (array $member): array => array_intersect_key($member, array_flip([
            'role', 'strategy', 'parameters', 'scope', 'horizon', 'capital_weight', 'risk_per_trade_percent',
            'sensor_timeframes', 'allowed_actions', 'data_requirements', 'operator_contract',
        ])), $manifest['members']);
        usort($members, fn (array $left, array $right): int => strcmp($this->epochs->parameterHash($left), $this->epochs->parameterHash($right)));
        $windows = array_map(fn (array $window): array => [
            'start_inclusive' => $window['start_inclusive'], 'end_exclusive' => $window['end_exclusive'],
            'evaluation_scope' => array_intersect_key((array) ($window['evaluation_scope'] ?? []), array_flip([
                'start_inclusive', 'end_exclusive', 'rows', 'decision_rows', 'warmup_rows',
            ])),
        ], array_values($plan['windows']));
        usort($windows, fn (array $left, array $right): int => strcmp($left['start_inclusive'], $right['start_inclusive']));
        return $this->epochs->parameterHash(['members' => $members, 'windows' => $windows,
            'components' => array_map(fn (array $component): array => array_diff_key($component, ['id' => true, 'version' => true]), (array) ($manifest['components'] ?? [])),
            'execution' => array_diff_key($manifest['execution'], ['id' => true, 'version' => true]),
            'risk_policy' => $plan['risk_policy'], 'cost_model' => $plan['cost_model'],
            'initial_capital' => $plan['initial_capital'], 'execution_timeframe' => $plan['execution_timeframe'],
            'objective' => $plan['objective'] ?? $manifest['evaluation_policy']['objective']]);
    }

    private function priorReceiptValid(ResearchExperimentReceipt $receipt): bool
    {
        try {
            if (data_get($receipt->payload, 'evidence.protocol') !== self::PROTOCOL
                || $this->epochs->parameterHash((array) data_get($receipt->payload, 'contract', [])) !== $receipt->contract_hash
                || $this->epochs->parameterHash((array) data_get($receipt->payload, 'evidence', [])) !== $receipt->evidence_hash) return false;
            $version = SpecialistCouncilVersion::find($receipt->source_id);
            $exam = DB::table('specialist_council_evaluations')->where('specialist_council_version_id', $receipt->source_id)->first();
            $plan = DB::table('specialist_council_evaluation_plans')->where('specialist_council_version_id', $receipt->source_id)->first();
            if (! $version || ! $exam || ! $plan || ! $this->contracts->manifestValid($version->manifest)
                || $version->manifest_hash !== data_get($receipt->payload, 'contract.scope.manifest_hash')
                || $version->assessment_hash !== data_get($receipt->payload, 'evidence.assessment_hash')
                || $this->epochs->parameterHash((array) $version->assessment) !== $version->assessment_hash
                || $exam->assessment_hash !== $version->assessment_hash
                || $this->epochs->parameterHash(json_decode($exam->assessment, true, 512, JSON_THROW_ON_ERROR)) !== $exam->assessment_hash
                || $plan->plan_hash !== data_get($receipt->payload, 'evidence.plan_hash')
                || $this->epochs->parameterHash(json_decode($plan->plan, true, 512, JSON_THROW_ON_ERROR)) !== $plan->plan_hash
                || json_decode($exam->original_run_ids, true, 512, JSON_THROW_ON_ERROR) !== data_get($receipt->payload, 'evidence.original_run_ids')) return false;
            foreach ((array) data_get($receipt->payload, 'evidence.original_sources', []) as $source) {
                $run = LabEvaluationRun::where('run_id', $source['run_id'] ?? '')->first();
                if (! $run || $run->status !== 'completed' || ! $run->finished_at) return false;
                foreach (['request_hash', 'response_hash', 'data_hash', 'parameter_hash', 'code_hash'] as $hash) {
                    if (! is_string($source[$hash] ?? null) || ! hash_equals((string) $run->{$hash}, $source[$hash])) return false;
                }
            }
            return true;
        } catch (\Throwable) { return false; }
    }

    private function closure(SpecialistCouncilVersion $version, array $assessment, string $status): array
    {
        $base = ['identity' => $version->assessment_hash, 'priority' => 5,
            'owner' => ResearchLoopArbiterService::class, 'executor' => ResearchExperimentWorkConsumerService::class,
            'executable' => false, 'version_id' => $version->id, 'manifest_hash' => $version->manifest_hash,
            'assessment_hash' => $version->assessment_hash, 'same_evidence_replay_forbidden' => true];
        if ($status === 'technical_unassessable') return ['TECHNICAL_QUARANTINE', [...$base,
            'type' => 'specialist_council_technical_repair', 'dependency_key' => 'council_original_evidence_repair:'.$version->id,
            'retry_condition' => ['code' => 'NEW_SEALED_ORIGINAL_COUNCIL_EVIDENCE_REQUIRED', 'max_experiments' => 1,
                'same_evidence_replay_forbidden' => true]], []];
        if ($status === 'data_missing') return ['INCONCLUSIVE', [...$base,
            'type' => 'specialist_council_data_repair', 'dependency_key' => 'council_execution_data_owner_receipt:'.$version->id,
            'retry_condition' => ['code' => 'VERIFIED_EXECUTION_DATA_PREREQUISITES_REQUIRED', 'max_experiments' => 1,
                'same_evidence_replay_forbidden' => true]], []];
        if ($status === 'underpowered') return ['UNDERPOWERED', [...$base,
            'type' => 'specialist_council_power_extension', 'dependency_key' => 'prospective_council_powered_scope:'.$version->id,
            'retry_condition' => ['code' => 'NEW_PREREGISTERED_POWERED_SCOPE_REQUIRED', 'max_experiments' => 1,
                'same_evidence_replay_forbidden' => true]], []];
        if ($status !== 'research_compared') throw new LogicException('UNKNOWN_COUNCIL_RESEARCH_OBSERVATION_STATUS');
        if (($assessment['qualified'] ?? false) === true) return ['POSITIVE_CANDIDATE', [...$base,
            'type' => 'specialist_council_descendant_transfer',
            'dependency_key' => 'prospective_council_descendant_and_ablation:'.$version->id,
            'retry_condition' => ['code' => 'NEW_CONTROLLED_DESCENDANT_TRAIT_ABLATION_AND_AUTHORIZED_WINDOW_REQUIRED',
                'max_experiments' => 1, 'same_evidence_replay_forbidden' => true]], []];
        $direction = $this->economicDirection($assessment);
        if ($direction === 'locally_promising') return ['BEHAVIORAL_ACTIVATION_HYPOTHESIS', [...$base,
            'type' => 'specialist_council_independent_validation',
            'dependency_key' => 'authorized_unused_council_validation:'.$version->id,
            'data_policy' => ['paper_2026_is_research' => false, 'minimum_research_year' => 2027,
                'unused_authorized_window_required' => true, 'original_plan_does_not_authorize_validation' => true],
            'retry_condition' => ['code' => 'AUTHORIZED_UNUSED_INDEPENDENT_COUNCIL_WINDOW_REQUIRED',
                'max_experiments' => 1, 'same_evidence_replay_forbidden' => true]], []];
        return ['INCONCLUSIVE', [], ['code' => $direction === 'local_negative'
            ? 'SCOPED_COUNCIL_COMPARISON_NOT_BETTER_THAN_SOLO' : 'SCOPED_COUNCIL_COMPARISON_NO_INCREMENTAL_BENEFIT',
            'hypothesis_closed_for_original_manifest_and_window' => true,
            'new_question_requires_new_preregistered_contract' => true, 'global_harmful_ban' => false]];
    }

    private function economicDirection(array $assessment): string
    {
        $comparisons = (array) ($assessment['comparisons'] ?? []);
        if ($comparisons === []) return 'unassessable';
        if (($assessment['research_observation_status'] ?? '') !== 'research_compared') return 'not_powered_or_assessable';
        $localRiskFailure = in_array('COUNCIL_EXTERNAL_RISK_LIMIT_EXCEEDED', (array) ($assessment['reason_codes'] ?? []), true);
        if (! $localRiskFailure && collect($comparisons)->contains(fn (array $comparison): bool =>
            ($comparison['incremental_value'] ?? false) && ($comparison['powered'] ?? false)
            && ! empty($comparison['ablations']) && collect($comparison['ablations'])->every(fn (array $ablation): bool =>
                ($ablation['incremental_value_observed'] ?? false)))) return 'locally_promising';
        return collect($comparisons)->every(fn (array $comparison): bool => ($comparison['net_profit_delta_vs_solo'] ?? 0) < 0)
            ? 'local_negative' : 'local_null';
    }
}
